<?php
if (!defined('ABSPATH')) {
    exit;
}

function roxy_rs_now_mysql(): string {
    return current_time('mysql');
}

function roxy_rs_repo_insert_backing(array $data) {
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
        'approved_showing_id' => null,
        'woo_order_id' => null,
        'charge_intent_id' => null,
        'admin_note' => null,
    ], $data);

    $ok = $wpdb->insert($table, $row);
    if (!$ok) {
        return new WP_Error('db_insert_failed', $wpdb->last_error ?: 'Could not save backing.');
    }

    return (int) $wpdb->insert_id;
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
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
    return $row ?: null;
}

function roxy_rs_repo_list_backings_for_request(int $request_id, array $statuses = []): array {
    global $wpdb;
    $table = roxy_rs_table_backings();
    if (!$statuses) {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE request_id = %d ORDER BY id ASC", $request_id), ARRAY_A);
        return $rows ?: [];
    }

    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
    $sql = $wpdb->prepare(
        "SELECT * FROM $table WHERE request_id = %d AND status IN ($placeholders) ORDER BY id ASC",
        array_merge([$request_id], array_values($statuses))
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    return $rows ?: [];
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

    return [
        'support_qty' => (int) ($row['support_qty'] ?? 0),
        'subscriber_qty' => (int) ($row['subscriber_qty'] ?? 0),
        'charge_total' => (int) ($row['charge_total'] ?? 0),
        'sponsor_amount' => (int) ($row['sponsor_amount'] ?? 0),
        'sponsor_ticket_qty' => (int) ($row['sponsor_ticket_qty'] ?? 0),
        'has_sponsor' => !empty($row['has_sponsor']),
    ];
}
