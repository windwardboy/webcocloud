<?php
/**
 * MySQL connection for draft orders.
 *
 * Credentials stay in the private secrets file, outside this repository:
 * WEBCO_DB_HOST, WEBCO_DB_NAME, WEBCO_DB_USER, WEBCO_DB_PASSWORD.
 * WEBCO_DB_PORT is optional and defaults to 3306.
 * This file must not print or return those values.
 */

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'db.php') {
    http_response_code(404);
    exit;
}

if (!defined('WEBCO_SECRETS_FILE')) {
    define('WEBCO_SECRETS_FILE', '/home/sites/39b/8/836e0b54be/webco-secrets.php');
}

/**
 * Load the private secrets file once per PHP process.
 * Secrets may define() constants; a second plain require would warn.
 */
function webco_load_secrets(): bool
{
    if (!defined('WEBCO_SECRETS_FILE') || !is_string(WEBCO_SECRETS_FILE) || WEBCO_SECRETS_FILE === '') {
        return false;
    }
    if (!is_file(WEBCO_SECRETS_FILE)) {
        return false;
    }

    ob_start();
    require_once WEBCO_SECRETS_FILE;
    ob_end_clean();

    return true;
}

function webco_db(): ?PDO
{
    static $pdo = null;
    static $failed = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($failed) {
        return null;
    }

    $config = webco_db_config();
    if ($config === null || !extension_loaded('pdo_mysql')) {
        $failed = true;
        return null;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
        $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
    }

    try {
        $connection = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['port'],
                $config['name']
            ),
            $config['user'],
            $config['password'],
            $options
        );
    } catch (PDOException) {
        $failed = true;
        return null;
    }

    $config['password'] = '';
    $pdo = $connection;
    return $pdo;
}

/**
 * @return array{host: string, port: int, name: string, user: string, password: string}|null
 */
function webco_db_config(): ?array
{
    if (!webco_load_secrets()) {
        return null;
    }

    $host = webco_loaded_secret('WEBCO_DB_HOST', $WEBCO_DB_HOST ?? null);
    $name = webco_loaded_secret('WEBCO_DB_NAME', $WEBCO_DB_NAME ?? null);
    $user = webco_loaded_secret('WEBCO_DB_USER', $WEBCO_DB_USER ?? null);
    $password = webco_loaded_secret('WEBCO_DB_PASSWORD', $WEBCO_DB_PASSWORD ?? null);
    $port = webco_loaded_secret('WEBCO_DB_PORT', $WEBCO_DB_PORT ?? null);

    if ($host === null || $name === null || $user === null || $password === null) {
        return null;
    }
    if (!webco_safe_dsn_part($host) || !webco_safe_dsn_part($name) || !webco_safe_dsn_part($user)) {
        return null;
    }

    $portNumber = 3306;
    if ($port !== null) {
        if (!preg_match('/^\d{1,5}$/', $port)) {
            return null;
        }
        $portNumber = (int) $port;
        if ($portNumber < 1 || $portNumber > 65535) {
            return null;
        }
    }

    return [
        'host' => $host,
        'port' => $portNumber,
        'name' => $name,
        'user' => $user,
        'password' => $password,
    ];
}

function webco_loaded_secret(string $name, mixed $variable): ?string
{
    $value = defined($name) ? constant($name) : $variable;

    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    return $value;
}

function webco_safe_dsn_part(string $value): bool
{
    return strlen($value) <= 128 && preg_match('/^[A-Za-z0-9._:-]+$/', $value) === 1;
}

