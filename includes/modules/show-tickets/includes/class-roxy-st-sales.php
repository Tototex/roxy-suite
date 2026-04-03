<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

class Sales {
  private static array $stats_cache = [];
  private const META_KEY = '_roxy_sales_stats';
  private const CACHE_VERSION = 1;

  public static function init(): void {
    add_action('woocommerce_order_status_changed', [__CLASS__, 'on_order_changed'], 20, 1);
    add_action('woocommerce_checkout_order_processed', [__CLASS__, 'on_order_changed'], 20, 1);
    add_action('woocommerce_refund_created', [__CLASS__, 'on_refund_created'], 20, 2);
    add_action('save_post_' . CPT::POST_TYPE, [__CLASS__, 'on_showing_saved'], 30, 1);
  }

  public static function on_showing_saved(int $showing_id): void {
    self::clear_showing_cache($showing_id);
    self::refresh_showing_stats($showing_id);
  }

  public static function on_order_changed(int $order_id): void {
    self::mark_order_showings($order_id);
    foreach (self::showing_ids_for_order($order_id) as $showing_id) {
      self::clear_showing_cache($showing_id);
      self::refresh_showing_stats($showing_id);
    }
  }

  public static function on_refund_created(int $refund_id, array $args = []): void {
    $order_id = isset($args['order_id']) ? (int) $args['order_id'] : 0;
    if ($order_id > 0) {
      self::on_order_changed($order_id);
    }
  }

  public static function get_showing_stats(int $showing_id): array {
    $showing_id = (int) $showing_id;
    if ($showing_id <= 0) {
      return self::empty_stats();
    }
    if (isset(self::$stats_cache[$showing_id])) {
      return self::$stats_cache[$showing_id];
    }

    $cached = get_post_meta($showing_id, self::META_KEY, true);
    if (is_array($cached) && (int) ($cached['cache_version'] ?? 0) === self::CACHE_VERSION) {
      unset($cached['cache_version'], $cached['generated_at']);
      return self::$stats_cache[$showing_id] = array_merge(self::empty_stats(), $cached);
    }

    return self::refresh_showing_stats($showing_id);
  }

  public static function refresh_showing_stats(int $showing_id): array {
    $showing_id = (int) $showing_id;
    if ($showing_id <= 0) {
      return self::empty_stats();
    }

    $stats = self::calculate_showing_stats($showing_id);
    self::$stats_cache[$showing_id] = $stats;

    $stored = $stats;
    $stored['cache_version'] = self::CACHE_VERSION;
    $stored['generated_at'] = current_time('mysql');
    update_post_meta($showing_id, self::META_KEY, $stored);

    return $stats;
  }

  public static function clear_showing_cache(int $showing_id): void {
    $showing_id = (int) $showing_id;
    unset(self::$stats_cache[$showing_id]);
    if ($showing_id > 0) {
      delete_post_meta($showing_id, self::META_KEY);
    }
  }

  public static function sold_qty_for_showing(int $showing_id): int {
    $stats = self::get_showing_stats($showing_id);
    return (int) ($stats['sold_qty'] ?? 0);
  }

  private static function calculate_showing_stats(int $showing_id): array {
    $product_map = self::product_map_for_showing($showing_id);
    if (!$product_map) {
      return self::empty_stats();
    }

    $product_ids = array_values(array_unique(array_map('intval', array_values($product_map))));
    if (!$product_ids) {
      return self::empty_stats();
    }

    $ticket_type_by_product = [];
    foreach ($product_map as $type => $pid) {
      $ticket_type_by_product[(int) $pid] = (string) $type;
    }

    $stats = self::empty_stats();
    $stats['ticket_types'] = [];

    $order_ids = wc_get_orders([
      'limit' => -1,
      'return' => 'ids',
      'status' => ['processing', 'completed', 'on-hold'],
      'type' => 'shop_order',
      'meta_query' => [[
        'key' => '_roxy_contains_showing_' . $showing_id,
        'value' => '1',
      ]],
    ]);

    if (!$order_ids) {
      $order_ids = self::find_and_tag_legacy_orders_for_showing($showing_id, $ticket_type_by_product);
    }

    foreach ($order_ids as $oid) {
      $order = wc_get_order($oid);
      if (!$order) continue;

      $matched_order = false;
      foreach ($order->get_items() as $item) {
        $pid = (int) $item->get_product_id();
        if (!isset($ticket_type_by_product[$pid])) continue;

        $matched_order = true;
        $qty = (int) $item->get_quantity();
        $type = $ticket_type_by_product[$pid];
        $line_total = (float) $item->get_total();

        $stats['sold_qty'] += $qty;
        $stats['gross_revenue'] += $line_total;
        if ($type === 'subscriber') {
          $stats['subscriber_qty'] += $qty;
        }

        if (!isset($stats['ticket_types'][$type])) {
          $stats['ticket_types'][$type] = [
            'qty' => 0,
            'revenue' => 0.0,
            'label' => self::ticket_type_label($showing_id, $type),
          ];
        }
        $stats['ticket_types'][$type]['qty'] += $qty;
        $stats['ticket_types'][$type]['revenue'] += $line_total;
      }

      if ($matched_order) {
        $stats['order_count']++;
      }
    }

    $stats['paid_qty'] = max(0, $stats['sold_qty'] - $stats['subscriber_qty']);
    $stats['gross_revenue'] = round((float) $stats['gross_revenue'], 2);
    foreach ($stats['ticket_types'] as $type => $row) {
      $stats['ticket_types'][$type]['revenue'] = round((float) $row['revenue'], 2);
    }

    return $stats;
  }


