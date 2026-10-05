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
$label='PRIVATE RESERVATION AUTHORITY FIXTURE 2026-10-05';
try {
  $show=wp_insert_post(['post_type'=>'roxy_showing','post_status'=>'draft','post_title'=>$label],true);if(is_wp_error($show))throw new RuntimeException('Private showing failed');$created[]=$show;update_post_meta($show,'_roxy_capacity',6);
  $p=new WC_Product_Simple();$p->set_name($label);$p->set_status('draft');$p->set_regular_price('0');$p->set_catalog_visibility('hidden');$pid=$p->save();$created[]=$pid;
  update_post_meta($pid,ROXY_ST_META_SHOWING_ID,$show);update_post_meta($pid,ROXY_ST_META_TICKET_TYPE,'adult');update_post_meta($show,'_roxy_pid_adult',$pid);
  $order=wc_create_order(['status'=>'pending','customer_note'=>$label]);if(is_wp_error($order))throw new RuntimeException('Private order failed');$orders[]=$order;$item=$order->add_product($p,2);$order->calculate_totals();$order->save();
  $qty=static fn()=>\RoxyST\FixtureReservations::quantity_for_showing($show);
  $check($qty()===0,'pending unpaid order does not silently change existing reservation policy');
  update_post_meta($show,'_roxy_sales_stats',['cache_version'=>2,'sold_qty'=>0]);$order->update_status('on-hold');
  $check($qty()===2&&\RoxyST\FixtureCapacity::remaining_seats_for_showing($show)===4,'held order blocks seats despite stale zero Sales cache');
  $cache=get_post_meta($show,'_roxy_sales_stats',true);$check($cache['sold_qty']===0,'reservation read does not rewrite financial report cache');
  $order->update_status('processing');$check($qty()===2,'processing order remains reserved');
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
  $refund=wc_create_refund(['order_id'=>$order->get_id(),'amount'=>0,'reason'=>$label,'refund_payment'=>false,'restock_items'=>false,'line_items'=>[$item=>['qty'=>1,'refund_total'=>0]]]);if(is_wp_error($refund))throw new RuntimeException('Private refund failed');
  $check($qty()===2,'partial refund preserves conservative original-quantity seat policy');
  $operation=new \RoxyST\FixtureIssuance([],'reservation-reader:'.$show);$check($operation->run(static fn($writer)=>$writer->reserved_seats($show))===$qty(),'transactional walk-up authority uses the identical shared reservation reader');
  $fail=static fn($sql)=>str_contains($sql,'SELECT COALESCE(SUM(CAST(q.meta_value')?'SELECT * FROM roxy_missing_reservation_failure':$sql;
  add_filter('query',$fail);$errors=$wpdb->suppress_errors(true);try{$check(\RoxyST\FixtureCapacity::remaining_seats_for_showing($show)===0,'SQL failure denies public availability without a fatal');}finally{remove_filter('query',$fail);$wpdb->suppress_errors($errors);}
  $check($qty()===2,'read failure leaves reservation rows intact and next read succeeds');
  foreach(['SELECT meta_key,meta_value','SELECT post_id FROM'] as $prefix) {
    $mapping_fail=static fn($sql)=>str_starts_with($sql,$prefix)?'SELECT * FROM roxy_missing_mapping_failure':$sql;
    add_filter('query',$mapping_fail);$errors=$wpdb->suppress_errors(true);
    try{$check(\RoxyST\FixtureCapacity::remaining_seats_for_showing($show)===0,'failed '.$prefix.' mapping read cannot grant availability');}
    finally{remove_filter('query',$mapping_fail);$wpdb->suppress_errors($errors);}
  }
  echo 'RESERVATIONS_WOO_OK'.PHP_EOL;
} finally {
  if($refund && !is_wp_error($refund))$refund->delete(true);
  foreach($orders as $order)if($order && !is_wp_error($order))foreach(\RoxyST\Tickets::get_order_ticket_ids($order->get_id()) as $id)wp_delete_post($id,true);
  foreach($orders as $order)if($order && !is_wp_error($order))$order->delete(true);
  foreach(array_reverse($created) as $id)wp_delete_post($id,true);
}