function webco_ensure_orders_table(PDO $db): bool
{
    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id CHAR(23) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT \'draft\',
                package_code VARCHAR(20) NOT NULL,
                package_name VARCHAR(80) NOT NULL,
                package_price_pence INT UNSIGNED NOT NULL,
                domain_path VARCHAR(16) NOT NULL,
                domain_name VARCHAR(253) NOT NULL,
                business_name VARCHAR(120) NOT NULL,
                contact_name VARCHAR(120) NOT NULL,
                email VARCHAR(160) NOT NULL,
                phone VARCHAR(20) NOT NULL,
                contact_method VARCHAR(16) NOT NULL,
                address_line_1 VARCHAR(120) NOT NULL,
                address_line_2 VARCHAR(120) NULL,
                town VARCHAR(80) NOT NULL,
                county VARCHAR(80) NULL,
                postcode VARCHAR(10) NOT NULL,
                company_number VARCHAR(8) NULL,
                care_choice VARCHAR(16) NOT NULL,
                care_price_pence INT UNSIGNED NULL,
                stripe_checkout_session_id VARCHAR(255) NULL,
                stripe_customer_id VARCHAR(255) NULL,
                stripe_subscription_id VARCHAR(255) NULL,
                payment_intent_id VARCHAR(255) NULL,
                care_status VARCHAR(16) NULL,
                care_trial_ends_at DATETIME NULL,
                hosting_status VARCHAR(16) NULL,
                hosting_included_until DATETIME NULL,
                stripe_livemode TINYINT(1) NULL,
                paid_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY orders_public_id (public_id),
                UNIQUE KEY orders_stripe_checkout_session_id (stripe_checkout_session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        if (!webco_ensure_order_payment_columns($db)) {
            return false;
        }
        if (!webco_ensure_order_state_columns($db)) {
            return false;
        }
        if (!webco_ensure_stripe_events_table($db)) {
            return false;
        }
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * The live table was created before these columns existed.
 * CREATE TABLE IF NOT EXISTS does not add them, so this alters the table.
 */
function webco_ensure_order_payment_columns(PDO $db): bool
{
    $columns = [
        'stripe_checkout_session_id' => 'VARCHAR(255) NULL',
        'stripe_customer_id' => 'VARCHAR(255) NULL',
        'stripe_subscription_id' => 'VARCHAR(255) NULL',
        'paid_at' => 'TIMESTAMP NULL DEFAULT NULL',
    ];

    try {
        $existing = [];
        $described = $db->query('SHOW COLUMNS FROM orders');
        if ($described === false) {
            return false;
        }
        foreach ($described->fetchAll() as $column) {
            $name = strtolower((string) ($column['Field'] ?? ''));
            if ($name !== '') {
                $existing[$name] = true;
            }
        }

        foreach ($columns as $name => $definition) {
            if (isset($existing[$name])) {
                continue;
            }
            $db->exec('ALTER TABLE orders ADD COLUMN ' . $name . ' ' . $definition);
        }

        $indexed = false;
        $indexes = $db->query('SHOW INDEX FROM orders');
        if ($indexes === false) {
            return false;
        }
        foreach ($indexes->fetchAll() as $index) {
            if (($index['Key_name'] ?? '') === 'orders_stripe_checkout_session_id') {
                $indexed = true;
                break;
            }
        }
        if (!$indexed) {
            $db->exec(
                'ALTER TABLE orders ADD UNIQUE KEY orders_stripe_checkout_session_id (stripe_checkout_session_id)'
            );
        }
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * @return list<string>
 */
function webco_order_statuses(): array
{
    return ['draft', 'checkout_created', 'paid', 'cancelled', 'refunded'];
}

function webco_order_status_valid(string $status): bool
{
    return in_array($status, webco_order_statuses(), true);
}

/**
 * @return list<string>
 */
function webco_care_statuses(): array
{
    return ['trialing', 'active', 'past_due', 'cancelled'];
}

function webco_care_status_valid(?string $status): bool
{
    return $status === null || in_array($status, webco_care_statuses(), true);
}

/**
 * @return list<string>
 */
function webco_hosting_statuses(): array
{
    return ['included', 'trialing', 'active', 'past_due', 'cancelled'];
}

function webco_hosting_status_valid(?string $status): bool
{
    return $status === null || in_array($status, webco_hosting_statuses(), true);
}

function webco_ensure_order_state_columns(PDO $db): bool
{
    $columns = [
        'payment_intent_id' => 'VARCHAR(255) NULL',
        'care_status' => 'VARCHAR(16) NULL',
        'care_trial_ends_at' => 'DATETIME NULL',
        'hosting_status' => 'VARCHAR(16) NULL',
        'hosting_included_until' => 'DATETIME NULL',
        'stripe_livemode' => 'TINYINT(1) NULL',
    ];

    try {
        $existing = webco_table_column_set($db, 'orders');
        if ($existing === null) {
            return false;
        }
        foreach ($columns as $name => $definition) {
            if (isset($existing[$name])) {
                continue;
            }
            $db->exec('ALTER TABLE orders ADD COLUMN ' . $name . ' ' . $definition);
        }
        if (!webco_ensure_check_constraint(
            $db,
            'orders',
            'orders_status_check',
            'status IN (\'draft\', \'checkout_created\', \'paid\', \'cancelled\', \'refunded\')'
        )) {
            return false;
        }
        if (!webco_ensure_check_constraint(
            $db,
            'orders',
            'orders_care_status_check',
            'care_status IS NULL OR care_status IN (\'trialing\', \'active\', \'past_due\', \'cancelled\')'
        )) {
            return false;
        }
        if (!webco_ensure_check_constraint(
            $db,
            'orders',
            'orders_hosting_status_check',
            'hosting_status IS NULL OR hosting_status IN (\'included\', \'trialing\', \'active\', \'past_due\', \'cancelled\')'
        )) {
            return false;
        }
        if (!webco_ensure_check_constraint(
            $db,
            'orders',
            'orders_stripe_livemode_check',
            'stripe_livemode IS NULL OR stripe_livemode IN (0, 1)'
        )) {
            return false;
        }
    } catch (PDOException) {
        return false;
    }

    return true;
}

function webco_ensure_stripe_events_table(PDO $db): bool
{
    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS stripe_events (
                stripe_event_id VARCHAR(255) NOT NULL,
                event_type VARCHAR(80) NOT NULL,
                claimed_at DATETIME NOT NULL,
                processed_at DATETIME NULL,
                result VARCHAR(16) NULL,
                PRIMARY KEY (stripe_event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * @return array<string, true>|null
 */
function webco_table_column_set(PDO $db, string $table): ?array
{
    if (!preg_match('/^[a-z_]+$/', $table)) {
        return null;
    }

    $described = $db->query('SHOW COLUMNS FROM ' . $table);
    if ($described === false) {
        return null;
    }

    $existing = [];
    foreach ($described->fetchAll() as $column) {
        $name = strtolower((string) ($column['Field'] ?? ''));
        if ($name !== '') {
            $existing[$name] = true;
        }
    }

    return $existing;
}

function webco_ensure_check_constraint(PDO $db, string $table, string $name, string $expression): bool
{
    if (!preg_match('/^[a-z_]+$/', $table) || !preg_match('/^[a-z_]+$/', $name)) {
        return false;
    }

    try {
        $statement = $db->prepare(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND CONSTRAINT_NAME = :constraint_name'
        );
        $statement->execute([
            'table_name' => $table,
            'constraint_name' => $name,
        ]);
        if ($statement->fetch() !== false) {
            return true;
        }

        $db->exec(
            'ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . $name . ' CHECK (' . $expression . ')'
        );
    } catch (PDOException) {
        return true;
    }

    return true;
}

function webco_for_update(PDO $db): string
{
    $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    return $driver === 'mysql' ? ' FOR UPDATE' : '';
}

/**
 * @return array{
 *   public_id: string,
 *   status: string,
 *   package_code: string,
 *   package_price_pence: int,
 *   care_choice: string,
 *   domain_name: string,
 *   email: string,
 *   stripe_checkout_session_id: ?string
 * }|null
 */
function webco_find_order_checkout_row(PDO $db, string $publicId): ?array
{
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $publicId)) {
        return null;
    }

    try {
        $statement = $db->prepare(
            'SELECT public_id, status, package_code, package_price_pence, care_choice,
                    domain_name, email, stripe_checkout_session_id
             FROM orders
             WHERE public_id = :public_id'
        );
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return null;
    }
    if ($row === false) {
        return null;
    }

    $sessionId = $row['stripe_checkout_session_id'] ?? null;

    return [
        'public_id' => (string) ($row['public_id'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'package_code' => (string) ($row['package_code'] ?? ''),
        'package_price_pence' => (int) ($row['package_price_pence'] ?? 0),
        'care_choice' => (string) ($row['care_choice'] ?? ''),
        'domain_name' => (string) ($row['domain_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'stripe_checkout_session_id' => is_string($sessionId) && $sessionId !== '' ? $sessionId : null,
    ];
}

/**
 * @param array<string, mixed> $order
 */
function webco_update_open_order(PDO $db, string $publicId, array $order): bool
{
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $publicId)) {
        return false;
    }

    try {
        $statement = $db->prepare(
            'UPDATE orders
             SET package_code = :package_code,
                 package_name = :package_name,
                 package_price_pence = :package_price_pence,
                 domain_path = :domain_path,
                 domain_name = :domain_name,
                 business_name = :business_name,
                 contact_name = :contact_name,
                 email = :email,
                 phone = :phone,
                 contact_method = :contact_method,
                 address_line_1 = :address_line_1,
                 address_line_2 = :address_line_2,
                 town = :town,
                 county = :county,
                 postcode = :postcode,
                 company_number = :company_number,
                 care_choice = :care_choice,
                 care_price_pence = :care_price_pence
             WHERE public_id = :public_id
               AND status IN (\'draft\', \'checkout_created\', \'cancelled\')'
        );
        $statement->execute([
            'package_code' => $order['package_code'],
            'package_name' => $order['package_name'],
            'package_price_pence' => $order['package_price_pence'],
            'domain_path' => $order['domain_path'],
            'domain_name' => $order['domain_name'],
            'business_name' => $order['business_name'],
            'contact_name' => $order['contact_name'],
            'email' => $order['email'],
            'phone' => $order['phone'],
            'contact_method' => $order['contact_method'],
            'address_line_1' => $order['address_line_1'],
            'address_line_2' => $order['address_line_2'],
            'town' => $order['town'],
            'county' => $order['county'],
            'postcode' => $order['postcode'],
            'company_number' => $order['company_number'],
            'care_choice' => $order['care_choice'],
            'care_price_pence' => $order['care_price_pence'],
            'public_id' => $publicId,
        ]);
        if ($statement->rowCount() === 1) {
            return true;
        }
    } catch (PDOException) {
        return false;
    }

    $current = webco_find_order_checkout_row($db, $publicId);

    return $current !== null && in_array($current['status'], ['draft', 'checkout_created', 'cancelled'], true);
}

function webco_bind_checkout_session(PDO $db, string $publicId, string $sessionId, ?string $previousSessionId): bool
{
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $publicId)) {
        return false;
    }
    if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]{8,240}$/', $sessionId) || strlen($sessionId) > 255) {
        return false;
    }
    if ($previousSessionId !== null && !preg_match('/^cs_(test|live)_[A-Za-z0-9]{8,240}$/', $previousSessionId)) {
        return false;
    }

    try {
        if ($previousSessionId === null) {
            $statement = $db->prepare(
                'UPDATE orders
                 SET status = \'checkout_created\',
                     stripe_checkout_session_id = :session_id
                 WHERE public_id = :public_id
                   AND status IN (\'draft\', \'checkout_created\', \'cancelled\')
                   AND (stripe_checkout_session_id IS NULL OR stripe_checkout_session_id = :session_match)'
            );
            $statement->execute([
                'session_id' => $sessionId,
                'public_id' => $publicId,
                'session_match' => $sessionId,
            ]);
        } else {
            $statement = $db->prepare(
                'UPDATE orders
                 SET status = \'checkout_created\',
                     stripe_checkout_session_id = :session_id
                 WHERE public_id = :public_id
                   AND status IN (\'draft\', \'checkout_created\', \'cancelled\')
                   AND (stripe_checkout_session_id = :previous_session OR stripe_checkout_session_id = :session_match)'
            );
            $statement->execute([
                'session_id' => $sessionId,
                'public_id' => $publicId,
                'previous_session' => $previousSessionId,
                'session_match' => $sessionId,
            ]);
        }
        if ($statement->rowCount() === 1) {
            return true;
        }

        $current = webco_find_order_checkout_row($db, $publicId);
    } catch (PDOException) {
        return false;
    }

    return $current !== null
        && $current['status'] === 'checkout_created'
        && $current['stripe_checkout_session_id'] === $sessionId;
}

/**
 * What to do when Continue is pressed for a saved order.
 *
 * @return 'resume_paid'|'reuse_open_session'|'replace_session'|'create_session'|'new_order'
 */
function webco_checkout_action(
    string $status,
    bool $samePurchase,
    ?string $sessionStatus,
    bool $sessionBindingSame
): string {
    if ($status === 'paid' && $samePurchase) {
        return 'resume_paid';
    }
    if ($status === 'paid' || $status === 'refunded' || !webco_order_status_valid($status)) {
        return 'new_order';
    }
    if (!in_array($status, ['draft', 'checkout_created', 'cancelled'], true)) {
        return 'new_order';
    }
    if ($sessionStatus === 'complete') {
        return 'resume_paid';
    }
    if ($sessionStatus === null) {
        return 'create_session';
    }
    if ($sessionStatus === 'open' && $sessionBindingSame) {
        return 'reuse_open_session';
    }

    return 'replace_session';
}

/**
 * @param array{
 *   public_id: string,
 *   session_id: string,
 *   customer_id: ?string,
 *   subscription_id: string,
 *   payment_intent_id: ?string,
 *   website_amount_pence: int,
 *   stripe_livemode: int,
 *   care_status: ?string,
 *   care_trial_ends_at: ?string,
 *   hosting_status: string,
 *   hosting_included_until: ?string
 * } $payment
 * @return 'paid'|'already'|'mismatch'|'missing'|'error'
 */
function webco_record_checkout_payment(PDO $db, array $payment): string
{
    $publicId = $payment['public_id'];
    $sessionId = $payment['session_id'];
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $publicId)) {
        return 'error';
    }
    if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]{8,240}$/', $sessionId) || strlen($sessionId) > 255) {
        return 'error';
    }
    if (!webco_stripe_customer_id_valid($payment['customer_id'])) {
        return 'error';
    }
    if (!preg_match('/^sub_[A-Za-z0-9]{8,240}$/', $payment['subscription_id'])) {
        return 'error';
    }
    if (!webco_payment_intent_id_valid($payment['payment_intent_id'])) {
        return 'error';
    }
    $livemode = $payment['stripe_livemode'] ?? null;
    if ($livemode !== 0 && $livemode !== 1) {
        return 'error';
    }
    if ($payment['website_amount_pence'] < 1) {
        return 'error';
    }
    if (!webco_care_status_valid($payment['care_status']) || !webco_hosting_status_valid($payment['hosting_status'])) {
        return 'error';
    }
    if (!webco_utc_datetime_valid($payment['care_trial_ends_at']) || !webco_utc_datetime_valid($payment['hosting_included_until'])) {
        return 'error';
    }
    if ($payment['hosting_status'] === '') {
        return 'error';
    }

    try {
        $db->beginTransaction();
        $select = $db->prepare(
            'SELECT id, status, stripe_checkout_session_id, package_price_pence
             FROM orders
             WHERE public_id = :public_id' . webco_for_update($db)
        );
        $select->execute(['public_id' => $publicId]);
        $row = $select->fetch();
        if ($row === false) {
            $db->rollBack();
            return 'missing';
        }

        $status = (string) ($row['status'] ?? '');
        $storedSession = $row['stripe_checkout_session_id'] ?? null;
        $storedSession = is_string($storedSession) && $storedSession !== '' ? $storedSession : null;
        $price = (int) ($row['package_price_pence'] ?? 0);
        if ($status === 'paid') {
            $db->rollBack();

            return $storedSession === $sessionId ? 'already' : 'mismatch';
        }
        if ($price !== $payment['website_amount_pence']) {
            $db->rollBack();
            return 'mismatch';
        }
        if ($storedSession !== null && $storedSession !== $sessionId) {
            $db->rollBack();
            return 'mismatch';
        }
        if (!in_array($status, ['draft', 'checkout_created', 'cancelled'], true)) {
            $db->rollBack();
            return 'mismatch';
        }

        $update = $db->prepare(
            'UPDATE orders
             SET status = \'paid\',
                 stripe_checkout_session_id = :session_id,
                 stripe_customer_id = :customer_id,
                 stripe_subscription_id = :subscription_id,
                 payment_intent_id = :payment_intent_id,
                 stripe_livemode = :stripe_livemode,
                 care_status = :care_status,
                 care_trial_ends_at = :care_trial_ends_at,
                 hosting_status = :hosting_status,
                 hosting_included_until = :hosting_included_until,
                 paid_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND status = :status
               AND package_price_pence = :website_amount'
        );
        $update->execute([
            'session_id' => $sessionId,
            'customer_id' => $payment['customer_id'],
            'subscription_id' => $payment['subscription_id'],
            'payment_intent_id' => $payment['payment_intent_id'],
            'stripe_livemode' => $livemode,
            'care_status' => $payment['care_status'],
            'care_trial_ends_at' => $payment['care_trial_ends_at'],
            'hosting_status' => $payment['hosting_status'],
            'hosting_included_until' => $payment['hosting_included_until'],
            'id' => (int) ($row['id'] ?? 0),
            'status' => $status,
            'website_amount' => $payment['website_amount_pence'],
        ]);
        if ($update->rowCount() !== 1) {
            $db->rollBack();
            return 'error';
        }
        $db->commit();
    } catch (PDOException $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $sqlState = $exception->errorInfo[0] ?? '';
        if ($sqlState === '23000') {
            return 'mismatch';
        }

        return 'error';
    }

    return 'paid';
}

function webco_stripe_customer_id_valid(?string $customerId): bool
{
    if ($customerId === null) {
        return true;
    }

    return (bool) preg_match('/^cus_[A-Za-z0-9]{8,240}$/', $customerId);
}

function webco_payment_intent_id_valid(?string $paymentIntentId): bool
{
    if ($paymentIntentId === null) {
        return true;
    }

    return (bool) preg_match('/^pi_[A-Za-z0-9]{8,240}$/', $paymentIntentId);
}

function webco_utc_datetime_valid(?string $value): bool
{
    if ($value === null) {
        return true;
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d H:i:s') === $value;
}

/**
 * @return 'cancelled'|'ignored'|'missing'|'error'
 */
function webco_cancel_open_checkout(PDO $db, string $publicId, string $sessionId): string
{
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $publicId)) {
        return 'error';
    }
    if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]{8,240}$/', $sessionId)) {
        return 'error';
    }

    try {
        $db->beginTransaction();
        $select = $db->prepare(
            'SELECT id, status, stripe_checkout_session_id
             FROM orders
             WHERE public_id = :public_id' . webco_for_update($db)
        );
        $select->execute(['public_id' => $publicId]);
        $row = $select->fetch();
        if ($row === false) {
            $db->rollBack();
            return 'missing';
        }

        $status = (string) ($row['status'] ?? '');
        $storedSession = (string) ($row['stripe_checkout_session_id'] ?? '');
        if ($status === 'paid' || $status === 'refunded' || $storedSession !== $sessionId) {
            $db->rollBack();
            return 'ignored';
        }
        if ($status === 'cancelled') {
            $db->rollBack();
            return 'ignored';
        }
        if ($status !== 'checkout_created') {
            $db->rollBack();
            return 'ignored';
        }

        $update = $db->prepare(
            'UPDATE orders
             SET status = \'cancelled\'
             WHERE id = :id
               AND status = \'checkout_created\'
               AND stripe_checkout_session_id = :session_id'
        );
        $update->execute([
            'id' => (int) ($row['id'] ?? 0),
            'session_id' => $sessionId,
        ]);
        if ($update->rowCount() !== 1) {
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

    return 'cancelled';
}

/**
 * Claims a Stripe event before side effects.
 * A second delivery of an applied or ignored event is done.
 * A rejected event can be reclaimed so Stripe Resend or a later retry can apply
 * after a code fix. A second delivery while the first claim is fresh is busy.
 *
 * @return array{state: 'claimed', claimed_at: string}|array{state: 'done'|'busy'|'error'}
 */
function webco_claim_stripe_event(PDO $db, string $eventId, string $eventType): array
{
    if (!preg_match('/^evt_[A-Za-z0-9]{8,240}$/', $eventId) || strlen($eventId) > 255) {
        return ['state' => 'error'];
    }
    if (!preg_match('/^[a-z0-9_.]{3,80}$/', $eventType)) {
        return ['state' => 'error'];
    }

    $now = gmdate('Y-m-d H:i:s');
    try {
        $insert = $db->prepare(
            'INSERT INTO stripe_events (stripe_event_id, event_type, claimed_at, processed_at, result)
             VALUES (:id, :event_type, :claimed_at, NULL, NULL)'
        );
        $insert->execute([
            'id' => $eventId,
            'event_type' => $eventType,
            'claimed_at' => $now,
        ]);

        return ['state' => 'claimed', 'claimed_at' => $now];
    } catch (PDOException $exception) {
        $sqlState = $exception->errorInfo[0] ?? '';
        if ($sqlState !== '23000') {
            return ['state' => 'error'];
        }
    }

    try {
        $select = $db->prepare(
            'SELECT claimed_at, processed_at, result
             FROM stripe_events
             WHERE stripe_event_id = :id'
        );
        $select->execute(['id' => $eventId]);
        $row = $select->fetch();
    } catch (PDOException) {
        return ['state' => 'error'];
    }
    if ($row === false) {
        return ['state' => 'error'];
    }

    $result = $row['result'] ?? null;
    $processedAt = $row['processed_at'] ?? null;
    if ($processedAt !== null && ($result === 'applied' || $result === 'ignored')) {
        return ['state' => 'done'];
    }

    $claimedAt = (string) ($row['claimed_at'] ?? '');
    $claimed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $claimedAt, new DateTimeZone('UTC'));
    if (!$claimed instanceof DateTimeImmutable) {
        return ['state' => 'busy'];
    }

    // Rejected events are reclaimable immediately so a Resend after a fix can apply.
    // Unfinished claims stay busy for two minutes to avoid overlapping workers.
    $rejected = $processedAt !== null && $result === 'rejected';
    $age = time() - $claimed->getTimestamp();
    if (!$rejected && $age < 120) {
        return ['state' => 'busy'];
    }

    $taken = gmdate('Y-m-d H:i:s');
    try {
        if ($rejected) {
            $reclaim = $db->prepare(
                'UPDATE stripe_events
                 SET claimed_at = :claimed_at,
                     event_type = :event_type,
                     processed_at = NULL,
                     result = NULL
                 WHERE stripe_event_id = :id
                   AND result = \'rejected\'
                   AND claimed_at = :previous_claim'
            );
        } else {
            $reclaim = $db->prepare(
                'UPDATE stripe_events
                 SET claimed_at = :claimed_at, event_type = :event_type
                 WHERE stripe_event_id = :id
                   AND processed_at IS NULL
                   AND claimed_at = :previous_claim'
            );
        }
        $reclaim->execute([
            'claimed_at' => $taken,
            'event_type' => $eventType,
            'id' => $eventId,
            'previous_claim' => $claimedAt,
        ]);
    } catch (PDOException) {
        return ['state' => 'error'];
    }
    if ($reclaim->rowCount() === 1) {
        return ['state' => 'claimed', 'claimed_at' => $taken];
    }

    return ['state' => 'busy'];
}

