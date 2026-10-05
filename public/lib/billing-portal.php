<?php
/**
 * Stripe Customer Portal for the website in the current brief session.
 * The customer id comes from that project's paid order. Checkout and webhooks
 * are not involved.
 *
 * Temporary diagnostics use error_log() and a private log beside the secrets
 * file. They never include secrets, customer ids, portal URLs, tokens, or
 * payment details.
 */

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/projects.php';

function webco_billing_livemode(mixed $value): ?int
{
    if ($value === 0 || $value === '0') {
        return 0;
    }
    if ($value === 1 || $value === '1') {
        return 1;
    }

    return null;
}

function webco_billing_portal_customer_id(string $customerId): bool
{
    return $customerId !== '' && webco_stripe_customer_id_valid($customerId);
}

function webco_billing_portal_allowed(?string $customerId, mixed $livemode, string $orderStatus): bool
{
    return $orderStatus === 'paid'
        && webco_billing_livemode($livemode) === 0
        && is_string($customerId)
        && webco_billing_portal_customer_id($customerId);
}

function webco_billing_portal_return_url(): string
{
    return WEBCO_PUBLIC_ORIGIN . '/brief.php';
}

function webco_billing_portal_fields(string $customerId): ?string
{
    if (!webco_billing_portal_customer_id($customerId)) {
        return null;
    }

    return webco_stripe_form([
        'customer' => $customerId,
        'return_url' => webco_billing_portal_return_url(),
    ]);
}

function webco_billing_portal_log(string $marker): void
{
    $marker = str_replace(["\r", "\n"], '', $marker);
    if ($marker === '' || strlen($marker) > 300) {
        $marker = 'diagnostic omitted';
    }

    error_log('webco billing portal: ' . $marker);
    webco_billing_debug_append($marker);
}

function webco_billing_debug_log_path(): ?string
{
    if (!defined('WEBCO_SECRETS_FILE') || !is_string(WEBCO_SECRETS_FILE) || WEBCO_SECRETS_FILE === '') {
        return null;
    }

    $directory = dirname(WEBCO_SECRETS_FILE);
    $directory = rtrim(str_replace('\\', '/', $directory), '/');
    if ($directory === '' || $directory === '.' || $directory === '/') {
        return null;
    }

    $path = $directory . '/webco-billing-debug.log';
    if (webco_billing_debug_is_public_path($path)) {
        return null;
    }

    return $path;
}

function webco_billing_debug_append(string $marker, ?string $path = null): bool
{
    $path ??= webco_billing_debug_log_path();
    if (!is_string($path) || basename(str_replace('\\', '/', $path)) !== 'webco-billing-debug.log') {
        return false;
    }
    if (webco_billing_debug_is_public_path($path)) {
        return false;
    }

    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        return false;
    }

    $marker = str_replace(["\r", "\n"], '', $marker);
    if ($marker === '' || strlen($marker) > 300) {
        $marker = 'diagnostic omitted';
    }
    $line = gmdate('Y-m-d\TH:i:s\Z') . ' ' . $marker . "\n";
    $handle = fopen($path, 'ab');
    if ($handle === false) {
        return false;
    }
    $locked = flock($handle, LOCK_EX);
    $written = $locked && fwrite($handle, $line) === strlen($line);
    if ($locked) {
        flock($handle, LOCK_UN);
    }
    fclose($handle);
    if ($written) {
        chmod($path, 0600);
    }

    return $written;
}

/**
 * @return array{at: string, marker: string}|null
 */
