<?php
/**
 * Stripe Checkout Sessions for saved draft orders.
 *
 * The secret key and Price IDs stay in the private secrets file, outside
 * this repository. This file must not print or return those values.
 * A return from Checkout is not payment confirmation.
 */

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'stripe.php') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';

const WEBCO_PUBLIC_ORIGIN = 'https://webcocloud.net';

/**
 * @param array{package_code?: mixed, care_choice?: mixed, email?: mixed} $order
 * @return array{id: string, url: string}|null
 */
function webco_create_checkout_session(array $order, string $publicId, ?string $idempotencyKey = null): ?array
{
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $publicId)) {
        return null;
    }
    if ($idempotencyKey === null) {
        $idempotencyKey = 'webco-checkout-' . $publicId;
    }
    if (!preg_match('/^webco-checkout-[A-Za-z0-9_-]{8,180}$/', $idempotencyKey)) {
        return null;
    }

    $packageCode = $order['package_code'] ?? null;
    $careChoice = $order['care_choice'] ?? null;
    $email = $order['email'] ?? null;
    if (!is_string($packageCode) || !is_string($careChoice) || !is_string($email)) {
        return null;
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }

    $prices = webco_stripe_line_prices($packageCode, $careChoice);
    $secret = webco_stripe_secret();
    if ($prices === null) {
        webco_checkout_log('checkout create blocked: price configuration unavailable');
        return null;
    }
    if ($secret === null) {
        webco_checkout_log('checkout create blocked: secret key unavailable or not sk_test_/sk_live_');
        return null;
    }

    $orderQuery = rawurlencode($publicId);
    $successUrl = WEBCO_PUBLIC_ORIGIN
        . '/start/checkout/success/?order='
        . $orderQuery
        . '&session_id={CHECKOUT_SESSION_ID}';
    $cancelUrl = WEBCO_PUBLIC_ORIGIN . '/start/checkout/cancel/?order=' . $orderQuery;

    $fields = [
        'mode' => 'subscription',
        'client_reference_id' => $publicId,
        'customer_email' => $email,
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'metadata[public_id]' => $publicId,
        'subscription_data[metadata][public_id]' => $publicId,
        'line_items[0][price]' => $prices[0],
        'line_items[0][quantity]' => '1',
        'line_items[1][price]' => $prices[1],
        'line_items[1][quantity]' => '1',
    ];
    if ($careChoice === 'managed') {
        $fields['subscription_data[trial_period_days]'] = '30';
    } elseif ($careChoice === 'standard') {
        $fields['subscription_data[trial_end]'] = (string) webco_stripe_calendar_year_timestamp(time());
    } else {
        return null;
    }

    $body = webco_stripe_form($fields);

    $created = webco_stripe_post_session($secret, $body, $idempotencyKey);
    $secret = '';
    if ($created === null) {
        webco_checkout_log('checkout create blocked: stripe session response rejected');
    }

    return $created;
}

/**
 * One-time website fee actually collected on the Checkout Session.
 * Recurring Managed Care and hosting amounts are ignored.
 *
 * @param array<mixed> $lineItems
 */
function webco_collected_website_pence(array $lineItems): ?int
{
    if (($lineItems['has_more'] ?? false) === true) {
        return null;
    }
    $rows = $lineItems['data'] ?? null;
    if (!is_array($rows)) {
        return null;
    }

    $sum = 0;
    $oneTime = 0;
    foreach ($rows as $item) {
        if (!is_array($item)) {
            return null;
        }
        $price = $item['price'] ?? null;
        if (!is_array($price)) {
            return null;
        }
        $type = $price['type'] ?? '';
        if ($type === 'recurring') {
            continue;
        }
        if ($type !== 'one_time') {
            return null;
        }
        $currency = $item['currency'] ?? $price['currency'] ?? '';
        if ($currency !== 'gbp') {
            return null;
        }
        $amount = webco_stripe_amount($item['amount_total'] ?? null);
        if ($amount === null) {
            return null;
        }
        $oneTime++;
        $sum += $amount;
    }
    if ($oneTime !== 1) {
        return null;
    }

    return $sum;
}

