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
    $projectId = webco_session_project_id();
    $sent = $_POST['csrf'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    $category = $_POST['category'] ?? '';
    if ($projectId === null) {
        webco_brief_upload_denied();
    }
    if (!is_string($sent) || !is_string($known) || strlen($sent) !== 32 || !hash_equals($known, $sent)) {
        header('Location: /brief.php?notice=again', true, 303);
        exit;
    }
    if (!is_string($category) || webco_asset_rules($category) === null) {
        header('Location: /brief.php?notice=upload_failed', true, 303);
        exit;
    }

    $db = webco_db();
    if (!$db instanceof PDO || !webco_ensure_project_tables($db)) {
        header('Location: /brief.php?notice=upload_failed', true, 303);
        exit;
    }

    $file = $_FILES['file'] ?? null;
    if (!is_array($file)) {
        header('Location: /brief.php?notice=upload_failed', true, 303);
        exit;
    }

    $result = webco_store_customer_upload($db, $projectId, $category, $file);
    header('Location: /brief.php?notice=' . rawurlencode($result), true, 303);
    exit;
}

function webco_brief_upload_denied(): void
{
    webco_private_headers();
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="robots" content="noindex, nofollow"><title>Website brief</title></head>';
    echo '<body><main><h1>Website brief</h1>';
    echo '<p>Open the secure link in your Webco email to continue this brief.</p>';
    echo '</main></body></html>';
    exit;
}
