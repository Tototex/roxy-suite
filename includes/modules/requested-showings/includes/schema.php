<?php
if (!defined('ABSPATH')) {
    exit;
}

function roxy_rs_table_backings(): string {
    global $wpdb;
    return $wpdb->prefix . 'roxy_requested_showing_backings';
}

function roxy_rs_install_schema(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = roxy_rs_table_backings();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        request_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        backing_type VARCHAR(16) NOT NULL DEFAULT 'backer',
        payment_token_id BIGINT UNSIGNED NULL,
        general_qty INT UNSIGNED NOT NULL DEFAULT 0,
        discount_qty INT UNSIGNED NOT NULL DEFAULT 0,
        subscriber_qty INT UNSIGNED NOT NULL DEFAULT 0,
        support_qty INT UNSIGNED NOT NULL DEFAULT 0,
        sponsor_amount INT NOT NULL DEFAULT 0,
        sponsor_ticket_qty INT UNSIGNED NOT NULL DEFAULT 0,
        charge_total INT NOT NULL DEFAULT 0,
        approved_showing_id BIGINT UNSIGNED NULL,
        woo_order_id BIGINT UNSIGNED NULL,
        charge_intent_id VARCHAR(190) NULL,
        admin_note TEXT NULL,
        PRIMARY KEY (id),
        KEY request_id (request_id),
        KEY user_id (user_id),
        KEY status (status),
        KEY backing_type (backing_type),
        KEY woo_order_id (woo_order_id)
    ) $charset;";

    dbDelta($sql);
}
