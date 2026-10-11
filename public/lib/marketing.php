<?php
/**
 * Daily marketing totals for the package demos, the HGV landing page and onboarding entry.
 *
 * Rows are aggregate counters. This file does not store IP addresses, cookies,
 * visitor identifiers or individual page events.
 */

declare(strict_types=1);

$webcoMarketingScript = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
if (str_ends_with($webcoMarketingScript, '/lib/marketing.php')) {
    http_response_code(404);
    exit;
}
unset($webcoMarketingScript);

require_once __DIR__ . '/db.php';

/**
 * MySQL table. One row per day, site, path bucket, source and event.
 */
function webco_marketing_daily_sql(): string
{
    return 'CREATE TABLE IF NOT EXISTS marketing_daily (
        day DATE NOT NULL,
        site VARCHAR(16) NOT NULL,
        path_key VARCHAR(24) NOT NULL,
        source VARCHAR(16) NOT NULL,
        event_type VARCHAR(16) NOT NULL,
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (day, site, path_key, source, event_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
}

function webco_ensure_marketing_daily(PDO $db): bool
{
    try {
        $db->exec(webco_marketing_daily_sql());
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * @return list<string>
 */
function webco_marketing_sources(): array
{
    return ['direct', 'mail', 'search', 'social', 'referral'];
}

/**
 * @return list<string>
 */
function webco_marketing_events(): array
{
    return ['page_view', 'visit', 'cta_click'];
}

function webco_marketing_origin_allowed(string $origin): bool
{
    return preg_match(
        '#^https://(www\.)?(webcocloud\.net|webco-essential\.co\.uk|webco-professional\.co\.uk)$#',
        strtolower($origin)
    ) === 1;
}

function webco_marketing_site_for_origin(string $origin): ?string
{
    if (!webco_marketing_origin_allowed($origin)) {
        return null;
    }

    $host = parse_url(strtolower($origin), PHP_URL_HOST);
    if (!is_string($host)) {
        return null;
    }
    if ($host === 'webco-essential.co.uk' || $host === 'www.webco-essential.co.uk') {
        return 'essential';
    }
    if ($host === 'webco-professional.co.uk' || $host === 'www.webco-professional.co.uk') {
        return 'professional';
    }
    if ($host === 'webcocloud.net' || $host === 'www.webcocloud.net') {
        return 'cloud';
    }

    return null;
}

function webco_marketing_normal_path(mixed $path): ?string
{
    if (!is_string($path) || $path === '' || strlen($path) > 120) {
        return null;
    }
    if (str_contains($path, '?') || str_contains($path, '#') || str_contains($path, '\\') || str_contains($path, '%')) {
        return null;
    }

    $path = strtolower($path);
    if ($path[0] !== '/' || preg_match('#^[a-z0-9/_.~-]+$#', $path) !== 1) {
        return null;
    }
    if (str_contains($path, '..') || str_contains($path, '//')) {
        return null;
    }
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }

    return $path === '' ? null : $path;
}

function webco_marketing_path_key(string $site, string $path, string $event, string $package): ?string
{
    if ($site === 'essential' || $site === 'professional') {
        return $event === 'cta_click' ? 'cta' : 'all';
    }
    if ($site !== 'cloud' || $event === 'cta_click') {
        return null;
    }
    if ($path === '/web-design/hgv-driver-training') {
        return 'hgv';
    }
    if ($path !== '/start') {
        return null;
    }
    if ($package === 'essential') {
        return 'start-essential';
    }
    if ($package === 'professional') {
        return 'start-professional';
    }

    return 'start';
}

function webco_marketing_bot_user_agent(string $userAgent): bool
{
    if ($userAgent === '' || strlen($userAgent) > 400) {
        return true;
    }
    if (preg_match('/Mozilla|Safari|Firefox|Chrome|Edg\//i', $userAgent) !== 1) {
        return true;
    }

    return preg_match(
        '/googlebot|bingbot|duckduckbot|baiduspider|yandex|petalbot|bytespider|gptbot|claudebot|ahrefs|semrush|dotbot|mj12bot|facebookexternalhit|slurp|wget|curl\/|python-|headless|phantom|scrapy|httpclient|crawler|spider/i',
        $userAgent
    ) === 1;
}

/**
 * @param array<string, string> $signals
 */
function webco_marketing_skip_request(string $userAgent, array $signals): bool
{
    if (($signals['gpc'] ?? '') === '1' || webco_marketing_bot_user_agent($userAgent)) {
        return true;
    }
    foreach (['purpose', 'sec-purpose', 'x-purpose', 'x-moz'] as $key) {
        $value = strtolower($signals[$key] ?? '');
        if ($value !== '' && (str_contains($value, 'prefetch') || str_contains($value, 'preview'))) {
            return true;
        }
    }

    return false;
}

function webco_marketing_london_day(int $timestamp): string
{
    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(new DateTimeZone('Europe/London'))
        ->format('Y-m-d');
}

function webco_marketing_shift_day(string $day, int $delta): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('Europe/London'));
    if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $day) {
        return null;
    }

    return $date->modify($delta . ' days')->format('Y-m-d');
}

