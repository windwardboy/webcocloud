<?php
/**
 * Temporary read-only check for the provisioning eligibility query.
 *
 *   php bin/provision-diagnose.php
 *
 * CLI only. One SELECT. No schema changes, no Stripe, no 20i.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc > 1) {
    fwrite(STDERR, "diagnostic only; no arguments are accepted\n");
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
        'SELECT p.id
         FROM projects p
         INNER JOIN orders o ON o.id = p.order_id
         WHERE p.provisioning_status = \'ready\'
           AND o.status = \'paid\'
         ORDER BY p.id'
    );
    if ($statement === false) {
        fwrite(STDOUT, "query_failed\n");
        exit(1);
    }
    $count = count($statement->fetchAll());
} catch (PDOException $exception) {
    $info = $exception->errorInfo;
    $sqlState = is_array($info) ? (string) ($info[0] ?? $exception->getCode()) : (string) $exception->getCode();
    $driverCode = is_array($info) && isset($info[1]) ? (string) $info[1] : '';
    $driverMessage = is_array($info) && isset($info[2]) ? (string) $info[2] : $exception->getMessage();

    fwrite(STDOUT, 'sqlstate=' . webco_diagnose_redact($sqlState) . "\n");
    fwrite(STDOUT, 'driver_code=' . webco_diagnose_redact($driverCode) . "\n");
    fwrite(STDOUT, 'message=' . webco_diagnose_redact($driverMessage) . "\n");
    exit(1);
}

fwrite(STDOUT, "query_ok\n");
fwrite(STDOUT, $count . "\n");
exit(0);

function webco_diagnose_redact(string $value): string
{
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = preg_replace('/password\s*[=:]\s*\S+/i', 'password=redacted', $value) ?? $value;
    $value = preg_replace('#(mysql://[^:\s/]+:)[^@\s]+@#i', '$1redacted@', $value) ?? $value;
    $value = preg_replace('/\bsk_(?:test|live)_[A-Za-z0-9]+/', 'sk_redacted', $value) ?? $value;
    $value = trim($value);
    if (strlen($value) > 300) {
        return substr($value, 0, 300);
    }

    return $value;
}
