<?php
/**
 * Customer website brief. The email link exchanges a token for a session.
 * The page stores one note and lists files already saved for this project.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/projects.php';

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'brief.php') {
    webco_handle_brief();
}

function webco_handle_brief(): void
{
    webco_private_headers();
    webco_start_named_session('WEBCOBRIEF');

    $access = $_GET['access'] ?? null;
    if (is_string($access) && $access !== '') {
        webco_brief_exchange($access);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        webco_brief_post();
    }

    $projectId = webco_session_project_id();
    if ($projectId === null) {
        webco_brief_message(
            'Website brief',
            'Open the secure link in your Webco email to continue this brief.'
        );
    }

    $db = webco_db();
    if (!$db instanceof PDO || !webco_ensure_project_tables($db)) {
        webco_brief_message('Website brief', 'The brief is unavailable just now. Try again in a moment.');
    }

    $project = webco_customer_project($db, $projectId);
    if ($project === null) {
        $_SESSION = [];
        webco_brief_message(
            'Website brief',
            'Open the secure link in your Webco email to continue this brief.'
        );
    }

    webco_brief_form($project, webco_brief_notice($_GET['notice'] ?? null));
}

function webco_brief_exchange(string $access): void
{
    if (!preg_match('/^[a-f0-9]{64}$/', $access)) {
        webco_brief_message('Website brief', 'This link is not valid. Use the link from your Webco email.');
    }

    $db = webco_db();
    if (!$db instanceof PDO || !webco_ensure_project_tables($db)) {
        webco_brief_message('Website brief', 'The brief is unavailable just now. Try again in a moment.');
    }

    $projectId = webco_project_id_for_brief_token($db, $access);
    if ($projectId === null) {
        webco_brief_message('Website brief', 'This link is not valid. Use the link from your Webco email.');
    }

    session_regenerate_id(true);
    $_SESSION = [
        'project_id' => $projectId,
        'csrf' => bin2hex(random_bytes(16)),
    ];
    header('Location: /brief.php', true, 303);
    exit;
}

function webco_brief_post(): void
{
    $projectId = webco_session_project_id();
    if ($projectId === null || !webco_brief_csrf_ok()) {
        webco_brief_redirect('again');
    }

    $db = webco_db();
    if (!$db instanceof PDO || !webco_ensure_project_tables($db)) {
        webco_brief_redirect('error');
    }

    $summary = $_POST['summary'] ?? '';
    if (!is_string($summary)) {
        webco_brief_redirect('invalid');
    }

    $submit = ($_POST['intent'] ?? '') === 'submit';
    $result = webco_save_customer_brief($db, $projectId, $summary, $submit);
    if ($result === 'saved' || $result === 'submitted' || $result === 'invalid') {
        webco_brief_redirect($result);
    }

    webco_brief_redirect('error');
}

function webco_brief_csrf_ok(): bool
{
    $sent = $_POST['csrf'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || !is_string($known) || strlen($sent) !== 32 || strlen($known) !== 32) {
        return false;
    }

    return hash_equals($known, $sent);
}

function webco_brief_redirect(string $notice): void
{
    header('Location: /brief.php?notice=' . rawurlencode($notice), true, 303);
    exit;
}

function webco_brief_notice(mixed $notice): string
{
    $messages = [
        'saved' => 'Your progress has been saved. You can close this page and use the same email link to continue.',
        'submitted' => 'The brief has been submitted. Webco will contact you. You can still add files.',
        'uploaded' => 'The file has been added to this project.',
        'upload_type' => 'That file type is not accepted. Logos and photos can be JPEG, PNG or WebP. Documents can be PDF.',
        'upload_size' => 'That file is too large. The limit is 10 MB.',
        'upload_limit' => 'This category already has 20 files.',
        'upload_failed' => 'The file could not be saved. Try again.',
        'invalid' => 'The note could not be saved. Keep it under 8,000 characters.',
        'again' => 'That action was not accepted. Try again.',
        'error' => 'The brief could not be saved. Try again.',
    ];
    if (!is_string($notice) || !isset($messages[$notice])) {
        return '';
    }

    return $messages[$notice];
}

/**
 * @param array{
 *   id: int,
 *   order_public_id: string,
 *   status: string,
 *   business_name: string,
 *   summary: string,
 *   submitted_at: ?string,
 *   assets: array<string, list<array{original_name: string, size_bytes: int}>>
 * } $project
 */
