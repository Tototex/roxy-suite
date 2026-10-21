<?php
/** Offline fixture for payment eligibility, per-item refund stats, and order-item/refund invalidation. */
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!defined('ROXY_ST_META_SHOWING_ID')) define('ROXY_ST_META_SHOWING_ID', '_roxy_showing_id');
if (!defined('ROXY_ST_META_TICKET_TYPE')) define('ROXY_ST_META_TICKET_TYPE', '_roxy_ticket_type');
if (!class_exists('SalesFixtureDatabase')) {
  final class SalesFixtureDatabase {
    public int $connection_id = 101;
    public string $last_error = '';
    public function prepare(string $query, ...$args): string {
      foreach ($args as $arg) {
        $replacement = is_int($arg) ? (string)$arg : "'" . str_replace("'", "''", (string)$arg) . "'";
        $query = preg_replace('/%[sd]/', $replacement, $query, 1);
      }
      return $query;
    }
    public function get_var(string $query) {
      if ($query === 'SELECT CONNECTION_ID()') return $this->connection_id;
      if (preg_match('/GET_LOCK\\(\\s*\'([^\']+)\'\\s*,\\s*\\d+\\s*\\)/', $query, $match)) {
        $owner = $GLOBALS['sales_fixture_locks'][$match[1]] ?? null;
        if ($owner !== null && (int)$owner !== $this->connection_id) return 0;
        $GLOBALS['sales_fixture_locks'][$match[1]] = $this->connection_id;
        return 1;
      }
      if (preg_match('/IS_USED_LOCK\\(\\s*\'([^\']+)\'\\s*\\)/', $query, $match)) return $GLOBALS['sales_fixture_locks'][$match[1]] ?? null;
      if (preg_match('/RELEASE_LOCK\\(\\s*\'([^\']+)\'\\s*\\)/', $query, $match)) {
        if (($GLOBALS['sales_fixture_locks'][$match[1]] ?? null) !== $this->connection_id) return 0;
        unset($GLOBALS['sales_fixture_locks'][$match[1]]);
        return 1;
      }
      return null;
    }
  }
}
if (!isset($GLOBALS['wpdb'])) $GLOBALS['wpdb'] = new SalesFixtureDatabase();
if (!class_exists('WC_Order_Factory')) {
  class WC_Order_Factory {
    public static function get_order_item(int $item_id) {
      foreach ($GLOBALS['sales_fixture_orders'] ?? [] as $order) {
        foreach ($order->get_items() as $item) if ($item->get_id() === $item_id) return $item;
      }
      return null;
    }
  }
}

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
    public int $id; public int $product_id; public int $quantity; public float $total; public int $order_id;
    public function __construct(int $id, int $product_id, int $quantity, float $total, int $order_id=42) { $this->id=$id; $this->product_id=$product_id; $this->quantity=$quantity; $this->total=$total; $this->order_id=$order_id; }
    public function get_id(): int { return $this->id; }
    public function get_order_id(): int { return $this->order_id; }
    public function get_product_id(): int { return $this->product_id; }
    public function get_quantity(): int { return $this->quantity; }
    public function get_total(): float { return $this->total; }
  }
  final class Meta { private string $key; private $value; public function __construct(string $key,$value) {$this->key=$key;$this->value=$value;} public function get_data(): array {return ["key"=>$this->key,"value"=>$this->value];} }
  final class Order {
    public int $id; public string $status; public bool $paid; public array $items; public array $refund_qty; public array $refund_total; public array $meta=[]; public array $pending_meta=[];
    public function __construct(string $status, bool $paid, array $items, array $refund_qty=[], array $refund_total=[], int $id=42) { $this->id=$id; $this->status=$status; $this->paid=$paid; $this->items=$items; $this->refund_qty=$refund_qty; $this->refund_total=$refund_total; }
    public function get_status(): string { return $this->status; }
    public function is_paid(): bool { return $this->paid; }
    public function get_items(): array { return $this->items; }
    public function get_qty_refunded_for_item(int $id) { return $this->refund_qty[$id] ?? 0; }
    public function get_total_refunded_for_item(int $id) { return $this->refund_total[$id] ?? 0; }
    public function get_date_created() { return null; }
    public function get_meta(string $key, bool $single=true) { return $this->pending_meta[$key] ?? $this->meta[$key] ?? ""; }
    public function get_meta_data(): array { $all=array_merge($this->meta,$this->pending_meta); $rows=[]; foreach ($all as $key=>$value) $rows[]=new Meta((string)$key,$value); return $rows; }
    public function update_meta_data(string $key, $value): void { $this->pending_meta[$key]=$value; }
    public function save(): int { if (!empty($GLOBALS["sales_fixture_order_save_failure"])) { $this->pending_meta=[]; return 0; } $this->meta=array_merge($this->meta,$this->pending_meta); $this->pending_meta=[]; return $this->id; }
  }
  function wc_get_orders(array $args) {
    $GLOBALS["sales_fixture_query"] = $args;
    if (!empty($GLOBALS["sales_fixture_query_failure"]) && (empty($GLOBALS["sales_fixture_legacy_failure_only"]) || !isset($args["meta_query"]))) return false;
    if (!empty($GLOBALS["sales_fixture_drop_refresh_lock"])) {
      foreach ($GLOBALS["sales_fixture_locks"] as $name=>$owner) if ($owner===$GLOBALS["wpdb"]->connection_id) unset($GLOBALS["sales_fixture_locks"][$name]);
      unset($GLOBALS["sales_fixture_drop_refresh_lock"]);
    }
    $orders=array_filter($GLOBALS["sales_fixture_orders"] ?? [], static fn($order) => in_array($order->get_status(), $args["status"] ?? [], true));
    if (!empty($GLOBALS["sales_fixture_filter_tagged_orders"]) && isset($args["meta_query"][0])) {
      $meta=$args["meta_query"][0];
      $orders=array_filter($orders,static fn($order) => (string)$order->get_meta((string)$meta["key"],true)===(string)$meta["value"]);
    }
    return array_keys($orders);
  }
  function wc_get_order($id) { return $GLOBALS["sales_fixture_orders"][(int)$id] ?? null; }
  function get_post_meta($id, $key, $single=false) { return $GLOBALS["sales_fixture_meta"][(int)$id][$key] ?? ""; }
  function update_post_meta($id, $key, $value) { if (!empty($GLOBALS["sales_fixture_tag_write_failure"]) && strpos((string)$key, "_roxy_contains_showing_") === 0) return false; if ($key==="_roxy_sales_stats" && !empty($GLOBALS["sales_fixture_drop_lock_during_stats_write"])) { foreach ($GLOBALS["sales_fixture_locks"] as $name=>$owner) if ($owner===$GLOBALS["wpdb"]->connection_id) unset($GLOBALS["sales_fixture_locks"][$name]); unset($GLOBALS["sales_fixture_drop_lock_during_stats_write"]); } if ($key==="_roxy_sales_stats" && !empty($GLOBALS["sales_fixture_stats_write_failure"])) { $GLOBALS["wpdb"]->last_error="fixture stats-cache write failure"; return false; } if ($key==="_roxy_sales_stats") $GLOBALS["sales_fixture_stats_writes"] = ($GLOBALS["sales_fixture_stats_writes"] ?? 0)+1; $GLOBALS["sales_fixture_meta"][(int)$id][$key]=$value; return true; }
  function delete_post_meta($id, $key, $value=null) { if ($value===null || ($GLOBALS["sales_fixture_meta"][(int)$id][$key] ?? null)===$value) unset($GLOBALS["sales_fixture_meta"][(int)$id][$key]); return true; }
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
  if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
  return $method->invoke(null, 501);
};
$single_order = static function (string $status, bool $paid, int $qty=1, float $total=0.0, array $refund_qty=[], array $refund_total=[]) use ($item,$order): void {
  $GLOBALS['sales_fixture_orders'] = [42 => new $order($status,$paid,[new $item(901,701,$qty,$total)],$refund_qty,$refund_total)];
};

