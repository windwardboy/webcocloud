<?php
/**
 * Stripe Customer Portal stays bound to the brief session's paid test order.
 * These tests do not call Stripe.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/billing-portal.php';

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

$customer = 'cus_' . str_repeat('a', 14);
$other = 'cus_' . str_repeat('b', 14);
$portalUrl = 'https://billing.stripe.com/p/session/test_exampleSession';
$portalSecret = 'test_' . str_repeat('A', 24);
$portalQueryUrl = 'https://billing.stripe.com/p/session?secret=' . $portalSecret;

check(webco_billing_portal_allowed($customer, 0, 'paid'), 'a paid test customer can open the portal');
check(webco_billing_portal_allowed($customer, '0', 'paid'), 'a string test mode still counts as test');
check(!webco_billing_portal_allowed($customer, 1, 'paid'), 'a live customer is not opened with the test portal');
check(!webco_billing_portal_allowed($customer, '1', 'paid'), 'a string live mode is not opened');
check(!webco_billing_portal_allowed($customer, null, 'paid'), 'an unknown mode is not opened');
check(!webco_billing_portal_allowed($customer, false, 'paid'), 'a boolean false is not treated as test mode');
check(!webco_billing_portal_allowed(null, 0, 'paid'), 'a missing customer id is not opened');
check(!webco_billing_portal_allowed('', 0, 'paid'), 'a blank customer id is not opened');
check(!webco_billing_portal_allowed('cust_bad', 0, 'paid'), 'a malformed customer id is not opened');
check(!webco_billing_portal_allowed($customer, 0, 'checkout_created'), 'an unpaid order is not opened');
check(!webco_billing_portal_allowed($customer, 0, 'refunded'), 'a refunded order is not opened');
check(webco_billing_portal_return_url() === 'https://webcocloud.net/brief.php', 'the portal returns to the client home');

$fields = webco_billing_portal_fields($customer);
check(is_string($fields), 'a portal request can be built');
check(is_string($fields) && str_contains($fields, 'customer=' . rawurlencode($customer)), 'the request names the stored customer');
check(is_string($fields) && str_contains($fields, 'return_url=' . rawurlencode('https://webcocloud.net/brief.php')), 'the request sets the client-home return');
check(is_string($fields) && !str_contains($fields, $other), 'the request does not include another customer');
check(is_string($fields) && !str_contains($fields, 'configuration') && !str_contains($fields, 'flow_data'), 'the request does not change the portal configuration');
check(webco_billing_portal_fields('') === null, 'a blank customer does not build a request');

$accepted = webco_billing_portal_url([
    'object' => 'billing_portal.session',
    'customer' => $customer,
    'livemode' => false,
    'url' => $portalUrl,
], $customer);
check($accepted === $portalUrl, 'a test portal address for this customer is accepted');
check(webco_billing_portal_url([
    'object' => 'billing_portal.session',
    'customer' => $other,
    'livemode' => false,
    'url' => $portalUrl,
], $customer) === null, 'a portal for a different customer is rejected');
check(webco_billing_portal_url([
    'object' => 'billing_portal.session',
    'customer' => ['id' => $customer],
    'livemode' => false,
    'url' => $portalUrl,
], $customer) === null, 'an expanded customer object is rejected');
check(webco_billing_portal_url([
    'object' => 'billing_portal.session',
    'customer' => $customer,
    'livemode' => true,
    'url' => 'https://billing.stripe.com/p/session/live_exampleSession',
], $customer) === null, 'a live portal address is rejected');
check(webco_billing_portal_url([
    'object' => 'checkout.session',
    'customer' => $customer,
    'livemode' => false,
    'url' => 'https://checkout.stripe.com/c/pay/cs_test_example',
], $customer) === null, 'a checkout address is not a portal address');
check(webco_is_test_billing_portal_url('http://billing.stripe.com/p/session/test_example') === false, 'the portal address must be https');
check(webco_is_test_billing_portal_url('https://billing.stripe.com.evil.com/p/session/test_example') === false, 'a lookalike host is rejected');
check(webco_is_test_billing_portal_url("https://billing.stripe.com/p/session/test_example\r\nLocation: https://evil.example") === false, 'a portal address cannot break the redirect');
check(webco_is_test_billing_portal_url($portalQueryUrl) === true, 'a test portal secret on the session path is accepted');
check(webco_is_test_billing_portal_url('https://billing.stripe.com/p/session/' . $portalSecret) === true, 'a test token in the session path is accepted');
check(webco_billing_portal_url_problem('https://billing.stripe.com/p/session?secret=live_' . str_repeat('B', 24)) === 'query', 'a live portal secret is rejected');
check(webco_billing_portal_url_problem('https://billing.stripe.com/p/login?secret=' . $portalSecret) === 'path', 'a login path is not a portal session');
check(webco_billing_portal_url_problem('https://billing.stripe.com/p/session?secret=' . $portalSecret . '&next=https://evil.example') === 'query', 'a portal secret cannot carry another address');
check(webco_billing_portal_url_problem('https://user:pass@billing.stripe.com/p/session?secret=' . $portalSecret) === 'userinfo', 'a portal address cannot carry userinfo');
check(webco_billing_portal_url_problem('http://billing.stripe.com/p/session?secret=' . $portalSecret) === 'scheme', 'a portal secret still requires https');
check(webco_billing_portal_url_problem('https://billing.stripe.com.evil.com/p/session?secret=' . $portalSecret) === 'host', 'a lookalike host is rejected with a portal secret');
check(webco_billing_portal_url([
    'object' => 'billing_portal.session',
    'customer' => $customer,
    'livemode' => false,
    'url' => $portalQueryUrl,
], $customer) === $portalQueryUrl, 'a query-form test portal address for this customer is accepted');
check(webco_billing_portal_url([
    'object' => 'billing_portal.session',
    'customer' => $other,
    'livemode' => false,
    'url' => $portalQueryUrl,
], $customer) === null, 'a query-form portal for a different customer is rejected');
check(webco_billing_portal_response_failure([
    'object' => 'billing_portal.session',
    'customer' => $customer,
    'livemode' => true,
    'url' => $portalQueryUrl,
], $customer) === 'returned livemode mismatch value=true', 'a query-form portal in live mode is rejected');

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-billing-' . getmypid() . '.sqlite';
if (is_file($path)) {
    unlink($path);
}
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE orders (
        id INTEGER PRIMARY KEY,
        status TEXT,
        stripe_customer_id TEXT,
        stripe_livemode INTEGER
    )'
);
$db->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, order_id INTEGER)');
$db->exec(
    "INSERT INTO orders (id, status, stripe_customer_id, stripe_livemode)
     VALUES (1, 'paid', " . $db->quote($customer) . ", 0)"
);
$db->exec(
    "INSERT INTO orders (id, status, stripe_customer_id, stripe_livemode)
     VALUES (2, 'paid', " . $db->quote($other) . ", 0)"
);
$db->exec(
    "INSERT INTO orders (id, status, stripe_customer_id, stripe_livemode)
     VALUES (3, 'paid', NULL, 0)"
);
$db->exec(
    "INSERT INTO orders (id, status, stripe_customer_id, stripe_livemode)
     VALUES (4, 'paid', " . $db->quote($customer) . ", 1)"
);
$db->exec('INSERT INTO projects (id, order_id) VALUES (1, 1), (2, 2), (3, 3), (4, 4)');

check(webco_billing_portal_customer_for_project($db, 1) === $customer, 'the session project uses its own customer');
check(webco_billing_portal_customer_for_project($db, 2) === $other, 'another project keeps its own customer');
check(webco_billing_portal_customer_for_project($db, 1) !== webco_billing_portal_customer_for_project($db, 2), 'two projects do not share a portal customer');
check(webco_billing_portal_customer_for_project($db, 3) === null, 'a paid order without a customer id stays closed');
check(webco_billing_portal_customer_for_project($db, 4) === null, 'a live customer on a project stays closed');
check(webco_project_billing_portal_open($db, 1) === true, 'the dashboard can show the portal for the test customer');
check(webco_project_billing_portal_open($db, 3) === false, 'the dashboard stays closed without a customer id');
check(webco_brief_notice('billing') === 'Billing could not be opened just now. Try again in a moment.', 'a failed portal returns a plain notice');

$paid = ['status' => 'paid', 'stripe_customer_id' => $customer, 'stripe_livemode' => 0, 'livemode_kind' => '0'];
check(webco_billing_portal_eligibility_failure($paid) === null, 'an eligible order has no failure marker');
check(webco_billing_portal_eligibility_failure(null) === 'billing order not eligible', 'a missing order is not eligible');
check(webco_billing_portal_eligibility_failure(['status' => 'paid', 'stripe_customer_id' => null, 'stripe_livemode' => 0]) === 'missing/invalid customer id', 'a missing customer id has its own marker');
check(webco_billing_portal_eligibility_failure(['status' => 'paid', 'stripe_customer_id' => 'cust_bad', 'stripe_livemode' => 0]) === 'missing/invalid customer id', 'a malformed customer id has its own marker');
check(webco_billing_portal_eligibility_failure(['status' => 'paid', 'stripe_customer_id' => $customer, 'stripe_livemode' => 1, 'livemode_kind' => '1']) === 'livemode mismatch value=1', 'a live order has a livemode marker');
check(webco_billing_portal_eligibility_failure(['status' => 'paid', 'stripe_customer_id' => $customer, 'stripe_livemode' => null, 'livemode_kind' => 'null']) === 'livemode mismatch value=null', 'an unknown mode has a livemode marker');
check(webco_billing_portal_eligibility_failure(['status' => 'refunded', 'stripe_customer_id' => $customer, 'stripe_livemode' => 0]) === 'billing order not eligible status=refunded', 'an unpaid order stays not eligible');

$session = [
    'object' => 'billing_portal.session',
    'customer' => $customer,
    'livemode' => false,
    'url' => $portalUrl,
];
check(webco_billing_portal_response_failure($session, $customer) === null, 'a matching test portal response has no failure marker');
check(webco_billing_portal_response_failure(['object' => 'checkout.session'] + $session, $customer) === 'unexpected portal response', 'a checkout object is not a portal response');
check(str_starts_with((string) webco_billing_portal_response_failure(['object' => 'billing_portal.session', 'customer' => $other, 'livemode' => false, 'url' => $portalUrl], $customer), 'returned customer mismatch'), 'a different customer has a mismatch marker');
check(webco_billing_portal_response_failure(['object' => 'billing_portal.session', 'customer' => ['id' => $customer], 'livemode' => false, 'url' => $portalUrl], $customer) === 'returned customer mismatch', 'an expanded customer has a mismatch marker');
check(webco_billing_portal_response_failure(['object' => 'billing_portal.session', 'customer' => $customer, 'livemode' => true, 'url' => $portalUrl], $customer) === 'returned livemode mismatch value=true', 'a live response has a livemode marker');
check(webco_billing_portal_response_failure(['object' => 'billing_portal.session', 'customer' => $customer, 'livemode' => false, 'url' => 'https://billing.stripe.com/p/session/live_example'], $customer) === 'invalid Billing Portal URL reason=path', 'a non-test portal path has a URL marker');
check(webco_billing_portal_response_failure(['object' => 'billing_portal.session', 'customer' => $customer, 'livemode' => false, 'url' => 'https://checkout.stripe.com/c/pay/cs_test_example'], $customer) === 'invalid Billing Portal URL reason=host', 'a checkout host has a URL marker');
check(!str_contains((string) webco_billing_portal_response_failure(['object' => 'billing_portal.session', 'customer' => $customer, 'livemode' => false, 'url' => $portalUrl . 'x'], $customer), $portalUrl), 'a URL marker does not include the address');

$secret = 'sk_test_' . str_repeat('k', 20);
$unsafe = 'No such customer: ' . $customer . ' key ' . $secret . ' see https://billing.stripe.com/p/session/test_secret mail owner@example.com token ' . str_repeat('ab', 16);
$safe = webco_billing_portal_safe_message($unsafe);
check(str_contains($safe, 'No such customer:'), 'a Stripe message can keep its plain wording');
check(!str_contains($safe, $customer), 'a Stripe message drops the customer id');
check(!str_contains($safe, $secret) && !str_contains($safe, 'sk_test_'), 'a Stripe message drops the secret key');
check(!str_contains($safe, 'billing.stripe.com') && !str_contains($safe, 'http'), 'a Stripe message drops addresses');
check(!str_contains($safe, 'owner@example.com'), 'a Stripe message drops email addresses');
check(!str_contains($safe, str_repeat('ab', 16)), 'a Stripe message drops long tokens');
check(webco_billing_portal_safe_token('invalid_request_error') === 'invalid_request_error', 'a Stripe error type can be logged');
check(webco_billing_portal_safe_token($secret) === 'none', 'a secret is not a safe error token');

$log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-billing-log-' . getmypid() . '.log';
if (is_file($log)) {
    unlink($log);
}
ini_set('error_log', $log);
webco_billing_portal_log('billing order not eligible');
webco_billing_portal_log_api_error(400, [
    'error' => [
        'type' => 'invalid_request_error',
        'code' => 'resource_missing',
        'message' => $unsafe,
    ],
]);
webco_billing_portal_log_api_error(400, [
    'error' => [
        'type' => $secret,
        'code' => $customer,
        'message' => $portalUrl,
    ],
]);
webco_open_billing_portal('');
$written = is_file($log) ? (string) file_get_contents($log) : '';
check(str_contains($written, 'webco billing portal: billing order not eligible'), 'a failure marker is written to the error log');
check(str_contains($written, 'Stripe HTTP/API error status=400 type=invalid_request_error code=resource_missing'), 'a Stripe error logs its status, type, and code');
check(str_contains($written, 'missing/invalid customer id'), 'a blank customer is logged before Stripe is called');
check(!str_contains($written, $customer) && !str_contains($written, $secret) && !str_contains($written, 'billing.stripe.com'), 'the error log does not contain secrets, customer ids, or portal addresses');
if (is_file($log)) {
    unlink($log);
}

$privateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-billing-private-' . getmypid();
if (!is_dir($privateDir)) {
    mkdir($privateDir, 0700);
}
$privateLog = $privateDir . DIRECTORY_SEPARATOR . 'webco-billing-debug.log';
if (is_file($privateLog)) {
    unlink($privateLog);
}
$expectedLog = rtrim(str_replace('\\', '/', dirname(WEBCO_SECRETS_FILE)), '/') . '/webco-billing-debug.log';
check(webco_billing_debug_log_path() === $expectedLog, 'the billing log sits beside the private secrets file');
check(webco_billing_debug_is_public_path($expectedLog) === false, 'the secrets directory is outside the public web root');
check(webco_billing_debug_append('billing order not eligible', $privateLog) === true, 'a diagnostic can be appended to the private log');
check(webco_billing_debug_append('portal session ready', $privateLog) === true, 'a later diagnostic is appended');
$privateText = (string) file_get_contents($privateLog);
check(substr_count($privateText, "\n") === 2, 'the private log is appended rather than replaced');
check(str_contains($privateText, 'billing order not eligible') && str_contains($privateText, 'portal session ready'), 'both diagnostics stay in the private log');
$latest = webco_billing_debug_latest($privateLog);
check(is_array($latest) && $latest['marker'] === 'portal session ready', 'the latest private diagnostic is the last line');
check(is_array($latest) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $latest['at']) === 1, 'the latest private diagnostic has a timestamp');
file_put_contents($privateLog, "2026-10-05T13:00:00Z billing order not eligible\n2026-10-05T13:01:00Z secret " . $secret . ' customer ' . $customer . ' https://billing.stripe.com/p/session/test_hidden owner@example.com' . "\n");
$redacted = webco_billing_debug_latest($privateLog);
check(is_array($redacted) && $redacted['at'] === '2026-10-05T13:01:00Z', 'a stored line keeps its timestamp');
check(is_array($redacted) && !str_contains($redacted['marker'], $customer) && !str_contains($redacted['marker'], $secret), 'a stored line drops customer ids and secret keys');
check(is_array($redacted) && !str_contains($redacted['marker'], 'billing.stripe.com') && !str_contains($redacted['marker'], 'owner@example.com'), 'a stored line drops portal addresses and email');
$publicLog = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'webco-billing-debug.log';
$distLog = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'webco-billing-debug.log';
check(webco_billing_debug_append('billing order not eligible', $publicLog) === false, 'the public web root cannot receive the billing log');
check(webco_billing_debug_append('billing order not eligible', $distLog) === false, 'the deployed web root cannot receive the billing log');
check(!is_file($publicLog) && !is_file($distLog), 'no billing log file is created under a web root');
$clientHome = (string) file_get_contents(dirname(__DIR__) . '/public/lib/client-home.php');
check(!str_contains($clientHome, 'webco_billing_debug') && !str_contains($clientHome, 'Billing check'), 'the customer home does not show the billing diagnostic');
$handoff = webco_billing_portal_continue_html($portalQueryUrl);
check(is_string($handoff) && str_contains($handoff, 'Continue to billing'), 'the portal handoff has a same-site continuation');
check(is_string($handoff) && str_contains($handoff, htmlspecialchars($portalQueryUrl, ENT_QUOTES, 'UTF-8')), 'the portal handoff keeps the validated address');
check(is_string($handoff) && !str_contains($handoff, '<form'), 'the portal handoff is not another form');
check(webco_billing_portal_continue_html('https://billing.stripe.com/p/session/live_' . str_repeat('B', 24)) === null, 'a live portal address is not handed off');
$ampUrl = $portalQueryUrl . '&foo=1';
$ampHtml = webco_billing_portal_continue_html($ampUrl);
check(is_string($ampHtml) && str_contains($ampHtml, '&amp;foo=1') && !str_contains($ampHtml, '&foo=1'), 'the portal handoff escapes the address');
check(webco_billing_portal_remember('https://evil.example/') === false, 'another site cannot be stored for the handoff');
check(webco_billing_portal_remember($portalQueryUrl) === true, 'the validated portal address can be stored for the handoff');
check(webco_billing_portal_take() === $portalQueryUrl, 'the handoff reads the stored portal address');
check(webco_billing_portal_take() === null, 'the handoff is used once');
$endpoint = (string) file_get_contents(dirname(__DIR__) . '/public/billing-portal.php');
check(str_contains($endpoint, "header('Location: /billing-portal.php', true, 303);"), 'the form post returns to this site');
check(!str_contains($endpoint, "Location: ' . \$url"), 'the form post does not redirect straight to the portal');
foreach (['public/brief.php', 'public/lib/projects.php'] as $policyFile) {
    $policy = (string) file_get_contents(dirname(__DIR__) . '/' . $policyFile);
    check(str_contains($policy, "form-action 'self'"), 'client pages keep form posts on this site');
    check(!str_contains($policy, 'billing.stripe.com'), 'client pages do not allow form posts to Stripe');
}
foreach (['public/brief.php', 'public/lib/brief-wizard.php', 'public/lib/client-home.php'] as $formFile) {
    $formSource = (string) file_get_contents(dirname(__DIR__) . '/' . $formFile);
    preg_match_all('/action="([^"]*)"/', $formSource, $formActions);
    check($formActions[1] !== [], 'client forms name an action');
    foreach ($formActions[1] as $action) {
        check(preg_match('#^/[A-Za-z0-9./_-]+$#', $action) === 1, 'a client form posts to a relative address');
        check(!str_contains($action, 'stripe') && !str_contains(strtolower($action), 'http'), 'a client form does not post to Stripe');
    }
}
if (is_file($privateLog)) {
    unlink($privateLog);
}
if (is_dir($privateDir)) {
    rmdir($privateDir);
}

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
