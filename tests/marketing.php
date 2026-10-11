<?php
/**
 * Marketing daily totals: validation, aggregation and the admin overview.
 * Run with: php tests/marketing.php
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/lib/marketing.php';

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

$sql = webco_marketing_daily_sql();
check(str_contains($sql, 'CREATE TABLE IF NOT EXISTS marketing_daily'), 'schema creates marketing_daily if missing');
check(str_contains($sql, 'PRIMARY KEY (day, site, path_key, source, event_type)'), 'schema aggregates on the daily key');
check(stripos($sql, 'ip') === false, 'schema has no IP column');
check(!str_contains($sql, 'user_agent') && !str_contains($sql, 'visitor'), 'schema has no visitor identifier');

check(webco_marketing_site_for_origin('https://webco-essential.co.uk') === 'essential', 'essential demo origin is accepted');
check(webco_marketing_site_for_origin('https://www.webco-professional.co.uk') === 'professional', 'professional www origin is accepted');
check(webco_marketing_site_for_origin('https://webcocloud.net') === 'cloud', 'Webco Cloud origin is accepted');
check(webco_marketing_site_for_origin('https://webcocloud.net.evil') === null, 'a lookalike origin is rejected');
check(webco_marketing_site_for_origin('http://webco-essential.co.uk') === null, 'plain HTTP origins are rejected');
check(webco_marketing_normal_path('/Start/?package=essential') === null, 'a path cannot carry a query');
check(webco_marketing_normal_path('/web-design/hgv-driver-training/') === '/web-design/hgv-driver-training', 'the HGV path is normalised');
check(webco_marketing_path_key('essential', '/', 'page_view', '') === 'all', 'demo page views share one daily bucket');
check(webco_marketing_path_key('professional', '/contact', 'cta_click', '') === 'cta', 'demo purchase clicks share one bucket');
check(webco_marketing_path_key('cloud', '/web-design/hgv-driver-training', 'page_view', '') === 'hgv', 'HGV views are bucketed');
check(webco_marketing_path_key('cloud', '/start', 'page_view', 'essential') === 'start-essential', 'essential onboarding is bucketed');
check(webco_marketing_path_key('cloud', '/start/checkout', 'page_view', 'essential') === null, 'checkout is not counted');
check(webco_marketing_path_key('cloud', '/start', 'cta_click', '') === null, 'cloud does not count demo purchase clicks');

$browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';
check(!webco_marketing_bot_user_agent($browser), 'a normal browser is counted');
check(webco_marketing_bot_user_agent('Mozilla/5.0 (compatible; Googlebot/2.1)'), 'a crawler is ignored');
check(webco_marketing_bot_user_agent('curl/8.0'), 'a non-browser client is ignored');
check(webco_marketing_skip_request($browser, ['gpc' => '1']), 'Global Privacy Control skips counting');
check(webco_marketing_skip_request($browser, ['purpose' => 'prefetch']), 'prefetch requests are ignored');

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-mkt-test-' . getmypid();
$now = 1_780_000_000;
check(webco_marketing_rate_allow('203.0.113.10', $now, $dir, 2, 3), 'the first count in a minute is allowed');
check(webco_marketing_rate_allow('203.0.113.10', $now, $dir, 2, 3), 'the second count in a minute is allowed');
check(!webco_marketing_rate_allow('203.0.113.10', $now, $dir, 2, 3), 'a further count in the same minute is limited');
check(webco_marketing_rate_allow('203.0.113.11', $now, $dir, 2, 3), 'a different address has its own limit');
$stored = (string) file_get_contents($dir . DIRECTORY_SEPARATOR . (string) webco_marketing_client_key('203.0.113.10'));
check(!str_contains($stored, '203.0.113.10'), 'the rate file does not contain the address');
check(webco_marketing_client_key('203.0.113.10') !== webco_marketing_client_key('203.0.113.11'), 'rate keys differ by address');

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE marketing_daily (
        day TEXT NOT NULL,
        site TEXT NOT NULL,
        path_key TEXT NOT NULL,
        source TEXT NOT NULL,
        event_type TEXT NOT NULL,
        hits INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (day, site, path_key, source, event_type)
    )'
);

$rateDir = $dir . '-accept';
$body = json_encode([
    'events' => ['page_view', 'visit'],
    'path' => '/',
    'source' => 'mail',
    'package' => '',
]);
check(
    webco_marketing_accept($db, 'https://webco-essential.co.uk', (string) $body, $browser, '203.0.113.20', $now, $rateDir, [], false) === 'stored',
    'an essential demo page view and estimated visit are stored'
);
check(
    webco_marketing_accept($db, 'https://webco-essential.co.uk', (string) $body, $browser, '203.0.113.21', $now, $rateDir, [], false) === 'stored',
    'a second view increments the same daily row'
);
$click = json_encode([
    'events' => ['cta_click'],
    'path' => '/courses/',
    'source' => 'mail',
    'package' => '',
]);
check(
    webco_marketing_accept($db, 'https://www.webco-professional.co.uk', (string) $click, $browser, '203.0.113.22', $now, $rateDir, [], false) === 'stored',
    'a professional purchase click is stored'
);
$hgv = json_encode([
    'events' => ['page_view', 'visit'],
    'path' => '/web-design/hgv-driver-training/',
    'source' => 'search',
    'package' => '',
]);
check(
    webco_marketing_accept($db, 'https://webcocloud.net', (string) $hgv, $browser, '203.0.113.23', $now, $rateDir, [], false) === 'stored',
    'an HGV landing view is stored'
);
$start = json_encode([
    'events' => ['page_view'],
    'path' => '/start/',
    'source' => 'referral',
    'package' => 'professional',
]);
check(
    webco_marketing_accept($db, 'https://webcocloud.net', (string) $start, $browser, '203.0.113.24', $now, $rateDir, [], false) === 'stored',
    'a professional onboarding entry is stored'
);
$checkout = json_encode([
    'events' => ['page_view'],
    'path' => '/start/checkout/',
    'source' => 'direct',
    'package' => 'essential',
]);
check(
    webco_marketing_accept($db, 'https://webcocloud.net', (string) $checkout, $browser, '203.0.113.25', $now, $rateDir, [], false) === 'dropped',
    'checkout is dropped'
);
check(
    webco_marketing_accept($db, 'https://example.com', (string) $body, $browser, '203.0.113.26', $now, $rateDir, [], false) === 'dropped',
    'an unknown origin is dropped'
);
check(
    webco_marketing_accept($db, 'https://webco-essential.co.uk', (string) $body, 'Mozilla/5.0 Googlebot', '203.0.113.27', $now, $rateDir, [], false) === 'dropped',
    'a crawler is dropped before a write'
);
$badPackage = json_encode([
    'events' => ['page_view'],
    'path' => '/start/',
    'source' => 'direct',
    'package' => 'enterprise',
]);
check(
    webco_marketing_accept($db, 'https://webcocloud.net', (string) $badPackage, $browser, '203.0.113.28', $now, $rateDir, [], false) === 'dropped',
    'an unknown package is dropped'
);

$rows = webco_marketing_rows($db, '2020-01-01');
check(is_array($rows), 'stored rows can be read');
$essentialViews = 0;
foreach ($rows as $row) {
    check(!str_contains(json_encode($row), '203.0.113'), 'a stored row has no address');
    if ($row['site'] === 'essential' && $row['event_type'] === 'page_view') {
        $essentialViews = $row['hits'];
        check($row['path_key'] === 'all' && $row['source'] === 'mail', 'essential views keep the mail source bucket');
    }
}
check($essentialViews === 2, 'two essential views share one counter');

$day = webco_marketing_london_day($now);
$overview = webco_marketing_overview($rows ?? [], $day, 28);
check($overview['totals']['essential_views'] === 2, 'the overview totals essential page views');
check($overview['totals']['essential_visits'] === 2, 'the overview totals estimated visits');
check($overview['totals']['professional_clicks'] === 1, 'the overview totals professional purchase clicks');
check($overview['totals']['hgv_views'] === 1, 'the overview totals HGV landing views');
check($overview['totals']['start_professional'] === 1, 'the overview totals professional onboarding');
check($overview['totals']['start_entries'] === 1, 'onboarding entries include the package choice');
$mail = $overview['sources'][1];
check($mail['source'] === 'mail' && $mail['views'] === 2 && $mail['clicks'] === 1, 'direct mail is a source category');
$html = webco_marketing_overview_html($overview);
check(str_contains($html, 'Estimated visits'), 'the overview names estimated visits');
check(str_contains($html, 'Est. visits'), 'the daily trend names estimated visits');
check(str_contains($html, 'Direct mail'), 'the overview names the direct-mail source');
check(!str_contains($html, '203.0.113'), 'the overview does not show an address');
check(str_contains($html, 'Demo page views') && str_contains($html, 'Estimated demo visits'), 'the dashboard shows summary cards');
check(str_contains($html, 'Essential demo') && str_contains($html, 'Professional demo'), 'the dashboard compares both demo sites');
check(!str_contains($html, '£'), 'the dashboard shows no package prices');
check(str_contains($html, 'Demo page views, last 7 days') && str_contains($html, '<details'), 'the week chart and collapsible 28-day table are present');
check(!str_contains($html, '<script'), 'the dashboard uses no script');
$empty = webco_marketing_overview_html(webco_marketing_overview([], $day, 28));
check(str_contains($empty, 'No activity recorded yet'), 'an empty period shows a message');
check(!str_contains($empty, '<table'), 'an empty period shows no table of zeros');

$schema = (string) file_get_contents(dirname(__DIR__) . '/bin/provision-schema.php');
check(str_contains($schema, 'webco_ensure_marketing_daily'), 'schema migration ensures the marketing table');

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
