<?php
/**
 * Read-only list of current Webco Cloud customer/account records.
 *
 *   php bin/list-active-accounts.php
 *
 * Prints a compact table of orders and linked projects. Does not modify data.
 * Does not call Stripe or 20i. This file is not a web page.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');

if ($argc > 1) {
    fwrite(STDERR, "list only; no arguments are accepted\n");
    exit(1);
}

require dirname(__DIR__) . '/public/lib/db.php';

$db = webco_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "database unavailable\n");
    exit(1);
}

try {
    $statement = $db->query(
        'SELECT o.public_id,
                p.id AS project_id,
                o.business_name,
                o.email,
                o.domain_name,
                o.status AS order_status,
                p.provisioning_status,
                o.stripe_livemode,
                p.twentyi_package_id
         FROM orders o
         LEFT JOIN projects p ON p.order_id = o.id
         ORDER BY o.id ASC'
    );
    if ($statement === false) {
        fwrite(STDERR, "database unavailable\n");
        exit(1);
    }
    $rows = $statement->fetchAll();
} catch (PDOException) {
    fwrite(STDERR, "database unavailable\n");
    exit(1);
}

if ($rows === []) {
    fwrite(STDOUT, "active_accounts: 0\n");
    exit(0);
}

$headers = [
    'public_id',
    'project_id',
    'business_name',
    'email',
    'domain_name',
    'order_status',
    'provisioning_status',
    'stripe_livemode',
    'twentyi_package_id',
];

$lines = [];
$lines[] = implode("\t", $headers);
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $lines[] = implode("\t", [
        webco_list_active_cell($row['public_id'] ?? null),
        webco_list_active_cell($row['project_id'] ?? null),
        webco_list_active_cell($row['business_name'] ?? null),
        webco_list_active_cell($row['email'] ?? null),
        webco_list_active_cell($row['domain_name'] ?? null),
        webco_list_active_cell($row['order_status'] ?? null),
        webco_list_active_cell($row['provisioning_status'] ?? null),
        webco_list_active_cell($row['stripe_livemode'] ?? null),
        webco_list_active_cell($row['twentyi_package_id'] ?? null),
    ]);
}
$lines[] = 'active_accounts: ' . (string) count($rows);

fwrite(STDOUT, implode("\n", $lines) . "\n");
exit(0);

function webco_list_active_cell(mixed $value): string
{
    if ($value === null) {
        return '-';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (!is_scalar($value)) {
        return '-';
    }
    $text = trim((string) $value);
    if ($text === '') {
        return '-';
    }

    return str_replace(["\t", "\r", "\n"], ' ', $text);
}