function webco_marketing_client_key(string $ip): ?string
{
    if ($ip === '' || strlen($ip) > 128 || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return null;
    }

    return hash_hmac('sha256', $ip, 'webco-marketing-rate-v1');
}

/**
 * File counters only. The address is not written into the file or the database.
 */
function webco_marketing_rate_allow(
    string $ip,
    int $now,
    ?string $dir = null,
    int $minuteLimit = 30,
    int $dayLimit = 150
): bool {
    $key = webco_marketing_client_key($ip);
    if ($key === null || $minuteLimit < 1 || $dayLimit < 1) {
        return false;
    }

    $dir ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webco-mkt-rate';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }

    static $cleaned = false;
    if (!$cleaned) {
        $cleaned = true;
        webco_marketing_rate_cleanup($dir, $now);
    }

    $path = $dir . DIRECTORY_SEPARATOR . $key;
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        return false;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }

    $minute = intdiv($now, 60);
    $day = intdiv($now, 86400);
    $minuteCount = 0;
    $dayCount = 0;
    $raw = stream_get_contents($handle);
    if (is_string($raw) && preg_match('/^(\d+) (\d+) (\d+) (\d+)$/', trim($raw), $matches) === 1) {
        if ((int) $matches[1] === $minute) {
            $minuteCount = (int) $matches[2];
        }
        if ((int) $matches[3] === $day) {
            $dayCount = (int) $matches[4];
        }
    }
    if ($minuteCount >= $minuteLimit || $dayCount >= $dayLimit) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return false;
    }

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, $minute . ' ' . ($minuteCount + 1) . ' ' . $day . ' ' . ($dayCount + 1));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return true;
}

function webco_marketing_rate_cleanup(string $dir, int $now): void
{
    if (!is_dir($dir)) {
        return;
    }

    $cutoff = $now - 172800;
    $files = scandir($dir);
    if ($files === false) {
        return;
    }
    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || preg_match('/^[a-f0-9]{64}$/', $file) !== 1) {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        $modified = @filemtime($path);
        if ($modified !== false && $modified < $cutoff) {
            @unlink($path);
        }
    }
}

function webco_marketing_increment(
    PDO $db,
    string $day,
    string $site,
    string $pathKey,
    string $source,
    string $event
): bool {
    $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'sqlite'
        ? 'INSERT INTO marketing_daily (day, site, path_key, source, event_type, hits)
           VALUES (:day, :site, :path_key, :source, :event_type, 1)
           ON CONFLICT(day, site, path_key, source, event_type) DO UPDATE SET hits = hits + 1'
        : 'INSERT INTO marketing_daily (day, site, path_key, source, event_type, hits)
           VALUES (:day, :site, :path_key, :source, :event_type, 1)
           ON DUPLICATE KEY UPDATE hits = hits + 1';

    try {
        $statement = $db->prepare($sql);
        $statement->execute([
            'day' => $day,
            'site' => $site,
            'path_key' => $pathKey,
            'source' => $source,
            'event_type' => $event,
        ]);
    } catch (PDOException) {
        return false;
    }

    return true;
}

/**
 * Validates one collector body and adds to the daily counters.
 *
 * @param array<string, string> $signals
 * @return 'stored'|'dropped'
 */
