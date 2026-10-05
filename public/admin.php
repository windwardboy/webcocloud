<?php
/**
 * Staff project list. Sign-in uses WEBCO_ADMIN_PASSWORD from the private secrets file.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/brief-wizard.php';
require_once __DIR__ . '/lib/billing-portal.php';

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
    if ($action === 'request') {
        webco_admin_request_post();
    }
    if ($action === 'archive') {
        webco_admin_archive_post(false);
    }
    if ($action === 'restore') {
        webco_admin_archive_post(true);
    }
    if ($action === 'delete_test') {
        webco_admin_delete_post();
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

function webco_admin_request_post(): void
{
    $requestId = $_POST['request_id'] ?? '';
    if (!is_string($requestId) || !preg_match('/^\d{1,12}$/', $requestId)) {
        webco_admin_redirect('again');
    }

    if (!webco_advance_request_status(webco_admin_db(), (int) $requestId)) {
        webco_admin_redirect('again');
    }

    webco_admin_redirect('request');
}

function webco_admin_archive_post(bool $restore): void
{
    $projectId = webco_admin_project_id();
    if ($projectId === null || !webco_archive_project(webco_admin_db(), $projectId, $restore)) {
        webco_admin_redirect('again');
    }

    webco_admin_redirect($restore ? 'restored' : 'archived');
}

function webco_admin_delete_post(): void
{
    $projectId = webco_admin_project_id();
    $confirmation = $_POST['confirm_id'] ?? '';
    if ($projectId === null || !is_string($confirmation) || strlen($confirmation) > 40) {
        webco_admin_redirect('delete_refused');
    }

    $result = webco_delete_test_project(webco_admin_db(), $projectId, $confirmation);
    if ($result === 'deleted') {
        unset($_POST['return_project']);
    }
    webco_admin_redirect($result === 'deleted' ? 'deleted' : 'delete_refused');
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
    $view = webco_admin_requested_view();
    $projectId = webco_admin_requested_project();

    webco_admin_page_open(false);
    echo '<header class="bar"><div><p class="eyebrow">Webco Cloud · Admin</p><h1>Projects</h1></div>';
    echo '<form method="post" action="/admin.php"><input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
    echo '<input type="hidden" name="action" value="logout"><button class="btn btn-secondary btn-sm" type="submit">Sign out</button></form></header>';
    if ($notice !== '') {
        echo '<p class="notice" role="status">' . webco_html($notice) . '</p>';
    }

    if ($projectId !== null) {
        $project = webco_admin_find_project($projects, $projectId);
        if ($project === null) {
            echo '<p>That project was not found.</p>';
            echo '<p><a href="' . webco_html(webco_admin_view_href($view)) . '">Back to ' . webco_html(webco_admin_view_label($view)) . '</a></p>';
        } else {
            webco_admin_project($project, $csrf, $view);
        }
        echo '</main></body></html>';
        exit;
    }

    webco_admin_summary($projects);
    webco_admin_view_nav($projects, $view);
    $visible = [];
    foreach ($projects as $project) {
        if (webco_project_in_admin_view($project, $view)) {
            $visible[] = $project;
        }
    }
    if ($projects === []) {
        echo '<p class="empty">No projects yet. A project appears here after a paid order is confirmed.</p>';
    } elseif ($visible === []) {
        echo '<p class="empty">Nothing in ' . webco_html(webco_admin_view_label($view)) . '.</p>';
    }
    echo '<div class="rows">';
    foreach ($visible as $project) {
        webco_admin_project_row($project, $csrf, $view);
    }
    echo '</div>';

    echo '</main></body></html>';
    exit;
}

function webco_admin_page_open(bool $narrow): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow"><title>Projects</title>';
    echo webco_admin_styles();
    echo '</head><body><main' . ($narrow ? ' class="narrow"' : '') . '>';
}

/**
 * Compact status tag. Tone is '' (neutral), 'flag' (needs a look), 'ok' or 'quiet'.
 * The words carry the meaning, so colour is only a hint.
 */
function webco_admin_badge(string $text, string $tone = ''): string
{
    $class = 'badge' . ($tone !== '' ? ' badge-' . $tone : '');

    return '<span class="' . $class . '">' . webco_html($text) . '</span>';
}

function webco_admin_open_label(int $open): string
{
    return $open === 1 ? '1 open request' : (string) $open . ' open requests';
}

/**
 * @param list<array<string, mixed>> $projects
 */
