<?php
/**
 * Fill missing entitlement fields on paid orders from a read-only Stripe view.
 * This file is not a public page and does not call 20i.
 */

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'reconcile.php') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/stripe.php';

/**
 * Preview by default. Apply writes only null fields on rows that are still paid.
 *
 * @param callable(array<string, mixed>): array{ok: bool, reason?: string, livemode?: int, status?: string, trial_end?: ?int} $retrieve
 * @return list<array<string, mixed>>
 */
function webco_reconcile_orders(PDO $db, callable $retrieve, bool $apply): array
{
    $rows = webco_reconcile_candidates($db);
    if ($rows === null) {
        return [[
            'public_id' => '',
            'action' => 'unchanged',
            'reason' => 'database_error',
            'current' => null,
            'proposed' => null,
        ]];
    }

    $results = [];
    foreach ($rows as $row) {
        $results[] = webco_reconcile_one($db, $row, $retrieve, $apply);
    }

    return $results;
}

/**
 * @return list<array<string, mixed>>|null
 */
function webco_reconcile_candidates(PDO $db): ?array
{
    try {
        $statement = $db->query(
            'SELECT id, public_id, status, care_choice,
                    stripe_checkout_session_id, stripe_subscription_id,
                    stripe_livemode, care_status, care_trial_ends_at,
                    hosting_status, hosting_included_until
             FROM orders
             WHERE status = \'paid\'
               AND (
                    stripe_livemode IS NULL
                    OR hosting_status IS NULL
                    OR (care_choice = \'managed\' AND (care_status IS NULL OR care_trial_ends_at IS NULL))
                    OR (care_choice = \'standard\' AND hosting_included_until IS NULL)
               )
             ORDER BY id'
        );
        if ($statement === false) {
            return null;
        }

        return $statement->fetchAll();
    } catch (PDOException) {
        return null;
    }
}

/**
 * Read the Checkout Session only when the order has no subscription id.
 * Both calls are GET requests.
 *
 * @param array<string, mixed> $order
 * @return array{ok: bool, reason?: string, livemode?: int, status?: string, trial_end?: ?int}
 */
function webco_reconcile_stripe_view(array $order): array
{
    $subscriptionId = $order['stripe_subscription_id'] ?? null;
    if (!is_string($subscriptionId) || $subscriptionId === '') {
        $sessionId = $order['stripe_checkout_session_id'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            return ['ok' => false, 'reason' => 'missing_subscription'];
        }
        $session = webco_stripe_get_session_subscription($sessionId);
        if ($session === null) {
            return ['ok' => false, 'reason' => 'stripe_unavailable'];
        }
        $subscriptionId = $session['subscription_id'];
        if (!is_string($subscriptionId) || $subscriptionId === '') {
            return ['ok' => false, 'reason' => 'missing_subscription'];
        }
    }

    $subscription = webco_stripe_get_subscription_fields($subscriptionId);
    if ($subscription === null) {
        return ['ok' => false, 'reason' => 'stripe_unavailable'];
    }
    if ($subscription['trial_end'] === null) {
        return ['ok' => false, 'reason' => 'missing_trial_end'];
    }

    return [
        'ok' => true,
        'livemode' => $subscription['livemode'],
        'status' => $subscription['status'],
        'trial_end' => $subscription['trial_end'],
    ];
}

/**
 * @return array{
 *   stripe_livemode: int,
 *   care_status: ?string,
 *   care_trial_ends_at: ?string,
 *   hosting_status: string,
 *   hosting_included_until: ?string
 * }|null
 */
function webco_reconcile_proposal(string $careChoice, int $livemode, string $status, int $trialEnd): ?array
{
    if ($livemode !== 0 && $livemode !== 1) {
        return null;
    }
    $local = webco_local_subscription_state($careChoice, $status, $trialEnd);
    if ($local === null) {
        return null;
    }

    return [
        'stripe_livemode' => $livemode,
        'care_status' => $local['care_status'],
        'care_trial_ends_at' => $local['care_trial_ends_at'],
        'hosting_status' => $local['hosting_status'],
        'hosting_included_until' => $local['hosting_included_until'],
    ];
}

/**
 * @param array<string, mixed> $order
 * @param callable(array<string, mixed>): array{ok: bool, reason?: string, livemode?: int, status?: string, trial_end?: ?int} $retrieve
 * @return array<string, mixed>
 */
function webco_reconcile_one(PDO $db, array $order, callable $retrieve, bool $apply): array
{
    $current = webco_reconcile_values($order);
    $view = $retrieve($order);
    if (($view['ok'] ?? false) !== true) {
        return webco_reconcile_result($order, 'unchanged', (string) ($view['reason'] ?? 'stripe_unavailable'), $current, null);
    }

    $livemode = $view['livemode'] ?? null;
    $status = $view['status'] ?? '';
    $trialEnd = $view['trial_end'] ?? null;
    if (!is_int($livemode) || !is_string($status) || !is_int($trialEnd)) {
        return webco_reconcile_result($order, 'unchanged', 'missing_trial_end', $current, null);
    }

    if (webco_map_stripe_subscription_status($status) === null) {
        return webco_reconcile_result($order, 'unchanged', 'unusable_status', $current, null);
    }
    $proposed = webco_reconcile_proposal((string) ($order['care_choice'] ?? ''), $livemode, $status, $trialEnd);
    if ($proposed === null) {
        return webco_reconcile_result($order, 'unchanged', 'missing_trial_end', $current, null);
    }
    if (!$apply) {
        return webco_reconcile_result($order, 'preview', null, $current, $proposed);
    }
    if (!webco_reconcile_apply($db, (int) ($order['id'] ?? 0), (string) ($order['care_choice'] ?? ''), $proposed)) {
        return webco_reconcile_result($order, 'unchanged', 'not_open', $current, $proposed);
    }

    return webco_reconcile_result($order, 'applied', null, $current, $proposed);
}

/**
 * Fills only columns that are still null. A value already stored by the webhook stays.
 */
function webco_reconcile_apply(PDO $db, int $orderId, string $careChoice, array $proposed): bool
{
    if ($orderId < 1 || ($careChoice !== 'standard' && $careChoice !== 'managed')) {
        return false;
    }

    try {
        $statement = $db->prepare(
            'UPDATE orders
             SET stripe_livemode = CASE WHEN stripe_livemode IS NULL THEN :stripe_livemode ELSE stripe_livemode END,
                 care_status = CASE WHEN care_status IS NULL THEN :care_status ELSE care_status END,
                 care_trial_ends_at = CASE WHEN care_trial_ends_at IS NULL THEN :care_trial_ends_at ELSE care_trial_ends_at END,
                 hosting_status = CASE WHEN hosting_status IS NULL THEN :hosting_status ELSE hosting_status END,
                 hosting_included_until = CASE WHEN hosting_included_until IS NULL THEN :hosting_included_until ELSE hosting_included_until END
             WHERE id = :id
               AND status = \'paid\'
               AND (
                    stripe_livemode IS NULL
                    OR hosting_status IS NULL
                    OR (care_choice = \'managed\' AND (care_status IS NULL OR care_trial_ends_at IS NULL))
                    OR (care_choice = \'standard\' AND hosting_included_until IS NULL)
               )'
        );
        $statement->execute([
            'stripe_livemode' => $proposed['stripe_livemode'],
            'care_status' => $proposed['care_status'],
            'care_trial_ends_at' => $proposed['care_trial_ends_at'],
            'hosting_status' => $proposed['hosting_status'],
            'hosting_included_until' => $proposed['hosting_included_until'],
            'id' => $orderId,
        ]);
    } catch (PDOException) {
        return false;
    }

    return $statement->rowCount() === 1;
}

/**
 * @param array<string, mixed> $order
 * @param array<string, mixed>|null $proposed
 * @return array<string, mixed>
 */
function webco_reconcile_result(array $order, string $action, ?string $reason, array $current, ?array $proposed): array
{
    return [
        'public_id' => (string) ($order['public_id'] ?? ''),
        'action' => $action,
        'reason' => $reason,
        'current' => $current,
        'proposed' => $proposed,
    ];
}

/**
 * @param array<string, mixed> $order
 * @return array<string, mixed>
 */
function webco_reconcile_values(array $order): array
{
    return [
        'stripe_livemode' => $order['stripe_livemode'] === null || $order['stripe_livemode'] === '' ? null : (int) $order['stripe_livemode'],
        'care_status' => webco_reconcile_text($order['care_status'] ?? null),
        'care_trial_ends_at' => webco_reconcile_text($order['care_trial_ends_at'] ?? null),
        'hosting_status' => webco_reconcile_text($order['hosting_status'] ?? null),
        'hosting_included_until' => webco_reconcile_text($order['hosting_included_until'] ?? null),
    ];
}

function webco_reconcile_text(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim((string) $value);

    return $value === '' ? null : $value;
}
