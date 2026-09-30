<?php
/**
 * Temporary draft-order endpoint.
 * Accepts the browser onboarding record and stores one draft row.
 * Does not take payment.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require __DIR__ . '/lib/db.php';

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'draft-order.php') {
    handle_draft_order_request();
}

function handle_draft_order_request(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['status' => 'error']);
    }

    $contentType = strtolower(trim(strtok((string) ($_SERVER['CONTENT_TYPE'] ?? ''), ';')));
    if ($contentType !== 'application/json') {
        respond(400, ['status' => 'error']);
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 20000) {
        respond(400, ['status' => 'error']);
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        respond(400, ['status' => 'error']);
    }

    $order = order_from_state($payload);
    if ($order === null) {
        respond(400, ['status' => 'error']);
    }

    $db = webco_db();
    if (!$db instanceof PDO || !webco_ensure_orders_table($db)) {
        respond(500, ['status' => 'error']);
    }

    $orderId = webco_insert_draft_order($db, $order);
    if ($orderId === null) {
        respond(500, ['status' => 'error']);
    }

    respond(201, ['orderId' => $orderId]);
}

/**
 * Prices are taken from the package, not from the submitted strings.
 *
 * @param array<mixed> $state
 * @return array<string, mixed>|null
 */
function order_from_state(array $state): ?array
{
    $packages = [
        'essential' => ['name' => 'Webco Essential', 'price' => 59500, 'care' => 3900],
        'professional' => ['name' => 'Webco Professional', 'price' => 99500, 'care' => 5900],
    ];

    $package = $state['package'] ?? '';
    if (!is_string($package) || !isset($packages[$package])) {
        return null;
    }

    $domainPath = $state['domainPath'] ?? '';
    if ($domainPath !== 'new' && $domainPath !== 'existing') {
        return null;
    }

    $domain = is_string($state['domain'] ?? null) ? normalise_domain($state['domain']) : null;
    if ($domain === null) {
        return null;
    }

    $details = $state['details'] ?? null;
    if (!is_array($details)) {
        return null;
    }

    $businessName = text_field($details['businessName'] ?? null, 2, 120);
    $contactName = text_field($details['contactName'] ?? null, 2, 120);
    $address1 = text_field($details['address1'] ?? null, 2, 120);
    $town = text_field($details['town'] ?? null, 2, 80);
    $address2 = optional_text($details['address2'] ?? null, 120);
    $county = optional_text($details['county'] ?? null, 80);
    if ($businessName === null || $contactName === null || $address1 === null || $town === null) {
        return null;
    }
    if ($address2 === false || $county === false) {
        return null;
    }

    $email = is_string($details['email'] ?? null) ? strtolower(trim($details['email'])) : '';
    if (strlen($email) > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }

    $phone = is_string($details['phone'] ?? null) ? preg_replace('/[\s()-]/', '', $details['phone']) ?? '' : '';
    if (!preg_match('/^(?:0\d{10}|\+44\d{10}|0044\d{10})$/', $phone)) {
        return null;
    }

    $contactMethod = $details['contactMethod'] ?? '';
    if (!in_array($contactMethod, ['email', 'phone', 'whatsapp'], true)) {
        return null;
    }

    $postcode = is_string($details['postcode'] ?? null) ? normalise_postcode($details['postcode']) : null;
    if ($postcode === null) {
        return null;
    }

    $companyNumber = optional_text($details['companyNumber'] ?? null, 8);
    if ($companyNumber === false) {
        return null;
    }
    if ($companyNumber !== null) {
        $companyNumber = strtoupper($companyNumber);
        if (!preg_match('/^(?:\d{8}|[A-Z]{2}\d{6})$/', $companyNumber)) {
            return null;
        }
    }

    $care = $state['care'] ?? null;
    if (!is_array($care)) {
        return null;
    }
    $careChoice = $care['choice'] ?? '';
    if ($careChoice !== 'managed' && $careChoice !== 'standard') {
        return null;
    }

    return [
        'package_code' => $package,
        'package_name' => $packages[$package]['name'],
        'package_price_pence' => $packages[$package]['price'],
        'domain_path' => $domainPath,
        'domain_name' => $domain,
        'business_name' => $businessName,
        'contact_name' => $contactName,
        'email' => $email,
        'phone' => $phone,
        'contact_method' => $contactMethod,
        'address_line_1' => $address1,
        'address_line_2' => $address2,
        'town' => $town,
        'county' => $county,
        'postcode' => $postcode,
        'company_number' => $companyNumber,
        'care_choice' => $careChoice,
        'care_price_pence' => $careChoice === 'managed' ? $packages[$package]['care'] : null,
    ];
}

function text_field(mixed $value, int $min, int $max): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    $length = strlen($value);
    if ($length < $min || $length > $max) {
        return null;
    }

    return $value;
}

function optional_text(mixed $value, int $max): string|false|null
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        return false;
    }
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') {
        return null;
    }
    if (strlen($value) > $max) {
        return false;
    }

    return $value;
}

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

function normalise_postcode(string $value): ?string
{
    $compact = strtoupper((string) preg_replace('/\s+/', '', trim($value)));
    if (!preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $compact)) {
        return null;
    }

    return substr($compact, 0, -3) . ' ' . substr($compact, -3);
}

/**
 * @param array<string, mixed> $payload
 */
function respond(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
