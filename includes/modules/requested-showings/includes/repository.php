<?php
if (!defined('ABSPATH')) {
    exit;
}

function roxy_rs_now_mysql(): string {
    return current_time('mysql');
}

function roxy_rs_repo_canonical_quantity($value, int $maximum = PHP_INT_MAX): ?int {
    if ($maximum < 0) return null;
    if (is_int($value)) return $value >= 0 && $value <= $maximum ? $value : null;
    if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)) return null;
    $max = (string) $maximum;
    if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) return null;
    return (int) $value;
}

function roxy_rs_repo_subscriber_lock_name(int $request_id, int $user_id): string {
    global $wpdb;
    $identity = (defined('DB_NAME') ? DB_NAME : '') . ':' . roxy_rs_table_backings() . ':' . $request_id . ':' . $user_id;
    return 'roxy-rs-ent-' . substr(hash('sha256', $identity), 0, 48);
}

function roxy_rs_repo_subscriber_lock_owned(array $claim): bool {
    global $wpdb;
    $wpdb->last_error = '';
    $connection = (string) $wpdb->get_var('SELECT CONNECTION_ID()');
    if ($wpdb->last_error !== '' || $connection === '' || $connection !== (string) ($claim['connection'] ?? '')) return false;
    $wpdb->last_error = '';
    $owner = (string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', (string) ($claim['name'] ?? '')));
    return $wpdb->last_error === '' && $owner !== '' && $owner === $connection;
}

function roxy_rs_repo_subscriber_entitlement(int $user_id) {
    if (!class_exists('\\RoxyST\\Capacity') || !method_exists('\\RoxyST\\Capacity', 'subscription_entitlement_count')) {
        return new WP_Error('subscriber_entitlement_unavailable', 'Subscriber eligibility could not be verified. Please try again later.');
    }
    try {
        $entitlement = \RoxyST\Capacity::subscription_entitlement_count($user_id);
    } catch (\Throwable $error) {
        return new WP_Error('subscriber_entitlement_unavailable', 'Subscriber eligibility could not be verified. Please try again later.');
    }
    return is_int($entitlement) && $entitlement > 0
        ? $entitlement
        : new WP_Error('subscriber_entitlement_missing', 'An active subscriber membership is required for subscriber reservations.');
}

function roxy_rs_repo_insert_backing(array $data) {
    global $wpdb;
    $lease = null;
    try {
        $request_id = (int) ($data['request_id'] ?? 0);
        if ($request_id <= 0 || !class_exists('\\RoxyRS\\ConversionClaims')) throw new \RuntimeException('Request safety checks are unavailable.');
        $lease = \RoxyRS\ConversionClaims::lease('request:' . $request_id);
        wp_cache_delete($request_id, 'post_meta');
        $request = get_post($request_id);
        if (!$request || $request->post_type !== \RoxyRS\CPT::POST_TYPE
            || !in_array(\RoxyRS\CPT::get_status($request_id), ['active', 'threshold_met'], true)
            || get_post_meta($request_id, '_roxy_rs_creation_showing', true) !== ''
            || !\RoxyRS\Frontend::backing_window_open((string) get_post_meta($request_id, \RoxyRS\CPT::META_DEADLINE_AT, true))) {
            return new WP_Error('backing_window_closed', 'This request is not currently accepting backers.');
        }
        $lease->assert_owner();
        $wpdb->last_error = '';
        $connection = (string) $wpdb->get_var('SELECT CONNECTION_ID()');
        if ($wpdb->last_error !== '' || !preg_match('/^[1-9][0-9]*$/D', $connection)) throw new \RuntimeException('Request connection could not be verified.');
        $name = 'roxy_scope_' . substr(hash('sha256', $wpdb->prefix . ':requested-conversion:request:' . $request_id), 0, 48);
        $lease->assert_owner();
        $guard = $wpdb->prepare('CONNECTION_ID()=%s AND IS_USED_LOCK(%s)=CONNECTION_ID()', $connection, $name);
        $result = roxy_rs_repo_insert_backing_owned($data, $guard);
        $lease->assert_owner();
        return $result;
    } catch (\Throwable $error) {
        return new WP_Error('backing_request_busy', 'This request is busy or a prior backing save needs review. If saving was uncertain, contact the theater before submitting another backing.');
    } finally {
        if ($lease !== null) $lease->release_lease();
    }
}

function roxy_rs_repo_insert_backing_owned(array $data, string $request_guard) {
    global $wpdb;
    $table = roxy_rs_table_backings();
    $now = roxy_rs_now_mysql();

    $row = array_merge([
        'created_at' => $now,
        'updated_at' => $now,
        'request_id' => 0,
        'user_id' => 0,
        'status' => 'pending',
        'backing_type' => 'backer',
        'payment_token_id' => null,
        'general_qty' => 0,
        'discount_qty' => 0,
        'subscriber_qty' => 0,
        'support_qty' => 0,
        'sponsor_amount' => 0,
        'sponsor_ticket_qty' => 0,
        'charge_total' => 0,
        'agreement_json' => null,
        'approved_showing_id' => null,
        'woo_order_id' => null,
        'charge_intent_id' => null,
        'admin_note' => null,
    ], $data);

    foreach (['general_qty', 'discount_qty', 'subscriber_qty', 'sponsor_ticket_qty', 'support_qty'] as $quantity_key) {
        $quantity = roxy_rs_repo_canonical_quantity($row[$quantity_key], 4294967295);
        if ($quantity === null) return new WP_Error('invalid_backing_quantity', 'Ticket quantities must be whole nonnegative numbers.');
        $row[$quantity_key] = $quantity;
    }
    if ($row['general_qty'] > 4294967295 - $row['discount_qty']
        || $row['support_qty'] !== $row['general_qty'] + $row['discount_qty']) {
        return new WP_Error('invalid_backing_quantity', 'Ticket quantities could not be safely totaled.');
    }
    foreach (['sponsor_amount', 'charge_total'] as $money_key) {
        $amount = roxy_rs_repo_canonical_quantity($row[$money_key], 2147483647);
        if ($amount === null) return new WP_Error('invalid_backing_amount', 'Backing amounts exceed the supported payment range.');
        $row[$money_key] = $amount;
    }
    $subscriber_qty = $row['subscriber_qty'];
    if (!class_exists('\\RoxyRS\\PledgeAttempts')) throw new \RuntimeException('Durable pledge safety storage is unavailable.');
    $fingerprint = \RoxyRS\PledgeAttempts::fingerprint($row);
    $replay = \RoxyRS\PledgeAttempts::replay((int) $row['request_id'], (int) $row['user_id'], $fingerprint);
    if ($replay > 0) {
        if (!roxy_rs_repo_saved_pledge_matches($replay, $row, $fingerprint)) throw new \RuntimeException('Saved pledge receipt needs reconciliation.');
        return $replay;
    }
    $attempt_started = false;

    $claim = null;
    $entitlement = 0;
    $request_id = (int) $row['request_id'];
    $user_id = (int) $row['user_id'];
    if ($subscriber_qty > 0) {
        if ($request_id <= 0 || $user_id <= 0) return new WP_Error('invalid_subscriber_owner', 'Subscriber reservation ownership could not be verified.');
        $entitlement = roxy_rs_repo_subscriber_entitlement($user_id);
        if (is_wp_error($entitlement)) return $entitlement;

        $name = roxy_rs_repo_subscriber_lock_name($request_id, $user_id);
        $wpdb->last_error = '';
        $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        $lock_error = $wpdb->last_error !== '';
        if ((string) $locked !== '1') return new WP_Error('subscriber_pledge_busy', 'Subscriber reservations are being updated. Please retry.');
        // The finally below owns every post-GET_LOCK path. An empty connection
        // deliberately prevents release until ownership can be proven.
        $claim = ['name' => $name, 'connection' => ''];
    }

    try {
        if ($claim !== null) {
            $wpdb->last_error = '';
            $connection = (string) $wpdb->get_var('SELECT CONNECTION_ID()');
            if ($connection !== '') $claim['connection'] = $connection;
            if ($wpdb->last_error !== '' || $connection === '' || $lock_error) return new WP_Error('subscriber_pledge_lock_lost', 'Subscriber reservations could not be safely locked. Please retry.');
            if (!roxy_rs_repo_subscriber_lock_owned($claim)) return new WP_Error('subscriber_pledge_lock_lost', 'Subscriber reservations could not be safely locked. Please retry.');

            $statuses = "'pending','threshold_met','approved','charged'";
            $wpdb->last_error = '';
            $outstanding = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(subscriber_qty),0) FROM $table WHERE request_id = %d AND user_id = %d AND status IN ($statuses)",
                $request_id,
                $user_id
            ));
            $outstanding_qty = roxy_rs_repo_canonical_quantity($outstanding);
            if ($wpdb->last_error !== '' || $outstanding_qty === null) {
                return new WP_Error('subscriber_pledge_read_failed', 'Existing subscriber reservations could not be verified. Please retry.');
            }
            if (!roxy_rs_repo_subscriber_lock_owned($claim)) return new WP_Error('subscriber_pledge_lock_lost', 'Subscriber reservations could not be safely locked. Please retry.');
            if ($outstanding_qty > $entitlement - $subscriber_qty) return new WP_Error('subscriber_entitlement_exceeded', 'Your existing subscriber reservations use the available membership entitlement for this request.');

            $columns = [];
            $expressions = [];
            $parameters = [];
            foreach ($row as $column => $value) {
                if (!preg_match('/^[a-z_]+$/', (string) $column) || (!is_scalar($value) && $value !== null)) return new WP_Error('invalid_backing_data', 'Backing data could not be safely saved.');
                $columns[] = '`' . $column . '`';
                if ($value === null) $expressions[] = 'NULL';
                else { $expressions[] = '%s'; $parameters[] = (string) $value; }
            }
            $parameters[] = (string) $claim['connection'];
            $parameters[] = (string) $claim['name'];
            $insert_sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') SELECT ' . implode(', ', $expressions)
                . ' WHERE CONNECTION_ID() = %s AND IS_USED_LOCK(%s) = CONNECTION_ID() AND ' . $request_guard;
            if (!roxy_rs_repo_subscriber_lock_owned($claim)) return new WP_Error('subscriber_pledge_lock_lost', 'Subscriber reservations could not be safely locked. Please retry.');
            $wpdb->last_error = '';
            \RoxyRS\PledgeAttempts::begin($request_id, $user_id, $fingerprint);
            $attempt_started = true;
            $inserted = $wpdb->query($wpdb->prepare($insert_sql, $parameters));
            if ($wpdb->last_error !== '' || $inserted !== 1) return new WP_Error('pledge_save_review', 'The pledge save could not be confirmed. Contact the theater before submitting another pledge.');
            return roxy_rs_repo_finish_pledge((int) $wpdb->insert_id, $row, $fingerprint);
        }

        $columns = []; $expressions = []; $parameters = [];
        foreach ($row as $column => $value) {
            if (!preg_match('/^[a-z_]+$/D', (string) $column) || (!is_scalar($value) && $value !== null)) return new WP_Error('invalid_backing_data', 'Backing data could not be safely saved.');
            $columns[] = '`' . $column . '`';
            if ($value === null) $expressions[] = 'NULL';
            else { $expressions[] = '%s'; $parameters[] = (string) $value; }
        }
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') SELECT ' . implode(',', $expressions) . ' WHERE ' . $request_guard;
        $wpdb->last_error = '';
        \RoxyRS\PledgeAttempts::begin($request_id, $user_id, $fingerprint);
        $attempt_started = true;
        $ok = $wpdb->query($wpdb->prepare($sql, $parameters));
        if ($wpdb->last_error !== '' || $ok !== 1) return new WP_Error('pledge_save_review', 'The pledge save could not be confirmed. Contact the theater before submitting another pledge.');
        return roxy_rs_repo_finish_pledge((int) $wpdb->insert_id, $row, $fingerprint);
    } catch (\Throwable $error) {
        return new WP_Error(
            $attempt_started ? 'pledge_save_review' : ($claim !== null ? 'subscriber_pledge_read_failed' : 'db_insert_failed'),
            $attempt_started ? 'The pledge save could not be confirmed. Contact the theater before submitting another pledge.' : ($claim !== null ? 'Subscriber reservations could not be verified. Please retry.' : 'Could not save backing.')
        );
    } finally {
        if ($claim !== null && roxy_rs_repo_subscriber_lock_owned($claim)) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $claim['name']));
        }
    }
}

