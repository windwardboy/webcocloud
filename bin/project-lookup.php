<?php
/**
 * Read-only project lookup by Webco order id.
 *
 *   php bin/project-lookup.php --order=wc_d1c805f3b3cb89e803f4
 *
 * Prints project_id, provisioning_status, and twentyi_package_id when one
 * is stored. Does not write to the database and does not call an API.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');

/**
 * @param list<string> $argv
 * @return array{ok: bool, error: string, order: string}
 */
function webco_project_lookup_options(array $argv): array
{
    $order = null;
    $empty = ['ok' => false, 'error' => '', 'order' => ''];

    foreach (array_slice($argv, 1) as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, '--order=')) {
            $empty['error'] = 'pass one order, as in --order=wc_d1c805f3b3cb89e803f4';

            return $empty;
        }
        if ($order !== null) {
            $empty['error'] = '--order may be given once';

            return $empty;
        }
        $raw = substr($arg, strlen('--order='));
        if (!preg_match('/^wc_[a-f0-9]{20}$/', $raw)) {
            $empty['error'] = '--order must be a Webco order id, as in --order=wc_d1c805f3b3cb89e803f4';

            return $empty;
        }
        $order = $raw;
    }

    if ($order === null) {
        $empty['error'] = '--order is required, as in --order=wc_d1c805f3b3cb89e803f4';

        return $empty;
    }

    return ['ok' => true, 'error' => '', 'order' => $order];
}

/**
 * @return array{ok: bool, found: bool, project_id: int, provisioning_status: string, package_id: ?string}
 */
function webco_project_lookup_row(PDO $db, string $orderId): array
{
    $missing = [
        'ok' => false,
        'found' => false,
        'project_id' => 0,
        'provisioning_status' => '',
        'package_id' => null,
    ];
    if (!preg_match('/^wc_[a-f0-9]{20}$/', $orderId)) {
        return $missing;
    }

    try {
        $statement = $db->prepare(
            'SELECT id, provisioning_status, twentyi_package_id
             FROM projects
             WHERE order_public_id = :order_public_id'
        );
        $statement->execute(['order_public_id' => $orderId]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return $missing;
    }
    if ($row === false) {
        $missing['ok'] = true;

        return $missing;
    }

    $projectId = (int) ($row['id'] ?? 0);
    $status = (string) ($row['provisioning_status'] ?? '');
    if ($projectId < 1 || !in_array($status, ['waiting_payment', 'ready', 'in_progress', 'provisioned', 'failed'], true)) {
        return $missing;
    }

    $packageId = $row['twentyi_package_id'] ?? null;
    if (is_int($packageId)) {
        $packageId = (string) $packageId;
    }
    if (!is_string($packageId) || !preg_match('/^[1-9][0-9]{0,11}$/', trim($packageId))) {
        $packageId = null;
    } else {
        $packageId = trim($packageId);
    }

    return [
        'ok' => true,
        'found' => true,
        'project_id' => $projectId,
        'provisioning_status' => $status,
        'package_id' => $packageId,
    ];
}

function webco_project_lookup_text(int $projectId, string $status, ?string $packageId): string
{
    $lines = [
        'project_id: ' . $projectId,
        'provisioning_status: ' . $status,
    ];
    if ($packageId !== null && preg_match('/^[1-9][0-9]{0,11}$/', $packageId)) {
        $lines[] = 'twentyi_package_id: ' . $packageId;
    }

    return implode("\n", $lines) . "\n";
}

$webcoProjectLookupEntry = realpath(__FILE__);
$webcoProjectLookupScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
if (is_string($webcoProjectLookupEntry) && $webcoProjectLookupEntry === $webcoProjectLookupScript) {
    $options = webco_project_lookup_options($argv);
    if (!$options['ok']) {
        fwrite(STDERR, $options['error'] . "\n");
        exit(1);
    }

    require dirname(__DIR__) . '/public/lib/db.php';

    $db = webco_db();
    if (!$db instanceof PDO) {
        fwrite(STDERR, "database unavailable\n");
        exit(1);
    }

    $row = webco_project_lookup_row($db, $options['order']);
    if (!$row['ok']) {
        fwrite(STDERR, "database unavailable\n");
        exit(1);
    }
    if (!$row['found']) {
        fwrite(STDERR, "project not found\n");
        exit(1);
    }

    fwrite(STDOUT, webco_project_lookup_text($row['project_id'], $row['provisioning_status'], $row['package_id']));
    exit(0);
}
unset($webcoProjectLookupEntry, $webcoProjectLookupScript);
