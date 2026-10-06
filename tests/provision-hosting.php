<?php
/**
 * Hosting provisioning checks. The 20i transport is fake. No live writes.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/lib/provision-hosting.php';

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

function test_db(): PDO
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-hosting-' . getmypid() . '.sqlite';
    if (is_file($path)) {
        unlink($path);
    }
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
            address_line_1 TEXT,
            address_line_2 TEXT,
            town TEXT,
            county TEXT,
            postcode TEXT,
            company_number TEXT,
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
            twentyi_package_id TEXT,
            provisioning_attempted_at TEXT,
            provisioned_at TEXT,
            domain_registered_at TEXT,
            updated_at TEXT
        )'
    );

    return $db;
}

/**
 * @param array<string, mixed> $order
 * @param array<string, mixed> $project
 */
function insert_case(PDO $db, array $order = [], array $project = []): int
{
    static $n = 0;
    $n++;
    $publicId = 'wc_' . str_pad(dechex($n), 20, 'a', STR_PAD_LEFT);
    $row = array_merge([
        'public_id' => $publicId,
        'status' => 'paid',
        'package_code' => 'essential',
        'package_name' => 'Webco Essential',
        'domain_path' => 'existing',
        'domain_name' => 'customer.example',
        'business_name' => 'Example Training',
        'contact_name' => 'Alex Example',
        'email' => 'alex@example.com',
        'phone' => '07123456789',
        'address_line_1' => '1 High Street',
        'address_line_2' => null,
        'town' => 'Weston-super-Mare',
        'county' => 'Somerset',
        'postcode' => 'BS22 6AR',
        'company_number' => null,
        'care_choice' => 'standard',
        'hosting_status' => 'trialing',
        'hosting_included_until' => '2027-10-02 17:25:00',
        'care_status' => null,
        'stripe_livemode' => 1,
    ], $order);
    $statement = $db->prepare(
        'INSERT INTO orders (
            public_id, status, package_code, package_name, domain_path, domain_name,
            business_name, contact_name, email, phone, address_line_1, address_line_2,
            town, county, postcode, company_number, care_choice, hosting_status,
            hosting_included_until, care_status, stripe_livemode
         ) VALUES (
            :public_id, :status, :package_code, :package_name, :domain_path, :domain_name,
            :business_name, :contact_name, :email, :phone, :address_line_1, :address_line_2,
            :town, :county, :postcode, :company_number, :care_choice, :hosting_status,
            :hosting_included_until, :care_status, :stripe_livemode
         )'
    );
    $statement->execute($row);
    $orderId = (int) $db->lastInsertId();
    $projectRow = array_merge([
        'order_id' => $orderId,
        'order_public_id' => $row['public_id'],
        'vertical_code' => 'hgv_training',
        'status' => 'awaiting_brief',
        'provisioning_status' => 'ready',
        'provisioning_error' => 'previous failure',
        'twentyi_package_id' => null,
        'domain_registered_at' => null,
    ], $project);
    $insert = $db->prepare(
        'INSERT INTO projects (
            order_id, order_public_id, vertical_code, status, provisioning_status,
            provisioning_error, twentyi_package_id, domain_registered_at
         ) VALUES (
            :order_id, :order_public_id, :vertical_code, :status, :provisioning_status,
            :provisioning_error, :twentyi_package_id, :domain_registered_at
         )'
    );
    $insert->execute($projectRow);

    return (int) $db->lastInsertId();
}

/**
 * @return array<string, mixed>
 */
