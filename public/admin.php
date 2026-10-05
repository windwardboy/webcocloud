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
        echo '<p>No projects yet. A project appears here after a paid order is confirmed.</p>';
    } elseif ($visible === []) {
        echo '<p>Nothing in ' . webco_html(webco_admin_view_label($view)) . '.</p>';
    }
    foreach ($visible as $project) {
        webco_admin_project_row($project, $csrf, $view);
    }

    echo '</main></body></html>';
    exit;
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

    echo '<section class="attention" aria-label="Needs attention">';
    echo '<p class="eyebrow">Needs attention</p>';
    echo '<p class="summary-line"><strong>' . (string) $counts['attention'] . '</strong> '
        . ($counts['attention'] === 1 ? 'project needs attention' : 'projects need attention') . '</p>';
    echo '<ul class="summary-list">';
    echo '<li>' . (string) $counts['brief_received'] . ' ' . ($counts['brief_received'] === 1 ? 'brief received' : 'briefs received') . '</li>';
    echo '<li>' . (string) $counts['call_requested'] . ' ' . ($counts['call_requested'] === 1 ? 'call requested' : 'calls requested') . '</li>';
    echo '<li>' . (string) $counts['ready_for_clone'] . ' ready for clone</li>';
    echo '<li>' . (string) $counts['ready_for_build'] . ' ready for build</li>';
    echo '<li>' . (string) $counts['review'] . ' in review</li>';
    echo '<li>' . (string) $counts['open_requests'] . ' open request' . ($counts['open_requests'] === 1 ? '' : 's') . '</li>';
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
            . webco_html($label) . ' <span>' . (string) $count . '</span></a>';
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

    echo '<article class="row">';
    echo '<div class="row-main">';
    echo '<h2>' . webco_html((string) ($project['business_name'] ?? '')) . '</h2>';
    echo '<p class="meta">' . webco_html(webco_admin_dash((string) ($project['domain_name'] ?? '')))
        . ' · ' . webco_html(webco_admin_dash((string) ($project['package_name'] ?? '')))
        . ' · ' . webco_html(webco_admin_care_label((string) ($project['care_choice'] ?? ''))) . '</p>';
    echo '<p class="meta">Paid ' . webco_html(webco_admin_paid_label((string) ($project['paid_at'] ?? '')))
        . ' · ' . webco_html($open === 1 ? '1 open request' : (string) $open . ' open requests') . '</p>';
    echo '<p class="next-line">Next: ' . webco_html(webco_admin_next_label($project)) . '</p>';
    echo '</div>';
    echo '<div class="row-status">';
    echo '<p class="status-pill">' . webco_html(webco_project_status_label($status)) . '</p>';
    if ($call) {
        echo '<p class="call-pill">Call requested</p>';
    }
    if (webco_project_is_archived($project)) {
        echo '<form method="post" action="/admin.php">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="action" value="restore">';
        echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
        webco_admin_context_fields($view, $id);
        echo '<button class="quiet" type="submit">Restore</button></form>';
    }
    echo '<a class="view-link" href="' . webco_html($href) . '">View project</a>';
    echo '</div></article>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_project(array $project, string $csrf, string $view = 'attention'): void
{
    $id = (int) ($project['id'] ?? 0);
    $status = (string) ($project['status'] ?? '');
    $customerSent = ($project['customer_notified_at'] ?? null) !== null;
    $internalSent = ($project['internal_notified_at'] ?? null) !== null;
    $pending = !$customerSent || !$internalSent;
    $archived = webco_project_is_archived($project);

    echo '<p class="back"><a href="' . webco_html(webco_admin_view_href($view)) . '">Back to '
        . webco_html(webco_admin_view_label($view)) . '</a></p>';
    echo '<article class="card">';
    echo '<h2>' . webco_html((string) ($project['business_name'] ?? '')) . '</h2>';
    echo '<p class="meta">' . webco_html((string) ($project['order_public_id'] ?? '')) . '</p>';
    if ($archived) {
        echo '<p class="archived-note">Archived. The build status, payment, files and requests are unchanged.</p>';
    }
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

    webco_admin_billing_debug();
    webco_admin_callout($project);
    echo '<h3>Website brief</h3>';
    webco_admin_brief_filled('Who they are', (string) ($project['business_overview'] ?? ''));
    webco_admin_brief_filled('How long operating', (string) ($project['years_operating'] ?? ''));
    webco_admin_brief_filled('Experience and credentials', (string) ($project['credentials'] ?? ''));
    webco_admin_brief_filled('What visitors should understand first', (string) ($project['first_impression'] ?? ''));
    $courses = trim((string) ($project['course_entries'] ?? ''));
    webco_admin_brief_filled('Courses', $courses !== '' ? webco_brief_pairs_plain($courses) : (string) ($project['services'] ?? ''));
    webco_admin_brief_filled('Main location', (string) ($project['locations'] ?? ''));
    webco_admin_brief_filled('Areas served', (string) ($project['areas_served'] ?? ''));
    $further = trim((string) ($project['location_entries'] ?? ''));
    if ($further !== '') {
        webco_admin_brief_filled('Further locations', webco_brief_pairs_plain($further));
    }
    webco_admin_brief_filled('Experience', (string) ($project['why_experience'] ?? ''));
    webco_admin_brief_filled('Vehicles, equipment or facilities', (string) ($project['why_facilities'] ?? ''));
    webco_admin_brief_filled('Flexibility', (string) ($project['why_flexibility'] ?? ''));
    webco_admin_brief_filled('Customer support', (string) ($project['why_support'] ?? ''));
    webco_admin_brief_filled('What sets them apart', (string) ($project['why_difference'] ?? ''));
    webco_admin_brief_filled('Brand colours they already use', (string) ($project['branding'] ?? ''));
    webco_admin_brief_filled('Preferred enquiry route', webco_brief_enquiry_label((string) ($project['enquiry_route'] ?? '')));
    webco_admin_brief_filled('Contact details', (string) ($project['contact_details'] ?? ''));
    webco_admin_brief_filled('Opening hours', (string) ($project['opening_hours'] ?? ''));
    webco_admin_brief_text('Anything else', (string) ($project['summary'] ?? ''));
    foreach ([
        'goals' => 'Earlier note about website goals',
        'style_tone' => 'Earlier note about style',
        'liked_sites' => 'Earlier note about other websites',
        'required_pages' => 'Earlier note about pages',
    ] as $key => $label) {
        $legacy = trim((string) ($project[$key] ?? ''));
        if ($legacy !== '') {
            webco_admin_brief_text($label, $legacy);
        }
    }
    webco_admin_requests($project, $csrf, $view);

    webco_admin_next_action($project, $csrf, $view);

    echo '<h3>Notifications</h3><p>';
    echo $customerSent ? 'Customer email sent. ' : 'Customer email pending. ';
    echo $internalSent ? 'Internal email sent.' : 'Internal email pending.';
    echo '</p>';
    if ($pending) {
        echo '<form method="post" action="/admin.php">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="action" value="notify">';
        echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
        webco_admin_context_fields($view, $id);
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
            $fileRequest = $file['request_id'] ?? null;
            if (is_int($fileRequest) || (is_string($fileRequest) && ctype_digit($fileRequest))) {
                echo '<span class="meta">Request ' . webco_html((string) $fileRequest) . '</span> ';
            }
            echo '<form method="post" action="/admin.php" class="inline">';
            echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
            echo '<input type="hidden" name="action" value="download">';
            echo '<input type="hidden" name="asset_id" value="' . webco_html((string) $assetId) . '">';
            webco_admin_context_fields($view, $id);
            echo '<button class="quiet" type="submit">Download</button></form></li>';
        }
        echo '</ul>';
    }

    echo '<h3>Archive</h3>';
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
    echo '<button class="quiet" type="submit">' . ($archived ? 'Restore project' : 'Archive project') . '</button></form>';

    webco_admin_delete_panel($project, $csrf, $view);

    echo '</article>';
}

