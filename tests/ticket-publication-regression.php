<?php
namespace RoxyST {
    class Log { public static function warn(...$args) { $GLOBALS['logs'][]=$args; } }
    class Settings {
        public static function get_price($key,$default=0){return $default;}
        public static function get_default_capacity(){return 100;}
        public static function get($key,$default=''){return $default;}
    }
    class Reservations {
        public static int $committed=0;
        public static function quantity_for_showing(int $showing_id,int $exclude_order_id=0,int $subscriber_user_id=0): int { return self::$committed; }
    }
    class Issuance {
        public static int $walkups=0;
        public static bool $fail=false;
        public static bool $lease_fail=false;
        public static bool $unpublish_on_lease=false;
        public static bool $throw_after_lease=false;
        public static int $lease_acquired=0;
        public static int $lease_released=0;
        public static int $ownership_checks=0;
        public static int $throw_on_ownership_check=0;
        public function __construct($ids,string $scope='') { $GLOBALS['capacity_locks'][]=$scope; }
        public function run(callable $operation) { if(self::$fail)throw new RuntimeException('fixture lock failure');return $operation($this); }
        public function acquire_lease(): void { if(self::$lease_fail)throw new RuntimeException('fixture lease failure'); self::$lease_acquired++; if(self::$unpublish_on_lease){$GLOBALS['statuses'][1]='draft';$GLOBALS['stale_statuses'][1]='publish';} if(self::$throw_after_lease)$GLOBALS['throw_post_type']=true; }
        public function assert_owner(): void { self::$ownership_checks++; if(self::$throw_on_ownership_check===self::$ownership_checks)throw new RuntimeException('fixture ownership loss'); }
        public function release_lease(): void { self::$lease_released++; }
        public function member_walkup_quantity(int $showing_id,int $subscription_id=0): int { return self::$walkups; }
        public function post_meta(int $id,string $key,$value,bool $remove=false): void { update_post_meta($id,$key,$value); }
        public function post_meta_value(int $id,string $key) { return get_post_meta($id,$key,true); }
    }
}
namespace {
define('ABSPATH', __DIR__); define('MINUTE_IN_SECONDS',60); define('ROXY_ST_META_SHOWING_ID','_roxy_showing_id'); define('ROXY_ST_META_TICKET_TYPE','_roxy_ticket_type');
$root=$argv[1]??dirname(__DIR__); $fixture=$argv[2]??$root.'/includes/modules/show-tickets/includes/';
$GLOBALS['meta']=[]; $GLOBALS['types']=[]; $GLOBALS['statuses']=[]; $GLOBALS['notices']=[]; $GLOBALS['writes']=[]; $GLOBALS['hooks']=[]; $GLOBALS['listing_queries']=[]; $GLOBALS['cleanup_queries']=[]; $GLOBALS['cleanup_pages']=[]; $GLOBALS['transients']=[]; $GLOBALS['capacity_locks']=[]; $GLOBALS['logs']=[];
$GLOBALS['room_lock_calls']=[]; $GLOBALS['room_conflict']=false; $GLOBALS['scheduled_events']=[]; $GLOBALS['schedule_fail']=false;
class WooCommerce {} class WP_Error { private $message; function __construct($code,$message){$this->message=$message;} function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
class FixtureSubscriptionItem { private $quantity; function __construct($quantity){$this->quantity=$quantity;} function get_quantity(){return $this->quantity;} }
class FixtureSubscription { private $status; private $items; function __construct($status,$items){$this->status=$status;$this->items=$items;} function has_status($status){return $this->status===$status;} function get_items(){return $this->items;} }
function wcs_get_users_subscriptions($user_id){$subscriptions=$GLOBALS['fixture_wcs_subscriptions']??[];if($subscriptions==='throw')throw new RuntimeException('private subscription read failure');return $subscriptions;}
function __($s,...$args){return $s;}
function get_post_type($id){if(!empty($GLOBALS['throw_post_type']))throw new RuntimeException('fixture readiness read failure');return $GLOBALS['types'][$id]??false;}
function get_post_status($id){if(isset($GLOBALS['stale_statuses'][$id]))return $GLOBALS['stale_statuses'][$id];return $GLOBALS['statuses'][$id]??false;}
function clean_post_cache($id){unset($GLOBALS['stale_statuses'][$id]);}
function get_the_title($id){return $GLOBALS['titles'][$id]??'Fixture showing';}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['meta'][$id][$key]=$value;return true;}
function delete_post_meta($id,$key){unset($GLOBALS['meta'][$id][$key]);return true;}
function wp_update_post($args,$error=false){$GLOBALS['writes'][]=$args; $GLOBALS['statuses'][$args['ID']]=$args['post_status']; return $args['ID'];}
function wp_trash_post($id){$GLOBALS['statuses'][$id]='trash'; return true;}
function get_posts($args){if(($args['meta_compare']??'')==='<'){ $GLOBALS['cleanup_queries'][]=$args; return $GLOBALS['cleanup_pages'][$args['paged']??1]??[]; } $ids=[]; foreach($GLOBALS['types'] as $id=>$type){if($type!==$args['post_type'])continue; $pass=true; foreach($args['meta_query']??[] as $condition){if(get_post_meta($id,$condition['key'])!=$condition['value'])$pass=false;} if($pass)$ids[]=$id;} return $ids;}
function sanitize_key($s){return $s;}
function esc_url_raw($s){return $s;}
function current_user_can(...$args){return true;}
function get_current_user_id(){return 7;}
function set_transient($key,$value,$expiration){$GLOBALS['transients'][$key]=$value;return true;}
function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function delete_transient($key){unset($GLOBALS['transients'][$key]);}
function wp_next_scheduled($hook,$args=[]){foreach($GLOBALS['scheduled_events'] as $event)if($event['hook']===$hook && $event['args']===$args)return $event['timestamp'];return false;}
function wp_schedule_single_event($timestamp,$hook,$args=[]){if($GLOBALS['schedule_fail'])return false;$GLOBALS['scheduled_events'][]=['timestamp'=>$timestamp,'hook'=>$hook,'args'=>$args];return true;}
function roxy_eb_with_showing_time_lock($start,$ignore_showing_id,$write){$GLOBALS['room_lock_calls'][]=[$start,$ignore_showing_id];if($GLOBALS['room_conflict'])return new WP_Error('reservation_conflict','That showing time overlaps another room reservation or showing. The change was not saved.');return $write();}
function wp_is_post_revision($id){return false;}
function add_action($hook,$callback,...$args){$GLOBALS['hooks'][$hook][]=$callback;}
function add_filter($hook,$callback,...$args){add_action($hook,$callback,...$args);}
function wc_add_notice($message,$type){$GLOBALS['notices'][]=$message;}
function wc_has_notice($message,$type){return in_array($message,$GLOBALS['notices'],true);}
function WC(){return $GLOBALS['woo'];}
function wp_verify_nonce(...$args){return true;}
function sanitize_text_field($s){return $s;}
function wp_unslash($s){return $s;}
function wp_die($message){throw new RuntimeException($message);}
function esc_url($value){return htmlspecialchars((string)$value,ENT_QUOTES);}
function esc_attr($value){return htmlspecialchars((string)$value,ENT_QUOTES);}
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES);}
function wp_kses_post($value){return (string)$value;}
function admin_url($path=''){return 'https://fixture.invalid/'.$path;}
function wp_create_nonce($action){return 'fixture-nonce';}
function wc_format_localized_price($price){return number_format((float)$price,2,'.','');}
function wc_format_decimal($price,$decimals=2){return number_format((float)$price,(int)$decimals,'.','');}
function wc_get_price_decimals(){return 2;}
function get_post_thumbnail_id($id){return 0;}
function wp_set_object_terms(...$args){return true;}
function wp_timezone(){return new DateTimeZone('America/Los_Angeles');}
function wp_date($format,$timestamp){return (new DateTimeImmutable('@'.(int)$timestamp))->setTimezone(wp_timezone())->format('Y-m-d H:i');}
function current_datetime(){return new DateTimeImmutable('2040-01-02 12:00:00',wp_timezone());}
class WP_Query {
    public $posts=[];
    function __construct($args){
        $GLOBALS['listing_queries'][]=$args;
        $pool=$GLOBALS['listing_fixture_posts']??[];
        $range=$args['meta_query'][0]['value']??null;
        if(is_array($range))$pool=array_values(array_filter($pool,function($post)use($range){$start=get_post_meta($post->ID,'_roxy_start');return $start >= $range[0] && $start <= $range[1];}));
        usort($pool,function($a,$b){$cmp=strcmp((string)get_post_meta($a->ID,'_roxy_start'),(string)get_post_meta($b->ID,'_roxy_start'));return $cmp!==0?$cmp:((int)$a->ID<=>(int)$b->ID);});
        $size=(int)($args['posts_per_page']??10);$page=(int)($args['paged']??1);
        $this->posts=array_slice($pool,($page-1)*$size,$size);
    }
}
function check($ok,$label){if(!$ok)throw new RuntimeException($label); echo "PASS: $label\n";}
require $fixture.'class-roxy-st-cpt.php'; require $fixture.'class-roxy-st-products.php'; require $fixture.'class-roxy-st-eligibility.php'; require $fixture.'class-roxy-st-capacity.php'; require $fixture.'class-roxy-st-tickets.php'; require $fixture.'class-roxy-st-frontend.php';
\RoxyST\Eligibility::init(); \RoxyST\Products::init();
$GLOBALS['fixture_wcs_subscriptions']=[new FixtureSubscription('active',[new FixtureSubscriptionItem(2),new FixtureSubscriptionItem(1)]),new FixtureSubscription('pending-cancel',[new FixtureSubscriptionItem(1)])];
check(\RoxyST\Capacity::subscription_entitlement_count(7)===4,'active and pending-cancel subscription item quantities produce the confirmed entitlement');
foreach([new WP_Error('private','subscription lookup failed'),'throw','unavailable'] as $unreadable){
    $GLOBALS['fixture_wcs_subscriptions']=$unreadable;
    check(\RoxyST\Capacity::subscription_entitlement_count(7)===0 && \RoxyST\Capacity::subscriber_limit_remaining_for_showing(1,7,false)===0,'unreadable subscription collection grants no subscriber seats');
}
foreach(['bad',-1,1.5,NAN,INF,PHP_INT_MAX] as $malformed_quantity){
    $GLOBALS['fixture_wcs_subscriptions']=[new FixtureSubscription('active',[new FixtureSubscriptionItem($malformed_quantity)])];
    check(\RoxyST\Capacity::subscription_entitlement_count(7)===0 && \RoxyST\Capacity::subscriber_limit_remaining_for_showing(1,7,false)===0,'malformed/overflow subscription quantity grants no subscriber seats');
}
$GLOBALS['fixture_wcs_subscriptions']=[new FixtureSubscription('active',[new stdClass()])];
check(\RoxyST\Capacity::subscription_entitlement_count(7)===0 && \RoxyST\Capacity::subscriber_limit_remaining_for_showing(1,7,false)===0,'malformed subscription item grants no subscriber seats');
$GLOBALS['fixture_wcs_subscriptions']=[];
check(isset($GLOBALS['hooks']['woocommerce_is_purchasable'],$GLOBALS['hooks']['woocommerce_checkout_process'],$GLOBALS['hooks']['woocommerce_check_cart_items'],$GLOBALS['hooks']['transition_post_status'],$GLOBALS['hooks']['before_delete_post'],$GLOBALS['hooks']['roxy_st_sync_products_retry']), 'all managed publication and checkout hooks registered');
$GLOBALS['types']=[1=>'roxy_showing',101=>'product',102=>'product',103=>'product',104=>'product',200=>'product'];
$GLOBALS['statuses']=[1=>'publish',101=>'publish',102=>'publish',103=>'publish',104=>'publish',200=>'publish'];
$GLOBALS['meta']=[1=>['_roxy_pricing_profile'=>'movie_evening','_roxy_pid_adult'=>101,'_roxy_pid_discount'=>102,'_roxy_pid_subscriber'=>104,'_roxy_start'=>current_datetime()->modify('-30 minutes')->format('Y-m-d\TH:i'),'_roxy_duration_minutes'=>'60'],101=>['_roxy_showing_id'=>1,'_roxy_ticket_type'=>'adult'],102=>['_roxy_showing_id'=>1,'_roxy_ticket_type'=>'discount'],103=>['_roxy_showing_id'=>1,'_roxy_ticket_type'=>'adult'],104=>['_roxy_showing_id'=>1,'_roxy_ticket_type'=>'subscriber']];
set_error_handler(static function($severity,$message,$file,$line){if(!(error_reporting()&$severity))return false;throw new \ErrorException($message,0,$severity,$file,$line);});
check(\RoxyST\Eligibility::showing_sales_open(1),'show remains eligible before its calculated end');
\RoxyST\Products::ensure_products_for_showing(1);
check(\RoxyST\Issuance::$lease_acquired===1 && \RoxyST\Issuance::$lease_released===1 && \RoxyST\Issuance::$ownership_checks>0,'ticket-product synchronization holds and verifies the shared seat-claim lease');
check(!isset($GLOBALS['transients']['roxy_st_sync_1']),'successful ticket-product synchronization releases its secondary sync lock');
$GLOBALS['statuses'][103]='publish';
$GLOBALS['writes']=[]; $GLOBALS['capacity_locks']=[];
$writes_before_lease_failure=count($GLOBALS['writes']); $meta_before_lease_failure=$GLOBALS['meta'][1];
\RoxyST\Issuance::$lease_fail=true; \RoxyST\Products::ensure_products_for_showing(1); \RoxyST\Issuance::$lease_fail=false;
check(count($GLOBALS['writes'])===$writes_before_lease_failure && $GLOBALS['meta'][1]===$meta_before_lease_failure && !isset($GLOBALS['transients']['roxy_st_sync_1']),'ticket-product synchronization fails closed when the seat lease is unavailable');
check(($GLOBALS['scheduled_events'][0]['hook']??'')==='roxy_st_sync_products_retry' && ($GLOBALS['scheduled_events'][0]['args']??[])===[1,1],'ticket-product synchronization schedules a bounded retry after lease contention');
$retry_event=array_shift($GLOBALS['scheduled_events']); \RoxyST\Products::retry_product_sync($retry_event['args'][0],$retry_event['args'][1]);
check(!isset($GLOBALS['transients']['roxy_st_sync_1']),'scheduled ticket-product retry completes and releases its sync lock');
$GLOBALS['statuses'][103]='publish'; $GLOBALS['writes']=[];
$GLOBALS['schedule_fail']=true; \RoxyST\Issuance::$lease_fail=true; \RoxyST\Products::ensure_products_for_showing(1); \RoxyST\Issuance::$lease_fail=false; $GLOBALS['schedule_fail']=false;
check(count($GLOBALS['scheduled_events'])===0 && !empty($GLOBALS['logs']),'failed ticket-product retry scheduling is logged');
$lease_releases_before_sync_conflict=\RoxyST\Issuance::$lease_released;
set_transient('roxy_st_sync_1',1,30); \RoxyST\Products::ensure_products_for_showing(1); unset($GLOBALS['transients']['roxy_st_sync_1']);
check(\RoxyST\Issuance::$lease_released===$lease_releases_before_sync_conflict+1 && count($GLOBALS['writes'])===$writes_before_lease_failure,'overlapping product sync releases its seat lease without changing products');
$lease_releases_before_status_change=\RoxyST\Issuance::$lease_released;
\RoxyST\Issuance::$unpublish_on_lease=true; \RoxyST\Products::ensure_products_for_showing(1); \RoxyST\Issuance::$unpublish_on_lease=false; $GLOBALS['statuses'][1]='publish';
check(\RoxyST\Issuance::$lease_released===$lease_releases_before_status_change+1 && count($GLOBALS['writes'])===$writes_before_lease_failure && !isset($GLOBALS['transients']['roxy_st_sync_1']),'product sync rechecks showing readiness after acquiring the seat lease');
$lease_releases_before_read_failure=\RoxyST\Issuance::$lease_released;
\RoxyST\Issuance::$throw_after_lease=true;
try { \RoxyST\Products::ensure_products_for_showing(1); } catch(RuntimeException $error) {}
\RoxyST\Issuance::$throw_after_lease=false; unset($GLOBALS['throw_post_type']);
check(\RoxyST\Issuance::$lease_released===$lease_releases_before_read_failure+1 && !isset($GLOBALS['transients']['roxy_st_sync_1']),'product sync releases the seat lease if readiness recheck throws');
$GLOBALS['writes']=[]; $ownership_check_before=\RoxyST\Issuance::$ownership_checks;
\RoxyST\Issuance::$throw_on_ownership_check=$ownership_check_before+3; \RoxyST\Products::ensure_products_for_showing(1); \RoxyST\Issuance::$throw_on_ownership_check=0;
check(count($GLOBALS['writes'])>0 && ($GLOBALS['scheduled_events'][0]['hook']??'')==='roxy_st_sync_products_retry' && !isset($GLOBALS['transients']['roxy_st_sync_1']),'ownership loss after a product write is logged and schedules reconciliation');
$recovery_event=array_shift($GLOBALS['scheduled_events']); \RoxyST\Products::retry_product_sync($recovery_event['args'][0],$recovery_event['args'][1]);
check(!isset($GLOBALS['transients']['roxy_st_sync_1']),'ownership-loss reconciliation retry completes and releases its sync lock');
$GLOBALS['statuses'][103]='publish'; $GLOBALS['writes']=[];
$show_end=\RoxyST\Eligibility::showing_end_timestamp(1);
check($show_end!==null && \RoxyST\Eligibility::showing_sales_open(1,$show_end-1) && !\RoxyST\Eligibility::showing_sales_open(1,$show_end),'sales cutoff is exclusive at the actual showing end');
$cleanup=new ReflectionMethod(\RoxyST\Products::class,'trash_products_for_expired_showing'); $cleanup->setAccessible(true);
foreach(['0','not-a-number','10081'] as $bad_duration){
    $GLOBALS['meta'][1]['_roxy_duration_minutes']=$bad_duration;
    check(\RoxyST\Eligibility::showing_end_timestamp(1)===null && !\RoxyST\Eligibility::showing_sales_open(1,current_datetime()->getTimestamp()) && is_wp_error(\RoxyST\Eligibility::product_error(101)),'malformed or out-of-range duration fails closed');
}
$cleanup->invoke(null,1);
check(($GLOBALS['statuses'][101]??'')==='publish','cleanup does not trash products when a supplied duration is invalid');
$GLOBALS['meta'][1]['_roxy_duration_minutes']='';
$show_start=\RoxyST\Eligibility::showing_start_timestamp(1);
check($show_start!==null && \RoxyST\Eligibility::showing_sales_cutoff_timestamp(1)===$show_start && \RoxyST\Eligibility::showing_sales_open(1,$show_start-1) && !\RoxyST\Eligibility::showing_sales_open(1,$show_start),'missing duration preserves the legacy start-time cutoff');
$GLOBALS['meta'][1]['_roxy_duration_minutes']='60';
$form=new ReflectionMethod(\RoxyST\Frontend::class,'render_ticket_form'); $form->setAccessible(true);
check(strpos($form->invoke(null,1,'movie_evening'),'name="general_qty"')!==false,'online ticket form remains available during a running showing');
$cleanup->invoke(null,1);
check(($GLOBALS['statuses'][101]??'')==='publish','cleanup preserves ticket products while the showing is still running');

