<?php
/**
 * Saves one draft order, then opens a Stripe Checkout Session for it.
 * Prices come from the saved package and care choice.
 * The order stays draft. A Checkout redirect is not payment.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/stripe.php';

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'draft-order.php') {
    handle_draft_order_request();
}

function handle_draft_order_request(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['status' => 'error']);
    }

    $contentType = strtolower(trim(strtok((string) ($_SERVER['CONTENT_TYPE'] ?? ''), ';')));
    if ($contentType !== 'application/json') {
        respond(400, ['status' => 'error']);
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 20000) {
        respond(400, ['status' => 'error']);
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        respond(400, ['status' => 'error']);
    }

    $order = order_from_state($payload);
    if ($order === null) {
        respond(400, ['status' => 'error']);
    }

    $db = webco_db();
    if (!$db instanceof PDO || !webco_ensure_orders_table($db)) {
        respond(500, ['status' => 'error']);
    }

    $requestedId = $payload['draftOrderId'] ?? null;
    if (!is_string($requestedId) || !preg_match('/^wc_[a-f0-9]{20}$/', $requestedId)) {
        $requestedId = null;
    }

    $started = webco_start_checkout($db, $order, $requestedId);
    if ($started === null) {
        respond(500, ['status' => 'error']);
    }
    if ($started['type'] === 'pending') {
        respond(200, ['orderId' => $started['orderId'], 'pending' => true]);
    }
    if ($started['type'] !== 'checkout') {
        respond(502, ['status' => 'error', 'orderId' => $started['orderId']]);
    }

    respond(201, ['orderId' => $started['orderId'], 'checkoutUrl' => $started['url']]);
}

/**
 * Reuses a saved unpaid order when Continue is pressed again.
 * A paid order is never given a second Checkout Session.
 *
 * @param array<string, mixed> $order
 * @return array{type: 'checkout', orderId: string, url: string}|array{type: 'pending', orderId: string}|array{type: 'error', orderId: string}|null
 */
function webco_start_checkout(PDO $db, array $order, ?string $requestedId): ?array
{
    $existing = null;
    if ($requestedId !== null) {
        $existing = webco_find_order_checkout_row($db, $requestedId);
        if ($existing === null) {
            $requestedId = null;
        }
    }

    if ($existing !== null) {
        $samePurchase = $existing['package_code'] === $order['package_code']
            && $existing['care_choice'] === $order['care_choice']
            && $existing['domain_name'] === $order['domain_name']
            && $existing['email'] === $order['email'];
        $sessionBindingSame = $existing['package_code'] === $order['package_code']
            && $existing['care_choice'] === $order['care_choice']
            && $existing['email'] === $order['email'];
        $action = webco_checkout_action($existing['status'], $samePurchase, null, $sessionBindingSame);
        if ($action === 'new_order') {
            $existing = null;
        } elseif ($existing['status'] === 'paid') {
            return ['type' => 'pending', 'orderId' => $existing['public_id']];
        }
    }

    if ($existing === null) {
        $publicId = webco_insert_draft_order($db, $order);
        if ($publicId === null) {
            return null;
        }

        return webco_open_checkout_session($db, $order, $publicId, null);
    }

    $publicId = $existing['public_id'];
    $sessionId = $existing['stripe_checkout_session_id'];
    $sessionBindingSame = $existing['package_code'] === $order['package_code']
        && $existing['care_choice'] === $order['care_choice']
        && $existing['email'] === $order['email'];

    if ($sessionId !== null) {
        $session = webco_stripe_fetch_session($sessionId);
        if ($session === null) {
            return ['type' => 'error', 'orderId' => $publicId];
        }
        $action = webco_checkout_action($existing['status'], true, $session['status'], $sessionBindingSame);
        if ($action === 'resume_paid') {
            return ['type' => 'pending', 'orderId' => $publicId];
        }
        if ($action === 'reuse_open_session') {
            if (!webco_update_open_order($db, $publicId, $order)) {
                return null;
            }
            if (!webco_bind_checkout_session($db, $publicId, $sessionId, $sessionId)) {
                return null;
            }

            return ['type' => 'checkout', 'orderId' => $publicId, 'url' => $session['url']];
        }
        if ($session['status'] === 'open') {
            if (!webco_stripe_expire_session($sessionId)) {
                return ['type' => 'error', 'orderId' => $publicId];
            }
            $after = webco_stripe_fetch_session($sessionId);
            if ($after !== null && $after['status'] === 'complete') {
                return ['type' => 'pending', 'orderId' => $publicId];
            }
        }
    }

    if (!webco_update_open_order($db, $publicId, $order)) {
        return null;
    }

    return webco_open_checkout_session($db, $order, $publicId, $sessionId);
}

/**
 * @param array<string, mixed> $order
 * @return array{type: 'checkout', orderId: string, url: string}|array{type: 'error', orderId: string}|null
 */
