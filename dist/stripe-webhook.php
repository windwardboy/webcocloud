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

    $eventId = $event['id'] ?? '';
    $eventType = $event['type'] ?? '';
    if (!is_string($eventId) || !is_string($eventType)) {
        webco_webhook_respond(400, ['status' => 'error']);
    }

    $db = webco_db();
    if (!$db instanceof PDO || !webco_ensure_orders_table($db)) {
        webco_webhook_respond(500, ['status' => 'error']);
    }

    $claim = webco_claim_stripe_event($db, $eventId, $eventType);
    if ($claim['state'] === 'done') {
        webco_webhook_respond(200, ['received' => true, 'result' => 'already']);
    }
    if ($claim['state'] !== 'claimed' || !isset($claim['claimed_at'])) {
        webco_webhook_respond(500, ['status' => 'error']);
    }

    $outcome = webco_apply_stripe_event($db, $event);
    if ($outcome === 'retry') {
        // Leave processed_at null so Stripe can retry or a stale claim can be reclaimed.
        webco_checkout_log('stripe webhook retry for ' . $eventType);
        webco_webhook_respond(500, ['status' => 'error']);
    }

    $result = $outcome === 'reject' ? 'rejected' : $outcome;
    // applied / ignored / reject are terminal for this delivery. Acknowledge with 200 so
    // Stripe does not retry a finished reject into a no-op "already" success.
    $finished = webco_finish_stripe_event($db, $eventId, $claim['claimed_at'], $result);
    if (!$finished) {
        webco_webhook_respond(500, ['status' => 'error']);
    }
    if ($outcome === 'reject') {
        webco_checkout_log('stripe webhook rejected ' . $eventType);
    }

    webco_webhook_respond(200, ['received' => true, 'result' => $result]);
}

/**
 * Recognised subscription and refund events are acknowledged without
 * billing changes. Managed Care cancellation must not start hosting.
 *
 * @param array<mixed> $event
 * @return 'applied'|'ignored'|'reject'|'retry'
 */
function webco_apply_stripe_event(PDO $db, array $event): string
{
    $livemode = $event['livemode'] ?? null;
    if ($livemode !== false && $livemode !== true) {
        return 'ignored';
    }

    $type = $event['type'] ?? '';
    if (!is_string($type)) {
        return 'reject';
    }

    if ($type === 'checkout.session.completed') {
        return webco_apply_checkout_completed($db, $event);
    }
    if ($type === 'checkout.session.expired') {
        return webco_apply_checkout_expired($db, $event);
    }
    if (
        $type === 'customer.subscription.updated'
        || $type === 'customer.subscription.deleted'
        || $type === 'invoice.paid'
        || $type === 'invoice.payment_failed'
        || $type === 'charge.refunded'
        || $type === 'refund.created'
        || $type === 'refund.updated'
    ) {
        return 'ignored';
    }

    return 'ignored';
}

/**
 * @param array<mixed> $event
 * @return 'applied'|'ignored'|'reject'|'retry'
 */
function webco_apply_checkout_completed(PDO $db, array $event): string
{
    $payment = webco_checkout_payment_from_event($event);
    if ($payment === null) {
        return 'ignored';
    }
    if ($payment === false) {
        return 'reject';
    }

    $order = webco_find_order_checkout_row($db, $payment['public_id']);
    if ($order === null) {
        return 'ignored';
    }
    if ($order['status'] === 'paid') {
        if ($order['stripe_checkout_session_id'] !== $payment['session_id']) {
            return 'reject';
        }
        if (webco_ensure_paid_project($db, $payment['public_id']) !== 'ok') {
            return 'retry';
        }

        return 'applied';
    }
    if ($order['stripe_checkout_session_id'] !== null && $order['stripe_checkout_session_id'] !== $payment['session_id']) {
        return 'reject';
    }

    $subscriptionId = $payment['subscription_id'];
    if ($subscriptionId === null) {
        return 'retry';
    }

    $lineItems = webco_stripe_fetch_line_items($payment['session_id']);
    $subscription = webco_stripe_fetch_subscription($subscriptionId);
    if ($lineItems === null || $subscription === null) {
        return 'retry';
    }

    $prices = webco_stripe_line_prices($order['package_code'], $order['care_choice']);
    $expectedWebsitePriceId = is_array($prices) ? $prices[0] : null;
    $websiteFee = webco_checkout_website_fee_pence(
        $lineItems,
        $order['package_price_pence'],
        $expectedWebsitePriceId
    );
    if ($websiteFee === null) {
        webco_checkout_log('checkout completed rejected: website fee mismatch');
        return 'reject';
    }

    $local = webco_local_subscription_state($order['care_choice'], $subscription['status'], $subscription['trial_end']);
    if ($local === null) {
        return 'retry';
    }

    $result = webco_record_checkout_payment($db, [
        'public_id' => $payment['public_id'],
        'session_id' => $payment['session_id'],
        'customer_id' => $payment['customer_id'],
        'subscription_id' => $subscriptionId,
        'payment_intent_id' => $payment['payment_intent_id'],
        'stripe_livemode' => $payment['stripe_livemode'],
        'website_amount_pence' => $websiteFee,
        'care_status' => $local['care_status'],
        'care_trial_ends_at' => $local['care_trial_ends_at'],
        'hosting_status' => $local['hosting_status'],
        'hosting_included_until' => $local['hosting_included_until'],
    ]);
    if ($result === 'error') {
        return 'retry';
    }
    if ($result === 'mismatch') {
        return 'reject';
    }
    if ($result === 'missing') {
        return 'ignored';
    }
    if ($result !== 'paid' && $result !== 'already') {
        return 'retry';
    }
    if ($result === 'already' && $order['stripe_checkout_session_id'] !== null && $order['stripe_checkout_session_id'] !== $payment['session_id']) {
        return 'reject';
    }
    if (webco_ensure_paid_project($db, $payment['public_id']) !== 'ok') {
        return 'retry';
    }

    return 'applied';
}

