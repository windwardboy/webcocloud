<?php
/**
 * Run the existing reconciliation apply inside a transaction and roll it back.
 *
 *   php bin/reconcile-apply-diagnose.php --order=wc_xxx
 *
 * With php -f:
 *   php -f bin/reconcile-apply-diagnose.php -- --order=wc_xxx
 *
 * CLI only. Stripe is read with GET. The UPDATE is rolled back.
 * No 20i calls and no committed database changes.
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

require dirname(__DIR__) . '/public/lib/reconcile.php';

$db = webco_db();
if (!$db instanceof PDO) {
    webco_apply_diagnose_say('database=unavailable');
    exit(0);
}

$order = webco_reconcile_find_order($db, $publicId);
if ($order === false) {
    webco_apply_diagnose_say('order=unavailable');
    exit(0);
}
if ($order === null) {
    webco_apply_diagnose_say('public_id=' . $publicId);
    webco_apply_diagnose_say('order=none');
    exit(0);
}
if ((string) ($order['status'] ?? '') !== 'paid') {
    webco_apply_diagnose_say('public_id=' . $publicId);
    webco_apply_diagnose_say('order_status=not_paid');
    exit(0);
}

$view = webco_reconcile_stripe_view($order);
if (($view['ok'] ?? false) !== true) {
    webco_apply_diagnose_say('stripe_read=failed');
    webco_apply_diagnose_say('reason=' . (string) ($view['reason'] ?? 'stripe_unavailable'));
    exit(0);
}

$livemode = $view['livemode'] ?? null;
$status = $view['status'] ?? '';
$trialEnd = $view['trial_end'] ?? null;
$proposed = null;
if (is_int($livemode) && is_string($status) && is_int($trialEnd)) {
    $proposed = webco_reconcile_proposal((string) ($order['care_choice'] ?? ''), $livemode, $status, $trialEnd);
}
if ($proposed === null) {
    webco_apply_diagnose_say('proposal=unavailable');
    exit(0);
}

$original = webco_apply_diagnose_columns($db, $publicId);
webco_apply_diagnose_say('proposal_ready');
webco_apply_diagnose_columns_out($proposed);
webco_apply_diagnose_say('original_state');
webco_apply_diagnose_columns_out($original ?? []);

$caught = false;
try {
    $db->beginTransaction();
    webco_apply_diagnose_say('transaction_started');
    webco_apply_diagnose_say('apply_call_start');
    $returned = webco_reconcile_apply(
        $db,
        (int) ($order['id'] ?? 0),
        (string) ($order['care_choice'] ?? ''),
        $proposed
    );
    webco_apply_diagnose_say('apply_call_returned');
    webco_apply_diagnose_say('apply_returned=' . ($returned ? 'true' : 'false'));
    if ($returned !== true) {
        webco_apply_diagnose_connection_error($db);
    }
    webco_apply_diagnose_say('statement_diagnostics=unavailable');
    $inside = webco_apply_diagnose_columns($db, $publicId);
    webco_apply_diagnose_say('row_inside_transaction');
    webco_apply_diagnose_columns_out($inside ?? []);
} catch (Throwable $exception) {
    $caught = true;
    webco_apply_diagnose_throwable($exception);
} finally {
    if ($db->inTransaction()) {
        try {
            $db->rollBack();
            webco_apply_diagnose_say('rollback_complete');
        } catch (Throwable $rollbackError) {
            webco_apply_diagnose_say('rollback_failed');
            webco_apply_diagnose_throwable($rollbackError);
        }
    }
}

if (!$caught) {
    webco_apply_diagnose_say('exception=none');
}

$persistent = webco_apply_diagnose_columns($db, $publicId);
webco_apply_diagnose_say(
    'persistent_state_verified=' . ($persistent !== null && $persistent === $original ? 'yes' : 'no')
);
if ($persistent !== null) {
    webco_apply_diagnose_columns_out($persistent);
}
exit(0);

function webco_apply_diagnose_say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

/**
 * @param array<string, mixed> $values
 */
function webco_apply_diagnose_columns_out(array $values): void
{
    foreach (
        [
            'stripe_livemode',
            'care_status',
            'care_trial_ends_at',
            'hosting_status',
            'hosting_included_until',
        ] as $column
    ) {
        if (!array_key_exists($column, $values)) {
            continue;
        }
        $value = $values[$column];
        webco_apply_diagnose_say($column . '=' . ($value === null ? 'NULL' : (string) $value));
    }
}

/**
 * @return array<string, ?string>|null
 */
function webco_apply_diagnose_columns(PDO $db, string $publicId): ?array
{
    $statement = $db->prepare(
        'SELECT stripe_livemode, care_status, care_trial_ends_at,
                hosting_status, hosting_included_until
         FROM orders
         WHERE public_id = :public_id
         LIMIT 1'
    );
    $statement->execute(['public_id' => $publicId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    $values = [];
    foreach (
        [
            'stripe_livemode',
            'care_status',
            'care_trial_ends_at',
            'hosting_status',
            'hosting_included_until',
        ] as $column
    ) {
        $value = $row[$column] ?? null;
        $values[$column] = $value === null ? null : (string) $value;
    }

    return $values;
}

function webco_apply_diagnose_connection_error(PDO $db): void
{
    $info = $db->errorInfo();
    $sqlState = isset($info[0]) ? (string) $info[0] : '';
    $driverCode = isset($info[1]) && $info[1] !== null ? (string) $info[1] : '';
    $driverMessage = isset($info[2]) ? (string) $info[2] : '';
    webco_apply_diagnose_say('apply_sqlstate=' . ($sqlState === '' ? 'NULL' : $sqlState));
    webco_apply_diagnose_say('apply_driver_code=' . ($driverCode === '' ? 'NULL' : $driverCode));
    webco_apply_diagnose_say('apply_driver_message=' . ($driverMessage === '' ? 'NULL' : webco_apply_diagnose_redact($driverMessage)));
}

function webco_apply_diagnose_throwable(Throwable $exception): void
{
    webco_apply_diagnose_say('exception_class=' . $exception::class);
    webco_apply_diagnose_say('exception_message=' . webco_apply_diagnose_redact($exception->getMessage()));
    if ($exception instanceof PDOException) {
        $info = $exception->errorInfo ?? null;
        $sqlState = is_array($info) ? (string) ($info[0] ?? '') : '';
        $driverCode = is_array($info) && isset($info[1]) && $info[1] !== null ? (string) $info[1] : '';
        webco_apply_diagnose_say('exception_sqlstate=' . ($sqlState === '' ? 'NULL' : $sqlState));
        webco_apply_diagnose_say('exception_driver_code=' . ($driverCode === '' ? 'NULL' : $driverCode));
    }
}

function webco_apply_diagnose_redact(string $value): string
{
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = preg_replace('/\bsk_(?:test|live)_[A-Za-z0-9]+/', 'sk_redacted', $value) ?? $value;
    $value = preg_replace('/\b(?:cs_(?:test|live)_|sub_|cus_|pi_)[A-Za-z0-9]+/', 'stripe_id_redacted', $value) ?? $value;
    $value = preg_replace('/password\s*[=:]\s*\S+/i', 'password=redacted', $value) ?? $value;
    $value = trim($value);
    if (strlen($value) > 300) {
        return substr($value, 0, 300);
    }

    return $value;
}
