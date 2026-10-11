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
    $html = '<p class="meta">Last 28 UK days, ending ' . webco_marketing_h($overview['end_day'])
        . '. Counts are daily totals. Estimated visits count one open browser tab, not a person, so the same person can appear more than once. No visitor identifier is stored.</p>';
    $html .= '<ul class="counts" aria-label="Marketing totals">';
    $cards = [
        ['Essential page views', $totals['essential_views']],
        ['Essential estimated visits', $totals['essential_visits']],
        ['Essential purchase clicks', $totals['essential_clicks']],
        ['Professional page views', $totals['professional_views']],
        ['Professional estimated visits', $totals['professional_visits']],
        ['Professional purchase clicks', $totals['professional_clicks']],
        ['HGV landing page views', $totals['hgv_views']],
        ['HGV estimated visits', $totals['hgv_visits']],
        ['Onboarding entries', $totals['start_entries']],
        ['Essential onboarding', $totals['start_essential']],
        ['Professional onboarding', $totals['start_professional']],
        ['Onboarding without a package', $totals['start_unspecified']],
    ];
    foreach ($cards as [$label, $value]) {
        $html .= '<li class="count' . ($value === 0 ? ' is-zero' : '') . '"><strong>' . (string) $value
            . '</strong> ' . webco_marketing_h($label) . '</li>';
    }
    $html .= '</ul>';

    $html .= '<section class="panel"><h2>Daily trend</h2>';
    $html .= '<table class="mkt-table"><thead><tr><th>Day</th><th>Essential views</th><th>Professional views</th>'
        . '<th>HGV views</th><th>Onboarding</th><th>Purchase clicks</th><th>Est. visits</th></tr></thead><tbody>';
    foreach ($overview['trend'] as $day) {
        $html .= '<tr><td>' . webco_marketing_h((string) $day['day']) . '</td><td>' . (int) $day['essential_views']
            . '</td><td>' . (int) $day['professional_views'] . '</td><td>' . (int) $day['hgv_views']
            . '</td><td>' . (int) $day['start_entries'] . '</td><td>' . (int) $day['clicks']
            . '</td><td>' . (int) $day['visits'] . '</td></tr>';
    }
    $html .= '</tbody></table></section>';

    $html .= '<section class="panel"><h2>Traffic sources</h2>';
    $html .= '<p class="meta">Direct mail is the link category <span class="mono">src=mail</span>. Other categories come from the referring site. The full address is not stored.</p>';
    $html .= '<table class="mkt-table"><thead><tr><th>Source</th><th>Page views</th><th>Est. visits</th><th>Purchase clicks</th></tr></thead><tbody>';
    foreach ($overview['sources'] as $source) {
        $html .= '<tr><td>' . webco_marketing_h($source['label']) . '</td><td>' . $source['views']
            . '</td><td>' . $source['visits'] . '</td><td>' . $source['clicks'] . '</td></tr>';
    }
    $html .= '</tbody></table></section>';

    return $html;
}

function webco_marketing_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
