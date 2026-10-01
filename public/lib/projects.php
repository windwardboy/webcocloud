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
        'in_build' => 'In build',
        'review' => 'Review',
        'ready_to_launch' => 'Ready to launch',
        'live' => 'Live',
        default => $status,
    };
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
                brief_token_hash CHAR(64) NOT NULL,
                customer_notified_at TIMESTAMP NULL DEFAULT NULL,
                internal_notified_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY projects_order_id (order_id),
                UNIQUE KEY projects_order_public_id (order_public_id),
                UNIQUE KEY projects_brief_token_hash (brief_token_hash)
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
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * Creates the project for a paid order once, then sends any missing notices.
 * Returns ok when the project row and asset folders exist. Mail failure stays ok.
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
                    order_id, order_public_id, vertical_code, status, brief_token_hash
                 ) VALUES (
                    :order_id, :order_public_id, :vertical_code, \'awaiting_brief\', :brief_token_hash
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
    $row = webco_project_notification_row($db, $projectId);
    if ($row === null) {
        return;
    }

    if ($row['customer_notified_at'] === null) {
        $token = $knownToken;
        if ($token === null) {
            $token = webco_replace_unsent_brief_token($db, $projectId);
        }
        if ($token !== null) {
            $url = webco_public_origin() . '/brief.php?access=' . $token;
            $sent = webco_mail_customer_brief(
                $row['email'],
                $row['contact_name'],
                $row['order_public_id'],
                $url
            );
            if ($sent) {
                webco_stamp_customer_notified($db, $projectId, $token);
            }
        }
    }

    $row = webco_project_notification_row($db, $projectId);
    if ($row === null || $row['internal_notified_at'] !== null) {
        return;
    }

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
    if ($sent) {
        webco_stamp_internal_notified($db, $projectId);
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

function webco_replace_unsent_brief_token(PDO $db, int $projectId): ?string
{
    $token = bin2hex(random_bytes(32));
    try {
        $statement = $db->prepare(
            'UPDATE projects
             SET brief_token_hash = :hash, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND customer_notified_at IS NULL'
        );
        $statement->execute([
            'hash' => hash('sha256', $token),
            'id' => $projectId,
        ]);
    } catch (PDOException) {
        return null;
    }

    if ($statement->rowCount() !== 1) {
        return null;
    }

    return $token;
}

function webco_stamp_customer_notified(PDO $db, int $projectId, string $token): void
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return;
    }

    try {
        $statement = $db->prepare(
            'UPDATE projects
             SET customer_notified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND customer_notified_at IS NULL
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

function webco_stamp_internal_notified(PDO $db, int $projectId): void
{
    try {
        $statement = $db->prepare(
            'UPDATE projects
             SET internal_notified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND internal_notified_at IS NULL'
        );
        $statement->execute(['id' => $projectId]);
    } catch (PDOException) {
        return;
    }
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
 *   assets: array<string, list<array{original_name: string, size_bytes: int}>>
 * }|null
 */
function webco_customer_project(PDO $db, int $projectId): ?array
{
    if ($projectId < 1) {
        return null;
    }

    try {
        $statement = $db->prepare(
            'SELECT p.id, p.order_public_id, p.status, o.business_name,
                    b.summary, b.submitted_at
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

    return [
        'id' => (int) $row['id'],
        'order_public_id' => (string) ($row['order_public_id'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'business_name' => (string) ($row['business_name'] ?? ''),
        'summary' => (string) ($row['summary'] ?? ''),
        'submitted_at' => webco_nullable_string($row['submitted_at'] ?? null),
        'assets' => webco_project_assets($db, $projectId),
    ];
}

/**
 * @return array<string, list<array{original_name: string, size_bytes: int}>>
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
            'SELECT category, original_name, size_bytes
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
        $grouped[$category][] = [
            'original_name' => (string) ($row['original_name'] ?? ''),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
        ];
    }

    return $grouped;
}

/**
 * @return 'saved'|'submitted'|'invalid'|'error'
 */
function webco_save_customer_brief(PDO $db, int $projectId, string $summary, bool $submit): string
{
    $summary = webco_brief_summary($summary);
    if ($summary === null || $projectId < 1) {
        return 'invalid';
    }

    try {
        $db->beginTransaction();
        $locked = $db->prepare(
            'SELECT status FROM projects WHERE id = :id FOR UPDATE'
        );
        $locked->execute(['id' => $projectId]);
        $project = $locked->fetch();
        if ($project === false) {
            $db->rollBack();
            return 'error';
        }

        $status = (string) ($project['status'] ?? '');
        $brief = $db->prepare(
            'UPDATE project_briefs
             SET summary = :summary, updated_at = CURRENT_TIMESTAMP
             WHERE project_id = :project_id'
        );
        $brief->execute([
            'summary' => $summary,
            'project_id' => $projectId,
        ]);

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
function webco_store_customer_upload(PDO $db, int $projectId, string $category, array $file): string
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

        $counted = $db->prepare(
            'SELECT COUNT(*) FROM project_assets
             WHERE project_id = :project_id AND category = :category'
        );
        $counted->execute([
            'project_id' => $projectId,
            'category' => $category,
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
        $insert = $db->prepare(
            'INSERT INTO project_assets (
                project_id, category, storage_name, original_name, mime_type, size_bytes
             ) VALUES (
                :project_id, :category, :storage_name, :original_name, :mime_type, :size_bytes
             )'
        );
        $insert->execute([
            'project_id' => $projectId,
            'category' => $category,
            'storage_name' => $storageName,
            'original_name' => webco_original_upload_name((string) ($file['name'] ?? '')),
            'mime_type' => $checked['mime'],
            'size_bytes' => $size,
        ]);
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

/**
 * @return list<array<string, mixed>>
 */
function webco_list_projects_for_admin(PDO $db): array
{
    $statement = $db->query(
        'SELECT p.id, p.order_public_id, p.vertical_code, p.status,
                p.customer_notified_at, p.internal_notified_at, p.created_at,
                o.business_name, o.contact_name, o.email, o.phone, o.domain_name,
                o.package_name, o.care_choice, o.paid_at,
                b.summary, b.updated_at AS brief_updated_at, b.submitted_at
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
        $projects[$id] = $row;
    }

    if ($projects === []) {
        return [];
    }

    $assets = $db->query(
        'SELECT a.id, a.project_id, a.category, a.original_name, a.size_bytes, a.created_at
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
        $projects[$projectId]['assets'][$category][] = [
            'id' => (int) ($asset['id'] ?? 0),
            'original_name' => (string) ($asset['original_name'] ?? ''),
            'size_bytes' => (int) ($asset['size_bytes'] ?? 0),
            'created_at' => (string) ($asset['created_at'] ?? ''),
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
