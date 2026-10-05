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
    echo '<p class="meta client-foot"><a href="/support/">Support</a></p>';
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
    echo '<header class="client-top">';
    echo '<p class="eyebrow">Client area</p>';
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
    echo '<section class="card welcome">';
    echo '<h1>' . webco_html($business !== '' ? $business : 'Your website') . '</h1>';
    echo '<dl class="facts">';
    echo '<div><dt>Package</dt><dd>' . webco_html(webco_client_package_label($project)) . '</dd></div>';
    echo '<div><dt>Domain</dt><dd>' . webco_html(webco_client_domain_label($project)) . '</dd></div>';
    $submitted = webco_client_date(is_string($project['submitted_at'] ?? null) ? $project['submitted_at'] : null);
    if ($submitted !== '') {
        echo '<div><dt>Brief submitted</dt><dd>' . webco_html($submitted) . '</dd></div>';
    }
    echo '</dl>';
    echo '<p class="status-line">' . webco_html(webco_client_status_sentence($status)) . '</p>';
    webco_client_call_line($project);
    echo '</section>';

    echo '<section class="progress-block" aria-labelledby="progress-heading">';
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

    echo '<h2>What you can do</h2>';
    echo '<div class="action-grid">';
    webco_client_action('/brief.php?view=brief', 'View website brief', 'Read the brief Webco received.', false);
    webco_client_action('/brief.php?view=files', 'Files you sent', $fileDetail, false);
    webco_client_action('/brief.php?view=request', 'Request an update', 'Or ask Webco for help.', true);
    webco_client_action('/brief.php?view=requests', 'View previous requests', $requestDetail, false);
    webco_client_action('/brief.php?view=contact', 'Contact Webco', $callDetail, false);
    echo '</div>';

    echo '<section class="card" aria-labelledby="requests-heading">';
    echo '<h2 id="requests-heading">Previous requests</h2>';
    webco_client_request_list(array_slice($requests, 0, 3), true, false, [], '');
    if ($requestCount > 3) {
        echo '<p><a href="/brief.php?view=requests">View all requests</a></p>';
    }
    echo '</section>';

    webco_client_billing(($project['billing_portal'] ?? false) === true);
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_view_brief(array $project): void
{
    echo '<h1>Website brief</h1>';
    $submitted = webco_client_date(is_string($project['submitted_at'] ?? null) ? $project['submitted_at'] : null);
    if ($submitted !== '') {
        echo '<p class="lead">Your website brief was received on ' . webco_html($submitted) . '.</p>';
    } else {
        echo '<p class="lead">Your website brief was received.</p>';
    }
    echo '<p class="note">This is the brief you submitted. To ask for a change, request an update.</p>';
    $sections = webco_brief_review_sections($project);
    if ($sections === []) {
        echo '<p class="meta">There are no written answers on this brief.</p>';
    } else {
        $labels = webco_brief_wizard_labels((string) ($project['package_code'] ?? 'essential'));
        $current = '';
        echo '<div class="card brief-read">';
        foreach ($sections as $section) {
            if ($section['step'] !== $current) {
                $current = $section['step'];
                echo '<h2>' . webco_html($labels[$current] ?? 'Brief') . '</h2>';
            }
            echo '<h3>' . webco_html($section['label']) . '</h3>';
            echo '<p class="summary">' . webco_html($section['value']) . '</p>';
        }
        echo '</div>';
    }
    echo '<p class="actions"><a class="button-link" href="/brief.php?view=request">Request an update</a></p>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_view_files(array $project): void
{
    echo '<h1>Files you sent</h1>';
    echo '<p class="lead">Logos, photos and documents saved with your website brief.</p>';
    echo '<div class="file-grid">';
    webco_client_file_groups(webco_client_brief_assets($project));
    echo '</div>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_view_request(array $project): void
{
    echo '<h1>Request an update</h1>';
    echo '<p class="lead">Ask for a website change, or ask Webco for help. This stays separate from your website brief.</p>';
    echo '<section class="card">';
    webco_brief_request_form($project, webco_brief_csrf_token());
    echo '</section>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_client_view_requests(array $project): void
{
    echo '<h1>Your requests</h1>';
    echo '<p class="lead">Updates and questions you have sent about this website.</p>';
    echo '<p class="actions"><a class="button-link" href="/brief.php?view=request">Request an update</a></p>';
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
    echo '<h1>Contact Webco</h1>';
    $reference = trim((string) ($project['order_public_id'] ?? ''));
    if ($reference !== '') {
        echo '<p class="meta">Project reference ' . webco_html($reference) . '</p>';
    }
    echo '<section class="card">';
    if (($project['call_requested'] ?? false) === true) {
        echo '<h2>Call requested</h2>';
        echo '<p class="note">You asked for a phone call about the website brief.</p>';
        echo '<dl class="facts">';
        echo '<div><dt>Preferred number</dt><dd>' . webco_html(webco_client_given((string) ($project['call_number'] ?? ''))) . '</dd></div>';
        echo '<div><dt>Preferred time</dt><dd>' . webco_html(webco_client_given((string) ($project['call_time'] ?? ''))) . '</dd></div>';
        echo '</dl>';
    } else {
        echo '<h2>Phone call</h2>';
        echo '<p>You have not asked for a phone call about this website brief.</p>';
    }
    echo '</section>';
    echo '<p class="actions"><a class="button-link" href="/brief.php?view=request">Ask Webco for help</a></p>';
}

/**
 * Both actions open the same Stripe Customer Portal session for this project.
 */
function webco_client_billing(bool $available = false): void
{
    echo '<section class="card billing" id="billing">';
    echo '<h2>Billing</h2>';
    if (!$available) {
        echo '<p class="lead">Invoices and payment details are not available in this area yet.</p>';
        echo '<ul class="placeholder-list">';
        echo '<li><span>Billing &amp; invoices</span> <span class="soon">Coming next</span></li>';
        echo '<li><span>Manage payment details</span> <span class="soon">Coming next</span></li>';
        echo '</ul></section>';

        return;
    }

    echo '<p class="lead">Invoices, receipts and the card used for this website are managed in Stripe.</p>';
    echo '<form method="post" action="/billing-portal.php">';
    echo '<input type="hidden" name="csrf" value="' . webco_html(webco_brief_csrf_token()) . '">';
    echo '<button type="submit">Billing &amp; invoices</button>';
    echo '<button type="submit">Manage payment details</button>';
    echo '</form></section>';
}

function webco_client_action(string $href, string $title, string $detail, bool $primary): void
{
    echo '<a class="' . ($primary ? 'action-card action-primary' : 'action-card') . '" href="' . webco_html($href) . '">';
    echo '<strong>' . webco_html($title) . '</strong>';
    echo '<span>' . webco_html($detail) . '</span>';
    echo '</a>';
}

function webco_client_timeline_list(string $status): void
{
    echo '<ol class="stages">';
    foreach (webco_client_timeline($status) as $item) {
        $state = $item['state'];
        $word = match ($state) {
            'done' => 'Done',
            'current' => 'Now',
            default => 'Later',
        };
        echo '<li class="' . $state . '"' . ($state === 'current' ? ' aria-current="step"' : '') . '>';
        echo '<span class="stage-state">' . webco_html($word) . '</span>';
        echo '<span class="stage-label">' . webco_html($item['label']) . '</span>';
        echo '</li>';
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
    echo '<p class="call-line"><strong>Call requested</strong>';
    $number = trim((string) ($project['call_number'] ?? ''));
    $time = trim((string) ($project['call_time'] ?? ''));
    if ($number !== '') {
        echo '<span>Preferred number ' . webco_html($number) . '</span>';
    }
    if ($time !== '') {
        echo '<span>Preferred time ' . webco_html($time) . '</span>';
    }
    echo '</p>';
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
        echo '<section class="card file-group">';
        echo '<h2>' . webco_html($label) . '</h2>';
        if ($files === []) {
            echo '<p class="meta">None yet.</p>';
        } else {
            echo '<ul class="files">';
            foreach ($files as $file) {
                echo '<li><span class="file-name">' . webco_html((string) ($file['original_name'] ?? ''))
                    . '</span> <span class="meta">(' . webco_html(webco_brief_size((int) ($file['size_bytes'] ?? 0)))
                    . ')</span></li>';
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
    echo '<div class="request-list">';
    foreach ($requests as $request) {
        $requestId = (int) ($request['id'] ?? 0);
        echo '<article class="request-row">';
        echo '<p class="request-type">' . webco_html(webco_request_type_label((string) ($request['request_type'] ?? ''))) . '</p>';
        echo '<p class="request-meta">';
        $date = webco_client_date(is_string($request['created_at'] ?? null) ? $request['created_at'] : null);
        if ($date !== '') {
            echo '<span>' . webco_html($date) . '</span>';
        }
        echo '<span class="pill">' . webco_html(webco_request_status_label((string) ($request['status'] ?? ''))) . '</span>';
        if (($request['call_requested'] ?? false) === true) {
            echo '<span class="pill">Call requested</span>';
        }
        echo '</p>';
        $summary = (string) ($request['summary'] ?? '');
        echo '<p class="summary">' . webco_html($compact ? webco_client_excerpt($summary) : $summary) . '</p>';
        if (!$compact && ($request['call_requested'] ?? false) === true) {
            $number = trim((string) ($request['call_number'] ?? ''));
            $time = trim((string) ($request['call_time'] ?? ''));
            if ($number !== '' || $time !== '') {
                echo '<p class="meta">';
                if ($number !== '') {
                    echo 'Preferred number ' . webco_html($number);
                }
                if ($time !== '') {
                    echo ($number !== '' ? ' · ' : '') . 'Preferred time ' . webco_html($time);
                }
                echo '</p>';
            }
        }
        if (!$compact && $requestId > 0) {
            $owned = webco_brief_assets_for_request($assets, $requestId);
            $named = [];
            foreach (['logo' => 'Logo', 'photo' => 'Photo', 'document' => 'Document'] as $category => $label) {
                foreach ($owned[$category] ?? [] as $file) {
                    $named[] = $label . ': ' . (string) ($file['original_name'] ?? '');
                }
            }
            if ($named !== []) {
                echo '<ul class="files">';
                foreach ($named as $name) {
                    echo '<li>' . webco_html($name) . '</li>';
                }
                echo '</ul>';
            }
            if ($uploads) {
                echo '<details class="request-files"><summary>Add a file to this request</summary>';
                webco_brief_auto_uploads($owned, $csrf, $requestId);
                echo '</details>';
            }
        }
        echo '</article>';
    }
    echo '</div>';
}

function webco_client_styles(): string
{
    return '<style>
      body.client-home { background: #f6f5f1; color: #0e1b20; }
      body.client-home main { max-width: 72rem; }
      body.client-home h1 { font-size: clamp(2rem, 4vw, 3rem); }
      body.client-home h2 { margin: 1.25rem 0 0.4rem; font-size: clamp(1.35rem, 2vw, 1.7rem); }
      body.client-home .card h2 { margin-top: 0; }
      body.client-home .card { margin-top: 1rem; }
      .client-top { margin-bottom: 0.4rem; }
      .client-nav { display: flex; flex-wrap: wrap; gap: 0.45rem; margin-top: 0.75rem; }
      .client-nav a {
        display: inline-flex; align-items: center; min-height: 2.75rem; padding: 0.35rem 0.85rem;
        border: 1px solid #d5e0dc; border-radius: 999px; background: #fff; color: #122028;
        font-weight: 650; text-decoration: none;
      }
      .client-nav a[aria-current="page"] { background: #0c6b62; border-color: #0c6b62; color: #f7fbfa; }
      .welcome h1 { margin-bottom: 0.2rem; }
      .facts { display: grid; gap: 0.75rem; margin: 1rem 0 0; }
      .facts div { min-width: 0; }
      .facts dt { color: #3e4e58; font-size: 0.82rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
      .facts dd { margin: 0.15rem 0 0; overflow-wrap: anywhere; font-weight: 650; }
      .status-line { margin-top: 1rem; padding: 0.8rem 0.9rem; border-radius: 12px; background: #e5f3f1; font-weight: 650; }
      .call-line { display: flex; flex-direction: column; gap: 0.15rem; margin-top: 0.85rem; }
      .call-line strong { font-weight: 750; }
      .stages { display: grid; gap: 0.45rem; margin: 0.6rem 0 0; padding: 0; list-style: none; }
      .stages li {
        display: flex; flex-direction: column; gap: 0.1rem; padding: 0.7rem 0.85rem;
        border: 1px solid #d5e0dc; border-radius: 12px; background: #fff; color: #3e4e58;
      }
      .stages li.done { color: #0c6b62; }
      .stages li.current { border-color: #0c6b62; background: #e5f3f1; color: #122028; font-weight: 700; }
      .stage-state { font-size: 0.78rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
      .action-grid { display: grid; gap: 0.75rem; margin-top: 0.6rem; }
      .action-card {
        display: flex; flex-direction: column; justify-content: center; gap: 0.25rem;
        min-height: 5.5rem; padding: 1rem 1.05rem; border: 1px solid #d5e0dc; border-radius: 16px;
        background: #fff; color: #122028; text-decoration: none;
      }
      .action-card strong { font-size: 1.08rem; }
      .action-card span { color: #3e4e58; }
      .action-primary { background: #0c6b62; border-color: #0c6b62; color: #f7fbfa; }
      .action-primary span { color: #e5f3f1; }
      .request-list { display: grid; gap: 0.75rem; margin-top: 0.8rem; }
      .request-row { padding: 0.9rem 1rem; border: 1px solid #d5e0dc; border-radius: 12px; background: #fff; }
      .request-type { margin: 0; font-weight: 750; }
      .request-meta { display: flex; flex-wrap: wrap; gap: 0.35rem 0.6rem; align-items: center; margin-top: 0.25rem; color: #3e4e58; }
      .pill { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; background: #e5f3f1; color: #08524b; font-size: 0.85rem; font-weight: 700; }
      .file-grid { display: grid; gap: 0.75rem; }
      .file-name { font-weight: 650; overflow-wrap: anywhere; }
      .placeholder-list { margin: 0.8rem 0 0; padding: 0; list-style: none; }
      .placeholder-list li {
        display: flex; justify-content: space-between; gap: 0.75rem; align-items: center;
        margin-top: 0.55rem; padding: 0.75rem 0.85rem; border: 1px dashed #d5e0dc; border-radius: 12px;
      }
      .soon { padding: 0.12rem 0.5rem; border-radius: 999px; background: #f3f6f5; color: #3e4e58; font-size: 0.82rem; font-weight: 700; white-space: nowrap; }
      body.client-home .billing form { display: grid; gap: 0.65rem; margin-top: 0.9rem; }
      body.client-home .billing button { width: 100%; margin: 0; min-height: 2.75rem; }
      .brief-read h2 { margin-top: 1.1rem; }
      .brief-read h2:first-child { margin-top: 0; }
      .request-files { margin-top: 0.7rem; }
      .request-files summary { cursor: pointer; font-weight: 650; }
      a.button-link {
        display: inline-flex; align-items: center; min-height: 2.75rem; margin-top: 0.8rem;
        padding: 0.55rem 1rem; border-radius: 999px; background: #0c6b62; color: #f7fbfa;
        font-weight: 650; text-decoration: none;
      }
      .client-foot { margin-top: 1.5rem; }
      body.client-home a:focus-visible,
      body.client-home button:focus-visible,
      body.client-home summary:focus-visible { outline: 3px solid #0b7a6e; outline-offset: 3px; }
      @media (min-width: 40rem) {
        .facts { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .call-line { flex-direction: row; flex-wrap: wrap; gap: 0.35rem 1rem; align-items: baseline; }
        .action-grid { grid-template-columns: 1fr 1fr; }
        body.client-home .billing form { grid-template-columns: 1fr 1fr; }
      }
      @media (min-width: 64rem) {
        .stages { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        .action-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .file-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
      }
    </style>';
}
