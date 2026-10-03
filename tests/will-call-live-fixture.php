<?php
// Only operates on an explicitly identified $0, tototest-marked checkout.
if (!defined('WP_CLI') || !WP_CLI) exit;
$id=(int)($args[0]??0);$mode=$args[1]??'inspect';
$order=wc_get_order($id);
if (!$order || $id!==30629 || (float)$order->get_total()!==0.0 || strpos($order->get_customer_note(),'ROXY STABILITY TEST')!==0 || !in_array('tototest',$order->get_coupon_codes(),true)) throw new RuntimeException('Not the authorized zero-dollar test fixture.');
$tickets=\RoxyST\Tickets::get_order_ticket_ids($id);
if (count($tickets)!==3) throw new RuntimeException('Unexpected test ticket count.');
$item_ids=array_keys($order->get_items());$item_id=(int)$item_ids[0];
if ($mode==='refund') {
    if ($order->get_meta('_roxy_stability_fixture_refund')) throw new RuntimeException('Fixture refund already exists.');
    $refund=wc_create_refund(['order_id'=>$id,'amount'=>0,'reason'=>'ROXY STABILITY TEST partial quantity','refund_payment'=>false,'restock_items'=>false,'line_items'=>[$item_id=>['qty'=>1,'refund_total'=>0,'refund_tax'=>[]]]]);
    if(is_wp_error($refund))throw new RuntimeException($refund->get_error_message());
    $order->update_meta_data('_roxy_stability_fixture_refund',(int)$refund->get_id());$order->save();
} elseif ($mode==='delete-refund') {
    $refund_id=(int)$order->get_meta('_roxy_stability_fixture_refund');$refund=wc_get_order($refund_id);
    if(!$refund || $refund->get_parent_id()!==$id || (float)$refund->get_amount()!==0.0)throw new RuntimeException('Not the fixture-only refund.');
    $refund->delete(true);
    // Same hook and arguments used by installed WooCommerce delete_refund AJAX.
    do_action('woocommerce_refund_deleted',$refund_id,$id);
    $order->delete_meta_data('_roxy_stability_fixture_refund');$order->save();
} elseif ($mode==='cancel') {
    foreach($tickets as $ticket)\RoxyST\Tickets::undo_check_in_ticket($ticket);
    $order->update_status('cancelled','ROXY STABILITY TEST completed; no funds moved. All test admissions cleared.');
    \RoxyST\Tickets::sync_order_tickets($id);
} elseif($mode!=='inspect')throw new RuntimeException('Unknown fixture action.');
$order=wc_get_order($id);
echo wp_json_encode(['order'=>$id,'status'=>$order->get_status(),'total'=>$order->get_total(),'refunded_qty'=>abs((float)$order->get_qty_refunded_for_item($item_id))]) . "\n";
foreach($tickets as $ticket) echo wp_json_encode(['ticket'=>$ticket,'state'=>get_post_meta($ticket,\RoxyST\Tickets::META_STATE,true),'eligible'=>\RoxyST\Tickets::ticket_is_eligible($ticket),'checked'=>(int)get_post_meta($ticket,\RoxyST\Tickets::META_CHECKED_IN,true),'source'=>get_post_meta($ticket,'_roxy_checked_in_source',true)]) . "\n";
