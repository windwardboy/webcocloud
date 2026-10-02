<?php
/**
 * Reconcile paid orders whose Stripe mode or entitlement fields are still null.
 *
 *   php bin/provision-reconcile.php
 *   php bin/provision-reconcile.php --limit=1
 *   php bin/provision-reconcile.php --order=wc_xxx
 *   php bin/provision-reconcile.php --apply
 *
 * With php -f, end PHP's own options before the flags:
 *   php -f bin/provision-reconcile.php -- --limit=1
 *   php -f bin/provision-reconcile.php -- --order=wc_xxx
 *   php -f bin/provision-reconcile.php -- --apply
 *
 * The default is a preview and writes nothing. --limit is preview only.
 * Stripe is read with GET. Projects and 20i are left alone.
 * Each candidate is printed and flushed before the next Stripe read.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/public/lib/reconcile.php';

$options = webco_reconcile_cli_options($argv);
if (!$options['ok']) {
    fwrite(STDERR, $options['error'] . "\n");
    exit(1);
}

$db = webco_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database unavailable\n");
    exit(1);
}

$apply = $options['apply'];
webco_reconcile_say('mode: ' . ($apply ? 'apply' : 'preview'));

$count = webco_reconcile_candidate_count($db);
if ($count === null) {
    webco_reconcile_say('candidates: unavailable');
    webco_reconcile_say('summary: failure');
    exit(1);
}
webco_reconcile_say('candidates: ' . $count);
if ($options['limit'] !== null) {
    webco_reconcile_say('limit: ' . $options['limit']);
}
if ($options['public_id'] !== null) {
    webco_reconcile_say('order: ' . $options['public_id']);
    $found = webco_reconcile_find_order($db, $options['public_id']);
    if ($found === false) {
        webco_reconcile_say('summary: failure');
        exit(1);
    }
    if ($found === null) {
        webco_reconcile_say_result(webco_reconcile_result(
            ['public_id' => $options['public_id']],
            'unchanged',
            'missing_order',
            [],
            null
        ));
        webco_reconcile_say('summary: success');
        exit(0);
    }
}

$failed = false;
$results = webco_reconcile_orders(
    $db,
    'webco_reconcile_stripe_view',
    $apply,
    $options['limit'],
    $options['public_id'],
    static function (array $result) use (&$failed): void {
        if (($result['reason'] ?? '') === 'database_error') {
            $failed = true;
        }
        webco_reconcile_say_result($result);
    }
);
if ($options['public_id'] !== null && $results === []) {
    webco_reconcile_say_result(webco_reconcile_result(
        is_array($found) ? $found : [],
        'unchanged',
        'not_a_candidate',
        is_array($found) ? webco_reconcile_values($found) : [],
        null
    ));
}

webco_reconcile_say('summary: ' . ($failed ? 'failure' : 'success'));
exit($failed ? 1 : 0);

function webco_reconcile_say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

/**
 * @param array<string, mixed> $result
 */
function webco_reconcile_say_result(array $result): void
{
    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
    webco_reconcile_say(is_string($encoded) ? $encoded : '{}');
}
