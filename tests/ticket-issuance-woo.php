<?php
// Actual Woo CRUD + staged ticket implementation; private disposable $0 order, no payment/mail.
if (!defined('WP_CLI') || !WP_CLI) exit;
$root=$args[0]??dirname(__DIR__);
require __DIR__.'/ticket-fixture-loader.php';
roxy_fixture_tickets($root,'IssuanceFixtureTickets');
if (!class_exists('RoxyST\\Tickets', false)) class_alias('RoxyST\\IssuanceFixtureTickets','RoxyST\\Tickets');
if (!post_type_exists('roxy_ticket')) \RoxyST\IssuanceFixtureTickets::register_post_type();
require_once $root.'/includes/modules/will-call/roxy-will-call.php';
add_filter('pre_wp_mail',static fn()=>true);
remove_action('woocommerce_checkout_order_processed',[\RoxyST\Tickets::class,'on_order_changed'],30);
remove_action('woocommerce_order_status_changed',[\RoxyST\Tickets::class,'on_order_changed'],30);
remove_action('woocommerce_refund_created',[\RoxyST\Tickets::class,'on_refund_created'],30);
remove_action('woocommerce_refund_deleted',[\RoxyST\Tickets::class,'on_refund_deleted'],30);
remove_action('woocommerce_order_status_changed',[\RoxyST\Sales::class,'on_order_changed'],20);
$created=[];$order=null;$refund=null;$group_order=null;$group_ids=[];$marathon_order=null;$marathon_ids=[];$summary=null;$display_key='';
$wp_cli_path=(string)(getenv('ROXY_WP_CLI')?:'/usr/local/bin/wp');
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
try{
  $show=wp_insert_post(['post_type'=>'roxy_showing','post_status'=>'draft','post_title'=>'PRIVATE ISSUANCE FIXTURE 2026-10-05'],true);if(is_wp_error($show))throw new RuntimeException('Cannot create fixture');$created[]=$show;
  $product=new WC_Product_Simple();$product->set_name('PRIVATE ISSUANCE FIXTURE 2026-10-05');$product->set_status('draft');$product->set_catalog_visibility('hidden');$product->set_regular_price('0');$pid=$product->save();$created[]=$pid;
  update_post_meta($pid,ROXY_ST_META_SHOWING_ID,$show);update_post_meta($pid,ROXY_ST_META_TICKET_TYPE,'general');update_post_meta($show,'_roxy_pid_adult',$pid);update_post_meta($show,'_roxy_start',wp_date('Y-m-d').' 23:00');
  $order=wc_create_order(['status'=>'pending','customer_note'=>'PRIVATE ISSUANCE FIXTURE 2026-10-05 — no payment or fulfillment']);if(is_wp_error($order))throw new RuntimeException('Cannot create private order');
  $item_id=$order->add_product($product,3);$order->calculate_totals();$order->save();
  $check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id()),'actual Woo order issues complete ticket set');
  $check(!\RoxyST\IssuanceFixtureTickets::sync_order_tickets(2147483000),'missing Woo order is a reconciliation failure, not a successful empty issuance');
  $ids=\RoxyST\IssuanceFixtureTickets::get_order_ticket_ids($order->get_id());$check(count($ids)===3&&count(array_unique($ids))===3,'three distinct ticket identities are saved through an unrelated WP_Query');
  $marathon_order=wc_create_order(['status'=>'processing','customer_note'=>'PRIVATE MARATHON FIXTURE — six separate admissions under one order']);if(is_wp_error($marathon_order))throw new RuntimeException('Marathon fixture order failed');
  $marathon_showings=[];
  for($i=0;$i<6;$i++){
    $showing_id=wp_insert_post(['post_type'=>'roxy_showing','post_status'=>'draft','post_title'=>'PRIVATE MARATHON FIXTURE '.$i],true);if(is_wp_error($showing_id))throw new RuntimeException('Marathon showing fixture failed');$created[]=$showing_id;$marathon_showings[]=$showing_id;
    $show_product=new WC_Product_Simple();$show_product->set_name('PRIVATE MARATHON FIXTURE '.$i);$show_product->set_status('draft');$show_product->set_catalog_visibility('hidden');$show_product->set_regular_price('7.50');$show_product_id=$show_product->save();$created[]=$show_product_id;update_post_meta($show_product_id,ROXY_ST_META_SHOWING_ID,$showing_id);update_post_meta($show_product_id,ROXY_ST_META_TICKET_TYPE,'marathon');$marathon_order->add_product($show_product,1);
  }
  $marathon_order->calculate_totals();$marathon_order->save();
  $check((float)$marathon_order->get_total()===45.0&&\RoxyST\IssuanceFixtureTickets::sync_order_tickets($marathon_order->get_id()),'one $45 order with six showing-specific lines issues through normal ticket flow');
  $marathon_ids=\RoxyST\IssuanceFixtureTickets::get_order_ticket_ids($marathon_order->get_id());$marathon_ticket_showings=array_map(static fn($id)=>(int)get_post_meta($id,'_roxy_ticket_showing_id',true),$marathon_ids);
  $check(count($marathon_ids)===6&&count(array_unique($marathon_ids))===6&&$marathon_ticket_showings===$marathon_showings,'marathon order has six unique QR ticket records, one tied to each showing');
  $check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($marathon_order->get_id())&&\RoxyST\IssuanceFixtureTickets::get_order_ticket_ids($marathon_order->get_id())===$marathon_ids,'reconciling a marathon order does not duplicate any QR admission');
  $scan_results=[];foreach($marathon_ids as $ticket_id)$scan_results[]=\RoxyST\IssuanceFixtureTickets::check_in_ticket($ticket_id,9,'ticket');
  $check(!in_array(false,$scan_results,true)&&count(array_filter($marathon_ids,static fn($id)=>(int)get_post_meta($id,'_roxy_checked_in',true)===1))===6,'each showing-specific marathon QR scans independently without consuming other admissions');
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
  if(!function_exists('proc_open'))throw new RuntimeException('Independent admission worker test requires proc_open');
  $workers=[];
  try{
    foreach([[9,'will_call'],[10,'ticket']] as [$actor,$source]){
      $pipes=[];$process=proc_open([PHP_BINARY,$wp_cli_path,'--path='.ABSPATH,'eval-file',__DIR__.'/ticket-admission-worker.php',$root,(string)$order->get_id(),(string)$ids[0],(string)$actor,$source],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
      if(!is_resource($process))throw new RuntimeException('Cannot start private worker');
      fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
      $workers[]=['process'=>$process,'pipes'=>$pipes,'out'=>'','error'=>'','exit'=>null];
    }
    $deadline=microtime(true)+40;
    do{
      $running=false;
      foreach($workers as &$worker){$worker['out'].=stream_get_contents($worker['pipes'][1]);$worker['error'].=stream_get_contents($worker['pipes'][2]);$status=proc_get_status($worker['process']);if($status['running'])$running=true;elseif($worker['exit']===null)$worker['exit']=$status['exitcode'];}unset($worker);
      if($running&&microtime(true)>$deadline)throw new RuntimeException('Private admission workers timed out');
      if($running)usleep(100000);
    }while($running);
    $results=[];
    foreach($workers as $worker){if($worker['exit']!==0||!preg_match('/ADMISSION_RESULT=([01])/',$worker['out'],$match))throw new RuntimeException('Private admission worker failed');$results[]=(int)$match[1];}
    $check(array_sum($results)===1,'two independent overlapping staff workers produce exactly one admission');
    wp_cache_delete($ids[0],'post_meta');$winner=$results[0]===1?[9,'will_call']:[10,'ticket'];
    $check((int)get_post_meta($ids[0],'_roxy_checked_in_by',true)===$winner[0]&&get_post_meta($ids[0],'_roxy_checked_in_source',true)===$winner[1],'concurrent winner retains matching staff actor and source');
    $check(\RoxyST\IssuanceFixtureTickets::undo_check_in_ticket($ids[0]),'concurrent fixture admission explicitly cleared');
  }finally{
    foreach($workers as $worker){if(proc_get_status($worker['process'])['running'])proc_terminate($worker['process']);fclose($worker['pipes'][1]);fclose($worker['pipes'][2]);proc_close($worker['process']);}
  }
  $check(\RoxyST\IssuanceFixtureTickets::check_in_ticket($ids[0],9,'will_call'),'common admission saves actual transition');
  $check(get_post_meta($ids[0],'_roxy_checked_in_source',true)==='will_call'&&(int)get_post_meta($ids[0],'_roxy_checked_in_by',true)===9,'source and staff actor commit with admission');
  $check(!\RoxyST\IssuanceFixtureTickets::check_in_ticket($ids[0],10,'ticket')&&(int)get_post_meta($ids[0],'_roxy_checked_in_by',true)===9,'repeat scan does not report a second admission or replace first actor');
  $check(!\RoxyST\IssuanceFixtureTickets::undo_check_in_ticket($ids[0],'ticket')&&(int)get_post_meta($ids[0],'_roxy_checked_in',true)===1,'source mismatch rejects stale undo');
  $check(\RoxyST\IssuanceFixtureTickets::undo_check_in_ticket($ids[0],'will_call')&&get_post_meta($ids[0],'_roxy_checked_in_source',true)===''&&get_post_meta($ids[0],'_roxy_ticket_state',true)==='valid','matching explicit undo removes complete admission record');
  $check(!\RoxyST\IssuanceFixtureTickets::undo_check_in_ticket($ids[0]),'repeat undo does not report a phantom transition');
  global $wpdb;
  $fail_admit=static function($sql)use($wpdb){return strpos($sql,"INSERT INTO `{$wpdb->postmeta}`")===0&&str_contains($sql,'_roxy_checked_in_by')?'INSERT INTO `roxy_missing_admission_failure_target` VALUES (1)':$sql;};
  add_filter('query',$fail_admit);$suppressed=$wpdb->suppress_errors(true);
  try{$check(!\RoxyST\IssuanceFixtureTickets::check_in_ticket($ids[0],9),'later admission write failure reports no admission');}
  finally{remove_filter('query',$fail_admit);$wpdb->suppress_errors($suppressed);}
  $check(!get_post_meta($ids[0],'_roxy_checked_in',true)&&!get_post_meta($ids[0],'_roxy_checked_in_at',true)&&get_post_meta($ids[0],'_roxy_ticket_state',true)==='valid','failed admission rolls back earlier flag and timestamp');
  $check(\RoxyST\IssuanceFixtureTickets::check_in_ticket($ids[0],9,'ticket'),'fresh staff retry succeeds after rollback');
  $fail_undo=static function($sql)use($wpdb){return strpos($sql,"DELETE FROM `{$wpdb->postmeta}`")===0&&str_contains($sql,'_roxy_checked_in_at')?'DELETE FROM `roxy_missing_undo_failure_target`':$sql;};
  add_filter('query',$fail_undo);$suppressed=$wpdb->suppress_errors(true);
  try{$check(!\RoxyST\IssuanceFixtureTickets::undo_check_in_ticket($ids[0]),'later undo write failure reports no undo');}
  finally{remove_filter('query',$fail_undo);$wpdb->suppress_errors($suppressed);}
  $check((int)get_post_meta($ids[0],'_roxy_checked_in',true)===1&&get_post_meta($ids[0],'_roxy_checked_in_source',true)==='ticket'&&get_post_meta($ids[0],'_roxy_ticket_state',true)==='checked_in','failed undo retains original complete admission');
  $check(\RoxyST\IssuanceFixtureTickets::undo_check_in_ticket($ids[0]),'explicit retry successfully undoes private fixture');
  $check(\RoxyST\IssuanceFixtureTickets::check_in_ticket($ids[0],9,'ticket')&&\RoxyST\IssuanceFixtureTickets::check_in_ticket($ids[1],9,'will_call'),'legacy quantity fixture has one QR and one Will Call admission');
  $legacy=\RoxyST\IssuanceFixtureTickets::set_order_item_check_in_qty($order->get_id(),$item_id,1,9);
  $check($legacy['current']===1&&$legacy['undone']===1&&(int)get_post_meta($ids[0],'_roxy_checked_in',true)===1&&!(int)get_post_meta($ids[1],'_roxy_checked_in',true),'legacy quantity helper uses atomic source-aware undo and preserves QR admission');
  $legacy_qr_only=\RoxyST\IssuanceFixtureTickets::set_order_item_check_in_qty($order->get_id(),$item_id,0,9);
  $check(!empty($legacy_qr_only['error'])&&$legacy_qr_only['changed']===0&&$legacy_qr_only['current']===1&&(int)get_post_meta($ids[0],'_roxy_checked_in',true)===1,'legacy quantity helper cannot undo QR admission and reports the unchanged state');
  $check(\RoxyST\IssuanceFixtureTickets::undo_check_in_ticket($ids[0],'ticket'),'QR fixture explicitly cleared after legacy helper checks');
  $summary=['context_id'=>1000000000+$show,'customer_key'=>'private-group-'.$order->get_id(),'baseline_used'=>0];
  $count_checked=static function($set){foreach($set as $id)wp_cache_delete($id,'post_meta');return count(array_filter($set,static fn($id)=>(int)get_post_meta($id,'_roxy_checked_in',true)===1));};
  \RoxyST\IssuanceFixtureTickets::apply_will_call_group($ids,3,false,$summary,null,9);
  $check($count_checked($ids)===3&&count(array_filter($ids,static fn($id)=>(int)get_post_meta($id,'_roxy_checked_in_by',true)===9))===3,'Will Call group records the explicitly supplied staff actor for every ticket');
  $summary['baseline_used']=3;
  \RoxyST\IssuanceFixtureTickets::apply_will_call_group($ids,0,true,$summary,null,9);
  $summary['baseline_used']=0;
  $fail_group=static function($sql)use($wpdb,$ids){return strpos($sql,"INSERT INTO `{$wpdb->postmeta}`")===0&&str_contains($sql,'SELECT '.$ids[1].',')&&str_contains($sql,'_roxy_checked_in_by')?'INSERT INTO `roxy_missing_group_failure_target` VALUES (1)':$sql;};
  add_filter('query',$fail_group);$suppressed=$wpdb->suppress_errors(true);$rejected=false;
  try {try {\RoxyST\IssuanceFixtureTickets::apply_will_call_group($ids,3,false,$summary);}catch(RuntimeException $e){$rejected=true;}}
  finally {remove_filter('query',$fail_group);$wpdb->suppress_errors($suppressed);}
  $check($rejected&&$count_checked($ids)===0,'later ticket failure rolls back the entire group');
  $fail_summary=static function($sql)use($wpdb){return strpos($sql,"INSERT INTO `{$wpdb->prefix}roxy_will_call_checkins`")===0?'INSERT INTO `roxy_missing_group_summary_target` VALUES (1)':$sql;};
  add_filter('query',$fail_summary);$suppressed=$wpdb->suppress_errors(true);$rejected=false;
  try {try {\RoxyST\IssuanceFixtureTickets::apply_will_call_group($ids,3,false,$summary);}catch(RuntimeException $e){$rejected=true;}}
  finally {remove_filter('query',$fail_summary);$wpdb->suppress_errors($suppressed);}
  $check($rejected&&$count_checked($ids)===0,'summary persistence failure rolls back every ticket admission');
  $workers=[];$deadline=microtime(true)+40;
  try {
    foreach([1,2] as $worker_id){$pipes=[];$process=proc_open([PHP_BINARY,$wp_cli_path,'--path='.ABSPATH,'eval-file',__DIR__.'/ticket-group-worker.php',$root,(string)$order->get_id(),(string)$summary['context_id'],$summary['customer_key']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot start private group worker');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$workers[]=['process'=>$process,'pipes'=>$pipes,'out'=>'','exit'=>null];}
    do {$running=false;foreach($workers as &$worker){$worker['out'].=stream_get_contents($worker['pipes'][1]);stream_get_contents($worker['pipes'][2]);$status=proc_get_status($worker['process']);if($status['running'])$running=true;elseif($worker['exit']===null)$worker['exit']=$status['exitcode'];}unset($worker);if($running){if(microtime(true)>$deadline)throw new RuntimeException('Group workers timed out');usleep(100000);}}while($running);
    $results=[];foreach($workers as $worker){if($worker['exit']!==0||!preg_match('/GROUP_RESULT=([01])/',$worker['out'],$match))throw new RuntimeException('Group worker failed');$results[]=(int)$match[1];}
    $check(array_sum($results)===1&&$count_checked($ids)===3,'two independent group saves allow exactly one original-baseline transition');
  }finally{foreach($workers as $worker){if(proc_get_status($worker['process'])['running'])proc_terminate($worker['process']);fclose($worker['pipes'][1]);fclose($worker['pipes'][2]);proc_close($worker['process']);}}
  $stored=(int)$wpdb->get_var($wpdb->prepare("SELECT used_qty FROM {$wpdb->prefix}roxy_will_call_checkins WHERE product_id=%d AND customer_key=%s",$summary['context_id'],$summary['customer_key']));
  $check($stored===3,'successful group summary matches actual admitted tickets');
  $rejected=false;try{\RoxyST\IssuanceFixtureTickets::apply_will_call_group($ids,0,true,$summary);}catch(RuntimeException $e){$rejected=true;}
  $check($rejected&&$count_checked($ids)===3,'stale group baseline cannot undo newer admission');
  $summary['baseline_used']=3;\RoxyST\IssuanceFixtureTickets::apply_will_call_group($ids,0,true,$summary);
  $check($count_checked($ids)===0,'explicit group undo commits complete ticket set');
  $check(\RoxyST\IssuanceFixtureTickets::check_in_ticket($ids[0],9,'ticket'),'private QR admission precedes mixed-source group');
  $summary['baseline_used']=1;\RoxyST\IssuanceFixtureTickets::apply_will_call_group($ids,3,false,$summary);
  $summary['baseline_used']=3;$rejected=false;try{\RoxyST\IssuanceFixtureTickets::apply_will_call_group($ids,0,true,$summary);}catch(RuntimeException $e){$rejected=true;}
  $check($rejected&&$count_checked($ids)===3&&get_post_meta($ids[0],'_roxy_checked_in_source',true)==='ticket','group cannot erase an unrelated QR admission');
  \RoxyST\IssuanceFixtureTickets::apply_will_call_group($ids,1,true,$summary);
  $check($count_checked($ids)===1&&get_post_meta($ids[0],'_roxy_checked_in_source',true)==='ticket','group undo preserves earlier QR identity and source');
  \RoxyST\IssuanceFixtureTickets::undo_check_in_ticket($ids[0]);$summary['baseline_used']=0;$stale_summary_rejected=false;
  try{\RoxyST\IssuanceFixtureTickets::apply_will_call_group($ids,0,false,$summary);}catch(RuntimeException $e){$stale_summary_rejected=true;}
  $stale_summary=(int)$wpdb->get_var($wpdb->prepare("SELECT used_qty FROM {$wpdb->prefix}roxy_will_call_checkins WHERE product_id=%d AND customer_key=%s",$summary['context_id'],$summary['customer_key']));
  $check($stale_summary_rejected&&$stale_summary===1&&$count_checked($ids)===0,'ticket undo cannot silently erase a stale higher aggregate; the summary remains for manager review');
  $wpdb->delete($wpdb->prefix.'roxy_will_call_checkins',['product_id'=>$summary['context_id'],'customer_key'=>$summary['customer_key']]);
  $group_order=wc_create_order(['status'=>'processing','customer_note'=>'PRIVATE ISSUANCE FIXTURE 2026-10-05 — private second group order']);if(is_wp_error($group_order))throw new RuntimeException('Second fixture order failed');$group_order->add_product($product,1);$group_order->calculate_totals();$group_order->save();\RoxyST\IssuanceFixtureTickets::sync_order_tickets($group_order->get_id());$group_ids=\RoxyST\IssuanceFixtureTickets::get_order_ticket_ids($group_order->get_id());$both=array_merge($ids,$group_ids);
  \RoxyST\IssuanceFixtureTickets::apply_will_call_group($both,4,false,$summary);
  $check(count($group_ids)===1&&$count_checked($both)===4,'one customer group commits tickets spanning two Woo orders');
  $summary['baseline_used']=4;\RoxyST\IssuanceFixtureTickets::apply_will_call_group($both,0,true,$summary);
  $check(\RoxyST\IssuanceFixtureTickets::check_in_ticket($ids[0],9,'ticket'),'historical aggregate fixture establishes a ticket-level admission');
  $display_key=roxy_will_call_customer_key((string)get_post_meta($ids[0],'_roxy_ticket_customer_name',true),(string)get_post_meta($ids[0],'_roxy_ticket_customer_email',true));
  $wpdb->replace($wpdb->prefix.'roxy_will_call_checkins',['product_id'=>$pid,'customer_key'=>$display_key,'checked_in'=>1,'used_qty'=>2,'updated_at'=>current_time('mysql')],['%d','%s','%d','%d','%s']);
  ob_start();
  try {roxy_will_call_render_table('product',$pid,[['customer_key'=>$display_key,'name'=>'Private Fixture Buyer','email'=>'','qty'=>3,'ticket_types'=>['General'=>3],'orders'=>[],'latest_order_ts'=>0,'source'=>'order']],['total_qty'=>3,'total_revenue'=>0,'order_count'=>1]);$review_html=(string)ob_get_contents();}
  finally {ob_end_clean();}
  $check(str_contains($review_html,'Attendance needs review')&&str_contains($review_html,'Legacy count: 2; ticket records: 1')&&preg_match('/class="roxy-used"[^>]*disabled/',$review_html)===1,'Will Call UI flags the legacy mismatch and disables edits without merging the counts');
  $display_stored=(int)$wpdb->get_var($wpdb->prepare("SELECT used_qty FROM {$wpdb->prefix}roxy_will_call_checkins WHERE product_id=%d AND customer_key=%s",$pid,$display_key));
  $check($display_stored===2,'rendering the review-required UI does not change the historical aggregate');
  $wpdb->delete($wpdb->prefix.'roxy_will_call_checkins',['product_id'=>$pid,'customer_key'=>$display_key]);
  $wpdb->replace($wpdb->prefix.'roxy_will_call_checkins',['product_id'=>$summary['context_id'],'customer_key'=>$summary['customer_key'],'checked_in'=>1,'used_qty'=>2,'updated_at'=>current_time('mysql')],['%d','%s','%d','%d','%s']);
  $summary['baseline_used']=1;$legacy_rejected=false;
  try{\RoxyST\IssuanceFixtureTickets::apply_will_call_group($both,1,false,$summary);}catch(RuntimeException $e){$legacy_rejected=true;}
  $legacy_stored=(int)$wpdb->get_var($wpdb->prepare("SELECT used_qty FROM {$wpdb->prefix}roxy_will_call_checkins WHERE product_id=%d AND customer_key=%s",$summary['context_id'],$summary['customer_key']));
  $check($legacy_rejected&&$legacy_stored===2&&(int)get_post_meta($ids[0],'_roxy_checked_in',true)===1,'ticket-level save refuses to overwrite a higher legacy aggregate and preserves both sources for review');
  \RoxyST\IssuanceFixtureTickets::undo_check_in_ticket($ids[0],'ticket');
  foreach($ids as $ticket_id)$check(\RoxyST\IssuanceFixtureTickets::check_in_ticket($ticket_id,9,'ticket'),'fixture ticket checks in before later refund');
  \RoxyST\Sales::clear_showing_cache($show);$before_refund_stats=\RoxyST\Sales::get_showing_stats($show);
  $check((int)($before_refund_stats['sold_qty']??-1)===4&&\RoxyST\IssuanceFixtureTickets::count_checked_in_for_showing($show)===3,'four paid tickets count as sales while only three actual door check-ins count as attendance');
  $refund=wc_create_refund(['order_id'=>$order->get_id(),'amount'=>0,'reason'=>'PRIVATE ISSUANCE FIXTURE','refund_payment'=>false,'restock_items'=>false,'line_items'=>[$item_id=>['qty'=>1,'refund_total'=>0,'refund_tax'=>[]]]]);if(is_wp_error($refund))throw new RuntimeException('Fixture refund failed');
  $check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id()),'actual zero-dollar line refund reconciles');
  $check(count(array_filter($ids,static fn($id)=>get_post_meta($id,'_roxy_ticket_state',true)==='refunded'))===1,'one refund invalidates exactly one existing ticket');
  \RoxyST\Sales::clear_showing_cache($show);$refunded_stats=\RoxyST\Sales::get_showing_stats($show);
  $check((int)($refunded_stats['sold_qty']??-1)===3,'a later refund reduces four reportable tickets across both fixture orders to three');
  $check(\RoxyST\IssuanceFixtureTickets::count_checked_in_for_showing($show)===3&&count(array_filter($ids,static fn($id)=>(int)get_post_meta($id,'_roxy_checked_in',true)===1))===3,'a later refund preserves all three actual door check-ins separately from reportable sales');
  $order->update_status('cancelled');$check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id()),'cancellation reconciles with original identities');
  $check(count(array_filter($ids,static fn($id)=>get_post_meta($id,'_roxy_ticket_state',true)==='valid'))===0,'cancelled fixture has no valid tickets');
  update_post_meta($ids[2],'_roxy_ticket_order_id',999999999);
  $check(!\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id()),'cross-order reference is rejected rather than reassigning a ticket');
  $check((int)get_post_meta($ids[2],'_roxy_ticket_order_id',true)===999999999,'rejected reference retains original identity');
  update_post_meta($ids[2],'_roxy_ticket_order_id',$order->get_id());
  $check(\RoxyST\IssuanceFixtureTickets::sync_order_tickets($order->get_id()),'repaired private fixture reconciles on retry');
  echo 'TICKET_ISSUANCE_WOO_OK'.PHP_EOL;
}finally{
  if($summary){global $wpdb;$wpdb->delete($wpdb->prefix.'roxy_will_call_checkins',['product_id'=>$summary['context_id'],'customer_key'=>$summary['customer_key']]);}
  if($display_key!=='')$wpdb->delete($wpdb->prefix.'roxy_will_call_checkins',['product_id'=>$pid,'customer_key'=>$display_key]);
  if($marathon_order instanceof WC_Order){foreach(array_unique(array_merge($marathon_ids??[],\RoxyST\IssuanceFixtureTickets::get_order_ticket_ids($marathon_order->get_id()))) as $id)wp_delete_post($id,true);$marathon_order->delete(true);}
  if($group_order instanceof WC_Order){foreach($group_ids as $id)wp_delete_post($id,true);$group_order->delete(true);}
  if($refund instanceof WC_Order_Refund)$refund->delete(true);
  if($order instanceof WC_Order){foreach(range(1,3) as $attempt)wp_clear_scheduled_hook('roxy_st_retry_order_tickets',[$order->get_id(),$attempt]);foreach(array_unique(array_merge($ids??[],\RoxyST\IssuanceFixtureTickets::get_order_ticket_ids($order->get_id()))) as $id)wp_delete_post($id,true);$order->delete(true);}
  foreach(array_reverse($created) as $id)wp_delete_post($id,true);
}