/**
 * Local care and hosting columns for the single Checkout subscription.
 * Managed Care includes hosting, so it does not get a hosting trial end.
 *
 * @return array{
 *   care_status: ?string,
 *   care_trial_ends_at: ?string,
 *   hosting_status: string,
 *   hosting_included_until: ?string
 * }|null
 */
function webco_local_subscription_state(string $careChoice, string $stripeStatus, ?int $trialEnd): ?array
{
    $mapped = webco_map_stripe_subscription_status($stripeStatus);
    if ($mapped === null || $trialEnd === null || $trialEnd < 1) {
        return null;
    }
    $trialAt = gmdate('Y-m-d H:i:s', $trialEnd);
    if (!webco_utc_datetime_valid($trialAt)) {
        return null;
    }

    if ($careChoice === 'managed') {
        return [
            'care_status' => $mapped,
            'care_trial_ends_at' => $trialAt,
            'hosting_status' => 'included',
            'hosting_included_until' => null,
        ];
    }
    if ($careChoice === 'standard') {
        return [
            'care_status' => null,
            'care_trial_ends_at' => null,
            'hosting_status' => $mapped,
            'hosting_included_until' => $trialAt,
        ];
    }

    return null;
}

function webco_stripe_livemode_column(mixed $livemode): ?int
{
    if ($livemode === false) {
        return 0;
    }
    if ($livemode === true) {
        return 1;
    }

    return null;
}

/**
 * @param array<mixed> $session
 * @return array{livemode: int, subscription_id: ?string}|null
 */
function webco_stripe_session_subscription(array $session): ?array
{
    $livemode = webco_stripe_livemode_column($session['livemode'] ?? null);
    if ($livemode === null) {
        return null;
    }

    $subscription = $session['subscription'] ?? null;
    if (is_array($subscription)) {
        $subscription = $subscription['id'] ?? null;
    }
    if ($subscription === null || $subscription === '') {
        return [
            'livemode' => $livemode,
            'subscription_id' => null,
        ];
    }
    if (!is_string($subscription) || strlen($subscription) > 255 || !preg_match('/^sub_[A-Za-z0-9]{8,240}$/', $subscription)) {
        return null;
    }
    $subscriptionId = $subscription;

    return [
        'livemode' => $livemode,
        'subscription_id' => $subscriptionId,
    ];
}

/**
 * @param array<mixed> $subscription
 * @return array{livemode: int, status: string, trial_end: ?int}|null
 */
function webco_stripe_subscription_fields(array $subscription): ?array
{
    $livemode = webco_stripe_livemode_column($subscription['livemode'] ?? null);
    $status = $subscription['status'] ?? '';
    if ($livemode === null || !is_string($status) || $status === '') {
        return null;
    }

    $trialEnd = $subscription['trial_end'] ?? null;
    if (is_string($trialEnd) && preg_match('/^\d+$/', $trialEnd)) {
        $trialEnd = (int) $trialEnd;
    }
    if (!is_int($trialEnd) || $trialEnd < 1) {
        $trialEnd = null;
    }

    return [
        'livemode' => $livemode,
        'status' => $status,
        'trial_end' => $trialEnd,
    ];
}

function webco_map_stripe_subscription_status(string $status): ?string
{
    if ($status === 'trialing' || $status === 'active' || $status === 'past_due') {
        return $status;
    }
    if ($status === 'canceled' || $status === 'cancelled') {
        return 'cancelled';
    }

    return null;
}

function webco_stripe_amount(mixed $amount): ?int
{
    if (is_int($amount) && $amount >= 0) {
        return $amount;
    }
    if (is_string($amount) && preg_match('/^\d+$/', $amount)) {
        return (int) $amount;
    }

    return null;
}

/**
 * @return array{id: string, url: string, status: string}|null
 */