  private static function find_and_tag_legacy_orders_for_showing(int $showing_id, array $ticket_type_by_product): array {
    $order_ids = wc_get_orders([
      'limit' => -1,
      'return' => 'ids',
      'status' => ['processing', 'completed', 'on-hold'],
      'type' => 'shop_order',
    ]);

    $matched = [];
    foreach ($order_ids as $order_id) {
      $order = wc_get_order($order_id);
      if (!$order) continue;
      foreach ($order->get_items() as $item) {
        $pid = (int) $item->get_product_id();
        if (isset($ticket_type_by_product[$pid])) {
          update_post_meta((int) $order_id, '_roxy_contains_showing_' . $showing_id, '1');
          $matched[] = (int) $order_id;
          break;
        }
      }
    }

    return $matched;
  }

  public static function mark_order_showings($order_id): void {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $showing_ids = self::showing_ids_for_order((int) $order_id);
    if (!$showing_ids) return;

    foreach ($showing_ids as $showing_id) {
      update_post_meta((int) $order_id, '_roxy_contains_showing_' . (int) $showing_id, '1');
    }
  }

  private static function showing_ids_for_order(int $order_id): array {
    $order = wc_get_order($order_id);
    if (!$order) return [];

    $showing_ids = [];
    foreach ($order->get_items() as $item) {
      $product_id = (int) $item->get_product_id();
      if ($product_id <= 0) continue;
      $showing_id = (int) get_post_meta($product_id, ROXY_ST_META_SHOWING_ID, true);
      if ($showing_id > 0) {
        $showing_ids[$showing_id] = $showing_id;
      }
    }
    return array_values($showing_ids);
  }

  private static function product_map_for_showing(int $showing_id): array {
    $map = [];
    foreach (['adult','discount','matinee','live1','live2','subscriber'] as $type) {
      $pid = (int) get_post_meta($showing_id, '_roxy_pid_' . $type, true);
      if ($pid > 0) {
        $map[$type] = $pid;
      }
    }

    $legacy_raw = get_post_meta($showing_id, '_roxy_legacy_product_ids', true);
    if (is_array($legacy_raw)) {
      $legacy_raw = implode("\n", array_map('intval', $legacy_raw));
    }
    $legacy_ids = preg_split('/[\r\n,]+/', (string) $legacy_raw);
    $idx = 1;
    foreach ((array) $legacy_ids as $raw_id) {
      $pid = (int) trim((string) $raw_id);
      if ($pid > 0) {
        $map['legacy_' . $idx] = $pid;
        $idx++;
      }
    }

    return $map;
  }

  private static function ticket_type_label(int $showing_id, string $type): string {
    switch ($type) {
      case 'adult':
        return 'General';
      case 'discount':
        return 'Discount';
      case 'matinee':
        return 'Matinee';
      case 'live1':
        return (string) (get_post_meta($showing_id, '_roxy_live_label_1', true) ?: 'Live 1');
      case 'live2':
        return (string) (get_post_meta($showing_id, '_roxy_live_label_2', true) ?: 'Live 2');
      case 'subscriber':
        return 'Subscriber';
      default:
        if (strpos($type, 'legacy_') === 0) {
          return 'Legacy';
        }
        return ucfirst((string) $type);
    }
  }

  private static function empty_stats(): array {
    return [
      'sold_qty' => 0,
      'paid_qty' => 0,
      'subscriber_qty' => 0,
      'gross_revenue' => 0.0,
      'order_count' => 0,
      'ticket_types' => [],
    ];
  }
}
