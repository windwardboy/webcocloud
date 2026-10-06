<?php
/**
 * Idempotent payment and provisioning schema migration.
 *
 *   php bin/provision-schema.php
 *
 * CLI only. Uses webco_ensure_orders_table() and
 * webco_ensure_project_provisioning_columns() from the application.
 * Does not call webco_ensure_paid_project() or webco_ensure_project_tables(),
 * so it does not create project rows, asset folders, briefs, or send mail.
 * No Stripe and no 20i calls.
 *
 * Verification treats paid Stripe test-mode projects still in waiting_payment
 * as allowed. Only paid live (stripe_livemode = 1) waiting_payment rows fail.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/public/lib/db.php';
require dirname(__DIR__) . '/public/lib/projects.php';

/**
 * @param list<string> $orderColumns
 * @param list<string> $projectColumns
 * @param list<string> $eventColumns
 * @param array<string, list<string>> $constraints
 * @return array{
 *   orders_columns: array<string, true>|null,
 *   projects_columns: array<string, true>|null,
 *   events_columns: array<string, true>|null,
 *   events_table: bool|null,
 *   orders_session_index: bool|null,
 *   events_primary: bool|null,
 *   constraints: array<string, bool|null>
 * }
 */
function webco_schema_snapshot(
    PDO $db,
    array $orderColumns,
    array $projectColumns,
    array $eventColumns,
    array $constraints
): array {
    $constraintState = [];
    foreach ($constraints as $table => $names) {
        foreach ($names as $name) {
            $constraintState[$name] = webco_schema_constraint($db, $table, $name);
        }
    }

    return [
        'orders_columns' => webco_schema_columns($db, 'orders'),
        'projects_columns' => webco_schema_columns($db, 'projects'),
        'events_columns' => webco_schema_columns($db, 'stripe_events'),
        'events_table' => webco_schema_table($db, 'stripe_events'),
        'orders_session_index' => webco_schema_index($db, 'orders', 'orders_stripe_checkout_session_id'),
        'events_primary' => webco_schema_index($db, 'stripe_events', 'PRIMARY'),
        'constraints' => $constraintState,
    ];
}

/**
 * @return array<string, true>|null
 */
function webco_schema_columns(PDO $db, string $table): ?array
{
    if (!preg_match('/^[a-z_]+$/', $table)) {
        return null;
    }

    try {
        $described = $db->query('SHOW COLUMNS FROM ' . $table);
        if ($described === false) {
            return null;
        }
        $existing = [];
        foreach ($described->fetchAll() as $column) {
            $name = strtolower((string) ($column['Field'] ?? ''));
            if ($name !== '') {
                $existing[$name] = true;
            }
        }
    } catch (PDOException) {
        return null;
    }

    return $existing;
}

