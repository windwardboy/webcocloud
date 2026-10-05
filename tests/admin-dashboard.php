<?php
/**
 * Admin dashboard views, archive, and the restricted test-project delete.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

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

$publicId = 'wc_' . str_repeat('a', 20);
$otherId = 'wc_' . str_repeat('b', 20);

$active = project_row(1, 'Kent Training', 'ada@kent.example', 'brief_received', null, false);
$active['summary'] = 'We train drivers.';
$active['submitted_at'] = '2026-10-03 12:00:00';
$active['business_overview'] = 'Family training firm.';
check(webco_project_in_admin_view($active, 'attention'), 'a received brief needs attention');
check(webco_project_in_admin_view($active, 'active'), 'a received brief stays in the active view');
check(!webco_project_in_admin_view($active, 'live'), 'a received brief is not live');
check(!webco_project_in_admin_view($active, 'archived'), 'a working project is not archived');

$waiting = project_row(2, 'New School', 'new@kent.example', 'awaiting_brief', null, false);
check(!webco_project_in_admin_view($waiting, 'attention'), 'waiting for a brief is not attention by itself');
check(webco_project_in_admin_view($waiting, 'active'), 'waiting for a brief stays active');

$call = project_row(3, 'Call School', 'call@kent.example', 'brief_in_progress', null, false);
$call['call_requested'] = 1;
check(in_array('call_requested', webco_project_attention_reasons($call), true), 'a requested call needs attention');

$clone = project_row(4, 'Clone School', 'clone@kent.example', 'ready_for_clone', null, false);
$build = project_row(5, 'Build School', 'build@kent.example', 'ready_for_build', null, false);
$review = project_row(6, 'Review School', 'review@kent.example', 'review', null, false);
check(webco_project_in_admin_view($clone, 'attention'), 'ready for clone needs attention');
check(webco_project_in_admin_view($build, 'attention'), 'ready for build needs attention');
check(webco_project_in_admin_view($review, 'attention'), 'review needs attention');

$live = project_row(7, 'Live School', 'live@kent.example', 'live', null, false);
$live['requests'] = [['status' => 'open'], ['status' => 'done']];
check(webco_project_open_request_count($live) === 1, 'done requests are not open');
check(webco_project_in_admin_view($live, 'attention'), 'an open request on a live site needs attention');
check(webco_project_in_admin_view($live, 'live'), 'a live site stays in the live view');
check(!webco_project_in_admin_view($live, 'active'), 'a live site leaves the active view');

$archived = project_row(8, 'Old School', 'old@kent.example', 'brief_received', null, true);
$archived['call_requested'] = 1;
check(webco_project_attention_reasons($archived) === [], 'an archived project does not need attention');
check(webco_project_in_admin_view($archived, 'archived'), 'an archived project is in the archive');
check(!webco_project_in_admin_view($archived, 'active'), 'an archived project leaves the active view');
check(webco_project_in_admin_view($archived, 'all'), 'an archived project remains in all');

check(!webco_project_test_delete_allowed('Kent Training', 'ada@example.com', null), 'a normal name cannot be deleted');
check(!webco_project_test_delete_allowed('[TEST] Kent Training', 'ada@kent.example', null), 'a real email domain cannot be deleted');
check(!webco_project_test_delete_allowed('[TEST] Kent Training', 'ada@example.com.evil', null), 'a lookalike email domain cannot be deleted');
check(!webco_project_test_delete_allowed('[TEST] Kent Training', 'ada@example.com', '4242'), 'a stored 20i package blocks deletion');
check(webco_project_test_delete_allowed('[TEST] Kent Training', 'ada@example.com', null), 'an explicit test project can be deleted');
check(webco_project_test_delete_allowed('[TEST] Kent Training', 'Ada@Example.TEST', '  '), 'a blank package id does not block a test project');

$row = admin_html_row($active);
check(str_contains($row, 'Kent Training'), 'a row shows the business');
check(str_contains($row, 'kent.example'), 'a row shows the domain');
check(str_contains($row, 'Webco Essential'), 'a row shows the package');
check(str_contains($row, 'Managed Care'), 'a row shows care');
check(str_contains($row, 'Brief received'), 'a row shows the build status');
check(str_contains($row, '2026-10-03'), 'a row shows the paid date');
check(str_contains($row, 'Mark ready for clone'), 'a row shows the next action');
check(str_contains($row, 'View project'), 'a row links to the project');
check(!str_contains($row, 'Family training firm.'), 'a row does not include the full brief');
check(!str_contains($row, 'Billing check'), 'a row does not show the billing diagnostic');

$detail = admin_html_detail($active);
check(str_contains($detail, 'Family training firm.'), 'the project page shows the brief');
check(str_contains($detail, 'Mark ready for clone'), 'the project page keeps the workflow control');
check(str_contains($detail, 'Billing check'), 'the project page shows the billing diagnostic');
check(str_contains($detail, 'No billing check has been recorded yet.'), 'the project page stays quiet until a billing check is recorded');
check(!str_contains($detail, 'webco-billing-debug.log') && !str_contains($detail, 'webco-secrets.php'), 'the project page does not reveal the private log path');
check(str_contains($detail, 'This project cannot be deleted.'), 'a normal project has no delete action');
check(!str_contains($detail, 'name="confirm_id"'), 'a normal project does not ask for delete confirmation');

$testProject = project_row(9, '[TEST] Sample School', 'sample@example.com', 'awaiting_brief', null, false);
$testProject['order_public_id'] = $publicId;
$testDetail = admin_html_detail($testProject);
check(str_contains($testDetail, 'name="confirm_id"'), 'a marked test project can be confirmed for deletion');
check(str_contains($testDetail, 'The paid order stays'), 'the delete warning says the order is kept');

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-admin-' . getmypid() . '.sqlite';
if (is_file($path)) {
    unlink($path);
}
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE orders (
        id INTEGER PRIMARY KEY,
        business_name TEXT,
        email TEXT,
        stripe_session_id TEXT
    )'
);
$db->exec(
    'CREATE TABLE projects (
        id INTEGER PRIMARY KEY,
        order_id INTEGER NOT NULL,
        order_public_id TEXT NOT NULL,
        status TEXT NOT NULL,
        twentyi_package_id TEXT,
        archived_at TEXT,
        updated_at TEXT
    )'
);
$db->exec('CREATE TABLE project_briefs (project_id INTEGER PRIMARY KEY, summary TEXT)');
$db->exec('CREATE TABLE project_requests (id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, summary TEXT)');
$db->exec('CREATE TABLE project_assets (id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, original_name TEXT)');
$db->exec(
    "INSERT INTO orders (id, business_name, email, stripe_session_id)
     VALUES (1, '[TEST] Sample School', 'sample@example.com', 'cs_test_keep')"
);
$db->exec(
    "INSERT INTO orders (id, business_name, email, stripe_session_id)
     VALUES (2, 'Real School', 'ada@kent.example', 'cs_live_keep')"
);
$db->exec(
    "INSERT INTO projects (id, order_id, order_public_id, status, archived_at)
     VALUES (1, 1, '{$publicId}', 'brief_received', NULL)"
);
$db->exec(
    "INSERT INTO projects (id, order_id, order_public_id, status, twentyi_package_id)
     VALUES (2, 2, '{$otherId}', 'live', '4242')"
);
$db->exec("INSERT INTO project_briefs (project_id, summary) VALUES (1, 'Test brief')");
$db->exec("INSERT INTO project_requests (id, project_id, summary) VALUES (5, 1, 'Test request')");
$db->exec("INSERT INTO project_assets (id, project_id, original_name) VALUES (8, 1, 'logo.png')");

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-admin-files-' . getmypid();
foreach ([$publicId, $otherId] as $folder) {
    foreach (['logos', 'photos', 'documents'] as $kind) {
        $dir = $root . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $kind;
        mkdir($dir, 0700, true);
    }
}
file_put_contents($root . DIRECTORY_SEPARATOR . $publicId . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR . 'logo.png', 'logo');
file_put_contents($root . DIRECTORY_SEPARATOR . $otherId . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR . 'keep.png', 'keep');

check(webco_archive_project($db, 1, false), 'a project can be archived');
$archivedRow = $db->query('SELECT status, archived_at FROM projects WHERE id = 1')->fetch();
check(is_array($archivedRow) && $archivedRow['status'] === 'brief_received', 'archive does not change the build status');
check(is_array($archivedRow) && is_string($archivedRow['archived_at']) && $archivedRow['archived_at'] !== '', 'archive records the time');
check(webco_archive_project($db, 1, true), 'an archived project can be restored');
$restored = $db->query('SELECT status, archived_at FROM projects WHERE id = 1')->fetch();
check(is_array($restored) && $restored['status'] === 'brief_received', 'restore does not change the build status');
check(is_array($restored) && $restored['archived_at'] === null, 'restore clears the archive time');

check(webco_delete_test_project($db, 2, $otherId, $root) === 'refused', 'a real project is not deleted');
check(webco_delete_test_project($db, 1, 'wc_wrong', $root) === 'mismatch', 'the wrong confirmation does not delete');
check(is_file($root . DIRECTORY_SEPARATOR . $publicId . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR . 'logo.png'), 'a refused delete leaves the file');
$blocked = $root . DIRECTORY_SEPARATOR . $publicId . DIRECTORY_SEPARATOR . 'notes.txt';
file_put_contents($blocked, 'extra');
check(webco_delete_test_project($db, 1, $publicId, $root) === 'error', 'an unexpected stored file stops deletion');
unlink($blocked);
check(webco_delete_test_project($db, 1, $publicId, $root) === 'deleted', 'a confirmed test project can be deleted');
check($db->query('SELECT id FROM projects WHERE id = 1')->fetch() === false, 'the test project row is removed');
check($db->query('SELECT project_id FROM project_briefs WHERE project_id = 1')->fetch() === false, 'the test brief is removed');
check($db->query('SELECT id FROM project_requests WHERE project_id = 1')->fetch() === false, 'the test request is removed');
check($db->query('SELECT id FROM project_assets WHERE project_id = 1')->fetch() === false, 'the test asset row is removed');
check(!is_dir($root . DIRECTORY_SEPARATOR . $publicId), 'the test upload folder is removed');
check(is_file($root . DIRECTORY_SEPARATOR . $otherId . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR . 'keep.png'), 'another project folder is kept');
$keptOrder = $db->query('SELECT stripe_session_id FROM orders WHERE id = 1')->fetch();
check(is_array($keptOrder) && $keptOrder['stripe_session_id'] === 'cs_test_keep', 'the paid order and its Stripe reference stay');
check($db->query('SELECT id FROM projects WHERE id = 2')->fetch() !== false, 'a real project row stays');
check(!str_contains((string) file_get_contents(dirname(__DIR__) . '/public/admin.php'), 'addWeb'), 'admin still does not call 20i');
check(!str_contains((string) file_get_contents(dirname(__DIR__) . '/public/lib/projects.php'), 'api.stripe.com'), 'project deletion does not call Stripe');

remove_tree($root);
$db = null;
if (is_file($path)) {
    unlink($path);
}

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);

/**
 * @return array<string, mixed>
 */
function project_row(int $id, string $name, string $email, string $status, ?string $packageId, bool $archived): array
{
    return [
        'id' => $id,
        'business_name' => $name,
        'email' => $email,
        'domain_name' => 'kent.example',
        'package_name' => 'Webco Essential',
        'care_choice' => 'managed',
        'status' => $status,
        'paid_at' => '2026-10-03 09:00:00',
        'order_public_id' => 'wc_' . str_repeat('c', 20),
        'twentyi_package_id' => $packageId,
        'archived_at' => $archived ? '2026-10-03 10:00:00' : null,
        'call_requested' => 0,
        'summary' => '',
        'submitted_at' => null,
        'requests' => [],
        'assets' => ['logo' => [], 'photo' => [], 'document' => []],
    ];
}

/**
 * @param array<string, mixed> $project
 */
function admin_html_row(array $project): string
{
    ob_start();
    webco_admin_project_row($project, '0123456789abcdef0123456789abcdef', 'attention');

    return (string) ob_get_clean();
}

/**
 * @param array<string, mixed> $project
 */
function admin_html_detail(array $project): string
{
    ob_start();
    webco_admin_project($project, '0123456789abcdef0123456789abcdef', 'attention');

    return (string) ob_get_clean();
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) {
            remove_tree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}
