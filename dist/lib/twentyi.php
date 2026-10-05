<?php
/**
 * 20i Services API client.
 *
 * Discovery reads GET /package and GET /reseller/star/packageTypes.
 * Hosting creation is POST /reseller/star/addWeb, and only when a caller
 * passes a payload to webco_twentyi_create_hosting_package(). The
 * provisioning worker does not call this file.
 *
 * The General API key stays outside this repository. This file must not
 * print, log, or return that key, and error results omit response bodies.
 */

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'twentyi.php') {
    http_response_code(404);
    exit;
}

if (!defined('WEBCO_SECRETS_FILE')) {
    define('WEBCO_SECRETS_FILE', '/home/sites/39b/8/836e0b54be/webco-secrets.php');
}

const WEBCO_TWENTYI_PACKAGE_LIST = '/package';
const WEBCO_TWENTYI_PACKAGE_TYPES = '/reseller/*/packageTypes';
const WEBCO_TWENTYI_ADD_WEB = '/reseller/*/addWeb';
const WEBCO_TWENTYI_ADD_DOMAIN = '/reseller/*/addDomain';
const WEBCO_TWENTYI_DOMAIN_LIST = '/domain';
const WEBCO_TWENTYI_DOMAIN_SEARCH_PREFIX = '/domain-search/';
const WEBCO_TWENTYI_PLATFORM_DOMAIN = 'webcocloud.net';

function webco_twentyi_api_key(): ?string
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

function webco_twentyi_read_url(string $path): ?string
{
    if (
        $path !== WEBCO_TWENTYI_PACKAGE_LIST
        && $path !== WEBCO_TWENTYI_PACKAGE_TYPES
        && $path !== WEBCO_TWENTYI_DOMAIN_LIST
    ) {
        return null;
    }

    return 'https://api.20i.com' . $path;
}

/**
 * Domain search is GET /domain-search/{domain}. Only that pattern is allowed.
 */
function webco_twentyi_domain_search_url(string $domain): ?string
{
    $domain = webco_twentyi_domain($domain);
    if ($domain === null) {
        return null;
    }

    return 'https://api.20i.com' . WEBCO_TWENTYI_DOMAIN_SEARCH_PREFIX . rawurlencode($domain);
}

/**
 * Included with hosting/Managed Care: single-label .uk or .co.uk only.
 * Rejects org.uk, me.uk, and other multi-part UK endings.
 */
function webco_domain_is_included_tld(string $domain): bool
{
    $domain = webco_twentyi_domain($domain);
    if ($domain === null) {
        return false;
    }

    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.(?:co\.)?uk$/', $domain) === 1;
}

/**
 * @return array{ok: bool, status: int, body: string}
 */
function webco_twentyi_http_get(string $path, string $bearer): array
{
    $failed = ['ok' => false, 'status' => 0, 'body' => ''];
    $url = webco_twentyi_read_url($path);
    if ($url === null || $bearer === '' || !function_exists('curl_init')) {
        return $failed;
    }

    $handle = curl_init($url);
    if ($handle === false) {
        return $failed;
    }

    curl_setopt_array($handle, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $bearer,
            'Expect:',
        ],
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($response) || strlen($response) > 5000000) {
        return ['ok' => false, 'status' => $status, 'body' => ''];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $response,
    ];
}

/**
 * @param callable(string): array{ok: bool, status: int, body: string} $get
 * @return array<string, mixed>
 */
function webco_twentyi_discovery(callable $get): array
{
    $types = webco_twentyi_read_section($get, WEBCO_TWENTYI_PACKAGE_TYPES, 'webco_twentyi_type_rows');
    $packages = webco_twentyi_read_section($get, WEBCO_TWENTYI_PACKAGE_LIST, 'webco_twentyi_package_rows');

    return [
        'ok' => ($types['ok'] ?? false) === true && ($packages['ok'] ?? false) === true,
        'package_types' => $types,
        'packages' => $packages,
        'platform_domain' => WEBCO_TWENTYI_PLATFORM_DOMAIN,
        'platform_match' => webco_twentyi_platform_match(
            is_array($packages['rows'] ?? null) ? $packages['rows'] : []
        ),
    ];
}

