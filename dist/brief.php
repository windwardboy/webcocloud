<?php
/**
 * Customer website brief. The email link exchanges a token for a session.
 * The page stores one note and lists files already saved for this project.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/brief-wizard.php';

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'brief.php') {
    webco_handle_brief();
}

function webco_handle_brief(): void
{
    webco_private_headers();
    header(
        "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; "
        . "script-src 'unsafe-inline'; img-src 'self'; connect-src 'self'; "
        . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
    );
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

    $intent = $_POST['intent'] ?? '';
    if ($intent === 'request') {
        webco_brief_request_post($db, $projectId);
    }

    $step = $_POST['step'] ?? '';
    if (!is_string($step) || !webco_brief_wizard_step_valid($step)) {
        webco_brief_redirect('again');
    }
    $goto = $_POST['goto'] ?? '';
    if (!is_string($goto)) {
        $goto = '';
    }
    $submit = $intent === 'submit';
    if ($submit && $step !== 'review') {
        webco_brief_redirect('again', $step);
    }
    if ($goto !== '' && !webco_brief_wizard_step_valid($goto)) {
        webco_brief_redirect('again', $step);
    }

    $fields = webco_brief_posted_fields($step);
    if ($goto !== '') {
        $fields['wizard_step'] = $goto;
    }
    $result = webco_save_customer_brief($db, $projectId, $fields, $submit);
    if ($result === 'invalid') {
        webco_brief_redirect('invalid', $step);
    }
    if ($result === 'saved') {
        webco_brief_redirect('saved', $goto !== '' ? $goto : $step);
    }
    if ($result === 'submitted') {
        webco_brief_redirect('submitted');
    }

    webco_brief_redirect('error', $step);
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

function webco_brief_redirect(string $notice, string $step = ''): void
{
    $query = [];
    if ($notice !== '') {
        $query['notice'] = $notice;
    }
    if ($step !== '' && webco_brief_wizard_step_valid($step)) {
        $query['step'] = $step;
    }
    $target = '/brief.php';
    if ($query !== []) {
        $target .= '?' . http_build_query($query);
    }
    header('Location: ' . $target, true, 303);
    exit;
}

function webco_brief_notice(mixed $notice): string
{
    $messages = [
        'saved' => 'Progress saved.',
        'submitted' => 'The brief has been submitted. Webco will contact you. You can still send a support request.',
        'requested' => 'Your request has been sent. It is listed separately from the website brief.',
        'brief_first' => 'Submit the website brief before sending a support request.',
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
 *   phone: string,
 *   assets: array<string, list<array{original_name: string, size_bytes: int, request_id: ?int}>>,
 *   requests: list<array<string, mixed>>
 * } $project
 */
function webco_brief_form(array $project, string $notice): void
{
    $csrf = webco_brief_csrf_token();
    if ($project['submitted_at'] !== null) {
        webco_brief_render_received($project, $notice);
        exit;
    }

    $requested = $_GET['step'] ?? '';
    $step = is_string($requested) && webco_brief_wizard_step_valid($requested)
        ? $requested
        : (string) ($project['wizard_step'] ?? '');
    if (!webco_brief_wizard_step_valid($step)) {
        $step = 'business';
    }
    webco_brief_render_wizard($project, $step, $notice, $csrf);
    exit;
}

/**
 * @param array<string, mixed> $project
 */
