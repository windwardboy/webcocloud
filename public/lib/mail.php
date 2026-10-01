<?php
/**
 * Outgoing mail for project notifications.
 *
 * Project code calls the two functions below and does not talk to the
 * transport. Replacing mail() later means changing this file only.
 * WEBCO_MAIL_FROM and WEBCO_NOTIFY_EMAIL stay in the private secrets file.
 */

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'mail.php') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';

function webco_mail_customer_brief(
    string $to,
    string $contactName,
    string $orderPublicId,
    string $briefUrl
): bool {
    $to = webco_mail_address($to);
    if ($to === null || !webco_mail_order_id($orderPublicId) || !webco_mail_brief_url($briefUrl)) {
        return false;
    }

    $name = webco_mail_line($contactName);
    if ($name === '') {
        $name = 'there';
    }

    $message = webco_mail_customer_brief_message($name, $orderPublicId, $briefUrl);

    return webco_mail_deliver_html(
        $to,
        'Your Webco website brief',
        $message['text'],
        $message['html'],
        webco_mail_address(webco_mail_secret('WEBCO_NOTIFY_EMAIL'))
    );
}

/**
 * @return array{text: string, html: string}
 */
function webco_mail_customer_brief_message(string $name, string $orderPublicId, string $briefUrl): array
{
    $safeName = webco_mail_html($name);
    $safeOrder = webco_mail_html($orderPublicId);
    $safeUrl = webco_mail_html($briefUrl);

    $text = "Hello {$name},\r\n"
        . "\r\n"
        . "Payment for order {$orderPublicId} has been received.\r\n"
        . "\r\n"
        . "Complete your website brief when you are ready. You can save your progress and come back later. "
        . "After you submit it, Webco will review everything and contact you personally.\r\n"
        . "\r\n"
        . "Complete your website brief:\r\n"
        . $briefUrl . "\r\n"
        . "\r\n"
        . "Order reference: {$orderPublicId}\r\n"
        . "\r\n"
        . "Webco Cloud\r\n"
        . "https://webcocloud.net\r\n";

    $html = '<!DOCTYPE html><html lang="en-GB"><body style="margin:0;background:#f3f6f5;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f6f5;">'
        . '<tr><td align="center" style="padding:32px 16px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;">'
        . '<tr><td style="padding:28px 28px 8px;border-top:4px solid #0c6b62;font-family:Segoe UI,Helvetica,Arial,sans-serif;">'
        . '<p style="margin:0;color:#0c6b62;font-size:13px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;">Webco Cloud</p>'
        . '<h1 style="margin:12px 0 0;color:#122028;font-family:Georgia,Palatino,serif;font-size:28px;font-weight:600;line-height:1.2;">Payment received</h1>'
        . '<p style="margin:12px 0 0;color:#3e4e58;font-size:16px;line-height:1.5;">Hello ' . $safeName . ', thank you. Your website order is confirmed.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 28px 8px;font-family:Segoe UI,Helvetica,Arial,sans-serif;">'
        . '<a href="' . $safeUrl . '" style="display:inline-block;padding:12px 18px;background:#0c6b62;color:#f7fbfa;border-radius:999px;font-size:16px;font-weight:700;line-height:1.2;text-decoration:none;">Complete your website brief</a>'
        . '</td></tr>'
        . '<tr><td style="padding:8px 28px 0;color:#122028;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5;">'
        . '<p style="margin:12px 0 0;">You can save your progress and come back to the brief later.</p>'
        . '<p style="margin:12px 0 0;">After you submit it, Webco will review everything and contact you personally.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 28px 28px;color:#3e4e58;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.4;">'
        . 'Order reference<br><strong style="color:#122028;">' . $safeOrder . '</strong>'
        . '</td></tr></table></td></tr></table></body></html>';

    return [
        'text' => $text,
        'html' => $html,
    ];
}