/**
 * @param callable(string): array{ok: bool, status: int, body: string} $get
 * @param callable(array<mixed>): list<array<string, mixed>> $parse
 * @return array{ok: bool, status: int, failure: string, rows: list<array<string, mixed>>}
 */
function webco_twentyi_read_section(callable $get, string $path, callable $parse): array
{
    $empty = ['ok' => false, 'status' => 0, 'failure' => 'transport', 'rows' => []];
    if (webco_twentyi_read_url($path) === null) {
        return $empty;
    }

    $result = $get($path);
    if (!is_array($result) || !is_bool($result['ok'] ?? null) || !is_int($result['status'] ?? null) || !is_string($result['body'] ?? null)) {
        return $empty;
    }
    if ($result['ok'] !== true) {
        return [
            'ok' => false,
            'status' => $result['status'],
            'failure' => $result['status'] > 0 ? 'http' : 'transport',
            'rows' => [],
        ];
    }

    $decoded = webco_twentyi_decode($result['body']);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $result['status'],
            'failure' => 'unreadable',
            'rows' => [],
        ];
    }

    return [
        'ok' => true,
        'status' => $result['status'],
        'failure' => '',
        'rows' => $parse($decoded),
    ];
}

function webco_twentyi_decode(string $body): mixed
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return null;
    }
    if (array_key_exists('result', $decoded) && count($decoded) === 1 && is_array($decoded['result'])) {
        return $decoded['result'];
    }

    return $decoded;
}

/**
 * @param array<mixed> $decoded
 * @return list<array{id: string, label: string, platform: string}>
 */
