<?php
/**
 * Domain availability proof of concept.
 *
 * 20i documents the bearer token as the base64-encoded General API key:
 * https://docs.20i.com/api/20i-api-documentation
 *
 * Domain search is GET https://api.20i.com/domain-search/{domain}
 * as used by the 20i Services API. Results may arrive as JSON or as one
 * JSON object per line.
 *
 * The API key is loaded from a file outside this repository. This script
 * must not print, log, or return that value.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

const WEBCO_SECRETS_FILE = '/home/sites/39b/8/836e0b54be/webco-secrets.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond('error', null, 405);
}

$raw = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['domain'] ?? '')
    : ($_GET['domain'] ?? '');

if (!is_string($raw)) {
    respond('error', null, 400);
}

$domain = normalise_domain($raw);
if ($domain === null) {
    respond('error', null, 400);
}

$key = load_api_key();
if ($key === null) {
    respond('error', null, 500);
}

$bearer = base64_encode($key);
$key = '';

$body = request_domain_search($domain, $bearer);
$bearer = '';

if ($body === null) {
    respond('error', $domain, 502);
}

$status = interpret_search($body, $domain);
if ($status === 'error') {
    respond('error', $domain, 502);
}

respond($status, $domain, 200);

function normalise_domain(string $value): ?string
{
    $value = strtolower(trim($value));
    $value = preg_replace('#^https?://#', '', $value) ?? '';
    $value = preg_replace('#/.*$#', '', $value) ?? '';
    $value = rtrim($value, '.');

    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)) {
        return null;
    }

    return $value;
}

function load_api_key(): ?string
{
    if (!is_file(WEBCO_SECRETS_FILE)) {
        return null;
    }

    ob_start();
    require WEBCO_SECRETS_FILE;
    ob_end_clean();

    $key = '';
    if (defined('WEBCO_20I_API_KEY')) {
        $key = (string) WEBCO_20I_API_KEY;
    } elseif (isset($WEBCO_20I_API_KEY) && is_string($WEBCO_20I_API_KEY)) {
        $key = $WEBCO_20I_API_KEY;
    }

    $key = trim($key);
    if ($key === '') {
        return null;
    }

    return $key;
}

function request_domain_search(string $domain, string $bearer): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $handle = curl_init('https://api.20i.com/domain-search/' . $domain);
    if ($handle === false) {
        return null;
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $bearer,
            'Expect:',
        ],
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($response) || $status < 200 || $status >= 300) {
        return null;
    }

    return $response;
}

function interpret_search(string $body, string $domain): string
{
    $match = null;

    foreach (search_records($body) as $row) {
        $name = strtolower(rtrim((string) ($row['name'] ?? ''), '.'));
        if ($name !== $domain) {
            continue;
        }

        $match = $row;
        if (empty($row['suggestion'])) {
            break;
        }
    }

    if ($match === null || !empty($match['suggestion'])) {
        return 'error';
    }

    $can = strtolower((string) ($match['can'] ?? ''));
    if ($can === 'register') {
        return 'available';
    }

    if ($can !== '') {
        return 'unavailable';
    }

    if (array_key_exists('available', $match)) {
        return $match['available'] === true ? 'available' : 'unavailable';
    }

    return 'error';
}

/**
 * @return list<array<string, mixed>>
 */
function search_records(string $body): array
{
    $records = [];
    $trimmed = trim($body);
    if ($trimmed === '') {
        return [];
    }

    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) {
        if (is_list_array($decoded)) {
            foreach ($decoded as $row) {
                if (is_array($row)) {
                    $records[] = $row;
                }
            }
            return $records;
        }

        if (isset($decoded['name']) || isset($decoded['can']) || isset($decoded['available'])) {
            return [$decoded];
        }

        foreach ($decoded as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!isset($row['name']) && is_string($key)) {
                $row['name'] = $key;
            }
            $records[] = $row;
        }

        return $records;
    }

    foreach (preg_split("/\r\n|\n|\r/", $trimmed) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true);
        if (is_array($row)) {
            $records[] = $row;
        }
    }

    return $records;
}

/**
 * @param array<mixed> $value
 */
function is_list_array(array $value): bool
{
    if ($value === []) {
        return true;
    }

    return array_keys($value) === range(0, count($value) - 1);
}

function respond(string $status, ?string $domain, int $code): void
{
    http_response_code($code);
    $payload = ['status' => $status];
    if ($domain !== null && $status !== 'error') {
        $payload['domain'] = $domain;
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
