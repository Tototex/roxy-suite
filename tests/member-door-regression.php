<?php
// Real admission/validation methods with mocked WordPress, orders and member log.
define('ABSPATH',__DIR__);
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function get_post_type($id){return $id===50?'roxy_showing':'roxy_ticket';}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['meta'][$id][$key]=$value;return true;}
function get_posts($args){return $GLOBALS['reserved'];}
function get_current_user_id(){return 1;}
function get_the_title($id){return 'Fixture showing';}
function current_time($format){return '2026-10-03 12:00:00';}
function wc_get_order($id){return $GLOBALS['order'];}
function absint($value){return abs((int)$value);}
function wp_parse_url($url,$component){return parse_url($url,$component);}
function sanitize_text_field($value){return $value;}function wp_unslash($value){return $value;}
function roxy_suite_user_can_access_admin(){return true;}function check_ajax_referer(...$args){}
class JsonResult extends RuntimeException{public $payload;function __construct($payload){$this->payload=$payload;}}
function wp_send_json_success($payload){throw new JsonResult($payload);}
function wp_send_json_error($payload,$code=400){throw new RuntimeException($payload['message']);}
class Roxy_Sub_Check {
    static $logs=[]; static $fail=false;
    static function prepare_admission_log(){return true;}
    static function get_member_payload($id,$log=false){return ['found'=>true,'status'=>'valid','credential_type'=>'member','subscription_id'=>$id,'membership_qty'=>3,'customer_email'=>'fixture@example.test'];}
    static function log_member_visit($id,$show,$qty,$source,$writer=null){if(self::$fail)return ['ok'=>false,'message'=>'Fixture persistence failure'];if($writer)$writer([]);self::$logs[]=[$qty,$source];return ['ok'=>true,'payload'=>self::get_member_payload($id)+['admit_quantity'=>$qty]];}
    static function admitted_quantity_for_showing(...$args){return $GLOBALS['walkup']??0;}
}
class TestOrder {
    public $status='processing';public $refunded=0;
    function get_status(){return $this->status;}
    function get_item($id){return new class {function get_meta(...$args){return [101,102,103];}};}
    function get_qty_refunded_for_item($id){return -$this->refunded;}
}
eval('namespace RoxyST; class CPT {const POST_TYPE="roxy_showing";}');
$root=$argv[1]??dirname(__DIR__);
require __DIR__.'/ticket-atomic-test-double.php';
$code=preg_replace('/^<\?php\s*/','',file_get_contents($root.'/includes/modules/show-tickets/includes/class-roxy-st-tickets.php'),1);
// Stats computation is tested elsewhere; bypass only unrelated cache/stat calls.
$code=str_replace(['self::door_stats_payload($showing_id)','self::door_stats_payload($lock_showing_id)'],['[]','[]'],$code);
$code=str_replace(['self::invalidate_door_stats_cache($showing_id);','self::invalidate_door_stats_cache_for_ticket($ticket_id);'],'',$code);
eval($code);
$GLOBALS['order']=new TestOrder;$GLOBALS['reserved']=[101,102,103];
$reset=static function(){Roxy_Sub_Check::$logs=[];Roxy_Sub_Check::$fail=false;foreach([101,102,103] as $id)$GLOBALS['meta'][$id]=['_roxy_ticket_state'=>'valid','_roxy_ticket_order_id'=>1,'_roxy_ticket_order_item_id'=>10,'_roxy_ticket_showing_id'=>50,'_roxy_ticket_type'=>'subscriber','_roxy_ticket_customer_email'=>'fixture@example.test'];};
$admit=new ReflectionMethod(\RoxyST\Tickets::class,'member_admission_payload');$admit->setAccessible(true);
$reset();$GLOBALS['meta'][101]['_roxy_checked_in']=1;$GLOBALS['meta'][101]['_roxy_ticket_state']='checked_in';$GLOBALS['order']->refunded=1;
$result=$admit->invoke(null,1,50,3,'manual_admit');
check($result['ok']&&$result['payload']['admit_quantity']===1&&Roxy_Sub_Check::$logs[0][0]===1,'reserved admission logs only one actually changed, not three requested');
$reset();$GLOBALS['order']->status='cancelled';$result=$admit->invoke(null,1,50,3,'manual_admit');
check(!$result['ok']&&!Roxy_Sub_Check::$logs&&$result['payload']['admit_quantity']===0,'cancelled reservations cannot create phantom walk-up/log admission');
$GLOBALS['order']->status='processing';$GLOBALS['order']->refunded=0;$reset();
$_POST=['token'=>'1','lock_showing_id'=>50,'auto_admit'=>'0'];try{\RoxyST\Tickets::ajax_door_validate();}catch(JsonResult $json){$result=$json->payload;}
check(empty($result['admitted'])&&!Roxy_Sub_Check::$logs&&!get_post_meta(101,'_roxy_checked_in'),'Auto Admit off only verifies membership');
unset($_POST['auto_admit']);try{\RoxyST\Tickets::ajax_door_validate();}catch(JsonResult $json){$result=$json->payload;}
check(empty($result['admitted'])&&!Roxy_Sub_Check::$logs,'missing Auto Admit flag defaults to verification only');
$_POST['auto_admit']='1';try{\RoxyST\Tickets::ajax_door_validate();}catch(JsonResult $json){$result=$json->payload;}
check(!empty($result['admitted'])&&$result['admit_quantity']===1&&count(Roxy_Sub_Check::$logs)===1,'explicit Auto Admit on admits exactly one reserved member');
$result=$admit->invoke(null,1,50,1,'manual_admit');
check(!$result['ok']&&count(Roxy_Sub_Check::$logs)===1&&!get_post_meta(102,'_roxy_checked_in'),'repeat target-one scan does not admit another reservation');
$reset();Roxy_Sub_Check::$fail=true;$result=$admit->invoke(null,1,50,3,'manual_admit');
check(!$result['ok']&&!get_post_meta(101,'_roxy_checked_in')&&!get_post_meta(102,'_roxy_checked_in')&&!get_post_meta(103,'_roxy_checked_in'),'failed reserved log rolls back every ticket admission');
$reset();$GLOBALS['reserved']=[];$GLOBALS['walkup']=2;$result=$admit->invoke(null,1,50,3,'manual_admit');
check($result['ok']&&$result['payload']['admit_quantity']===1,'walk-up request capped at remaining membership quantity');
$GLOBALS['walkup']=0;Roxy_Sub_Check::$fail=true;$result=$admit->invoke(null,1,50,3,'manual_admit');
check(!$result['ok']&&$result['payload']['admit_quantity']===0,'failed member log reports zero admission');
echo "NOTE: unit transaction double; real persistence and concurrency require MySQL/Woo fixtures.\n";
