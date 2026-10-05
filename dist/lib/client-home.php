<?php
/**
 * Client home for the website in the current brief session.
 * Billing opens Stripe Customer Portal only when this project already has a
 * test-mode customer. Managed Care, renewals and more than one project are not built here.
 */

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/brief-wizard.php';

/**
 * @return list<string>
 */
function webco_client_views(): array
{
    return ['home', 'brief', 'files', 'request', 'requests', 'contact'];
}

function webco_client_view_valid(string $view): bool
{
    return in_array($view, webco_client_views(), true);
}

/**
 * Customer timeline position. Steps before the brief is received use -1.
 * ready_for_clone and ready_for_build are both site preparation.
 * ready_to_launch and live share the last stage.
 */
function webco_client_stage_index(string $status): int
{
    return match ($status) {
        'brief_received' => 0,
        'ready_for_clone', 'ready_for_build' => 1,
        'in_build' => 2,
        'review' => 3,
        'ready_to_launch', 'live' => 4,
        default => -1,
    };
}

/**
 * @return list<array{label: string, state: string}>
 */
function webco_client_timeline(string $status): array
{
    $index = webco_client_stage_index($status);
    $labels = [
        'Brief received',
        'Site preparation',
        'Build',
        'Your review',
        $status === 'live' ? 'Live' : 'Ready to launch',
    ];
    $items = [];
    foreach ($labels as $position => $label) {
        if ($position < $index) {
            $state = 'done';
        } elseif ($position === $index) {
            $state = 'current';
        } else {
            $state = 'upcoming';
        }
        $items[] = ['label' => $label, 'state' => $state];
    }

    return $items;
}

