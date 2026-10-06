<?php
/**
 * Explicit 20i hosting cleanup for approved test packages only.
 *
 * Preview (default):
 *   php bin/cleanup-hosting-packages.php
 *
 * Apply (delete hosting accounts only; keep domain registrations):
 *   php bin/cleanup-hosting-packages.php --apply
 *
 * Allowed package IDs only:
 *   3940479 (sexyunderneath.com)
 *   3940545 (jetfunnels.com)
 *   3943689 (concretejunkie.co.uk)
 *
 * Never touches package 3943463 (pandahugs.uk).
 * Never calls domain cancel/delete/transfer endpoints.
 * Never calls Stripe. Never modifies the Webco database.
 *
 * This file is not a web page.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');

require dirname(__DIR__) . '/public/lib/cleanup-hosting.php';

$options = webco_cleanup_hosting_cli_options($argv);
if (!$options['ok']) {
    fwrite(STDERR, $options['error'] . "\n");
    exit(1);
}

$key = webco_twentyi_api_key();
if ($key === null) {
    fwrite(STDERR, "20i API key unavailable\n");
    exit(1);
}
$bearer = base64_encode($key);
$key = '';

$listPackages = static function () use (&$bearer): array {
    $response = webco_twentyi_http_get(WEBCO_TWENTYI_PACKAGE_LIST, $bearer);
    if (($response['ok'] ?? false) !== true) {
        return ['ok' => false, 'rows' => []];
    }
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'rows' => []];
    }
    $rows = webco_twentyi_package_rows($decoded);

    return ['ok' => true, 'rows' => $rows];
};

$deletePackage = static function (string $packageId) use (&$bearer): array {
    return webco_twentyi_delete_hosting_package(
        static function (string $path, string $body) use (&$bearer): array {
            return webco_twentyi_http_post($path, $bearer, $body);
        },
        $packageId
    );
};

$report = webco_cleanup_hosting_run($options['apply'], $listPackages, $deletePackage);
$bearer = '';
fwrite(STDOUT, webco_cleanup_hosting_report_text($report));
exit(($report['ok'] ?? false) === true ? 0 : 1);
