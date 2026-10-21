<?php
// Isolated actual WordPress/Woo/MySQL fixture; disconnects after one draft product insert.
if (!defined('WP_CLI') || !WP_CLI) exit;
if (!class_exists('WooCommerce') || !class_exists('RoxyST\\Products') || !class_exists('RoxyST\\Eligibility')) {
    throw new RuntimeException('Show Tickets product-sync dependencies are unavailable.');
}

global $wpdb;
$showing_id = 0;
$triggered = false;
$connection_before = 0;
$connection_after = 0;
$slug = '';
$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS: ' . $label . PHP_EOL;
};
$read_slug_products = static function () use (&$slug): array {
    return array_map('intval', get_posts([
        'post_type' => 'product',
        'post_status' => ['publish', 'private', 'draft', 'pending', 'future', 'trash'],
        'posts_per_page' => 10,
        'fields' => 'ids',
        'name' => $slug,
        'no_found_rows' => true,
        'cache_results' => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ]));
};

try {
    $title = 'PRIVATE PRODUCT CONNECTION LOSS ' . bin2hex(random_bytes(6));
    $showing_id = wp_insert_post([
        'post_type' => RoxyST\CPT::POST_TYPE,
        'post_status' => 'publish',
        'post_title' => $title,
        'post_content' => '',
        'post_author' => get_current_user_id(),
    ], true);
    if (is_wp_error($showing_id) || !$showing_id) throw new RuntimeException('Private showing fixture could not be created.');
    $showing_id = (int) $showing_id;
    if (get_post_status($showing_id) !== 'publish') throw new RuntimeException('Showing fixture must be published to exercise ticket eligibility.');
    update_post_meta($showing_id, '_roxy_pricing_profile', 'movie_evening');
    update_post_meta($showing_id, '_roxy_start', current_datetime()->modify('+2 days')->format('Y-m-d\\TH:i'));
    update_post_meta($showing_id, '_roxy_duration_minutes', '120');
    $slug = 'roxy-ticket-' . $showing_id . '-adult';

    $drop_after_insert = static function ($post_id, $post, $update) use (&$triggered, &$connection_before, &$connection_after, $slug, $wpdb): void {
        if ($triggered || !$post || $post->post_type !== 'product' || $post->post_name !== $slug || $update) return;
        $triggered = true;
        $connection_before = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
        if ($connection_before <= 0) throw new RuntimeException('Could not capture the initial MySQL connection ID.');
        $wpdb->close();
        if (!$wpdb->db_connect(false)) throw new RuntimeException('Fixture could not reconnect MySQL after disconnect.');
        $connection_after = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
        if ($connection_after <= 0 || $connection_after === $connection_before) throw new RuntimeException('Fixture did not obtain a replacement MySQL connection.');
    };

    add_action('save_post_product', $drop_after_insert, 1, 3);
    try {
        RoxyST\Products::ensure_products_for_showing($showing_id);
    } finally {
        remove_action('save_post_product', $drop_after_insert, 1);
    }

    $partial_ids = $read_slug_products();
    $check($triggered && $connection_after !== $connection_before, 'real MySQL connection is dropped after the first draft product insert');
    $check(count($partial_ids) === 1, 'partial synchronization leaves one recoverable product identity');
    $partial_id = (int) ($partial_ids[0] ?? 0);
    $partial_product = $partial_id > 0 ? wc_get_product($partial_id) : false;
    $check($partial_product instanceof WC_Product && !$partial_product->is_purchasable(), 'partially synchronized ticket product remains unavailable after the connection loss');
    $check((int) get_post_meta($showing_id, '_roxy_pid_adult', true) === 0, 'showing does not claim the partially synchronized product');
    $check((bool) wp_next_scheduled('roxy_st_sync_products_retry', [$showing_id, 1]), 'connection loss schedules the bounded product-sync retry');

    RoxyST\Products::ensure_products_for_showing($showing_id, 1);
    $recovered_id = (int) get_post_meta($showing_id, '_roxy_pid_adult', true);
    $recovered_product = $recovered_id > 0 ? wc_get_product($recovered_id) : false;
    $recovered_ids = $read_slug_products();
    $check($recovered_id === $partial_id && count($recovered_ids) === 1, 'retry reuses the same product instead of creating a duplicate');
    $check($recovered_product instanceof WC_Product && $recovered_product->get_status() === 'publish' && $recovered_product->is_purchasable(), 'successful retry repairs metadata and mapping before ticket sales become eligible');
    echo 'TICKET_PRODUCT_SYNC_CONNECTION_LOSS_OK' . PHP_EOL;
} finally {
    if (isset($drop_after_insert)) remove_action('save_post_product', $drop_after_insert, 1);
    if (empty($wpdb->dbh) && !$wpdb->db_connect(false)) throw new RuntimeException('Fixture cleanup could not restore the database connection.');
    if ($showing_id > 0) {
        for ($attempt = 1; $attempt <= 5; ++$attempt) wp_clear_scheduled_hook('roxy_st_sync_products_retry', [$showing_id, $attempt]);
        foreach ($read_slug_products() as $product_id) wp_delete_post($product_id, true);
        wp_delete_post($showing_id, true);
        clean_post_cache($showing_id);
    }
}
