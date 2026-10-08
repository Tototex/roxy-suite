<?php
/** Offline fixture for payment eligibility, per-item refund stats, and refund invalidation. */
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!defined('ROXY_ST_META_SHOWING_ID')) define('ROXY_ST_META_SHOWING_ID', '_roxy_showing_id');
if (!defined('ROXY_ST_META_TICKET_TYPE')) define('ROXY_ST_META_TICKET_TYPE', '_roxy_ticket_type');

$candidate = $argv[1] ?? dirname(__DIR__) . '/includes/modules/show-tickets/includes/class-roxy-st-sales.php';
if (!is_file($candidate)) throw new RuntimeException('Candidate Sales file missing: ' . $candidate);
$namespace = 'SalesPaymentRefundFixture_' . bin2hex(random_bytes(4));
function wc_get_is_paid_statuses(): array { return ['processing','completed','custom-paid']; }
$source = file_get_contents($candidate);
if (!is_string($source)) throw new RuntimeException('Could not read candidate Sales source.');
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$source = str_replace('namespace RoxyST;', 'namespace ' . $namespace . ';', $source);

eval('namespace ' . $namespace . ';
  final class CPT { public const POST_TYPE = "roxy_showing"; }
  final class Item {
    public int $id; public int $product_id; public int $quantity; public float $total;
    public function __construct(int $id, int $product_id, int $quantity, float $total) { $this->id=$id; $this->product_id=$product_id; $this->quantity=$quantity; $this->total=$total; }
    public function get_id(): int { return $this->id; }
    public function get_product_id(): int { return $this->product_id; }
    public function get_quantity(): int { return $this->quantity; }
    public function get_total(): float { return $this->total; }
  }
  final class Order {
    public string $status; public bool $paid; public array $items; public array $refund_qty; public array $refund_total;
    public function __construct(string $status, bool $paid, array $items, array $refund_qty=[], array $refund_total=[]) { $this->status=$status; $this->paid=$paid; $this->items=$items; $this->refund_qty=$refund_qty; $this->refund_total=$refund_total; }
    public function get_status(): string { return $this->status; }
    public function is_paid(): bool { return $this->paid; }
    public function get_items(): array { return $this->items; }
    public function get_qty_refunded_for_item(int $id) { return $this->refund_qty[$id] ?? 0; }
    public function get_total_refunded_for_item(int $id) { return $this->refund_total[$id] ?? 0; }
    public function get_date_created() { return null; }
  }
  function wc_get_orders(array $args) { $GLOBALS["sales_fixture_query"] = $args; if (!empty($GLOBALS["sales_fixture_query_failure"]) && (empty($GLOBALS["sales_fixture_legacy_failure_only"]) || !isset($args["meta_query"]))) return false; return array_keys(array_filter($GLOBALS["sales_fixture_orders"] ?? [], static fn($order) => in_array($order->get_status(), $args["status"] ?? [], true))); }
  function wc_get_order($id) { return $GLOBALS["sales_fixture_orders"][(int)$id] ?? null; }
  function get_post_meta($id, $key, $single=false) { return $GLOBALS["sales_fixture_meta"][(int)$id][$key] ?? ""; }
  function update_post_meta($id, $key, $value) { if (!empty($GLOBALS["sales_fixture_tag_write_failure"]) && strpos((string)$key, "_roxy_contains_showing_") === 0) return false; $GLOBALS["sales_fixture_meta"][(int)$id][$key]=$value; return true; }
  function delete_post_meta($id, $key) { unset($GLOBALS["sales_fixture_meta"][(int)$id][$key]); return true; }
  function current_time($type) { return "2037-01-01 12:00:00"; }
  function wp_timezone() { return new \\DateTimeZone("UTC"); }
  function wp_date($format, $timestamp=null, $timezone=null) { return gmdate($format, $timestamp ?? time()); }
  function add_action($hook, $callback, $priority=10, $accepted_args=1) { $GLOBALS["sales_fixture_hooks"][$hook]=[$callback,$priority,$accepted_args]; }
');
eval($source);
$sales = '\\' . $namespace . '\\Sales';
$item = '\\' . $namespace . '\\Item';
$order = '\\' . $namespace . '\\Order';
$showing_id = 501; $product_id = 701;
$GLOBALS['sales_fixture_meta'] = [
  $showing_id => ['_roxy_pid_adult'=>$product_id, '_roxy_start'=>'2037-01-02T19:00'],
  $product_id => [ROXY_ST_META_SHOWING_ID => (string)$showing_id],
];
$GLOBALS['sales_fixture_orders'] = [];
$GLOBALS['sales_fixture_hooks'] = [];
$checks = 0;
$check = static function ($ok, string $label) use (&$checks): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $label);
  $checks++; echo "PASS: {$label}\n";
};
$calculate = static function (int $id=42) use ($sales): array {
  $method = new ReflectionMethod($sales, 'calculate_showing_stats');
  $method->setAccessible(true);
  return $method->invoke(null, 501);
};
$single_order = static function (string $status, bool $paid, int $qty=1, float $total=0.0, array $refund_qty=[], array $refund_total=[]) use ($item,$order): void {
  $GLOBALS['sales_fixture_orders'] = [42 => new $order($status,$paid,[new $item(901,701,$qty,$total)],$refund_qty,$refund_total)];
};

