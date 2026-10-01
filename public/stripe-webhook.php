<?php
/**
 * Stripe webhook for checkout.session.completed.
 *
 * The signing secret stays in the private secrets file as WEBCO_STRIPE_WEBHOOK_SECRET.
 * The browser success URL is not consulted and is not proof of payment.
 * An order is marked paid only after the signature is valid and the saved draft matches.
 * A paid order then gets one project. That step does not change the paid update.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/stripe.php';
require_once __DIR__ . '/lib/projects.php';

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'stripe-webhook.php') {
    webco_handle_stripe_webhook();
}

function webco_handle_stripe_webhook(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        webco_webhook_respond(405, ['status' => 'error']);
    }

    $claimedLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($claimedLength > 200000) {
        webco_webhook_respond(400, ['status' => 'error']);
    }

    $input = fopen('php://input', 'rb');
    if ($input === false) {
        webco_webhook_respond(400, ['status' => 'error']);
    }
    $payload = stream_get_contents($input, 200001);
    fclose($input);
    if (!is_string($payload) || $payload === '' || strlen($payload) > 200000) {
        webco_webhook_respond(400, ['status' => 'error']);
    }

    $header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if (!is_string($header) || $header === '') {
        webco_webhook_respond(400, ['status' => 'error']);
    }

    $secret = webco_stripe_webhook_secret();
    if ($secret === null) {
        webco_webhook_respond(500, ['status' => 'error']);
    }

    $valid = webco_stripe_signature_valid($payload, $header, $secret, time());
    $secret = '';
    if (!$valid) {
        webco_webhook_respond(400, ['status' => 'error']);
    }

    $event = json_decode($payload, true);
    $payload = '';
    if (!is_array($event)) {
        webco_webhook_respond(400, ['status' => 'error']);
    }

    $payment = webco_checkout_payment_from_event($event);
    if ($payment === null) {
        webco_webhook_respond(200, ['received' => true]);
    }
    if ($payment === false) {
        webco_webhook_respond(400, ['status' => 'error']);
    }

    $db = webco_db();
    if (!$db instanceof PDO || !webco_ensure_orders_table($db)) {
        webco_webhook_respond(500, ['status' => 'error']);
    }

    $result = webco_mark_order_paid(
        $db,
        $payment['public_id'],
        $payment['session_id'],
        $payment['customer_id'],
        $payment['subscription_id']
    );
    if ($result === 'error') {
        webco_webhook_respond(500, ['status' => 'error']);
    }

    if ($result === 'paid' || $result === 'already') {
        if (webco_ensure_paid_project($db, $payment['public_id']) !== 'ok') {
            webco_webhook_respond(500, ['status' => 'error']);
        }
    }

    webco_webhook_respond(200, ['received' => true]);
}

function webco_stripe_signature_valid(string $payload, string $header, string $secret, int $now): bool
{
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) {
            continue;
        }
        $key = trim($pair[0]);
        $value = trim($pair[1]);
        if ($key === 't' && preg_match('/^[0-9]{10,12}$/', $value)) {
            $timestamp = (int) $value;
        }
        if ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/', $value)) {
            $signatures[] = $value;
        }
    }

    if ($timestamp === null || $signatures === []) {
        return false;
    }
    if (abs($now - $timestamp) > 300) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}

/**
 * A paid Checkout Session to apply, null when the event should be acknowledged
 * without a database write, or false when the signed event is malformed.
 *
 * @param array<mixed> $event
 * @return array{public_id: string, session_id: string, customer_id: ?string, subscription_id: ?string}|false|null
 */
function webco_checkout_payment_from_event(array $event): array|false|null
{
    $type = $event['type'] ?? '';
    if (!is_string($type) || $type !== 'checkout.session.completed') {
        return null;
    }
    if (($event['livemode'] ?? null) !== false) {
        return null;
    }

    $session = $event['data']['object'] ?? null;
    if (!is_array($session) || ($session['object'] ?? '') !== 'checkout.session') {
        return false;
    }
    if (($session['livemode'] ?? null) !== false) {
        return null;
    }
    if (($session['payment_status'] ?? '') !== 'paid') {
        return null;
    }

    $reference = $session['client_reference_id'] ?? null;
    $metadata = $session['metadata'] ?? null;
    $metadataId = is_array($metadata) ? ($metadata['public_id'] ?? null) : null;
    if (!is_string($reference) || !is_string($metadataId) || $reference !== $metadataId) {
        return false;
    }
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $reference)) {
        return false;
    }

    $sessionId = webco_stripe_reference_id($session['id'] ?? null, 'cs_test_');
    if ($sessionId === null) {
        return false;
    }

    return [
        'public_id' => $reference,
        'session_id' => $sessionId,
        'customer_id' => webco_stripe_reference_id($session['customer'] ?? null, 'cus_'),
        'subscription_id' => webco_stripe_reference_id($session['subscription'] ?? null, 'sub_'),
    ];
}

function webco_stripe_reference_id(mixed $value, string $prefix): ?string
{
    if (is_array($value)) {
        $value = $value['id'] ?? null;
    }
    if (!is_string($value) || strlen($value) > 255) {
        return null;
    }
    $pattern = '/^' . preg_quote($prefix, '/') . '[A-Za-z0-9]{8,240}$/';
    if (!preg_match($pattern, $value)) {
        return null;
    }

    return $value;
}

/**
 * @param array<string, mixed> $body
 */
function webco_webhook_respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