function webco_marketing_accept(
    ?PDO $db,
    string $origin,
    string $body,
    string $userAgent,
    string $ip,
    int $now,
    ?string $rateDir = null,
    array $signals = [],
    bool $ensure = true
): string {
    $site = webco_marketing_site_for_origin($origin);
    if ($site === null || webco_marketing_skip_request($userAgent, $signals) || strlen($body) > 1500) {
        return 'dropped';
    }
    if (!webco_marketing_rate_allow($ip, $now, $rateDir)) {
        return 'dropped';
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return 'dropped';
    }

    $source = $payload['source'] ?? '';
    if (!is_string($source) || !in_array($source, webco_marketing_sources(), true)) {
        return 'dropped';
    }

    $path = webco_marketing_normal_path($payload['path'] ?? null);
    if ($path === null) {
        return 'dropped';
    }

    $package = $payload['package'] ?? '';
    if ($package === null) {
        $package = '';
    }
    if (!is_string($package) || ($package !== '' && $package !== 'essential' && $package !== 'professional')) {
        return 'dropped';
    }

    $events = $payload['events'] ?? null;
    if (!is_array($events) || $events === [] || count($events) > 2) {
        return 'dropped';
    }

    $accepted = [];
    foreach ($events as $event) {
        if (!is_string($event) || !in_array($event, webco_marketing_events(), true) || isset($accepted[$event])) {
            return 'dropped';
        }
        $pathKey = webco_marketing_path_key($site, $path, $event, $package);
        if ($pathKey === null) {
            return 'dropped';
        }
        $accepted[$event] = $pathKey;
    }

    if (!$db instanceof PDO) {
        return 'dropped';
    }
    if ($ensure && !webco_ensure_marketing_daily($db)) {
        return 'dropped';
    }

    $day = webco_marketing_london_day($now);
    try {
        $db->beginTransaction();
        foreach ($accepted as $event => $pathKey) {
            if (!webco_marketing_increment($db, $day, $site, $pathKey, $source, $event)) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                return 'dropped';
            }
        }
        $db->commit();
    } catch (PDOException) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return 'dropped';
    }

    return 'stored';
}

/**
 * @return list<array{day: string, site: string, path_key: string, source: string, event_type: string, hits: int}>|null
 */
