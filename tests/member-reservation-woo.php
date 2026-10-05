<?php
// Actual Woo/WCS + staged implementation, private $0 records only. No gateway or mail.
if (!defined('WP_CLI') || !WP_CLI) exit;
$root=$args[0]??dirname(__DIR__);
require __DIR__.'/ticket-fixture-loader.php';
roxy_fixture_members($root);roxy_fixture_tickets($root,'MemberFixtureTickets');
add_filter('pre_wp_mail',static fn()=>true);
remove_action('woocommerce_order_status_changed',[\RoxyST\Tickets::class,'on_order_changed'],30);
remove_action('woocommerce_order_status_changed',[\RoxyST\Sales::class,'on_order_changed'],20);
global $wpdb;
$table=$wpdb->prefix.'roxy_member_scans';
$before=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A)));
$ticket_digest=static function()use($wpdb){return hash('sha256',wp_json_encode([$wpdb->get_results("SELECT * FROM `{$wpdb->posts}` WHERE post_type='roxy_ticket' ORDER BY ID",ARRAY_A),$wpdb->get_results("SELECT m.* FROM `{$wpdb->postmeta}` m JOIN `{$wpdb->posts}` p ON p.ID=m.post_id WHERE p.post_type='roxy_ticket' ORDER BY m.meta_id",ARRAY_A)]));};
$tickets_before=$ticket_digest();
$label='PRIVATE MEMBER RESERVATION FIXTURE 2026-10-05';
$created=[];$ids=[];$user=0;$sub=null;$order=null;$workers=[];
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
try {
  $old_schema=static fn()=>'not-ready';
  add_filter('pre_option_roxy_member_scans_schema_version',$old_schema);
  try{$check(!Fixture_Roxy_Sub_Check::prepare_admission_log(),'admission refuses outdated schema without attempting repair');}
  finally{remove_filter('pre_option_roxy_member_scans_schema_version',$old_schema);}
  $user=wp_insert_user(['user_login'=>'roxy-member-fixture-'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'user_email'=>'fixture-'.bin2hex(random_bytes(6)).'@example.test','display_name'=>$label,'role'=>'customer']);
  if(is_wp_error($user))throw new RuntimeException('Private user failed');
  $show=wp_insert_post(['post_type'=>'roxy_showing','post_status'=>'draft','post_title'=>$label],true);if(is_wp_error($show))throw new RuntimeException('Private showing failed');$created[]=$show;
  $product=new WC_Product_Simple();$product->set_name($label);$product->set_status('draft');$product->set_catalog_visibility('hidden');$product->set_regular_price('0');$pid=$product->save();$created[]=$pid;
  update_post_meta($pid,ROXY_ST_META_SHOWING_ID,$show);update_post_meta($pid,ROXY_ST_META_TICKET_TYPE,'subscriber');
  $sub=wcs_create_subscription(['status'=>'pending','customer_id'=>$user,'customer_note'=>$label,'billing_period'=>'month','billing_interval'=>1]);if(is_wp_error($sub))throw new RuntimeException('Private subscription failed');
  $sub->add_product($product,3);$sub->calculate_totals();$sub->save();
  // Fixture-only activation avoids billing/renewal/status-transition side effects.
  if($wpdb->update($wpdb->posts,['post_status'=>'wc-active'],['ID'=>$sub->get_id()])!==1)throw new RuntimeException('Private activation failed');clean_post_cache($sub->get_id());
  $check(Fixture_Roxy_Sub_Check::get_member_payload($sub->get_id(),false)['status']==='valid','actual WCS fixture is active with three-person entitlement');
  $order=wc_create_order(['status'=>'pending','customer_id'=>$user,'customer_note'=>$label]);if(is_wp_error($order))throw new RuntimeException('Private order failed');$order->set_billing_email(get_userdata($user)->user_email);$order->add_product($product,3);$order->calculate_totals();$order->save();$order->update_status('processing');
  $check(\RoxyST\MemberFixtureTickets::sync_order_tickets($order->get_id()),'actual subscriber reservations issued');$ids=\RoxyST\MemberFixtureTickets::get_order_ticket_ids($order->get_id());$check(count($ids)===3,'three private subscriber tickets exist');
  $method=new ReflectionMethod(\RoxyST\MemberFixtureTickets::class,'member_admission_payload');
  $admit=static fn($qty)=>$method->invoke(null,$sub->get_id(),$show,$qty,'manual_admit');
  $count=static function()use(&$ids){foreach($ids as $id)wp_cache_delete($id,'post_meta');return count(array_filter($ids,static fn($id)=>(int)get_post_meta($id,'_roxy_checked_in',true)===1));};
  $logs=static fn()=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE subscription_id=%d",$sub->get_id()));
  $fail=static fn($sql)=>str_starts_with($sql,"INSERT INTO `$table`")?'INSERT INTO `roxy_missing_member_log_failure` VALUES (1)':$sql;
  add_filter('query',$fail);$errors=$wpdb->suppress_errors(true);
  try{$result=$admit(3);}finally{remove_filter('query',$fail);$wpdb->suppress_errors($errors);}
  $check(!$result['ok']&&$count()===0&&$logs()===0,'late member log failure rolls back every admission');
  $fail=static fn($sql)=>str_starts_with($sql,"INSERT INTO `{$wpdb->postmeta}`")&&str_contains($sql,'SELECT '.$ids[1].',')&&str_contains($sql,'_roxy_checked_in_by')?'INSERT INTO `roxy_missing_member_ticket_failure` VALUES (1)':$sql;
  add_filter('query',$fail);$errors=$wpdb->suppress_errors(true);
  try{$result=$admit(3);}finally{remove_filter('query',$fail);$wpdb->suppress_errors($errors);}
  $check(!$result['ok']&&$count()===0&&$logs()===0,'late ticket failure leaves no ticket or member visit');
  $fail=static fn($sql)=>str_contains($sql,'information_schema.TABLES')&&str_contains($sql,'roxy_member_scans')?"SELECT 'MyISAM'":$sql;
  add_filter('query',$fail);
  try{$result=$admit(3);}finally{remove_filter('query',$fail);}
  $check(!$result['ok']&&$count()===0&&$logs()===0,'nontransactional member storage fails closed and rolls back tickets');
  $result=$admit(1);$check($result['ok']&&$count()===1&&$logs()===1,'fresh retry commits one ticket and one visit together');
  $result=$admit(1);$check(!$result['ok']&&$count()===1&&$logs()===1,'repeated target-one scan cannot consume another reservation');
  $result=$admit(3);$sum=(int)$wpdb->get_var($wpdb->prepare("SELECT SUM(quantity) FROM `$table` WHERE subscription_id=%d",$sub->get_id()));
  $check($result['ok']&&$count()===3&&$sum===3&&$result['payload']['admit_quantity']===2,'raising target logs only the two newly admitted people');
  foreach($ids as $id)\RoxyST\MemberFixtureTickets::undo_check_in_ticket($id);$wpdb->delete($table,['subscription_id'=>$sub->get_id()]);
  for($n=0;$n<2;$n++) {
    $pipes=[];$process=proc_open([PHP_BINARY,'/usr/local/bin/wp','--path='.ABSPATH,'eval-file',__DIR__.'/member-reservation-worker.php',$root,(string)$sub->get_id(),(string)$show],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Private worker failed');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$workers[]=['process'=>$process,'pipes'=>$pipes,'out'=>'','exit'=>null];
  }
  $deadline=microtime(true)+40;
  do {$running=false;foreach($workers as &$worker){$worker['out'].=stream_get_contents($worker['pipes'][1]);stream_get_contents($worker['pipes'][2]);$status=proc_get_status($worker['process']);if($status['running'])$running=true;elseif($worker['exit']===null)$worker['exit']=$status['exitcode'];}unset($worker);if($running&&microtime(true)>$deadline)throw new RuntimeException('Private workers timed out');if($running)usleep(100000);}while($running);
  $wins=0;foreach($workers as $worker){if($worker['exit']!==0||!preg_match('/MEMBER_RESULT=([01])/',$worker['out'],$match))throw new RuntimeException('Private worker result missing');$wins+=(int)$match[1];}
  $check($wins===1&&$count()===1&&$logs()===1,'two independent overlapping member scans commit exactly one admission and visit');
  foreach($ids as $id)\RoxyST\MemberFixtureTickets::undo_check_in_ticket($id);$wpdb->delete($table,['subscription_id'=>$sub->get_id()]);
  $check(\RoxyST\MemberFixtureTickets::check_in_ticket($ids[0],9,'ticket'),'private QR admission saved before member arrival');
  $result=$admit(3);$sum=(int)$wpdb->get_var($wpdb->prepare("SELECT SUM(quantity) FROM `$table` WHERE subscription_id=%d",$sub->get_id()));
  $check($result['ok']&&$count()===3&&$sum===2&&get_post_meta($ids[0],'_roxy_checked_in_source',true)==='ticket','member admission preserves earlier QR record and logs only new people');
  echo 'MEMBER_RESERVATION_WOO_OK'.PHP_EOL;
} finally {
  foreach($workers as $worker){if(proc_get_status($worker['process'])['running'])proc_terminate($worker['process']);fclose($worker['pipes'][1]);fclose($worker['pipes'][2]);proc_close($worker['process']);}
  if($sub instanceof WC_Subscription){$wpdb->delete($table,['subscription_id'=>$sub->get_id()]);$sub->delete(true);}
  foreach($ids as $id)wp_delete_post($id,true);
  if($order instanceof WC_Order)$order->delete(true);
  foreach(array_reverse($created) as $id)wp_delete_post($id,true);
  if(is_int($user)&&$user>0){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user);}
}
$after=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A)));
$check(hash_equals($before,$after),'every original member log row remains unchanged after fixture cleanup');
$check(hash_equals($tickets_before,$ticket_digest()),'every original ticket and its metadata remain unchanged after fixture cleanup');
