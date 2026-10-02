<?php
/**
 * Read-only 20i discovery checks. The transport is fake. No 20i calls.
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
 * @param callable(string): array{ok: bool, status: int, body: string} $get
 */
function render(callable $get): string
{
    return webco_twentyi_discovery_text(webco_twentyi_discovery($get));
}

/**
 * @param array<string, string> $bodies
 * @return array{0: stdClass, 1: callable(string): array{ok: bool, status: int, body: string}}
 */
function fake_transport(array $bodies, int $status = 200, bool $ok = true): array
{
    $bucket = new stdClass();
    $bucket->calls = [];
    $get = static function (string $path) use ($bucket, $bodies, $status, $ok): array {
        $bucket->calls[] = $path;

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => $bodies[$path] ?? '',
        ];
    };

    return [$bucket, $get];
}

$library = file_get_contents(dirname(__DIR__) . '/public/lib/twentyi.php');
$cli = file_get_contents(dirname(__DIR__) . '/bin/twentyi-discover.php');
check(is_string($library) && is_string($cli), 'discovery files can be read');
$source = (string) $library . (string) $cli;
check(str_contains((string) $cli, "PHP_SAPI !== 'cli'"), 'CLI entry refuses a web request');
check(!str_contains($source, 'webco_db'), 'discovery does not open the database');
check(!str_contains($source, 'addWeb'), 'discovery does not call addWeb');
check(!str_contains($source, 'addDomain'), 'discovery does not call addDomain');
check(!str_contains($source, 'CURLOPT_POST'), 'discovery does not send a POST');
check(!str_contains(strtolower($source), 'ftp'), 'discovery does not request FTP');
check(!str_contains(strtolower($source), 'ssh'), 'discovery does not request SSH');
check(!str_contains(strtolower($source), 'mailbox'), 'discovery does not request mailboxes');
check(str_contains((string) $library, 'CURLOPT_HTTPGET => true'), 'HTTP transport is GET');
check(str_contains((string) $library, 'CURLOPT_FOLLOWLOCATION => false'), 'HTTP transport does not follow redirects');

check(webco_twentyi_read_url('/package') === 'https://api.20i.com/package', 'package list URL is the documented GET');
check(
    webco_twentyi_read_url('/reseller/*/packageTypes') === 'https://api.20i.com/reseller/*/packageTypes',
    'package type URL is the documented GET'
);
foreach (['/reseller/*/addWeb', '/reseller/*/addDomain', '/package/1/email/example.com', '/domain/1/nameservers'] as $blocked) {
    check(webco_twentyi_read_url($blocked) === null, $blocked . ' is not a discovery read');
}
$blockedGet = webco_twentyi_http_get('/reseller/*/addWeb', 'test-bearer-secret');
check($blockedGet['ok'] === false && $blockedGet['body'] === '', 'rejected path makes no request and returns an empty body');

$types = json_encode([
    '811' => [
        'id' => 811,
        'label' => 'Linux Unlimited',
        'platform' => 'linux',
        'welcomeEmail' => ['from' => 'secret@example.com', 'data' => '<html>secret-page</html>'],
        'limit' => ['disk' => 'unlimited-secret'],
    ],
    '900' => [
        'label' => 'WordPress',
        'platform' => 'wordpress',
    ],
], JSON_UNESCAPED_SLASHES);
$packages = json_encode([
    [
        'id' => 1002,
        'created' => '2023-05-01T00:00:00+00:00',
        'name' => 'customer.example',
        'names' => ['customer.example', 'not a domain'],
        'packageTypeName' => 'WordPress',
        'typeRef' => 900,
        'packageLabels' => [],
        'stackUsers' => ['stack-user:12345'],
        'ftpPassword' => 'ftp-secret',
    ],
    [
        'id' => 1001,
        'created' => '2024-01-15T16:30:30+00:00',
        'enabled' => true,
        'name' => 'webcocloud.net',
        'label' => 'Webco Cloud',
        'names' => ['webcocloud.net', 'www.webcocloud.net'],
        'packageTypeName' => 'Linux Unlimited',
        'productSpec' => null,
        'stackUsers' => ['stack-user:999'],
        'typeRef' => 811,
        'packageLabels' => [],
    ],
], JSON_UNESCAPED_SLASHES);
check(is_string($types) && is_string($packages), 'fixtures encode');

