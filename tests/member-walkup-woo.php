<?php
// Private real Woo/WCS/MySQL walk-up tests; no billing, providers or emails.
if (!defined('WP_CLI') || !WP_CLI) exit;
$root=$args[0]??dirname(__DIR__);
require __DIR__.'/ticket-fixture-loader.php';roxy_fixture_members($root);roxy_fixture_capacity($root);roxy_fixture_tickets($root,'WalkupFixtureTickets');
add_filter('pre_wp_mail',static fn()=>true);
remove_action('woocommerce_order_status_changed',[\RoxyST\Tickets::class,'on_order_changed'],30);
remove_action('woocommerce_order_status_changed',[\RoxyST\Sales::class,'on_order_changed'],20);
global $wpdb;
$table=$wpdb->prefix.'roxy_member_scans';
$digest=static function()use($wpdb,$table){return hash('sha256',wp_json_encode([$wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A),$wpdb->get_results("SELECT * FROM `{$wpdb->posts}` WHERE post_type='roxy_ticket' ORDER BY ID",ARRAY_A),$wpdb->get_results("SELECT m.* FROM `{$wpdb->postmeta}` m JOIN `{$wpdb->posts}` p ON p.ID=m.post_id WHERE p.post_type='roxy_ticket' ORDER BY m.meta_id",ARRAY_A)]));};
$before=$digest();$label='PRIVATE MEMBER WALKUP FIXTURE 2026-10-05';$created=[];$users=[];$subs=[];$orders=[];$workers=[];$ticket_ids=[];
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
try {
  $show=wp_insert_post(['post_type'=>'roxy_showing','post_status'=>'draft','post_title'=>$label],true);if(is_wp_error($show))throw new RuntimeException('Private showing failed');$created[]=$show;update_post_meta($show,'_roxy_capacity',1);
  $product=new WC_Product_Simple();$product->set_name($label);$product->set_status('draft');$product->set_catalog_visibility('hidden');$product->set_regular_price('0');$pid=$product->save();$created[]=$pid;
  update_post_meta($pid,ROXY_ST_META_SHOWING_ID,$show);update_post_meta($pid,ROXY_ST_META_TICKET_TYPE,'subscriber');update_post_meta($show,'_roxy_pid_subscriber',$pid);
  for($n=0;$n<2;$n++) {
    $user=wp_insert_user(['user_login'=>'roxy-walkup-fixture-'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'user_email'=>'fixture-'.bin2hex(random_bytes(6)).'@example.test','display_name'=>$label,'role'=>'customer']);if(is_wp_error($user))throw new RuntimeException('Private user failed');$users[]=$user;
    $sub=wcs_create_subscription(['status'=>'pending','customer_id'=>$user,'customer_note'=>$label,'billing_period'=>'month','billing_interval'=>1]);if(is_wp_error($sub))throw new RuntimeException('Private subscription failed');$subs[]=$sub;$sub->add_product($product,3);$sub->calculate_totals();$sub->save();
    if($wpdb->update($wpdb->posts,['post_status'=>'wc-active'],['ID'=>$sub->get_id()])!==1)throw new RuntimeException('Private activation failed');clean_post_cache($sub->get_id());
  }
  $method=new ReflectionMethod(\RoxyST\WalkupFixtureTickets::class,'member_admission_payload');
  $admit=static fn($qty,$n=0)=>$method->invoke(null,$subs[$n]->get_id(),$show,$qty,'manual_admit');
  $total=static fn()=>Fixture_Roxy_Sub_Check::walkup_quantity_for_showing($show);
  $clear=static function()use($wpdb,$table,$show){$wpdb->delete($table,['showing_id'=>$show]);delete_transient('roxy_st_door_stats_'.$show);};
  // Cache a deliberately stale zero-sales value; fresh walk-up checks must ignore it.
  update_post_meta($show,'_roxy_sales_stats',['cache_version'=>2,'sold_qty'=>0]);
  $order=wc_create_order(['status'=>'pending','customer_note'=>$label]);if(is_wp_error($order))throw new RuntimeException('Private held order failed');$orders[]=$order;$order->add_product($product,1);$order->calculate_totals();$order->save();$order->update_status('on-hold');
  $result=$admit(1);$check(!$result['ok']&&$total()===0,'held online reservation blocks last-seat walk-up despite stale sales cache');
  $order->update_status('cancelled');$result=$admit(3);$check(!$result['ok']&&$total()===0,'oversized group is rejected rather than partially admitted');
  $fail=static fn($sql)=>str_starts_with($sql,"INSERT INTO `$table`")?'INSERT INTO `roxy_missing_walkup_log_failure` VALUES (1)':$sql;
  add_filter('query',$fail);$errors=$wpdb->suppress_errors(true);try{$result=$admit(1);}finally{remove_filter('query',$fail);$wpdb->suppress_errors($errors);}
  $check(!$result['ok']&&$total()===0,'failed transactional walk-up log reports no admission');
  $result=$admit(1);$check($result['ok']&&$total()===1&&$result['payload']['admit_quantity']===1,'fresh staff retry commits one walk-up');
  $result=$admit(1);$check(!$result['ok']&&$total()===1,'repeat target-one walk-up cannot consume another entitlement');
  $result=$admit(1,1);$check(!$result['ok']&&$total()===1,'different subscriber cannot take an occupied last seat');
  $check(\RoxyST\FixtureCapacity::remaining_seats_for_showing($show)===0,'public seat availability includes walk-up without double subtraction');
  $check(\RoxyST\FixtureCapacity::subscriber_limit_remaining_for_showing($show,$users[0],false)===2,'online entitlement deducts the admitted member walk-up');
  $clear();update_post_meta($show,'_roxy_capacity',3);$result=$admit(1);$result=$admit(3);
  $check($result['ok']&&$total()===3&&$result['payload']['admit_quantity']===2,'raising arrived target logs only newly arrived people');
  $clear();update_post_meta($show,'_roxy_capacity',1);
  for($n=0;$n<2;$n++) {
    $pipes=[];$process=proc_open([PHP_BINARY,'/usr/local/bin/wp','--path='.ABSPATH,'eval-file',__DIR__.'/member-walkup-worker.php',$root,(string)$subs[$n]->get_id(),(string)$show],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Private worker failed');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$workers[]=['process'=>$process,'pipes'=>$pipes,'out'=>'','exit'=>null];
  }
  $deadline=microtime(true)+40;
  do{$running=false;foreach($workers as &$worker){$worker['out'].=stream_get_contents($worker['pipes'][1]);stream_get_contents($worker['pipes'][2]);$status=proc_get_status($worker['process']);if($status['running'])$running=true;elseif($worker['exit']===null)$worker['exit']=$status['exitcode'];}unset($worker);if($running&&microtime(true)>$deadline)throw new RuntimeException('Private workers timed out');if($running)usleep(100000);}while($running);
  $wins=0;foreach($workers as $worker){if($worker['exit']!==0||!preg_match('/WALKUP_RESULT=([01])/',$worker['out'],$match))throw new RuntimeException('Private worker result missing');$wins+=(int)$match[1];}
  $check($wins===1&&$total()===1,'two independent different-member scans cannot both take the last walk-up seat');
  $clear();delete_post_meta($show,'_roxy_capacity');$result=$admit(3);$check($result['ok']&&$total()===3,'blank capacity retains unlimited-show policy but enforces member entitlement');
  $clear();update_post_meta($show,'_roxy_capacity',6);$result=$admit(1);
  $order->set_customer_id($users[0]);$order->set_billing_email(get_userdata($users[0])->user_email);
  foreach($order->get_items() as $item){$item->set_quantity(3);$item->save();}
  $order->save();$order->update_status('processing');
  $check(wc_get_order($order->get_id())->get_status()==='on-hold'&&(int)get_post_meta($order->get_id(),'_roxy_seat_review',true)===1,'confirmation rejects three more subscriber seats after one of three already arrived');
  // The reservation is for the two remaining people, not all three including the walk-up.
  foreach($order->get_items() as $item){$item->set_quantity(2);$item->save();}
  $order->save();$order->update_status('processing');
  $check(\RoxyST\WalkupFixtureTickets::sync_order_tickets($order->get_id()),'later private subscriber reservation issued after initial walk-up');
  $ticket_ids=\RoxyST\WalkupFixtureTickets::get_order_ticket_ids($order->get_id());$result=$admit(3);
  $checked=count(array_filter($ticket_ids,static fn($id)=>(int)get_post_meta($id,'_roxy_checked_in',true)===1));
  $all_member=(int)$wpdb->get_var($wpdb->prepare("SELECT SUM(quantity) FROM `$table` WHERE subscription_id=%d AND showing_id=%d",$subs[0]->get_id(),$show));
  $check($result['ok']&&$checked===2&&$all_member===3,'later reserved admission counts prior walk-up and logs only two new arrivals');
  $result=$admit(3);$all_member=(int)$wpdb->get_var($wpdb->prepare("SELECT SUM(quantity) FROM `$table` WHERE subscription_id=%d AND showing_id=%d",$subs[0]->get_id(),$show));
  $check(!$result['ok']&&$all_member===3,'hybrid walk-up plus reserved retry cannot consume remaining ticket again');
  echo 'MEMBER_WALKUP_WOO_OK'.PHP_EOL;
} finally {
  foreach($workers as $worker){if(proc_get_status($worker['process'])['running'])proc_terminate($worker['process']);fclose($worker['pipes'][1]);fclose($worker['pipes'][2]);proc_close($worker['process']);}
  foreach($subs as $sub){$wpdb->delete($table,['subscription_id'=>$sub->get_id()]);$sub->delete(true);}
  foreach($ticket_ids as $id)wp_delete_post($id,true);
  foreach($orders as $order)$order->delete(true);
  foreach(array_reverse($created) as $id)wp_delete_post($id,true);
  require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $user)wp_delete_user($user);
}
$check(hash_equals($before,$digest()),'all original member logs and tickets unchanged after private cleanup');
