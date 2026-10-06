<?php
/**
 * Schema migration verification helpers. No live database writes.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/bin/provision-schema.php';

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

$source = (string) file_get_contents(dirname(__DIR__) . '/bin/provision-schema.php');
check($source !== '', 'schema migration source can be read');
check(
    str_contains($source, "o.stripe_livemode = 1"),
    'paid waiting verification requires stripe_livemode = 1'
);
check(
    str_contains($source, 'domain_registered_at'),
    'schema migration mentions domain_registered_at'
);
$projectColumns = webco_schema_project_columns();
check(
    in_array('domain_registered_at', $projectColumns, true),
    'domain_registered_at is in the projectColumns verification list'
);
check(
    in_array('provisioning_attempted_at', $projectColumns, true),
    'existing provisioning columns remain in the verification list'
);

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-schema-' . getmypid() . '.sqlite';
if (is_file($path)) {
    unlink($path);
}
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        status TEXT NOT NULL,
        stripe_livemode INTEGER
    )'
);
$db->exec(
    'CREATE TABLE projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL UNIQUE,
        provisioning_status TEXT NOT NULL
    )'
);

function insert_schema_case(PDO $db, string $status, ?int $livemode, string $provisioning): int
{
    static $n = 0;
    $n++;
    $db->prepare(
        'INSERT INTO orders (id, status, stripe_livemode) VALUES (:id, :status, :livemode)'
    )->execute([
        'id' => $n,
        'status' => $status,
        'livemode' => $livemode,
    ]);
    $db->prepare(
        'INSERT INTO projects (order_id, provisioning_status) VALUES (:order_id, :status)'
    )->execute([
        'order_id' => $n,
        'status' => $provisioning,
    ]);

    return $n;
}

insert_schema_case($db, 'paid', 0, 'waiting_payment');
insert_schema_case($db, 'paid', 0, 'waiting_payment');
insert_schema_case($db, 'paid', 1, 'ready');
insert_schema_case($db, 'draft', null, 'waiting_payment');

$columns = ['provisioning_status' => true];
$testOnly = webco_schema_paid_waiting_count($db, $columns);
check($testOnly === 0, 'paid test-mode projects stuck in waiting_payment are allowed');

insert_schema_case($db, 'paid', 1, 'waiting_payment');
$withLive = webco_schema_paid_waiting_count($db, $columns);
check($withLive === 1, 'a paid live project stuck in waiting_payment fails verification count');

insert_schema_case($db, 'paid', null, 'waiting_payment');
$withNull = webco_schema_paid_waiting_count($db, $columns);
check($withNull === 1, 'paid orders with unknown livemode do not inflate the live waiting count');

check(webco_schema_paid_waiting_count($db, null) === null, 'missing provisioning_status column is unreadable');
check(
    webco_schema_paid_waiting_count($db, ['twentyi_package_id' => true]) === null,
    'absent provisioning_status column is unreadable'
);

$schemaScript = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'provision-schema.php';
$schemaReal = realpath($schemaScript);
check(is_string($schemaReal) && $schemaReal !== '', 'schema script realpath resolves');
check(
    webco_schema_is_cli_entrypoint(__FILE__) === false,
    'including the schema script from tests is not treated as the CLI entrypoint'
);
check(
    webco_schema_is_cli_entrypoint($schemaReal) === true,
    'an absolute path to the schema script is treated as the CLI entrypoint'
);

$previousCwd = getcwd();
check($previousCwd !== false, 'current working directory is available');
if ($previousCwd !== false) {
    chdir(dirname(__DIR__));
    check(
        webco_schema_is_cli_entrypoint('bin/provision-schema.php') === true,
        'a relative path to the schema script is treated as the CLI entrypoint'
    );
    chdir($previousCwd);
}

check(
    !str_contains($source, "str_ends_with(\$script, '/bin/provision-schema.php')"),
    'schema entrypoint no longer relies on a /bin/ suffix match alone'
);
check(
    str_contains($source, 'webco_schema_is_cli_entrypoint'),
    'schema migration uses webco_schema_is_cli_entrypoint for CLI detection'
);

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
