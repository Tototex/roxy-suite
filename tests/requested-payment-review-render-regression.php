<?php
// Read-only admin payment-review panel regression. No WordPress writes or provider calls.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);

class WC_Order {
    public function __construct(
        private array $meta = [],
        private string $created_via = 'roxy_requested_showings',
        private bool $paid = false,
        private int $id = 9001,
        private int $customer_id = 999,
        private float $total = 18.0,
        private string $currency = 'USD'
    ) {}
    public function get_created_via(): string { return $this->created_via; }
    public function get_meta(string $key, bool $single = true) { return $this->meta[$key] ?? ''; }
    public function is_paid(): bool { return $this->paid; }
    public function get_order_number(): string { return (string) $this->id; }
    public function get_id(): int { return $this->id; }
    public function get_customer_id(): int { return $this->customer_id; }
    public function get_total(): float { return $this->total; }
    public function get_currency(): string { return $this->currency; }
    public function get_status(): string { return $this->paid ? 'processing' : 'on-hold'; }
}

$GLOBALS['requested_payment_review_orders'] = [];
$GLOBALS['requested_payment_review_can_edit_ids'] = [9001];
$GLOBALS['requested_payment_review_metaboxes'] = [];
function current_user_can(string $capability, int $object_id = 0): bool {
    return $capability === 'edit_shop_order' && in_array($object_id, $GLOBALS['requested_payment_review_can_edit_ids'], true);
}
function wc_get_order(int $order_id) { return $GLOBALS['requested_payment_review_orders'][$order_id] ?? false; }
function add_meta_box(...$args): void { $GLOBALS['requested_payment_review_metaboxes'][] = $args; }
function wc_price(float $amount, array $args = []): string { return '$' . number_format($amount, 2); }
function wc_get_order_status_name(string $status): string { return ucfirst($status); }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function wp_kses_post(string $value): string { return $value; }
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }

require dirname(__DIR__) . '/includes/modules/requested-showings/includes/class-roxy-rs-conversion.php';

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
};
$render = static function (int $post_id = 9001): string {
    ob_start();
    \RoxyRS\Conversion::render_payment_review_metabox((object) ['ID' => $post_id]);
    return (string) ob_get_clean();
};
$set_order = static function (WC_Order $order): void {
    $GLOBALS['requested_payment_review_orders'][$order->get_id()] = $order;
};
$valid_attempt = [
    'version' => 1,
    'key' => 'fixture-key',
    'hash' => str_repeat('a', 64),
    'order_id' => 9001,
    'request_id' => 55,
    'backing_id' => 77,
    'customer_id' => 999,
    'amount' => 1800,
    'currency' => 'usd',
    'started_at' => '2026-10-07 19:00:00',
];
$valid_result = ['key' => 'fixture-key', 'hash' => str_repeat('a', 64), 'intent_id' => 'pi_abc123', 'status' => 'succeeded'];
$identity_meta = ['_roxy_rs_request_id' => 55, '_roxy_rs_backing_id' => 77];

\RoxyRS\Conversion::register_payment_review_metabox();
$box = $GLOBALS['requested_payment_review_metaboxes'][0] ?? [];
$check(($box[0] ?? '') === 'roxy_rs_payment_review' && ($box[3] ?? '') === 'shop_order', 'review panel registers only on the WooCommerce order screen');

$set_order(new WC_Order($identity_meta + [
    '_roxy_seat_review' => '1',
    '_roxy_rs_payment_attempt' => json_encode($valid_attempt),
    '_roxy_rs_payment_result' => '',
]));
$html = $render();
$check(str_contains($html, 'Payment may need manual reconciliation'), 'uncertain attempt is visibly marked for manager review');
$check(str_contains($html, '9001') && str_contains($html, 'ID 9001') && str_contains($html, '$18.00') && str_contains($html, '55 / 77'), 'order, amount, request and backing identifiers are shown');
$check(str_contains($html, 'Do not retry conversion') && str_contains($html, 'read-only'), 'panel warns against an unsafe retry and has no action controls');
$check(!str_contains($html, 'customer_id') && !str_contains($html, 'payment_method') && !str_contains($html, 'token'), 'private card/token fields are not rendered');

