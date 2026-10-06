<?php
/**
 * Read-only Stripe catalog expectations for Webco checkout combinations.
 *
 * Used by bin/stripe-catalog-check.php and offline tests. Does not create
 * Checkout Sessions, charges, subscriptions, or call 20i.
 */

declare(strict_types=1);

$webcoStripeCatalogScript = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
if (str_ends_with($webcoStripeCatalogScript, '/lib/stripe-catalog.php')) {
    http_response_code(404);
    exit;
}
unset($webcoStripeCatalogScript);

require_once __DIR__ . '/stripe.php';

/**
 * Catalog amounts match draft-order.php and the payment-state matrix.
 *
 * @return array<string, array{
 *   label: string,
 *   secret: string,
 *   type: 'one_time'|'recurring',
 *   unit_amount: int,
 *   currency: string,
 *   interval: ?string,
 *   interval_count: ?int,
 *   product_name_must_match: list<string>,
 *   product_name_must_not_match: list<string>
 * }>
 */
function webco_stripe_catalog_definitions(): array
{
    return [
        'essential_website' => [
            'label' => 'Essential website',
            'secret' => 'WEBCO_STRIPE_PRICE_ESSENTIAL_WEBSITE',
            'type' => 'one_time',
            'unit_amount' => 59500,
            'currency' => 'gbp',
            'interval' => null,
            'interval_count' => null,
            'product_name_must_match' => ['/Essential/i'],
            'product_name_must_not_match' => ['/Professional/i', '/Managed/i'],
        ],
        'professional_website' => [
            'label' => 'Professional website',
            'secret' => 'WEBCO_STRIPE_PRICE_PROFESSIONAL_WEBSITE',
            'type' => 'one_time',
            'unit_amount' => 99500,
            'currency' => 'gbp',
            'interval' => null,
            'interval_count' => null,
            'product_name_must_match' => ['/Professional/i'],
            'product_name_must_not_match' => ['/Essential/i', '/Managed/i'],
        ],
        'annual_hosting' => [
            'label' => 'Annual hosting',
            'secret' => 'WEBCO_STRIPE_PRICE_HOSTING',
            'type' => 'recurring',
            'unit_amount' => 9900,
            'currency' => 'gbp',
            'interval' => 'year',
            'interval_count' => 1,
            'product_name_must_match' => ['/Host/i'],
            'product_name_must_not_match' => ['/Managed/i'],
        ],
        'essential_managed_care' => [
            'label' => 'Essential Managed Care',
            'secret' => 'WEBCO_STRIPE_PRICE_ESSENTIAL_MANAGED_CARE',
            'type' => 'recurring',
            'unit_amount' => 3900,
            'currency' => 'gbp',
            'interval' => 'month',
            'interval_count' => 1,
            'product_name_must_match' => ['/Managed/i', '/Essential/i'],
            'product_name_must_not_match' => ['/Professional/i'],
        ],
        'professional_managed_care' => [
            'label' => 'Professional Managed Care',
            'secret' => 'WEBCO_STRIPE_PRICE_PROFESSIONAL_MANAGED_CARE',
            'type' => 'recurring',
            'unit_amount' => 5900,
            'currency' => 'gbp',
            'interval' => 'month',
            'interval_count' => 1,
            'product_name_must_match' => ['/Managed/i', '/Professional/i'],
            'product_name_must_not_match' => ['/Essential/i'],
        ],
    ];
}

/**
 * The four live checkout combinations.
 *
 * @return list<array{
 *   label: string,
 *   package_code: string,
 *   care_choice: string,
 *   website_key: string,
 *   recurring_key: string,
 *   trial: 'year'|'days30'
 * }>
 */
function webco_stripe_catalog_combinations(): array
{
    return [
        [
            'label' => 'Essential + Annual hosting',
            'package_code' => 'essential',
            'care_choice' => 'standard',
            'website_key' => 'essential_website',
            'recurring_key' => 'annual_hosting',
            'trial' => 'year',
        ],
        [
            'label' => 'Professional + Annual hosting',
            'package_code' => 'professional',
            'care_choice' => 'standard',
            'website_key' => 'professional_website',
            'recurring_key' => 'annual_hosting',
            'trial' => 'year',
        ],
        [
            'label' => 'Essential + Managed Care',
            'package_code' => 'essential',
            'care_choice' => 'managed',
            'website_key' => 'essential_website',
            'recurring_key' => 'essential_managed_care',
            'trial' => 'days30',
        ],
        [
            'label' => 'Professional + Managed Care',
            'package_code' => 'professional',
            'care_choice' => 'managed',
            'website_key' => 'professional_website',
            'recurring_key' => 'professional_managed_care',
            'trial' => 'days30',
        ],
    ];
}

