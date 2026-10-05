<?php
/**
 * 20i addDomain checks. The transport is fake. No 20i calls.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/lib/twentyi.php';

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
 * @param array{ok: bool, status: int, body: string} $response
 * @return array{0: stdClass, 1: callable(string, string): array{ok: bool, status: int, body: string}}
 */
function fake_post(array $response): array
{
    $bucket = new stdClass();
    $bucket->calls = [];
    $post = static function (string $path, string $body) use ($bucket, $response): array {
        $bucket->calls[] = ['path' => $path, 'body' => $body];

        return $response;
    };

    return [$bucket, $post];
}

$order = [
    'business_name' => 'Example Training',
    'contact_name' => 'Alex Example',
    'email' => 'alex@example.com',
    'phone' => '07123456789',
    'address_line_1' => '1 High Street',
    'address_line_2' => 'Suite 2',
    'town' => 'Weston-super-Mare',
    'county' => 'Somerset',
    'postcode' => 'BS22 6AR',
    'company_number' => '10607383',
];

check(webco_domain_is_included_tld('example.co.uk') === true, '.co.uk is included');
check(webco_domain_is_included_tld('example.uk') === true, '.uk is included');
check(webco_domain_is_included_tld('example.org.uk') === false, '.org.uk is not included');
check(webco_domain_is_included_tld('example.com') === false, '.com is not included');

$payload = webco_twentyi_add_domain_payload('Example.CO.UK', $order);
check(is_array($payload) && ($payload['name'] ?? '') === 'example.co.uk', 'payload normalises the domain');
check(($payload['years'] ?? 0) === 1 && ($payload['privacyService'] ?? true) === false, 'payload is one year without privacy');
check(($payload['contact']['telephone'] ?? '') === '+447123456789', 'UK phone is normalised to +44');
check(($payload['contact']['extension']['co-no'] ?? '') === '10607383', 'company number is passed for Nominet');
check(webco_twentyi_add_domain_payload('example.com', $order) === null, 'non-included TLD builds no payload');
check(webco_twentyi_add_domain_payload('example.co.uk', array_merge($order, ['email' => 'bad'])) === null, 'bad email builds no payload');

[$created, $createPost] = fake_post(['ok' => true, 'status' => 200, 'body' => '{"result":true}']);
$made = webco_twentyi_register_domain($createPost, 'example.co.uk', $order);
check(count($created->calls) === 1, 'a valid register posts once');
check(($created->calls[0]['path'] ?? '') === '/reseller/*/addDomain', 'the post path is addDomain');
check($made['ok'] === true && $made['failure'] === '', 'a successful register is ok');
check(!array_key_exists('body', $made), 'a success result has no response body');

[$errored, $errorPost] = fake_post(['ok' => false, 'status' => 402, 'body' => '{"error":"Bearer leaked"}']);
$httpError = webco_twentyi_register_domain($errorPost, 'example.co.uk', $order);
check($httpError === ['ok' => false, 'failure' => 'http', 'status' => 402], 'an API error keeps the status only');
check(!str_contains(json_encode($httpError) ?: '', 'leaked'), 'an API error drops the response body');

check(
    webco_twentyi_write_url('/reseller/*/addDomain') === 'https://api.20i.com/reseller/*/addDomain',
    'addDomain URL is the documented POST'
);
check(webco_twentyi_read_url('/reseller/*/addDomain') === null, 'the discovery GET client still refuses addDomain');

$searchBody = json_encode([
    ['name' => 'example.co.uk', 'can' => 'register', 'suggestion' => false],
]);
check(
    webco_twentyi_domain_search_status((string) $searchBody, 'example.co.uk') === 'available',
    'domain search can mean available'
);
$takenBody = json_encode([
    ['name' => 'example.co.uk', 'can' => 'transfer', 'suggestion' => false],
]);
check(
    webco_twentyi_domain_search_status((string) $takenBody, 'example.co.uk') === 'unavailable',
    'domain search can mean unavailable'
);

$list = webco_twentyi_registered_domain_names([
    ['id' => 1, 'name' => 'Example.CO.UK', 'token' => 'secret'],
    ['id' => 2, 'name' => 'other.uk'],
]);
check($list === ['example.co.uk', 'other.uk'], 'registered domain names are normalised');
check(!in_array('secret', $list, true), 'registered domain parsing drops non-domain fields');

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
