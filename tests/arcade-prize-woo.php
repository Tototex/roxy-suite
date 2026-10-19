<?php
// Real installed Woo/WCS with a private customer/product and fixture-only claims.
if(!defined('WP_CLI')||!WP_CLI)exit;
$root=$args[0]??dirname(__DIR__);global $wpdb;
add_filter('pre_wp_mail',static fn()=>true);
$token=bin2hex(random_bytes(6));$prefix='roxy_arcade_private_'.$token.'_';
$label='PRIVATE ARCADE PRIZE FIXTURE 2026-10-05';
$code=file_get_contents($root.'/includes/modules/arcade/roxy-arcade.php');
$code=preg_replace('/^<\?php\s*/','',$code,1);
$code=preg_replace("/define\('ROXY_ARCADE_VERSION', '[^']+'\);/",'', $code);
$code=str_replace(['class Roxy_Arcade {','Roxy_Arcade::init();',"'roxy_arcade_award_claim_'"],['class ArcadePrizeFixture {','',"'".$prefix."'"],$code);
eval($code);
$once=new ReflectionMethod('ArcadePrizeFixture','award_once');
$user=0;$product=null;$subscriptions=[];$keys=[];$id_filter=null;$availability_filter=null;$connections=[];$real_lock_a=null;$real_lock_b=null;
$digest=static function()use($wpdb){
    $queries=["SELECT * FROM {$wpdb->posts} WHERE post_type='shop_subscription' ORDER BY ID",
      "SELECT m.* FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='shop_subscription' ORDER BY m.meta_id",
      "SELECT i.* FROM {$wpdb->prefix}woocommerce_order_items i JOIN {$wpdb->posts} p ON p.ID=i.order_id WHERE p.post_type='shop_subscription' ORDER BY i.order_item_id",
      "SELECT m.* FROM {$wpdb->prefix}woocommerce_order_itemmeta m JOIN {$wpdb->prefix}woocommerce_order_items i ON i.order_item_id=m.order_item_id JOIN {$wpdb->posts} p ON p.ID=i.order_id WHERE p.post_type='shop_subscription' ORDER BY m.meta_id"];
    $data=[];foreach($queries as $query){$data[]=$wpdb->get_results($query,ARRAY_A);if($wpdb->last_error)throw new RuntimeException('Subscription evidence read failed');}
    return hash('sha256',wp_json_encode($data));
};
$baseline=$digest();
$settings_keys=['roxy_arcade_rewards_enabled','roxy_arcade_auto_fulfill_rewards','roxy_arcade_last_awarded_month','roxy_arcade_last_winner_user_id','roxy_arcade_last_winner_sub_id','roxy_arcade_last_review_candidate'];
$settings_before=array_map('get_option',$settings_keys);
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";};
try {
    $user=wp_insert_user(['user_login'=>'roxy-prize-fixture-'.$token,'user_pass'=>wp_generate_password(32),'user_email'=>'prize-'.$token.'@example.test','display_name'=>$label,'role'=>'customer']);
    if(is_wp_error($user))throw new RuntimeException('Private customer failed');
    $product=new WC_Product_Subscription();$product->set_name($label);$product->set_status('draft');$product->set_catalog_visibility('hidden');$product->set_regular_price('123');
    $product->update_meta_data('_subscription_period','month');$product->update_meta_data('_subscription_period_interval',1);$product->save();
    $id_filter=static fn($id,$sku)=>$sku==='SUB002'?$product->get_id():$id;add_filter('woocommerce_get_product_id_by_sku',$id_filter,10,2);
    // Keep the fixture product unpublished, while simulating a published product's
    // availability for this request and this exact product only.
    $availability_filter=static fn($status,$p)=>$p->get_id()===$product->get_id()?'publish':$status;
    add_filter('woocommerce_product_get_status',$availability_filter,10,2);
    $check(wc_get_product_id_by_sku('SUB002')===$product->get_id()&&WC_Subscriptions_Product::get_period($product)==='month','private SKU mapping and billing metadata verified');
    $key=$prefix.'2700-01';$keys[]=$key;
    if(!defined('SAVEQUERIES'))define('SAVEQUERIES',true);
    if(!SAVEQUERIES)throw new RuntimeException('Private claim capture requires query logging');
    $query_start=count((array)$wpdb->queries);
    $id=$once->invoke(null,'2700-01',$user);
    $queries=array_slice((array)$wpdb->queries,$query_start);
    if($id<=0){
        $failed_claim=json_decode((string)get_option($key,''),true);
        $diagnostic_queries=array_map(static fn($query)=>substr((string)($query[0]??''),0,600),array_slice($queries,-16));
        echo 'ARCADE PRIZE FAILURE DIAGNOSTICS: '.wp_json_encode(['claim'=>$failed_claim,'database_error'=>$wpdb->last_error,'recent_queries'=>$diagnostic_queries])."\n";
    }
    $check($id>0,'installed WCS creates private one-period prize');$subscriptions[]=$id;
    $sub=wcs_get_subscription($id);$claim=json_decode(get_option($key),true);
    $check($claim['state']==='completed'&&(int)$claim['subscription_id']===$id,'durable completed claim identifies actual subscription');
    $check($sub->get_created_via()===$claim['created_via'],'normal award persists the per-claim created_via marker in WCS');
    $check($sub->get_status()==='active'&&$sub->get_billing_period()==='month'&&(int)$sub->get_billing_interval()===1,'actual subscription activated with product billing terms');
    $check((float)$sub->get_total()===0.0&&(float)$sub->get_total_tax()===0.0,'actual subscription total and tax are zero');
    $items=$sub->get_items();$item=reset($items);
    $check(count($items)===1&&(float)$item->get_subtotal()===0.0&&(float)$item->get_total()===0.0,'priced product becomes genuinely free subscription line');
    $check($sub->get_requires_manual_renewal()&&$sub->get_payment_method()===''&&$sub->get_time('next_payment')===0,'actual prize has no automatic payment or renewal');
    $check($sub->get_time('end')===wcs_add_time(1,'month',$sub->get_time('start')),'actual WCS expiry is exactly one billing period');
    $member=Roxy_Sub_Check::get_member_payload($id,false);
    $check($member['status']==='valid'&&$member['membership_qty']===1,'Member Check recognizes the active one-person prize without logging admission');
    $check($once->invoke(null,'2700-01',$user)===0,'repeat actual award cannot create another subscription');
    do_action('woocommerce_scheduled_subscription_expiration',$id);
    $check(wcs_get_subscription($id)->get_status()==='expired','installed expiry handler ends the private prize');
    $check(Roxy_Sub_Check::get_member_payload($id,false)['status']==='invalid','Member Check rejects the expired prize');
    $sql='';foreach($queries as $query)if(str_contains($query[0],'INSERT IGNORE')&&str_contains($query[0],$key)){$sql=$query[0];break;}
    if(!$sql)throw new RuntimeException('Candidate claim statement not captured');
    $race=$prefix.'2700-02';$keys[]=$race;$sql=str_replace($key,$race,$sql);
    foreach([1,2] as $n)$connections[]=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    foreach($connections as $connection)if(!$connection->dbh->query($sql,MYSQLI_ASYNC))throw new RuntimeException('Independent claim submission failed');
    $wins=0;foreach($connections as $connection){$connection->dbh->reap_async_query();$wins+=$connection->dbh->affected_rows;}
    $check($wins===1,'captured claim SQL has one winner across two independent connections');
    $check($once->invoke(null,'2700-02',$user)===0,'crash-left started claim prevents actual prize retry');

    // Exercise the production advisory-lock helper against actual independent
    // MySQL connections, including contention, owner verification, and release.
    $acquire_lock=new ReflectionMethod('ArcadePrizeFixture','acquire_award_lock');
    $release_lock=new ReflectionMethod('ArcadePrizeFixture','release_award_lock');
    $owns_lock=new ReflectionMethod('ArcadePrizeFixture','award_lock_is_owned');
    $lock_month='2700-04';
    $real_lock_a=$acquire_lock->invoke(null,$lock_month,$connections[0]);
    $check(is_array($real_lock_a)&&$owns_lock->invoke(null,$real_lock_a),'production lock helper acquires and verifies ownership on MySQL connection A');
    $check(strlen($real_lock_a['name'])<=64&&strpos($real_lock_a['name'],'roxy_arcade_award_')===0,
      'production MySQL lock name is namespaced and within server limit');
    $real_lock_b=$acquire_lock->invoke(null,$lock_month,$connections[1]);
    $check($real_lock_b===false&&$owns_lock->invoke(null,$real_lock_a),'independent MySQL connection B cannot acquire A’s held monthly lock');
    $release_lock->invoke(null,$real_lock_a);$real_lock_a=null;
    $real_lock_b=$acquire_lock->invoke(null,$lock_month,$connections[1]);
    $check(is_array($real_lock_b)&&$owns_lock->invoke(null,$real_lock_b),'connection B acquires the lock after A releases it');
    $wrong_connection_lock=$real_lock_b;$wrong_connection_lock['db']=$connections[0];
    $release_lock->invoke(null,$wrong_connection_lock);
    $check($owns_lock->invoke(null,$real_lock_b),'release through a different connection does not release B’s lock');
    $check($acquire_lock->invoke(null,$lock_month,$connections[1])===false&&$owns_lock->invoke(null,$real_lock_b),
      'same-process reacquisition remains blocked after a misdirected release attempt');
    $release_lock->invoke(null,$real_lock_b);$real_lock_b=null;

    // Simulate process death after WCS saved its initial subscription but
    // before the award worker could persist the returned ID. Confirm both
    // created_via persistence and the supported CRUD lookup against real WCS.
    $orphan_month='2700-03';$orphan_key=$prefix.$orphan_month;$keys[]=$orphan_key;
    $marker='roxy_arcade_prize_'.wp_generate_uuid4();
    $orphan_claim=['state'=>'started','user_id'=>(int)$user,'subscription_id'=>0,
      'token'=>substr($marker,18),'created_via'=>$marker,'started_at'=>gmdate('c')];
    if(!add_option($orphan_key,wp_json_encode($orphan_claim),'',false))throw new RuntimeException('Orphan claim fixture could not be saved');
    $orphan=wcs_create_subscription(['customer_id'=>$user,'created_via'=>$marker,'status'=>'pending',
      'billing_period'=>'month','billing_interval'=>1,'start_date'=>gmdate('Y-m-d H:i:s')]);
    if(is_wp_error($orphan)||!$orphan)throw new RuntimeException('Private orphan subscription fixture failed');
    $orphan_id=(int)$orphan->get_id();$subscriptions[]=$orphan_id;
    $check($orphan->get_created_via()===$marker&&$orphan->get_customer_id()===(int)$user,
      'WCS persists created_via and customer on the initial saved subscription');
    $check($once->invoke(null,$orphan_month,$user)===0,'retry attaches a recovered orphan without reporting award completion');
    $recovered=json_decode(get_option($orphan_key),true);
    $check(($recovered['state']??'')==='needs_review'&&(int)($recovered['subscription_id']??0)===$orphan_id,
      'actual CRUD query recovers exactly one orphan into needs_review with its identity');
    $orphan=wcs_get_subscription($orphan_id);
    $check($orphan&&$orphan->get_status()==='pending'&&count($orphan->get_items())===0,
      'recovered actual subscription remains pending and unmodified for manager review');
    $matches=wc_get_orders(['type'=>'shop_subscription','status'=>'all','created_via'=>$marker,
      'customer_id'=>(int)$user,'limit'=>2,'return'=>'objects']);
    $match=is_array($matches)&&count($matches)===1?reset($matches):false;
    $check(is_object($match)&&$match->get_id()===$orphan_id,
      'Woo CRUD query by created_via/customer returns the exact private subscription');
} finally {
    $release_lock_cleanup=new ReflectionMethod('ArcadePrizeFixture','release_award_lock');
    if(is_array($real_lock_a))$release_lock_cleanup->invoke(null,$real_lock_a);
    if(is_array($real_lock_b))$release_lock_cleanup->invoke(null,$real_lock_b);
    if($id_filter)remove_filter('woocommerce_get_product_id_by_sku',$id_filter,10);
    if($availability_filter)remove_filter('woocommerce_product_get_status',$availability_filter,10);
    foreach($connections as $connection)$connection->close();
    // Include subscriptions created before a failed identity/completion write.
    if($user)$subscriptions=array_unique(array_merge($subscriptions,$wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_customer_user' WHERE p.post_type='shop_subscription' AND m.meta_value=%s",(string)$user))));
    foreach($subscriptions as $id){$sub=wcs_get_subscription($id);if($sub)$sub->delete(true);}
    foreach($keys as $key)delete_option($key);
    if($product)$product->delete(true);
    if($user){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user);}
    $check($baseline===$digest(),'original subscription rows/metadata/items unchanged; private customer/product/prizes/claims removed');
    $check($settings_before===array_map('get_option',$settings_keys),'real reward settings and winner/review markers unchanged');
    foreach($subscriptions as $id)$check(!as_has_scheduled_action('woocommerce_scheduled_subscription_expiration',['subscription_id'=>(int)$id]),'deleted private prize has no pending expiry action');
}