function webco_marketing_rows(PDO $db, string $fromDay): ?array
{
    try {
        $statement = $db->prepare(
            'SELECT day, site, path_key, source, event_type, hits
             FROM marketing_daily
             WHERE day >= :from_day
             ORDER BY day DESC'
        );
        $statement->execute(['from_day' => $fromDay]);
        $rows = $statement->fetchAll();
    } catch (PDOException) {
        return null;
    }

    $normalised = [];
    foreach ($rows as $row) {
        $normalised[] = [
            'day' => (string) ($row['day'] ?? ''),
            'site' => (string) ($row['site'] ?? ''),
            'path_key' => (string) ($row['path_key'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'hits' => (int) ($row['hits'] ?? 0),
        ];
    }

    return $normalised;
}

function webco_marketing_purge(PDO $db, string $cutoffDay): void
{
    try {
        $statement = $db->prepare('DELETE FROM marketing_daily WHERE day < :cutoff');
        $statement->execute(['cutoff' => $cutoffDay]);
    } catch (PDOException) {
        return;
    }
}

/**
 * @param list<array{day: string, site: string, path_key: string, source: string, event_type: string, hits: int}> $rows
 * @return array{
 *   end_day: string,
 *   totals: array<string, int>,
 *   trend: list<array<string, int|string>>,
 *   sources: list<array{source: string, label: string, views: int, visits: int, clicks: int}>
 * }
 */
function webco_marketing_overview(array $rows, string $endDay, int $days = 28): array
{
    $totals = [
        'essential_views' => 0,
        'essential_visits' => 0,
        'essential_clicks' => 0,
        'professional_views' => 0,
        'professional_visits' => 0,
        'professional_clicks' => 0,
        'hgv_views' => 0,
        'hgv_visits' => 0,
        'start_entries' => 0,
        'start_essential' => 0,
        'start_professional' => 0,
        'start_unspecified' => 0,
    ];
    $trend = [];
    $cursor = $endDay;
    for ($index = 0; $index < $days; $index++) {
        $trend[$cursor] = [
            'day' => $cursor,
            'essential_views' => 0,
            'professional_views' => 0,
            'hgv_views' => 0,
            'start_entries' => 0,
            'clicks' => 0,
            'visits' => 0,
        ];
        $previous = webco_marketing_shift_day($cursor, -1);
        if ($previous === null) {
            break;
        }
        $cursor = $previous;
    }

    $sourceTotals = [];
    foreach (webco_marketing_sources() as $source) {
        $sourceTotals[$source] = ['views' => 0, 'visits' => 0, 'clicks' => 0];
    }

    foreach ($rows as $row) {
        if (!isset($trend[$row['day']]) || $row['hits'] < 1) {
            continue;
        }
        $bucket = webco_marketing_bucket($row['site'], $row['path_key'], $row['event_type']);
        if ($bucket === null) {
            continue;
        }
        if (isset($totals[$bucket])) {
            $totals[$bucket] += $row['hits'];
        }
        if ($bucket === 'start_essential' || $bucket === 'start_professional' || $bucket === 'start_unspecified') {
            $totals['start_entries'] += $row['hits'];
            $trend[$row['day']]['start_entries'] += $row['hits'];
        } elseif (isset($trend[$row['day']][$bucket])) {
            $trend[$row['day']][$bucket] += $row['hits'];
        }
        if ($row['event_type'] === 'visit') {
            $trend[$row['day']]['visits'] += $row['hits'];
        }
        if ($row['event_type'] === 'cta_click') {
            $trend[$row['day']]['clicks'] += $row['hits'];
        }
        if (isset($sourceTotals[$row['source']])) {
            if ($row['event_type'] === 'page_view') {
                $sourceTotals[$row['source']]['views'] += $row['hits'];
            } elseif ($row['event_type'] === 'visit') {
                $sourceTotals[$row['source']]['visits'] += $row['hits'];
            } elseif ($row['event_type'] === 'cta_click') {
                $sourceTotals[$row['source']]['clicks'] += $row['hits'];
            }
        }
    }

    $labels = [
        'direct' => 'Direct',
        'mail' => 'Direct mail',
        'search' => 'Search',
        'social' => 'Social',
        'referral' => 'Other sites',
    ];
    $sources = [];
    foreach ($labels as $source => $label) {
        $sources[] = [
            'source' => $source,
            'label' => $label,
            'views' => $sourceTotals[$source]['views'],
            'visits' => $sourceTotals[$source]['visits'],
            'clicks' => $sourceTotals[$source]['clicks'],
        ];
    }

    return [
        'end_day' => $endDay,
        'totals' => $totals,
        'trend' => array_values($trend),
        'sources' => $sources,
    ];
}

function webco_marketing_bucket(string $site, string $pathKey, string $event): ?string
{
    if ($site === 'essential' && $pathKey === 'all' && $event === 'page_view') {
        return 'essential_views';
    }
    if ($site === 'essential' && $pathKey === 'all' && $event === 'visit') {
        return 'essential_visits';
    }
    if ($site === 'essential' && $pathKey === 'cta' && $event === 'cta_click') {
        return 'essential_clicks';
    }
    if ($site === 'professional' && $pathKey === 'all' && $event === 'page_view') {
        return 'professional_views';
    }
    if ($site === 'professional' && $pathKey === 'all' && $event === 'visit') {
        return 'professional_visits';
    }
    if ($site === 'professional' && $pathKey === 'cta' && $event === 'cta_click') {
        return 'professional_clicks';
    }
    if ($site === 'cloud' && $pathKey === 'hgv' && $event === 'page_view') {
        return 'hgv_views';
    }
    if ($site === 'cloud' && $pathKey === 'hgv' && $event === 'visit') {
        return 'hgv_visits';
    }
    if ($site === 'cloud' && $event === 'page_view' && $pathKey === 'start-essential') {
        return 'start_essential';
    }
    if ($site === 'cloud' && $event === 'page_view' && $pathKey === 'start-professional') {
        return 'start_professional';
    }
    if ($site === 'cloud' && $event === 'page_view' && $pathKey === 'start') {
        return 'start_unspecified';
    }

    return null;
}

/**
 * Admin-only styles for the marketing dashboard. Scoped with the mk- prefix so no other admin page changes.
 * No scripts: bars are sized with inline widths and heights, which the admin CSP allows.
 */
function webco_marketing_styles(): string
{
    return <<<'CSS'
<style>
  main { max-width: 90rem; }
  .mk-intro { max-width: 60rem; margin-top: 0.9rem; }
  .mk-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 0.75rem; margin: 0.9rem 0 0; padding: 0; list-style: none; }
  .mk-card { min-width: 0; padding: 0.85rem 1rem; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
  .mk-card h2 { color: var(--muted); font: 700 0.75rem/1.3 var(--sans); letter-spacing: 0.05em; text-transform: uppercase; }
  .mk-value { display: block; margin-top: 0.25rem; font: 600 2.25rem/1.1 var(--serif); color: var(--ink); }
  .mk-card.is-zero .mk-value { color: var(--muted); }
  .mk-note { margin: 0.3rem 0 0; color: var(--muted); font-size: 0.8125rem; line-height: 1.35; }
  .mk-sites { display: grid; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); gap: 0.75rem; margin-top: 0.75rem; }
  .mk-site { min-width: 0; padding: 0.85rem 1rem 1rem; border: 1px solid var(--line); border-top: 4px solid var(--site); border-radius: 10px; background: #fff; }
  .mk-site-essential { --site: var(--green); --site-tint: var(--green-t); }
  .mk-site-professional { --site: #3f5a8c; --site-tint: #e8eef7; }
  .mk-site h3 { display: flex; align-items: center; gap: 0.5rem; font-size: 1.0625rem; }
  .mk-swatch { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 3px; background: var(--site); flex: none; }
  .mk-site dl { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.5rem; margin: 0.75rem 0 0; }
  .mk-site dt { color: var(--muted); font-size: 0.75rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
  .mk-site dd { margin: 0.1rem 0 0; font: 600 1.625rem/1.15 var(--serif); }
  .mk-site dd.is-zero { color: var(--muted); }
  .mk-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 0.75rem; margin-top: 0.75rem; }
  .mk-grid > .panel { margin-top: 0; }
  .mk-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.3rem 1rem; }
  .mk-legend { display: flex; gap: 0.9rem; margin: 0; padding: 0; list-style: none; color: var(--ink-2); font-size: 0.8125rem; }
  .mk-legend li { display: inline-flex; align-items: center; gap: 0.35rem; }
  .mk-chart { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.4rem; margin: 0.75rem 0 0; padding: 0; list-style: none; }
  .mk-day { min-width: 0; }
  .mk-bars { display: flex; align-items: flex-end; justify-content: center; gap: 0.25rem; height: 10rem; padding-bottom: 1px; border-bottom: 1px solid var(--line-2); }
  .mk-col { display: flex; flex: 1 1 0; flex-direction: column; justify-content: flex-end; align-items: stretch; max-width: 2.75rem; height: 100%; text-align: center; }
  .mk-val { display: block; height: 1.125rem; color: var(--ink-2); font-size: 0.75rem; font-weight: 600; line-height: 1.125rem; }
  .mk-val.is-zero { color: var(--muted); font-weight: 400; }
  .mk-bar { display: block; width: 100%; border-radius: 4px 4px 0 0; background: var(--site); }
  .mk-e { --site: var(--green); }
  .mk-p { --site: #3f5a8c; }
  .mk-label { display: block; margin-top: 0.3rem; color: var(--muted); font-size: 0.75rem; text-align: center; line-height: 1.25; }
  .mk-sources { margin: 0.5rem 0 0; padding: 0; list-style: none; }
  .mk-src { display: grid; grid-template-columns: 7rem minmax(0, 1fr); gap: 0.15rem 0.75rem; align-items: center; padding: 0.55rem 0; border-bottom: 1px solid var(--line); }
  .mk-src:last-child { border-bottom: 0; }
  .mk-src-name { font-weight: 600; }
  .mk-track { display: block; height: 0.6rem; border-radius: 99px; background: var(--soft); border: 1px solid var(--line); overflow: hidden; }
  .mk-fill { display: block; height: 100%; background: var(--green); }
  .mk-src-nums { grid-column: 2; color: var(--muted); font-size: 0.8125rem; }
  .mk-src-nums b { color: var(--ink); font-weight: 600; }
  .mk-empty { margin-top: 0.9rem; padding: 1.25rem 1.1rem; border: 1px dashed var(--line-2); border-radius: 10px; background: var(--soft); }
  .mk-empty h2 { font-size: 1.125rem; }
  .mk-empty p { max-width: 46rem; color: var(--ink-2); }
  .mk-quiet { margin: 0.75rem 0 0; padding: 0.7rem 0.4rem; color: var(--muted); }
  .mk-more { margin-top: 0.75rem; }
  .mk-more > summary { cursor: pointer; font-weight: 600; }
  .mk-scroll { margin-top: 0.4rem; overflow-x: auto; }
  .mk-scroll .mkt-table { min-width: 34rem; }
  .mk-zero { color: var(--muted); }
  @media (min-width: 40rem) {
    .mk-src { grid-template-columns: 7rem minmax(6rem, 1fr) auto; }
    .mk-src-nums { grid-column: auto; }
  }
  @media (min-width: 64rem) {
    .mk-grid { grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); align-items: start; }
  }
  @media (max-width: 39.9375rem) {
    .mk-value { font-size: 1.875rem; }
    .mk-chart { gap: 0.2rem; }
    .mk-bars { height: 8rem; gap: 0.15rem; }
    .mk-label { font-size: 0.6875rem; }
  }
</style>
CSS;
}

function webco_marketing_format_day(string $day, bool $withYear): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('Europe/London'));
    if (!$date instanceof DateTimeImmutable) {
        return $day;
    }

    return $date->format($withYear ? 'j M Y' : 'D j');
}