// Refresh serialization must fail closed on contention and after a reconnect.
$GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_legacy_sales_scan_complete'] = '1';
$sales_lock_name = 'roxy_sales_stats_' . md5('1:' . $showing_id);
$GLOBALS['sales_fixture_locks'][$sales_lock_name] = 999;
$writes_before_busy_refresh = $GLOBALS['sales_fixture_stats_writes'] ?? 0;
$busy_refresh = $sales::refresh_showing_stats($showing_id);
$check(!empty($busy_refresh['read_error']) && ($GLOBALS['sales_fixture_stats_writes'] ?? 0)===$writes_before_busy_refresh
  && ($GLOBALS['sales_fixture_locks'][$sales_lock_name] ?? null)===999,
  'contended showing refresh preserves cached totals and does not write or release another connection’s lock');
unset($GLOBALS['sales_fixture_locks'][$sales_lock_name]);
$GLOBALS['sales_fixture_drop_refresh_lock'] = true;
$writes_before_lost_lock = $GLOBALS['sales_fixture_stats_writes'] ?? 0;
$lost_lock_refresh = $sales::refresh_showing_stats($showing_id);
$check(!empty($lost_lock_refresh['read_error']) && ($GLOBALS['sales_fixture_stats_writes'] ?? 0)===$writes_before_lost_lock
  && !isset($GLOBALS['sales_fixture_locks'][$sales_lock_name]),
  'connection losing its showing lock after the sales query cannot persist its calculated snapshot');
