<?php
// Pure prize/claim fixtures: no actual membership, payment or email.
define('ABSPATH', __DIR__);
function add_action(...$args){}function wp_cache_delete(...$args){}function wp_generate_uuid4(){return 'fixture-token';}
function wp_json_encode($v){return json_encode($v);}function is_wp_error($v){return $v instanceof WP_Error;}
function get_option($key,$default=false){if(array_key_exists($key,$GLOBALS['options']??[]))return $GLOBALS['options'][$key];return $GLOBALS['wpdb']->claims[$key]??$default;}
function update_option($key,$value,...$args){if(($GLOBALS['fail_option_write']??'')===$key)return false;$GLOBALS['options'][$key]=$value;return true;}
function wc_get_product_id_by_sku($sku){return 1;}function wc_get_product($id){return (object)['ID'=>1];}
function get_user_by($field,$id){return $id===9 ? (object)['ID'=>9] : false;}
class WooCommerce {} class WP_Error {}
class WC_Subscriptions_Product {static function is_subscription($p){return true;}static function get_period($p){return $GLOBALS['period']??'month';}static function get_interval($p){return 1;}}
class WC_Order_Item_Product {public $subtotal,$total,$taxes;function set_product($p){}function set_quantity($n){}function set_subtotal($v){$this->subtotal=$v;}function set_total($v){$this->total=$v;}function set_taxes($v){$this->taxes=$v;}}
function wcs_create_subscription($args){$GLOBALS['arcade_prize_fixture_create_attempt_log'][]=$args;$GLOBALS['create_args']=$args;return $GLOBALS['create_error']??new PrizeSubscription;}
function wcs_add_time($interval,$period,$start){if($interval!==1||$period!=='month')throw new RuntimeException('Wrong time argument order');return $start+30*86400;}
class PrizeSubscription {
    public $item;function get_id(){return 55;}function get_time($key){return 1791212400;}
    function add_item($item){$this->item=$item;$GLOBALS['item']=$item;}
    function set_requires_manual_renewal($v){$GLOBALS['manual']=$v;}function set_payment_method($v){$GLOBALS['payment']=$v;}
    function calculate_totals($taxes){if($taxes!==false)throw new RuntimeException('Unexpected tax recalculation');}
    function get_total(){return $GLOBALS['nonzero']??0;}function update_dates($v){$GLOBALS['dates']=$v;}
    function save(){}function update_status($v,$note){$GLOBALS['activated']=true;return !($GLOBALS['activation_fail']??false);}function add_order_note($n){}
}
class PrizeDatabase {
    public $options='fixture_options';public $claims=[];public $fail_insert=false,$fail_update=false,$fail_on_update=0,$commit_then_fail_on_update=0,$updates=0;
    function prepare($sql,...$params){return [$sql,$params];}
    function query($query){[$sql,$params]=$query;
        if(str_starts_with($sql,'INSERT')){if($this->fail_insert)return false;[$key,$value]=$params;if(isset($this->claims[$key]))return 0;$this->claims[$key]=$value;return 1;}
        $this->updates++;if($this->fail_update||$this->updates===$this->fail_on_update)return false;[$value,$key,$expected]=$params;if(($this->claims[$key]??null)!==$expected)return 0;$this->claims[$key]=$value;return $this->updates===$this->commit_then_fail_on_update?false:1;
    }
}
$GLOBALS['wpdb']=new PrizeDatabase;$GLOBALS['arcade_prize_fixture_create_attempt_log']=[];$GLOBALS['options']=[];
require ($argv[1]??dirname(__DIR__)).'/includes/modules/arcade/roxy-arcade.php';
$once=new ReflectionMethod('Roxy_Arcade','award_once');
function prize_check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
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
$GLOBALS['fail_option_write']='roxy_arcade_last_winner_user_id';$subscription_calls_before=$subscription_attempts();
$result=$approve->invoke(null);
prize_check(empty($result['ok'])&&$subscription_attempts()===$subscription_calls_before+1&&$GLOBALS['options']['roxy_arcade_last_awarded_month']==='2700-10','partial summary write leaves completed claim recoverable after month marker saved');
unset($GLOBALS['fail_option_write']);$result=$approve->invoke(null);
prize_check(!empty($result['ok'])&&$subscription_attempts()===$subscription_calls_before+1,'retry after month-marker commit reconciles remaining award metadata without duplicate subscription');
prize_check($GLOBALS['options']['roxy_arcade_last_winner_user_id']===9&&$GLOBALS['options']['roxy_arcade_last_winner_sub_id']===55&&$GLOBALS['options']['roxy_arcade_last_review_candidate']===[],'partial-write recovery finishes winner identity and clears review candidate');
$GLOBALS['options']['roxy_arcade_last_review_candidate']=['month'=>'2700-11','user_id'=>9,'name'=>'Fixture Player','score'=>102,'queued_at'=>'fixture'];
$GLOBALS['fail_option_write']='roxy_arcade_last_review_candidate';$subscription_calls_before=$subscription_attempts();
$result=$approve->invoke(null);
prize_check(empty($result['ok'])&&$subscription_attempts()===$subscription_calls_before+1&&$GLOBALS['options']['roxy_arcade_last_review_candidate']['month']==='2700-11','candidate-clear failure keeps the completed claim available for recovery');
unset($GLOBALS['fail_option_write']);$result=$approve->invoke(null);
prize_check(!empty($result['ok'])&&$subscription_attempts()===$subscription_calls_before+1&&$GLOBALS['options']['roxy_arcade_last_review_candidate']===[],'retry after candidate-clear failure completes without another subscription');
