<?php
/**
 * Hosting provisioning for an existing domain.
 *
 * Preview lists eligible projects and does not write. Apply claims a ready
 * project, checks the 20i package list, then creates one hosting package.
 * The dry-run worker and the Stripe webhook do not call this file.
 *
 * This step does not register domains, change DNS, issue SSL, create
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
 *
 * @return list<int>|null
 */
function webco_provision_hosting_candidate_ids(PDO $db, ?int $projectId = null): ?array
{
    if ($projectId !== null && $projectId < 1) {
        return [];
    }

    $sql = 'SELECT p.id
            FROM projects p
            INNER JOIN orders o ON o.id = p.order_id
            WHERE o.status = \'paid\'
              AND o.stripe_livemode = 1
              AND o.domain_path = \'existing\'
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

function webco_provision_hosting_needs_create(PDO $db, ?int $projectId = null): ?bool
{
    $ids = webco_provision_hosting_candidate_ids($db, $projectId);
    if ($ids === null) {
        return null;
    }
    foreach ($ids as $id) {
        $state = webco_provision_hosting_state($db, $id);
        if ($state === null) {
            return null;
        }
        if ($state['package_id'] === null && $state['provisioning_status'] === 'ready') {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, mixed>|null
 */
function webco_provision_hosting_state(PDO $db, int $projectId): ?array
{
    if ($projectId < 1) {
        return null;
    }

    try {
        $statement = $db->prepare(
            'SELECT p.id, p.order_public_id, p.provisioning_status, p.twentyi_package_id,
                    p.provisioning_error, o.public_id, o.status AS order_status,
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

    return [
        'id' => (int) ($row['id'] ?? 0),
        'order_public_id' => (string) ($row['order_public_id'] ?? ''),
        'public_id' => (string) ($row['public_id'] ?? ''),
        'provisioning_status' => (string) ($row['provisioning_status'] ?? ''),
        'package_id' => $packageId,
        'provisioning_error' => webco_provision_nullable($row['provisioning_error'] ?? null),
        'order_status' => (string) ($row['order_status'] ?? ''),
        'stripe_livemode' => $livemode === null || $livemode === '' ? null : (int) $livemode,
        'domain_path' => (string) ($row['domain_path'] ?? ''),
        'domain_name' => (string) ($row['domain_name'] ?? ''),
        'package_code' => (string) ($row['package_code'] ?? ''),
        'eligible' => (string) ($row['order_status'] ?? '') === 'paid'
            && (int) $livemode === 1
            && $livemode !== null
            && (string) ($row['domain_path'] ?? '') === 'existing',
    ];
}

/**
 * @return list<array<string, mixed>>|null
 */
function webco_provision_hosting_preview(PDO $db, ?int $projectId = null): ?array
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

    $ids = webco_provision_hosting_candidate_ids($db, $projectId);
    if ($ids === null) {
        return null;
    }
    if ($projectId !== null && $ids === []) {
        $state = webco_provision_hosting_state($db, $projectId);

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
        $state = webco_provision_hosting_state($db, $id);
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
 * @return array{ok: bool, error: string, apply: bool, project_id: ?int}
 */
function webco_provision_hosting_cli_options(array $argv): array
{
    $apply = false;
    $projectId = null;
    $empty = [
        'ok' => false,
        'error' => '',
        'apply' => false,
        'project_id' => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (!is_string($arg)) {
            $empty['error'] = 'preview is the default; arguments are --project=ID and --apply';

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

        $empty['error'] = 'preview is the default; arguments are --project=ID and --apply';

        return $empty;
    }

    return [
        'ok' => true,
        'error' => '',
        'apply' => $apply,
        'project_id' => $projectId,
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 */
function webco_provision_hosting_preview_text(array $rows): string
{
    $lines = [
        'mode: preview',
        'package_type: ' . WEBCO_PROVISION_HOSTING_PACKAGE_TYPE,
        'candidates: ' . count(array_filter(
            $rows,
            static fn (array $row): bool => ($row['action'] ?? '') === 'create' || ($row['action'] ?? '') === 'finish_existing'
        )),
    ];
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
 * @return array{ok: bool, results: list<array<string, mixed>>}
 */
function webco_provision_hosting_apply(
    PDO $db,
    callable $listPackages,
    callable $createHosting,
    callable $log,
    ?int $projectId = null
): array {
    webco_provision_log($log, 'hosting_worker_started', ['mode' => 'apply']);
    $ids = webco_provision_hosting_candidate_ids($db, $projectId);
    if ($ids === null) {
        webco_provision_log($log, 'hosting_worker_stopped', ['reason' => 'database_error']);

        return ['ok' => false, 'results' => []];
    }

    $needsCreate = false;
    foreach ($ids as $id) {
        $state = webco_provision_hosting_state($db, $id);
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
        $results[] = webco_provision_hosting_project($db, $id, $packageList, $createHosting, $log);
    }

    $ok = true;
    foreach ($results as $result) {
        $outcome = (string) ($result['outcome'] ?? '');
        if ($outcome === 'failed' || $outcome === 'id_stored') {
            $ok = false;
        }
    }

    return ['ok' => $ok, 'results' => $results];
}

/**
 * @param array{ok: bool, domains: list<string>} $packageList
 * @param callable(string, string, string): array<string, mixed> $createHosting
 * @param callable(array{event: string, context: array<string, mixed>}): void $log
 * @param callable(PDO, int, string): bool|null $storePackageId
 * @param callable(PDO, int, string): bool|null $markProvisioned
 * @return array{project_id: int, outcome: string, package_id: ?string, error: ?string}
 */
function webco_provision_hosting_project(
    PDO $db,
    int $projectId,
    array $packageList,
    callable $createHosting,
    callable $log,
    ?callable $storePackageId = null,
    ?callable $markProvisioned = null
): array {
    $store = $storePackageId ?? 'webco_provision_hosting_store_package_id';
    $mark = $markProvisioned ?? 'webco_provision_hosting_mark_provisioned';
    $state = webco_provision_hosting_state($db, $projectId);
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

    $claimed = webco_provision_hosting_claim($db, $projectId);
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
    if (($payload['domain_path'] ?? '') !== 'existing') {
        $error = 'Only an existing domain can be hosted in this step';
        webco_provision_fail($db, $projectId, $error);

        return webco_provision_hosting_failed($log, $projectId, $error);
    }

    if (($packageList['ok'] ?? false) !== true || !is_array($packageList['domains'] ?? null)) {
        $error = 'Existing hosting packages could not be read';
        webco_provision_fail($db, $projectId, $error);

        return webco_provision_hosting_failed($log, $projectId, $error);
    }

    $domain = (string) $payload['domain_name'];
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
function webco_provision_hosting_claim(PDO $db, int $projectId): ?array
{
    if ($projectId < 1) {
        return null;
    }

    try {
        $db->beginTransaction();
        $select = $db->prepare(
            'SELECT p.id, p.order_id, p.order_public_id, p.vertical_code, p.status AS project_status,
                    p.twentyi_package_id,
                    o.id AS linked_order_id, o.public_id, o.status AS order_status,
                    o.package_code, o.package_name, o.domain_path, o.domain_name,
                    o.business_name, o.contact_name, o.email, o.phone, o.care_choice,
                    o.hosting_status, o.hosting_included_until, o.care_status
             FROM projects p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.id = :id
               AND p.provisioning_status = \'ready\'
               AND o.status = \'paid\'
               AND o.stripe_livemode = 1
               AND o.domain_path = \'existing\'' . webco_for_update($db)
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
