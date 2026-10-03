<?php
/**
 * Initial website brief and later client requests. SQLite only.
 * Support requests must not change the website build status.
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

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-requests-' . getmypid() . '.sqlite';
if (is_file($path)) {
    unlink($path);
}
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE orders (
        id INTEGER PRIMARY KEY,
        business_name TEXT,
        phone TEXT,
        package_code TEXT,
        package_name TEXT,
        domain_name TEXT
    )'
);
$db->exec(
    'CREATE TABLE projects (
        id INTEGER PRIMARY KEY,
        order_id INTEGER,
        order_public_id TEXT,
        status TEXT NOT NULL,
        updated_at TEXT
    )'
);
$db->exec(
    'CREATE TABLE project_briefs (
        project_id INTEGER PRIMARY KEY,
        summary TEXT,
        business_overview TEXT,
        services TEXT,
        locations TEXT,
        goals TEXT,
        style_tone TEXT,
        branding TEXT,
        liked_sites TEXT,
        required_pages TEXT,
        years_operating TEXT,
        credentials TEXT,
        first_impression TEXT,
        course_entries TEXT,
        areas_served TEXT,
        location_entries TEXT,
        why_experience TEXT,
        why_facilities TEXT,
        why_flexibility TEXT,
        why_support TEXT,
        why_difference TEXT,
        enquiry_route TEXT,
        contact_details TEXT,
        opening_hours TEXT,
        wizard_step TEXT,
        call_requested INTEGER NOT NULL DEFAULT 0,
        call_number TEXT,
        call_time TEXT,
        call_note TEXT,
        updated_at TEXT,
        submitted_at TEXT
    )'
);
$db->exec(
    'CREATE TABLE project_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        request_type TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'open\',
        summary TEXT NOT NULL,
        call_requested INTEGER NOT NULL DEFAULT 0,
        call_number TEXT,
        call_time TEXT,
        call_note TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT,
        completed_at TEXT
    )'
);
$db->exec(
    'CREATE TABLE project_assets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        category TEXT NOT NULL,
        storage_name TEXT NOT NULL,
        original_name TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        size_bytes INTEGER NOT NULL,
        request_id INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )'
);

$db->prepare('INSERT INTO orders (id, business_name, phone) VALUES (1, :name, :phone)')
    ->execute(['name' => 'Kent Training', 'phone' => '01634 000111']);
$db->prepare(
    'INSERT INTO projects (id, order_id, order_public_id, status, updated_at)
     VALUES (1, 1, :public_id, :status, :updated_at)'
)->execute([
    'public_id' => 'wc_testbrief',
    'status' => 'awaiting_brief',
    'updated_at' => '2020-01-01 00:00:00',
]);
$db->exec('INSERT INTO project_briefs (project_id, summary) VALUES (1, \'Please keep the old note.\')');

$saved = webco_save_customer_brief($db, 1, brief_fields([
    'summary' => 'Please keep the old note.',
    'business_overview' => 'We train HGV drivers.',
    'services' => 'Class 1 and Class 2 courses.',
    'locations' => 'Medway and Maidstone.',
    'goals' => 'Enquiries and phone calls.',
    'style_tone' => 'Straightforward and local.',
    'branding' => 'Deep green and cream.',
    'liked_sites' => 'Like example.co.uk because it is plain.',
    'required_pages' => 'Courses, locations, contact.',
    'call_requested' => 'yes',
    'call_number' => '07700 900123',
    'call_time' => 'Weekday mornings',
    'call_note' => 'Ask for Sam.',
]), false);
check($saved === 'saved', 'an expanded brief can be saved');
check(status_of($db, 1) === 'brief_in_progress', 'saving progress moves a waiting brief into progress');

$brief = brief_row($db, 1);
check($brief['summary'] === 'Please keep the old note.', 'the original note is kept');
check($brief['business_overview'] === 'We train HGV drivers.', 'the business overview is stored');
check($brief['services'] === 'Class 1 and Class 2 courses.', 'the services are stored');
check($brief['locations'] === 'Medway and Maidstone.', 'the locations are stored');
check($brief['goals'] === 'Enquiries and phone calls.', 'the website goals are stored');
check($brief['style_tone'] === 'Straightforward and local.', 'the style is stored');
check($brief['branding'] === 'Deep green and cream.', 'the branding is stored');
check($brief['liked_sites'] === 'Like example.co.uk because it is plain.', 'liked and disliked sites are stored');
check($brief['required_pages'] === 'Courses, locations, contact.', 'required pages are stored');
check((int) $brief['call_requested'] === 1, 'a requested phone call is stored');
check($brief['call_number'] === '07700 900123', 'the preferred call number is stored');
check($brief['call_time'] === 'Weekday mornings', 'the preferred call time is stored');
check($brief['call_note'] === 'Ask for Sam.', 'the call note is stored');
check($brief['submitted_at'] === null || $brief['submitted_at'] === '', 'a saved brief is not submitted');

$customer = webco_customer_project($db, 1);
check(is_array($customer) && $customer['phone'] === '01634 000111', 'the order phone is available to prefill a call');
check(is_array($customer) && $customer['call_requested'] === true, 'the customer view shows the requested call');

$submitted = webco_save_customer_brief($db, 1, brief_fields([
    'summary' => 'Please keep the old note.',
    'business_overview' => 'We train HGV drivers.',
    'call_requested' => 'yes',
    'call_number' => '07700 900123',
    'call_time' => 'Weekday mornings',
    'call_note' => 'Ask for Sam.',
]), true);
check($submitted === 'submitted', 'an expanded brief can be submitted');
check(status_of($db, 1) === 'brief_received', 'submitting the brief marks it received');
$submittedAt = (string) (brief_row($db, 1)['submitted_at'] ?? '');
check($submittedAt !== '', 'submission records a time');

$db->prepare('UPDATE project_briefs SET submitted_at = :at WHERE project_id = 1')
    ->execute(['at' => '2026-01-01 09:00:00']);
$again = webco_save_customer_brief($db, 1, brief_fields([
    'summary' => 'Please keep the old note.',
    'business_overview' => 'We train HGV drivers.',
]), true);
check($again === 'submitted', 'a later submit is accepted');
check(status_of($db, 1) === 'brief_received', 'a later submit does not move the build status');
check((string) (brief_row($db, 1)['submitted_at'] ?? '') === '2026-01-01 09:00:00', 'a later submit does not replace the first submission time');

$db->prepare('INSERT INTO orders (id, business_name, phone) VALUES (2, :name, :phone)')
    ->execute(['name' => 'Call Only', 'phone' => '01634 000222']);
$db->prepare(
    'INSERT INTO projects (id, order_id, order_public_id, status, updated_at)
     VALUES (2, 2, :public_id, :status, :updated_at)'
)->execute([
    'public_id' => 'wc_callonly',
    'status' => 'awaiting_brief',
    'updated_at' => '2020-01-01 00:00:00',
]);
$db->exec('INSERT INTO project_briefs (project_id) VALUES (2)');
check(webco_save_customer_brief($db, 2, brief_fields([
    'call_requested' => 'yes',
    'call_number' => '07700 900222',
]), true) === 'submitted', 'a call-only brief can still be submitted');
check(!webco_advance_project_status($db, 2), 'a call preference alone cannot open the build');
check(status_of($db, 2) === 'brief_received', 'a refused build step leaves the brief received');

$db->prepare('INSERT INTO orders (id, business_name, phone) VALUES (3, :name, :phone)')
    ->execute(['name' => 'Overview Only', 'phone' => '']);
$db->prepare(
    'INSERT INTO projects (id, order_id, order_public_id, status, updated_at)
     VALUES (3, 3, :public_id, :status, :updated_at)'
)->execute([
    'public_id' => 'wc_overview',
    'status' => 'awaiting_brief',
    'updated_at' => '2020-01-01 00:00:00',
]);
$db->exec('INSERT INTO project_briefs (project_id) VALUES (3)');
check(webco_save_customer_brief($db, 3, brief_fields([
    'business_overview' => 'Plant training across Kent.',
]), true) === 'submitted', 'an overview-only brief can be submitted');
check(webco_advance_project_status($db, 3), 'a submitted overview can open the build');
check(status_of($db, 3) === 'ready_for_clone', 'the overview brief reaches ready for clone');

$db->prepare('UPDATE projects SET status = :status, updated_at = :updated_at WHERE id = 3')
    ->execute(['status' => 'live', 'updated_at' => '2020-01-01 00:00:00']);
$first = webco_create_project_request($db, 3, 'content_change', 'Update the course prices.', [
    'call_requested' => 'yes',
    'call_number' => '07700 900333',
    'call_time' => 'Tuesday afternoon',
    'call_note' => 'Short call.',
]);
$second = webco_create_project_request($db, 3, 'technical_problem', 'The contact form is not arriving.');
check($first > 0 && $second > 0 && $first !== $second, 'a project can hold more than one request');
check(webco_create_project_request($db, 3, 'not_a_type', 'This should be rejected.') === 0, 'an unknown request type is rejected');
check(status_of($db, 3) === 'live', 'creating requests does not change the build status');
check(updated_at_of($db, 3) === '2020-01-01 00:00:00', 'creating requests does not rewrite the project');

$requests = webco_project_requests($db, 3);
check(count($requests) === 2, 'both requests are listed');
$byId = [];
foreach ($requests as $request) {
    $byId[(int) $request['id']] = $request;
}
check(($byId[$first]['status'] ?? '') === 'open', 'a new request starts open');
check(($byId[$first]['request_type'] ?? '') === 'content_change', 'the first request keeps its type');
check(($byId[$first]['call_requested'] ?? false) === true, 'a request can ask for a phone call');
check(($byId[$first]['call_number'] ?? '') === '07700 900333', 'the request stores the call number');
check(($byId[$second]['status'] ?? '') === 'open', 'the second request starts open');
check(($byId[$second]['call_requested'] ?? true) === false, 'a request can skip the phone call');

check(webco_advance_request_status($db, $first), 'an open request can move to in progress');
$afterProgress = request_row($db, $first);
check($afterProgress['status'] === 'in_progress', 'the request is in progress');
check($afterProgress['completed_at'] === null || $afterProgress['completed_at'] === '', 'in progress does not complete the request');
check(status_of($db, 3) === 'live', 'moving a request to in progress leaves the build status');

check(webco_advance_request_status($db, $first), 'an in-progress request can be marked done');
$afterDone = request_row($db, $first);
check($afterDone['status'] === 'done', 'the request is done');
check(is_string($afterDone['completed_at']) && $afterDone['completed_at'] !== '', 'a finished request records a completion time');
check(!webco_advance_request_status($db, $first), 'a finished request has no further step');
check(request_row($db, $second)['status'] === 'open', 'finishing one request leaves the other open');
check(status_of($db, 3) === 'live', 'finishing a request leaves the build status');
check(updated_at_of($db, 3) === '2020-01-01 00:00:00', 'request status changes do not rewrite the project');

check(webco_asset_request_for_project($db, 3, null) === null, 'a brief file has no request owner');
check(webco_asset_request_for_project($db, 3, $first) === $first, 'a file can belong to this project request');
check(webco_asset_request_for_project($db, 1, $first) === false, 'another project cannot use this request');

$briefStorage = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg';
$requestStorage = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png';
check(webco_save_project_asset_row(
    $db,
    3,
    'logo',
    $briefStorage,
    'logo.jpg',
    'image/jpeg',
    1200,
    null
), 'an initial brief file can be stored without a request');
check(webco_save_project_asset_row(
    $db,
    3,
    'photo',
    $requestStorage,
    'yard.png',
    'image/png',
    2400,
    $first
), 'a file can be stored against one request');
check(!webco_save_project_asset_row(
    $db,
    3,
    'photo',
    'cccccccccccccccccccccccccccccccc.webp',
    'nope.webp',
    'image/webp',
    100,
    false
), 'an unowned request cannot store a file');

$assets = $db->query('SELECT storage_name, request_id FROM project_assets ORDER BY id')->fetchAll();
check(count($assets) === 2, 'only the two owned files were stored');
check((string) ($assets[0]['storage_name'] ?? '') === $briefStorage && ($assets[0]['request_id'] ?? null) === null, 'the brief file keeps an empty request id');
check((string) ($assets[1]['storage_name'] ?? '') === $requestStorage && (int) ($assets[1]['request_id'] ?? 0) === $first, 'the request file stores that request id');

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function brief_fields(array $overrides): array
{
    $fields = [
        'summary' => '',
        'business_overview' => '',
        'services' => '',
        'locations' => '',
        'goals' => '',
        'style_tone' => '',
        'branding' => '',
        'liked_sites' => '',
        'required_pages' => '',
        'call_requested' => 'no',
        'call_number' => '',
        'call_time' => '',
        'call_note' => '',
    ];

    return $overrides + $fields;
}

function status_of(PDO $db, int $projectId): string
{
    $statement = $db->prepare('SELECT status FROM projects WHERE id = :id');
    $statement->execute(['id' => $projectId]);
    $row = $statement->fetch();

    return is_array($row) ? (string) ($row['status'] ?? '') : '';
}

function updated_at_of(PDO $db, int $projectId): string
{
    $statement = $db->prepare('SELECT updated_at FROM projects WHERE id = :id');
    $statement->execute(['id' => $projectId]);
    $row = $statement->fetch();

    return is_array($row) ? (string) ($row['updated_at'] ?? '') : '';
}

/**
 * @return array<string, mixed>
 */
function brief_row(PDO $db, int $projectId): array
{
    $statement = $db->prepare('SELECT * FROM project_briefs WHERE project_id = :id');
    $statement->execute(['id' => $projectId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : [];
}

/**
 * @return array<string, mixed>
 */
function request_row(PDO $db, int $requestId): array
{
    $statement = $db->prepare('SELECT * FROM project_requests WHERE id = :id');
    $statement->execute(['id' => $requestId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : [];
}
