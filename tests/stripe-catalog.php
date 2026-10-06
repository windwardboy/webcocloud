<?php
/**
 * Offline Stripe catalog checks. No Stripe network calls.
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

$secretsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-stripe-catalog-' . getmypid() . '.php';
if (is_file($secretsPath)) {
    unlink($secretsPath);
}

$ids = [
    'essential_website' => 'price_' . str_repeat('a', 16),
    'professional_website' => 'price_' . str_repeat('b', 16),
    'annual_hosting' => 'price_' . str_repeat('c', 16),
    'essential_managed_care' => 'price_' . str_repeat('d', 16),
    'professional_managed_care' => 'price_' . str_repeat('e', 16),
];
$products = [
    'essential_website' => ['id' => 'prod_' . str_repeat('A', 14), 'name' => 'Webco Essential Website'],
    'professional_website' => ['id' => 'prod_' . str_repeat('B', 14), 'name' => 'Webco Professional Website'],
    'annual_hosting' => ['id' => 'prod_' . str_repeat('C', 14), 'name' => 'Webco Annual Hosting'],
    'essential_managed_care' => ['id' => 'prod_' . str_repeat('D', 14), 'name' => 'Webco Essential Managed Care'],
    'professional_managed_care' => ['id' => 'prod_' . str_repeat('E', 14), 'name' => 'Webco Professional Managed Care'],
];

$written = file_put_contents(
    $secretsPath,
    "<?php\n"
    . "define('WEBCO_STRIPE_SECRET_KEY', 'sk_live_" . str_repeat('L', 24) . "');\n"
    . "define('WEBCO_STRIPE_PRICE_ESSENTIAL_WEBSITE', " . var_export($ids['essential_website'], true) . ");\n"
    . "define('WEBCO_STRIPE_PRICE_PROFESSIONAL_WEBSITE', " . var_export($ids['professional_website'], true) . ");\n"
    . "define('WEBCO_STRIPE_PRICE_HOSTING', " . var_export($ids['annual_hosting'], true) . ");\n"
    . "define('WEBCO_STRIPE_PRICE_ESSENTIAL_MANAGED_CARE', " . var_export($ids['essential_managed_care'], true) . ");\n"
    . "define('WEBCO_STRIPE_PRICE_PROFESSIONAL_MANAGED_CARE', " . var_export($ids['professional_managed_care'], true) . ");\n"
);
check($written !== false, 'temporary catalog secrets file can be written');
define('WEBCO_SECRETS_FILE', $secretsPath);

require dirname(__DIR__) . '/public/lib/stripe-catalog.php';

$definitions = webco_stripe_catalog_definitions();
check(count($definitions) === 5, 'catalog defines all five live Prices');
check(count(webco_stripe_catalog_combinations()) === 4, 'catalog defines all four checkout combinations');

$publicId = 'wc_' . str_repeat('c', 20);
foreach (webco_stripe_catalog_combinations() as $combination) {
    $fields = webco_stripe_checkout_session_fields([
        'package_code' => $combination['package_code'],
        'care_choice' => $combination['care_choice'],
        'email' => 'catalog-check@webcocloud.net',
    ], $publicId);
    $problems = webco_stripe_catalog_session_problems($fields, $combination, $ids);
    check($problems === [], $combination['label'] . ' builds a valid Checkout Session payload');
    check(($fields['allow_promotion_codes'] ?? null) === 'true', $combination['label'] . ' keeps promotion codes enabled');
}

$fakePrices = [
    'essential_website' => [
        'object' => 'price',
        'active' => true,
        'livemode' => true,
        'type' => 'one_time',
        'currency' => 'gbp',
        'unit_amount' => 59500,
        'product' => $products['essential_website']['id'],
    ],
    'professional_website' => [
        'object' => 'price',
        'active' => true,
        'livemode' => true,
        'type' => 'one_time',
        'currency' => 'gbp',
        'unit_amount' => 99500,
        'product' => $products['professional_website']['id'],
    ],
    'annual_hosting' => [
        'object' => 'price',
        'active' => true,
        'livemode' => true,
        'type' => 'recurring',
        'currency' => 'gbp',
        'unit_amount' => 9900,
        'recurring' => ['interval' => 'year', 'interval_count' => 1],
        'product' => $products['annual_hosting']['id'],
    ],
    'essential_managed_care' => [
        'object' => 'price',
        'active' => true,
        'livemode' => true,
        'type' => 'recurring',
        'currency' => 'gbp',
        'unit_amount' => 3900,
        'recurring' => ['interval' => 'month', 'interval_count' => 1],
        'product' => $products['essential_managed_care']['id'],
    ],
    'professional_managed_care' => [
        'object' => 'price',
        'active' => true,
        'livemode' => true,
        'type' => 'recurring',
        'currency' => 'gbp',
        'unit_amount' => 5900,
        'recurring' => ['interval' => 'month', 'interval_count' => 1],
        'product' => $products['professional_managed_care']['id'],
    ],
];

foreach ($definitions as $key => $definition) {
    $problems = webco_stripe_catalog_price_problems(
        $fakePrices[$key],
        $definition,
        $products[$key],
        1
    );
    check($problems === [], $definition['label'] . ' matches the catalog expectation');
}

$wrongAmount = $fakePrices['essential_website'];
$wrongAmount['unit_amount'] = 595;
check(
    webco_stripe_catalog_price_problems($wrongAmount, $definitions['essential_website'], $products['essential_website'], 1) !== [],
    'a discounted Essential website amount is rejected by the catalog check'
);

$wrongMode = $fakePrices['annual_hosting'];
$wrongMode['livemode'] = false;
check(
    webco_stripe_catalog_price_problems($wrongMode, $definitions['annual_hosting'], $products['annual_hosting'], 1) !== [],
    'a test-mode Price is rejected against a live secret'
);

$getJson = static function (string $path) use ($fakePrices, $products, $ids): ?array {
    foreach ($ids as $key => $priceId) {
        if ($path === '/v1/prices/' . rawurlencode($priceId)) {
            return $fakePrices[$key];
        }
        $productId = $products[$key]['id'];
        if ($path === '/v1/products/' . rawurlencode($productId)) {
            return ['object' => 'product', 'id' => $productId, 'name' => $products[$key]['name']];
        }
    }

    return null;
};

$report = webco_stripe_catalog_run($getJson, 'sk_live_' . str_repeat('L', 24));
check(($report['ok'] ?? false) === true, 'a complete matching catalog report is ok');
check(($report['secret_mode'] ?? '') === 'live', 'the report records live secret mode');
check(count($report['combinations'] ?? []) === 4, 'the report covers four combinations');

$text = webco_stripe_catalog_report_text($report);
check(str_contains($text, 'ok: yes'), 'the text report marks success');
check(!str_contains($text, 'price_'), 'the text report does not print Price IDs');
check(!str_contains($text, 'sk_live_'), 'the text report does not print the secret key');

$mismatchedProduct = $products;
$mismatchedProduct['essential_website']['name'] = 'Webco Professional Website';
$badGet = static function (string $path) use ($fakePrices, $mismatchedProduct, $ids): ?array {
    foreach ($ids as $key => $priceId) {
        if ($path === '/v1/prices/' . rawurlencode($priceId)) {
            return $fakePrices[$key];
        }
        $productId = $mismatchedProduct[$key]['id'];
        if ($path === '/v1/products/' . rawurlencode($productId)) {
            return ['object' => 'product', 'id' => $productId, 'name' => $mismatchedProduct[$key]['name']];
        }
    }

    return null;
};
$badReport = webco_stripe_catalog_run($badGet, 'sk_live_' . str_repeat('L', 24));
check(($badReport['ok'] ?? true) === false, 'a website Price on the wrong Product fails the catalog check');

$bin = (string) file_get_contents(dirname(__DIR__) . '/bin/stripe-catalog-check.php');
check(str_contains($bin, 'GET only'), 'the live catalog CLI documents read-only use');
check(
    !str_contains($bin, 'checkout/sessions') && !str_contains($bin, 'webco_create_checkout_session'),
    'the live catalog CLI does not create Checkout Sessions'
);

@unlink($secretsPath);

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
