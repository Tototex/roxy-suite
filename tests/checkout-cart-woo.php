<?php
// WP-CLI only. Disposable user and $0 order; no gateway, stock or mail.
if (!defined('WP_CLI') || !WP_CLI) exit;
$root=$args[0]??dirname(__DIR__);
if(class_exists('Roxy_Suite_Checkout')) {
    remove_action('woocommerce_before_cart_emptied',['Roxy_Suite_Checkout','capture'],10);
    remove_filter('woocommerce_checkout_no_payment_needed_redirect',['Roxy_Suite_Checkout','complete'],10);
}
$source=file_get_contents($root.'/includes/class-roxy-suite-checkout.php');
$source=preg_replace('/^<\?php\s*/','',$source,1);
$source=str_replace('final class Roxy_Suite_Checkout {','final class CartFixtureCheckout {',$source);
eval($source);CartFixtureCheckout::boot();
add_filter('pre_wp_mail',static fn()=>true);
$user=0;$order=null;$old_user=get_current_user_id();
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
try {
    $created_user=wp_insert_user(['user_login'=>'roxy_cart_fixture_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(40),'role'=>'subscriber']);
    if(is_wp_error($created_user))throw new RuntimeException('Cannot create private user');
    $user=(int)$created_user;
    wp_set_current_user($user);
    WC()->session=new WC_Session_Handler();WC()->session->init();WC()->cart=new WC_Cart();
    $product=new WC_Product_Simple();$product->set_name('PRIVATE CART FIXTURE');$product->set_regular_price('20');
    $items=['fixture'=>['product_id'=>0,'variation_id'=>0,'variation'=>[],'quantity'=>1,'data'=>$product,'line_subtotal'=>20,'line_subtotal_tax'=>0,'line_total'=>20,'line_tax'=>0,'line_tax_data'=>['total'=>[],'subtotal'=>[]]]];
    $cart_session=new WC_Cart_Session(WC()->cart);
    $prepare=static function()use($items,$cart_session){WC()->cart->set_cart_contents($items);$cart_session->persistent_cart_update();};
    $prepare();$key='_woocommerce_persistent_cart_'.get_current_blog_id();
    $check(count(get_user_meta($user,$key,true)['cart']??[])===1,'actual Woo persistent cart contains fixture');
    $order=wc_create_order(['status'=>'processing','customer_id'=>$user,'customer_note'=>'PRIVATE CART FIXTURE 2026-10-05 — no fulfillment']);
    if(is_wp_error($order))throw new RuntimeException('Cannot create private zero-dollar order');
    $order->set_total(0);$order->set_cart_hash(WC()->cart->get_cart_hash());$order->save();
    WC()->cart->empty_cart(false);
    $check(WC()->cart->is_empty()&&is_array(get_user_meta($user,$key,true)),'actual core temporary clear retains saved cart');
    $check(apply_filters('woocommerce_checkout_no_payment_needed_redirect','unchanged-url',$order)==='unchanged-url','redirect preserved');
    $check(get_user_meta($user,$key,true)==='','actual expected-value metadata deletion removes completed saved cart');
    $prepare();$order->set_cart_hash(WC()->cart->get_cart_hash());WC()->cart->empty_cart(false);
    $new=get_user_meta($user,$key,true);$new['cart']['fixture']['quantity']=2;update_user_meta($user,$key,$new);
    apply_filters('woocommerce_checkout_no_payment_needed_redirect','unchanged-url',$order);
    $check(get_user_meta($user,$key,true)['cart']['fixture']['quantity']===2,'actual metadata compare-and-delete preserves concurrent newer cart');
    $prepare();$order->set_cart_hash(WC()->cart->get_cart_hash());WC()->cart->empty_cart(false);
    $injected=false;
    $race=static function($sql)use(&$injected,$user,$key){global $wpdb;if(!$injected&&strpos($sql,"DELETE FROM `{$wpdb->usermeta}` WHERE user_id=".$user.' ')===0){$injected=true;$new=get_user_meta($user,$key,true);$new['cart']['fixture']['quantity']=3;update_user_meta($user,$key,$new);}return $sql;};
    add_filter('query',$race);
    try {apply_filters('woocommerce_checkout_no_payment_needed_redirect','unchanged-url',$order);}
    finally {remove_filter('query',$race);}
    $check($injected&&get_user_meta($user,$key,true)['cart']['fixture']['quantity']===3,'newer write immediately before actual DELETE remains protected by SQL predicate');
    $prepare();$order->set_cart_hash('different-cart');WC()->cart->empty_cart(false);
    apply_filters('woocommerce_checkout_no_payment_needed_redirect','unchanged-url',$order);
    $check(is_array(get_user_meta($user,$key,true)),'actual unrelated order/cart hash cannot delete saved cart');
    echo "CHECKOUT_CART_WOO_OK\n";
} finally {
    if($order instanceof WC_Order)$order->delete(true);
    if($user>0){if(WC()->session)WC()->session->destroy_session();require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user);}
    wp_set_current_user($old_user);
}
