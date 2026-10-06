<?php
/**
 * Offline cleanup-audit checks. No production database and no DELETE.
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

require dirname(__DIR__) . '/public/lib/cleanup-audit.php';

$knownOrders = webco_cleanup_known_order_public_ids();
$knownProjects = webco_cleanup_known_project_ids();
$knownDomains = webco_cleanup_known_test_domains();
check(in_array('wc_27155bed5bfe1eea3391', $knownOrders, true), 'live smoke order wc_27155bed is allowlisted');
check(in_array('wc_803ee94b9c7316b806d3', $knownOrders, true), 'live smoke order wc_803ee94b is allowlisted');
check(in_array(10, $knownProjects, true) && in_array(11, $knownProjects, true), 'projects 10 and 11 are allowlisted');
check(in_array('pandahugs.uk', $knownDomains, true), 'pandahugs.uk is on the manual test domain allowlist');
check(in_array('fotojuice.com', $knownDomains, true), 'fotojuice.com is on the manual test domain allowlist');
check(in_array('how-much-more-huh.co.uk', $knownDomains, true), 'how-much-more-huh.co.uk is on the manual test domain allowlist');
check(
    !in_array('totally-made-up-not-listed.test', $knownDomains, true),
    'domains are not inferred; only explicit allowlist entries count'
);

$manualDomain = webco_cleanup_order_reasons(
    'wc_' . str_repeat('f', 20),
    null,
    'bumblebee.co.uk',
    'Bee',
    'bee@bumblebee.co.uk',
    1,
    null
);
check(
    $manualDomain === ['manual_test_domain_allowlist'],
    'an allowlisted historical test domain becomes a candidate without other signals'
);

$liveReasons = webco_cleanup_order_reasons(
    'wc_27155bed5bfe1eea3391',
    11,
    'pandahugs.uk',
    'Panda Hugs',
    'owner@pandahugs.uk',
    1,
    null
);
check(in_array('known_test_order_public_id', $liveReasons, true), 'allowlisted live smoke order is identified');
check(
    webco_cleanup_stripe_class(1, $liveReasons) === 'stripe_live_smoke_test',
    'allowlisted livemode=1 orders are classed as live smoke tests'
);

check(
    webco_cleanup_hosting_decision_for_package('3943463') === 'hosting_should_remain',
    'pandahugs package 3943463 must keep hosting'
);
check(
    webco_cleanup_hosting_decision_for_package('3943689') === 'safe_candidate_for_deleting_hosting',
    'concretejunkie package 3943689 is approved for hosting-only delete'
);
check(
    webco_cleanup_hosting_decision_for_package('3940479') === 'safe_candidate_for_deleting_hosting',
    'sexyunderneath package 3940479 is approved for hosting-only delete'
);
check(
    !in_array('3943463', webco_cleanup_hosting_delete_safe_package_ids(), true),
    'pandahugs package 3943463 is never on the hosting-delete allowlist'
);

$testReasons = webco_cleanup_order_reasons(
    'wc_' . str_repeat('a', 20),
    null,
    'customer.example',
    'Real Biz',
    'ada@customer.example',
    0,
    null
);
check($testReasons === ['stripe_test_mode'], 'stripe_livemode=0 alone is enough to identify a test order');
check(webco_cleanup_stripe_class(0, $testReasons) === 'stripe_test_mode', 'livemode=0 is stripe_test_mode');

$keepReasons = webco_cleanup_order_reasons(
    'wc_' . str_repeat('b', 20),
    99,
    'real-customer.co.uk',
    'Real Customer Ltd',
    'hello@real-customer.co.uk',
    1,
    '3949999'
);
check($keepReasons === [], 'an unknown live customer order is not identified as test data');
check(
    webco_cleanup_stripe_class(1, $keepReasons) === 'stripe_live',
    'unknown livemode=1 orders stay classed as ordinary live'
);

$marker = webco_cleanup_order_reasons(
    'wc_' . str_repeat('c', 20),
    null,
    'demo.example',
    '[TEST] Demo School',
    'demo@example.com',
    null,
    null
);
check(in_array('admin_test_marker', $marker, true), 'admin [TEST]/example.com markers are identified');

$liveBundle = webco_cleanup_classify_bundle(
    [
        'id' => 20,
        'public_id' => 'wc_803ee94b9c7316b806d3',
        'domain_name' => 'concretejunkie.co.uk',
        'business_name' => 'Concrete',
        'email' => 'a@b.co.uk',
        'stripe_livemode' => 1,
        'status' => 'paid',
    ],
    [
        'id' => 12,
        'twentyi_package_id' => '3943689',
        'provisioning_status' => 'provisioned',
    ],
    ['briefs' => 1, 'assets' => 0, 'requests' => 0]
);
check(($liveBundle['decision'] ?? '') === 'candidate', 'project 12 live smoke test is a cleanup candidate');
check(($liveBundle['stripe_class'] ?? '') === 'stripe_live_smoke_test', 'project 12 is labelled live smoke test');
check(
    str_contains(implode(' ', $liveBundle['notes'] ?? []), '3943689'),
    'a stored 20i package id is noted on the candidate'
);
check(
    str_contains(implode(' ', $liveBundle['notes'] ?? []), 'hosting_decision:safe_candidate_for_deleting_hosting'),
    'project 12 hosting is marked safe_candidate_for_deleting_hosting'
);

$keepHostingBundle = webco_cleanup_classify_bundle(
    [
        'id' => 10,
        'public_id' => 'wc_59aaa75a2a3a4133c749',
        'domain_name' => 'pandahugs.uk',
        'business_name' => 'Panda',
        'email' => 'p@pandahugs.uk',
        'stripe_livemode' => 1,
        'status' => 'paid',
    ],
    [
        'id' => 10,
        'twentyi_package_id' => '3943463',
        'provisioning_status' => 'provisioned',
    ],
    ['briefs' => 0, 'assets' => 0, 'requests' => 0]
);
check(
    str_contains(implode(' ', $keepHostingBundle['notes'] ?? []), 'hosting_decision:hosting_should_remain'),
    'project 10 hosting is marked hosting_should_remain'
);

$keepBundle = webco_cleanup_classify_bundle(
    [
        'id' => 100,
        'public_id' => 'wc_' . str_repeat('d', 20),
        'domain_name' => 'genuine-client.co.uk',
        'business_name' => 'Genuine Client',
        'email' => 'hi@genuine-client.co.uk',
        'stripe_livemode' => 1,
        'status' => 'paid',
    ],
    [
        'id' => 100,
        'twentyi_package_id' => '4000001',
        'provisioning_status' => 'provisioned',
    ],
    ['briefs' => 1, 'assets' => 2, 'requests' => 1]
);
check(($keepBundle['decision'] ?? '') === 'keep', 'an unidentified live customer is kept');
check(
    in_array('not_positively_identified_as_test_data', $keepBundle['keep_reasons'] ?? [], true),
    'kept rows explain that identification was not positive'
);

$cli = webco_cleanup_cli_options(['bin/cleanup-test-data.php']);
check(($cli['ok'] ?? false) === true && ($cli['apply'] ?? true) === false, 'default CLI mode is preview');
$applyOnly = webco_cleanup_cli_options(['bin/cleanup-test-data.php', '--apply']);
check(($applyOnly['ok'] ?? true) === false, '--apply without --confirm-candidates is refused');
$apply = webco_cleanup_cli_options(['bin/cleanup-test-data.php', '--apply', '--confirm-candidates=28']);
check(
    ($apply['ok'] ?? false) === true
        && ($apply['apply'] ?? false) === true
        && ($apply['confirm_candidates'] ?? null) === 28,
    '--apply with --confirm-candidates is accepted'
);
$bad = webco_cleanup_cli_options(['bin/cleanup-test-data.php', '--delete-all']);
check(($bad['ok'] ?? true) === false, 'unknown cleanup flags are refused');

$bin = (string) file_get_contents(dirname(__DIR__) . '/bin/cleanup-test-data.php');
check(str_contains($bin, 'webco_cleanup_apply'), 'the CLI wires the guarded apply path');
check(str_contains($bin, 'Never calls Stripe or 20i'), 'the DB cleanup CLI documents no Stripe/20i calls');

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-cleanup-audit-' . getmypid() . '.sqlite';
if (is_file($path)) {
    unlink($path);
}
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE orders (
    id INTEGER PRIMARY KEY,
    public_id TEXT,
    status TEXT,
    package_code TEXT,
    care_choice TEXT,
    domain_path TEXT,
    domain_name TEXT,
    business_name TEXT,
    contact_name TEXT,
    email TEXT,
    stripe_livemode INTEGER,
    stripe_checkout_session_id TEXT,
    stripe_customer_id TEXT,
    stripe_subscription_id TEXT,
    payment_intent_id TEXT,
    paid_at TEXT,
    created_at TEXT
)');
$db->exec('CREATE TABLE projects (
    id INTEGER PRIMARY KEY,
    order_id INTEGER,
    order_public_id TEXT,
    status TEXT,
    provisioning_status TEXT,
    provisioning_error TEXT,
    twentyi_package_id TEXT,
    domain_registered_at TEXT,
    provisioned_at TEXT,
    archived_at TEXT,
    created_at TEXT
)');
$db->exec('CREATE TABLE project_briefs (project_id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE project_assets (id INTEGER PRIMARY KEY, project_id INTEGER)');
$db->exec('CREATE TABLE project_requests (id INTEGER PRIMARY KEY, project_id INTEGER)');
$db->exec('CREATE TABLE stripe_events (stripe_event_id TEXT PRIMARY KEY)');

$db->exec(
    "INSERT INTO orders (
        id, public_id, status, package_code, care_choice, domain_path, domain_name,
        business_name, contact_name, email, stripe_livemode, created_at
     ) VALUES
     (10, 'wc_59aaa75a2a3a4133c749', 'paid', 'essential', 'standard', 'existing', 'pandahugs.uk',
      'Panda', 'Pat', 'pat@pandahugs.uk', 1, '2026-01-01'),
     (11, 'wc_27155bed5bfe1eea3391', 'paid', 'essential', 'standard', 'existing', 'pandahugs.uk',
      'Smoke', 'Sam', 'sam@example.net', 1, '2026-01-02'),
     (100, 'wc_" . str_repeat('e', 20) . "', 'paid', 'essential', 'standard', 'existing', 'real-client.co.uk',
      'Real Client', 'Ri', 'hi@real-client.co.uk', 1, '2026-01-03'),
     (7, 'wc_aaaa0000000000000001', 'paid', 'essential', 'standard', 'existing', 'demo.test',
      'Demo', 'Dee', 'dee@demo.test', 0, '2026-01-04')"
);
$db->exec(
    "INSERT INTO projects (
        id, order_id, order_public_id, status, provisioning_status, twentyi_package_id, created_at
     ) VALUES
     (10, 10, 'wc_59aaa75a2a3a4133c749', 'awaiting_brief', 'provisioned', '3943463', '2026-01-01'),
     (11, 11, 'wc_27155bed5bfe1eea3391', 'awaiting_brief', 'failed', NULL, '2026-01-02'),
     (100, 100, 'wc_" . str_repeat('e', 20) . "', 'awaiting_brief', 'provisioned', '4000001', '2026-01-03')"
);
$db->exec('INSERT INTO project_briefs (project_id) VALUES (10), (11), (100)');
$db->exec('INSERT INTO project_assets (id, project_id) VALUES (1, 10)');
$db->exec("INSERT INTO stripe_events (stripe_event_id) VALUES ('evt_keep_1'), ('evt_keep_2')");

$audit = webco_cleanup_audit($db);
check(($audit['ok'] ?? false) === true, 'sqlite cleanup audit completes');
check(($audit['summary']['candidates'] ?? 0) === 3, 'allowlisted and stripe-test orders become candidates');
check(($audit['summary']['kept'] ?? 0) === 1, 'the unidentified live customer is kept');
check(($audit['summary']['candidate_stripe_live_smoke_test'] ?? 0) === 2, 'two live smoke-test candidates are counted');
check(($audit['summary']['candidate_stripe_test_mode'] ?? 0) === 1, 'one stripe test-mode candidate is counted');
check(($audit['stripe_events']['count'] ?? 0) === 2, 'stripe_events are counted');
check(($audit['summary']['hosting_keep'] ?? 0) === 1, 'one candidate package is marked hosting_should_remain');
check(($audit['summary']['hosting_manual'] ?? 0) === 0, 'sqlite fixture has no other packaged candidates needing a hosting decision');
$text = webco_cleanup_audit_text($audit);
check(str_contains($text, 'decision: keep_all'), 'stripe_events stay keep_all in the report');
check(str_contains($text, 'manual_test_domain_allowlist:'), 'the preview prints the manual domain allowlist');
check(str_contains($text, 'hosting_should_remain:'), 'the preview groups 20i packages that must remain');
check(str_contains($text, '3943463'), 'package 3943463 appears under hosting decisions');
check(str_contains($text, 'wc_27155bed5bfe1eea3391'), 'the live smoke order appears in the preview text');
check(str_contains($text, 'real-client.co.uk'), 'kept live customers remain visible in the report');
check(str_contains($text, 'destructive: no'), 'the report states the run is non-destructive');

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-cleanup-files-' . getmypid();
foreach (['wc_59aaa75a2a3a4133c749', 'wc_27155bed5bfe1eea3391', 'wc_aaaa0000000000000001', 'wc_' . str_repeat('e', 20)] as $folder) {
    foreach (['logos', 'photos', 'documents'] as $kind) {
        mkdir($root . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $kind, 0700, true);
    }
}
file_put_contents($root . DIRECTORY_SEPARATOR . 'wc_' . str_repeat('e', 20) . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR . 'keep.png', 'keep');

$wrongCount = webco_cleanup_apply($db, 99, $root);
check(($wrongCount['ok'] ?? true) === false, 'apply refuses when confirm count does not match');
check($db->query('SELECT COUNT(*) FROM orders')->fetchColumn() == 4, 'a refused apply leaves all orders in place');

$applied = webco_cleanup_apply($db, 3, $root);
check(($applied['ok'] ?? false) === true, 'apply succeeds for the confirmed candidate count');
check(count($applied['deleted_orders'] ?? []) === 3, 'apply deletes exactly the candidate orders');
check(($applied['kept_stripe_events'] ?? 0) === 2, 'apply keeps every stripe_events row');
check($db->query('SELECT COUNT(*) FROM orders')->fetchColumn() == 1, 'only the unidentified live order remains');
check($db->query('SELECT COUNT(*) FROM projects')->fetchColumn() == 1, 'only the real-client project remains');
check($db->query('SELECT COUNT(*) FROM stripe_events')->fetchColumn() == 2, 'stripe_events rows remain after apply');
check(
    is_file($root . DIRECTORY_SEPARATOR . 'wc_' . str_repeat('e', 20) . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR . 'keep.png'),
    'non-candidate upload files remain'
);
check(!is_dir($root . DIRECTORY_SEPARATOR . 'wc_27155bed5bfe1eea3391'), 'candidate upload folders are removed');

require dirname(__DIR__) . '/public/lib/cleanup-hosting.php';
$hostPreview = webco_cleanup_hosting_run(
    false,
    static function (): array {
        return [
            'ok' => true,
            'rows' => [
                ['id' => '3940479', 'name' => 'sexyunderneath.com', 'names' => ['sexyunderneath.com']],
                ['id' => '3940545', 'name' => 'jetfunnels.com', 'names' => ['jetfunnels.com']],
                ['id' => '3943689', 'name' => 'concretejunkie.co.uk', 'names' => ['concretejunkie.co.uk']],
                ['id' => '3943463', 'name' => 'pandahugs.uk', 'names' => ['pandahugs.uk']],
            ],
        ];
    },
    static function (string $id): array {
        return ['ok' => false, 'package_id' => $id, 'failure' => 'should_not_run', 'status' => 0];
    }
);
check(($hostPreview['ok'] ?? false) === true, 'hosting preview is ok for the three approved packages');
check(($hostPreview['deleted'] ?? ['x']) === [], 'hosting preview does not delete');
$calls = [];
$hostApply = webco_cleanup_hosting_run(
    true,
    static function (): array {
        return [
            'ok' => true,
            'rows' => [
                ['id' => '3940479', 'name' => 'sexyunderneath.com', 'names' => ['sexyunderneath.com']],
                ['id' => '3940545', 'name' => 'jetfunnels.com', 'names' => ['jetfunnels.com']],
                ['id' => '3943689', 'name' => 'concretejunkie.co.uk', 'names' => ['concretejunkie.co.uk']],
                ['id' => '3943463', 'name' => 'pandahugs.uk', 'names' => ['pandahugs.uk']],
            ],
        ];
    },
    static function (string $id) use (&$calls): array {
        $calls[] = $id;
        return ['ok' => true, 'package_id' => $id, 'failure' => '', 'status' => 200];
    }
);
check(($hostApply['ok'] ?? false) === true, 'hosting apply succeeds for the three approved packages');
check($calls === ['3940479', '3940545', '3943689'], 'hosting apply deletes only the three approved package ids');
check(!in_array('3943463', $calls, true), 'hosting apply never deletes pandahugs package 3943463');
$mismatch = webco_cleanup_hosting_run(
    true,
    static function (): array {
        return [
            'ok' => true,
            'rows' => [
                ['id' => '3940479', 'name' => 'wrong.example', 'names' => ['wrong.example']],
            ],
        ];
    },
    static function (string $id): array {
        return ['ok' => true, 'package_id' => $id, 'failure' => '', 'status' => 200];
    }
);
check(($mismatch['ok'] ?? true) === false, 'hosting apply refuses a domain mismatch');
check(
    webco_twentyi_delete_web_payload('3943463') !== null
        && (webco_twentyi_delete_web_payload('3940479')['delete-id'][0] ?? '') === '3940479',
    'deleteWeb payload carries a single package id'
);
$hostBin = (string) file_get_contents(dirname(__DIR__) . '/bin/cleanup-hosting-packages.php');
check(str_contains($hostBin, 'Never touches package 3943463'), 'hosting CLI documents pandahugs keep');
check(str_contains($hostBin, 'deleteWeb') || str_contains($hostBin, 'DELETE_WEB') || str_contains($hostBin, 'webco_twentyi_delete_hosting_package'), 'hosting CLI uses deleteWeb hosting deletion');
check(!str_contains($hostBin, 'addDomain'), 'hosting CLI does not register or alter domains');

@unlink($path);
if (is_dir($root)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($root);
}

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