function webco_twentyi_type_rows(array $decoded): array
{
    $rows = [];
    if (webco_twentyi_is_list($decoded)) {
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = webco_twentyi_type_row($row, null);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
    } else {
        foreach ($decoded as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = webco_twentyi_type_row($row, is_int($key) ? (string) $key : (is_string($key) ? $key : null));
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
    }

    usort($rows, static function (array $left, array $right): int {
        return ((int) $left['id']) <=> ((int) $right['id']);
    });

    return $rows;
}

/**
 * @param array<mixed> $row
 * @return array{id: string, label: string, platform: string}|null
 */
function webco_twentyi_type_row(array $row, ?string $key): ?array
{
    $id = webco_twentyi_id($row['id'] ?? null) ?? webco_twentyi_id($key);
    if ($id === null) {
        return null;
    }

    return [
        'id' => $id,
        'label' => webco_twentyi_text($row['label'] ?? $row['name'] ?? null) ?? '',
        'platform' => webco_twentyi_platform($row['platform'] ?? null) ?? '',
    ];
}

/**
 * @param array<mixed> $decoded
 * @return list<array<string, mixed>>
 */
function webco_twentyi_package_rows(array $decoded): array
{
    $source = webco_twentyi_is_list($decoded) ? $decoded : array_values($decoded);
    $rows = [];
    foreach ($source as $row) {
        if (!is_array($row)) {
            continue;
        }
        $mapped = webco_twentyi_package_row($row);
        if ($mapped !== null) {
            $rows[] = $mapped;
        }
    }

    usort($rows, static function (array $left, array $right): int {
        return ((int) $left['id']) <=> ((int) $right['id']);
    });

    return $rows;
}

/**
 * @param array<mixed> $row
 * @return array<string, mixed>|null
 */
function webco_twentyi_package_row(array $row): ?array
{
    $id = webco_twentyi_id($row['id'] ?? null);
    if ($id === null) {
        return null;
    }

    $names = [];
    $withheld = 0;
    $rawNames = $row['names'] ?? null;
    if (is_array($rawNames)) {
        foreach ($rawNames as $name) {
            $domain = webco_twentyi_domain(is_string($name) ? $name : '');
            if ($domain === null) {
                $withheld++;
                continue;
            }
            $names[] = $domain;
        }
    } elseif (array_key_exists('names', $row)) {
        $withheld++;
    }

    $name = webco_twentyi_domain(is_string($row['name'] ?? null) ? $row['name'] : '');
    $labelState = 'absent';
    $label = '';
    if (array_key_exists('label', $row)) {
        $labelState = 'present';
        $clean = webco_twentyi_text($row['label']);
        if ($clean === null) {
            $labelState = is_string($row['label']) && trim($row['label']) === '' ? 'empty' : 'withheld';
        } else {
            $label = $clean;
        }
    }

    return [
        'id' => $id,
        'name' => $name ?? '',
        'label_state' => $labelState,
        'label' => $label,
        'package_labels' => array_key_exists('packageLabels', $row),
        'type_ref' => webco_twentyi_id($row['typeRef'] ?? null) ?? '',
        'package_type_name' => webco_twentyi_text($row['packageTypeName'] ?? null) ?? '',
        'created' => webco_twentyi_timestamp($row['created'] ?? null) ?? '',
        'names' => $names,
        'names_withheld' => $withheld,
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{state: string, rows: list<array<string, mixed>>}
 */
function webco_twentyi_platform_match(array $rows): array
{
    $matched = [];
    foreach ($rows as $row) {
        $names = $row['names'] ?? [];
        $name = (string) ($row['name'] ?? '');
        $domains = is_array($names) ? $names : [];
        if ($name !== '') {
            $domains[] = $name;
        }
        foreach ($domains as $domain) {
            if ($domain === WEBCO_TWENTYI_PLATFORM_DOMAIN) {
                $matched[] = $row;
                break;
            }
        }
    }

    $state = 'absent';
    if (count($matched) === 1) {
        $state = 'matched';
    } elseif (count($matched) > 1) {
        $state = 'ambiguous';
    }

    return ['state' => $state, 'rows' => $matched];
}

/**
 * @param array<string, mixed> $report
 */
function webco_twentyi_discovery_text(array $report): string
{
    $lines = [
        '20i discovery (read-only)',
        'endpoints: GET /package, GET /reseller/*/packageTypes',
    ];

    $types = is_array($report['package_types'] ?? null) ? $report['package_types'] : [];
    $lines[] = webco_twentyi_section_heading('package_types', $types);
    foreach (is_array($types['rows'] ?? null) ? $types['rows'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lines[] = '- id=' . webco_twentyi_field($row['id'] ?? '')
            . ' label=' . webco_twentyi_field($row['label'] ?? '', 'not returned')
            . ' platform=' . webco_twentyi_field($row['platform'] ?? '', 'not returned');
    }

    $packages = is_array($report['packages'] ?? null) ? $report['packages'] : [];
    $rows = [];
    foreach (is_array($packages['rows'] ?? null) ? $packages['rows'] : [] as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    $lines[] = webco_twentyi_section_heading('packages', $packages, (string) count($rows));
    $lines[] = 'label_field: ' . webco_twentyi_presence($rows, 'label_state');
    $lines[] = 'package_labels_field: ' . webco_twentyi_flag_presence($rows, 'package_labels');
    foreach ($rows as $row) {
        $names = is_array($row['names'] ?? null) ? $row['names'] : [];
        $nameText = $names === [] ? '(none)' : implode(', ', array_map('strval', $names));
        $withheld = (int) ($row['names_withheld'] ?? 0);
        if ($withheld > 0) {
            $nameText .= ' withheld=' . $withheld;
        }
        $lines[] = '- id=' . webco_twentyi_field($row['id'] ?? '')
            . ' name=' . webco_twentyi_field($row['name'] ?? '', 'not returned')
            . ' label=' . webco_twentyi_label_field($row)
            . ' typeRef=' . webco_twentyi_field($row['type_ref'] ?? '', 'not returned')
            . ' packageTypeName=' . webco_twentyi_field($row['package_type_name'] ?? '', 'not returned')
            . ' created=' . webco_twentyi_field($row['created'] ?? '', 'not returned')
            . ' names=' . $nameText;
    }

    $domain = webco_twentyi_domain((string) ($report['platform_domain'] ?? '')) ?? WEBCO_TWENTYI_PLATFORM_DOMAIN;
    $match = is_array($report['platform_match'] ?? null) ? $report['platform_match'] : [];
    $state = (string) ($match['state'] ?? 'absent');
    if ($state === 'matched') {
        $chosen = is_array($match['rows'][0] ?? null) ? $match['rows'][0] : [];
        $lines[] = $domain . ': matched';
        $lines[] = 'package_id: ' . webco_twentyi_field($chosen['id'] ?? '');
        $lines[] = 'typeRef: ' . webco_twentyi_field($chosen['type_ref'] ?? '', 'not returned');
        $lines[] = 'packageTypeName: ' . webco_twentyi_field($chosen['package_type_name'] ?? '', 'not returned');
    } elseif ($state === 'ambiguous') {
        $ids = [];
        foreach (is_array($match['rows'] ?? null) ? $match['rows'] : [] as $row) {
            if (is_array($row)) {
                $ids[] = webco_twentyi_field($row['id'] ?? '');
            }
        }
        $lines[] = $domain . ': ambiguous package ids ' . implode(', ', $ids);
    } else {
        $lines[] = $domain . ': not matched';
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param array<string, mixed> $section
 */
function webco_twentyi_section_heading(string $label, array $section, ?string $count = null): string
{
    if (($section['ok'] ?? false) !== true) {
        $failure = (string) ($section['failure'] ?? 'transport');
        $status = (int) ($section['status'] ?? 0);
        if ($failure === 'http' && $status > 0) {
            return $label . ': failed (HTTP ' . $status . ')';
        }
        if ($failure === 'unreadable') {
            return $label . ': failed (unreadable response)';
        }

        return $label . ': failed (transport)';
    }
    if ($count === null) {
        $rows = $section['rows'] ?? [];
        $count = (string) (is_array($rows) ? count($rows) : 0);
    }

    return $label . ': ' . $count;
}

/**
 * @param list<array<string, mixed>> $rows
 */
function webco_twentyi_presence(array $rows, string $stateKey): string
{
    if ($rows === []) {
        return 'no packages returned';
    }
    $present = 0;
    foreach ($rows as $row) {
        if (($row[$stateKey] ?? 'absent') !== 'absent') {
            $present++;
        }
    }
    if ($present === count($rows)) {
        return 'returned';
    }
    if ($present === 0) {
        return 'not returned';
    }

    return 'returned on ' . $present . ' of ' . count($rows);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function webco_twentyi_flag_presence(array $rows, string $key): string
{
    if ($rows === []) {
        return 'no packages returned';
    }
    $present = 0;
    foreach ($rows as $row) {
        if (($row[$key] ?? false) === true) {
            $present++;
        }
    }
    if ($present === count($rows)) {
        return 'returned';
    }
    if ($present === 0) {
        return 'not returned';
    }

    return 'returned on ' . $present . ' of ' . count($rows);
}

/**
 * @param array<string, mixed> $row
 */
function webco_twentyi_label_field(array $row): string
{
    $state = (string) ($row['label_state'] ?? 'absent');
    if ($state === 'present') {
        return webco_twentyi_field($row['label'] ?? '');
    }
    if ($state === 'empty') {
        return '(empty)';
    }
    if ($state === 'withheld') {
        return '(withheld)';
    }

    return '(not returned)';
}

function webco_twentyi_field(mixed $value, string $missing = '(withheld)'): string
{
    $text = is_string($value) ? $value : '';
    if ($text === '') {
        return '(' . $missing . ')';
    }

    return $text;
}

/**
 * @param array<mixed> $value
 */
function webco_twentyi_is_list(array $value): bool
{
    if ($value === []) {
        return true;
    }

    return array_keys($value) === range(0, count($value) - 1);
}

function webco_twentyi_id(mixed $value): ?string
{
    if (is_int($value)) {
        $value = (string) $value;
    }
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if (!preg_match('/^[1-9][0-9]{0,11}$/', $value)) {
        return null;
    }

    return $value;
}

function webco_twentyi_text(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '' || strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F@\/\\\\]/', $value) === 1) {
        return null;
    }

    return $value;
}

function webco_twentyi_platform(mixed $value): ?string
{
    if (!is_string($value) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $value) !== 1) {
        return null;
    }

    return $value;
}

function webco_twentyi_timestamp(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/', $value) !== 1) {
        return null;
    }

    return $value;
}

function webco_twentyi_domain(string $value): ?string
{
    $value = strtolower(rtrim(trim($value), '.'));
    if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value) !== 1) {
        return null;
    }

    return $value;
}

function webco_twentyi_write_url(string $path): ?string
{
    if ($path !== WEBCO_TWENTYI_ADD_WEB && $path !== WEBCO_TWENTYI_ADD_DOMAIN) {
        return null;
    }

    return 'https://api.20i.com' . $path;
}

/**
 * POST the addWeb path. Any other path is refused before curl runs.
 *
 * @return array{ok: bool, status: int, body: string}
 */
function webco_twentyi_http_post(string $path, string $bearer, string $body): array
{
    $failed = ['ok' => false, 'status' => 0, 'body' => ''];
    $url = webco_twentyi_write_url($path);
    if ($url === null || $bearer === '' || $body === '' || strlen($body) > 8000 || !function_exists('curl_init')) {
        return $failed;
    }

    $handle = curl_init($url);
    if ($handle === false) {
        return $failed;
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $bearer,
            'Expect:',
        ],
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($response) || strlen($response) > 100000) {
        return ['ok' => false, 'status' => $status, 'body' => ''];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $response,
    ];
}

/**
 * Fields for the addWeb POST. Label is omitted when null.
 * An unusable label rejects the payload rather than being dropped.
 *
 * @return array{domain_name: string, type: string, label?: string}|null
 */
function webco_twentyi_add_web_payload(string $domainName, string $packageTypeId, ?string $label = null): ?array
{
    $domain = webco_twentyi_domain($domainName);
    $type = webco_twentyi_id($packageTypeId);
    if ($domain === null || $type === null) {
        return null;
    }

    $payload = [
        'domain_name' => $domain,
        'type' => $type,
    ];
    if ($label === null) {
        return $payload;
    }

    $clean = webco_twentyi_text($label);
    if ($clean === null) {
        return null;
    }
    $payload['label'] = $clean;

    return $payload;
}

/**
 * The created package ID is the numeric result field, as documented for addWeb.
 */
function webco_twentyi_add_web_package_id(string $body): ?string
{
    if ($body === '' || strlen($body) > 100000) {
        return null;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !array_key_exists('result', $decoded)) {
        return null;
    }

    return webco_twentyi_id($decoded['result']);
}

/**
 * Create one hosting package. The callable performs the POST so tests can
 * supply a fake transport. Nothing here is called by the provision worker.
 *
 * @param callable(string, string): array{ok: bool, status: int, body: string} $post
 * @return array{ok: bool, package_id: string, failure: string, status: int}
 */
function webco_twentyi_create_hosting_package(callable $post, string $domainName, string $packageTypeId, ?string $label = null): array
{
    $invalid = ['ok' => false, 'package_id' => '', 'failure' => 'invalid', 'status' => 0];
    $payload = webco_twentyi_add_web_payload($domainName, $packageTypeId, $label);
    if ($payload === null) {
        return $invalid;
    }

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || $body === '') {
        return $invalid;
    }

    $result = $post(WEBCO_TWENTYI_ADD_WEB, $body);
    if (!is_array($result) || !is_bool($result['ok'] ?? null) || !is_int($result['status'] ?? null) || !is_string($result['body'] ?? null)) {
        return ['ok' => false, 'package_id' => '', 'failure' => 'transport', 'status' => 0];
    }
    if ($result['ok'] !== true) {
        return [
            'ok' => false,
            'package_id' => '',
            'failure' => $result['status'] > 0 ? 'http' : 'transport',
            'status' => $result['status'],
        ];
    }

    $packageId = webco_twentyi_add_web_package_id($result['body']);
    if ($packageId === null) {
        $decoded = json_decode($result['body'], true);

        return [
            'ok' => false,
            'package_id' => '',
            'failure' => is_array($decoded) ? 'rejected' : 'unreadable',
            'status' => $result['status'],
        ];
    }

    return [
        'ok' => true,
        'package_id' => $packageId,
        'failure' => '',
        'status' => $result['status'],
    ];
}

