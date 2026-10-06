<?php
/**
 * Safe payment-state checks. No Stripe network calls and no production database.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/draft-order.php';
require dirname(__DIR__) . '/public/order-status.php';
require dirname(__DIR__) . '/public/lib/projects.php';
require dirname(__DIR__) . '/public/stripe-webhook.php';

$failures = 0;

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

function line_items(int $websitePence, int $recurringPence): array
{
    return [
        'has_more' => false,
        'data' => [
            [
                'amount_total' => $websitePence,
                'currency' => 'gbp',
                'price' => ['type' => 'one_time', 'currency' => 'gbp'],
            ],
            [
                'amount_total' => $recurringPence,
                'currency' => 'gbp',
                'price' => ['type' => 'recurring', 'currency' => 'gbp'],
            ],
        ],
    ];
}

$trialEnd = 1798761600;
$trialAt = gmdate('Y-m-d H:i:s', $trialEnd);
$offers = [
    ['essential', 'standard', 59500, 9900],
    ['professional', 'standard', 99500, 9900],
    ['essential', 'managed', 59500, 3900],
    ['professional', 'managed', 99500, 5900],
];

foreach ($offers as [$package, $care, $website, $recurring]) {
    $label = $package . ' + ' . $care;
    $collected = webco_collected_website_pence(line_items($website, $recurring));
    check($collected === $website, "{$label} website fee ignores the recurring amount");
    check($collected !== $website + $recurring, "{$label} recurring amount is not part of the website fee");

    $state = webco_local_subscription_state($care, 'trialing', $trialEnd);
    check(is_array($state), "{$label} local subscription state");
    if (!is_array($state)) {
        continue;
    }
    if ($care === 'managed') {
        check($state['care_status'] === 'trialing', "{$label} care is trialing");
        check($state['care_trial_ends_at'] === $trialAt, "{$label} care trial end comes from Stripe");
        check($state['hosting_status'] === 'included', "{$label} hosting is included with Managed Care");
        check($state['hosting_included_until'] === null, "{$label} included hosting has no separate end");
    } else {
        check($state['care_status'] === null, "{$label} has no Managed Care status");
        check($state['care_trial_ends_at'] === null, "{$label} has no care trial");
        check($state['hosting_status'] === 'trialing', "{$label} hosting is trialing for the included year");
        check($state['hosting_included_until'] === $trialAt, "{$label} hosting included-until is the trial end");
    }
}

check(webco_collected_website_pence(line_items(100, 9900)) === 100, 'a wrong one-time amount stays visible for rejection');

$publicId = 'wc_' . str_repeat('a', 20);
$sessionId = 'cs_test_' . str_repeat('b', 16);
$otherSession = 'cs_test_' . str_repeat('c', 16);
$eventId = 'evt_' . str_repeat('d', 16);

$paidEvent = [
    'id' => $eventId,
    'type' => 'checkout.session.completed',
    'livemode' => false,
    'data' => [
        'object' => [
            'object' => 'checkout.session',
            'id' => $sessionId,
            'livemode' => false,
            'payment_status' => 'paid',
            'client_reference_id' => $publicId,
            'metadata' => ['public_id' => $publicId],
            'customer' => 'cus_' . str_repeat('e', 14),
            'subscription' => 'sub_' . str_repeat('f', 14),
            'payment_intent' => 'pi_' . str_repeat('a', 16),
        ],
    ],
];
$parsed = webco_checkout_payment_from_event($paidEvent);
check(is_array($parsed) && $parsed['session_id'] === $sessionId, 'paid test checkout session is accepted');
check(is_array($parsed) && $parsed['payment_intent_id'] === 'pi_' . str_repeat('a', 16), 'payment intent is kept when Stripe sends one');

$unpaid = $paidEvent;
$unpaid['data']['object']['payment_status'] = 'unpaid';
check(webco_checkout_payment_from_event($unpaid) === null, 'unpaid checkout does not count as website payment');

$live = $paidEvent;
$live['livemode'] = true;
check(webco_checkout_payment_from_event($live) === null, 'live mode is still ignored');

check(webco_order_status_payload([
    'status' => 'checkout_created',
    'package_name' => 'Webco Essential',
    'domain_name' => 'example.co.uk',
    'care_choice' => 'standard',
], $publicId)['status'] === 'checkout_created', 'success lookup stays unpaid before the webhook');
check(webco_order_status_payload(null, 'not-an-order')['status'] === 'unknown', 'invalid order reference stays unknown');
check(webco_order_status_payload(null, $publicId)['status'] === 'unknown', 'missing order reference stays unknown');

check(webco_checkout_action('paid', true, null, true) === 'resume_paid', 'a second Continue on the same paid order does not open another checkout');
check(webco_checkout_action('paid', false, null, false) === 'new_order', 'a different purchase after payment starts a new order');
check(webco_checkout_action('draft', true, null, true) === 'create_session', 'the first Continue creates a checkout session');
check(webco_checkout_action('checkout_created', true, 'open', true) === 'reuse_open_session', 'an open session is reused');
check(webco_checkout_action('checkout_created', true, 'open', false) === 'replace_session', 'changed package or care replaces the open session');
check(webco_checkout_action('checkout_created', true, 'complete', true) === 'resume_paid', 'a completed session is not replaced');
check(webco_checkout_action('cancelled', true, 'expired', true) === 'replace_session', 'an expired checkout can be replaced on the same order');
check(strlen('checkout_created') === 16, 'checkout_created fits the existing status column');

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-payment-state-' . getmypid() . '.sqlite';
if (is_file($path)) {
    unlink($path);
}
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$other = new PDO('sqlite:' . $path);
$other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec(
    'CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL,
        package_code TEXT NOT NULL,
        package_price_pence INTEGER NOT NULL,
        care_choice TEXT NOT NULL,
        domain_name TEXT,
        email TEXT,
        stripe_checkout_session_id TEXT UNIQUE,
        stripe_customer_id TEXT,
        stripe_subscription_id TEXT,
        payment_intent_id TEXT,
        care_status TEXT,
        care_trial_ends_at TEXT,
        hosting_status TEXT,
        hosting_included_until TEXT,
        stripe_livemode INTEGER,
        paid_at TEXT
    )'
);
$db->exec(
    'CREATE TABLE stripe_events (
        stripe_event_id TEXT NOT NULL PRIMARY KEY,
        event_type TEXT NOT NULL,
        claimed_at TEXT NOT NULL,
        processed_at TEXT,
        result TEXT
    )'
);
$db->exec(
    'CREATE TABLE projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL UNIQUE,
        brief_token_hash TEXT NOT NULL,
        provisioning_status TEXT NOT NULL DEFAULT \'waiting_payment\',
        provisioned_at TEXT,
        provisioning_error TEXT,
        customer_notified_at TEXT,
        internal_notified_at TEXT,
        updated_at TEXT
    )'
);

$insert = $db->prepare(
    'INSERT INTO orders (
        public_id, status, package_code, package_price_pence, care_choice, stripe_checkout_session_id
     ) VALUES (:public_id, \'checkout_created\', \'essential\', 59500, \'standard\', :session_id)'
);
$insert->execute(['public_id' => $publicId, 'session_id' => $sessionId]);

$standard = webco_local_subscription_state('standard', 'trialing', $trialEnd);
$payment = [
    'public_id' => $publicId,
    'session_id' => $sessionId,
    'customer_id' => 'cus_' . str_repeat('e', 14),
    'subscription_id' => 'sub_' . str_repeat('f', 14),
    'payment_intent_id' => 'pi_' . str_repeat('a', 16),
    'stripe_livemode' => 0,
    'website_amount_pence' => 59500,
    'care_status' => $standard['care_status'],
    'care_trial_ends_at' => $standard['care_trial_ends_at'],
    'hosting_status' => $standard['hosting_status'],
    'hosting_included_until' => $standard['hosting_included_until'],
];

$mismatch = $payment;
$mismatch['session_id'] = $otherSession;
check(webco_record_checkout_payment($db, $mismatch) === 'mismatch', 'a different checkout session cannot mark the order paid');
$stillOpen = $db->query('SELECT status FROM orders WHERE public_id = ' . $db->quote($publicId))->fetch();
check(($stillOpen['status'] ?? '') === 'checkout_created', 'mismatched session leaves the order unpaid');

check(webco_record_checkout_payment($db, $payment) === 'paid', 'matching website fee marks the order paid');
check(webco_record_checkout_payment($db, $payment) === 'already', 'repeat payment for the same session does not write again');
$stored = $db->query(
    'SELECT status, care_status, hosting_status, hosting_included_until, payment_intent_id, stripe_livemode
     FROM orders WHERE public_id = ' . $db->quote($publicId)
)->fetch();
check(($stored['status'] ?? '') === 'paid', 'order status is paid');
check(($stored['care_status'] ?? null) === null, 'standard hosting stores no care status');
check(($stored['hosting_status'] ?? '') === 'trialing', 'standard hosting stores the included-year trial');
check(($stored['hosting_included_until'] ?? '') === $trialAt, 'standard hosting stores the trial end');
check(($stored['payment_intent_id'] ?? '') === $payment['payment_intent_id'], 'payment intent is stored');
check((int) ($stored['stripe_livemode'] ?? -1) === 0, 'test checkout stores stripe_livemode 0');
$db->prepare('UPDATE orders SET stripe_livemode = 1 WHERE public_id = :id')->execute(['id' => $publicId]);
check(webco_record_checkout_payment($db, $payment) === 'already', 'a repeat paid event does not write again');
$kept = $db->query('SELECT stripe_livemode FROM orders WHERE public_id = ' . $db->quote($publicId))->fetch();
check((int) ($kept['stripe_livemode'] ?? -1) === 1, 'a repeat paid event does not overwrite stripe_livemode');

$managedId = 'wc_' . str_repeat('b', 20);
$managedSession = 'cs_test_' . str_repeat('d', 16);
$insert->execute(['public_id' => $managedId, 'session_id' => $managedSession]);
$db->prepare('UPDATE orders SET package_code = \'professional\', package_price_pence = 99500, care_choice = \'managed\' WHERE public_id = :id')
    ->execute(['id' => $managedId]);
$managedState = webco_local_subscription_state('managed', 'trialing', $trialEnd);
$managedPayment = $payment;
$managedPayment['public_id'] = $managedId;
$managedPayment['session_id'] = $managedSession;
$managedPayment['website_amount_pence'] = 99500;
$managedPayment['care_status'] = $managedState['care_status'];
$managedPayment['care_trial_ends_at'] = $managedState['care_trial_ends_at'];
$managedPayment['hosting_status'] = $managedState['hosting_status'];
$managedPayment['hosting_included_until'] = $managedState['hosting_included_until'];
check(webco_record_checkout_payment($db, $managedPayment) === 'paid', 'professional Managed Care payment is recorded');
$managedRow = $db->query(
    'SELECT care_status, care_trial_ends_at, hosting_status, hosting_included_until
     FROM orders WHERE public_id = ' . $db->quote($managedId)
)->fetch();
check(($managedRow['care_status'] ?? '') === 'trialing', 'Managed Care status is trialing');
check(($managedRow['care_trial_ends_at'] ?? '') === $trialAt, 'Managed Care trial end is stored');
check(($managedRow['hosting_status'] ?? '') === 'included', 'Managed Care hosting is included');
check(($managedRow['hosting_included_until'] ?? null) === null, 'Managed Care does not store a separate hosting end');

$amountId = 'wc_' . str_repeat('9', 20);
$amountSession = 'cs_test_' . str_repeat('9', 16);
$insert->execute(['public_id' => $amountId, 'session_id' => $amountSession]);
$wrongAmount = $payment;
$wrongAmount['public_id'] = $amountId;
$wrongAmount['session_id'] = $amountSession;
$wrongAmount['website_amount_pence'] = 100;
check(webco_record_checkout_payment($db, $wrongAmount) === 'mismatch', 'a different website amount cannot mark the order paid');
$amountRow = $db->query('SELECT status FROM orders WHERE public_id = ' . $db->quote($amountId))->fetch();
check(($amountRow['status'] ?? '') === 'checkout_created', 'amount mismatch leaves the order unpaid');

$first = webco_claim_stripe_event($db, $eventId, 'checkout.session.completed');
$second = webco_claim_stripe_event($other, $eventId, 'checkout.session.completed');
check(($first['state'] ?? '') === 'claimed', 'first webhook delivery claims the event');
check(($second['state'] ?? '') === 'busy', 'overlapping delivery does not run side effects');
check(webco_finish_stripe_event($db, $eventId, $first['claimed_at'], 'applied'), 'finished event is marked processed');
$third = webco_claim_stripe_event($other, $eventId, 'checkout.session.completed');
check(($third['state'] ?? '') === 'done', 'a later delivery of the same event is already done');

$staleId = 'evt_' . str_repeat('e', 16);
$stale = webco_claim_stripe_event($db, $staleId, 'checkout.session.completed');
$db->prepare('UPDATE stripe_events SET claimed_at = :claimed_at WHERE stripe_event_id = :id')
    ->execute(['claimed_at' => gmdate('Y-m-d H:i:s', time() - 300), 'id' => $staleId]);
$reclaimed = webco_claim_stripe_event($other, $staleId, 'checkout.session.completed');
check(($stale['state'] ?? '') === 'claimed' && ($reclaimed['state'] ?? '') === 'claimed', 'a stale unfinished claim can be recovered');

$projectsSource = (string) file_get_contents(dirname(__DIR__) . '/public/lib/projects.php');
check($projectsSource !== '', 'projects library source can be read');
check(
    preg_match(
        '/function webco_ensure_paid_project\(PDO \$db, string \$publicId\): string\s*\{(?P<body>.*)\nfunction webco_lock_paid_order/s',
        $projectsSource,
        $ensureMatch
    ) === 1,
    'ensure_paid_project body can be read'
);
$ensureBody = (string) ($ensureMatch['body'] ?? '');
check(
    str_contains($ensureBody, 'webco_mark_project_ready($db, $projectId)'),
    'paid project creation marks the project ready for provisioning'
);
check(
    (bool) preg_match(
        '/if\s*\(\s*!webco_mark_project_ready\(\$db,\s*\$projectId\)\s*\)\s*\{\s*return\s+\'error\'\s*;/s',
        $ensureBody
    ),
    'failure to mark ready causes the paid project path to retry'
);
check(
    !str_contains($ensureBody, 'stripe_livemode'),
    'paid project readiness is not gated on stripe_livemode'
);
check(
    str_contains($ensureBody, "\\'waiting_payment\\'"),
    'new project rows still insert as waiting_payment before promotion'
);

$db->exec(
    'INSERT INTO projects (order_id, brief_token_hash, provisioning_status)
     VALUES (1, \'' . hash('sha256', 'token') . '\', \'waiting_payment\')'
);
check(webco_mark_project_ready($db, 1), 'paid work can move from waiting_payment to ready');
$ready = $db->query('SELECT provisioning_status FROM projects WHERE id = 1')->fetch();
check(($ready['provisioning_status'] ?? '') === 'ready', 'provisioning_status is ready');
check(webco_mark_project_ready($db, 1), 'marking ready again does not fail');
$db->exec('UPDATE projects SET provisioning_status = \'in_progress\' WHERE id = 1');
check(webco_mark_project_ready($db, 1), 'in-progress provisioning is left alone');
$progress = $db->query('SELECT provisioning_status FROM projects WHERE id = 1')->fetch();
check(($progress['provisioning_status'] ?? '') === 'in_progress', 'ready is not forced over in_progress');

$token = str_repeat('ab', 32);
$db->exec(
    'INSERT INTO projects (order_id, brief_token_hash, provisioning_status)
     VALUES (2, \'' . hash('sha256', $token) . '\', \'ready\')'
);
$claimedToken = webco_claim_customer_notification($db, 2, $token);
$secondToken = webco_claim_customer_notification($other, 2, $token);
check($claimedToken === $token, 'one handler claims the customer email');
check($secondToken === null, 'a concurrent handler cannot claim the same customer email');
webco_release_customer_notification($db, 2, $token);
$retryToken = webco_claim_customer_notification($db, 2, $token);
check($retryToken === $token, 'a failed send releases the claim for a genuine retry');

check(webco_claim_internal_notification($db, 2), 'one handler claims the internal email');
check(!webco_claim_internal_notification($other, 2), 'a concurrent handler cannot claim the same internal email');
webco_release_internal_notification($db, 2);
check(webco_claim_internal_notification($db, 2), 'a failed internal send can be retried');

$testReadyId = 'wc_' . str_repeat('d', 20);
$liveReadyId = 'wc_' . str_repeat('e', 20);
$db->prepare(
    'INSERT INTO orders (
        public_id, status, package_code, package_price_pence, care_choice, stripe_livemode
     ) VALUES (:public_id, \'paid\', \'essential\', 59500, \'standard\', :livemode)'
)->execute(['public_id' => $testReadyId, 'livemode' => 0]);
$testOrderId = (int) $db->lastInsertId();
$db->prepare(
    'INSERT INTO orders (
        public_id, status, package_code, package_price_pence, care_choice, stripe_livemode
     ) VALUES (:public_id, \'paid\', \'essential\', 59500, \'standard\', :livemode)'
)->execute(['public_id' => $liveReadyId, 'livemode' => 1]);
$liveOrderId = (int) $db->lastInsertId();
$db->prepare(
    'INSERT INTO projects (order_id, brief_token_hash, provisioning_status)
     VALUES (:order_id, :hash, \'waiting_payment\')'
)->execute(['order_id' => $testOrderId, 'hash' => hash('sha256', 'test-ready')]);
$testProjectId = (int) $db->lastInsertId();
$db->prepare(
    'INSERT INTO projects (order_id, brief_token_hash, provisioning_status)
     VALUES (:order_id, :hash, \'waiting_payment\')'
)->execute(['order_id' => $liveOrderId, 'hash' => hash('sha256', 'live-ready')]);
$liveProjectId = (int) $db->lastInsertId();
check(
    webco_mark_project_ready($db, $testProjectId),
    'a paid Stripe test-mode project can become ready for allow-test-order runs'
);
check(
    webco_mark_project_ready($db, $liveProjectId),
    'a paid live project can become ready for normal provisioning'
);
$testStatus = $db->query(
    'SELECT provisioning_status FROM projects WHERE id = ' . $testProjectId
)->fetch();
$liveStatus = $db->query(
    'SELECT provisioning_status FROM projects WHERE id = ' . $liveProjectId
)->fetch();
check(($testStatus['provisioning_status'] ?? '') === 'ready', 'paid test-mode provisioning_status is ready');
check(($liveStatus['provisioning_status'] ?? '') === 'ready', 'paid live provisioning_status is ready');
check(
    webco_mark_project_ready($db, $testProjectId) && webco_mark_project_ready($db, $liveProjectId),
    'repeating readiness for paid test and live projects stays idempotent'
);

$draftId = 'wc_' . str_repeat('c', 20);
$db->prepare(
    'INSERT INTO orders (public_id, status, package_code, package_price_pence, care_choice)
     VALUES (:public_id, \'draft\', \'essential\', 59500, \'managed\')'
)->execute(['public_id' => $draftId]);
$bound = webco_bind_checkout_session($db, $draftId, $sessionId . 'x', null);
check($bound, 'checkout session id is stored before payment');
$boundRow = webco_find_order_checkout_row($db, $draftId);
check(($boundRow['status'] ?? '') === 'checkout_created', 'the order moves to checkout_created');
check(webco_cancel_open_checkout($db, $draftId, (string) ($boundRow['stripe_checkout_session_id'] ?? '')) === 'cancelled', 'an expired open checkout can be cancelled');
check(webco_cancel_open_checkout($db, $publicId, $sessionId) === 'ignored', 'a paid order is not cancelled by session expiry');

$db = null;
$other = null;
gc_collect_cycles();
@unlink($path);

if ($failures > 0) {
    echo $failures . " failed\n";
    exit(1);
}

echo "all passed\n";
exit(0);
