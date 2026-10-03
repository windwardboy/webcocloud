<?php
/**
 * Paid orders become projects. The order row stays the payment record.
 * Asset files live outside the document root, keyed by the wc_ order id.
 */

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'projects.php') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

const WEBCO_ASSET_MAX_BYTES = 10485760;
const WEBCO_ASSET_MAX_COUNT = 20;

function webco_public_origin(): string
{
    if (defined('WEBCO_PUBLIC_ORIGIN')) {
        return WEBCO_PUBLIC_ORIGIN;
    }

    return 'https://webcocloud.net';
}

/**
 * The current funnel persists only these package codes, and only from the
 * server allowlist in draft-order.php. Both packages are the HGV training offer.
 * A browser-supplied vertical is never read.
 */
function webco_vertical_for_stored_package(string $packageCode): ?string
{
    if ($packageCode === 'essential' || $packageCode === 'professional') {
        return 'hgv_training';
    }

    return null;
}

/**
 * @return list<string>
 */
function webco_project_statuses(): array
{
    return [
        'awaiting_brief',
        'brief_in_progress',
        'brief_received',
        'ready_for_clone',
        'ready_for_build',
        'in_build',
        'review',
        'ready_to_launch',
        'live',
    ];
}

function webco_project_status_valid(string $status): bool
{
    return in_array($status, webco_project_statuses(), true);
}

function webco_project_status_label(string $status): string
{
    return match ($status) {
        'awaiting_brief' => 'Awaiting brief',
        'brief_in_progress' => 'Brief in progress',
        'brief_received' => 'Brief received',
        'ready_for_clone' => 'Ready for clone',
        'ready_for_build' => 'Ready for build',
        'in_build' => 'In build',
        'review' => 'Review',
        'ready_to_launch' => 'Ready to launch',
        'live' => 'Live',
        default => $status,
    };
}

function webco_project_package_id(mixed $value): ?string
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
 * A submitted brief is ready when the original note or any intake answer has text.
 * Call preference alone does not satisfy the build gate.
 *
 * @param list<string|null> $details
 */
