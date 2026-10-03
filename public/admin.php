<?php
/**
 * Staff project list. Sign-in uses WEBCO_ADMIN_PASSWORD from the private secrets file.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/projects.php';

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'admin.php') {
    webco_handle_admin();
}

function webco_handle_admin(): void
{
    webco_private_headers();
    webco_start_named_session('WEBCOADMIN');
    webco_admin_csrf();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        webco_admin_post();
    }

    if (webco_admin_signed_in()) {
        webco_admin_dashboard();
    }

    webco_admin_login(webco_admin_notice($_GET['notice'] ?? null));
}

function webco_admin_post(): void
{
    $action = $_POST['action'] ?? '';
    if (!is_string($action) || !webco_admin_csrf_ok()) {
        webco_admin_redirect('again');
    }

    if ($action === 'login') {
        webco_admin_login_post();
    }
    if (!webco_admin_signed_in()) {
        webco_admin_redirect('again');
    }
    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        webco_admin_redirect('');
    }
    if ($action === 'status') {
        webco_admin_status_post();
    }
    if ($action === 'clone_complete') {
        webco_admin_clone_post();
    }
    if ($action === 'notify') {
        webco_admin_notify_post();
    }
    if ($action === 'download') {
        webco_admin_download_post();
    }

    webco_admin_redirect('again');
}

function webco_admin_login_post(): void
{
    $fails = (int) ($_SESSION['fails'] ?? 0);
    if ($fails >= 8) {
        webco_admin_redirect('paused');
    }

    $expected = webco_admin_password();
    $given = $_POST['password'] ?? '';
    if (!is_string($given) || strlen($given) > 200) {
        $given = '';
    }
    if ($expected === null) {
        webco_admin_redirect('unconfigured');
    }

    $match = hash_equals(hash('sha256', $expected, true), hash('sha256', $given, true));
    if (!$match) {
        $_SESSION['fails'] = $fails + 1;
        webco_admin_redirect('rejected');
    }

    session_regenerate_id(true);
    $_SESSION = [
        'admin' => true,
        'csrf' => bin2hex(random_bytes(16)),
    ];
    webco_admin_redirect('');
}

function webco_admin_status_post(): void
{
    $projectId = webco_admin_project_id();
    if ($projectId === null) {
        webco_admin_redirect('again');
    }

    $db = webco_admin_db();
    if (!webco_advance_project_status($db, $projectId)) {
        webco_admin_redirect('again');
    }

    webco_admin_redirect('status');
}

function webco_admin_clone_post(): void
{
    $projectId = webco_admin_project_id();
    $packageId = $_POST['package_id'] ?? '';
    if ($projectId === null || !is_string($packageId)) {
        webco_admin_redirect('again');
    }

    $result = webco_record_cloned_package(webco_admin_db(), $projectId, $packageId);
    if ($result === 'saved') {
        webco_admin_redirect('cloned');
    }
    if ($result === 'duplicate') {
        webco_admin_redirect('clone_done');
    }

    webco_admin_redirect('again');
}

function webco_admin_notify_post(): void
{
    $projectId = webco_admin_project_id();
    if ($projectId === null) {
        webco_admin_redirect('again');
    }

    $db = webco_admin_db();
    webco_send_project_notifications($db, $projectId, null);
    $row = webco_project_notification_row($db, $projectId);
    if ($row === null) {
        webco_admin_redirect('again');
    }
    if ($row['customer_notified_at'] === null || $row['internal_notified_at'] === null) {
        webco_admin_redirect('notify_pending');
    }

    webco_admin_redirect('notified');
}

function webco_admin_download_post(): void
{
    $assetId = $_POST['asset_id'] ?? '';
    if (!is_string($assetId) || !preg_match('/^\d{1,12}$/', $assetId)) {
        webco_admin_redirect('again');
    }

    $db = webco_admin_db();
    $asset = webco_asset_download($db, (int) $assetId);
    if ($asset === null) {
        webco_admin_redirect('again');
    }

    $size = filesize($asset['path']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $asset['name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if (is_int($size)) {
        header('Content-Length: ' . (string) $size);
    }
    readfile($asset['path']);
    exit;
}

function webco_admin_dashboard(): void
{
    $db = webco_admin_db();
    try {
        $projects = webco_list_projects_for_admin($db);
    } catch (PDOException) {
        webco_admin_message('Projects are unavailable just now.');
    }

    $notice = webco_admin_notice($_GET['notice'] ?? null);
    $csrf = (string) $_SESSION['csrf'];

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow"><title>Projects</title>';
    echo webco_admin_styles();
    echo '</head><body><main>';
    echo '<div class="top"><div><p class="eyebrow">Webco Cloud</p><h1>Projects</h1></div>';
    echo '<form method="post" action="/admin.php"><input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
    echo '<input type="hidden" name="action" value="logout"><button class="quiet" type="submit">Sign out</button></form></div>';
    if ($notice !== '') {
        echo '<p class="notice" role="status">' . webco_html($notice) . '</p>';
    }
    if ($projects === []) {
        echo '<p>No projects yet. A project appears here after a paid order is confirmed.</p>';
    }

    foreach ($projects as $project) {
        webco_admin_project($project, $csrf);
    }

    echo '</main></body></html>';
    exit;
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_project(array $project, string $csrf): void
{
    $id = (int) ($project['id'] ?? 0);
    $status = (string) ($project['status'] ?? '');
    $customerSent = ($project['customer_notified_at'] ?? null) !== null;
    $internalSent = ($project['internal_notified_at'] ?? null) !== null;
    $pending = !$customerSent || !$internalSent;

    echo '<article class="card">';
    echo '<h2>' . webco_html((string) ($project['business_name'] ?? '')) . '</h2>';
    echo '<p class="meta">' . webco_html((string) ($project['order_public_id'] ?? '')) . '</p>';
    echo '<dl>';
    webco_admin_row('Contact', (string) ($project['contact_name'] ?? ''));
    webco_admin_row('Email', (string) ($project['email'] ?? ''));
    webco_admin_row('Phone', (string) ($project['phone'] ?? ''));
    webco_admin_row('Domain', (string) ($project['domain_name'] ?? ''));
    webco_admin_row('Package', (string) ($project['package_name'] ?? ''));
    webco_admin_row('Care', webco_admin_care_label((string) ($project['care_choice'] ?? '')));
    webco_admin_row('Vertical', webco_admin_vertical_label((string) ($project['vertical_code'] ?? '')));
    webco_admin_row('Paid', (string) ($project['paid_at'] ?? ''));
    webco_admin_row('Brief saved', (string) ($project['brief_updated_at'] ?? ''));
    webco_admin_row('Brief submitted', (string) ($project['submitted_at'] ?? ''));
    echo '</dl>';

    $summary = trim((string) ($project['summary'] ?? ''));
    echo '<h3>Note</h3>';
    echo '<p class="summary">' . ($summary === '' ? 'None yet.' : webco_html($summary)) . '</p>';

    webco_admin_next_action($project, $csrf);

    echo '<h3>Notifications</h3><p>';
    echo $customerSent ? 'Customer email sent. ' : 'Customer email pending. ';
    echo $internalSent ? 'Internal email sent.' : 'Internal email pending.';
    echo '</p>';
    if ($pending) {
        echo '<form method="post" action="/admin.php">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="action" value="notify">';
        echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
        echo '<button type="submit">Send pending notifications</button></form>';
    }

    $assets = is_array($project['assets'] ?? null) ? $project['assets'] : [];
    foreach (['logo' => 'Logos', 'photo' => 'Photos', 'document' => 'Documents'] as $category => $label) {
        echo '<h3>' . webco_html($label) . '</h3>';
        $files = is_array($assets[$category] ?? null) ? $assets[$category] : [];
        if ($files === []) {
            echo '<p class="meta">None yet.</p>';
            continue;
        }
        echo '<ul>';
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $assetId = (int) ($file['id'] ?? 0);
            echo '<li>' . webco_html((string) ($file['original_name'] ?? ''));
            echo ' <span class="meta">' . webco_html(webco_admin_size((int) ($file['size_bytes'] ?? 0))) . '</span> ';
            echo '<form method="post" action="/admin.php" class="inline">';
            echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
            echo '<input type="hidden" name="action" value="download">';
            echo '<input type="hidden" name="asset_id" value="' . webco_html((string) $assetId) . '">';
            echo '<button class="quiet" type="submit">Download</button></form></li>';
        }
        echo '</ul>';
    }

    echo '</article>';
}

function webco_admin_row(string $label, string $value): void
{
    echo '<div><dt>' . webco_html($label) . '</dt><dd>' . webco_html($value === '' ? '—' : $value) . '</dd></div>';
}

function webco_admin_login(string $notice): void
{
    if (webco_admin_password() === null) {
        webco_admin_message('Staff sign-in is not configured.');
    }

    $csrf = (string) $_SESSION['csrf'];
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow"><title>Projects</title>';
    echo webco_admin_styles();
    echo '</head><body><main class="narrow">';
    echo '<p class="eyebrow">Webco Cloud</p><h1>Projects</h1>';
    if ($notice !== '') {
        echo '<p class="notice" role="status">' . webco_html($notice) . '</p>';
    }
    echo '<form method="post" action="/admin.php">';
    echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
    echo '<input type="hidden" name="action" value="login">';
    echo '<label for="password">Password</label>';
    echo '<input id="password" name="password" type="password" autocomplete="current-password" required>';
    echo '<button type="submit">Sign in</button></form>';
    echo '</main></body></html>';
    exit;
}

function webco_admin_message(string $message): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow"><title>Projects</title>';
    echo webco_admin_styles();
    echo '</head><body><main class="narrow"><p class="eyebrow">Webco Cloud</p><h1>Projects</h1>';
    echo '<p>' . webco_html($message) . '</p></main></body></html>';
    exit;
}

function webco_admin_notice(mixed $notice): string
{
    $messages = [
        'status' => 'Status updated.',
        'cloned' => 'Clone recorded. The project is ready for build.',
        'clone_done' => 'This clone was already recorded.',
        'notified' => 'Notifications sent.',
        'notify_pending' => 'A notification is still pending. Check the mail settings and try again.',
        'rejected' => 'That password was not accepted.',
        'paused' => 'Sign-in is paused in this browser session.',
        'unconfigured' => 'Staff sign-in is not configured.',
        'again' => 'That action was not accepted. Try again.',
    ];
    if (!is_string($notice) || !isset($messages[$notice])) {
        return '';
    }

    return $messages[$notice];
}

function webco_admin_password(): ?string
{
    if (!defined('WEBCO_SECRETS_FILE') || !is_file(WEBCO_SECRETS_FILE)) {
        return null;
    }

    $name = 'WEBCO_ADMIN_PASSWORD';
    $variable = null;
    if (!defined($name)) {
        ob_start();
        require_once WEBCO_SECRETS_FILE;
        ob_end_clean();
        if (isset($$name) && is_string($$name)) {
            $variable = $$name;
        }
    }

    $password = webco_loaded_secret($name, $variable);
    if ($password === null || strlen($password) < 16 || strpbrk($password, "\r\n\0") !== false) {
        return null;
    }

    return $password;
}

function webco_admin_db(): PDO
{
    $db = webco_db();
    if (!$db instanceof PDO || !webco_ensure_orders_table($db) || !webco_ensure_project_tables($db)) {
        webco_admin_message('Projects are unavailable just now.');
    }

    return $db;
}

function webco_admin_signed_in(): bool
{
    return ($_SESSION['admin'] ?? false) === true;
}

function webco_admin_project_id(): ?int
{
    $id = $_POST['project_id'] ?? '';
    if (!is_string($id) || !preg_match('/^\d{1,12}$/', $id)) {
        return null;
    }

    $id = (int) $id;

    return $id > 0 ? $id : null;
}

function webco_admin_csrf(): void
{
    $csrf = $_SESSION['csrf'] ?? '';
    if (!is_string($csrf) || strlen($csrf) !== 32) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

function webco_admin_csrf_ok(): bool
{
    $sent = $_POST['csrf'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || !is_string($known) || strlen($sent) !== 32 || strlen($known) !== 32) {
        return false;
    }

    return hash_equals($known, $sent);
}

function webco_admin_redirect(string $notice): void
{
    $target = '/admin.php';
    if ($notice !== '') {
        $target .= '?notice=' . rawurlencode($notice);
    }
    header('Location: ' . $target, true, 303);
    exit;
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_next_action(array $project, string $csrf): void
{
    $id = (int) ($project['id'] ?? 0);
    $status = (string) ($project['status'] ?? '');
    $packageId = webco_project_package_id($project['twentyi_package_id'] ?? null);
    $briefReady = webco_project_brief_is_ready(
        is_string($project['summary'] ?? null) ? $project['summary'] : null,
        $project['submitted_at'] ?? null
    );

    echo '<div class="next">';
    echo '<p class="eyebrow">Current status</p>';
    echo '<p class="now">' . webco_html(webco_project_status_label($status)) . '</p>';
    if ($packageId !== null) {
        echo '<p class="meta">20i package ' . webco_html($packageId) . '</p>';
    }

    if ($status === 'awaiting_brief' || $status === 'brief_in_progress') {
        echo '<p>Next: the customer submits the brief.</p></div>';

        return;
    }
    if ($status === 'brief_received' && !$briefReady) {
        echo '<p>Next: the brief needs a submitted note before it can be marked ready for clone.</p></div>';

        return;
    }
    if ($status === 'brief_received') {
        echo '<p>Next: mark this project ready for the manual 20i clone.</p>';
        echo '<form method="post" action="/admin.php">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="action" value="status">';
        echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
        echo '<button type="submit">Mark ready for clone</button></form></div>';

        return;
    }
    if ($status === 'ready_for_clone' && $packageId === null) {
        echo '<p>Next: clone the template in My20i, then save the new hosting package id.</p>';
        echo '<form method="post" action="/admin.php">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="action" value="clone_complete">';
        echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
        echo '<label for="package-' . webco_html((string) $id) . '">20i package id</label>';
        echo '<input id="package-' . webco_html((string) $id) . '" name="package_id" inputmode="numeric" maxlength="12" required>';
        echo '<button type="submit">Save package and mark ready for build</button></form></div>';

        return;
    }
    if ($status === 'ready_for_clone') {
        echo '<p>The package id is already stored. Clone completion cannot be recorded again.</p></div>';

        return;
    }
    if ($status === 'live') {
        echo '<p>This website is live.</p></div>';

        return;
    }

    $next = webco_project_workflow_next($status);
    if ($next === null) {
        echo '<p>No status change is available from here.</p></div>';

        return;
    }

    echo '<p>Next: move this project to ' . webco_html(webco_project_status_label($next)) . '.</p>';
    echo '<form method="post" action="/admin.php">';
    echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
    echo '<input type="hidden" name="action" value="status">';
    echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
    echo '<button type="submit">' . webco_html(webco_project_status_label($next)) . '</button></form></div>';
}

function webco_admin_care_label(string $care): string
{
    if ($care === 'managed') {
        return 'Managed Care';
    }
    if ($care === 'standard') {
        return 'Annual hosting';
    }

    return $care;
}

function webco_admin_vertical_label(string $code): string
{
    if ($code === 'hgv_training') {
        return 'HGV training';
    }

    return $code;
}

function webco_admin_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    return (string) max(1, (int) round($bytes / 1024)) . ' KB';
}

function webco_admin_styles(): string
{
    return '<style>
      :root { color-scheme: light; }
      body { margin: 0; background: #f3f6f5; color: #122028; font: 1rem/1.5 "Segoe UI", Helvetica, Arial, sans-serif; }
      main { max-width: 52rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
      main.narrow { max-width: 28rem; }
      h1, h2, h3 { font-family: Georgia, Palatino, serif; font-weight: 600; line-height: 1.2; }
      h1 { margin: 0; font-size: 2.2rem; }
      h2 { margin: 0; font-size: 1.5rem; }
      h3 { margin: 1.2rem 0 0; font-size: 1.05rem; }
      p { margin: 0.6rem 0 0; }
      .eyebrow { margin: 0; color: #0c6b62; font-size: 0.8rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
      .top { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; }
      .card { margin-top: 1.25rem; padding: 1.1rem 1.2rem 1.3rem; background: #fff; border: 1px solid #d5e0dc; border-radius: 16px; }
      .meta { color: #3e4e58; }
      .notice { padding: 0.8rem 1rem; background: #e5f3f1; border-radius: 12px; }
      .next { margin-top: 1rem; padding: 0.9rem 1rem; background: #f3f6f5; border-radius: 12px; }
      .next .now { margin: 0.15rem 0 0; font-size: 1.15rem; }
      .summary { white-space: pre-wrap; }
      dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 0.7rem 1rem; margin: 1rem 0 0; }
      dl div { margin: 0; }
      dt { color: #3e4e58; font-size: 0.82rem; }
      dd { margin: 0.1rem 0 0; }
      label { display: block; margin-top: 0.6rem; font-weight: 650; }
      input[type="password"], input[name="package_id"], select { display: block; width: 100%; max-width: 22rem; margin-top: 0.35rem; padding: 0.55rem 0.7rem; border: 1px solid #d5e0dc; border-radius: 10px; font: inherit; }
      button { margin-top: 0.7rem; padding: 0.55rem 0.9rem; border: 0; border-radius: 999px; background: #0c6b62; color: #f7fbfa; font: inherit; cursor: pointer; }
      button.quiet { background: #fff; color: #122028; border: 1px solid #d5e0dc; }
      form.inline { display: inline; }
      form.inline button { margin-top: 0; }
      ul { margin: 0.35rem 0 0; padding-left: 1.1rem; }
      li { margin: 0.25rem 0; }
    </style>';
}
