<?php
namespace RoxyST {
    class Log { public static function warn(...$args) {} }
    class Settings {
        public static function get_price($key,$default=0){return $default;}
        public static function get_default_capacity(){return 100;}
        public static function get($key,$default=''){return $default;}
    }
}
namespace {
define('ABSPATH', __DIR__); define('MINUTE_IN_SECONDS',60); define('ROXY_ST_META_SHOWING_ID','_roxy_showing_id'); define('ROXY_ST_META_TICKET_TYPE','_roxy_ticket_type');
$root=$argv[1]??dirname(__DIR__); $fixture=$argv[2]??$root.'/includes/modules/show-tickets/includes/';
$GLOBALS['meta']=[]; $GLOBALS['types']=[]; $GLOBALS['statuses']=[]; $GLOBALS['notices']=[]; $GLOBALS['writes']=[]; $GLOBALS['hooks']=[]; $GLOBALS['listing_queries']=[]; $GLOBALS['cleanup_queries']=[]; $GLOBALS['cleanup_pages']=[]; $GLOBALS['transients']=[];
class WooCommerce {} class WP_Error { private $message; function __construct($code,$message){$this->message=$message;} function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($s,...$args){return $s;}
function get_post_type($id){return $GLOBALS['types'][$id]??false;}
function get_post_status($id){return $GLOBALS['statuses'][$id]??false;}
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
function wp_timezone(){return new DateTimeZone('America/Los_Angeles');}
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
require $fixture.'class-roxy-st-cpt.php'; require $fixture.'class-roxy-st-products.php'; require $fixture.'class-roxy-st-eligibility.php'; require $fixture.'class-roxy-st-frontend.php';
\RoxyST\Eligibility::init(); \RoxyST\Products::init();
check(isset($GLOBALS['hooks']['woocommerce_is_purchasable'],$GLOBALS['hooks']['woocommerce_checkout_process'],$GLOBALS['hooks']['woocommerce_check_cart_items'],$GLOBALS['hooks']['transition_post_status'],$GLOBALS['hooks']['before_delete_post']), 'all managed publication and checkout hooks registered');
$GLOBALS['types']=[1=>'roxy_showing',101=>'product',102=>'product',103=>'product',200=>'product'];
$GLOBALS['statuses']=[1=>'publish',101=>'publish',102=>'publish',103=>'publish',200=>'publish'];
$GLOBALS['meta']=[1=>['_roxy_pricing_profile'=>'movie_evening','_roxy_pid_adult'=>101,'_roxy_pid_discount'=>102,'_roxy_start'=>current_datetime()->modify('-30 minutes')->format('Y-m-d\TH:i'),'_roxy_duration_minutes'=>'60'],101=>['_roxy_showing_id'=>1,'_roxy_ticket_type'=>'adult'],102=>['_roxy_showing_id'=>1,'_roxy_ticket_type'=>'discount'],103=>['_roxy_showing_id'=>1,'_roxy_ticket_type'=>'adult']];
set_error_handler(static function($severity,$message,$file,$line){if(!(error_reporting()&$severity))return false;throw new \ErrorException($message,0,$severity,$file,$line);});
check(\RoxyST\Eligibility::showing_sales_open(1),'show remains eligible before its calculated end');
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
check($GLOBALS['meta'][1]['_roxy_duration_minutes']==='60','missing duration POST preserves the saved value');
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
