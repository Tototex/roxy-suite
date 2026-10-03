<?php
// Standalone admission/refund/queue checks; no live orders, database or emails.
define('ABSPATH', __DIR__); define('HOUR_IN_SECONDS',3600);
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function add_action($name,$callback,...$args){$GLOBALS['actions'][$name]=$callback;}
function add_filter(...$args){} function register_activation_hook(...$args){}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['meta'][$id][$key]=$value;return true;}
function delete_post_meta($id,$key){unset($GLOBALS['meta'][$id][$key]);return true;}
function get_post_type($id){return isset($GLOBALS['meta'][$id])?'roxy_ticket':'';}
function get_current_user_id(){return 9;} function current_time($format){return '2026-10-03 10:00:00';}
function wc_get_order($id){return $GLOBALS['orders'][$id]??false;}
function wc_get_orders($args){return array_keys($GLOBALS['orders']);}
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
    public $status='processing'; public $refund=1;
    function get_status(){return $this->status;}
    function get_items($type='line_item'){return [10=>new TestItem];}
    function get_item($id){return $id===10?new TestItem:false;}
    function get_qty_refunded_for_item($id){return -$this->refund;}
    function get_total_refunded_for_item($id){return $this->refund*6;}
    function get_tax_refunded_for_item($id,$tax_id){return $this->refund*0.6;}
    function get_billing_first_name(){return 'Test';} function get_billing_last_name(){return 'Buyer';}
    function get_billing_email(){return 'buyer@example.test';} function get_date_created(){return null;}
}
class TestItem {
    function get_id(){return 10;} function get_product_id(){return 8;} function get_variation_id(){return 0;}
    function get_quantity(){return 3;} function get_total(){return 18;} function get_total_tax(){return 1.8;}
    function get_taxes(){return ['total'=>[1=>1.8]];} function get_meta($key,$single){return [101,102,103];}
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
require $root.'/includes/modules/show-tickets/includes/class-roxy-st-tickets.php';
require $root.'/includes/modules/will-call/roxy-will-call.php';
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
check(roxy_will_call_cache_key([8],[8=>'General'])!==roxy_will_call_cache_key([8],[8=>'Renamed']),'cache identity includes ticket labels');
$save=$GLOBALS['actions']['wp_ajax_roxy_will_call_save'];
$GLOBALS['meta']=[]; $GLOBALS['baseline']=0;
$_POST=['nonce'=>'test','context_id'=>8,'customer_key'=>$key,'used_qty'=>1,'baseline_used'=>'0','issued_at'=>(int)(microtime(true)*1000)];
try{$save();}catch(JsonResult $e){check($e->success,'valid legacy customer admits within paid unrefunded quantity');}
$_POST['baseline_used']='99';$before=$GLOBALS['wpdb']->writes;
try{$save();}catch(JsonResult $e){check(!$e->success && $GLOBALS['wpdb']->writes===$before,'stale baseline rejected before attendance write');}
$_POST['baseline_used']='0';$_POST['issued_at']=((time()-3*HOUR_IN_SECONDS)*1000);
try{$save();}catch(JsonResult $e){check(!$e->success && $GLOBALS['wpdb']->writes===$before,'expired offline admission rejected');}
$_POST['issued_at']=(int)(microtime(true)*1000);$_POST['customer_key']='fake-customer';
try{$save();}catch(JsonResult $e){check(!$e->success && $GLOBALS['wpdb']->writes===$before,'unknown customer cannot create attendance');}
$_POST['customer_key']=$key;$GLOBALS['wpdb']->fail=true;
try{$save();}catch(JsonResult $e){check(!$e->success,'database failure does not report saved attendance');}
echo "NOTE: full ticket concurrency, legacy refund reconciliation and financial reports remain separate checks.\n";
