<?php
/**
 * Offline checks for the active-accounts list CLI. No production database.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

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

$source = (string) file_get_contents(dirname(__DIR__) . '/bin/list-active-accounts.php');
check($source !== '', 'list-active-accounts source can be read');
check(str_contains($source, 'active_accounts: 0'), 'empty result prints active_accounts: 0');
check(str_contains($source, 'LEFT JOIN projects'), 'orders without projects are still listed');
check(
    str_contains($source, 'public_id')
        && str_contains($source, 'project_id')
        && str_contains($source, 'business_name')
        && str_contains($source, 'email')
        && str_contains($source, 'domain_name')
        && str_contains($source, 'order_status')
        && str_contains($source, 'provisioning_status')
        && str_contains($source, 'stripe_livemode')
        && str_contains($source, 'twentyi_package_id'),
    'the list includes the required columns'
);
check(!str_contains($source, 'DELETE '), 'the list CLI does not delete rows');
check(!str_contains($source, 'UPDATE '), 'the list CLI does not update rows');
check(!str_contains($source, 'api.stripe.com'), 'the list CLI does not call Stripe');
check(!str_contains($source, 'api.20i.com'), 'the list CLI does not call 20i');
check(str_contains($source, 'no arguments are accepted'), 'the list CLI refuses arguments');

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
