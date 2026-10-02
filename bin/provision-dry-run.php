<?php
/**
 * Dry-run provisioning worker.
 *
 * Run from the server shell or a cron job:
 *   php bin/provision-dry-run.php
 *
 * This file is not a web page. It never calls 20i or Stripe.
 * It claims only orders with stripe_livemode = 1, the same rule a real
 * worker must use. Test and unreconciled orders stay ready.
 * Logs go to stderr. The result JSON goes to stdout.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc > 1) {
    fwrite(STDERR, "dry-run only; no arguments are accepted\n");
    exit(1);
}

require dirname(__DIR__) . '/public/lib/provision.php';

$db = webco_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database unavailable\n");
    exit(1);
}

$result = webco_provision_dry_run($db, static function (array $entry): void {
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        return;
    }
    fwrite(STDERR, $line . "\n");
});

$encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
fwrite(STDOUT, (is_string($encoded) ? $encoded : '{}') . "\n");

if (!($result['ok'] ?? false)) {
    exit(1);
}
foreach ($result['results'] as $item) {
    if (($item['outcome'] ?? '') === 'failed') {
        exit(1);
    }
}

exit(0);
