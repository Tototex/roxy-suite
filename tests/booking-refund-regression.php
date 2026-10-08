<?php
/**
 * Standalone regression tests for the event-booking refund helper.
 * Run with: php tests/booking-refund-regression.php
 */

define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code;
    private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }

class FakeRefundItem {
    public $qty; public $total; public $tax; public $meta; public $taxes; public $type;
    public function __construct($qty, $total, $tax, array $meta = [], array $taxes = [], $type = 'line_item') { $this->qty=$qty; $this->total=$total; $this->tax=$tax; $this->meta=$meta; $this->taxes=$taxes ?: ($tax ? [1=>$tax] : []); $this->type=$type; }
    public function get_quantity() { return $this->qty; }
    public function get_total() { return $this->total; }
    public function get_total_tax() { return $this->tax; }
    public function get_taxes() { return ['total'=>$this->taxes]; }
    public function get_type() { return $this->type; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
}
class WC_Order {
    public $id; public $total; public $items; public $refunds=[]; public $primaryBooking=0; public $paid=true; public $meta=[]; public $savedMeta=[];
    public $datePaid = null; public $saveFailure = false; public $readbackFailure = false; public $status='processing'; public $saveCalls=0; public $failSaveOnCall=0; public $failReadbackOnCall=0; public $readCalls=0;
    public function __construct($id, $total, array $items, $primaryBooking = 0, array $refunds = [], $paid = true) { $this->id=$id; $this->total=$total; $this->items=$items; $this->primaryBooking=$primaryBooking; $this->refunds=$refunds; $this->paid=$paid; $this->datePaid=$paid ? (object)['date'=>'2026-01-01'] : null; }
    public function get_id() { return $this->id; }
    public function get_total() { return $this->total; }
    public function get_items($type = 'line_item') { return $this->items; }
    public function get_refunds() { return $this->refunds; }
    public function is_paid() { return $this->paid; }
    public function has_status($status) { return $status === 'refunded' && $this->get_total_refunded() >= $this->total; }
    public function get_meta($key, $single = true) { return $key === '_roxy_eb_booking_id' ? $this->primaryBooking : ($this->meta[$key] ?? ''); }
    public function get_date_paid() { return $this->datePaid; }
    public function add_order_note($note) { $GLOBALS['notes'][]=$note; }
    public function update_meta_data($key, $value) { $this->meta[$key]=$value; }
    public function save_meta_data() { $this->saveCalls++; if (!$this->saveFailure && $this->saveCalls !== $this->failSaveOnCall) $this->savedMeta=$this->meta; }
    public function read_meta_data($force = false) { $this->readCalls++; $this->meta=($this->readbackFailure || $this->readCalls === $this->failReadbackOnCall) ? [] : $this->savedMeta; }
    public function get_total_refunded() { return array_sum(array_map(static function($r) { return (float)$r->get_amount(); }, $this->refunds)); }
    private function refundItems($id) { $out=[]; foreach ($this->refunds as $r) foreach ($r->get_items(['line_item','fee','shipping']) as $rid=>$item) if ((int)$rid===(int)$id) $out[]=$item; return $out; }
    public function get_total_refunded_for_item($id) { return array_sum(array_map(static function($i){return -(float)$i->get_total();},$this->refundItems($id))); }
    public function get_tax_refunded_for_item($id, $rate) { $sum=0; foreach($this->refundItems($id) as $i) $sum += -(float)($i->get_taxes()['total'][$rate]??0); return $sum; }
    public function get_qty_refunded_for_item($id) { return array_sum(array_map(static function($i){return (float)$i->get_quantity();},$this->refundItems($id))); }
}
class FakeRefund {
    public $total; public $items;
    public function __construct($total, array $items) {
        $this->total=$total; $this->items=[];
        foreach ($items as $id=>$item) {
            if ($item instanceof FakeRefundItem) $this->items[$id]=$item;
            elseif (is_array($item)) {
                $taxes=$item['refund_tax']??[1=>($item['tax']??0)];
                if (!is_array($taxes)) $taxes=[1=>$taxes];
                $negativeTaxes=array_map(static function($v){return -abs((float)$v);},$taxes);
                $this->items[$id]=new FakeRefundItem(-abs((float)($item['qty']??0)),-abs((float)($item['refund_total']??$item['total']??0)),-abs(array_sum($taxes)),[],$negativeTaxes,$item['type']??'line_item');
            }
        }
    }
    public function get_amount() { return $this->total; }
    public function get_items($types = ['line_item']) { return $this->items; }
}
class WC_Order_Refund extends FakeRefund {
    public $refundedPayment; public function __construct($total,$items,$refundedPayment=true) { parent::__construct($total,$items); $this->refundedPayment=$refundedPayment; }
    public function get_id() { return 9001; }
    public function get_refunded_payment() { return $this->refundedPayment; }
}
class FakeWpdb {
    public $prefix = 'wp_'; public $queries=[]; public $locks=[]; public $connection=41; public $loseLock=false; public $contend=false; public $replaceConnectionOnGetLock=false;
    public function prepare($query, ...$args) { foreach ($args as $arg) $query = preg_replace('/%s|%d/', "'" . addslashes((string)$arg) . "'", $query, 1); return $query; }
    public function get_var($query) { $this->queries[]=$query; if (stripos($query, 'CONNECTION_ID') !== false) return $this->connection; if (stripos($query, 'GET_LOCK') !== false) { preg_match("/'([^']+)'/",$query,$m); if($this->contend)return 0; $this->locks[$m[1]??'']= $this->connection; if($this->replaceConnectionOnGetLock)$this->connection++; return '1'; } if (stripos($query, 'IS_USED_LOCK') !== false) { if($this->loseLock)return null; preg_match("/'([^']+)'/",$query,$m); return $this->locks[$m[1]??'']??null; } if (stripos($query, 'RELEASE_LOCK') !== false) { preg_match("/'([^']+)'/",$query,$m); unset($this->locks[$m[1]??'']); return 1; } return null; }
    public function query($query) { $this->queries[]=$query; return 1; }
}
$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['refund_response'] = null;
$GLOBALS['orders'] = [];
function roxy_eb_repo_get_booking($id) { return $GLOBALS['bookings'][$id] ?? null; }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? null; }
function wc_get_price_decimals() { return 2; }
function wc_create_refund($args) {
    $GLOBALS['wc_refund_calls'][] = $args;
    if (($GLOBALS['refund_behavior'] ?? '') === 'throw') throw new RuntimeException('gateway outcome unknown');
    if (($GLOBALS['refund_behavior'] ?? '') === 'error') return new WP_Error('gateway_error', 'gateway declined');
    if (($GLOBALS['refund_behavior'] ?? '') === 'unknown') return new WC_Order_Refund((float)$args['amount'], [], false);
    $items=[];
    foreach ($args['line_items'] as $id=>$line) $items[$id]=new FakeRefundItem(-$line['qty'],-$line['refund_total'],-array_sum($line['refund_tax']),[],array_map(static function($v){return -$v;},$line['refund_tax']));
    $refund = new WC_Order_Refund((float)$args['amount'], $items);
    if (isset($GLOBALS['orders'][$args['order_id']])) $GLOBALS['orders'][$args['order_id']]->refunds[]=$refund;
    if (isset($GLOBALS['during_refund'])) { $callback=$GLOBALS['during_refund']; unset($GLOBALS['during_refund']); $callback(); }
    return $refund;
}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function near($actual, $expected, $message) { check(abs((float)$actual - (float)$expected) < 0.0001, $message . ' (got ' . var_export($actual, true) . ')'); }
function run_test($name, $fn) { $fn(); echo "ok - $name\n"; }
function make_item($booking, $adjustment = null, $total = 0, $tax = 0, $qty = 1) {
    $meta=[]; if ($booking !== null) $meta['_roxy_eb_booking_id']=$booking;
    if ($adjustment !== null) $meta['_roxy_eb_booking_adjustment']=json_encode(['booking_id'=>$adjustment]);
    if ($booking !== null) $meta['_roxy_eb_booking']=json_encode(['booking_id'=>$booking]);
    return new FakeRefundItem($qty, $total, $tax, $meta, $tax ? [1=>$tax] : []);
}
function reset_gateway() { $GLOBALS['wc_refund_calls']=[]; $GLOBALS['refund_behavior']=''; }
function register_order($order) { $GLOBALS['orders'][$order->get_id()]=$order; return $order; }
function make_legacy_item($total, $tax=0) { return new FakeRefundItem(1,$total,$tax,['_roxy_eb_booking'=>json_encode(['legacy'=>true])],$tax ? [1=>$tax] : []); }
function make_adjustment_item($booking_id,$total,$tax=0) { return new FakeRefundItem(1,$total,$tax,['_roxy_eb_booking_adjustment'=>json_encode(['booking_id'=>$booking_id])],$tax ? [1=>$tax] : []); }

