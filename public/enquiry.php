<?php
/**
 * Receives the short enquiry form on the HGV landing page.
 *
 * It validates on the server, stores the enquiry for the admin page and tells the business by email.
 * It never creates an order, a customer account or a checkout, and it never emails the visitor
 * or anyone else they name, so it cannot be used to send mail to third parties.
 *
 * With JavaScript the page posts here and reads a JSON answer. Without it the same form posts normally
 * and gets a small standalone page back.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/enquiries.php';

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'enquiry.php') {
    webco_handle_enquiry_request();
}

function webco_handle_enquiry_request(): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: same-origin');

    $wantsJson = webco_enquiry_wants_json();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        webco_enquiry_respond($wantsJson, 405, 'error', 'This page only accepts the enquiry form.');
    }

    if (!webco_enquiry_same_site()) {
        webco_enquiry_respond($wantsJson, 403, 'error', 'Your message could not be accepted from here.');
    }

    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 20000) {
        webco_enquiry_respond($wantsJson, 413, 'error', 'That message is too large to send.');
    }

    $contentType = strtolower(trim(strtok((string) ($_SERVER['CONTENT_TYPE'] ?? ''), ';')));
    if ($contentType !== 'application/x-www-form-urlencoded' && $contentType !== 'multipart/form-data') {
        webco_enquiry_respond($wantsJson, 415, 'error', 'Your message could not be read.');
    }

    $result = webco_process_enquiry($_POST, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), time(), null);
    webco_enquiry_respond(
        $wantsJson,
        $result['http'],
        $result['status'],
        $result['message'],
        $result['errors'] ?? []
    );
}

/**
 * The whole submission, separated from HTTP so it can be tested.
 *
 * @param array<mixed> $input
 * @return array{http: int, status: string, message: string, errors?: array<string, string>}
 */
function webco_process_enquiry(array $input, string $ip, int $now, ?PDO $db): array
{
    $thanks = 'Thank you. Your message has been sent and Webco Media will reply to the email address you gave.';

    // A hidden field that people never see. A bot that fills it gets a normal-looking answer and nothing is stored.
    $trap = $input['homepage_url'] ?? '';
    if (!is_string($trap) || $trap !== '') {
        return ['http' => 200, 'status' => 'ok', 'message' => $thanks];
    }

    // Forms completed faster than a person could type are asked to try again rather than silently dropped.
    $elapsed = $input['elapsed'] ?? '';
    if (is_string($elapsed) && preg_match('/^\d{1,9}$/', $elapsed) === 1 && (int) $elapsed < WEBCO_ENQUIRY_MIN_ELAPSED_MS) {
        return [
            'http' => 429,
            'status' => 'error',
            'message' => 'That was a little too quick. Please check your details and press send again.',
        ];
    }

    $checked = webco_enquiry_validate($input);
    if ($checked['ok'] !== true) {
        return [
            'http' => 422,
            'status' => 'error',
            'message' => 'Please check the highlighted details and try again.',
            'errors' => $checked['errors'],
        ];
    }
    $data = $checked['data'];

    // A connection passed in by the caller (tests) is used as it is. Otherwise open one and make sure the table exists.
    if ($db === null) {
        $db = webco_db();
        if ($db instanceof PDO && !webco_ensure_enquiries_table($db)) {
            $db = null;
        }
    }
    if (!$db instanceof PDO) {
        return [
            'http' => 503,
            'status' => 'error',
            'message' => 'Sorry, your message could not be saved just now.',
        ];
    }

    $ipHash = webco_enquiry_ip_hash($ip);
    $rate = webco_enquiry_rate_state($db, $ipHash, $now);
    if ($rate === 'ip_limit') {
        return [
            'http' => 429,
            'status' => 'error',
            'message' => 'You have sent several messages already. Please wait a little before sending another, or call us.',
        ];
    }
    if ($rate === 'global_limit') {
        return [
            'http' => 429,
            'status' => 'error',
            'message' => 'The form is very busy at the moment. Please try again shortly, or call us.',
        ];
    }
    if ($rate === 'error') {
        return ['http' => 503, 'status' => 'error', 'message' => 'Sorry, your message could not be saved just now.'];
    }

    // A double tap should not create two enquiries or two emails. Report it as sent.
    if (webco_enquiry_is_duplicate($db, $data['email'], $data['message'], $now)) {
        return ['http' => 200, 'status' => 'ok', 'message' => $thanks];
    }

    $stored = webco_enquiry_insert($db, $data, $ipHash, $now);
    if ($stored === null) {
        return ['http' => 503, 'status' => 'error', 'message' => 'Sorry, your message could not be saved just now.'];
    }

    webco_enquiry_notify($db, $stored['id'], $stored['public_id'], $data);

    return ['http' => 201, 'status' => 'ok', 'message' => $thanks];
}

function webco_enquiry_wants_json(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');

    return str_contains($accept, 'application/json');
}

/**
 * Accepts a request that comes from this site, or from a client that sends no origin at all.
 * It is not a security boundary on its own. The limits in lib/enquiries.php are.
 */
function webco_enquiry_same_site(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
        $value = $_SERVER[$header] ?? '';
        if (!is_string($value) || $value === '') {
            continue;
        }
        $sentHost = parse_url($value, PHP_URL_HOST);
        if (!is_string($sentHost)) {
            return false;
        }
        $sentHost = strtolower($sentHost);
        $port = parse_url($value, PHP_URL_PORT);
        if (is_int($port)) {
            $sentHost .= ':' . $port;
        }
        if ($sentHost !== $host) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, string> $errors
 */
function webco_enquiry_respond(bool $json, int $code, string $status, string $message, array $errors = []): never
{
    http_response_code($code);

    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        $body = ['status' => $status, 'message' => $message];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    $ok = $status === 'ok';
    $title = $ok ? 'Message sent' : 'Message not sent';
    $back = '/web-design/hgv-driver-training/' . ($ok ? '' : '#enquiry');

    echo '<!doctype html><html lang="en-GB"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' — Webco Cloud</title>'
        . '<style>body{margin:0;background:#f6f5f1;color:#0e1b20;font:1.0625rem/1.6 system-ui,-apple-system,"Segoe UI",Arial,sans-serif}'
        . 'main{max-width:34rem;margin:0 auto;padding:3rem 1.25rem}h1{font:500 2rem/1.2 Georgia,serif;margin:0 0 1rem}'
        . 'a{color:#0c6b62}ul{padding-left:1.2rem}</style></head><body><main>'
        . '<h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
        . '<p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    if ($errors !== []) {
        echo '<ul>';
        foreach ($errors as $error) {
            echo '<li>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }
    echo '<p><a href="' . htmlspecialchars($back, ENT_QUOTES, 'UTF-8') . '">'
        . ($ok ? 'Back to the Webco Cloud website page' : 'Go back to the form') . '</a></p>'
        . '<p>You can also call <a href="tel:+441934228015">01934 228 015</a> or email '
        . '<a href="mailto:hello@webcomedia.net">hello@webcomedia.net</a>.</p>'
        . '</main></body></html>';
    exit;
}
