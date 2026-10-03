<?php
/**
 * Dry-run provisioning worker.
 *
 * Claims one ready project at a time, checks the linked paid order, and
 * describes what a later 20i worker would provision. It does not call 20i
 * or Stripe, and it does not mark a project provisioned.
 *
 * Cron or admin tooling should call webco_provision_dry_run(). The claim
 * stays in webco_provision_claim(). This file is not a public page.
 */

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'provision.php') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/projects.php';

/**
 * @param callable(array{event: string, context: array<string, mixed>}): void $log
 * @return array{mode: 'dry_run', ok: bool, results: list<array<string, mixed>>}
 */
function webco_provision_dry_run(PDO $db, callable $log): array
{
    webco_provision_log($log, 'worker_started', ['mode' => 'dry_run']);
    $ids = webco_provision_eligible_ids($db);
    if ($ids === null) {
        webco_provision_log($log, 'worker_stopped', ['reason' => 'database_error']);

        return ['mode' => 'dry_run', 'ok' => false, 'results' => []];
    }

    $results = [];
    foreach ($ids as $projectId) {
        webco_provision_log($log, 'project_discovered', ['project_id' => $projectId]);
        $results[] = webco_provision_process($db, $projectId, $log);
    }

    return ['mode' => 'dry_run', 'ok' => true, 'results' => $results];
}

/**
 * Dry-run one project. A project that is not ready, or whose order is not
 * paid, is skipped and left unchanged.
 *
 * @param callable(array{event: string, context: array<string, mixed>}): void $log
 * @return array<string, mixed>
 */
function webco_provision_dry_run_project(PDO $db, int $projectId, callable $log): array
{
    webco_provision_log($log, 'worker_started', ['mode' => 'dry_run', 'project_id' => $projectId]);

    return webco_provision_process($db, $projectId, $log);
}

/**
 * Ready projects whose linked order is still paid in Stripe Live mode.
 * stripe_livemode must be 1. Test orders (0) and unreconciled orders (NULL)
 * are not eligible. Real provisioning must use this query.
 * Incomplete customer or package data is claimed and then failed by validation,
 * so it is still returned here.
 *
 * @return list<int>|null
 */
