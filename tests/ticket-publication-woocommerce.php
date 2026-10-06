<?php
/** WP-CLI fixture: actual metadata + Woo/Store API gate, no public fixture or order. */
require_once $args[0];
global $wpdb;
$ids=[]; $checks=0; $saved=[WC()->cart, WC()->session, WC()->customer];
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label); echo "PASS: $label\n"; $checks++;};
try {
  foreach([RoxyST\CPT::POST_TYPE,'product','product'] as $type){
    if(!$wpdb->insert($wpdb->posts,['post_type'=>$type,'post_status'=>'draft','post_title'=>'PRIVATE publication fixture','post_date'=>current_time('mysql'),'post_date_gmt'=>current_time('mysql',true)]))throw new RuntimeException('fixture insert failed');
    $ids[]=(int)$wpdb->insert_id;
  }
  [$sid,$pid,$duplicate]=$ids;
  foreach([$sid=>['_roxy_pricing_profile'=>'movie_evening','_roxy_pid_adult'=>$pid],$pid=>[ROXY_ST_META_SHOWING_ID=>$sid,ROXY_ST_META_TICKET_TYPE=>'adult'],$duplicate=>[ROXY_ST_META_SHOWING_ID=>$sid,ROXY_ST_META_TICKET_TYPE=>'adult']] as $id=>$meta){foreach($meta as $key=>$value)if(!add_post_meta($id,$key,$value))throw new RuntimeException('fixture metadata failed');}
  // Physical posts stay draft. Publication state is simulated request-locally only.
  $visible=true;
  $status=static function($value,$post)use($ids,&$visible){return in_array((int)$post->ID,$ids,true)&&$visible?'publish':$value;};
  add_filter('get_post_status',$status,10,2);
  $product=new WC_Product_Simple(); $product->set_id($pid); $product->set_status('publish'); $product->set_price(12); $product->set_virtual(true); $product->set_tax_status('none');
  $check($product->is_purchasable(),'normal fixture baseline purchasable before gate');
  RoxyST\Eligibility::init();
  $check(RoxyST\Eligibility::product_error($pid)===null && $product->is_purchasable(),'actual WP metadata canonical public ticket passes');
  $check(is_wp_error(RoxyST\Eligibility::product_error($duplicate)),'actual metadata duplicate rejected');
  WC()->session=new WC_Session_Handler(); WC()->customer=new WC_Customer(0,false); WC()->cart=new WC_Cart();
  WC()->cart->cart_contents=['fixture'=>['product_id'=>$pid,'quantity'=>1,'variation_id'=>0,'variation'=>[],'data'=>$product,'data_hash'=>wc_get_cart_item_data_hash($product)]];
  $visible=false; wc_clear_notices();
  $check(!$product->is_purchasable(),'Woo purchasability rejects showing withdrawn after cart selection');
  // Isolate unrelated validators in this CLI subprocess. Production hooks remain intact.
  remove_all_filters('woocommerce_add_to_cart_validation'); add_filter('woocommerce_add_to_cart_validation',[RoxyST\Eligibility::class,'validate_add'],5,6);
  $check(!apply_filters('woocommerce_add_to_cart_validation',true,$pid,1,0,[],[]),'direct cart validation rejects withdrawn showing');
  remove_all_actions('woocommerce_check_cart_items'); add_action('woocommerce_check_cart_items',[RoxyST\Eligibility::class,'validate_cart'],9990);
  wc_clear_notices(); do_action('woocommerce_check_cart_items');
  $check(wc_notice_count('error')===1,'classic stale cart returns one eligibility error');
  wc_clear_notices();
  $controller=new Automattic\WooCommerce\StoreApi\Utilities\CartController(); $blocked=false;
  try{$controller->validate_cart();}catch(Automattic\WooCommerce\StoreApi\Exceptions\InvalidCartException $e){$blocked=true;}
  $check($blocked,'installed Store API rejects withdrawn showing before checkout');
  $visible=true; wc_clear_notices(); $controller->validate_cart();
  $check(wc_notice_count('error')===0,'restored canonical public ticket passes installed Store API');
  $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN (%d,%d,%d) AND post_status='draft'",$sid,$pid,$duplicate))===3,'all physical fixtures remained private draft');
  if(isset($args[1])) {
    $source=file_get_contents($args[1]);
    $source=str_replace('class Products {','class PublicationFixtureProducts {',$source);
    eval('?>'.$source);
    $updated=[];
    $record=static function($id)use(&$updated,$ids){if(in_array((int)$id,$ids,true))$updated[]=(int)$id;};
    add_action('post_updated',$record);
    RoxyST\PublicationFixtureProducts::on_status_changed('draft','publish',(object)['ID'=>$sid,'post_type'=>RoxyST\CPT::POST_TYPE]);
    $check(in_array($pid,$updated,true)&&in_array($duplicate,$updated,true),'actual WP updates deactivate canonical and duplicate identities');
    $check((int)get_post_meta($pid,ROXY_ST_META_SHOWING_ID,true)===$sid,'deactivation retains historical identity metadata');
    remove_action('post_updated',$record); $updated=[]; add_action('post_updated',$record);
    RoxyST\PublicationFixtureProducts::on_showing_deleted($sid);
    $check(in_array($pid,$updated,true),'actual deletion callback updates linked product safely');
    remove_action('post_updated',$record);
  }
  echo "Passed $checks installed publication checks. No checkout/payment/public fixture.\n";
} finally {
  if(isset($status))remove_filter('get_post_status',$status,10);
  if(isset($record))remove_action('post_updated',$record);
  foreach($ids as $id){$wpdb->delete($wpdb->postmeta,['post_id'=>$id]);$wpdb->delete($wpdb->posts,['ID'=>$id]);clean_post_cache($id);}
  [WC()->cart, WC()->session, WC()->customer]=$saved;
}
