<?php
/**
 * Dry-run provisioning checks. No 20i network calls and no Stripe calls.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/lib/provision.php';

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
 * @return array{db: PDO, other: PDO, path: string}
 */
function test_db(): array
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-provision-' . getmypid() . '.sqlite';
    if (is_file($path)) {
        unlink($path);
    }
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 300');
    $other = new PDO('sqlite:' . $path);
    $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $other->exec('PRAGMA busy_timeout = 300');
    $db->exec(
        'CREATE TABLE orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL,
            package_code TEXT,
            package_name TEXT,
            domain_path TEXT,
            domain_name TEXT,
            business_name TEXT,
            contact_name TEXT,
            email TEXT,
            phone TEXT,
            care_choice TEXT,
            hosting_status TEXT,
            hosting_included_until TEXT,
            care_status TEXT,
            stripe_livemode INTEGER
        )'
    );
    $db->exec(
        'CREATE TABLE projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL UNIQUE,
            order_public_id TEXT NOT NULL,
            vertical_code TEXT,
            status TEXT NOT NULL,
            provisioning_status TEXT NOT NULL,
            provisioning_error TEXT,
            updated_at TEXT
        )'
    );

    return ['db' => $db, 'other' => $other, 'path' => $path];
}

/**
 * @param array<string, mixed> $order
 * @param array<string, mixed> $project
 */
function insert_case(PDO $db, array $order, array $project = []): int
{
    static $n = 0;
    $n++;
    $publicId = $order['public_id'] ?? ('wc_' . str_pad(dechex($n), 20, 'a', STR_PAD_LEFT));
    $row = array_merge([
        'public_id' => $publicId,
        'status' => 'paid',
        'package_code' => 'essential',
        'package_name' => 'Webco Essential',
        'domain_path' => 'new',
        'domain_name' => 'example.com',
        'business_name' => 'Example Training',
        'contact_name' => 'Alex Example',
        'email' => 'alex@example.com',
        'phone' => '07123456789',
        'care_choice' => 'standard',
        'hosting_status' => 'trialing',
        'hosting_included_until' => '2027-10-02 17:25:00',
        'care_status' => null,
        'stripe_livemode' => 1,
    ], $order);
    $row['public_id'] = $publicId;

    $statement = $db->prepare(
        'INSERT INTO orders (
            public_id, status, package_code, package_name, domain_path, domain_name,
            business_name, contact_name, email, phone, care_choice, hosting_status,
            hosting_included_until, care_status, stripe_livemode
         ) VALUES (
            :public_id, :status, :package_code, :package_name, :domain_path, :domain_name,
            :business_name, :contact_name, :email, :phone, :care_choice, :hosting_status,
            :hosting_included_until, :care_status, :stripe_livemode
         )'
    );
    $statement->execute($row);
    $orderId = (int) $db->lastInsertId();
    $projectRow = array_merge([
        'order_id' => $orderId,
        'order_public_id' => $publicId,
        'vertical_code' => 'hgv_training',
        'status' => 'awaiting_brief',
        'provisioning_status' => 'ready',
        'provisioning_error' => null,
    ], $project);
    $insert = $db->prepare(
        'INSERT INTO projects (
            order_id, order_public_id, vertical_code, status, provisioning_status, provisioning_error
         ) VALUES (
            :order_id, :order_public_id, :vertical_code, :status, :provisioning_status, :provisioning_error
         )'
    );
    $insert->execute($projectRow);

    return (int) $db->lastInsertId();
}

/**
 * @return array{0: stdClass, 1: callable(array{event: string, context: array<string, mixed>}): void}
 */
function capture_log(): array
{
    $bucket = new stdClass();
    $bucket->entries = [];
    $log = static function (array $entry) use ($bucket): void {
        $bucket->entries[] = $entry;
    };

    return [$bucket, $log];
}

function status_of(PDO $db, int $projectId): string
{
    $statement = $db->prepare('SELECT provisioning_status FROM projects WHERE id = :id');
    $statement->execute(['id' => $projectId]);
    $row = $statement->fetch();

    return (string) ($row['provisioning_status'] ?? '');
}

function error_of(PDO $db, int $projectId): ?string
{
    $statement = $db->prepare('SELECT provisioning_error FROM projects WHERE id = :id');
    $statement->execute(['id' => $projectId]);
    $row = $statement->fetch();
    $error = $row['provisioning_error'] ?? null;

    return $error === null ? null : (string) $error;
}