function webco_provision_eligible_ids(PDO $db): ?array
{
    try {
        $statement = $db->query(
            'SELECT p.id
             FROM projects p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.provisioning_status = \'ready\'
               AND o.status = \'paid\'
               AND o.stripe_livemode = 1
             ORDER BY p.id'
        );
        if ($statement === false) {
            return null;
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

/**
 * Move ready + paid to in_progress. A second caller gets null and changes nothing.
 * The conditional update is the concurrency guard; the transaction holds the row
 * on MySQL until that update commits.
 *
 * @return array<string, mixed>|null
 */
function webco_provision_claim(PDO $db, int $projectId): ?array
{
    if ($projectId < 1) {
        return null;
    }

    try {
        $db->beginTransaction();
        $select = $db->prepare(
            'SELECT p.id, p.order_id, p.order_public_id, p.vertical_code, p.status AS project_status,
                    o.id AS linked_order_id, o.public_id, o.status AS order_status,
                    o.package_code, o.package_name, o.domain_path, o.domain_name,
                    o.business_name, o.contact_name, o.email, o.phone, o.care_choice,
                    o.hosting_status, o.hosting_included_until, o.care_status
             FROM projects p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.id = :id
               AND p.provisioning_status = \'ready\'
               AND o.status = \'paid\'
               AND o.stripe_livemode = 1' . webco_for_update($db)
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
                 provisioning_error = NULL,
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

/**
 * @param array<string, mixed> $row
 * @return array{ok: true, error: null, payload: array<string, mixed>}|array{ok: false, error: string, payload: null}
 */
function webco_provision_validate(array $row): array
{
    $projectId = (int) ($row['id'] ?? 0);
    $orderId = (int) ($row['order_id'] ?? 0);
    $linkedOrderId = (int) ($row['linked_order_id'] ?? 0);
    $publicId = trim((string) ($row['order_public_id'] ?? ''));
    $orderPublicId = trim((string) ($row['public_id'] ?? ''));
    $orderStatus = (string) ($row['order_status'] ?? '');
    if ($projectId < 1 || $orderId < 1 || $orderId !== $linkedOrderId || $orderStatus !== 'paid' || $publicId === '' || $publicId !== $orderPublicId) {
        return webco_provision_invalid('Order reference does not match the project');
    }
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $publicId)) {
        return webco_provision_invalid('Order reference does not match the project');
    }

    $packageCode = trim((string) ($row['package_code'] ?? ''));
    if ($packageCode === '') {
        return webco_provision_invalid('Missing package code');
    }
    $packageTarget = webco_provision_package_target($packageCode);
    if ($packageTarget === null) {
        return webco_provision_invalid('Unsupported package');
    }

    $packageName = trim((string) ($row['package_name'] ?? ''));
    if ($packageName === '') {
        return webco_provision_invalid('Missing package name');
    }
    $expectedName = $packageTarget === 'professional' ? 'Webco Professional' : 'Webco Essential';
    if ($packageName !== $expectedName) {
        return webco_provision_invalid('Package name does not match package code');
    }

    $vertical = trim((string) ($row['vertical_code'] ?? ''));
    if (webco_vertical_for_stored_package($packageTarget) !== $vertical) {
        return webco_provision_invalid('Vertical does not match package');
    }

    $projectStatus = trim((string) ($row['project_status'] ?? ''));
    if (!webco_project_status_valid($projectStatus)) {
        return webco_provision_invalid('Invalid project status');
    }

    $domainPath = trim((string) ($row['domain_path'] ?? ''));
    if ($domainPath !== 'new' && $domainPath !== 'existing') {
        return webco_provision_invalid('Missing domain choice');
    }

    $domain = strtolower(trim((string) ($row['domain_name'] ?? '')));
    if ($domain === '') {
        return webco_provision_invalid('Missing domain');
    }
    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
        return webco_provision_invalid('Invalid domain');
    }

    $business = trim((string) ($row['business_name'] ?? ''));
    if ($business === '' || strlen($business) > 120) {
        return webco_provision_invalid('Missing business name');
    }
    $contact = trim((string) ($row['contact_name'] ?? ''));
    if ($contact === '' || strlen($contact) > 120) {
        return webco_provision_invalid('Missing contact name');
    }

    $email = strtolower(trim((string) ($row['email'] ?? '')));
    if ($email === '') {
        return webco_provision_invalid('Missing contact email');
    }
    if (strlen($email) > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return webco_provision_invalid('Invalid contact email');
    }

    $care = trim((string) ($row['care_choice'] ?? ''));
    $hostingStatus = webco_provision_nullable($row['hosting_status'] ?? null);
    $hostingUntil = webco_provision_nullable($row['hosting_included_until'] ?? null);
    $careStatus = webco_provision_nullable($row['care_status'] ?? null);
    if ($care === 'managed') {
        $managed = true;
        $standalone = false;
        $hostingMode = 'managed_care';
        if ($hostingStatus !== null && $hostingStatus !== 'included') {
            return webco_provision_invalid('Hosting mode does not match care choice');
        }
        if ($hostingUntil !== null) {
            return webco_provision_invalid('Hosting mode does not match care choice');
        }
    } elseif ($care === 'standard') {
        $managed = false;
        $standalone = true;
        $hostingMode = 'standalone_hosting';
        if ($hostingStatus === 'included' || $careStatus !== null) {
            return webco_provision_invalid('Hosting mode does not match care choice');
        }
    } else {
        return webco_provision_invalid('Unsupported care choice');
    }

    $phone = webco_provision_nullable($row['phone'] ?? null);
    if ($phone !== null && !preg_match('/^(?:0\d{10}|\+44\d{10}|0044\d{10})$/', $phone)) {
        $phone = null;
    }

    $summaryPackage = $packageTarget === 'professional' ? 'Professional' : 'Essential';
    $summaryHosting = $managed ? 'Managed Care hosting' : 'standard hosting';

    return [
        'ok' => true,
        'error' => null,
        'payload' => [
            'project_id' => $projectId,
            'order_public_id' => $publicId,
            'package_code' => $packageTarget,
            'package_target' => $packageTarget,
            'package_name' => $packageName,
            'domain_path' => $domainPath,
            'domain_name' => $domain,
            'business_name' => $business,
            'contact_name' => $contact,
            'email' => $email,
            'phone' => $phone,
            'care_choice' => $care,
            'hosting_mode' => $hostingMode,
            'managed_care' => $managed,
            'standalone_hosting' => $standalone,
            'hosting_status' => $hostingStatus,
            'care_status' => $careStatus,
            'vertical_code' => $vertical,
            'project_status' => $projectStatus,
            'summary' => 'Would provision ' . $summaryPackage . ' website for ' . $domain . ' with ' . $summaryHosting,
        ],
    ];
}

/**
 * Internal package target only. There is no 20i package id here.
 */
function webco_provision_package_target(string $packageCode): ?string
{
    if ($packageCode === 'essential' || $packageCode === 'professional') {
        return $packageCode;
    }

    return null;
}

/**
 * Failed data stays failed. Only the in_progress claim can be marked failed,
 * so a project that has already moved on is left alone.
 */
function webco_provision_fail(PDO $db, int $projectId, string $error): bool
{
    if ($projectId < 1) {
        return false;
    }
    $error = webco_provision_safe_error($error);

    try {
        $statement = $db->prepare(
            'UPDATE projects
             SET provisioning_status = \'failed\',
                 provisioning_error = :error,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND provisioning_status = \'in_progress\''
        );
        $statement->execute([
            'error' => $error,
            'id' => $projectId,
        ]);
    } catch (PDOException) {
        return false;
    }

    return $statement->rowCount() === 1;
}

/**
 * Dry-run success returns the claim to ready. A later live worker that has
 * moved the row past in_progress is not overwritten.
 */
function webco_provision_release(PDO $db, int $projectId): bool
{
    if ($projectId < 1) {
        return false;
    }

    try {
        $statement = $db->prepare(
            'UPDATE projects
             SET provisioning_status = \'ready\',
                 provisioning_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND provisioning_status = \'in_progress\''
        );
        $statement->execute(['id' => $projectId]);
    } catch (PDOException) {
        return false;
    }

    return $statement->rowCount() === 1;
}

/**
 * @param callable(array{event: string, context: array<string, mixed>}): void $log
 * @param array<string, mixed> $context
 */
function webco_provision_log(callable $log, string $event, array $context = []): void
{
    $log([
        'event' => $event,
        'context' => $context,
    ]);
}

/**
 * @param callable(array{event: string, context: array<string, mixed>}): void $log
 * @return array<string, mixed>
 */
function webco_provision_process(PDO $db, int $projectId, callable $log): array
{
    $claimed = webco_provision_claim($db, $projectId);
    if ($claimed === null) {
        $reason = webco_provision_skip_reason($db, $projectId);
        webco_provision_log($log, 'project_skipped', [
            'project_id' => $projectId,
            'reason' => $reason,
        ]);

        return [
            'project_id' => $projectId,
            'outcome' => 'skipped',
            'payload' => null,
            'error' => null,
            'returned_to_ready' => false,
        ];
    }

    webco_provision_log($log, 'project_claimed', ['project_id' => $projectId]);
    $decision = webco_provision_validate($claimed);
    if (!$decision['ok'] || !is_array($decision['payload'])) {
        $error = webco_provision_safe_error($decision['error'] ?? 'Provisioning data is incomplete');
        webco_provision_fail($db, $projectId, $error);
        webco_provision_log($log, 'validation_failed', [
            'project_id' => $projectId,
            'error' => $error,
        ]);

        return [
            'project_id' => $projectId,
            'outcome' => 'failed',
            'payload' => null,
            'error' => $error,
            'returned_to_ready' => false,
        ];
    }

    $payload = $decision['payload'];
    webco_provision_log($log, 'validation_passed', ['project_id' => $projectId]);
    webco_provision_log($log, 'dry_run_generated', [
        'project_id' => $projectId,
        'summary' => $payload['summary'],
        'payload' => $payload,
    ]);
    $released = webco_provision_release($db, $projectId);
    if ($released) {
        webco_provision_log($log, 'project_returned_to_ready', ['project_id' => $projectId]);
    } else {
        webco_provision_log($log, 'project_skipped', [
            'project_id' => $projectId,
            'reason' => 'status_changed',
        ]);
    }

    return [
        'project_id' => $projectId,
        'outcome' => 'dry_run',
        'payload' => $payload,
        'error' => null,
        'returned_to_ready' => $released,
    ];
}

function webco_provision_skip_reason(PDO $db, int $projectId): string
{
    try {
        $statement = $db->prepare(
            'SELECT p.provisioning_status, o.status AS order_status, o.stripe_livemode
             FROM projects p
             LEFT JOIN orders o ON o.id = p.order_id
             WHERE p.id = :id'
        );
        $statement->execute(['id' => $projectId]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return 'not_eligible';
    }
    if ($row === false) {
        return 'not_eligible';
    }

    $status = (string) ($row['provisioning_status'] ?? '');
    if ($status === 'in_progress') {
        return 'already_claimed';
    }
    $livemode = $row['stripe_livemode'] ?? null;
    if ($status !== 'ready' || (string) ($row['order_status'] ?? '') !== 'paid' || (int) $livemode !== 1 || $livemode === null) {
        return 'not_eligible';
    }

    return 'already_claimed';
}

/**
 * @return array{ok: false, error: string, payload: null}
 */
function webco_provision_invalid(string $error): array
{
    return [
        'ok' => false,
        'error' => webco_provision_safe_error($error),
        'payload' => null,
    ];
}

function webco_provision_safe_error(string $error): string
{
    $error = trim(preg_replace('/\s+/', ' ', $error) ?? '');
    if ($error === '') {
        return 'Provisioning data is incomplete';
    }
    if (strlen($error) > 180) {
        return substr($error, 0, 180);
    }

    return $error;
}

function webco_provision_nullable(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim((string) $value);

    return $value === '' ? null : $value;
}
