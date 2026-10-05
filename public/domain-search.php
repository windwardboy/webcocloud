<?php
/**
 * Domain availability check for the Domains page.
 *
 * Checks the requested domain through the 20i Reseller API, then also checks
 * a short list of popular UK-focused endings for the same name.
 *
 * 20i documents the bearer token as the base64-encoded General API key:
 * https://docs.20i.com/api/20i-api-documentation
 *
 * Domain search is GET https://api.20i.com/domain-search/{domain}
 *
 * The API key is loaded from a file outside this repository. This script
 * must not print, log, or return that value.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

const WEBCO_SECRETS_FILE = '/home/sites/39b/8/836e0b54be/webco-secrets.php';

/** @var list<string> */
const WEBCO_POPULAR_UK_TLDS = ['co.uk', 'uk', 'com', 'org.uk', 'net', 'org'];

/** Known suffixes used when splitting a typed domain into name + ending. */
const WEBCO_KNOWN_TLDS = [
    'co.uk',
    'org.uk',
    'me.uk',
    'ltd.uk',
    'plc.uk',
    'net.uk',
    'ac.uk',
    'gov.uk',
    'uk',
    'com',
    'net',
    'org',
    'io',
    'co',
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error(405);
}

$raw = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['domain'] ?? '')
    : ($_GET['domain'] ?? '');

if (!is_string($raw)) {
    respond_error(400);
}

$domain = normalise_domain($raw);
if ($domain === null) {
    respond_error(400);
}

$parts = split_domain($domain);
if ($parts === null) {
    respond_error(400);
}

$key = load_api_key();
if ($key === null) {
    respond_error(500);
}

$bearer = base64_encode($key);
$key = '';

$candidates = [$domain];
foreach (WEBCO_POPULAR_UK_TLDS as $tld) {
    $candidate = $parts['label'] . '.' . $tld;
    if ($candidate !== $domain) {
        $candidates[] = $candidate;
    }
}

$bodies = request_domain_searches($candidates, $bearer);
$bearer = '';

$primaryStatus = interpret_search($bodies[$domain] ?? null, $domain);
if ($primaryStatus === 'error') {
    respond_error(502, $domain);
}

$alternatives = [];
foreach (WEBCO_POPULAR_UK_TLDS as $tld) {
    $candidate = $parts['label'] . '.' . $tld;
    if ($candidate === $domain) {
        continue;
    }

    $status = interpret_search($bodies[$candidate] ?? null, $candidate);
    if ($status !== 'available' && $status !== 'unavailable') {
        continue;
    }

    $alternatives[] = [
        'domain' => $candidate,
        'status' => $status,
    ];
}

respond_ok($primaryStatus, $domain, $alternatives);

function normalise_domain(string $value): ?string
{
    $value = strtolower(trim($value));
    $value = preg_replace('#^https?://#', '', $value) ?? '';
    $value = preg_replace('#/.*$#', '', $value) ?? '';
    $value = rtrim($value, '.');

    if (str_starts_with($value, 'www.')) {
        $value = substr($value, 4);
    }

    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)) {
        return null;
    }

    return $value;
}

/**
 * @return array{label: string, tld: string}|null
 */
function split_domain(string $domain): ?array
{
    $known = WEBCO_KNOWN_TLDS;
    usort($known, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($known as $tld) {
        $suffix = '.' . $tld;
        if (!str_ends_with($domain, $suffix)) {
            continue;
        }

        $label = substr($domain, 0, -strlen($suffix));
        if ($label === false || $label === '') {
            return null;
        }

        if (str_contains($label, '.')) {
            $segments = explode('.', $label);
            $label = (string) end($segments);
        }

        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
            return null;
        }

        return ['label' => $label, 'tld' => $tld];
    }

    $segments = explode('.', $domain);
    if (count($segments) < 2) {
        return null;
    }

    $tld = (string) array_pop($segments);
    $label = (string) array_pop($segments);
    if ($label === '' || $tld === '') {
        return null;
    }

    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
        return null;
    }

    return ['label' => $label, 'tld' => $tld];
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

/**
 * @param list<string> $domains
 * @return array<string, string|null>
 */
function request_domain_searches(array $domains, string $bearer): array
{
    $results = [];
    foreach ($domains as $domain) {
        $results[$domain] = null;
    }

    if (!function_exists('curl_multi_init') || !function_exists('curl_init')) {
        return $results;
    }

    $multi = curl_multi_init();
    if ($multi === false) {
        return $results;
    }

    /** @var array<int, array{0: \CurlHandle, 1: string}> */
    $handles = [];

    foreach ($domains as $domain) {
        $handle = curl_init('https://api.20i.com/domain-search/' . rawurlencode($domain));
        if ($handle === false) {
            continue;
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

        curl_multi_add_handle($multi, $handle);
        $handles[(int) $handle] = [$handle, $domain];
    }

    if ($handles === []) {
        curl_multi_close($multi);
        return $results;
    }

    $running = 0;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    foreach ($handles as [$handle, $domain]) {
        $response = curl_multi_getcontent($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);

        if (is_string($response) && $httpStatus >= 200 && $httpStatus < 300) {
            $results[$domain] = $response;
        }
    }

    curl_multi_close($multi);

    return $results;
}

function interpret_search(?string $body, string $domain): string
{
    if ($body === null) {
        return 'error';
    }

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

/**
 * @param list<array{domain: string, status: string}> $alternatives
 */
function respond_ok(string $status, string $domain, array $alternatives): void
{
    http_response_code(200);
    echo json_encode([
        'status' => $status,
        'domain' => $domain,
        'alternatives' => $alternatives,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function respond_error(int $code, ?string $domain = null): void
{
    http_response_code($code);
    $payload = ['status' => 'error'];
    if ($domain !== null && $code >= 500) {
        $payload['domain'] = $domain;
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
