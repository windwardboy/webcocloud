<?php
/**
 * Landing-page enquiries: validation, spam controls, storage and the admin card.
 * Run with: php tests/enquiries.php
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/enquiry.php';
require dirname(__DIR__) . '/public/admin.php';

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "ok  {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL {$message}\n";
}

/**
 * @return array<string, string>
 */
function good_input(array $override = []): array
{
    return array_merge([
        'name' => 'Sam Taylor',
        'business' => 'Taylor HGV Training',
        'email' => 'Sam@Taylor-Training.example',
        'phone' => '01234 567 890',
        'package' => 'professional',
        'message' => 'Hello, could you tell me how the Professional website handles three locations?',
        'homepage_url' => '',
        'elapsed' => '14000',
    ], $override);
}

// ---------- Validation ----------

$ok = webco_enquiry_validate(good_input());
check($ok['ok'] === true, 'a complete enquiry is valid');
check(($ok['data']['email'] ?? '') === 'sam@taylor-training.example', 'the email is lower-cased');
check(($ok['data']['phone'] ?? '') === '01234 567 890', 'a normal telephone number is kept');

$minimal = webco_enquiry_validate(good_input(['phone' => '', 'package' => 'something-else']));
check($minimal['ok'] === true && $minimal['data']['phone'] === null, 'telephone is optional');
check($minimal['ok'] === true && $minimal['data']['package'] === 'not_sure', 'an unknown package falls back to not sure');

$empty = webco_enquiry_validate([]);
check($empty['ok'] === false && isset($empty['errors']['name'], $empty['errors']['business'], $empty['errors']['email'], $empty['errors']['message']), 'an empty form lists every required field');

$badEmail = webco_enquiry_validate(good_input(['email' => 'not-an-email']));
check($badEmail['ok'] === false && isset($badEmail['errors']['email']), 'a bad email is refused');

$injected = webco_enquiry_validate(good_input(['email' => "a@b.example\r\nBcc: victim@example.com"]));
check($injected['ok'] === false && isset($injected['errors']['email']), 'a header-injection attempt in the email is refused');

$badPhone = webco_enquiry_validate(good_input(['phone' => 'call me maybe']));
check($badPhone['ok'] === false && isset($badPhone['errors']['phone']), 'letters in the telephone field are refused');

$short = webco_enquiry_validate(good_input(['message' => 'Hi']));
check($short['ok'] === false && isset($short['errors']['message']), 'a one-word message is refused');

$long = webco_enquiry_validate(good_input(['message' => str_repeat('a', 2001)]));
check($long['ok'] === false && isset($long['errors']['message']), 'an over-long message is refused');

$links = webco_enquiry_validate(good_input(['message' => 'Look at http://a.example http://b.example and https://c.example please']));
check($links['ok'] === false && isset($links['errors']['message']), 'a message stuffed with links is refused');

$oneLink = webco_enquiry_validate(good_input(['message' => 'My current site is https://old-site.example and I would like a new one.']));
check($oneLink['ok'] === true, 'a message with one link is accepted');

$arrays = webco_enquiry_validate(good_input(['name' => ['x'], 'message' => ['y']]));
check($arrays['ok'] === false, 'array values in place of text are refused');

$controls = webco_enquiry_validate(good_input(['name' => "Sam\x00\x07 Taylor", 'message' => "Line one is here.\r\n\r\n\r\n\r\nLine two is here."]));
check($controls['ok'] === true && $controls['data']['name'] === 'Sam Taylor', 'control characters are stripped from names');
check($controls['ok'] === true && $controls['data']['message'] === "Line one is here.\n\nLine two is here.", 'line breaks in messages are tidied');

$accents = webco_enquiry_validate(good_input(['name' => 'Zoë', 'business' => 'Ø']));
check($accents['ok'] === false && isset($accents['errors']['business']) && !isset($accents['errors']['name']), 'length is counted in characters, not bytes');

// ---------- Storage, spam controls and limits ----------

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-enquiries-' . getmypid() . '.sqlite';
if (is_file($path)) {
    unlink($path);
}
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec(
    'CREATE TABLE enquiries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT \'new\',
        source TEXT NOT NULL,
        contact_name TEXT NOT NULL,
        business_name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT NULL,
        package_interest TEXT NOT NULL,
        message TEXT NOT NULL,
        ip_hash TEXT NOT NULL,
        notified_at TEXT NULL,
        handled_at TEXT NULL,
        created_at TEXT NOT NULL
    )'
);
$db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY)');