function webco_admin_find_project(array $projects, int $projectId): ?array
{
    foreach ($projects as $project) {
        if ((int) ($project['id'] ?? 0) === $projectId) {
            return $project;
        }
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $projects
 */
function webco_admin_summary(array $projects): void
{
    $counts = [
        'attention' => 0,
        'brief_received' => 0,
        'call_requested' => 0,
        'ready_for_clone' => 0,
        'ready_for_build' => 0,
        'review' => 0,
        'open_requests' => 0,
    ];
    foreach ($projects as $project) {
        if (webco_project_is_archived($project)) {
            continue;
        }
        $reasons = webco_project_attention_reasons($project);
        if ($reasons !== []) {
            $counts['attention']++;
        }
        foreach (['brief_received', 'ready_for_clone', 'ready_for_build', 'review', 'call_requested'] as $reason) {
            if (in_array($reason, $reasons, true)) {
                $counts[$reason]++;
            }
        }
        $counts['open_requests'] += webco_project_open_request_count($project);
    }

    $items = [
        ['brief_received', $counts['brief_received'] === 1 ? 'brief received' : 'briefs received', false],
        ['call_requested', $counts['call_requested'] === 1 ? 'call requested' : 'calls requested', true],
        ['ready_for_clone', 'ready for clone', false],
        ['ready_for_build', 'ready for build', false],
        ['review', 'in review', false],
        ['open_requests', $counts['open_requests'] === 1 ? 'open request' : 'open requests', true],
    ];

    echo '<section class="attention" aria-labelledby="attention-heading">';
    echo '<div class="attention-total"><h2 id="attention-heading" class="eyebrow">Needs attention</h2>';
    echo '<p class="total"><strong>' . (string) $counts['attention'] . '</strong> '
        . ($counts['attention'] === 1 ? 'project needs attention' : 'projects need attention') . '</p></div>';
    echo '<ul class="counts">';
    foreach ($items as [$key, $label, $flag]) {
        $class = 'count' . ($flag && $counts[$key] > 0 ? ' is-flag' : '') . ($counts[$key] === 0 ? ' is-zero' : '');
        echo '<li class="' . $class . '"><strong>' . (string) $counts[$key] . '</strong> <span>' . webco_html($label) . '</span></li>';
    }
    echo '</ul></section>';
}

/**
 * @param list<array<string, mixed>> $projects
 */
function webco_admin_view_nav(array $projects, string $current): void
{
    echo '<nav class="views" aria-label="Project views">';
    foreach (webco_admin_view_labels() as $view => $label) {
        $count = 0;
        foreach ($projects as $project) {
            if (webco_project_in_admin_view($project, $view)) {
                $count++;
            }
        }
        $currentAttr = $view === $current ? ' aria-current="page"' : '';
        echo '<a href="' . webco_html(webco_admin_view_href($view)) . '"' . $currentAttr . '>'
            . webco_html($label) . ' <span class="tab-count">' . (string) $count . '</span></a>';
    }
    echo '</nav>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_project_row(array $project, string $csrf, string $view): void
{
    $id = (int) ($project['id'] ?? 0);
    $status = (string) ($project['status'] ?? '');
    $open = webco_project_open_request_count($project);
    $call = (int) ($project['call_requested'] ?? 0) === 1;
    $href = '/admin.php?project=' . rawurlencode((string) $id) . '&view=' . rawurlencode($view);

    $name = (string) ($project['business_name'] ?? '');

    echo '<article class="row">';
    echo '<div class="row-main">';
    echo '<h2>' . webco_html($name) . '</h2>';
    echo '<p class="meta">' . webco_html(webco_admin_dash((string) ($project['domain_name'] ?? '')))
        . ' · ' . webco_html(webco_admin_dash((string) ($project['package_name'] ?? '')))
        . ' · ' . webco_html(webco_admin_care_label((string) ($project['care_choice'] ?? ''))) . '</p>';
    echo '<p class="meta">Paid ' . webco_html(webco_admin_paid_label((string) ($project['paid_at'] ?? ''))) . '</p>';
    echo '</div>';
    echo '<div class="row-state"><div class="badges">';
    echo webco_admin_badge(webco_project_status_label($status), $status === 'live' ? 'ok' : '');
    if (webco_project_is_archived($project)) {
        echo webco_admin_badge('Archived', 'quiet');
    }
    if ($call) {
        echo webco_admin_badge('Call requested', 'flag');
    }
    if ($open > 0) {
        echo webco_admin_badge(webco_admin_open_label($open), 'flag');
    }
    echo '</div>';
    if ($open === 0) {
        echo '<p class="meta">No open requests</p>';
    }
    echo '</div>';
    echo '<p class="row-next"><span>Next</span> ' . webco_html(webco_admin_next_label($project)) . '</p>';
    echo '<div class="row-actions">';
    if (webco_project_is_archived($project)) {
        echo '<form method="post" action="/admin.php" class="row-restore">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="action" value="restore">';
        echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
        webco_admin_context_fields($view, $id);
        echo '<button class="btn btn-secondary btn-sm" type="submit">Restore</button></form>';
    }
    // The link is stretched over the whole row in CSS, so the row is clickable but there is still one real link.
    echo '<a class="btn btn-secondary btn-sm view-link" href="' . webco_html($href) . '">View project'
        . '<span class="sr-only"> ' . webco_html($name) . '</span></a>';
    echo '</div></article>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_project(array $project, string $csrf, string $view = 'attention'): void
{
    $id = (int) ($project['id'] ?? 0);
    $status = (string) ($project['status'] ?? '');
    $archived = webco_project_is_archived($project);
    $open = webco_project_open_request_count($project);

    echo '<p class="back"><a href="' . webco_html(webco_admin_view_href($view)) . '">Back to '
        . webco_html(webco_admin_view_label($view)) . '</a></p>';
    echo '<article class="project">';

    echo '<section class="panel project-head" aria-labelledby="project-title">';
    echo '<div class="head-line"><h2 id="project-title">' . webco_html((string) ($project['business_name'] ?? '')) . '</h2>';
    echo '<div class="badges">' . webco_admin_badge(webco_project_status_label($status), $status === 'live' ? 'ok' : '');
    if ($archived) {
        echo webco_admin_badge('Archived', 'quiet');
    }
    if ((int) ($project['call_requested'] ?? 0) === 1) {
        echo webco_admin_badge('Call requested', 'flag');
    }
    if ($open > 0) {
        echo webco_admin_badge(webco_admin_open_label($open), 'flag');
    }
    echo '</div></div>';
    echo '<p class="ref">' . webco_html((string) ($project['order_public_id'] ?? '')) . '</p>';
    if ($archived) {
        echo '<p class="archived-note">Archived. The build status, payment, files and requests are unchanged.</p>';
    }
    echo '<dl class="meta-grid">';
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
    echo '</dl></section>';

    webco_admin_next_action($project, $csrf, $view);
    webco_admin_callout($project);

    echo '<div class="detail-grid"><div class="detail-main">';
    webco_admin_requests($project, $csrf, $view);
    echo '</div><div class="detail-side">';
    webco_admin_billing_debug();
    webco_admin_notifications($project, $csrf, $view);
    webco_admin_files($project, $csrf, $view);
    echo '</div></div>';

    webco_admin_brief($project);

    echo '<section class="admin-zone" aria-labelledby="admin-zone-title">';
    echo '<h3 id="admin-zone-title" class="eyebrow">Project administration</h3><div class="zone-grid">';
    webco_admin_archive_panel($project, $csrf, $view);
    webco_admin_delete_panel($project, $csrf, $view);
    echo '</div></section>';

    echo '</article>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_notifications(array $project, string $csrf, string $view): void
{
    $id = (int) ($project['id'] ?? 0);
    $customerSent = ($project['customer_notified_at'] ?? null) !== null;
    $internalSent = ($project['internal_notified_at'] ?? null) !== null;
    $pending = !$customerSent || !$internalSent;

    echo '<section class="panel panel-quiet" aria-labelledby="notifications-title"><h3 id="notifications-title">Notifications</h3>';
    echo '<p class="badges">';
    echo webco_admin_badge($customerSent ? 'Customer email sent' : 'Customer email pending', $customerSent ? 'quiet' : 'flag');
    echo webco_admin_badge($internalSent ? 'Internal email sent' : 'Internal email pending', $internalSent ? 'quiet' : 'flag');
    echo '</p>';
    if ($pending) {
        echo '<form method="post" action="/admin.php">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="action" value="notify">';
        echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
        webco_admin_context_fields($view, $id);
        echo '<button class="btn btn-secondary btn-sm" type="submit">Send pending notifications</button></form>';
    }
    echo '</section>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_files(array $project, string $csrf, string $view): void
{
    $id = (int) ($project['id'] ?? 0);
    $assets = is_array($project['assets'] ?? null) ? $project['assets'] : [];

    echo '<section class="panel" aria-labelledby="files-title"><h3 id="files-title">Files</h3>';
    foreach (['logo' => 'Logos', 'photo' => 'Photos', 'document' => 'Documents'] as $category => $label) {
        $files = is_array($assets[$category] ?? null) ? $assets[$category] : [];
        echo '<h4 class="group-title">' . webco_html($label) . ' <span class="tab-count">' . (string) count($files) . '</span></h4>';
        if ($files === []) {
            echo '<p class="meta">None yet.</p>';
            continue;
        }
        echo '<ul class="file-list">';
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $assetId = (int) ($file['id'] ?? 0);
            echo '<li><div class="file-info"><span class="file-name">' . webco_html((string) ($file['original_name'] ?? '')) . '</span>';
            echo '<span class="meta">' . webco_html(webco_admin_size((int) ($file['size_bytes'] ?? 0)));
            $fileRequest = $file['request_id'] ?? null;
            if (is_int($fileRequest) || (is_string($fileRequest) && ctype_digit($fileRequest))) {
                echo ' · Request ' . webco_html((string) $fileRequest);
            }
            echo '</span></div>';
            echo '<form method="post" action="/admin.php">';
            echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
            echo '<input type="hidden" name="action" value="download">';
            echo '<input type="hidden" name="asset_id" value="' . webco_html((string) $assetId) . '">';
            webco_admin_context_fields($view, $id);
            echo '<button class="btn btn-secondary btn-sm" type="submit">Download'
                . '<span class="sr-only"> ' . webco_html((string) ($file['original_name'] ?? '')) . '</span></button></form></li>';
        }
        echo '</ul>';
    }
    echo '</section>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_archive_panel(array $project, string $csrf, string $view): void
{
    $id = (int) ($project['id'] ?? 0);
    $status = (string) ($project['status'] ?? '');
    $archived = webco_project_is_archived($project);

    echo '<section class="panel panel-quiet" aria-labelledby="archive-title"><h3 id="archive-title">Archive</h3>';
    if ($archived) {
        echo '<p>Restore puts this project back into the active dashboard. Its build status stays '
            . webco_html(webco_project_status_label($status)) . '.</p>';
    } else {
        echo '<p>Archive hides this project from Needs attention, Active and Live. It does not change the build status, payment, files or requests.</p>';
    }
    echo '<form method="post" action="/admin.php">';
    echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
    echo '<input type="hidden" name="action" value="' . ($archived ? 'restore' : 'archive') . '">';
    echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
    webco_admin_context_fields($view, $id);
    echo '<button class="btn btn-secondary btn-sm" type="submit">' . ($archived ? 'Restore project' : 'Archive project') . '</button></form>';
    echo '</section>';
}

function webco_admin_billing_debug(): void
{
    $latest = webco_billing_debug_latest();
    echo '<section class="panel panel-quiet" aria-labelledby="billing-title"><h3 id="billing-title">Billing check</h3>';
    if ($latest === null) {
        echo '<p class="meta">No billing check has been recorded yet.</p></section>';

        return;
    }

    echo '<p class="meta">' . webco_html($latest['at']) . '</p>';
    echo '<p class="mono">' . webco_html($latest['marker']) . '</p></section>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_callout(array $project): void
{
    if ((int) ($project['call_requested'] ?? 0) !== 1) {
        return;
    }

    echo '<section class="callout" aria-labelledby="callout-title">';
    echo '<div class="callout-head"><h3 id="callout-title">Phone call requested</h3>' . webco_admin_badge('Call requested', 'flag') . '</div>';
    echo '<p>The customer wants a call before the website starts.</p>';
    echo '<dl class="fields fields-row">';
    echo webco_admin_field('Number', (string) ($project['call_number'] ?? ''), true);
    echo webco_admin_field('Time', (string) ($project['call_time'] ?? ''), true);
    echo webco_admin_field('Note', (string) ($project['call_note'] ?? ''), true);
    echo '</dl></section>';
}

/**
 * One label and value pair. Empty values are left out unless $always is set.
 */
function webco_admin_field(string $label, string $value, bool $always = false): string
{
    $value = trim($value);
    if ($value === '' && !$always) {
        return '';
    }

    return '<div><dt>' . webco_html($label) . '</dt><dd class="summary">'
        . ($value === '' ? 'None yet.' : webco_html($value)) . '</dd></div>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_brief(array $project): void
{
    $courses = trim((string) ($project['course_entries'] ?? ''));
    $further = trim((string) ($project['location_entries'] ?? ''));
    $anything = [['Anything else', (string) ($project['summary'] ?? '')]];
    foreach ([
        'goals' => 'Earlier note about website goals',
        'style_tone' => 'Earlier note about style',
        'liked_sites' => 'Earlier note about other websites',
        'required_pages' => 'Earlier note about pages',
    ] as $key => $label) {
        $legacy = trim((string) ($project[$key] ?? ''));
        if ($legacy !== '') {
            $anything[] = [$label, $legacy];
        }
    }

    $groups = [
        'Your business' => [
            ['Who they are', (string) ($project['business_overview'] ?? '')],
            ['How long operating', (string) ($project['years_operating'] ?? '')],
            ['Experience and credentials', (string) ($project['credentials'] ?? '')],
            ['What visitors should understand first', (string) ($project['first_impression'] ?? '')],
        ],
        'Courses' => [
            ['Courses', $courses !== '' ? webco_brief_pairs_plain($courses) : (string) ($project['services'] ?? '')],
        ],
        'Locations' => [
            ['Main location', (string) ($project['locations'] ?? '')],
            ['Areas served', (string) ($project['areas_served'] ?? '')],
            ['Further locations', $further !== '' ? webco_brief_pairs_plain($further) : ''],
        ],
        'Why customers should choose you' => [
            ['Experience', (string) ($project['why_experience'] ?? '')],
            ['Vehicles, equipment or facilities', (string) ($project['why_facilities'] ?? '')],
            ['Flexibility', (string) ($project['why_flexibility'] ?? '')],
            ['Customer support', (string) ($project['why_support'] ?? '')],
            ['What sets them apart', (string) ($project['why_difference'] ?? '')],
        ],
        'Branding' => [
            ['Brand colours they already use', (string) ($project['branding'] ?? '')],
        ],
        'Contact preferences' => [
            ['Preferred enquiry route', webco_brief_enquiry_label((string) ($project['enquiry_route'] ?? ''))],
            ['Contact details', (string) ($project['contact_details'] ?? '')],
            ['Opening hours', (string) ($project['opening_hours'] ?? '')],
        ],
        'Anything else' => $anything,
    ];

    echo '<section class="panel" aria-labelledby="brief-title"><h3 id="brief-title">Website brief</h3>';
    echo '<div class="brief-groups">';
    foreach ($groups as $title => $fields) {
        $html = '';
        foreach ($fields as [$label, $value]) {
            // The Anything else note is always listed, as before; every other empty answer is left out.
            $html .= webco_admin_field($label, $value, $title === 'Anything else' && $label === 'Anything else');
        }
        if ($html === '') {
            continue;
        }
        echo '<section class="brief-group"><h4 class="group-title">' . webco_html($title) . '</h4><dl class="fields">' . $html . '</dl></section>';
    }
    echo '</div></section>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_requests(array $project, string $csrf, string $view): void
{
    $requests = is_array($project['requests'] ?? null) ? $project['requests'] : [];
    $assets = is_array($project['assets'] ?? null) ? $project['assets'] : [];
    $active = [];
    $done = [];
    foreach ($requests as $request) {
        if (!is_array($request)) {
            continue;
        }
        if ((string) ($request['status'] ?? '') === 'done') {
            $done[] = $request;
        } else {
            $active[] = $request;
        }
    }

    echo '<section class="panel" aria-labelledby="requests-title"><h3 id="requests-title">Client requests</h3>';
    echo '<p class="meta">These are separate from the website build status.</p>';
    if ($active === [] && $done === []) {
        echo '<p class="meta">No requests yet.</p></section>';

        return;
    }

    foreach ($active as $request) {
        webco_admin_request($request, $csrf, true, $view, (int) ($project['id'] ?? 0), $assets);
    }
    foreach ($done as $request) {
        webco_admin_request($request, $csrf, false, $view, (int) ($project['id'] ?? 0), $assets);
    }
    echo '</section>';
}

/**
 * @param array<string, mixed> $request
 * @param array<string, mixed> $assets
 */
function webco_admin_request(array $request, string $csrf, bool $action, string $view, int $projectId, array $assets = []): void
{
    $id = (int) ($request['id'] ?? 0);
    $status = (string) ($request['status'] ?? '');
    $next = webco_request_status_next($status);
    $typeLabel = webco_request_type_label((string) ($request['request_type'] ?? ''));
    $tone = $status === 'open' ? 'flag' : ($status === 'done' ? 'quiet' : '');

    echo '<article class="request' . ($status === 'done' ? ' is-done' : '') . '">';
    echo '<div class="request-head"><h4>' . webco_html($typeLabel) . '</h4><div class="badges">';
    echo webco_admin_badge(webco_request_status_label($status), $tone);
    if ((int) ($request['call_requested'] ?? 0) === 1) {
        echo webco_admin_badge('Call requested', 'flag');
    }
    echo '</div></div>';
    $date = webco_admin_paid_label((string) ($request['created_at'] ?? ''));
    if ($date !== '—') {
        echo '<p class="meta request-date">' . webco_html($date) . '</p>';
    }
    echo '<p class="summary">' . webco_html(trim((string) ($request['summary'] ?? ''))) . '</p>';
    if ((int) ($request['call_requested'] ?? 0) === 1) {
        $fields = webco_admin_field('Number', (string) ($request['call_number'] ?? ''))
            . webco_admin_field('Time', (string) ($request['call_time'] ?? ''))
            . webco_admin_field('Note', (string) ($request['call_note'] ?? ''));
        if ($fields !== '') {
            echo '<dl class="fields fields-row call-box">' . $fields . '</dl>';
        }
    }
    $names = [];
    foreach ($assets as $files) {
        if (!is_array($files)) {
            continue;
        }
        foreach ($files as $file) {
            if (is_array($file) && $id > 0 && (int) ($file['request_id'] ?? 0) === $id) {
                $names[] = (string) ($file['original_name'] ?? '');
            }
        }
    }
    if ($names !== []) {
        echo '<p class="meta">Files with this request: ' . webco_html(implode(', ', $names)) . ' (download under Files)</p>';
    }
    if ($action && $next !== null) {
        echo '<form method="post" action="/admin.php" class="request-action">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="action" value="request">';
        echo '<input type="hidden" name="request_id" value="' . webco_html((string) $id) . '">';
        webco_admin_context_fields($view, $projectId);
        echo '<button class="btn btn-primary btn-sm" type="submit">Mark ' . webco_html(strtolower(webco_request_status_label($next)))
            . '<span class="sr-only"> for ' . webco_html($typeLabel) . ' request</span></button>';
        echo '</form>';
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
    webco_admin_page_open(true);
    echo '<p class="eyebrow">Webco Cloud · Admin</p><h1>Projects</h1>';
    if ($notice !== '') {
        echo '<p class="notice" role="status">' . webco_html($notice) . '</p>';
    }
    echo '<form method="post" action="/admin.php" class="panel login">';
    echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
    echo '<input type="hidden" name="action" value="login">';
    echo '<label for="password">Password</label>';
    echo '<input id="password" name="password" type="password" autocomplete="current-password" required>';
    echo '<button class="btn btn-primary" type="submit">Sign in</button></form>';
    echo '</main></body></html>';
    exit;
}

function webco_admin_message(string $message): void
{
    webco_admin_page_open(true);
    echo '<p class="eyebrow">Webco Cloud · Admin</p><h1>Projects</h1>';
    echo '<p>' . webco_html($message) . '</p></main></body></html>';
    exit;
}

function webco_admin_notice(mixed $notice): string
{
    $messages = [
        'status' => 'Status updated.',
        'request' => 'Request status updated.',
        'archived' => 'Project archived. Its build status is unchanged.',
        'restored' => 'Project restored.',
        'deleted' => 'Test project removed. The paid order was kept, and Stripe and 20i were not contacted.',
        'delete_refused' => 'That project was not deleted. It must start with [TEST], use a reserved test email, have no 20i package id, and the confirmation must match the order id.',
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
    $params = [];
    if ($notice !== '') {
        $params['notice'] = $notice;
    }
    $view = $_POST['return_view'] ?? '';
    if (is_string($view) && isset(webco_admin_view_labels()[$view])) {
        $params['view'] = $view;
    }
    $project = $_POST['return_project'] ?? '';
    if (is_string($project) && preg_match('/^\d{1,12}$/', $project) === 1) {
        $params['project'] = $project;
    }
    $target = '/admin.php';
    if ($params !== []) {
        $target .= '?' . http_build_query($params);
    }
    header('Location: ' . $target, true, 303);
    exit;
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_next_action(array $project, string $csrf, string $view): void
{
    $id = (int) ($project['id'] ?? 0);
    $status = (string) ($project['status'] ?? '');
    $packageId = webco_project_package_id($project['twentyi_package_id'] ?? null);
    $details = [];
    foreach (webco_brief_detail_columns() as $column) {
        $value = $project[$column] ?? null;
        $details[] = is_string($value) ? $value : null;
    }
    $briefReady = webco_project_brief_is_ready(
        is_string($project['summary'] ?? null) ? $project['summary'] : null,
        $project['submitted_at'] ?? null,
        $details
    );

    echo '<section class="status-panel" aria-labelledby="status-title">';
    echo '<div class="status-now"><h3 id="status-title" class="eyebrow">Current status</h3>';
    echo '<p class="now">' . webco_html(webco_project_status_label($status)) . '</p>';
    if ($packageId !== null) {
        echo '<p class="meta">20i package ' . webco_html($packageId) . '</p>';
    }
    echo '</div><div class="status-next">';

    if ($status === 'awaiting_brief' || $status === 'brief_in_progress') {
        echo '<p>Next: the customer submits the brief.</p></div></section>';

        return;
    }
    if ($status === 'brief_received' && !$briefReady) {
        echo '<p>Next: the brief needs a submitted note before it can be marked ready for clone.</p></div></section>';

        return;
    }
    if ($status === 'brief_received') {
        echo '<p>Next: mark this project ready for the manual 20i clone.</p>';
        echo '<form method="post" action="/admin.php">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="action" value="status">';
        echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
        webco_admin_context_fields($view, $id);
        echo '<button class="btn btn-primary" type="submit">Mark ready for clone</button></form></div></section>';

        return;
    }
    if ($status === 'ready_for_clone' && $packageId === null) {
        echo '<p>Next: clone the template in My20i, then save the new hosting package id.</p>';
        echo '<form method="post" action="/admin.php" class="package-form">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="action" value="clone_complete">';
        echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
        webco_admin_context_fields($view, $id);
        echo '<label for="package-' . webco_html((string) $id) . '">20i package id</label>';
        echo '<input id="package-' . webco_html((string) $id) . '" name="package_id" inputmode="numeric" maxlength="12" required>';
        echo '<button class="btn btn-primary" type="submit">Save package and mark ready for build</button></form></div></section>';

        return;
    }
    if ($status === 'ready_for_clone') {
        echo '<p>The package id is already stored. Clone completion cannot be recorded again.</p></div></section>';

        return;
    }
    if ($status === 'live') {
        echo '<p>This website is live.</p></div></section>';

        return;
    }

    $next = webco_project_workflow_next($status);
    if ($next === null) {
        echo '<p>No status change is available from here.</p></div></section>';

        return;
    }

    echo '<p>Next: move this project to ' . webco_html(webco_project_status_label($next)) . '.</p>';
    echo '<form method="post" action="/admin.php">';
    echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
    echo '<input type="hidden" name="action" value="status">';
    echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
    webco_admin_context_fields($view, $id);
    echo '<button class="btn btn-primary" type="submit">' . webco_html(webco_project_status_label($next)) . '</button></form></div></section>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_delete_panel(array $project, string $csrf, string $view): void
{
    $id = (int) ($project['id'] ?? 0);
    $publicId = (string) ($project['order_public_id'] ?? '');
    $allowed = webco_project_test_delete_allowed(
        (string) ($project['business_name'] ?? ''),
        (string) ($project['email'] ?? ''),
        $project['twentyi_package_id'] ?? null
    );

    echo '<section class="panel ' . ($allowed ? 'panel-danger' : 'panel-quiet') . '" aria-labelledby="delete-title">';
    echo '<h3 id="delete-title">Delete test project</h3>';
    echo '<p>Deletion is only available when the business name starts with [TEST], the order email uses example.com, example.test or webco.test, and no 20i package id is stored.</p>';
    echo '<p>Removing a test project deletes the project, its brief, support requests and uploaded files. The paid order stays. Stripe and 20i are not contacted.</p>';
    if (!$allowed) {
        echo '<p class="meta">This project cannot be deleted.</p></section>';

        return;
    }

    echo '<form method="post" action="/admin.php" class="danger-zone">';
    echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
    echo '<input type="hidden" name="action" value="delete_test">';
    echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
    webco_admin_context_fields($view, $id);
    echo '<label for="confirm-id">Type ' . webco_html($publicId) . ' to confirm</label>';
    echo '<input id="confirm-id" name="confirm_id" autocomplete="off" spellcheck="false" required>';
    echo '<button class="btn btn-danger btn-sm" type="submit">Delete test project</button></form></section>';
}

function webco_admin_context_fields(string $view, int $projectId): void
{
    if (!isset(webco_admin_view_labels()[$view])) {
        $view = 'attention';
    }
    echo '<input type="hidden" name="return_view" value="' . webco_html($view) . '">';
    if ($projectId > 0) {
        echo '<input type="hidden" name="return_project" value="' . webco_html((string) $projectId) . '">';
    }
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_next_label(array $project): string
{
    $details = [];
    foreach (webco_brief_detail_columns() as $column) {
        $value = $project[$column] ?? null;
        $details[] = is_string($value) ? $value : null;
    }

    return webco_project_next_action_label(
        (string) ($project['status'] ?? ''),
        webco_project_brief_is_ready(
            is_string($project['summary'] ?? null) ? $project['summary'] : null,
            $project['submitted_at'] ?? null,
            $details
        ),
        webco_project_package_id($project['twentyi_package_id'] ?? null)
    );
}

/**
 * @return array<string, string>
 */
function webco_admin_view_labels(): array
{
    return [
        'attention' => 'Needs attention',
        'active' => 'Active',
        'live' => 'Live',
        'archived' => 'Archived',
        'all' => 'All',
    ];
}

function webco_admin_view_label(string $view): string
{
    $labels = webco_admin_view_labels();

    return $labels[$view] ?? $labels['attention'];
}

function webco_admin_requested_view(): string
{
    $view = $_GET['view'] ?? 'attention';
    if (!is_string($view) || !isset(webco_admin_view_labels()[$view])) {
        return 'attention';
    }

    return $view;
}

function webco_admin_requested_project(): ?int
{
    $project = $_GET['project'] ?? '';
    if (!is_string($project) || preg_match('/^\d{1,12}$/', $project) !== 1) {
        return null;
    }
    $project = (int) $project;

    return $project > 0 ? $project : null;
}

function webco_admin_view_href(string $view): string
{
    return '/admin.php?view=' . rawurlencode(isset(webco_admin_view_labels()[$view]) ? $view : 'attention');
}

function webco_admin_dash(string $value): string
{
    $value = trim($value);

    return $value === '' ? '—' : $value;
}

function webco_admin_paid_label(string $paid): string
{
    $paid = trim($paid);
    if ($paid === '') {
        return '—';
    }
    $date = strstr($paid, ' ', true);

    return is_string($date) && $date !== '' ? $date : $paid;
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

/**
 * Staff console styles. They share the Webco colours with the customer area but are
 * denser on purpose, and they live here so a customer-area change cannot reach admin.
 */
function webco_admin_styles(): string
{
    $css = <<<'CSS'
      :root {
        color-scheme: light;
        --bg: #f6f5f1;
        --soft: #faf9f5;
        --ink: #0e1b20;
        --ink-2: #34444d;
        --muted: #56656d;
        --line: #e0ddd4;
        --line-2: #cbc7bb;
        --field: #8a9791;
        --green: #0c6b62;
        --green-d: #08524b;
        --green-t: #e5f3f1;
        --green-l: #b9dbd6;
        --flag-bg: #fff4dc;
        --flag-line: #e0c27a;
        --flag-ink: #5e4010;
        --danger: #8a3b2a;
        --danger-line: #d3a398;
        --focus: #0a7f72;
        --serif: Georgia, "Iowan Old Style", Palatino, "Times New Roman", serif;
        --sans: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      }
      *, *::before, *::after { box-sizing: border-box; }
      body { margin: 0; background: var(--bg); color: var(--ink); font: 0.9375rem/1.45 var(--sans); overflow-wrap: break-word; }
      main { width: 100%; max-width: 74rem; margin: 0 auto; padding: 1rem 1rem 3rem; }
      main.narrow { max-width: 26rem; }
      .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
      h1, h2, h3 { margin: 0; font-family: var(--serif); font-weight: 600; line-height: 1.2; letter-spacing: -0.005em; overflow-wrap: anywhere; }
      h1 { font-size: 1.625rem; }
      h2 { font-size: 1.0625rem; }
      h3 { font-size: 1.0625rem; }
      h4 { margin: 0; }
      p { margin: 0.5rem 0 0; }
      a { color: var(--green); text-underline-offset: 0.18em; }
      a:hover { color: var(--green-d); }
      :focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; }
      .eyebrow { margin: 0; color: var(--green); font: 700 0.75rem/1.3 var(--sans); letter-spacing: 0.08em; text-transform: uppercase; }
      .meta { color: var(--muted); font-size: 0.875rem; }
      .mono { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 0.8125rem; overflow-wrap: anywhere; }
      .summary { white-space: pre-wrap; overflow-wrap: anywhere; }
      .empty { margin-top: 1rem; color: var(--muted); }
      .back { margin: 0.9rem 0 0; font-size: 0.875rem; }

      .bar { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--line-2); }
      .bar form { margin: 0; }
      .notice { margin: 0.75rem 0 0; padding: 0.5rem 0.75rem; border: 1px solid var(--green-l); border-radius: 8px; background: var(--green-t); font-weight: 600; }

      .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 2.5rem; margin: 0; padding: 0.4rem 1rem; border: 1px solid transparent; border-radius: 8px; font: 600 0.9375rem/1.2 var(--sans); text-align: center; text-decoration: none; cursor: pointer; transition: background-color 0.15s, border-color 0.15s, color 0.15s; }
      .btn-sm { min-height: 2.125rem; padding: 0.25rem 0.8rem; font-size: 0.875rem; }
      .btn-primary { border-color: var(--green); background: var(--green); color: #fff; }
      .btn-primary:hover { border-color: var(--green-d); background: var(--green-d); color: #fff; }
      .btn-secondary { border-color: var(--line-2); background: #fff; color: var(--ink); }
      .btn-secondary:hover { border-color: var(--green); background: var(--soft); color: var(--green-d); }
      .btn-danger { border-color: var(--danger-line); background: #fff; color: var(--danger); }
      .btn-danger:hover { border-color: var(--danger); background: #fbefec; color: var(--danger); }

      .badges { display: flex; flex-wrap: wrap; align-items: center; gap: 0.3rem; margin: 0; }
      .badge { display: inline-flex; align-items: center; min-height: 1.375rem; padding: 0 0.5rem; border: 1px solid var(--line-2); border-radius: 6px; background: #fff; color: var(--ink-2); font: 600 0.75rem/1 var(--sans); white-space: nowrap; }
      .badge-flag { border-color: var(--flag-line); background: var(--flag-bg); color: var(--flag-ink); }
      .badge-ok { border-color: var(--green-l); background: var(--green-t); color: var(--green-d); }
      .badge-quiet { border-color: var(--line); background: var(--soft); color: var(--muted); }

      .attention { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.6rem 1.5rem; margin-top: 0.9rem; padding: 0.7rem 0.9rem; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
      .attention-total .total { margin: 0.1rem 0 0; font-size: 1rem; }
      .attention-total .total strong { font-size: 1.375rem; font-family: var(--serif); }
      .counts { display: flex; flex-wrap: wrap; gap: 0.35rem; margin: 0; padding: 0; list-style: none; }
      .count { padding: 0.25rem 0.6rem; border: 1px solid var(--line); border-radius: 8px; background: var(--soft); color: var(--ink-2); font-size: 0.8125rem; }
      .count strong { color: var(--ink); font-size: 1rem; }
      .count.is-zero, .count.is-zero strong { color: var(--muted); }
      .count.is-flag { border-color: var(--flag-line); background: var(--flag-bg); color: var(--flag-ink); }
      .count.is-flag strong { color: var(--flag-ink); }

      .views { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.75rem; }
      .views a { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 0.4rem; min-height: 2.125rem; padding: 0.2rem 0.7rem; border: 1px solid var(--line-2); border-radius: 8px; background: #fff; color: var(--ink-2); font-size: 0.875rem; font-weight: 600; text-decoration: none; white-space: nowrap; }
      .views a:hover { border-color: var(--green); color: var(--green-d); }
      .views a[aria-current="page"] { border-color: var(--green); background: var(--green); color: #fff; }
      .tab-count { display: inline-flex; align-items: center; justify-content: center; min-width: 1.35rem; height: 1.2rem; padding: 0 0.3rem; border-radius: 6px; background: var(--green-t); color: var(--green-d); font-size: 0.75rem; font-weight: 700; }
      .views a[aria-current="page"] .tab-count { background: rgba(255, 255, 255, 0.22); color: #fff; }

      .rows { display: grid; gap: 0.4rem; margin-top: 0.75rem; }
      .row { position: relative; display: grid; grid-template-columns: minmax(0, 1fr); gap: 0.5rem; align-items: center; padding: 0.7rem 0.85rem; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
      .row:hover { border-color: var(--green); background: var(--soft); }
      .row:has(.view-link:focus-visible) { outline: 3px solid var(--focus); outline-offset: 1px; }
      .row-main h2 { font-size: 1.0625rem; }
      .row-main .meta, .row-state .meta { margin: 0.1rem 0 0; }
      .row-state .badges + .meta { margin-top: 0.3rem; }
      .row-next { margin: 0; color: var(--ink); }
      .row-next span { display: block; color: var(--muted); font-size: 0.75rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
      .row-actions { display: flex; align-items: center; justify-content: flex-end; gap: 0.4rem; }
      .row-restore { position: relative; z-index: 1; margin: 0; }
      .view-link::after { content: ""; position: absolute; inset: 0; }

      .panel { min-width: 0; margin-top: 0.75rem; padding: 0.8rem 0.95rem 0.95rem; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
      .panel > h3 + * { margin-top: 0.4rem; }
      .panel-quiet { background: var(--soft); font-size: 0.875rem; }
      .panel-quiet h3 { font-size: 1rem; }
      .panel-danger { border-color: var(--danger-line); background: #fffaf9; }
      .panel > form { margin: 0.6rem 0 0; }
      .head-line { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem 0.75rem; }
      .head-line h2 { font-size: 1.375rem; }
      .ref { margin: 0.15rem 0 0; color: var(--muted); font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 0.75rem; overflow-wrap: anywhere; }
      .archived-note { margin-top: 0.6rem; padding: 0.45rem 0.7rem; border: 1px solid var(--flag-line); border-radius: 8px; background: var(--flag-bg); color: var(--flag-ink); }
      .meta-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr)); gap: 0.55rem 1.25rem; margin: 0.8rem 0 0; padding-top: 0.75rem; border-top: 1px solid var(--line); }
      .meta-grid div { min-width: 0; }
      .meta-grid dt, .fields dt { color: var(--muted); font-size: 0.75rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
      .meta-grid dd { margin: 0.05rem 0 0; overflow-wrap: anywhere; }

      .status-panel { display: grid; gap: 0.7rem 1.5rem; margin-top: 0.75rem; padding: 0.9rem 1rem 1rem; border: 1px solid var(--green-l); border-left: 5px solid var(--green); border-radius: 10px; background: #fff; }
      .status-now .now { margin: 0.1rem 0 0; font: 600 1.5rem/1.2 var(--serif); }
      .status-now .meta { margin-top: 0.2rem; }
      .status-next > p:first-child { margin-top: 0; }
      .status-next form { margin: 0.6rem 0 0; }
      .package-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.4rem 0.6rem; }
      .package-form label { flex: 1 0 100%; margin: 0; }
      .package-form input[name="package_id"] { flex: 1 1 9rem; width: auto; max-width: 14rem; margin: 0; }

      .callout { margin-top: 0.75rem; padding: 0.75rem 0.95rem 0.9rem; border: 1px solid var(--flag-line); border-left: 5px solid #b8955a; border-radius: 10px; background: var(--flag-bg); }
      .callout-head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem 0.75rem; }
      .callout p { margin-top: 0.3rem; }

      .detail-grid { display: grid; gap: 0.75rem; margin-top: 0.75rem; }
      .detail-main, .detail-side { display: grid; gap: 0.75rem; align-content: start; min-width: 0; }
      .detail-main > .panel, .detail-side > .panel { margin-top: 0; }
      .group-title { display: flex; align-items: center; gap: 0.4rem; margin: 0.8rem 0 0.35rem; padding-bottom: 0.2rem; border-bottom: 1px solid var(--line); color: var(--green); font: 700 0.75rem/1.3 var(--sans); letter-spacing: 0.06em; text-transform: uppercase; }
      .group-title:first-of-type { margin-top: 0.4rem; }
      .fields { display: grid; gap: 0.5rem; margin: 0; }
      .fields > div { min-width: 0; }
      .fields dd { margin: 0.05rem 0 0; }
      .fields-row { grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); margin-top: 0.5rem; }
      .brief-groups { columns: 2 19rem; column-gap: 2rem; margin-top: 0.4rem; }
      .brief-group { break-inside: avoid; margin-bottom: 0.6rem; }
      .brief-group .group-title { margin-top: 0.2rem; }

      .request { margin-top: 0.55rem; padding: 0.65rem 0.8rem 0.75rem; border: 1px solid var(--line-2); border-radius: 8px; background: #fff; }
      .request.is-done { border-color: var(--line); background: var(--soft); color: var(--ink-2); }
      .request-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.3rem 0.75rem; }
      .request-head h4 { font: 700 0.9375rem/1.3 var(--sans); }
      .request-date { margin: 0.1rem 0 0; }
      .request .summary { margin-top: 0.4rem; }
      .call-box { padding: 0.45rem 0.65rem; border-radius: 6px; background: var(--flag-bg); }
      .request-action { margin: 0.6rem 0 0; }

      .file-list { display: grid; gap: 0.3rem; margin: 0; padding: 0; list-style: none; }
      .file-list li { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; padding: 0.35rem 0.55rem; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
      .file-list form { margin: 0; flex: 0 0 auto; }
      .file-info { display: flex; flex-direction: column; min-width: 0; }
      .file-name { font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
      .file-info .meta { line-height: 1.3; }
      .panel .group-title + .meta { margin-top: 0.2rem; }

      .admin-zone { margin-top: 1.5rem; padding-top: 0.9rem; border-top: 1px solid var(--line-2); }
      .zone-grid { display: grid; gap: 0.75rem; margin-top: 0.4rem; }
      .zone-grid > .panel { margin-top: 0; }
      .danger-zone { padding-top: 0.6rem; border-top: 1px solid var(--danger-line); }
      .danger-zone label { margin-top: 0; }

      label { display: block; margin-top: 0.6rem; font-weight: 650; }
      input:not([type="hidden"]) { display: block; width: 100%; max-width: 22rem; margin-top: 0.3rem; padding: 0.45rem 0.65rem; border: 1px solid var(--field); border-radius: 8px; background: #fff; color: var(--ink); font: inherit; font-size: 1rem; }
      input:not([type="hidden"]):focus { border-color: var(--green); }
      .login { padding: 1rem; }
      .login .btn { margin-top: 0.8rem; }

      @media (min-width: 40rem) {
        main { padding: 1.25rem 1.5rem 4rem; }
        .row { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem 1.25rem; }
        .row-main { grid-column: 1 / -1; }
        .row-actions { grid-column: 1 / -1; }
        .zone-grid { grid-template-columns: 1fr 1fr; }
      }
      @media (min-width: 64rem) {
        main { padding: 1.5rem 2rem 4rem; }
        .row { grid-template-columns: minmax(0, 2.3fr) minmax(0, 1.7fr) minmax(0, 1.5fr) auto; }
        .row-main, .row-actions { grid-column: auto; }
        .status-panel { grid-template-columns: minmax(11rem, 1fr) minmax(0, 2.4fr); }
        .status-next { padding-left: 1.5rem; border-left: 1px solid var(--line); }
        .detail-grid { grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); align-items: start; }
      }
      @media (max-width: 39.9375rem) {
        .bar h1 { font-size: 1.4375rem; }
        .btn-sm { min-height: 2.5rem; }
        .row-actions .btn { flex: 1; }
        .request-action .btn, .status-next form .btn { width: 100%; }
        .package-form input[name="package_id"] { max-width: none; }
        .file-list li { flex-wrap: wrap; }
      }
      @media (prefers-reduced-motion: reduce) {
        * { transition: none !important; }
      }
CSS;

    return '<style>' . $css . '</style>';
}
