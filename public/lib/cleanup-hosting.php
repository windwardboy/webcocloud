<?php
/**
 * Explicit 20i hosting-package cleanup. Hosting accounts only — never domains.
 *
 * Preview is default. --apply deletes only allowlisted package IDs after a
 * GET /package domain-name safety check. Stripe and Webco DB are not touched.
 */

declare(strict_types=1);

$webcoCleanupHostingScript = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
if (str_ends_with($webcoCleanupHostingScript, '/lib/cleanup-hosting.php')) {
    http_response_code(404);
    exit;
}
unset($webcoCleanupHostingScript);

require_once __DIR__ . '/cleanup-audit.php';
require_once __DIR__ . '/twentyi.php';

/**
 * @return list<string>
 */
function webco_cleanup_hosting_allowed_delete_ids(): array
{
    return webco_cleanup_hosting_delete_safe_package_ids();
}

/**
 * @param list<array<string, mixed>> $packageRows from webco_twentyi_package_rows
 * @return array<string, array{id: string, name: string, names: list<string>}>
 */
function webco_cleanup_hosting_index_packages(array $packageRows): array
{
    $index = [];
    foreach ($packageRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = webco_cleanup_package_id($row['id'] ?? null);
        if ($id === null) {
            continue;
        }
        $names = [];
        foreach ($row['names'] ?? [] as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        $index[$id] = [
            'id' => $id,
            'name' => is_string($row['name'] ?? null) ? (string) $row['name'] : '',
            'names' => $names,
        ];
    }

    return $index;
}

/**
 * @param array{id: string, name: string, names: list<string>}|null $package
 */
function webco_cleanup_hosting_domain_matches(?array $package, string $expectedDomain): bool
{
    $expected = strtolower(trim($expectedDomain));
    if ($expected === '' || $package === null) {
        return false;
    }
    if (strtolower((string) ($package['name'] ?? '')) === $expected) {
        return true;
    }
    foreach ($package['names'] ?? [] as $name) {
        if (is_string($name) && strtolower($name) === $expected) {
            return true;
        }
    }

    return false;
}

/**
 * @param callable(): array{ok: bool, domains?: list<string>, rows?: list<array<string, mixed>>} $listPackages
 * @param callable(string): array{ok: bool, package_id: string, failure: string, status: int} $deletePackage
 * @return array{
 *   ok: bool,
 *   apply: bool,
 *   error: ?string,
 *   targets: list<array<string, mixed>>,
 *   deleted: list<string>,
 *   refused: list<string>
 * }
 */
function webco_cleanup_hosting_run(bool $apply, callable $listPackages, callable $deletePackage): array
{
    $allowed = webco_cleanup_hosting_allowed_delete_ids();
    $expected = webco_cleanup_hosting_delete_expected_domains();
    $keep = webco_cleanup_hosting_keep_package_ids();

    foreach ($allowed as $id) {
        if (in_array($id, $keep, true)) {
            return [
                'ok' => false,
                'apply' => $apply,
                'error' => 'allowlist conflict: keep package listed for delete',
                'targets' => [],
                'deleted' => [],
                'refused' => [],
            ];
        }
        if (!isset($expected[$id])) {
            return [
                'ok' => false,
                'apply' => $apply,
                'error' => 'package ' . $id . ' has no expected domain mapping',
                'targets' => [],
                'deleted' => [],
                'refused' => [],
            ];
        }
    }

    $listed = $listPackages();
    if (($listed['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'apply' => $apply,
            'error' => 'existing hosting packages could not be read',
            'targets' => [],
            'deleted' => [],
            'refused' => [],
        ];
    }

    $rows = is_array($listed['rows'] ?? null) ? $listed['rows'] : [];
    $index = webco_cleanup_hosting_index_packages($rows);
    $targets = [];
    $refused = [];

    if (in_array('3943463', $allowed, true)) {
        return [
            'ok' => false,
            'apply' => $apply,
            'error' => 'package 3943463 must never be deleted by this tool',
            'targets' => [],
            'deleted' => [],
            'refused' => ['3943463:keep_list'],
        ];
    }

    foreach ($allowed as $packageId) {
        $domain = $expected[$packageId];
        $package = $index[$packageId] ?? null;
        if ($package === null) {
            $targets[] = [
                'package_id' => $packageId,
                'expected_domain' => $domain,
                'action' => 'already_absent',
                'ok' => true,
            ];
            continue;
        }
        if (!webco_cleanup_hosting_domain_matches($package, $domain)) {
            $refused[] = $packageId . ':domain_mismatch';
            $targets[] = [
                'package_id' => $packageId,
                'expected_domain' => $domain,
                'actual_name' => $package['name'],
                'action' => 'refuse_domain_mismatch',
                'ok' => false,
            ];
            continue;
        }
        $targets[] = [
            'package_id' => $packageId,
            'expected_domain' => $domain,
            'actual_name' => $package['name'],
            'action' => $apply ? 'delete_hosting' : 'would_delete_hosting',
            'ok' => true,
        ];
    }

    $deleted = [];
    if ($apply) {
        foreach ($targets as $target) {
            if (($target['ok'] ?? false) !== true) {
                continue;
            }
            $packageId = (string) $target['package_id'];
            if ($packageId === '3943463' || in_array($packageId, $keep, true)) {
                $refused[] = $packageId . ':keep_list';
                continue;
            }
            if (($target['action'] ?? '') === 'already_absent') {
                continue;
            }
            if (($target['action'] ?? '') !== 'delete_hosting') {
                continue;
            }
            $result = $deletePackage($packageId);
            if (($result['ok'] ?? false) === true) {
                $deleted[] = $packageId;
            } else {
                $refused[] = $packageId . ':' . (string) ($result['failure'] ?? 'rejected');
            }
        }
    }

    return [
        'ok' => $refused === [],
        'apply' => $apply,
        'error' => $refused === [] ? null : 'one or more hosting packages were refused',
        'targets' => $targets,
        'deleted' => $deleted,
        'refused' => $refused,
    ];
}

/**
 * @param array<string, mixed> $report
 */
function webco_cleanup_hosting_report_text(array $report): string
{
    $lines = [
        'mode: cleanup-hosting-packages ' . ((($report['apply'] ?? false) === true) ? 'apply' : 'preview'),
        'ok: ' . ((($report['ok'] ?? false) === true) ? 'yes' : 'no'),
        'stripe_api: not called',
        'domain_api: not called',
        'scope: hosting packages only; domain registrations untouched',
        'keep_untouched: 3943463 pandahugs.uk',
    ];
    if (is_string($report['error'] ?? null) && $report['error'] !== '') {
        $lines[] = 'error: ' . $report['error'];
    }
    $lines[] = 'targets:';
    foreach ($report['targets'] ?? [] as $target) {
        if (!is_array($target)) {
            continue;
        }
        $lines[] = '  - package_id: ' . (string) ($target['package_id'] ?? '');
        $lines[] = '    expected_domain: ' . (string) ($target['expected_domain'] ?? '');
        if (isset($target['actual_name'])) {
            $lines[] = '    actual_name: ' . (string) $target['actual_name'];
        }
        $lines[] = '    action: ' . (string) ($target['action'] ?? '');
    }
    foreach ($report['deleted'] ?? [] as $id) {
        if (is_string($id)) {
            $lines[] = 'deleted: ' . $id;
        }
    }
    foreach ($report['refused'] ?? [] as $item) {
        if (is_string($item)) {
            $lines[] = 'refused: ' . $item;
        }
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param list<string> $argv
 * @return array{ok: bool, error: string, apply: bool}
 */
function webco_cleanup_hosting_cli_options(array $argv): array
{
    $apply = false;
    foreach (array_slice($argv, 1) as $arg) {
        if (!is_string($arg)) {
            return ['ok' => false, 'error' => 'preview is the default; only --apply is recognised', 'apply' => false];
        }
        if ($arg === '--apply') {
            if ($apply) {
                return ['ok' => false, 'error' => '--apply may be given once', 'apply' => false];
            }
            $apply = true;
            continue;
        }

        return ['ok' => false, 'error' => 'unrecognised argument; preview is default, or --apply', 'apply' => false];
    }

    return ['ok' => true, 'error' => '', 'apply' => $apply];
}
