<?php
/**
 * Package-aware website brief wizard. It reads and writes project_briefs only.
 */

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * @return array<string, string>
 */
function webco_brief_wizard_labels(string $package): array
{
    $courses = $package === 'professional' ? 'Your courses' : 'Your courses';
    $locations = $package === 'professional' ? 'Your locations' : 'Your location';

    return [
        'business' => 'Your business',
        'courses' => $courses,
        'locations' => $locations,
        'why' => 'Why customers should choose you',
        'branding' => 'Branding and images',
        'contact' => 'Contact preferences',
        'other' => 'Anything else',
        'review' => 'Review and submit',
    ];
}

function webco_brief_course_mode(string $package): string
{
    return $package === 'professional' ? 'multiple' : 'single';
}

function webco_brief_location_mode(string $package): string
{
    return $package === 'professional' ? 'multiple' : 'single';
}

function webco_brief_show_value(array $project, string $key): string
{
    $value = trim((string) ($project[$key] ?? ''));
    $step = (string) ($project['wizard_step'] ?? '');
    $businessSaved = $step !== '' && $step !== 'business';
    if ($key === 'first_impression' && $value === '' && !$businessSaved) {
        return trim((string) ($project['goals'] ?? ''));
    }

    return $value;
}

/**
 * @param array<string, mixed> $project
 * @return list<array{label: string, value: string}>
 */
function webco_brief_legacy_notes(array $project): array
{
    $notes = [];
    foreach ([
        'goals' => 'Earlier note about website goals',
        'style_tone' => 'Earlier note about style',
        'liked_sites' => 'Earlier note about other websites',
        'required_pages' => 'Earlier note about pages',
    ] as $key => $label) {
        if ($key === 'goals' && webco_brief_show_value($project, 'first_impression') === trim((string) ($project['goals'] ?? ''))) {
            continue;
        }
        $value = trim((string) ($project[$key] ?? ''));
        if ($value !== '') {
            $notes[] = ['label' => $label, 'value' => $value];
        }
    }

    return $notes;
}

/**
 * @return array{src: string, alt: string}|null
 */
function webco_brief_step_image(string $package, string $step): ?array
{
    $file = match ($step) {
        'business' => 'hero',
        'courses' => 'courses',
        'locations' => 'location',
        'why' => 'why',
        'branding' => 'brand',
        'contact' => 'contact',
        default => null,
    };
    if ($file === null || ($package !== 'essential' && $package !== 'professional')) {
        return null;
    }

    $alt = match ($step) {
        'business' => 'The opening section of the website you chose',
        'courses' => 'The course section of the website you chose',
        'locations' => 'The location section of the website you chose',
        'why' => 'The reasons-to-choose section of the website you chose',
        'branding' => 'The logo and colours already used on the website you chose',
        'contact' => 'The contact section of the website you chose',
        default => 'Section of the website you chose',
    };

    return [
        'src' => '/images/brief/' . $package . '-' . $file . '.jpg',
        'alt' => $alt,
    ];
}

/**
 * @param array<string, mixed> $project
 * @return list<array{step: string, label: string, value: string}>
 */
