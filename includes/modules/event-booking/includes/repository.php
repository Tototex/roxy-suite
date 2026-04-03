<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_now_mysql() {
    return current_time('mysql');
}

function roxy_eb_datetime_to_mysql($dt) {
    return $dt->format('Y-m-d H:i:s');
}

function roxy_eb_mysql_to_dt($mysql) {
    $tz = wp_timezone();
    return new DateTimeImmutable($mysql, $tz);
}

function roxy_eb_repo_insert_booking($data) {
    global $wpdb;
    $table = roxy_eb_table_bookings();
    $now = roxy_eb_now_mysql();

    $defaults = [
        'created_at' => $now,
        'updated_at' => $now,
        'status' => 'confirmed',
        'wp_user_id' => null,
        'customer_first_name' => '',
        'customer_last_name' => '',
        'customer_email' => '',
        'customer_phone' => '',
        'customer_type' => 'personal',
        'business_name' => null,
        'payment_method' => 'pay_now',
        'invoice_status' => 'not_needed',
        'guest_count' => 0,
        'tier' => '',
        'staff_shifts_required' => 1,
        'event_format' => 'movie',
        'movie_title' => null,
        'live_description' => null,
        'visibility' => 'private',
        'doors_open_at' => $now,
        'show_start_at' => $now,
        'doors_close_at' => $now,
        'reserved_start_at' => $now,
        'reserved_end_at' => $now,
        'extra_hours' => 0,
        'base_price' => 0,
        'extra_price' => 0,
        'pizza_requested' => 0,
        'pizza_quantity' => 0,
        'pizza_order_details' => null,
        'pizza_total' => 0,
        'pizza_checked_at' => null,
        'pizza_checked_by' => null,
        'bulk_concessions_requested' => 0,
        'bulk_popcorn_qty' => 0,
        'bulk_soda_qty' => 0,
        'bulk_concessions_total' => 0,
        'total_price' => 0,
        'woo_order_id' => null,
        'sling_shift_ids' => null,
        'sling_status' => null,
        'sling_error' => null,
        'notes_admin' => null,
    ];
    $row = array_merge($defaults, $data);

    $ok = $wpdb->insert($table, $row);
    if (!$ok) return new WP_Error('db_insert_failed', $wpdb->last_error);

    return intval($wpdb->insert_id);
}

function roxy_eb_repo_update_booking($id, $data) {
    global $wpdb;
    $table = roxy_eb_table_bookings();
    $data['updated_at'] = roxy_eb_now_mysql();
    $ok = $wpdb->update($table, $data, ['id' => intval($id)]);
    if ($ok === false) return new WP_Error('db_update_failed', $wpdb->last_error);
    return true;
}

function roxy_eb_repo_get_booking($id) {
    global $wpdb;
    $table = roxy_eb_table_bookings();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", intval($id)), ARRAY_A);
    return $row ?: null;
}

function roxy_eb_repo_get_booking_by_order($order_id) {
    global $wpdb;
    $table = roxy_eb_table_bookings();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE woo_order_id=%d ORDER BY id DESC LIMIT 1", intval($order_id)), ARRAY_A);
    return $row ?: null;
}

function roxy_eb_repo_list_bookings_for_user($wp_user_id, $email) {
    global $wpdb;
    $table = roxy_eb_table_bookings();
    $wp_user_id = intval($wp_user_id);
    $email = sanitize_email($email);
    if ($wp_user_id > 0) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE (wp_user_id=%d) OR (customer_email=%s) ORDER BY doors_open_at DESC",
            $wp_user_id, $email
        ), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE customer_email=%s ORDER BY doors_open_at DESC",
            $email
        ), ARRAY_A);
    }
    return $rows ?: [];
}