function webco_billing_debug_latest(?string $path = null): ?array
{
    $path ??= webco_billing_debug_log_path();
    if (!is_string($path) || basename(str_replace('\\', '/', $path)) !== 'webco-billing-debug.log') {
        return null;
    }
    if (webco_billing_debug_is_public_path($path) || !is_file($path) || !is_readable($path)) {
        return null;
    }

    $size = filesize($path);
    if (!is_int($size) || $size < 1) {
        return null;
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }
    $read = min($size, 8192);
    if ($size > $read && fseek($handle, -$read, SEEK_END) !== 0) {
        fclose($handle);

        return null;
    }
    if (!flock($handle, LOCK_SH)) {
        fclose($handle);

        return null;
    }
    $chunk = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    if (!is_string($chunk) || trim($chunk) === '') {
        return null;
    }

    $lines = preg_split("/\r\n|\n|\r/", trim($chunk));
    if (!is_array($lines)) {
        return null;
    }
    for ($index = count($lines) - 1; $index >= 0; $index--) {
        $parsed = webco_billing_debug_parse_line((string) $lines[$index]);
        if ($parsed !== null) {
            return $parsed;
        }
    }

    return null;
}

/**
 * @return array{at: string, marker: string}|null
 */
function webco_billing_debug_parse_line(string $line): ?array
{
    if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z) (.{1,300})$/', $line, $matches) !== 1) {
        return null;
    }
    $marker = webco_billing_portal_safe_message($matches[2], 300);
    if ($marker === 'none' || preg_match('/sk_|cus_|https?:|@/i', $marker) === 1) {
        $marker = 'diagnostic omitted';
    }

    return [
        'at' => $matches[1],
        'marker' => $marker,
    ];
}

