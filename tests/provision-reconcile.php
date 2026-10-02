<?php
/**
 * Reconciliation checks. No Stripe network calls and no 20i calls.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/lib/reconcile.php';

$failures = 0;
$paths = [];

function check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "ok  {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL {$message}\n";
}

function test_db(): PDO
{
    global $paths;
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-reconcile-' . getmypid() . '-' . count($paths) . '.sqlite';
    if (is_file($path)) {
        unlink($path);
    }
    $paths[] = $path;
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(
        'CREATE TABLE orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL,
            care_choice TEXT NOT NULL,
            stripe_checkout_session_id TEXT,
            stripe_subscription_id TEXT,
            stripe_livemode INTEGER,
            care_status TEXT,
            care_trial_ends_at TEXT,
            hosting_status TEXT,
            hosting_included_until TEXT
        )'
    );
    $db->exec(
        'CREATE TABLE projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            provisioning_status TEXT NOT NULL
        )'
    );

    return $db;
}

function add_order(PDO $db, array $order): int
{
    static $n = 0;
    $n++;
    $row = array_merge([
        'public_id' => 'wc_' . str_pad(dechex($n), 20, 'b', STR_PAD_LEFT),
        'status' => 'paid',
        'care_choice' => 'standard',
        'stripe_checkout_session_id' => 'cs_test_' . str_pad(dechex($n), 16, 'c', STR_PAD_LEFT),
        'stripe_subscription_id' => 'sub_' . str_pad(dechex($n), 14, 'd', STR_PAD_LEFT),
        'stripe_livemode' => null,
        'care_status' => null,
        'care_trial_ends_at' => null,
        'hosting_status' => null,
        'hosting_included_until' => null,
    ], $order);
    $statement = $db->prepare(
        'INSERT INTO orders (
            public_id, status, care_choice, stripe_checkout_session_id, stripe_subscription_id,
            stripe_livemode, care_status, care_trial_ends_at, hosting_status, hosting_included_until
         ) VALUES (
            :public_id, :status, :care_choice, :stripe_checkout_session_id, :stripe_subscription_id,
            :stripe_livemode, :care_status, :care_trial_ends_at, :hosting_status, :hosting_included_until
         )'
    );
    $statement->execute($row);
    $id = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO projects (order_id, provisioning_status) VALUES (:order_id, \'ready\')')
        ->execute(['order_id' => $id]);

    return $id;
}

function order_row(PDO $db, int $id): array
{
    $statement = $db->prepare('SELECT * FROM orders WHERE id = :id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    return $row === false ? [] : $row;
}

function project_status(PDO $db, int $orderId): string
{
    $statement = $db->prepare('SELECT provisioning_status FROM projects WHERE order_id = :id');
    $statement->execute(['id' => $orderId]);
    $row = $statement->fetch();

    return (string) ($row['provisioning_status'] ?? '');
}

$trialEnd = 1798761600;
$trialAt = gmdate('Y-m-d H:i:s', $trialEnd);
$standardView = static function (array $order) use ($trialEnd): array {
    return ['ok' => true, 'livemode' => 0, 'status' => 'trialing', 'trial_end' => $trialEnd];
};

$previewDb = test_db();
$previewId = add_order($previewDb, ['care_choice' => 'standard']);
$before = order_row($previewDb, $previewId);
$preview = webco_reconcile_orders($previewDb, $standardView, false);
check(($preview[0]['action'] ?? '') === 'preview', 'preview reconciliation reports the proposed values');
check(($preview[0]['current']['hosting_status'] ?? null) === null, 'preview prints the current hosting status');
check(($preview[0]['proposed']['hosting_status'] ?? '') === 'trialing', 'preview proposes the Stripe subscription status');
check(($preview[0]['proposed']['hosting_included_until'] ?? '') === $trialAt, 'preview proposes the Stripe trial end');
check(($preview[0]['proposed']['stripe_livemode'] ?? -1) === 0, 'preview proposes test mode from the Stripe object');
check(order_row($previewDb, $previewId) == $before, 'preview reconciliation performs no writes');

$standardDb = test_db();
$standardId = add_order($standardDb, ['care_choice' => 'standard']);
$applied = webco_reconcile_orders($standardDb, $standardView, true);
$standard = order_row($standardDb, $standardId);
check(($applied[0]['action'] ?? '') === 'applied', 'apply reconciliation updates null entitlement fields');
check($standard['care_status'] === null && $standard['care_trial_ends_at'] === null, 'standard hosting leaves care fields null');
check($standard['hosting_status'] === 'trialing', 'standard hosting stores the Stripe subscription status');
check($standard['hosting_included_until'] === $trialAt, 'standard hosting stores the Stripe trial end');
check((int) $standard['stripe_livemode'] === 0, 'standard hosting stores stripe_livemode from Stripe');
check(project_status($standardDb, $standardId) === 'ready', 'apply does not change provisioning_status');

$keptDb = test_db();
$keptId = add_order($keptDb, [
    'care_choice' => 'standard',
    'hosting_status' => 'active',
    'stripe_livemode' => null,
    'hosting_included_until' => null,
]);
webco_reconcile_orders($keptDb, $standardView, true);
$kept = order_row($keptDb, $keptId);
check($kept['hosting_status'] === 'active', 'apply does not overwrite a newer hosting status');
check($kept['hosting_included_until'] === $trialAt, 'apply still fills a null trial end');
check((int) $kept['stripe_livemode'] === 0, 'apply still fills a null stripe_livemode');

$managedDb = test_db();
$managedId = add_order($managedDb, ['care_choice' => 'managed']);
webco_reconcile_orders($managedDb, static function (array $order) use ($trialEnd): array {
    return ['ok' => true, 'livemode' => 0, 'status' => 'trialing', 'trial_end' => $trialEnd];
}, true);
$managed = order_row($managedDb, $managedId);
check($managed['care_status'] === 'trialing', 'Managed Care stores the Stripe subscription status');
check($managed['care_trial_ends_at'] === $trialAt, 'Managed Care stores the Stripe trial end');
check($managed['hosting_status'] === 'included', 'Managed Care hosting is included');
check($managed['hosting_included_until'] === null, 'Managed Care has no separate hosting end');

$missingDb = test_db();
$missingId = add_order($missingDb, ['stripe_subscription_id' => null]);
$beforeMissing = order_row($missingDb, $missingId);
$missing = webco_reconcile_orders($missingDb, static function (array $order): array {
    return ['ok' => false, 'reason' => 'missing_subscription'];
}, true);
check(($missing[0]['action'] ?? '') === 'unchanged' && ($missing[0]['reason'] ?? '') === 'missing_subscription', 'missing subscription leaves the order unchanged');
check(order_row($missingDb, $missingId) == $beforeMissing, 'missing subscription performs no write');

$emptyView = webco_reconcile_stripe_view([
    'stripe_subscription_id' => null,
    'stripe_checkout_session_id' => null,
]);
check(($emptyView['ok'] ?? true) === false && ($emptyView['reason'] ?? '') === 'missing_subscription', 'no session and no subscription is missing_subscription');

$trialDb = test_db();
$trialId = add_order($trialDb, []);
$beforeTrial = order_row($trialDb, $trialId);
$trial = webco_reconcile_orders($trialDb, static function (array $order): array {
    return ['ok' => false, 'reason' => 'missing_trial_end'];
}, true);
check(($trial[0]['action'] ?? '') === 'unchanged' && ($trial[0]['reason'] ?? '') === 'missing_trial_end', 'missing trial end leaves the order unchanged');
check(order_row($trialDb, $trialId) == $beforeTrial, 'missing trial end performs no write');

$repeatDb = test_db();
$repeatId = add_order($repeatDb, ['care_choice' => 'standard']);
webco_reconcile_orders($repeatDb, $standardView, true);
$once = order_row($repeatDb, $repeatId);
$again = webco_reconcile_orders($repeatDb, $standardView, true);
$twice = order_row($repeatDb, $repeatId);
check($again === [], 'repeated reconciliation finds nothing left to change');
check($twice == $once, 'repeated reconciliation is idempotent');

$unpaidDb = test_db();
$unpaidId = add_order($unpaidDb, ['status' => 'checkout_created']);
$beforeUnpaid = order_row($unpaidDb, $unpaidId);
$unpaid = webco_reconcile_orders($unpaidDb, $standardView, true);
check($unpaid === [], 'an unpaid order is not a reconciliation candidate');
check(order_row($unpaidDb, $unpaidId) == $beforeUnpaid, 'apply does not update an unpaid order');

$session = webco_stripe_session_subscription([
    'livemode' => false,
    'subscription' => null,
]);
check(is_array($session) && $session['livemode'] === 0 && $session['subscription_id'] === null, 'a session without a subscription does not invent one');
$fields = webco_stripe_subscription_fields([
    'livemode' => false,
    'status' => 'trialing',
    'trial_end' => null,
]);
check(is_array($fields) && $fields['trial_end'] === null && $fields['livemode'] === 0, 'a subscription without trial_end stays unusable');
check(webco_stripe_livemode_column(true) === 1, 'a live Stripe object maps to stripe_livemode 1');
check(webco_stripe_livemode_column('false') === null, 'livemode is not inferred from a string');

$limitDb = test_db();
$limitFirst = add_order($limitDb, ['care_choice' => 'standard']);
$limitSecond = add_order($limitDb, ['care_choice' => 'standard']);
$limitCalls = 0;
$limited = webco_reconcile_orders($limitDb, static function (array $order) use (&$limitCalls, $trialEnd): array {
    $limitCalls++;

    return ['ok' => true, 'livemode' => 0, 'status' => 'trialing', 'trial_end' => $trialEnd];
}, false, 1);
$limitFirstRow = order_row($limitDb, $limitFirst);
$limitSecondRow = order_row($limitDb, $limitSecond);
check($limitCalls === 1 && count($limited) === 1, 'a limit of 1 processes one candidate');
check(($limited[0]['public_id'] ?? '') === $limitFirstRow['public_id'], 'limit keeps the oldest candidate');
check($limitSecondRow['hosting_status'] === null && $limitSecondRow['stripe_livemode'] === null, 'limit leaves the remaining candidate unread');
add_order($limitDb, ['status' => 'checkout_created']);
check(webco_reconcile_candidate_count($limitDb) === 2, 'candidate count includes only unreconciled paid orders');

$targetDb = test_db();
$targetSkip = add_order($targetDb, ['care_choice' => 'standard']);
$targetKeep = add_order($targetDb, ['care_choice' => 'managed']);
$targetPublic = (string) order_row($targetDb, $targetKeep)['public_id'];
$targetCalls = 0;
$targeted = webco_reconcile_orders($targetDb, static function (array $order) use (&$targetCalls, $trialEnd): array {
    $targetCalls++;

    return ['ok' => true, 'livemode' => 0, 'status' => 'trialing', 'trial_end' => $trialEnd];
}, false, null, $targetPublic);
check($targetCalls === 1 && count($targeted) === 1, 'a public order id processes only that order');
check(($targeted[0]['public_id'] ?? '') === $targetPublic, 'the targeted order is the one reported');
check(order_row($targetDb, $targetSkip)['hosting_status'] === null, 'a targeted preview does not read the other order');
check(webco_reconcile_find_order($targetDb, 'wc_' . str_repeat('e', 20)) === null, 'an unknown public id is not a candidate read');

$progressDb = test_db();
$progressFirst = add_order($progressDb, ['care_choice' => 'standard']);
$progressSecond = add_order($progressDb, ['care_choice' => 'standard']);
$progressFirstPublic = (string) order_row($progressDb, $progressFirst)['public_id'];
$progressSecondPublic = (string) order_row($progressDb, $progressSecond)['public_id'];
$reported = [];
$reportedBeforeNext = false;
webco_reconcile_orders($progressDb, static function (array $order) use (&$reported, &$reportedBeforeNext, $progressFirstPublic, $progressSecondPublic, $trialEnd): array {
    if (($order['public_id'] ?? '') === $progressSecondPublic) {
        $reportedBeforeNext = $reported === [$progressFirstPublic];
    }

    return ['ok' => true, 'livemode' => 0, 'status' => 'trialing', 'trial_end' => $trialEnd];
}, false, null, null, static function (array $result) use (&$reported): void {
    $reported[] = (string) ($result['public_id'] ?? '');
});
check($reported === [$progressFirstPublic, $progressSecondPublic], 'progress reports candidates in order');
check($reportedBeforeNext, 'the first candidate is reported before the next Stripe read');

$defaultOptions = webco_reconcile_cli_options(['provision-reconcile.php']);
$limitOptions = webco_reconcile_cli_options(['provision-reconcile.php', '--limit=1']);
$orderOptions = webco_reconcile_cli_options(['provision-reconcile.php', '--order=' . $targetPublic]);
$applyOptions = webco_reconcile_cli_options(['provision-reconcile.php', '--apply']);
$limitedApply = webco_reconcile_cli_options(['provision-reconcile.php', '--apply', '--limit=1']);
check(($defaultOptions['ok'] ?? false) === true && ($defaultOptions['apply'] ?? true) === false, 'no arguments stay in preview');
check(($limitOptions['limit'] ?? 0) === 1 && ($limitOptions['apply'] ?? true) === false, '--limit=1 is accepted for preview');
check(($orderOptions['public_id'] ?? '') === $targetPublic && ($orderOptions['apply'] ?? true) === false, '--order accepts one public id');
check(($applyOptions['apply'] ?? false) === true && $applyOptions['limit'] === null, '--apply is explicit');
check(($limitedApply['ok'] ?? true) === false, '--limit cannot be combined with --apply');

$cli = (string) file_get_contents(dirname(__DIR__) . '/bin/provision-reconcile.php');
check(str_contains($cli, '--apply'), 'apply mode is an explicit flag');
check(str_contains($cli, '--limit=1'), 'preview can limit the number of candidates');
check(str_contains($cli, '--order='), 'preview can target one order');
check(str_contains($cli, 'fflush(STDOUT)'), 'progress is flushed as each candidate is processed');
check(str_contains($cli, "PHP_SAPI !== 'cli'"), 'reconciliation refuses a web request');
check(!str_contains($cli, 'api.20i.com'), 'reconciliation CLI does not call 20i');

$previewDb = null;
$standardDb = null;
$keptDb = null;
$managedDb = null;
$missingDb = null;
$trialDb = null;
$repeatDb = null;
$unpaidDb = null;
$limitDb = null;
$targetDb = null;
$progressDb = null;
gc_collect_cycles();
foreach ($paths as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}

if ($failures > 0) {
    echo $failures . " failed\n";
    exit(1);
}

echo "all passed\n";
exit(0);
