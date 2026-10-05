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

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
