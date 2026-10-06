<?php
/**
 * Read-only audit of Webco Cloud test/demo customer data.
 *
 * Lists candidate orders/projects and dependent rows. Does not DELETE or
 * UPDATE. Does not call Stripe, 20i, or touch upload files.
 */

declare(strict_types=1);

$webcoCleanupAuditScript = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
if (str_ends_with($webcoCleanupAuditScript, '/lib/cleanup-audit.php')) {
    http_response_code(404);
    exit;
}
unset($webcoCleanupAuditScript);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/projects.php';

/**
 * Explicitly named live/test smoke orders from the finished live-testing pass.
 *
 * @return list<string>
 */
function webco_cleanup_known_order_public_ids(): array
{
    return [
        'wc_4af19bd87fd46dc2774b',
        'wc_f1e77242e3b57309131b',
        'wc_cdb8e67978e2792fd8e2',
        'wc_55008be88aea588ef0cf',
        'wc_59aaa75a2a3a4133c749',
        'wc_27155bed5bfe1eea3391',
        'wc_803ee94b9c7316b806d3',
    ];
}

/**
 * Explicitly named projects from the finished live-testing pass.
 *
 * @return list<int>
 */
function webco_cleanup_known_project_ids(): array
{
    return [4, 6, 7, 8, 9, 10, 11, 12];
}

/**
 * Explicit manual TEST domain allowlist. Domains are never inferred from
 * “looks fake”; only names listed here (plus other positive rules) qualify.
 *
 * @return list<string>
 */
function webco_cleanup_known_test_domains(): array
{
    return [
        'i-need-beer-now.com',
        'i-hate-terminals.co.uk',
        'i-really-hate-terminals.co.uk',
        'pandahugs.uk',
        'concretejunkie.co.uk',
        'fotojuice.com',
        'bumblebee.co.uk',
        'bubbly.com',
        'sillylittledomaintest.com',
        'herewegoagainwoo.com',
        'woopywoopywam.com',
        'wookoowaa.com',
        'wookiewookiewaa.com',
        'heeeeehaaa.com',
        'hfhfhfhfhfh.co.uk',
        'how-much-more-huh.co.uk',
    ];
}

/**
 * 20i package IDs that must keep hosting/domain even if the Webco DB row is
 * later removed. Explicit only — never inferred.
 *
 * @return list<string>
 */
function webco_cleanup_hosting_keep_package_ids(): array
{
    return [
        '3943463', // project 10 / pandahugs.uk — keep 20i package and domain
    ];
}

/**
 * 20i package IDs explicitly confirmed safe to delete hosting for later.
 * Empty until each package is approved one by one.
 *
 * @return list<string>
 */
function webco_cleanup_hosting_delete_safe_package_ids(): array
{
    return [];
}

/**
 * Tables that hold customer/order/project data in this application.
 *
 * @return list<string>
 */
function webco_cleanup_related_tables(): array
{
    return [
        'orders',
        'projects',
        'project_briefs',
        'project_assets',
        'project_requests',
        'stripe_events',
    ];
}

/**
 * @return list<string>
 */
function webco_cleanup_order_reasons(
    string $publicId,
    ?int $projectId,
    string $domainName,
    string $businessName,
    string $email,
    mixed $livemode,
    mixed $packageId
): array {
    $reasons = [];
    if (in_array($publicId, webco_cleanup_known_order_public_ids(), true)) {
        $reasons[] = 'known_test_order_public_id';
    }
    if ($projectId !== null && in_array($projectId, webco_cleanup_known_project_ids(), true)) {
        $reasons[] = 'known_test_project_id';
    }
    $domain = strtolower(trim($domainName));
    if (in_array($domain, webco_cleanup_known_test_domains(), true)) {
        $reasons[] = 'manual_test_domain_allowlist';
    }
    if ($livemode === 0 || $livemode === '0') {
        $reasons[] = 'stripe_test_mode';
    }
    if (webco_project_test_delete_allowed($businessName, $email, $packageId)) {
        $reasons[] = 'admin_test_marker';
    }

    return $reasons;
}

/**
 * Live smoke tests are livemode=1 and appear on the explicit allowlists.
 *
 * @param list<string> $reasons
 */
function webco_cleanup_package_id(mixed $value): ?string
{
    if (is_int($value)) {
        $value = (string) $value;
    }
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if (!preg_match('/^[1-9][0-9]{0,11}$/', $value)) {
        return null;
    }

    return $value;
}

