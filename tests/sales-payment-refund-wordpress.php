<?php
/** WP-CLI fixture: actual zero-value Woo orders/refund; Sales metadata stays in memory. */
if (!defined('WP_CLI') || !WP_CLI) exit;

$root = $args[0] ?? dirname(__DIR__);
$candidate = $args[1] ?? ($root . '/includes/modules/show-tickets/includes/class-roxy-st-sales.php');
if (!is_file($candidate)) throw new RuntimeException('Candidate Sales file missing: ' . $candidate);
if (!defined('ROXY_ST_META_SHOWING_ID')) define('ROXY_ST_META_SHOWING_ID', '_roxy_showing_id');
if (!defined('ROXY_ST_META_TICKET_TYPE')) define('ROXY_ST_META_TICKET_TYPE', '_roxy_ticket_type');

$namespace = 'SalesRefundWoo_' . bin2hex(random_bytes(5));
$token = 'PRIVATE-SALES-REFUND-' . bin2hex(random_bytes(6));
$source = file_get_contents($candidate);
if (!is_string($source)) throw new RuntimeException('Could not read candidate Sales source.');
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$source = str_replace('namespace RoxyST;', 'namespace ' . $namespace . ';', $source);
$GLOBALS['sales_refund_fixture_order_ids'] = [];
$GLOBALS['sales_refund_fixture_meta'] = [];
$GLOBALS['sales_refund_fixture_meta_writes'] = [];
$GLOBALS['sales_refund_fixture_mail'] = [];
$mail_transport_attempts = 0;

// Candidate Sales sees only whitelisted fixture orders and in-memory metadata; no postmeta is written.
eval('namespace ' . $namespace . ';
  final class CPT { public const POST_TYPE = "roxy_showing"; }
  function wc_get_orders(array $args): array { return $GLOBALS["sales_refund_fixture_order_ids"]; }
  function wc_get_order($id) { $id=(int)$id; return in_array($id,$GLOBALS["sales_refund_fixture_order_ids"],true) ? \\wc_get_order($id) : null; }
  function get_post_meta($id,$key,$single=false) { return $GLOBALS["sales_refund_fixture_meta"][(int)$id][$key] ?? ""; }
  function update_post_meta($id,$key,$value) { $GLOBALS["sales_refund_fixture_meta"][(int)$id][$key]=$value; $GLOBALS["sales_refund_fixture_meta_writes"][]=[(int)$id,(string)$key]; return true; }
  function delete_post_meta($id,$key) { unset($GLOBALS["sales_refund_fixture_meta"][(int)$id][$key]); $GLOBALS["sales_refund_fixture_meta_writes"][]=[(int)$id,(string)$key,"delete"]; return true; }
');
eval($source);
$sales = '\\' . $namespace . '\\Sales';
$created_orders = []; $created_products = []; $created_refunds = [];
$detached = [];
$mail_filter = static function ($pre, $atts) { $GLOBALS['sales_refund_fixture_mail'][] = $atts; return true; };
$mail_transport_guard = static function () use (&$mail_transport_attempts): void { ++$mail_transport_attempts; throw new RuntimeException('Fixture mail reached the transport despite interception.'); };
$check = static function ($ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); echo "PASS: {$label}\n"; };
$safe_delete_order = static function (int $id) use ($token): void {
  $order = wc_get_order($id);
  if (!$order) return;
  if ($order->get_type() !== 'shop_order' || strpos((string)$order->get_customer_note(), $token) === false) throw new RuntimeException('Refusing to delete an order not owned by this fixture.');
  $order->delete(true);
};

