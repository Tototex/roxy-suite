<?php
if (!defined('ABSPATH')) exit;

/** Prevent a completed classic no-payment checkout restoring its saved cart. */
final class Roxy_Suite_Checkout {
    private static ?array $completed_cart = null;

    public static function boot(): void {
        add_action('woocommerce_before_cart_emptied', [__CLASS__, 'capture'], 10, 1);
        add_filter('woocommerce_checkout_no_payment_needed_redirect', [__CLASS__, 'complete'], 10, 2);
    }

    private static function identity(array $cart): array {
        foreach ($cart as &$item) {
            if (!is_array($item)) continue;
            // Saved carts can precede coupon/tax recalculation. Preserve every other field.
            unset($item['line_subtotal'], $item['line_subtotal_tax'], $item['line_total'], $item['line_tax'], $item['line_tax_data']);
        }
        unset($item);
        return $cart;
    }

    public static function capture($clear_persistent): void {
        self::$completed_cart = null;
        if ($clear_persistent || !function_exists('WC') || !class_exists('WC_Cart_Session')) return;
        $user = get_current_user_id();
        $cart = WC()->cart;
        if ($user <= 0 || !$cart || $cart->is_empty()) return;
        $key = '_woocommerce_persistent_cart_' . get_current_blog_id();
        $saved = get_user_meta($user, $key, true);
        $session = new WC_Cart_Session($cart);
        if (!is_array($saved) || empty($saved['cart']) || !is_array($saved['cart']) || self::identity($saved['cart']) !== self::identity($session->get_cart_for_session())) return;
        self::$completed_cart = ['user'=>$user, 'key'=>$key, 'saved'=>$saved, 'hash'=>$cart->get_cart_hash()];
    }

    public static function complete($redirect, $order) {
        $snapshot = self::$completed_cart;
        self::$completed_cart = null;
        if (!$snapshot || !($order instanceof WC_Order) || (float)$order->get_total() !== 0.0 || !$order->is_paid()) return $redirect;
        if (get_current_user_id() !== $snapshot['user'] || (int)$order->get_customer_id() !== $snapshot['user'] || !$order->has_cart_hash($snapshot['hash'])) return $redirect;
        if (!WC()->cart || !WC()->cart->is_empty()) return $redirect;
        // Match inside the DELETE itself. WP's metadata delete selects IDs first,
        // leaving a race where another request can change those IDs before deletion.
        global $wpdb;
        $removed = $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->usermeta}` WHERE user_id=%d AND meta_key=%s AND meta_value=%s", $snapshot['user'], $snapshot['key'], maybe_serialize($snapshot['saved'])));
        wp_cache_delete($snapshot['user'], 'user_meta');
        if ($removed === false) {
            error_log('Roxy Suite: completed zero-dollar saved cart could not be removed.');
        }
        return $redirect;
    }
}
