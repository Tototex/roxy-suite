<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

class Capacity {
  private static array $subscriber_usage_cache = [];

  public static function subscription_entitlement_count(int $user_id): int {
    if ($user_id <= 0) return 0;
    if (!function_exists('wcs_get_users_subscriptions')) return 0;
    $subs = wcs_get_users_subscriptions($user_id);
    $count = 0;
    foreach ($subs as $sub) {
      if (!is_object($sub) || !method_exists($sub, 'has_status')) continue;
      if ($sub->has_status('active') || $sub->has_status('pending-cancel')) {
        if (method_exists($sub, 'get_items')) {
          $items = $sub->get_items();
          $qty_sum = 0;
          if (is_array($items)) {
            foreach ($items as $item) {
              if (is_object($item) && method_exists($item, 'get_quantity')) {
                $qty_sum += (int) $item->get_quantity();
              }
            }
          }
          $count += max(1, $qty_sum);
        } else {
          $count += 1;
        }
      }
    }
    return max(0, (int) $count);
  }

  public static function init(): void {
    add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add_to_cart'], 20, 4);
    add_action('woocommerce_checkout_process', [__CLASS__, 'validate_checkout_capacity']);
    add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_checkout_capacity']);
    add_filter('woocommerce_update_cart_validation', [__CLASS__, 'validate_cart_update'], 20, 4);
    add_action('woocommerce_after_cart_item_quantity_update', [__CLASS__, 'after_cart_item_qty_update'], 20, 4);
  }

  public static function after_cart_item_qty_update(string $cart_item_key, int $quantity, int $old_quantity, $cart): void {
    if (!WC()->cart) return;
    $item = WC()->cart->get_cart_item($cart_item_key);
    if (!$item) return;
    $pid = (int) ($item['product_id'] ?? 0);
    if (!$pid) return;
    $sid = (int) get_post_meta($pid, ROXY_ST_META_SHOWING_ID, true);
    if (!$sid) return;
    $type = (string) get_post_meta($pid, ROXY_ST_META_TICKET_TYPE, true);

    if ($type === 'subscriber') {
      $max = self::subscriber_limit_remaining_for_showing($sid, get_current_user_id(), false);
      if ($max <= 0) {
        WC()->cart->set_quantity($cart_item_key, 0);
        wc_add_notice('Subscriber tickets require an active subscription.', 'error');
        return;
      }

      $total = 0;
      foreach (WC()->cart->get_cart() as $k => $it) {
        $p = (int) ($it['product_id'] ?? 0);
        if (!$p) continue;
        if ((int) get_post_meta($p, ROXY_ST_META_SHOWING_ID, true) !== $sid) continue;
        if ((string) get_post_meta($p, ROXY_ST_META_TICKET_TYPE, true) !== 'subscriber') continue;
        $total += (int) ($it['quantity'] ?? 0);
      }

      if ($total > $max) {
        $excess = $total - $max;
        $new_qty = max(0, $quantity - $excess);
        WC()->cart->set_quantity($cart_item_key, $new_qty);
        wc_add_notice('Subscriber tickets are limited to ' . (int) $max . ' per show for your account.', 'error');
      }
    }

    $remaining = self::remaining_seats_for_showing($sid);
    if (is_int($remaining) && $remaining < self::cart_qty_for_showing($sid)) {
      $new_qty = max(0, $quantity - (self::cart_qty_for_showing($sid) - $remaining));
      WC()->cart->set_quantity($cart_item_key, $new_qty);
      wc_add_notice('This show does not have enough seats remaining.', 'error');
    }
  }

  public static function validate_add_to_cart(bool $passed, int $product_id, int $quantity, int $variation_id = 0): bool {
    // This is a validation filter: never undo a rejection made by WooCommerce
    // or an earlier validation callback.
    if (!$passed) return false;

    $sid = (int) get_post_meta($product_id, ROXY_ST_META_SHOWING_ID, true);
    if (!$sid) return $passed;

    $type = (string) get_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, true);
    if ($type === 'subscriber') {
      $max = self::subscriber_limit_remaining_for_showing($sid, get_current_user_id(), false);
      if ($max <= 0) {
        wc_add_notice('Subscriber tickets are only available to active subscribers.', 'error');
        return false;
      }
      $in_cart_sub = self::cart_qty_for_showing_type($sid, 'subscriber');
      if (($in_cart_sub + $quantity) > $max) {
        wc_add_notice('Subscriber tickets are limited to ' . (int) $max . ' per show for your account.', 'error');
        return false;
      }
    }

    $capacity = self::capacity_limit_for_showing($sid);
    if ($capacity === null) return $passed;

    $in_cart = self::cart_qty_for_showing($sid);
    $sold = self::sold_qty_for_showing($sid, true);

    if (($sold + $in_cart + $quantity) > $capacity) {
      wc_add_notice('This show is sold out (or does not have enough seats remaining).', 'error');
      return false;
    }

