<?php
/**
 * Read-only order status for the checkout success page.
 * The order id is only a lookup key. Payment is whatever MySQL already stored.
 * The response lists only the fields that page shows.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/db.php';

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'order-status.php') {
    webco_handle_order_status();
}

function webco_handle_order_status(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        webco_order_status_respond(405, ['status' => 'unknown']);
    }

    $orderId = $_GET['order'] ?? '';
    if (!is_string($orderId) || !preg_match('/^wc_[a-f0-9]{20}$/', $orderId)) {
        webco_order_status_respond(200, ['status' => 'unknown']);
    }

    $db = webco_db();
    if (!$db instanceof PDO) {
        webco_order_status_respond(200, ['status' => 'unknown']);
    }

    webco_order_status_respond(200, webco_order_status_payload(webco_find_order_display($db, $orderId), $orderId));
}

/**
 * @param array{status: string, package_name: string, domain_name: string, care_choice: string}|null $row
 * @return array<string, string>
 */
function webco_order_status_payload(?array $row, string $publicId): array
{
    if ($row === null || !preg_match('/^wc_[a-f0-9]{20}$/', $publicId)) {
        return ['status' => 'unknown'];
    }

    if ($row['status'] === 'draft') {
        return [
            'status' => 'draft',
            'orderId' => $publicId,
        ];
    }

    if ($row['status'] !== 'paid') {
        return ['status' => 'unknown'];
    }

    $payload = [
        'status' => 'paid',
        'orderId' => $publicId,
    ];

    if ($row['package_name'] === 'Webco Essential' || $row['package_name'] === 'Webco Professional') {
        $payload['packageName'] = $row['package_name'];
    }

    $domain = webco_status_domain($row['domain_name']);
    if ($domain !== null) {
        $payload['domain'] = $domain;
    }

    if ($row['care_choice'] === 'managed' || $row['care_choice'] === 'standard') {
        $payload['care'] = $row['care_choice'];
    }

    return $payload;
}

function webco_status_domain(string $value): ?string
{
    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)) {
        return null;
    }

    return $value;
}

/**
 * @param array<string, string> $body
 */
function webco_order_status_respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
