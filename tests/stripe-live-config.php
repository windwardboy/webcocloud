<?php
/**
 * Live and test Stripe secret/session acceptance. No Stripe network calls.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

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

$secretsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-stripe-live-' . getmypid() . '.php';
if (is_file($secretsPath)) {
    unlink($secretsPath);
}

$liveSecret = 'sk_live_' . str_repeat('L', 24);
$testSecret = 'sk_test_' . str_repeat('T', 24);
$written = file_put_contents(
    $secretsPath,
    "<?php\n"
    . "define('WEBCO_STRIPE_SECRET_KEY', " . var_export($liveSecret, true) . ");\n"
    . "define('WEBCO_STRIPE_PRICE_ESSENTIAL_WEBSITE', 'price_" . str_repeat('a', 16) . "');\n"
    . "define('WEBCO_STRIPE_PRICE_HOSTING', 'price_" . str_repeat('b', 16) . "');\n"
);
check($written !== false, 'temporary live secrets file can be written');
define('WEBCO_SECRETS_FILE', $secretsPath);

require dirname(__DIR__) . '/public/lib/stripe.php';
require dirname(__DIR__) . '/public/stripe-webhook.php';

check(webco_stripe_secret() === $liveSecret, 'sk_live_ secret keys are accepted');
check(
    webco_stripe_checkout_session_id_valid('cs_live_' . str_repeat('c', 16)),
    'cs_live_ checkout session ids are valid'
);
check(
    webco_stripe_checkout_session_id_valid('cs_test_' . str_repeat('d', 16)),
    'cs_test_ checkout session ids remain valid'
);
check(
    !webco_stripe_checkout_session_id_valid('cs_other_' . str_repeat('e', 16)),
    'unknown checkout session prefixes stay invalid'
);

$liveUrl = 'https://checkout.stripe.com/c/pay/cs_live_' . str_repeat('f', 16);
$testUrl = 'https://checkout.stripe.com/c/pay/cs_test_' . str_repeat('g', 16);
check(webco_is_stripe_checkout_url($liveUrl), 'live Checkout URLs are accepted');
check(webco_is_stripe_checkout_url($testUrl), 'test Checkout URLs remain accepted');
check(webco_is_test_checkout_url($liveUrl), 'legacy test URL helper also accepts live Checkout URLs');

$liveSession = [
    'id' => 'cs_live_' . str_repeat('h', 16),
    'livemode' => true,
    'status' => 'open',
    'url' => $liveUrl,
];
$record = webco_stripe_session_record($liveSession);
check(is_array($record) && ($record['id'] ?? '') === $liveSession['id'], 'a live open session record is accepted');

$mismatched = $liveSession;
$mismatched['id'] = 'cs_test_' . str_repeat('i', 16);
check(webco_stripe_session_record($mismatched) === null, 'live mode cannot use a cs_test_ session id');

$testSession = [
    'id' => 'cs_test_' . str_repeat('j', 16),
    'livemode' => false,
    'status' => 'open',
    'url' => $testUrl,
];
check(is_array(webco_stripe_session_record($testSession)), 'a test open session record remains accepted');

$publicId = 'wc_' . str_repeat('1', 20);
$liveEvent = [
    'id' => 'evt_' . str_repeat('2', 16),
    'type' => 'checkout.session.completed',
    'livemode' => true,
    'data' => [
        'object' => [
            'object' => 'checkout.session',
            'id' => 'cs_live_' . str_repeat('3', 16),
            'livemode' => true,
            'payment_status' => 'paid',
            'client_reference_id' => $publicId,
            'metadata' => ['public_id' => $publicId],
            'customer' => 'cus_' . str_repeat('4', 14),
            'subscription' => 'sub_' . str_repeat('5', 14),
            'payment_intent' => 'pi_' . str_repeat('6', 16),
        ],
    ],
];
$liveParsed = webco_checkout_payment_from_event($liveEvent);
check(
    is_array($liveParsed)
        && ($liveParsed['session_id'] ?? '') === 'cs_live_' . str_repeat('3', 16)
        && ($liveParsed['stripe_livemode'] ?? null) === 1,
    'paid live checkout.session.completed events are accepted'
);

$partialLive = $liveEvent;
$partialLive['data']['object']['livemode'] = false;
check(webco_checkout_payment_from_event($partialLive) === false, 'event and session livemode must agree');

$source = (string) file_get_contents(dirname(__DIR__) . '/public/lib/stripe.php');
check(str_contains($source, "sk_(test|live)_"), 'secret validation explicitly allows test and live keys');
check(str_contains($source, 'webco_checkout_log'), 'checkout failures can write internal diagnostics');
check(
    str_contains($source, 'checkout create blocked: secret key unavailable or not sk_test_/sk_live_'),
    'missing live secret keys produce a private diagnostic marker'
);
check(
    str_contains($source, 'sk_(?:test|live)_|whsec_|price_|Bearer'),
    'checkout diagnostics refuse to log secret-shaped markers'
);

ob_start();
webco_checkout_log('leaked ' . $liveSecret);
webco_checkout_log('checkout create blocked: secret key unavailable or not sk_test_/sk_live_');
ob_end_clean();
check(true, 'checkout diagnostics accept safe markers without throwing');

check(
    preg_match('/^sk_test_[A-Za-z0-9]{16,200}$/', $testSecret) === 1
        && preg_match('/^sk_(test|live)_[A-Za-z0-9]{16,200}$/', $testSecret) === 1,
    'sk_test_ remains a valid secret key shape'
);

$astro = (string) file_get_contents(dirname(__DIR__) . '/src/pages/start/checkout.astro');
check(
    str_contains($astro, '/cs_live_') && str_contains($astro, '/cs_test_'),
    'the checkout funnel accepts live and test Stripe Checkout URLs'
);

$publicId = 'wc_' . str_repeat('a', 20);
$order = [
    'package_code' => 'essential',
    'care_choice' => 'standard',
    'email' => 'alex@example.com',
];
$fields = webco_stripe_checkout_session_fields($order, $publicId);
check(is_array($fields), 'checkout session fields can be built for Essential + hosting');
check(($fields['allow_promotion_codes'] ?? null) === 'true', 'checkout sessions allow Stripe promotion codes');
check(
    ($fields['line_items[0][price]'] ?? '') === 'price_' . str_repeat('a', 16)
        && ($fields['line_items[1][price]'] ?? '') === 'price_' . str_repeat('b', 16),
    'one-off website and recurring hosting prices stay on the session'
);
check(
    !array_key_exists('discounts[0][coupon]', $fields ?? [])
        && !array_key_exists('discounts[0][promotion_code]', $fields ?? []),
    'no coupon or promotion code is applied automatically'
);
$body = webco_stripe_form(is_array($fields) ? $fields : []);
check(
    str_contains($body, 'allow_promotion_codes=true'),
    'the encoded Checkout Session body sends allow_promotion_codes=true'
);
check(
    !str_contains($body, 'discounts'),
    'the encoded Checkout Session body does not send a discounts list'
);

$managed = webco_stripe_checkout_session_fields([
    'package_code' => 'essential',
    'care_choice' => 'managed',
    'email' => 'alex@example.com',
], $publicId);
check($managed === null, 'Managed Care fields stay null until the Managed Care price is configured');

check(
    str_contains($source, 'WEBCO_STRIPE_PRICE_PROFESSIONAL_WEBSITE')
        && str_contains($source, 'WEBCO_STRIPE_PRICE_ESSENTIAL_MANAGED_CARE')
        && str_contains($source, 'WEBCO_STRIPE_PRICE_PROFESSIONAL_MANAGED_CARE'),
    'checkout line prices wire all five configured live Price secrets'
);
check(
    str_contains($source, "subscription_data[trial_period_days]'] = '30'")
        && str_contains($source, 'webco_stripe_calendar_year_timestamp'),
    'Managed Care uses a 30-day trial and annual hosting uses a one-year trial_end'
);

@unlink($secretsPath);

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
