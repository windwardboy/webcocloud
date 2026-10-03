<?php
/**
 * Hosting provisioning for an existing domain.
 *
 * Preview is the default. It does not claim a project and it does not call 20i:
 *   php bin/provision-hosting.php
 *   php bin/provision-hosting.php --project=1
 *
 * A hosting package is created only when --apply is present:
 *   php bin/provision-hosting.php --apply
 *   php bin/provision-hosting.php --apply --project=1
 *
 * One Stripe test-mode order can be included only by naming its project.
 * Preview still does not call 20i:
 *   php bin/provision-hosting.php --project=1 --allow-test-order
 *   php bin/provision-hosting.php --project=1 --allow-test-order --apply
 *
 * Eligible projects are ready, or already in progress with a stored package
 * id. The order must be paid, Stripe live mode, and domain_path existing.
 * --allow-test-order also accepts stripe_livemode 0 for that one project.
 * Package type 117014 is used for Essential and Professional.
 *
 * Run php bin/provision-schema.php first so projects.twentyi_package_id and
 * projects.provisioning_attempted_at exist.
 *
 * This file is not a web page. The dry-run worker does not call it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');

require dirname(__DIR__) . '/public/lib/provision-hosting.php';

$options = webco_provision_hosting_cli_options($argv);
if (!$options['ok']) {
    fwrite(STDERR, $options['error'] . "\n");
    exit(1);
}

$db = webco_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database unavailable\n");
    exit(1);
}

$allowTestOrder = $options['allow_test_order'];

if (!$options['apply']) {
    $preview = webco_provision_hosting_preview($db, $options['project_id'], $allowTestOrder);
    if ($preview === null) {
        fwrite(STDERR, "database unavailable\n");
        exit(1);
    }
    fwrite(STDOUT, webco_provision_hosting_preview_text($preview, $allowTestOrder));
    exit(0);
}

$needsCreate = webco_provision_hosting_needs_create($db, $options['project_id'], $allowTestOrder);
if ($needsCreate === null) {
    fwrite(STDERR, "database unavailable\n");
    exit(1);
}

$bearer = '';
if ($needsCreate) {
    $key = webco_twentyi_api_key();
    if ($key === null) {
        fwrite(STDERR, "20i API key unavailable\n");
        exit(1);
    }
    $bearer = base64_encode($key);
    $key = '';
}

$result = webco_provision_hosting_apply(
    $db,
    static function () use (&$bearer): array {
        return webco_provision_hosting_list_live($bearer);
    },
    static function (string $domain, string $type, string $label) use (&$bearer): array {
        return webco_twentyi_create_hosting_package(
            static function (string $path, string $body) use (&$bearer): array {
                return webco_twentyi_http_post($path, $bearer, $body);
            },
            $domain,
            $type,
            $label
        );
    },
    static function (array $entry): void {
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if (is_string($line)) {
            fwrite(STDERR, $line . "\n");
        }
    },
    $options['project_id'],
    $allowTestOrder
);
$bearer = '';

$encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
fwrite(STDOUT, (is_string($encoded) ? $encoded : '{}') . "\n");
exit(($result['ok'] ?? false) === true ? 0 : 1);
