<?php
/**
 * Secrets must load once per process. No production secrets file is used.
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

$secretsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-secrets-load-' . getmypid() . '.php';
if (is_file($secretsPath)) {
    unlink($secretsPath);
}
$written = file_put_contents(
    $secretsPath,
    "<?php\n"
    . "define('WEBCO_DB_HOST', '127.0.0.1');\n"
    . "define('WEBCO_DB_NAME', 'webco_test');\n"
    . "define('WEBCO_DB_USER', 'webco_user');\n"
    . "define('WEBCO_DB_PASSWORD', 'webco_pass');\n"
    . "define('WEBCO_20I_API_KEY', 'test-20i-key');\n"
);
check($written !== false, 'temporary secrets file can be written');
define('WEBCO_SECRETS_FILE', $secretsPath);

require dirname(__DIR__) . '/public/lib/db.php';
require dirname(__DIR__) . '/public/lib/twentyi.php';

check(function_exists('webco_load_secrets'), 'webco_load_secrets is available');
check(WEBCO_SECRETS_FILE === $secretsPath, 'tests control the secrets path');

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if ($severity === E_WARNING || $severity === E_NOTICE || $severity === E_USER_WARNING) {
        $warnings[] = $message;
    }

    return true;
});

check(webco_load_secrets() === true, 'the first secrets load succeeds');
check(webco_load_secrets() === true, 'a second secrets load is idempotent');
$config = webco_db_config();
$key = webco_twentyi_api_key();
check(is_array($config) && ($config['host'] ?? '') === '127.0.0.1', 'db config can read secrets after a prior load');
check($key === 'test-20i-key', 'twentyi can read the API key after db already loaded secrets');

restore_error_handler();

$duplicate = array_values(array_filter(
    $warnings,
    static fn (string $message): bool => str_contains($message, 'already defined')
));
check($duplicate === [], 'loading secrets for db and twentyi does not redefine constants');

$dbSource = (string) file_get_contents(dirname(__DIR__) . '/public/lib/db.php');
$twentyiSource = (string) file_get_contents(dirname(__DIR__) . '/public/lib/twentyi.php');
$domainSource = (string) file_get_contents(dirname(__DIR__) . '/public/domain-search.php');
check(str_contains($dbSource, 'function webco_load_secrets'), 'db library owns webco_load_secrets');
check(str_contains($dbSource, 'require_once WEBCO_SECRETS_FILE'), 'db secrets load uses require_once');
check(!preg_match('/(?<!_once )require WEBCO_SECRETS_FILE/', $dbSource), 'db library has no plain require of secrets');
check(str_contains($twentyiSource, 'webco_load_secrets()'), 'twentyi loads secrets through webco_load_secrets');
check(!preg_match('/(?<!_once )require WEBCO_SECRETS_FILE/', $twentyiSource), 'twentyi has no plain require of secrets');
check(str_contains($domainSource, 'webco_load_secrets()'), 'domain search loads secrets through webco_load_secrets');
check(!preg_match('/(?<!_once )require WEBCO_SECRETS_FILE/', $domainSource), 'domain search has no plain require of secrets');

@unlink($secretsPath);

echo $failures === 0 ? "passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