function webco_marketing_metric_card(string $label, int $value, string $note): string
{
    return '<li class="mk-card' . ($value === 0 ? ' is-zero' : '') . '"><h2>' . webco_marketing_h($label)
        . '</h2><span class="mk-value">' . number_format($value) . '</span><p class="mk-note">'
        . webco_marketing_h($note) . '</p></li>';
}

function webco_marketing_site_card(string $class, string $name, int $views, int $visits, int $clicks): string
{
    $html = '<section class="mk-site mk-site-' . $class . '" aria-label="' . webco_marketing_h($name) . '">'
        . '<h3><span class="mk-swatch" aria-hidden="true"></span>' . webco_marketing_h($name) . '</h3><dl>';
    foreach ([['Page views', $views], ['Est. visits', $visits], ['Purchase clicks', $clicks]] as [$label, $value]) {
        $html .= '<div><dt>' . $label . '</dt><dd' . ($value === 0 ? ' class="is-zero"' : '') . '>'
            . number_format($value) . '</dd></div>';
    }

    return $html . '</dl></section>';
}

/**
 * @param list<array<string, int|string>> $recent Oldest day first.
 */
function webco_marketing_week_chart(array $recent): string
{
    $max = 0;
    foreach ($recent as $day) {
        $max = max($max, (int) $day['essential_views'], (int) $day['professional_views']);
    }
    $html = '<section class="panel" aria-labelledby="mk-week"><div class="mk-head"><h2 id="mk-week">Demo page views, last 7 days</h2>'
        . '<ul class="mk-legend" aria-label="Key"><li><span class="mk-swatch mk-e" aria-hidden="true"></span>Essential</li>'
        . '<li><span class="mk-swatch mk-p" aria-hidden="true"></span>Professional</li></ul></div>';
    if ($max === 0) {
        return $html . '<p class="mk-quiet">No demo page views in the last 7 days.</p></section>';
    }

    $html .= '<ol class="mk-chart">';
    foreach ($recent as $day) {
        $html .= '<li class="mk-day"><div class="mk-bars">';
        foreach ([['essential_views', 'mk-e', 'Essential'], ['professional_views', 'mk-p', 'Professional']] as [$key, $class, $name]) {
            $value = (int) $day[$key];
            $height = $value === 0 ? 0.0 : max(3.0, round($value / $max * 82, 1));
            $html .= '<span class="mk-col" title="' . $name . ': ' . $value . '"><span class="mk-val'
                . ($value === 0 ? ' is-zero' : '') . '">' . $value . '</span><span class="mk-bar ' . $class
                . '" style="height:' . $height . '%"></span></span>';
        }
        $html .= '</div><span class="mk-label">' . webco_marketing_h(webco_marketing_format_day((string) $day['day'], false))
            . '</span></li>';
    }

    return $html . '</ol></section>';
}