$_POST=['roxy_showing_nonce'=>'fixture','roxy_capacity'=>'100','roxy_pricing_profile'=>'movie_evening','roxy_start'=>$GLOBALS['meta'][1]['_roxy_start']];
\RoxyST\CPT::save(1,null);
check(($GLOBALS['capacity_locks'][0]??'')==='walkup:1','showing capacity writes use the shared seat-claim lock');
check($GLOBALS['meta'][1]['_roxy_duration_minutes']==='60','missing duration POST preserves the saved value');
\RoxyST\Reservations::$committed=2; \RoxyST\Issuance::$walkups=1;
$_POST['roxy_capacity']='2'; $_POST['roxy_live_label_1']='Must not partially save';
\RoxyST\CPT::save(1,null);
check((string)$GLOBALS['meta'][1]['_roxy_capacity']==='100' && ($GLOBALS['meta'][1]['_roxy_live_label_1']??'')!=='Must not partially save' && isset($GLOBALS['transients']['roxy_st_capacity_conflict_7']),'capacity below sold seats plus walk-ups rejects the entire showing edit');
$_POST['roxy_capacity']='3'; \RoxyST\CPT::save(1,null);
check((string)$GLOBALS['meta'][1]['_roxy_capacity']==='3','capacity equal to current committed occupancy is accepted');
foreach(['2.5','-1',['4']] as $invalid_capacity){
    $_POST['roxy_capacity']=$invalid_capacity; $_POST['roxy_live_label_1']='Must not save with invalid capacity';
    \RoxyST\CPT::save(1,null);
    check((string)$GLOBALS['meta'][1]['_roxy_capacity']==='3' && ($GLOBALS['meta'][1]['_roxy_live_label_1']??'')!=='Must not save with invalid capacity','malformed/non-whole capacity rejects all submitted showing settings');
}
$_POST['roxy_capacity']='3';
\RoxyST\Issuance::$fail=true; $_POST['roxy_capacity']='4'; \RoxyST\CPT::save(1,null); \RoxyST\Issuance::$fail=false;
check((string)$GLOBALS['meta'][1]['_roxy_capacity']==='3','unavailable seat lock rejects capacity writes');
\RoxyST\Reservations::$committed=0; \RoxyST\Issuance::$walkups=0; unset($GLOBALS['transients']['roxy_st_capacity_conflict_7']); $_POST['roxy_capacity']='100';
$_POST['roxy_duration_minutes']=''; \RoxyST\CPT::save(1,null);
check($GLOBALS['meta'][1]['_roxy_duration_minutes']==='60' && isset($GLOBALS['transients']['roxy_st_invalid_duration_7']),'blank duration preserves a saved value and can warn');
$GLOBALS['meta'][1]['_roxy_duration_minutes']=''; unset($GLOBALS['transients']['roxy_st_invalid_duration_7']);
\RoxyST\CPT::save(1,null);
check($GLOBALS['meta'][1]['_roxy_duration_minutes']==='' && !isset($GLOBALS['transients']['roxy_st_invalid_duration_7']),'blank optional duration without a saved value is accepted silently');
$GLOBALS['meta'][1]['_roxy_duration_minutes']='60';
foreach(['0','1.5','not-a-number','10081'] as $invalid_duration){
    $_POST['roxy_duration_minutes']=$invalid_duration; \RoxyST\CPT::save(1,null);
    check($GLOBALS['meta'][1]['_roxy_duration_minutes']==='60','invalid duration is rejected without erasing the saved value');
}
$_POST['roxy_duration_minutes']=['90']; \RoxyST\CPT::save(1,null);
check($GLOBALS['meta'][1]['_roxy_duration_minutes']==='60','nonscalar duration input is rejected without erasing the saved value');
$_POST['roxy_duration_minutes']='90'; \RoxyST\CPT::save(1,null);
check($GLOBALS['meta'][1]['_roxy_duration_minutes']==='90','valid duration updates the canonical saved value');
$GLOBALS['meta'][1]['_roxy_duration_minutes']='60';