function roxy_rs_repo_saved_pledge_matches(int $id, array $expected, string $hash): bool {
    $saved = $id > 0 ? roxy_rs_repo_get_backing($id) : null;
    return $saved !== null && !array_diff_key($expected, $saved)
        && hash_equals($hash, \RoxyRS\PledgeAttempts::fingerprint(array_intersect_key($saved, $expected)));
}

function roxy_rs_repo_finish_pledge(int $id, array $row, string $hash): int {
    if (!roxy_rs_repo_saved_pledge_matches($id, $row, $hash)) throw new \RuntimeException('Saved backing readback did not match the attempted pledge.');
    \RoxyRS\PledgeAttempts::finish((int) $row['request_id'], (int) $row['user_id'], $hash, $id);
    return $id;
}

function roxy_rs_repo_update_backing(int $id, array $data) {
    global $wpdb;
    $table = roxy_rs_table_backings();
    $data['updated_at'] = roxy_rs_now_mysql();
    $ok = $wpdb->update($table, $data, ['id' => $id]);
    if ($ok === false) {
        return new WP_Error('db_update_failed', $wpdb->last_error ?: 'Could not update backing.');
    }

    return true;
}

function roxy_rs_repo_get_backing(int $id): ?array {
    global $wpdb;
    $table = roxy_rs_table_backings();
    $wpdb->last_error = '';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
    return $wpdb->last_error === '' && is_array($row) ? $row : null;
}