function webco_brief_form(array $project, string $notice): void
{
    $csrf = $_SESSION['csrf'] ?? '';
    if (!is_string($csrf) || strlen($csrf) !== 32) {
        $csrf = bin2hex(random_bytes(16));
        $_SESSION['csrf'] = $csrf;
    }

    $submitted = $project['submitted_at'] !== null;
    $status = webco_project_status_label($project['status']);

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>Website brief</title>';
    echo webco_brief_styles();
    echo '</head><body><main>';
    echo '<p class="eyebrow">Webco Cloud</p>';
    echo '<h1>Website brief</h1>';
    echo '<p class="lead">Add a short note and any logos, photos or documents. You can save and come back with the same email link.</p>';
    echo '<p class="meta">' . webco_html($project['business_name']) . ' · ' . webco_html($project['order_public_id']) . '</p>';
    echo '<p class="meta">Status: ' . webco_html($status) . '</p>';
    if ($notice !== '') {
        echo '<p class="notice" role="status">' . webco_html($notice) . '</p>';
    }
    if ($submitted) {
        echo '<p class="note">This brief has been submitted. Webco will contact you. You can still add files.</p>';
    }

    echo '<form method="post" action="/brief.php">';
    echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
    echo '<label for="summary">Short note</label>';
    echo '<textarea id="summary" name="summary" maxlength="8000" rows="8">';
    echo webco_html($project['summary']);
    echo '</textarea>';
    echo '<div class="actions">';
    echo '<button class="quiet" type="submit" name="intent" value="save">Save progress</button>';
    if (!$submitted) {
        echo '<button type="submit" name="intent" value="submit">Submit brief</button>';
    }
    echo '</div></form>';

    echo '<section><h2>Files</h2>';
    echo '<p>Logos and photos can be JPEG, PNG or WebP. Documents can be PDF. Each file can be up to 10 MB, with 20 files in each category.</p>';
    webco_brief_uploads($project['assets'], $csrf);
    echo '</section>';
    echo '<p class="meta"><a href="/support/">Support</a></p>';
    echo '</main></body></html>';
    exit;
}

/**
 * @param array<string, list<array{original_name: string, size_bytes: int}>> $assets
 */
function webco_brief_uploads(array $assets, string $csrf): void
{
    $categories = [
        'logo' => 'Logos',
        'photo' => 'Photos',
        'document' => 'Documents',
    ];
    $accept = [
        'logo' => 'image/jpeg,image/png,image/webp',
        'photo' => 'image/jpeg,image/png,image/webp',
        'document' => 'application/pdf',
    ];

    foreach ($categories as $category => $label) {
        echo '<h3>' . webco_html($label) . '</h3>';
        $files = $assets[$category] ?? [];
        if ($files === []) {
            echo '<p class="meta">None yet.</p>';
        } else {
            echo '<ul>';
            foreach ($files as $file) {
                echo '<li>' . webco_html($file['original_name']) . ' <span>('
                    . webco_html(webco_brief_size((int) $file['size_bytes'])) . ')</span></li>';
            }
            echo '</ul>';
        }
        echo '<form method="post" action="/brief-upload.php" enctype="multipart/form-data">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="category" value="' . webco_html($category) . '">';
        echo '<label for="file-' . webco_html($category) . '">Add a ' . webco_html(strtolower($label)) . ' file</label>';
        echo '<input id="file-' . webco_html($category) . '" name="file" type="file" accept="'
            . webco_html($accept[$category]) . '" required>';
        echo '<button type="submit">Upload</button>';
        echo '</form>';
    }
}

function webco_brief_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    return (string) max(1, (int) round($bytes / 1024)) . ' KB';
}

function webco_brief_message(string $title, string $message): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . webco_html($title) . '</title>';
    echo webco_brief_styles();
    echo '</head><body><main>';
    echo '<p class="eyebrow">Webco Cloud</p>';
    echo '<h1>' . webco_html($title) . '</h1>';
    echo '<p class="lead">' . webco_html($message) . '</p>';
    echo '</main></body></html>';
    exit;
}

function webco_brief_styles(): string
{
    return '<style>
      :root { color-scheme: light; }
      body { margin: 0; background: #f3f6f5; color: #122028; font: 1.0625rem/1.6 "Segoe UI", Helvetica, Arial, sans-serif; }
      main { max-width: 40rem; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
      h1, h2, h3 { font-family: Georgia, Palatino, serif; font-weight: 600; line-height: 1.2; }
      h1 { margin: 0; font-size: 2.4rem; }
      h2 { margin: 2rem 0 0; font-size: 1.6rem; }
      h3 { margin: 1.4rem 0 0; font-size: 1.2rem; }
      p { margin: 0.75rem 0 0; }
      .eyebrow { margin: 0; color: #0c6b62; font-size: 0.85rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
      .lead, .note { color: #3e4e58; }
      .meta { color: #3e4e58; }
      .notice { padding: 0.8rem 1rem; background: #e5f3f1; border-radius: 12px; }
      form { margin-top: 1rem; }
      label { display: block; margin-top: 0.8rem; font-weight: 650; }
      textarea, input[type="file"] { display: block; width: 100%; margin-top: 0.35rem; }
      textarea { box-sizing: border-box; padding: 0.75rem; border: 1px solid #d5e0dc; border-radius: 12px; font: inherit; }
      button { margin: 0.8rem 0.6rem 0 0; padding: 0.7rem 1rem; border: 0; border-radius: 999px; background: #0c6b62; color: #f7fbfa; font: inherit; cursor: pointer; }
      button.quiet { background: transparent; color: #122028; border: 1px solid #d5e0dc; }
      .actions { margin-top: 0.4rem; }
      a { color: #0c6b62; }
      ul { margin: 0.4rem 0 0; padding-left: 1.2rem; }
    </style>';
}