/**
 * @param array<mixed> $event
 * @return 'applied'|'ignored'|'reject'|'retry'
 */
function webco_apply_checkout_expired(PDO $db, array $event): string
{
    $session = webco_checkout_reference_from_event($event, 'checkout.session.expired');
    if ($session === null) {
        return 'ignored';
    }
    if ($session === false) {
        return 'reject';
    }

    $result = webco_cancel_open_checkout($db, $session['public_id'], $session['session_id']);
    if ($result === 'error') {
        return 'retry';
    }
    if ($result === 'cancelled') {
        return 'applied';
    }

    return 'ignored';
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
 * @return array{public_id: string, session_id: string, customer_id: ?string, subscription_id: ?string, payment_intent_id: ?string, stripe_livemode: int}|false|null
 */
function webco_checkout_payment_from_event(array $event): array|false|null
{
    $session = webco_checkout_reference_from_event($event, 'checkout.session.completed');
    if ($session === null || $session === false) {
        return $session;
    }

    $object = $event['data']['object'] ?? null;
    if (!is_array($object) || ($object['payment_status'] ?? '') !== 'paid') {
        return null;
    }

    $customer = webco_optional_stripe_id($object['customer'] ?? null, 'cus_');
    $subscription = webco_optional_stripe_id($object['subscription'] ?? null, 'sub_');
    $paymentIntent = webco_optional_stripe_id($object['payment_intent'] ?? null, 'pi_');
    $livemode = webco_stripe_livemode_column($object['livemode'] ?? null);
    if ($livemode === null) {
        return null;
    }
    if ($customer === false || $subscription === false || $paymentIntent === false) {
        return false;
    }

    return [
        'public_id' => $session['public_id'],
        'session_id' => $session['session_id'],
        'customer_id' => $customer,
        'subscription_id' => $subscription,
        'payment_intent_id' => $paymentIntent,
        'stripe_livemode' => $livemode,
    ];
}

/**
 * @param array<mixed> $event
 * @return array{public_id: string, session_id: string}|false|null
 */
function webco_checkout_reference_from_event(array $event, string $expectedType): array|false|null
{
    $type = $event['type'] ?? '';
    if (!is_string($type) || $type !== $expectedType) {
        return null;
    }
    $eventLive = $event['livemode'] ?? null;
    if ($eventLive !== false && $eventLive !== true) {
        return null;
    }

    $session = $event['data']['object'] ?? null;
    if (!is_array($session) || ($session['object'] ?? '') !== 'checkout.session') {
        return false;
    }
    $sessionLive = $session['livemode'] ?? null;
    if ($sessionLive !== false && $sessionLive !== true) {
        return null;
    }
    if ($sessionLive !== $eventLive) {
        return false;
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

    $prefix = $sessionLive === true ? 'cs_live_' : 'cs_test_';
    $sessionId = webco_stripe_reference_id($session['id'] ?? null, $prefix);
    if ($sessionId === null) {
        return false;
    }

    return [
        'public_id' => $reference,
        'session_id' => $sessionId,
    ];
}

/**
 * Null when the field is absent, false when it is present but not a valid id.
 */
function webco_optional_stripe_id(mixed $value, string $prefix): string|false|null
{
    if ($value === null || $value === '') {
        return null;
    }

    $id = webco_stripe_reference_id($value, $prefix);

    return $id === null ? false : $id;
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
