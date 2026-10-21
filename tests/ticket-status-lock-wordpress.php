<?php
// Private WordPress/MySQL status-transition lock fixture. No order/payment/email is created.
if (!defined('WP_CLI') || !WP_CLI) exit;
if (!class_exists('\RoxyST\Products') || !class_exists('\RoxyST\Issuance')) throw new RuntimeException('Show Tickets publication classes are unavailable.');
global $wpdb;
$showing_id = 0;
$product_id = 0;
$blocker = null;
$owned_locks = [];
$checks = 0;
$check = static function ($ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS: ' . $label . PHP_EOL;
    $checks++;
};
$lock_name = static function (int $id) use ($wpdb): string {
    return 'roxy_scope_' . substr(hash('sha256', $wpdb->prefix . ':walkup:' . $id), 0, 48);
};
$acquire_external = static function (string $name) use (&$blocker, &$owned_locks): void {
    $blocker = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    if ((string) $blocker->get_var($blocker->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
        throw new RuntimeException('Could not acquire independent fixture lock.');
    }
    $owned_locks[] = $name;
};
$release_external = static function () use (&$blocker, &$owned_locks): void {
    if (!$blocker) return;
    foreach (array_reverse($owned_locks) as $name) {
        $owner = (int) $blocker->get_var($blocker->prepare('SELECT IS_USED_LOCK(%s)', $name));
        if ($owner === (int) $blocker->get_var('SELECT CONNECTION_ID()')) {
            $blocker->get_var($blocker->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }
    $owned_locks = [];
    $blocker->close();
    $blocker = null;
};

try {
    $showing_id = wp_insert_post([
        'post_type' => \RoxyST\CPT::POST_TYPE,
        'post_status' => 'draft',
        'post_title' => 'PRIVATE showing status-lock fixture',
    ], true);
    if (is_wp_error($showing_id) || !$showing_id) throw new RuntimeException('Could not create private showing fixture.');
    update_post_meta($showing_id, '_roxy_start', wp_date('Y-m-d\TH:i', time() + 7200));
    update_post_meta($showing_id, '_roxy_duration_minutes', '60');

    $product = new WC_Product_Simple();
    $product->set_name('PRIVATE showing status-lock ticket fixture');
    $product->set_status('draft');
    $product->set_catalog_visibility('hidden');
    $product->set_regular_price('0');
    $product_id = (int) $product->save();
    if ($product_id <= 0) throw new RuntimeException('Could not create private ticket product fixture.');
    update_post_meta($showing_id, '_roxy_pid_adult', $product_id);
    update_post_meta($product_id, ROXY_ST_META_SHOWING_ID, $showing_id);
    update_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, 'adult');

    // Set up an existing public showing/product without invoking unrelated save hooks.
    if ($wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $showing_id]) !== 1
        || $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $product_id]) !== 1) {
        throw new RuntimeException('Could not prepare published private fixtures.');
    }
    clean_post_cache($showing_id);
    clean_post_cache($product_id);
    $name = $lock_name((int) $showing_id);

    $acquire_external($name);
    $blocked = \RoxyST\Products::guard_showing_status_change(
        ['post_type' => \RoxyST\CPT::POST_TYPE, 'post_status' => 'draft'],
        ['ID' => $showing_id],
        [],
        true
    );
    $check(($blocked['post_status'] ?? '') === 'publish', 'demotion refuses to change a showing while an independent confirmation owns its seat lock');
    $release_external();

    $allowed = \RoxyST\Products::guard_showing_status_change(
        ['post_type' => \RoxyST\CPT::POST_TYPE, 'post_status' => 'draft'],
        ['ID' => $showing_id],
        [],
        true
    );
    $check(($allowed['post_status'] ?? '') === 'draft', 'demotion acquires the real shared showing seat lock when it is available');
    if ($wpdb->update($wpdb->posts, ['post_status' => 'draft'], ['ID' => $showing_id]) !== 1) {
        throw new RuntimeException('Could not apply guarded fixture demotion.');
    }
    clean_post_cache($showing_id);
    \RoxyST\Products::on_status_changed('draft', 'publish', (object) ['ID' => $showing_id, 'post_type' => \RoxyST\CPT::POST_TYPE]);
    clean_post_cache($product_id);
    $check(get_post_status($product_id) === 'draft', 'guarded demotion deactivates the ticket product before releasing its seat lock');
    $check($wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $name)) === null, 'demotion releases its connection-owned named lock after product cleanup');

    $acquire_external($name);
    $blocked_delete = \RoxyST\Products::guard_showing_delete(null, get_post($showing_id));
    $check($blocked_delete === false, 'permanent deletion is refused while an independent confirmation owns the seat lock');
    $release_external();
    $delete_guard = \RoxyST\Products::guard_showing_delete(null, get_post($showing_id));
    $check($delete_guard === null, 'permanent deletion acquires the real shared showing seat lock when it is available');
    \RoxyST\Products::on_showing_deleted((int) $showing_id);
    $check($wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $name)) === null, 'deletion releases its connection-owned named lock after product cleanup');

    echo 'TICKET_STATUS_LOCK_WORDPRESS_OK checks=' . $checks . PHP_EOL;
} finally {
    $release_external();
    \RoxyST\Products::release_all_showing_change_leases();
    if ($product_id > 0) {
        $product = wc_get_product($product_id);
        if ($product) $product->delete(true);
    }
    if ($showing_id > 0) wp_delete_post($showing_id, true);
}
