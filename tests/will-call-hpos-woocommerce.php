<?php
/**
 * Private HPOS-backed Will Call list contract. Uses only synthetic zero-value
 * orders in the disposable CI store; no payment gateway or mail transport.
 */
if (!defined('WP_CLI') || !WP_CLI || !function_exists('wc_create_order')) {
    throw new RuntimeException('This fixture requires WP-CLI and active WooCommerce.');
}

$utility = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
if (!class_exists($utility) || !$utility::custom_orders_table_usage_is_enabled()) {
    throw new RuntimeException('This fixture requires authoritative HPOS storage.');
}
if (!function_exists('roxy_will_call_get_list')) {
    throw new RuntimeException('The Will Call module did not load in the HPOS fixture.');
}

$token = 'PRIVATE-HPOS-WILL-CALL-' . bin2hex(random_bytes(6));
$product_id = 0;
$order_ids = [];
$email_guard = static function () { return false; };
$check = static function ($condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};

try {
    add_filter('woocommerce_email_enabled_new_order', '__return_false', PHP_INT_MAX);
    add_filter('woocommerce_email_enabled_customer_processing_order', '__return_false', PHP_INT_MAX);
    add_filter('woocommerce_email_enabled_customer_completed_order', '__return_false', PHP_INT_MAX);
    add_filter('pre_wp_mail', $email_guard, PHP_INT_MAX, 2);

    $product = new WC_Product_Simple();
    $product->set_name($token . ' ticket');
    $product->set_status('private');
    $product->set_catalog_visibility('hidden');
    $product->set_regular_price('0');
    $product->set_price('0');
    $product_id = (int) $product->save();
    if ($product_id <= 0) throw new RuntimeException('Could not create private HPOS ticket product.');

    $make_order = static function (string $status, int $quantity, bool $matching) use (&$order_ids, $product, $token): WC_Order {
        $order = wc_create_order(['status' => $status, 'customer_note' => $token . ' zero-value fixture']);
        if (is_wp_error($order) || !$order instanceof WC_Order) throw new RuntimeException('Could not create private HPOS fixture order.');
        $order_ids[] = (int) $order->get_id();
        $order->set_billing_first_name($matching ? 'Matching Buyer' : 'Unrelated Buyer');
        $order->set_billing_last_name('Fixture');
        $order->set_billing_email($matching ? 'matching@example.invalid' : 'unrelated@example.invalid');
        if ($matching && $order->add_product($product, $quantity) <= 0) throw new RuntimeException('Could not add private HPOS ticket line.');
        $order->calculate_totals();
        $order->save();
        return $order;
    };

    $make_order('processing', 2, true);
    $make_order('completed', 1, true);
    $make_order('processing', 5, false);
    $make_order('cancelled', 4, true);

    $check(roxy_will_call_legacy_cpt_orders_enabled() === false,
        'HPOS uses the WooCommerce API scan, never the legacy CPT item-query optimization');
    $result = roxy_will_call_get_list([$product_id], [], true);
    $check(($result['totals']['total_qty'] ?? null) === 3,
        'HPOS CRUD item reads include processing and completed ticket quantities only');
    $check(($result['totals']['order_count'] ?? null) === 2,
        'HPOS list excludes unrelated and cancelled synthetic orders');
    $check(count($result['rows'] ?? []) === 1
        && ($result['rows'][0]['email'] ?? '') === 'matching@example.invalid'
        && ($result['rows'][0]['qty'] ?? null) === 3,
        'HPOS customer grouping preserves the exact matching ticket total');
    $check((float)($result['totals']['total_revenue'] ?? -1) === 0.0,
        'private fixture remains zero-value with no payment');
} finally {
    remove_filter('woocommerce_email_enabled_new_order', '__return_false', PHP_INT_MAX);
    remove_filter('woocommerce_email_enabled_customer_processing_order', '__return_false', PHP_INT_MAX);
    remove_filter('woocommerce_email_enabled_customer_completed_order', '__return_false', PHP_INT_MAX);
    remove_filter('pre_wp_mail', $email_guard, PHP_INT_MAX);
    if (function_exists('roxy_will_call_cache_key') && $product_id > 0) {
        delete_transient(roxy_will_call_cache_key([$product_id]));
    }
    foreach (array_reverse($order_ids) as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) continue;
        if ((string)$order->get_customer_note() !== $token . ' zero-value fixture'
            || (float)$order->get_total() !== 0.0
            || !in_array($order->get_status(), ['processing', 'completed', 'cancelled'], true)) {
            throw new RuntimeException('Refusing to remove an order not owned by this zero-value fixture.');
        }
        $order->delete(true);
        if (wc_get_order($order_id)) throw new RuntimeException('A private HPOS fixture order was not removed.');
    }
    if ($product_id > 0) {
        $product = wc_get_product($product_id);
        if ($product) {
            if ($product->get_name() !== $token . ' ticket' || $product->get_type() !== 'simple'
                || (float)$product->get_price() !== 0.0 || $product->get_status() !== 'private') {
                throw new RuntimeException('Refusing to remove a product not owned by this zero-value fixture.');
            }
            $product->delete(true);
        }
    }
}

echo "Passed private HPOS Will Call checks. No gateway or external mail transport was used.\n";