function webco_finish_stripe_event(PDO $db, string $eventId, string $claimedAt, string $result): bool
{
    if (!in_array($result, ['applied', 'ignored', 'rejected'], true)) {
        return false;
    }
    if (!webco_utc_datetime_valid($claimedAt)) {
        return false;
    }

    try {
        $statement = $db->prepare(
            'UPDATE stripe_events
             SET processed_at = :processed_at, result = :result
             WHERE stripe_event_id = :id
               AND processed_at IS NULL
               AND claimed_at = :claimed_at'
        );
        $statement->execute([
            'processed_at' => gmdate('Y-m-d H:i:s'),
            'result' => $result,
            'id' => $eventId,
            'claimed_at' => $claimedAt,
        ]);
    } catch (PDOException) {
        return false;
    }

    return $statement->rowCount() === 1;
}

/**
 * Reads the fields the success page is allowed to show.
 * Returns null when the order is missing or the lookup fails.
 *
 * @return array{status: string, package_name: string, domain_name: string, care_choice: string}|null
 */
function webco_find_order_display(PDO $db, string $publicId): ?array
{
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $publicId)) {
        return null;
    }

    try {
        $statement = $db->prepare(
            'SELECT status, package_name, domain_name, care_choice
             FROM orders
             WHERE public_id = :public_id'
        );
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return null;
    }

    if ($row === false) {
        return null;
    }

    return [
        'status' => (string) ($row['status'] ?? ''),
        'package_name' => (string) ($row['package_name'] ?? ''),
        'domain_name' => (string) ($row['domain_name'] ?? ''),
        'care_choice' => (string) ($row['care_choice'] ?? ''),
    ];
}