/**
 * @param list<array{source: string, label: string, views: int, visits: int, clicks: int}> $sources
 */
function webco_marketing_sources_panel(array $sources): string
{
    $max = 0;
    foreach ($sources as $source) {
        $max = max($max, $source['views']);
    }
    $html = '<section class="panel" aria-labelledby="mk-sources"><h2 id="mk-sources">Traffic sources</h2>'
        . '<p class="meta">All tracked pages. Direct mail means the link category <span class="mono">src=mail</span>. '
        . 'Other categories come from the referring site; the full address is not stored.</p><ul class="mk-sources">';
    foreach ($sources as $source) {
        $width = $max === 0 || $source['views'] === 0 ? 0.0 : max(2.0, round($source['views'] / $max * 100, 1));
        $html .= '<li class="mk-src"><span class="mk-src-name">' . webco_marketing_h($source['label'])
            . '</span><span class="mk-track" aria-hidden="true"><span class="mk-fill" style="width:' . $width . '%"></span></span>'
            . '<span class="mk-src-nums"><b>' . number_format($source['views']) . '</b> page views · <b>'
            . number_format($source['visits']) . '</b> est. visits · <b>' . number_format($source['clicks'])
            . '</b> clicks</span></li>';
    }

    return $html . '</ul></section>';
}