/**
 * @return 'safe_candidate_for_deleting_hosting'|'hosting_should_remain'|'needs_manual_decision'
 */
function webco_cleanup_hosting_decision_for_package(string $packageId): string
{
    $packageId = trim($packageId);
    if ($packageId === '') {
        return 'needs_manual_decision';
    }
    if (in_array($packageId, webco_cleanup_hosting_keep_package_ids(), true)) {
        return 'hosting_should_remain';
    }
    if (in_array($packageId, webco_cleanup_hosting_delete_safe_package_ids(), true)) {
        return 'safe_candidate_for_deleting_hosting';
    }

    return 'needs_manual_decision';
}

/**
 * Group distinct 20i package IDs found on cleanup candidates.
 *
 * @param list<array<string, mixed>> $candidates
 * @return array{
 *   safe_candidate_for_deleting_hosting: list<array<string, mixed>>,
 *   hosting_should_remain: list<array<string, mixed>>,
 *   needs_manual_decision: list<array<string, mixed>>
 * }
 */
function webco_cleanup_hosting_package_report(array $candidates): array
{
    $groups = [
        'safe_candidate_for_deleting_hosting' => [],
        'hosting_should_remain' => [],
        'needs_manual_decision' => [],
    ];
    $seen = [];

    foreach ($candidates as $bundle) {
        if (!is_array($bundle)) {
            continue;
        }
        $project = is_array($bundle['project'] ?? null) ? $bundle['project'] : null;
        $order = is_array($bundle['order'] ?? null) ? $bundle['order'] : null;
        $packageId = webco_cleanup_package_id($project['twentyi_package_id'] ?? null);
        if ($packageId === null) {
            continue;
        }
        if (isset($seen[$packageId])) {
            continue;
        }
        $seen[$packageId] = true;
        $decision = webco_cleanup_hosting_decision_for_package($packageId);
        $groups[$decision][] = [
            'package_id' => $packageId,
            'project_id' => (int) ($project['id'] ?? 0),
            'order_public_id' => (string) ($order['public_id'] ?? ''),
            'domain' => (string) ($order['domain_name'] ?? ''),
            'decision' => $decision,
        ];
    }

    return $groups;
}

function webco_cleanup_stripe_class(mixed $livemode, array $reasons): string
{
    $isLive = $livemode === 1 || $livemode === '1';
    $isTest = $livemode === 0 || $livemode === '0';
    $allowlisted = in_array('known_test_order_public_id', $reasons, true)
        || in_array('known_test_project_id', $reasons, true)
        || in_array('manual_test_domain_allowlist', $reasons, true);

    if ($isLive && $allowlisted) {
        return 'stripe_live_smoke_test';
    }
    if ($isTest) {
        return 'stripe_test_mode';
    }
    if ($livemode === null || $livemode === '') {
        return 'stripe_livemode_unknown';
    }
    if ($isLive) {
        return 'stripe_live';
    }

    return 'stripe_livemode_unexpected';
}

/**
 * @param array<string, mixed> $order
 * @param array<string, mixed>|null $project
 * @return array{
 *   decision: 'candidate'|'keep',
 *   stripe_class: string,
 *   reasons: list<string>,
 *   keep_reasons: list<string>,
 *   order: array<string, mixed>,
 *   project: ?array<string, mixed>,
 *   dependents: array{briefs: int, assets: int, requests: int},
 *   storage_path: ?string,
 *   notes: list<string>
 * }
 */
