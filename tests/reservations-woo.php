<?php
// Read-only authority exercised with private real Woo orders; no gateway or mail.
if(!defined('WP_CLI') || !WP_CLI)exit;
$root=$args[0]??dirname(__DIR__);
require __DIR__.'/ticket-fixture-loader.php';roxy_fixture_members($root);roxy_fixture_capacity($root);roxy_fixture_issuance($root);
add_filter('pre_wp_mail',static fn()=>true);
remove_action('woocommerce_order_status_changed',[\RoxyST\Tickets::class,'on_order_changed'],30);
remove_action('woocommerce_order_status_changed',[\RoxyST\Sales::class,'on_order_changed'],20);
remove_action('woocommerce_refund_created',[\RoxyST\Tickets::class,'on_refund_created'],30);
remove_action('woocommerce_refund_deleted',[\RoxyST\Tickets::class,'on_refund_deleted'],30);
global $wpdb;
$created=[];$orders=[];$refund=null;$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
\Fixture_Roxy_Sub_Check::maybe_upgrade_schema();
$check(\Fixture_Roxy_Sub_Check::prepare_admission_log(),'private capacity fixture initializes its admission-log dependency');
$label='PRIVATE RESERVATION AUTHORITY FIXTURE 2026-10-05';
try {
  $show=wp_insert_post(['post_type'=>'roxy_showing','post_status'=>'draft','post_title'=>$label],true);if(is_wp_error($show))throw new RuntimeException('Private showing failed');$created[]=$show;update_post_meta($show,'_roxy_capacity',6);
  $p=new WC_Product_Simple();$p->set_name($label);$p->set_status('draft');$p->set_regular_price('0');$p->set_catalog_visibility('hidden');$pid=$p->save();$created[]=$pid;
  update_post_meta($pid,ROXY_ST_META_SHOWING_ID,$show);update_post_meta($pid,ROXY_ST_META_TICKET_TYPE,'adult');update_post_meta($show,'_roxy_pid_adult',$pid);
  $order=wc_create_order(['status'=>'pending','customer_note'=>$label]);if(is_wp_error($order))throw new RuntimeException('Private order failed');$orders[]=$order;$item=$order->add_product($p,2);$order->calculate_totals();$order->save();
  $qty=static fn()=>\RoxyST\FixtureReservations::quantity_for_showing($show);
  $check($qty()===0,'pending unpaid order does not silently change existing reservation policy');
  $order->update_meta_data('_roxy_seat_hold_until',time()+1200);$order->save();
  $check($qty()===2,'pending order with an unexpired hold reserves its seats');
  $check(\RoxyST\FixtureReservations::quantity_for_showing($show,$order->get_id())===0,'current order can be excluded from reservation totals');
  if(class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
    $bad_hold=wc_create_order(['status'=>'pending','customer_note'=>$label]);if(is_wp_error($bad_hold))throw new RuntimeException('Private malformed-hold order failed');$orders[]=$bad_hold;
    if($bad_hold->add_product($p,1)<=0)throw new RuntimeException('Private malformed-hold ticket line failed.');$bad_hold->add_meta_data('_roxy_seat_hold_until',time()+600,false);$bad_hold->add_meta_data('_roxy_seat_hold_until',time()+1200,false);$bad_hold->calculate_totals();$bad_hold->save();
    $rejected=false;try{$qty();}catch(RuntimeException $error){$rejected=true;}
    $check($rejected,'ambiguous HPOS hold metadata fails closed');$bad_hold->update_status('cancelled');
  }
  $order->update_meta_data('_roxy_seat_hold_until',time()-10);$order->save();
  $check($qty()===0,'expired pending hold releases its seats');
  $order->delete_meta_data('_roxy_seat_hold_until');$order->save();
  $order->update_status('checkout-draft');$order->update_meta_data('_roxy_seat_hold_until',time()+1200);$order->save();
  $check($qty()===2,'checkout-draft order with an unexpired hold reserves its seats');
  $order->update_meta_data('_roxy_seat_hold_until',time()-10);$order->save();
  $check($qty()===0,'expired checkout-draft hold releases its seats');
  $order->delete_meta_data('_roxy_seat_hold_until');$order->save();$order->update_status('pending');
  update_post_meta($show,'_roxy_sales_stats',['cache_version'=>2,'sold_qty'=>0]);$order->update_status('on-hold');
  $held_qty=$qty();
  $held_walkups=\Fixture_Roxy_Sub_Check::walkup_quantity_for_showing($show);
  $held_remaining=\RoxyST\FixtureCapacity::remaining_seats_for_showing($show);
  $check($held_qty===2,'held order remains in the reservation reader after status change');
  $check($held_walkups===0,'isolated reservation fixture has no walk-up admissions (walkups='.$held_walkups.')');
  $check($held_remaining===4,'held order blocks seats despite stale zero Sales cache (remaining='.$held_remaining.')');
  $cache=get_post_meta($show,'_roxy_sales_stats',true);$check($cache['sold_qty']===0,'reservation read does not rewrite financial report cache');
  $order->update_status('processing');$check($qty()===2,'processing order remains reserved');
  $quantity_table=$wpdb->prefix.'woocommerce_order_itemmeta';
  $write_quantity=static function(string $quantity)use($wpdb,$quantity_table,$item):void{$wpdb->last_error='';$updated=$wpdb->update($quantity_table,['meta_value'=>$quantity],['order_item_id'=>$item,'meta_key'=>'_qty']);if($updated!==1||$wpdb->last_error!=='')throw new RuntimeException('Private reservation quantity fixture could not be updated.');};
  foreach(['bad','1.5','-1'] as $invalid_quantity){
    $write_quantity($invalid_quantity);$rejected=false;
    try{$qty();}catch(RuntimeException $error){$rejected=true;}
    $check($rejected,'malformed or fractional order-item quantity is rejected: '.$invalid_quantity);
    $check(\RoxyST\FixtureCapacity::remaining_seats_for_showing($show)===0,'invalid order-item quantity fails closed for public availability: '.$invalid_quantity);
  }
  $deleted=$wpdb->delete($quantity_table,['order_item_id'=>$item,'meta_key'=>'_qty']);if($deleted!==1||$wpdb->last_error!=='')throw new RuntimeException('Private reservation quantity metadata could not be removed.');
  $missing_rejected=false;try{$qty();}catch(RuntimeException $error){$missing_rejected=true;}
  $check($missing_rejected,'missing order-item quantity is rejected instead of undercounting seats');
  $check(\RoxyST\FixtureCapacity::remaining_seats_for_showing($show)===0,'missing order-item quantity fails closed for public availability');
  if(!$wpdb->insert($quantity_table,['order_item_id'=>$item,'meta_key'=>'_qty','meta_value'=>'2']))throw new RuntimeException('Private reservation quantity metadata could not be restored.');
  $write_quantity('2.000');$check($qty()===2,'whole-number decimal serialization remains a valid reservation quantity');
  $write_quantity('2');$check($qty()===2,'restored valid order quantity remains reserved');
  $order->update_status('completed');$check($qty()===2,'completed order remains reserved');
  update_post_meta($show,'_roxy_sales_stats',['cache_version'=>2,'sold_qty'=>999]);$order->update_status('cancelled');
  $check($qty()===0&&\RoxyST\FixtureCapacity::remaining_seats_for_showing($show)===6,'cancellation frees seats despite stale high Sales cache');
  $order->update_status('failed');$check($qty()===0,'failed order is not a reservation');
  $order->update_status('processing');
  // No showing marker: the line item mapping is sufficient.
  $check(!get_post_meta($order->get_id(),'_roxy_contains_showing_'.$show,true)&&$qty()===2,'untagged order is counted from product identity');
  update_post_meta($show,'_roxy_pid_discount',$pid);update_post_meta($show,'_roxy_legacy_product_ids',[$pid,$pid]);
  $check($qty()===2,'duplicate current and legacy mappings do not double-count order lines');
  delete_post_meta($pid,ROXY_ST_META_SHOWING_ID);delete_post_meta($show,'_roxy_pid_adult');delete_post_meta($show,'_roxy_pid_discount');
  $check($qty()===2,'serialized historical product mapping preserves reservations');
  update_post_meta($show,'_roxy_legacy_product_ids',"$pid,$pid\n");$check($qty()===2,'comma and newline historical mapping remains supported');
  delete_post_meta($show,'_roxy_legacy_product_ids');update_post_meta($pid,ROXY_ST_META_SHOWING_ID,$show);
  $check($qty()===2,'product identity alone counts a reservation without current showing product IDs');
  $subscriber_user=71234567;
  $subscriber_product=new WC_Product_Simple();$subscriber_product->set_name($label.' subscriber');$subscriber_product->set_status('draft');$subscriber_product->set_catalog_visibility('hidden');$subscriber_product->set_regular_price('0');$subscriber_product_id=$subscriber_product->save();$created[]=$subscriber_product_id;
  update_post_meta($subscriber_product_id,ROXY_ST_META_SHOWING_ID,$show);update_post_meta($subscriber_product_id,ROXY_ST_META_TICKET_TYPE,'subscriber');update_post_meta($show,'_roxy_pid_subscriber',$subscriber_product_id);
  $subscriber_order=wc_create_order(['status'=>'processing','customer_id'=>$subscriber_user,'customer_note'=>$label]);if(is_wp_error($subscriber_order))throw new RuntimeException('Private subscriber reservation order failed');$orders[]=$subscriber_order;
  if($subscriber_order->add_product($subscriber_product,3)<=0)throw new RuntimeException('Private subscriber ticket line failed.');$subscriber_order->calculate_totals();$subscriber_order->save();
  $check($qty()===5,'all customers and ticket types contribute to the general reservation total');
  $check(\RoxyST\FixtureReservations::quantity_for_showing($show,0,$subscriber_user)===3,'subscriber-specific totals count only that customer’s subscriber tickets');
  $other_subscriber=wc_create_order(['status'=>'processing','customer_id'=>$subscriber_user+1,'customer_note'=>$label]);if(is_wp_error($other_subscriber))throw new RuntimeException('Private second-subscriber reservation order failed');$orders[]=$other_subscriber;
  if($other_subscriber->add_product($subscriber_product,2)<=0)throw new RuntimeException('Private second-subscriber ticket line failed.');$other_subscriber->calculate_totals();$other_subscriber->save();
  $check($qty()===7,'a second subscriber customer contributes to the general reservation total');
  $check(\RoxyST\FixtureReservations::quantity_for_showing($show,0,$subscriber_user)===3,'subscriber-specific total excludes another customer’s subscriber tickets');
  for($i=0;$i<99;$i++) {
    $bulk_order=wc_create_order(['status'=>'processing','customer_note'=>$label]);if(is_wp_error($bulk_order))throw new RuntimeException('Private bulk reservation order failed');$orders[]=$bulk_order;
    if($bulk_order->add_product($p,1)<=0)throw new RuntimeException('Private bulk ticket line failed.');$bulk_order->calculate_totals();$bulk_order->save();
  }
  $check($qty()===106,'more than one bounded HPOS candidate page aggregates all matching seat orders');
  $refund=wc_create_refund(['order_id'=>$order->get_id(),'amount'=>0,'reason'=>$label,'refund_payment'=>false,'restock_items'=>false,'line_items'=>[$item=>['qty'=>1,'refund_total'=>0]]]);if(is_wp_error($refund))throw new RuntimeException('Private refund failed');
  $check($qty()===106,'partial refund preserves conservative original-quantity seat policy');
  $operation=new \RoxyST\FixtureIssuance([],'reservation-reader:'.$show);$check($operation->run(static fn($writer)=>$writer->reserved_seats($show))===$qty(),'transactional walk-up authority uses the identical shared reservation reader');
  $fail=static fn($sql)=>str_contains($sql,'invalid_quantity_count')?'SELECT * FROM roxy_missing_reservation_failure':$sql;
  add_filter('query',$fail);$errors=$wpdb->suppress_errors(true);try{$check(\RoxyST\FixtureCapacity::remaining_seats_for_showing($show)===0,'SQL failure denies public availability without a fatal');}finally{remove_filter('query',$fail);$wpdb->suppress_errors($errors);}
  $check($qty()===106,'read failure leaves reservation rows intact and next read succeeds');
  foreach(['SELECT meta_key,meta_value','SELECT post_id FROM'] as $prefix) {
    $mapping_fail=static fn($sql)=>str_starts_with($sql,$prefix)?'SELECT * FROM roxy_missing_mapping_failure':$sql;
    add_filter('query',$mapping_fail);$errors=$wpdb->suppress_errors(true);
    try{$check(\RoxyST\FixtureCapacity::remaining_seats_for_showing($show)===0,'failed '.$prefix.' mapping read cannot grant availability');}
    finally{remove_filter('query',$mapping_fail);$wpdb->suppress_errors($errors);}
  }
  if(class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
    $candidate_query_fail=static fn($sql)=>str_contains($sql,'woocommerce_order_items') && str_contains($sql,"meta_key='_product_id'")?'SELECT * FROM roxy_missing_hpos_candidate_failure':$sql;
    add_filter('query',$candidate_query_fail);$errors=$wpdb->suppress_errors(true);
    try{$rejected=false;try{$qty();}catch(RuntimeException $error){$rejected=true;}}
    finally{remove_filter('query',$candidate_query_fail);$wpdb->suppress_errors($errors);}
    $check($rejected,'HPOS ticket-order candidate query failure is not treated as zero reservations');
    $order_query_fail=static fn($sql)=>str_contains($sql,$wpdb->prefix.'wc_orders')?'SELECT * FROM roxy_missing_hpos_order_query_failure':$sql;
    add_filter('query',$order_query_fail);$errors=$wpdb->suppress_errors(true);
    try{$rejected=false;try{$qty();}catch(RuntimeException $error){$rejected=true;}}
    finally{remove_filter('query',$order_query_fail);$wpdb->suppress_errors($errors);}
    $check($rejected,'HPOS Woo order query failure is not treated as zero reservations');
  }
  echo 'RESERVATIONS_WOO_OK'.PHP_EOL;
} finally {
  if($refund && !is_wp_error($refund))$refund->delete(true);
  foreach($orders as $order)if($order && !is_wp_error($order))foreach(\RoxyST\Tickets::get_order_ticket_ids($order->get_id()) as $id)wp_delete_post($id,true);
  foreach($orders as $order)if($order && !is_wp_error($order))$order->delete(true);
  foreach(array_reverse($created) as $id)wp_delete_post($id,true);
}