$GLOBALS['requested_payment_review_can_edit_ids'] = [];
$check($render() === '', 'users without order-edit capability see no payment metadata');
$GLOBALS['requested_payment_review_can_edit_ids'] = [9001];
$set_order(new WC_Order($identity_meta, 'roxy_requested_showings', false, 9002));
$check($render(9001) === '', 'a Woo lookup returning a different order ID fails closed');

$valid_marker_json = static function (array $attempt): string { return (string) json_encode($attempt); };
$set_order(new WC_Order($identity_meta + [
    '_roxy_rs_payment_attempt' => $valid_marker_json($valid_attempt),
    '_roxy_rs_payment_result' => (string) json_encode($valid_result),
], 'roxy_requested_showings', true));
$html = $render();
$check(str_contains($html, 'pi_abc123') && str_contains($html, 'succeeded'), 'an order-bound saved PaymentIntent and provider status are shown');

foreach ([
    'order_id' => 9002,
    'request_id' => 56,
    'backing_id' => 78,
    'customer_id' => 1000,
    'amount' => 1900,
    'currency' => 'cad',
] as $field => $wrong_value) {
    $attempt = $valid_attempt;
    $attempt[$field] = $wrong_value;
    $set_order(new WC_Order($identity_meta + [
        '_roxy_rs_payment_attempt' => $valid_marker_json($attempt),
        '_roxy_rs_payment_result' => (string) json_encode($valid_result),
    ], 'roxy_requested_showings', true));
    $html = $render();
    $check(!str_contains($html, 'pi_abc123') && str_contains($html, 'identity') && str_contains($html, 'none verified'), 'receipt is hidden when ' . $field . ' does not match this order');
}

$set_order(new WC_Order($identity_meta + [
    '_roxy_rs_payment_attempt' => '{"key":"fixture-key","hash":"' . str_repeat('a', 64) . '","started_at":"<script>","request_id":55,"backing_id":77}',
    '_roxy_rs_payment_result' => '{"key":"different-key","hash":"' . str_repeat('a', 64) . '","intent_id":"pi_abc123","status":"succeeded"}',
], 'roxy_requested_showings', true));
$html = $render();
$check(!str_contains($html, 'pi_abc123') && str_contains($html, 'none verified'), 'provider result from a different attempt is not presented as verified');
$check(!str_contains($html, '<script>'), 'receipt data is escaped before rendering');
$set_order(new WC_Order([], 'checkout'));
$check($render() === '', 'unrelated WooCommerce orders reveal no Requested Showings panel');

$url_method = new ReflectionMethod(\RoxyRS\Conversion::class, 'verified_review_order_admin_url');
$url_method->setAccessible(true);
$backing = ['id' => 77, 'user_id' => 999];
$link_order = new WC_Order($identity_meta, 'roxy_requested_showings', false, 9001, 999);
$verified_url = static fn($order, int $order_id = 9001, int $request_id = 55, array $linked_backing = ['id' => 77, 'user_id' => 999])
    => $url_method->invoke(null, $order, $order_id, $request_id, $linked_backing);
$check($verified_url($link_order, 9001, 55, $backing) === 'https://example.test/wp-admin/post.php?post=9001&action=edit', 'approval review link is generated for the matching requested-showing backing order');
$check($verified_url(new WC_Order($identity_meta, 'checkout'), 9001, 55, $backing) === '', 'approval review link rejects unrelated Woo order types');
$check($verified_url(new WC_Order($identity_meta, 'roxy_requested_showings', false, 9002), 9001, 55, $backing) === '', 'approval review link rejects order ID mismatch');
$check($verified_url(new WC_Order($identity_meta, 'roxy_requested_showings', false, 9001, 1000), 9001, 55, $backing) === '', 'approval review link rejects a different backing owner');
$check($verified_url(new WC_Order(['_roxy_rs_request_id' => 56, '_roxy_rs_backing_id' => 77]), 9001, 55, $backing) === '', 'approval review link rejects request mismatch');
$check($verified_url(new WC_Order(['_roxy_rs_request_id' => 55, '_roxy_rs_backing_id' => 78]), 9001, 55, $backing) === '', 'approval review link rejects backing mismatch');
$check($verified_url($link_order, 9001, 55, ['id' => 77, 'user_id' => 1000]) === '', 'approval review link rejects backing record owned by another customer');

echo "OK: $checks requested-showing payment-review render checks passed\n";
