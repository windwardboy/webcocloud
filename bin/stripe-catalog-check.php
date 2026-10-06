<?php
/**
 * Read-only Stripe catalog check for live (or test) Price configuration.
 *
 * Run from the repository root on the server:
 *   php bin/stripe-catalog-check.php
 *
 * Uses WEBCO_STRIPE_SECRET_KEY and the five WEBCO_STRIPE_PRICE_* secrets.
 * GET only: Prices and Products. Does not create Checkout Sessions, charges,
 * subscriptions, domains, or 20i resources. Does not print secret values or
 * Price IDs.
 *
 * This file is not a web page.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');

if ($argc > 1) {
    fwrite(STDERR, "catalog check only; no arguments are accepted\n");
    exit(1);
}

require dirname(__DIR__) . '/public/lib/stripe-catalog.php';

$secret = webco_stripe_secret();
if ($secret === null) {
    fwrite(STDERR, "Stripe secret key unavailable\n");
    exit(1);
}

$getJson = static function (string $path) use (&$secret): ?array {
    $response = webco_stripe_api($secret, 'GET', $path, null, null);
    if ($response === null || ($response['status'] ?? 0) !== 200 || !is_array($response['body'] ?? null)) {
        return null;
    }

    return $response['body'];
};

$report = webco_stripe_catalog_run($getJson, $secret);
$secret = '';

fwrite(STDOUT, webco_stripe_catalog_report_text($report));
exit(($report['ok'] ?? false) === true ? 0 : 1);
