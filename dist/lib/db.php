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

const WEBCO_SECRETS_FILE = '/home/sites/39b/8/836e0b54be/webco-secrets.php';

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
    if (!is_file(WEBCO_SECRETS_FILE)) {
        return null;
    }

    ob_start();
    require WEBCO_SECRETS_FILE;
    ob_end_clean();

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
 * Moves a draft to paid once. A repeat for the same Checkout Session does not write again.
 *
 * @return 'paid'|'already'|'missing'|'error'
 */
function webco_mark_order_paid(
    PDO $db,
    string $publicId,
    string $sessionId,
    ?string $customerId,
    ?string $subscriptionId
): string {
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $publicId)) {
        return 'error';
    }
    if (!preg_match('/^cs_test_[A-Za-z0-9]{8,240}$/', $sessionId) || strlen($sessionId) > 255) {
        return 'error';
    }

    try {
        $update = $db->prepare(
            'UPDATE orders
             SET status = \'paid\',
                 stripe_checkout_session_id = :session_id,
                 stripe_customer_id = :customer_id,
                 stripe_subscription_id = :subscription_id,
                 paid_at = CURRENT_TIMESTAMP
             WHERE public_id = :public_id
               AND status = \'draft\''
        );
        $update->execute([
            'session_id' => $sessionId,
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'public_id' => $publicId,
        ]);
        if ($update->rowCount() === 1) {
            return 'paid';
        }

        $select = $db->prepare(
            'SELECT status, stripe_checkout_session_id
             FROM orders
             WHERE public_id = :public_id'
        );
        $select->execute(['public_id' => $publicId]);
        $row = $select->fetch();
        if ($row === false) {
            return 'missing';
        }
        if (($row['status'] ?? '') === 'paid') {
            return 'already';
        }
    } catch (PDOException $exception) {
        $sqlState = $exception->errorInfo[0] ?? '';
        if ($sqlState === '23000') {
            return 'already';
        }

        return 'error';
    }

    return 'error';
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
