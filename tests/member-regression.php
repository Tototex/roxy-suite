<?php
// Isolated member lookup/admission/navigation regression; no actual memberships.
define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function get_option($key,$default=false){return ['roxy_member_scans_schema_version'=>'1','date_format'=>'Y-m-d','time_format'=>'H:i'][$key]??$default;}
function update_option(...$args){} function add_action(...$args){}function add_filter(...$args){}function register_activation_hook(...$args){}
function wp_timezone(){return new DateTimeZone('America/Los_Angeles');}
function wp_date($format,$timestamp=null,$timezone=null){return (new DateTimeImmutable('@'.($timestamp??1791010800)))->setTimezone($timezone??wp_timezone())->format($format);}
function current_time($format){return '2026-10-03 10:00:00';}function date_i18n($fmt,$timestamp){return date($fmt,$timestamp);}
function absint($value){return abs((int)$value);}function get_post_meta(...$args){return '';}
function get_user_meta($id,$key,$single=true){return $key==='first_name'?'Fixture':'Member';}
function wcs_get_subscription($id){return new TestSubscription($id);}
function wcs_get_subscriptions($args){$GLOBALS['member_search_queries'][]=$args;if(!empty($GLOBALS['member_search_fail']))throw new RuntimeException('fixture query failure');$ids=range(101,1);$offset=((int)($args['paged']??1)-1)*(int)($args['subscriptions_per_page']??100);return array_map(static fn($id)=>new TestSubscription($id),array_slice($ids,$offset,(int)($args['subscriptions_per_page']??100)));}
function home_url($path=''){return 'https://example.test'.$path;}
function admin_url($path=''){return 'https://example.test/wp-admin/'.$path;}
function wp_nonce_url($url,$action){return $url.'&_wpnonce=test';}
function add_query_arg($key,$value=null,$url=null){if(is_array($key))return $value.(str_contains($value,'?')?'&':'?').http_build_query($key);return $url.(str_contains($url,'?')?'&':'?').http_build_query([$key=>$value]);}
function esc_html($v){return htmlspecialchars((string)$v);}function esc_attr($v){return htmlspecialchars((string)$v);}function esc_url($v){return (string)$v;}
function sanitize_key($v){return (string)$v;}function sanitize_text_field($v){return (string)$v;}function wp_unslash($v){return $v;}
function roxy_suite_user_can_access_admin(){return true;}
function get_the_author_meta(...$args){return 'Fixture staff';}
class TestSubscription {
    private $id;function __construct($id){$this->id=$id;}
    function get_id(){return (int)$this->id;}
    function get_user(){return (object)['ID'=>8,'user_email'=>'fixture'.$this->id.'@example.test','display_name'=>'Fixture Member'];}
    function get_status(){return $this->id===2?'cancelled':($this->id===3?'expired':'active');}
    function get_billing_email(){return 'billing'.$this->id.'@example.test';}
    function get_billing_first_name(){return 'Fixture';}function get_billing_last_name(){return 'Member';}
    function get_meta($key,$single=true){return $key==='_roxy_member_photo_id'?0:0;}
    function update_meta_data($key,$value){}function delete_meta_data($key){}function save(){return $this->id;}
    function get_items(){return [new class {function get_quantity(){return 3;}}];}
    function get_date($key){return '';}
}
class TestDatabase {
    public $prefix='test_';public $last_error='';public $queries=[];public $inserts=[];public $fail=false;
    function esc_like($value){return str_replace('_','\\_',$value);}
    function prepare($sql,...$args){return $sql;}
    function get_var($sql){$this->queries[]=$sql;if(str_contains($sql,'SHOW TABLES'))return 'test_roxy_member_scans';if(str_contains($sql,'COUNT'))return 1;return '2026-09-28 19:30:00';}
    function get_results($sql,$format){$this->queries[]=$sql;if(str_contains($sql,'GROUP BY subscription_id, user_id'))return [['subscription_id'=>(int)($GLOBALS['history_subscription_id']??2),'user_id'=>8,'scanned_at'=>'2026-09-28 19:30:00','quantity'=>3]];if(str_contains($sql,'GROUP BY subscription_id'))return [['subscription_id'=>1,'visits_month'=>3,'visits_lifetime'=>6,'last_visit'=>'2026-09-28 19:30:00']];return [['id'=>1,'scanned_at'=>'2026-09-28 19:30:00','subscription_id'=>1,'is_active'=>1,'status'=>'active','user_id'=>8,'ip'=>'','user_agent'=>'']];}
    function insert($table,$data,$formats){if($this->fail)return false;$this->inserts[]=$data;return 1;}
}
$GLOBALS['wpdb']=new TestDatabase;
$root=$argv[1]??dirname(__DIR__);
require $root.'/includes/modules/sub-check/roxy-sub-check.php';
require $root.'/includes/class-roxy-suite-members-dashboard.php';
check(Roxy_Sub_Check::prepare_admission_log(),'admission verifies initialized log without schema repair');
$found=Roxy_Sub_Check::search_members('fixture1@',5);
check(count($found)===1&&$found[0]['subscription_id']===1&&count($GLOBALS['member_search_queries'])===2,'member search pages through subscription CRUD results and finds an older email match');
check($GLOBALS['member_search_queries'][0]['subscription_status']===['active','pending-cancel']&&$GLOBALS['member_search_queries'][0]['subscriptions_per_page']===100&&$GLOBALS['member_search_queries'][0]['paged']===1,'member search uses bounded stable Woo Subscriptions query pages');
$GLOBALS['member_search_fail']=true;
check(Roxy_Sub_Check::search_members('fixture1@',5)===[],'subscription query failure returns no potentially misleading partial search results');
unset($GLOBALS['member_search_fail']);
$payload=Roxy_Sub_Check::get_member_payload(1,false);
check($payload['last_visit']==='2026-09-28 19:30','nonlogging lookup includes latest actual admission in site timezone');
$query=end($GLOBALS['wpdb']->queries);
check(str_contains($query,'is_active=1')&&str_contains($query,'manual_admit_walkup')&&!str_contains($query,'OFFSET 1'),'last visit excludes lookups and is not second arbitrary scan');
$GLOBALS['wpdb']->fail=true;$result=Roxy_Sub_Check::log_member_visit(1,50,3,'manual_admit_walkup');
check(!$result['ok']&&!($result['payload']['admitted']??false)&&!$GLOBALS['wpdb']->inserts,'failed log write cannot report member admitted');
$GLOBALS['wpdb']->fail=false;$result=Roxy_Sub_Check::log_member_visit(1,50,99,'manual_admit_walkup');
check($result['ok']&&$result['payload']['admit_quantity']===3&&$GLOBALS['wpdb']->inserts[0]['quantity']===3,'successful group admission records actual clamped membership quantity');
$written=[];
$result=Roxy_Sub_Check::log_member_visit(1,50,99,'manual_admit_reserved',static function($row)use(&$written){$written[]=$row;return true;});
check(!$result['ok']&&!$written,'transactional visit refuses entitlement clamp after tickets were changed');
$result=Roxy_Sub_Check::log_member_visit(1,50,2,'manual_admit_reserved',static function($row)use(&$written){$written[]=$row;return true;});
check($result['ok']&&count($written)===1&&$written[0]['quantity']===2&&$written[0]['source']==='manual_admit_reserved','transaction writer receives canonical validated visit fields');
$insert_count=count($GLOBALS['wpdb']->inserts);
foreach([2=>'canceled',3=>'expired'] as $sub_id=>$label) {
    $result=Roxy_Sub_Check::log_member_visit($sub_id,50,1,'manual_admit_walkup');
    check(!$result['ok']&&count($GLOBALS['wpdb']->inserts)===$insert_count,'new admission is denied for '.$label.' membership without a scan write');
    $GLOBALS['history_subscription_id']=$sub_id;
    $rows=Roxy_Sub_Check::showing_admit_rows(50);
    check(count($rows)===1&&$rows[0]['qty']===3&&$rows[0]['subscription_id']===$sub_id,'persisted historical admission quantity remains visible after '.$label.' membership');
    check(!Roxy_Sub_Check::check_subscription($sub_id)['active'],'historical '.$label.' subscription is inactive for new admission');
}
$show_table_calls=count(array_filter($GLOBALS['wpdb']->queries,fn($s)=>str_contains($s,'SHOW TABLES')));
check($show_table_calls===1,'operational calls check schema once per request, no repeated dbDelta');
$stats=new ReflectionMethod(\RoxySuite\Members_Dashboard::class,'scan_stats_map');$stats->setAccessible(true);
$map=$stats->invoke(null,[1]);$query=end($GLOBALS['wpdb']->queries);
check($map[1]['month']===3&&str_contains($query,'THEN quantity')&&str_contains($query,'SUM(quantity) AS visits_lifetime')&&str_contains($query,'is_active=1')&&str_contains($query,'nfc_admit_reserved'),'dashboard metrics sum admitted people, excluding lookup/inactive scans');
$_GET=['page'=>'roxy-ticket-ops','tab'=>'member-check-log','sub'=>1];ob_start();Roxy_Sub_Check::render_scan_log_page();$html=ob_get_clean();
check(str_contains($html,'name="tab" value="member-check-log"')&&str_contains($html,'tab=member-check-log'),'log filter and navigation preserve unified tab');
check(str_contains($html,'admin-post.php?action=roxy_sub_export_scans')&&str_contains($html,'_wpnonce=test'),'CSV export routes before admin HTML with nonce');
$stamp=new ReflectionMethod(Roxy_Sub_Check::class,'scan_timestamp');$stamp->setAccessible(true);
check($stamp->invoke(null,'not-a-date')===0&&$stamp->invoke(null,'2026-02-30 10:00:00')===0,'malformed historic log dates do not crash history');
echo "NOTE: admission concurrency/undo reconciliation and dashboard query pagination remain separate open findings.\n";