function roxy_rs_repo_list_backings_for_request(int $request_id, array $statuses = []): array {
    global $wpdb;
    $table = roxy_rs_table_backings();
    $wpdb->last_error = '';
    if (!$statuses) {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE request_id = %d ORDER BY id ASC", $request_id), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) throw new \RuntimeException('Request backings could not be read.');
        return $rows;
    }

    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
    $sql = $wpdb->prepare(
        "SELECT * FROM $table WHERE request_id = %d AND status IN ($placeholders) ORDER BY id ASC",
        array_merge([$request_id], array_values($statuses))
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($rows)) throw new \RuntimeException('Request backings could not be read.');
    return $rows;
}

function roxy_rs_repo_list_backings_for_user(int $user_id): array {
    global $wpdb;
    $table = roxy_rs_table_backings();
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY id DESC", $user_id), ARRAY_A);
    return $rows ?: [];
}

function roxy_rs_repo_backing_totals(int $request_id): array {
    global $wpdb;
    $table = roxy_rs_table_backings();
    $wpdb->last_error = '';
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status IN ('pending','threshold_met','approved','charged') THEN support_qty ELSE 0 END),0) AS support_qty,
                COALESCE(SUM(CASE WHEN status IN ('pending','threshold_met','approved','charged') THEN subscriber_qty ELSE 0 END),0) AS subscriber_qty,
                COALESCE(SUM(CASE WHEN status IN ('pending','threshold_met','approved','charged') THEN charge_total ELSE 0 END),0) AS charge_total,
                COALESCE(SUM(CASE WHEN status IN ('pending','threshold_met','approved','charged') THEN sponsor_amount ELSE 0 END),0) AS sponsor_amount,
                COALESCE(SUM(CASE WHEN status IN ('pending','threshold_met','approved','charged') THEN sponsor_ticket_qty ELSE 0 END),0) AS sponsor_ticket_qty,
                MAX(CASE WHEN status IN ('pending','threshold_met','approved','charged') AND sponsor_amount > 0 THEN 1 ELSE 0 END) AS has_sponsor
             FROM $table
             WHERE request_id = %d",
            $request_id
        ),
        ARRAY_A
    );
    if ($wpdb->last_error !== '') throw new RuntimeException('Requested-showing backing totals could not be read.');
    if (!is_array($row)) throw new RuntimeException('Requested-showing backing totals returned an incomplete result.');

    $totals = [];
    foreach (['support_qty', 'subscriber_qty', 'charge_total', 'sponsor_amount', 'sponsor_ticket_qty'] as $key) {
        if (!array_key_exists($key, $row)) throw new RuntimeException('Requested-showing backing totals returned an incomplete result.');
        $value = roxy_rs_repo_canonical_quantity($row[$key]);
        if ($value === null) throw new RuntimeException('Requested-showing backing totals returned an invalid or overflowing value.');
        $totals[$key] = $value;
    }
    if (!array_key_exists('has_sponsor', $row)) throw new RuntimeException('Requested-showing backing totals returned an incomplete result.');
    $has_sponsor = $row['has_sponsor'];
    if ($has_sponsor === null) $totals['has_sponsor'] = false;
    elseif ($has_sponsor === 0 || $has_sponsor === '0') $totals['has_sponsor'] = false;
    elseif ($has_sponsor === 1 || $has_sponsor === '1') $totals['has_sponsor'] = true;
    else throw new RuntimeException('Requested-showing backing totals returned an invalid sponsor flag.');
    $has_any_totals = false;
    foreach (['support_qty', 'subscriber_qty', 'charge_total', 'sponsor_amount', 'sponsor_ticket_qty'] as $key) {
        if ($totals[$key] !== 0) { $has_any_totals = true; break; }
    }
    if (($has_sponsor === null && $has_any_totals)
        || (($totals['sponsor_amount'] > 0) !== $totals['has_sponsor'])) {
        throw new RuntimeException('Requested-showing backing totals returned inconsistent sponsorship values.');
    }
    return $totals;
}