/**
 * Contact field for domain registration. Allows a longer address line.
 */
function webco_twentyi_contact_text(mixed $value, int $max = 160): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        return null;
    }

    return $value;
}

/**
 * UK-oriented telephone for 20i domain contacts.
 */
function webco_twentyi_contact_phone(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if (preg_match('/^(?:0\d{10}|\+44\d{10}|0044\d{10})$/', $value) === 1) {
        if (str_starts_with($value, '0')) {
            return '+44' . substr($value, 1);
        }
        if (str_starts_with($value, '0044')) {
            return '+44' . substr($value, 4);
        }

        return $value;
    }

    return null;
}

/**
 * Build the addDomain POST body from order contact details.
 *
 * @param array{
 *   business_name?: mixed,
 *   contact_name?: mixed,
 *   email?: mixed,
 *   phone?: mixed,
 *   address_line_1?: mixed,
 *   address_line_2?: mixed,
 *   town?: mixed,
 *   county?: mixed,
 *   postcode?: mixed,
 *   company_number?: mixed
 * } $order
 * @return array<string, mixed>|null
 */
function webco_twentyi_add_domain_payload(string $domainName, array $order, int $years = 1): ?array
{
    $domain = webco_twentyi_domain($domainName);
    if ($domain === null || !webco_domain_is_included_tld($domain) || $years < 1 || $years > 10) {
        return null;
    }

    $organisation = webco_twentyi_contact_text($order['business_name'] ?? null, 120);
    $name = webco_twentyi_contact_text($order['contact_name'] ?? null, 120);
    $email = strtolower(trim((string) ($order['email'] ?? '')));
    $phone = webco_twentyi_contact_phone($order['phone'] ?? null);
    $line1 = webco_twentyi_contact_text($order['address_line_1'] ?? null, 120);
    $line2 = webco_twentyi_contact_text($order['address_line_2'] ?? null, 120);
    $town = webco_twentyi_contact_text($order['town'] ?? null, 80);
    $county = webco_twentyi_contact_text($order['county'] ?? null, 80);
    $postcode = webco_twentyi_contact_text($order['postcode'] ?? null, 10);
    if (
        $organisation === null
        || $name === null
        || $email === ''
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || strlen($email) > 160
        || $phone === null
        || $line1 === null
        || $town === null
        || $postcode === null
    ) {
        return null;
    }

    $address = $line1;
    if ($line2 !== null) {
        $address .= ', ' . $line2;
        if (strlen($address) > 200) {
            $address = $line1;
        }
    }

    $contact = [
        'organisation' => $organisation,
        'name' => $name,
        'address' => $address,
        'telephone' => $phone,
        'email' => $email,
        'cc' => 'GB',
        'pc' => $postcode,
        'sp' => $county ?? $town,
        'city' => $town,
    ];

    $company = strtoupper(trim((string) ($order['company_number'] ?? '')));
    if (preg_match('/^[0-9A-Z]{6,8}$/', $company) === 1) {
        $contact['extension'] = ['co-no' => $company];
    }

    return [
        'name' => $domain,
        'years' => $years,
        'contact' => $contact,
        'privacyService' => false,
    ];
}

