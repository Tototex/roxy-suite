<?php
// Standalone contract tests. Actual Woo/browser checks are separate.
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function WC() {return $GLOBALS['wc'];}
function get_current_user_id() {return $GLOBALS['user'];}
function get_current_blog_id() {return 1;}
function get_user_meta(...$args) {return $GLOBALS['saved'];}
function maybe_serialize($value){return serialize($value);}
function wp_cache_delete(...$args){}
class FakeWpdb {public $usermeta='usermeta';function prepare($sql,...$args){return serialize($args);}function query($sql){$args=unserialize($sql);if($GLOBALS['saved']===unserialize($args[2])){$GLOBALS['saved']='';return 1;}return 0;}}
$GLOBALS['wpdb']=new FakeWpdb();
class WC_Cart_Session {private $cart;function __construct($cart){$this->cart=$cart;} function get_cart_for_session(){return $this->cart->items;}}
class Cart {public array $items=[];function is_empty(){return !$this->items;}function get_cart_hash(){return 'current-hash';}}
class WC_Order {public $total=0,$paid=true,$user=7,$hash='current-hash';function get_total(){return $this->total;}function is_paid(){return $this->paid;}function get_customer_id(){return $this->user;}function has_cart_hash($hash){return $this->hash===$hash;}}
require ($argv[1]??dirname(__DIR__)).'/includes/class-roxy-suite-checkout.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function setup(){ $GLOBALS['user']=7;$GLOBALS['wc']=(object)['cart'=>new Cart()];$GLOBALS['wc']->cart->items=['a'=>['product_id'=>9,'quantity'=>1,'custom'=>'ticket']];$GLOBALS['saved']=['cart'=>$GLOBALS['wc']->cart->items];return new WC_Order();}
function finish($o){$GLOBALS['wc']->cart->items=[];return Roxy_Suite_Checkout::complete('same-url',$o);}
$o=setup();Roxy_Suite_Checkout::capture(false);check(finish($o)==='same-url'&&$GLOBALS['saved']==='','matching completed zero-dollar cart removed without redirect change');
$o=setup();$GLOBALS['saved']['cart']['a']['line_total']=20;Roxy_Suite_Checkout::capture(false);finish($o);check($GLOBALS['saved']==='','coupon recalculation does not change saved item identity');
foreach(['total'=>1,'paid'=>false,'user'=>8,'hash'=>'different-hash'] as $property=>$value){$o=setup();$o->$property=$value;Roxy_Suite_Checkout::capture(false);finish($o);check(is_array($GLOBALS['saved']),"guard preserves cart for $property mismatch");}
$o=setup();$GLOBALS['user']=0;Roxy_Suite_Checkout::capture(false);finish($o);check(is_array($GLOBALS['saved']),'guest checkout untouched');
$o=setup();Roxy_Suite_Checkout::capture(true);finish($o);check(is_array($GLOBALS['saved']),'normal persistent clearing untouched');
$o=setup();$GLOBALS['saved']['cart']['a']['quantity']=2;Roxy_Suite_Checkout::capture(false);finish($o);check(is_array($GLOBALS['saved']),'different saved cart before capture preserved');
$o=setup();Roxy_Suite_Checkout::capture(false);$GLOBALS['saved']['cart']['a']['quantity']=3;finish($o);check($GLOBALS['saved']['cart']['a']['quantity']===3,'concurrent saved cart edit preserved by expected-value deletion');
$o=setup();Roxy_Suite_Checkout::capture(false);Roxy_Suite_Checkout::complete('same-url',$o);check(is_array($GLOBALS['saved']),'nonempty current cart never cleared');
$o=setup();Roxy_Suite_Checkout::capture(false);finish($o);$GLOBALS['saved']=['cart'=>['new'=>['product_id'=>12]]];finish($o);check(isset($GLOBALS['saved']['cart']['new']),'repeated completion cannot clear a later cart');
$o=setup();$GLOBALS['saved']=['cart'=>'malformed'];Roxy_Suite_Checkout::capture(false);finish($o);check($GLOBALS['saved']['cart']==='malformed','malformed saved metadata fails closed without throwing');
echo "CHECKOUT_CART_REGRESSION_OK\n";
