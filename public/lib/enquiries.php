<?php
/**
 * Pre-sale enquiries from the HGV landing page.
 *
 * An enquiry is a question from someone who has not bought anything. It is stored in its own
 * table, separate from orders and projects, and never creates an order, an account or a checkout.
 * Staff read and follow up enquiries in the existing admin page.
 *
 * Abuse controls live here so the endpoint cannot be used as a mail relay:
 * the recipient is fixed, nothing from the form reaches a mail header except a validated
 * Reply-To address, and storage and notification are rate limited.
 */

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'enquiries.php') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

const WEBCO_ENQUIRY_SOURCE = 'hgv-landing';
const WEBCO_ENQUIRY_MIN_ELAPSED_MS = 1500;
const WEBCO_ENQUIRY_IP_SHORT_LIMIT = 3;
const WEBCO_ENQUIRY_IP_SHORT_WINDOW = 600;
const WEBCO_ENQUIRY_IP_DAY_LIMIT = 8;
const WEBCO_ENQUIRY_GLOBAL_HOUR_LIMIT = 40;
const WEBCO_ENQUIRY_DUPLICATE_WINDOW = 86400;
const WEBCO_ENQUIRY_MAX_LINKS = 2;

/**
 * @return list<string>
 */
function webco_enquiry_statuses(): array
{
    return ['new', 'replied', 'closed'];
}

function webco_enquiry_status_label(string $status): string
{
    return match ($status) {
        'new' => 'New',
        'replied' => 'Replied',
        'closed' => 'Closed',
        default => $status,
    };
}

/**
 * @return array<string, string>
 */
function webco_enquiry_package_labels(): array
{
    return [
        'not_sure' => 'Not sure yet',
        'essential' => 'Essential Website',
        'professional' => 'Professional Website',
    ];
}

function webco_enquiry_package_label(string $package): string
{
    return webco_enquiry_package_labels()[$package] ?? $package;
}

/**
 * Number of characters, not bytes, so a name like "Zoë" counts as three.
 */
function webco_enquiry_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $count = preg_match_all('/./su', $value);

    return $count === false ? strlen($value) : $count;
}

/**
 * One line of text: control characters removed, runs of whitespace collapsed.
 */
function webco_enquiry_single_line(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';

    return trim($value);
}

/**
 * Message text: line breaks kept, other control characters removed, extra blank lines trimmed.
 */
function webco_enquiry_multi_line(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[^\P{C}\n]+/u', '', $value) ?? '';
    $value = preg_replace("/[ \t]+\n/", "\n", $value) ?? '';
    $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';

    return trim($value);
}

/**
 * Checks and tidies one submission.
 *
 * @param array<mixed> $input
 * @return array{ok: true, data: array{name: string, business: string, email: string, phone: ?string, package: string, message: string}}|array{ok: false, errors: array<string, string>}
 */
function webco_enquiry_validate(array $input): array
{
    $errors = [];

    $name = webco_enquiry_single_line($input['name'] ?? null);
    $nameLength = webco_enquiry_length($name);
    if ($nameLength < 2) {
        $errors['name'] = 'Please enter your name.';
    } elseif ($nameLength > 120) {
        $errors['name'] = 'Please use 120 characters or fewer.';
    }

    $business = webco_enquiry_single_line($input['business'] ?? null);
    $businessLength = webco_enquiry_length($business);
    if ($businessLength < 2) {
        $errors['business'] = 'Please enter your business name.';
    } elseif ($businessLength > 120) {
        $errors['business'] = 'Please use 120 characters or fewer.';
    }

    $emailRaw = is_string($input['email'] ?? null) ? trim($input['email']) : '';
    $email = webco_mail_address($emailRaw);
    if ($email === null) {
        $errors['email'] = 'Please enter an email address we can reply to, such as name@example.co.uk.';
    } else {
        $email = strtolower($email);
    }

    $phone = webco_enquiry_single_line($input['phone'] ?? null);
    if ($phone === '') {
        $phone = null;
    } elseif (preg_match('/^[0-9+()\-\s]{7,24}$/', $phone) !== 1 || preg_match_all('/\d/', $phone) < 7) {
        $errors['phone'] = 'Please check the telephone number, or leave it blank.';
        $phone = null;
    }

    $package = $input['package'] ?? 'not_sure';
    if (!is_string($package) || !isset(webco_enquiry_package_labels()[$package])) {
        $package = 'not_sure';
    }

    $message = webco_enquiry_multi_line($input['message'] ?? null);
    $messageLength = webco_enquiry_length($message);
    if ($messageLength < 10) {
        $errors['message'] = 'Please add a short message, at least a sentence, so we know how to help.';
    } elseif ($messageLength > 2000) {
        $errors['message'] = 'Please keep your message to 2,000 characters or fewer.';
    } elseif (webco_enquiry_link_count($message) > WEBCO_ENQUIRY_MAX_LINKS) {
        $errors['message'] = 'Please leave out web links, or include no more than two. Tell us in words and we will take it from there.';
    }

    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors];
    }

    return [
        'ok' => true,
        'data' => [
            'name' => $name,
            'business' => $business,
            'email' => (string) $email,
            'phone' => $phone,
            'package' => $package,
            'message' => $message,
        ],
    ];
}

