<?php
namespace RoxyST;
if (!defined('ABSPATH')) exit;

/** One publication/profile gate for direct products, public submission and stale carts. */
class Eligibility {
  public static function init(): void {
    add_filter('woocommerce_is_purchasable', [__CLASS__, 'purchasable'], 99, 2);
    add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add'], 5, 6);
    add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_cart'], 9990);
    add_action('woocommerce_checkout_process', [__CLASS__, 'validate_cart'], 9990);
  }

  public static function showing_is_public(int $id): bool {
    return $id > 0 && get_post_type($id) === CPT::POST_TYPE && get_post_status($id) === 'publish';
  }

  /** Null denotes a non-ticket product; WP_Error denotes an invalid ticket. */
  public static function product_error(int $id) {
    if ($id <= 0 || !get_post_type($id)) {
      return new \WP_Error('roxy_product_missing', __('An item in your cart is no longer available. Remove it before checking out.', 'roxy-show-tickets'));
    }
    $sid = (int)get_post_meta($id, ROXY_ST_META_SHOWING_ID, true);
    $type = (string)get_post_meta($id, ROXY_ST_META_TICKET_TYPE, true);
    if (!$sid && $type === '') return null;
    if (!self::showing_is_public($sid) || get_post_type($id) !== 'product' || get_post_status($id) !== 'publish') {
      return new \WP_Error('roxy_ticket_unavailable', __('This showing is no longer available for online ticket sales. Remove its tickets from your cart.', 'roxy-show-tickets'));
    }
    $profile = (string)get_post_meta($sid, '_roxy_pricing_profile', true);
    if ($profile === '') $profile = 'movie_evening';
    $allowed = ['movie_evening'=>['adult','discount','subscriber'], 'movie_matinee'=>['matinee','subscriber'], 'live_event'=>['subscriber']];
    if ($profile === 'live_event') {
      foreach ([1,2] as $tier) if (Products::live_tier_is_configured($sid, $tier)) $allowed[$profile][] = 'live' . $tier;
    }
    if (!in_array($type, $allowed[$profile] ?? [], true) || (int)get_post_meta($sid, '_roxy_pid_' . $type, true) !== $id) {
      return new \WP_Error('roxy_ticket_changed', __('This ticket option has changed. Remove it from your cart and select tickets again.', 'roxy-show-tickets'));
    }
    return null;
  }

  public static function purchasable($passed, $product): bool {
    return (bool)$passed && !is_wp_error(self::product_error((int)$product->get_id()));
  }

  public static function validate_add($passed, $id, $quantity, $variation_id = 0, $variations = [], $data = []): bool {
    $error = self::product_error((int)$id);
    if (!is_wp_error($error)) return (bool)$passed;
    self::notice($error->get_error_message());
    return false;
  }

  public static function validate_cart(): void {
    if (!function_exists('WC') || !WC()->cart) return;
    foreach (WC()->cart->get_cart() as $item) {
      $error = self::product_error((int)($item['product_id'] ?? 0));
      if (is_wp_error($error)) self::notice($error->get_error_message());
    }
  }

  private static function notice(string $message): void {
    if (function_exists('wc_has_notice') && wc_has_notice($message, 'error')) return;
    wc_add_notice($message, 'error');
  }
}
