<?php
/**
 * Temporary read-only check of the reconciliation CLI parser.
 *
 *   php bin/apply-arg-diagnose.php -- --apply --order=wc_xxx
 *
 * With php -f:
 *   php -f bin/apply-arg-diagnose.php -- --apply --order=wc_xxx
 *
 * CLI only. No database connection, no Stripe call, and no writes.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/public/lib/reconcile.php';

$options = webco_reconcile_cli_options($argv);
webco_apply_arg_say('parse=' . (($options['ok'] ?? false) === true ? 'ok' : 'failed'));
webco_apply_arg_say('mode=' . (($options['apply'] ?? false) === true ? 'apply' : 'preview'));
webco_apply_arg_say('order=' . (is_string($options['public_id'] ?? null) ? $options['public_id'] : 'NULL'));
$limit = $options['limit'] ?? null;
webco_apply_arg_say('limit=' . (is_int($limit) ? (string) $limit : 'NULL'));
exit(0);

function webco_apply_arg_say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}
