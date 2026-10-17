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
        // Sponsor eligibility is a request-wide singleton. The frontend's
        // initial totals are only a display-time snapshot; recheck under the
        // same request lease used for every backing insert so two stale sponsor
        // submissions cannot both be accepted.
        if ((string) ($data['backing_type'] ?? '') === 'sponsor') {
            $totals = roxy_rs_repo_backing_totals($request_id);
            if (!empty($totals['has_sponsor'])) {
                return new WP_Error('sponsor_already_claimed', 'This request already has a sponsor. You can still back tickets or reserve subscriber seats.');
            }
        }
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

            $wpdb->last_error = '';
            $outstanding_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id,request_id,status,support_qty,general_qty,discount_qty,subscriber_qty,charge_total,sponsor_amount,sponsor_ticket_qty,woo_order_id,agreement_json,approved_showing_id
                 FROM $table WHERE request_id = %d AND user_id = %d AND status IN ('pending','threshold_met','approved','charged') ORDER BY id ASC",
                $request_id,
                $user_id
            ), ARRAY_A);
            $outstanding_qty = 0;
            $outstanding_valid = $wpdb->last_error === '' && is_array($outstanding_rows);
            foreach ($outstanding_rows ?: [] as $existing_backing) {
                try { $effective_backing = roxy_rs_repo_effective_backing($existing_backing); }
                catch (\Throwable $error) { $outstanding_valid = false; break; }
                if ($effective_backing['subscriber_qty'] > PHP_INT_MAX - $outstanding_qty) { $outstanding_valid = false; break; }
                $outstanding_qty += $effective_backing['subscriber_qty'];
            }
            if (!$outstanding_valid) {
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

/** Return manager-review entries for refunds against already converted backings. */
function roxy_rs_repo_converted_refund_review_entry(array $row): ?array {
    if ((string)($row['status'] ?? '') !== 'charged'
        || roxy_rs_repo_canonical_quantity($row['approved_showing_id'] ?? null) === null
        || (int)$row['approved_showing_id'] <= 0) return null;

    $backing_id = roxy_rs_repo_canonical_quantity($row['id'] ?? null);
    $request_id = roxy_rs_repo_canonical_quantity($row['request_id'] ?? null);
    $order_id = roxy_rs_repo_canonical_quantity($row['woo_order_id'] ?? null);
    $showing_id = roxy_rs_repo_canonical_quantity($row['approved_showing_id'] ?? null);
    $entry = [
        'backing_id' => $backing_id ?? 0,
        'request_id' => $request_id ?? 0,
        'order_id' => $order_id ?? 0,
        'showing_id' => $showing_id ?? 0,
        'verified' => false,
        'refund_total_cents' => null,
    ];
    try {
        if ($backing_id === null || $backing_id <= 0 || $request_id === null || $request_id <= 0
            || $order_id === null || $order_id <= 0 || $showing_id === null || $showing_id <= 0
            || !function_exists('wc_get_order')) throw new \RuntimeException('Converted backing identity is unavailable.');
        $order = wc_get_order($order_id);
        if (!$order || !method_exists($order, 'get_id') || (int)$order->get_id() !== $order_id
            || !method_exists($order, 'get_meta')
            || (int)$order->get_meta('_roxy_rs_request_id', true) !== $request_id
            || (int)$order->get_meta('_roxy_rs_backing_id', true) !== $backing_id
            || !method_exists($order, 'get_total') || !method_exists($order, 'get_total_refunded')) {
            throw new \RuntimeException('Converted backing order could not be verified.');
        }
        $total = roxy_rs_repo_money_cents($order->get_total());
        $refunded = roxy_rs_repo_money_cents($order->get_total_refunded());
        if ($refunded > $total) throw new \RuntimeException('Converted backing refund exceeds its order total.');
        $entry['verified'] = true;
        $entry['refund_total_cents'] = $refunded;
        return $refunded > 0 ? $entry : null;
    } catch (\Throwable $error) {
        // Do not let a failed refund/order read look like a clean converted backing.
        return $entry;
    }
}

/** Read-only refund review queue for the admin request editor. */
function roxy_rs_repo_converted_refund_reviews(int $request_id): array {
    $rows = roxy_rs_repo_list_backings_for_request($request_id);
    $reviews = [];
    foreach ($rows as $row) {
        $entry = roxy_rs_repo_converted_refund_review_entry($row);
        if ($entry !== null) $reviews[] = $entry;
    }
    return $reviews;
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
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,request_id,status,support_qty,general_qty,discount_qty,subscriber_qty,charge_total,sponsor_amount,sponsor_ticket_qty,woo_order_id,agreement_json,approved_showing_id
         FROM $table WHERE request_id = %d ORDER BY id ASC",
        $request_id
    ), ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($rows)) throw new RuntimeException('Requested-showing backing totals could not be read.');

    $totals = ['support_qty'=>0,'subscriber_qty'=>0,'charge_total'=>0,'sponsor_amount'=>0,'sponsor_ticket_qty'=>0,'has_sponsor'=>false,'refund_reviews'=>[]];
    foreach ($rows as $row) {
        if (!in_array((string)($row['status'] ?? ''), ['pending','threshold_met','approved','charged'], true)) continue;
        $effective = roxy_rs_repo_effective_backing($row);
        if ((string)($row['status'] ?? '') === 'charged' && (int)($row['approved_showing_id'] ?? 0) > 0
            && (int)($effective['refund_total_cents'] ?? 0) > 0) {
            $totals['refund_reviews'][] = [
                'backing_id' => (int)$row['id'],
                'request_id' => (int)$row['request_id'],
                'order_id' => (int)$row['woo_order_id'],
                'showing_id' => (int)$row['approved_showing_id'],
                'verified' => true,
                'refund_total_cents' => (int)$effective['refund_total_cents'],
            ];
        }
        foreach (['support_qty','subscriber_qty','charge_total','sponsor_amount','sponsor_ticket_qty'] as $key) {
            if ($effective[$key] > PHP_INT_MAX - $totals[$key]) throw new RuntimeException('Requested-showing backing totals exceed the supported range.');
            $totals[$key] += $effective[$key];
        }
        if ($effective['sponsor_amount'] > 0) $totals['has_sponsor'] = true;
    }
    return $totals;
}

