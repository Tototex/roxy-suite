<?php
// Real capacity handlers with standalone Woo/member/sales doubles; no customer writes.
define('ABSPATH',__DIR__);define('ROXY_ST_META_SHOWING_ID','_roxy_showing_id');define('ROXY_ST_META_TICKET_TYPE','_roxy_ticket_type');
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function get_current_user_id(){return 7;}function sanitize_key($v){return $v;}function esc_html($v){return $v;}function get_the_title($id){return 'Private fixture';}
function wc_add_notice($message,$type){$GLOBALS['notices'][]=$message;}
function wc_get_orders($args){return [];}
function WC(){return $GLOBALS['woo'];}
function wcs_get_users_subscriptions($id){return [new class{function has_status($s){return $s==='active';}function get_items(){return [new class{function get_quantity(){return 3;}}];}}];}
class TestCart {
  public $rows=[];
  function get_cart(){return $this->rows;}
  function get_cart_item($key){return $this->rows[$key]??null;}
  function set_quantity($key,$qty){$this->rows[$key]['quantity']=$qty;}
}
class Roxy_Sub_Check{static function walkup_quantity_for_showing($show,$user=0){if(!empty($GLOBALS['log_fail']))throw new RuntimeException('Fixture read failure');return (int)($GLOBALS[$user>0?'user_walkups':'walkups']??0);}}
eval('namespace RoxyST;class Reservations{static function quantity_for_showing($id){if(!empty($GLOBALS["reservation_fail"]))throw new \\RuntimeException("Fixture failure");return (int)($GLOBALS["sold"]??0);}}');
$root=$argv[1]??dirname(__DIR__);require $root.'/includes/modules/show-tickets/includes/class-roxy-st-capacity.php';
$cart=new TestCart();$GLOBALS['woo']=(object)['cart'=>$cart];$GLOBALS['meta']=[50=>['_roxy_capacity'=>5],10=>['_roxy_showing_id'=>50,'_roxy_ticket_type'=>'adult']];$GLOBALS['sold']=2;$GLOBALS['walkups']=2;$GLOBALS['user_walkups']=2;$GLOBALS['notices']=[];
check(\RoxyST\Capacity::remaining_seats_for_showing(50)===1,'remaining seats subtract ticket reservations and walk-ups once');
check(!\RoxyST\Capacity::validate_add_to_cart(false,10,1),'capacity filter preserves an earlier add-to-cart rejection');
check(\RoxyST\Capacity::validate_add_to_cart(true,10,1),'last remaining seat can be added');
check(!\RoxyST\Capacity::validate_add_to_cart(true,10,2),'add-to-cart cannot exceed walk-up-adjusted availability');
$cart->rows=['row'=>['product_id'=>10,'quantity'=>1]];
check(!\RoxyST\Capacity::validate_cart_update(true,'row',$cart->rows['row'],2),'cart update cannot bypass walk-up-adjusted availability');
$cart->rows['row']['quantity']=2;$GLOBALS['notices']=[];\RoxyST\Capacity::validate_checkout_capacity();
check(count($GLOBALS['notices'])===1,'checkout refuses too many seats after a walk-up');
\RoxyST\Capacity::after_cart_item_qty_update('row',2,1,$cart);
check($cart->rows['row']['quantity']===1,'quantity-change hook trims to actual remaining seats');
$GLOBALS['meta'][10]['_roxy_ticket_type']='subscriber';$cart->rows=[];$GLOBALS['meta'][50]['_roxy_capacity']=20;
check(\RoxyST\Capacity::subscriber_limit_remaining_for_showing(50,7,false)===1,'walk-up usage reduces online member entitlement');
check(!\RoxyST\Capacity::validate_add_to_cart(true,10,2),'subscriber add-to-cart cannot reuse walk-up entitlement');
$cart->rows=['row'=>['product_id'=>10,'quantity'=>1]];
check(!\RoxyST\Capacity::validate_cart_update(true,'row',$cart->rows['row'],2),'subscriber update does not add the current line back to entitlement');
$GLOBALS['user_walkups']=1;$cart->rows['other']=['product_id'=>10,'quantity'=>1];
check(!\RoxyST\Capacity::validate_cart_update(true,'row',$cart->rows['row'],2),'subscriber update also reserves other cart lines');
$GLOBALS['log_fail']=true;
check(\RoxyST\Capacity::remaining_seats_for_showing(50)===0,'unknown arrivals fail closed without a page fatal');
check(\RoxyST\Capacity::subscriber_limit_remaining_for_showing(50,7,false)===0,'unknown member arrivals cannot grant fresh entitlement');
$GLOBALS['log_fail']=false;unset($GLOBALS['meta'][50]['_roxy_capacity']);
check(\RoxyST\Capacity::remaining_seats_for_showing(50)===null,'blank capacity keeps unlimited policy');
$GLOBALS['meta'][50]['_roxy_capacity']=0;
check(\RoxyST\Capacity::remaining_seats_for_showing(50)===0,'zero capacity remains distinct from unlimited');
$GLOBALS['meta'][50]['_roxy_capacity']=20;$GLOBALS['reservation_fail']=true;
check(\RoxyST\Capacity::remaining_seats_for_showing(50)===0,'unknown reservations fail closed without a public fatal');
$GLOBALS['reservation_fail']=false;$GLOBALS['sold']=PHP_INT_MAX;
check(\RoxyST\Capacity::remaining_seats_for_showing(50)===0,'reservation plus arrival overflow fails closed');
