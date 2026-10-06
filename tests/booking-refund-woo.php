<?php
// Installed Woo contract test; request-local fake gateway, private synthetic orders.
if (!defined('WP_CLI') || !WP_CLI) exit;
$root = $args[0] ?? dirname(__DIR__);
if (!function_exists('roxy_eb_refund_booking_payment')) require_once $root . '/includes/modules/event-booking/includes/refunds.php';
add_filter('pre_wp_mail', static fn() => true, PHP_INT_MAX);
class RoxyBookingFixtureGateway extends WC_Payment_Gateway {
    public function __construct() { $this->id = 'roxy_private_refund_fixture'; $this->supports = ['refunds']; }
    public function process_refund($order_id, $amount = null, $reason = '') {
        if (!in_array((int) $order_id, $GLOBALS['roxy_refund_fixture_orders'], true)) throw new RuntimeException('Not a fixture order.');
        $GLOBALS['roxy_refund_fixture_calls'][] = [(int) $order_id, (float) $amount];
        if (($GLOBALS['roxy_refund_fixture_mode'] ?? '') === 'error') return new WP_Error('fixture_gateway_refused', 'Synthetic gateway refused.');
        return true; // Never contacts a payment processor.
    }
}
$GLOBALS['roxy_refund_fixture_orders'] = [];
$GLOBALS['roxy_refund_fixture_calls'] = [];
$gateway = new RoxyBookingFixtureGateway();
$filter = static function ($gateways) use ($gateway) { $gateways[$gateway->id] = $gateway; return $gateways; };
add_filter('woocommerce_payment_gateways', $filter);
WC_Payment_Gateways::instance()->init();
$check = static function ($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; };
global $wpdb;
$booking_digest = static function () use ($wpdb) { $rows = $wpdb->get_results('SELECT * FROM ' . roxy_eb_table_bookings() . ' ORDER BY id', ARRAY_A); if ($wpdb->last_error) throw new RuntimeException('Booking baseline read failed'); return hash('sha256', wp_json_encode($rows)); };
$before = $booking_digest();
$make = static function () use ($gateway) {
    $order = wc_create_order(['status' => 'pending', 'customer_note' => 'PRIVATE REFUND FIXTURE — fake gateway only']);
    if (is_wp_error($order)) throw new RuntimeException('Fixture order creation failed.');
    $GLOBALS['roxy_refund_fixture_orders'][] = $order->get_id();
    $booking = new WC_Order_Item_Product(); $booking->set_name('PRIVATE BOOKING FIXTURE'); $booking->set_quantity(1); $booking->set_subtotal(140); $booking->set_total(100); $booking->set_taxes(['total' => [999 => 10], 'subtotal' => [999 => 14]]); $booking->add_meta_data('_roxy_eb_booking_id', 700000001, true); $booking->add_meta_data('_roxy_eb_booking', wp_json_encode(['doors_open_at' => '2700-01-01 12:00:00']), true); $order->add_item($booking);
    $other = new WC_Order_Item_Product(); $other->set_name('PRIVATE UNRELATED FIXTURE'); $other->set_quantity(2); $other->set_subtotal(40); $other->set_total(40); $other->set_taxes(['total' => [999 => 4], 'subtotal' => [999 => 4]]); $order->add_item($other);
    $order->set_payment_method($gateway->id); $order->set_date_paid(time()); $order->set_status('processing'); $order->calculate_totals(false); $order->save();
    return [$order, $booking->get_id(), $other->get_id()];
};
try {
    [$order, $item_id, $other_id] = $make();
    $plan = roxy_eb_booking_refund_plan($order, 700000001);
    $check(!is_wp_error($plan) && abs($plan['amount'] - 110) < .001 && count($plan['line_items']) === 1, 'actual Woo mixed-cart plan uses discounted booking total plus item tax');
    $prior = wc_create_refund(['order_id' => $order->get_id(), 'amount' => 33, 'refund_payment' => true, 'line_items' => [$item_id => ['qty' => 0, 'refund_total' => 30, 'refund_tax' => [999 => 3]]]]);
    $check(!is_wp_error($prior) && $prior->get_refunded_payment(), 'installed Woo creates item-allocated synthetic gateway refund');
    $result = roxy_eb_refund_booking_payment(wc_get_order($order->get_id()), 700000001);
    $check(!is_wp_error($result) && $result['refunded'] && abs($result['amount'] - 77) < .001, 'installed Woo remainder refund excludes previous total and tax');
    $fresh = wc_get_order($order->get_id());
    $check(abs($fresh->get_total_refunded_for_item($item_id) - 100) < .001 && abs($fresh->get_tax_refunded_for_item($item_id, 999) - 10) < .001 && (float) $fresh->get_total_refunded_for_item($other_id) === 0.0, 'actual refund lines preserve unrelated purchase and exhaust booking balance only');
    $calls = count($GLOBALS['roxy_refund_fixture_calls']);
    $repeat = roxy_eb_refund_booking_payment($fresh, 700000001);
    $check(!is_wp_error($repeat) && !$repeat['refunded'] && count($GLOBALS['roxy_refund_fixture_calls']) === $calls, 'repeated actual refund cannot charge gateway twice');
    [$bad] = $make(); $GLOBALS['roxy_refund_fixture_mode'] = 'error';
    $failure = roxy_eb_refund_booking_payment($bad, 700000001);
    $check(is_wp_error($failure), 'installed Woo propagates synthetic gateway refusal');
    $calls = count($GLOBALS['roxy_refund_fixture_calls']); $GLOBALS['roxy_refund_fixture_mode'] = '';
    $retry = roxy_eb_refund_booking_payment(wc_get_order($bad->get_id()), 700000001);
    $check(is_wp_error($retry) && $retry->get_error_code() === 'refund_review' && count($GLOBALS['roxy_refund_fixture_calls']) === $calls, 'persisted uncertain claim blocks actual retry even after gateway recovery');
    [$conflict, $conflict_id] = $make();
    $result = roxy_eb_refund_booking_payment($conflict, 0, $conflict_id, 'Private conflict fixture');
    $check(!is_wp_error($result) && $result['amount'] === 110.0, 'conflict refund targets exact original booking line');
    [$contended] = $make();
    $peer = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $lock_key = 'roxy-eb-refund-' . substr(hash('sha256', $wpdb->prefix . ':' . $contended->get_id()), 0, 40);
    if ((string) $peer->get_var($peer->prepare('SELECT GET_LOCK(%s, 0)', $lock_key)) !== '1') throw new RuntimeException('Cannot acquire private fixture peer lock');
    try {
        $calls = count($GLOBALS['roxy_refund_fixture_calls']);
        $result = roxy_eb_refund_booking_payment($contended, 700000001);
        $check(is_wp_error($result) && $result->get_error_code() === 'refund_busy' && count($GLOBALS['roxy_refund_fixture_calls']) === $calls, 'independent actual database connection blocks concurrent refund before gateway');
    } finally {
        $peer->get_var($peer->prepare('SELECT RELEASE_LOCK(%s)', $lock_key));
        $peer->close();
    }
    if ((new ReflectionFunction('roxy_eb_handle_conflict_refund'))->getNumberOfParameters() >= 3) {
        [$via_handler, $handler_id, $unrelated_id] = $make();
        roxy_eb_handle_conflict_refund($via_handler, ['total_price' => 2700], $handler_id);
        $fresh = wc_get_order($via_handler->get_id());
        $notes = wc_get_order_notes(['order_id' => $fresh->get_id(), 'limit' => 50]);
        $text = implode('\n', array_map(static function ($note) { return $note->content; }, $notes));
        $check(abs($fresh->get_total_refunded() - 110) < .001 && (float) $fresh->get_total_refunded_for_item($unrelated_id) === 0.0 && strpos($text, 'Gateway refunded booking item balance') !== false, 'deployed conflict handler refunds exact balance and records confirmed success');
        [$via_failure, $failure_id] = $make();
        $GLOBALS['roxy_refund_fixture_mode'] = 'error';
        roxy_eb_handle_conflict_refund($via_failure, [], $failure_id);
        $GLOBALS['roxy_refund_fixture_mode'] = '';
        $notes = wc_get_order_notes(['order_id' => $via_failure->get_id(), 'limit' => 50]);
        $text = implode('\n', array_map(static function ($note) { return $note->content; }, $notes));
        $check((float) wc_get_order($via_failure->get_id())->get_total_refunded() === 0.0 && strpos($text, 'needs manager review') !== false && strpos($text, 'Gateway refunded booking item balance') === false, 'deployed conflict handler does not claim success after gateway refusal');
    }
} finally {
    remove_filter('woocommerce_payment_gateways', $filter);
    foreach ($GLOBALS['roxy_refund_fixture_orders'] as $id) {
        $order = wc_get_order($id);
        if ($order) { foreach ($order->get_refunds() as $refund) $refund->delete(true); $order->delete(true); }
    }
}
$check($before === $booking_digest(), 'all original booking rows unchanged');
foreach ($GLOBALS['roxy_refund_fixture_orders'] as $id) $check(!wc_get_order($id), 'private fixture order removed: ' . $id);
echo "Private orders/refunds removed; no external gateway or delivered email.\n";