$helper = ($argv[1] ?? dirname(__DIR__)) . '/includes/modules/event-booking/includes/refunds.php';
if (!is_file($helper)) { fwrite(STDERR, "Missing planned helper: $helper\n"); exit(2); }
require_once $helper;

run_test('mixed cart plan preserves unrelated item and actual paid item totals/tax', function() {
    $order=new WC_Order(10, 160, [21=>make_item(7,null,100,10), 22=>make_item(null,null,40,4), 23=>make_item(8,null,20,2)]);
    $p=roxy_eb_booking_refund_plan($order,7);
    check(!is_wp_error($p), 'expected plan'); near($p['amount'],110,'booking refund amount');
    check(count($p['line_items'])===1 && isset($p['line_items'][21]), 'only owned line item should be refunded');
    near($p['line_items'][21]['refund_total'],100,'actual discounted line total'); near(array_sum($p['line_items'][21]['refund_tax']),10,'actual tax');
});
run_test('prior itemized refund reduces item balance including tax', function() {
    $prior=new FakeRefund(33,[31=>['qty'=>1,'refund_total'=>30,'refund_tax'=>[1=>3]]]);
    $order=new WC_Order(11,110,[31=>make_item(7,null,90,9),32=>make_item(7,null,10,1)],0,[$prior]);
    $p=roxy_eb_booking_refund_plan($order,7);
    check(!is_wp_error($p),'expected remainder plan'); near($p['amount'],77,'remaining amount');
    near($p['line_items'][31]['refund_total'],60,'remaining item total'); near(array_sum($p['line_items'][31]['refund_tax']),6,'remaining item tax');
});
run_test('ambiguous legacy primary order linkage is rejected', function() {
    $GLOBALS['bookings'][7]=['id'=>7,'woo_order_id'=>12];
    $o=new WC_Order(12,100,[1=>make_legacy_item(50),2=>make_legacy_item(50)],0);
    check(is_wp_error(roxy_eb_booking_refund_plan($o,7)), 'multi-item legacy order must be ambiguous');
});
run_test('single legacy primary booking item is accepted only for its linked order', function() {
    $GLOBALS['bookings'][70]=['id'=>70,'woo_order_id'=>120];
    $o=new WC_Order(120,40,[5=>make_legacy_item(40)],0);
    $p=roxy_eb_booking_refund_plan($o,70); check(!is_wp_error($p) && isset($p['line_items'][5]),'one legacy booking line should resolve');
    $other=new WC_Order(121,40,[5=>make_legacy_item(40)],0);
    check(is_wp_error(roxy_eb_booking_refund_plan($other,70)),'legacy link to another order must fail');
});
run_test('adjustment order line is owned by JSON booking_id metadata', function() {
    $o=new WC_Order(122,35,[6=>make_adjustment_item(72,30,5)]);
    $p=roxy_eb_booking_refund_plan($o,72);
    check(!is_wp_error($p) && isset($p['line_items'][6]),'adjustment booking_id should select its line');
    check(is_wp_error(roxy_eb_booking_refund_plan($o,73)),'different booking must not select adjustment line');
});
run_test('wrong explicit item and missing ownership metadata are rejected', function() {
    $o=new WC_Order(13,100,[1=>make_item(8,null,100,0)]);
    check(is_wp_error(roxy_eb_booking_refund_plan($o,7,1)), 'conflicting item owner must fail');
    $u=new WC_Order(14,100,[1=>make_item(null,null,100,0)]);
    check(is_wp_error(roxy_eb_booking_refund_plan($u,7,1)), 'explicit item without booking metadata must fail');
});
run_test('explicit item accepts matching booking payload and rejects conflicting booking metadata', function() {
    $matching=new WC_Order(131,25,[4=>make_item(7,null,25,0)]);
    $p=roxy_eb_booking_refund_plan($matching,7,4); check(!is_wp_error($p) && isset($p['line_items'][4]),'matching explicit item should be selected');
    $conflicting=new WC_Order(132,25,[4=>make_item(8,null,25,0)]);
    check(is_wp_error(roxy_eb_booking_refund_plan($conflicting,7,4)),'explicit item with another booking id must be rejected');
});
run_test('unallocated prior refund is rejected', function() {
    $o=new WC_Order(15,100,[1=>make_item(7,null,100,0)],0,[new FakeRefund(10,[])]);
    check(is_wp_error(roxy_eb_booking_refund_plan($o,7)), 'unallocated prior refund must fail');
});
run_test('zero remaining balance does not call gateway', function() {
    reset_gateway(); $o=register_order(new WC_Order(16,50,[1=>make_item(7,null,50,0)],0,[new FakeRefund(50,[1=>['qty'=>1,'refund_total'=>50,'refund_tax'=>[1=>0]]])]));
    $r=roxy_eb_refund_booking_payment($o,7);
    check(!is_wp_error($r) && !$r['refunded'] && $r['refund']===null,'expected no-op'); check(count($GLOBALS['wc_refund_calls'])===0,'gateway must not be called');
});
run_test('gateway request is exact and successful', function() {
    reset_gateway(); $o=register_order(new WC_Order(17,100,[1=>make_item(7,null,90,10)]));
    $r=roxy_eb_refund_booking_payment($o,7);
    check(!is_wp_error($r) && $r['refunded'],'expected successful refund'); near($r['amount'],100,'requested amount');
    $c=$GLOBALS['wc_refund_calls'][0]; check($c['refund_payment']===true && $c['restock_items']===false,'gateway flags');
    check($c['line_items']===[1=>['qty'=>1.0,'refund_total'=>90.0,'refund_tax'=>[1=>10.0]]],'exact item allocation');
});
run_test('captured date is refundable even when current order status is cancelled', function() {
    reset_gateway(); $o=register_order(new WC_Order(171,40,[1=>make_item(7,null,40,0)],0,[],false));
    $o->datePaid=(object)['date'=>'2026-01-02']; $o->status='cancelled';
    $r=roxy_eb_refund_booking_payment($o,7);
    check(!is_wp_error($r) && $r['refunded'],'captured cancelled order should still be refunded');
    check(count($GLOBALS['wc_refund_calls'])===1,'captured payment must reach gateway');
});
run_test('gateway error and exception return errors and mark ambiguous outcome', function() {
    foreach (['error','throw'] as $behavior) {
        reset_gateway(); $GLOBALS['refund_behavior']=$behavior; $o=register_order(new WC_Order(18,100,[1=>make_item(7,null,100,0)]));
        check(is_wp_error(roxy_eb_refund_booking_payment($o,7)),'gateway ' . $behavior . ' must be an error');
    }
});
run_test('claim persistence failure or lock loss prevents gateway call', function() {
    global $wpdb;
    reset_gateway(); $o=register_order(new WC_Order(20,25,[1=>make_item(7,null,25,0)])); $o->saveFailure=true;
    check(is_wp_error(roxy_eb_refund_booking_payment($o,7)),'failed durable save must error');
    check(count($GLOBALS['wc_refund_calls'])===0,'must not call gateway without durable claim');
    reset_gateway(); $o=register_order(new WC_Order(21,25,[1=>make_item(7,null,25,0)])); $wpdb->loseLock=true;
    check(is_wp_error(roxy_eb_refund_booking_payment($o,7)),'lost lock must error');
    check(count($GLOBALS['wc_refund_calls'])===0,'must not call gateway after lock loss'); $wpdb->loseLock=false;
    reset_gateway(); $o=register_order(new WC_Order(22,25,[1=>make_item(7,null,25,0)])); $o->readbackFailure=true;
    check(is_wp_error(roxy_eb_refund_booking_payment($o,7)),'claim readback failure must error');
    check(count($GLOBALS['wc_refund_calls'])===0,'must not call gateway without verified claim');
});
run_test('named lock contention and connection replacement prevent gateway call', function() {
    global $wpdb;
    reset_gateway(); $o=register_order(new WC_Order(23,25,[1=>make_item(7,null,25,0)])); $wpdb->contend=true;
    check(is_wp_error(roxy_eb_refund_booking_payment($o,7)),'contended GET_LOCK must return an error');
    check(count($GLOBALS['wc_refund_calls'])===0,'contended lock must not call gateway'); $wpdb->contend=false;
    reset_gateway(); $o=register_order(new WC_Order(24,25,[1=>make_item(7,null,25,0)])); $wpdb->replaceConnectionOnGetLock=true;
    check(is_wp_error(roxy_eb_refund_booking_payment($o,7)),'connection replacement must lose ownership');
    check(count($GLOBALS['wc_refund_calls'])===0,'replacement connection must not call gateway'); $wpdb->replaceConnectionOnGetLock=false;
    $wpdb->locks=[];
});
run_test('successful gateway with completion marker write failures blocks retry', function() {
    foreach (['save','readback'] as $failure) {
        reset_gateway(); $id=$failure==='save' ? 25 : 26; $o=register_order(new WC_Order($id,25,[1=>make_item(7,null,25,0)]));
        if ($failure==='save') $o->failSaveOnCall=2; else $o->failReadbackOnCall=2;
        check(is_wp_error(roxy_eb_refund_booking_payment($o,7)),'completion '.$failure.' failure must return error');
        check(count($GLOBALS['wc_refund_calls'])===1,'gateway should have succeeded once before completion '.$failure.' failure');
        $retry=roxy_eb_refund_booking_payment($o,7);
        check(is_wp_error($retry) || (is_array($retry) && empty($retry['refunded'])),'completion retry must block or safely no-op');
        check(count($GLOBALS['wc_refund_calls'])===1,'completion failure retry must not call gateway again');
    }
});
run_test('refund without captured payment confirmation is uncertain and cannot retry', function() {
    reset_gateway(); $GLOBALS['refund_behavior']='unknown'; $o=register_order(new WC_Order(27,25,[1=>make_item(7,null,25,0)]));
    check(is_wp_error(roxy_eb_refund_booking_payment($o,7)),'unconfirmed gateway refund must return error');
    check(count($GLOBALS['wc_refund_calls'])===1,'first uncertain request should reach gateway');
    check(is_wp_error(roxy_eb_refund_booking_payment($o,7)),'uncertain outcome marker must block retry');
    check(count($GLOBALS['wc_refund_calls'])===1,'uncertain outcome must never resubmit automatically');
});
run_test('duplicate and retry path does not blindly submit twice', function() {
    global $wpdb;
    reset_gateway(); $o=register_order(new WC_Order(19,100,[1=>make_item(7,null,100,0)]));
    $first=roxy_eb_refund_booking_payment($o,7); check(!is_wp_error($first),'first call');
    $second=roxy_eb_refund_booking_payment($o,7); check(is_wp_error($second) || (is_array($second) && empty($second['refunded'])),'duplicate must be blocked or resolve to a no-op after refund refresh');
    check(count($GLOBALS['wc_refund_calls'])===1,'only one gateway call allowed');
    check(count(array_filter($wpdb->queries,static function($q){return stripos($q,'GET_LOCK')!==false;}))>0,'connection-owned named lock required');
});

