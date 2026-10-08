<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

class Sales {
  private static array $stats_cache = [];
  private static array $stats_read_errors = [];
  private const META_KEY = '_roxy_sales_stats';
  private const LEGACY_SCAN_COMPLETE_KEY = '_roxy_legacy_sales_scan_complete';
  private const CACHE_VERSION = 3;

  public static function init(): void {
    add_action('woocommerce_order_status_changed', [__CLASS__, 'on_order_changed'], 20, 1);
    add_action('woocommerce_checkout_order_processed', [__CLASS__, 'on_order_changed'], 20, 1);
    add_action('woocommerce_refund_created', [__CLASS__, 'on_refund_created'], 20, 2);
    add_action('woocommerce_refund_deleted', [__CLASS__, 'on_refund_deleted'], 20, 2);
    add_action('save_post_' . CPT::POST_TYPE, [__CLASS__, 'on_showing_saved'], 30, 1);
  }

  public static function on_showing_saved(int $showing_id): void {
    self::refresh_showing_stats($showing_id);
  }

  public static function on_order_changed(int $order_id): void {
    self::mark_order_showings($order_id);
    foreach (self::showing_ids_for_order($order_id) as $showing_id) {
      self::refresh_showing_stats($showing_id);
    }
  }

  public static function on_refund_created(int $refund_id, array $args = []): void {
    $order_id = isset($args['order_id']) ? (int) $args['order_id'] : 0;
    if ($order_id > 0) {
      self::on_order_changed($order_id);
    }
  }

  public static function on_refund_deleted(int $refund_id, int $order_id): void {
    if ($order_id > 0) self::on_order_changed($order_id);
  }