function webco_open_checkout_session(PDO $db, array $order, string $publicId, ?string $previousSessionId): ?array
{
    $idempotencyKey = 'webco-checkout-' . $publicId;
    if ($previousSessionId !== null) {
        $idempotencyKey .= '-' . substr(hash('sha256', $previousSessionId), 0, 16);
    }

    $created = webco_create_checkout_session($order, $publicId, $idempotencyKey);
    if ($created === null) {
        webco_checkout_log('open checkout failed for order ' . $publicId);
        return ['type' => 'error', 'orderId' => $publicId];
    }
    if (!webco_bind_checkout_session($db, $publicId, $created['id'], $previousSessionId)) {
        webco_checkout_log('bind checkout session failed for order ' . $publicId);
        return ['type' => 'error', 'orderId' => $publicId];
    }

    return ['type' => 'checkout', 'orderId' => $publicId, 'url' => $created['url']];
}

/**
 * Prices are taken from the package, not from the submitted strings.
 *
 * @param array<mixed> $state
 * @return array<string, mixed>|null
 */
function order_from_state(array $state): ?array
{
    $packages = [
        'essential' => ['name' => 'Webco Essential', 'price' => 59500, 'care' => 3900],
        'professional' => ['name' => 'Webco Professional', 'price' => 99500, 'care' => 5900],
    ];

    $package = $state['package'] ?? '';
    if (!is_string($package) || !isset($packages[$package])) {
        return null;
    }

    $domainPath = $state['domainPath'] ?? '';
    if ($domainPath !== 'new' && $domainPath !== 'existing') {
        return null;
    }

    $domain = is_string($state['domain'] ?? null) ? normalise_domain($state['domain']) : null;
    if ($domain === null) {
        return null;
    }
    if ($domainPath === 'new' && !domain_is_included_tld($domain)) {
        return null;
    }

    $details = $state['details'] ?? null;
    if (!is_array($details)) {
        return null;
    }

    $businessName = text_field($details['businessName'] ?? null, 2, 120);
    $contactName = text_field($details['contactName'] ?? null, 2, 120);
    $address1 = text_field($details['address1'] ?? null, 2, 120);
    $town = text_field($details['town'] ?? null, 2, 80);
    $address2 = optional_text($details['address2'] ?? null, 120);
    $county = optional_text($details['county'] ?? null, 80);
    if ($businessName === null || $contactName === null || $address1 === null || $town === null) {
        return null;
    }
    if ($address2 === false || $county === false) {
        return null;
    }

    $email = is_string($details['email'] ?? null) ? strtolower(trim($details['email'])) : '';
    if (strlen($email) > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }

    $phone = is_string($details['phone'] ?? null) ? preg_replace('/[\s()-]/', '', $details['phone']) ?? '' : '';
    if (!preg_match('/^(?:0\d{10}|\+44\d{10}|0044\d{10})$/', $phone)) {
        return null;
    }

    $contactMethod = $details['contactMethod'] ?? '';
    if (!in_array($contactMethod, ['email', 'phone', 'whatsapp'], true)) {
        return null;
    }

    $postcode = is_string($details['postcode'] ?? null) ? normalise_postcode($details['postcode']) : null;
    if ($postcode === null) {
        return null;
    }

    $companyNumber = optional_text($details['companyNumber'] ?? null, 8);
    if ($companyNumber === false) {
        return null;
    }
    if ($companyNumber !== null) {
        $companyNumber = strtoupper($companyNumber);
        if (!preg_match('/^(?:\d{8}|[A-Z]{2}\d{6})$/', $companyNumber)) {
            return null;
        }
    }

    $care = $state['care'] ?? null;
    if (!is_array($care)) {
        return null;
    }
    $careChoice = $care['choice'] ?? '';
    if ($careChoice !== 'managed' && $careChoice !== 'standard') {
        return null;
    }

    return [
        'package_code' => $package,
        'package_name' => $packages[$package]['name'],
        'package_price_pence' => $packages[$package]['price'],
        'domain_path' => $domainPath,
        'domain_name' => $domain,
        'business_name' => $businessName,
        'contact_name' => $contactName,
        'email' => $email,
        'phone' => $phone,
        'contact_method' => $contactMethod,
        'address_line_1' => $address1,
        'address_line_2' => $address2,
        'town' => $town,
        'county' => $county,
        'postcode' => $postcode,
        'company_number' => $companyNumber,
        'care_choice' => $careChoice,
        'care_price_pence' => $careChoice === 'managed' ? $packages[$package]['care'] : null,
    ];
}

function text_field(mixed $value, int $min, int $max): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    $length = strlen($value);
    if ($length < $min || $length > $max) {
        return null;
    }

    return $value;
}

function optional_text(mixed $value, int $max): string|false|null
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        return false;
    }
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') {
        return null;
    }
    if (strlen($value) > $max) {
        return false;
    }

    return $value;
}

function domain_is_included_tld(string $domain): bool
{
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.(?:co\.)?uk$/', $domain) === 1;
}

function normalise_domain(string $value): ?string
{
    $value = strtolower(trim($value));
    $value = preg_replace('#^https?://#', '', $value) ?? '';
    $value = preg_replace('#/.*$#', '', $value) ?? '';
    $value = rtrim($value, '.');

    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)) {
        return null;
    }

    return $value;
}

function normalise_postcode(string $value): ?string
{
    $compact = strtoupper((string) preg_replace('/\s+/', '', trim($value)));
    if (!preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $compact)) {
        return null;
    }

    return substr($compact, 0, -3) . ' ' . substr($compact, -3);
}

/**
 * @param array<string, mixed> $payload
 */
function respond(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