function webco_enquiry_link_count(string $message): int
{
    $count = preg_match_all('~(?:https?://|www\.)\S+~i', $message);

    return $count === false ? 0 : $count;
}

function webco_ensure_enquiries_table(PDO $db): bool
{
    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS enquiries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id CHAR(24) NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT \'new\',
                source VARCHAR(40) NOT NULL,
                contact_name VARCHAR(120) NOT NULL,
                business_name VARCHAR(120) NOT NULL,
                email VARCHAR(160) NOT NULL,
                phone VARCHAR(24) NULL,
                package_interest VARCHAR(12) NOT NULL,
                message TEXT NOT NULL,
                ip_hash CHAR(64) NOT NULL,
                notified_at DATETIME NULL,
                handled_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY enquiries_public_id (public_id),
                KEY enquiries_ip_created (ip_hash, created_at),
                KEY enquiries_status_created (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * A one-way hash of the visitor address, used only to rate limit. The address itself is not stored.
 */
function webco_enquiry_ip_hash(string $ip): string
{
    $salt = webco_mail_secret('WEBCO_ENQUIRY_SALT');
    if ($salt === null) {
        $salt = 'webco-enquiry-v1';
    }

    return hash_hmac('sha256', $ip, $salt);
}

/**
 * @return 'ok'|'ip_limit'|'global_limit'|'error'
 */
function webco_enquiry_rate_state(PDO $db, string $ipHash, int $now): string
{
    try {
        $count = static function (string $sql, array $params) use ($db): int {
            $statement = $db->prepare($sql);
            $statement->execute($params);

            return (int) $statement->fetchColumn();
        };

        $short = $count(
            'SELECT COUNT(*) FROM enquiries WHERE ip_hash = :ip AND created_at >= :since',
            ['ip' => $ipHash, 'since' => gmdate('Y-m-d H:i:s', $now - WEBCO_ENQUIRY_IP_SHORT_WINDOW)]
        );
        if ($short >= WEBCO_ENQUIRY_IP_SHORT_LIMIT) {
            return 'ip_limit';
        }

        $day = $count(
            'SELECT COUNT(*) FROM enquiries WHERE ip_hash = :ip AND created_at >= :since',
            ['ip' => $ipHash, 'since' => gmdate('Y-m-d H:i:s', $now - 86400)]
        );
        if ($day >= WEBCO_ENQUIRY_IP_DAY_LIMIT) {
            return 'ip_limit';
        }

        $hour = $count(
            'SELECT COUNT(*) FROM enquiries WHERE created_at >= :since',
            ['since' => gmdate('Y-m-d H:i:s', $now - 3600)]
        );
        if ($hour >= WEBCO_ENQUIRY_GLOBAL_HOUR_LIMIT) {
            return 'global_limit';
        }
    } catch (PDOException) {
        return 'error';
    }

    return 'ok';
}

/**
 * True when the same person sent the same message very recently, for example by double tapping.
 */
function webco_enquiry_is_duplicate(PDO $db, string $email, string $message, int $now): bool
{
    try {
        $statement = $db->prepare(
            'SELECT id FROM enquiries WHERE email = :email AND message = :message AND created_at >= :since LIMIT 1'
        );
        $statement->execute([
            'email' => $email,
            'message' => $message,
            'since' => gmdate('Y-m-d H:i:s', $now - WEBCO_ENQUIRY_DUPLICATE_WINDOW),
        ]);

        return $statement->fetch() !== false;
    } catch (PDOException) {
        return false;
    }
}

/**
 * @param array{name: string, business: string, email: string, phone: ?string, package: string, message: string} $data
 * @return array{id: int, public_id: string}|null
 */
function webco_enquiry_insert(PDO $db, array $data, string $ipHash, int $now): ?array
{
    $statement = $db->prepare(
        'INSERT INTO enquiries (
            public_id, status, source, contact_name, business_name, email, phone,
            package_interest, message, ip_hash, created_at
        ) VALUES (
            :public_id, \'new\', :source, :contact_name, :business_name, :email, :phone,
            :package_interest, :message, :ip_hash, :created_at
        )'
    );

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $publicId = 'enq_' . bin2hex(random_bytes(10));
        try {
            $statement->execute([
                'public_id' => $publicId,
                'source' => WEBCO_ENQUIRY_SOURCE,
                'contact_name' => $data['name'],
                'business_name' => $data['business'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'package_interest' => $data['package'],
                'message' => $data['message'],
                'ip_hash' => $ipHash,
                'created_at' => gmdate('Y-m-d H:i:s', $now),
            ]);

            return ['id' => (int) $db->lastInsertId(), 'public_id' => $publicId];
        } catch (PDOException $exception) {
            $sqlState = $exception->errorInfo[0] ?? '';
            if ($sqlState !== '23000') {
                return null;
            }
        }
    }

    return null;
}

/**
 * Tells the business about a stored enquiry. Failure is harmless: the enquiry is already saved and
 * shows in the admin page. Only one fixed recipient is ever used.
 *
 * @param array{name: string, business: string, email: string, phone: ?string, package: string, message: string} $data
 */
function webco_enquiry_notify(PDO $db, int $id, string $publicId, array $data): bool
{
    $to = webco_mail_address(webco_mail_secret('WEBCO_NOTIFY_EMAIL'));
    if ($to === null) {
        return false;
    }

    $body = "A question has come in from the HGV landing page.\r\n"
        . "\r\n"
        . 'Reference: ' . $publicId . "\r\n"
        . 'Name: ' . $data['name'] . "\r\n"
        . 'Business: ' . $data['business'] . "\r\n"
        . 'Email: ' . $data['email'] . "\r\n"
        . 'Telephone: ' . ($data['phone'] ?? 'not given') . "\r\n"
        . 'Package of interest: ' . webco_enquiry_package_label($data['package']) . "\r\n"
        . "\r\n"
        . "Message:\r\n"
        . str_replace("\n", "\r\n", $data['message']) . "\r\n"
        . "\r\n"
        . "Reply to this email to answer directly. Enquiries are also listed in the admin page:\r\n"
        . "https://webcocloud.net/admin.php?section=enquiries\r\n";

    // The subject is fixed text plus the reference. Nothing the visitor typed goes into a header except Reply-To.
    $sent = webco_mail_deliver($to, 'New website enquiry ' . $publicId, $body, $data['email']);
    if (!$sent) {
        return false;
    }

    try {
        $statement = $db->prepare('UPDATE enquiries SET notified_at = :now WHERE id = :id');
        $statement->execute(['now' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
    } catch (PDOException) {
        // The mail went. Not recording that is a cosmetic gap only.
    }

    return true;
}

/**
 * Newest first.
 *
 * @return list<array<string, mixed>>
 */
function webco_list_enquiries(PDO $db, int $limit = 300): array
{
    $limit = max(1, min($limit, 1000));
    $statement = $db->query(
        'SELECT id, public_id, status, source, contact_name, business_name, email, phone,
                package_interest, message, notified_at, handled_at, created_at
         FROM enquiries
         ORDER BY created_at DESC, id DESC
         LIMIT ' . $limit
    );
    if ($statement === false) {
        return [];
    }

    return array_values($statement->fetchAll());
}

function webco_count_new_enquiries(PDO $db): int
{
    try {
        $statement = $db->query('SELECT COUNT(*) FROM enquiries WHERE status = \'new\'');
        if ($statement === false) {
            return 0;
        }

        return (int) $statement->fetchColumn();
    } catch (PDOException) {
        return 0;
    }
}

/**
 * Moves an enquiry to a new status. Any status may move to any other, so a mistaken click can be undone.
 */
function webco_enquiry_set_status(PDO $db, int $id, string $status): bool
{
    if ($id < 1 || !in_array($status, webco_enquiry_statuses(), true)) {
        return false;
    }

    try {
        $statement = $db->prepare(
            'UPDATE enquiries
             SET status = :status, handled_at = :handled
             WHERE id = :id AND status <> :status_again'
        );
        $statement->execute([
            'status' => $status,
            'handled' => $status === 'new' ? null : gmdate('Y-m-d H:i:s'),
            'id' => $id,
            'status_again' => $status,
        ]);

        return $statement->rowCount() === 1;
    } catch (PDOException) {
        return false;
    }
}