function webco_brief_review_sections(array $project): array
{
    $package = (string) ($project['package_code'] ?? 'essential');
    $sections = [];
    $add = static function (string $step, string $label, string $value) use (&$sections): void {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        $sections[] = ['step' => $step, 'label' => $label, 'value' => $value];
    };

    $add('business', 'Who you are', webco_brief_show_value($project, 'business_overview'));
    $add('business', 'How long you have been operating', webco_brief_show_value($project, 'years_operating'));
    $add('business', 'Experience and credentials', webco_brief_show_value($project, 'credentials'));
    $add('business', 'What visitors should understand first', webco_brief_show_value($project, 'first_impression'));

    if (webco_brief_course_mode($package) === 'multiple') {
        $add('courses', 'Courses', webco_brief_pairs_plain((string) ($project['course_entries'] ?? '')));
        if (trim((string) ($project['course_entries'] ?? '')) === '') {
            $add('courses', 'Courses', webco_brief_show_value($project, 'services'));
        }
    } else {
        $add('courses', 'Courses and services', webco_brief_show_value($project, 'services'));
    }

    $add('locations', 'Main location', webco_brief_show_value($project, 'locations'));
    $add('locations', 'Areas served', webco_brief_show_value($project, 'areas_served'));
    if (webco_brief_location_mode($package) === 'multiple') {
        $add('locations', 'Further locations', webco_brief_pairs_plain((string) ($project['location_entries'] ?? '')));
    }

    $add('why', 'Experience', webco_brief_show_value($project, 'why_experience'));
    $add('why', 'Vehicles, equipment or facilities', webco_brief_show_value($project, 'why_facilities'));
    $add('why', 'Flexibility', webco_brief_show_value($project, 'why_flexibility'));
    $add('why', 'Customer support', webco_brief_show_value($project, 'why_support'));
    $add('why', 'What sets you apart', webco_brief_show_value($project, 'why_difference'));

    $add('branding', 'Brand colours you already use', webco_brief_show_value($project, 'branding'));
    $add('contact', 'Preferred enquiry route', webco_brief_enquiry_label(webco_brief_show_value($project, 'enquiry_route')));
    $add('contact', 'Contact details', webco_brief_show_value($project, 'contact_details'));
    $add('contact', 'Opening hours', webco_brief_show_value($project, 'opening_hours'));
    $call = ((bool) ($project['call_requested'] ?? false)) ? 'Yes' : 'No';
    $add('contact', 'Phone call before the website starts', $call);
    if ($call === 'Yes') {
        $add('contact', 'Preferred number', (string) ($project['call_number'] ?? ''));
        $add('contact', 'Preferred time', (string) ($project['call_time'] ?? ''));
        $add('contact', 'Call note', (string) ($project['call_note'] ?? ''));
    }
    $add('other', 'Anything else', webco_brief_show_value($project, 'summary'));
    foreach (webco_brief_legacy_notes($project) as $note) {
        $add('other', $note['label'], $note['value']);
    }

    return $sections;
}

function webco_brief_enquiry_label(string $route): string
{
    return match ($route) {
        'phone' => 'Phone',
        'email' => 'Email',
        'either' => 'Phone or email',
        default => '',
    };
}