function webco_client_status_sentence(string $status): string
{
    return match ($status) {
        'awaiting_brief' => 'We are waiting for your website brief.',
        'brief_in_progress' => 'Your website brief is still in progress.',
        'brief_received' => 'We have your website brief. Webco will read it and contact you.',
        'ready_for_clone' => 'Webco is preparing your website.',
        'ready_for_build' => 'Your website is prepared and the build is about to start.',
        'in_build' => 'Webco is building your website.',
        'review' => 'Your website is ready for you to review.',
        'ready_to_launch' => 'Your website is ready to launch.',
        'live' => 'Your website is live.',
        default => 'Webco will update you as your website moves forward.',
    };
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_package_label(array $project): string
{
    $name = trim((string) ($project['package_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    if ((string) ($project['package_code'] ?? '') === 'professional') {
        return 'Webco Professional';
    }

    return 'Webco Essential';
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_domain_label(array $project): string
{
    $domain = trim((string) ($project['domain_name'] ?? ''));

    return $domain !== '' ? $domain : 'Not confirmed yet';
}

function webco_client_date(?string $value): string
{
    if ($value === null) {
        return '';
    }
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    $failed = !$parsed instanceof DateTimeImmutable
        || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0));
    if ($failed) {
        return '';
    }

    return $parsed->format('j F Y');
}

function webco_client_excerpt(string $summary, int $limit = 160): string
{
    $flat = preg_replace('/\s+/u', ' ', $summary);
    if (!is_string($flat)) {
        $flat = preg_replace('/\s+/', ' ', $summary);
    }
    $summary = trim(is_string($flat) ? $flat : '');
    if ($summary === '' || $limit < 2) {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($summary) <= $limit) {
            return $summary;
        }

        return rtrim(mb_substr($summary, 0, $limit - 1)) . '…';
    }
    if (strlen($summary) <= $limit) {
        return $summary;
    }

    return rtrim(substr($summary, 0, $limit - 1)) . '…';
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_render(array $project, string $view, string $notice): void
{
    if (!webco_client_view_valid($view)) {
        $view = 'home';
    }
    $business = trim((string) ($project['business_name'] ?? ''));
    $titles = [
        'home' => $business !== '' ? $business : 'Your website',
        'brief' => 'Website brief',
        'files' => 'Files you sent',
        'request' => 'Request an update',
        'requests' => 'Your requests',
        'contact' => 'Contact Webco',
    ];
    webco_brief_page_open($titles[$view] . ' · Webco Cloud', 'client-home');
    webco_client_chrome($view);
    webco_brief_notice_line($notice);
    match ($view) {
        'brief' => webco_client_view_brief($project),
        'files' => webco_client_view_files($project),
        'request' => webco_client_view_request($project),
        'requests' => webco_client_view_requests($project),
        'contact' => webco_client_view_contact($project),
        default => webco_client_view_home($project),
    };
    if ($view === 'requests') {
        webco_brief_upload_script();
    }
    webco_brief_page_close();
}

function webco_client_chrome(string $view): void
{
    $links = [
        'home' => ['/brief.php', 'Home'],
        'brief' => ['/brief.php?view=brief', 'Brief'],
        'files' => ['/brief.php?view=files', 'Files'],
        'requests' => ['/brief.php?view=requests', 'Requests'],
        'contact' => ['/brief.php?view=contact', 'Contact'],
    ];
    echo '<header class="client-bar">';
    echo '<p class="brand">Webco Cloud <span>· Client area</span></p>';
    echo '<nav class="client-nav" aria-label="Client area">';
    foreach ($links as $id => $link) {
        $current = ($id === $view || ($view === 'request' && $id === 'requests'));
        echo '<a href="' . webco_html($link[0]) . '"' . ($current ? ' aria-current="page"' : '') . '>'
            . webco_html($link[1]) . '</a>';
    }
    echo '</nav></header>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_view_home(array $project): void
{
    $business = trim((string) ($project['business_name'] ?? ''));
    $status = (string) ($project['status'] ?? '');
    echo '<section class="card project" aria-labelledby="project-title">';
    echo '<h1 id="project-title">' . webco_html($business !== '' ? $business : 'Your website') . '</h1>';
    echo '<p class="status-line">' . webco_html(webco_client_status_sentence($status)) . '</p>';
    echo '<dl class="facts">';
    echo '<div><dt>Package</dt><dd>' . webco_html(webco_client_package_label($project)) . '</dd></div>';
    echo '<div><dt>Domain</dt><dd>' . webco_html(webco_client_domain_label($project)) . '</dd></div>';
    $submitted = webco_client_date(is_string($project['submitted_at'] ?? null) ? $project['submitted_at'] : null);
    if ($submitted !== '') {
        echo '<div><dt>Brief submitted</dt><dd>' . webco_html($submitted) . '</dd></div>';
    }
    echo '</dl>';
    webco_client_call_line($project);
    echo '</section>';

    echo '<section class="card" aria-labelledby="progress-heading">';
    echo '<h2 id="progress-heading">Website progress</h2>';
    webco_client_timeline_list($status);
    echo '</section>';

    $requests = webco_client_request_rows($project);
    $files = webco_client_brief_assets($project);
    $fileCount = webco_client_file_count($files);
    $fileDetail = $fileCount === 0 ? 'None yet.' : ($fileCount === 1 ? '1 file' : (string) $fileCount . ' files');
    $requestCount = count($requests);
    $requestDetail = $requestCount === 0 ? 'Nothing sent yet.' : ($requestCount === 1 ? '1 request' : (string) $requestCount . ' requests');
    $callDetail = ($project['call_requested'] ?? false) === true
        ? 'Call requested'
        : 'Questions and your call preference.';

    echo '<h2 class="section-title">What you can do</h2>';
    echo '<div class="action-grid">';
    webco_client_action('/brief.php?view=request', 'Request an update', 'Or ask Webco for help.', true);
    webco_client_action('/brief.php?view=brief', 'View website brief', 'Read the brief Webco received.', false);
    webco_client_action('/brief.php?view=files', 'Files you sent', $fileDetail, false);
    webco_client_action('/brief.php?view=requests', 'View previous requests', $requestDetail, false);
    webco_client_action('/brief.php?view=contact', 'Contact Webco', $callDetail, false);
    echo '</div>';

    echo '<div class="grid-split">';
    echo '<section class="card" aria-labelledby="requests-heading">';
    echo '<h2 id="requests-heading">Previous requests</h2>';
    webco_client_request_list(array_slice($requests, 0, 3), true, false, [], '');
    if ($requestCount > 3) {
        echo '<p class="more-link"><a href="/brief.php?view=requests">View all requests</a></p>';
    }
    echo '</section>';
    webco_client_billing(($project['billing_portal'] ?? false) === true);
    echo '</div>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_view_brief(array $project): void
{
    echo '<header class="page-head"><h1>Website brief</h1>';
    $submitted = webco_client_date(is_string($project['submitted_at'] ?? null) ? $project['submitted_at'] : null);
    if ($submitted !== '') {
        echo '<p class="lead">Your website brief was received on ' . webco_html($submitted) . '.</p>';
    } else {
        echo '<p class="lead">Your website brief was received.</p>';
    }
    echo '</header>';
    echo '<div class="card callout">';
    echo '<p class="note">This is the brief you submitted. To ask for a change, request an update.</p>';
    echo '<a class="btn btn-primary" href="/brief.php?view=request">Request an update</a>';
    echo '</div>';
    $sections = webco_brief_review_sections($project);
    if ($sections === []) {
        echo '<p class="meta">There are no written answers on this brief.</p>';

        return;
    }
    $labels = webco_brief_wizard_labels((string) ($project['package_code'] ?? 'essential'));
    $current = '';
    echo '<div class="doc">';
    foreach ($sections as $section) {
        if ($section['step'] !== $current) {
            if ($current !== '') {
                echo '</dl></section>';
            }
            $current = $section['step'];
            echo '<section class="doc-section"><h2>' . webco_html($labels[$current] ?? 'Brief') . '</h2><dl class="qa">';
        }
        echo '<div><dt>' . webco_html($section['label']) . '</dt><dd>' . webco_html($section['value']) . '</dd></div>';
    }
    echo '</dl></section></div>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_view_files(array $project): void
{
    echo '<header class="page-head"><h1>Files you sent</h1>';
    echo '<p class="lead">Logos, photos and documents saved with your website brief.</p>';
    echo '<p class="meta">To send another file, add it to a request on the <a href="/brief.php?view=requests">Requests</a> page.</p>';
    echo '</header>';
    echo '<div class="card files-panel">';
    webco_client_file_groups(webco_client_brief_assets($project));
    echo '</div>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_view_request(array $project): void
{
    echo '<header class="page-head"><h1>Request an update</h1>';
    echo '<p class="lead">Ask for a website change, or ask Webco for help. This stays separate from your website brief.</p>';
    echo '</header>';
    echo '<section class="card">';
    webco_brief_request_form($project, webco_brief_csrf_token());
    echo '</section>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_view_requests(array $project): void
{
    echo '<header class="page-head"><h1>Your requests</h1>';
    echo '<p class="lead">Updates and questions you have sent about this website.</p>';
    echo '<p class="actions"><a class="btn btn-primary" href="/brief.php?view=request">Request an update</a></p>';
    echo '</header>';
    webco_client_request_list(
        webco_client_request_rows($project),
        false,
        true,
        is_array($project['assets'] ?? null) ? $project['assets'] : [],
        webco_brief_csrf_token()
    );
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_view_contact(array $project): void
{
    echo '<header class="page-head"><h1>Contact Webco</h1>';
    echo '<p class="lead">Website changes and questions both go through a request, so Webco has the details in one place.</p>';
    echo '</header>';
    echo '<div class="grid-2">';

    echo '<section class="card" aria-labelledby="help-heading">';
    echo '<h2 id="help-heading">Ask Webco for help</h2>';
    echo '<p>Use a request for a website change or a question. You can ask for a call, and add files once it is sent.</p>';
    echo '<p class="actions">';
    echo '<a class="btn btn-primary" href="/brief.php?view=request">Ask Webco for help</a>';
    echo '<a class="btn btn-secondary" href="/brief.php?view=requests">Your requests</a>';
    echo '</p></section>';

    echo '<section class="card" aria-labelledby="call-heading">';
    echo '<h2 id="call-heading">Phone call</h2>';
    if (($project['call_requested'] ?? false) === true) {
        echo '<p class="badges">' . webco_ui_badge('Call requested', 'accent') . '</p>';
        echo '<p class="note">You asked for a phone call about the website brief.</p>';
        echo '<dl class="facts facts-rows">';
        echo '<div><dt>Preferred number</dt><dd>' . webco_html(webco_client_given((string) ($project['call_number'] ?? ''))) . '</dd></div>';
        echo '<div><dt>Preferred time</dt><dd>' . webco_html(webco_client_given((string) ($project['call_time'] ?? ''))) . '</dd></div>';
        echo '</dl>';
    } else {
        echo '<p>You have not asked for a phone call about this website brief.</p>';
        echo '<p class="meta">You can ask for one when you send a request.</p>';
    }
    echo '</section>';
    echo '</div>';

    // The public support page covers the separate hosting login, domains, email and Webco's own contact details.
    echo '<section class="card card-quiet" aria-labelledby="other-help-heading">';
    echo '<h2 id="other-help-heading">Hosting, domain and email</h2>';
    echo '<p>Hosting login, domain and email help, and Webco\'s contact details are on the <a href="/support/">Webco support page</a>.</p>';
    echo '</section>';

    $reference = trim((string) ($project['order_public_id'] ?? ''));
    if ($reference !== '') {
        echo '<p class="meta">Project reference <span class="ref">' . webco_html($reference) . '</span></p>';
    }
}

/**
 * Both actions open the same Stripe Customer Portal session for this project.
 */
function webco_client_billing(bool $available = false): void
{
    echo '<section class="card billing" id="billing" aria-labelledby="billing-heading">';
    echo '<h2 id="billing-heading">Billing</h2>';
    if (!$available) {
        echo '<p class="note">Invoices and payment details are not available in this area yet.</p>';
        echo '<ul class="placeholder-list">';
        echo '<li><span>Billing &amp; invoices</span> ' . webco_ui_badge('Coming next') . '</li>';
        echo '<li><span>Manage payment details</span> ' . webco_ui_badge('Coming next') . '</li>';
        echo '</ul></section>';

        return;
    }

    echo '<p class="note">Invoices, receipts and the card used for this website are managed in Stripe.</p>';
    echo '<form method="post" action="/billing-portal.php">';
    echo '<input type="hidden" name="csrf" value="' . webco_html(webco_brief_csrf_token()) . '">';
    echo '<button class="btn btn-secondary" type="submit">Billing &amp; invoices</button>';
    echo '<button class="btn btn-secondary" type="submit">Manage payment details</button>';
    echo '</form></section>';
}

function webco_client_action(string $href, string $title, string $detail, bool $primary): void
{
    echo '<a class="' . ($primary ? 'action action-primary' : 'action') . '" href="' . webco_html($href) . '">';
    echo '<span class="action-text"><strong>' . webco_html($title) . '</strong>';
    echo '<span>' . webco_html($detail) . '</span></span>';
    echo '</a>';
}

function webco_client_timeline_list(string $status): void
{
    echo '<ol class="tracker">';
    foreach (webco_client_timeline($status) as $item) {
        $state = $item['state'];
        $word = match ($state) {
            'done' => 'Done',
            'current' => 'Now',
            default => 'Later',
        };
        echo '<li class="' . $state . '"' . ($state === 'current' ? ' aria-current="step"' : '') . '>';
        echo '<span class="stage-body">';
        echo '<span class="stage-label">' . webco_html($item['label']) . '</span>';
        if ($state === 'current') {
            echo webco_ui_badge($word, 'accent', 'stage-state');
        } else {
            echo '<span class="stage-state">' . webco_html($word) . '</span>';
        }
        echo '</span></li>';
    }
    echo '</ol>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_call_line(array $project): void
{
    if (($project['call_requested'] ?? false) !== true) {
        return;
    }
    $number = trim((string) ($project['call_number'] ?? ''));
    $time = trim((string) ($project['call_time'] ?? ''));
    echo '<div class="call-line">' . webco_ui_badge('Call requested', 'accent');
    if ($number !== '' || $time !== '') {
        echo '<dl class="kv">';
        if ($number !== '') {
            echo '<div><dt>Preferred number</dt><dd>' . webco_html($number) . '</dd></div>';
        }
        if ($time !== '') {
            echo '<div><dt>Preferred time</dt><dd>' . webco_html($time) . '</dd></div>';
        }
        echo '</dl>';
    }
    echo '</div>';
}

function webco_client_given(string $value): string
{
    $value = trim($value);

    return $value !== '' ? $value : 'Not given';
}

/**
 * @param array<string, mixed> $project
 * @return array<string, list<array{original_name: string, size_bytes: int, request_id: ?int}>>
 */
function webco_client_brief_assets(array $project): array
{
    $assets = is_array($project['assets'] ?? null) ? $project['assets'] : [];

    return webco_brief_assets_for_request($assets, null);
}

/**
 * @param array<string, list<array{original_name?: string, size_bytes?: int}>> $assets
 */
function webco_client_file_count(array $assets): int
{
    $count = 0;
    foreach (['logo', 'photo', 'document'] as $category) {
        $count += count($assets[$category] ?? []);
    }

    return $count;
}

/**
 * @param array<string, list<array{original_name?: string, size_bytes?: int}>> $assets
 */
function webco_client_file_groups(array $assets): void
{
    foreach (['logo' => 'Logos', 'photo' => 'Photos', 'document' => 'Documents'] as $category => $label) {
        $files = $assets[$category] ?? [];
        $count = count($files);
        echo '<section class="file-section" aria-labelledby="files-' . $category . '">';
        echo '<div class="file-section-head"><h2 id="files-' . $category . '">' . webco_html($label) . '</h2>';
        echo '<p class="meta">' . ($count === 1 ? '1 file' : (string) $count . ' files') . '</p></div>';
        if ($files === []) {
            echo '<p class="meta">None yet.</p>';
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
        echo '</section>';
    }
}

/**
 * @param array<string, mixed> $project
 * @return list<array<string, mixed>>
 */
function webco_client_request_rows(array $project): array
{
    $requests = $project['requests'] ?? [];
    if (!is_array($requests)) {
        return [];
    }
    $rows = [];
    foreach ($requests as $request) {
        if (is_array($request)) {
            $rows[] = $request;
        }
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $requests
 * @param array<string, list<array{original_name: string, size_bytes: int, request_id: ?int}>> $assets
 */
function webco_client_request_list(array $requests, bool $compact, bool $uploads, array $assets, string $csrf): void
{
    if ($requests === []) {
        echo '<p class="meta">No requests yet.</p>';

        return;
    }
    $heading = $compact ? 'h3' : 'h2';
    echo '<div class="request-list' . ($compact ? '' : ' is-cards') . '">';
    foreach ($requests as $request) {
        $requestId = (int) ($request['id'] ?? 0);
        $status = (string) ($request['status'] ?? '');
        $called = ($request['call_requested'] ?? false) === true;
        echo '<article class="request">';
        echo '<div class="request-head"><div class="request-id">';
        echo '<' . $heading . ' class="request-title">'
            . webco_html(webco_request_type_label((string) ($request['request_type'] ?? ''))) . '</' . $heading . '>';
        $date = webco_client_date(is_string($request['created_at'] ?? null) ? $request['created_at'] : null);
        if ($date !== '') {
            echo '<p class="meta request-date">' . webco_html($date) . '</p>';
        }
        echo '</div><div class="badges">';
        echo webco_ui_badge(webco_request_status_label($status), $status === 'in_progress' ? 'accent' : '');
        if ($called) {
            echo webco_ui_badge('Call requested', 'accent');
        }
        echo '</div></div>';
        $summary = (string) ($request['summary'] ?? '');
        echo '<p class="request-text summary">' . webco_html($compact ? webco_client_excerpt($summary) : $summary) . '</p>';
        if (!$compact && $called) {
            $number = trim((string) ($request['call_number'] ?? ''));
            $time = trim((string) ($request['call_time'] ?? ''));
            if ($number !== '' || $time !== '') {
                echo '<div class="request-call"><p class="label-sm">Call details</p><dl class="kv">';
                if ($number !== '') {
                    echo '<div><dt>Preferred number</dt><dd>' . webco_html($number) . '</dd></div>';
                }
                if ($time !== '') {
                    echo '<div><dt>Preferred time</dt><dd>' . webco_html($time) . '</dd></div>';
                }
                echo '</dl></div>';
            }
        }
        if (!$compact && $requestId > 0) {
            $owned = webco_brief_assets_for_request($assets, $requestId);
            $kinds = ['logo' => 'Logo', 'photo' => 'Photo', 'document' => 'Document'];
            echo '<div class="attachments"><p class="label-sm">Files with this request</p>';
            $rows = '';
            foreach ($kinds as $category => $kind) {
                foreach ($owned[$category] ?? [] as $file) {
                    $rows .= webco_ui_file_row(
                        (string) ($file['original_name'] ?? ''),
                        $kind . ' · ' . webco_brief_size((int) ($file['size_bytes'] ?? 0))
                    );
                }
            }
            if ($rows !== '') {
                echo '<ul class="files">' . $rows . '</ul>';
            } else {
                echo '<p class="meta">No files yet.</p>';
            }
            echo '</div>';
            if ($uploads) {
                echo '<details class="add-files"><summary class="btn btn-secondary btn-sm">Add a file to this request</summary>';
                webco_brief_auto_uploads($owned, $csrf, $requestId);
                echo '</details>';
            }
        }
        echo '</article>';
    }
    echo '</div>';
}