/**
 * @return 0|1|null null when the secret is missing or not a Stripe secret key
 */
function webco_stripe_catalog_secret_livemode(?string $secret): ?int
{
    if (!is_string($secret) || $secret === '') {
        return null;
    }
    if (str_starts_with($secret, 'sk_live_')) {
        return 1;
    }
    if (str_starts_with($secret, 'sk_test_')) {
        return 0;
    }

    return null;
}

/**
 * @param array<mixed> $price
 * @param array<string, mixed> $definition
 * @param array{id?: string, name?: string}|null $product
 * @return list<string>
 */
function webco_stripe_catalog_price_problems(
    array $price,
    array $definition,
    ?array $product,
    int $expectLivemode
): array {
    $problems = [];
    $label = (string) ($definition['label'] ?? 'price');

    if (($price['object'] ?? null) !== 'price') {
        $problems[] = $label . ': Stripe object is not a price';
        return $problems;
    }
    if (($price['active'] ?? null) !== true) {
        $problems[] = $label . ': price is not active';
    }
    $livemode = $price['livemode'] ?? null;
    if ($livemode !== ($expectLivemode === 1)) {
        $problems[] = $label . ': price livemode does not match the configured secret key mode';
    }
    if (($price['type'] ?? null) !== $definition['type']) {
        $problems[] = $label . ': expected type ' . $definition['type'];
    }
    if (strtolower((string) ($price['currency'] ?? '')) !== $definition['currency']) {
        $problems[] = $label . ': expected currency ' . $definition['currency'];
    }
    $amount = webco_stripe_amount($price['unit_amount'] ?? null);
    if ($amount !== (int) $definition['unit_amount']) {
        $problems[] = $label . ': expected unit_amount ' . (int) $definition['unit_amount'];
    }

    if ($definition['type'] === 'recurring') {
        $recurring = $price['recurring'] ?? null;
        if (!is_array($recurring)) {
            $problems[] = $label . ': missing recurring schedule';
        } else {
            if (($recurring['interval'] ?? null) !== $definition['interval']) {
                $problems[] = $label . ': expected interval ' . (string) $definition['interval'];
            }
            $count = $recurring['interval_count'] ?? null;
            if (is_string($count) && preg_match('/^\d+$/', $count)) {
                $count = (int) $count;
            }
            if ($count !== (int) $definition['interval_count']) {
                $problems[] = $label . ': expected interval_count ' . (int) $definition['interval_count'];
            }
        }
    } elseif (array_key_exists('recurring', $price) && $price['recurring'] !== null) {
        $problems[] = $label . ': one_time price must not be recurring';
    }

    $productName = is_array($product) ? (string) ($product['name'] ?? '') : '';
    if ($productName === '') {
        $problems[] = $label . ': product name is missing';
    } else {
        foreach ($definition['product_name_must_match'] ?? [] as $pattern) {
            if (!is_string($pattern) || preg_match($pattern, $productName) !== 1) {
                $problems[] = $label . ': product name does not match ' . (string) $pattern;
            }
        }
        foreach ($definition['product_name_must_not_match'] ?? [] as $pattern) {
            if (is_string($pattern) && preg_match($pattern, $productName) === 1) {
                $problems[] = $label . ': product name unexpectedly matches ' . $pattern;
            }
        }
    }

    return $problems;
}

/**
 * @param array<string, string>|null $fields
 * @param array<string, mixed> $combination
 * @param array<string, string> $priceIds keyed by catalog definition key
 * @return list<string>
 */