function webco_billing_debug_is_public_path(string $path): bool
{
    $candidate = $path;
    $real = realpath($path);
    if (is_string($real) && $real !== '') {
        $candidate = $real;
    } else {
        $parent = dirname($path);
        $realParent = realpath($parent);
        if (is_string($realParent) && $realParent !== '') {
            $candidate = $realParent . DIRECTORY_SEPARATOR . basename($path);
        }
    }
    $candidate = webco_billing_debug_normalize($candidate);
    foreach (webco_billing_debug_public_roots() as $root) {
        $root = webco_billing_debug_normalize($root);
        if ($root === '') {
            continue;
        }
        if ($candidate === $root || str_starts_with($candidate, $root . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function webco_billing_debug_public_roots(): array
{
    $roots = [
        dirname(__DIR__),
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'dist',
    ];
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($documentRoot) && $documentRoot !== '') {
        $roots[] = $documentRoot;
    }

    return $roots;
}

function webco_billing_debug_normalize(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = rtrim($path, '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
    }

    return $path;
}

function webco_billing_portal_safe_token(mixed $value): string
{
    if (!is_string($value) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $value) !== 1) {
        return 'none';
    }
    if (preg_match('/^(?:sk|rk|pk|cus|whsec|bps|cs|seti|pi|pm)_/i', $value) === 1) {
        return 'none';
    }

    return $value;
}

function webco_billing_portal_safe_message(mixed $value, int $limit = 160): string
{
    if (!is_string($value) || $value === '') {
        return 'none';
    }

    $message = str_replace(["\r", "\n", "\t"], ' ', $value);
    $patterns = [
        '/sk_(?:test|live)_[A-Za-z0-9]+/',
        '/rk_(?:test|live)_[A-Za-z0-9]+/',
        '/whsec_[A-Za-z0-9+\/=_-]+/',
        '/cus_[A-Za-z0-9]+/',
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/',
        '#https?://\S+#',
        '/\b[a-f0-9]{32,}\b/i',
    ];
    foreach ($patterns as $pattern) {
        $replaced = preg_replace($pattern, '[redacted]', $message);
        $message = is_string($replaced) ? $replaced : '';
    }
    $collapsed = preg_replace('/\s+/', ' ', trim($message));
    $message = is_string($collapsed) ? $collapsed : '';
    if ($message === '') {
        return 'none';
    }
    if ($limit < 1 || $limit > 300) {
        $limit = 160;
    }
    if (strlen($message) > $limit) {
        $message = substr($message, 0, $limit);
    }

    return $message;
}

function webco_billing_portal_livemode_kind(mixed $value): string
{
    if ($value === false) {
        return 'false';
    }
    if ($value === true) {
        return 'true';
    }
    if ($value === 0 || $value === '0') {
        return '0';
    }
    if ($value === 1 || $value === '1') {
        return '1';
    }
    if ($value === null) {
        return 'null';
    }

    return 'other';
}

function webco_billing_portal_url_problem(string $url): string
{
    if (strlen($url) > 2048) {
        return 'length';
    }
    if (preg_match('/[\s\r\n\\\\]/', $url) === 1) {
        return 'whitespace';
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return 'parse';
    }
    if (($parts['scheme'] ?? '') !== 'https') {
        return 'scheme';
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return 'userinfo';
    }
    $host = $parts['host'] ?? '';
    if (!is_string($host) || strcasecmp($host, 'billing.stripe.com') !== 0) {
        return 'host';
    }
    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        return 'port';
    }
    if (isset($parts['fragment']) && $parts['fragment'] !== '') {
        return 'path';
    }
    $path = $parts['path'] ?? '';
    if (!is_string($path)) {
        return 'path';
    }
    if (preg_match('#^/(?:p/)?session/test_[A-Za-z0-9_-]{8,}$#', $path) === 1) {
        $query = $parts['query'] ?? null;
        if ($query === null || $query === '') {
            return 'none';
        }

        return 'query';
    }
    if (preg_match('#^/p/session/?$#', $path) === 1) {
        return webco_billing_portal_query_problem($parts['query'] ?? null);
    }

    return 'path';
}

function webco_billing_portal_query_problem(mixed $query): string
{
    if (!is_string($query) || $query === '' || preg_match('/[\s\r\n\\\\]/', $query) === 1) {
        return 'query';
    }
    if (str_contains($query, '://') || str_contains($query, '@')) {
        return 'query';
    }

    $params = [];
    parse_str($query, $params);
    $secret = $params['secret'] ?? null;
    if (!is_string($secret) || preg_match('/^test_[A-Za-z0-9_-]{8,}$/', $secret) !== 1) {
        return 'query';
    }

    return 'none';
}

function webco_is_test_billing_portal_url(string $url): bool
{
    return webco_billing_portal_url_problem($url) === 'none';
}

/**
 * @param array<mixed> $body
 */
function webco_billing_portal_log_api_error(int $status, array $body): void
{
    if ($status < 100 || $status > 599) {
        $status = 0;
    }
    $error = $body['error'] ?? null;
    $type = 'none';
    $code = 'none';
    $message = 'none';
    if (is_array($error)) {
        $type = webco_billing_portal_safe_token($error['type'] ?? null);
        $code = webco_billing_portal_safe_token($error['code'] ?? null);
        $message = webco_billing_portal_safe_message($error['message'] ?? null);
    }

    webco_billing_portal_log(
        'Stripe HTTP/API error status=' . $status
        . ' type=' . $type
        . ' code=' . $code
        . ' message=' . $message
    );
}

/**
 * @param array{status: string, stripe_customer_id: ?string, stripe_livemode: ?int, livemode_kind?: string}|null $row
 */
function webco_billing_portal_eligibility_failure(?array $row): ?string
{
    if ($row === null) {
        return 'billing order not eligible';
    }
    $customerId = $row['stripe_customer_id'] ?? null;
    if (!is_string($customerId) || !webco_billing_portal_customer_id($customerId)) {
        return 'missing/invalid customer id';
    }
    if (webco_billing_livemode($row['stripe_livemode'] ?? null) !== 0) {
        $kind = $row['livemode_kind'] ?? 'other';
        if (!in_array($kind, ['false', 'true', '0', '1', 'null', 'other'], true)) {
            $kind = 'other';
        }

        return 'livemode mismatch value=' . $kind;
    }
    if (($row['status'] ?? '') !== 'paid') {
        $status = $row['status'] ?? '';
        $label = is_string($status) && preg_match('/^[a-z_]{1,40}$/', $status) === 1 ? $status : 'other';

        return 'billing order not eligible status=' . $label;
    }

    return null;
}

/**
 * @param array<mixed> $body
 */
function webco_billing_portal_response_failure(array $body, string $customerId): ?string
{
    if (($body['object'] ?? '') !== 'billing_portal.session') {
        return 'unexpected portal response';
    }
    $returned = $body['customer'] ?? null;
    if (!is_string($returned) || !hash_equals($customerId, $returned)) {
        return 'returned customer mismatch';
    }
    if (webco_stripe_livemode_column($body['livemode'] ?? null) !== 0) {
        return 'returned livemode mismatch value=' . webco_billing_portal_livemode_kind($body['livemode'] ?? null);
    }
    $url = $body['url'] ?? null;
    if (!is_string($url)) {
        return 'invalid Billing Portal URL reason=type';
    }
    $problem = webco_billing_portal_url_problem($url);
    if ($problem !== 'none') {
        return 'invalid Billing Portal URL reason=' . $problem;
    }

    return null;
}

/**
 * @param array<mixed> $body
 */
function webco_billing_portal_url(array $body, string $customerId): ?string
{
    if (webco_billing_portal_response_failure($body, $customerId) !== null) {
        return null;
    }
    $url = $body['url'] ?? null;

    return is_string($url) ? $url : null;
}

function webco_open_billing_portal(string $customerId): ?string
{
    $body = webco_billing_portal_fields($customerId);
    if ($body === null) {
        webco_billing_portal_log('missing/invalid customer id');

        return null;
    }

    $secret = webco_stripe_secret();
    if ($secret === null) {
        webco_billing_portal_log('Stripe test secret unavailable');

        return null;
    }

    $response = webco_stripe_api($secret, 'POST', '/v1/billing_portal/sessions', $body, null);
    $secret = '';
    if ($response === null) {
        webco_billing_portal_log('Stripe API transport failure');

        return null;
    }
    if (!is_array($response['body'])) {
        $status = (int) ($response['status'] ?? 0);
        webco_billing_portal_log('malformed JSON response status=' . $status);

        return null;
    }
    if ($response['status'] !== 200) {
        webco_billing_portal_log_api_error((int) $response['status'], $response['body']);

        return null;
    }

    $reason = webco_billing_portal_response_failure($response['body'], $customerId);
    if ($reason !== null) {
        webco_billing_portal_log($reason);

        return null;
    }

    return webco_billing_portal_url($response['body'], $customerId);
}

/**
 * @return array{status: string, stripe_customer_id: ?string, stripe_livemode: ?int, livemode_kind: string}|null
 */
function webco_project_order_billing(PDO $db, int $projectId): ?array
{
    if ($projectId < 1) {
        return null;
    }

    try {
        $statement = $db->prepare(
            'SELECT o.status, o.stripe_customer_id, o.stripe_livemode
             FROM projects p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.id = :id'
        );
        $statement->execute(['id' => $projectId]);
        $row = $statement->fetch();
    } catch (PDOException) {
        return null;
    }
    if ($row === false) {
        return null;
    }

    $customer = $row['stripe_customer_id'] ?? null;
    if (!is_string($customer) || $customer === '') {
        $customer = null;
    }
    $rawMode = $row['stripe_livemode'] ?? null;

    return [
        'status' => (string) ($row['status'] ?? ''),
        'stripe_customer_id' => $customer,
        'stripe_livemode' => webco_billing_livemode($rawMode),
        'livemode_kind' => webco_billing_portal_livemode_kind($rawMode),
    ];
}

function webco_billing_portal_customer_for_project(PDO $db, int $projectId): ?string
{
    $row = webco_project_order_billing($db, $projectId);
    if ($row === null || !webco_billing_portal_allowed(
        $row['stripe_customer_id'],
        $row['stripe_livemode'],
        $row['status']
    )) {
        return null;
    }

    return $row['stripe_customer_id'];
}

function webco_project_billing_portal_open(PDO $db, int $projectId): bool
{
    return webco_billing_portal_customer_for_project($db, $projectId) !== null;
}