function project_row(PDO $db, int $projectId): array
{
    $statement = $db->prepare(
        'SELECT provisioning_status, twentyi_package_id, provisioning_error,
                provisioning_attempted_at, provisioned_at, domain_registered_at
         FROM projects WHERE id = :id'
    );
    $statement->execute(['id' => $projectId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : [];
}

/**
 * @return array{0: stdClass, 1: callable(string, string, string): array<string, mixed>}
 */
function fake_create(array $response): array
{
    $bucket = new stdClass();
    $bucket->calls = [];
    $create = static function (string $domain, string $type, string $label) use ($bucket, $response): array {
        $bucket->calls[] = ['domain' => $domain, 'type' => $type, 'label' => $label];

        return $response;
    };

    return [$bucket, $create];
}

function quiet_log(): callable
{
    return static function (array $entry): void {
    };
}

$worker = (string) file_get_contents(dirname(__DIR__) . '/public/lib/provision.php');
$dryCli = (string) file_get_contents(dirname(__DIR__) . '/bin/provision-dry-run.php');
$hostingCli = (string) file_get_contents(dirname(__DIR__) . '/bin/provision-hosting.php');
$webhook = (string) file_get_contents(dirname(__DIR__) . '/public/stripe-webhook.php');
check($worker !== '' && $dryCli !== '' && $hostingCli !== '' && $webhook !== '', 'hosting files can be read');
check(!str_contains($worker . $dryCli, 'provision-hosting.php'), 'the dry-run worker does not call hosting provisioning');
check(!str_contains($webhook, 'provision-hosting'), 'payment does not provision hosting');
check(!str_contains($webhook, 'allow-test-order') && !str_contains($webhook, 'allow_test_order'), 'the webhook cannot allow a test order');
$distWebhook = (string) file_get_contents(dirname(__DIR__) . '/dist/stripe-webhook.php');
check(!str_contains($distWebhook, 'allow-test-order') && !str_contains($distWebhook, 'allow_test_order'), 'the deployed webhook cannot allow a test order');
check(str_contains($hostingCli, "PHP_SAPI !== 'cli'"), 'CLI entry refuses a web request');
$applyGate = strpos($hostingCli, '!$options[\'apply\']');
$getCall = strpos($hostingCli, 'webco_provision_hosting_list_live');
$postCall = strpos($hostingCli, 'webco_twentyi_http_post');
check($applyGate !== false && $getCall !== false && $postCall !== false && $applyGate < $getCall && $applyGate < $postCall, 'preview returns before either 20i request');
$options = webco_provision_hosting_cli_options(['provision-hosting.php']);
check($options['ok'] === true && $options['apply'] === false, 'the CLI defaults to preview');
$applied = webco_provision_hosting_cli_options(['provision-hosting.php', '--project=4', '--apply']);
check($applied['ok'] === true && $applied['apply'] === true && $applied['project_id'] === 4 && $applied['allow_test_order'] === false, '--apply is explicit and stays in live mode');
check($options['allow_test_order'] === false, 'test orders stay disabled unless the flag is passed');
$bulkFlag = webco_provision_hosting_cli_options(['provision-hosting.php', '--allow-test-order']);
check($bulkFlag['ok'] === false && $bulkFlag['allow_test_order'] === false, 'test override without one project is rejected');
$bulkApply = webco_provision_hosting_cli_options(['provision-hosting.php', '--apply', '--allow-test-order']);
check($bulkApply['ok'] === false && $bulkApply['apply'] === false, 'bulk test apply is rejected before a 20i write');
$named = webco_provision_hosting_cli_options(['provision-hosting.php', '--project=1', '--allow-test-order']);
check($named['ok'] === true && $named['apply'] === false && $named['allow_test_order'] === true && $named['project_id'] === 1, 'one named test project is accepted for preview');

$listed = webco_provision_hosting_domains([
    'ok' => true,
    'rows' => webco_twentyi_package_rows([
        [
            'id' => 50,
            'name' => 'taken.example',
            'names' => ['taken.example', 'www.taken.example'],
            'ftpPassword' => 'ftp-secret',
        ],
        [
            'id' => 51,
            'name' => 'other.example',
            'names' => ['alias.example'],
        ],
    ]),
]);
check($listed['ok'] === true && in_array('taken.example', $listed['domains'], true), 'package list keeps the primary domain');
check(in_array('alias.example', $listed['domains'], true), 'package list keeps extra domain names');
check(!in_array('ftp-secret', $listed['domains'], true), 'package list drops non-domain fields');
check(webco_provision_hosting_domain_taken('Taken.Example', $listed['domains']), 'an existing domain is a collision');
check(!webco_provision_hosting_domain_taken('www.customer.example', ['customer.example']), 'a different host is not the same domain');

$db = test_db();
$successId = insert_case($db, ['domain_name' => 'customer.example']);
$successPublic = (string) $db->query('SELECT order_public_id FROM projects WHERE id = ' . $successId)->fetchColumn();
$preview = webco_provision_hosting_preview($db);
check(is_array($preview) && ($preview[0]['action'] ?? '') === 'create', 'preview describes a create');
check(project_row($db, $successId)['provisioning_status'] === 'ready', 'preview does not claim the project');
check(project_row($db, $successId)['provisioning_attempted_at'] === null, 'preview does not record an attempt');

[$created, $create] = fake_create([
    'ok' => true,
    'package_id' => '866239',
    'failure' => '',
    'status' => 200,
    'body' => 'Bearer secret-token',
]);
$success = webco_provision_hosting_project(
    $db,
    $successId,
    ['ok' => true, 'domains' => ['www.customer.example']],
    $create,
    quiet_log()
);
$successRow = project_row($db, $successId);
check($success['outcome'] === 'provisioned' && $success['package_id'] === '866239', 'a created package is provisioned');
check(count($created->calls) === 1, 'hosting is created once');
check(
    ($created->calls[0]['domain'] ?? '') === 'customer.example'
        && ($created->calls[0]['type'] ?? '') === '117014'
        && ($created->calls[0]['label'] ?? '') === $successPublic,
    'create uses the customer domain, package type 117014, and the order id'
);
check($successRow['provisioning_status'] === 'provisioned', 'provisioning_status is provisioned');
check($successRow['twentyi_package_id'] === '866239', 'the 20i package id is stored on the project');
check($successRow['provisioning_error'] === null, 'a successful attempt clears the previous error');
check($successRow['provisioning_attempted_at'] !== null && $successRow['provisioning_attempted_at'] !== '', 'the attempt time is stored');
check($successRow['provisioned_at'] !== null && $successRow['provisioned_at'] !== '', 'provisioned_at is stored after the package id');
check(!str_contains(json_encode($success) ?: '', 'secret-token'), 'the create result drops the response body');

$professionalId = insert_case($db, [
    'package_code' => 'professional',
    'package_name' => 'Webco Professional',
    'domain_name' => 'professional.example',
    'care_choice' => 'managed',
    'hosting_status' => 'included',
    'hosting_included_until' => null,
    'care_status' => 'trialing',
]);
[$professionalCreated, $professionalCreate] = fake_create([
    'ok' => true,
    'package_id' => '866240',
    'failure' => '',
    'status' => 200,
]);
webco_provision_hosting_project(
    $db,
    $professionalId,
    ['ok' => true, 'domains' => []],
    $professionalCreate,
    quiet_log()
);
check(($professionalCreated->calls[0]['type'] ?? '') === '117014', 'Professional uses the same package type');

$collisionId = insert_case($db, ['domain_name' => 'taken.example']);
[$collisionCreated, $collisionCreate] = fake_create([
    'ok' => true,
    'package_id' => '1',
    'failure' => '',
    'status' => 200,
]);
$collision = webco_provision_hosting_project(
    $db,
    $collisionId,
    ['ok' => true, 'domains' => $listed['domains']],
    $collisionCreate,
    quiet_log()
);
$collisionRow = project_row($db, $collisionId);
check($collisionCreated->calls === [], 'a colliding domain is not created or adopted');
check($collision['outcome'] === 'failed', 'a collision fails the attempt');
check($collisionRow['provisioning_status'] === 'failed', 'a collision sets provisioning_status failed');
check($collisionRow['twentyi_package_id'] === null, 'a collision does not store the existing package id');
check(
    ($collisionRow['provisioning_error'] ?? '') === 'Domain already exists on a 20i hosting package',
    'a collision stores a clear error'
);

$retryId = insert_case($db, ['domain_name' => 'stored.example'], ['twentyi_package_id' => '424242']);
$listCalls = 0;
$retryList = static function () use (&$listCalls): array {
    $listCalls++;

    return ['ok' => true, 'domains' => []];
};
[$retryCreated, $retryCreate] = fake_create([
    'ok' => true,
    'package_id' => '999999',
    'failure' => '',
    'status' => 200,
]);
$retry = webco_provision_hosting_apply($db, $retryList, $retryCreate, quiet_log(), $retryId);
$retryRow = project_row($db, $retryId);
check($listCalls === 0 && $retryCreated->calls === [], 'a stored package id is not listed or created again');
check(($retry['results'][0]['outcome'] ?? '') === 'provisioned', 'a stored package id finishes as provisioned');
check($retryRow['twentyi_package_id'] === '424242', 'the stored package id is unchanged');
check($retryRow['provisioning_error'] === null, 'finishing a stored package clears the previous error');

$apiId = insert_case($db, ['domain_name' => 'api.example']);
[$apiCreated, $apiCreate] = fake_create([
    'ok' => false,
    'package_id' => '',
    'failure' => 'http',
    'status' => 502,
    'body' => 'Bearer leaked-token password=hidden',
]);
$api = webco_provision_hosting_project(
    $db,
    $apiId,
    ['ok' => true, 'domains' => []],
    $apiCreate,
    quiet_log()
);
$apiRow = project_row($db, $apiId);
check(count($apiCreated->calls) === 1 && $api['outcome'] === 'failed', 'an API failure is recorded');
check($apiRow['provisioning_status'] === 'failed', 'an API failure sets provisioning_status failed');
check($apiRow['twentyi_package_id'] === null, 'an API failure stores no package id');
check(
    ($apiRow['provisioning_error'] ?? '') === 'Hosting package creation failed (HTTP 502)',
    'an API failure stores the status without the response'
);
$apiEncoded = json_encode([$api, $apiRow]) ?: '';
check(!str_contains($apiEncoded, 'leaked-token') && !str_contains($apiEncoded, 'password'), 'an API failure drops the response body');

$saveId = insert_case($db, ['domain_name' => 'save.example']);
[$saveCreated, $saveCreate] = fake_create([
    'ok' => true,
    'package_id' => '777001',
    'failure' => '',
    'status' => 200,
]);
$save = webco_provision_hosting_project(
    $db,
    $saveId,
    ['ok' => true, 'domains' => []],
    $saveCreate,
    quiet_log(),
    static function (PDO $ignored, int $ignoredId, string $ignoredPackage): bool {
        return false;
    }
);
$saveRow = project_row($db, $saveId);
check(count($saveCreated->calls) === 1 && $save['outcome'] === 'failed', 'a database failure after creation does not claim success');
check($saveRow['twentyi_package_id'] === null, 'a failed package-id write leaves the column empty');
check($saveRow['provisioning_status'] === 'failed', 'a failed package-id write marks the attempt failed');
check(
    ($saveRow['provisioning_error'] ?? '') === 'Hosting package was created but its id could not be saved',
    'a failed package-id write explains that the hosting package already exists'
);
$db->prepare('UPDATE projects SET provisioning_status = \'ready\' WHERE id = :id')->execute(['id' => $saveId]);
[$saveAgain, $saveCreateAgain] = fake_create([
    'ok' => true,
    'package_id' => '777002',
    'failure' => '',
    'status' => 200,
]);
$saveRetry = webco_provision_hosting_project(
    $db,
    $saveId,
    ['ok' => true, 'domains' => ['save.example']],
    $saveCreateAgain,
    quiet_log()
);
check($saveAgain->calls === [] && $saveRetry['outcome'] === 'failed', 'a retry after a lost package id does not create another package');
check(
    (project_row($db, $saveId)['provisioning_error'] ?? '') === 'Domain already exists on a 20i hosting package',
    'a retry after a lost package id records the collision'
);

$markId = insert_case($db, ['domain_name' => 'mark.example']);
[$markCreated, $markCreate] = fake_create([
    'ok' => true,
    'package_id' => '777003',
    'failure' => '',
    'status' => 200,
]);
$marked = webco_provision_hosting_project(
    $db,
    $markId,
    ['ok' => true, 'domains' => []],
    $markCreate,
    quiet_log(),
    null,
    static function (PDO $ignored, int $ignoredId, string $ignoredPackage): bool {
        return false;
    }
);
$markRow = project_row($db, $markId);
check($marked['outcome'] === 'id_stored' && $markRow['twentyi_package_id'] === '777003', 'the package id is kept when the provisioned update fails');
check($markRow['provisioning_status'] === 'in_progress', 'a failed provisioned update leaves the claim in progress');
$markListCalls = 0;
$finished = webco_provision_hosting_apply(
    $db,
    static function () use (&$markListCalls): array {
        $markListCalls++;

        return ['ok' => true, 'domains' => []];
    },
    $markCreate,
    quiet_log(),
    $markId
);
$finishedRow = project_row($db, $markId);
check($markListCalls === 0 && count($markCreated->calls) === 1, 'finishing a stored package does not call 20i again');
check(($finished['results'][0]['outcome'] ?? '') === 'provisioned', 'the stored package is marked provisioned on the next run');
check($finishedRow['provisioning_status'] === 'provisioned' && $finishedRow['provisioning_error'] === null, 'the next run completes the stored package');

$newDomainId = insert_case($db, ['domain_name' => 'new.example.com', 'domain_path' => 'new']);
$includedNewId = insert_case($db, ['domain_name' => 'brand-new.co.uk', 'domain_path' => 'new']);
$testModeId = insert_case($db, ['domain_name' => 'test.example', 'stripe_livemode' => 0]);
$otherTestId = insert_case($db, ['domain_name' => 'other-test.example', 'stripe_livemode' => 0]);
$candidates = webco_provision_hosting_candidate_ids($db);
check(
    is_array($candidates)
        && in_array($includedNewId, $candidates, true)
        && in_array($newDomainId, $candidates, true)
        && !in_array($testModeId, $candidates, true),
    'included and other new domains are candidates; test-mode orders are not'
);
$paidCom = webco_provision_hosting_project(
    $db,
    $newDomainId,
    ['ok' => true, 'domains' => []],
    fake_create(['ok' => true, 'package_id' => '1', 'failure' => '', 'status' => 200])[1],
    quiet_log()
);
check($paidCom['outcome'] === 'failed', 'a new .com domain is refused');
check(
    (project_row($db, $newDomainId)['provisioning_error'] ?? '') === 'Only .uk and .co.uk domains are included with hosting',
    'a new .com domain explains the included TLD rule'
);

$registerCalls = [];
[$includedCreated, $includedCreate] = fake_create([
    'ok' => true,
    'package_id' => '900100',
    'failure' => '',
    'status' => 200,
]);
$included = webco_provision_hosting_project(
    $db,
    $includedNewId,
    ['ok' => true, 'domains' => []],
    $includedCreate,
    quiet_log(),
    null,
    null,
    false,
    static function (string $domain, array $order) use (&$registerCalls): array {
        $registerCalls[] = ['domain' => $domain, 'order' => $order];

        return ['ok' => true, 'failure' => '', 'status' => 200];
    },
    static function (string $domain): string {
        return $domain === 'brand-new.co.uk' ? 'available' : 'error';
    },
    static function (): array {
        return ['ok' => true, 'domains' => []];
    }
);
check($included['outcome'] === 'provisioned', 'an included new domain is provisioned');
check(count($registerCalls) === 1 && ($registerCalls[0]['domain'] ?? '') === 'brand-new.co.uk', 'an included new domain is registered once');
check(count($includedCreated->calls) === 1, 'hosting is created after registration');
check((project_row($db, $includedNewId)['domain_registered_at'] ?? null) !== null, 'domain_registered_at is stored');

$alreadyId = insert_case(
    $db,
    ['domain_name' => 'already.uk', 'domain_path' => 'new'],
    ['domain_registered_at' => '2026-10-05 12:00:00']
);
$alreadyRegister = [];
[$alreadyCreated, $alreadyCreate] = fake_create([
    'ok' => true,
    'package_id' => '900101',
    'failure' => '',
    'status' => 200,
]);
$already = webco_provision_hosting_project(
    $db,
    $alreadyId,
    ['ok' => true, 'domains' => []],
    $alreadyCreate,
    quiet_log(),
    null,
    null,
    false,
    static function (string $domain, array $order) use (&$alreadyRegister): array {
        $alreadyRegister[] = $domain;

        return ['ok' => true, 'failure' => '', 'status' => 200];
    },
    static function (): string {
        return 'available';
    },
    static function (): array {
        return ['ok' => true, 'domains' => []];
    }
);
check($already['outcome'] === 'provisioned' && $alreadyRegister === [], 'a previously registered domain skips addDomain');
check(count($alreadyCreated->calls) === 1, 'a previously registered domain still creates hosting');

$unownedId = insert_case($db, ['domain_name' => 'taken-now.co.uk', 'domain_path' => 'new']);
[$unownedCreated, $unownedCreate] = fake_create([
    'ok' => true,
    'package_id' => '1',
    'failure' => '',
    'status' => 200,
]);
$unowned = webco_provision_hosting_project(
    $db,
    $unownedId,
    ['ok' => true, 'domains' => []],
    $unownedCreate,
    quiet_log(),
    null,
    null,
    false,
    static function (): array {
        return ['ok' => true, 'failure' => '', 'status' => 200];
    },
    static function (): string {
        return 'unavailable';
    },
    static function (): array {
        return ['ok' => true, 'domains' => []];
    }
);
check($unownedCreated->calls === [] && $unowned['outcome'] === 'failed', 'an unavailable new domain does not create hosting');
check(
    (project_row($db, $unownedId)['provisioning_error'] ?? '') === 'Domain is no longer available to register',
    'an unavailable new domain stores a clear error'
);

$existingNeverRegisters = [];
$existingId = insert_case($db, ['domain_name' => 'keep.example', 'domain_path' => 'existing']);
[$existingCreated, $existingCreate] = fake_create([
    'ok' => true,
    'package_id' => '900102',
    'failure' => '',
    'status' => 200,
]);
webco_provision_hosting_project(
    $db,
    $existingId,
    ['ok' => true, 'domains' => []],
    $existingCreate,
    quiet_log(),
    null,
    null,
    false,
    static function () use (&$existingNeverRegisters): array {
        $existingNeverRegisters[] = true;

        return ['ok' => true, 'failure' => '', 'status' => 200];
    }
);
check($existingNeverRegisters === [] && count($existingCreated->calls) === 1, 'an existing domain never calls register');

$defaultPreview = webco_provision_hosting_preview($db, $testModeId);
check(($defaultPreview[0]['action'] ?? '') === 'not_eligible', 'a test order stays ineligible without the override');
check(
    !str_contains(webco_provision_hosting_preview_text(is_array($defaultPreview) ? $defaultPreview : []), 'test_order_override'),
    'default preview does not announce a test override'
);
$bulkCandidates = webco_provision_hosting_candidate_ids($db, null, true);
check(
    is_array($bulkCandidates) && !in_array($testModeId, $bulkCandidates, true) && !in_array($otherTestId, $bulkCandidates, true),
    'asking for every project still excludes test orders'
);
$outsideCli = webco_provision_hosting_candidate_ids($db, $testModeId, true);
check(is_array($outsideCli) && $outsideCli === [], 'a script other than the hosting CLI cannot select a test order');

$previousScript = $_SERVER['SCRIPT_FILENAME'] ?? '';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/bin/provision-hosting.php';
$allowed = webco_provision_hosting_candidate_ids($db, $testModeId, true);
check($allowed === [$testModeId], 'the hosting CLI can select one named test project');
$allowedPreview = webco_provision_hosting_preview($db, $testModeId, true);
$allowedText = webco_provision_hosting_preview_text(is_array($allowedPreview) ? $allowedPreview : [], true);
check(($allowedPreview[0]['action'] ?? '') === 'create', 'the named test project can be previewed');
check(str_contains($allowedText, "test_order_override: active\n"), 'preview says the test-mode override is active');
check(project_row($db, $testModeId)['provisioning_status'] === 'ready', 'previewing a test project does not claim it');
check(!in_array($otherTestId, $allowed, true), 'the override does not include a second test project');

$previousCwd = getcwd();
check($previousCwd !== false, 'current working directory is available for relative CLI checks');
$newUkTestId = insert_case($db, [
    'domain_name' => 'i-really-hate-terminals.co.uk',
    'domain_path' => 'new',
    'package_code' => 'professional',
    'package_name' => 'Webco Professional',
    'stripe_livemode' => 0,
]);
$unsupportedTestId = insert_case($db, [
    'domain_name' => 'paid-new.example.com',
    'domain_path' => 'new',
    'stripe_livemode' => 0,
]);
if ($previousCwd !== false) {
    chdir(dirname(__DIR__));
    $_SERVER['SCRIPT_FILENAME'] = 'bin/provision-hosting.php';
    check(
        webco_provision_hosting_test_override(true, $newUkTestId) === true,
        'a relative bin/provision-hosting.php invocation enables the test override'
    );
    $relativeCandidates = webco_provision_hosting_candidate_ids($db, $newUkTestId, true);
    check($relativeCandidates === [$newUkTestId], 'relative CLI can select a paid ready new .co.uk test project');
    $relativePreview = webco_provision_hosting_preview($db, $newUkTestId, true);
    check(
        ($relativePreview[0]['action'] ?? '') === 'create'
            && ($relativePreview[0]['domain_name'] ?? '') === 'i-really-hate-terminals.co.uk',
        'relative CLI previews an eligible new .co.uk test project as create'
    );
    $bulkRelative = webco_provision_hosting_candidate_ids($db, null, true);
    check(
        is_array($bulkRelative) && !in_array($newUkTestId, $bulkRelative, true),
        'relative CLI still cannot bulk-select test orders'
    );
    $liveOnly = webco_provision_hosting_candidate_ids($db, $newUkTestId, false);
    check($liveOnly === [], 'without --allow-test-order a test project stays ineligible');
    chdir($previousCwd);
}
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/bin/provision-hosting.php';

[$unsupportedCreated, $unsupportedCreate] = fake_create([
    'ok' => true,
    'package_id' => '1',
    'failure' => '',
    'status' => 200,
]);
$unsupported = webco_provision_hosting_project(
    $db,
    $unsupportedTestId,
    ['ok' => true, 'domains' => []],
    $unsupportedCreate,
    quiet_log(),
    null,
    null,
    true,
    static function (): array {
        return ['ok' => true, 'failure' => '', 'status' => 200];
    },
    static function (): string {
        return 'available';
    },
    static function (): array {
        return ['ok' => true, 'domains' => []];
    }
);
check($unsupportedCreated->calls === [] && $unsupported['outcome'] === 'failed', 'a test-mode new .com domain is still refused');
check(
    (project_row($db, $unsupportedTestId)['provisioning_error'] ?? '') === 'Only .uk and .co.uk domains are included with hosting',
    'a test-mode new .com domain explains the included TLD rule'
);

[$testCollisionCreated, $testCollisionCreate] = fake_create([
    'ok' => true,
    'package_id' => '1',
    'failure' => '',
    'status' => 200,
]);
$testCollisionId = insert_case($db, ['domain_name' => 'taken.example', 'stripe_livemode' => 0]);
$testCollision = webco_provision_hosting_apply(
    $db,
    static function (): array {
        return ['ok' => true, 'domains' => ['taken.example']];
    },
    $testCollisionCreate,
    quiet_log(),
    $testCollisionId,
    true
);
check($testCollisionCreated->calls === [], 'a test-mode collision is not created');
check(($testCollision['test_order_override'] ?? '') === 'active', 'apply says the test-mode override is active');
check(
    (project_row($db, $testCollisionId)['provisioning_error'] ?? '') === 'Domain already exists on a 20i hosting package',
    'a test-mode collision stores the same collision error'
);

$storedTestId = insert_case(
    $db,
    ['domain_name' => 'stored-test.example', 'stripe_livemode' => 0],
    ['twentyi_package_id' => '515151']
);
[$storedTestCreated, $storedTestCreate] = fake_create([
    'ok' => true,
    'package_id' => '515152',
    'failure' => '',
    'status' => 200,
]);
$storedTest = webco_provision_hosting_apply($db, static function (): array {
    return ['ok' => true, 'domains' => []];
}, $storedTestCreate, quiet_log(), $storedTestId, true);
check($storedTestCreated->calls === [], 'a stored test-mode package id is not created again');
check(project_row($db, $storedTestId)['twentyi_package_id'] === '515151', 'the stored test-mode package id is unchanged');
check(project_row($db, $storedTestId)['provisioning_status'] === 'provisioned', 'a stored test-mode package id can be finished');
$_SERVER['SCRIPT_FILENAME'] = $previousScript;

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
