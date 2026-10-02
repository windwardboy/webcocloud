<?php
/**
 * Read-only 20i package discovery.
 *
 * Run from the repository root on the server:
 *   php bin/twentyi-discover.php
 *
 * Uses the private WEBCO_20I_API_KEY. GET only. No arguments.
 * This file is not a web page and does not write to the database.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');

if ($argc > 1) {
    fwrite(STDERR, "discovery only; no arguments are accepted\n");
    exit(1);
}

require dirname(__DIR__) . '/public/lib/twentyi.php';

$key = webco_twentyi_api_key();
if ($key === null) {
    fwrite(STDERR, "20i API key unavailable\n");
    exit(1);
}

$bearer = base64_encode($key);
$key = '';

$report = webco_twentyi_discovery(static function (string $path) use (&$bearer): array {
    $result = webco_twentyi_http_get($path, $bearer);

    return [
        'ok' => $result['ok'],
        'status' => $result['status'],
        'body' => $result['body'],
    ];
});
$bearer = '';

fwrite(STDOUT, webco_twentyi_discovery_text($report));
exit(($report['ok'] ?? false) === true ? 0 : 1);
