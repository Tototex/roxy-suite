<?php
// wp eval-file tests/requested-agreement-woocommerce.php [candidate-root]
// Exercises installed Woo order math with private fixtures; no charge or real backing.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
$root = $args[0] ?? dirname(__DIR__);
foreach (['/includes/modules/requested-showings/includes/class-roxy-rs-conversion.php', '/includes/modules/requested-showings/includes/class-roxy-rs-agreement.php'] as $file) {
    if (!is_file($root . $file)) throw new RuntimeException('Requested Showings candidate file is missing: ' . $file);
}

$namespace = 'RequestedAgreementWooFixture' . bin2hex(random_bytes(4));
$conversion_source = file_get_contents($root . '/includes/modules/requested-showings/includes/class-roxy-rs-conversion.php');
$conversion_source = str_replace('namespace RoxyRS;', 'namespace ' . $namespace . ';', $conversion_source);
eval('?>' . $conversion_source);
$conversion = $namespace . '\\Conversion';
$conversion::init();
$set_gross = new ReflectionMethod($conversion, 'set_agreed_gross');
$set_gross->setAccessible(true);
$set_fee = new ReflectionMethod($conversion, 'set_agreed_fee_gross');
$set_fee->setAccessible(true);
$verify_items = new ReflectionMethod($conversion, 'verify_agreement_items');
$verify_items->setAccessible(true);
$agreement_items_hash = new ReflectionMethod($conversion, 'agreement_items_hash');
$agreement_items_hash->setAccessible(true);
$verified_marker = new ReflectionMethod($conversion, 'has_verified_agreement_marker');
$verified_marker->setAccessible(true);

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
};
$block_mail = static function () { return true; };
add_filter('pre_wp_mail', $block_mail, PHP_INT_MAX);
$order = null;
$zero_order = null;
$product_id = 0;
try {
    $product = new WC_Product_Simple();
    $product->set_name('Private requested-agreement test product');
    $product->set_status('private');
    $product->set_tax_status('none');
    $product->set_price('999.99'); // Deliberately unlike the amount the pledge agreed to.
    $product_id = $product->save();
    if (!$product_id) throw new RuntimeException('Could not create private Woo product fixture.');

    $order = wc_create_order(['customer_id' => 0]);
    if (is_wp_error($order) || !$order instanceof WC_Order) throw new RuntimeException('Could not create private Woo order fixture.');
    $base = wc_get_base_location();
    $order->set_billing_country((string) ($base['country'] ?? 'US'));
    $order->set_billing_state((string) ($base['state'] ?? ''));
    $order->set_billing_postcode((string) get_option('woocommerce_store_postcode', '92663'));
    $order->set_billing_city((string) get_option('woocommerce_store_city', 'Newport Beach'));
    $order->set_created_via('roxy_requested_showings');
    $order->add_meta_data('_roxy_rs_agreement_hash', str_repeat('a', 64), true);
    $item_id = $order->add_product(wc_get_product($product_id), 2);
    $item = $item_id ? $order->get_item($item_id, false) : false;
    if (!$item) throw new RuntimeException('Could not add private Woo ticket fixture.');
    $check($set_gross->invoke(null, $order, $item, 2400) === true, 'installed Woo accepts a $24.00 nontaxable agreement despite a changed catalog price');
    $cached_ticket = $order->get_item($item_id, false);
    $check($cached_ticket && (int) round((float) $cached_ticket->get_total() * 100) === 2400 && (float) $cached_ticket->get_total_tax() === 0.0,
        'agreed whole-dollar amount remains on the cached line without tax');

    $fee = new WC_Order_Item_Fee();
    $fee->set_name('Requested showing sponsorship');
    $check($set_fee->invoke(null, $order, $fee, 500) === true, 'installed Woo records a $5.00 nontaxable sponsorship amount');
    $order->add_item($fee);
    $agreement = [
        'quantities' => ['general_qty'=>2,'discount_qty'=>0,'subscriber_qty'=>0,'sponsor_ticket_qty'=>0],
        'unit_prices_cents' => ['general'=>1200,'discount'=>800,'matinee'=>800],
        'sponsor_amount_cents' => 500,
    ];
    $order->update_taxes();
    $order->calculate_totals(false);
    $order->save();
    $reloaded = wc_get_order($order->get_id());
    $original_ledger_hash = $agreement_items_hash->invoke(null, $reloaded);
    if (!is_string($original_ledger_hash) || $original_ledger_hash === '') throw new RuntimeException('Could not fingerprint saved Woo agreement fixture.');
    $reloaded->update_meta_data('_roxy_rs_agreement_ledger_hash', $original_ledger_hash);
    $reloaded->save();
    $reloaded = wc_get_order($order->get_id());
    $persisted_lines = $reloaded instanceof WC_Order ? $reloaded->get_items('line_item') : [];
    $persisted_line_net = array_sum(array_map(static fn($line) => (float) $line->get_total(), $persisted_lines));
    $persisted_total_cents = $reloaded instanceof WC_Order ? (int) round((float) $reloaded->get_total() * 100) : -1;
    $check($reloaded instanceof WC_Order && $persisted_total_cents === 2900, 'persisted Woo order total stays exactly $29.00 with no tax (' . $persisted_total_cents . ' cents; lines=' . count($persisted_lines) . '; line cents=' . (int) round($persisted_line_net * 100) . ')');
    $persisted_item = array_values($reloaded->get_items('line_item'))[0] ?? null;
    $persisted_fee = array_values($reloaded->get_items('fee'))[0] ?? null;
    $check($persisted_item && (int) round((float) $persisted_item->get_total() * 100) === 2400 && (float) $persisted_item->get_total_tax() === 0.0, 'saved ticket line remains $24.00 with zero tax');
    if (!$persisted_item || !$persisted_fee) throw new RuntimeException('Saved Woo agreement fixture is incomplete.');
    $check($verified_marker->invoke(null, $reloaded) === true, 'customer display recognizes an unchanged agreement ledger');
    $reloaded->update_meta_data('_roxy_rs_agreement_ledger_hash', str_repeat('b', 64));
    $check($verified_marker->invoke(null, $reloaded) === false, 'customer display rejects a missing or mismatched saved ledger fingerprint');
    $reloaded->update_meta_data('_roxy_rs_agreement_ledger_hash', $original_ledger_hash);
    $display_line = $reloaded->get_formatted_line_subtotal($persisted_item);
    $check(str_contains(wp_strip_all_tags($display_line), '24.00') && !str_contains(wp_strip_all_tags($display_line), '22.30'), 'customer order line shows the $24.00 agreed gross rather than Woo tax-exclusive net');
    $display_rows = $reloaded->get_order_item_totals('excl');
    $display_values = array_map(static fn($row) => wp_strip_all_tags((string) ($row['value'] ?? '')), $display_rows);
    $check(isset($display_rows['cart_subtotal']) && str_contains($display_values['cart_subtotal'], '24.00'), 'customer order summary shows the gross ticket subtotal');
    $check(isset($display_rows['fee_' . $persisted_fee->get_id()]) && str_contains($display_values['fee_' . $persisted_fee->get_id()], '5.00'), 'customer order summary shows the gross sponsorship amount');
    $check(count(array_filter($display_rows, static fn($row) => ($row['type'] ?? '') === 'tax')) === 0 && (float) $reloaded->get_total_tax() === 0.0, 'customer summary and Woo tax ledger report no ticket or sponsorship tax');
    $display_total = $reloaded->get_formatted_order_total('incl');
    $check(str_contains(wp_strip_all_tags($display_total), '29.00') && !str_contains($display_total, 'includes_tax'), 'customer order total remains $29.00 without adding ticket or sponsorship tax detail');
    $product_map = ['adult'=>$product_id,'discount'=>0,'matinee'=>0,'subscriber'=>0];
    $check($verify_items->invoke(null, $reloaded, $reloaded->get_items('line_item'), $product_map, 'movie_evening', $agreement) === true, 'installed Woo order itemization matches the saved ticket and sponsorship agreement');
    $check((float) $reloaded->get_total_tax() === 0.0, 'Woo records no tax for the nontaxable requested-showing order');
    $item_net_total = array_sum(array_map(static fn($line) => (float) $line->get_total(), $reloaded->get_items('line_item')));
    $fee_net_total = array_sum(array_map(static fn($line) => (float) $line->get_total(), $reloaded->get_items('fee')));
    $check((int) round(((float) $reloaded->get_total() - $item_net_total - $fee_net_total) * 100) === 0,
        'Woo order gross is exactly the untaxed ticket and sponsorship line amounts');
    $check(is_string($original_ledger_hash) && $original_ledger_hash !== '', 'installed Woo order produces a stable item-and-tax ledger fingerprint');

    $changed_product = wc_get_product($product_id);
    $changed_product->set_tax_status('taxable');
    $changed_product->save();
    clean_post_cache($product_id);
    $tax_status_order = wc_get_order($reloaded->get_id());
    $check($tax_status_order instanceof WC_Order
        && $agreement_items_hash->invoke(null, $tax_status_order) !== $original_ledger_hash,
        'tax-status changes on an agreed ticket product change the ledger fingerprint');
    $taxable_item = array_values($tax_status_order->get_items('line_item'))[0] ?? null;
    $check($taxable_item && $set_gross->invoke(null, $tax_status_order, $taxable_item, 2400) === false,
        'conversion refuses a ticket product accidentally configured as taxable');
    $changed_product = wc_get_product($product_id);
    $changed_product->set_tax_status('none');
    $changed_product->save();
    clean_post_cache($product_id);
    $reloaded = wc_get_order($reloaded->get_id());

    $persisted_item = array_values($reloaded->get_items('line_item'))[0] ?? null;
    $persisted_fee = array_values($reloaded->get_items('fee'))[0] ?? null;
    if (!$persisted_item || !$persisted_fee) throw new RuntimeException('Saved Woo agreement fixture is incomplete.');
    $check($set_gross->invoke(null, $reloaded, $persisted_item, 2500) === true
        && $set_fee->invoke(null, $reloaded, $persisted_fee, 400) === true, 'fixture can model offsetting edits while retaining the same order total');
    $reloaded->update_taxes();
    $reloaded->calculate_totals(false);
    $reloaded->save();
    $offset_order = wc_get_order($reloaded->get_id());
    $check($offset_order instanceof WC_Order && (int) round((float) $offset_order->get_total() * 100) === 2900, 'offsetting line edits leave the same $29.00 grand total');
    $check($verify_items->invoke(null, $offset_order, $offset_order->get_items('line_item'), $product_map, 'movie_evening', $agreement) === false, 'item-level verification rejects offsetting edits before any charge');

    $zero_order = wc_create_order(['customer_id' => 0]);
    if (is_wp_error($zero_order) || !$zero_order instanceof WC_Order) throw new RuntimeException('Could not create private zero-price Woo order fixture.');
    $zero_item_id = $zero_order->add_product(wc_get_product($product_id), 1);
    $zero_item = $zero_item_id ? $zero_order->get_item($zero_item_id, false) : false;
    if (!$zero_item) throw new RuntimeException('Could not retrieve the cached zero-price Woo item fixture.');
    $zero_item->set_subtotal(0);
    $zero_item->set_total(0);
    $zero_item->set_taxes(false);
    $zero_item->save();
    $zero_order->update_taxes();
    $zero_order->calculate_totals(false);
    $zero_order->save();
    $zero_order = wc_get_order($zero_order->get_id());
    $zero_line = $zero_order instanceof WC_Order ? array_values($zero_order->get_items('line_item'))[0] ?? null : null;
    $check($zero_order instanceof WC_Order && (int) round((float) $zero_order->get_total() * 100) === 0
        && $zero_line && (int) round(((float) $zero_line->get_total() + (float) $zero_line->get_total_tax()) * 100) === 0,
        'free subscriber/sponsor ticket remains zero after Woo recalculates cached order items');
} finally {
    if ($order instanceof WC_Order && $order->get_id() && method_exists($order, 'delete')) $order->delete(true);
    if ($zero_order instanceof WC_Order && $zero_order->get_id() && method_exists($zero_order, 'delete')) $zero_order->delete(true);
    if ($product_id) wp_delete_post($product_id, true);
    remove_filter('pre_wp_mail', $block_mail, PHP_INT_MAX);
}
echo "OK: $checks installed WooCommerce agreement checks passed\n";