function enquiry_count(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM enquiries')->fetchColumn();
}

$now = 1_800_000_000;

$trap = webco_process_enquiry(good_input(['homepage_url' => 'https://spam.example']), '203.0.113.9', $now, $db);
check($trap['http'] === 200 && $trap['status'] === 'ok' && enquiry_count($db) === 0, 'the hidden field looks like success but stores nothing');

$fast = webco_process_enquiry(good_input(['elapsed' => '300']), '203.0.113.9', $now, $db);
check($fast['http'] === 429 && enquiry_count($db) === 0, 'a form sent in under a second and a half is asked to retry and stores nothing');

$invalid = webco_process_enquiry(good_input(['email' => 'nope']), '203.0.113.9', $now, $db);
check($invalid['http'] === 422 && isset($invalid['errors']['email']) && enquiry_count($db) === 0, 'an invalid enquiry returns field errors and stores nothing');

$sent = webco_process_enquiry(good_input(), '203.0.113.9', $now, $db);
check($sent['http'] === 201 && $sent['status'] === 'ok' && enquiry_count($db) === 1, 'a valid enquiry is stored');

$row = $db->query('SELECT * FROM enquiries')->fetch();
check($row['status'] === 'new', 'a new enquiry starts as new');
check($row['source'] === 'hgv-landing', 'the source is recorded');
check($row['business_name'] === 'Taylor HGV Training' && $row['package_interest'] === 'professional', 'the business and package are stored');
check(preg_match('/^enq_[a-f0-9]{20}$/', (string) $row['public_id']) === 1, 'the reference has the expected shape');
check(!str_contains((string) json_encode($row), '203.0.113.9'), 'the visitor address is not stored in the clear');
check(strlen((string) $row['ip_hash']) === 64, 'a keyed hash of the address is stored for rate limiting');
check($row['notified_at'] === null, 'with no mail settings the enquiry is stored and marked as not notified');
check((int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 0, 'an enquiry does not create an order');

$again = webco_process_enquiry(good_input(), '203.0.113.9', $now + 5, $db);
check($again['http'] === 200 && enquiry_count($db) === 1, 'sending the same message twice stores it once');

foreach (['b', 'c'] as $n => $letter) {
    webco_process_enquiry(good_input(['message' => "A different question number {$letter} about the websites."]), '203.0.113.9', $now + 10 + $n, $db);
}
check(enquiry_count($db) === 3, 'three different enquiries from one address are stored');
$limited = webco_process_enquiry(good_input(['message' => 'A fourth different question about the websites.']), '203.0.113.9', $now + 30, $db);
check($limited['http'] === 429 && enquiry_count($db) === 3, 'a fourth message within ten minutes is limited');

$later = webco_process_enquiry(good_input(['message' => 'A question sent much later in the day.']), '203.0.113.9', $now + 900, $db);
check($later['http'] === 201 && enquiry_count($db) === 4, 'the short limit lifts after ten minutes');

$other = webco_process_enquiry(good_input(['message' => 'A question from a different visitor entirely.']), '198.51.100.7', $now + 31, $db);
check($other['http'] === 201, 'another address is not affected by the first address limit');

// The daily and global caps.
$db->exec('DELETE FROM enquiries');
for ($i = 0; $i < 8; $i++) {
    $db->prepare(
        'INSERT INTO enquiries (public_id, source, contact_name, business_name, email, package_interest, message, ip_hash, created_at)
         VALUES (:p, \'hgv-landing\', \'A\', \'B\', \'a@b.example\', \'not_sure\', \'m\', :h, :c)'
    )->execute(['p' => 'enq_' . substr(hash('sha1', 'a' . $i), 0, 20), 'h' => webco_enquiry_ip_hash('192.0.2.1'), 'c' => gmdate('Y-m-d H:i:s', $now - 20000 + $i)]);
}
$daily = webco_process_enquiry(good_input(['message' => 'Ninth question from the same address today.']), '192.0.2.1', $now, $db);
check($daily['http'] === 429, 'a ninth message from one address in a day is limited');

$db->exec('DELETE FROM enquiries');
for ($i = 0; $i < 40; $i++) {
    $db->prepare(
        'INSERT INTO enquiries (public_id, source, contact_name, business_name, email, package_interest, message, ip_hash, created_at)
         VALUES (:p, \'hgv-landing\', \'A\', \'B\', \'a@b.example\', \'not_sure\', \'m\', :h, :c)'
    )->execute(['p' => 'enq_' . substr(hash('sha1', 'b' . $i), 0, 20), 'h' => hash('sha256', (string) $i), 'c' => gmdate('Y-m-d H:i:s', $now - 100)]);
}
$flood = webco_process_enquiry(good_input(['message' => 'One more question during a flood of traffic.']), '192.0.2.50', $now, $db);
check($flood['http'] === 429, 'the global hourly cap stops a flood from many addresses');

// ---------- Mail ----------

$mailSent = webco_enquiry_notify($db, 1, 'enq_' . str_repeat('a', 20), [
    'name' => 'Sam',
    'business' => 'B',
    'email' => 'sam@example.com',
    'phone' => null,
    'package' => 'essential',
    'message' => 'A message long enough.',
]);
check($mailSent === false, 'notification does nothing when mail settings are missing');

// ---------- Status changes and admin card ----------

$db->exec('DELETE FROM enquiries');
$stored = webco_enquiry_insert($db, [
    'name' => 'Pat <b>Smith</b>',
    'business' => 'Smith & Sons "Training"',
    'email' => 'pat@example.com',
    'phone' => '07700 900123',
    'package' => 'essential',
    'message' => "<script>alert(1)</script>\nSecond line",
], webco_enquiry_ip_hash('192.0.2.77'), $now);
check($stored !== null && $stored['id'] > 0, 'an enquiry can be inserted directly');
$id = (int) $stored['id'];
check(webco_count_new_enquiries($db) === 1, 'new enquiries are counted');
check(webco_enquiry_set_status($db, $id, 'replied'), 'a new enquiry can be marked replied');
check(webco_count_new_enquiries($db) === 0, 'a replied enquiry is no longer new');
check(!webco_enquiry_set_status($db, $id, 'replied'), 'setting the same status again changes nothing');
check(!webco_enquiry_set_status($db, $id, 'deleted'), 'an unknown status is refused');
check(webco_enquiry_set_status($db, $id, 'closed'), 'an enquiry can be closed');
check(webco_enquiry_set_status($db, $id, 'new'), 'a closed enquiry can be reopened');
check(webco_enquiry_set_status($db, $id, 'replied'), 'status moves can be repeated');
$list = webco_list_enquiries($db);
check(count($list) === 1 && $list[0]['public_id'] === $stored['public_id'], 'the list returns stored enquiries');

$_SESSION = ['csrf' => str_repeat('a', 32)];
ob_start();
webco_admin_enquiry_card($list[0], str_repeat('a', 32), 'new');
$html = (string) ob_get_clean();
check(!str_contains($html, '<script>'), 'the admin card escapes visitor text');
check(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'the admin card shows the message as text');
check(str_contains($html, 'Smith &amp; Sons &quot;Training&quot;'), 'the admin card escapes the business name');
check(str_contains($html, 'mailto:pat@example.com'), 'the admin card links to reply by email');
check(str_contains($html, 'tel:07700900123'), 'the admin card links the telephone number');
check(str_contains($html, 'name="to_status" value="closed"'), 'the admin card offers the next status');
check(str_contains($html, 'Email alert not sent'), 'the admin card flags a missing email alert');

// Retention: enquiries older than 30 days are removed, newer ones are kept.
$retentionStart = strtotime((string) $db->query('SELECT MIN(created_at) FROM enquiries')->fetchColumn() . ' UTC');
$retentionTotal = (int) $db->query('SELECT COUNT(*) FROM enquiries')->fetchColumn();
check(webco_purge_old_enquiries($db, $retentionStart + 29 * 86400) === 0, 'enquiries under 30 days old are kept');
check(webco_purge_old_enquiries($db, $retentionStart + 31 * 86400) === $retentionTotal && $retentionTotal > 0, 'enquiries over 30 days old are deleted');
check((int) $db->query('SELECT COUNT(*) FROM enquiries')->fetchColumn() === 0, 'nothing is left after the purge');

@unlink($path);

echo $failures === 0 ? "\nAll enquiry checks passed.\n" : "\n{$failures} enquiry check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