// Empty/default API contract includes explicit refund and net revenue totals.
$empty = $sales::get_showing_stats(0);
$check(($empty['refunded_revenue'] ?? null) === 0.0 && ($empty['net_revenue'] ?? null) === 0.0, 'empty stats expose zero refunded_revenue and net_revenue');

// Processing/completed zero-dollar orders count even if Woo does not mark them paid.
$single_order('processing', false, 1, 0.0);
$stats = $calculate();
$check($stats['sold_qty']===1 && $stats['gross_revenue']===0.0, 'processing zero-dollar order is eligible');
$single_order('completed', false, 2, 0.0);
$stats = $calculate();
$check($stats['sold_qty']===2 && $stats['gross_revenue']===0.0, 'completed zero-dollar order is eligible');

// An unpaid on-hold order is excluded, while a Woo-paid custom status remains eligible.
$single_order('on-hold', false, 2, 20.0);
$check($calculate()['sold_qty']===0, 'unpaid on-hold order contributes no sold quantity or revenue');
$single_order('custom-paid', true, 2, 20.0);
$check($calculate()['sold_qty']===2 && $calculate()['gross_revenue']===20.0, 'Woo is_paid semantics include a paid custom status');

// Canceled/refunded terminal orders remain excluded even if a faulty fake reports is_paid.
foreach (['cancelled','refunded'] as $status) {
  $single_order($status, true, 2, 20.0);
  $stats = $calculate();
  $check($stats['sold_qty']===0 && $stats['gross_revenue']===0.0 && $stats['refunded_revenue']===0.0 && $stats['net_revenue']===0.0 && $stats['order_count']===0, $status . ' terminal order is excluded from every eligible-order projection');
}