function webco_mail_internal_project(
    string $orderPublicId,
    string $businessName,
    string $contactName,
    string $customerEmail,
    string $domainName,
    string $packageName,
    string $careChoice,
    string $verticalCode,
    string $status
): bool {
    $to = webco_mail_address(webco_mail_secret('WEBCO_NOTIFY_EMAIL'));
    $customerEmail = webco_mail_address($customerEmail);
    if ($to === null || $customerEmail === null || !webco_mail_order_id($orderPublicId)) {
        return false;
    }

    $care = $careChoice;
    if ($careChoice === 'managed') {
        $care = 'Managed Care';
    } elseif ($careChoice === 'standard') {
        $care = 'Annual hosting';
    }

    $body = "A paid website order is ready.\r\n"
        . "\r\n"
        . 'Order: ' . $orderPublicId . "\r\n"
        . 'Business: ' . webco_mail_line($businessName) . "\r\n"
        . 'Contact: ' . webco_mail_line($contactName) . "\r\n"
        . 'Email: ' . $customerEmail . "\r\n"
        . 'Domain: ' . webco_mail_line($domainName) . "\r\n"
        . 'Package: ' . webco_mail_line($packageName) . "\r\n"
        . 'Care: ' . webco_mail_line($care) . "\r\n"
        . 'Vertical: ' . webco_mail_line($verticalCode) . "\r\n"
        . 'Status: ' . webco_mail_line($status) . "\r\n"
        . "\r\n"
        . "Dashboard: https://webcocloud.net/admin.php\r\n";

    return webco_mail_deliver($to, 'New paid website project ' . $orderPublicId, $body, $customerEmail);
}

function webco_mail_deliver(string $to, string $subject, string $body, ?string $replyTo): bool
{
    $from = webco_mail_address(webco_mail_secret('WEBCO_MAIL_FROM'));
    if ($from === null || $body === '' || preg_match('/[\r\n]/', $subject) === 1) {
        return false;
    }
    if ($replyTo !== null && webco_mail_address($replyTo) !== $replyTo) {
        return false;
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: Webco Cloud <' . $from . '>',
    ];
    if ($replyTo !== null) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    return mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);
}

function webco_mail_deliver_html(string $to, string $subject, string $text, string $html, ?string $replyTo): bool
{
    $from = webco_mail_address(webco_mail_secret('WEBCO_MAIL_FROM'));
    if ($from === null || $text === '' || $html === '' || preg_match('/[\r\n]/', $subject) === 1) {
        return false;
    }
    if ($replyTo !== null && webco_mail_address($replyTo) !== $replyTo) {
        return false;
    }

    $boundary = 'webco_' . bin2hex(random_bytes(16));
    if (str_contains($text, $boundary) || str_contains($html, $boundary)) {
        return false;
    }

    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n"
        . "\r\n"
        . quoted_printable_encode($text) . "\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n"
        . "\r\n"
        . quoted_printable_encode($html) . "\r\n"
        . '--' . $boundary . "--\r\n";

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'From: Webco Cloud <' . $from . '>',
    ];
    if ($replyTo !== null) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    return mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);
}

function webco_mail_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function webco_mail_secret(string $name): ?string
{
    if (!defined('WEBCO_SECRETS_FILE') || !is_file(WEBCO_SECRETS_FILE)) {
        return null;
    }

    $variable = null;
    if (!defined($name)) {
        ob_start();
        require_once WEBCO_SECRETS_FILE;
        ob_end_clean();
        if (isset($$name) && is_string($$name)) {
            $variable = $$name;
        }
    }

    return webco_loaded_secret($name, $variable);
}

function webco_mail_address(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '' || strlen($value) > 160 || preg_match('/[\r\n\0]/', $value) === 1) {
        return null;
    }
    if (preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $value) !== 1) {
        return null;
    }
    if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }

    return $value;
}

function webco_mail_order_id(string $value): bool
{
    return preg_match('/^wc_[a-f0-9]{20}$/', $value) === 1;
}

function webco_mail_brief_url(string $value): bool
{
    return preg_match('#^https://webcocloud\.net/brief\.php\?access=[a-f0-9]{64}$#', $value) === 1;
}

function webco_mail_line(string $value): string
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
}
