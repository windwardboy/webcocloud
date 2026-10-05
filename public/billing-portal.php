<?php
/**
 * Opens Stripe Customer Portal for the brief session's own project.
 * POST creates the session. GET does not. Chrome treats a cross-origin
 * redirect as part of the form post, so the post returns to this page and
 * the following response opens the portal.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/brief.php';
require_once __DIR__ . '/lib/billing-portal.php';

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    webco_handle_billing_portal();
}

function webco_handle_billing_portal(): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'GET') {
        webco_billing_portal_continue();
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        http_response_code(405);
        exit;
    }

    webco_private_headers();
    webco_start_named_session('WEBCOBRIEF');
    if (!webco_brief_csrf_ok()) {
        webco_brief_redirect('again');
    }

    $projectId = webco_session_project_id();
    if ($projectId === null) {
        webco_brief_message(
            'Billing',
            'Open the secure link in your Webco email to continue.'
        );
    }

    $db = webco_db();
    if (!$db instanceof PDO) {
        webco_billing_portal_log('billing order not eligible');
        webco_brief_redirect('billing');
    }

    $row = webco_project_order_billing($db, $projectId);
    $failure = webco_billing_portal_eligibility_failure($row);
    $customerId = is_array($row) ? ($row['stripe_customer_id'] ?? null) : null;
    if ($failure !== null || !is_string($customerId)) {
        webco_billing_portal_log($failure ?? 'missing/invalid customer id');
        webco_brief_redirect('billing');
    }

    $url = webco_open_billing_portal($customerId);
    if ($url === null) {
        webco_brief_redirect('billing');
    }

    if (!webco_billing_portal_remember($url)) {
        webco_brief_redirect('billing');
    }

    webco_billing_portal_log('portal session ready');
    header('Location: /billing-portal.php', true, 303);
    exit;
}

function webco_billing_portal_continue(): void
{
    webco_private_headers();
    webco_start_named_session('WEBCOBRIEF');
    $url = webco_billing_portal_take();
    $html = is_string($url) ? webco_billing_portal_continue_html($url) : null;
    if ($html === null) {
        webco_brief_redirect('billing');
    }

    echo $html;
    exit;
}
