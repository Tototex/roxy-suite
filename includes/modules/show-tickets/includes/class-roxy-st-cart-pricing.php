<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

/** Reprice showing-ticket cart lines from the current showing configuration. */
class CartPricing {
  private const BASELINE_KEY = '_roxy_st_cart_price_baseline';
  private static bool $price_changed_in_request = false;
  private static bool $review_notice_added = false;
  private static array $changes = [];

  public static function init(): void {
    add_action('woocommerce_before_calculate_totals', [__CLASS__, 'reprice'], 20, 1);
    add_action('woocommerce_check_cart_items', [__CLASS__, 'require_price_review'], 9999, 0);
    add_action('woocommerce_checkout_process', [__CLASS__, 'require_price_review'], 9999, 0);
  }

  public static function reprice($cart): void {
    if (!is_object($cart) || !method_exists($cart, 'get_cart')) return;

    // WooCommerce exposes cart_contents publicly; updating it preserves the baseline in the session.
    if (isset($cart->cart_contents) && is_array($cart->cart_contents)) {
      foreach ($cart->cart_contents as $cart_key => &$item) {
        self::reprice_item($item, (string) $cart_key);
      }
      unset($item);
      return;
    }

    foreach ($cart->get_cart() as $cart_key => $item) {
      self::reprice_item($item, (string) $cart_key);
    }
  }

  private static function reprice_item(array &$item, string $cart_key): void {
    $product = $item['data'] ?? null;
    if (!is_object($product) || !method_exists($product, 'get_price') || !method_exists($product, 'set_price')) return;

    $product_id = (int) ($item['product_id'] ?? (method_exists($product, 'get_id') ? $product->get_id() : 0));
    if ($product_id <= 0 || !defined('ROXY_ST_META_SHOWING_ID') || !defined('ROXY_ST_META_TICKET_TYPE')) return;

    $showing_id = (int) get_post_meta($product_id, ROXY_ST_META_SHOWING_ID, true);
    $ticket_type = (string) get_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, true);
    $price = self::current_price($showing_id, $ticket_type);
    if ($price === null) return;

    $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
    $price = round(max(0.0, $price), $decimals);
    $current = round((float) $product->get_price(), $decimals);
    $baseline = isset($item[self::BASELINE_KEY]) && is_numeric($item[self::BASELINE_KEY])
      ? round((float) $item[self::BASELINE_KEY], $decimals)
      : $current;
    // Legacy sessions contain the previously quoted pre-coupon, ex-tax subtotal
    // even when Woo has reloaded a product whose stored price already changed.
    if (!isset($item[self::BASELINE_KEY]) && isset($item['line_subtotal'], $item['quantity']) && is_numeric($item['line_subtotal']) && (float) $item['quantity'] > 0) {
      $baseline = round((float) $item['line_subtotal'] / (float) $item['quantity'], $decimals);
    }

    if ($baseline !== $price) {
      self::$price_changed_in_request = true;
      self::add_price_change_notice($product, $baseline, $price, $cart_key);
    }
    if ($current !== $price) $product->set_price($price);

    // Updating this session cart-line field makes repeated totals passes idempotent and
    // lets a later, distinct scheduled or settings change produce a fresh notice.
    $item[self::BASELINE_KEY] = $price;
  }

  private static function current_price(int $showing_id, string $ticket_type): ?float {
    if ($showing_id <= 0 || $ticket_type === '' || !class_exists(CPT::class) || !function_exists('get_post_type')) return null;
    if (get_post_type($showing_id) !== CPT::POST_TYPE) return null;
    $profile = (string) get_post_meta($showing_id, '_roxy_pricing_profile', true);
    if ($profile === '') $profile = 'movie_evening';

    if ($ticket_type === 'subscriber') {
      return in_array($profile, ['movie_evening', 'movie_matinee', 'live_event'], true) ? 0.0 : null;
    }

    if ($profile === 'movie_evening') {
      if ($ticket_type === 'adult') return Settings::get_price('general_price', 12);
      if ($ticket_type === 'discount') return Settings::get_price('discount_price', 8);
      return null;
    }

    if ($profile === 'movie_matinee' && $ticket_type === 'matinee') {
      return Settings::get_price('matinee_price', 8);
    }

    if ($profile === 'live_event' && in_array($ticket_type, ['live1', 'live2'], true)) {
      $tier = $ticket_type === 'live1' ? 1 : 2;
      if (!Products::live_tier_is_configured($showing_id, $tier)) return null;
      return Products::get_live_tier_active_price($showing_id, $tier);
    }

    return null;
  }

  /** Pause this request after a cart-price change so checkout cannot silently submit it. */
  public static function require_price_review(): void {
    if (!self::$price_changed_in_request || self::$review_notice_added || !function_exists('wc_add_notice')) return;
    self::$review_notice_added = true;
    wc_add_notice(
      implode('<br>', self::$changes) . '<br>' . __('Review the updated ticket price and cart total, then continue checkout.', 'roxy-show-tickets'),
      'error'
    );
  }

  private static function add_price_change_notice($product, float $old_price, float $new_price, string $cart_key): void {
    if (!function_exists('wc_add_notice')) return;
    $name = method_exists($product, 'get_name') ? (string) $product->get_name() : __('show ticket', 'roxy-show-tickets');
    $old = function_exists('wc_price') ? wc_price($old_price) : (string) $old_price;
    $new = function_exists('wc_price') ? wc_price($new_price) : (string) $new_price;
    $message = sprintf(
      __('The price for %1$s changed from %2$s to %3$s. Your cart total has been updated.', 'roxy-show-tickets'),
      esc_html($name),
      $old,
      $new
    );
    self::$changes[$cart_key] = $message;

    // The cart-line baseline prevents repetition. Keep the key in the message context
    // for filters/extensions that may want to associate the notice with its line.
    wc_add_notice(apply_filters('roxy_st_cart_price_change_notice', $message, $cart_key, $old_price, $new_price, $product), 'notice');
  }
}
