<?php
/**
 * Customer website brief. The email link exchanges a token for a session.
 * The page stores one note and lists files already saved for this project.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/brief-wizard.php';
require_once __DIR__ . '/lib/client-home.php';
require_once __DIR__ . '/lib/billing-portal.php';

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
            'Client area',
            'Open the secure link in your Webco email to open your client area.'
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
            'Client area',
            'Open the secure link in your Webco email to open your client area.'
        );
    }

    $project['billing_portal'] = webco_project_billing_portal_open($db, $projectId);
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

function webco_brief_redirect(string $notice, string $step = '', string $view = ''): void
{
    $query = [];
    if ($notice !== '') {
        $query['notice'] = $notice;
    }
    if ($step !== '' && webco_brief_wizard_step_valid($step)) {
        $query['step'] = $step;
    }
    if ($view !== '' && webco_client_view_valid($view)) {
        $query['view'] = $view;
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
        'billing' => 'Billing could not be opened just now. Try again in a moment.',
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
        $requestedView = $_GET['view'] ?? 'home';
        $view = is_string($requestedView) && webco_client_view_valid($requestedView) ? $requestedView : 'home';
        webco_brief_render_received($project, $notice, $view);
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
function webco_brief_request_form(array $project, string $csrf): void
{
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
    echo '<div class="form-actions"><button class="btn btn-primary" type="submit" name="intent" value="request">Send request</button></div>';
    echo '</form>';
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
        webco_brief_redirect('invalid', '', 'request');
    }

    $id = webco_create_project_request($db, $projectId, $type, $summary, [
        'call_requested' => ($_POST['request_call'] ?? '') === 'yes' ? 'yes' : 'no',
        'call_number' => is_string($_POST['request_call_number'] ?? null) ? $_POST['request_call_number'] : '',
        'call_time' => is_string($_POST['request_call_time'] ?? null) ? $_POST['request_call_time'] : '',
        'call_note' => is_string($_POST['request_call_note'] ?? null) ? $_POST['request_call_note'] : '',
    ]);
    webco_brief_redirect($id > 0 ? 'requested' : 'invalid', '', $id > 0 ? 'requests' : 'request');
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
                echo webco_ui_file_row(
                    (string) ($file['original_name'] ?? ''),
                    webco_brief_size((int) ($file['size_bytes'] ?? 0))
                );
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
    echo '<header class="page-head">';
    echo '<p class="brand">Webco Cloud</p>';
    echo '<h1>' . webco_html($title) . '</h1>';
    echo '<p class="lead">' . webco_html($message) . '</p>';
    echo '</header>';
    echo '</main></body></html>';
    exit;
}

function webco_brief_styles(): string
{
    return '<style>' . webco_customer_css() . '</style>';
}
