<?php
/**
 * Reconcile paid orders whose Stripe mode or entitlement fields are still null.
 *
 *   php bin/provision-reconcile.php
 *   php bin/provision-reconcile.php --apply
 *
 * With php -f, end PHP's own options before the flag:
 *   php -f bin/provision-reconcile.php -- --apply
 *
 * The default is a preview and writes nothing. --apply is the only accepted
 * argument. Stripe is read with GET. Projects and 20i are left alone.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$apply = false;
if ($argc > 2) {
    fwrite(STDERR, "preview is the default; the only argument is --apply\n");
    exit(1);
}
if ($argc === 2) {
    if (($argv[1] ?? '') !== '--apply') {
        fwrite(STDERR, "preview is the default; the only argument is --apply\n");
        exit(1);
    }
    $apply = true;
}

require dirname(__DIR__) . '/public/lib/reconcile.php';

$db = webco_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database unavailable\n");
    exit(1);
}

$results = webco_reconcile_orders($db, 'webco_reconcile_stripe_view', $apply);
$failed = false;
foreach ($results as $result) {
    if (($result['reason'] ?? '') === 'database_error') {
        $failed = true;
    }
    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, (is_string($encoded) ? $encoded : '{}') . "\n");
}
fwrite(STDOUT, 'mode: ' . ($apply ? 'apply' : 'preview') . "\n");
fwrite(STDOUT, 'summary: ' . ($failed ? 'failure' : 'success') . "\n");
exit($failed ? 1 : 0);
