<?php
// Private Woo refund lifecycle check. Run only inside the disposable WP/Woo CI site.
if (!defined('WP_CLI') || !WP_CLI || !class_exists('WooCommerce')) exit(1);
$root = rtrim((string)($args[0] ?? ''), '/\\');
if ($root === '' || !is_file($root . '/roxy-suite.php') || !function_exists('roxy_rs_repo_effective_backing')) throw new RuntimeException('Requested refund lifecycle source is unavailable.');
$check = static function(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); echo 'PASS: ' . $label . PHP_EOL; };
$suffix = bin2hex(random_bytes(6));
$request_id = 920000 + random_int(1, 9999);
$backing_id = 930000 + random_int(1, 9999);
$product_id = 0; $order_id = 0; $refund_ids = [];
$mail_guard = static function() { return true; };
add_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX);
try {
    $product = new WC_Product_Simple();
    $product->set_name('Private refund fixture ' . $suffix);
    $product->set_status('private');
    $product->set_catalog_visibility('hidden');
    $product->set_tax_status('none');
    $product->set_regular_price('10');
    $product_id = (int)$product->save();
    if ($product_id <= 0) throw new RuntimeException('Could not create the private refund product.');
    update_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, 'adult');

    $order = wc_create_order(['customer_id'=>0]);
    if (is_wp_error($order) || !$order instanceof WC_Order) throw new RuntimeException('Could not create the private refund order.');
    $order->set_created_via('roxy_requested_showings');
    $order->set_currency(strtoupper((string)get_woocommerce_currency()));
    $order->add_meta_data('_roxy_rs_request_id', $request_id, true);
    $order->add_meta_data('_roxy_rs_backing_id', $backing_id, true);
    $line_id = $order->add_product(wc_get_product($product_id), 1, ['subtotal'=>10,'total'=>10]);
    if (!$line_id) throw new RuntimeException('Could not add the private paid-ticket line.');
    $fee = new WC_Order_Item_Fee();
    $fee->set_name('Requested showing sponsorship');
    $fee->set_tax_status('none');
    $fee->set_amount(20);
    $fee->set_total(20);
    $order->add_item($fee);
    $order->calculate_totals(false);
    $order->save();
    $order_id = (int)$order->get_id();
    if ($order_id <= 0 || (int)wc_get_order($order_id)->get_total() !== 30) throw new RuntimeException('Private refund order total did not persist.');

    $row = ['id'=>$backing_id,'request_id'=>$request_id,'status'=>'charged','woo_order_id'=>$order_id,
        'general_qty'=>1,'discount_qty'=>0,'support_qty'=>1,'subscriber_qty'=>0,'sponsor_ticket_qty'=>0,
        'charge_total'=>3000,'sponsor_amount'=>2000];
    $quote = \RoxyRS\Agreement::quote($request_id,'movie_evening',['general'=>1000,'discount'=>800,'matinee'=>800],strtoupper((string)get_woocommerce_currency()));
    $row['agreement_json'] = \RoxyRS\Agreement::build($quote,$row);

    $refund = wc_create_refund(['order_id'=>$order_id,'amount'=>10,'reason'=>'Private fixture line refund','line_items'=>[
        $line_id=>['qty'=>1,'refund_total'=>10,'refund_tax'=>[]],
    ],'refund_payment'=>false,'restock_items'=>false]);
    if (is_wp_error($refund) || !$refund instanceof WC_Order_Refund) throw new RuntimeException('Woo did not create the private line-item refund.');
    $refund_ids[]=(int)$refund->get_id();
    $after_line = roxy_rs_repo_effective_backing($row);
    $check($after_line['charge_total']===2000 && $after_line['support_qty']===0 && $after_line['sponsor_amount']===2000,
        'actual Woo line refund reduces retained funding and paid ticket quantity while preserving the sponsor fee');

    $refund = wc_create_refund(['order_id'=>$order_id,'amount'=>20,'reason'=>'Private fixture amount-only refund','line_items'=>[],
        'refund_payment'=>false,'restock_items'=>false]);
    if (is_wp_error($refund) || !$refund instanceof WC_Order_Refund) throw new RuntimeException('Woo did not create the private amount-only refund.');
    $refund_ids[]=(int)$refund->get_id();
    $after_full = roxy_rs_repo_effective_backing($row);
    $check($after_full['charge_total']===0 && $after_full['sponsor_amount']===0,
        'actual Woo amount-only final refund clears retained funding and sponsorship');
    $check((float)wc_get_order($order_id)->get_total_refunded()===30.0,'Woo persisted both fixture refunds and exposed their cumulative amount');
} finally {
    foreach (array_reverse($refund_ids) as $refund_id) {
        $refund = wc_get_order($refund_id);
        if ($refund instanceof WC_Order_Refund) wp_delete_post($refund_id, true);
    }
    if ($order_id > 0 && wc_get_order($order_id)) wp_delete_post($order_id, true);
    if ($product_id > 0 && get_post_type($product_id) === 'product' && strpos((string)get_the_title($product_id), 'Private refund fixture ' . $suffix) === 0) wp_delete_post($product_id, true);
    remove_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX);
}
