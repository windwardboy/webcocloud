<?php
/**
 * Preview-only audit of Webco Cloud test/demo customer data.
 *
 * Default (safe):
 *   php bin/cleanup-test-data.php
 *
 * Lists candidate orders/projects and dependent rows that would be removed
 * together in a future cleanup. Does not DELETE or UPDATE. Does not call
 * Stripe or 20i. Does not remove upload files.
 *
 * --apply is recognised so a future destructive mode can require it explicitly,
 * but deletion is not implemented yet and --apply is refused.
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

if ($options['apply']) {
    fwrite(STDERR, "deletion is not implemented yet; re-run without --apply for a preview audit\n");
    exit(1);
}

$db = webco_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database unavailable\n");
    exit(1);
}

$audit = webco_cleanup_audit($db);
fwrite(STDOUT, webco_cleanup_audit_text($audit));
exit(($audit['ok'] ?? false) === true ? 0 : 1);
