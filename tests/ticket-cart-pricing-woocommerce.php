<?php
/** WP-CLI eval-file fixture; no checkout, customer session save, or payment. */
if (!defined('ABSPATH') || !class_exists('WC_Cart')) throw new RuntimeException('Loaded Woo required.');
require_once $args[0];
global $wpdb;
$showing = 0;
$saved = [WC()->cart, WC()->session, WC()->customer];
$checks = 0;
$assert = static function ($ok, $label) use (&$checks) {
    if (!$ok) throw new RuntimeException($label);
    echo 'ok - ' . $label . "\n"; $checks++;
};
$reset = static function () {
    foreach (['price_changed_in_request'=>false, 'review_notice_added'=>false, 'changes'=>[]] as $property=>$value) {
        $r = new ReflectionProperty(RoxyST\CartPricing::class, $property);
        $r->setAccessible(true); $r->setValue(null, $value);
    }
};
// Exercise installed Store API's legacy-notice bridge without unrelated item/coupon
// eligibility validation: fixture product is in memory, never publicly published.
class RoxyPricingFixtureController extends Automattic\WooCommerce\StoreApi\Utilities\CartController {
    public function validate_cart_items() {}
    public function validate_cart_coupons() {}
}
try {
    $wpdb->insert($wpdb->posts, [
        'post_title'=>'PRIVATE Roxy pricing fixture', 'post_type'=>RoxyST\CPT::POST_TYPE,
        'post_status'=>'draft', 'post_date'=>current_time('mysql'), 'post_date_gmt'=>current_time('mysql', true),
    ]);
    $showing = (int)$wpdb->insert_id;
    $assert($showing > 0, 'private showing fixture created without publication hooks');
    $pid = 2147483001;
    $metadata = static function ($value, $id, $key, $single) use ($pid, $showing) {
        if ((int)$id === $pid && $key === ROXY_ST_META_SHOWING_ID) return $single ? $showing : [$showing];
        if ((int)$id === $pid && $key === ROXY_ST_META_TICKET_TYPE) return $single ? 'adult' : ['adult'];
        if ((int)$id === $showing && $key === '_roxy_pricing_profile') return $single ? 'movie_evening' : ['movie_evening'];
        return $value;
    };
    add_filter('get_post_metadata', $metadata, 10, 4);
    $settings = static function ($value) { $value['general_price'] = 14; return $value; };
    add_filter('option_' . RoxyST\Settings::OPTION_KEY, $settings);
    WC()->session = new WC_Session_Handler(); // Do not init/save a customer session.
    WC()->customer = new WC_Customer(0, false);
    WC()->cart = new WC_Cart();
    $cart = WC()->cart;
    $product = new WC_Product_Simple();
    $product->set_id($pid); $product->set_name('Private fixture ticket');
    $product->set_price(12); $product->set_regular_price(12);
    $product->set_virtual(true); $product->set_tax_status('none');
    $cart->cart_contents = ['fixture'=>[
        'product_id'=>$pid, 'variation_id'=>0, 'variation'=>[], 'quantity'=>2,
        'data'=>$product, 'data_hash'=>wc_get_cart_item_data_hash($product),
    ]];
    // CLI has no browser CAPTCHA and this draft is intentionally not saleable.
    // Isolate unrelated checkout validators in this subprocess, never on live requests.
    remove_all_actions('woocommerce_checkout_process');
    remove_all_actions('woocommerce_check_cart_items');
    RoxyST\CartPricing::init();
    $reset(); wc_clear_notices();
    $cart->calculate_totals();
    $assert((float)$product->get_price() === 14.0, 'actual Woo totals use current ticket price');
    $assert((float)$cart->get_subtotal() === 28.0 && (float)$cart->get_total('edit') === 28.0, 'actual quantity totals recalculate');
    $assert(isset($cart->get_cart_for_session()['fixture']['_roxy_st_cart_price_baseline']), 'Woo session serialization retains pricing baseline');
    do_action('woocommerce_checkout_process');
    $errors = wc_get_notices('error');
    $assert(count($errors) === 1 && strpos(wp_strip_all_tags($errors[0]['notice']), '12.00') !== false, 'classic checkout halts with detailed old price');
    $reset(); wc_clear_notices(); $cart->calculate_totals();
    do_action('woocommerce_checkout_process');
    $assert(wc_notice_count('error') === 0, 'classic checkout can continue on reviewed next request');
    // Return to an old quote for a separate Store API request simulation.
    $cart->cart_contents['fixture']['_roxy_st_cart_price_baseline'] = 12;
    $reset(); wc_clear_notices(); $cart->calculate_totals();
    $controller = new RoxyPricingFixtureController();
    $caught = false;
    try { $controller->validate_cart(); }
    catch (Automattic\WooCommerce\StoreApi\Exceptions\InvalidCartException $e) {
        $caught = true;
        $message = implode(' ', $e->getError()->get_error_messages());
        $assert($e->getCode() === 409 && strpos($message, '12.00') !== false && strpos($message, '14.00') !== false, 'Store API review exception includes old and new prices with 409');
    }
    $assert($caught, 'installed Store API rejects changed-price request before payment');
    $assert(wc_notice_count('error') === 0, 'Store API restores notices without a sticky review error');
    $reset(); wc_clear_notices(); $cart->calculate_totals(); $controller->validate_cart();
    $assert(wc_notice_count('error') === 0, 'Store API next reviewed request passes price gate');
    $coupon = static function ($data, $code) {
        return $code === 'roxy-private-pricing-fixture' ? ['discount_type'=>'percent', 'amount'=>25] : $data;
    };
    add_filter('woocommerce_get_shop_coupon_data', $coupon, 10, 2);
    $cart->set_applied_coupons(['roxy-private-pricing-fixture']);
    $cart->calculate_totals();
    $assert((float)$cart->get_subtotal() === 28.0 && (float)$cart->get_discount_total() === 7.0 && (float)$cart->get_total('edit') === 21.0, 'actual Woo coupon discount applies to current price');
    $assert(wc_notice_count('error') === 0, 'coupon recalculation does not repeat price gate');
    echo "Passed $checks installed-Woo pricing checks. No order or payment created.\n";
} finally {
    if (isset($metadata)) remove_filter('get_post_metadata', $metadata, 10);
    if (isset($settings)) remove_filter('option_' . RoxyST\Settings::OPTION_KEY, $settings);
    if (isset($coupon)) remove_filter('woocommerce_get_shop_coupon_data', $coupon, 10);
    if ($showing) {
        $wpdb->delete($wpdb->posts, ['ID'=>$showing]); clean_post_cache($showing);
    }
    [WC()->cart, WC()->session, WC()->customer] = $saved;
}