function webco_project_brief_is_ready(?string $summary, mixed $submittedAt, array $details = []): bool
{
    if ($submittedAt === null || $submittedAt === '') {
        return false;
    }
    if (is_string($summary) && trim($summary) !== '') {
        return true;
    }
    foreach ($details as $detail) {
        if (is_string($detail) && trim($detail) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function webco_brief_detail_columns(): array
{
    return [
        'business_overview',
        'years_operating',
        'credentials',
        'first_impression',
        'services',
        'course_entries',
        'locations',
        'areas_served',
        'location_entries',
        'goals',
        'why_experience',
        'why_facilities',
        'why_flexibility',
        'why_support',
        'why_difference',
        'style_tone',
        'branding',
        'liked_sites',
        'required_pages',
        'enquiry_route',
        'contact_details',
        'opening_hours',
    ];
}

/**
 * @return list<string>
 */
function webco_brief_wizard_steps(): array
{
    return ['business', 'courses', 'locations', 'why', 'branding', 'contact', 'other', 'review'];
}

function webco_brief_wizard_step_valid(string $step): bool
{
    return in_array($step, webco_brief_wizard_steps(), true);
}

function webco_brief_package_code(string $packageCode, string $packageName = ''): string
{
    if ($packageCode === 'professional' || $packageCode === 'essential') {
        return $packageCode;
    }
    if (stripos($packageName, 'Professional') !== false) {
        return 'professional';
    }

    return 'essential';
}

/**
 * @return list<string>
 */
function webco_request_types(): array
{
    return [
        'website_update',
        'content_change',
        'new_page',
        'image_replacement',
        'contact_change',
        'technical_problem',
        'support_question',
        'other',
    ];
}

function webco_request_type_valid(string $type): bool
{
    return in_array($type, webco_request_types(), true);
}

function webco_request_type_label(string $type): string
{
    return match ($type) {
        'website_update' => 'Website update',
        'content_change' => 'Content change',
        'new_page' => 'New page or course',
        'image_replacement' => 'Image replacement',
        'contact_change' => 'Contact or detail change',
        'technical_problem' => 'Technical problem',
        'support_question' => 'Support question',
        'other' => 'Other',
        default => $type,
    };
}

/**
 * @return list<string>
 */
function webco_request_statuses(): array
{
    return ['open', 'in_progress', 'done'];
}

function webco_request_status_valid(string $status): bool
{
    return in_array($status, webco_request_statuses(), true);
}

function webco_request_status_label(string $status): string
{
    return match ($status) {
        'open' => 'Open',
        'in_progress' => 'In progress',
        'done' => 'Done',
        default => $status,
    };
}

function webco_request_status_next(string $status): ?string
{
    return match ($status) {
        'open' => 'in_progress',
        'in_progress' => 'done',
        default => null,
    };
}

function webco_project_status_sentence(string $status): string
{
    return match ($status) {
        'awaiting_brief' => 'We are waiting for your website brief.',
        'brief_in_progress' => 'Your website brief is in progress.',
        'brief_received' => 'Your website brief has been received. Webco will review it and contact you.',
        'ready_for_clone' => 'Your website is being prepared for build.',
        'ready_for_build' => 'Your website is ready to be built.',
        'in_build' => 'Your website is being built.',
        'review' => 'Your website is in review.',
        'ready_to_launch' => 'Your website is ready to launch.',
        'live' => 'Your website is live.',
        default => webco_project_status_label($status),
    };
}

/**
 * The one status an admin may apply next. Clone completion is a separate action.
 */
function webco_project_workflow_next(string $status): ?string
{
    return match ($status) {
        'brief_received' => 'ready_for_clone',
        'ready_for_build' => 'in_build',
        'in_build' => 'review',
        'review' => 'ready_to_launch',
        'ready_to_launch' => 'live',
        default => null,
    };
}

/**
 * Moves a project one step along the website workflow.
 * Ready for clone requires a submitted, non-empty brief.
 */
function webco_advance_project_status(PDO $db, int $projectId): bool
{
    if ($projectId < 1) {
        return false;
    }

    try {
        $db->beginTransaction();
        $select = $db->prepare(
            'SELECT p.status, b.summary, b.submitted_at
             FROM projects p
             LEFT JOIN project_briefs b ON b.project_id = p.id
             WHERE p.id = :id' . webco_for_update($db)
        );
        $select->execute(['id' => $projectId]);
        $row = $select->fetch();
        if ($row === false) {
            $db->rollBack();

            return false;
        }

        $status = (string) ($row['status'] ?? '');
        $next = webco_project_workflow_next($status);
        if ($next === null) {
            $db->rollBack();

            return false;
        }
        if ($next === 'ready_for_clone' && !webco_project_brief_is_ready(
            is_string($row['summary'] ?? null) ? $row['summary'] : null,
            $row['submitted_at'] ?? null,
            webco_brief_detail_values($db, $projectId)
        )) {
            $db->rollBack();

            return false;
        }

        $update = $db->prepare(
            'UPDATE projects
             SET status = :status, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = :current'
        );
        $update->execute([
            'status' => $next,
            'id' => $projectId,
            'current' => $status,
        ]);
        if ($update->rowCount() !== 1) {
            $db->rollBack();

            return false;
        }
        $db->commit();
    } catch (PDOException) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        return false;
    }

    return true;
}

/**
 * Stores the hosting package created by a manual 20i clone.
 * A second attempt does not replace the stored id or the status.
 *
 * @return 'saved'|'duplicate'|'invalid'|'error'
 */
function webco_record_cloned_package(PDO $db, int $projectId, string $packageId): string
{
    $packageId = webco_project_package_id($packageId) ?? '';
    if ($projectId < 1 || $packageId === '') {
        return 'invalid';
    }

    try {
        $db->beginTransaction();
        $select = $db->prepare(
            'SELECT status, twentyi_package_id
             FROM projects
             WHERE id = :id' . webco_for_update($db)
        );
        $select->execute(['id' => $projectId]);
        $row = $select->fetch();
        if ($row === false) {
            $db->rollBack();

            return 'error';
        }
        if ((string) ($row['status'] ?? '') !== 'ready_for_clone') {
            $db->rollBack();

            return 'duplicate';
        }
        $stored = webco_project_package_id($row['twentyi_package_id'] ?? null);
        if ($stored !== null) {
            $db->rollBack();

            return 'duplicate';
        }

        $update = $db->prepare(
            'UPDATE projects
             SET twentyi_package_id = :package_id,
                 status = \'ready_for_build\',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND status = \'ready_for_clone\'
               AND (twentyi_package_id IS NULL OR twentyi_package_id = \'\')'
        );
        $update->execute([
            'package_id' => $packageId,
            'id' => $projectId,
        ]);
        if ($update->rowCount() !== 1) {
            $db->rollBack();

            return 'duplicate';
        }
        $db->commit();
    } catch (PDOException $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $sqlState = $exception->errorInfo[0] ?? '';
        if ($sqlState === '23000') {
            return 'duplicate';
        }

        return 'error';
    }

    return 'saved';
}

function webco_ensure_project_tables(PDO $db): bool
{
    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS projects (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                order_public_id CHAR(23) NOT NULL,
                vertical_code VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT \'awaiting_brief\',
                provisioning_status VARCHAR(32) NOT NULL DEFAULT \'waiting_payment\',
                provisioned_at DATETIME NULL,
                provisioning_error TEXT NULL,
                twentyi_package_id VARCHAR(32) NULL,
                provisioning_attempted_at DATETIME NULL,
                brief_token_hash CHAR(64) NOT NULL,
                customer_notified_at TIMESTAMP NULL DEFAULT NULL,
                internal_notified_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY projects_order_id (order_id),
                UNIQUE KEY projects_order_public_id (order_public_id),
                UNIQUE KEY projects_brief_token_hash (brief_token_hash),
                UNIQUE KEY projects_twentyi_package_id (twentyi_package_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS project_briefs (
                project_id BIGINT UNSIGNED NOT NULL,
                summary TEXT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                submitted_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (project_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS project_assets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                category VARCHAR(16) NOT NULL,
                storage_name VARCHAR(48) NOT NULL,
                original_name VARCHAR(180) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                size_bytes INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY project_assets_project_category (project_id, category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        if (!webco_ensure_project_provisioning_columns($db)) {
            return false;
        }
        if (!webco_ensure_brief_detail_columns($db)) {
            return false;
        }
        if (!webco_ensure_project_requests_table($db)) {
            return false;
        }
        if (!webco_ensure_asset_request_column($db)) {
            return false;
        }
        if (!webco_ensure_project_archive_column($db)) {
            return false;
        }
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * Adds intake answers to a brief table created with only the original note.
 */
function webco_ensure_brief_detail_columns(PDO $db): bool
{
    $columns = [
        'business_overview' => 'TEXT NULL',
        'services' => 'TEXT NULL',
        'locations' => 'TEXT NULL',
        'goals' => 'TEXT NULL',
        'style_tone' => 'TEXT NULL',
        'branding' => 'TEXT NULL',
        'liked_sites' => 'TEXT NULL',
        'required_pages' => 'TEXT NULL',
        'years_operating' => 'VARCHAR(160) NULL',
        'credentials' => 'TEXT NULL',
        'first_impression' => 'TEXT NULL',
        'course_entries' => 'TEXT NULL',
        'areas_served' => 'TEXT NULL',
        'location_entries' => 'TEXT NULL',
        'why_experience' => 'TEXT NULL',
        'why_facilities' => 'TEXT NULL',
        'why_flexibility' => 'TEXT NULL',
        'why_support' => 'TEXT NULL',
        'why_difference' => 'TEXT NULL',
        'enquiry_route' => 'VARCHAR(20) NULL',
        'contact_details' => 'TEXT NULL',
        'opening_hours' => 'VARCHAR(400) NULL',
        'wizard_step' => 'VARCHAR(32) NULL',
        'call_requested' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'call_number' => 'VARCHAR(40) NULL',
        'call_time' => 'VARCHAR(120) NULL',
        'call_note' => 'TEXT NULL',
    ];

    return webco_ensure_columns($db, 'project_briefs', $columns);
}

function webco_ensure_project_requests_table(PDO $db): bool
{
    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS project_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                request_type VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT \'open\',
                summary TEXT NOT NULL,
                call_requested TINYINT(1) NOT NULL DEFAULT 0,
                call_number VARCHAR(40) NULL,
                call_time VARCHAR(120) NULL,
                call_note TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                completed_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY project_requests_project_status (project_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * Existing brief files keep a null request id. New files may name one request.
 */
function webco_ensure_asset_request_column(PDO $db): bool
{
    return webco_ensure_columns($db, 'project_assets', [
        'request_id' => 'BIGINT UNSIGNED NULL',
    ]);
}

/**
 * Hides a project from the working dashboard without changing its build status.
 */
function webco_ensure_project_archive_column(PDO $db): bool
{
    return webco_ensure_columns($db, 'projects', [
        'archived_at' => 'DATETIME NULL',
    ]);
}

/**
 * @param array<string, string> $columns
 */
function webco_ensure_columns(PDO $db, string $table, array $columns): bool
{
    if (!preg_match('/^[a-z_]+$/', $table)) {
        return false;
    }

    try {
        $existing = webco_table_column_set($db, $table);
        if ($existing === null) {
            return false;
        }
        foreach ($columns as $name => $definition) {
            if (!preg_match('/^[a-z_]+$/', $name) || isset($existing[$name])) {
                continue;
            }
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $definition);
        }
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * @return list<string>
 */
function webco_provisioning_statuses(): array
{
    return ['waiting_payment', 'ready', 'in_progress', 'provisioned', 'failed'];
}

function webco_provisioning_status_valid(string $status): bool
{
    return in_array($status, webco_provisioning_statuses(), true);
}

function webco_ensure_project_provisioning_columns(PDO $db): bool
{
    try {
        $existing = webco_table_column_set($db, 'projects');
        if ($existing === null) {
            return false;
        }
        if (!isset($existing['provisioning_status'])) {
            $db->exec(
                'ALTER TABLE projects ADD COLUMN provisioning_status VARCHAR(32) NOT NULL DEFAULT \'waiting_payment\''
            );
        }
        if (!isset($existing['provisioned_at'])) {
            $db->exec('ALTER TABLE projects ADD COLUMN provisioned_at DATETIME NULL');
        }
        if (!isset($existing['provisioning_error'])) {
            $db->exec('ALTER TABLE projects ADD COLUMN provisioning_error TEXT NULL');
        }
        if (!isset($existing['twentyi_package_id'])) {
            $db->exec('ALTER TABLE projects ADD COLUMN twentyi_package_id VARCHAR(32) NULL');
        }
        if (!isset($existing['provisioning_attempted_at'])) {
            $db->exec('ALTER TABLE projects ADD COLUMN provisioning_attempted_at DATETIME NULL');
        }
        if (!webco_ensure_project_unique_index($db, 'projects_twentyi_package_id', 'twentyi_package_id')) {
            return false;
        }
        if (!webco_ensure_check_constraint(
            $db,
            'projects',
            'projects_provisioning_status_check',
            'provisioning_status IN (\'waiting_payment\', \'ready\', \'in_progress\', \'provisioned\', \'failed\')'
        )) {
            return false;
        }
    } catch (PDOException) {
        return false;
    }

    return true;
}

function webco_ensure_project_unique_index(PDO $db, string $index, string $column): bool
{
    if (!preg_match('/^[a-z_]+$/', $index) || !preg_match('/^[a-z_]+$/', $column)) {
        return false;
    }

    try {
        $indexes = $db->query('SHOW INDEX FROM projects');
        if ($indexes === false) {
            return false;
        }
        foreach ($indexes->fetchAll() as $row) {
            if (($row['Key_name'] ?? '') === $index) {
                return true;
            }
        }
        $db->exec(
            'ALTER TABLE projects ADD UNIQUE KEY ' . $index . ' (' . $column . ')'
        );
    } catch (PDOException) {
        return false;
    }

    return true;
}

function webco_mark_project_ready(PDO $db, int $projectId): bool
{
    if ($projectId < 1) {
        return false;
    }

    try {
        $update = $db->prepare(
            'UPDATE projects
             SET provisioning_status = \'ready\', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND provisioning_status = \'waiting_payment\''
        );
        $update->execute(['id' => $projectId]);
        if ($update->rowCount() === 1) {
            return true;
        }

        $select = $db->prepare(
            'SELECT provisioning_status FROM projects WHERE id = :id'
        );
        $select->execute(['id' => $projectId]);
        $row = $select->fetch();
    } catch (PDOException) {
        return false;
    }
    if ($row === false) {
        return false;
    }

    return webco_provisioning_status_valid((string) ($row['provisioning_status'] ?? ''))
        && (string) ($row['provisioning_status'] ?? '') !== 'waiting_payment';
}

/**
 * Creates the project for a paid order once, then sends any missing notices.
 * The project stays off the automatic hosting worker. A manual clone records
 * the 20i package id later. Returns ok when the project row and asset
 * folders exist. Mail failure stays ok.
 *
 * @return 'ok'|'error'
 */
function webco_ensure_paid_project(PDO $db, string $publicId): string
{
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $publicId)) {
        return 'error';
    }
    if (!webco_ensure_project_tables($db)) {
        return 'error';
    }

    $token = null;
    $projectId = 0;
    try {
        $db->beginTransaction();
        $order = webco_lock_paid_order($db, $publicId);
        if ($order === null) {
            $db->rollBack();
            return 'error';
        }

        $vertical = webco_vertical_for_stored_package($order['package_code']);
        if ($vertical === null) {
            $db->rollBack();
            return 'error';
        }

        $projectId = webco_lock_project_id($db, $order['id']);
        if ($projectId === null) {
            $token = bin2hex(random_bytes(32));
            $insert = $db->prepare(
                'INSERT INTO projects (
                    order_id, order_public_id, vertical_code, status, provisioning_status, brief_token_hash
                 ) VALUES (
                    :order_id, :order_public_id, :vertical_code, \'awaiting_brief\', \'waiting_payment\', :brief_token_hash
                 )'
            );
            $insert->execute([
                'order_id' => $order['id'],
                'order_public_id' => $order['public_id'],
                'vertical_code' => $vertical,
                'brief_token_hash' => hash('sha256', $token),
            ]);
            $projectId = (int) $db->lastInsertId();
            if ($projectId < 1) {
                $db->rollBack();
                return 'error';
            }

            $brief = $db->prepare(
                'INSERT INTO project_briefs (project_id, summary, updated_at, submitted_at)
                 VALUES (:project_id, NULL, NULL, NULL)'
            );
            $brief->execute(['project_id' => $projectId]);
        }

        $db->commit();
    } catch (PDOException $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $sqlState = $exception->errorInfo[0] ?? '';
        if ($sqlState !== '23000') {
            return 'error';
        }
        $token = null;
        $projectId = webco_project_id_for_order_public_id($db, $publicId) ?? 0;
    }

    if ($projectId < 1 || !webco_provision_project_dirs($publicId)) {
        return 'error';
    }

    webco_send_project_notifications($db, $projectId, $token);

    return 'ok';
}

/**
 * @return array{id: int, public_id: string, package_code: string}|null
 */
function webco_lock_paid_order(PDO $db, string $publicId): ?array
{
    $statement = $db->prepare(
        'SELECT id, public_id, package_code
         FROM orders
         WHERE public_id = :public_id AND status = \'paid\'
         FOR UPDATE'
    );
    $statement->execute(['public_id' => $publicId]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }

    $id = (int) ($row['id'] ?? 0);
    $storedPublicId = (string) ($row['public_id'] ?? '');
    $packageCode = (string) ($row['package_code'] ?? '');
    if ($id < 1 || $storedPublicId !== $publicId) {
        return null;
    }

    return [
        'id' => $id,
        'public_id' => $storedPublicId,
        'package_code' => $packageCode,
    ];
}

function webco_lock_project_id(PDO $db, int $orderId): ?int
{
    $statement = $db->prepare(
        'SELECT id FROM projects WHERE order_id = :order_id FOR UPDATE'
    );
    $statement->execute(['order_id' => $orderId]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }

    $id = (int) ($row['id'] ?? 0);

    return $id > 0 ? $id : null;
}

function webco_project_id_for_order_public_id(PDO $db, string $publicId): ?int
{
    try {
        $statement = $db->prepare(
            'SELECT id FROM projects WHERE order_public_id = :order_public_id'
        );
        $statement->execute(['order_public_id' => $publicId]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return null;
    }
    if ($row === false) {
        return null;
    }

    $id = (int) ($row['id'] ?? 0);

    return $id > 0 ? $id : null;
}

function webco_projects_root(): ?string
{
    if (!defined('WEBCO_SECRETS_FILE')) {
        return null;
    }

    return dirname(WEBCO_SECRETS_FILE) . DIRECTORY_SEPARATOR . 'webco-projects';
}

function webco_provision_project_dirs(string $orderPublicId): bool
{
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $orderPublicId)) {
        return false;
    }

    $root = webco_projects_root();
    if ($root === null || !webco_mkdir_private($root)) {
        return false;
    }

    $guard = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($guard)) {
        $written = file_put_contents($guard, "Require all denied\n");
        if ($written === false) {
            return false;
        }
    }

    $base = $root . DIRECTORY_SEPARATOR . $orderPublicId;
    if (!webco_mkdir_private($base)) {
        return false;
    }

    foreach (['logos', 'photos', 'documents'] as $folder) {
        if (!webco_mkdir_private($base . DIRECTORY_SEPARATOR . $folder)) {
            return false;
        }
    }

    return true;
}

function webco_mkdir_private(string $path): bool
{
    if (!is_dir($path)) {
        if (is_file($path) || (!mkdir($path, 0700, true) && !is_dir($path))) {
            return false;
        }
    }

    @chmod($path, 0700);

    return is_dir($path) && is_writable($path);
}

function webco_send_project_notifications(PDO $db, int $projectId, ?string $knownToken): void
{
    $token = webco_claim_customer_notification($db, $projectId, $knownToken);
    if ($token !== null) {
        $row = webco_project_notification_row($db, $projectId);
        $sent = false;
        if ($row !== null) {
            $url = webco_public_origin() . '/brief.php?access=' . $token;
            $sent = webco_mail_customer_brief(
                $row['email'],
                $row['contact_name'],
                $row['order_public_id'],
                $url
            );
        }
        if (!$sent) {
            webco_release_customer_notification($db, $projectId, $token);
        }
    }

    if (!webco_claim_internal_notification($db, $projectId)) {
        return;
    }

    $row = webco_project_notification_row($db, $projectId);
    $sent = false;
    if ($row !== null) {
        $sent = webco_mail_internal_project(
            $row['order_public_id'],
            $row['business_name'],
            $row['contact_name'],
            $row['email'],
            $row['domain_name'],
            $row['package_name'],
            $row['care_choice'],
            $row['vertical_code'],
            $row['status']
        );
    }
    if (!$sent) {
        webco_release_internal_notification($db, $projectId);
    }
}

function webco_claim_customer_notification(PDO $db, int $projectId, ?string $knownToken): ?string
{
    if ($projectId < 1) {
        return null;
    }

    $token = $knownToken;
    if ($token === null || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        $knownToken = null;
    }

    try {
        if ($knownToken === null) {
            $statement = $db->prepare(
                'UPDATE projects
                 SET brief_token_hash = :hash,
                     customer_notified_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND customer_notified_at IS NULL'
            );
            $statement->execute([
                'hash' => hash('sha256', $token),
                'id' => $projectId,
            ]);
        } else {
            $statement = $db->prepare(
                'UPDATE projects
                 SET brief_token_hash = :hash,
                     customer_notified_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND customer_notified_at IS NULL
                   AND brief_token_hash = :expected_hash'
            );
            $statement->execute([
                'hash' => hash('sha256', $token),
                'id' => $projectId,
                'expected_hash' => hash('sha256', $token),
            ]);
        }
    } catch (PDOException) {
        return null;
    }

    if ($statement->rowCount() !== 1) {
        return null;
    }

    return $token;
}

function webco_release_customer_notification(PDO $db, int $projectId, string $token): void
{
    if ($projectId < 1 || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return;
    }

    try {
        $statement = $db->prepare(
            'UPDATE projects
             SET customer_notified_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND customer_notified_at IS NOT NULL
               AND brief_token_hash = :hash'
        );
        $statement->execute([
            'id' => $projectId,
            'hash' => hash('sha256', $token),
        ]);
    } catch (PDOException) {
        return;
    }
}

function webco_claim_internal_notification(PDO $db, int $projectId): bool
{
    if ($projectId < 1) {
        return false;
    }

    try {
        $statement = $db->prepare(
            'UPDATE projects
             SET internal_notified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND internal_notified_at IS NULL'
        );
        $statement->execute(['id' => $projectId]);
    } catch (PDOException) {
        return false;
    }

    return $statement->rowCount() === 1;
}

function webco_release_internal_notification(PDO $db, int $projectId): void
{
    if ($projectId < 1) {
        return;
    }

    try {
        $statement = $db->prepare(
            'UPDATE projects
             SET internal_notified_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND internal_notified_at IS NOT NULL'
        );
        $statement->execute(['id' => $projectId]);
    } catch (PDOException) {
        return;
    }
}

/**
 * @return array{
 *   order_public_id: string,
 *   vertical_code: string,
 *   status: string,
 *   customer_notified_at: ?string,
 *   internal_notified_at: ?string,
 *   business_name: string,
 *   contact_name: string,
 *   email: string,
 *   domain_name: string,
 *   package_name: string,
 *   care_choice: string
 * }|null
 */
function webco_project_notification_row(PDO $db, int $projectId): ?array
{
    if ($projectId < 1) {
        return null;
    }

    try {
        $statement = $db->prepare(
            'SELECT p.order_public_id, p.vertical_code, p.status,
                    p.customer_notified_at, p.internal_notified_at,
                    o.business_name, o.contact_name, o.email, o.domain_name,
                    o.package_name, o.care_choice
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

    return [
        'order_public_id' => (string) ($row['order_public_id'] ?? ''),
        'vertical_code' => (string) ($row['vertical_code'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'customer_notified_at' => webco_nullable_string($row['customer_notified_at'] ?? null),
        'internal_notified_at' => webco_nullable_string($row['internal_notified_at'] ?? null),
        'business_name' => (string) ($row['business_name'] ?? ''),
        'contact_name' => (string) ($row['contact_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'domain_name' => (string) ($row['domain_name'] ?? ''),
        'package_name' => (string) ($row['package_name'] ?? ''),
        'care_choice' => (string) ($row['care_choice'] ?? ''),
    ];
}

function webco_project_id_for_brief_token(PDO $db, string $token): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    try {
        $statement = $db->prepare(
            'SELECT id FROM projects WHERE brief_token_hash = :hash'
        );
        $statement->execute(['hash' => hash('sha256', $token)]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return null;
    }
    if ($row === false) {
        return null;
    }

    $id = (int) ($row['id'] ?? 0);

    return $id > 0 ? $id : null;
}

/**
 * @return array{
 *   id: int,
 *   order_public_id: string,
 *   status: string,
 *   business_name: string,
 *   summary: string,
 *   submitted_at: ?string,
 *   phone: string,
 *   business_overview: string,
 *   services: string,
 *   locations: string,
 *   goals: string,
 *   style_tone: string,
 *   branding: string,
 *   liked_sites: string,
 *   required_pages: string,
 *   call_requested: bool,
 *   call_number: string,
 *   call_time: string,
 *   call_note: string,
 *   assets: array<string, list<array{original_name: string, size_bytes: int, request_id: ?int}>>,
 *   requests: list<array<string, mixed>>
 * }|null
 */
function webco_customer_project(PDO $db, int $projectId): ?array
{
    if ($projectId < 1) {
        return null;
    }

    $detailSql = implode(', ', array_map(
        static fn (string $column): string => 'b.' . $column,
        webco_brief_detail_columns()
    ));

    try {
        $statement = $db->prepare(
            'SELECT p.id, p.order_public_id, p.status, o.business_name, o.phone,
                    o.package_code, o.package_name,
                    b.summary, b.submitted_at, b.wizard_step,
                    b.call_requested, b.call_number, b.call_time, b.call_note,
                    ' . $detailSql . '
             FROM projects p
             INNER JOIN orders o ON o.id = p.order_id
             LEFT JOIN project_briefs b ON b.project_id = p.id
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

    $project = [
        'id' => (int) $row['id'],
        'order_public_id' => (string) ($row['order_public_id'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'business_name' => (string) ($row['business_name'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'package_code' => webco_brief_package_code(
            (string) ($row['package_code'] ?? ''),
            (string) ($row['package_name'] ?? '')
        ),
        'package_name' => (string) ($row['package_name'] ?? ''),
        'summary' => (string) ($row['summary'] ?? ''),
        'wizard_step' => (string) ($row['wizard_step'] ?? ''),
        'submitted_at' => webco_nullable_string($row['submitted_at'] ?? null),
        'call_requested' => (int) ($row['call_requested'] ?? 0) === 1,
        'call_number' => (string) ($row['call_number'] ?? ''),
        'call_time' => (string) ($row['call_time'] ?? ''),
        'call_note' => (string) ($row['call_note'] ?? ''),
        'assets' => webco_project_assets($db, $projectId),
        'requests' => webco_project_requests($db, $projectId),
    ];
    foreach (webco_brief_detail_columns() as $column) {
        $project[$column] = (string) ($row[$column] ?? '');
    }

    return $project;
}

/**
 * @return array<string, list<array{original_name: string, size_bytes: int, request_id: ?int}>>
 */
function webco_project_assets(PDO $db, int $projectId): array
{
    $grouped = [
        'logo' => [],
        'photo' => [],
        'document' => [],
    ];

    try {
        $statement = $db->prepare(
            'SELECT category, original_name, size_bytes, request_id
             FROM project_assets
             WHERE project_id = :project_id
             ORDER BY id'
        );
        $statement->execute(['project_id' => $projectId]);
        $rows = $statement->fetchAll();
    } catch (PDOException) {
        return $grouped;
    }

    foreach ($rows as $row) {
        $category = (string) ($row['category'] ?? '');
        if (!isset($grouped[$category])) {
            continue;
        }
        $requestId = $row['request_id'] ?? null;
        $grouped[$category][] = [
            'original_name' => (string) ($row['original_name'] ?? ''),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'request_id' => is_numeric($requestId) && (int) $requestId > 0 ? (int) $requestId : null,
        ];
    }

    return $grouped;
}

/**
 * Saves the initial website brief. Later support requests must not call this.
 *
 * @param array<string, mixed> $fields
 * @return 'saved'|'submitted'|'invalid'|'error'
 */
function webco_save_customer_brief(PDO $db, int $projectId, array $fields, bool $submit): string
{
    $stored = webco_brief_input($fields);
    if ($stored === null || $projectId < 1) {
        return 'invalid';
    }

    try {
        $db->beginTransaction();
        $locked = $db->prepare(
            'SELECT status FROM projects WHERE id = :id' . webco_for_update($db)
        );
        $locked->execute(['id' => $projectId]);
        $project = $locked->fetch();
        if ($project === false) {
            $db->rollBack();
            return 'error';
        }

        $status = (string) ($project['status'] ?? '');
        $columns = webco_brief_save_columns();
        $currentStatement = $db->prepare(
            'SELECT ' . implode(', ', $columns) . ' FROM project_briefs WHERE project_id = :project_id'
        );
        $currentStatement->execute(['project_id' => $projectId]);
        $current = $currentStatement->fetch();
        $merged = webco_brief_merge(is_array($current) ? $current : [], $stored);
        $assignments = [];
        foreach ($columns as $column) {
            $assignments[] = $column . ' = :' . $column;
        }
        $brief = $db->prepare(
            'UPDATE project_briefs
             SET ' . implode(', ', $assignments) . ',
                 updated_at = CURRENT_TIMESTAMP
             WHERE project_id = :project_id'
        );
        $brief->execute($merged + ['project_id' => $projectId]);

        $result = 'saved';
        if ($submit) {
            if ($status === 'awaiting_brief' || $status === 'brief_in_progress') {
                $advance = $db->prepare(
                    'UPDATE projects
                     SET status = \'brief_received\', updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND status IN (\'awaiting_brief\', \'brief_in_progress\')'
                );
                $advance->execute(['id' => $projectId]);
            }
            $stamp = $db->prepare(
                'UPDATE project_briefs
                 SET submitted_at = CURRENT_TIMESTAMP
                 WHERE project_id = :project_id AND submitted_at IS NULL'
            );
            $stamp->execute(['project_id' => $projectId]);
            $result = 'submitted';
        } elseif ($status === 'awaiting_brief') {
            $advance = $db->prepare(
                'UPDATE projects
                 SET status = \'brief_in_progress\', updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status = \'awaiting_brief\''
            );
            $advance->execute(['id' => $projectId]);
        }

        $db->commit();
    } catch (PDOException) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        return 'error';
    }

    return $result;
}

/**
 * @param array<mixed> $file
 * @return 'uploaded'|'upload_type'|'upload_size'|'upload_limit'|'upload_failed'
 */
function webco_store_customer_upload(PDO $db, int $projectId, string $category, array $file, ?int $requestId = null): string
{
    $rules = webco_asset_rules($category);
    if ($rules === null || $projectId < 1) {
        return 'upload_failed';
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return 'upload_size';
    }
    if ($error !== UPLOAD_ERR_OK || is_array($file['tmp_name'] ?? null) || is_array($file['name'] ?? null)) {
        return 'upload_failed';
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return 'upload_failed';
    }
    if ($size < 1 || $size > WEBCO_ASSET_MAX_BYTES) {
        return 'upload_size';
    }

    $checked = webco_checked_upload($tmp, $rules);
    if ($checked === null) {
        return 'upload_type';
    }

    try {
        $statement = $db->prepare(
            'SELECT order_public_id, status FROM projects WHERE id = :id'
        );
        $statement->execute(['id' => $projectId]);
        $project = $statement->fetch();
        if ($project === false) {
            return 'upload_failed';
        }

        $ownedRequest = webco_asset_request_for_project($db, $projectId, $requestId);
        if ($ownedRequest === false) {
            return 'upload_failed';
        }

        $counted = $db->prepare(
            'SELECT COUNT(*) FROM project_assets
             WHERE project_id = :project_id AND category = :category
               AND (
                    (:request_id IS NULL AND request_id IS NULL)
                    OR request_id = :request_match
               )'
        );
        $counted->execute([
            'project_id' => $projectId,
            'category' => $category,
            'request_id' => $ownedRequest,
            'request_match' => $ownedRequest,
        ]);
        $assetCount = (int) $counted->fetchColumn();
    } catch (PDOException) {
        return 'upload_failed';
    }
    if ($assetCount >= WEBCO_ASSET_MAX_COUNT) {
        return 'upload_limit';
    }

    $orderPublicId = (string) ($project['order_public_id'] ?? '');
    if (!webco_provision_project_dirs($orderPublicId)) {
        return 'upload_failed';
    }

    $folder = webco_project_folder($orderPublicId, $rules['folder']);
    if ($folder === null) {
        return 'upload_failed';
    }

    $storageName = bin2hex(random_bytes(16)) . '.' . $checked['extension'];
    $destination = $folder . DIRECTORY_SEPARATOR . $storageName;
    if (!move_uploaded_file($tmp, $destination)) {
        return 'upload_failed';
    }
    @chmod($destination, 0600);
    if (!is_file($destination)) {
        return 'upload_failed';
    }

    try {
        $db->beginTransaction();
        if (!webco_save_project_asset_row(
            $db,
            $projectId,
            $category,
            $storageName,
            webco_original_upload_name((string) ($file['name'] ?? '')),
            $checked['mime'],
            $size,
            $ownedRequest
        )) {
            $db->rollBack();
            @unlink($destination);

            return 'upload_failed';
        }
        if ((string) ($project['status'] ?? '') === 'awaiting_brief') {
            $advance = $db->prepare(
                'UPDATE projects
                 SET status = \'brief_in_progress\', updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status = \'awaiting_brief\''
            );
            $advance->execute(['id' => $projectId]);
        }
        $db->commit();
    } catch (PDOException) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        @unlink($destination);

        return 'upload_failed';
    }

    return 'uploaded';
}

/**
 * @param array{folder: string, mimes: array<string, string>} $rules
 * @return array{mime: string, extension: string}|null
 */
function webco_checked_upload(string $tmp, array $rules): ?array
{
    if (!class_exists('finfo')) {
        return null;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!is_string($mime) || !isset($rules['mimes'][$mime])) {
        return null;
    }

    $extension = $rules['mimes'][$mime];
    if ($extension === 'pdf') {
        $head = file_get_contents($tmp, false, null, 0, 5);
        if ($head !== '%PDF-') {
            return null;
        }
    } else {
        $info = @getimagesize($tmp);
        $expected = match ($extension) {
            'jpg' => IMAGETYPE_JPEG,
            'png' => IMAGETYPE_PNG,
            'webp' => IMAGETYPE_WEBP,
            default => 0,
        };
        if (!is_array($info) || (int) ($info[2] ?? 0) !== $expected) {
            return null;
        }
    }

    return [
        'mime' => $mime,
        'extension' => $extension,
    ];
}

/**
 * @return array{folder: string, mimes: array<string, string>}|null
 */
function webco_asset_rules(string $category): ?array
{
    if ($category === 'logo' || $category === 'photo') {
        return [
            'folder' => $category === 'logo' ? 'logos' : 'photos',
            'mimes' => [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ],
        ];
    }
    if ($category === 'document') {
        return [
            'folder' => 'documents',
            'mimes' => [
                'application/pdf' => 'pdf',
            ],
        ];
    }

    return null;
}

function webco_project_folder(string $orderPublicId, string $folder): ?string
{
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $orderPublicId)) {
        return null;
    }
    if (!in_array($folder, ['logos', 'photos', 'documents'], true)) {
        return null;
    }

    $root = webco_projects_root();
    if ($root === null) {
        return null;
    }

    return $root . DIRECTORY_SEPARATOR . $orderPublicId . DIRECTORY_SEPARATOR . $folder;
}

/**
 * @return list<string>
 */
function webco_brief_detail_values(PDO $db, int $projectId): array
{
    if ($projectId < 1) {
        return [];
    }

    $columns = implode(', ', webco_brief_detail_columns());
    try {
        $statement = $db->prepare(
            'SELECT ' . $columns . ' FROM project_briefs WHERE project_id = :project_id'
        );
        $statement->execute(['project_id' => $projectId]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return [];
    }
    if ($row === false) {
        return [];
    }

    $values = [];
    foreach (webco_brief_detail_columns() as $column) {
        $value = $row[$column] ?? null;
        $values[] = is_string($value) ? $value : null;
    }

    return $values;
}

/**
 * @param array<string, mixed> $fields
 * @return array<string, int|string|null>|null
 */
/**
 * @return list<string>
 */
function webco_brief_save_columns(): array
{
    return array_merge(['summary'], webco_brief_detail_columns(), [
        'wizard_step',
        'call_requested',
        'call_number',
        'call_time',
        'call_note',
    ]);
}

/**
 * @param array<string, mixed> $fields
 * @return array<string, int|string|null>|null
 */
function webco_brief_input(array $fields): ?array
{
    $limits = [
        'summary' => 8000,
        'business_overview' => 4000,
        'years_operating' => 160,
        'credentials' => 2000,
        'first_impression' => 2000,
        'services' => 4000,
        'locations' => 2000,
        'areas_served' => 2000,
        'goals' => 2000,
        'why_experience' => 2000,
        'why_facilities' => 2000,
        'why_flexibility' => 2000,
        'why_support' => 2000,
        'why_difference' => 2000,
        'style_tone' => 2000,
        'branding' => 2000,
        'liked_sites' => 4000,
        'required_pages' => 4000,
        'contact_details' => 2000,
        'opening_hours' => 400,
    ];
    $stored = [];
    foreach ($limits as $name => $max) {
        if (!array_key_exists($name, $fields)) {
            continue;
        }
        $text = webco_brief_text($fields[$name], $max);
        if ($text === null) {
            return null;
        }
        $stored[$name] = $text;
    }

    if (array_key_exists('course_entries', $fields)) {
        $courses = webco_brief_normalize_pairs($fields['course_entries'], 6, 120, 1000);
        if ($courses === null) {
            return null;
        }
        $stored['course_entries'] = $courses;
    }
    if (array_key_exists('location_entries', $fields)) {
        $locations = webco_brief_normalize_pairs($fields['location_entries'], 4, 120, 1000);
        if ($locations === null) {
            return null;
        }
        $stored['location_entries'] = $locations;
    }
    if (array_key_exists('enquiry_route', $fields)) {
        $route = $fields['enquiry_route'];
        if (!is_string($route) || !in_array($route, ['', 'phone', 'email', 'either'], true)) {
            return null;
        }
        $stored['enquiry_route'] = $route;
    }
    if (array_key_exists('wizard_step', $fields)) {
        $step = $fields['wizard_step'];
        if (!is_string($step) || !webco_brief_wizard_step_valid($step)) {
            return null;
        }
        $stored['wizard_step'] = $step;
    }
    if (array_key_exists('call_requested', $fields)) {
        $requested = $fields['call_requested'];
        $callRequested = $requested === true || $requested === 1 || $requested === '1' || $requested === 'yes';
        $number = webco_brief_text($fields['call_number'] ?? '', 40);
        $time = webco_brief_text($fields['call_time'] ?? '', 120);
        $note = webco_brief_text($fields['call_note'] ?? '', 2000);
        if ($number === null || $time === null || $note === null) {
            return null;
        }
        $stored['call_requested'] = $callRequested ? 1 : 0;
        $stored['call_number'] = $number === '' ? null : $number;
        $stored['call_time'] = $time === '' ? null : $time;
        $stored['call_note'] = $note === '' ? null : $note;
    }

    return $stored;
}

/**
 * @param array<string, mixed> $current
 * @param array<string, int|string|null> $patch
 * @return array<string, int|string|null>
 */
function webco_brief_merge(array $current, array $patch): array
{
    $merged = [];
    foreach (webco_brief_save_columns() as $column) {
        if (array_key_exists($column, $patch)) {
            $merged[$column] = $patch[$column];
            continue;
        }
        $merged[$column] = webco_brief_current_column($current, $column);
    }

    return $merged;
}

/**
 * @param array<string, mixed> $current
 */
function webco_brief_current_column(array $current, string $column): int|string|null
{
    $value = $current[$column] ?? null;
    if ($column === 'call_requested') {
        return (int) $value === 1 ? 1 : 0;
    }
    if (in_array($column, ['call_number', 'call_time', 'call_note', 'wizard_step'], true)) {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
    if (is_string($value)) {
        return $value;
    }

    return '';
}

/**
 * @return list<array{name: string, detail: string}>
 */
function webco_brief_pairs(string $json): array
{
    if (trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $pairs = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $pairs[] = [
            'name' => trim((string) ($row['name'] ?? '')),
            'detail' => trim((string) ($row['detail'] ?? '')),
        ];
    }

    return $pairs;
}

function webco_brief_normalize_pairs(mixed $value, int $maxItems, int $nameMax, int $detailMax): ?string
{
    if ($value === null || $value === '') {
        return '';
    }
    $rows = $value;
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }
        $rows = $decoded;
    }
    if (!is_array($rows)) {
        return null;
    }

    $clean = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            return null;
        }
        $nameRaw = $row['name'] ?? '';
        $detailRaw = $row['detail'] ?? '';
        if (!is_string($nameRaw) || !is_string($detailRaw)) {
            return null;
        }
        $name = webco_brief_text($nameRaw, $nameMax);
        $detail = webco_brief_text($detailRaw, $detailMax);
        if ($name === null || $detail === null) {
            return null;
        }
        if ($name === '' && $detail === '') {
            continue;
        }
        $clean[] = ['name' => $name, 'detail' => $detail];
        if (count($clean) > $maxItems) {
            return null;
        }
    }
    if ($clean === []) {
        return '';
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);

    return is_string($json) ? $json : null;
}

function webco_brief_text(mixed $value, int $max): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = str_replace("\0", '', $value);
    $value = trim($value);
    if (strlen($value) > $max) {
        return null;
    }

    return $value;
}

/**
 * null keeps the file on the initial brief. false rejects another project's request.
 *
 * @return int|false|null
 */
function webco_asset_request_for_project(PDO $db, int $projectId, ?int $requestId): int|false|null
{
    if ($requestId === null) {
        return null;
    }
    if ($projectId < 1 || $requestId < 1) {
        return false;
    }

    try {
        $statement = $db->prepare(
            'SELECT id FROM project_requests WHERE id = :id AND project_id = :project_id'
        );
        $statement->execute([
            'id' => $requestId,
            'project_id' => $projectId,
        ]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return false;
    }

    return $row === false ? false : $requestId;
}

function webco_save_project_asset_row(
    PDO $db,
    int $projectId,
    string $category,
    string $storageName,
    string $originalName,
    string $mime,
    int $size,
    int|false|null $requestId
): bool {
    if ($requestId === false || $projectId < 1 || webco_asset_rules($category) === null) {
        return false;
    }
    if (preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $storageName) !== 1) {
        return false;
    }

    try {
        $statement = $db->prepare(
            'INSERT INTO project_assets (
                project_id, category, storage_name, original_name, mime_type, size_bytes, request_id
             ) VALUES (
                :project_id, :category, :storage_name, :original_name, :mime_type, :size_bytes, :request_id
             )'
        );
        $statement->execute([
            'project_id' => $projectId,
            'category' => $category,
            'storage_name' => $storageName,
            'original_name' => $originalName,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'request_id' => $requestId,
        ]);
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * Creates one support request. This never changes the website build status.
 *
 * @param array<string, mixed> $call
 */
function webco_create_project_request(PDO $db, int $projectId, string $type, string $summary, array $call = []): int
{
    $summary = webco_brief_text($summary, 8000);
    if ($projectId < 1 || $summary === null || trim($summary) === '' || !webco_request_type_valid($type)) {
        return 0;
    }
    $number = webco_brief_text($call['call_number'] ?? '', 40);
    $time = webco_brief_text($call['call_time'] ?? '', 120);
    $note = webco_brief_text($call['call_note'] ?? '', 2000);
    if ($number === null || $time === null || $note === null) {
        return 0;
    }
    $requested = $call['call_requested'] ?? false;
    $callRequested = $requested === true || $requested === 1 || $requested === '1' || $requested === 'yes';

    try {
        $project = $db->prepare('SELECT id, status FROM projects WHERE id = :id');
        $project->execute(['id' => $projectId]);
        $before = $project->fetch();
        if ($before === false) {
            return 0;
        }
        $statusBefore = (string) ($before['status'] ?? '');

        $insert = $db->prepare(
            'INSERT INTO project_requests (
                project_id, request_type, status, summary, call_requested, call_number, call_time, call_note, updated_at
             ) VALUES (
                :project_id, :request_type, \'open\', :summary, :call_requested, :call_number, :call_time, :call_note, CURRENT_TIMESTAMP
             )'
        );
        $insert->execute([
            'project_id' => $projectId,
            'request_type' => $type,
            'summary' => $summary,
            'call_requested' => $callRequested ? 1 : 0,
            'call_number' => $number === '' ? null : $number,
            'call_time' => $time === '' ? null : $time,
            'call_note' => $note === '' ? null : $note,
        ]);
        $id = (int) $db->lastInsertId();

        $check = $db->prepare('SELECT status FROM projects WHERE id = :id');
        $check->execute(['id' => $projectId]);
        $after = $check->fetch();
    } catch (PDOException) {
        return 0;
    }

    if ($id < 1 || !is_array($after) || (string) ($after['status'] ?? '') !== $statusBefore) {
        return 0;
    }

    return $id;
}

/**
 * Moves one request one step: open, then in progress, then done.
 * The website project status is left untouched.
 */
function webco_advance_request_status(PDO $db, int $requestId): bool
{
    if ($requestId < 1) {
        return false;
    }

    try {
        $db->beginTransaction();
        $select = $db->prepare(
            'SELECT r.status, r.project_id, p.status AS project_status
             FROM project_requests r
             INNER JOIN projects p ON p.id = r.project_id
             WHERE r.id = :id' . webco_for_update($db)
        );
        $select->execute(['id' => $requestId]);
        $row = $select->fetch();
        if ($row === false) {
            $db->rollBack();

            return false;
        }

        $next = webco_request_status_next((string) ($row['status'] ?? ''));
        $projectId = (int) ($row['project_id'] ?? 0);
        $projectStatus = (string) ($row['project_status'] ?? '');
        if ($next === null || $projectId < 1) {
            $db->rollBack();

            return false;
        }

        $update = $db->prepare(
            'UPDATE project_requests
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP,
                 completed_at = CASE WHEN :next_status = \'done\' THEN CURRENT_TIMESTAMP ELSE completed_at END
             WHERE id = :id AND status = :current'
        );
        $update->execute([
            'status' => $next,
            'next_status' => $next,
            'id' => $requestId,
            'current' => (string) $row['status'],
        ]);
        if ($update->rowCount() !== 1) {
            $db->rollBack();

            return false;
        }

        $unchanged = $db->prepare('SELECT status FROM projects WHERE id = :id');
        $unchanged->execute(['id' => $projectId]);
        $project = $unchanged->fetch();
        if (!is_array($project) || (string) ($project['status'] ?? '') !== $projectStatus) {
            $db->rollBack();

            return false;
        }
        $db->commit();
    } catch (PDOException) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        return false;
    }

    return true;
}

/**
 * @return list<array<string, mixed>>
 */
function webco_project_requests(PDO $db, int $projectId): array
{
    if ($projectId < 1) {
        return [];
    }

    try {
        $statement = $db->prepare(
            'SELECT id, project_id, request_type, status, summary, call_requested,
                    call_number, call_time, call_note, created_at, updated_at, completed_at
             FROM project_requests
             WHERE project_id = :project_id
             ORDER BY id DESC'
        );
        $statement->execute(['project_id' => $projectId]);
        $rows = $statement->fetchAll();
    } catch (PDOException) {
        return [];
    }

    $requests = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $requests[] = [
            'id' => $id,
            'project_id' => (int) ($row['project_id'] ?? 0),
            'request_type' => (string) ($row['request_type'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'summary' => (string) ($row['summary'] ?? ''),
            'call_requested' => (int) ($row['call_requested'] ?? 0) === 1,
            'call_number' => (string) ($row['call_number'] ?? ''),
            'call_time' => (string) ($row['call_time'] ?? ''),
            'call_note' => (string) ($row['call_note'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'completed_at' => webco_nullable_string($row['completed_at'] ?? null),
        ];
    }

    return $requests;
}

function webco_brief_summary(string $value): ?string
{
    $value = str_replace("\0", '', $value);
    $value = trim($value);
    if (strlen($value) > 8000) {
        return null;
    }

    return $value;
}

function webco_original_upload_name(string $name): string
{
    $name = str_replace(["\0", '\\', '/'], '', $name);
    $name = basename($name);
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '' || $name === '.' || $name === '..') {
        return 'upload';
    }
    if (strlen($name) > 180) {
        $name = substr($name, 0, 180);
    }

    return $name;
}

function webco_project_is_archived(array $project): bool
{
    $archived = $project['archived_at'] ?? null;

    return is_string($archived) && trim($archived) !== '';
}

function webco_project_open_request_count(array $project): int
{
    $requests = is_array($project['requests'] ?? null) ? $project['requests'] : [];
    $count = 0;
    foreach ($requests as $request) {
        if (!is_array($request)) {
            continue;
        }
        $status = (string) ($request['status'] ?? '');
        if ($status === 'open' || $status === 'in_progress') {
            $count++;
        }
    }

    return $count;
}

/**
 * @return list<string>
 */
function webco_project_attention_reasons(array $project): array
{
    if (webco_project_is_archived($project)) {
        return [];
    }

    $reasons = [];
    $status = (string) ($project['status'] ?? '');
    if (in_array($status, ['brief_received', 'ready_for_clone', 'ready_for_build', 'review'], true)) {
        $reasons[] = $status;
    }
    if ((int) ($project['call_requested'] ?? 0) === 1) {
        $reasons[] = 'call_requested';
    }
    if (webco_project_open_request_count($project) > 0) {
        $reasons[] = 'open_requests';
    }

    return $reasons;
}

function webco_project_in_admin_view(array $project, string $view): bool
{
    $archived = webco_project_is_archived($project);
    $status = (string) ($project['status'] ?? '');

    return match ($view) {
        'attention' => !$archived && webco_project_attention_reasons($project) !== [],
        'active' => !$archived && $status !== 'live',
        'live' => !$archived && $status === 'live',
        'archived' => $archived,
        'all' => true,
        default => false,
    };
}

function webco_project_next_action_label(string $status, bool $briefReady, ?string $packageId): string
{
    if ($status === 'awaiting_brief' || $status === 'brief_in_progress') {
        return 'Waiting for the customer brief';
    }
    if ($status === 'brief_received' && !$briefReady) {
        return 'Brief needs a submitted note';
    }
    if ($status === 'brief_received') {
        return 'Mark ready for clone';
    }
    if ($status === 'ready_for_clone' && $packageId === null) {
        return 'Save the 20i package id';
    }
    if ($status === 'ready_for_clone') {
        return 'Package id already stored';
    }
    if ($status === 'live') {
        return 'Website is live';
    }

    $next = webco_project_workflow_next($status);
    if ($next === null) {
        return 'No status change from here';
    }

    return 'Move to ' . webco_project_status_label($next);
}

function webco_archive_project(PDO $db, int $projectId, bool $restore): bool
{
    if ($projectId < 1) {
        return false;
    }

    $archived = $restore ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';
    $value = $restore ? 'NULL' : 'CURRENT_TIMESTAMP';
    try {
        $statement = $db->prepare(
            'UPDATE projects
             SET archived_at = ' . $value . ', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND ' . $archived
        );
        $statement->execute(['id' => $projectId]);
        $check = $db->prepare('SELECT status, archived_at FROM projects WHERE id = :id');
        $check->execute(['id' => $projectId]);
        $row = $check->fetch();
    } catch (PDOException) {
        return false;
    }
    if ($row === false) {
        return false;
    }

    $stamp = $row['archived_at'] ?? null;
    $isArchived = is_string($stamp) && trim($stamp) !== '';

    return $restore ? !$isArchived : $isArchived;
}

/**
 * A project may be deleted only when it is explicitly marked as a test.
 * The business name must start with "[TEST] ", the order email must use
 * example.com, example.test, or webco.test, and no 20i package id is stored.
 */
function webco_project_test_delete_allowed(string $businessName, string $email, mixed $packageId): bool
{
    if (!str_starts_with($businessName, '[TEST] ')) {
        return false;
    }
    $email = strtolower(trim($email));
    if (preg_match('/^[^@\s]+@(example\.com|example\.test|webco\.test)$/', $email) !== 1) {
        return false;
    }
    if (is_int($packageId)) {
        return false;
    }
    if (is_string($packageId) && trim($packageId) !== '') {
        return false;
    }

    return true;
}

/**
 * Removes one test project, its brief, requests, asset rows and private files.
 * The paid order is kept. Stripe and 20i are not called.
 *
 * @return 'deleted'|'refused'|'mismatch'|'missing'|'error'
 */
function webco_delete_test_project(PDO $db, int $projectId, string $confirmation, ?string $storageRoot = null): string
{
    if ($projectId < 1) {
        return 'missing';
    }

    try {
        $statement = $db->prepare(
            'SELECT p.order_public_id, p.twentyi_package_id, p.order_id,
                    o.business_name, o.email
             FROM projects p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.id = :id'
        );
        $statement->execute(['id' => $projectId]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return 'error';
    }
    if ($row === false) {
        return 'missing';
    }

    $publicId = (string) ($row['order_public_id'] ?? '');
    $orderId = (int) ($row['order_id'] ?? 0);
    if ($orderId < 1 || preg_match('/^wc_[a-f0-9]{20}$/', $publicId) !== 1) {
        return 'refused';
    }
    if (!webco_project_test_delete_allowed(
        (string) ($row['business_name'] ?? ''),
        (string) ($row['email'] ?? ''),
        $row['twentyi_package_id'] ?? null
    )) {
        return 'refused';
    }
    if (strlen($confirmation) !== strlen($publicId) || !hash_equals($publicId, $confirmation)) {
        return 'mismatch';
    }
    if (!webco_remove_project_storage($publicId, $storageRoot)) {
        return 'error';
    }

    try {
        $db->beginTransaction();
        foreach (['project_assets', 'project_requests', 'project_briefs'] as $table) {
            $delete = $db->prepare('DELETE FROM ' . $table . ' WHERE project_id = :id');
            $delete->execute(['id' => $projectId]);
        }
        $project = $db->prepare('DELETE FROM projects WHERE id = :id');
        $project->execute(['id' => $projectId]);
        $still = $db->prepare('SELECT id FROM projects WHERE id = :id');
        $still->execute(['id' => $projectId]);
        $order = $db->prepare('SELECT id FROM orders WHERE id = :id');
        $order->execute(['id' => $orderId]);
        $kept = $order->fetch();
        if ($kept === false || $still->fetch() !== false) {
            $db->rollBack();

            return 'error';
        }
        $db->commit();
    } catch (PDOException) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        return 'error';
    }

    return 'deleted';
}

/**
 * Deletes the private upload folder for one order id. The shared root is kept.
 */
function webco_remove_project_storage(string $orderPublicId, ?string $storageRoot = null): bool
{
    if (preg_match('/^wc_[a-f0-9]{20}$/', $orderPublicId) !== 1) {
        return false;
    }

    $root = $storageRoot ?? webco_projects_root();
    if ($root === null || !is_dir($root)) {
        return $storageRoot === null;
    }

    $realRoot = realpath($root);
    $base = $root . DIRECTORY_SEPARATOR . $orderPublicId;
    if (!is_dir($base)) {
        return true;
    }
    $realBase = realpath($base);
    if ($realRoot === false || $realBase === false || !str_starts_with($realBase, $realRoot . DIRECTORY_SEPARATOR)) {
        return false;
    }

    foreach (['logos', 'photos', 'documents'] as $folder) {
        $dir = $realBase . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($dir)) {
            continue;
        }
        $items = scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) || !unlink($path)) {
                return false;
            }
        }
        if (!rmdir($dir)) {
            return false;
        }
    }

    $left = scandir($realBase);
    if ($left === false) {
        return false;
    }
    foreach ($left as $item) {
        if ($item !== '.' && $item !== '..') {
            return false;
        }
    }

    return rmdir($realBase);
}

/**
 * @return list<array<string, mixed>>
 */
function webco_list_projects_for_admin(PDO $db): array
{
    $statement = $db->query(
        'SELECT p.id, p.order_public_id, p.vertical_code, p.status,
                p.twentyi_package_id,
                p.customer_notified_at, p.internal_notified_at, p.created_at, p.archived_at,
                o.business_name, o.contact_name, o.email, o.phone, o.domain_name,
                o.package_name, o.care_choice, o.paid_at,
                b.summary, b.updated_at AS brief_updated_at, b.submitted_at, b.wizard_step,
                b.business_overview, b.years_operating, b.credentials, b.first_impression,
                b.services, b.course_entries, b.locations, b.areas_served, b.location_entries,
                b.goals, b.why_experience, b.why_facilities, b.why_flexibility, b.why_support,
                b.why_difference, b.style_tone, b.branding, b.liked_sites, b.required_pages,
                b.enquiry_route, b.contact_details, b.opening_hours,
                b.call_requested, b.call_number, b.call_time, b.call_note
         FROM projects p
         INNER JOIN orders o ON o.id = p.order_id
         LEFT JOIN project_briefs b ON b.project_id = p.id
         ORDER BY p.created_at DESC, p.id DESC'
    );
    if ($statement === false) {
        return [];
    }

    $projects = [];
    foreach ($statement->fetchAll() as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $row['id'] = $id;
        $row['assets'] = [
            'logo' => [],
            'photo' => [],
            'document' => [],
        ];
        $row['requests'] = [];
        $projects[$id] = $row;
    }

    if ($projects === []) {
        return [];
    }

    $requests = $db->query(
        'SELECT id, project_id, request_type, status, summary, call_requested,
                call_number, call_time, call_note, created_at, completed_at
         FROM project_requests
         ORDER BY id DESC'
    );
    if ($requests !== false) {
        foreach ($requests->fetchAll() as $request) {
            $projectId = (int) ($request['project_id'] ?? 0);
            if (!isset($projects[$projectId]) || !is_array($request)) {
                continue;
            }
            $projects[$projectId]['requests'][] = $request;
        }
    }

    $assets = $db->query(
        'SELECT a.id, a.project_id, a.category, a.original_name, a.size_bytes, a.created_at, a.request_id
         FROM project_assets a
         INNER JOIN projects p ON p.id = a.project_id
         ORDER BY a.id'
    );
    if ($assets === false) {
        return array_values($projects);
    }

    foreach ($assets->fetchAll() as $asset) {
        $projectId = (int) ($asset['project_id'] ?? 0);
        $category = (string) ($asset['category'] ?? '');
        if (!isset($projects[$projectId]['assets'][$category])) {
            continue;
        }
        $requestId = $asset['request_id'] ?? null;
        $projects[$projectId]['assets'][$category][] = [
            'id' => (int) ($asset['id'] ?? 0),
            'original_name' => (string) ($asset['original_name'] ?? ''),
            'size_bytes' => (int) ($asset['size_bytes'] ?? 0),
            'created_at' => (string) ($asset['created_at'] ?? ''),
            'request_id' => is_numeric($requestId) && (int) $requestId > 0 ? (int) $requestId : null,
        ];
    }

    return array_values($projects);
}

function webco_update_project_status(PDO $db, int $projectId, string $status): bool
{
    if ($projectId < 1 || !webco_project_status_valid($status)) {
        return false;
    }

    try {
        $statement = $db->prepare(
            'UPDATE projects
             SET status = :status, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'id' => $projectId,
        ]);
        $check = $db->prepare(
            'SELECT id FROM projects WHERE id = :id AND status = :status'
        );
        $check->execute([
            'id' => $projectId,
            'status' => $status,
        ]);
    } catch (PDOException) {
        return false;
    }

    return $check->fetch() !== false;
}

/**
 * @return array{path: string, name: string}|null
 */
function webco_asset_download(PDO $db, int $assetId): ?array
{
    if ($assetId < 1) {
        return null;
    }

    try {
        $statement = $db->prepare(
            'SELECT a.storage_name, a.original_name, a.category, p.order_public_id
             FROM project_assets a
             INNER JOIN projects p ON p.id = a.project_id
             WHERE a.id = :id'
        );
        $statement->execute(['id' => $assetId]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return null;
    }
    if ($row === false) {
        return null;
    }

    $rules = webco_asset_rules((string) ($row['category'] ?? ''));
    $storageName = (string) ($row['storage_name'] ?? '');
    if ($rules === null || preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $storageName) !== 1) {
        return null;
    }

    $folder = webco_project_folder((string) ($row['order_public_id'] ?? ''), $rules['folder']);
    $root = webco_projects_root();
    if ($folder === null || $root === null) {
        return null;
    }

    $path = $folder . DIRECTORY_SEPARATOR . $storageName;
    $real = realpath($path);
    $base = realpath($root);
    if ($real === false || $base === false || !is_file($real)) {
        return null;
    }
    if (!str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        return null;
    }

    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($row['original_name'] ?? '')) ?? '';
    $name = trim($name, '._');
    if ($name === '') {
        $name = $storageName;
    }

    return [
        'path' => $real,
        'name' => $name,
    ];
}

function webco_private_headers(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header(
        "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; "
        . "img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
    );
}

function webco_session_project_id(): ?int
{
    $id = $_SESSION['project_id'] ?? null;
    if (is_int($id) && $id > 0) {
        return $id;
    }
    if (is_string($id) && preg_match('/^[1-9]\d{0,11}$/', $id) === 1) {
        return (int) $id;
    }

    return null;
}

function webco_start_named_session(string $name): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name($name);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function webco_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function webco_nullable_string(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = (string) $value;
    if ($value === '') {
        return null;
    }

    return $value;
}