$recovered_lock_refresh = $sales::refresh_showing_stats($showing_id);
$check(empty($recovered_lock_refresh['read_error']) && ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT IS_USED_LOCK(%s)', $sales_lock_name))===null),
  'a subsequent refresh recovers and releases its own showing lock');
$previous_stats = ['cache_version'=>3,'sold_qty'=>7,'paid_qty'=>7,'gross_revenue'=>70.0,'refunded_revenue'=>0.0,'net_revenue'=>70.0,'order_count'=>2];
$GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_sales_stats'] = $previous_stats;
$GLOBALS['wpdb']->last_error = '';
$GLOBALS['sales_fixture_stats_write_failure'] = true;
$write_failure_refresh = $sales::refresh_showing_stats($showing_id);
unset($GLOBALS['sales_fixture_stats_write_failure']);
$GLOBALS['wpdb']->last_error = '';
$check(!empty($write_failure_refresh['read_error']) && $write_failure_refresh['sold_qty']===7
  && $GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_sales_stats']===$previous_stats
  && ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT IS_USED_LOCK(%s)', $sales_lock_name))===null),
  'stats-cache database write failure preserves last-known-good totals and is not reported as success');
unset($GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_sales_stats']);
$GLOBALS['sales_fixture_drop_lock_during_stats_write'] = true;
$lost_write_refresh = $sales::refresh_showing_stats($showing_id);
$check(!empty($lost_write_refresh['read_error']) && !isset($GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_sales_stats'])
  && ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT IS_USED_LOCK(%s)', $sales_lock_name))===null),
  'connection loss at the stats-cache write boundary removes only its unowned candidate and fails closed');

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
// Malformed provider values must not be silently cast to zero and overstate sales/net revenue.
$single_order('processing', true, 3, 30.0, [901=>'malformed'], [901=>-10.0]);
$malformed_qty_failed_closed = false;
try { $calculate(); } catch (RuntimeException $e) { $malformed_qty_failed_closed = true; }
$check($malformed_qty_failed_closed, 'malformed refunded quantity rejects the complete ticket-sales projection');
$single_order('processing', true, 3, 30.0, [901=>-1], [901=>'malformed']);
$malformed_total_failed_closed = false;
try { $calculate(); } catch (RuntimeException $e) { $malformed_total_failed_closed = true; }
$check($malformed_total_failed_closed, 'malformed refunded amount rejects the complete ticket-sales projection');
$GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_sales_stats'] = [
  'cache_version'=>3, 'sold_qty'=>2, 'paid_qty'=>2, 'gross_revenue'=>30.0,
  'refunded_revenue'=>10.0, 'net_revenue'=>20.0, 'order_count'=>1,
];
$refresh_with_bad_refund = $sales::refresh_showing_stats($showing_id);
$check(!empty($refresh_with_bad_refund['read_error']) && $refresh_with_bad_refund['sold_qty']===2
  && $refresh_with_bad_refund['net_revenue']===20.0
  && $GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_sales_stats']['net_revenue']===20.0,
  'malformed refund data preserves last-known-good historical totals and marks refresh unavailable');
$single_order('processing', true, 3, 30.0, [901=>-1.5], [901=>-10.0]);
$fractional_qty_failed_closed = false;
try { $calculate(); } catch (RuntimeException $e) { $fractional_qty_failed_closed = true; }
$check($fractional_qty_failed_closed, 'fractional refunded ticket quantity is not truncated into a plausible count');
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
$sales::clear_showing_cache($showing_id);
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

// Normal Woo admin edits fire per-item hooks during a batch. The projection must
// refresh once, after the final item values are saved, and include old showings.
$sales::clear_showing_cache($showing_id);
$GLOBALS['sales_fixture_orders'][42] = new $order('processing',true,[new $item(901,701,2,20.0)]);
$GLOBALS['sales_fixture_orders'][42]->meta['_roxy_contains_showing_' . $showing_id]='1';
$GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_legacy_sales_scan_complete']='1';
$before_edit = $sales::get_showing_stats($showing_id);
$check($before_edit['sold_qty']===2 && $before_edit['gross_revenue']===20.0, 'line-item edit fixture begins with cached original quantity and amount');
$sales::before_order_items_saved(42, []);
$GLOBALS['sales_fixture_orders'][42]->items=[new $item(901,701,4,44.0)];
$sales::on_order_item_updated(901, $GLOBALS['sales_fixture_orders'][42]->items[0], 42);
$writes_before_admin_save = $GLOBALS['sales_fixture_stats_writes'] ?? 0;
$sales::on_order_items_saved(42, []);
$writes_after_admin_save = $GLOBALS['sales_fixture_stats_writes'] ?? 0;
$after_edit = $sales::get_showing_stats($showing_id);
$check($after_edit['sold_qty']===4 && $after_edit['gross_revenue']===44.0,
  'completed admin line-item save rebuilds cached quantity and amount after edits');
$check($writes_after_admin_save===$writes_before_admin_save+1,
  'batched admin item hooks defer to one projection refresh at save completion');

// Generic CRUD invalidates edits and retains the old showing when its last
// ticket item is deleted, even though no ticket product remains on the order.
$sales::clear_showing_cache($showing_id);
$GLOBALS['sales_fixture_orders'][42]->items=[new $item(901,701,4,44.0)];
$sales::get_showing_stats($showing_id);
$GLOBALS['sales_fixture_orders'][42]->items=[new $item(901,701,5,55.0)];
$sales::on_order_item_updated(901, ['order_item_qty'=>5,'line_total'=>55.0]);
$after_generic_edit = $sales::get_showing_stats($showing_id);
$check($after_generic_edit['sold_qty']===5 && $after_generic_edit['gross_revenue']===55.0,
  'generic Woo order-item CRUD update rebuilds cached quantity and amount');
$sales::before_order_item_deleted(901);
$GLOBALS['sales_fixture_orders'][42]->items=[];
$sales::on_order_item_deleted(901);
$after_item_removal = $sales::get_showing_stats($showing_id);
$check($after_item_removal['sold_qty']===0 && $after_item_removal['gross_revenue']===0.0,
  'generic deletion of an order’s last ticket item clears its former showing projection');

// Changing a line to another showing's product must clear the prior showing,
// even though Woo's generic update action sees only the post-update item.
$second_showing_id = 502; $second_product_id = 702;
$GLOBALS['sales_fixture_meta'][$second_showing_id] = ['_roxy_pid_adult'=>$second_product_id, '_roxy_start'=>'2037-01-03T19:00', '_roxy_legacy_sales_scan_complete'=>'1'];
$GLOBALS['sales_fixture_meta'][$second_product_id] = [ROXY_ST_META_SHOWING_ID => (string)$second_showing_id];
$GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_legacy_sales_scan_complete'] = '1';
$GLOBALS['sales_fixture_orders'][42] = new $order('processing',true,[new $item(901,701,3,30.0)]);
$sales::on_order_changed(42);
$GLOBALS['sales_fixture_orders'][42]->items=[new $item(901,702,2,24.0)];
$sales::on_order_item_updated(901, ['order_item_qty'=>2,'line_total'=>24.0], 42);
$old_product_showing_stats = $sales::get_showing_stats($showing_id);
$new_product_showing_stats = $sales::get_showing_stats($second_showing_id);
$check($old_product_showing_stats['sold_qty']===0 && $old_product_showing_stats['gross_revenue']===0.0
  && $new_product_showing_stats['sold_qty']===2 && $new_product_showing_stats['gross_revenue']===24.0,
  'generic ticket product reassignment clears former showing totals and refreshes the destination showing');
$GLOBALS['sales_fixture_orders'][42]->items=[new $item(901,703,2,24.0)];
$sales::on_order_item_updated(901, ['order_item_qty'=>2,'line_total'=>24.0], 42);
$removed_ticket_showing_stats = $sales::get_showing_stats($second_showing_id);
$check($removed_ticket_showing_stats['sold_qty']===0 && $removed_ticket_showing_stats['gross_revenue']===0.0,
  'generic reassignment from a ticket product to a non-ticket product clears the former showing projection');

$hooks = $GLOBALS['sales_fixture_hooks'];
$check(($hooks['woocommerce_before_save_order_items'][2] ?? null)===2
  && ($hooks['woocommerce_saved_order_items'][2] ?? null)===2
  && ($hooks['woocommerce_update_order_item'][2] ?? null)===3
  && ($hooks['woocommerce_before_delete_order_item'][2] ?? null)===1
  && ($hooks['woocommerce_delete_order_item'][2] ?? null)===1,
  'admin batch and generic Woo item lifecycle hooks register with expected argument counts');

// A failed legacy read must not complete the scan or replace good cached totals.
$GLOBALS['sales_fixture_orders'] = [];
unset($GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_legacy_sales_scan_complete']);
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
$GLOBALS['sales_fixture_orders'][42]->meta=[];
$GLOBALS['sales_fixture_order_save_failure'] = true;
$legacy_scan = new ReflectionMethod($sales, 'find_and_tag_legacy_orders_for_showing');
if (PHP_VERSION_ID < 80100) $legacy_scan->setAccessible(true);
$tag_failure = false;
try { $legacy_scan->invoke(null, $showing_id, [701=>'adult']); } catch (Throwable $e) { $tag_failure = true; }
unset($GLOBALS['sales_fixture_order_save_failure']);
$GLOBALS['sales_fixture_orders'][42]->meta=[];
$check($tag_failure && !isset($GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_legacy_sales_scan_complete']),
  'failed legacy order-tag write cannot certify the scan complete');

$GLOBALS['sales_fixture_orders'][42]->meta=[];
$GLOBALS['sales_fixture_order_save_failure'] = true;
$order_hook_survives_marker_failure = true;
try { $sales::mark_order_showings(42); } catch (Throwable $e) { $order_hook_survives_marker_failure = false; }
unset($GLOBALS['sales_fixture_order_save_failure']);
$check($order_hook_survives_marker_failure && !isset($GLOBALS['sales_fixture_orders'][42]->meta['_roxy_contains_showing_' . $showing_id]),
  'order status hook does not propagate a marker-save failure or claim the order marker persisted');

$sales::mark_order_showings(42);
$marker = '_roxy_contains_showing_' . $showing_id;
$check($GLOBALS['sales_fixture_orders'][42]->get_meta($marker, true)==='1'
  && !isset($GLOBALS['sales_fixture_meta'][42][$marker]),
  'showing discovery marker is stored on the Woo order object, not order postmeta');
$sales::mark_order_showings(42);
$check($GLOBALS['sales_fixture_orders'][42]->get_meta($marker, true)==='1',
  'repeated order change preserves an existing showing marker');

$GLOBALS['sales_fixture_filter_tagged_orders']=true;
$GLOBALS['sales_fixture_orders'] = [
  42 => new $order('processing',true,[new $item(901,701,1,10.0)]),
  43 => new $order('processing',true,[new $item(902,701,1,10.0)],[],[],43),
];
$GLOBALS['sales_fixture_orders'][42]->meta[$marker]='1';
$GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_legacy_sales_scan_complete']='1';
$GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_pid_adult']=$product_id;
$GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_sales_stats']=['cache_version'=>3,'sold_qty'=>1,'paid_qty'=>1,'gross_revenue'=>10.0,'refunded_revenue'=>0.0,'net_revenue'=>10.0,'order_count'=>1];
$GLOBALS['sales_fixture_order_save_failure']=true;
$sales::on_order_changed(43);
unset($GLOBALS['sales_fixture_order_save_failure']);
$preserved=$sales::get_showing_stats($showing_id);
$check(!empty($preserved['read_error']) && $preserved['sold_qty']===1
  && $GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_sales_stats']['sold_qty']===1
  && !isset($GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_legacy_sales_scan_complete']),
  'failed marker save forces a complete retry, preserves the last good totals, and invalidates prior scan completion');
unset($GLOBALS['sales_fixture_filter_tagged_orders']);
$GLOBALS['sales_fixture_orders'] = [
  42 => new $order('processing',true,[new $item(901,701,1,10.0)]),
  43 => new $order('processing',true,[new $item(902,701,1,10.0)],[],[],43),
];
$GLOBALS['sales_fixture_orders'][42]->meta[$marker]='1';
unset($GLOBALS['sales_fixture_meta'][$showing_id]['_roxy_legacy_sales_scan_complete']);
$stats=$calculate();
$check($stats['sold_qty']===2 && $stats['order_count']===2,
  'an incomplete historical scan merges newly discovered orders with already-tagged orders without double-counting');

$cpt_source = file_get_contents(__DIR__ . '/../includes/modules/show-tickets/includes/class-roxy-st-cpt.php');
$check(is_string($cpt_source)
  && str_contains($cpt_source, "dashboard_card('Gross Revenue', wc_price((float) (\$stats['gross_revenue'] ?? 0)))")
  && str_contains($cpt_source, "dashboard_card('Refunds', wc_price((float) (\$stats['refunded_revenue'] ?? 0)))")
  && str_contains($cpt_source, "dashboard_card('Net Revenue', wc_price((float) (\$stats['net_revenue'] ?? 0)))")
  && str_contains($cpt_source, '<th>Gross Revenue</th>')
  && !str_contains($cpt_source, "dashboard_card('Revenue', wc_price((float) (\$stats['gross_revenue'] ?? 0)))"),
  'admin sales dashboard distinguishes original gross, refunds and net revenue after ticket refunds');

echo "Passed {$checks} isolated Sales payment/refund checks. No provider or database access.\n";
