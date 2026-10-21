<?php
// Pure prize/claim fixtures: no actual membership, payment or email.
define('ABSPATH', __DIR__);
if(!defined('DB_NAME'))define('DB_NAME','fixture_database');
function add_action(...$args){}function wp_cache_delete(...$args){}function wp_generate_uuid4(){static $n=0;$n++;return sprintf('00000000-0000-4000-8000-%012d',$n);}
function wp_json_encode($v){return json_encode($v);}function is_wp_error($v){return $v instanceof WP_Error;}
function get_option($key,$default=false){if(array_key_exists($key,$GLOBALS['options']??[]))return $GLOBALS['options'][$key];return $GLOBALS['wpdb']->claims[$key]??$default;}
function update_option($key,$value,...$args){if(($GLOBALS['fail_option_write']??'')===$key)return false;$GLOBALS['options'][$key]=$value;return true;}
function wc_get_product_id_by_sku($sku){return 1;}function wc_get_product($id){return (object)['ID'=>1];}
function get_user_by($field,$id){return $id===9 ? (object)['ID'=>9] : false;}
class WooCommerce {} class WP_Error {}
class WC_Subscriptions_Product {static function is_subscription($p){return true;}static function get_period($p){return $GLOBALS['period']??'month';}static function get_interval($p){return 1;}}
class WC_Order_Item_Product {public $subtotal,$total,$taxes;function set_product($p){}function set_quantity($n){}function set_subtotal($v){$this->subtotal=$v;}function set_total($v){$this->total=$v;}function set_taxes($v){$this->taxes=$v;}}
function wcs_create_subscription($args){$GLOBALS['arcade_prize_fixture_create_attempt_log'][]=$args;$GLOBALS['create_args']=$args;$sub=$GLOBALS['create_error']??new PrizeSubscription;$sub->customer_id=(int)$args['customer_id'];$sub->created_via=(string)$args['created_via'];$GLOBALS['orphan_matches'][$sub->created_via][]=$sub;if(!empty($GLOBALS['nested_approval_month'])){$GLOBALS['nested_pending_saved']=($GLOBALS['create_args']['status']==='pending'&&$GLOBALS['orphan_matches'][$sub->created_via][0]===$sub);$month=$GLOBALS['nested_approval_month'];$owner_db=$GLOBALS['wpdb'];$contender=new PrizeDatabase;$contender->claims=&$owner_db->claims;$GLOBALS['wpdb']=$contender;$before_claim=$contender->claims['roxy_arcade_award_claim_'.$month]??null;$before_attempts=count($GLOBALS['arcade_prize_fixture_create_attempt_log']);$before_queries=count($GLOBALS['order_queries']);$GLOBALS['nested_approval_result']=$GLOBALS['once']->invoke(null,$month,9);$GLOBALS['nested_claim_unchanged']=($contender->claims['roxy_arcade_award_claim_'.$month]??null)===$before_claim;$GLOBALS['nested_create_unchanged']=count($GLOBALS['arcade_prize_fixture_create_attempt_log'])===$before_attempts;$GLOBALS['nested_recovery_unchanged']=count($GLOBALS['order_queries'])===$before_queries;$GLOBALS['wpdb']=$owner_db;unset($GLOBALS['nested_approval_month']);}return $sub;}
function wc_get_orders($args){$GLOBALS['order_queries'][]=$args;if(($GLOBALS['order_query_exception']??false))throw new RuntimeException('Fixture order query failure');if(array_key_exists('order_query_result',$GLOBALS))return $GLOBALS['order_query_result'];return $GLOBALS['orphan_matches'][$args['created_via']]??[];}
function wcs_add_time($interval,$period,$start){if($interval!==1||$period!=='month')throw new RuntimeException('Wrong time argument order');return $start+30*86400;}
class PrizeSubscription {
    public $item;public $customer_id=0,$created_via='',$activated=false;function get_id(){if(!empty($GLOBALS['throw_get_id_once'])){$GLOBALS['throw_get_id_once']=false;throw new RuntimeException('Simulated interruption after WCS creation');}return 55;}function get_type(){return 'shop_subscription';}function get_customer_id(){return $this->customer_id;}function get_created_via(){return $this->created_via;}function get_time($key){return 1791212400;}
    function add_item($item){$this->item=$item;$GLOBALS['item']=$item;}
    function set_requires_manual_renewal($v){$GLOBALS['manual']=$v;}function set_payment_method($v){$GLOBALS['payment']=$v;}
    function calculate_totals($taxes){if($taxes!==false)throw new RuntimeException('Unexpected tax recalculation');}
    function get_total(){return $GLOBALS['nonzero']??0;}function update_dates($v){$GLOBALS['dates']=$v;}
    function get_status(){return $this->activated?'active':'pending';}
    function save(){}function update_status($v,$note){if($GLOBALS['activation_fail']??false)return null;$this->activated=true;$GLOBALS['activated']=true;return null;}function add_order_note($n){}
}
class PrizeDatabase {
    private static $next_connection_id=100;public $connection_id,$options='fixture_options';public $claims=[];public $fail_insert=false,$fail_update=false,$fail_on_update=0,$commit_then_fail_on_update=0,$updates=0,$last_error='';
    function __construct(){$this->connection_id=++self::$next_connection_id;}
    function prepare($sql,...$params){return [$sql,$params];}
    function get_var($query){
        $this->last_error='';if(is_array($query)){[$sql,$params]=$query;}else{$sql=$query;$params=[];}
        if($sql==='SELECT CONNECTION_ID()')return $this->connection_id;
        // Parse the compound ownership checks before the individual lock
        // functions they contain; otherwise the fixture misreads parameters.
        if(strpos($sql,'SELECT IF(')===0){
            if(strpos($sql,'RELEASE_LOCK(')!==false){[$expected_id,$name,$expected_owner]=$params;$owner=$GLOBALS['fixture_named_locks'][$name]??null;if($this->connection_id!==(int)$expected_id||$owner!==(int)$expected_owner)return 0;unset($GLOBALS['fixture_named_locks'][$name]);return 1;}
            [$expected_id,$name,$expected_owner]=$params;return $this->connection_id===(int)$expected_id&&($GLOBALS['fixture_named_locks'][$name]??null)===(int)$expected_owner?1:0;
        }
        if(strpos($sql,'GET_LOCK(')!==false){$name=$params[0];$owner=$GLOBALS['fixture_named_locks'][$name]??null;if($owner!==null&&$owner!==$this->connection_id)return 0;$GLOBALS['fixture_named_locks'][$name]=$this->connection_id;return 1;}
        if(strpos($sql,'IS_USED_LOCK(')!==false&&strpos($sql,'RELEASE_LOCK(')===false)return $GLOBALS['fixture_named_locks'][$params[0]]??null;
        if(strpos($sql,'RELEASE_LOCK(')!==false){$name=$params[count($params)-1];$owner=$GLOBALS['fixture_named_locks'][$name]??null;if($owner!==$this->connection_id)return 0;unset($GLOBALS['fixture_named_locks'][$name]);return 1;}
        return null;
    }
    function query($query){[$sql,$params]=$query;
        if(str_starts_with($sql,'INSERT')){if($this->fail_insert)return false;[$key,$value]=$params;if(isset($this->claims[$key]))return 0;$this->claims[$key]=$value;return 1;}
        $this->updates++;if($this->fail_update||$this->updates===$this->fail_on_update)return false;[$value,$key,$expected]=$params;if(($this->claims[$key]??null)!==$expected)return 0;$this->claims[$key]=$value;return $this->updates===$this->commit_then_fail_on_update?false:1;
    }
}
$GLOBALS['wpdb']=new PrizeDatabase;$GLOBALS['arcade_prize_fixture_create_attempt_log']=[];$GLOBALS['options']=[];$GLOBALS['orphan_matches']=[];$GLOBALS['order_queries']=[];$GLOBALS['fixture_named_locks']=[];
require ($argv[1]??dirname(__DIR__)).'/includes/modules/arcade/roxy-arcade.php';
$once=new ReflectionMethod('Roxy_Arcade','award_once');
$GLOBALS['once']=$once;
function prize_check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$parse_claim=new ReflectionMethod('Roxy_Arcade','parse_award_claim_for_admin');
$valid_admin_claim=['state'=>'started','user_id'=>9,'subscription_id'=>0,'token'=>'11111111-2222-4333-8444-555555555555','created_via'=>'roxy_arcade_prize_11111111-2222-4333-8444-555555555555','started_at'=>'fixture'];
prize_check($parse_claim->invoke(null,null)===['claim'=>[],'corrupt'=>false],'absent monthly prize claim is displayed as no attempt');
prize_check($parse_claim->invoke(null,json_encode($valid_admin_claim))===['claim'=>$valid_admin_claim,'corrupt'=>false],'valid incomplete prize claim remains available for manager review');
$completed_admin_claim=array_replace($valid_admin_claim,['state'=>'completed','subscription_id'=>123,'completed_at'=>'2026-10-20 12:00:00']);
prize_check($parse_claim->invoke(null,json_encode($completed_admin_claim))===['claim'=>$completed_admin_claim,'corrupt'=>false],'valid completed prize claim remains visible and cannot be mistaken for a missing attempt');
foreach(['{invalid-json',json_encode(['state'=>'started','user_id'=>9,'subscription_id'=>0]),json_encode(array_replace($valid_admin_claim,['created_via'=>'roxy_arcade_prize_99999999-9999-4999-8999-999999999999'])),json_encode(array_replace($valid_admin_claim,['state'=>'unknown']))] as $invalid_admin_claim){
    prize_check($parse_claim->invoke(null,$invalid_admin_claim)===['claim'=>[],'corrupt'=>true],'malformed, incomplete, or unknown prize claim identity is surfaced as corrupt for manager investigation');
}
$lock_name_method=new ReflectionMethod('Roxy_Arcade','award_lock_name');$lock_helper=new ReflectionMethod('Roxy_Arcade','acquire_award_lock');$release_lock_method=new ReflectionMethod('Roxy_Arcade','release_award_lock');$lock_owner_method=new ReflectionMethod('Roxy_Arcade','award_lock_is_owned');$lock_name=$lock_name_method->invoke(null,'2700-01');
prize_check(strlen($lock_name)<=64&&strpos($lock_name,'roxy_arcade_award_')===0&&$lock_name===$lock_name_method->invoke(null,'2700-01'),'month advisory lock name is deterministic, namespaced, and within MySQL length');
$expected_lock_name='roxy_arcade_award_'.md5(DB_NAME.'|'.$GLOBALS['wpdb']->options.'|2700-01');
prize_check($lock_name===$expected_lock_name,'award lock identity includes database and claim table scope');
$original_options_table=$GLOBALS['wpdb']->options;$GLOBALS['wpdb']->options='another_site_options';$other_site_lock=$lock_name_method->invoke(null,'2700-01');$GLOBALS['wpdb']->options=$original_options_table;
prize_check($other_site_lock!==$lock_name,'award lock scope separates sites sharing a MySQL server');
$guard_month='2701-08';$guard_lock=$lock_helper->invoke(null,$guard_month,$GLOBALS['wpdb']);$misdirected_guard=$guard_lock;$misdirected_guard['db']=new PrizeDatabase;$release_lock_method->invoke(null,$misdirected_guard);
prize_check($lock_helper->invoke(null,$guard_month,$GLOBALS['wpdb'])===false&&$lock_owner_method->invoke(null,$guard_lock),'misdirected release keeps the original same-process connection guard');
$guard_name=$lock_name_method->invoke(null,$guard_month);unset($GLOBALS['fixture_named_locks'][$guard_name]);$release_lock_method->invoke(null,$guard_lock);$reopened_guard=$lock_helper->invoke(null,$guard_month,$GLOBALS['wpdb']);
prize_check(is_array($reopened_guard)&&$lock_owner_method->invoke(null,$reopened_guard),'confirmed lost server ownership clears the stale local guard for safe reacquisition');$release_lock_method->invoke(null,$reopened_guard);
$busy_month='2701-11';$busy_name=$lock_name_method->invoke(null,$busy_month);$GLOBALS['fixture_named_locks'][$busy_name]=99999;$busy_attempts=count($GLOBALS['arcade_prize_fixture_create_attempt_log']);$busy_queries=count($GLOBALS['order_queries']);
prize_check($once->invoke(null,$busy_month,9)===0&&!isset($GLOBALS['wpdb']->claims['roxy_arcade_award_claim_'.$busy_month])&&count($GLOBALS['arcade_prize_fixture_create_attempt_log'])===$busy_attempts&&count($GLOBALS['order_queries'])===$busy_queries,'contended month lock fails closed before claim mutation, recovery lookup, or subscription creation');unset($GLOBALS['fixture_named_locks'][$busy_name]);
prize_check($lock_helper->invoke(null,'2701-12',new stdClass)===false,'unsupported lock database fails closed');
prize_check($once->invoke(null,'2700-01',9)===55,'first monthly claim creates prize');
$claim=json_decode($GLOBALS['wpdb']->claims['roxy_arcade_award_claim_2700-01'],true);
prize_check($claim['state']==='completed'&&$claim['subscription_id']===55,'completed claim retains subscription identity');
prize_check($GLOBALS['create_args']['status']==='pending'&&$GLOBALS['create_args']['billing_period']==='month','installed product API supplies pending subscription terms');
prize_check($GLOBALS['item']->subtotal===0&&$GLOBALS['item']->total===0&&$GLOBALS['item']->taxes===['subtotal'=>[],'total'=>[]],'actual prize line amounts and taxes are zero');
prize_check($GLOBALS['manual']===true&&$GLOBALS['payment']===''&&$GLOBALS['dates']['next_payment']===0,'no automatic renewal or payment method');
prize_check((new DateTimeImmutable($GLOBALS['dates']['end'],new DateTimeZone('UTC')))->getTimestamp()===1791212400+30*86400,'correct interval/period order produces one-period expiry');
$subscription_attempts=static function(){return count($GLOBALS['arcade_prize_fixture_create_attempt_log']);};
prize_check($once->invoke(null,'2700-01',9)===0&&$subscription_attempts()===1,'completed claim cannot create a second prize');
prize_check($once->invoke(null,'2700-01',10)===0&&$subscription_attempts()===1,'changed winner cannot bypass monthly claim');
$GLOBALS['wpdb']->fail_insert=true;prize_check($once->invoke(null,'2700-02',9)===0&&$subscription_attempts()===1,'claim write failure creates no subscription');$GLOBALS['wpdb']->fail_insert=false;
$GLOBALS['wpdb']->fail_update=true;$GLOBALS['activated']=false;
prize_check($once->invoke(null,'2700-03',9)===0&&!$GLOBALS['activated'],'identity persistence failure prevents activation');
$GLOBALS['wpdb']->fail_update=false;$attempts_before_retry=$subscription_attempts();
prize_check($once->invoke(null,'2700-03',9)===0&&$subscription_attempts()===$attempts_before_retry,'uncertain started claim is never retried; before='.$attempts_before_retry.', after='.$subscription_attempts());
$GLOBALS['activation_fail']=true;prize_check($once->invoke(null,'2700-04',9)===0,'activation failure reports no successful award');unset($GLOBALS['activation_fail']);
$claim=json_decode($GLOBALS['wpdb']->claims['roxy_arcade_award_claim_2700-04'],true);
prize_check($claim['state']==='needs_review'&&$claim['subscription_id']===55,'failed activation retains exact identity for review');
$GLOBALS['activated']=false;$attempts_before_interleave=$subscription_attempts();$GLOBALS['nested_approval_month']='2701-10';
prize_check($once->invoke(null,'2701-10',9)===55,'first worker completes normally after a nested approval contends for its month lock');
$interleave_key='roxy_arcade_award_claim_2701-10';$interleave_claim=json_decode($GLOBALS['wpdb']->claims[$interleave_key],true);
prize_check($GLOBALS['nested_pending_saved']===true&&($GLOBALS['nested_approval_result']??null)===0&&$GLOBALS['nested_claim_unchanged']===true&&$GLOBALS['nested_create_unchanged']===true&&$GLOBALS['nested_recovery_unchanged']===true,'contending approval after pending WCS save cannot inspect/recover or mutate the claim, or create another subscription');
prize_check($subscription_attempts()===$attempts_before_interleave+1&&$interleave_claim['state']==='completed'&&$interleave_claim['subscription_id']===55&&$GLOBALS['activated'],'original worker persists identity, activates, and finalizes exactly once');
$GLOBALS['activated']=false;$GLOBALS['throw_get_id_once']=true;$attempts_before_crash=$subscription_attempts();
prize_check($once->invoke(null,'2700-12',9)===0&&$subscription_attempts()===$attempts_before_crash+1,'simulated crash after WCS creation does not report an award');
$crash_key='roxy_arcade_award_claim_2700-12';$crash_claim=json_decode($GLOBALS['wpdb']->claims[$crash_key],true);$orphan=$GLOBALS['orphan_matches'][$crash_claim['created_via']][0];
prize_check($crash_claim['state']==='needs_review'&&$crash_claim['subscription_id']===0&&!$GLOBALS['activated'],'interrupted creation leaves a reviewable claim without activating the orphan');
$queries_before_recovery=count($GLOBALS['order_queries']);$attempts_before_recovery=$subscription_attempts();
prize_check($once->invoke(null,'2700-12',9)===0&&$subscription_attempts()===$attempts_before_recovery,'retry recovers orphan without creating a duplicate');
$crash_claim=json_decode($GLOBALS['wpdb']->claims[$crash_key],true);
prize_check($crash_claim['state']==='needs_review'&&$crash_claim['subscription_id']===55&&$crash_claim['created_via']===$orphan->created_via,'unique orphan identity is persisted only as needs_review');
prize_check(count($GLOBALS['order_queries'])===$queries_before_recovery+1&&$GLOBALS['order_queries'][array_key_last($GLOBALS['order_queries'])]===['type'=>'shop_subscription','status'=>'all','created_via'=>$orphan->created_via,'customer_id'=>9,'limit'=>2,'return'=>'objects'],'recovery uses bounded Woo CRUD query by subscription type, marker, and customer');
$GLOBALS['activated']=false;prize_check(!$orphan->activated&&!$GLOBALS['activated'],'orphan recovery never activates the subscription');
$seed_claim=static function($month,$user_id=9,$state='started',$id=0){$key='roxy_arcade_award_claim_'.$month;$marker='roxy_arcade_prize_'.wp_generate_uuid4();$claim=['state'=>$state,'user_id'=>$user_id,'subscription_id'=>$id,'token'=>substr($marker,18),'created_via'=>$marker,'started_at'=>'fixture'];$GLOBALS['wpdb']->claims[$key]=json_encode($claim);return [$key,$claim];};
[$no_match_key,$no_match_claim]=$seed_claim('2701-01');$attempts_before_no_match=$subscription_attempts();
prize_check($once->invoke(null,'2701-01',9)===0&&$subscription_attempts()===$attempts_before_no_match&&json_decode($GLOBALS['wpdb']->claims[$no_match_key],true)['subscription_id']===0,'no orphan match fails closed without creating or attaching a subscription');
[$ambiguous_key,$ambiguous_claim]=$seed_claim('2701-02');$ambiguous_a=new PrizeSubscription;$ambiguous_a->created_via=$ambiguous_claim['created_via'];$ambiguous_a->customer_id=9;
$ambiguous_b=new PrizeSubscription;$ambiguous_b->created_via=$ambiguous_claim['created_via'];$ambiguous_b->customer_id=9;$GLOBALS['orphan_matches'][$ambiguous_claim['created_via']]=[$ambiguous_a,$ambiguous_b];$attempts_before_ambiguous=$subscription_attempts();
prize_check($once->invoke(null,'2701-02',9)===0&&$subscription_attempts()===$attempts_before_ambiguous&&json_decode($GLOBALS['wpdb']->claims[$ambiguous_key],true)['subscription_id']===0,'multiple marker matches fail closed without creating or attaching a subscription');
foreach(['exception','wp_error','malformed','malformed_object','wrong_customer','wrong_marker'] as $failure){$failure_month=sprintf('2701-%02d',3+array_search($failure,['exception','wp_error','malformed','malformed_object','wrong_customer','wrong_marker'],true));[$failure_key,$failure_claim]=$seed_claim($failure_month);$match=new PrizeSubscription;$match->created_via=$failure_claim['created_via'];$match->customer_id=9;$GLOBALS['orphan_matches'][$failure_claim['created_via']]=[$match];
    unset($GLOBALS['order_query_result']);$GLOBALS['order_query_exception']=false;
    if($failure==='exception')$GLOBALS['order_query_exception']=true;
    elseif($failure==='wp_error')$GLOBALS['order_query_result']=new WP_Error;
    elseif($failure==='malformed')$GLOBALS['order_query_result']=false;
    elseif($failure==='malformed_object'){$bad=new stdClass;$GLOBALS['order_query_result']=[$bad];}
    elseif($failure==='wrong_customer'){$match->customer_id=10;$GLOBALS['order_query_result']=[$match];}
    elseif($failure==='wrong_marker'){$match->created_via='roxy_arcade_prize_00000000-0000-4000-8000-999999999999';$GLOBALS['order_query_result']=[$match];}
    $attempts_before_failure=$subscription_attempts();$queries_before_failure=count($GLOBALS['order_queries']);$GLOBALS['activated']=false;
    $failure_result=$once->invoke(null,$failure_month,9);
    prize_check(count($GLOBALS['order_queries'])===$queries_before_failure+1,'failure case '.$failure.' reached the Woo order query with valid month '.$failure_month);
    prize_check($failure_result===0&&$subscription_attempts()===$attempts_before_failure&&!$GLOBALS['activated']&&json_decode($GLOBALS['wpdb']->claims[$failure_key],true)['subscription_id']===0,'query '.$failure.' fails closed without creating, attaching, or activating a subscription');
}
unset($GLOBALS['order_query_result'],$GLOBALS['order_query_exception']);
[$write_failure_key,$write_failure_claim]=$seed_claim('2701-09');$write_orphan=new PrizeSubscription;$write_orphan->created_via=$write_failure_claim['created_via'];$write_orphan->customer_id=9;$GLOBALS['orphan_matches'][$write_failure_claim['created_via']]=[$write_orphan];$GLOBALS['wpdb']->fail_update=true;
prize_check($once->invoke(null,'2701-09',9)===0&&json_decode($GLOBALS['wpdb']->claims[$write_failure_key],true)['subscription_id']===0,'recovered-ID persistence failure leaves the claim unlinked and reviewable');$GLOBALS['wpdb']->fail_update=false;
$GLOBALS['period']='invalid';$attempts_before_invalid_period=$subscription_attempts();prize_check($once->invoke(null,'2700-05',9)===0&&$subscription_attempts()===$attempts_before_invalid_period,'invalid product billing terms create no subscription');unset($GLOBALS['period']);
prize_check($once->invoke(null,'bad',9)===0,'invalid month refuses claim');
$GLOBALS['wpdb']->updates=0;$GLOBALS['wpdb']->fail_on_update=2;
prize_check($once->invoke(null,'2700-06',9)===0,'completion write failure does not report successful award');
$GLOBALS['wpdb']->fail_on_update=0;$attempts_before_retry=$subscription_attempts();
prize_check($once->invoke(null,'2700-06',9)===0&&$subscription_attempts()===$attempts_before_retry,'activated but unfinalized claim blocks duplicate retry');
$GLOBALS['wpdb']->updates=0;$GLOBALS['wpdb']->commit_then_fail_on_update=1;$GLOBALS['activated']=false;$attempts_before_first_attempt=$subscription_attempts();
prize_check($once->invoke(null,'2700-07',9)===0&&!$GLOBALS['activated'],'lost acknowledgement after identity commit prevents prize activation');
$attempts_after_first_attempt=$subscription_attempts();
prize_check($attempts_after_first_attempt===$attempts_before_first_attempt+1,'2700-07 first attempt makes one subscription call; key=roxy_arcade_award_claim_2700-07, before='.$attempts_before_first_attempt.', after='.$attempts_after_first_attempt);
$retry_claim_key='roxy_arcade_award_claim_2700-07';
$claim=json_decode($GLOBALS['wpdb']->claims[$retry_claim_key]??'',true);
prize_check($claim['state']==='started'&&$claim['subscription_id']===55,'committed identity remains attached to the blocked claim after lost acknowledgement; key='.$retry_claim_key.', claim='.json_encode($claim));
$GLOBALS['wpdb']->commit_then_fail_on_update=0;
prize_check($once->invoke(null,'2700-07',9)===0&&$subscription_attempts()===$attempts_after_first_attempt,'identity commit with lost acknowledgement cannot create a duplicate prize on retry; key='.$retry_claim_key.', attempts_before_retry='.$attempts_after_first_attempt.', after_retry='.$subscription_attempts());
$GLOBALS['wpdb']->updates=0;$GLOBALS['wpdb']->commit_then_fail_on_update=2;$GLOBALS['activated']=false;$attempts_before_first_attempt=$subscription_attempts();
prize_check($once->invoke(null,'2700-08',9)===0&&$GLOBALS['activated'],'completed-claim acknowledgement loss reports uncertain result after activation');
$attempts_after_first_attempt=$subscription_attempts();
prize_check($attempts_after_first_attempt===$attempts_before_first_attempt+1,'2700-08 first attempt makes one subscription call; key=roxy_arcade_award_claim_2700-08, before='.$attempts_before_first_attempt.', after='.$attempts_after_first_attempt);
$retry_claim_key='roxy_arcade_award_claim_2700-08';
$claim=json_decode($GLOBALS['wpdb']->claims[$retry_claim_key]??'',true);
prize_check($claim['state']==='completed'&&$claim['subscription_id']===55,'committed completed claim preserves prize identity after lost acknowledgement');
$GLOBALS['wpdb']->commit_then_fail_on_update=0;
prize_check($once->invoke(null,'2700-08',9)===0&&$subscription_attempts()===$attempts_after_first_attempt,'completed claim after lost acknowledgement cannot award twice; key='.$retry_claim_key.', attempts_before_retry='.$attempts_after_first_attempt.', after_retry='.$subscription_attempts());
$approve=new ReflectionMethod('Roxy_Arcade','approve_review_candidate');
$GLOBALS['options']['roxy_arcade_last_review_candidate']=['month'=>'2700-09','user_id'=>9,'name'=>'Fixture Player','score'=>100,'queued_at'=>'fixture'];
$reconnect_claim_key='roxy_arcade_award_claim_2700-09';
$reconnect_claim_before=get_option($reconnect_claim_key,[]);
$GLOBALS['fail_option_write']='roxy_arcade_last_awarded_month';$subscription_calls_before=$subscription_attempts();
$result=$approve->invoke(null);
prize_check(empty($result['ok']),'settings reconnect failure returns incomplete summary; result='.json_encode($result));
prize_check($reconnect_claim_before===[],'settings reconnect fixture starts with no prior month claim; prior='.json_encode($reconnect_claim_before));
$claim=json_decode($GLOBALS['wpdb']->claims[$reconnect_claim_key]??'',true);
prize_check($subscription_attempts()===$subscription_calls_before+1&&$claim['state']==='completed','settings reconnect failure completes exactly one new prize; attempts_before='.$subscription_calls_before.', attempts_after='.$subscription_attempts().', claim='.json_encode($claim));
prize_check($claim['state']==='completed'&&$claim['user_id']===9&&$claim['subscription_id']===55&&$GLOBALS['options']['roxy_arcade_last_review_candidate']['month']==='2700-09','completed claim and candidate remain available for metadata recovery');
unset($GLOBALS['fail_option_write']);$result=$approve->invoke(null);
prize_check(!empty($result['ok'])&&$subscription_attempts()===$subscription_calls_before+1,'retry reconciles completed claim without creating another subscription');
prize_check($GLOBALS['options']['roxy_arcade_last_awarded_month']==='2700-09'&&$GLOBALS['options']['roxy_arcade_last_winner_user_id']===9&&$GLOBALS['options']['roxy_arcade_last_winner_sub_id']===55&&$GLOBALS['options']['roxy_arcade_last_review_candidate']===[],'recovery persists award summary and clears candidate only after verified writes');
$GLOBALS['options']['roxy_arcade_last_review_candidate']=['month'=>'2700-10','user_id'=>9,'name'=>'Fixture Player','score'=>101,'queued_at'=>'fixture'];
$GLOBALS['options']['roxy_arcade_last_winner_user_id']=8;
$GLOBALS['fail_option_write']='roxy_arcade_last_winner_user_id';$subscription_calls_before=$subscription_attempts();
$result=$approve->invoke(null);
prize_check(empty($result['ok']),'winner metadata write failure returns incomplete summary; result='.json_encode($result));
prize_check($subscription_attempts()===$subscription_calls_before+1,'winner metadata write failure creates one prize; attempts_before='.$subscription_calls_before.', attempts_after='.$subscription_attempts());
prize_check($GLOBALS['options']['roxy_arcade_last_awarded_month']==='2700-10'&&$GLOBALS['options']['roxy_arcade_last_winner_user_id']===8,'winner metadata write failure leaves month committed and stale winner detectable; month='.json_encode($GLOBALS['options']['roxy_arcade_last_awarded_month']??null).', winner='.json_encode($GLOBALS['options']['roxy_arcade_last_winner_user_id']??null));
unset($GLOBALS['fail_option_write']);$result=$approve->invoke(null);
prize_check(!empty($result['ok'])&&$subscription_attempts()===$subscription_calls_before+1,'retry after month-marker commit reconciles remaining award metadata without duplicate subscription');
prize_check($GLOBALS['options']['roxy_arcade_last_winner_user_id']===9&&$GLOBALS['options']['roxy_arcade_last_winner_sub_id']===55&&$GLOBALS['options']['roxy_arcade_last_review_candidate']===[],'partial-write recovery finishes winner identity and clears review candidate');
$GLOBALS['options']['roxy_arcade_last_review_candidate']=['month'=>'2700-11','user_id'=>9,'name'=>'Fixture Player','score'=>102,'queued_at'=>'fixture'];
$GLOBALS['fail_option_write']='roxy_arcade_last_review_candidate';$subscription_calls_before=$subscription_attempts();
$result=$approve->invoke(null);
prize_check(empty($result['ok'])&&$subscription_attempts()===$subscription_calls_before+1&&$GLOBALS['options']['roxy_arcade_last_review_candidate']['month']==='2700-11','candidate-clear failure keeps the completed claim available for recovery');
unset($GLOBALS['fail_option_write']);$result=$approve->invoke(null);
prize_check(!empty($result['ok'])&&$subscription_attempts()===$subscription_calls_before+1&&$GLOBALS['options']['roxy_arcade_last_review_candidate']===[],'retry after candidate-clear failure completes without another subscription');
