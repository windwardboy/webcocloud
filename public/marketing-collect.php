<?php
/**
 * Accepts a small marketing count from Webco Cloud or a package demo.
 *
 * The response is empty. Rejected counts are discarded and still return 204,
 * except for a missing allowed origin or the wrong method.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/marketing.php';

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'marketing-collect.php') {
    webco_marketing_handle();
}

function webco_marketing_handle(): void
{
    $originHeader = $_SERVER['HTTP_ORIGIN'] ?? '';
    $origin = is_string($originHeader)
        && strpbrk($originHeader, "\r\n") === false
        && webco_marketing_origin_allowed($originHeader)
        ? $originHeader
        : null;

    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Resource-Policy: cross-origin');
    if ($origin !== null) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST');
    }

    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
    if ($method === 'OPTIONS') {
        http_response_code($origin === null ? 403 : 204);
        exit;
    }
    if ($method !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit;
    }
    if ($origin === null) {
        http_response_code(403);
        exit;
    }

    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $type = strtolower(trim(strtok((string) ($_SERVER['CONTENT_TYPE'] ?? ''), ';')));
    $body = '';
    if ($length >= 1 && $length <= 1500 && ($type === 'text/plain' || $type === 'application/json')) {
        $read = file_get_contents('php://input');
        $body = is_string($read) ? $read : '';
    }

    $db = webco_db();
    webco_marketing_accept(
        $db instanceof PDO ? $db : null,
        $origin,
        $body,
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        time(),
        null,
        webco_marketing_request_signals()
    );

    http_response_code(204);
    exit;
}

/**
 * @return array<string, string>
 */
function webco_marketing_request_signals(): array
{
    $map = [
        'gpc' => 'HTTP_SEC_GPC',
        'purpose' => 'HTTP_PURPOSE',
        'sec-purpose' => 'HTTP_SEC_PURPOSE',
        'x-purpose' => 'HTTP_X_PURPOSE',
        'x-moz' => 'HTTP_X_MOZ',
    ];
    $signals = [];
    foreach ($map as $key => $serverKey) {
        $value = $_SERVER[$serverKey] ?? '';
        if (is_string($value) && $value !== '') {
            $signals[$key] = $value;
        }
    }

    return $signals;
}
