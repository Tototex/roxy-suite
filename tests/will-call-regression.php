<?php
// Standalone admission/refund/queue checks; no live orders, database or emails.
define('ABSPATH', __DIR__); define('HOUR_IN_SECONDS',3600);
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function add_action($name,$callback,...$args){$GLOBALS['actions'][$name]=$callback;}
function add_filter(...$args){} function register_activation_hook(...$args){}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function wp_timezone(){return new DateTimeZone('America/Los_Angeles');}
function update_post_meta($id,$key,$value){$GLOBALS['meta'][$id][$key]=$value;return true;}
function delete_post_meta($id,$key){unset($GLOBALS['meta'][$id][$key]);return true;}
function get_post_type($id){return isset($GLOBALS['meta'][$id])?'roxy_ticket':'';}
function get_current_user_id(){return 9;} function current_time($format){return '2026-10-03 10:00:00';}
function wc_get_order($id){if((int)($GLOBALS['order_read_fail_id']??0)===(int)$id)return false;if((int)($GLOBALS['order_read_throw_id']??0)===(int)$id)throw new RuntimeException('fixture order read failure');if((int)($GLOBALS['order_bad_items_id']??0)===(int)$id)return new TestOrderWithBadItems();return $GLOBALS['orders'][$id]??false;}
function wc_get_orders($args){
    $GLOBALS['order_queries'][]=$args;
    if (!empty($GLOBALS['order_query_throw'])) throw new RuntimeException('fixture order query failure');
    $page=(int)($args['paged']??1);
    if (!empty($GLOBALS['order_query_fail']) || (int)($GLOBALS['order_query_fail_page']??0)===$page) return false;
    $ids=array_keys($GLOBALS['orders']); sort($ids,SORT_NUMERIC);
    $statuses=array_map(fn($status)=>preg_replace('/^wc-/','',(string)$status),(array)($args['status']??[]));
    $cutoff=substr((string)($args['date_created']??''),1);
    $ids=array_values(array_filter($ids,function($id)use($statuses,$cutoff){
        $order=$GLOBALS['orders'][$id]??null;
        if(!$order || !in_array($order->get_status(),$statuses,true)) return false;
        $created=$order->get_date_created();
        return !$created || $created->getTimestamp()>=(new DateTimeImmutable($cutoff.' 00:00:00'))->getTimestamp();
    }));
    $limit=(int)($args['limit']??-1);
    $page_ids=$limit>0?array_slice($ids,($page-1)*$limit,$limit):$ids;
    if(!empty($args['paginate']))return (object)['orders'=>$page_ids,'total'=>count($ids),'max_num_pages'=>$limit>0?(int)ceil(count($ids)/$limit):1];
    return $page_ids;
}
function get_option($key,$default=false){return $default;} function update_option(...$args){}
function wp_json_encode($value){return json_encode($value);}
function get_transient($key){$GLOBALS['cache_reads'][]=$key;return $GLOBALS['cache'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['cache'][$key]=$value;}
function delete_transient($key){}
function wc_get_product($id){return new class {function get_name(){return 'General';}};}
function get_posts($args){return array_keys($GLOBALS['meta']);}
function roxy_suite_user_can_access_admin(){return true;}
function sanitize_text_field($value){return (string)$value;}
function wp_unslash($value){return $value;} function wp_verify_nonce(...$args){return true;}
function absint($value){return abs((int)$value);}
class JsonResult extends RuntimeException {public $success;public $data;function __construct($success,$data){$this->success=$success;$this->data=$data;}}
function wp_send_json_error($data){throw new JsonResult(false,$data);}
function wp_send_json_success($data){throw new JsonResult(true,$data);}
class WC_Order {
    public $status='processing'; public $refund=1; public $quantity=3; public $line_total=18; public $line_tax=1.8;
    public $billing_first='Test'; public $billing_last='Buyer'; public $billing_email='buyer@example.test'; public $created=null;
    function get_status(){return $this->status;}
    function get_items($type='line_item'){return [10=>new TestItem($this->quantity,$this->line_total,$this->line_tax)];}
    function get_item($id){return $id===10?new TestItem:false;}
    function get_qty_refunded_for_item($id){return -$this->refund;}
    function get_total_refunded_for_item($id){return $this->refund*6;}
    function get_tax_refunded_for_item($id,$tax_id){return $this->refund*0.6;}
    function get_billing_first_name(){return $this->billing_first;} function get_billing_last_name(){return $this->billing_last;}
    function get_billing_email(){return $this->billing_email;} function get_date_created(){return $this->created;}
}
class TestOrderWithBadItems extends WC_Order {function get_items($type='line_item'){return false;}}
class TestItem {
    private $quantity; private $total; private $tax;
    function __construct($quantity=3,$total=18,$tax=1.8){$this->quantity=$quantity;$this->total=$total;$this->tax=$tax;}
    function get_id(){return 10;} function get_product_id(){return 8;} function get_variation_id(){return 0;}
    function get_quantity(){return $this->quantity;} function get_total(){return $this->total;} function get_total_tax(){return $this->tax;}
    function get_taxes(){return ['total'=>[1=>1.8]];} function get_meta($key,$single){return [101,102,103];}
}
class FixtureDate {
    private $date;
    function __construct($value){$this->date=new DateTimeImmutable($value);}
    function getTimestamp(){return $this->date->getTimestamp();}
    function date($format){return $this->date->format($format);}
}
class TestDatabase {
    public $prefix='test_'; public $writes=0; public $fail=false;public $last_error='';
    function prepare($sql,...$args){return $sql;}
    function get_results($sql,$format){return [['customer_key'=>roxy_will_call_customer_key('Test Buyer','buyer@example.test'),'used_qty'=>$GLOBALS['baseline']??0,'checked_in'=>0]];}
    function replace(...$args){$this->writes++;return $this->fail?false:1;}
}
define('ARRAY_A','ARRAY_A');
$GLOBALS['wpdb']=new TestDatabase;
$root=$argv[1]??dirname(__DIR__);
require __DIR__.'/ticket-atomic-test-double.php';
require $root.'/includes/modules/show-tickets/includes/class-roxy-st-tickets.php';
require $root.'/includes/modules/will-call/roxy-will-call.php';
check(roxy_will_call_parse_showing_start('2028-02-29T19:30') instanceof DateTimeImmutable,'valid local leap-day showing parses');
check(roxy_will_call_parse_showing_start('2026-02-30T19:30')===null,'impossible showing date is rejected without normalization');
check(roxy_will_call_parse_showing_start('2026-03-08T02:30')===null,'nonexistent spring DST local time is rejected');
check(roxy_will_call_parse_showing_start('2026-11-01 01:30:00') instanceof DateTimeImmutable,'valid fall DST ambiguous local time remains accepted');
$GLOBALS['orders']=[1=>new WC_Order];
foreach([101,102,103] as $id)$GLOBALS['meta'][$id]=['_roxy_ticket_order_id'=>1,'_roxy_ticket_order_item_id'=>10,'_roxy_ticket_state'=>'valid','_roxy_ticket_customer_name'=>'Test Buyer','_roxy_ticket_customer_email'=>'buyer@example.test'];
$GLOBALS['meta'][102]['_roxy_ticket_refunded']=1;$GLOBALS['meta'][102]['_roxy_ticket_state']='refunded';
$GLOBALS['meta'][103]['_roxy_checked_in']=1;$GLOBALS['meta'][103]['_roxy_ticket_state']='checked_in';
check(roxy_will_call_apply_ticket_checkin_state([101,102,103],1)===[103] && !get_post_meta(101,'_roxy_checked_in'), 'existing QR identity retained, refunded ticket untouched');
$result=roxy_will_call_apply_ticket_checkin_state([101,102,103],2);
check(count($result)===2 && get_post_meta(101,'_roxy_checked_in_source')==='will_call','additional admission uses common paid ticket API');
$rejected=false;try{roxy_will_call_apply_ticket_checkin_state([101,102,103],1);}catch(RuntimeException $e){$rejected=true;}
check($rejected && get_post_meta(101,'_roxy_checked_in')==1,'decrease requires explicit undo');
check(roxy_will_call_apply_ticket_checkin_state([101,102,103],1,true)===[103] && !get_post_meta(101,'_roxy_checked_in'),'explicit undo affects only Will Call admission');
$rejected=false;try{roxy_will_call_apply_ticket_checkin_state([101,102,103],0,true);}catch(RuntimeException $e){$rejected=true;}
check($rejected && get_post_meta(103,'_roxy_checked_in')==1,'Will Call cannot undo QR admission');
$GLOBALS['orders'][1]->status='on-hold';
$rejected=false;try{roxy_will_call_apply_ticket_checkin_state([101],1);}catch(RuntimeException $e){$rejected=true;}
check($rejected && !get_post_meta(101,'_roxy_checked_in'),'unpaid tickets cannot be promoted to valid');
$GLOBALS['orders'][1]->status='processing';
$GLOBALS['orders'][2]=new WC_Order;$GLOBALS['orders'][2]->status='cancelled';
$GLOBALS['meta'][201]=$GLOBALS['meta'][101];$GLOBALS['meta'][201]['_roxy_ticket_order_id']=2;
$GLOBALS['meta'][202]=$GLOBALS['meta'][101];$GLOBALS['meta'][202]['_roxy_ticket_order_id']=999;
check(roxy_will_call_apply_ticket_checkin_state([201,202,101,102,103],1)===[103] && !get_post_meta(201,'_roxy_checked_in') && !get_post_meta(202,'_roxy_checked_in'),'same customer canceled/missing-order tickets do not displace paid QR identity');
unset($GLOBALS['orders'][2],$GLOBALS['meta'][201],$GLOBALS['meta'][202]);
$key=roxy_will_call_customer_key('Test Buyer','buyer@example.test');
check(roxy_will_call_authoritative_checkins(8,[$key=>['used_qty'=>0]])[$key]['used_qty']===1,'initial Used count includes actual QR admission');
$list=roxy_will_call_get_list([8],['8'=>'General'],true);
check($list['totals']['total_qty']===2 && abs($list['totals']['total_revenue']-13.2)<0.00001,'partial refunds reduce quantity and collected revenue including tax');
$saved_orders=$GLOBALS['orders']; $GLOBALS['orders']=[];
for($i=1;$i<=401;$i++){
    $order=new WC_Order;
    $order->status=$i===201?'completed':($i===2?'cancelled':($i===3?'on-hold':'processing'));
    $order->created=new FixtureDate($i===4?'2025-04-01 12:00:00':'2026-10-01 12:00:00');
    $order->billing_first='Buyer'.$i; $order->billing_last='Fixture'; $order->billing_email='buyer'.$i.'@example.test';
    if($i===1){$order->quantity=4;$order->line_total=24;$order->line_tax=2.4;}
    $GLOBALS['orders'][$i]=$order;
}
$GLOBALS['order_queries']=[];
$complete_list=roxy_will_call_get_list([8],['8'=>'General'],true);
check($complete_list['totals']['total_qty']===797 && $complete_list['totals']['order_count']===398 && abs($complete_list['totals']['total_revenue']-5260.2)<0.00001 && count($complete_list['rows'])===398,'Will Call reconciles distinct quantities, refunds, customers and excluded statuses/date across 401 orders');
check(count($GLOBALS['order_queries'])===2 && $GLOBALS['order_queries'][0]['limit']===200 && $GLOBALS['order_queries'][0]['paged']===1 && $GLOBALS['order_queries'][1]['paged']===2 && $GLOBALS['order_queries'][0]['orderby']==='ID' && $GLOBALS['order_queries'][0]['order']==='ASC' && $GLOBALS['order_queries'][0]['paginate']===true && $GLOBALS['order_queries'][0]['status']===['wc-processing','wc-completed'] && $GLOBALS['order_queries'][0]['return']==='ids' && strpos((string)$GLOBALS['order_queries'][0]['date_created'],'>')===0,'Will Call reads the complete eligible order set in stable bounded pages');
$GLOBALS['cache']=[]; $failed_forms=0;
foreach(['order_query_fail','order_query_throw'] as $failure_flag){
    $GLOBALS[$failure_flag]=true; $failed=false;
    try { roxy_will_call_get_list([8],['8'=>'General'],true); } catch (Throwable $e) { $failed=true; }
    unset($GLOBALS[$failure_flag]);
    if($failed && $GLOBALS['cache']===[]) $failed_forms++;
}
check($failed_forms===2,'false and thrown first-page order query failures are rejected before caching');
$GLOBALS['order_query_fail_page']=2;$GLOBALS['cache']=[];$failed=false;
try { roxy_will_call_get_list([8],['8'=>'General'],true); } catch (Throwable $e) { $failed=true; }
unset($GLOBALS['order_query_fail_page']);
check($failed && $GLOBALS['cache']===[],'later-page order query failure rejects the full Will Call list without caching partial results');
$GLOBALS['order_read_fail_id']=401;$GLOBALS['cache']=[];$failed=false;
try { roxy_will_call_get_list([8],['8'=>'General'],true); } catch (Throwable $e) { $failed=true; }
unset($GLOBALS['order_read_fail_id']);
check($failed && $GLOBALS['cache']===[],'order disappearing during page hydration rejects the full Will Call list without caching partial results');
$GLOBALS['order_read_throw_id']=401;$GLOBALS['cache']=[];$failed=false;
try { roxy_will_call_get_list([8],['8'=>'General'],true); } catch (Throwable $e) { $failed=true; }
unset($GLOBALS['order_read_throw_id']);
check($failed && $GLOBALS['cache']===[],'thrown order hydration rejects the full Will Call list without caching partial results');
$GLOBALS['order_bad_items_id']=401;$GLOBALS['cache']=[];$failed=false;
try { roxy_will_call_get_list([8],['8'=>'General'],true); } catch (Throwable $e) { $failed=true; }
unset($GLOBALS['order_bad_items_id']);
check($failed && $GLOBALS['cache']===[],'malformed order items reject the full Will Call list without caching partial results');
$GLOBALS['orders']=$saved_orders;
check(roxy_will_call_cache_key([8],[8=>'General'])!==roxy_will_call_cache_key([8],[8=>'Renamed']),'cache identity includes ticket labels');
$save=$GLOBALS['actions']['wp_ajax_roxy_will_call_save'];
$GLOBALS['meta']=[]; $GLOBALS['baseline']=0;
$_POST=['nonce'=>'test','context_id'=>8,'customer_key'=>$key,'used_qty'=>1,'baseline_used'=>'0','issued_at'=>(int)(microtime(true)*1000)];
try{$save();}catch(JsonResult $e){check($e->success,'valid legacy customer admits within paid unrefunded quantity');}
$_POST['baseline_used']='1';$_POST['used_qty']=0;
try{$save();}catch(JsonResult $e){check(!$e->success&&$GLOBALS['baseline']===1,'legacy decrease also requires explicit undo');}
$_POST['used_qty']=1;
$_POST['baseline_used']='99';$before=$GLOBALS['wpdb']->writes;
try{$save();}catch(JsonResult $e){check(!$e->success && $GLOBALS['wpdb']->writes===$before,'stale baseline rejected before attendance write');}
$_POST['baseline_used']='1';$_POST['issued_at']=((time()-3*HOUR_IN_SECONDS)*1000);
try{$save();}catch(JsonResult $e){check(!$e->success && $GLOBALS['wpdb']->writes===$before,'expired offline admission rejected');}
$_POST['issued_at']=(int)(microtime(true)*1000);$_POST['customer_key']='fake-customer';
try{$save();}catch(JsonResult $e){check(!$e->success && $GLOBALS['wpdb']->writes===$before,'unknown customer cannot create attendance');}
$_POST['customer_key']=$key;$GLOBALS['wpdb']->fail=true;
try{$save();}catch(JsonResult $e){check(!$e->success,'database failure does not report saved attendance');}
$GLOBALS['wpdb']->fail=false; $GLOBALS['order_query_fail']=true; $before=$GLOBALS['wpdb']->writes; $query_error=false;
try{$save();}catch(JsonResult $e){$query_error=!$e->success && $GLOBALS['wpdb']->writes===$before && strpos($e->data['message'],'No attendance was changed')!==false;}
check($query_error,'fresh order query failure returns a clear error without attendance writes');
unset($GLOBALS['order_query_fail']);
echo "NOTE: full ticket concurrency, legacy refund reconciliation and financial reports remain separate checks.\n";
