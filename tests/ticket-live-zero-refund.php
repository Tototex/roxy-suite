<?php
// Explicit opt-in live regression on an identified, zero-dollar test order only.
// No gateway refund, real order, or stock restoration is permitted by this script.
if (!defined('WP_CLI') || !WP_CLI) exit('WP-CLI only');
$test_id = (int) ($args[0] ?? 0);
$order = wc_get_order($test_id);
if (!$order || (float)$order->get_total() !== 0.0 || (!str_starts_with($order->get_customer_note(),'ROXY STABILITY REFUND TEST 2026-10-02') && !str_starts_with($order->get_customer_note(),'ROXY STABILITY ISSUANCE TEST 2026-10-05')) || !in_array('tototest',$order->get_coupon_codes(),true)) throw new RuntimeException('Not an authorized zero-dollar test order');
$items = $order->get_items();
if (count($items) !== 1) throw new RuntimeException('Expected exactly one ticket line');
$item = reset($items); $item_id = (int)$item->get_id();
if ((int)$item->get_quantity() !== 3 || (float)$item->get_total() !== 0.0) throw new RuntimeException('Expected three discounted tickets');
// Keep fixture-only refund/status messages from being emailed during these transitions.
add_filter('pre_wp_mail',static fn()=>true);
$can = new ReflectionMethod(\RoxyST\Tickets::class,'can_check_in'); $can->setAccessible(true);
$check = static function($ok,$label) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; };
$ticket_ids = \RoxyST\Tickets::get_order_ticket_ids($test_id);
$check(count($ticket_ids)===3,'three tickets issued through real checkout');
$count_eligible = static function() use ($can,$ticket_ids) { return count(array_filter($ticket_ids,static fn($id)=>$can->invoke(null,(int)$id))); };
try {
    $order->update_status('on-hold');
    $check($count_eligible()===0,'real on-hold order cannot admit tickets');
    $order->update_status('processing');
    $check($count_eligible()===3,'confirmed $0 order restores three eligible tickets');
    foreach ([2,1] as $expected) {
        $refund = wc_create_refund(['order_id'=>$test_id,'amount'=>0,'reason'=>'Zero-dollar stability regression','refund_payment'=>false,'restock_items'=>false,'line_items'=>[$item_id=>['qty'=>1,'refund_total'=>0,'refund_tax'=>[]]]]);
        if (is_wp_error($refund)) throw new RuntimeException($refund->get_error_message());
        \RoxyST\Tickets::sync_order_tickets($test_id);
        $check($count_eligible()===$expected,'cumulative actual Woo refund leaves ' . $expected . ' eligible ticket(s)');
        \RoxyST\Tickets::sync_order_tickets($test_id);
        $check($count_eligible()===$expected,'repeated sync cannot reactivate refunded tickets');
    }
    $refunded_id = null;
    foreach ($ticket_ids as $id) if ((int)get_post_meta($id,\RoxyST\Tickets::META_REFUNDED,true)===1) { $refunded_id=$id; break; }
    $check(!\RoxyST\Tickets::check_in_ticket($refunded_id),'actual refunded ticket rejected at admission');
    \RoxyST\Tickets::undo_check_in_ticket($refunded_id);
    $check(!$can->invoke(null,$refunded_id),'actual refund cannot be revived through undo');
} finally {
    $order = wc_get_order($test_id);
    $order->add_order_note('Zero-dollar refund/status regression completed or stopped. Test reservation canceled; no gateway funds moved.');
    $order->update_status('cancelled');
    \RoxyST\Tickets::sync_order_tickets($test_id);
    echo 'TEST_ORDER_CANCELED=' . $test_id . "\n";
}
