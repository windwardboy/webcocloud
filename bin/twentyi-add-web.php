<?php
/**
 * Probe 20i hosting package creation.
 *
 * Preview is the default. It prints the POST body and does not call 20i:
 *   php bin/twentyi-add-web.php --domain=example.com --type=811
 *   php bin/twentyi-add-web.php --domain=example.com --type=811 --label=wc_0123456789abcdef0123
 *
 * A real package is created only when --apply is present:
 *   php bin/twentyi-add-web.php --domain=example.com --type=811 --label=wc_0123456789abcdef0123 --apply
 *
 * --label should identify the Webco order or project. --type is a package
 * type id from the package-type list.
 *
 * This file is not a web page. It does not open the database, and the
 * provisioning worker does not call it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');

require dirname(__DIR__) . '/public/lib/twentyi.php';

$options = webco_twentyi_add_web_cli_options($argv);
if (!$options['ok']) {
    fwrite(STDERR, $options['error'] . "\n");
    exit(1);
}

$payload = webco_twentyi_add_web_payload(
    (string) $options['domain'],
    (string) $options['type'],
    $options['label']
);
if ($payload === null) {
    fwrite(STDERR, "domain, package type, or label is not usable\n");
    exit(1);
}

if (!$options['apply']) {
    fwrite(STDOUT, webco_twentyi_add_web_preview_text($payload));
    exit(0);
}

$key = webco_twentyi_api_key();
if ($key === null) {
    fwrite(STDERR, "20i API key unavailable\n");
    exit(1);
}

$bearer = base64_encode($key);
$key = '';

$result = webco_twentyi_create_hosting_package(
    static function (string $path, string $body) use (&$bearer): array {
        return webco_twentyi_http_post($path, $bearer, $body);
    },
    (string) $options['domain'],
    (string) $options['type'],
    $options['label']
);
$bearer = '';

fwrite(STDOUT, webco_twentyi_add_web_result_text($result));
exit(($result['ok'] ?? false) === true ? 0 : 1);