$library = file_get_contents(dirname(__DIR__) . '/public/lib/provision.php');
$cli = file_get_contents(dirname(__DIR__) . '/bin/provision-dry-run.php');
check(is_string($library) && is_string($cli), 'worker files can be read');
$source = (string) $library . (string) $cli;
check(!str_contains($source, 'api.20i.com'), 'worker does not call the 20i API');
check(!str_contains($source, 'api.stripe.com'), 'worker does not call Stripe');
check(!str_contains($source, 'curl_'), 'worker does not open an HTTP client');
check(str_contains((string) $cli, "PHP_SAPI !== 'cli'"), 'CLI entry refuses a web request');

$setup = test_db();
$db = $setup['db'];
$other = $setup['other'];

$essentialId = insert_case($db, [
    'domain_name' => 'fotojuice.example',
    'care_choice' => 'standard',
    'hosting_status' => 'trialing',
    'care_status' => null,
]);
[$essentialBucket, $essentialLogger] = capture_log();
$essential = webco_provision_dry_run_project($db, $essentialId, $essentialLogger);
$essentialLog = $essentialBucket->entries;
check(($essential['outcome'] ?? '') === 'dry_run', 'paid Essential project in ready completes a dry-run');
check(($essential['returned_to_ready'] ?? false) === true, 'Essential dry-run returns the project to ready');
check(status_of($db, $essentialId) === 'ready', 'Essential provisioning_status is ready again');
check(error_of($db, $essentialId) === null, 'Essential dry-run clears provisioning_error');
$essentialPayload = $essential['payload'] ?? null;
check(is_array($essentialPayload) && ($essentialPayload['package_code'] ?? '') === 'essential', 'Essential package target is essential');
check(is_array($essentialPayload) && ($essentialPayload['package_target'] ?? '') === 'essential', 'Essential mapping does not invent a 20i package id');
check(is_array($essentialPayload) && ($essentialPayload['standalone_hosting'] ?? false) === true, 'standard hosting is standalone');
check(is_array($essentialPayload) && ($essentialPayload['managed_care'] ?? true) === false, 'standard hosting is not Managed Care');
check(is_array($essentialPayload) && ($essentialPayload['hosting_mode'] ?? '') === 'standalone_hosting', 'standard hosting mode is standalone_hosting');
check(
    is_array($essentialPayload) && ($essentialPayload['summary'] ?? '') === 'Would provision Essential website for fotojuice.example with standard hosting',
    'Essential standard summary names the website and hosting'
);
$essentialEvents = array_column($essentialLog, 'event');
check(in_array('worker_started', $essentialEvents, true), 'worker started is logged');
check(in_array('project_claimed', $essentialEvents, true), 'project claimed is logged');
check(in_array('validation_passed', $essentialEvents, true), 'validation passed is logged');
check(in_array('dry_run_generated', $essentialEvents, true), 'dry-run payload is logged');
check(in_array('project_returned_to_ready', $essentialEvents, true), 'return to ready is logged');
$essentialEncoded = json_encode($essentialLog);
check(is_string($essentialEncoded) && !str_contains($essentialEncoded, 'sk_') && !str_contains($essentialEncoded, 'password'), 'Essential log has no secrets');

$professionalId = insert_case($db, [
    'package_code' => 'professional',
    'package_name' => 'Webco Professional',
    'domain_name' => 'example.com',
    'domain_path' => 'existing',
    'care_choice' => 'managed',
    'hosting_status' => 'included',
    'hosting_included_until' => null,
    'care_status' => 'trialing',
    'business_name' => 'Harbour Training',
    'email' => 'ops@example.com',
]);
$professional = webco_provision_dry_run_project($db, $professionalId, static function (array $entry): void {
});
$professionalPayload = $professional['payload'] ?? null;
check(($professional['outcome'] ?? '') === 'dry_run', 'paid Professional project in ready completes a dry-run');
check(is_array($professionalPayload) && ($professionalPayload['package_code'] ?? '') === 'professional', 'Professional package target is professional');
check(is_array($professionalPayload) && ($professionalPayload['managed_care'] ?? false) === true, 'Managed Care is selected');
check(is_array($professionalPayload) && ($professionalPayload['standalone_hosting'] ?? true) === false, 'Managed Care has no standalone hosting');
check(is_array($professionalPayload) && ($professionalPayload['hosting_mode'] ?? '') === 'managed_care', 'Managed Care hosting mode is managed_care');
check(is_array($professionalPayload) && ($professionalPayload['domain_path'] ?? '') === 'existing', 'domain choice is kept');
check(
    is_array($professionalPayload) && ($professionalPayload['summary'] ?? '') === 'Would provision Professional website for example.com with Managed Care hosting',
    'Professional Managed Care summary matches the dry-run sentence'
);
check(status_of($db, $professionalId) === 'ready', 'Professional provisioning_status is ready again');