function webco_brief_call(array $project): void
{
    $requested = ($project['call_requested'] ?? false) === true;
    $number = (string) ($project['call_number'] ?? '');
    if ($number === '') {
        $number = (string) ($project['phone'] ?? '');
    }
    echo '<fieldset class="call"><legend>Would you like a phone call before I start your website?</legend>';
    echo '<label class="choice"><input type="radio" name="call_requested" value="no"' . ($requested ? '' : ' checked') . '> No</label>';
    echo '<label class="choice"><input type="radio" name="call_requested" value="yes"' . ($requested ? ' checked' : '') . '> Yes</label>';
    echo '<div class="call-extra">';
    echo '<label for="call_number">Preferred number</label>';
    echo '<input id="call_number" name="call_number" type="tel" maxlength="40" value="' . webco_html($number) . '">';
    echo '<label for="call_time">Preferred time</label>';
    echo '<input id="call_time" name="call_time" type="text" maxlength="120" value="' . webco_html((string) ($project['call_time'] ?? '')) . '">';
    echo '<label for="call_note">Note for the call</label>';
    echo '<textarea id="call_note" name="call_note" maxlength="2000" rows="3">' . webco_html((string) ($project['call_note'] ?? '')) . '</textarea>';
    echo '</div></fieldset>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_brief_requests(array $project, string $csrf): void
{
    $requests = is_array($project['requests'] ?? null) ? $project['requests'] : [];
    $assets = is_array($project['assets'] ?? null) ? $project['assets'] : [];

    echo '<section class="panel"><h2>Support and updates</h2>';
    echo '<p class="lead">These requests are for changes after the website brief. They do not replace it.</p>';
    if ($requests === []) {
        echo '<p class="meta">No requests yet.</p>';
    }
    foreach ($requests as $request) {
        if (!is_array($request)) {
            continue;
        }
        $requestId = (int) ($request['id'] ?? 0);
        echo '<article class="request">';
        echo '<p class="eyebrow">' . webco_html(webco_request_type_label((string) ($request['request_type'] ?? ''))) . '</p>';
        echo '<p class="request-status">' . webco_html(webco_request_status_label((string) ($request['status'] ?? ''))) . '</p>';
        echo '<p class="summary">' . webco_html((string) ($request['summary'] ?? '')) . '</p>';
        if (($request['call_requested'] ?? false) === true) {
            echo '<p class="meta">Phone call requested';
            $number = trim((string) ($request['call_number'] ?? ''));
            $time = trim((string) ($request['call_time'] ?? ''));
            if ($number !== '') {
                echo ' · ' . webco_html($number);
            }
            if ($time !== '') {
                echo ' · ' . webco_html($time);
            }
            echo '</p>';
        }
        webco_brief_auto_uploads(webco_brief_assets_for_request($assets, $requestId), $csrf, $requestId);
        echo '</article>';
    }

    echo '<h3>New request</h3>';
    echo '<form method="post" action="/brief.php">';
    echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
    echo '<label for="request_type">What do you need?</label>';
    echo '<select id="request_type" name="request_type" required>';
    foreach (webco_request_types() as $type) {
        echo '<option value="' . webco_html($type) . '">' . webco_html(webco_request_type_label($type)) . '</option>';
    }
    echo '</select>';
    echo '<label for="request_summary">What should we do?</label>';
    echo '<textarea id="request_summary" name="request_summary" maxlength="8000" rows="5" required></textarea>';
    echo '<fieldset class="call"><legend>Would you like a phone call about this request?</legend>';
    echo '<label class="choice"><input type="radio" name="request_call" value="no" checked> No</label>';
    echo '<label class="choice"><input type="radio" name="request_call" value="yes"> Yes</label>';
    echo '<div class="call-extra">';
    echo '<label for="request_call_number">Preferred number</label>';
    echo '<input id="request_call_number" name="request_call_number" type="tel" maxlength="40" value="' . webco_html((string) ($project['phone'] ?? '')) . '">';
    echo '<label for="request_call_time">Preferred time</label>';
    echo '<input id="request_call_time" name="request_call_time" type="text" maxlength="120">';
    echo '<label for="request_call_note">Note for the call</label>';
    echo '<textarea id="request_call_note" name="request_call_note" maxlength="2000" rows="3"></textarea>';
    echo '</div></fieldset>';
    echo '<button type="submit" name="intent" value="request">Send request</button>';
    echo '</form></section>';
}

/**
 * @return array<string, mixed>
 */
function webco_brief_posted_fields(string $step): array
{
    $text = match ($step) {
        'business' => ['business_overview', 'years_operating', 'credentials', 'first_impression'],
        'courses' => ['services'],
        'locations' => ['locations', 'areas_served'],
        'why' => ['why_experience', 'why_facilities', 'why_flexibility', 'why_support', 'why_difference'],
        'branding' => ['branding'],
        'contact' => ['enquiry_route', 'contact_details', 'opening_hours', 'call_number', 'call_time', 'call_note'],
        'other' => ['summary'],
        default => [],
    };
    $fields = [];
    foreach ($text as $name) {
        if (!array_key_exists($name, $_POST)) {
            continue;
        }
        $value = $_POST[$name];
        $fields[$name] = is_string($value) ? $value : '';
    }
    if ($step === 'courses' && isset($_POST['course_name']) && is_array($_POST['course_name'])) {
        $details = $_POST['course_detail'] ?? [];
        $pairs = webco_brief_pairs_from_request($_POST['course_name'], is_array($details) ? $details : []);
        $fields['course_entries'] = $pairs;
        $encoded = webco_brief_normalize_pairs($pairs, 6, 120, 1000);
        $fields['services'] = $encoded === null ? '' : webco_brief_pairs_plain($encoded);
    }
    if ($step === 'locations' && isset($_POST['location_name']) && is_array($_POST['location_name'])) {
        $details = $_POST['location_detail'] ?? [];
        $fields['location_entries'] = webco_brief_pairs_from_request(
            $_POST['location_name'],
            is_array($details) ? $details : []
        );
    }
    if ($step === 'contact') {
        $fields['call_requested'] = ($_POST['call_requested'] ?? '') === 'yes' ? 'yes' : 'no';
        if (!isset($fields['enquiry_route']) || !is_string($fields['enquiry_route'])) {
            $fields['enquiry_route'] = '';
        }
    }

    return $fields;
}

function webco_brief_request_post(PDO $db, int $projectId): void
{
    $project = webco_customer_project($db, $projectId);
    if ($project === null || $project['submitted_at'] === null) {
        webco_brief_redirect('brief_first');
    }

    $type = $_POST['request_type'] ?? '';
    $summary = $_POST['request_summary'] ?? '';
    if (!is_string($type) || !is_string($summary)) {
        webco_brief_redirect('invalid');
    }

    $id = webco_create_project_request($db, $projectId, $type, $summary, [
        'call_requested' => ($_POST['request_call'] ?? '') === 'yes' ? 'yes' : 'no',
        'call_number' => is_string($_POST['request_call_number'] ?? null) ? $_POST['request_call_number'] : '',
        'call_time' => is_string($_POST['request_call_time'] ?? null) ? $_POST['request_call_time'] : '',
        'call_note' => is_string($_POST['request_call_note'] ?? null) ? $_POST['request_call_note'] : '',
    ]);
    webco_brief_redirect($id > 0 ? 'requested' : 'invalid');
}

/**
 * @param array<string, list<array{original_name: string, size_bytes: int, request_id: ?int}>> $assets
 * @return array<string, list<array{original_name: string, size_bytes: int, request_id: ?int}>>
 */
function webco_brief_assets_for_request(array $assets, ?int $requestId): array
{
    $filtered = [];
    foreach ($assets as $category => $files) {
        $filtered[$category] = [];
        foreach ($files as $file) {
            $owner = $file['request_id'] ?? null;
            if ($requestId === null && $owner === null) {
                $filtered[$category][] = $file;
            } elseif ($requestId !== null && $owner === $requestId) {
                $filtered[$category][] = $file;
            }
        }
    }

    return $filtered;
}

/**
 * @param array<string, list<array{original_name: string, size_bytes: int, request_id?: ?int}>> $assets
 */
/**
 * @param array<string, list<array{original_name: string, size_bytes: int, request_id?: ?int}>> $assets
 * @param array<string, string>|null $only
 */
function webco_brief_auto_uploads(array $assets, string $csrf, ?int $requestId, ?array $only = null): void
{
    $categories = $only ?? [
        'logo' => 'Logo',
        'photo' => 'Photos',
        'document' => 'Documents',
    ];
    $accept = [
        'logo' => 'image/jpeg,image/png,image/webp',
        'photo' => 'image/jpeg,image/png,image/webp',
        'document' => 'application/pdf',
    ];

    foreach ($categories as $category => $label) {
        if (!isset($accept[$category])) {
            continue;
        }
        $multiple = $category !== 'logo';
        $fieldId = 'file-' . $category . ($requestId === null ? '-brief' : '-' . (string) $requestId);
        $files = $assets[$category] ?? [];
        echo '<div class="upload-group">';
        if ($files === []) {
            echo '<p class="meta" data-upload-empty>None yet.</p>';
        } else {
            echo '<ul class="files">';
            foreach ($files as $file) {
                echo '<li>' . webco_html((string) ($file['original_name'] ?? '')) . ' <span>('
                    . webco_html(webco_brief_size((int) ($file['size_bytes'] ?? 0))) . ')</span></li>';
            }
            echo '</ul>';
        }
        echo '<form class="upload" method="post" action="/brief-upload.php" enctype="multipart/form-data">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="category" value="' . webco_html($category) . '">';
        echo '<input type="hidden" name="ajax" value="1">';
        if ($requestId !== null) {
            echo '<input type="hidden" name="request_id" value="' . webco_html((string) $requestId) . '">';
        }
        echo '<label for="' . webco_html($fieldId) . '">Choose ' . webco_html(strtolower($label)) . '</label>';
        echo '<input id="' . webco_html($fieldId) . '" name="file" type="file" accept="'
            . webco_html($accept[$category]) . '"' . ($multiple ? ' multiple' : '') . '>';
        echo '<div data-upload-status role="status" aria-live="polite"></div>';
        echo '</form></div>';
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
      main { max-width: 64rem; margin: 0 auto; padding: 2.5rem 1.25rem 5rem; }
      h1, h2, h3 { font-family: Georgia, Palatino, serif; font-weight: 600; line-height: 1.2; }
      h1 { margin: 0; font-size: 2.4rem; }
      h2 { margin: 2rem 0 0; font-size: 1.6rem; }
      h3 { margin: 1.4rem 0 0; font-size: 1.2rem; }
      p { margin: 0.75rem 0 0; }
      .eyebrow { margin: 0; color: #0c6b62; font-size: 0.85rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
      .lead, .note { color: #3e4e58; }
      .status { margin-top: 0.8rem; font-weight: 650; }
      .hint { margin-top: 0.2rem; color: #3e4e58; font-size: 0.92rem; }
      .panel { margin-top: 0.4rem; }
      fieldset.call { margin: 1.2rem 0 0; padding: 0.9rem 1rem 1rem; border: 1px solid #d5e0dc; border-radius: 12px; background: #f7fbfa; }
      fieldset.call legend { padding: 0 0.3rem; font-weight: 700; }
      label.choice { display: inline-flex; align-items: center; gap: 0.35rem; margin: 0.4rem 1rem 0 0; font-weight: 650; }
      input[type="text"], input[type="tel"], select { display: block; width: 100%; box-sizing: border-box; margin-top: 0.35rem; padding: 0.65rem 0.75rem; border: 1px solid #d5e0dc; border-radius: 12px; font: inherit; }
      .request { margin-top: 1rem; padding: 0.9rem 1rem; border: 1px solid #d5e0dc; border-radius: 12px; }
      .request-status { margin-top: 0.2rem; font-weight: 700; }
      .summary { white-space: pre-wrap; }
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
      .reassurance, .package-line, .step-count, .review-label { color: #3e4e58; }
      .review-label, .step-count { font-weight: 650; }
      .card { margin-top: 1rem; padding: 1.1rem 1.15rem 1.25rem; background: #fff; border: 1px solid #d5e0dc; border-radius: 16px; }
      .progress { display: flex; gap: 0.35rem; margin: 1rem 0 0; padding: 0; list-style: none; }
      .progress li { flex: 1; height: 0.45rem; overflow: hidden; border-radius: 999px; background: #d5e0dc; color: transparent; }
      .progress li.done, .progress li.current { background: #0c6b62; }
      .step-layout.has-figure { display: flex; flex-direction: column; }
      .step-figure { order: -1; margin: 0.2rem 0 0.4rem; }
      .step-figure img { width: 100%; max-height: 9.5rem; object-fit: cover; object-position: top; border-radius: 12px; }
      .step-figure figcaption { margin-top: 0.35rem; color: #3e4e58; font-size: 0.92rem; }
      .pair-list { margin-top: 0.4rem; }
      .slot { margin-top: 0.9rem; padding: 0.85rem 0.9rem 1rem; border: 1px solid #d5e0dc; border-radius: 12px; background: #f7fbfa; }
      .slot-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem 0.75rem; }
      .slot-title { margin: 0; font-weight: 700; }
      .slot-remove, .add-pair { min-height: 2.75rem; margin: 0; }
      .add-pair { width: 100%; margin-top: 0.9rem; }
      .pair-status:empty { margin: 0; }
      .call-extra { display: none; }
      fieldset.call:has(input[value="yes"]:checked) .call-extra { display: block; }
      .upload-ok { color: #0c6b62; font-weight: 650; }
      .upload-fail { color: #8a3b2a; font-weight: 650; }
      .dock { position: sticky; bottom: 0; display: flex; gap: 0.6rem; margin-top: 0.4rem; padding: 0.75rem 0; background: #fff; }
      @media (min-width: 52rem) {
        .progress { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.35rem 0.8rem; }
        .progress li { height: auto; overflow: visible; background: transparent; color: #3e4e58; font-size: 0.92rem; }
        .progress li.current { color: #122028; font-weight: 700; }
        .progress li.done { color: #0c6b62; }
        .step-layout.has-figure { display: grid; grid-template-columns: minmax(0, 1fr) 17.5rem; gap: 1.25rem; align-items: start; }
        .step-figure { order: 0; margin: 0.4rem 0 0; }
        .step-figure img { max-height: none; object-fit: initial; }
        .dock { position: static; background: transparent; }
        .add-pair { width: auto; }
      }
    </style>';
}