// Exercise the actual cancellation caller without delivering mail or scheduling work.
function wp_timezone() { return new DateTimeZone('America/Los_Angeles'); }
function roxy_eb_get_settings() { return ['cancel_free_days'=>7]; }
function roxy_eb_mysql_to_dt($value) { return new DateTimeImmutable($value, wp_timezone()); }
function roxy_eb_booking_adjustment_order_ids($booking) { return $booking['woo_adjustment_order_ids'] ?? []; }
function roxy_eb_repo_get_booking_by_order($order_id) { foreach ($GLOBALS['bookings']??[] as $booking) if ((int)($booking['woo_order_id']??0)===(int)$order_id) return $booking; return null; }
function roxy_eb_repo_update_booking($id, $data) {
    $expected=$data['_roxy_expected_revision']??null; unset($data['_roxy_expected_revision']);
    if ($expected!==null && !hash_equals(roxy_eb_booking_revision($GLOBALS['bookings'][$id]),(string)$expected)) return new WP_Error('booking_stale','fixture revision changed');
    if (!empty($GLOBALS['booking_save_error'])) return new WP_Error('save_failed','fixture write failure');
    $GLOBALS['bookings'][$id]=array_merge($GLOBALS['bookings'][$id],$data); return true;
}
function roxy_eb_email_internal_booking_failed($order, $message) { $GLOBALS['failure_notices'][]=$message; }
function roxy_eb_clear_pizza_reminders($id) { $GLOBALS['cleared_reminders'][]=$id; }
function roxy_eb_sling_enqueue_cancel($id) { $GLOBALS['queued_cancellations'][]=$id; }
require dirname($helper) . '/my-account.php';
require_once dirname($helper) . '/woo.php';
function make_booking($id, $order_id, array $adjustments = []) {
    $GLOBALS['bookings'][$id]=['id'=>$id,'status'=>'confirmed','payment_method'=>'card','invoice_status'=>'not_needed','woo_order_id'=>$order_id,'woo_adjustment_order_ids'=>$adjustments,'fixture_revision'=>'r1','doors_open_at'=>(new DateTimeImmutable('+30 days',wp_timezone()))->format('Y-m-d H:i:s')];
    $GLOBALS['booking_save_error']=false; $GLOBALS['cleared_reminders']=[]; $GLOBALS['queued_cancellations']=[];
}
run_test('actual cancellation caller preserves unrelated items and refunds linked adjustment only', function() {
    reset_gateway(); make_booking(80,180,[181]);
    register_order(new WC_Order(180,70,[1=>make_item(80,null,50),2=>make_item(null,null,20)]));
    register_order(new WC_Order(181,15,[3=>make_adjustment_item(80,15)]));
    check(roxy_eb_cancel_booking(80,'admin')===true,'cancellation succeeds');
    check($GLOBALS['bookings'][80]['status']==='cancelled','booking cancelled after successful refunds');
    check(count($GLOBALS['wc_refund_calls'])===2,'primary and adjustment refunded separately');
    check(array_keys($GLOBALS['wc_refund_calls'][0]['line_items'])===[1],'unrelated cart item excluded by actual caller');
    check($GLOBALS['cleared_reminders']===[80] && $GLOBALS['queued_cancellations']===[80],'dependent work follows successful cancellation');
});
run_test('actual cancellation caller refuses missing orders and failed gateway without releasing booking', function() {
    reset_gateway(); make_booking(81,99999);
    check(is_wp_error(roxy_eb_cancel_booking(81,'admin')),'missing linked order fails closed');
    check($GLOBALS['bookings'][81]['status']==='confirmed' && !$GLOBALS['cleared_reminders'],'missing order cannot cancel booking');
    reset_gateway(); make_booking(82,182); register_order(new WC_Order(182,50,[1=>make_item(82,null,50)])); $GLOBALS['refund_behavior']='error';
    check(is_wp_error(roxy_eb_cancel_booking(82,'admin')),'gateway refusal propagated');
    check($GLOBALS['bookings'][82]['status']==='confirmed' && !$GLOBALS['queued_cancellations'],'failed refund cannot release reservation');
});
run_test('actual cancellation caller reports failed booking save after successful refund', function() {
    reset_gateway(); make_booking(83,183); register_order(new WC_Order(183,50,[1=>make_item(83,null,50)])); $GLOBALS['booking_save_error']=true;
    $result=roxy_eb_cancel_booking(83,'admin');
    check(is_wp_error($result) && $result->get_error_code()==='cancel_save_failed','failed cancellation persistence must be reported');
    check(count($GLOBALS['wc_refund_calls'])===1 && !$GLOBALS['cleared_reminders'] && !$GLOBALS['queued_cancellations'],'no follow-on cancellation work after failed save');
    $GLOBALS['booking_save_error']=false;
    check(roxy_eb_cancel_booking(83,'admin')===true && count($GLOBALS['wc_refund_calls'])===1,'retry finishes cancellation without second gateway request');
});
run_test('cancellation does not overwrite a booking edit made during provider refund', function() {
    reset_gateway(); make_booking(84,184); register_order(new WC_Order(184,50,[1=>make_item(84,null,50)]));
    $GLOBALS['during_refund']=static function() { $GLOBALS['bookings'][84]['guest_count']=9; $GLOBALS['bookings'][84]['fixture_revision']='r2'; };
    $result=roxy_eb_cancel_booking(84,'admin');
    check(is_wp_error($result) && $GLOBALS['bookings'][84]['status']==='confirmed' && $GLOBALS['bookings'][84]['guest_count']===9,
      'stale cancellation is rejected without overwriting the concurrent booking edit');
    check(count($GLOBALS['wc_refund_calls'])===1 && !$GLOBALS['queued_cancellations'],'stale cancellation performs no duplicate provider call or follow-on work');
});
run_test('Woo refund hook retains reminders when booking cancellation fails', function() {
    reset_gateway(); make_booking(85,99998);
    roxy_eb_maybe_cancel_booking_for_order(99998);
    check($GLOBALS['bookings'][85]['status']==='confirmed' && !$GLOBALS['cleared_reminders'] && !$GLOBALS['queued_cancellations'],
      'failed full-refund cancellation keeps the booking reserved and does not clear its reminders');
});
echo "All booking refund regressions passed.\n";
