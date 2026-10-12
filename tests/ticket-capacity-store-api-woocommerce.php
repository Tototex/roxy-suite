<?php
/** Private WP-CLI fixture: Store API capacity checks; no public products, orders, or payments. */
if (!defined('ABSPATH') || !class_exists('WC_Cart')) throw new RuntimeException('Loaded Woo required.');
global $wpdb;
$ids = [];
$saved = [WC()->cart, WC()->session, WC()->customer];
$checks = 0;
$assert = static function ($ok, $label) use (&$checks) {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
    $checks++;
};
try {
    foreach ([RoxyST\CPT::POST_TYPE, 'product'] as $type) {
        if (!$wpdb->insert($wpdb->posts, [
            'post_title' => 'PRIVATE Store API capacity fixture',
            'post_type' => $type,
            'post_status' => 'draft',
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', true),
        ])) throw new RuntimeException('Private fixture post could not be created.');
        $ids[] = (int) $wpdb->insert_id;
    }
    [$showing_id, $product_id] = $ids;
    foreach ([
        [$showing_id, '_roxy_capacity', 3],
        [$showing_id, '_roxy_start', current_datetime()->modify('+1 day')->format('Y-m-d\\TH:i')],
        [$product_id, ROXY_ST_META_SHOWING_ID, $showing_id],
        [$product_id, ROXY_ST_META_TICKET_TYPE, 'adult'],
    ] as [$id, $key, $value]) {
        if (!add_post_meta($id, $key, $value)) throw new RuntimeException('Private fixture metadata could not be created.');
    }
    // Keep this test focused on Capacity: the independent publication gate is covered separately.
    if (class_exists(RoxyST\Eligibility::class)) remove_filter('woocommerce_is_purchasable', [RoxyST\Eligibility::class, 'purchasable'], 99);
    RoxyST\Capacity::init();
    WC()->session = new WC_Session_Handler(); // Never initialize or persist a customer session.
    WC()->customer = new WC_Customer(0, false);
    WC()->cart = new WC_Cart();

    $product = new WC_Product_Simple();
    $product->set_id($product_id);
    $product->set_name('Private capacity fixture ticket');
    $product->set_status('publish');
    $product->set_price(12);
    $product->set_virtual(true);
    $product->set_tax_status('none');
    $controller = new Automattic\WooCommerce\StoreApi\Utilities\CartController();
    $request = new WP_REST_Request('POST', '/wc/store/v1/cart/add-item');
    $request->set_param('quantity', 4);
    wc_clear_notices();
    $blocked = false;
    $api_error = null;
    try { $controller->validate_add_to_cart($product, $request); }
    catch (Automattic\WooCommerce\StoreApi\Exceptions\RouteException $error) { $blocked = true; $api_error = $error; }
    $assert($blocked, 'Store API rejects an add-to-cart quantity above available capacity');
    if ($api_error instanceof Automattic\WooCommerce\StoreApi\Exceptions\RouteException) {
        echo 'Store API capacity error: ' . $api_error->error_code . ' — ' . wp_strip_all_tags($api_error->getMessage()) . "\n";
    }
    $assert($api_error instanceof Automattic\WooCommerce\StoreApi\Exceptions\RouteException
        && $api_error->error_code === 'woocommerce_rest_add_to_cart_error'
        && str_contains(wp_strip_all_tags($api_error->getMessage()), 'not have enough seats remaining'),
        'Store API response carries the expected seat-limit error');

    $request->set_param('quantity', 2);
    wc_clear_notices();
    $controller->validate_add_to_cart($product, $request);
    $assert(wc_notice_count('error') === 0, 'Store API accepts a quantity within available capacity');

    // Simulate a cart created while capacity was higher; changing the limit must block checkout.
    WC()->cart->cart_contents = ['private-capacity-fixture' => [
        'product_id' => $product_id, 'variation_id' => 0, 'variation' => [], 'quantity' => 2,
        'data' => $product, 'data_hash' => wc_get_cart_item_data_hash($product),
    ]];
    update_post_meta($showing_id, '_roxy_capacity', 1);
    wc_clear_notices();
    $blocked = false;
    try { $controller->validate_cart(); }
    catch (Automattic\WooCommerce\StoreApi\Exceptions\InvalidCartException $error) { $blocked = true; }
    $assert($blocked, 'Store API rejects checkout when a stale cart now exceeds capacity');
    $assert(wc_notice_count('error') === 0, 'Store API translates the capacity notice into its cart error without leaking notices');

    update_post_meta($showing_id, '_roxy_capacity', 3);
    wc_clear_notices();
    $controller->validate_cart();
    $assert(wc_notice_count('error') === 0, 'Store API allows checkout validation when capacity is available');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN (%d,%d) AND post_status='draft'", $showing_id, $product_id)) === 2, 'fixture records remain private drafts');
    echo "Passed $checks installed Store API capacity checks. No checkout, order, or payment created.\n";
} finally {
    foreach ($ids as $id) {
        $wpdb->delete($wpdb->postmeta, ['post_id' => $id]);
        $wpdb->delete($wpdb->posts, ['ID' => $id]);
        clean_post_cache($id);
    }
    [WC()->cart, WC()->session, WC()->customer] = $saved;
}