function webco_cleanup_classify_bundle(array $order, ?array $project, array $dependents): array
{
    $publicId = (string) ($order['public_id'] ?? '');
    $projectId = $project !== null ? (int) ($project['id'] ?? 0) : null;
    if ($projectId !== null && $projectId < 1) {
        $projectId = null;
    }
    $packageId = $project['twentyi_package_id'] ?? null;
    $reasons = webco_cleanup_order_reasons(
        $publicId,
        $projectId,
        (string) ($order['domain_name'] ?? ''),
        (string) ($order['business_name'] ?? ''),
        (string) ($order['email'] ?? ''),
        $order['stripe_livemode'] ?? null,
        $packageId
    );
    $stripeClass = webco_cleanup_stripe_class($order['stripe_livemode'] ?? null, $reasons);
    $notes = [];
    if (is_string($packageId) && trim($packageId) !== '') {
        $packageId = trim($packageId);
        $hostingDecision = webco_cleanup_hosting_decision_for_package($packageId);
        $notes[] = 'has_twentyi_package_id:' . $packageId;
        $notes[] = 'hosting_decision:' . $hostingDecision;
        if ($hostingDecision === 'hosting_should_remain') {
            $notes[] = '20i package/domain must remain even if this Webco order/project is later deleted';
        } elseif ($hostingDecision === 'safe_candidate_for_deleting_hosting') {
            $notes[] = '20i hosting deletion is explicitly allowlisted for a later step (not performed by this audit)';
        } else {
            $notes[] = '20i hosting needs a manual keep/delete decision before any hosting cleanup';
        }
    }
    if ($stripeClass === 'stripe_live_smoke_test') {
        $notes[] = 'live Stripe smoke-test order; Stripe customer/subscription objects are out of scope';
    }

    $storage = preg_match('/^wc_[a-f0-9]{20}$/', $publicId) === 1
        ? 'webco-projects/' . $publicId . '/ (files not touched by this audit)'
        : null;

    if ($reasons === []) {
        return [
            'decision' => 'keep',
            'stripe_class' => $stripeClass,
            'reasons' => [],
            'keep_reasons' => ['not_positively_identified_as_test_data'],
            'order' => $order,
            'project' => $project,
            'dependents' => $dependents,
            'storage_path' => $storage,
            'notes' => $notes,
        ];
    }

    return [
        'decision' => 'candidate',
        'stripe_class' => $stripeClass,
        'reasons' => $reasons,
        'keep_reasons' => [],
        'order' => $order,
        'project' => $project,
        'dependents' => $dependents,
        'storage_path' => $storage,
        'notes' => $notes,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   error: ?string,
 *   tables_present: list<string>,
 *   tables_missing: list<string>,
 *   relationship: list<string>,
 *   candidates: list<array<string, mixed>>,
 *   kept: list<array<string, mixed>>,
 *   stripe_events: array{count: int, note: string},
 *   hosting_packages: array<string, list<array<string, mixed>>>,
 *   manual_test_domains: list<string>,
 *   summary: array<string, int>
 * }
 */
function webco_cleanup_audit(PDO $db): array
{
    $emptyHosting = [
        'safe_candidate_for_deleting_hosting' => [],
        'hosting_should_remain' => [],
        'needs_manual_decision' => [],
    ];

    $relationship = [
        'orders 1—1 projects (projects.order_id / projects.order_public_id)',
        'projects 1—1 project_briefs (project_briefs.project_id)',
        'projects 1—N project_assets (project_assets.project_id; optional request_id)',
        'projects 1—N project_requests (project_requests.project_id)',
        'stripe_events is keyed only by stripe_event_id (no FK to orders)',
        'private upload files live under webco-projects/{order_public_id}/ (not in MySQL)',
        'future delete order: assets → requests → briefs → projects → orders; files only with explicit later approval; never Stripe/20i from this tool',
    ];

    $present = [];
    $missing = [];
    foreach (webco_cleanup_related_tables() as $table) {
        if (webco_cleanup_table_exists($db, $table)) {
            $present[] = $table;
        } else {
            $missing[] = $table;
        }
    }
    if ($missing !== []) {
        return [
            'ok' => false,
            'error' => 'required tables missing: ' . implode(', ', $missing),
            'tables_present' => $present,
            'tables_missing' => $missing,
            'relationship' => $relationship,
            'candidates' => [],
            'kept' => [],
            'stripe_events' => ['count' => 0, 'note' => ''],
            'hosting_packages' => $emptyHosting,
            'manual_test_domains' => webco_cleanup_known_test_domains(),
            'summary' => [],
        ];
    }

    try {
        $orderRows = $db->query(
            'SELECT id, public_id, status, package_code, care_choice, domain_path, domain_name,
                    business_name, contact_name, email, stripe_livemode, stripe_checkout_session_id,
                    stripe_customer_id, stripe_subscription_id, payment_intent_id, paid_at, created_at
             FROM orders
             ORDER BY id ASC'
        );
        if ($orderRows === false) {
            throw new PDOException('orders query failed');
        }
        $orders = $orderRows->fetchAll();

        $projectRows = $db->query(
            'SELECT id, order_id, order_public_id, status, provisioning_status, provisioning_error,
                    twentyi_package_id, domain_registered_at, provisioned_at, archived_at, created_at
             FROM projects
             ORDER BY id ASC'
        );
        if ($projectRows === false) {
            throw new PDOException('projects query failed');
        }
        $projectsByOrderId = [];
        foreach ($projectRows->fetchAll() as $project) {
            $orderId = (int) ($project['order_id'] ?? 0);
            if ($orderId > 0) {
                $projectsByOrderId[$orderId] = $project;
            }
        }
    } catch (PDOException) {
        return [
            'ok' => false,
            'error' => 'database read failed',
            'tables_present' => $present,
            'tables_missing' => $missing,
            'relationship' => $relationship,
            'candidates' => [],
            'kept' => [],
            'stripe_events' => ['count' => 0, 'note' => ''],
            'hosting_packages' => $emptyHosting,
            'manual_test_domains' => webco_cleanup_known_test_domains(),
            'summary' => [],
        ];
    }

    $candidates = [];
    $kept = [];
    foreach ($orders as $order) {
        $orderId = (int) ($order['id'] ?? 0);
        $project = $projectsByOrderId[$orderId] ?? null;
        $projectId = $project !== null ? (int) ($project['id'] ?? 0) : 0;
        $dependents = [
            'briefs' => $projectId > 0 ? webco_cleanup_count($db, 'project_briefs', 'project_id', $projectId) : 0,
            'assets' => $projectId > 0 ? webco_cleanup_count($db, 'project_assets', 'project_id', $projectId) : 0,
            'requests' => $projectId > 0 ? webco_cleanup_count($db, 'project_requests', 'project_id', $projectId) : 0,
        ];
        $bundle = webco_cleanup_classify_bundle($order, $project, $dependents);
        if ($bundle['decision'] === 'candidate') {
            $candidates[] = $bundle;
        } else {
            $kept[] = $bundle;
        }
        unset($projectsByOrderId[$orderId]);
    }

    // Projects whose order row is missing should never be auto-deleted.
    foreach ($projectsByOrderId as $orphanProject) {
        $kept[] = [
            'decision' => 'keep',
            'stripe_class' => 'orphan_project',
            'reasons' => [],
            'keep_reasons' => ['project_without_order_row'],
            'order' => null,
            'project' => $orphanProject,
            'dependents' => [
                'briefs' => 0,
                'assets' => 0,
                'requests' => 0,
            ],
            'storage_path' => null,
            'notes' => ['manual review required; refuse automatic cleanup'],
        ];
    }

    $eventCount = webco_cleanup_count_all($db, 'stripe_events');
    $liveSmoke = 0;
    $stripeTest = 0;
    foreach ($candidates as $bundle) {
        if (($bundle['stripe_class'] ?? '') === 'stripe_live_smoke_test') {
            $liveSmoke++;
        }
        if (($bundle['stripe_class'] ?? '') === 'stripe_test_mode') {
            $stripeTest++;
        }
    }
    $hostingPackages = webco_cleanup_hosting_package_report($candidates);

    return [
        'ok' => true,
        'error' => null,
        'tables_present' => $present,
        'tables_missing' => $missing,
        'relationship' => $relationship,
        'candidates' => $candidates,
        'kept' => $kept,
        'stripe_events' => [
            'count' => $eventCount,
            'note' => 'No FK to orders; cannot positively attribute events to test checkouts without Stripe. Keep all stripe_events rows.',
        ],
        'hosting_packages' => $hostingPackages,
        'manual_test_domains' => webco_cleanup_known_test_domains(),
        'summary' => [
            'orders_scanned' => count($orders),
            'candidates' => count($candidates),
            'kept' => count($kept),
            'candidate_stripe_test_mode' => $stripeTest,
            'candidate_stripe_live_smoke_test' => $liveSmoke,
            'stripe_events_kept' => $eventCount,
            'hosting_keep' => count($hostingPackages['hosting_should_remain']),
            'hosting_delete_safe' => count($hostingPackages['safe_candidate_for_deleting_hosting']),
            'hosting_manual' => count($hostingPackages['needs_manual_decision']),
        ],
    ];
}

function webco_cleanup_table_exists(PDO $db, string $table): bool
{
    if (!preg_match('/^[a-z_]+$/', $table)) {
        return false;
    }
    try {
        // SELECT works on MySQL and SQLite for the fixed application table names.
        $statement = $db->query('SELECT 1 FROM ' . $table . ' LIMIT 1');

        return $statement !== false;
    } catch (PDOException) {
        return false;
    }
}

function webco_cleanup_count(PDO $db, string $table, string $column, int $id): int
{
    if (!preg_match('/^[a-z_]+$/', $table) || !preg_match('/^[a-z_]+$/', $column) || $id < 1) {
        return 0;
    }
    try {
        $statement = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = :id');
        $statement->execute(['id' => $id]);
        return (int) $statement->fetchColumn();
    } catch (PDOException) {
        return 0;
    }
}

function webco_cleanup_count_all(PDO $db, string $table): int
{
    if (!preg_match('/^[a-z_]+$/', $table)) {
        return 0;
    }
    try {
        $statement = $db->query('SELECT COUNT(*) FROM ' . $table);
        if ($statement === false) {
            return 0;
        }

        return (int) $statement->fetchColumn();
    } catch (PDOException) {
        return 0;
    }
}

/**
 * @param array<string, mixed> $audit
 */
function webco_cleanup_audit_text(array $audit): string
{
    $lines = [
        'mode: cleanup-test-data preview',
        'ok: ' . ((($audit['ok'] ?? false) === true) ? 'yes' : 'no'),
        'destructive: no',
        'stripe_api: not called',
        'twentyi_api: not called',
        'files: not touched',
    ];
    if (is_string($audit['error'] ?? null) && $audit['error'] !== '') {
        $lines[] = 'error: ' . $audit['error'];
    }

    $lines[] = 'tables_present: ' . implode(', ', $audit['tables_present'] ?? []);
    if (($audit['tables_missing'] ?? []) !== []) {
        $lines[] = 'tables_missing: ' . implode(', ', $audit['tables_missing'] ?? []);
    }

    $lines[] = 'manual_test_domain_allowlist:';
    foreach ($audit['manual_test_domains'] ?? [] as $domain) {
        if (is_string($domain) && $domain !== '') {
            $lines[] = '  - ' . $domain;
        }
    }

    $lines[] = 'relationships:';
    foreach ($audit['relationship'] ?? [] as $item) {
        if (is_string($item)) {
            $lines[] = '  - ' . $item;
        }
    }

    $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : [];
    $lines[] = 'summary:';
    foreach ($summary as $key => $value) {
        $lines[] = '  ' . $key . ': ' . (string) $value;
    }

    $events = is_array($audit['stripe_events'] ?? null) ? $audit['stripe_events'] : [];
    $lines[] = 'stripe_events:';
    $lines[] = '  count: ' . (string) ($events['count'] ?? 0);
    $lines[] = '  decision: keep_all';
    $lines[] = '  note: ' . (string) ($events['note'] ?? '');

    $hosting = is_array($audit['hosting_packages'] ?? null) ? $audit['hosting_packages'] : [];
    $lines[] = 'twentyi_packages_on_candidates:';
    $lines[] = '  safe_candidate_for_deleting_hosting:';
    $lines = array_merge($lines, webco_cleanup_hosting_group_lines($hosting['safe_candidate_for_deleting_hosting'] ?? []));
    $lines[] = '  hosting_should_remain:';
    $lines = array_merge($lines, webco_cleanup_hosting_group_lines($hosting['hosting_should_remain'] ?? []));
    $lines[] = '  needs_manual_decision:';
    $lines = array_merge($lines, webco_cleanup_hosting_group_lines($hosting['needs_manual_decision'] ?? []));

    $lines[] = 'candidates_for_future_cleanup:';
    $candidates = $audit['candidates'] ?? [];
    if (!is_array($candidates) || $candidates === []) {
        $lines[] = '  (none)';
    } else {
        foreach ($candidates as $bundle) {
            if (!is_array($bundle)) {
                continue;
            }
            $order = is_array($bundle['order'] ?? null) ? $bundle['order'] : [];
            $project = is_array($bundle['project'] ?? null) ? $bundle['project'] : null;
            $deps = is_array($bundle['dependents'] ?? null) ? $bundle['dependents'] : [];
            $lines[] = '  - order: ' . (string) ($order['public_id'] ?? '');
            $lines[] = '    order_id: ' . (string) ($order['id'] ?? '');
            $lines[] = '    project_id: ' . (string) ($project['id'] ?? 'none');
            $lines[] = '    domain: ' . (string) ($order['domain_name'] ?? '');
            $lines[] = '    email: ' . (string) ($order['email'] ?? '');
            $lines[] = '    business: ' . (string) ($order['business_name'] ?? '');
            $lines[] = '    order_status: ' . (string) ($order['status'] ?? '');
            $lines[] = '    stripe_livemode: ' . webco_cleanup_scalar($order['stripe_livemode'] ?? null);
            $lines[] = '    stripe_class: ' . (string) ($bundle['stripe_class'] ?? '');
            $lines[] = '    provisioning_status: ' . (string) ($project['provisioning_status'] ?? 'n/a');
            $lines[] = '    twentyi_package_id: ' . webco_cleanup_scalar($project['twentyi_package_id'] ?? null);
            $lines[] = '    reasons: ' . implode(', ', is_array($bundle['reasons'] ?? null) ? $bundle['reasons'] : []);
            $lines[] = '    dependents: briefs=' . (string) ($deps['briefs'] ?? 0)
                . ' assets=' . (string) ($deps['assets'] ?? 0)
                . ' requests=' . (string) ($deps['requests'] ?? 0);
            $lines[] = '    storage: ' . (string) ($bundle['storage_path'] ?? 'none');
            foreach ($bundle['notes'] ?? [] as $note) {
                if (is_string($note) && $note !== '') {
                    $lines[] = '    note: ' . $note;
                }
            }
            $lines[] = '    would_delete_together: project_assets, project_requests, project_briefs, projects, orders';
        }
    }

    $lines[] = 'kept_not_identified_as_test:';
    $kept = $audit['kept'] ?? [];
    if (!is_array($kept) || $kept === []) {
        $lines[] = '  (none)';
    } else {
        foreach ($kept as $bundle) {
            if (!is_array($bundle)) {
                continue;
            }
            $order = is_array($bundle['order'] ?? null) ? $bundle['order'] : null;
            $project = is_array($bundle['project'] ?? null) ? $bundle['project'] : null;
            $lines[] = '  - order: ' . (string) (($order['public_id'] ?? null) ?? 'none');
            $lines[] = '    project_id: ' . (string) (($project['id'] ?? null) ?? 'none');
            $lines[] = '    domain: ' . (string) (($order['domain_name'] ?? null) ?? '');
            $lines[] = '    stripe_class: ' . (string) ($bundle['stripe_class'] ?? '');
            $lines[] = '    keep_reasons: ' . implode(', ', is_array($bundle['keep_reasons'] ?? null) ? $bundle['keep_reasons'] : []);
        }
    }

    $lines[] = 'apply: not available in this preview; pass --apply later only after review (currently refused)';

    return implode("\n", $lines) . "\n";
}

/**
 * @param mixed $rows
 * @return list<string>
 */
function webco_cleanup_hosting_group_lines(mixed $rows): array
{
    if (!is_array($rows) || $rows === []) {
        return ['    (none)'];
    }
    $lines = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lines[] = '    - package_id: ' . (string) ($row['package_id'] ?? '');
        $lines[] = '      project_id: ' . (string) ($row['project_id'] ?? '');
        $lines[] = '      order: ' . (string) ($row['order_public_id'] ?? '');
        $lines[] = '      domain: ' . (string) ($row['domain'] ?? '');
    }
    if ($lines === []) {
        return ['    (none)'];
    }

    return $lines;
}

function webco_cleanup_scalar(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_scalar($value)) {
        $text = trim((string) $value);

        return $text === '' ? 'null' : $text;
    }

    return 'null';
}

/**
 * @param list<string> $argv
 * @return array{ok: bool, error: string, apply: bool}
 */
function webco_cleanup_cli_options(array $argv): array
{
    $apply = false;
    foreach (array_slice($argv, 1) as $arg) {
        if (!is_string($arg)) {
            return ['ok' => false, 'error' => 'preview is the default; only --apply is recognised', 'apply' => false];
        }
        if ($arg === '--apply') {
            if ($apply) {
                return ['ok' => false, 'error' => '--apply may be given once', 'apply' => false];
            }
            $apply = true;
            continue;
        }

        return [
            'ok' => false,
            'error' => 'preview is the default; only --apply is recognised (deletion is not implemented yet)',
            'apply' => false,
        ];
    }

    return ['ok' => true, 'error' => '', 'apply' => $apply];
}
