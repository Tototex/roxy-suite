<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_table_bookings() {
    global $wpdb;
    return $wpdb->prefix . 'roxy_event_bookings';
}

function roxy_eb_table_blocks() {
    global $wpdb;
    return $wpdb->prefix . 'roxy_event_blocks';
}

function roxy_eb_table_sling_logs() {
    global $wpdb;
    return $wpdb->prefix . 'roxy_event_sling_logs';
}

function roxy_eb_install_schema() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();

    $bookings = roxy_eb_table_bookings();
    $blocks   = roxy_eb_table_blocks();
    $logs     = roxy_eb_table_sling_logs();

    $sql1 = "CREATE TABLE $bookings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'confirmed',
        wp_user_id BIGINT UNSIGNED NULL,
        customer_first_name VARCHAR(100) NOT NULL,
        customer_last_name VARCHAR(100) NOT NULL,
        customer_email VARCHAR(190) NOT NULL,
        customer_phone VARCHAR(50) NOT NULL,
        customer_type VARCHAR(16) NOT NULL DEFAULT 'personal',
        business_name VARCHAR(190) NULL,
        payment_method VARCHAR(16) NOT NULL DEFAULT 'pay_now',
        invoice_status VARCHAR(16) NOT NULL DEFAULT 'not_needed',
        guest_count INT UNSIGNED NOT NULL,
        tier VARCHAR(32) NOT NULL,
        staff_shifts_required TINYINT UNSIGNED NOT NULL DEFAULT 1,
        event_format VARCHAR(16) NOT NULL,
        movie_title VARCHAR(255) NULL,
        live_description TEXT NULL,
        visibility VARCHAR(16) NOT NULL,
        doors_open_at DATETIME NOT NULL,
        show_start_at DATETIME NOT NULL,
        doors_close_at DATETIME NOT NULL,
        reserved_start_at DATETIME NOT NULL,
        reserved_end_at DATETIME NOT NULL,
        extra_hours INT NOT NULL DEFAULT 0,
        base_price INT NOT NULL DEFAULT 0,
        extra_price INT NOT NULL DEFAULT 0,
        pizza_requested TINYINT UNSIGNED NOT NULL DEFAULT 0,
        pizza_quantity INT UNSIGNED NOT NULL DEFAULT 0,
        pizza_order_details TEXT NULL,
        pizza_total INT NOT NULL DEFAULT 0,
        pizza_checked_at DATETIME NULL,
        pizza_checked_by BIGINT UNSIGNED NULL,
        bulk_concessions_requested TINYINT UNSIGNED NOT NULL DEFAULT 0,
        bulk_popcorn_qty INT UNSIGNED NOT NULL DEFAULT 0,
        bulk_soda_qty INT UNSIGNED NOT NULL DEFAULT 0,
        bulk_concessions_total INT NOT NULL DEFAULT 0,
        total_price INT NOT NULL DEFAULT 0,
        woo_order_id BIGINT UNSIGNED NULL,
        sling_shift_ids TEXT NULL,
        sling_status VARCHAR(32) NULL,
        sling_error TEXT NULL,
        notes_admin TEXT NULL,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY doors_open_at (doors_open_at),
        KEY reserved_start_at (reserved_start_at),
        KEY reserved_end_at (reserved_end_at),
        KEY woo_order_id (woo_order_id),
        KEY customer_email (customer_email),
        KEY wp_user_id (wp_user_id),
        KEY pizza_requested (pizza_requested),
        KEY invoice_status (invoice_status),
        KEY payment_method (payment_method),
        KEY bulk_concessions_requested (bulk_concessions_requested)
    ) $charset;";

    $sql2 = "CREATE TABLE $blocks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        type VARCHAR(32) NOT NULL DEFAULT 'manual_event',
        title VARCHAR(255) NOT NULL,
        visibility VARCHAR(16) NOT NULL DEFAULT 'private',
        note TEXT NULL,
        start_at DATETIME NOT NULL,
        end_at DATETIME NOT NULL,
        created_by BIGINT UNSIGNED NULL,
        PRIMARY KEY (id),
        KEY start_at (start_at),
        KEY end_at (end_at),
        KEY type (type)
    ) $charset;";

    $sql3 = "CREATE TABLE $logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        booking_id BIGINT UNSIGNED NULL,
        action VARCHAR(32) NOT NULL,
        endpoint VARCHAR(255) NOT NULL,
        http_code INT NULL,
        message TEXT NULL,
        request_json LONGTEXT NULL,
        response_body LONGTEXT NULL,
        PRIMARY KEY (id),
        KEY booking_id (booking_id),
        KEY created_at (created_at),
        KEY action (action)
    ) $charset;";

    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);
}