function webco_stripe_catalog_session_problems(
    ?array $fields,
    array $combination,
    array $priceIds
): array {
    $label = (string) ($combination['label'] ?? 'combination');
    if (!is_array($fields)) {
        return [$label . ': checkout session fields could not be built'];
    }

    $problems = [];
    $websiteKey = (string) ($combination['website_key'] ?? '');
    $recurringKey = (string) ($combination['recurring_key'] ?? '');
    $website = $priceIds[$websiteKey] ?? '';
    $recurring = $priceIds[$recurringKey] ?? '';

    if (($fields['mode'] ?? null) !== 'subscription') {
        $problems[] = $label . ': mode must be subscription';
    }
    if (($fields['allow_promotion_codes'] ?? null) !== 'true') {
        $problems[] = $label . ': allow_promotion_codes must be true';
    }
    if (
        array_key_exists('discounts[0][coupon]', $fields)
        || array_key_exists('discounts[0][promotion_code]', $fields)
    ) {
        $problems[] = $label . ': automatic discounts must not be set';
    }
    if (($fields['line_items[0][price]'] ?? null) !== $website) {
        $problems[] = $label . ': website Price ID mismatch';
    }
    if (($fields['line_items[1][price]'] ?? null) !== $recurring) {
        $problems[] = $label . ': recurring Price ID mismatch';
    }
    if (($fields['line_items[0][quantity]'] ?? null) !== '1' || ($fields['line_items[1][quantity]'] ?? null) !== '1') {
        $problems[] = $label . ': line item quantities must be 1';
    }

    $trial = (string) ($combination['trial'] ?? '');
    if ($trial === 'days30') {
        if (($fields['subscription_data[trial_period_days]'] ?? null) !== '30') {
            $problems[] = $label . ': Managed Care must use a 30-day trial';
        }
        if (array_key_exists('subscription_data[trial_end]', $fields)) {
            $problems[] = $label . ': Managed Care must not set trial_end';
        }
    } elseif ($trial === 'year') {
        // Match webco_stripe_checkout_session_fields(), which stamps trial_end from time().
        $expected = (string) webco_stripe_calendar_year_timestamp(time());
        if (($fields['subscription_data[trial_end]'] ?? null) !== $expected) {
            $problems[] = $label . ': annual hosting trial_end must be one calendar year ahead';
        }
        if (array_key_exists('subscription_data[trial_period_days]', $fields)) {
            $problems[] = $label . ': annual hosting must not set trial_period_days';
        }
    } else {
        $problems[] = $label . ': unknown trial rule';
    }

    return $problems;
}

/**
 * @param callable(string): ?array $getJson path => decoded Stripe object body
 * @return array{
 *   ok: bool,
 *   secret_mode: 'live'|'test'|'invalid',
 *   prices: list<array<string, mixed>>,
 *   combinations: list<array<string, mixed>>,
 *   problems: list<string>
 * }
 */
