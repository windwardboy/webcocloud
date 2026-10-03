<?php
/**
 * Saves one customer file against the brief session's project.
 */

declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/projects.php';

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'brief-upload.php') {
    webco_handle_brief_upload();
}

function webco_handle_brief_upload(): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit;
    }

    webco_start_named_session('WEBCOBRIEF');
    $json = webco_brief_upload_wants_json();
    $projectId = webco_session_project_id();
    $sent = $_POST['csrf'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    $category = $_POST['category'] ?? '';
    $requestRaw = $_POST['request_id'] ?? '';
    $requestId = null;
    if (is_string($requestRaw) && $requestRaw !== '') {
        if (!preg_match('/^\d{1,12}$/', $requestRaw)) {
            webco_brief_upload_finish('upload_failed', $json);
        }
        $requestId = (int) $requestRaw;
    }
    if ($projectId === null) {
        webco_brief_upload_denied($json);
    }
    if (!is_string($sent) || !is_string($known) || strlen($sent) !== 32 || !hash_equals($known, $sent)) {
        webco_brief_upload_finish('again', $json);
    }
    if (!is_string($category) || webco_asset_rules($category) === null) {
        webco_brief_upload_finish('upload_failed', $json);
    }

    $db = webco_db();
    if (!$db instanceof PDO || !webco_ensure_project_tables($db)) {
        webco_brief_upload_finish('upload_failed', $json);
    }

    $file = $_FILES['file'] ?? null;
    if (!is_array($file)) {
        webco_brief_upload_finish('upload_failed', $json);
    }

    $result = webco_store_customer_upload($db, $projectId, $category, $file, $requestId);
    webco_brief_upload_finish($result, $json);
}

function webco_brief_upload_wants_json(): bool
{
    return ($_POST['ajax'] ?? '') === '1';
}

function webco_brief_upload_finish(string $result, bool $json): void
{
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($result === 'uploaded' ? 200 : 422);
        echo json_encode([
            'ok' => $result === 'uploaded',
            'result' => $result,
            'message' => webco_brief_upload_message($result),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: /brief.php?notice=' . rawurlencode($result), true, 303);
    exit;
}

function webco_brief_upload_message(string $result): string
{
    return match ($result) {
        'uploaded' => 'Uploaded.',
        'upload_type' => 'That file type is not accepted. Logos and photos can be JPEG, PNG or WebP. Documents can be PDF.',
        'upload_size' => 'That file is too large. The limit is 10 MB.',
        'upload_limit' => 'This category already has 20 files.',
        'again' => 'That action was not accepted. Try again.',
        default => 'The file could not be saved. Try again.',
    };
}

function webco_brief_upload_denied(bool $json = false): void
{
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'result' => 'again',
            'message' => 'Open the secure link in your Webco email to continue this brief.',
        ]);
        exit;
    }

    webco_private_headers();
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="robots" content="noindex, nofollow"><title>Website brief</title></head>';
    echo '<body><main><h1>Website brief</h1>';
    echo '<p>Open the secure link in your Webco email to continue this brief.</p>';
    echo '</main></body></html>';
    exit;
}
