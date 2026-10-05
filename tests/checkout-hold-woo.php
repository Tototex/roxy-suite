<?php
// Private actual Woo/WCS/MySQL lifecycle; no billing, gateway or mail.
if(!defined('WP_CLI') || !WP_CLI)exit;
$root=$args[0]??dirname(__DIR__);require __DIR__.'/ticket-fixture-loader.php';roxy_fixture_members($root);roxy_fixture_holds($root);roxy_fixture_tickets($root,'HoldFixtureTickets');
add_filter('pre_wp_mail',static fn()=>true);
remove_action('woocommerce_order_status_changed',[\RoxyST\Sales::class,'on_order_changed'],20);
remove_action('woocommerce_order_status_changed',[\RoxyST\Tickets::class,'on_order_changed'],30);
foreach(['woocommerce_refund_created'=>'on_refund_created','woocommerce_refund_deleted'=>'on_refund_deleted'] as $hook=>$method)remove_action($hook,[\RoxyST\Tickets::class,$method],30);
if(class_exists('RoxyST\\Holds')) {
 foreach(['woocommerce_before_order_object_save'=>'guard_confirmation','woocommerce_order_status_cancelled'=>'release','woocommerce_order_status_failed'=>'release','woocommerce_checkout_order_created'=>'claim','woocommerce_store_api_checkout_order_processed'=>'claim_store_api','woocommerce_before_pay_action'=>'claim','woocommerce_checkout_order_exception'=>'release','woocommerce_before_checkout_process'=>'release_changed_cart_hold','woocommerce_cart_item_removed'=>'release_changed_cart_hold','woocommerce_after_cart_item_quantity_update'=>'release_changed_cart_hold','woocommerce_cart_emptied'=>'release_changed_cart_hold'] as $hook=>$method)remove_action($hook,[\RoxyST\Holds::class,$method],5);
 remove_action('woocommerce_after_order_object_save',[\RoxyST\Holds::class,'release_confirmation'],1);
}
\RoxyST\FixtureHolds::init();
global $wpdb;
$label='PRIVATE CHECKOUT HOLD FIXTURE 2026-10-05';$created=[];$orders=[];$workers=[];$users=[];$subs=[];
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
$digest=static fn()=>hash('sha256',wp_json_encode([$wpdb->get_results("SELECT * FROM `{$wpdb->prefix}roxy_member_scans` ORDER BY id",ARRAY_A),$wpdb->get_results("SELECT * FROM `{$wpdb->posts}` WHERE post_type='roxy_ticket' ORDER BY ID",ARRAY_A),$wpdb->get_results("SELECT m.* FROM `{$wpdb->postmeta}` m JOIN `{$wpdb->posts}` p ON p.ID=m.post_id WHERE p.post_type='roxy_ticket' ORDER BY m.meta_id",ARRAY_A)]));$before=$digest();
try {
 $show=wp_insert_post(['post_type'=>'roxy_showing','post_status'=>'draft','post_title'=>$label],true);if(is_wp_error($show))throw new RuntimeException('Private showing failed');$created[]=$show;update_post_meta($show,'_roxy_capacity',1);
 $p=new WC_Product_Simple();$p->set_name($label);$p->set_status('draft');$p->set_catalog_visibility('hidden');$p->set_regular_price('0');$pid=$p->save();$created[]=$pid;update_post_meta($pid,ROXY_ST_META_SHOWING_ID,$show);update_post_meta($pid,ROXY_ST_META_TICKET_TYPE,'adult');
 $make=static function($customer=0)use($p,$label,&$orders){$o=wc_create_order(['status'=>'pending','customer_id'=>$customer,'customer_note'=>$label]);if(is_wp_error($o))throw new RuntimeException('Private order failed');$orders[]=$o;$o->add_product($p,1);$o->calculate_totals();$o->save();return $o;};
 $a=$make();$b=$make();$qty=static fn()=>\RoxyST\FixtureReservations::quantity_for_showing($show);
 $check($qty()===0,'unmanaged pending orders do not acquire holds accidentally');
 do_action('woocommerce_checkout_order_created',$a);$until=(int)get_post_meta($a->get_id(),'_roxy_seat_hold_until',true);
 $check($qty()===1&&$until>time(),'unpaid claimed order reserves the last seat durably');
 $check(\RoxyST\FixtureCapacity::remaining_seats_for_showing($show)===0,'public availability includes active unpaid hold');
 try{\RoxyST\FixtureHolds::claim($b);$denied=false;}catch(Throwable $e){$denied=true;}
 $check($denied&&!get_post_meta($b->get_id(),'_roxy_seat_hold_until',true),'second unpaid checkout cannot reserve occupied last seat');
 try{do_action('woocommerce_store_api_checkout_order_processed',$b);$denied=false;}catch(\Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e){$denied=$e->getCode()===409;}
 $check($denied,'Store API adapter rejects unavailable seats with a conflict response');
 $a->set_cart_hash('private-original-hash');$a->save();
 $session=WC()->session;$cart=WC()->cart;
 try {
  WC()->session=new class($a->get_id()){private $id;function __construct($id){$this->id=$id;}function get($key){return $key==='order_awaiting_payment'?$this->id:null;}};
  WC()->cart=new class($pid){private $pid;function __construct($pid){$this->pid=$pid;}function get_cart(){return [['product_id'=>$this->pid,'quantity'=>1]];}function get_cart_hash(){return 'private-new-hash';}};
  $check(\RoxyST\FixtureHolds::retry_order_id()===$a->get_id()&&\RoxyST\FixtureReservations::quantity_for_showing($show,$a->get_id())===0,'trusted session retry excludes its own pending hold');
  \RoxyST\FixtureHolds::release_changed_cart_hold();$check($qty()===0,'changed checkout cart releases its earlier unpaid hold');
 } finally{WC()->session=$session;WC()->cart=$cart;}
 do_action('woocommerce_before_pay_action',wc_get_order($a->get_id()));$check($qty()===1,'order-pay adapter renews without counting itself twice');
 update_post_meta($a->get_id(),'_roxy_seat_hold_until',time()-5);$check($qty()===0,'expired hold releases availability without requiring cron');
 \RoxyST\FixtureHolds::claim($b);$check($qty()===1,'another order can claim the expired seat');
 $a=wc_get_order($a->get_id());$a->set_transaction_id('PRIVATE-NO-CHARGE');$a->set_date_paid(time());$a->update_status('processing');$a=wc_get_order($a->get_id());
 $check($a->get_status()==='on-hold'&&(int)$a->get_meta('_roxy_seat_review',true)===1,'late payment with no seat is retained on-hold for manager review');
 $check($a->get_transaction_id()==='PRIVATE-NO-CHARGE'&&$a->get_date_paid()!==null,'late-payment review preserves payment evidence without refund');
 $check(\RoxyST\HoldFixtureTickets::sync_order_tickets($a->get_id()),'review order projection still saves safely');
 $ids=\RoxyST\HoldFixtureTickets::get_order_ticket_ids($a->get_id());$check(count($ids)===1&&!\RoxyST\HoldFixtureTickets::ticket_is_eligible($ids[0]),'review order cannot issue an eligible admission');
 $a->update_status('cancelled');$b->update_status('cancelled');
 $check($qty()===0&&!get_post_meta($b->get_id(),'_roxy_seat_hold_until',true),'cancellation clears unpaid hold and frees availability');
 $a->update_status('pending');$b->update_status('pending');\RoxyST\FixtureHolds::claim($a);$a->update_status('processing');$a=wc_get_order($a->get_id());
 $check($a->get_status()==='processing'&&(int)$a->get_meta('_roxy_seat_review',true)===0&&$qty()===1,'available seat can be confirmed and review flag cleared without double reservation');
 $a->update_status('cancelled');$a->update_status('pending');\RoxyST\FixtureHolds::claim($a);
 $second=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$scope='roxy_scope_'.substr(hash('sha256',$wpdb->prefix.':walkup:'.$show),0,48);$inside=false;
 $pause=static function($saving)use($a,$show,$wpdb,$second,$scope,&$inside){if($saving->get_id()!==$a->get_id()||$saving->get_status()!=='processing')return;update_post_meta($a->get_id(),'_roxy_seat_hold_until',time()-5);$inside=(string)$second->get_var($second->prepare('SELECT GET_LOCK(%s,0)',$scope))==='0';};
 add_action('woocommerce_before_order_object_save',$pause,6);try{$a->update_status('processing');}finally{remove_action('woocommerce_before_order_object_save',$pause,6);}
 $check($inside&&$qty()===1,'showing lock spans status persistence even if hold expires after validation');
 $acquired=(string)$second->get_var($second->prepare('SELECT GET_LOCK(%s,0)',$scope))==='1';if($acquired)$second->get_var($second->prepare('SELECT RELEASE_LOCK(%s)',$scope));$second->close();
 $check($acquired,'confirmation releases its showing lease after Woo saves status');
 update_post_meta($a->get_id(),'_roxy_seat_hold_until',time()-5);$check($qty()===1,'confirmed paid order remains reserved after hold expiry');
 $a->update_status('completed');$check(wc_get_order($a->get_id())->get_status()==='completed','normal paid-to-completed transition does not reacquire an occupied seat');
 $a->update_status('cancelled');$a->update_status('pending');\RoxyST\FixtureHolds::claim($a);$a->update_status('failed');$check($qty()===0&&!get_post_meta($a->get_id(),'_roxy_seat_hold_until',true),'failed order releases hold');
 $a->update_status('pending');\RoxyST\FixtureHolds::claim($a);do_action('woocommerce_checkout_order_exception',$a);$check($qty()===0,'checkout exception release removes only the unpaid hold');
 // Simulate a late metadata failure: the managed marker must roll back with expiry.
 delete_post_meta($a->get_id(),'_roxy_seat_hold_managed');
 $fail=static fn($sql)=>str_contains($sql,"'_roxy_seat_hold_until'")&&str_starts_with($sql,'INSERT INTO')?'INSERT INTO roxy_missing_hold_write VALUES (1)':$sql;
 add_filter('query',$fail);$errors=$wpdb->suppress_errors(true);try{try{\RoxyST\FixtureHolds::claim($a);$denied=false;}catch(Throwable $e){$denied=true;}}finally{remove_filter('query',$fail);$wpdb->suppress_errors($errors);}
 $check($denied&&!get_post_meta($a->get_id(),'_roxy_seat_hold_managed',true)&&$qty()===0,'late hold write failure rolls back earlier marker and reserves nothing');
 for($n=0;$n<2;$n++){$o=$n===0?$a:$b;$pipes=[];$process=proc_open([PHP_BINARY,'/usr/local/bin/wp','--path='.ABSPATH,'eval-file',__DIR__.'/checkout-hold-worker.php',$root,(string)$o->get_id()],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Worker failed');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$workers[]=['process'=>$process,'pipes'=>$pipes,'out'=>'','error'=>'','exit'=>null];}
 $deadline=microtime(true)+40;do{$running=false;foreach($workers as &$w){$w['out'].=stream_get_contents($w['pipes'][1]);$w['error'].=stream_get_contents($w['pipes'][2]);$s=proc_get_status($w['process']);if($s['running'])$running=true;elseif($w['exit']===null)$w['exit']=$s['exitcode'];}unset($w);if($running&&microtime(true)>$deadline)throw new RuntimeException('Workers timed out');if($running)usleep(100000);}while($running);
 $wins=0;foreach($workers as $w){if($w['exit']!==0||!preg_match('/HOLD_RESULT=([01])/',$w['out'],$m))throw new RuntimeException('Worker result missing: '.$w['error']);$wins+=(int)$m[1];}
 $check($wins===1&&$qty()===1,'independent checkouts competing for the last seat produce exactly one durable hold');
 foreach($workers as $w){fclose($w['pipes'][1]);fclose($w['pipes'][2]);proc_close($w['process']);}$workers=[];
 foreach([$a,$b] as $o)\RoxyST\FixtureHolds::release($o);
 // Subscriber entitlement is protected even when showing capacity is unlimited.
 delete_post_meta($show,'_roxy_capacity');update_post_meta($pid,ROXY_ST_META_TICKET_TYPE,'subscriber');
 $u=wp_insert_user(['user_login'=>'roxy-hold-fixture-'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'user_email'=>'fixture-'.bin2hex(random_bytes(6)).'@example.test','role'=>'customer']);if(is_wp_error($u))throw new RuntimeException('Private user failed');$users[]=$u;
 $sub=wcs_create_subscription(['status'=>'pending','customer_id'=>$u,'customer_note'=>$label,'billing_period'=>'month','billing_interval'=>1]);if(is_wp_error($sub))throw new RuntimeException('Subscription failed');$subs[]=$sub;$sub->add_product($p,1);$sub->calculate_totals();$sub->save();$wpdb->update($wpdb->posts,['post_status'=>'wc-active'],['ID'=>$sub->get_id()]);clean_post_cache($sub->get_id());
 $c=$make($u);$d=$make($u);\RoxyST\FixtureHolds::claim($c);try{\RoxyST\FixtureHolds::claim($d);$denied=false;}catch(Throwable $e){$denied=true;}
 $check($denied&&\RoxyST\FixtureReservations::quantity_for_showing($show,0,$u)===1,'unpaid subscriber hold consumes account entitlement on unlimited showing');
 \RoxyST\FixtureHolds::release($c);\RoxyST\FixtureHolds::release($d);update_post_meta($pid,ROXY_ST_META_TICKET_TYPE,'adult');update_post_meta($show,'_roxy_capacity',1);$e=$make();
 foreach([[(string)$e->get_id()],[(string)$sub->get_id(),(string)$show]] as $params){$pipes=[];$process=proc_open(array_merge([PHP_BINARY,'/usr/local/bin/wp','--path='.ABSPATH,'eval-file',__DIR__.'/checkout-hold-worker.php',$root],$params),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cross-path worker failed');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$workers[]=['process'=>$process,'pipes'=>$pipes,'out'=>'','error'=>'','exit'=>null];}
 $deadline=microtime(true)+40;do{$running=false;foreach($workers as &$w){$w['out'].=stream_get_contents($w['pipes'][1]);$w['error'].=stream_get_contents($w['pipes'][2]);$s=proc_get_status($w['process']);if($s['running'])$running=true;elseif($w['exit']===null)$w['exit']=$s['exitcode'];}unset($w);if($running&&microtime(true)>$deadline)throw new RuntimeException('Cross-path workers timed out');if($running)usleep(100000);}while($running);
 $wins=0;foreach($workers as $w){if($w['exit']!==0||!preg_match('/HOLD_RESULT=([01])/',$w['out'],$m))throw new RuntimeException('Cross-path worker result missing: '.$w['error']);$wins+=(int)$m[1];}
 $arrivals=Fixture_Roxy_Sub_Check::walkup_quantity_for_showing($show);$check($wins===1&&$qty()+$arrivals===1,'checkout and member walk-up competing for last seat share exactly one winner');
 foreach($workers as $w){fclose($w['pipes'][1]);fclose($w['pipes'][2]);proc_close($w['process']);}$workers=[];
 \RoxyST\FixtureHolds::release($e);$wpdb->delete($wpdb->prefix.'roxy_member_scans',['subscription_id'=>$sub->get_id(),'showing_id'=>$show]);
 $show2=wp_insert_post(['post_type'=>'roxy_showing','post_status'=>'draft','post_title'=>$label],true);if(is_wp_error($show2))throw new RuntimeException('Second private showing failed');$created[]=$show2;update_post_meta($show2,'_roxy_capacity',0);
 $p2=new WC_Product_Simple();$p2->set_name($label);$p2->set_status('draft');$p2->set_catalog_visibility('hidden');$p2->set_regular_price('0');$pid2=$p2->save();$created[]=$pid2;update_post_meta($pid2,ROXY_ST_META_SHOWING_ID,$show2);update_post_meta($pid2,ROXY_ST_META_TICKET_TYPE,'adult');
 $multi=$make();$multi->add_product($p2,1);$multi->calculate_totals();$multi->save();try{\RoxyST\FixtureHolds::claim($multi);$denied=false;}catch(Throwable $ex){$denied=true;}
 $check($denied&&!get_post_meta($multi->get_id(),'_roxy_seat_hold_managed',true)&&$qty()===0,'one full showing rejects the entire multi-showing hold without reserving the other');
 update_post_meta($show2,'_roxy_capacity',1);\RoxyST\FixtureHolds::claim($multi);
 $check($qty()===1&&\RoxyST\FixtureReservations::quantity_for_showing($show2)===1,'multi-showing retry reserves both available seats atomically');
 $multi->update_status('cancelled');$check($qty()===0&&\RoxyST\FixtureReservations::quantity_for_showing($show2)===0,'multi-showing cancellation releases both reservations');
 echo 'CHECKOUT_HOLD_WOO_OK'.PHP_EOL;
} finally {
 foreach($workers as $w){if(proc_get_status($w['process'])['running'])proc_terminate($w['process']);fclose($w['pipes'][1]);fclose($w['pipes'][2]);proc_close($w['process']);}
 foreach($orders as $o){foreach(\RoxyST\HoldFixtureTickets::get_order_ticket_ids($o->get_id()) as $id)wp_delete_post($id,true);$o->delete(true);}
 foreach($subs as $s){$wpdb->delete($wpdb->prefix.'roxy_member_scans',['subscription_id'=>$s->get_id()]);$s->delete(true);}foreach(array_reverse($created) as $id)wp_delete_post($id,true);require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $u)wp_delete_user($u);
}
$check(hash_equals($before,$digest()),'all original ticket and member log rows unchanged after cleanup');