function webco_admin_billing_debug(): void
{
    $latest = webco_billing_debug_latest();
    echo '<h3>Billing check</h3>';
    if ($latest === null) {
        echo '<p class="meta">No billing check has been recorded yet.</p>';

        return;
    }

    echo '<p class="meta">' . webco_html($latest['at']) . '</p>';
    echo '<p>' . webco_html($latest['marker']) . '</p>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_callout(array $project): void
{
    if ((int) ($project['call_requested'] ?? 0) !== 1) {
        return;
    }

    echo '<div class="callout">';
    echo '<p class="eyebrow">Phone call requested</p>';
    echo '<p>The customer wants a call before the website starts.</p>';
    webco_admin_brief_text('Number', (string) ($project['call_number'] ?? ''));
    webco_admin_brief_text('Time', (string) ($project['call_time'] ?? ''));
    webco_admin_brief_text('Note', (string) ($project['call_note'] ?? ''));
    echo '</div>';
}

function webco_admin_brief_text(string $label, string $value): void
{
    $value = trim($value);
    echo '<h3>' . webco_html($label) . '</h3>';
    echo '<p class="summary">' . ($value === '' ? 'None yet.' : webco_html($value)) . '</p>';
}

function webco_admin_brief_filled(string $label, string $value): void
{
    if (trim($value) === '') {
        return;
    }
    webco_admin_brief_text($label, $value);
}

/**
 * @param array<string, mixed> $project
 */
function webco_admin_requests(array $project, string $csrf, string $view): void
{
    $requests = is_array($project['requests'] ?? null) ? $project['requests'] : [];
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

    echo '<h3>Client requests</h3>';
    echo '<p class="meta">These are separate from the website build status.</p>';
    if ($active === [] && $done === []) {
        echo '<p class="meta">No requests yet.</p>';

        return;
    }

    foreach ($active as $request) {
        webco_admin_request($request, $csrf, true, $view, (int) ($project['id'] ?? 0));
    }
    foreach ($done as $request) {
        webco_admin_request($request, $csrf, false, $view, (int) ($project['id'] ?? 0));
    }
}

/**
 * @param array<string, mixed> $request
 */
function webco_admin_request(array $request, string $csrf, bool $action, string $view, int $projectId): void
{
    $id = (int) ($request['id'] ?? 0);
    $status = (string) ($request['status'] ?? '');
    $next = webco_request_status_next($status);
    echo '<article class="request">';
    echo '<p class="eyebrow">' . webco_html(webco_request_type_label((string) ($request['request_type'] ?? ''))) . '</p>';
    echo '<p class="request-status">' . webco_html(webco_request_status_label($status)) . '</p>';
    echo '<p class="summary">' . webco_html(trim((string) ($request['summary'] ?? ''))) . '</p>';
    if ((int) ($request['call_requested'] ?? 0) === 1) {
        echo '<p class="call-line">Phone call requested';
        $number = trim((string) ($request['call_number'] ?? ''));
        $time = trim((string) ($request['call_time'] ?? ''));
        if ($number !== '') {
            echo ' · ' . webco_html($number);
        }
        if ($time !== '') {
            echo ' · ' . webco_html($time);
        }
        $note = trim((string) ($request['call_note'] ?? ''));
        if ($note !== '') {
            echo '<br>' . webco_html($note);
        }
        echo '</p>';
    }
    if ($action && $next !== null) {
        echo '<form method="post" action="/admin.php">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="action" value="request">';
        echo '<input type="hidden" name="request_id" value="' . webco_html((string) $id) . '">';
        webco_admin_context_fields($view, $projectId);
        echo '<button type="submit">Mark ' . webco_html(strtolower(webco_request_status_label($next))) . '</button>';
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
        webco_admin_context_fields($view, $id);
        echo '<button type="submit">Mark ready for clone</button></form></div>';

        return;
    }
    if ($status === 'ready_for_clone' && $packageId === null) {
        echo '<p>Next: clone the template in My20i, then save the new hosting package id.</p>';
        echo '<form method="post" action="/admin.php">';
        echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
        echo '<input type="hidden" name="action" value="clone_complete">';
        echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
        webco_admin_context_fields($view, $id);
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
    webco_admin_context_fields($view, $id);
    echo '<button type="submit">' . webco_html(webco_project_status_label($next)) . '</button></form></div>';
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

    echo '<h3>Delete test project</h3>';
    echo '<p>Deletion is only available when the business name starts with [TEST], the order email uses example.com, example.test or webco.test, and no 20i package id is stored.</p>';
    echo '<p>Removing a test project deletes the project, its brief, support requests and uploaded files. The paid order stays. Stripe and 20i are not contacted.</p>';
    if (!$allowed) {
        echo '<p class="meta">This project cannot be deleted.</p>';

        return;
    }

    echo '<form method="post" action="/admin.php" class="danger-zone">';
    echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
    echo '<input type="hidden" name="action" value="delete_test">';
    echo '<input type="hidden" name="project_id" value="' . webco_html((string) $id) . '">';
    webco_admin_context_fields($view, $id);
    echo '<label for="confirm-id">Type ' . webco_html($publicId) . ' to confirm</label>';
    echo '<input id="confirm-id" name="confirm_id" autocomplete="off" spellcheck="false" required>';
    echo '<button class="danger" type="submit">Delete test project</button></form>';
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

function webco_admin_styles(): string
{
    return '<style>
      :root { color-scheme: light; }
      body { margin: 0; background: #f3f6f5; color: #122028; font: 1rem/1.5 "Segoe UI", Helvetica, Arial, sans-serif; }
      main { max-width: 76rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
      main.narrow { max-width: 28rem; }
      h1, h2, h3 { font-family: Georgia, Palatino, serif; font-weight: 600; line-height: 1.2; }
      h1 { margin: 0; font-size: 2.2rem; }
      h2 { margin: 0; font-size: 1.5rem; }
      h3 { margin: 1.2rem 0 0; font-size: 1.05rem; }
      p { margin: 0.6rem 0 0; }
      .eyebrow { margin: 0; color: #0c6b62; font-size: 0.8rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
      .top { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; }
      .card { margin-top: 1.25rem; padding: 1.1rem 1.2rem 1.3rem; background: #fff; border: 1px solid #d5e0dc; border-radius: 16px; }
      .row { display: flex; justify-content: space-between; gap: 1rem; margin-top: 0.8rem; padding: 0.9rem 1rem; background: #fff; border: 1px solid #d5e0dc; border-radius: 14px; }
      .row h2 { font-size: 1.2rem; }
      .row-status { display: flex; flex-direction: column; align-items: flex-start; gap: 0.35rem; min-width: 9rem; }
      .status-pill, .call-pill { margin: 0; font-size: 0.85rem; font-weight: 700; }
      .call-pill { color: #8a6230; }
      .next-line { margin-top: 0.35rem; }
      .view-link { font-weight: 700; }
      .attention { margin-top: 1rem; padding: 0.9rem 1rem; background: #fff; border: 1px solid #d5e0dc; border-radius: 14px; }
      .summary-line { margin: 0.25rem 0 0; font-size: 1.15rem; }
      .summary-list { display: flex; flex-wrap: wrap; gap: 0.35rem 1rem; margin: 0.55rem 0 0; padding: 0; list-style: none; color: #3e4e58; }
      .views { display: flex; flex-wrap: wrap; gap: 0.45rem; margin-top: 1rem; }
      .views a { padding: 0.35rem 0.7rem; border: 1px solid #d5e0dc; border-radius: 999px; background: #fff; text-decoration: none; }
      .views a[aria-current="page"] { background: #0c6b62; color: #f7fbfa; border-color: #0c6b62; }
      .views span { font-weight: 700; }
      .back { margin: 1rem 0 0; }
      .archived-note { padding: 0.7rem 0.8rem; background: #fff8ee; border-radius: 12px; }
      .danger-zone { margin-top: 0.6rem; padding: 0.8rem 0.9rem; border: 1px solid #e7c7be; border-radius: 12px; background: #fff7f5; }
      button.danger { background: #8a3b2a; }
      @media (max-width: 40rem) {
        .row { flex-direction: column; }
        .row-status { min-width: 0; }
      }
      .meta { color: #3e4e58; }
      .notice { padding: 0.8rem 1rem; background: #e5f3f1; border-radius: 12px; }
      .next { margin-top: 1rem; padding: 0.9rem 1rem; background: #f3f6f5; border-radius: 12px; }
      .next .now { margin: 0.15rem 0 0; font-size: 1.15rem; }
      .summary { white-space: pre-wrap; }
      .callout { margin-top: 1rem; padding: 0.9rem 1rem; border: 2px solid #b8955a; border-radius: 12px; background: #fff8ee; }
      .request { margin-top: 0.8rem; padding: 0.8rem 0.9rem; border: 1px solid #d5e0dc; border-radius: 12px; }
      .request-status { margin: 0.15rem 0 0; font-weight: 700; }
      .call-line { margin-top: 0.45rem; font-weight: 650; }
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
