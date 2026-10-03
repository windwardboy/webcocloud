<?php
/**
 * 20i addWeb checks. The transport is fake. No 20i calls.
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

$library = (string) file_get_contents(dirname(__DIR__) . '/public/lib/twentyi.php');
$cli = (string) file_get_contents(dirname(__DIR__) . '/bin/twentyi-add-web.php');
$worker = (string) file_get_contents(dirname(__DIR__) . '/public/lib/provision.php');
$workerCli = (string) file_get_contents(dirname(__DIR__) . '/bin/provision-dry-run.php');
check($library !== '' && $cli !== '' && $worker !== '' && $workerCli !== '', 'addWeb files can be read');
check(str_contains($cli, "PHP_SAPI !== 'cli'"), 'CLI entry refuses a web request');
check(!str_contains($cli, 'webco_db'), 'addWeb CLI does not open the database');
check(!str_contains($worker . $workerCli, 'twentyi'), 'provisioning worker does not call 20i');
check(!str_contains($worker . $workerCli, 'addWeb'), 'provisioning worker does not create a hosting package');
$applyGate = strpos($cli, '!$options[\'apply\']');
$postCall = strpos($cli, 'webco_twentyi_http_post');
check($applyGate !== false && $postCall !== false && $applyGate < $postCall, 'preview returns before the POST transport');

$payload = webco_twentyi_add_web_payload('Example.COM', '811', null);
check($payload === ['domain_name' => 'example.com', 'type' => '811'], 'payload is domain and package type only');
$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
check($encoded === '{"domain_name":"example.com","type":"811"}', 'JSON body matches the addWeb fields');

$labelled = webco_twentyi_add_web_payload('example.com', '811', '  wc_0123456789abcdef0123  ');
check(
    $labelled === [
        'domain_name' => 'example.com',
        'type' => '811',
        'label' => 'wc_0123456789abcdef0123',
    ],
    'label identifies the Webco order and is included when usable'
);
check(webco_twentyi_add_web_payload('not a domain', '811', null) === null, 'a bad domain builds no payload');
check(webco_twentyi_add_web_payload('example.com', '0', null) === null, 'package type 0 builds no payload');
check(webco_twentyi_add_web_payload('example.com', '811', 'secret@example.com') === null, 'a label with an address is rejected');
check(webco_twentyi_add_web_payload('example.com', '811', str_repeat('a', 121)) === null, 'an overlong label is rejected');

$preview = webco_twentyi_add_web_preview_text(is_array($labelled) ? $labelled : []);
check(str_contains($preview, "mode: preview\n"), 'preview names itself');
check(str_contains($preview, 'path: /reseller/*/addWeb'), 'preview names the addWeb path');
check(str_contains($preview, 'request: not sent'), 'preview says the request was not sent');
check(!str_contains(strtolower($preview), 'bearer') && !str_contains($preview, 'Authorization'), 'preview has no credential');

[$skipped, $skipPost] = fake_post(['ok' => true, 'status' => 200, 'body' => '{"result":1}']);
$invalid = webco_twentyi_create_hosting_package($skipPost, 'example.com', '811', 'bad/label');
check($skipped->calls === [], 'invalid input does not call the transport');
check($invalid === ['ok' => false, 'package_id' => '', 'failure' => 'invalid', 'status' => 0], 'invalid input is rejected locally');

[$created, $createPost] = fake_post(['ok' => true, 'status' => 200, 'body' => '{"result":866239}']);
$made = webco_twentyi_create_hosting_package($createPost, 'Example.com.', '811', 'wc_0123456789abcdef0123');
check(count($created->calls) === 1, 'a valid package is posted once');
check(($created->calls[0]['path'] ?? '') === '/reseller/*/addWeb', 'the post path is addWeb');
check(
    ($created->calls[0]['body'] ?? '') === '{"domain_name":"example.com","type":"811","label":"wc_0123456789abcdef0123"}',
    'the posted JSON is the constructed payload'
);
check($made['ok'] === true && $made['package_id'] === '866239', 'the package id is read from result');
check(array_keys($made) === ['ok', 'package_id', 'failure', 'status'], 'a success result has no response body');

