<?php
/** Private WooCommerce HPOS order-marker contract; uses one synthetic $0 pending order. */
if (!defined('WP_CLI') || !WP_CLI) exit;

$token = 'PRIVATE-HPOS-SALES-MARKER-' . bin2hex(random_bytes(6));
$order_id = 0;
$product_id = 0;
$showing_id = 0;
$mail_filter = static function () { return true; };
$check = static function ($ok, string $label): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $label);
  echo "PASS: {$label}\n";
};

try {
  $utility = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
  if (!class_exists($utility) || !$utility::custom_orders_table_usage_is_enabled()) {
    throw new RuntimeException('This fixture requires WooCommerce HPOS to be authoritative.');
  }
  if (!class_exists('RoxyST\\Sales')) {
    throw new RuntimeException('The active Show Tickets module did not load.');
  }
  $storage_guard = \RoxySuite\Compatibility::order_storage_status();
  $check(($storage_guard['status'] ?? '') === 'pass' && ($storage_guard['value'] ?? '') === 'HPOS custom order tables',
    'production diagnostics recognize authoritative HPOS while the isolated marker contract runs');
  add_filter('pre_wp_mail', $mail_filter, PHP_INT_MAX, 2);

  $showing_id = (int) wp_insert_post([
    'post_type' => 'roxy_showing',
    'post_status' => 'draft',
    'post_title' => $token . ' showing',
  ], true);
  if ($showing_id <= 0 || is_wp_error($showing_id)) throw new RuntimeException('Could not create private showing fixture.');

  $product = new WC_Product_Simple();
  $product->set_name($token . ' ticket product');
  $product->set_status('draft');
  $product->set_catalog_visibility('hidden');
  $product->set_regular_price('0');
  $product->update_meta_data(ROXY_ST_META_SHOWING_ID, (string) $showing_id);
  $product_id = (int) $product->save();
  if ($product_id <= 0) throw new RuntimeException('Could not create private ticket product fixture.');

  $order = wc_create_order(['status' => 'pending', 'customer_note' => $token . ' zero-value HPOS marker fixture']);
  if (is_wp_error($order) || !$order instanceof WC_Order) throw new RuntimeException('Could not create private pending Woo order.');
  $order_id = (int) $order->get_id();
  if ($order_id <= 0 || (int) $order->add_product($product, 1) <= 0) throw new RuntimeException('Could not add the private ticket line.');
  $order->calculate_totals();
  $order->save();

  \RoxyST\Sales::mark_order_showings($order_id);
  $key = '_roxy_contains_showing_' . $showing_id;
  $fresh = wc_get_order($order_id);
  $check($fresh instanceof WC_Order && (string) $fresh->get_meta($key, true) === '1',
    'showing marker is persisted and readable through WooCommerce order CRUD on HPOS');

  $found = wc_get_orders([
    'type' => 'shop_order',
    'status' => ['pending'],
    'limit' => -1,
    'return' => 'ids',
    'meta_query' => [['key' => $key, 'value' => '1']],
  ]);
  $check(is_array($found) && in_array($order_id, array_map('intval', $found), true),
    'the HPOS order-meta query finds the marked order');
  $check((float) $fresh->get_total() === 0.0 && $fresh->get_status() === 'pending',
    'the synthetic order remains $0 and pending; no payment or fulfillment was requested');
} finally {
  remove_filter('pre_wp_mail', $mail_filter, PHP_INT_MAX);
  if ($order_id > 0) {
    $order = wc_get_order($order_id);
    if ($order) {
      if ($order->get_type() !== 'shop_order' || strpos((string) $order->get_customer_note(), $token) === false
        || (float) $order->get_total() !== 0.0 || $order->get_status() !== 'pending') {
        throw new RuntimeException('Refusing to delete an order not owned by this pending $0 fixture.');
      }
      $order->delete(true);
      if (wc_get_order($order_id)) throw new RuntimeException('The private HPOS order fixture was not removed.');
    }
  }
  if ($product_id > 0) {
    $product = wc_get_product($product_id);
    if ($product) {
      if ($product->get_name() !== $token . ' ticket product' || $product->get_type() !== 'simple'
        || (float) $product->get_price() !== 0.0 || $product->get_status() !== 'draft') {
        throw new RuntimeException('Refusing to delete a product not owned by this HPOS fixture.');
      }
      $product->delete(true);
    }
  }
  if ($showing_id > 0) {
    $showing = get_post($showing_id);
    if ($showing) {
      if ($showing->post_type !== 'roxy_showing' || $showing->post_title !== $token . ' showing'
        || get_post_status($showing_id) !== 'draft') {
        throw new RuntimeException('Refusing to delete a showing not owned by this HPOS fixture.');
      }
      wp_delete_post($showing_id, true);
    }
  }
}

echo "Passed private HPOS sales-marker contract checks. No payment gateway or external mail transport was used.\n";