/**
 * @param callable(string, string): array{ok: bool, status: int, body: string} $post
 * @param array<string, mixed> $order
 * @return array{ok: bool, failure: string, status: int}
 */
function webco_twentyi_register_domain(callable $post, string $domainName, array $order, int $years = 1): array
{
    $invalid = ['ok' => false, 'failure' => 'invalid', 'status' => 0];
    $payload = webco_twentyi_add_domain_payload($domainName, $order, $years);
    if ($payload === null) {
        return $invalid;
    }

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || $body === '') {
        return $invalid;
    }

    $result = $post(WEBCO_TWENTYI_ADD_DOMAIN, $body);
    if (!is_array($result) || !is_bool($result['ok'] ?? null) || !is_int($result['status'] ?? null) || !is_string($result['body'] ?? null)) {
        return ['ok' => false, 'failure' => 'transport', 'status' => 0];
    }
    if ($result['ok'] !== true) {
        return [
            'ok' => false,
            'failure' => $result['status'] > 0 ? 'http' : 'transport',
            'status' => $result['status'],
        ];
    }

    return [
        'ok' => true,
        'failure' => '',
        'status' => $result['status'],
    ];
}

/**
 * Interpret a domain-search response body for one domain.
 *
 * @return 'available'|'unavailable'|'error'
 */