/** Calculate a backing's current contribution from Woo's durable refund ledger. */
function roxy_rs_repo_effective_backing(array $row): array {
    $out = [];
    foreach (['general_qty','discount_qty','subscriber_qty','support_qty','charge_total','sponsor_amount','sponsor_ticket_qty'] as $key) {
        $value = roxy_rs_repo_canonical_quantity($row[$key] ?? null, in_array($key, ['charge_total','sponsor_amount'], true) ? 2147483647 : PHP_INT_MAX);
        if ($value === null) throw new RuntimeException('Requested-showing backing contains an invalid or overflowing value.');
        $out[$key] = $value;
    }
    if ($out['general_qty'] > PHP_INT_MAX - $out['discount_qty']
        || $out['support_qty'] !== $out['general_qty'] + $out['discount_qty']) {
        throw new RuntimeException('Requested-showing backing ticket quantities are inconsistent.');
    }
    $status = (string)($row['status'] ?? '');
    $out['refund_total_cents'] = 0;
    if ($status !== 'charged') return $out;
    $order_id = roxy_rs_repo_canonical_quantity($row['woo_order_id'] ?? null);
    if ($order_id === null || $order_id <= 0 || !function_exists('wc_get_order')) throw new RuntimeException('A charged backing order could not be verified.');
    $order = wc_get_order($order_id);
    if (!$order || !method_exists($order, 'get_id') || (int)$order->get_id() !== $order_id
        || !method_exists($order, 'get_meta')
        || (int)$order->get_meta('_roxy_rs_request_id', true) !== (int)($row['request_id'] ?? 0)
        || (int)$order->get_meta('_roxy_rs_backing_id', true) !== (int)($row['id'] ?? 0)
        || !method_exists($order, 'get_total') || !method_exists($order, 'get_total_refunded')
        || !method_exists($order, 'get_items') || !method_exists($order, 'get_qty_refunded_for_item')
        || !method_exists($order, 'get_total_refunded_for_item')) {
        throw new RuntimeException('A charged backing order or its refund ledger is unavailable.');
    }
    $agreement_json = (string)($row['agreement_json'] ?? '');
    $agreement = null;
    if ($agreement_json !== '') {
        if (!class_exists('\\RoxyRS\\Agreement')) throw new RuntimeException('Requested-showing agreement validation is unavailable.');
        try { $agreement = \RoxyRS\Agreement::validate($agreement_json, $row); }
        catch (\Throwable $error) { throw new RuntimeException('A charged backing agreement could not be verified.'); }
    }
    $order_total = roxy_rs_repo_money_cents($order->get_total());
    $refund_total = roxy_rs_repo_money_cents($order->get_total_refunded());
    if ($order_total !== $out['charge_total'] || $refund_total > $order_total) throw new RuntimeException('A charged backing payment total does not match its agreement.');
    $out['refund_total_cents'] = $refund_total;
    $out['charge_total'] -= $refund_total;

    $unit_prices = $agreement['unit_prices_cents'] ?? [];
    $profile = (string)($agreement['profile'] ?? '');
    $general_price = (int)($unit_prices[$profile === 'movie_matinee' ? 'matinee' : 'general'] ?? -1);
    $refunded_general = 0;
    $refunded_discount = 0;
    $refunded_subscriber = 0;
    $refunded_sponsor_tickets = 0;
    $ticket_refund_amount = 0;
    $fee_refund = 0;
    foreach ($order->get_items('line_item') as $item) {
        if (!is_object($item) || !method_exists($item,'get_id') || !method_exists($item,'get_quantity') || !method_exists($item,'get_meta') || !method_exists($item,'get_product_id')) throw new RuntimeException('A backing ticket line could not be verified.');
        $item_id = (int)$item->get_id();
        $quantity = roxy_rs_repo_whole_quantity($item->get_quantity());
        $refunded = roxy_rs_repo_whole_quantity(abs((float)$order->get_qty_refunded_for_item($item_id)));
        if ($item_id <= 0 || $quantity === null || $refunded === null || $refunded > $quantity) throw new RuntimeException('A backing ticket refund quantity is invalid.');
        $item_refund_amount = roxy_rs_repo_money_cents(abs((float)$order->get_total_refunded_for_item($item_id)));
        if ($item_refund_amount > PHP_INT_MAX - $ticket_refund_amount) throw new RuntimeException('Backing ticket refunds exceed the supported range.');
        $ticket_refund_amount += $item_refund_amount;
        if ($refunded === 0) continue;
        $role = (string)$item->get_meta('_roxy_rs_refund_role', true);
        if ($role === '') {
            $type_key = defined('ROXY_ST_META_TICKET_TYPE') ? ROXY_ST_META_TICKET_TYPE : '_roxy_ticket_type';
            $ticket_type = function_exists('get_post_meta') ? (string)get_post_meta((int)$item->get_product_id(), $type_key, true) : '';
            if (in_array($ticket_type, ['adult','matinee'], true)) $role = $out['sponsor_ticket_qty'] > 0 ? 'legacy_general' : 'paid_general';
            elseif ($ticket_type === 'discount') $role = 'paid_discount';
            elseif ($ticket_type === 'subscriber') $role = 'subscriber';
            else throw new RuntimeException('A legacy refunded ticket line cannot be identified.');
        }
        if ($role === 'paid_general') $refunded_general += $refunded;
        elseif ($role === 'paid_discount') $refunded_discount += $refunded;
        elseif ($role === 'subscriber') $refunded_subscriber += $refunded;
        elseif ($role === 'sponsor_ticket') $refunded_sponsor_tickets += $refunded;
        elseif ($role === 'legacy_general') {
            $amount = $item_refund_amount;
            if ($amount > 0) {
                if ($general_price <= 0) {
                    if (!method_exists($item,'get_total') || $out['general_qty'] <= 0) throw new RuntimeException('A legacy ticket refund cannot be allocated to paid ticket quantities.');
                    $original_paid_line = roxy_rs_repo_money_cents(abs((float)$item->get_total()));
                    if ($original_paid_line % $out['general_qty'] !== 0) throw new RuntimeException('A legacy ticket refund cannot be allocated to paid ticket quantities.');
                    $general_price = intdiv($original_paid_line, $out['general_qty']);
                }
                if ($general_price <= 0 || $amount % $general_price !== 0) throw new RuntimeException('A legacy ticket refund cannot be allocated to paid ticket quantities.');
                $paid_units = intdiv($amount, $general_price);
                if ($paid_units > $refunded) throw new RuntimeException('A legacy ticket refund amount and quantity do not match.');
                $refunded_general += $paid_units;
                $refunded_sponsor_tickets += $refunded - $paid_units;
            } elseif ($out['sponsor_ticket_qty'] > 0) {
                if ($out['general_qty'] > 0 && $general_price <= 0) throw new RuntimeException('A legacy zero-value mixed ticket refund cannot be allocated safely.');
                $refunded_sponsor_tickets += $refunded;
            } else {
                $refunded_general += $refunded;
            }
        } else throw new RuntimeException('A backing ticket line has an unknown refund role.');
    }
    if ($refunded_general > $out['general_qty'] || $refunded_discount > $out['discount_qty']
        || $refunded_subscriber > $out['subscriber_qty'] || $refunded_sponsor_tickets > $out['sponsor_ticket_qty']) {
        throw new RuntimeException('Backing refund quantities exceed the original ticket quantities.');
    }
    $out['general_qty'] -= $refunded_general;
    $out['discount_qty'] -= $refunded_discount;
    $out['support_qty'] = $out['general_qty'] + $out['discount_qty'];
    $out['subscriber_qty'] -= $refunded_subscriber;
    $out['sponsor_ticket_qty'] -= $refunded_sponsor_tickets;
    $sponsor_fee_total = 0;
    foreach ($order->get_items('fee') as $fee) {
        if (!is_object($fee) || !method_exists($fee,'get_id') || !method_exists($fee,'get_name')) throw new RuntimeException('A backing sponsorship fee could not be verified.');
        if ($fee->get_name() !== 'Requested showing sponsorship') continue;
        if (!method_exists($fee,'get_total')) throw new RuntimeException('A backing sponsorship fee could not be verified.');
        $sponsor_fee_total += roxy_rs_repo_money_cents(abs((float)$fee->get_total()));
        $fee_refund += roxy_rs_repo_money_cents(abs((float)$order->get_total_refunded_for_item((int)$fee->get_id())));
    }
    if ($sponsor_fee_total !== $out['sponsor_amount']) throw new RuntimeException('A backing sponsorship fee does not match its saved pledge.');
    if ($fee_refund > $out['sponsor_amount']) throw new RuntimeException('A sponsorship refund exceeds the original pledge.');
    if ($ticket_refund_amount > PHP_INT_MAX - $fee_refund) throw new RuntimeException('Backing refunds exceed the supported range.');
    $allocated_refunds = $ticket_refund_amount + $fee_refund;
    if ($allocated_refunds > $refund_total) throw new RuntimeException('Woo refund line allocations exceed the refunded payment total.');
    // Woo permits an amount-only refund without line allocations. A complete
    // refund clears the paid backing quantities and sponsorship; a partial
    // refund cannot say which ticket quantities were returned, so fail closed
    // until a manager resolves it with itemized lines.
    $unallocated_refund = $refund_total - $allocated_refunds;
    if ($unallocated_refund > 0) {
        if ($refund_total === $order_total) {
            $fee_refund = $out['sponsor_amount'];
            $out['general_qty'] = 0;
            $out['discount_qty'] = 0;
            $out['support_qty'] = 0;
            $out['sponsor_ticket_qty'] = 0;
        } else {
            throw new RuntimeException('A partial amount-only refund is not itemized and requires manager review.');
        }
    }
    $out['sponsor_amount'] -= $fee_refund;
    return $out;
}

function roxy_rs_repo_money_cents($value): int {
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) throw new RuntimeException('A backing payment amount is invalid.');
    $cents = (float)$value * 100;
    if (!is_finite($cents) || $cents > 2147483647 || abs($cents - round($cents)) > 0.00001) throw new RuntimeException('A backing payment amount is outside the supported range.');
    return (int)round($cents);
}

function roxy_rs_repo_whole_quantity($value): ?int {
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || floor((float)$value) !== (float)$value || (float)$value > PHP_INT_MAX) return null;
    return (int)$value;
}