[$seen, $get] = fake_transport([
    '/reseller/*/packageTypes' => (string) $types,
    '/package' => (string) $packages,
]);
$text = render($get);
check($seen->calls === ['/reseller/*/packageTypes', '/package'], 'discovery requests only the two documented reads');
check(str_contains($text, 'package_types: 2'), 'both package types are counted');
check(str_contains($text, 'id=811 label=Linux Unlimited platform=linux'), 'Linux package type is reported');
check(str_contains($text, 'id=900 label=WordPress platform=wordpress'), 'map key is used when a type has no id');
check(str_contains($text, 'packages: 2'), 'both packages are counted');
check(str_contains($text, 'label_field: returned on 1 of 2'), 'label is reported only where the list returns it');
check(str_contains($text, 'package_labels_field: returned'), 'packageLabels presence is reported separately from label');
check(str_contains($text, 'id=1001 name=webcocloud.net label=Webco Cloud typeRef=811 packageTypeName=Linux Unlimited created=2024-01-15T16:30:30+00:00 names=webcocloud.net, www.webcocloud.net'), 'webcocloud.net package fields are kept');
check(str_contains($text, 'names=customer.example withheld=1'), 'a non-domain name is counted and not printed');
check(str_contains($text, "webcocloud.net: matched\npackage_id: 1001\ntypeRef: 811\npackageTypeName: Linux Unlimited\n"), 'the platform package is selected by exact domain');
foreach (['secret@example.com', 'secret-page', 'unlimited-secret', 'stack-user', 'ftp-secret', 'test-bearer-secret', 'Bearer', 'Authorization'] as $secret) {
    check(!str_contains($text, $secret), 'report omits ' . $secret);
}

$noLabel = json_encode([
    [
        'id' => 50,
        'name' => 'www.webcocloud.net',
        'names' => ['www.webcocloud.net'],
        'typeRef' => 811,
        'packageTypeName' => 'Linux Unlimited',
        'created' => '2024-02-02T03:04:05Z',
        'packageLabels' => ['ignored-tag'],
    ],
], JSON_UNESCAPED_SLASHES);
[, $noLabelGet] = fake_transport([
    '/reseller/*/packageTypes' => '[]',
    '/package' => (string) $noLabel,
]);
$noLabelText = render($noLabelGet);
check(str_contains($noLabelText, 'label_field: not returned'), 'a missing label key is reported as not returned');
check(str_contains($noLabelText, 'label=(not returned)'), 'the package line shows the label was absent');
check(!str_contains($noLabelText, 'ignored-tag'), 'packageLabels values are not printed');
check(str_contains($noLabelText, 'webcocloud.net: not matched'), 'www.webcocloud.net is not treated as the apex domain');

$ambiguous = json_encode([
    ['id' => 7, 'name' => 'webcocloud.net', 'names' => ['webcocloud.net'], 'typeRef' => 1, 'packageTypeName' => 'One', 'label' => 'First'],
    ['id' => 8, 'name' => 'other.example', 'names' => ['other.example', 'webcocloud.net'], 'typeRef' => 2, 'packageTypeName' => 'Two', 'label' => 'Second'],
], JSON_UNESCAPED_SLASHES);
[, $ambiguousGet] = fake_transport([
    '/reseller/*/packageTypes' => '[]',
    '/package' => (string) $ambiguous,
]);
$ambiguousText = render($ambiguousGet);
check(str_contains($ambiguousText, 'webcocloud.net: ambiguous package ids 7, 8'), 'two exact matches are not reduced to one package');
check(!str_contains($ambiguousText, 'package_id:'), 'an ambiguous match does not choose a package id');

$errorCalls = [];
$errorGet = static function (string $path) use (&$errorCalls): array {
    $errorCalls[] = $path;

    return [
        'ok' => false,
        'status' => 500,
        'body' => 'password=hidden Bearer leaked-token',
    ];
};
$errorText = render($errorGet);
check($errorCalls === ['/reseller/*/packageTypes', '/package'], 'a failed read still requests only the two GET paths');
check(str_contains($errorText, 'package_types: failed (HTTP 500)'), 'HTTP failure reports the status only');
check(str_contains($errorText, 'packages: failed (HTTP 500)'), 'package list failure reports the status only');
check(!str_contains($errorText, 'password') && !str_contains($errorText, 'leaked-token') && !str_contains($errorText, 'Bearer'), 'failed response bodies are discarded');

[, $badGet] = fake_transport([
    '/reseller/*/packageTypes' => '{',
    '/package' => '[]',
]);
$badText = render($badGet);
check(str_contains($badText, 'package_types: failed (unreadable response)'), 'invalid JSON is not printed');
check(str_contains($badText, 'packages: 0'), 'a readable empty package list still reports');
check(str_contains($badText, 'webcocloud.net: not matched'), 'an empty package list does not invent a match');

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
