<?php
// Isolated member lookup/admission/navigation regression; no actual memberships.
define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');
define('MINUTE_IN_SECONDS',60);
define('MEMBER_REGRESSION_UPLOADS',sys_get_temp_dir().'/roxy-member-regression-'.getmypid().'/uploads/');
mkdir(MEMBER_REGRESSION_UPLOADS,0777,true);
register_shutdown_function(static function(){foreach(glob(MEMBER_REGRESSION_UPLOADS.'*')?:[] as $path)if(is_file($path))unlink($path);foreach(glob(MEMBER_REGRESSION_UPLOADS.'.*')?:[] as $path)if(is_file($path))unlink($path);if(is_dir(MEMBER_REGRESSION_UPLOADS))rmdir(MEMBER_REGRESSION_UPLOADS);$root=dirname(MEMBER_REGRESSION_UPLOADS);if(is_dir($root))rmdir($root);});
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function get_option($key,$default=false){return $GLOBALS['member_options'][$key]??(['roxy_member_scans_schema_version'=>'1','date_format'=>'Y-m-d','time_format'=>'H:i'][$key]??$default);}
function update_option($key,$value,...$args){$GLOBALS['member_options'][$key]=$value;return true;} function add_action(...$args){}function add_filter(...$args){}function register_activation_hook(...$args){}
function wp_timezone(){return new DateTimeZone('America/Los_Angeles');}
function get_current_user_id(){return 42;}function set_transient($key,$value,$expiration){$GLOBALS['test_transients'][$key]=$value;return true;}function get_transient($key){return $GLOBALS['test_transients'][$key]??false;}function delete_transient($key){unset($GLOBALS['test_transients'][$key]);}
function get_current_screen(){return $GLOBALS['test_screen']??null;}
function wp_date($format,$timestamp=null,$timezone=null){return (new DateTimeImmutable('@'.($timestamp??1791010800)))->setTimezone($timezone??wp_timezone())->format($format);}
function current_time($format){return '2026-10-03 10:00:00';}function date_i18n($fmt,$timestamp){return date($fmt,$timestamp);}
function absint($value){return abs((int)$value);}function get_post_meta($id,$key,$single=true){return $GLOBALS['member_post_meta'][(int)$id][$key]??'';}function update_post_meta($id,$key,$value){$GLOBALS['member_post_meta'][(int)$id][$key]=$value;return true;}
function get_user_meta($id,$key,$single=true){return $key==='first_name'?'Fixture':'Member';}
function wp_verify_nonce($nonce,$action){return $nonce==='fixture';}function current_user_can(...$args){return true;}
function wp_get_attachment_image_src($id,$size){return ['https://example.test/photo/'.$id];}
function get_post_type($id){return 'attachment';}function wp_upload_dir(...$args){return ['basedir'=>MEMBER_REGRESSION_UPLOADS];}function trailingslashit($path){return rtrim((string)$path,'/\\').'/';}function wp_normalize_path($path){return str_replace('\\','/',(string)$path);}function get_attached_file($id){return MEMBER_REGRESSION_UPLOADS.absint($id).'.jpg';}function wp_get_attachment_metadata($id){return ['file'=>absint($id).'.jpg'];}
function wcs_get_subscription($id){return new TestSubscription($id);}
function wcs_is_custom_order_tables_usage_enabled(){return !empty($GLOBALS['member_hpos']);}
function wcs_get_subscriptions($args){$GLOBALS['member_search_queries'][]=$args;if(!empty($GLOBALS['member_hpos_query_fail'])){$GLOBALS['wpdb']->last_error='fixture subscription query failure';return null;}return $GLOBALS['member_hpos_subscriptions'][(int)($args['customer_id']??0)]??[];}
function home_url($path=''){return 'https://example.test'.$path;}
function admin_url($path=''){return 'https://example.test/wp-admin/'.$path;}
function wp_nonce_url($url,$action){return $url.'&_wpnonce=test';}
function add_query_arg($key,$value=null,$url=null){if(is_array($key))return $value.(str_contains($value,'?')?'&':'?').http_build_query($key);return $url.(str_contains($url,'?')?'&':'?').http_build_query([$key=>$value]);}
function esc_html($v){return htmlspecialchars((string)$v);}function esc_attr($v){return htmlspecialchars((string)$v);}function esc_url($v){return (string)$v;}
function sanitize_key($v){return (string)$v;}function sanitize_text_field($v){return (string)$v;}function wp_unslash($v){return $v;}
function roxy_suite_user_can_access_admin(){return true;}
function get_the_author_meta(...$args){return 'Fixture staff';}
class TestSubscription {
    private $id;private $customer_id;private $pending=[];function __construct($id,$customer_id=8){$this->id=$id;$this->customer_id=$customer_id;$this->pending=$GLOBALS['test_sub_meta'][(int)$id]??[];}
    function get_id(){return (int)$this->id;}
    function get_customer_id(){return (int)$this->customer_id;}
    function get_user(){return (object)['ID'=>8,'user_email'=>'fixture'.$this->id.'@example.test','display_name'=>'Fixture Member'];}
    function get_status(){return $this->id===2?'cancelled':($this->id===3?'expired':'active');}
    function get_billing_email(){return 'billing'.$this->id.'@example.test';}
    function get_billing_first_name(){return 'Fixture';}function get_billing_last_name(){return 'Member';}
    function get_meta($key,$single=true){return $this->pending[$key]??'';}
    function update_meta_data($key,$value){$this->pending[$key]=$value;}function delete_meta_data($key){unset($this->pending[$key]);}
    function save(){if(!empty($GLOBALS['test_sub_save_fail']))return false;$GLOBALS['test_sub_meta'][(int)$this->id]=$this->pending;return $this->id;}
    function get_items(){return [new class {function get_quantity(){return 3;}}];}
    function get_date($key){return '';}
}
class WP_User_Query {
    private $args;function __construct($args){$this->args=$args;$GLOBALS['member_hpos_user_queries'][]=$args;}
    function get_results(){if(!empty($GLOBALS['member_hpos_user_query_fail'])){$GLOBALS['wpdb']->last_error='fixture user query failure';return null;}return $GLOBALS['member_hpos_user_results']??[];}
}
class TestDatabase {
    public $prefix='test_';public $posts='wp_posts';public $postmeta='wp_postmeta';public $users='wp_users';public $usermeta='wp_usermeta';public $last_error='';public $queries=[];public $inserts=[];public $fail=false;public $prepared_args=[];public $last_col_query='';public $last_col_args=[];
    function esc_like($value){return str_replace('_','\\_',$value);}
    function prepare($sql,...$args){$this->prepared_args=$args;return $sql;}
    function get_col($sql){$this->queries[]=$sql;$this->last_col_query=$sql;$this->last_col_args=$this->prepared_args;if($this->fail){$this->last_error='fixture query failure';return null;}$this->last_error='';return [1];}
    function get_var($sql){$this->queries[]=$sql;$this->last_error='';if(str_contains($sql,'SHOW TABLES'))return 'test_roxy_member_scans';if(str_contains($sql,'COUNT'))return 1;return '2026-09-28 19:30:00';}
    function get_results($sql,$format){$this->queries[]=$sql;$this->last_error='';if(str_contains($sql,'GROUP BY subscription_id, user_id'))return [['subscription_id'=>(int)($GLOBALS['history_subscription_id']??2),'user_id'=>8,'scanned_at'=>'2026-09-28 19:30:00','quantity'=>3]];if(str_contains($sql,'GROUP BY subscription_id'))return [['subscription_id'=>1,'visits_month'=>3,'visits_lifetime'=>6,'last_visit'=>'2026-09-28 19:30:00']];return [['id'=>1,'scanned_at'=>'2026-09-28 19:30:00','subscription_id'=>1,'is_active'=>1,'status'=>'active','user_id'=>8,'ip'=>'','user_agent'=>'']];}
    function insert($table,$data,$formats){if($this->fail)return false;$this->inserts[]=$data;return 1;}
}
$GLOBALS['wpdb']=new TestDatabase;
$GLOBALS['member_search_queries']=[];
$GLOBALS['test_sub_meta']=[];
$GLOBALS['member_options']=[];$GLOBALS['member_post_meta']=[];
$GLOBALS['member_photo_fixture_files']=[77,88,99];foreach($GLOBALS['member_photo_fixture_files'] as $id)file_put_contents(MEMBER_REGRESSION_UPLOADS.$id.'.jpg','fixture-photo');
$GLOBALS['test_transients']=[];$GLOBALS['test_screen']=(object)['post_type'=>'shop_subscription'];
$root=$argv[1]??dirname(__DIR__);
require $root.'/includes/modules/sub-check/roxy-sub-check.php';
require $root.'/includes/class-roxy-suite-members-dashboard.php';
check(Roxy_Sub_Check::prepare_admission_log(),'admission verifies initialized log without schema repair');
$found=Roxy_Sub_Check::search_members('fixture1@',5);
check(count($found)===1&&$found[0]['subscription_id']===1&&count($GLOBALS['member_search_queries'])===0,'member search finds an active subscriber without loading paged subscription collections');
$search_sql=$GLOBALS['wpdb']->last_col_query;
check(str_contains($search_sql,"p.post_status IN ('wc-active', 'wc-pending-cancel')")&&str_contains($search_sql,'ORDER BY p.ID DESC')&&str_contains($search_sql,'LIMIT %d')&&str_contains($search_sql,'EXISTS ('),'member search applies identity, active-status and result-limit filters in SQL');
check(count($GLOBALS['wpdb']->last_col_args)===7&&$GLOBALS['wpdb']->last_col_args[0]==='%fixture1@%','member search safely binds the search term and bounded result count');
$GLOBALS['member_hpos']=true;$GLOBALS['member_hpos_user_results']=[8,8];$GLOBALS['member_hpos_user_queries']=[];$GLOBALS['member_search_queries']=[];
$GLOBALS['member_hpos_subscriptions']=[8=>[new TestSubscription(4,8),new TestSubscription(2,8),new TestSubscription(3,8)]];
$hpos_found=Roxy_Sub_Check::search_members('fixture4@',5);
check(count($hpos_found)===1&&$hpos_found[0]['subscription_id']===4,'HPOS member search uses matching customer and active subscription APIs');
check(count($GLOBALS['member_hpos_user_queries'])===2&&$GLOBALS['member_hpos_user_queries'][0]['search_columns']===['user_email','display_name']&&$GLOBALS['member_hpos_user_queries'][1]['meta_query']['relation']==='OR','HPOS member search covers customer contact and first/last-name fields');
check(count($GLOBALS['member_search_queries'])===1&&$GLOBALS['member_search_queries'][0]['customer_id']===8&&$GLOBALS['member_search_queries'][0]['subscription_status']===['active','pending-cancel'],'HPOS subscription lookup is customer-scoped and excludes inactive memberships');
$GLOBALS['member_hpos_subscriptions'][8]=[];$GLOBALS['member_hpos_user_results']=[];
$exact_hpos=Roxy_Sub_Check::search_members('9',5);
check(count($exact_hpos)===1&&$exact_hpos[0]['subscription_id']===9,'HPOS member search resolves an exact subscription ID via Woo Subscriptions');
$GLOBALS['member_hpos_user_query_fail']=true;$hpos_search_failed=false;try{Roxy_Sub_Check::search_members('fixture1@',5);}catch(RuntimeException $error){$hpos_search_failed=true;}unset($GLOBALS['member_hpos_user_query_fail']);
check($hpos_search_failed,'HPOS customer-query failure is reported instead of shown as no match');
$GLOBALS['member_hpos_user_results']=[8];
$GLOBALS['member_hpos_query_fail']=true;$hpos_search_failed=false;try{Roxy_Sub_Check::search_members('fixture1@',5);}catch(RuntimeException $error){$hpos_search_failed=true;}unset($GLOBALS['member_hpos_query_fail']);
check($hpos_search_failed,'HPOS subscription-query failure is reported instead of shown as no match');
$GLOBALS['member_hpos']=false;
$GLOBALS['wpdb']->fail=true;
$search_failed=false;try{Roxy_Sub_Check::search_members('fixture1@',5);}catch(RuntimeException $error){$search_failed=true;}
check($search_failed,'subscription query failure is surfaced instead of misreported as no subscribers');
$GLOBALS['wpdb']->fail=false;
$dashboard_action=new ReflectionMethod(\RoxySuite\Members_Dashboard::class,'handle_member_actions');if (PHP_VERSION_ID < 80100) $dashboard_action->setAccessible(true);
$_POST=['roxy_members_action_nonce'=>'fixture','roxy_members_action'=>'save_photo','subscription_id'=>42,'attachment_id'=>77];ob_start();$dashboard_action->invoke(null);$photo_notice=ob_get_clean();
check(($GLOBALS['test_sub_meta'][42]['_roxy_member_photo_id']??0)===77&&str_contains($photo_notice,'Member photo updated.'),'member dashboard persists photo metadata through subscription CRUD');
check(($GLOBALS['member_post_meta'][77][Roxy_Sub_Check::META_PHOTO_PRIVATE]??'')==='1','member dashboard protects an attachment before saving its subscription reference');
check((Roxy_Sub_Check::get_member_payload(42,false)['photo_url']??'')==='https://example.test/wp-admin/admin-post.php?action=roxy_member_photo&attachment_id=77','member-check photo read uses the staff-only image endpoint');
$photo_post=(object)['ID'=>51,'post_type'=>'shop_subscription'];
$_POST=['roxy_member_photo_nonce'=>'fixture','roxy_member_photo_id'=>'88'];
Roxy_Sub_Check::save_subscription_photo_metabox(51,$photo_post);
ob_start();Roxy_Sub_Check::subscription_photo_save_notice();$legacy_photo_notice=ob_get_clean();
check(($GLOBALS['test_sub_meta'][51]['_roxy_member_photo_id']??0)===88&&str_contains($legacy_photo_notice,'Member photo updated.'),'legacy subscription photo editor verifies CRUD save and reports success');
$_POST=['roxy_member_photo_nonce'=>'fixture','roxy_member_photo_id'=>'0'];
Roxy_Sub_Check::save_subscription_photo_metabox(51,$photo_post);
ob_start();Roxy_Sub_Check::subscription_photo_save_notice();$remove_photo_notice=ob_get_clean();
check(!isset($GLOBALS['test_sub_meta'][51]['_roxy_member_photo_id'])&&str_contains($remove_photo_notice,'Member photo updated.'),'legacy subscription photo removal is verified and reported');
$_POST=['roxy_member_photo_nonce'=>'fixture','roxy_member_photo_id'=>['88']];
Roxy_Sub_Check::save_subscription_photo_metabox(51,$photo_post);
ob_start();Roxy_Sub_Check::subscription_photo_save_notice();$bad_photo_notice=ob_get_clean();
check(!isset($GLOBALS['test_sub_meta'][51]['_roxy_member_photo_id'])&&str_contains($bad_photo_notice,'could not be verified'),'malformed legacy photo input fails safely with a scoped error notice');
$GLOBALS['test_sub_save_fail']=true;$_POST=['roxy_member_photo_nonce'=>'fixture','roxy_member_photo_id'=>'99'];
Roxy_Sub_Check::save_subscription_photo_metabox(51,$photo_post);$GLOBALS['test_sub_save_fail']=false;
ob_start();Roxy_Sub_Check::subscription_photo_save_notice();$failed_photo_notice=ob_get_clean();
check(!isset($GLOBALS['test_sub_meta'][51]['_roxy_member_photo_id'])&&str_contains($failed_photo_notice,'could not be verified'),'failed legacy photo CRUD save is not reported as successful');
$_POST=['roxy_members_action_nonce'=>'fixture','roxy_members_action'=>'toggle_trade','subscription_id'=>42,'trade_value'=>'1'];ob_start();$dashboard_action->invoke(null);$trade_notice=ob_get_clean();
check(($GLOBALS['test_sub_meta'][42]['_roxy_member_trade_subscription']??0)===1&&str_contains($trade_notice,'Trade / comp flag updated.'),'member dashboard persists trade metadata through subscription CRUD');
$GLOBALS['test_sub_save_fail']=true;$_POST=['roxy_members_action_nonce'=>'fixture','roxy_members_action'=>'toggle_trade','subscription_id'=>42,'trade_value'=>'0'];ob_start();$dashboard_action->invoke(null);$failed_save_notice=ob_get_clean();unset($GLOBALS['test_sub_save_fail']);
check(($GLOBALS['test_sub_meta'][42]['_roxy_member_trade_subscription']??0)===1&&str_contains($failed_save_notice,'could not be saved'),'failed subscription CRUD save is reported and does not alter persisted metadata');
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
$stats=new ReflectionMethod(\RoxySuite\Members_Dashboard::class,'scan_stats_map');if (PHP_VERSION_ID < 80100) $stats->setAccessible(true);
$map=$stats->invoke(null,[1]);$query=end($GLOBALS['wpdb']->queries);
check($map[1]['month']===3&&str_contains($query,'THEN quantity')&&str_contains($query,'SUM(quantity) AS visits_lifetime')&&str_contains($query,'is_active=1')&&str_contains($query,'nfc_admit_reserved'),'dashboard metrics sum admitted people, excluding lookup/inactive scans');
$_GET=['page'=>'roxy-ticket-ops','tab'=>'member-check-log','sub'=>1];ob_start();Roxy_Sub_Check::render_scan_log_page();$html=ob_get_clean();
check(str_contains($html,'name="tab" value="member-check-log"')&&str_contains($html,'tab=member-check-log'),'log filter and navigation preserve unified tab');
check(str_contains($html,'admin-post.php?action=roxy_sub_export_scans')&&str_contains($html,'_wpnonce=test'),'CSV export routes before admin HTML with nonce');
$stamp=new ReflectionMethod(Roxy_Sub_Check::class,'scan_timestamp');if (PHP_VERSION_ID < 80100) $stamp->setAccessible(true);
check($stamp->invoke(null,'not-a-date')===0&&$stamp->invoke(null,'2026-02-30 10:00:00')===0,'malformed historic log dates do not crash history');
echo "NOTE: admission concurrency/undo reconciliation and dashboard query pagination remain separate open findings.\n";