function webco_twentyi_domain_search_status(string $body, string $domain): string
{
    $domain = webco_twentyi_domain($domain);
    if ($domain === null || $body === '' || strlen($body) > 500000) {
        return 'error';
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return 'error';
    }

    $rows = [];
    if (webco_twentyi_is_list($decoded)) {
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    } elseif (isset($decoded['name']) || isset($decoded['can']) || isset($decoded['available'])) {
        $rows[] = $decoded;
    } else {
        foreach ($decoded as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!isset($row['name']) && is_string($key)) {
                $row['name'] = $key;
            }
            $rows[] = $row;
        }
    }

    $match = null;
    foreach ($rows as $row) {
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
 * @param callable(string): array{ok: bool, status: int, body: string} $get
 * @return 'available'|'unavailable'|'error'
 */
function webco_twentyi_check_domain_availability(callable $get, string $domainName): string
{
    $domain = webco_twentyi_domain($domainName);
    $url = webco_twentyi_domain_search_url($domainName);
    if ($domain === null || $url === null) {
        return 'error';
    }

    $path = WEBCO_TWENTYI_DOMAIN_SEARCH_PREFIX . rawurlencode($domain);
    $result = $get($path);
    if (!is_array($result) || ($result['ok'] ?? false) !== true || !is_string($result['body'] ?? null)) {
        return 'error';
    }

    return webco_twentyi_domain_search_status($result['body'], $domain);
}

/**
 * Domain names from GET /domain. Response bodies are not kept beyond parsing.
 *
 * @return list<string>
 */
function webco_twentyi_registered_domain_names(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return [];
    }

    $rows = webco_twentyi_is_list($decoded) ? $decoded : [$decoded];
    $names = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $domain = webco_twentyi_domain((string) ($row['name'] ?? ''));
        if ($domain !== null) {
            $names[] = $domain;
        }
    }

    return array_values(array_unique($names));
}