$unpaidStatuses = ['draft', 'checkout_created', 'cancelled', 'refunded'];
foreach ($unpaidStatuses as $unpaidStatus) {
    $unpaidId = insert_case($db, ['status' => $unpaidStatus, 'domain_name' => $unpaidStatus . '.example']);
    [$unpaidBucket, $unpaidLogger] = capture_log();
    $unpaid = webco_provision_dry_run_project($db, $unpaidId, $unpaidLogger);
    $unpaidLog = $unpaidBucket->entries;
    check(($unpaid['outcome'] ?? '') === 'skipped', $unpaidStatus . ' order is not claimed');
    check(status_of($db, $unpaidId) === 'ready', $unpaidStatus . ' project stays ready');
    check(error_of($db, $unpaidId) === null, $unpaidStatus . ' project is not failed');
    $reasons = array_column(array_column($unpaidLog, 'context'), 'reason');
    check(in_array('not_eligible', $reasons, true), $unpaidStatus . ' skip is logged as not eligible');
}

$missingDomainId = insert_case($db, ['domain_name' => '']);
$missingDomain = webco_provision_dry_run_project($db, $missingDomainId, static function (array $entry): void {
});
check(($missingDomain['outcome'] ?? '') === 'failed', 'missing domain fails the dry-run');
check(($missingDomain['error'] ?? '') === 'Missing domain', 'missing domain error is safe and specific');
check(status_of($db, $missingDomainId) === 'failed', 'missing domain sets provisioning_status to failed');
check(error_of($db, $missingDomainId) === 'Missing domain', 'missing domain is stored on the project');

$badPackageId = insert_case($db, ['package_code' => 'enterprise', 'package_name' => 'Webco Enterprise']);
$badPackage = webco_provision_dry_run_project($db, $badPackageId, static function (array $entry): void {
});
check(($badPackage['outcome'] ?? '') === 'failed', 'unsupported package fails the dry-run');
check(($badPackage['error'] ?? '') === 'Unsupported package', 'unsupported package is not guessed');
check(status_of($db, $badPackageId) === 'failed', 'unsupported package sets provisioning_status to failed');

$missingEmailId = insert_case($db, ['email' => '']);
$missingEmail = webco_provision_dry_run_project($db, $missingEmailId, static function (array $entry): void {
});
check(($missingEmail['outcome'] ?? '') === 'failed' && ($missingEmail['error'] ?? '') === 'Missing contact email', 'missing contact email fails closed');

$progressId = insert_case($db, ['domain_name' => 'busy.example'], ['provisioning_status' => 'in_progress']);
[$progressBucket, $progressLogger] = capture_log();
$progress = webco_provision_dry_run_project($db, $progressId, $progressLogger);
$progressLog = $progressBucket->entries;
check(($progress['outcome'] ?? '') === 'skipped', 'in_progress project is not claimed again');
check(status_of($db, $progressId) === 'in_progress', 'in_progress project stays in_progress');
$progressReasons = array_column(array_column($progressLog, 'context'), 'reason');
check(in_array('already_claimed', $progressReasons, true), 'in_progress skip is logged as already claimed');

$doneId = insert_case($db, ['domain_name' => 'done.example'], ['provisioning_status' => 'provisioned']);
$done = webco_provision_dry_run_project($db, $doneId, static function (array $entry): void {
});
check(($done['outcome'] ?? '') === 'skipped', 'provisioned project is not claimed');
check(status_of($db, $doneId) === 'provisioned', 'provisioned project stays provisioned');
check(webco_provision_release($db, $doneId) === false, 'release cannot overwrite provisioned');
check(status_of($db, $doneId) === 'provisioned', 'provisioned status remains after a dry-run release');