// Partial signed refund quantity is normalized/clamped; gross stays at the sale value, refund/net are separate.
$single_order('processing', true, 3, 30.0, [901=>-1], [901=>-10.0]);
$stats = $calculate();
$check($stats['sold_qty']===2 && $stats['paid_qty']===2 && $stats['ticket_types']['adult']['qty']===2, 'partial signed item refund reduces current item and paid quantities');
$check($stats['gross_revenue']===30.0 && $stats['refunded_revenue']===10.0 && $stats['net_revenue']===20.0, 'eligible-order original line value is preserved with separate itemized refund and net values; not a dated cash ledger');
$single_order('processing', true, 3, 30.0, [901=>-3], [901=>-30.0]);
$stats = $calculate();
$check($stats['sold_qty']===0 && $stats['paid_qty']===0 && $stats['ticket_types']['adult']['qty']===0, 'fully item-refunded processing order has no remaining sold tickets');
$check($stats['gross_revenue']===30.0 && $stats['refunded_revenue']===30.0 && $stats['net_revenue']===0.0 && $stats['ticket_types']['adult']['revenue']===30.0 && $stats['order_count']===1, 'fully item-refunded active order preserves the eligible-order original-value API distinctly from terminal refunded status');
$GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_pid_discount'] = 702;
$GLOBALS['sales_fixture_orders'] = [42 => new $order('processing',true,[new $item(901,701,3,30.0),new $item(902,702,2,16.0)], [901=>-1], [901=>-10.0])];
$stats = $calculate();
$check($stats['sold_qty']===4 && $stats['ticket_types']['adult']['qty']===2 && $stats['ticket_types']['discount']['qty']===2 && $stats['gross_revenue']===46.0 && $stats['refunded_revenue']===10.0 && $stats['net_revenue']===36.0 && $stats['order_count']===1, 'mixed ticket types subtract only the exact refunded line quantity and amount');
unset($GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_pid_discount']);
$single_order('processing', true, 2, 20.0, [901=>-9], [901=>-20.0]);
$check($calculate()['sold_qty']===0, 'refunded item quantity is clamped at zero rather than becoming negative');

// Cached stats are version-invalidated; refund deletion uses Woo’s (refund_id, parent_order_id) hook contract.
$single_order('processing', true, 3, 30.0, [901=>-1], [901=>-10.0]);
$GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_sales_stats'] = ['cache_version'=>2,'sold_qty'=>99,'gross_revenue'=>999.0];
$fresh = $sales::get_showing_stats($showing_id);
$check($fresh['sold_qty']===2, 'old cache version is recalculated after payment/refund semantics change');
$GLOBALS['sales_fixture_orders'][42] = new $order('processing',true,[new $item(901,701,3,30.0)]);
$sales::init();
$hook = $GLOBALS['sales_fixture_hooks']['woocommerce_refund_deleted'] ?? null;
$check(is_array($hook) && ltrim($hook[0][0], '\\')===ltrim($sales, '\\') && $hook[0][1]==='on_refund_deleted' && $hook[2]===2, 'refund-deleted hook registers the parent-order second argument');
$sales::on_refund_deleted(902,42);
$after_delete = $sales::get_showing_stats($showing_id);
$check($after_delete['sold_qty']===3 && $after_delete['refunded_revenue']===0.0 && $after_delete['gross_revenue']===30.0, 'refund deletion clears cached stats and restores original item quantity/revenue');

// A failed legacy read must not complete the scan or replace good cached totals.
$GLOBALS['sales_fixture_orders'] = [];
$GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_sales_stats'] = [
  'cache_version'=>3, 'sold_qty'=>7, 'paid_qty'=>7, 'gross_revenue'=>70.0,
  'refunded_revenue'=>0.0, 'net_revenue'=>70.0, 'order_count'=>2,
];
$GLOBALS['sales_fixture_query_failure'] = true;
$GLOBALS['sales_fixture_legacy_failure_only'] = true;
$failed_refresh = $sales::refresh_showing_stats($showing_id);
$check(!empty($failed_refresh['read_error']) && $failed_refresh['sold_qty']===7
  && $GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_sales_stats']['sold_qty']===7,
  'failed legacy order query preserves last-known-good totals and marks the read unavailable');
$check(!isset($GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_legacy_sales_scan_complete'])
  && $sales::sold_qty_for_showing($showing_id)===PHP_INT_MAX,
  'failed legacy order query cannot mark its scan complete or let capacity treat unreadable sales as zero');
unset($GLOBALS['sales_fixture_query_failure'], $GLOBALS['sales_fixture_legacy_failure_only']);
$recovered = $sales::refresh_showing_stats($showing_id);
$check(empty($recovered['read_error']) && $recovered['sold_qty']===0
  && $GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_legacy_sales_scan_complete']==='1',
  'successful retry completes the legacy scan and clears the temporary unavailable state');
$GLOBALS['sales_fixture_orders'] = [42 => new $order('processing',true,[new $item(901,701,1,10.0)])];
unset($GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_legacy_sales_scan_complete']);
$GLOBALS['sales_fixture_tag_write_failure'] = true;
$legacy_scan = new ReflectionMethod($sales, 'find_and_tag_legacy_orders_for_showing');
$legacy_scan->setAccessible(true);
$tag_failure = false;
try { $legacy_scan->invoke(null, $showing_id, [701=>'adult']); } catch (Throwable $e) { $tag_failure = true; }
unset($GLOBALS['sales_fixture_tag_write_failure']);
$check($tag_failure && !isset($GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_legacy_sales_scan_complete']),
  'failed legacy order-tag write cannot certify the scan complete');

echo "Passed {$checks} isolated Sales payment/refund checks. No provider or database access.\n";
