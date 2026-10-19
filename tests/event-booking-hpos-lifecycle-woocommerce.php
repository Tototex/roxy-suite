<?php
/** Private HPOS Event Booking lifecycle. Synthetic zero-value order; mail intercepted. */
if (!defined('WP_CLI') || !WP_CLI || !class_exists('WooCommerce') || !class_exists('RoxySuite\\Compatibility')) {
    throw new RuntimeException('This fixture requires the active Roxy Suite, Event Booking, and WooCommerce modules.');
}
$order_util='Automattic\\WooCommerce\\Utilities\\OrderUtil';
if (!class_exists($order_util) || !$order_util::custom_orders_table_usage_is_enabled()) {
    throw new RuntimeException('This fixture requires authoritative HPOS storage.');
}
if (!function_exists('roxy_eb_on_payment_complete') || !function_exists('roxy_eb_start_booking_adjustment_checkout')) {
    throw new RuntimeException('Event Booking WooCommerce handlers are unavailable.');
}

$checks=0;
$check=static function(bool $ok,string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    echo 'PASS: '.$label.PHP_EOL;
};
$saved_settings=get_option(roxy_eb_option_key(),null);
$product=null;
$order=null;
$booking_id=0;
$mail_calls=0;
$mail_guard=static function($short_circuit,$args) use (&$mail_calls) {
    $mail_calls++;
    return true;
};
add_filter('pre_wp_mail',$mail_guard,PHP_INT_MAX,2);
try {
    $product=new WC_Product_Simple();
    $product->set_name('Private HPOS Event Booking Fixture');
    $product->set_status('private');
    $product->set_catalog_visibility('hidden');
    $product->set_virtual(true);
    $product->set_regular_price('0');
    $product->set_price('0');
    $product->save();
    if ((int)$product->get_id()<=0) throw new RuntimeException('Could not create private zero-value booking product.');

    update_option(roxy_eb_option_key(),array_merge(roxy_eb_get_settings(),[
        'booking_product_id'=>(int)$product->get_id(),
        'sling_mode'=>'disabled',
        'pizza_reminders_enabled'=>false,
    ]),false);

    $payload=[
        'first_name'=>'Private','last_name'=>'HPOS Fixture','email'=>'hpos-fixture@example.invalid','phone'=>'0000000000',
        'customer_type'=>'personal','business_name'=>'','payment_method'=>'pay_now','guest_count'=>12,
        'event_format'=>'movie','movie_title'=>'Synthetic Lifecycle','live_description'=>'','visibility'=>'private','notes'=>'',
        'doors_open_at'=>'2042-04-15 17:00:00','show_start_at'=>'2042-04-15 18:00:00','doors_close_at'=>'2042-04-15 21:00:00',
        'reserved_start_at'=>'2042-04-15 16:30:00','reserved_end_at'=>'2042-04-15 21:30:00',
        'extra_hours'=>0,'base_price'=>0,'extra_price'=>0,'total_price'=>0,
        'pizza_requested'=>0,'pizza_quantity'=>0,'pizza_order_details'=>'','pizza_total'=>0,
        'bulk_concessions_requested'=>0,'bulk_popcorn_qty'=>0,'bulk_soda_qty'=>0,'bulk_concessions_total'=>0,
        'special_charge_label'=>'','special_charge_total'=>0,
    ];
    $order=wc_create_order(['status'=>'pending','customer_id'=>0]);
    if (is_wp_error($order) || !$order instanceof WC_Order) throw new RuntimeException('Could not create private HPOS order.');
    if ($order->add_product($product,1)<=0) throw new RuntimeException('Could not add private booking product to HPOS order.');
    $items=$order->get_items();
    $item=$items ? reset($items) : false;
    if (!$item instanceof WC_Order_Item_Product) throw new RuntimeException('Private booking order item is unavailable.');
    $item->add_meta_data('_roxy_eb_booking',wp_json_encode($payload),true);
    $item->save();
    $order->calculate_totals();
    $order->set_status('processing'); // Synthetic paid-state marker only; no gateway/provider call.
    $order->save();
    $order_id=(int)$order->get_id();
    if ((int)round((float)$order->get_total()*100)!==0) throw new RuntimeException('The HPOS lifecycle fixture must remain zero-value.');

    do_action('woocommerce_payment_complete',$order_id);
    $booking=roxy_eb_repo_get_booking_by_order($order_id);
    if (!$booking) throw new RuntimeException('HPOS payment-complete hook did not persist its booking.');
    $booking_id=(int)$booking['id'];
    $check($booking_id>0 && (int)$booking['woo_order_id']===$order_id,'HPOS payment completion links one private booking to its Woo order');
    $check($booking['customer_email']==='hpos-fixture@example.invalid' && $booking['event_format']==='movie','HPOS CRUD item metadata is decoded into the expected booking');

    do_action('woocommerce_payment_complete',$order_id);
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.roxy_eb_table_bookings().'` WHERE woo_order_id=%d',$order_id))===1,'replayed HPOS payment-complete hook does not duplicate the booking');

    $change=[
        'delta_total'=>100,'expected_revision'=>roxy_eb_booking_revision($booking),
        'duration_hours'=>2,'extra_hours'=>0,'show_start_at'=>$booking['show_start_at'],
        'doors_close_at'=>$booking['doors_close_at'],'reserved_start_at'=>$booking['reserved_start_at'],
        'reserved_end_at'=>$booking['reserved_end_at'],'total_price'=>100,
    ];
    $cart_result=roxy_eb_start_booking_adjustment_checkout($booking,$change);
    $cart=WC()->cart;
    $cart_items=$cart && method_exists($cart,'get_cart') ? $cart->get_cart() : [];
    $found=false;
    foreach($cart_items as $cart_item) {
        if ((int)($cart_item['product_id']??0)===(int)$product->get_id()
            && (int)(($cart_item[roxy_eb_booking_adjustment_meta_key()]['booking_id']??0))===$booking_id) $found=true;
    }
    $check(!is_wp_error($cart_result) && is_string($cart_result) && $found,'HPOS booking adjustment starts a Woo cart with the matching booking identity');
    if ($cart && method_exists($cart,'empty_cart')) $cart->empty_cart(true);
    $check($mail_calls>0,'booking confirmation email attempts were intercepted before transport');
} finally {
    remove_filter('pre_wp_mail',$mail_guard,PHP_INT_MAX);
    if (function_exists('roxy_eb_clear_pizza_reminders') && $booking_id>0) roxy_eb_clear_pizza_reminders($booking_id);
    global $wpdb;
    if ($order instanceof WC_Order) {
        $wpdb->delete(roxy_eb_table_bookings(),['woo_order_id'=>(int)$order->get_id()],['%d']);
        $order->delete(true);
    }
    if ($product instanceof WC_Product) $product->delete(true);
    if ($saved_settings===null) delete_option(roxy_eb_option_key());
    else update_option(roxy_eb_option_key(),$saved_settings,false);
    if (WC()->cart && method_exists(WC()->cart,'empty_cart')) WC()->cart->empty_cart(true);
}
echo 'Passed '.$checks.' private HPOS Event Booking lifecycle checks. No payment provider or outbound mail transport was used.'.PHP_EOL;
