<?php
// Explicit opt-in: inspect and cancel only the identified browser-created $0 hold test.
if(!defined('WP_CLI') || !WP_CLI)exit;
$id=(int)($args[0]??0);$order=wc_get_order($id);
if(!$order || (float)$order->get_total()!==0.0 || !str_starts_with($order->get_customer_note(),'ROXY STABILITY CHECKOUT HOLD TEST 2026-10-05') || !in_array('tototest',$order->get_coupon_codes(),true))throw new RuntimeException('Not an authorized zero-dollar hold test');
$items=$order->get_items();if(count($items)!==1)throw new RuntimeException('Expected one ticket line');$item=reset($items);
if((int)$item->get_quantity()!==1 || (float)$item->get_total()!==0.0)throw new RuntimeException('Unexpected test quantity or amount');
$tickets=\RoxyST\Tickets::get_order_ticket_ids($id);
if(count($tickets)!==1 || (int)get_post_meta($tickets[0],\RoxyST\Tickets::META_CHECKED_IN,true)!==0)throw new RuntimeException('Unexpected or admitted test ticket');
add_filter('pre_wp_mail',static fn()=>true);
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
$can=new ReflectionMethod(\RoxyST\Tickets::class,'can_check_in');
try {
 $check((int)get_post_meta($id,'_roxy_seat_hold_managed',true)===1 && (int)get_post_meta($id,'_roxy_seat_hold_until',true)>0,'real classic checkout acquired a durable hold');
 $check(in_array($order->get_status(),['processing','completed'],true) && (int)$order->get_meta('_roxy_seat_review',true)===0,'real zero-dollar confirmation cleared seat review');
 $check($can->invoke(null,$tickets[0]),'confirmed live test ticket is eligible but unadmitted');
 echo 'LIVE_TEST_ORDER='.$id.' TICKET='.$tickets[0].PHP_EOL;
} finally {
 $order->add_order_note('Checkout seat-hold regression verified; $0 test canceled without admission or fulfillment.');
 $order->update_status('cancelled');\RoxyST\Tickets::sync_order_tickets($id);
}
$check(wc_get_order($id)->get_status()==='cancelled' && !$can->invoke(null,$tickets[0]) && (int)get_post_meta($tickets[0],\RoxyST\Tickets::META_CHECKED_IN,true)===0,'live test canceled and ticket ineligible/unadmitted');
