<?php
/**
 * Hosting provisioning for existing domains, and for new .uk / .co.uk domains.
 *
 * Preview lists eligible projects and does not write. Apply claims a ready
 * project, registers an included new domain when needed, checks the 20i
 * package list, then creates one hosting package.
 * The dry-run worker and the Stripe webhook do not call this file.
 *
 * New domains outside .uk / .co.uk are refused (not included). Existing-domain
 * transfers stay manual. This step does not change DNS, issue SSL, create
 * mailboxes, deploy files, or copy a site template.
 */

declare(strict_types=1);

$webcoProvisioningScript = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
if (str_ends_with($webcoProvisioningScript, '/lib/provision-hosting.php')) {
    http_response_code(404);
    exit;
}
unset($webcoProvisioningScript);

require_once __DIR__ . '/provision.php';
require_once __DIR__ . '/twentyi.php';

const WEBCO_PROVISION_HOSTING_PACKAGE_TYPE = '117014';

/**
 * Ready projects, and in-progress projects that already have a stored package
 * id, whose order is paid in Stripe live mode for an existing domain.
 * A test-mode order is included only when the CLI names that one project.
 *
 * @return list<int>|null
 */
function webco_provision_hosting_candidate_ids(PDO $db, ?int $projectId = null, bool $allowTestOrder = false): ?array
{
    if ($projectId !== null && $projectId < 1) {
        return [];
    }

    $sql = 'SELECT p.id
            FROM projects p
            INNER JOIN orders o ON o.id = p.order_id
            WHERE o.status = \'paid\'
              AND ' . webco_provision_hosting_livemode_sql($allowTestOrder, $projectId) . '
              AND o.domain_path IN (\'existing\', \'new\')
              AND (
                    p.provisioning_status = \'ready\'
                    OR (
                        p.provisioning_status = \'in_progress\'
                        AND p.twentyi_package_id IS NOT NULL
                        AND p.twentyi_package_id <> \'\'
                    )
              )';
    if ($projectId !== null) {
        $sql .= ' AND p.id = :id';
    }
    $sql .= ' ORDER BY p.id';

    try {
        $statement = $db->prepare($sql);
        if ($projectId !== null) {
            $statement->execute(['id' => $projectId]);
        } else {
            $statement->execute();
        }
        $ids = [];
        foreach ($statement->fetchAll() as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    } catch (PDOException) {
        return null;
    }

    return $ids;
}

function webco_provision_hosting_needs_create(PDO $db, ?int $projectId = null, bool $allowTestOrder = false): ?bool
{
    $ids = webco_provision_hosting_candidate_ids($db, $projectId, $allowTestOrder);
    if ($ids === null) {
        return null;
    }
    foreach ($ids as $id) {
        $state = webco_provision_hosting_state($db, $id, $allowTestOrder);
        if ($state === null) {
            return null;
        }
        if ($state['package_id'] === null && $state['provisioning_status'] === 'ready') {
            return true;
        }
    }

    return false;
}

function webco_provision_hosting_test_override(bool $allowTestOrder, ?int $projectId): bool
{
    if (!$allowTestOrder || $projectId === null || $projectId < 1 || PHP_SAPI !== 'cli') {
        return false;
    }

    // Resolve relative invocations such as `php bin/provision-hosting.php`.
    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (!is_string($script) || $script === '') {
        return false;
    }

    $normalized = str_replace('\\', '/', $script);

    return str_ends_with($normalized, '/bin/provision-hosting.php');
}

function webco_provision_hosting_livemode_sql(bool $allowTestOrder, ?int $projectId): string
{
    if (webco_provision_hosting_test_override($allowTestOrder, $projectId)) {
        return 'o.stripe_livemode IN (0, 1)';
    }

    return 'o.stripe_livemode = 1';
}

function webco_provision_hosting_state(PDO $db, int $projectId, bool $allowTestOrder = false): ?array
{
    if ($projectId < 1) {
        return null;
    }

    try {
        $statement = $db->prepare(
            'SELECT p.id, p.order_public_id, p.provisioning_status, p.twentyi_package_id,
                    p.provisioning_error, p.domain_registered_at, o.public_id, o.status AS order_status,
                    o.stripe_livemode, o.domain_path, o.domain_name, o.package_code
             FROM projects p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.id = :id'
        );
        $statement->execute(['id' => $projectId]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return null;
    }
    if ($row === false) {
        return null;
    }

    $packageId = webco_twentyi_id($row['twentyi_package_id'] ?? null);
    $livemode = $row['stripe_livemode'] ?? null;
    $livemode = $livemode === null || $livemode === '' ? null : (int) $livemode;
    $live = $livemode === 1;
    $test = webco_provision_hosting_test_override($allowTestOrder, $projectId) && $livemode === 0;
    $domainPath = (string) ($row['domain_path'] ?? '');
    $domainName = (string) ($row['domain_name'] ?? '');
    $domainOk = $domainPath === 'existing' || $domainPath === 'new';

    return [
        'id' => (int) ($row['id'] ?? 0),
        'order_public_id' => (string) ($row['order_public_id'] ?? ''),
        'public_id' => (string) ($row['public_id'] ?? ''),
        'provisioning_status' => (string) ($row['provisioning_status'] ?? ''),
        'package_id' => $packageId,
        'provisioning_error' => webco_provision_nullable($row['provisioning_error'] ?? null),
        'domain_registered_at' => webco_provision_nullable($row['domain_registered_at'] ?? null),
        'order_status' => (string) ($row['order_status'] ?? ''),
        'stripe_livemode' => $livemode,
        'domain_path' => $domainPath,
        'domain_name' => $domainName,
        'package_code' => (string) ($row['package_code'] ?? ''),
        'eligible' => (string) ($row['order_status'] ?? '') === 'paid'
            && ($live || $test)
            && $domainOk,
    ];
}

/**
 * @return list<array<string, mixed>>|null
 */
function webco_provision_hosting_preview(PDO $db, ?int $projectId = null, bool $allowTestOrder = false): ?array
{
    if ($projectId !== null && $projectId < 1) {
        return [[
            'project_id' => $projectId,
            'action' => 'not_eligible',
            'domain_name' => '',
            'package_type' => WEBCO_PROVISION_HOSTING_PACKAGE_TYPE,
            'label' => '',
            'package_id' => null,
        ]];
    }

    $ids = webco_provision_hosting_candidate_ids($db, $projectId, $allowTestOrder);
    if ($ids === null) {
        return null;
    }
    if ($projectId !== null && $ids === []) {
        $state = webco_provision_hosting_state($db, $projectId, $allowTestOrder);

        return [[
            'project_id' => $projectId,
            'action' => $state === null ? 'missing' : 'not_eligible',
            'domain_name' => $state['domain_name'] ?? '',
            'package_type' => WEBCO_PROVISION_HOSTING_PACKAGE_TYPE,
            'label' => $state['public_id'] ?? '',
            'package_id' => $state['package_id'] ?? null,
        ]];
    }

    $rows = [];
    foreach ($ids as $id) {
        $state = webco_provision_hosting_state($db, $id, $allowTestOrder);
        if ($state === null) {
            return null;
        }
        $rows[] = [
            'project_id' => $id,
            'action' => $state['package_id'] === null ? 'create' : 'finish_existing',
            'domain_name' => $state['domain_name'],
            'package_type' => WEBCO_PROVISION_HOSTING_PACKAGE_TYPE,
            'label' => $state['public_id'],
            'package_id' => $state['package_id'],
        ];
    }

    return $rows;
}

/**
 * @param list<string> $argv
 * @return array{ok: bool, error: string, apply: bool, project_id: ?int, allow_test_order: bool}
 */
function webco_provision_hosting_cli_options(array $argv): array
{
    $apply = false;
    $projectId = null;
    $allowTestOrder = false;
    $empty = [
        'ok' => false,
        'error' => '',
        'apply' => false,
        'project_id' => null,
        'allow_test_order' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (!is_string($arg)) {
            $empty['error'] = 'preview is the default; arguments are --project=ID, --apply, and --allow-test-order';

            return $empty;
        }
        if ($arg === '--apply') {
            if ($apply) {
                $empty['error'] = 'preview is the default; --apply may be given once';

                return $empty;
            }
            $apply = true;
            continue;
        }
        if ($arg === '--allow-test-order') {
            if ($allowTestOrder) {
                $empty['error'] = '--allow-test-order may be given once';

                return $empty;
            }
            $allowTestOrder = true;
            continue;
        }
        if (str_starts_with($arg, '--project=')) {
            if ($projectId !== null) {
                $empty['error'] = '--project may be given once';

                return $empty;
            }
            $raw = substr($arg, strlen('--project='));
            if (!preg_match('/^[1-9][0-9]{0,11}$/', $raw)) {
                $empty['error'] = '--project must be one project id, as in --project=1';

                return $empty;
            }
            $projectId = (int) $raw;
            continue;
        }

        $empty['error'] = 'preview is the default; arguments are --project=ID, --apply, and --allow-test-order';

        return $empty;
    }

    if ($allowTestOrder && $projectId === null) {
        $empty['error'] = '--allow-test-order requires --project=ID';

        return $empty;
    }

    return [
        'ok' => true,
        'error' => '',
        'apply' => $apply,
        'project_id' => $projectId,
        'allow_test_order' => $allowTestOrder,
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 */
function webco_provision_hosting_preview_text(array $rows, bool $allowTestOrder = false): string
{
    $lines = ['mode: preview'];
    if ($allowTestOrder) {
        $lines[] = 'test_order_override: active';
    }
    $lines[] = 'package_type: ' . WEBCO_PROVISION_HOSTING_PACKAGE_TYPE;
    $lines[] = 'candidates: ' . count(array_filter(
            $rows,
            static fn (array $row): bool => ($row['action'] ?? '') === 'create' || ($row['action'] ?? '') === 'finish_existing'
        ));
    if ($rows === []) {
        $lines[] = 'request: not sent';

        return implode("\n", $lines) . "\n";
    }
    foreach ($rows as $row) {
        $lines[] = 'project_id: ' . (int) ($row['project_id'] ?? 0);
        $lines[] = 'action: ' . webco_provision_hosting_action((string) ($row['action'] ?? ''));
        $domain = webco_twentyi_domain((string) ($row['domain_name'] ?? ''));
        if ($domain !== null) {
            $lines[] = 'domain_name: ' . $domain;
        }
        $label = (string) ($row['label'] ?? '');
        if (preg_match('/^wc_[a-f0-9]{20}$/', $label)) {
            $lines[] = 'label: ' . $label;
        }
        $packageId = webco_twentyi_id($row['package_id'] ?? null);
        if ($packageId !== null) {
            $lines[] = 'package_id: ' . $packageId;
        }
    }
    $lines[] = 'request: not sent';

    return implode("\n", $lines) . "\n";
}

function webco_provision_hosting_action(string $action): string
{
    if (in_array($action, ['create', 'finish_existing', 'not_eligible', 'missing'], true)) {
        return $action;
    }

    return 'not_eligible';
}

/**
 * Domain names from a package-list section. Response bodies are not kept.
 *
 * @param array<string, mixed> $section
 * @return array{ok: bool, domains: list<string>}
 */
function webco_provision_hosting_domains(array $section): array
{
    if (($section['ok'] ?? false) !== true || !is_array($section['rows'] ?? null)) {
        return ['ok' => false, 'domains' => []];
    }

    $domains = [];
    foreach ($section['rows'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidates = [(string) ($row['name'] ?? '')];
        if (is_array($row['names'] ?? null)) {
            foreach ($row['names'] as $name) {
                if (is_string($name)) {
                    $candidates[] = $name;
                }
            }
        }
        foreach ($candidates as $candidate) {
            $domain = webco_twentyi_domain($candidate);
            if ($domain !== null) {
                $domains[$domain] = $domain;
            }
        }
    }

    return ['ok' => true, 'domains' => array_values($domains)];
}

/**
 * @param list<string> $domains
 */
function webco_provision_hosting_domain_taken(string $domain, array $domains): bool
{
    $domain = webco_twentyi_domain($domain);
    if ($domain === null) {
        return true;
    }
    foreach ($domains as $existing) {
        if (is_string($existing) && webco_twentyi_domain($existing) === $domain) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{ok: bool, domains: list<string>}
 */
function webco_provision_hosting_list_live(string $bearer): array
{
    if ($bearer === '') {
        return ['ok' => false, 'domains' => []];
    }

    $section = webco_twentyi_read_section(
        static function (string $path) use ($bearer): array {
            return webco_twentyi_http_get($path, $bearer);
        },
        WEBCO_TWENTYI_PACKAGE_LIST,
        'webco_twentyi_package_rows'
    );

    return webco_provision_hosting_domains($section);
}

/**
 * @param callable(): array{ok: bool, domains?: list<string>} $listPackages
 * @param callable(string, string, string): array<string, mixed> $createHosting
 * @param callable(array{event: string, context: array<string, mixed>}): void $log
 * @param callable(string, array<string, mixed>): array<string, mixed>|null $registerDomain
 * @param callable(string): string|null $checkAvailability returns available|unavailable|error
 * @param callable(): array{ok: bool, domains?: list<string>}|null $listRegistered
 * @return array{ok: bool, results: list<array<string, mixed>>}
 */
function webco_provision_hosting_apply(
    PDO $db,
    callable $listPackages,
    callable $createHosting,
    callable $log,
    ?int $projectId = null,
    bool $allowTestOrder = false,
    ?callable $registerDomain = null,
    ?callable $checkAvailability = null,
    ?callable $listRegistered = null
): array {
    $testOverride = webco_provision_hosting_test_override($allowTestOrder, $projectId);
    $started = ['mode' => 'apply'];
    if ($testOverride) {
        $started['test_order_override'] = 'active';
    }
    webco_provision_log($log, 'hosting_worker_started', $started);
    $ids = webco_provision_hosting_candidate_ids($db, $projectId, $allowTestOrder);
    if ($ids === null) {
        webco_provision_log($log, 'hosting_worker_stopped', ['reason' => 'database_error']);

        return ['ok' => false, 'results' => []];
    }

    $needsCreate = false;
    foreach ($ids as $id) {
        $state = webco_provision_hosting_state($db, $id, $allowTestOrder);
        if ($state === null) {
            return ['ok' => false, 'results' => []];
        }
        if ($state['package_id'] === null && $state['provisioning_status'] === 'ready') {
            $needsCreate = true;
        }
    }

    $packageList = ['ok' => true, 'domains' => []];
    if ($needsCreate) {
        $listed = $listPackages();
        $domains = is_array($listed) ? ($listed['domains'] ?? null) : null;
        if (!is_array($listed) || ($listed['ok'] ?? false) !== true || !is_array($domains)) {
            $packageList = ['ok' => false, 'domains' => []];
        } else {
            $clean = [];
            foreach ($domains as $domain) {
                if (is_string($domain)) {
                    $normal = webco_twentyi_domain($domain);
                    if ($normal !== null) {
                        $clean[] = $normal;
                    }
                }
            }
            $packageList = ['ok' => true, 'domains' => $clean];
        }
    }

    $results = [];
    foreach ($ids as $id) {
        $results[] = webco_provision_hosting_project(
            $db,
            $id,
            $packageList,
            $createHosting,
            $log,
            null,
            null,
            $allowTestOrder,
            $registerDomain,
            $checkAvailability,
            $listRegistered
        );
    }

    $ok = true;
    foreach ($results as $result) {
        $outcome = (string) ($result['outcome'] ?? '');
        if ($outcome === 'failed' || $outcome === 'id_stored') {
            $ok = false;
        }
    }

    $summary = ['ok' => $ok, 'results' => $results];
    if ($testOverride) {
        $summary['test_order_override'] = 'active';
    }

    return $summary;
}

/**
 * @param array{ok: bool, domains: list<string>} $packageList
 * @param callable(string, string, string): array<string, mixed> $createHosting
 * @param callable(array{event: string, context: array<string, mixed>}): void $log
 * @param callable(PDO, int, string): bool|null $storePackageId
 * @param callable(PDO, int, string): bool|null $markProvisioned
 * @param callable(string, array<string, mixed>): array<string, mixed>|null $registerDomain
 * @param callable(string): string|null $checkAvailability
 * @param callable(): array{ok: bool, domains?: list<string>}|null $listRegistered
 * @return array{project_id: int, outcome: string, package_id: ?string, error: ?string}
 */
function webco_provision_hosting_project(
    PDO $db,
    int $projectId,
    array $packageList,
    callable $createHosting,
    callable $log,
    ?callable $storePackageId = null,
    ?callable $markProvisioned = null,
    bool $allowTestOrder = false,
    ?callable $registerDomain = null,
    ?callable $checkAvailability = null,
    ?callable $listRegistered = null
): array {
    $store = $storePackageId ?? 'webco_provision_hosting_store_package_id';
    $mark = $markProvisioned ?? 'webco_provision_hosting_mark_provisioned';
    $state = webco_provision_hosting_state($db, $projectId, $allowTestOrder);
    if ($state === null || !$state['eligible']) {
        return webco_provision_hosting_result($projectId, 'skipped', null, null);
    }

    if ($state['package_id'] !== null && $state['provisioning_status'] === 'provisioned') {
        return webco_provision_hosting_result($projectId, 'already', $state['package_id'], null);
    }

    if ($state['package_id'] !== null && $state['provisioning_status'] === 'in_progress') {
        return webco_provision_hosting_finish($db, $projectId, $state['package_id'], $mark, $log);
    }

    if ($state['provisioning_status'] !== 'ready') {
        return webco_provision_hosting_result($projectId, 'skipped', $state['package_id'], null);
    }

    $claimed = webco_provision_hosting_claim($db, $projectId, $allowTestOrder);
    if ($claimed === null) {
        webco_provision_log($log, 'hosting_skipped', [
            'project_id' => $projectId,
            'reason' => 'not_claimed',
        ]);

        return webco_provision_hosting_result($projectId, 'skipped', null, null);
    }
    webco_provision_log($log, 'hosting_claimed', ['project_id' => $projectId]);

    $existingId = webco_twentyi_id($claimed['twentyi_package_id'] ?? null);
    if ($existingId !== null) {
        return webco_provision_hosting_finish($db, $projectId, $existingId, $mark, $log);
    }

    $decision = webco_provision_validate($claimed);
    if (!$decision['ok'] || !is_array($decision['payload'])) {
        $error = webco_provision_safe_error($decision['error'] ?? 'Provisioning data is incomplete');
        webco_provision_fail($db, $projectId, $error);

        return webco_provision_hosting_failed($log, $projectId, $error);
    }
    $payload = $decision['payload'];
    $domainPath = (string) ($payload['domain_path'] ?? '');
    if ($domainPath !== 'existing' && $domainPath !== 'new') {
        $error = 'Unsupported domain choice for hosting provisioning';
        webco_provision_fail($db, $projectId, $error);

        return webco_provision_hosting_failed($log, $projectId, $error);
    }

    $domain = (string) $payload['domain_name'];
    if ($domainPath === 'new') {
        $registered = webco_provision_hosting_ensure_domain_registered(
            $db,
            $projectId,
            $claimed,
            $domain,
            $log,
            $registerDomain,
            $checkAvailability,
            $listRegistered
        );
        if ($registered !== true) {
            return is_array($registered)
                ? $registered
                : webco_provision_hosting_failed($log, $projectId, 'Domain registration could not be completed');
        }
    }

    if (($packageList['ok'] ?? false) !== true || !is_array($packageList['domains'] ?? null)) {
        $error = 'Existing hosting packages could not be read';
        webco_provision_fail($db, $projectId, $error);

        return webco_provision_hosting_failed($log, $projectId, $error);
    }

    if (webco_provision_hosting_domain_taken($domain, $packageList['domains'])) {
        $error = 'Domain already exists on a 20i hosting package';
        webco_provision_fail($db, $projectId, $error);
        webco_provision_log($log, 'hosting_collision', ['project_id' => $projectId]);

        return webco_provision_hosting_failed($log, $projectId, $error);
    }

    $created = $createHosting($domain, WEBCO_PROVISION_HOSTING_PACKAGE_TYPE, (string) $payload['order_public_id']);
    if (!is_array($created) || ($created['ok'] ?? false) !== true) {
        $error = webco_provision_hosting_create_error(is_array($created) ? $created : []);
        webco_provision_fail($db, $projectId, $error);

        return webco_provision_hosting_failed($log, $projectId, $error);
    }

    $packageId = webco_twentyi_id($created['package_id'] ?? null);
    if ($packageId === null) {
        $error = 'Hosting package creation returned an unusable response';
        webco_provision_fail($db, $projectId, $error);

        return webco_provision_hosting_failed($log, $projectId, $error);
    }
    webco_provision_log($log, 'hosting_created', [
        'project_id' => $projectId,
        'package_id' => $packageId,
    ]);

    if (!$store($db, $projectId, $packageId)) {
        $error = 'Hosting package was created but its id could not be saved';
        webco_provision_fail($db, $projectId, $error);
        webco_provision_log($log, 'hosting_failed', [
            'project_id' => $projectId,
            'error' => $error,
            'package_id' => $packageId,
        ]);

        return webco_provision_hosting_result($projectId, 'failed', $packageId, $error);
    }
    webco_provision_log($log, 'hosting_stored', [
        'project_id' => $projectId,
        'package_id' => $packageId,
    ]);

    return webco_provision_hosting_finish($db, $projectId, $packageId, $mark, $log);
}

/**
 * Register an included new domain when needed. Returns true on success, or a
 * failed project result array.
 *
 * @param array<string, mixed> $claimed
 * @param callable(array{event: string, context: array<string, mixed>}): void $log
 * @param callable(string, array<string, mixed>): array<string, mixed>|null $registerDomain
 * @param callable(string): string|null $checkAvailability
 * @param callable(): array{ok: bool, domains?: list<string>}|null $listRegistered
 * @return true|array{project_id: int, outcome: string, package_id: ?string, error: ?string}
 */
function webco_provision_hosting_ensure_domain_registered(
    PDO $db,
    int $projectId,
    array $claimed,
    string $domain,
    callable $log,
    ?callable $registerDomain,
    ?callable $checkAvailability,
    ?callable $listRegistered
): array|bool {
    if (!webco_domain_is_included_tld($domain)) {
        $error = 'Only .uk and .co.uk domains are included with hosting';
        webco_provision_fail($db, $projectId, $error);

        return webco_provision_hosting_failed($log, $projectId, $error);
    }

    $already = webco_provision_nullable($claimed['domain_registered_at'] ?? null);
    if ($already !== null) {
        return true;
    }

    $owned = false;
    if ($listRegistered !== null) {
        $listed = $listRegistered();
        $domains = is_array($listed) ? ($listed['domains'] ?? null) : null;
        if (is_array($listed) && ($listed['ok'] ?? false) === true && is_array($domains)) {
            foreach ($domains as $name) {
                if (is_string($name) && webco_twentyi_domain($name) === $domain) {
                    $owned = true;
                    break;
                }
            }
        }
    }

    if (!$owned) {
        if ($checkAvailability === null || $registerDomain === null) {
            $error = 'Domain registration is not configured';
            webco_provision_fail($db, $projectId, $error);

            return webco_provision_hosting_failed($log, $projectId, $error);
        }

        $availability = $checkAvailability($domain);
        if ($availability === 'unavailable') {
            $error = 'Domain is no longer available to register';
            webco_provision_fail($db, $projectId, $error);

            return webco_provision_hosting_failed($log, $projectId, $error);
        }
        if ($availability !== 'available') {
            $error = 'Domain availability could not be confirmed';
            webco_provision_fail($db, $projectId, $error);

            return webco_provision_hosting_failed($log, $projectId, $error);
        }

        $contact = [
            'business_name' => $claimed['business_name'] ?? null,
            'contact_name' => $claimed['contact_name'] ?? null,
            'email' => $claimed['email'] ?? null,
            'phone' => $claimed['phone'] ?? null,
            'address_line_1' => $claimed['address_line_1'] ?? null,
            'address_line_2' => $claimed['address_line_2'] ?? null,
            'town' => $claimed['town'] ?? null,
            'county' => $claimed['county'] ?? null,
            'postcode' => $claimed['postcode'] ?? null,
            'company_number' => $claimed['company_number'] ?? null,
        ];
        $registered = $registerDomain($domain, $contact);
        if (!is_array($registered) || ($registered['ok'] ?? false) !== true) {
            // Retry path: registration may have succeeded earlier without our stamp.
            $ownedAfter = false;
            if ($listRegistered !== null) {
                $again = $listRegistered();
                $againDomains = is_array($again) ? ($again['domains'] ?? null) : null;
                if (is_array($again) && ($again['ok'] ?? false) === true && is_array($againDomains)) {
                    foreach ($againDomains as $name) {
                        if (is_string($name) && webco_twentyi_domain($name) === $domain) {
                            $ownedAfter = true;
                            break;
                        }
                    }
                }
            }
            if (!$ownedAfter) {
                $error = webco_provision_hosting_register_error(is_array($registered) ? $registered : []);
                webco_provision_fail($db, $projectId, $error);

                return webco_provision_hosting_failed($log, $projectId, $error);
            }
        } else {
            webco_provision_log($log, 'domain_registered', [
                'project_id' => $projectId,
                'domain_name' => $domain,
            ]);
        }
    } else {
        webco_provision_log($log, 'domain_already_registered', [
            'project_id' => $projectId,
            'domain_name' => $domain,
        ]);
    }

    if (!webco_provision_hosting_mark_domain_registered($db, $projectId)) {
        $error = 'Domain was registered but could not be recorded';
        webco_provision_fail($db, $projectId, $error);

        return webco_provision_hosting_failed($log, $projectId, $error);
    }

    return true;
}

/**
 * @param array<string, mixed> $registered
 */
function webco_provision_hosting_register_error(array $registered): string
{
    $failure = (string) ($registered['failure'] ?? '');
    $status = (int) ($registered['status'] ?? 0);
    if ($failure === 'http' && $status > 0) {
        return 'Domain registration failed (HTTP ' . $status . ')';
    }
    if ($failure === 'invalid') {
        return 'Domain registration details are incomplete';
    }

    return 'Domain registration could not be completed';
}

function webco_provision_hosting_mark_domain_registered(PDO $db, int $projectId): bool
{
    if ($projectId < 1) {
        return false;
    }

    try {
        $update = $db->prepare(
            'UPDATE projects
             SET domain_registered_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND provisioning_status = \'in_progress\'
               AND (domain_registered_at IS NULL OR domain_registered_at = \'\')'
        );
        $update->execute(['id' => $projectId]);
        if ($update->rowCount() === 1) {
            return true;
        }

        $select = $db->prepare('SELECT domain_registered_at FROM projects WHERE id = :id');
        $select->execute(['id' => $projectId]);
        $row = $select->fetch();
    } catch (PDOException) {
        return false;
    }
    if ($row === false) {
        return false;
    }

    return webco_provision_nullable($row['domain_registered_at'] ?? null) !== null;
}

/**
 * @param callable(PDO, int, string): bool $mark
 * @param callable(array{event: string, context: array<string, mixed>}): void $log
 * @return array{project_id: int, outcome: string, package_id: ?string, error: ?string}
 */
function webco_provision_hosting_finish(
    PDO $db,
    int $projectId,
    string $packageId,
    callable $mark,
    callable $log
): array {
    if ($mark($db, $projectId, $packageId)) {
        webco_provision_log($log, 'hosting_provisioned', [
            'project_id' => $projectId,
            'package_id' => $packageId,
        ]);

        return webco_provision_hosting_result($projectId, 'provisioned', $packageId, null);
    }

    webco_provision_log($log, 'hosting_id_stored', [
        'project_id' => $projectId,
        'package_id' => $packageId,
    ]);

    return webco_provision_hosting_result($projectId, 'id_stored', $packageId, null);
}

/**
 * @param callable(array{event: string, context: array<string, mixed>}): void $log
 * @return array{project_id: int, outcome: string, package_id: ?string, error: ?string}
 */
function webco_provision_hosting_failed(callable $log, int $projectId, string $error): array
{
    webco_provision_log($log, 'hosting_failed', [
        'project_id' => $projectId,
        'error' => $error,
    ]);

    return webco_provision_hosting_result($projectId, 'failed', null, $error);
}

/**
 * @return array{project_id: int, outcome: string, package_id: ?string, error: ?string}
 */
function webco_provision_hosting_result(int $projectId, string $outcome, ?string $packageId, ?string $error): array
{
    return [
        'project_id' => $projectId,
        'outcome' => $outcome,
        'package_id' => $packageId,
        'error' => $error,
    ];
}

/**
 * @param array<string, mixed> $created
 */
function webco_provision_hosting_create_error(array $created): string
{
    $failure = (string) ($created['failure'] ?? '');
    $status = (int) ($created['status'] ?? 0);
    if ($failure === 'http' && $status > 0) {
        return 'Hosting package creation failed (HTTP ' . $status . ')';
    }
    if ($failure === 'rejected' || $failure === 'unreadable') {
        return 'Hosting package creation returned an unusable response';
    }

    return 'Hosting package creation could not be completed';
}

/**
 * @return array<string, mixed>|null
 */
function webco_provision_hosting_claim(PDO $db, int $projectId, bool $allowTestOrder = false): ?array
{
    if ($projectId < 1) {
        return null;
    }

    try {
        $db->beginTransaction();
        $select = $db->prepare(
            'SELECT p.id, p.order_id, p.order_public_id, p.vertical_code, p.status AS project_status,
                    p.twentyi_package_id, p.domain_registered_at,
                    o.id AS linked_order_id, o.public_id, o.status AS order_status,
                    o.package_code, o.package_name, o.domain_path, o.domain_name,
                    o.business_name, o.contact_name, o.email, o.phone, o.care_choice,
                    o.address_line_1, o.address_line_2, o.town, o.county, o.postcode, o.company_number,
                    o.hosting_status, o.hosting_included_until, o.care_status
             FROM projects p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.id = :id
               AND p.provisioning_status = \'ready\'
               AND o.status = \'paid\'
               AND ' . webco_provision_hosting_livemode_sql($allowTestOrder, $projectId) . '
               AND o.domain_path IN (\'existing\', \'new\')' . webco_for_update($db)
        );
        $select->execute(['id' => $projectId]);
        $row = $select->fetch();
        if ($row === false) {
            $db->commit();

            return null;
        }

        $update = $db->prepare(
            'UPDATE projects
             SET provisioning_status = \'in_progress\',
                 provisioning_attempted_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND provisioning_status = \'ready\''
        );
        $update->execute(['id' => $projectId]);
        if ($update->rowCount() !== 1) {
            $db->rollBack();

            return null;
        }
        $db->commit();
    } catch (PDOException) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        return null;
    }

    return $row;
}

function webco_provision_hosting_store_package_id(PDO $db, int $projectId, string $packageId): bool
{
    $packageId = webco_twentyi_id($packageId) ?? '';
    if ($projectId < 1 || $packageId === '') {
        return false;
    }

    try {
        $update = $db->prepare(
            'UPDATE projects
             SET twentyi_package_id = :package_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND provisioning_status = \'in_progress\'
               AND (twentyi_package_id IS NULL OR twentyi_package_id = \'\')'
        );
        $update->execute([
            'package_id' => $packageId,
            'id' => $projectId,
        ]);
        if ($update->rowCount() === 1) {
            return true;
        }

        $select = $db->prepare('SELECT twentyi_package_id FROM projects WHERE id = :id');
        $select->execute(['id' => $projectId]);
        $row = $select->fetch();
    } catch (PDOException) {
        return false;
    }
    if ($row === false) {
        return false;
    }

    return webco_twentyi_id($row['twentyi_package_id'] ?? null) === $packageId;
}

function webco_provision_hosting_mark_provisioned(PDO $db, int $projectId, string $packageId): bool
{
    $packageId = webco_twentyi_id($packageId) ?? '';
    if ($projectId < 1 || $packageId === '') {
        return false;
    }

    try {
        $update = $db->prepare(
            'UPDATE projects
             SET provisioning_status = \'provisioned\',
                 provisioned_at = CURRENT_TIMESTAMP,
                 provisioning_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND provisioning_status = \'in_progress\'
               AND twentyi_package_id = :package_id'
        );
        $update->execute([
            'id' => $projectId,
            'package_id' => $packageId,
        ]);
        if ($update->rowCount() === 1) {
            return true;
        }

        $select = $db->prepare(
            'SELECT provisioning_status, twentyi_package_id FROM projects WHERE id = :id'
        );
        $select->execute(['id' => $projectId]);
        $row = $select->fetch();
    } catch (PDOException) {
        return false;
    }
    if ($row === false) {
        return false;
    }

    return (string) ($row['provisioning_status'] ?? '') === 'provisioned'
        && webco_twentyi_id($row['twentyi_package_id'] ?? null) === $packageId;
}