function webco_brief_pairs_plain(string $json): string
{
    $lines = [];
    foreach (webco_brief_pairs($json) as $pair) {
        $line = $pair['name'];
        if ($pair['detail'] !== '') {
            $line = $line === '' ? $pair['detail'] : $line . ': ' . $pair['detail'];
        }
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    $text = implode("\n", $lines);
    if (strlen($text) > 4000) {
        $text = substr($text, 0, 4000);
    }

    return $text;
}

/**
 * @param list<mixed> $names
 * @param list<mixed> $details
 * @return list<array{name: string, detail: string}>
 */
function webco_brief_pairs_from_request(array $names, array $details): array
{
    $pairs = [];
    $count = max(count($names), count($details));
    for ($index = 0; $index < $count; $index++) {
        $name = $names[$index] ?? '';
        $detail = $details[$index] ?? '';
        if (!is_string($name) || !is_string($detail)) {
            continue;
        }
        $pairs[] = ['name' => $name, 'detail' => $detail];
    }

    return $pairs;
}

/**
 * @param array<string, mixed> $project
 */
function webco_brief_render_received(array $project, string $notice): void
{
    webco_brief_page_open('Your website');
    echo '<p class="eyebrow">Webco Cloud</p>';
    echo '<h1>Your website</h1>';
    webco_brief_identity($project);
    echo '<p class="status">' . webco_html(webco_project_status_sentence((string) $project['status'])) . '</p>';
    webco_brief_notice_line($notice);
    echo '<p class="note">Your website brief has been received. Support requests below are separate from that brief.</p>';
    echo '<h2>Files sent with your brief</h2>';
    webco_brief_file_list(webco_brief_assets_for_request(
        is_array($project['assets'] ?? null) ? $project['assets'] : [],
        null
    ));
    webco_brief_requests($project, webco_brief_csrf_token());
    echo '<p class="meta"><a href="/support/">Support</a></p>';
    webco_brief_upload_script();
    webco_brief_page_close();
}

/**
 * @param array<string, mixed> $project
 */
function webco_brief_render_wizard(array $project, string $step, string $notice, string $csrf): void
{
    $package = (string) ($project['package_code'] ?? 'essential');
    $labels = webco_brief_wizard_labels($package);
    $steps = webco_brief_wizard_steps();
    $index = array_search($step, $steps, true);
    if ($index === false) {
        $index = 0;
        $step = 'business';
    }
    $previous = $index > 0 ? $steps[$index - 1] : null;
    $next = $index < count($steps) - 1 ? $steps[$index + 1] : null;
    $packageLabel = trim((string) ($project['package_name'] ?? ''));
    if ($packageLabel === '') {
        $packageLabel = $package === 'professional' ? 'Webco Professional' : 'Webco Essential';
    }

    webco_brief_page_open('Your website brief');
    echo '<p class="eyebrow">Webco Cloud</p>';
    echo '<h1>Your website brief</h1>';
    webco_brief_identity($project);
    echo '<p class="package-line">' . webco_html($packageLabel) . '</p>';
    echo '<p class="status">' . webco_html(webco_project_status_sentence((string) $project['status'])) . '</p>';
    echo '<p class="reassurance">Your progress is saved when you use Back or Continue. You can close this page and come back with the same email link.</p>';
    webco_brief_notice_line($notice);
    webco_brief_progress($labels, $steps, $index);

    echo '<form method="post" action="/brief.php" class="wizard">';
    echo '<input type="hidden" name="csrf" value="' . webco_html($csrf) . '">';
    echo '<input type="hidden" name="step" value="' . webco_html($step) . '">';
    echo '<section class="card">';
    echo '<p class="step-count">Step ' . (string) ($index + 1) . ' of ' . (string) count($steps) . '</p>';
    echo '<h2>' . webco_html($labels[$step]) . '</h2>';
    $image = webco_brief_step_image($package, $step);
    echo '<div class="step-layout' . ($image === null ? '' : ' has-figure') . '">';
    echo '<div class="step-copy">';
    webco_brief_render_step($project, $step, $package, $csrf);
    echo '</div>';
    if ($image !== null) {
        echo '<figure class="step-figure">';
        echo '<img src="' . webco_html($image['src']) . '" alt="' . webco_html($image['alt']) . '">';
        echo '<figcaption>This is the section you are giving us information for.</figcaption>';
        echo '</figure>';
    }
    echo '</div>';
    echo '<div class="dock">';
    if ($previous !== null) {
        echo '<button class="quiet" type="submit" name="goto" value="' . webco_html($previous) . '">Back</button>';
    }
    if ($step === 'review') {
        echo '<button type="submit" name="intent" value="submit">Submit website brief</button>';
    } elseif ($next !== null) {
        echo '<button type="submit" name="goto" value="' . webco_html($next) . '">Continue</button>';
    }
    echo '</div></section></form>';
    echo '<p class="meta"><a href="/support/">Support</a></p>';
    webco_brief_upload_script();
    webco_brief_pair_script();
    webco_brief_page_close();
}

/**
 * @param array<string, string> $labels
 * @param list<string> $steps
 */
function webco_brief_progress(array $labels, array $steps, int $index): void
{
    echo '<ol class="progress">';
    foreach ($steps as $position => $id) {
        $class = $position < $index ? 'done' : ($position === $index ? 'current' : '');
        echo '<li' . ($class === '' ? '' : ' class="' . $class . '"');
        if ($position === $index) {
            echo ' aria-current="step"';
        }
        echo '>' . webco_html($labels[$id] ?? $id) . '</li>';
    }
    echo '</ol>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_brief_render_step(array $project, string $step, string $package, string $csrf): void
{
    $hint = 'Tell us in your own words. You do not need to write polished website copy — we’ll shape it for you.';
    if ($step === 'business') {
        echo '<p class="hint">' . webco_html($hint) . '</p>';
        webco_brief_area($project, 'business_overview', 'Who are you?', 'The business, and what a visitor should know you do.', 4000);
        webco_brief_area($project, 'years_operating', 'How long have you been operating?', 'A year, a rough date, or “just starting” is enough.', 160, 2);
        webco_brief_area($project, 'credentials', 'Experience, qualifications or credentials', 'Instructors, accreditations, or anything a customer would expect you to mention.', 2000);
        webco_brief_area($project, 'first_impression', 'What should visitors understand first?', 'This fills the opening message on the website you have already chosen.', 2000, 4, 'first_impression');
        return;
    }
    if ($step === 'courses') {
        if (webco_brief_course_mode($package) === 'multiple') {
            echo '<p class="hint">Add each course or service that should have its own page on your Professional website. Start with the first one, then add another if you need it. ' . webco_html($hint) . '</p>';
            webco_brief_pair_slots($project, 'course', 6, 'Course', true);
            return;
        }
        echo '<p class="hint">List the courses or services you currently offer. On Essential these stay together on the core pages, rather than each having a page of its own. ' . webco_html($hint) . '</p>';
        webco_brief_area($project, 'services', 'Which courses or services do you currently offer?', 'One list is enough. Include names and anything important about each.', 4000, 8);
        return;
    }
    if ($step === 'locations') {
        if (webco_brief_location_mode($package) === 'multiple') {
            echo '<p class="hint">Professional can give each training location its own page. Start with the main base, then add the others you actually use.</p>';
        } else {
            echo '<p class="hint">Essential uses one main location, plus the areas you serve.</p>';
        }
        webco_brief_area($project, 'locations', 'Main location', 'The training base or business address customers should see.', 2000, 4);
        webco_brief_area($project, 'areas_served', 'Areas served', 'Towns or regions people travel from.', 2000, 3);
        if (webco_brief_location_mode($package) === 'multiple') {
            echo '<h3>Further locations</h3>';
            echo '<p class="hint">Add another location only if you train or work from more than one base.</p>';
            webco_brief_pair_slots($project, 'location', 4, 'Location', false);
        }
        return;
    }
    if ($step === 'why') {
        echo '<p class="hint">These points fill the “why choose you” section. Use only the ones that are true. ' . webco_html($hint) . '</p>';
        webco_brief_area($project, 'why_experience', 'Experience', 'Years, background, or the kind of drivers you usually train.', 2000, 3);
        webco_brief_area($project, 'why_facilities', 'Vehicles, equipment or facilities', 'Lorries, yard, classroom, or anything customers will see.', 2000, 3);
        webco_brief_area($project, 'why_flexibility', 'Flexibility', 'Dates, pace, or how you fit around someone who is working.', 2000, 3);
        webco_brief_area($project, 'why_support', 'Customer support', 'How you look after someone from the first enquiry onwards.', 2000, 3);
        webco_brief_area($project, 'why_difference', 'Anything that genuinely sets you apart', 'Leave this blank if the points above already cover it.', 2000, 3);
        return;
    }
    if ($step === 'branding') {
        echo '<p class="hint">Use what you already have. You do not need to invent a new visual style. If you have no logo or colours yet, leave those blank and we will follow the website you chose.</p>';
        webco_brief_area($project, 'branding', 'Brand colours you already use', 'Only if you already have them, for example “dark green and cream”.', 2000, 3);
        echo '<h3>Logo</h3>';
        echo '<p class="hint">Add your existing logo if you have one.</p>';
        webco_brief_auto_uploads(webco_brief_assets_for_request(
            is_array($project['assets'] ?? null) ? $project['assets'] : [],
            null
        ), $csrf, null, ['logo' => 'Logo']);
        echo '<h3>Photos</h3>';
        echo '<p class="hint">Vehicles, yard, classroom or team. JPEG, PNG or WebP, up to 10 MB each.</p>';
        webco_brief_auto_uploads(webco_brief_assets_for_request(
            is_array($project['assets'] ?? null) ? $project['assets'] : [],
            null
        ), $csrf, null, ['photo' => 'Photos']);
        echo '<h3>Documents</h3>';
        echo '<p class="hint">PDF only, up to 10 MB each. Use this for something we should read, not for a new page.</p>';
        webco_brief_auto_uploads(webco_brief_assets_for_request(
            is_array($project['assets'] ?? null) ? $project['assets'] : [],
            null
        ), $csrf, null, ['document' => 'Documents']);
        return;
    }
    if ($step === 'contact') {
        echo '<p class="hint">This is how a visitor should reach you, and whether you want a call before the build starts.</p>';
        $route = webco_brief_show_value($project, 'enquiry_route');
        echo '<label for="enquiry_route">Preferred enquiry route</label>';
        echo '<select id="enquiry_route" name="enquiry_route">';
        foreach (['' => 'No preference yet', 'phone' => 'Phone', 'email' => 'Email', 'either' => 'Phone or email'] as $value => $label) {
            echo '<option value="' . webco_html($value) . '"' . ($route === $value ? ' selected' : '') . '>' . webco_html($label) . '</option>';
        }
        echo '</select>';
        webco_brief_area($project, 'contact_details', 'Business and contact details to show', 'Phone, email, or other details that should appear on the site.', 2000, 4);
        webco_brief_area($project, 'opening_hours', 'Opening hours', 'Only if customers need them.', 400, 2);
        webco_brief_call($project);
        return;
    }
    if ($step === 'other') {
        echo '<p class="hint">If there is something else you would like us to consider, tell us here. If it falls outside your chosen package, we’ll discuss it with you before doing additional work or charging anything.</p>';
        webco_brief_area($project, 'summary', 'Anything else', 'This does not add pages or features by itself.', 8000, 6);
        $legacy = webco_brief_legacy_notes($project);
        if ($legacy !== []) {
            echo '<h3>Notes already saved</h3>';
            echo '<p class="hint">These came from an earlier version of this brief. They stay on the project.</p>';
            foreach ($legacy as $note) {
                echo '<h3>' . webco_html($note['label']) . '</h3>';
                echo '<p class="summary">' . webco_html($note['value']) . '</p>';
            }
        }
        return;
    }

    echo '<p class="hint">Check this, then submit. You can go back and change any step. Nothing is sent to Webco until you submit.</p>';
    $sections = webco_brief_review_sections($project);
    if ($sections === []) {
        echo '<p class="meta">Nothing has been entered yet. Go back and add the details you have.</p>';
    }
    $current = '';
    foreach ($sections as $section) {
        if ($section['step'] !== $current) {
            $current = $section['step'];
            $labels = webco_brief_wizard_labels($package);
            echo '<h3>' . webco_html($labels[$current] ?? $current) . '</h3>';
            echo '<p><a href="/brief.php?step=' . webco_html($current) . '">Edit</a></p>';
        }
        echo '<p class="review-label">' . webco_html($section['label']) . '</p>';
        echo '<p class="summary">' . webco_html($section['value']) . '</p>';
    }
    $assets = webco_brief_assets_for_request(
        is_array($project['assets'] ?? null) ? $project['assets'] : [],
        null
    );
    echo '<h3>Uploaded files</h3>';
    webco_brief_file_list($assets);
    echo '<p><a href="/brief.php?step=branding">Edit files</a></p>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_brief_pair_slots(array $project, string $kind, int $count, string $label, bool $keepFirst): void
{
    $stored = $kind === 'course'
        ? (string) ($project['course_entries'] ?? '')
        : (string) ($project['location_entries'] ?? '');
    $pairs = webco_brief_pairs($stored);
    if ($kind === 'course' && $pairs === []) {
        $services = trim((string) ($project['services'] ?? ''));
        if ($services !== '') {
            $pairs[] = ['name' => '', 'detail' => $services];
        }
    }
    $visible = count($pairs);
    if ($keepFirst) {
        $visible = max(1, $visible);
    }
    if ($visible > $count) {
        $visible = $count;
    }
    $nameKey = $kind . '_name';
    $detailKey = $kind . '_detail';
    $addLabel = $kind === 'course' ? 'Add another course' : 'Add another location';
    $limitText = $kind === 'course'
        ? 'You can add up to six courses.'
        : 'You can add up to four further locations.';
    echo '<div class="pair-list" data-pair-list data-keep-first="' . ($keepFirst ? '1' : '0') . '" data-max="' . (string) $count . '" data-label="' . webco_html($label) . '">';
    for ($index = 0; $index < $count; $index++) {
        $pair = $pairs[$index] ?? ['name' => '', 'detail' => ''];
        $number = (string) ($index + 1);
        $shown = $index < $visible;
        $canRemove = !$keepFirst || $index > 0;
        echo '<div class="slot" data-slot' . ($shown ? '' : ' hidden') . '>';
        echo '<div class="slot-head">';
        echo '<p class="slot-title" id="' . webco_html($kind . '-title-' . $number) . '" data-title>' . webco_html($label . ' ' . $number) . '</p>';
        if ($canRemove) {
            $removeLabel = 'Remove ' . strtolower($label) . ' ' . $number;
            echo '<button type="button" class="quiet slot-remove" data-remove aria-label="' . webco_html($removeLabel) . '"' . ($shown ? '' : ' disabled') . '>Remove</button>';
        }
        echo '</div>';
        echo '<label for="' . webco_html($nameKey . $number) . '">Name</label>';
        echo '<input id="' . webco_html($nameKey . $number) . '" name="' . webco_html($nameKey) . '[]" type="text" maxlength="120" value="' . webco_html($pair['name']) . '" aria-label="' . webco_html($label . ' ' . $number . ' name') . '" data-pair-name' . ($shown ? '' : ' disabled') . '>';
        echo '<label for="' . webco_html($detailKey . $number) . '">Details</label>';
        echo '<textarea id="' . webco_html($detailKey . $number) . '" name="' . webco_html($detailKey) . '[]" maxlength="1000" rows="3" aria-label="' . webco_html($label . ' ' . $number . ' details') . '" data-pair-detail' . ($shown ? '' : ' disabled') . '>';
        echo webco_html($pair['detail']);
        echo '</textarea></div>';
    }
    if ($kind === 'location') {
        echo '<input type="hidden" name="location_name[]" value="">';
        echo '<input type="hidden" name="location_detail[]" value="">';
    }
    echo '<p class="pair-status" data-pair-status role="status" aria-live="polite"></p>';
    echo '<p class="hint pair-limit" data-limit' . ($visible >= $count ? '' : ' hidden') . '>' . webco_html($limitText) . '</p>';
    echo '<button type="button" class="quiet add-pair" data-add' . ($visible >= $count ? ' disabled' : '') . '>+ ' . webco_html($addLabel) . '</button>';
    echo '</div>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_brief_area(
    array $project,
    string $name,
    string $label,
    string $hint,
    int $max,
    int $rows = 4,
    ?string $valueKey = null
): void {
    if ($name === 'first_impression') {
        $value = webco_brief_show_value($project, 'first_impression');
    } else {
        $raw = $project[$name] ?? '';
        $value = is_string($raw) ? $raw : '';
    }
    unset($valueKey);
    echo '<label for="' . webco_html($name) . '">' . webco_html($label) . '</label>';
    echo '<p class="hint">' . webco_html($hint) . '</p>';
    echo '<textarea id="' . webco_html($name) . '" name="' . webco_html($name) . '" maxlength="' . (string) $max . '" rows="' . (string) $rows . '">';
    echo webco_html($value);
    echo '</textarea>';
}

/**
 * @param array<string, mixed> $project
 */
function webco_brief_identity(array $project): void
{
    echo '<p class="meta">' . webco_html((string) ($project['business_name'] ?? '')) . ' · ' . webco_html((string) ($project['order_public_id'] ?? '')) . '</p>';
}

function webco_brief_notice_line(string $notice): void
{
    if ($notice !== '') {
        echo '<p class="notice" role="status">' . webco_html($notice) . '</p>';
    }
}

function webco_brief_page_open(string $title): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . webco_html($title) . '</title>';
    echo webco_brief_styles();
    echo '</head><body><main>';
}

function webco_brief_page_close(): void
{
    echo '</main></body></html>';
}

function webco_brief_csrf_token(): string
{
    $csrf = $_SESSION['csrf'] ?? '';
    if (!is_string($csrf) || strlen($csrf) !== 32) {
        $csrf = bin2hex(random_bytes(16));
        $_SESSION['csrf'] = $csrf;
    }

    return $csrf;
}

/**
 * @param array<string, list<array{original_name: string, size_bytes: int, request_id?: ?int}>> $assets
 */
function webco_brief_file_list(array $assets): void
{
    $any = false;
    foreach (['logo' => 'Logos', 'photo' => 'Photos', 'document' => 'Documents'] as $category => $label) {
        $files = $assets[$category] ?? [];
        if ($files === []) {
            continue;
        }
        $any = true;
        echo '<h3>' . webco_html($label) . '</h3><ul>';
        foreach ($files as $file) {
            echo '<li>' . webco_html((string) ($file['original_name'] ?? '')) . '</li>';
        }
        echo '</ul>';
    }
    if (!$any) {
        echo '<p class="meta">None yet.</p>';
    }
}

function webco_brief_upload_script(): void
{
    echo '<script>
      document.querySelectorAll("form.upload").forEach(function (form) {
        var input = form.querySelector("input[type=file]");
        var status = form.querySelector("[data-upload-status]");
        if (!input || !status) return;
        input.addEventListener("change", function () {
          var files = Array.prototype.slice.call(input.files || []);
          if (!files.length) return;
          status.textContent = "";
          var index = 0;
          var list = form.parentNode.querySelector("ul.files");
          function next() {
            if (index >= files.length) {
              input.value = "";
              return;
            }
            var file = files[index++];
            var line = document.createElement("p");
            line.textContent = "Uploading…";
            status.appendChild(line);
            var data = new FormData(form);
            data.set("file", file);
            data.set("ajax", "1");
            fetch(form.action, { method: "POST", body: data, credentials: "same-origin" })
              .then(function (response) { return response.json(); })
              .then(function (body) {
                if (body && body.ok) {
                  line.textContent = file.name + " — Uploaded ✓";
                  line.className = "upload-ok";
                  if (!list) {
                    list = document.createElement("ul");
                    list.className = "files";
                    form.parentNode.insertBefore(list, form);
                  }
                  var item = document.createElement("li");
                  item.textContent = file.name;
                  list.appendChild(item);
                } else {
                  line.textContent = file.name + " — " + ((body && body.message) ? body.message : "The file could not be saved. Try again.");
                  line.className = "upload-fail";
                }
                next();
              })
              .catch(function () {
                line.textContent = file.name + " — The file could not be saved. Try again.";
                line.className = "upload-fail";
                next();
              });
          }
          next();
        });
      });
    </script>';
}

function webco_brief_pair_script(): void
{
    echo '<script>
      document.querySelectorAll("[data-pair-list]").forEach(function (list) {
        var max = parseInt(list.getAttribute("data-max") || "0", 10);
        var keepFirst = list.getAttribute("data-keep-first") === "1";
        var label = list.getAttribute("data-label") || "Entry";
        var status = list.querySelector("[data-pair-status]");
        var limit = list.querySelector("[data-limit]");
        var add = list.querySelector("[data-add]");

        function slots() {
          return Array.prototype.slice.call(list.querySelectorAll("[data-slot]"));
        }

        function shown() {
          return slots().filter(function (slot) { return !slot.hidden; });
        }

        function setEnabled(slot, enabled) {
          slot.hidden = !enabled;
          Array.prototype.forEach.call(slot.querySelectorAll("[data-pair-name], [data-pair-detail], [data-remove]"), function (field) {
            field.disabled = !enabled;
          });
        }

        function read(slot) {
          return {
            name: slot.querySelector("[data-pair-name]").value,
            detail: slot.querySelector("[data-pair-detail]").value
          };
        }

        function write(slot, value) {
          slot.querySelector("[data-pair-name]").value = value.name;
          slot.querySelector("[data-pair-detail]").value = value.detail;
        }

        function sync() {
          var visible = shown();
          visible.forEach(function (slot, index) {
            var title = slot.querySelector("[data-title]");
            var text = label + " " + (index + 1);
            if (title) title.textContent = text;
            var name = slot.querySelector("[data-pair-name]");
            var detail = slot.querySelector("[data-pair-detail]");
            if (name) name.setAttribute("aria-label", text + " name");
            if (detail) detail.setAttribute("aria-label", text + " details");
            var remove = slot.querySelector("[data-remove]");
            if (remove) remove.setAttribute("aria-label", "Remove " + label.toLowerCase() + " " + (index + 1));
          });
          var full = visible.length >= max;
          if (add) add.disabled = full;
          if (limit) limit.hidden = !full;
        }

        if (add) {
          add.addEventListener("click", function () {
            var next = slots().filter(function (slot) { return slot.hidden; })[0];
            if (!next) return;
            setEnabled(next, true);
            sync();
            var input = next.querySelector("[data-pair-name]");
            if (input) input.focus();
            var title = next.querySelector("[data-title]");
            if (status) status.textContent = (title ? title.textContent : label) + " added.";
          });
        }

        list.addEventListener("click", function (event) {
          var remove = event.target.closest("[data-remove]");
          if (!remove || !list.contains(remove)) return;
          var current = shown();
          var slot = remove.closest("[data-slot]");
          var index = current.indexOf(slot);
          if (index < 0 || (keepFirst && index === 0)) return;
          var values = current.map(read);
          values.splice(index, 1);
          var all = slots();
          all.forEach(function (item, itemIndex) {
            if (itemIndex < values.length) {
              setEnabled(item, true);
              write(item, values[itemIndex]);
            } else {
              write(item, { name: "", detail: "" });
              setEnabled(item, false);
            }
          });
          sync();
          if (status) status.textContent = label + " " + (index + 1) + " removed.";
          var focusSlot = all[Math.min(index, values.length - 1)];
          var focus = focusSlot && !focusSlot.hidden ? focusSlot.querySelector("[data-pair-name]") : add;
          if (focus) focus.focus();
        });

        sync();
      });
    </script>';
}
