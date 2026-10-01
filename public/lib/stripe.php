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
 */
function webco_create_checkout_session(array $order, string $publicId): ?string
{
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $publicId)) {
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
    if ($prices === null || $secret === null) {
        return null;
    }

    $orderQuery = rawurlencode($publicId);
    $successUrl = WEBCO_PUBLIC_ORIGIN
        . '/start/checkout/success/?order='
        . $orderQuery
        . '&session_id={CHECKOUT_SESSION_ID}';
    $cancelUrl = WEBCO_PUBLIC_ORIGIN . '/start/checkout/cancel/?order=' . $orderQuery;

    $body = webco_stripe_form([
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
    ]);

    $url = webco_stripe_post_session($secret, $body, $publicId);
    $secret = '';

    return $url;
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

function webco_stripe_secret(): ?string
{
    $secret = webco_stripe_constant('WEBCO_STRIPE_SECRET_KEY');
    if ($secret === null || !preg_match('/^sk_test_[A-Za-z0-9]{16,200}$/', $secret)) {
        return null;
    }

    return $secret;
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
        if (!is_file(WEBCO_SECRETS_FILE)) {
            return null;
        }
        ob_start();
        require_once WEBCO_SECRETS_FILE;
        ob_end_clean();
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

function webco_stripe_post_session(string $secret, string $body, string $publicId): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $handle = curl_init('https://api.stripe.com/v1/checkout/sessions');
    if ($handle === false) {
        return null;
    }

    $options = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secret,
            'Content-Type: application/x-www-form-urlencoded',
            'Idempotency-Key: webco-checkout-' . $publicId,
            'Expect:',
        ],
    ];
    if (defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($handle, $options);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    $secret = '';

    if (!is_string($response) || $status !== 200) {
        return null;
    }

    $payload = json_decode($response, true);
    $response = '';
    if (!is_array($payload)) {
        return null;
    }

    $url = $payload['url'] ?? null;
    if (!is_string($url) || !webco_is_test_checkout_url($url)) {
        return null;
    }

    return $url;
}

function webco_is_test_checkout_url(string $url): bool
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

    return str_contains($path, '/cs_test_');
}