$previous_start=(string)$GLOBALS['meta'][1]['_roxy_start'];
$_POST['roxy_start']='2040-01-02T14:30';
$before_room_calls=count($GLOBALS['room_lock_calls']);
\RoxyST\CPT::save(1,null);
check(($GLOBALS['meta'][1]['_roxy_start']??'')==='2040-01-02T14:30' && $GLOBALS['room_lock_calls'][$before_room_calls]===['2040-01-02T14:30',1],'managed showing-time edits use the shared room-lock boundary and preserve valid edits');
$previous_start=(string)$GLOBALS['meta'][1]['_roxy_start'];
$GLOBALS['room_conflict']=true;
$_POST['roxy_start']='2040-01-02T15:30';
\RoxyST\CPT::save(1,null);
check(($GLOBALS['meta'][1]['_roxy_start']??'')===$previous_start && isset($GLOBALS['transients']['roxy_st_room_conflict_7']),'conflicting showing-time edit is rejected without replacing the saved start');
$GLOBALS['room_conflict']=false;

$GLOBALS['meta'][1]['_roxy_start']='2024-03-10T02:30';
check(\RoxyST\Eligibility::showing_start_timestamp(1)===null,'nonexistent local time during the spring DST jump is rejected');
$GLOBALS['meta'][1]['_roxy_start']='2024-03-10T01:30:00';
$dst_start=\RoxyST\Eligibility::showing_start_timestamp(1);
$GLOBALS['meta'][1]['_roxy_duration_minutes']='120';
$dst_end=\RoxyST\Eligibility::showing_end_timestamp(1);
check($dst_start!==null && $dst_end!==null && $dst_end-$dst_start===7200,'seconds-bearing start remains compatible and duration measures elapsed time across DST');
$GLOBALS['meta'][1]['_roxy_start']=current_datetime()->modify('-30 minutes')->format('Y-m-d\TH:i');
$GLOBALS['meta'][1]['_roxy_duration_minutes']='60';