    return $passed;
  }

  public static function validate_cart_update(bool $passed, string $cart_item_key, array $values, int $quantity): bool {
    if (!$passed) return false;
    $pid = (int) ($values['product_id'] ?? 0);
    if (!$pid) return $passed;
    $sid = (int) get_post_meta($pid, ROXY_ST_META_SHOWING_ID, true);
    if (!$sid) return $passed;

    $type = (string) get_post_meta($pid, ROXY_ST_META_TICKET_TYPE, true);
    if ($type === 'subscriber') {
      $remaining_excluding_this = self::subscriber_limit_remaining_for_showing($sid, get_current_user_id(), false);
      if ($remaining_excluding_this <= 0 && $quantity > 0) {
        wc_add_notice('Subscriber tickets require an active subscription and available subscriber seats for this show.', 'error');
        return false;
      }
      $current_item_qty = (int) ($values['quantity'] ?? 0);
      $other_subscriber_qty=max(0,self::cart_qty_for_showing_type($sid,'subscriber')-$current_item_qty);
      $allowed_for_this_line = max(0,$remaining_excluding_this-$other_subscriber_qty);
      if ($quantity > $allowed_for_this_line) {
        wc_add_notice('Subscriber tickets are limited to ' . (int) $allowed_for_this_line . ' remaining for this show on your account.', 'error');
        return false;
      }
    }

    $capacity = self::capacity_limit_for_showing($sid);
    if ($capacity === null) return $passed;

    $other_qty = max(0, self::cart_qty_for_showing($sid) - (int) ($values['quantity'] ?? 0));
    $sold = self::sold_qty_for_showing($sid, true);
    if (($sold + $other_qty + $quantity) > $capacity) {
      wc_add_notice('This show does not have enough seats remaining.', 'error');
      return false;
    }

    return $passed;
  }

  public static function validate_checkout_capacity(): void {
    if (!WC()->cart) return;

    $by_showing = [];
    $subscriber_by_showing = [];
    foreach (WC()->cart->get_cart() as $item) {
      $pid = (int) ($item['product_id'] ?? 0);
      if (!$pid) continue;
      $sid = (int) get_post_meta($pid, ROXY_ST_META_SHOWING_ID, true);
      if (!$sid) continue;
      $qty = (int) ($item['quantity'] ?? 0);
      $by_showing[$sid] = ($by_showing[$sid] ?? 0) + $qty;

      $type = (string) get_post_meta($pid, ROXY_ST_META_TICKET_TYPE, true);
      if ($type === 'subscriber') {
        $subscriber_by_showing[$sid] = ($subscriber_by_showing[$sid] ?? 0) + $qty;
      }
    }

    foreach ($subscriber_by_showing as $sid => $qty) {
      $max = self::subscriber_limit_remaining_for_showing($sid, get_current_user_id(), false);
      if ($max <= 0) {
        wc_add_notice('Subscriber tickets require an active subscription.', 'error');
      } elseif ($qty > $max) {
        wc_add_notice('Subscriber tickets are limited to ' . (int) $max . ' per show for your account.', 'error');
      }
    }

    foreach ($by_showing as $sid => $qty) {
      $capacity = self::capacity_limit_for_showing($sid);
      if ($capacity === null) continue;
      $sold = self::sold_qty_for_showing($sid, true);
      if (($sold + $qty) > $capacity) {
        wc_add_notice('Not enough remaining seats for: ' . esc_html(get_the_title($sid)) . '.', 'error');
      }
    }
  }

  public static function remaining_seats_for_showing(int $showing_id): ?int {
    $capacity = self::capacity_limit_for_showing($showing_id);
    if ($capacity === null) return null;

    $remaining = $capacity - self::sold_qty_for_showing($showing_id);
    return max(0, (int) $remaining);
  }

  public static function remaining_subscriber_seats_for_showing(int $showing_id, int $user_id = 0): int {
    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    return self::subscriber_limit_remaining_for_showing($showing_id, (int) $user_id);
  }



  public static function purchased_subscriber_qty_for_showing_user(int $showing_id, int $user_id): int {
    if ($showing_id <= 0 || $user_id <= 0 || !function_exists('wc_get_orders')) return 0;

    $cache_key = $showing_id . ':' . $user_id;
    if (isset(self::$subscriber_usage_cache[$cache_key])) {
      return self::$subscriber_usage_cache[$cache_key];
    }

    try {
      $orders = wc_get_orders([
        'customer_id' => $user_id,
        'status'      => ['wc-processing', 'wc-completed'],
        'limit'       => -1,
        'return'      => 'ids',
        'type'        => 'shop_order',
        'meta_query'  => [[
          'key'   => '_roxy_contains_showing_' . $showing_id,
          'value' => '1',
        ]],
      ]);
    } catch (\Throwable $error) {
      return self::$subscriber_usage_cache[$cache_key] = PHP_INT_MAX;
    }

    // This query controls paid entitlement consumption. Treat an unreadable or
    // malformed result as unknown usage, never as an empty purchase history.
    if (!is_array($orders) || (function_exists('is_wp_error') && is_wp_error($orders))) {
      return self::$subscriber_usage_cache[$cache_key] = PHP_INT_MAX;
    }

    $used = 0;
    try {
      foreach ($orders as $order) {
        $order = wc_get_order($order);
        if (!is_object($order) || !method_exists($order, 'get_items')) {
          return self::$subscriber_usage_cache[$cache_key] = PHP_INT_MAX;
        }
        $items = $order->get_items('line_item');
        if (!is_array($items) || (function_exists('is_wp_error') && is_wp_error($items))) {
          return self::$subscriber_usage_cache[$cache_key] = PHP_INT_MAX;
        }
        foreach ($items as $item) {
          if (!is_object($item) || !method_exists($item, 'get_product_id') || !method_exists($item, 'get_quantity')) {
            return self::$subscriber_usage_cache[$cache_key] = PHP_INT_MAX;
          }
          $product_id = (int) $item->get_product_id();
          if ($product_id <= 0) return self::$subscriber_usage_cache[$cache_key] = PHP_INT_MAX;
          if ((int) get_post_meta($product_id, ROXY_ST_META_SHOWING_ID, true) !== $showing_id) continue;
          if ((string) get_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, true) !== 'subscriber') continue;
          $quantity = $item->get_quantity();
          if (!is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity < 0
            || floor((float) $quantity) !== (float) $quantity || (float) $quantity > PHP_INT_MAX - $used) {
            return self::$subscriber_usage_cache[$cache_key] = PHP_INT_MAX;
          }
          $used += (int) $quantity;
        }
      }
    } catch (\Throwable $error) {
      return self::$subscriber_usage_cache[$cache_key] = PHP_INT_MAX;
    }

    return self::$subscriber_usage_cache[$cache_key] = max(0, (int) $used);
  }

  public static function subscriber_limit_remaining_for_showing(int $showing_id, int $user_id = 0, bool $include_cart = true): int {
    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    $entitlement = self::subscription_entitlement_count((int) $user_id);
    if ($entitlement <= 0) return 0;

    $used = self::purchased_subscriber_qty_for_showing_user($showing_id, (int) $user_id);
    if ($used === PHP_INT_MAX) return 0;
    try {
      if (class_exists('\Roxy_Sub_Check')) {
        $walkups = \Roxy_Sub_Check::walkup_quantity_for_showing($showing_id, (int) $user_id);
        if (!is_numeric($walkups) || !is_finite((float) $walkups) || (float) $walkups < 0
          || floor((float) $walkups) !== (float) $walkups || (float) $walkups > PHP_INT_MAX - $used) return 0;
        $used += (int) $walkups;
      }
    } catch (\Throwable $e) { return 0; } // Unknown arrivals cannot grant more entitlement.
    $remaining = max(0, $entitlement - $used);

    if ($include_cart) {
      $remaining -= self::cart_qty_for_showing_type($showing_id, 'subscriber');
    }

    return max(0, (int) $remaining);
  }

  public static function capacity_limit_for_showing(int $showing_id): ?int {
    $raw = get_post_meta($showing_id, '_roxy_capacity', true);
    if ($raw === '' || $raw === null) {
      return null;
    }
    return max(0, (int) $raw);
  }

  private static function cart_qty_for_showing(int $showing_id): int {
    if (!WC()->cart) return 0;
    $qty = 0;
    foreach (WC()->cart->get_cart() as $item) {
      $pid = (int) ($item['product_id'] ?? 0);
      if (!$pid) continue;
      $sid = (int) get_post_meta($pid, ROXY_ST_META_SHOWING_ID, true);
      if ($sid === $showing_id) {
        $qty += (int) ($item['quantity'] ?? 0);
      }
    }
    return $qty;
  }

  private static function cart_qty_for_showing_type(int $showing_id, string $ticket_type): int {
    if (!WC()->cart) return 0;
    $ticket_type = sanitize_key($ticket_type);
    $qty = 0;
    foreach (WC()->cart->get_cart() as $item) {
      $pid = (int) ($item['product_id'] ?? 0);
      if (!$pid) continue;
      $sid = (int) get_post_meta($pid, ROXY_ST_META_SHOWING_ID, true);
      if ($sid !== $showing_id) continue;
      $type = (string) get_post_meta($pid, ROXY_ST_META_TICKET_TYPE, true);
      if (sanitize_key($type) !== $ticket_type) continue;
      $qty += (int) ($item['quantity'] ?? 0);
    }
    return $qty;
  }

  private static function sold_qty_for_showing(int $showing_id, bool $checkout_retry = false): int {
    try { $walkups=class_exists('\Roxy_Sub_Check') ? \Roxy_Sub_Check::walkup_quantity_for_showing($showing_id) : 0; }
    catch(\Throwable $e){return PHP_INT_MAX;} // Fail closed without crashing public pages.
    try {
      $exclude=$checkout_retry && class_exists(__NAMESPACE__.'\\Holds') ? Holds::retry_order_id() : 0;
      $reserved=Reservations::quantity_for_showing($showing_id,$exclude);
      if($reserved>PHP_INT_MAX-$walkups)return PHP_INT_MAX;
      return $reserved+$walkups;
    } catch(\Throwable $e){return PHP_INT_MAX;}
  }
}