/**
 * @param array{
 *   package_code: string,
 *   package_name: string,
 *   package_price_pence: int,
 *   domain_path: string,
 *   domain_name: string,
 *   business_name: string,
 *   contact_name: string,
 *   email: string,
 *   phone: string,
 *   contact_method: string,
 *   address_line_1: string,
 *   address_line_2: ?string,
 *   town: string,
 *   county: ?string,
 *   postcode: string,
 *   company_number: ?string,
 *   care_choice: string,
 *   care_price_pence: ?int
 * } $order
 */
function webco_insert_draft_order(PDO $db, array $order): ?string
{
    $statement = $db->prepare(
        'INSERT INTO orders (
            public_id, status, package_code, package_name, package_price_pence,
            domain_path, domain_name, business_name, contact_name, email, phone,
            contact_method, address_line_1, address_line_2, town, county, postcode,
            company_number, care_choice, care_price_pence
        ) VALUES (
            :public_id, \'draft\', :package_code, :package_name, :package_price_pence,
            :domain_path, :domain_name, :business_name, :contact_name, :email, :phone,
            :contact_method, :address_line_1, :address_line_2, :town, :county, :postcode,
            :company_number, :care_choice, :care_price_pence
        )'
    );

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $publicId = 'wc_' . bin2hex(random_bytes(10));
        try {
            $statement->execute([
                'public_id' => $publicId,
                'package_code' => $order['package_code'],
                'package_name' => $order['package_name'],
                'package_price_pence' => $order['package_price_pence'],
                'domain_path' => $order['domain_path'],
                'domain_name' => $order['domain_name'],
                'business_name' => $order['business_name'],
                'contact_name' => $order['contact_name'],
                'email' => $order['email'],
                'phone' => $order['phone'],
                'contact_method' => $order['contact_method'],
                'address_line_1' => $order['address_line_1'],
                'address_line_2' => $order['address_line_2'],
                'town' => $order['town'],
                'county' => $order['county'],
                'postcode' => $order['postcode'],
                'company_number' => $order['company_number'],
                'care_choice' => $order['care_choice'],
                'care_price_pence' => $order['care_price_pence'],
            ]);
            return $publicId;
        } catch (PDOException $exception) {
            $sqlState = $exception->errorInfo[0] ?? '';
            if ($sqlState !== '23000') {
                return null;
            }
        }
    }

    return null;
}
