<?php
/**
 * Opens Stripe Customer Portal for the brief session's own project.
 * GET does not create a session. The browser cannot choose the Stripe customer.
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

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
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
    $customerId = $db instanceof PDO ? webco_billing_portal_customer_for_project($db, $projectId) : null;
    $url = is_string($customerId) ? webco_open_billing_portal($customerId) : null;
    if ($url === null) {
        webco_brief_redirect('billing');
    }

    header('Location: ' . $url, true, 303);
    exit;
}
