<?php
/**
 * Temporary read-only check for the Stripe session read used by reconciliation.
 *
 *   php bin/stripe-diagnose.php
 *
 * CLI only. Prints environment checks, then one GET of one paid Checkout
 * Session. No database writes, no Stripe mutations, and no 20i calls.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc > 1) {
    fwrite(STDERR, "diagnostic only; no arguments are accepted\n");
    exit(1);
}

require dirname(__DIR__) . '/public/lib/stripe.php';

webco_stripe_diagnose_say('sapi=' . PHP_SAPI);
webco_stripe_diagnose_say('curl=' . (function_exists('curl_init') ? 'yes' : 'no'));
webco_stripe_diagnose_say('pdo_mysql=' . (extension_loaded('pdo_mysql') ? 'yes' : 'no'));

$db = webco_db();
webco_stripe_diagnose_say($db instanceof PDO ? 'database=ok' : 'database=unavailable');
webco_stripe_diagnose_say(webco_stripe_secret() === null ? 'stripe_secret=missing' : 'stripe_secret=present');

if (!$db instanceof PDO) {
    webco_stripe_diagnose_say('stripe_livemode_column=unknown');
    webco_stripe_diagnose_say('order=none');
    exit(0);
}

$column = webco_stripe_diagnose_column($db);
webco_stripe_diagnose_say('stripe_livemode_column=' . $column['state']);
if ($column['sqlstate'] !== '') {
    webco_stripe_diagnose_say('sqlstate=' . $column['sqlstate']);
}

$order = webco_stripe_diagnose_order($db);
if ($order === null) {
    webco_stripe_diagnose_say('order=unavailable');
    exit(0);
}
if ($order['public_id'] === '') {
    webco_stripe_diagnose_say('order=none');
    exit(0);
}

webco_stripe_diagnose_say('public_id=' . $order['public_id']);
webco_stripe_diagnose_say('starting_stripe_read');

$started = microtime(true);
$session = webco_stripe_get_session_subscription($order['session_id']);
$seconds = microtime(true) - $started;

if (!is_array($session)) {
    webco_stripe_diagnose_say('stripe_read=failed');
    webco_stripe_diagnose_say('stripe_seconds=' . sprintf('%.3f', $seconds));
    exit(0);
}

$subscriptionId = $session['subscription_id'] ?? null;
webco_stripe_diagnose_say('stripe_read=ok');
webco_stripe_diagnose_say('livemode=' . (int) $session['livemode']);
webco_stripe_diagnose_say(
    'subscription_id=' . (is_string($subscriptionId) && $subscriptionId !== '' ? 'present' : 'absent')
);
webco_stripe_diagnose_say('stripe_seconds=' . sprintf('%.3f', $seconds));
exit(0);

function webco_stripe_diagnose_say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

/**
 * @return array{state: string, sqlstate: string}
 */
function webco_stripe_diagnose_column(PDO $db): array
{
    try {
        $statement = $db->query('SELECT stripe_livemode FROM orders LIMIT 0');
        if ($statement === false) {
            return ['state' => 'unknown', 'sqlstate' => ''];
        }

        return ['state' => 'present', 'sqlstate' => ''];
    } catch (PDOException $exception) {
        $info = $exception->errorInfo;
        $sqlState = is_array($info) ? (string) ($info[0] ?? '') : '';
        $driverCode = is_array($info) && isset($info[1]) ? (string) $info[1] : '';
        $absent = $sqlState === '42S22' || $driverCode === '1054';

        return [
            'state' => $absent ? 'absent' : 'unknown',
            'sqlstate' => $sqlState,
        ];
    }
}

/**
 * Null when the read fails. An empty public_id means no matching order.
 *
 * @return array{public_id: string, session_id: string}|null
 */
function webco_stripe_diagnose_order(PDO $db): ?array
{
    try {
        $statement = $db->query(
            'SELECT public_id, stripe_checkout_session_id
             FROM orders
             WHERE status = \'paid\'
               AND stripe_checkout_session_id IS NOT NULL
               AND stripe_checkout_session_id <> \'\'
             ORDER BY id
             LIMIT 1'
        );
        if ($statement === false) {
            return null;
        }
        $row = $statement->fetch();
    } catch (PDOException) {
        return null;
    }

    if ($row === false) {
        return ['public_id' => '', 'session_id' => ''];
    }
    if (!is_array($row)) {
        return null;
    }
    $publicId = $row['public_id'] ?? '';
    $sessionId = $row['stripe_checkout_session_id'] ?? '';
    if (!is_string($publicId) || $publicId === '' || !is_string($sessionId) || $sessionId === '') {
        return null;
    }

    return [
        'public_id' => $publicId,
        'session_id' => $sessionId,
    ];
}
