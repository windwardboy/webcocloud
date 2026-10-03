<?php
/**
 * Guided website brief. Autosave must not submit, and support requests stay separate.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/brief.php';
require dirname(__DIR__) . '/public/brief-upload.php';

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

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-wizard-' . getmypid() . '.sqlite';
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
        years_operating TEXT,
        credentials TEXT,
        first_impression TEXT,
        services TEXT,
        course_entries TEXT,
        locations TEXT,
        areas_served TEXT,
        location_entries TEXT,
        goals TEXT,
        why_experience TEXT,
        why_facilities TEXT,
        why_flexibility TEXT,
        why_support TEXT,
        why_difference TEXT,
        style_tone TEXT,
        branding TEXT,
        liked_sites TEXT,
        required_pages TEXT,
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

$db->exec(
    "INSERT INTO orders (id, business_name, phone, package_code, package_name, domain_name)
     VALUES (1, 'Kent Training', '01634 000111', 'essential', 'Webco Essential', 'kent.example')"
);
$db->exec(
    "INSERT INTO projects (id, order_id, order_public_id, status, updated_at)
     VALUES (1, 1, 'wc_wizardessential', 'awaiting_brief', '2020-01-01 00:00:00')"
);
$db->exec(
    "INSERT INTO project_briefs (project_id, summary, goals, style_tone)
     VALUES (1, 'Please keep the old note.', 'Get phone calls.', 'Plain and local.')"
);

$steps = [
    'business' => [
        'business_overview' => 'We train HGV drivers.',
        'years_operating' => '12 years',
        'credentials' => 'DVSA registered instructors.',
        'first_impression' => 'Practical training from one base.',
        'wizard_step' => 'courses',
    ],
    'courses' => [
        'services' => 'Category C and Driver CPC.',
        'wizard_step' => 'locations',
    ],
    'locations' => [
        'locations' => 'Example Town yard.',
        'areas_served' => 'Medway and Maidstone.',
        'wizard_step' => 'why',
    ],
    'why' => [
        'why_experience' => 'Most learners come from vans.',
        'why_facilities' => 'One rigid and a small yard.',
        'why_flexibility' => 'Weekday mornings.',
        'why_support' => 'We answer the phone.',
        'why_difference' => 'One base, no handover.',
        'wizard_step' => 'branding',
    ],
    'branding' => [
        'branding' => 'Dark green, already on the lorries.',
        'wizard_step' => 'contact',
    ],
    'contact' => [
        'enquiry_route' => 'phone',
        'contact_details' => '01634 000111',
        'opening_hours' => 'Weekdays 8 to 5',
        'call_requested' => 'yes',
        'call_number' => '07700 900123',
        'call_time' => 'Tuesday morning',
        'call_note' => 'Talk about the yard photo.',
        'wizard_step' => 'other',
    ],
    'other' => [
        'summary' => 'Please keep the old note.',
        'wizard_step' => 'review',
    ],
];

foreach ($steps as $name => $fields) {
    check(webco_save_customer_brief($db, 1, $fields, false) === 'saved', "autosave keeps the {$name} step");
    check(status_of($db, 1) === 'brief_in_progress', "the {$name} step does not submit the brief");
    check(submitted_at_of($db, 1) === '', "the {$name} step does not record submission");
}

$row = brief_row($db, 1);
check($row['business_overview'] === 'We train HGV drivers.', 'business answers survive later steps');
check($row['services'] === 'Category C and Driver CPC.', 'course answers survive later steps');
check($row['locations'] === 'Example Town yard.', 'location answers survive later steps');
check($row['why_experience'] === 'Most learners come from vans.', 'differentiator answers survive later steps');
check($row['branding'] === 'Dark green, already on the lorries.', 'branding answers survive later steps');
check((int) $row['call_requested'] === 1, 'a yes phone call is stored');
check($row['call_number'] === '07700 900123', 'the call number is stored');
check($row['summary'] === 'Please keep the old note.', 'the original note is kept');
check($row['style_tone'] === 'Plain and local.', 'an old style note is not wiped by the wizard');
check($row['wizard_step'] === 'review', 'the saved step is the review step');

$resumed = webco_customer_project($db, 1);
check(is_array($resumed) && $resumed['package_code'] === 'essential', 'a resumed brief knows it is Essential');
check(is_array($resumed) && $resumed['services'] === 'Category C and Driver CPC.', 'a resumed brief still has the courses');
check(is_array($resumed) && $resumed['phone'] === '01634 000111', 'the order phone is still available');
check(is_array($resumed) && $resumed['wizard_step'] === 'review', 'resume opens from the saved step');
check(is_array($resumed) && $resumed['domain_name'] === 'kent.example', 'a resumed brief includes the domain');

check(webco_save_customer_brief($db, 1, ['wizard_step' => 'branding', 'branding' => 'Dark green, already on the lorries.'], false) === 'saved', 'going back autosaves');
check(status_of($db, 1) === 'brief_in_progress', 'going back does not change the build status');
check((int) brief_row($db, 1)['call_requested'] === 1, 'going back does not clear the phone call');
check(webco_save_customer_brief($db, 1, [
    'call_requested' => 'no',
    'call_number' => '07700 900123',
    'call_time' => 'Tuesday morning',
    'call_note' => 'Talk about the yard photo.',
    'wizard_step' => 'other',
], false) === 'saved', 'a no phone call can be saved');
check((int) brief_row($db, 1)['call_requested'] === 0, 'the phone call is no longer requested');
check(brief_row($db, 1)['call_number'] === '07700 900123', 'the number stays available if they choose yes again');

$db->exec("UPDATE projects SET status = 'ready_for_clone', updated_at = '2020-01-01 00:00:00' WHERE id = 1");
check(webco_save_customer_brief($db, 1, ['wizard_step' => 'review'], false) === 'saved', 'a later autosave is accepted');
check(status_of($db, 1) === 'ready_for_clone', 'autosave does not move a project backwards');

$beforeSubmit = '2026-02-02 09:00:00';
$db->prepare('UPDATE project_briefs SET submitted_at = :at WHERE project_id = 1')->execute(['at' => $beforeSubmit]);
$db->exec("UPDATE projects SET status = 'live' WHERE id = 1");
check(webco_save_customer_brief($db, 1, [], true) === 'submitted', 'submit still reports submitted');
check(status_of($db, 1) === 'live', 'submit does not move a live project');
check(submitted_at_of($db, 1) === $beforeSubmit, 'a second submit does not replace the first submission time');

$db->exec("UPDATE projects SET status = 'brief_in_progress' WHERE id = 1");
$db->exec('UPDATE project_briefs SET submitted_at = NULL WHERE project_id = 1');
check(webco_save_customer_brief($db, 1, [], true) === 'submitted', 'the review step can submit');
check(status_of($db, 1) === 'brief_received', 'submit marks the brief received');
$stamped = submitted_at_of($db, 1);
check($stamped !== '', 'submit records the time once');
check(webco_save_customer_brief($db, 1, [], true) === 'submitted', 'submitting again is accepted');
check(status_of($db, 1) === 'brief_received', 'submitting again does not change the build status');
check(submitted_at_of($db, 1) === $stamped, 'submitting again keeps the original time');

$requestId = webco_create_project_request($db, 1, 'support_question', 'Can you change the phone number?');
check($requestId > 0, 'a support request can still be created');
check(status_of($db, 1) === 'brief_received', 'a support request does not change the build status');
check(webco_advance_request_status($db, $requestId), 'a support request can still move forward');
check(status_of($db, 1) === 'brief_received', 'moving a support request leaves the build status');

check(webco_save_project_asset_row($db, 1, 'logo', str_repeat('a', 32) . '.jpg', 'old-logo.jpg', 'image/jpeg', 100, null), 'an existing brief file can still be stored');
$assets = webco_project_assets($db, 1);
check(($assets['logo'][0]['original_name'] ?? '') === 'old-logo.jpg', 'an existing brief file is still listed');
check(array_key_exists('request_id', $assets['logo'][0]) && $assets['logo'][0]['request_id'] === null, 'an existing brief file has no request owner');

$db->exec(
    "INSERT INTO orders (id, business_name, phone, package_code, package_name)
     VALUES (2, 'South West Training', '01634 000222', 'professional', 'Webco Professional')"
);
$db->exec(
    "INSERT INTO projects (id, order_id, order_public_id, status, updated_at)
     VALUES (2, 2, 'wc_wizardprofessional', 'awaiting_brief', '2020-01-01 00:00:00')"
);
$db->exec('INSERT INTO project_briefs (project_id, services) VALUES (2, \'Old course list.\')');
$_POST = [
    'course_name' => ['Category C', 'Driver CPC', ''],
    'course_detail' => ['Rigid lorries.', 'Periodic hours.', ''],
];
$professionalCourses = webco_brief_posted_fields('courses');
check(webco_save_customer_brief($db, 2, $professionalCourses, false) === 'saved', 'professional course rows can be saved');
check(status_of($db, 2) === 'brief_in_progress', 'professional course autosave does not submit');
$storedCourses = webco_brief_pairs((string) brief_row($db, 2)['course_entries']);
check(count($storedCourses) === 2, 'empty professional course rows are dropped');
check(($storedCourses[0]['name'] ?? '') === 'Category C', 'the first professional course is kept');
check(str_contains((string) brief_row($db, 2)['services'], 'Category C'), 'professional courses also remain readable as services');

check(webco_brief_course_mode('essential') === 'single', 'Essential uses one course list');
check(webco_brief_course_mode('professional') === 'multiple', 'Professional uses course rows');
check(webco_brief_location_mode('essential') === 'single', 'Essential uses one location');
check(webco_brief_location_mode('professional') === 'multiple', 'Professional uses more than one location');

$essentialPage = wizard_html([
    'package_code' => 'essential',
    'package_name' => 'Webco Essential',
    'business_name' => 'Kent Training',
    'order_public_id' => 'wc_wizardessential',
    'status' => 'brief_in_progress',
    'phone' => '01634 000111',
    'goals' => 'Get phone calls.',
    'assets' => ['logo' => [], 'photo' => [], 'document' => []],
], 'courses');
check(str_contains($essentialPage, 'core pages'), 'Essential course copy stays inside the package');
check(!str_contains($essentialPage, 'name="course_name[]"'), 'Essential does not ask for separate course pages');
$professionalPage = wizard_html([
    'package_code' => 'professional',
    'package_name' => 'Webco Professional',
    'business_name' => 'South West Training',
    'order_public_id' => 'wc_wizardprofessional',
    'status' => 'brief_in_progress',
    'services' => 'Old course list.',
    'assets' => ['logo' => [], 'photo' => [], 'document' => []],
], 'courses');
check(str_contains($professionalPage, 'name="course_name[]"'), 'Professional asks for each course');
check(str_contains($professionalPage, 'Old course list.'), 'an older course note is shown in the professional rows');
check(str_contains($professionalPage, 'its own page'), 'Professional copy matches course pages');
check(substr_count($professionalPage, 'data-pair-name>') === 1, 'Professional starts with one course row');
check(substr_count($professionalPage, 'data-pair-name disabled>') === 5, 'the other course rows stay hidden');
check(str_contains($professionalPage, 'data-add>+ Add another course'), 'Professional can add another course');
check(!str_contains($professionalPage, '>Remove</button>') || substr_count($professionalPage, '>Remove</button>') === substr_count($professionalPage, ' disabled>Remove</button>'), 'the first course row cannot be removed');
check(str_contains($professionalPage, 'data-limit hidden'), 'the course limit stays hidden until six courses are open');
check(!str_contains($essentialPage, 'Add another course'), 'Essential does not gain course rows');

$savedCourses = wizard_html([
    'package_code' => 'professional',
    'package_name' => 'Webco Professional',
    'business_name' => 'South West Training',
    'order_public_id' => 'wc_wizardprofessional',
    'status' => 'brief_in_progress',
    'course_entries' => json_encode([
        ['name' => 'Category C', 'detail' => 'Rigid lorries.'],
        ['name' => 'Driver CPC', 'detail' => 'Periodic hours.'],
    ], JSON_UNESCAPED_UNICODE),
    'assets' => ['logo' => [], 'photo' => [], 'document' => []],
], 'courses');
check(str_contains($savedCourses, 'value="Category C"'), 'a saved course name is shown again');
check(str_contains($savedCourses, 'value="Driver CPC"'), 'a second saved course is shown again');
check(substr_count($savedCourses, 'data-pair-name>') === 2, 'saved courses open as separate rows');
check(substr_count($savedCourses, '>Remove</button>') - substr_count($savedCourses, ' disabled>Remove</button>') === 1, 'only an added course can be removed');

$fullCourses = [];
for ($courseNumber = 1; $courseNumber <= 6; $courseNumber++) {
    $fullCourses[] = ['name' => 'Course ' . (string) $courseNumber, 'detail' => 'Details'];
}
$fullCoursePage = wizard_html([
    'package_code' => 'professional',
    'package_name' => 'Webco Professional',
    'business_name' => 'South West Training',
    'order_public_id' => 'wc_wizardprofessional',
    'status' => 'brief_in_progress',
    'course_entries' => json_encode($fullCourses, JSON_UNESCAPED_UNICODE),
    'assets' => ['logo' => [], 'photo' => [], 'document' => []],
], 'courses');
check(str_contains($fullCoursePage, 'data-add disabled'), 'the sixth course hides the add action');
check(str_contains($fullCoursePage, 'type="button"'), 'adding or removing a row does not submit the brief');

$emptyLocations = wizard_html([
    'package_code' => 'professional',
    'package_name' => 'Webco Professional',
    'business_name' => 'South West Training',
    'order_public_id' => 'wc_wizardprofessional',
    'status' => 'brief_in_progress',
    'assets' => ['logo' => [], 'photo' => [], 'document' => []],
], 'locations');
check(str_contains($emptyLocations, 'name="locations"'), 'Professional still asks for the main location');
check(substr_count($emptyLocations, 'data-pair-name>') === 0, 'further locations stay hidden until requested');
check(str_contains($emptyLocations, 'data-add>+ Add another location'), 'Professional can add another location');
$essentialLocations = wizard_html([
    'package_code' => 'essential',
    'package_name' => 'Webco Essential',
    'business_name' => 'Kent Training',
    'order_public_id' => 'wc_wizardessential',
    'status' => 'brief_in_progress',
    'assets' => ['logo' => [], 'photo' => [], 'document' => []],
], 'locations');
check(!str_contains($essentialLocations, 'location_name[]'), 'Essential does not gain location rows');
check(!str_contains($essentialLocations, 'Add another location'), 'Essential does not offer extra locations');

$savedLocations = wizard_html([
    'package_code' => 'professional',
    'package_name' => 'Webco Professional',
    'business_name' => 'South West Training',
    'order_public_id' => 'wc_wizardprofessional',
    'status' => 'brief_in_progress',
    'location_entries' => json_encode([
        ['name' => 'Taunton', 'detail' => 'Second yard.'],
        ['name' => 'Exeter', 'detail' => 'Third yard.'],
    ], JSON_UNESCAPED_UNICODE),
    'assets' => ['logo' => [], 'photo' => [], 'document' => []],
], 'locations');
check(str_contains($savedLocations, 'value="Taunton"'), 'a saved further location is shown again');
check(str_contains($savedLocations, 'value="Exeter"'), 'a second saved location is shown again');
check(substr_count($savedLocations, '>Remove</button>') - substr_count($savedLocations, ' disabled>Remove</button>') === 2, 'further locations can be removed');

$_POST = [
    'locations' => 'Bristol',
    'areas_served' => 'Somerset',
    'location_name' => ['Taunton', ''],
    'location_detail' => ['Second yard.', ''],
];
check(webco_save_customer_brief($db, 2, webco_brief_posted_fields('locations'), false) === 'saved', 'a further location can be saved');
check(count(webco_brief_pairs((string) brief_row($db, 2)['location_entries'])) === 1, 'an empty location row is not stored');
$_POST = [
    'locations' => 'Bristol',
    'areas_served' => 'Somerset',
    'location_name' => [''],
    'location_detail' => [''],
];
check(webco_save_customer_brief($db, 2, webco_brief_posted_fields('locations'), false) === 'saved', 'removing every further location can be saved');
check((string) brief_row($db, 2)['location_entries'] === '', 'removed locations are cleared');
check((string) brief_row($db, 2)['locations'] === 'Bristol', 'the main location stays when further locations are removed');
check(status_of($db, 2) === 'brief_in_progress', 'location autosave does not submit');

$contactPage = wizard_html([
    'package_code' => 'essential',
    'business_name' => 'Kent Training',
    'order_public_id' => 'wc_wizardessential',
    'status' => 'brief_in_progress',
    'phone' => '01634 000111',
    'call_requested' => false,
    'assets' => ['logo' => [], 'photo' => [], 'document' => []],
], 'contact');
check(str_contains($contactPage, 'Would you like a phone call before I start your website?'), 'the call question is on the contact step');
check(str_contains($contactPage, 'class="call-extra"'), 'the call details are conditional');
check(str_contains($contactPage, 'value="01634 000111"'), 'the call number is prefilled from the order');
check(str_contains($contactPage, 'value="yes"'), 'yes is available');

$legacy = [
    'package_code' => 'essential',
    'goals' => 'Get phone calls.',
    'wizard_step' => '',
    'first_impression' => '',
    'call_requested' => false,
];
check(webco_brief_show_value($legacy, 'first_impression') === 'Get phone calls.', 'an old goals note appears in the opening message');
$review = webco_brief_review_sections($resumed ?? []);
$reviewText = implode("\n", array_map(static fn (array $line): string => $line['label'] . ' ' . $line['value'], $review));
check(str_contains($reviewText, 'We train HGV drivers.'), 'review includes the business');
check(str_contains($reviewText, 'Category C and Driver CPC.'), 'review includes the courses');
check(str_contains($reviewText, 'Example Town yard.'), 'review includes the location');
check(str_contains($reviewText, 'Phone call before the website starts'), 'review includes the call choice');

$brandingPage = wizard_html([
    'package_code' => 'essential',
    'business_name' => 'Kent Training',
    'order_public_id' => 'wc_wizardessential',
    'status' => 'brief_in_progress',
    'assets' => [
        'logo' => [['original_name' => 'old-logo.jpg', 'size_bytes' => 100, 'request_id' => null]],
        'photo' => [],
        'document' => [],
    ],
], 'branding');
check(str_contains($brandingPage, 'old-logo.jpg'), 'an existing logo is shown on the branding step');
check(str_contains($brandingPage, 'multiple'), 'photos and documents can be chosen together');
check(str_contains($brandingPage, 'Uploading'), 'choosing a file starts the upload');
check(!str_contains($brandingPage, '>Upload<'), 'there is no separate upload button');
$logoField = strpos($brandingPage, 'value="logo"');
$photoField = strpos($brandingPage, 'value="photo"');
$documentField = strpos($brandingPage, 'value="document"');
$briefFormEnds = strpos($brandingPage, '</form>');
check($briefFormEnds !== false && $logoField !== false && $briefFormEnds < $logoField, 'the logo upload is outside the brief form');
check($photoField !== false && $briefFormEnds < $photoField, 'the photo upload is outside the brief form');
check($documentField !== false && $briefFormEnds < $documentField, 'the document upload is outside the brief form');
check(substr_count($brandingPage, 'class="upload"') === 3, 'logo, photos and documents each upload on their own');
check(str_contains($brandingPage, 'form="brief-wizard"'), 'Back and Continue still submit the brief');
check(str_contains($brandingPage, 'upload-group'), 'a new file is added to its own category list');
$reviewFiles = wizard_html([
    'package_code' => 'essential',
    'business_name' => 'Kent Training',
    'order_public_id' => 'wc_wizardessential',
    'status' => 'brief_in_progress',
    'assets' => [
        'logo' => [['original_name' => 'old-logo.jpg', 'size_bytes' => 100, 'request_id' => null]],
        'photo' => [['original_name' => 'yard.jpg', 'size_bytes' => 200, 'request_id' => null]],
        'document' => [['original_name' => 'notes.pdf', 'size_bytes' => 300, 'request_id' => null]],
    ],
], 'review');
check(str_contains($reviewFiles, 'Uploaded files'), 'review has an uploaded files section');
check(str_contains($reviewFiles, 'old-logo.jpg'), 'review lists a saved logo');
check(str_contains($reviewFiles, 'yard.jpg'), 'review lists a saved photo');
check(str_contains($reviewFiles, 'notes.pdf'), 'review lists a saved document');
check(webco_brief_upload_wants_json() === false, 'a normal upload still redirects');
$_POST['ajax'] = '1';
check(webco_brief_upload_wants_json() === true, 'an automatic upload asks for a result message');
check(webco_brief_upload_message('upload_size') === 'That file is too large. The limit is 10 MB.', 'upload limits stay the same');
check(is_file(dirname(__DIR__) . '/public/images/brief/essential-courses.jpg'), 'the Essential course section image exists');
check(is_file(dirname(__DIR__) . '/public/images/brief/professional-location.jpg'), 'the Professional location section image exists');

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);

/**
 * @param array<string, mixed> $project
 */
function wizard_html(array $project, string $step): string
{
    ob_start();
    webco_brief_render_wizard($project, $step, '', '0123456789abcdef0123456789abcdef');
    $html = ob_get_clean();

    return is_string($html) ? $html : '';
}

function status_of(PDO $db, int $projectId): string
{
    $statement = $db->prepare('SELECT status FROM projects WHERE id = :id');
    $statement->execute(['id' => $projectId]);
    $row = $statement->fetch();

    return is_array($row) ? (string) ($row['status'] ?? '') : '';
}

function submitted_at_of(PDO $db, int $projectId): string
{
    $statement = $db->prepare('SELECT submitted_at FROM project_briefs WHERE project_id = :id');
    $statement->execute(['id' => $projectId]);
    $row = $statement->fetch();
    $value = is_array($row) ? ($row['submitted_at'] ?? null) : null;

    return is_string($value) ? $value : '';
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