$raceId = insert_case($db, ['domain_name' => 'race.example']);
$db->beginTransaction();
$held = $db->prepare(
    'UPDATE projects
     SET provisioning_status = \'in_progress\'
     WHERE id = :id AND provisioning_status = \'ready\''
);
$held->execute(['id' => $raceId]);
check($held->rowCount() === 1, 'first worker claims the ready project');
$secondClaim = webco_provision_claim($other, $raceId);
check($secondClaim === null, 'second worker cannot claim the project');
$db->commit();
check(status_of($db, $raceId) === 'in_progress', 'the project stays with the first claim');
check(webco_provision_claim($other, $raceId) === null, 'a later claim sees the project is no longer ready');
check(webco_provision_release($db, $raceId) === true, 'the first claim can return only its in_progress row to ready');
check(status_of($other, $raceId) === 'ready', 'released project is ready for another dry-run');

$repeatId = insert_case($db, ['domain_name' => 'repeat.example']);
$firstRun = webco_provision_dry_run_project($db, $repeatId, static function (array $entry): void {
});
$secondRun = webco_provision_dry_run_project($other, $repeatId, static function (array $entry): void {
});
check(($firstRun['outcome'] ?? '') === 'dry_run' && ($secondRun['outcome'] ?? '') === 'dry_run', 'the same project can be dry-run again');
check(status_of($db, $repeatId) === 'ready', 'repeated dry-run leaves the project ready');
check(($firstRun['payload']['summary'] ?? '') === ($secondRun['payload']['summary'] ?? ''), 'repeated dry-run describes the same work');

$batchReady = insert_case($db, ['domain_name' => 'batch.example']);
$batchBusy = insert_case($db, ['domain_name' => 'batch-busy.example'], ['provisioning_status' => 'in_progress']);
$batchUnpaid = insert_case($db, ['status' => 'draft', 'domain_name' => 'batch-draft.example']);
[$batchBucket, $batchLogger] = capture_log();
$batch = webco_provision_dry_run($db, $batchLogger);
$batchLog = $batchBucket->entries;
$batchIds = array_column($batch['results'] ?? [], 'project_id');
check(in_array($batchReady, $batchIds, true), 'batch worker discovers the ready paid project');
check(!in_array($batchBusy, $batchIds, true), 'batch worker does not discover in_progress work');
check(!in_array($batchUnpaid, $batchIds, true), 'batch worker does not discover an unpaid order');
check(status_of($db, $batchReady) === 'ready', 'batch dry-run returns the claimed project to ready');
check(status_of($db, $batchBusy) === 'in_progress', 'batch dry-run leaves in_progress work alone');
$discovered = [];
foreach ($batchLog as $entry) {
    if (($entry['event'] ?? '') === 'project_discovered') {
        $discovered[] = (int) ($entry['context']['project_id'] ?? 0);
    }
}
check(in_array($batchReady, $discovered, true), 'discovered project is logged');
check(!in_array($batchBusy, $discovered, true), 'in_progress project is not logged as discovered');

$nullMode = insert_case($db, ['stripe_livemode' => null, 'domain_name' => 'unknown-mode.example']);
$testMode = insert_case($db, ['stripe_livemode' => 0, 'domain_name' => 'test-mode.example']);
$liveMode = insert_case($db, ['stripe_livemode' => 1, 'domain_name' => 'live-mode.example']);
$nullRun = webco_provision_dry_run_project($db, $nullMode, static function (array $entry): void {
});
$testRun = webco_provision_dry_run_project($db, $testMode, static function (array $entry): void {
});
$liveRun = webco_provision_dry_run_project($db, $liveMode, static function (array $entry): void {
});
check(($nullRun['outcome'] ?? '') === 'skipped' && status_of($db, $nullMode) === 'ready', 'stripe_livemode NULL is not eligible');
check(($testRun['outcome'] ?? '') === 'skipped' && status_of($db, $testMode) === 'ready', 'stripe_livemode 0 is not eligible');
check(($liveRun['outcome'] ?? '') === 'dry_run' && status_of($db, $liveMode) === 'ready', 'stripe_livemode 1 is eligible when the order is paid and ready');

$db = null;
$other = null;
gc_collect_cycles();
@unlink($setup['path']);

if ($failures > 0) {
    echo $failures . " failed\n";
    exit(1);
}

echo "all passed\n";
exit(0);