try {
  add_filter('pre_wp_mail', $mail_filter, PHP_INT_MAX, 2);
  add_action('phpmailer_init', $mail_transport_guard, PHP_INT_MAX);

  $product = new WC_Product_Simple();
  $product_name = $token . ' generic zero-value product';
  $product->set_name($product_name); $product->set_status('draft'); $product->set_catalog_visibility('hidden');
  $product->set_regular_price('0'); $product_id = (int)$product->save();
  if ($product_id <= 0) throw new RuntimeException('Could not create private generic product.');
  $created_products[] = $product_id;
  if (get_post_meta($product_id, ROXY_ST_META_SHOWING_ID, true) !== '' || get_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, true) !== '') throw new RuntimeException('Fixture product unexpectedly has Show Tickets metadata.');

  $showing_id = 770000000 + random_int(1, 900000);
  // These IDs exist only in the candidate namespace fake; no showing post or production meta is created.
  $GLOBALS['sales_refund_fixture_meta'][$showing_id] = ['_roxy_pid_adult'=>$product_id];
  $GLOBALS['sales_refund_fixture_meta'][$product_id] = [ROXY_ST_META_SHOWING_ID=>(string)$showing_id];

  $make_order = static function (string $status, int $qty) use ($product, $token, &$created_orders): array {
    $order = wc_create_order(['status'=>'pending','customer_note'=>$token . ' ' . $status . ' zero-value sales fixture']);
    if (is_wp_error($order)) throw new RuntimeException('Could not create fixture Woo order.');
    $created_orders[] = (int)$order->get_id();
    $GLOBALS['sales_refund_fixture_order_ids'][] = (int)$order->get_id();
    $item_id = (int)$order->add_product($product, $qty);
    if ($item_id <= 0) throw new RuntimeException('Could not add private product to fixture order.');
    $order->calculate_totals();
    $order->set_status($status);
    $order->save();
    $fresh = wc_get_order($order->get_id());
    if (!$fresh || (float)$fresh->get_total() !== 0.0) throw new RuntimeException('Fixture order is not a genuine zero-total Woo order.');
    return [$fresh, $item_id];
  };

  [$processing, $item_id] = $make_order('processing', 3);
  [$on_hold] = $make_order('on-hold', 2);
  $stats = $sales::get_showing_stats($showing_id);
  $check($stats['sold_qty']===3 && $stats['order_count']===1, 'actual processing order counts while unpaid on-hold order is excluded');
  $check($stats['gross_revenue']===0.0 && $stats['refunded_revenue']===0.0 && $stats['net_revenue']===0.0, 'actual eligible $0 orders retain zero gross/refund/net values');

  $refund = wc_create_refund([
    'amount'=>0,
    'reason'=>$token . ' item quantity only',
    'order_id'=>$processing->get_id(),
    'refund_payment'=>false,
    'restock_items'=>false,
    'line_items'=>[$item_id=>['qty'=>1,'refund_total'=>0,'refund_tax'=>[]]],
  ]);
  if (is_wp_error($refund) || !$refund instanceof WC_Order_Refund) throw new RuntimeException('Woo did not create the zero-money itemized fixture refund.');
  $created_refunds[] = (int)$refund->get_id();
  $refund_qty = abs((int)$processing->get_qty_refunded_for_item($item_id));
  $refund_amount = abs((float)$processing->get_total_refunded_for_item($item_id));
  $check($refund_qty===1 && $refund_amount===0.0 && (float)$processing->get_total()===0.0, 'actual Woo refund is one item with zero amount and no gateway payment');

  // Invoke this isolated candidate directly; do not call init() or register global fulfillment hooks.
  $sales::on_refund_created((int)$refund->get_id(), ['order_id'=>(int)$processing->get_id()]);
  $refunded_stats = $sales::get_showing_stats($showing_id);
  $check($refunded_stats['sold_qty']===2 && $refunded_stats['gross_revenue']===0.0 && $refunded_stats['refunded_revenue']===0.0 && $refunded_stats['net_revenue']===0.0, 'actual partial quantity refund updates Sales stats without changing original collected gross');

  // Verify installed Woo AJAX's contract; its data-store delete does not emit it.
  $ajax = new ReflectionMethod('WC_AJAX', 'delete_refund');
  $ajax_lines = file($ajax->getFileName());
  $ajax_source = implode('', array_slice($ajax_lines, $ajax->getStartLine()-1, $ajax->getEndLine()-$ajax->getStartLine()+1));
  if (!preg_match('/do_action\(\s*[\'\"]woocommerce_refund_deleted[\'\"]\s*,\s*\$refund_id\s*,\s*\$order_id\s*\)/', $ajax_source)) throw new RuntimeException('Installed Woo refund deletion hook contract has changed.');
  // Suppress installed Sales/Tickets listeners and simulate that exact action
  // after deleting only our actual fixture refund. This is not an AJAX UI test.
  foreach ([
    ['woocommerce_refund_deleted', '\\RoxyST\\Sales', 'on_refund_deleted', 20, 2],
    ['woocommerce_refund_deleted', '\\RoxyST\\Tickets', 'on_refund_deleted', 30, 2],
  ] as [$hook,$class,$method,$priority,$accepted]) {
    if (class_exists($class)) {
      $callback = [$class,$method]; $registered = has_action($hook,$callback);
      if ($registered !== false) { remove_action($hook,$callback,$registered); $detached[] = [$hook,$callback,$registered,$accepted]; }
    }
  }
  $delete_event = null;
  $capture_delete = static function ($refund_id, $parent_order_id) use (&$delete_event): void { $delete_event=[(int)$refund_id,(int)$parent_order_id]; };
  add_action('woocommerce_refund_deleted', $capture_delete, PHP_INT_MAX, 2);
  $deleted_refund_id = (int)$refund->get_id();
  $refund->delete(true);
  $deleted = !wc_get_order($deleted_refund_id);
  if ($deleted) do_action('woocommerce_refund_deleted', $deleted_refund_id, (int)$processing->get_id());
  remove_action('woocommerce_refund_deleted', $capture_delete, PHP_INT_MAX);
  if (!$deleted || $delete_event !== [$deleted_refund_id,(int)$processing->get_id()]) throw new RuntimeException('Fixture refund deletion and installed hook contract did not complete.');
  $sales::on_refund_deleted($delete_event[0], $delete_event[1]);
  $restored = $sales::get_showing_stats($showing_id);
  $check($restored['sold_qty']===3 && $restored['gross_revenue']===0.0 && $restored['refunded_revenue']===0.0, 'actual Woo refund deletion plus candidate hook restores item quantity and invalidates cached stats');
  $check($mail_transport_attempts===0 && has_filter('pre_wp_mail', $mail_filter)!==false, 'fixture mail was intercepted before transport; none was sent');
  $check($GLOBALS['sales_refund_fixture_meta_writes']!==[] && array_reduce($GLOBALS['sales_refund_fixture_meta_writes'], static fn($ok,$write)=>$ok && in_array((int)$write[0],[$showing_id,(int)$processing->get_id(),(int)$on_hold->get_id()],true), true), 'candidate metadata cache/tag writes remained in the in-memory fixture map');
  echo "Passed installed-Woo Sales refund checks. Zero-value only; no gateway or real ticket product.\n";
} finally {
  if (isset($capture_delete)) remove_action('woocommerce_refund_deleted', $capture_delete, PHP_INT_MAX);
  try {
    foreach (array_reverse($created_refunds) as $refund_id) {
      $refund = wc_get_order($refund_id);
      if ($refund) {
        $parent = wc_get_order((int)$refund->get_parent_id());
        if (!$parent || strpos((string)$parent->get_customer_note(), $token) === false) throw new RuntimeException('Refusing to clean a refund whose parent is not fixture-owned.');
        $refund->delete(true);
      }
    }
    foreach (array_reverse($created_orders) as $order_id) $safe_delete_order((int)$order_id);
    foreach (array_reverse($created_products) as $product_id) {
      $product = wc_get_product($product_id);
      if ($product) {
        if ($product->get_name() !== $token . ' generic zero-value product' || $product->get_type() !== 'simple' || (float)$product->get_price() !== 0.0) throw new RuntimeException('Refusing to delete a product not owned by this fixture.');
        $product->delete(true);
      }
    }
  } finally {
    foreach ($detached as [$hook,$callback,$priority,$accepted]) add_action($hook,$callback,$priority,$accepted);
    remove_filter('pre_wp_mail', $mail_filter, PHP_INT_MAX);
    remove_action('phpmailer_init', $mail_transport_guard, PHP_INT_MAX);
  }
}