$stringId = webco_twentyi_create_hosting_package(
    fake_post(['ok' => true, 'status' => 201, 'body' => '{"result":"866239","token":"secret-token"}'])[1],
    'example.com',
    '811',
    null
);
check($stringId['package_id'] === '866239', 'a string result is accepted');
check(!str_contains(json_encode($stringId) ?: '', 'secret-token'), 'extra response fields are not returned');

foreach ([
    '' => 'unreadable',
    '{' => 'unreadable',
    '866239' => 'unreadable',
    '{"id":866239}' => 'rejected',
    '{"result":null}' => 'rejected',
    '{"result":0}' => 'rejected',
    '{"result":-1}' => 'rejected',
    '{"result":{"id":866239}}' => 'rejected',
    '{"result":[]}' => 'rejected',
    '{"result":"package-866239"}' => 'rejected',
] as $body => $failure) {
    $parsed = webco_twentyi_create_hosting_package(
        fake_post(['ok' => true, 'status' => 200, 'body' => (string) $body])[1],
        'example.com',
        '811',
        null
    );
    check($parsed['ok'] === false && $parsed['package_id'] === '' && $parsed['failure'] === $failure, 'malformed body ' . json_encode((string) $body) . ' is ' . $failure);
    check(!array_key_exists('body', $parsed), 'a malformed result does not keep the response body');
}

$errorBody = '{"error":"Bearer leaked-token","password":"hidden"}';
[$errored, $errorPost] = fake_post(['ok' => false, 'status' => 403, 'body' => $errorBody]);
$httpError = webco_twentyi_create_hosting_package($errorPost, 'example.com', '811', null);
check($httpError === ['ok' => false, 'package_id' => '', 'failure' => 'http', 'status' => 403], 'an API error keeps the status only');
$httpText = webco_twentyi_add_web_result_text($httpError);
check(str_contains($httpText, "failure: http\nstatus: 403\n"), 'API error text reports status only');
check(!str_contains($httpText, 'leaked-token') && !str_contains($httpText, 'password') && !str_contains($httpText, 'Bearer'), 'API error text drops the response body');

$transport = webco_twentyi_create_hosting_package(
    fake_post(['ok' => false, 'status' => 0, 'body' => 'Authorization: Bearer secret'])[1],
    'example.com',
    '811',
    null
);
check($transport['failure'] === 'transport' && $transport['status'] === 0, 'a transport failure has no HTTP status');
check(!str_contains(json_encode($transport) ?: '', 'secret'), 'a transport failure drops the response body');

$blocked = webco_twentyi_http_post('/package', 'test-bearer-secret', '{"domain_name":"example.com","type":"811"}');
check($blocked === ['ok' => false, 'status' => 0, 'body' => ''], 'a path other than addWeb makes no request');
check(webco_twentyi_write_url('/reseller/*/addWeb') === 'https://api.20i.com/reseller/*/addWeb', 'addWeb URL is the documented POST');
check(webco_twentyi_read_url('/reseller/*/addWeb') === null, 'the discovery GET client still refuses addWeb');

$options = webco_twentyi_add_web_cli_options([
    'twentyi-add-web.php',
    '--domain=Example.com',
    '--type=811',
    '--label=wc_0123456789abcdef0123',
]);
check($options['ok'] === true && $options['apply'] === false, 'the CLI defaults to preview');
check($options['label'] === 'wc_0123456789abcdef0123', 'the CLI keeps the order label');
$applied = webco_twentyi_add_web_cli_options([
    'twentyi-add-web.php',
    '--apply',
    '--domain=example.com',
    '--type=811',
]);
check($applied['ok'] === true && $applied['apply'] === true && $applied['label'] === null, '--apply is explicit and the label stays optional');
$missing = webco_twentyi_add_web_cli_options(['twentyi-add-web.php', '--apply']);
check($missing['ok'] === false && $missing['apply'] === false, 'apply without a domain and type is refused');
$twice = webco_twentyi_add_web_cli_options([
    'twentyi-add-web.php',
    '--domain=example.com',
    '--type=811',
    '--apply',
    '--apply',
]);
check($twice['ok'] === false, '--apply may be given once');

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