$now=current_datetime();
$GLOBALS['listing_fixture_posts']=[];
for($id=1000;$id<1100;$id++){
    $GLOBALS['types'][$id]='roxy_showing';$GLOBALS['statuses'][$id]='publish';
    $GLOBALS['meta'][$id]=['_roxy_start'=>$now->modify('-2 hours')->format('Y-m-d\TH:i'),'_roxy_duration_minutes'=>'60'];
    $GLOBALS['listing_fixture_posts'][]=(object)['ID'=>$id];
}
$GLOBALS['types'][1100]='roxy_showing';$GLOBALS['statuses'][1100]='publish';
$GLOBALS['meta'][1100]=['_roxy_start'=>$now->modify('-30 minutes')->format('Y-m-d\TH:i'),'_roxy_duration_minutes'=>'60'];
$GLOBALS['listing_fixture_posts'][]=(object)['ID'=>1100];
$listing=new ReflectionMethod(\RoxyST\Frontend::class,'eligible_listing_showings');$listing->setAccessible(true);
$eligible=$listing->invoke(null,$now,$now->modify('+10 days'),1);
check(count($eligible)===1 && (int)$eligible[0]->ID===1100,'paged listing filters ended candidates before applying the result limit');
check(count($GLOBALS['listing_queries'])===2 && $GLOBALS['listing_queries'][0]['posts_per_page']===100 && $GLOBALS['listing_queries'][1]['paged']===2,'public listing reads bounded pages until it fills the limit');
check(\RoxyST\Eligibility::product_error(101)===null,'canonical public general ticket passes');
check(\RoxyST\Eligibility::product_error(200)===null,'unrelated products untouched');
check(is_wp_error(\RoxyST\Eligibility::product_error(999)),'hard-deleted product with missing metadata fails closed');
check(is_wp_error(\RoxyST\Eligibility::product_error(103)),'obsolete duplicate product rejected');
$GLOBALS['statuses'][101]='draft'; check(is_wp_error(\RoxyST\Eligibility::product_error(101)),'withdrawn product rejected even with public showing'); $GLOBALS['statuses'][101]='publish';
foreach(['draft','pending','future','private','trash'] as $status){$GLOBALS['statuses'][1]=$status; check(is_wp_error(\RoxyST\Eligibility::product_error(101)),"$status showing rejected"); \RoxyST\Products::ensure_products_for_showing(1);}
check(count($GLOBALS['writes'])===0,'nonpublic product sync cannot publish anything');
$_POST=['nonce'=>'fixture','showing_id'=>1];
try {\RoxyST\Frontend::handle_add(); throw new RuntimeException('unavailable showing accepted');} catch(RuntimeException $e){check(strpos($e->getMessage(),'not available')!==false,'public endpoint rejects nonpublic showing before cart or product sync');}
$GLOBALS['statuses'][1]='publish'; $GLOBALS['meta'][1]['_roxy_pricing_profile']='live_event';
check(is_wp_error(\RoxyST\Eligibility::product_error(101)),'profile change invalidates stale movie option');
$GLOBALS['meta'][101]['_roxy_ticket_type']='live1'; $GLOBALS['meta'][1]['_roxy_pid_live1']=101;
check(is_wp_error(\RoxyST\Eligibility::product_error(101)),'removed live tier rejected');
$GLOBALS['meta'][1]['_roxy_live_price_1']='0'; check(\RoxyST\Eligibility::product_error(101)===null,'configured free live tier remains valid');
$GLOBALS['meta'][1]['_roxy_start']='invalid date';
$GLOBALS['meta'][1]['_roxy_duration_minutes']='60';
check(is_wp_error(\RoxyST\Eligibility::product_error(101)) && \RoxyST\Eligibility::showing_end_timestamp(1)===null,'malformed start date fails closed for live ticket sales');
\RoxyST\Products::ensure_products_for_showing(1);
check(!isset($GLOBALS['transients']['roxy_st_sync_1']),'malformed showing date blocks ticket product synchronization without acquiring a sync lock');
$meta_title=new ReflectionMethod(\RoxyST\Frontend::class,'meta_title');$meta_title->setAccessible(true);
check($meta_title->invoke(null,1)==='Fixture showing','malformed showing date is omitted from customer-facing SEO title');
$door_label=new ReflectionMethod(\RoxyST\Tickets::class,'ticket_showing_when_label');$door_label->setAccessible(true);
check($door_label->invoke(null,'2026-02-30T19:30')==='','malformed legacy showing date is omitted from ticket date labels');
$GLOBALS['meta'][1]['_roxy_start']=current_datetime()->modify('-90 minutes')->format('Y-m-d\TH:i');
$GLOBALS['meta'][1]['_roxy_duration_minutes']='60';
check(is_wp_error(\RoxyST\Eligibility::product_error(101)),'ended live showing is no longer purchasable');
check(strpos($form->invoke(null,1,'live_event'),'sales have ended')!==false,'ended showing does not render an online purchase form');
$_POST=['nonce'=>'fixture','showing_id'=>1];
try {\RoxyST\Frontend::handle_add(); throw new RuntimeException('ended showing accepted');} catch(RuntimeException $e){check(strpos($e->getMessage(),'sales have ended')!==false,'direct ticket submission rejects an ended showing');}
$GLOBALS['meta'][1]['_roxy_duration_minutes']='';
check(is_wp_error(\RoxyST\Eligibility::product_error(101)),'missing canonical duration fails closed');
$GLOBALS['meta'][1]['_roxy_start']=current_datetime()->modify('-3 hours')->format('Y-m-d\TH:i');
$GLOBALS['meta'][1]['_roxy_duration_minutes']='60';
$cleanup->invoke(null,1);
check(($GLOBALS['statuses'][101]??'')==='trash','cleanup trashes ticket products only after calculated showing end');
$GLOBALS['types'][2]='roxy_showing';$GLOBALS['statuses'][2]='draft';$GLOBALS['meta'][2]=['_roxy_start'=>current_datetime()->modify('+2 hours')->format('Y-m-d\TH:i'),'_roxy_duration_minutes'=>'60','_roxy_pid_adult'=>202];
$GLOBALS['types'][202]='product';$GLOBALS['statuses'][202]='publish';
$cleanup->invoke(null,2);
check(($GLOBALS['statuses'][202]??'')==='publish' && $GLOBALS['statuses'][2]==='draft','cleanup preserves products for a future nonpublic showing');
$GLOBALS['cleanup_pages']=[1=>range(5000,5099),2=>[5100]];
$cleanup_all=new ReflectionMethod(\RoxyST\Products::class,'trash_expired_showing_products');$cleanup_all->setAccessible(true);$cleanup_all->invoke(null);
check(count($GLOBALS['cleanup_queries'])===2 && $GLOBALS['cleanup_queries'][0]['posts_per_page']===100 && $GLOBALS['cleanup_queries'][1]['paged']===2,'cleanup scans expired showings in bounded pages');
$GLOBALS['statuses'][101]='publish'; $GLOBALS['statuses'][102]='publish'; $GLOBALS['meta'][1]['_roxy_start']=current_datetime()->modify('-30 minutes')->format('Y-m-d\TH:i');
$GLOBALS['woo']=(object)['cart'=>new class {function get_cart(){return [['product_id'=>101]];}}];
$GLOBALS['statuses'][1]='draft'; \RoxyST\Eligibility::validate_cart(); \RoxyST\Eligibility::validate_cart();
check(count($GLOBALS['notices'])===1,'stale cart blocked without duplicate notices');
check(!\RoxyST\Eligibility::validate_add(true,101,1),'direct cart add blocked');
\RoxyST\Products::on_status_changed('draft','publish',(object)['ID'=>1,'post_type'=>'roxy_showing']);
check($GLOBALS['statuses'][101]==='draft' && $GLOBALS['statuses'][102]==='draft' && $GLOBALS['statuses'][103]==='draft','deactivation covers canonical and duplicate product identities');
check($GLOBALS['statuses'][200]==='publish','deactivation preserves unrelated product');
$GLOBALS['statuses'][101]='publish'; \RoxyST\Products::on_showing_deleted(1);
check($GLOBALS['statuses'][101]==='draft','permanent showing deletion deactivates linked product');
unset($GLOBALS['types'][1]); check(is_wp_error(\RoxyST\Eligibility::product_error(101)),'deleted showing rejects historical ticket product');
echo "All publication and showing-end regressions passed.\n";
}
