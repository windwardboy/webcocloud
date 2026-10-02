<?php
/**
 * Temporary read-only view of one order's reconciliation columns.
 *
 *   php bin/reconcile-state.php --order=wc_xxx
 *
 * With php -f, end PHP's own options before the flag:
 *   php -f bin/reconcile-state.php -- --order=wc_xxx
 *
 * CLI only. One SELECT. No Stripe calls and no 20i calls.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$publicId = null;
if ($argc === 2 && preg_match('/^--order=(wc_[a-f0-9]{20})$/', (string) ($argv[1] ?? ''), $match) === 1) {
    $publicId = $match[1];
}
if ($publicId === null) {
    fwrite(STDERR, "usage: --order=wc_xxx\n");
    exit(1);
}

require dirname(__DIR__) . '/public/lib/db.php';

$db = webco_db();
if (!$db instanceof PDO) {
    webco_reconcile_state_say('database=unavailable');
    exit(0);
}

try {
    $statement = $db->prepare(
        'SELECT public_id, stripe_livemode, care_status, care_trial_ends_at,
                hosting_status, hosting_included_until
         FROM orders
         WHERE public_id = :public_id
         LIMIT 1'
    );
    $statement->execute(['public_id' => $publicId]);
    $row = $statement->fetch();
} catch (PDOException $exception) {
    $info = $exception->errorInfo;
    $sqlState = is_array($info) ? (string) ($info[0] ?? '') : '';
    webco_reconcile_state_say('query=failed');
    webco_reconcile_state_say('sqlstate=' . $sqlState);
    exit(0);
}

if (!is_array($row)) {
    webco_reconcile_state_say('public_id=' . $publicId);
    webco_reconcile_state_say('order=none');
    exit(0);
}

foreach (
    [
        'public_id',
        'stripe_livemode',
        'care_status',
        'care_trial_ends_at',
        'hosting_status',
        'hosting_included_until',
    ] as $column
) {
    $value = $row[$column] ?? null;
    webco_reconcile_state_say($column . '=' . ($value === null ? 'NULL' : (string) $value));
}
exit(0);

function webco_reconcile_state_say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}
