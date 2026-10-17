<?php
if (!defined('ABSPATH')) {
    exit;
}

function roxy_rs_table_backings(): string {
    global $wpdb;
    return $wpdb->prefix . 'roxy_requested_showing_backings';
}

function roxy_rs_table_payment_attempts(): string {
    global $wpdb;
    return $wpdb->prefix . 'roxy_rs_payment_attempts';
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
        agreement_json LONGTEXT NULL,
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
    $attempts = roxy_rs_table_payment_attempts();
    $attempts_sql = "CREATE TABLE $attempts (
        order_id BIGINT UNSIGNED NOT NULL,
        attempt_json LONGTEXT NOT NULL,
        result_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (order_id)
    ) $charset;";
    dbDelta($attempts_sql);
    $wpdb->last_error = '';
    $agreement_column = $wpdb->get_var("SHOW COLUMNS FROM `$table` LIKE 'agreement_json'");
    if ($wpdb->last_error !== '' || $agreement_column !== 'agreement_json') {
        throw new RuntimeException('Requested-showing agreement storage was not installed.');
    }
    $wpdb->last_error = '';
    $attempt_engine = $wpdb->get_var($wpdb->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
        $attempts
    ));
    if ($wpdb->last_error !== '' || $attempt_engine !== 'InnoDB') {
        throw new RuntimeException('Requested-showing payment-attempt storage was not installed as InnoDB.');
    }
    $wpdb->last_error = '';
    $primary = $wpdb->get_results("SHOW INDEX FROM `$attempts` WHERE Key_name='PRIMARY'", ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($primary) || count($primary) !== 1
        || (string) ($primary[0]['Column_name'] ?? '') !== 'order_id'
        || (int) ($primary[0]['Non_unique'] ?? 1) !== 0
        || (int) ($primary[0]['Seq_in_index'] ?? 0) !== 1) {
        throw new RuntimeException('Requested-showing payment-attempt uniqueness constraint was not installed.');
    }
    foreach (['order_id', 'attempt_json', 'result_json', 'created_at', 'updated_at'] as $column) {
        $wpdb->last_error = '';
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$attempts` LIKE %s", $column));
        if ($wpdb->last_error !== '' || $found !== $column) {
            throw new RuntimeException('Requested-showing payment-attempt schema is incomplete.');
        }
    }
}