/**
 * @param callable(string): array{ok: bool, status: int, body: string} $get
 * @return array{ok: bool, domains: list<string>}
 */
function webco_twentyi_list_registered_domains(callable $get): array
{
    $result = $get(WEBCO_TWENTYI_DOMAIN_LIST);
    if (!is_array($result) || ($result['ok'] ?? false) !== true || !is_string($result['body'] ?? null)) {
        return ['ok' => false, 'domains' => []];
    }
    $decoded = json_decode($result['body'], true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'domains' => []];
    }

    return ['ok' => true, 'domains' => webco_twentyi_registered_domain_names($decoded)];
}

/**
 * GET helper for domain-search only. Discovery GET still refuses this path.
 *
 * @return array{ok: bool, status: int, body: string}
 */
function webco_twentyi_http_get_domain_search(string $domainName, string $bearer): array
{
    $failed = ['ok' => false, 'status' => 0, 'body' => ''];
    $url = webco_twentyi_domain_search_url($domainName);
    if ($url === null || $bearer === '' || !function_exists('curl_init')) {
        return $failed;
    }

    $handle = curl_init($url);
    if ($handle === false) {
        return $failed;
    }

    curl_setopt_array($handle, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $bearer,
            'Expect:',
        ],
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($response) || strlen($response) > 500000) {
        return ['ok' => false, 'status' => $status, 'body' => ''];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $response,
    ];
}

