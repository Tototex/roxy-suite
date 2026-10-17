<?php
/** Private HPOS ticket lifecycle contract: synthetic zero-value orders only. */
if (!defined('WP_CLI') || !WP_CLI) exit;
if (!class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') || !Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) throw new RuntimeException('This fixture requires authoritative HPOS.');
if (!defined('WC_VERSION') || WC_VERSION !== '11.2.1') throw new RuntimeException('Review the HPOS schema contract before changing the disposable WooCommerce version.');
if (!class_exists('RoxyST\\Issuance') || !class_exists('RoxyST\\Holds') || !class_exists('RoxyST\\Tickets')) throw new RuntimeException('Show Tickets lifecycle is unavailable in the HPOS fixture.');

global $wpdb;
$meta_table=$wpdb->prefix.'wc_orders_meta';
$columns=$wpdb->get_col("SHOW COLUMNS FROM `$meta_table`",0);
$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$meta_table));
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
if ($wpdb->last_error || !is_array($columns) || array_diff(['id','order_id','meta_key','meta_value'],$columns) || $engine!=='InnoDB') throw new RuntimeException('WooCommerce 11.2.1 HPOS metadata schema differs from the verified transactional contract.');

$token='PRIVATE-ROXY-ST-HPOS-'.bin2hex(random_bytes(6));
$created_posts=[];$created_orders=[];$secondary=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
add_filter('pre_wp_mail',static fn()=>true);
try {
  $show=wp_insert_post(['post_type'=>'roxy_showing','post_status'=>'draft','post_title'=>$token.' SHOW'],true);
  if(is_wp_error($show))throw new RuntimeException('Could not create private HPOS showing.');$created_posts[]=(int)$show;
  $start=wp_date('Y-m-d',time()+2*DAY_IN_SECONDS).' 23:00';
  update_post_meta($show,'_roxy_start',$start);update_post_meta($show,'_roxy_duration_minutes',90);update_post_meta($show,'_roxy_capacity',1);
  $product=new WC_Product_Simple();$product->set_name($token.' TICKET');$product->set_status('draft');$product->set_catalog_visibility('hidden');$product->set_regular_price('0');$product_id=$product->save();
  if($product_id<=0)throw new RuntimeException('Could not create private HPOS ticket product.');$created_posts[]=$product_id;
  update_post_meta($product_id,ROXY_ST_META_SHOWING_ID,(int)$show);update_post_meta($product_id,ROXY_ST_META_TICKET_TYPE,'general');update_post_meta($show,'_roxy_pid_adult',$product_id);

  $make_order=static function(string $note)use($product,&$created_orders){$order=wc_create_order(['status'=>'pending','customer_note'=>$note]);if(is_wp_error($order)||!$order instanceof WC_Order)throw new RuntimeException('Could not create private HPOS order.');$created_orders[]=(int)$order->get_id();if($order->add_product($product,1)<=0)throw new RuntimeException('Could not add the private ticket line.');$order->calculate_totals();$order->save();return wc_get_order($order->get_id());};
  $first=$make_order($token.' HOLD TEST');
  $check($first->get_meta('_roxy_seat_hold_managed',true)==='','synthetic order primes its initially empty HPOS meta cache');
  $cache=wc_get_container()->get('\\Automattic\\WooCommerce\\Caching\\WPCacheEngine');
  $cache->cache_object((string)$first->get_id(),[],0,'orders_meta');
  $cache->delete_cached_object((string)$first->get_id(),'orders_data');
  $check($cache->is_cached((string)$first->get_id(),'orders_meta')&&!$cache->is_cached((string)$first->get_id(),'orders_data'),'HPOS fixture reproduces metadata-only cache without an order-data cache entry');
  $check(\RoxyST\Holds::claim($first),'HPOS pending ticket order claims a transactional seat hold');
  $fresh=wc_get_order($first->get_id());$until=(int)$fresh->get_meta('_roxy_seat_hold_until',true);
  $raw_markers=$wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM `$meta_table` WHERE order_id=%d AND meta_key IN (%s,%s) ORDER BY meta_key",(int)$first->get_id(),'_roxy_seat_hold_managed','_roxy_seat_hold_until'),ARRAY_A);
  $check((int)$fresh->get_meta('_roxy_seat_hold_managed',true)===1&&$until>time(),'guarded HPOS hold metadata visible through Woo CRUD after commit (crud_managed='.(string)$fresh->get_meta('_roxy_seat_hold_managed',true).', crud_until='.$until.', raw='.wp_json_encode($raw_markers).', sql_error='.(string)$wpdb->last_error.')');
  $check(\RoxyST\Reservations::quantity_for_showing((int)$show)===1,'unexpired HPOS hold counts as a reserved seat');

  (new \RoxyST\Issuance((int)$first->get_id()))->run(static fn($writer)=>$writer->transactional_order_meta((int)$first->get_id(),'_roxy_seat_hold_until',time()-1));
  $check(\RoxyST\Reservations::quantity_for_showing((int)$show)===0,'expired HPOS hold stops reserving capacity');
  $check(\RoxyST\Holds::claim(wc_get_order($first->get_id())),'expired hold can be safely renewed while the order remains pending');
  $first=wc_get_order($first->get_id());$first->set_status('cancelled');$first->save();
  $first=wc_get_order($first->get_id());
  $check((int)$first->get_meta('_roxy_seat_hold_until',true)===0,'cancelled HPOS order releases its hold through the transactional release path');

  $rollback_order=$make_order($token.' ROLLBACK TEST');
  $before=(int)wc_get_order($rollback_order->get_id())->get_meta('_roxy_seat_hold_managed',true);
  try {
    (new \RoxyST\Issuance((int)$rollback_order->get_id()))->run(static function($writer)use($rollback_order,$token){
      $writer->transactional_order_meta((int)$rollback_order->get_id(),'_roxy_seat_hold_managed',1);
      $writer->create($token.' ROLLED BACK TICKET');
      throw new RuntimeException('fixture rollback');
    });
    throw new RuntimeException('HPOS hold transaction unexpectedly committed after failure.');
  } catch(RuntimeException $error) { if($error->getMessage()!=='fixture rollback')throw $error; }
  $rollback_order=wc_get_order($rollback_order->get_id());
  $check((int)$rollback_order->get_meta('_roxy_seat_hold_managed',true)===$before,'HPOS hold metadata rolls back with a later transaction failure');
  $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_title=%s",$token.' ROLLED BACK TICKET'))===0,'ticket CPT writes roll back with HPOS hold metadata');

  $guard_order=$make_order($token.' GUARD TEST');
  $check(\RoxyST\Holds::claim($guard_order),'HPOS seat hold is established for a second order transaction');
  (new \RoxyST\Issuance((int)$guard_order->get_id()))->run(static function($writer)use($guard_order,$secondary,$meta_table,$check){
    $predicate=(new ReflectionMethod($writer,'predicate'));$predicate->setAccessible(true);$guard=$predicate->invoke($writer);
    $replayed=$secondary->query($secondary->prepare("INSERT INTO `$meta_table` (order_id,meta_key,meta_value) SELECT %d,%s,%s FROM DUAL WHERE $guard",(int)$guard_order->get_id(),'_roxy_seat_hold_until','1'));
    $check($replayed===0,'replayed HPOS hold SQL cannot write from a connection without the active lock');
  });
  $fail_order=$make_order($token.' FAILURE TEST');
  $fail_query=static function($sql)use($meta_table){return str_starts_with($sql,"INSERT INTO `$meta_table`")&&str_contains($sql,'_roxy_seat_hold_until')?'INSERT INTO `roxy_missing_hpos_meta_failure` VALUES (1)':$sql;};
  add_filter('query',$fail_query);$old_suppress=$wpdb->suppress_errors(true);
  try {
    $failed=false;
    try{(new \RoxyST\Issuance((int)$fail_order->get_id()))->run(static function($writer)use($fail_order){$writer->transactional_order_meta((int)$fail_order->get_id(),'_roxy_seat_hold_managed',1);$writer->transactional_order_meta((int)$fail_order->get_id(),'_roxy_seat_hold_until',time()+300);});}
    catch(RuntimeException $error){$failed=$error->getMessage()==='Ticket write failed';}
  } finally {remove_filter('query',$fail_query);$wpdb->suppress_errors($old_suppress);}
  $check($failed,'HPOS metadata SQL failure aborts the transaction');
  $check((int)wc_get_order($fail_order->get_id())->get_meta('_roxy_seat_hold_managed',true)===0,'failed HPOS hold writes leave no partial order marker');

  $review=$make_order($token.' REVIEW TEST');
  $review->set_status('processing');
  \RoxyST\Holds::guard_confirmation($review);
  $review->save();$review=wc_get_order($review->get_id());
  $check($review->get_status()==='on-hold'&&(int)$review->get_meta('_roxy_seat_review',true)===1&&$review->get_meta('_roxy_seat_review_reason',true)==='seat_availability','HPOS payment with unavailable capacity is preserved for manager review');
  ob_start();\RoxyST\Tickets::render_order_tickets((int)$review->get_id());$review_html=(string)ob_get_clean();
  $check(str_contains($review_html,'Your order requires a seat availability review'),'order ticket display reads the manager-review marker through WooCommerce order CRUD');

  $issue_show=wp_insert_post(['post_type'=>'roxy_showing','post_status'=>'draft','post_title'=>$token.' ISSUE SHOW'],true);
  if(is_wp_error($issue_show))throw new RuntimeException('Could not create HPOS issuance showing.');$created_posts[]=(int)$issue_show;
  update_post_meta($issue_show,'_roxy_start',$start);update_post_meta($issue_show,'_roxy_duration_minutes',90);update_post_meta($issue_show,'_roxy_capacity',5);
  $issue_product=new WC_Product_Simple();$issue_product->set_name($token.' ISSUE TICKET');$issue_product->set_status('draft');$issue_product->set_catalog_visibility('hidden');$issue_product->set_regular_price('0');$issue_product_id=$issue_product->save();
  if($issue_product_id<=0)throw new RuntimeException('Could not create HPOS issuance product.');$created_posts[]=$issue_product_id;
  update_post_meta($issue_product_id,ROXY_ST_META_SHOWING_ID,(int)$issue_show);update_post_meta($issue_product_id,ROXY_ST_META_TICKET_TYPE,'general');update_post_meta($issue_show,'_roxy_pid_adult',$issue_product_id);
  $issued=wc_create_order(['status'=>'processing','customer_note'=>$token.' ISSUE TEST']);
  if(is_wp_error($issued)||!$issued instanceof WC_Order)throw new RuntimeException('Could not create private HPOS issuance order.');$created_orders[]=(int)$issued->get_id();
  $issued_item=(int)$issued->add_product($issue_product,2);$issued->calculate_totals();$issued->save();
  $check(\RoxyST\Tickets::sync_order_tickets((int)$issued->get_id()),'HPOS paid ticket order issues through transactional ticket projection');
  $issued=wc_get_order($issued->get_id());$item=$issued->get_item($issued_item);$item->read_meta_data(true);$ticket_ids=array_map('intval',(array)$item->get_meta('_roxy_ticket_ids',true));
  $check(count($ticket_ids)===2&&count(array_unique($ticket_ids))===2,'HPOS issuance links one ticket CPT identity per purchased seat');
  $check(\RoxyST\Tickets::sync_order_tickets((int)$issued->get_id()),'repeated HPOS ticket reconciliation succeeds');
  $issued=wc_get_order($issued->get_id());$item=$issued->get_item($issued_item);$item->read_meta_data(true);
  $again=array_map('intval',(array)$item->get_meta('_roxy_ticket_ids',true));
  $check($again===$ticket_ids,'HPOS ticket reconciliation is idempotent and creates no duplicate ticket identity');
  $issued->update_meta_data('_roxy_seat_review',1);$issued->save();
  foreach($ticket_ids as $ticket_id)$check(!\RoxyST\Tickets::ticket_is_eligible($ticket_id),'HPOS manager-review marker blocks ticket admission through WooCommerce order CRUD');

  echo "Passed private Show Tickets HPOS lifecycle checks. No gateway or external mail transport was used.\n";
} finally {
  $secondary->close();
  foreach(array_reverse($created_orders) as $id){$order=wc_get_order($id);if($order&&str_contains((string)$order->get_customer_note(),$token))$order->delete(true);}
  foreach(array_reverse($created_posts) as $id){$post=get_post($id);if($post&&str_contains((string)$post->post_title,$token))wp_delete_post($id,true);}
}
