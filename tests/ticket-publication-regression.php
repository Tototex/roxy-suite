<?php
namespace RoxyST { class CPT { const POST_TYPE='roxy_showing'; } class Log { public static function warn(...$args) {} } }
namespace {
define('ABSPATH', __DIR__); define('ROXY_ST_META_SHOWING_ID','_roxy_showing_id'); define('ROXY_ST_META_TICKET_TYPE','_roxy_ticket_type');
$root=$argv[1]??dirname(__DIR__); $fixture=$argv[2]??$root.'/includes/modules/show-tickets/includes/';
$GLOBALS['meta']=[]; $GLOBALS['types']=[]; $GLOBALS['statuses']=[]; $GLOBALS['notices']=[]; $GLOBALS['writes']=[]; $GLOBALS['hooks']=[];
class WooCommerce {} class WP_Error { private $message; function __construct($code,$message){$this->message=$message;} function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($s,...$args){return $s;}
function get_post_type($id){return $GLOBALS['types'][$id]??false;}
function get_post_status($id){return $GLOBALS['statuses'][$id]??false;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function wp_update_post($args,$error=false){$GLOBALS['writes'][]=$args; $GLOBALS['statuses'][$args['ID']]=$args['post_status']; return $args['ID'];}
function get_posts($args){$ids=[]; foreach($GLOBALS['types'] as $id=>$type){if($type!==$args['post_type'])continue; $pass=true; foreach($args['meta_query']??[] as $condition){if(get_post_meta($id,$condition['key'])!=$condition['value'])$pass=false;} if($pass)$ids[]=$id;} return $ids;}
function sanitize_key($s){return $s;}
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
function check($ok,$label){if(!$ok)throw new RuntimeException($label); echo "PASS: $label\n";}
require $fixture.'class-roxy-st-products.php'; require $fixture.'class-roxy-st-eligibility.php'; require $fixture.'class-roxy-st-frontend.php';
\RoxyST\Eligibility::init(); \RoxyST\Products::init();
check(isset($GLOBALS['hooks']['woocommerce_is_purchasable'],$GLOBALS['hooks']['woocommerce_checkout_process'],$GLOBALS['hooks']['woocommerce_check_cart_items'],$GLOBALS['hooks']['transition_post_status'],$GLOBALS['hooks']['before_delete_post']), 'all managed publication and checkout hooks registered');
$GLOBALS['types']=[1=>'roxy_showing',101=>'product',102=>'product',103=>'product',200=>'product'];
$GLOBALS['statuses']=[1=>'publish',101=>'publish',102=>'publish',103=>'publish',200=>'publish'];
$GLOBALS['meta']=[1=>['_roxy_pricing_profile'=>'movie_evening','_roxy_pid_adult'=>101,'_roxy_pid_discount'=>102],101=>['_roxy_showing_id'=>1,'_roxy_ticket_type'=>'adult'],102=>['_roxy_showing_id'=>1,'_roxy_ticket_type'=>'discount'],103=>['_roxy_showing_id'=>1,'_roxy_ticket_type'=>'adult']];
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
echo "All publication regressions passed. No cutoff policy asserted.\n";
}
