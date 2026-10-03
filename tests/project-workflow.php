<?php
/**
 * Website project workflow. SQLite only. No Stripe and no 20i calls.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/lib/projects.php';

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

$statuses = webco_project_statuses();
check($statuses === [
    'awaiting_brief',
    'brief_in_progress',
    'brief_received',
    'ready_for_clone',
    'ready_for_build',
    'in_build',
    'review',
    'ready_to_launch',
    'live',
], 'website statuses follow the clone handoff');
check(webco_project_workflow_next('brief_received') === 'ready_for_clone', 'a received brief can move to clone');
check(webco_project_workflow_next('ready_for_clone') === null, 'clone completion is not a plain status step');
check(webco_project_workflow_next('ready_for_build') === 'in_build', 'a recorded clone can enter the build');
check(!str_contains((string) file_get_contents(dirname(__DIR__) . '/public/admin.php'), 'addWeb'), 'admin does not call addWeb');
check(is_file(dirname(__DIR__) . '/bin/provision-hosting.php'), 'the hosting worker remains available');

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-workflow-' . getmypid() . '.sqlite';
if (is_file($path)) {
    unlink($path);
}
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        status TEXT NOT NULL,
        twentyi_package_id TEXT UNIQUE,
        updated_at TEXT
    )'
);
$db->exec(
    'CREATE TABLE project_briefs (
        project_id INTEGER PRIMARY KEY,
        summary TEXT,
        submitted_at TEXT
    )'
);

$insert = $db->prepare('INSERT INTO projects (status) VALUES (:status)');
$brief = $db->prepare(
    'INSERT INTO project_briefs (project_id, summary, submitted_at) VALUES (:id, :summary, :submitted_at)'
);
$insert->execute(['status' => 'awaiting_brief']);
$waitingId = (int) $db->lastInsertId();
check(!webco_advance_project_status($db, $waitingId), 'an unfinished brief cannot skip to clone');

$insert->execute(['status' => 'brief_received']);
$emptyId = (int) $db->lastInsertId();
$brief->execute(['id' => $emptyId, 'summary' => '', 'submitted_at' => '2026-10-03 15:00:00']);
check(!webco_advance_project_status($db, $emptyId), 'an empty brief cannot be marked ready for clone');

$insert->execute(['status' => 'brief_received']);
$readyId = (int) $db->lastInsertId();
$brief->execute(['id' => $readyId, 'summary' => 'Train HGV drivers in Kent.', 'submitted_at' => '2026-10-03 15:00:00']);
check(webco_advance_project_status($db, $readyId), 'a submitted brief can be marked ready for clone');
check(status_of($db, $readyId) === 'ready_for_clone', 'the project is ready for clone');
check(webco_record_cloned_package($db, $readyId, '0') === 'invalid', 'package id 0 is rejected');
check(status_of($db, $readyId) === 'ready_for_clone', 'a rejected package id leaves the project ready for clone');
check(webco_record_cloned_package($db, $readyId, '866239') === 'saved', 'a manual clone stores the package id');
check(status_of($db, $readyId) === 'ready_for_build', 'a saved package id moves the project to ready for build');
check(package_of($db, $readyId) === '866239', 'the stored 20i reference is the package id');
check(webco_record_cloned_package($db, $readyId, '866240') === 'duplicate', 'clone completion cannot be applied twice');
check(package_of($db, $readyId) === '866239', 'a second clone does not replace the package id');
check(status_of($db, $readyId) === 'ready_for_build', 'a second clone does not change the status');

$insert->execute(['status' => 'ready_for_clone']);
$otherId = (int) $db->lastInsertId();
check(webco_record_cloned_package($db, $otherId, '866239') === 'duplicate', 'another project cannot take the same package id');
check(package_of($db, $otherId) === null, 'the colliding clone stores no package id');
check(status_of($db, $otherId) === 'ready_for_clone', 'the colliding project stays ready for clone');

check(webco_advance_project_status($db, $readyId), 'ready for build can enter the build');
check(status_of($db, $readyId) === 'in_build', 'the project is in build');
check(webco_advance_project_status($db, $readyId), 'in build can move to review');
check(webco_advance_project_status($db, $readyId), 'review can move to ready to launch');
check(webco_advance_project_status($db, $readyId), 'ready to launch can go live');
check(status_of($db, $readyId) === 'live', 'the project can reach live one step at a time');
check(!webco_advance_project_status($db, $readyId), 'a live project has no further status step');

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);

function status_of(PDO $db, int $projectId): string
{
    $statement = $db->prepare('SELECT status FROM projects WHERE id = :id');
    $statement->execute(['id' => $projectId]);
    $row = $statement->fetch();

    return is_array($row) ? (string) ($row['status'] ?? '') : '';
}

function package_of(PDO $db, int $projectId): ?string
{
    $statement = $db->prepare('SELECT twentyi_package_id FROM projects WHERE id = :id');
    $statement->execute(['id' => $projectId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }
    $value = $row['twentyi_package_id'] ?? null;

    return is_string($value) && $value !== '' ? $value : null;
}