  public static function get_showing_stats(int $showing_id): array {
    $showing_id = (int) $showing_id;
    if ($showing_id <= 0) {
      return self::empty_stats();
    }
    if (isset(self::$stats_read_errors[$showing_id])) {
      $cached = get_post_meta($showing_id, self::META_KEY, true);
      if (is_array($cached) && (int) ($cached['cache_version'] ?? 0) === self::CACHE_VERSION) {
        unset($cached['cache_version'], $cached['generated_at']);
        return array_merge(self::empty_stats(), $cached, ['read_error' => true]);
      }
      return array_merge(self::empty_stats(), ['read_error' => true]);
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

    try {
      $stats = self::calculate_showing_stats($showing_id);
    } catch (\Throwable $e) {
      self::$stats_read_errors[$showing_id] = true;
      if (function_exists('error_log')) {
        error_log('[Roxy Suite Sales] Showing ' . $showing_id . ' totals refresh failed: ' . $e->getMessage());
      }
      // Keep the last known-good persistent and request-local totals. An
      // unavailable marker makes capacity checks fail closed instead of
      // interpreting a failed read as zero sold.
      $previous = get_post_meta($showing_id, self::META_KEY, true);
      if (is_array($previous) && (int) ($previous['cache_version'] ?? 0) === self::CACHE_VERSION) {
        unset($previous['cache_version'], $previous['generated_at']);
        return array_merge(self::empty_stats(), $previous, ['read_error' => true]);
      }
      return array_merge(self::empty_stats(), ['read_error' => true]);
    }
    unset(self::$stats_read_errors[$showing_id]);
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
    unset(self::$stats_read_errors[$showing_id]);
    if ($showing_id > 0) {
      delete_post_meta($showing_id, self::META_KEY);
    }
  }

  public static function sold_qty_for_showing(int $showing_id): int {
    $stats = self::get_showing_stats($showing_id);
    if (!empty($stats['read_error'])) return PHP_INT_MAX;
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
    $showing_date = self::showing_date($showing_id);

    $order_ids = wc_get_orders([
      'limit' => -1,
      'return' => 'ids',
      'status' => array_values(array_unique(array_merge(['processing', 'completed', 'on-hold'], function_exists('wc_get_is_paid_statuses') ? wc_get_is_paid_statuses() : []))),
      'type' => 'shop_order',
      'meta_query' => [[
        'key' => '_roxy_contains_showing_' . $showing_id,
        'value' => '1',
      ]],
    ]);

    self::assert_order_ids_readable($order_ids, 'showing sales');

    if (!$order_ids && !self::legacy_scan_complete($showing_id)) {
      $order_ids = self::find_and_tag_legacy_orders_for_showing($showing_id, $ticket_type_by_product);
    }

    foreach ($order_ids as $oid) {
      $order = wc_get_order($oid);
      if (!$order || !method_exists($order, 'get_items')) {
        throw new \RuntimeException('A showing sales order could not be read completely.');
      }
      $items = $order->get_items();
      if (!is_array($items) || (function_exists('is_wp_error') && is_wp_error($items))) {
        throw new \RuntimeException('A showing sales order returned incomplete line items.');
      }

      $status = method_exists($order, 'get_status') ? strtolower((string) $order->get_status()) : '';
      if (in_array($status, ['cancelled', 'canceled', 'refunded'], true)) continue;
      $paid_status = in_array($status, ['processing', 'completed'], true);
      $is_paid = method_exists($order, 'is_paid') && $order->is_paid();
      if (!$paid_status && !$is_paid) continue;

      $order_date = self::order_date($order);
      $is_presale_order = $showing_date !== '' && $order_date !== '' && $order_date < $showing_date;
      $matched_order = false;
      foreach ($items as $item) {
        if (!is_object($item) || !method_exists($item, 'get_product_id') || !method_exists($item, 'get_quantity')
          || !method_exists($item, 'get_total')) {
          throw new \RuntimeException('A showing sales order returned malformed line items.');
        }
        $pid = (int) $item->get_product_id();
        if (!isset($ticket_type_by_product[$pid])) continue;

        $matched_order = true;
        $qty = max(0, (int) $item->get_quantity());
        $item_id = method_exists($item, 'get_id') ? (int) $item->get_id() : 0;
        $refunded_qty = $item_id > 0 && method_exists($order, 'get_qty_refunded_for_item')
          ? abs((int) $order->get_qty_refunded_for_item($item_id))
          : 0;
        $qty = max(0, $qty - min($qty, $refunded_qty));
        $type = $ticket_type_by_product[$pid];
        // These are eligible-order line values, not a dated cash ledger. Keep
        // the legacy gross field compatible; never move a later cash refund to
        // the showing/sale date by subtracting it here.
        $line_total = (float) $item->get_total();
        $refunded_total = $item_id > 0 && method_exists($order, 'get_total_refunded_for_item')
          ? abs((float) $order->get_total_refunded_for_item($item_id))
          : 0.0;

        $stats['sold_qty'] += $qty;
        $stats['gross_revenue'] += $line_total;
        $stats['refunded_revenue'] += $refunded_total;
        if ($type === 'subscriber') {
          $stats['subscriber_qty'] += $qty;
        } elseif ($is_presale_order) {
          $stats['presale_qty'] += $qty;
        } else {
          $stats['day_of_qty'] += $qty;
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

    global $wpdb;
    if (isset($wpdb->last_error) && (string) $wpdb->last_error !== '') {
      throw new \RuntimeException('The showing sales query did not complete cleanly.');
    }

    $stats['paid_qty'] = max(0, $stats['sold_qty'] - $stats['subscriber_qty']);
    $stats['gross_revenue'] = round((float) $stats['gross_revenue'], 2);
    $stats['refunded_revenue'] = round((float) $stats['refunded_revenue'], 2);
    $stats['net_revenue'] = round($stats['gross_revenue'] - $stats['refunded_revenue'], 2);
    foreach ($stats['ticket_types'] as $type => $row) {
      $stats['ticket_types'][$type]['revenue'] = round((float) $row['revenue'], 2);
    }

    return $stats;
  }


  private static function find_and_tag_legacy_orders_for_showing(int $showing_id, array $ticket_type_by_product): array {
    $query_args = [
      'limit' => -1,
      'return' => 'ids',
      'status' => array_values(array_unique(array_merge(['processing', 'completed', 'on-hold'], function_exists('wc_get_is_paid_statuses') ? wc_get_is_paid_statuses() : []))),
      'type' => 'shop_order',
    ];

    $window_start = self::legacy_scan_window_start($showing_id);
    if ($window_start !== '') {
      $query_args['date_created'] = '>=' . $window_start;
    }

    $order_ids = wc_get_orders($query_args);
    self::assert_order_ids_readable($order_ids, 'legacy showing sales');

    $matched = [];
    foreach ($order_ids as $order_id) {
      $order = wc_get_order($order_id);
      if (!$order || !method_exists($order, 'get_items')) {
        throw new \RuntimeException('A legacy order could not be read completely.');
      }
      $items = $order->get_items();
      if (!is_array($items) || (function_exists('is_wp_error') && is_wp_error($items))) {
        throw new \RuntimeException('A legacy order returned incomplete line items.');
      }
      foreach ($items as $item) {
        if (!is_object($item) || !method_exists($item, 'get_product_id')) {
          throw new \RuntimeException('A legacy order returned malformed line items.');
        }
        $pid = (int) $item->get_product_id();
        if (isset($ticket_type_by_product[$pid])) {
          $tag_key = '_roxy_contains_showing_' . $showing_id;
          if (get_post_meta((int) $order_id, $tag_key, true) !== '1'
            && update_post_meta((int) $order_id, $tag_key, '1') === false) {
            throw new \RuntimeException('A legacy order could not be tagged for the showing.');
          }
          if (get_post_meta((int) $order_id, $tag_key, true) !== '1') {
            throw new \RuntimeException('A legacy order showing tag could not be verified.');
          }
          $matched[] = (int) $order_id;
          break;
        }
      }
    }

    if (get_post_meta($showing_id, self::LEGACY_SCAN_COMPLETE_KEY, true) !== '1') {
      update_post_meta($showing_id, self::LEGACY_SCAN_COMPLETE_KEY, '1');
    }
    if (get_post_meta($showing_id, self::LEGACY_SCAN_COMPLETE_KEY, true) !== '1') {
      throw new \RuntimeException('The legacy showing sales scan could not be marked complete.');
    }

    return $matched;
  }

  private static function assert_order_ids_readable($order_ids, string $context): void {
    global $wpdb;
    if ((function_exists('is_wp_error') && is_wp_error($order_ids))
      || !is_array($order_ids)
      || (isset($wpdb->last_error) && (string) $wpdb->last_error !== '')) {
      throw new \RuntimeException('Could not read ' . $context . ' order identities.');
    }
    foreach ($order_ids as $order_id) {
      if (!is_numeric($order_id) || (float) $order_id <= 0 || (float) $order_id !== (float) (int) $order_id) {
        throw new \RuntimeException('The ' . $context . ' order query returned an invalid identity.');
      }
    }
  }

  private static function legacy_scan_complete(int $showing_id): bool {
    return get_post_meta($showing_id, self::LEGACY_SCAN_COMPLETE_KEY, true) === '1';
  }

  private static function legacy_scan_window_start(int $showing_id): string {
    $showing_date = self::showing_date($showing_id);
    if ($showing_date !== '') {
      try {
        return (new \DateTimeImmutable($showing_date, wp_timezone()))->modify('-180 days')->format('Y-m-d');
      } catch (\Throwable $e) {
        return $showing_date;
      }
    }

    return wp_date('Y-m-d', strtotime('-180 days'), wp_timezone());
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

  private static function showing_date(int $showing_id): string {
    $raw = (string) get_post_meta($showing_id, '_roxy_start', true);
    if ($raw === '') {
      return '';
    }

    try {
      return (new \DateTimeImmutable($raw, wp_timezone()))->format('Y-m-d');
    } catch (\Throwable $e) {
      return substr($raw, 0, 10);
    }
  }

  private static function order_date($order): string {
    if (!is_object($order) || !method_exists($order, 'get_date_created')) {
      return '';
    }

    $created = $order->get_date_created();
    if (!$created || !method_exists($created, 'setTimezone')) {
      return '';
    }

    try {
      return $created->setTimezone(wp_timezone())->date('Y-m-d');
    } catch (\Throwable $e) {
      return '';
    }
  }

  private static function empty_stats(): array {
    return [
      'sold_qty' => 0,
      'paid_qty' => 0,
      'subscriber_qty' => 0,
      'presale_qty' => 0,
      'day_of_qty' => 0,
      'gross_revenue' => 0.0,
      'refunded_revenue' => 0.0,
      'net_revenue' => 0.0,
      'order_count' => 0,
      'ticket_types' => [],
    ];
  }
}
