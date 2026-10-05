<?php
/**
 * Client home after a submitted brief. The wizard and request storage stay as they are.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/brief.php';

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "ok  {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL {$message}\n";
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['csrf'] = '0123456789abcdef0123456789abcdef';

$indexes = [
    'awaiting_brief' => -1,
    'brief_in_progress' => -1,
    'brief_received' => 0,
    'ready_for_clone' => 1,
    'ready_for_build' => 1,
    'in_build' => 2,
    'review' => 3,
    'ready_to_launch' => 4,
    'live' => 4,
];
foreach ($indexes as $status => $index) {
    check(webco_client_stage_index($status) === $index, "{$status} maps to timeline position {$index}");
}
check(webco_client_stage_index('ready_for_clone') === webco_client_stage_index('ready_for_build'), 'clone and build preparation are the same stage');

$building = webco_client_timeline('in_build');
check($building[0]['state'] === 'done' && $building[0]['label'] === 'Brief received', 'a build has passed the brief');
check($building[1]['state'] === 'done' && $building[1]['label'] === 'Site preparation', 'a build has passed preparation');
check($building[2]['state'] === 'current' && $building[2]['label'] === 'Build', 'a build is the current stage');
check($building[3]['state'] === 'upcoming' && $building[3]['label'] === 'Your review', 'review is still ahead during a build');
check($building[4]['label'] === 'Ready to launch' && $building[4]['state'] === 'upcoming', 'launch stays ahead during a build');

$live = webco_client_timeline('live');
check($live[3]['state'] === 'done', 'a live website has finished review');
check($live[4]['label'] === 'Live' && $live[4]['state'] === 'current', 'a live website shows Live');
check(webco_client_timeline('ready_to_launch')[4]['label'] === 'Ready to launch', 'a launch-ready website names that stage');
check(webco_client_timeline('brief_received')[0]['state'] === 'current', 'a received brief is the current stage');
check(webco_client_timeline('awaiting_brief')[0]['state'] === 'upcoming', 'a waiting brief has not reached the first stage');

check(webco_client_status_sentence('ready_for_clone') === 'Webco is preparing your website.', 'preparation is described without the internal name');
check(webco_client_status_sentence('review') === 'Your website is ready for you to review.', 'review is described for the customer');
check(!str_contains(webco_client_status_sentence('not_a_status'), 'not_a_status'), 'an unknown status is not printed');
check(webco_client_date('2026-10-03 15:04:00') === '3 October 2026', 'a brief date is shown in plain English');
check(webco_client_date('not-a-date') === '', 'an unreadable date is left blank');
check(webco_client_date(null) === '', 'a missing date is left blank');
$excerpt = webco_client_excerpt(str_repeat('a', 180) . 'UNIQUE-TAIL-TOKEN');
check(str_ends_with($excerpt, '…') && !str_contains($excerpt, 'UNIQUE-TAIL-TOKEN'), 'a long request is shortened on the compact list');
check(webco_client_package_label(['package_name' => '', 'package_code' => 'professional']) === 'Webco Professional', 'a professional package has a customer label');
check(webco_client_domain_label(['domain_name' => '']) === 'Not confirmed yet', 'a missing domain is explained');
check(webco_client_view_valid('requests') && !webco_client_view_valid('wizard'), 'only client sections are accepted');

$long = str_repeat('a', 180) . 'UNIQUE-TAIL-TOKEN';
$project = [
    'id' => 1,
    'order_public_id' => 'wc_0123456789abcdef0123',
    'status' => 'in_build',
    'business_name' => 'Kent Training',
    'package_code' => 'professional',
    'package_name' => 'Webco Professional',
    'domain_name' => 'kent-training.example',
    'summary' => 'Please keep the yard photo.',
    'submitted_at' => '2026-10-03 15:04:00',
    'phone' => '01634 000111',
    'business_overview' => 'We train HGV drivers.',
    'call_requested' => true,
    'call_number' => '07700 900123',
    'call_time' => 'Tuesday morning',
    'call_note' => 'Talk about the yard photo.',
    'internal_note' => 'SECRET ADMIN NOTE',
    'assets' => [
        'logo' => [['original_name' => 'kent-logo.png', 'size_bytes' => 2048, 'request_id' => null]],
        'photo' => [
            ['original_name' => 'yard.jpg', 'size_bytes' => 4096, 'request_id' => null],
            ['original_name' => 'request-only-photo.jpg', 'size_bytes' => 100, 'request_id' => 7],
        ],
        'document' => [],
    ],
    'requests' => [
        [
            'id' => 9,
            'request_type' => 'website_update',
            'status' => 'open',
            'summary' => $long,
            'call_requested' => false,
            'call_number' => '',
            'call_time' => '',
            'call_note' => '',
            'created_at' => '2026-10-03 12:00:00',
        ],
        [
            'id' => 7,
            'request_type' => 'content_change',
            'status' => 'in_progress',
            'summary' => 'Please change the phone number.',
            'call_requested' => true,
            'call_number' => '07700 900999',
            'call_time' => 'Friday afternoon',
            'call_note' => 'SECRET REQUEST NOTE',
            'created_at' => '2026-10-02 10:00:00',
        ],
        [
            'id' => 6,
            'request_type' => 'support_question',
            'status' => 'done',
            'summary' => 'Where is the contact page?',
            'call_requested' => false,
            'call_number' => '',
            'call_time' => '',
            'call_note' => '',
            'created_at' => '2026-10-01 10:00:00',
        ],
        [
            'id' => 5,
            'request_type' => 'other',
            'status' => 'open',
            'summary' => 'FOURTH-ONLY-REQUEST',
            'call_requested' => false,
            'call_number' => '',
            'call_time' => '',
            'call_note' => '',
            'created_at' => '2026-09-01 10:00:00',
        ],
    ],
];

$home = client_html($project, 'home');
check(str_contains($home, 'class="client-home"'), 'the client home uses the dashboard layout');
check(str_contains($home, 'Kent Training'), 'the welcome shows the business name');
check(str_contains($home, 'Webco Professional'), 'the welcome shows the package');
check(str_contains($home, 'kent-training.example'), 'the welcome shows the domain');
check(str_contains($home, '3 October 2026'), 'the welcome shows the brief date');
check(str_contains($home, 'Webco is building your website.'), 'the welcome uses a plain-English status');
check(str_contains($home, 'Website progress'), 'website progress is on the home');
check(str_contains($home, 'aria-current="step"'), 'the current stage is marked');
check(str_contains($home, 'Call requested'), 'a requested brief call is shown');
check(str_contains($home, '07700 900123'), 'the preferred brief number is shown');
check(str_contains($home, 'Tuesday morning'), 'the preferred brief time is shown');
check(str_contains($home, 'View website brief'), 'the brief can be opened from the home');
check(str_contains($home, 'Files you sent'), 'files can be opened from the home');
check(str_contains($home, 'Request an update'), 'a request is a clear action');
check(str_contains($home, 'View previous requests'), 'previous requests are a clear action');
check(str_contains($home, 'Contact Webco'), 'contact is a clear action');
check(str_contains($home, 'href="/brief.php?view=brief"'), 'client links stay on the brief session');
check(!str_contains($home, 'access='), 'client links do not ask for the email token');
check(str_contains($home, 'View all requests'), 'older requests remain one step away');
check(!str_contains($home, 'FOURTH-ONLY-REQUEST'), 'the home list stays short');
check(!str_contains($home, 'UNIQUE-TAIL-TOKEN'), 'the home shortens a long request');
check(str_contains($home, 'In progress'), 'a request status uses its customer label');
check(str_contains($home, 'Content change'), 'a request type uses its customer label');
check(!str_contains($home, 'name="request_summary"'), 'the request form is not expanded on the home');
check(!str_contains($home, '<script'), 'the home does not include the upload script');
check(str_contains($home, 'id="billing"'), 'billing has a section');
check(str_contains($home, 'Coming next'), 'billing is marked as not available yet');
check(str_contains($home, 'Manage payment details'), 'payment details are named as a later action');
$billingStart = strpos($home, 'id="billing"');
$billingEnd = $billingStart === false ? false : strpos($home, '</section>', $billingStart);
$billing = is_int($billingStart) && is_int($billingEnd) ? substr($home, $billingStart, $billingEnd - $billingStart) : '';
check($billing !== '' && !str_contains($billing, 'href='), 'billing does not use a link');
check(!str_contains($billing, 'action="/billing-portal.php"'), 'billing stays closed without a stored test customer');
check(!str_contains($home, 'billing.stripe.com') && !str_contains($home, 'customer_portal'), 'billing does not pretend to open Stripe');
$portalHome = client_html(project_with($project, [
    'billing_portal' => true,
    'stripe_customer_id' => 'cus_' . str_repeat('e', 14),
]), 'home');
$portalStart = strpos($portalHome, 'id="billing"');
$portalEnd = $portalStart === false ? false : strpos($portalHome, '</section>', $portalStart);
$portalBilling = is_int($portalStart) && is_int($portalEnd) ? substr($portalHome, $portalStart, $portalEnd - $portalStart) : '';
check(str_contains($portalBilling, 'action="/billing-portal.php"'), 'billing posts to the portal endpoint');
check(str_contains($portalBilling, 'method="post"'), 'billing uses a form post');
check(str_contains($portalBilling, 'name="csrf"'), 'billing sends the brief session token');
check(substr_count($portalBilling, '<button type="submit">') === 2, 'invoices and payment details are both buttons');
check(str_contains($portalBilling, 'Billing &amp; invoices'), 'the invoice action is labelled');
check(str_contains($portalBilling, 'Manage payment details'), 'the payment action is labelled');
check(!str_contains($portalBilling, 'href='), 'the portal buttons are not links');
check(!str_contains($portalBilling, 'Coming next'), 'an available portal is not marked as coming next');
check(!str_contains($portalHome, 'cus_' . str_repeat('e', 14)), 'the Stripe customer id is not shown');
check(!str_contains($portalHome, 'billing.stripe.com'), 'the portal address is created on the server');
check(!str_contains($home, 'SECRET ADMIN NOTE'), 'an internal note is not shown');
check(!str_contains($home, 'SECRET REQUEST NOTE'), 'a request call note is not shown on the home');
check(!str_contains($home, 'Talk about the yard photo.'), 'the brief call note stays on the brief');
check(!str_contains($home, 'in_progress'), 'the internal request status is not shown');
check(!str_contains($home, 'in_build'), 'the internal project status is not shown');
check(!str_contains($home, '07700 900999'), 'a request call number stays on the request itself');

foreach (['awaiting_brief', 'brief_in_progress', 'brief_received', 'ready_for_clone', 'ready_for_build', 'in_build', 'ready_to_launch'] as $status) {
    $page = client_html(project_with($project, ['status' => $status]), 'home');
    check(!str_contains($page, $status), "the {$status} home does not print that internal name");
}
$clone = client_html(project_with($project, ['status' => 'ready_for_clone']), 'home');
check(str_contains($clone, 'Webco is preparing your website.'), 'site preparation has a customer sentence');
check(str_contains($clone, 'Site preparation'), 'site preparation is the timeline label');
check(!str_contains(strtolower($clone), 'clone'), 'clone is not a customer word');
$livePage = client_html(project_with($project, ['status' => 'live']), 'home');
check(str_contains($livePage, 'Your website is live.'), 'a live website says so');
check(str_contains($livePage, '>Live<') || str_contains($livePage, 'Live</span>'), 'the last stage can read Live');
check(!str_contains($livePage, 'Ready to launch'), 'Live replaces the launch label');

$brief = client_html($project, 'brief');
check(str_contains($brief, 'Your website brief was received on 3 October 2026.'), 'the brief page confirms it was received');
check(str_contains($brief, 'We train HGV drivers.'), 'the submitted brief can be read');
check(str_contains($brief, 'Talk about the yard photo.'), 'the submitted call note can be read with the brief');
check(!str_contains($brief, 'name="business_overview"'), 'the brief page is not the edit wizard');
check(!str_contains($brief, 'Submit website brief'), 'the brief page does not submit the wizard again');
check(!str_contains($brief, 'Update submitted brief'), 'editing is not offered after submission');
check(str_contains($brief, 'href="/brief.php?view=request"'), 'a change goes through a request');

$files = client_html($project, 'files');
check(str_contains($files, 'Logos') && str_contains($files, 'Photos') && str_contains($files, 'Documents'), 'files are grouped');
check(strpos($files, 'Logos') < strpos($files, 'Photos') && strpos($files, 'Photos') < strpos($files, 'Documents'), 'file groups stay in a stable order');
check(str_contains($files, 'kent-logo.png') && str_contains($files, 'yard.jpg'), 'brief files keep their names');
check(str_contains($files, '(2 KB)'), 'a file size helps tell uploads apart');
check(str_contains($files, 'None yet.'), 'an empty group is still labelled');
check(!str_contains($files, 'request-only-photo.jpg'), 'a request file stays with that request');
check(!str_contains($files, 'name="file"'), 'the files page does not add an upload control');

$request = client_html($project, 'request');
check(str_contains($request, 'name="request_summary"'), 'the request form is available when opened');
check(str_contains($request, 'name="intent" value="request"'), 'the request form still posts the existing action');
check(str_contains($request, 'Would you like a phone call about this request?'), 'a request can still ask for a call');
check(str_contains($request, 'value="01634 000111"'), 'the request call number still starts from the order phone');
check(!str_contains($request, 'Website progress'), 'opening a request does not repeat the whole home');

$requests = client_html($project, 'requests');
check(str_contains($requests, 'FOURTH-ONLY-REQUEST'), 'the requests page lists older requests');
check(str_contains($requests, 'UNIQUE-TAIL-TOKEN'), 'the requests page keeps the full summary');
check(str_contains($requests, 'In progress') && str_contains($requests, 'Call requested'), 'a request shows its status and call');
check(str_contains($requests, '07700 900999') && str_contains($requests, 'Friday afternoon'), 'a request call keeps its number and time');
check(str_contains($requests, 'Photo: request-only-photo.jpg'), 'a file sent with a request stays identifiable');
check(str_contains($requests, 'Add a file to this request'), 'a request can still receive a file');
check(str_contains($requests, 'name="file"'), 'request uploads still use the existing upload form');
check(!str_contains($requests, 'name="request_summary"'), 'the requests page does not expand the new-request form');
check(!str_contains($requests, 'SECRET REQUEST NOTE'), 'a request call note is not treated as a customer summary');
check(!str_contains($requests, 'in_progress'), 'request rows do not show the stored status code');

$contact = client_html($project, 'contact');
check(str_contains($contact, 'Call requested'), 'contact shows that a brief call was requested');
check(str_contains($contact, 'Preferred number') && str_contains($contact, '07700 900123'), 'contact shows the preferred number');
check(str_contains($contact, 'Preferred time') && str_contains($contact, 'Tuesday morning'), 'contact shows the preferred time');
check(str_contains($contact, 'wc_0123456789abcdef0123'), 'contact can quote the project reference');
check(str_contains($contact, 'Ask Webco for help'), 'contact can open a request without the email link');
check(!str_contains($contact, 'SECRET ADMIN NOTE'), 'contact does not show an internal note');
check(!str_contains($contact, 'Talk about the yard photo.'), 'contact does not add the call note beyond the brief');

$quiet = client_html(project_with($project, [
    'call_requested' => false,
    'domain_name' => '',
    'package_name' => '',
    'submitted_at' => null,
    'requests' => [],
]), 'home');
check(!str_contains($quiet, 'Call requested'), 'a brief without a call does not say one was requested');
check(str_contains($quiet, 'Not confirmed yet'), 'a blank domain is explained on the home');
check(str_contains($quiet, 'Webco Professional'), 'the package code still has a label');

$unknown = client_html($project, 'billing-portal');
check(str_contains($unknown, 'What you can do'), 'an unknown section returns to the client home');
check(!str_contains($unknown, 'billing-portal'), 'an unknown section name is not printed');

ob_start();
webco_client_render($project, 'home', '<script>alert(1)</script>');
$escaped = ob_get_clean();
check(is_string($escaped) && str_contains($escaped, '&lt;script&gt;') && !str_contains($escaped, '<script>alert'), 'a notice cannot inject markup');

ob_start();
webco_brief_render_wizard([
    'package_code' => 'essential',
    'package_name' => 'Webco Essential',
    'business_name' => 'Kent Training',
    'order_public_id' => 'wc_test',
    'status' => 'brief_in_progress',
    'assets' => ['logo' => [], 'photo' => [], 'document' => []],
], 'business', '', '0123456789abcdef0123456789abcdef');
$wizard = ob_get_clean();
check(is_string($wizard) && str_contains($wizard, 'Your website brief'), 'an unfinished brief still uses the wizard');
check(is_string($wizard) && !str_contains($wizard, 'client-home'), 'an unfinished brief is not the client home');

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);

/**
 * @param array<string, mixed> $project
 */
function client_html(array $project, string $view): string
{
    ob_start();
    webco_client_render($project, $view, '');
    $html = ob_get_clean();

    return is_string($html) ? $html : '';
}

/**
 * @param array<string, mixed> $project
 * @param array<string, mixed> $changes
 * @return array<string, mixed>
 */
function project_with(array $project, array $changes): array
{
    return array_merge($project, $changes);
}
