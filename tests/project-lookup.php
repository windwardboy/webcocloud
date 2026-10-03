<?php
/**
 * Read-only project lookup checks. SQLite only. No live database and no API.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/bin/project-lookup.php';

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

$source = (string) file_get_contents(dirname(__DIR__) . '/bin/project-lookup.php');
check($source !== '', 'lookup file can be read');
check(str_contains($source, "PHP_SAPI !== 'cli'"), 'CLI entry refuses a web request');
check(str_contains($source, 'SELECT id, provisioning_status, twentyi_package_id'), 'lookup reads the project columns');
$forbidden = ['curl_', 'webco_twentyi', 'stripe', 'insert ', 'update ', 'delete ', 'alter ', 'mail.php', 'api.20i'];
foreach ($forbidden as $word) {
    check(!str_contains(strtolower($source), $word), 'lookup does not use ' . $word);
}

$missing = webco_project_lookup_options(['project-lookup.php']);
check($missing['ok'] === false, '--order is required');
$bad = webco_project_lookup_options(['project-lookup.php', '--order=wc_short']);
check($bad['ok'] === false, 'a short order id is rejected');
$twice = webco_project_lookup_options([
    'project-lookup.php',
    '--order=wc_d1c805f3b3cb89e803f4',
    '--order=wc_d1c805f3b3cb89e803f4',
]);
check($twice['ok'] === false, '--order may be given once');
$extra = webco_project_lookup_options(['project-lookup.php', '--order=wc_d1c805f3b3cb89e803f4', '--apply']);
check($extra['ok'] === false, 'lookup accepts no other arguments');
$parsed = webco_project_lookup_options(['project-lookup.php', '--order=wc_d1c805f3b3cb89e803f4']);
check($parsed['ok'] === true && $parsed['order'] === 'wc_d1c805f3b3cb89e803f4', 'one order id is accepted');

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-lookup-' . getmypid() . '.sqlite';
if (is_file($path)) {
    unlink($path);
}
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_public_id TEXT NOT NULL UNIQUE,
        provisioning_status TEXT NOT NULL,
        twentyi_package_id TEXT
    )'
);
$db->exec(
    "INSERT INTO projects (order_public_id, provisioning_status, twentyi_package_id)
     VALUES ('wc_d1c805f3b3cb89e803f4', 'ready', NULL)"
);
$db->exec(
    "INSERT INTO projects (order_public_id, provisioning_status, twentyi_package_id)
     VALUES ('wc_aaaaaaaaaaaaaaaaaaaa', 'provisioned', '866239')"
);

$ready = webco_project_lookup_row($db, 'wc_d1c805f3b3cb89e803f4');
check($ready['ok'] === true && $ready['found'] === true && $ready['project_id'] === 1, 'the named order returns its project id');
check($ready['provisioning_status'] === 'ready' && $ready['package_id'] === null, 'a project without a package id stays empty');
$readyText = webco_project_lookup_text($ready['project_id'], $ready['provisioning_status'], $ready['package_id']);
check($readyText === "project_id: 1\nprovisioning_status: ready\n", 'output names the project and status');
check(!str_contains($readyText, 'twentyi_package_id'), 'a missing package id is omitted');

$stored = webco_project_lookup_row($db, 'wc_aaaaaaaaaaaaaaaaaaaa');
check($stored['package_id'] === '866239' && $stored['provisioning_status'] === 'provisioned', 'a stored package id is returned');
$storedText = webco_project_lookup_text($stored['project_id'], $stored['provisioning_status'], $stored['package_id']);
check(str_contains($storedText, "twentyi_package_id: 866239\n"), 'output includes a stored package id');

$absent = webco_project_lookup_row($db, 'wc_bbbbbbbbbbbbbbbbbbbb');
check($absent['ok'] === true && $absent['found'] === false, 'an unknown order is not found');

$db = null;
unlink($path);

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