function roxy_eb_repo_list_bookings_in_range($start_mysql, $end_mysql) {
    global $wpdb;
    $table = roxy_eb_table_bookings();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,status,reserved_start_at,reserved_end_at,doors_open_at,doors_close_at,visibility,customer_last_name,guest_count
         FROM $table
         WHERE status IN ('confirmed','pending','pending_invoice')
         AND reserved_start_at < %s AND reserved_end_at > %s
         ORDER BY reserved_start_at ASC",
        $end_mysql, $start_mysql
    ), ARRAY_A);
    return $rows ?: [];
}

function roxy_eb_repo_insert_block($data) {
    global $wpdb;
    $table = roxy_eb_table_blocks();
    $now = roxy_eb_now_mysql();
    $defaults = [
        'created_at' => $now,
        'updated_at' => $now,
        'type' => 'manual_event',
        'title' => '',
        'visibility' => 'private',
        'note' => null,
        'start_at' => $now,
        'end_at' => $now,
        'created_by' => get_current_user_id() ?: null,
    ];
    $row = array_merge($defaults, $data);
    $ok = $wpdb->insert($table, $row);
    if (!$ok) return new WP_Error('db_insert_failed', $wpdb->last_error);
    return intval($wpdb->insert_id);
}

function roxy_eb_repo_update_block($id, $data) {
    global $wpdb;
    $table = roxy_eb_table_blocks();
    $data['updated_at'] = roxy_eb_now_mysql();
    $ok = $wpdb->update($table, $data, ['id' => intval($id)]);
    if ($ok === false) return new WP_Error('db_update_failed', $wpdb->last_error);
    return true;
}

function roxy_eb_repo_delete_block($id) {
    global $wpdb;
    $table = roxy_eb_table_blocks();
    $ok = $wpdb->delete($table, ['id' => intval($id)]);
    if ($ok === false) return new WP_Error('db_delete_failed', $wpdb->last_error);
    return true;
}

function roxy_eb_repo_list_blocks_in_range($start_mysql, $end_mysql) {
    global $wpdb;
    $table = roxy_eb_table_blocks();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table
         WHERE start_at < %s AND end_at > %s
         ORDER BY start_at ASC",
        $end_mysql, $start_mysql
    ), ARRAY_A);
    return $rows ?: [];
}

function roxy_eb_repo_insert_sling_log($data) {
    global $wpdb;
    $table = roxy_eb_table_sling_logs();
    $now = roxy_eb_now_mysql();

    $row = [
        'created_at' => $now,
        'booking_id' => isset($data['booking_id']) ? intval($data['booking_id']) : null,
        'action' => sanitize_text_field($data['action'] ?? ''),
        'endpoint' => sanitize_text_field($data['endpoint'] ?? ''),
        'http_code' => isset($data['http_code']) ? intval($data['http_code']) : null,
        'message' => isset($data['message']) ? wp_strip_all_tags((string)$data['message']) : null,
        'request_json' => isset($data['request_json']) ? (string)$data['request_json'] : null,
        'response_body' => isset($data['response_body']) ? (string)$data['response_body'] : null,
    ];

    foreach (['request_json','response_body'] as $k) {
        if (!empty($row[$k])) {
            $row[$k] = preg_replace('/Authorization\s*[:=]\s*[^"\s]+/i', 'Authorization: [REDACTED]', $row[$k]);
        }
    }

    $ok = $wpdb->insert($table, $row);
    if (!$ok) return new WP_Error('db_insert_failed', $wpdb->last_error);
    return intval($wpdb->insert_id);
}

function roxy_eb_repo_list_sling_logs($limit = 200, $booking_id = 0) {
    global $wpdb;
    $table = roxy_eb_table_sling_logs();
    $limit = max(1, min(500, intval($limit)));
    $booking_id = intval($booking_id);

    if ($booking_id > 0) {
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE booking_id=%d ORDER BY id DESC LIMIT %d", $booking_id, $limit),
            ARRAY_A
        );
    } else {
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );
    }

    return $rows ?: [];
}
