<?php
// Actual Woo CRUD + staged ticket implementation; private disposable $0 order, no payment/mail.
if (!defined('WP_CLI') || !WP_CLI) exit;
$root=$args[0]??dirname(__DIR__);
require_once $root.'/includes/modules/show-tickets/includes/class-roxy-st-issuance.php';
$source=file_get_contents($root.'/includes/modules/show-tickets/includes/class-roxy-st-tickets.php');
$source=preg_replace('/^<\?php\s*/','',$source,1);
$source=str_replace('class Tickets {','class IssuanceFixtureTickets {',$source);
eval($source);
add_filter('pre_wp_mail',static fn()=>true);
remove_action('woocommerce_checkout_order_processed',[\RoxyST\Tickets::class,'on_order_changed'],30);
remove_action('woocommerce_order_status_changed',[\RoxyST\Tickets::class,'on_order_changed'],30);
remove_action('woocommerce_refund_created',[\RoxyST\Tickets::class,'on_refund_created'],30);
remove_action('woocommerce_refund_deleted',[\RoxyST\Tickets::class,'on_refund_deleted'],30);
remove_action('woocommerce_order_status_changed',[\RoxyST\Sales::class,'on_order_changed'],20);
$created=[];$order=null;$refund=null;
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
try{
  $show=wp_insert_post(['post_type'=>'roxy_showing','post_status'=>'draft','post_title'=>'PRIVATE ISSUANCE FIXTURE 2026-10-05'],true);if(is_wp_error($show))throw new RuntimeException('Cannot create fixture');$created[]=$show;
  $product=new WC_Product_Simple();$product->set_name('PRIVATE ISSUANCE FIXTURE 2026-10-05');$product->set_status('draft');$product->set_catalog_visibility('hidden');$product->set_regular_price('0');$pid=$product->save();$created[]=$pid;
  update_post_meta($pid,ROXY_ST_META_SHOWING_ID,$show);update_post_meta($pid,ROXY_ST_META_TICKET_TYPE,'general');
  $order=wc_create_order(['status'=>'pending','customer_note'=>'PRIVATE ISSUANCE FIXTURE 2026-10-05 — no payment or fulfillment']);if(is_wp_error($order))throw new RuntimeException('Cannot create private order');
  $item_id=$order->add_product($product,3);$order->calculate_totals();$order->save();
  $check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id()),'actual Woo order issues complete ticket set');
  $ids=\RoxyST\IssuanceFixtureTickets::get_order_ticket_ids($order->get_id());$check(count($ids)===3,'three distinct ticket identities are saved');
  $check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id())&&\RoxyST\IssuanceFixtureTickets::get_order_ticket_ids($order->get_id())===$ids,'repeated issuance reuses identities without duplicate posts');
  wc_delete_order_item_meta($item_id,'_roxy_ticket_ids');
  $check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id())&&\RoxyST\IssuanceFixtureTickets::get_order_ticket_ids($order->get_id())===$ids,'lost order-item links recover canonical sequences without duplicate tickets');
  $changed=new WC_Order_Item_Product($item_id);$changed->set_quantity(2);$changed->save();
  $check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id())&&get_post_meta($ids[2],'_roxy_ticket_state',true)==='pending','quantity decrease invalidates excess canonical ticket');
  $changed->set_quantity(3);$changed->save();
  $check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id())&&\RoxyST\IssuanceFixtureTickets::get_order_ticket_ids($order->get_id())===$ids,'quantity increase reuses original ticket sequence');
  $order=wc_get_order($order->get_id());$order->update_status('processing');
  $check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id()),'paid status reconciles through guarded transaction');
  foreach($ids as $id)$check(get_post_meta($id,'_roxy_ticket_state',true)==='valid'&&(int)get_post_meta($id,'_roxy_ticket_order_item_id',true)===$item_id,'ticket payment state and item identity match');
  $refund=wc_create_refund(['order_id'=>$order->get_id(),'amount'=>0,'reason'=>'PRIVATE ISSUANCE FIXTURE','refund_payment'=>false,'restock_items'=>false,'line_items'=>[$item_id=>['qty'=>1,'refund_total'=>0,'refund_tax'=>[]]]]);if(is_wp_error($refund))throw new RuntimeException('Fixture refund failed');
  $check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id()),'actual zero-dollar line refund reconciles');
  $check(count(array_filter($ids,static fn($id)=>get_post_meta($id,'_roxy_ticket_state',true)==='refunded'))===1,'one refund invalidates exactly one existing ticket');
  $order->update_status('cancelled');$check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id()),'cancellation reconciles with original identities');
  $check(count(array_filter($ids,static fn($id)=>get_post_meta($id,'_roxy_ticket_state',true)==='valid'))===0,'cancelled fixture has no valid tickets');
  update_post_meta($ids[2],'_roxy_ticket_order_id',999999999);
  $check(!\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id()),'cross-order reference is rejected rather than reassigning a ticket');
  $check((int)get_post_meta($ids[2],'_roxy_ticket_order_id',true)===999999999,'rejected reference retains original identity');
  update_post_meta($ids[2],'_roxy_ticket_order_id',$order->get_id());
  $check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id()),'repaired private fixture reconciles on retry');
  echo 'TICKET_ISSUANCE_WOO_OK'.PHP_EOL;
}finally{
  if($refund instanceof WC_Order_Refund)$refund->delete(true);
  if($order instanceof WC_Order){foreach(range(1,3) as $attempt)wp_clear_scheduled_hook('roxy_st_retry_order_tickets',[$order->get_id(),$attempt]);foreach(array_unique(array_merge($ids??[],\RoxyST\IssuanceFixtureTickets::get_order_ticket_ids($order->get_id()))) as $id)wp_delete_post($id,true);$order->delete(true);}
  foreach(array_reverse($created) as $id)wp_delete_post($id,true);
}
