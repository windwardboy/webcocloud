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

// TEMPORARY DIAGNOSTIC. Delete the checkpoint lines and
// webco_reconcile_cli_process_order(), then call webco_reconcile_orders() again.
webco_reconcile_say('checkpoint=cli_parsed');

$db = webco_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database unavailable\n");
    exit(1);
}
webco_reconcile_say('checkpoint=db_connected');

$apply = $options['apply'];
webco_reconcile_say('mode: ' . ($apply ? 'apply' : 'preview'));

try {
    $count = webco_reconcile_candidate_count($db);
} catch (Throwable $exception) {
    webco_reconcile_cli_throwable($exception);
    throw $exception;
}
if ($count === null) {
    webco_reconcile_cli_pdo_error($db);
    webco_reconcile_say('candidates: unavailable');
    webco_reconcile_say('summary: failure');
    webco_reconcile_say('checkpoint=summary_written');
    exit(1);
}
webco_reconcile_say('checkpoint=count_ok');
webco_reconcile_say('candidates: ' . $count);
if ($options['limit'] !== null) {
    webco_reconcile_say('limit: ' . $options['limit']);
}
if ($options['public_id'] !== null) {
    webco_reconcile_say('order: ' . $options['public_id']);
    try {
        $found = webco_reconcile_find_order($db, $options['public_id']);
    } catch (Throwable $exception) {
        webco_reconcile_cli_throwable($exception);
        throw $exception;
    }
    if ($found === false) {
        webco_reconcile_cli_pdo_error($db);
        webco_reconcile_say('summary: failure');
        webco_reconcile_say('checkpoint=summary_written');
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
        webco_reconcile_say('checkpoint=result_json_written');
        webco_reconcile_say('summary: success');
        webco_reconcile_say('checkpoint=summary_written');
        exit(0);
    }
    webco_reconcile_say('checkpoint=order_lookup_ok');
}

try {
    $rows = webco_reconcile_candidates($db, $options['limit'], $options['public_id']);
} catch (Throwable $exception) {
    webco_reconcile_cli_throwable($exception);
    throw $exception;
}
if ($rows === null) {
    webco_reconcile_cli_pdo_error($db);
    webco_reconcile_say_result([
        'public_id' => '',
        'action' => 'unchanged',
        'reason' => 'database_error',
        'current' => null,
        'proposed' => null,
    ]);
    webco_reconcile_say('checkpoint=result_json_written');
    webco_reconcile_say('summary: failure');
    webco_reconcile_say('checkpoint=summary_written');
    exit(1);
}
webco_reconcile_say('checkpoint=candidate_query_ok');

$results = [];
foreach ($rows as $row) {
    try {
        $result = webco_reconcile_cli_process_order($db, $row, $apply);
    } catch (Throwable $exception) {
        webco_reconcile_cli_throwable($exception);
        throw $exception;
    }
    $results[] = $result;
    webco_reconcile_say_result($result);
    webco_reconcile_say('checkpoint=result_json_written');
}
if ($options['public_id'] !== null && $results === []) {
    webco_reconcile_say_result(webco_reconcile_result(
        is_array($found) ? $found : [],
        'unchanged',
        'not_a_candidate',
        is_array($found) ? webco_reconcile_values($found) : [],
        null
    ));
    webco_reconcile_say('checkpoint=result_json_written');
}

webco_reconcile_say('summary: success');
webco_reconcile_say('checkpoint=summary_written');
exit(0);

/**
 * TEMPORARY DIAGNOSTIC. Same branches as webco_reconcile_one().
 * Checkpoints are the only addition. Delete this with the checkpoint lines above.
 *
 * @param array<string, mixed> $order
 * @return array<string, mixed>
 */
function webco_reconcile_cli_process_order(PDO $db, array $order, bool $apply): array
{
    $current = webco_reconcile_values($order);
    webco_reconcile_say('checkpoint=stripe_read_start');
    $view = webco_reconcile_stripe_view($order);
    if (($view['ok'] ?? false) !== true) {
        return webco_reconcile_result($order, 'unchanged', (string) ($view['reason'] ?? 'stripe_unavailable'), $current, null);
    }
    webco_reconcile_say('checkpoint=stripe_read_ok');

    $livemode = $view['livemode'] ?? null;
    $status = $view['status'] ?? '';
    $trialEnd = $view['trial_end'] ?? null;
    if (!is_int($livemode) || !is_string($status) || !is_int($trialEnd)) {
        return webco_reconcile_result($order, 'unchanged', 'missing_trial_end', $current, null);
    }
    if (webco_map_stripe_subscription_status($status) === null) {
        return webco_reconcile_result($order, 'unchanged', 'unusable_status', $current, null);
    }
    $proposed = webco_reconcile_proposal((string) ($order['care_choice'] ?? ''), $livemode, $status, $trialEnd);
    if ($proposed === null) {
        return webco_reconcile_result($order, 'unchanged', 'missing_trial_end', $current, null);
    }
    webco_reconcile_say('checkpoint=proposal_ready');
    if (!$apply) {
        return webco_reconcile_result($order, 'preview', null, $current, $proposed);
    }

    webco_reconcile_say('checkpoint=apply_call_start');
    $applied = webco_reconcile_apply(
        $db,
        (int) ($order['id'] ?? 0),
        (string) ($order['care_choice'] ?? ''),
        $proposed
    );
    webco_reconcile_say('checkpoint=apply_call_returned result=' . ($applied ? 'true' : 'false'));
    if (!$applied) {
        return webco_reconcile_result($order, 'unchanged', 'not_open', $current, $proposed);
    }

    return webco_reconcile_result($order, 'applied', null, $current, $proposed);
}

function webco_reconcile_say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

function webco_reconcile_cli_pdo_error(PDO $db): void
{
    $info = $db->errorInfo();
    $sqlState = isset($info[0]) ? (string) $info[0] : '';
    $driverCode = isset($info[1]) && $info[1] !== null ? (string) $info[1] : '';
    $driverMessage = isset($info[2]) ? (string) $info[2] : '';
    webco_reconcile_say('sqlstate=' . ($sqlState === '' ? 'NULL' : $sqlState));
    webco_reconcile_say('driver_code=' . ($driverCode === '' ? 'NULL' : $driverCode));
    webco_reconcile_say('driver_message=' . ($driverMessage === '' ? 'NULL' : webco_reconcile_cli_redact($driverMessage)));
}

function webco_reconcile_cli_throwable(Throwable $exception): void
{
    webco_reconcile_say('exception_class=' . $exception::class);
    webco_reconcile_say('exception_message=' . webco_reconcile_cli_redact($exception->getMessage()));
    if ($exception instanceof PDOException) {
        $info = $exception->errorInfo ?? null;
        $sqlState = is_array($info) ? (string) ($info[0] ?? '') : '';
        $driverCode = is_array($info) && isset($info[1]) && $info[1] !== null ? (string) $info[1] : '';
        $driverMessage = is_array($info) && isset($info[2]) ? (string) $info[2] : '';
        webco_reconcile_say('sqlstate=' . ($sqlState === '' ? 'NULL' : $sqlState));
        webco_reconcile_say('driver_code=' . ($driverCode === '' ? 'NULL' : $driverCode));
        webco_reconcile_say('driver_message=' . ($driverMessage === '' ? 'NULL' : webco_reconcile_cli_redact($driverMessage)));
    }
}

function webco_reconcile_cli_redact(string $value): string
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

/**
 * @param array<string, mixed> $result
 */
function webco_reconcile_say_result(array $result): void
{
    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
    webco_reconcile_say(is_string($encoded) ? $encoded : '{}');
}