function webco_stripe_catalog_run(callable $getJson, ?string $secret): array
{
    $problems = [];
    $livemode = webco_stripe_catalog_secret_livemode($secret);
    $secretMode = $livemode === 1 ? 'live' : ($livemode === 0 ? 'test' : 'invalid');
    if ($livemode === null) {
        return [
            'ok' => false,
            'secret_mode' => $secretMode,
            'prices' => [],
            'combinations' => [],
            'problems' => ['Stripe secret key is missing or not sk_test_/sk_live_'],
        ];
    }

    $definitions = webco_stripe_catalog_definitions();
    $priceIds = [];
    $priceRows = [];
    $productIds = [];

    foreach ($definitions as $key => $definition) {
        $priceId = webco_stripe_price_id((string) $definition['secret']);
        if ($priceId === null) {
            $problems[] = $definition['label'] . ': secret ' . $definition['secret'] . ' is missing or invalid';
            $priceRows[] = [
                'key' => $key,
                'label' => $definition['label'],
                'configured' => false,
                'price_id' => null,
                'product_id' => null,
                'product_name' => null,
            ];
            continue;
        }
        $priceIds[$key] = $priceId;
        $price = $getJson('/v1/prices/' . rawurlencode($priceId));
        if (!is_array($price)) {
            $problems[] = $definition['label'] . ': Stripe Price could not be read';
            $priceRows[] = [
                'key' => $key,
                'label' => $definition['label'],
                'configured' => true,
                'price_id' => $priceId,
                'product_id' => null,
                'product_name' => null,
            ];
            continue;
        }

        $productId = $price['product'] ?? null;
        $product = null;
        if (is_string($productId) && preg_match('/^prod_[A-Za-z0-9]{8,80}$/', $productId) === 1) {
            $product = $getJson('/v1/products/' . rawurlencode($productId));
            if (is_array($product)) {
                $productIds[$key] = $productId;
            } else {
                $problems[] = $definition['label'] . ': Stripe Product could not be read';
                $product = null;
            }
        } else {
            $problems[] = $definition['label'] . ': Price has no usable product id';
            $productId = null;
        }

        $priceProblems = webco_stripe_catalog_price_problems($price, $definition, $product, $livemode);
        foreach ($priceProblems as $problem) {
            $problems[] = $problem;
        }

        $priceRows[] = [
            'key' => $key,
            'label' => $definition['label'],
            'configured' => true,
            'price_id' => $priceId,
            'product_id' => is_string($productId) ? $productId : null,
            'product_name' => is_array($product) ? (string) ($product['name'] ?? '') : null,
            'type' => $price['type'] ?? null,
            'unit_amount' => $price['unit_amount'] ?? null,
            'currency' => $price['currency'] ?? null,
            'interval' => is_array($price['recurring'] ?? null) ? ($price['recurring']['interval'] ?? null) : null,
            'livemode' => $price['livemode'] ?? null,
            'active' => $price['active'] ?? null,
        ];
    }

    if (count($productIds) === count($definitions) && count(array_unique(array_values($productIds))) !== count($productIds)) {
        $problems[] = 'configured Prices must each belong to a distinct Stripe Product';
    }

    $comboRows = [];
    foreach (webco_stripe_catalog_combinations() as $combination) {
        $fields = webco_stripe_checkout_session_fields([
            'package_code' => $combination['package_code'],
            'care_choice' => $combination['care_choice'],
            'email' => 'catalog-check@webcocloud.net',
        ], 'wc_' . str_repeat('c', 20));
        $comboProblems = webco_stripe_catalog_session_problems($fields, $combination, $priceIds);
        foreach ($comboProblems as $problem) {
            $problems[] = $problem;
        }
        $comboRows[] = [
            'label' => $combination['label'],
            'ok' => $comboProblems === [],
            'trial' => $combination['trial'],
            'allow_promotion_codes' => is_array($fields) ? ($fields['allow_promotion_codes'] ?? null) : null,
        ];
    }

    return [
        'ok' => $problems === [],
        'secret_mode' => $secretMode,
        'prices' => $priceRows,
        'combinations' => $comboRows,
        'problems' => $problems,
    ];
}

/**
 * @param array<string, mixed> $report
 */
function webco_stripe_catalog_report_text(array $report): string
{
    $lines = [
        'mode: stripe-catalog-check',
        'secret_mode: ' . (string) ($report['secret_mode'] ?? 'invalid'),
        'ok: ' . ((($report['ok'] ?? false) === true) ? 'yes' : 'no'),
    ];

    foreach ($report['prices'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lines[] = 'price: ' . (string) ($row['label'] ?? '');
        $lines[] = '  configured: ' . ((($row['configured'] ?? false) === true) ? 'yes' : 'no');
        if (($row['configured'] ?? false) === true) {
            $lines[] = '  type: ' . (string) ($row['type'] ?? '');
            $lines[] = '  unit_amount: ' . (string) ($row['unit_amount'] ?? '');
            $lines[] = '  currency: ' . (string) ($row['currency'] ?? '');
            if (($row['interval'] ?? null) !== null) {
                $lines[] = '  interval: ' . (string) $row['interval'];
            }
            $lines[] = '  active: ' . ((($row['active'] ?? null) === true) ? 'yes' : 'no');
            $lines[] = '  livemode: ' . ((($row['livemode'] ?? null) === true) ? 'yes' : 'no');
            $lines[] = '  product_name: ' . (string) ($row['product_name'] ?? '');
        }
    }

    foreach ($report['combinations'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lines[] = 'combination: ' . (string) ($row['label'] ?? '');
        $lines[] = '  payload: ' . ((($row['ok'] ?? false) === true) ? 'ok' : 'failed');
        $lines[] = '  trial: ' . (string) ($row['trial'] ?? '');
        $lines[] = '  allow_promotion_codes: ' . (string) ($row['allow_promotion_codes'] ?? '');
    }

    $problems = $report['problems'] ?? [];
    if (is_array($problems) && $problems !== []) {
        $lines[] = 'problems:';
        foreach ($problems as $problem) {
            if (is_string($problem) && $problem !== '') {
                $lines[] = '  - ' . $problem;
            }
        }
    }

    return implode("\n", $lines) . "\n";
}
