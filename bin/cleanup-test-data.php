<?php
/**
 * Audit and guarded cleanup of Webco Cloud test/demo customer data.
 *
 * Preview (default, safe):
 *   php bin/cleanup-test-data.php
 *
 * Apply (destructive DB + private uploads for positively identified candidates):
 *   php bin/cleanup-test-data.php --apply --confirm-candidates=28
 *
 * Re-runs the same positive identification rules before deleting.
 * Deletes only candidate rows, in dependency order, then removes
 * webco-projects/{order_public_id}/ for those orders.
 *
 * Never calls Stripe or 20i. Never deletes stripe_events.
 * 20i hosting cleanup is a separate command.
 *
 * This file is not a web page.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');

require dirname(__DIR__) . '/public/lib/cleanup-audit.php';

$options = webco_cleanup_cli_options($argv);
if (!$options['ok']) {
    fwrite(STDERR, $options['error'] . "\n");
    exit(1);
}

$db = webco_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database unavailable\n");
    exit(1);
}

if (!$options['apply']) {
    $audit = webco_cleanup_audit($db);
    fwrite(STDOUT, webco_cleanup_audit_text($audit));
    exit(($audit['ok'] ?? false) === true ? 0 : 1);
}

$result = webco_cleanup_apply($db, (int) $options['confirm_candidates']);
fwrite(STDOUT, webco_cleanup_apply_text($result));
exit(($result['ok'] ?? false) === true ? 0 : 1);