function webco_stripe_fetch_session(string $sessionId): ?array
{
    if (!webco_stripe_checkout_session_id_valid($sessionId)) {
        return null;
    }

    $secret = webco_stripe_secret();
    if ($secret === null) {
        return null;
    }
    $response = webco_stripe_api($secret, 'GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), null, null);
    $secret = '';
    if ($response === null || $response['status'] !== 200 || !is_array($response['body'])) {
        return null;
    }

    return webco_stripe_session_record($response['body']);
}

function webco_stripe_expire_session(string $sessionId): bool
{
    if (!webco_stripe_checkout_session_id_valid($sessionId)) {
        return false;
    }

    $secret = webco_stripe_secret();
    if ($secret === null) {
        return false;
    }
    $response = webco_stripe_api($secret, 'POST', '/v1/checkout/sessions/' . rawurlencode($sessionId) . '/expire', '', null);
    $secret = '';
    if ($response === null) {
        return false;
    }
    if ($response['status'] === 200 && is_array($response['body'])) {
        $record = webco_stripe_session_record($response['body']);

        return $record !== null && ($record['status'] === 'expired' || $record['status'] === 'complete');
    }

    $current = webco_stripe_fetch_session($sessionId);

    return $current !== null && ($current['status'] === 'expired' || $current['status'] === 'complete');
}

/**
 * @return array<mixed>|null
 */
function webco_stripe_fetch_line_items(string $sessionId): ?array
{
    if (!webco_stripe_checkout_session_id_valid($sessionId)) {
        return null;
    }

    $secret = webco_stripe_secret();
    if ($secret === null) {
        return null;
    }
    $response = webco_stripe_api(
        $secret,
        'GET',
        '/v1/checkout/sessions/' . rawurlencode($sessionId) . '/line_items?limit=10',
        null,
        null
    );
    $secret = '';
    if ($response === null || $response['status'] !== 200 || !is_array($response['body'])) {
        return null;
    }
    if (array_key_exists('livemode', $response['body'])) {
        $livemode = $response['body']['livemode'];
        if ($livemode !== false && $livemode !== true) {
            return null;
        }
    }

    return $response['body'];
}

/**
 * @return array{status: string, trial_end: int}|null
 */
function webco_stripe_fetch_subscription(string $subscriptionId): ?array
{
    if (!preg_match('/^sub_[A-Za-z0-9]{8,240}$/', $subscriptionId)) {
        return null;
    }

    $secret = webco_stripe_secret();
    if ($secret === null) {
        return null;
    }
    $response = webco_stripe_api($secret, 'GET', '/v1/subscriptions/' . rawurlencode($subscriptionId), null, null);
    $secret = '';
    if ($response === null || $response['status'] !== 200 || !is_array($response['body'])) {
        return null;
    }
    $livemode = $response['body']['livemode'] ?? null;
    if ($livemode !== false && $livemode !== true) {
        return null;
    }

    $status = $response['body']['status'] ?? '';
    $trialEnd = $response['body']['trial_end'] ?? null;
    if (!is_string($status)) {
        return null;
    }
    if (is_string($trialEnd) && preg_match('/^\d+$/', $trialEnd)) {
        $trialEnd = (int) $trialEnd;
    }
    if (!is_int($trialEnd) || $trialEnd < 1) {
        return null;
    }

    return [
        'status' => $status,
        'trial_end' => $trialEnd,
    ];
}

/**
 * Read-only Checkout Session fields used to find a subscription.
 * Null when the request fails or the signed mode flag is absent.
 *
 * @return array{livemode: int, subscription_id: ?string}|null
 */
function webco_stripe_get_session_subscription(string $sessionId): ?array
{
    if (!webco_stripe_checkout_session_id_valid($sessionId)) {
        return null;
    }

    $secret = webco_stripe_secret();
    if ($secret === null) {
        return null;
    }
    $response = webco_stripe_api($secret, 'GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), null, null);
    $secret = '';
    if ($response === null || $response['status'] !== 200 || !is_array($response['body'])) {
        return null;
    }

    return webco_stripe_session_subscription($response['body']);
}

/**
 * Read-only subscription mode, status and trial end.
 * A missing trial end is returned as null. The request itself failing returns null.
 *
 * @return array{livemode: int, status: string, trial_end: ?int}|null
 */
function webco_stripe_get_subscription_fields(string $subscriptionId): ?array
{
    if (!preg_match('/^sub_[A-Za-z0-9]{8,240}$/', $subscriptionId)) {
        return null;
    }

    $secret = webco_stripe_secret();
    if ($secret === null) {
        return null;
    }
    $response = webco_stripe_api($secret, 'GET', '/v1/subscriptions/' . rawurlencode($subscriptionId), null, null);
    $secret = '';
    if ($response === null || $response['status'] !== 200 || !is_array($response['body'])) {
        return null;
    }

    return webco_stripe_subscription_fields($response['body']);
}

/**
 * @param array<mixed> $session
 * @return array{id: string, url: string, status: string}|null
 */
function webco_stripe_session_record(array $session): ?array
{
    $livemode = $session['livemode'] ?? null;
    if ($livemode !== false && $livemode !== true) {
        return null;
    }
    $id = $session['id'] ?? null;
    $status = $session['status'] ?? '';
    if (!is_string($id) || !webco_stripe_checkout_session_id_valid($id)) {
        return null;
    }
    if ($livemode === true && !str_starts_with($id, 'cs_live_')) {
        return null;
    }
    if ($livemode === false && !str_starts_with($id, 'cs_test_')) {
        return null;
    }
    if ($status !== 'open' && $status !== 'complete' && $status !== 'expired') {
        return null;
    }

    $url = $session['url'] ?? '';
    if (!is_string($url)) {
        $url = '';
    }
    if ($status === 'open' && !webco_is_stripe_checkout_url($url)) {
        return null;
    }

    return [
        'id' => $id,
        'url' => $url,
        'status' => $status,
    ];
}

/**
 * Managed Care includes hosting, so the annual hosting price is used only
 * when care was saved as standard.
 *
 * @return array{0: string, 1: string}|null
 */
function webco_stripe_line_prices(string $packageCode, string $careChoice): ?array
{
    if ($packageCode === 'essential') {
        $websiteName = 'WEBCO_STRIPE_PRICE_ESSENTIAL_WEBSITE';
    } elseif ($packageCode === 'professional') {
        $websiteName = 'WEBCO_STRIPE_PRICE_PROFESSIONAL_WEBSITE';
    } else {
        return null;
    }

    if ($careChoice === 'managed' && $packageCode === 'essential') {
        $recurringName = 'WEBCO_STRIPE_PRICE_ESSENTIAL_MANAGED_CARE';
    } elseif ($careChoice === 'managed' && $packageCode === 'professional') {
        $recurringName = 'WEBCO_STRIPE_PRICE_PROFESSIONAL_MANAGED_CARE';
    } elseif ($careChoice === 'standard') {
        $recurringName = 'WEBCO_STRIPE_PRICE_HOSTING';
    } else {
        return null;
    }

    $website = webco_stripe_price_id($websiteName);
    $recurring = webco_stripe_price_id($recurringName);
    if ($website === null || $recurring === null) {
        return null;
    }

    return [$website, $recurring];
}

/**
 * The same UTC clock time one calendar year later.
 * 29 February lands on 28 February when the next year is not a leap year.
 */
function webco_stripe_calendar_year_timestamp(int $now): int
{
    $start = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('UTC'));
    $year = (int) $start->format('Y') + 1;
    $month = (int) $start->format('n');
    $day = (int) $start->format('j');
    $lastDay = (int) $start->setDate($year, $month, 1)->format('t');
    if ($day > $lastDay) {
        $day = $lastDay;
    }

    return $start->setDate($year, $month, $day)->getTimestamp();
}

function webco_stripe_secret(): ?string
{
    $secret = webco_stripe_constant('WEBCO_STRIPE_SECRET_KEY');
    if ($secret === null || !preg_match('/^sk_(test|live)_[A-Za-z0-9]{16,200}$/', $secret)) {
        return null;
    }

    return $secret;
}

function webco_stripe_checkout_session_id_valid(string $sessionId): bool
{
    return preg_match('/^cs_(test|live)_[A-Za-z0-9]{8,240}$/', $sessionId) === 1
        && strlen($sessionId) <= 255;
}

/**
 * True for open Checkout URLs in either Stripe test or live mode.
 */
function webco_is_stripe_checkout_url(string $url): bool
{
    if (strlen($url) > 2048) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = $parts['scheme'] ?? '';
    $host = $parts['host'] ?? '';
    $path = $parts['path'] ?? '';
    if ($scheme !== 'https' || $host !== 'checkout.stripe.com') {
        return false;
    }

    return str_contains($path, '/cs_test_') || str_contains($path, '/cs_live_');
}

/**
 * @deprecated Use webco_is_stripe_checkout_url(); kept for older callers.
 */
function webco_is_test_checkout_url(string $url): bool
{
    return webco_is_stripe_checkout_url($url);
}

/**
 * Internal checkout diagnostics. Never log secrets, Price IDs, or Stripe bodies.
 */
function webco_checkout_log(string $marker): void
{
    $marker = trim($marker);
    if ($marker === '' || strlen($marker) > 200) {
        return;
    }
    if (preg_match('/sk_(?:test|live)_|whsec_|price_|Bearer\s/i', $marker) === 1) {
        return;
    }

    error_log('webco checkout: ' . $marker);
}

function webco_stripe_webhook_secret(): ?string
{
    $secret = webco_stripe_constant('WEBCO_STRIPE_WEBHOOK_SECRET');
    if ($secret === null || !preg_match('/^whsec_[A-Za-z0-9+\/=_-]{8,200}$/', $secret)) {
        return null;
    }

    return $secret;
}

function webco_stripe_price_id(string $name): ?string
{
    $priceId = webco_stripe_constant($name);
    if ($priceId === null || !preg_match('/^price_[A-Za-z0-9]{8,80}$/', $priceId)) {
        return null;
    }

    return $priceId;
}

function webco_stripe_constant(string $name): ?string
{
    if (!defined('WEBCO_SECRETS_FILE')) {
        return null;
    }
    if (!defined($name)) {
        if (!webco_load_secrets()) {
            return null;
        }
    }

    return webco_loaded_secret($name, null);
}

/**
 * @param array<string, string> $fields
 */
function webco_stripe_form(array $fields): string
{
    $pairs = [];
    foreach ($fields as $key => $value) {
        $encoded = rawurlencode($value);
        if ($key === 'success_url') {
            $encoded = str_replace('%7BCHECKOUT_SESSION_ID%7D', '{CHECKOUT_SESSION_ID}', $encoded);
        }
        $pairs[] = rawurlencode($key) . '=' . $encoded;
    }

    return implode('&', $pairs);
}

/**
 * @return array{id: string, url: string}|null
 */
function webco_stripe_post_session(string $secret, string $body, string $idempotencyKey): ?array
{
    $response = webco_stripe_api($secret, 'POST', '/v1/checkout/sessions', $body, $idempotencyKey);
    $secret = '';
    if ($response === null || $response['status'] !== 200 || !is_array($response['body'])) {
        return null;
    }

    $record = webco_stripe_session_record($response['body']);
    if ($record === null || $record['status'] !== 'open') {
        return null;
    }

    return [
        'id' => $record['id'],
        'url' => $record['url'],
    ];
}

/**
 * @return array{status: int, body: array<mixed>|null}|null
 */
function webco_stripe_api(string $secret, string $method, string $path, ?string $body, ?string $idempotencyKey): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    if ($method !== 'GET' && $method !== 'POST') {
        return null;
    }
    if (!preg_match('#^/v1/[A-Za-z0-9_./?=&%-]+$#', $path)) {
        return null;
    }

    $handle = curl_init('https://api.stripe.com' . $path);
    if ($handle === false) {
        return null;
    }

    $headers = [
        'Authorization: Bearer ' . $secret,
        'Expect:',
    ];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if ($idempotencyKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POSTFIELDS] = $body ?? '';
    }
    if (defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($handle, $options);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    $secret = '';

    if (!is_string($response)) {
        return null;
    }

    $payload = json_decode($response, true);
    $response = '';

    return [
        'status' => $status,
        'body' => is_array($payload) ? $payload : null,
    ];
}
