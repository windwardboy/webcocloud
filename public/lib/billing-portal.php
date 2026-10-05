<?php
/**
 * Stripe Customer Portal for the website in the current brief session.
 * The customer id comes from that project's paid order. Checkout and webhooks
 * are not involved.
 */

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/projects.php';

function webco_billing_livemode(mixed $value): ?int
{
    if ($value === 0 || $value === '0') {
        return 0;
    }
    if ($value === 1 || $value === '1') {
        return 1;
    }

    return null;
}

function webco_billing_portal_customer_id(string $customerId): bool
{
    return $customerId !== '' && webco_stripe_customer_id_valid($customerId);
}

function webco_billing_portal_allowed(?string $customerId, mixed $livemode, string $orderStatus): bool
{
    return $orderStatus === 'paid'
        && webco_billing_livemode($livemode) === 0
        && is_string($customerId)
        && webco_billing_portal_customer_id($customerId);
}

function webco_billing_portal_return_url(): string
{
    return WEBCO_PUBLIC_ORIGIN . '/brief.php';
}

function webco_billing_portal_fields(string $customerId): ?string
{
    if (!webco_billing_portal_customer_id($customerId)) {
        return null;
    }

    return webco_stripe_form([
        'customer' => $customerId,
        'return_url' => webco_billing_portal_return_url(),
    ]);
}

function webco_is_test_billing_portal_url(string $url): bool
{
    if (strlen($url) > 2048 || preg_match('/[\s\r\n\\\\]/', $url) === 1) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $host = $parts['host'] ?? '';
    if (!is_string($host) || strcasecmp($host, 'billing.stripe.com') !== 0) {
        return false;
    }
    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        return false;
    }
    $path = $parts['path'] ?? '';
    if (!is_string($path) || !str_contains($path, '/session/test_')) {
        return false;
    }

    return true;
}

/**
 * @param array<mixed> $body
 */
function webco_billing_portal_url(array $body, string $customerId): ?string
{
    if (($body['object'] ?? '') !== 'billing_portal.session') {
        return null;
    }
    $returned = $body['customer'] ?? null;
    if (!is_string($returned) || !hash_equals($customerId, $returned)) {
        return null;
    }
    if (webco_stripe_livemode_column($body['livemode'] ?? null) !== 0) {
        return null;
    }
    $url = $body['url'] ?? null;
    if (!is_string($url) || !webco_is_test_billing_portal_url($url)) {
        return null;
    }

    return $url;
}

function webco_open_billing_portal(string $customerId): ?string
{
    $body = webco_billing_portal_fields($customerId);
    $secret = webco_stripe_secret();
    if ($body === null || $secret === null) {
        $secret = '';

        return null;
    }

    $response = webco_stripe_api($secret, 'POST', '/v1/billing_portal/sessions', $body, null);
    $secret = '';
    if ($response === null || $response['status'] !== 200 || !is_array($response['body'])) {
        return null;
    }

    return webco_billing_portal_url($response['body'], $customerId);
}

/**
 * @return array{status: string, stripe_customer_id: ?string, stripe_livemode: ?int}|null
 */
function webco_project_order_billing(PDO $db, int $projectId): ?array
{
    if ($projectId < 1) {
        return null;
    }

    try {
        $statement = $db->prepare(
            'SELECT o.status, o.stripe_customer_id, o.stripe_livemode
             FROM projects p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.id = :id'
        );
        $statement->execute(['id' => $projectId]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return null;
    }
    if ($row === false) {
        return null;
    }

    $customer = $row['stripe_customer_id'] ?? null;
    if (!is_string($customer) || $customer === '') {
        $customer = null;
    }

    return [
        'status' => (string) ($row['status'] ?? ''),
        'stripe_customer_id' => $customer,
        'stripe_livemode' => webco_billing_livemode($row['stripe_livemode'] ?? null),
    ];
}

function webco_billing_portal_customer_for_project(PDO $db, int $projectId): ?string
{
    $row = webco_project_order_billing($db, $projectId);
    if ($row === null || !webco_billing_portal_allowed(
        $row['stripe_customer_id'],
        $row['stripe_livemode'],
        $row['status']
    )) {
        return null;
    }

    return $row['stripe_customer_id'];
}

function webco_project_billing_portal_open(PDO $db, int $projectId): bool
{
    return webco_billing_portal_customer_for_project($db, $projectId) !== null;
}
