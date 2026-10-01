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

    $body = "Hello {$name},\r\n"
        . "\r\n"
        . "Payment for order {$orderPublicId} has been received.\r\n"
        . "\r\n"
        . "Use this secure link to complete your website brief and upload logos, photos or documents. "
        . "You can save your progress and come back to it later:\r\n"
        . "\r\n"
        . $briefUrl . "\r\n"
        . "\r\n"
        . "After you submit the brief, Webco will contact you personally.\r\n"
        . "\r\n"
        . "Webco Cloud\r\n"
        . "https://webcocloud.net\r\n";

    return webco_mail_deliver(
        $to,
        'Your Webco website brief',
        $body,
        webco_mail_address(webco_mail_secret('WEBCO_NOTIFY_EMAIL'))
    );
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