function webco_schema_table(PDO $db, string $table): ?bool
{
    try {
        $statement = $db->prepare(
            'SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);
    } catch (PDOException) {
        return null;
    }

    return $statement->fetch() !== false;
}

function webco_schema_index(PDO $db, string $table, string $index): ?bool
{
    if (!preg_match('/^[a-z_]+$/', $table)) {
        return null;
    }

    try {
        $indexes = $db->query('SHOW INDEX FROM ' . $table);
        if ($indexes === false) {
            return null;
        }
        foreach ($indexes->fetchAll() as $row) {
            if (($row['Key_name'] ?? '') === $index) {
                return true;
            }
        }
    } catch (PDOException) {
        return null;
    }

    return false;
}

function webco_schema_constraint(PDO $db, string $table, string $name): ?bool
{
    try {
        $statement = $db->prepare(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND CONSTRAINT_NAME = :constraint_name'
        );
        $statement->execute([
            'table_name' => $table,
            'constraint_name' => $name,
        ]);
    } catch (PDOException) {
        return null;
    }

    return $statement->fetch() !== false;
}

/**
 * Paid live orders still waiting for provisioning. Test-mode (0) and unknown
 * livemode (NULL) are excluded so Stripe test fixtures do not fail migration.
 *
 * @param array<string, true>|null $columns
 */
function webco_schema_paid_waiting_count(PDO $db, ?array $columns): ?int
{
    if (!is_array($columns) || !isset($columns['provisioning_status'])) {
        return null;
    }

    return webco_schema_count(
        $db,
        'SELECT COUNT(*) AS n
         FROM projects p
         INNER JOIN orders o ON o.id = p.order_id
         WHERE o.status = \'paid\'
           AND o.stripe_livemode = 1
           AND p.provisioning_status = \'waiting_payment\''
    );
}

function webco_schema_count(PDO $db, string $sql): ?int
{
    try {
        $statement = $db->query($sql);
        if ($statement === false) {
            return null;
        }
        $row = $statement->fetch();
    } catch (PDOException) {
        return null;
    }
    if ($row === false) {
        return null;
    }

    return (int) ($row['n'] ?? 0);
}

/**
 * @param array<string, true>|null $before
 * @param array<string, true>|null $after
 */
function webco_schema_presence(?array $before, ?array $after, string $column): string
{
    if (!is_array($after)) {
        return 'unreadable';
    }
    $has = isset($after[$column]);
    $had = is_array($before) && isset($before[$column]);
    if ($has && $had) {
        return 'already present';
    }
    if ($has) {
        return is_array($before) ? 'added' : 'present';
    }

    return 'missing';
}

function webco_schema_flag(bool|null $before, bool|null $after): string
{
    if ($after === null) {
        return 'unreadable';
    }
    if ($after && $before === true) {
        return 'already present';
    }
    if ($after) {
        return $before === false ? 'added' : 'present';
    }

    return 'missing';
}

/**
 * @param array<string, true>|null $columns
 */
function webco_schema_verify_column(?array $columns, string $label, string $column): bool
{
    $ok = is_array($columns) && isset($columns[$column]);
    webco_schema_say('verify ' . $label . ': ' . ($ok ? 'ok' : 'missing'));

    return $ok;
}

function webco_schema_verify_flag(bool|null $present, string $label): bool
{
    $ok = $present === true;
    webco_schema_say('verify ' . $label . ': ' . ($ok ? 'ok' : 'missing'));

    return $ok;
}

function webco_schema_count_label(?int $count): string
{
    return $count === null ? 'unreadable' : (string) $count;
}

function webco_schema_say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

/**
 * Project columns the migration reports and verifies.
 *
 * @return list<string>
 */
function webco_schema_project_columns(): array
{
    return [
        'provisioning_status',
        'provisioned_at',
        'provisioning_error',
        'twentyi_package_id',
        'provisioning_attempted_at',
        'domain_registered_at',
    ];
}

/**
 * @param list<string> $argv
 */
function webco_schema_run(array $argv): int
{
    if (count($argv) > 1) {
        fwrite(STDERR, "schema migration only; no arguments are accepted\n");

        return 1;
    }

    $db = webco_db();
    if (!$db instanceof PDO) {
        fwrite(STDERR, "database unavailable\n");

        return 1;
    }

    $orderColumns = [
        'stripe_checkout_session_id',
        'stripe_customer_id',
        'stripe_subscription_id',
        'paid_at',
        'payment_intent_id',
        'care_status',
        'care_trial_ends_at',
        'hosting_status',
        'hosting_included_until',
        'stripe_livemode',
    ];
    $projectColumns = webco_schema_project_columns();
    $eventColumns = [
        'stripe_event_id',
        'event_type',
        'claimed_at',
        'processed_at',
        'result',
    ];
    $constraints = [
        'orders' => [
            'orders_status_check',
            'orders_care_status_check',
            'orders_hosting_status_check',
            'orders_stripe_livemode_check',
        ],
        'projects' => [
            'projects_provisioning_status_check',
        ],
    ];

    $before = webco_schema_snapshot($db, $orderColumns, $projectColumns, $eventColumns, $constraints);
    $paidWaitingBefore = webco_schema_paid_waiting_count($db, $before['projects_columns']);

    $ordersEnsured = webco_ensure_orders_table($db);
    $projectsEnsured = webco_ensure_project_provisioning_columns($db);

    $after = webco_schema_snapshot($db, $orderColumns, $projectColumns, $eventColumns, $constraints);
    $failed = !$ordersEnsured || !$projectsEnsured;

    webco_schema_say('orders ensure: ' . ($ordersEnsured ? 'ok' : 'failed'));
    webco_schema_say('projects ensure: ' . ($projectsEnsured ? 'ok' : 'failed'));

    foreach ($orderColumns as $column) {
        webco_schema_say(
            'orders.' . $column . ': ' . webco_schema_presence($before['orders_columns'], $after['orders_columns'], $column)
        );
    }
    webco_schema_say(
        'orders index orders_stripe_checkout_session_id: ' . webco_schema_flag(
            $before['orders_session_index'],
            $after['orders_session_index']
        )
    );
    foreach ($projectColumns as $column) {
        webco_schema_say(
            'projects.' . $column . ': ' . webco_schema_presence($before['projects_columns'], $after['projects_columns'], $column)
        );
    }
    webco_schema_say(
        'stripe_events: ' . webco_schema_flag($before['events_table'], $after['events_table'])
    );
    foreach ($eventColumns as $column) {
        webco_schema_say(
            'stripe_events.' . $column . ': ' . webco_schema_presence($before['events_columns'], $after['events_columns'], $column)
        );
    }
    webco_schema_say(
        'stripe_events primary key: ' . webco_schema_flag($before['events_primary'], $after['events_primary'])
    );
    foreach ($constraints as $names) {
        foreach ($names as $name) {
            webco_schema_say(
                $name . ': ' . webco_schema_flag($before['constraints'][$name], $after['constraints'][$name])
            );
        }
    }

    if ($paidWaitingBefore === null) {
        webco_schema_say('paid_waiting_payment_before: column_absent');
    } else {
        webco_schema_say('paid_waiting_payment_before: ' . $paidWaitingBefore);
    }

    $paidWaitingAfter = webco_schema_paid_waiting_count($db, $after['projects_columns']);
    $paidReadyAfter = webco_schema_count(
        $db,
        'SELECT COUNT(*) AS n
         FROM projects p
         INNER JOIN orders o ON o.id = p.order_id
         WHERE o.status = \'paid\'
           AND p.provisioning_status = \'ready\''
    );
    $otherWaitingAfter = webco_schema_count(
        $db,
        'SELECT COUNT(*) AS n
         FROM projects p
         INNER JOIN orders o ON o.id = p.order_id
         WHERE o.status <> \'paid\'
           AND p.provisioning_status = \'waiting_payment\''
    );

    webco_schema_say('paid_waiting_payment_after: ' . webco_schema_count_label($paidWaitingAfter));
    webco_schema_say('paid_ready_after: ' . webco_schema_count_label($paidReadyAfter));
    webco_schema_say('unpaid_waiting_payment_after: ' . webco_schema_count_label($otherWaitingAfter));

    foreach ($orderColumns as $column) {
        $failed = !webco_schema_verify_column($after['orders_columns'], 'orders.' . $column, $column) || $failed;
    }
    $failed = !webco_schema_verify_flag($after['orders_session_index'], 'orders index orders_stripe_checkout_session_id') || $failed;
    foreach ($projectColumns as $column) {
        $failed = !webco_schema_verify_column($after['projects_columns'], 'projects.' . $column, $column) || $failed;
    }
    $failed = !webco_schema_verify_flag($after['events_table'], 'stripe_events') || $failed;
    foreach ($eventColumns as $column) {
        $failed = !webco_schema_verify_column($after['events_columns'], 'stripe_events.' . $column, $column) || $failed;
    }
    $failed = !webco_schema_verify_flag($after['events_primary'], 'stripe_events primary key') || $failed;
    foreach ($constraints as $names) {
        foreach ($names as $name) {
            $failed = !webco_schema_verify_flag($after['constraints'][$name], $name) || $failed;
        }
    }
    if ($paidWaitingAfter !== 0) {
        webco_schema_say('verify paid_waiting_payment: missing');
        $failed = true;
    } else {
        webco_schema_say('verify paid_waiting_payment: ok');
    }

    webco_schema_say('summary: ' . ($failed ? 'failure' : 'success'));

    return $failed ? 1 : 0;
}

$script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
if (str_ends_with($script, '/bin/provision-schema.php')) {
    exit(webco_schema_run($argv));
}