/**
 * @param list<string> $argv
 * @return array{ok: bool, error: string, apply: bool, domain: ?string, type: ?string, label: ?string}
 */
function webco_twentyi_add_web_cli_options(array $argv): array
{
    $apply = false;
    $domain = null;
    $type = null;
    $label = null;
    $empty = [
        'ok' => false,
        'error' => '',
        'apply' => false,
        'domain' => null,
        'type' => null,
        'label' => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (!is_string($arg)) {
            $empty['error'] = 'preview is the default; arguments are --domain, --type, optional --label, and --apply';

            return $empty;
        }
        if ($arg === '--apply') {
            if ($apply) {
                $empty['error'] = 'preview is the default; --apply may be given once';

                return $empty;
            }
            $apply = true;
            continue;
        }
        if (str_starts_with($arg, '--domain=')) {
            if ($domain !== null) {
                $empty['error'] = '--domain may be given once';

                return $empty;
            }
            $domain = substr($arg, strlen('--domain='));
            continue;
        }
        if (str_starts_with($arg, '--type=')) {
            if ($type !== null) {
                $empty['error'] = '--type may be given once';

                return $empty;
            }
            $type = substr($arg, strlen('--type='));
            continue;
        }
        if (str_starts_with($arg, '--label=')) {
            if ($label !== null) {
                $empty['error'] = '--label may be given once';

                return $empty;
            }
            $label = substr($arg, strlen('--label='));
            if ($label === '') {
                $empty['error'] = '--label must identify the Webco order or project';

                return $empty;
            }
            continue;
        }

        $empty['error'] = 'preview is the default; arguments are --domain, --type, optional --label, and --apply';

        return $empty;
    }

    if ($domain === null || $type === null) {
        $empty['error'] = '--domain and --type are required';

        return $empty;
    }
    if (webco_twentyi_add_web_payload($domain, $type, $label) === null) {
        $empty['error'] = 'domain, package type, or label is not usable';

        return $empty;
    }

    return [
        'ok' => true,
        'error' => '',
        'apply' => $apply,
        'domain' => $domain,
        'type' => $type,
        'label' => $label,
    ];
}

/**
 * @param array{domain_name: string, type: string, label?: string} $payload
 */
function webco_twentyi_add_web_preview_text(array $payload): string
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    return implode("\n", [
        'mode: preview',
        'method: POST',
        'path: ' . WEBCO_TWENTYI_ADD_WEB,
        'body: ' . (is_string($body) ? $body : ''),
        'request: not sent',
    ]) . "\n";
}

/**
 * @param array{ok: bool, package_id: string, failure: string, status: int} $result
 */
function webco_twentyi_add_web_result_text(array $result): string
{
    if (($result['ok'] ?? false) === true) {
        $packageId = webco_twentyi_id($result['package_id'] ?? null);
        if ($packageId === null) {
            return "mode: apply\nfailure: rejected\n";
        }

        return "mode: apply\npackage_id: " . $packageId . "\n";
    }

    $failure = (string) ($result['failure'] ?? 'transport');
    if (!in_array($failure, ['invalid', 'transport', 'http', 'unreadable', 'rejected'], true)) {
        $failure = 'transport';
    }
    $lines = [
        'mode: apply',
        'failure: ' . $failure,
    ];
    $status = (int) ($result['status'] ?? 0);
    if ($failure === 'http' && $status > 0) {
        $lines[] = 'status: ' . $status;
    }

    return implode("\n", $lines) . "\n";
}