/**
 * @param array{
 *   end_day: string,
 *   totals: array<string, int>,
 *   trend: list<array<string, int|string>>,
 *   sources: list<array{source: string, label: string, views: int, visits: int, clicks: int}>
 * } $overview
 */
function webco_marketing_overview_html(array $overview): string
{
    $totals = $overview['totals'];
    $html = webco_marketing_styles();
    $html .= '<p class="meta mk-intro">Last 28 UK days, ending ' . webco_marketing_h(webco_marketing_format_day($overview['end_day'], true))
        . '. <strong>Page views</strong> count every page load. <strong>Estimated visits</strong> count one open browser tab, '
        . 'not a person, so the same person can appear more than once. No visitor identifier is stored.</p>';

    $active = array_sum($totals) > 0;
    foreach ($overview['sources'] as $source) {
        $active = $active || $source['views'] + $source['visits'] + $source['clicks'] > 0;
    }
    if (!$active) {
        return $html . '<section class="mk-empty"><h2>No activity recorded yet</h2>'
            . '<p>Nothing has been counted in this period. Figures appear here once someone opens a demo website or the HGV landing page. '
            . 'Check back after the next visit; tracking is running if the demo sites carry the Webco Cloud script.</p></section>';
    }

    $html .= '<ul class="mk-metrics" aria-label="Summary">'
        . webco_marketing_metric_card('Demo page views', $totals['essential_views'] + $totals['professional_views'], 'Every page load on both demo sites.')
        . webco_marketing_metric_card('Estimated demo visits', $totals['essential_visits'] + $totals['professional_visits'], 'One open browser tab, not a person.')
        . webco_marketing_metric_card('Purchase CTA clicks', $totals['essential_clicks'] + $totals['professional_clicks'], 'Clicks on the floating Webco Cloud card.')
        . webco_marketing_metric_card('HGV landing visits', $totals['hgv_visits'], 'Estimated visits. Page views: ' . number_format($totals['hgv_views']) . '.')
        . webco_marketing_metric_card(
            'Onboarding entries',
            $totals['start_entries'],
            'Essential ' . number_format($totals['start_essential']) . ' · Professional ' . number_format($totals['start_professional'])
            . ($totals['start_unspecified'] > 0 ? ' · no package ' . number_format($totals['start_unspecified']) : '')
        )
        . '</ul>';

    $html .= '<div class="mk-sites">'
        . webco_marketing_site_card('essential', 'Essential demo', $totals['essential_views'], $totals['essential_visits'], $totals['essential_clicks'])
        . webco_marketing_site_card('professional', 'Professional demo', $totals['professional_views'], $totals['professional_visits'], $totals['professional_clicks'])
        . '</div>';

    $recent = array_reverse(array_slice($overview['trend'], 0, 7));
    $html .= '<div class="mk-grid">' . webco_marketing_week_chart($recent) . webco_marketing_sources_panel($overview['sources']) . '</div>';

    $html .= '<details class="panel mk-more"><summary>Daily figures, last 28 days</summary><div class="mk-scroll">'
        . '<table class="mkt-table"><thead><tr><th>Day</th><th>Essential views</th><th>Professional views</th>'
        . '<th>HGV views</th><th>Onboarding</th><th>Purchase clicks</th><th>Est. visits, all sites</th></tr></thead><tbody>';
    foreach ($overview['trend'] as $day) {
        $html .= '<tr><td>' . webco_marketing_h(webco_marketing_format_day((string) $day['day'], true)) . '</td>';
        foreach (['essential_views', 'professional_views', 'hgv_views', 'start_entries', 'clicks', 'visits'] as $key) {
            $value = (int) $day[$key];
            $html .= '<td' . ($value === 0 ? ' class="mk-zero"' : '') . '>' . $value . '</td>';
        }
        $html .= '</tr>';
    }

    return $html . '</tbody></table></div></details>';
}

function webco_marketing_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
