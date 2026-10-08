<?php
// Read-only admin payment-review panel regression. No WordPress writes or provider calls.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);

class WC_Order {
    public function __construct(private array $meta = [], private string $created_via = 'roxy_requested_showings', private bool $paid = false) {}
    public function get_created_via(): string { return $this->created_via; }
    public function get_meta(string $key, bool $single = true) { return $this->meta[$key] ?? ''; }
    public function is_paid(): bool { return $this->paid; }
    public function get_order_number(): string { return '9001'; }
    public function get_id(): int { return 9001; }
    public function get_total(): float { return 18.0; }
    public function get_currency(): string { return 'USD'; }
    public function get_status(): string { return $this->paid ? 'processing' : 'on-hold'; }
}

$GLOBALS['requested_payment_review_order'] = null;
$GLOBALS['requested_payment_review_can_edit'] = true;
$GLOBALS['requested_payment_review_metaboxes'] = [];
function current_user_can(string $capability, int $object_id = 0): bool { return $GLOBALS['requested_payment_review_can_edit']; }
function wc_get_order(int $order_id) { return $GLOBALS['requested_payment_review_order']; }
function add_meta_box(...$args): void { $GLOBALS['requested_payment_review_metaboxes'][] = $args; }
function wc_price(float $amount, array $args = []): string { return '$' . number_format($amount, 2); }
function wc_get_order_status_name(string $status): string { return ucfirst($status); }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function wp_kses_post(string $value): string { return $value; }

require dirname(__DIR__) . '/includes/modules/requested-showings/includes/class-roxy-rs-conversion.php';

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
};
$render = static function () {
    ob_start();
    \RoxyRS\Conversion::render_payment_review_metabox((object) ['ID' => 9001]);
    return (string) ob_get_clean();
};

\RoxyRS\Conversion::register_payment_review_metabox();
$box = $GLOBALS['requested_payment_review_metaboxes'][0] ?? [];
$check(($box[0] ?? '') === 'roxy_rs_payment_review' && ($box[3] ?? '') === 'shop_order', 'review panel registers only on the WooCommerce order screen');

$GLOBALS['requested_payment_review_order'] = new WC_Order([
    '_roxy_seat_review' => '1',
    '_roxy_rs_payment_attempt' => json_encode([
        'request_id'=>55,'backing_id'=>77,'customer_id'=>999,'amount'=>1800,'currency'=>'usd','started_at'=>'2026-10-07 19:00:00',
    ]),
    '_roxy_rs_payment_result' => '',
]);
$html = $render();
$check(str_contains($html, 'Payment may need manual reconciliation'), 'uncertain attempt is visibly marked for manager review');
$check(str_contains($html, '9001') && str_contains($html, 'ID 9001') && str_contains($html, '$18.00') && str_contains($html, '55 / 77'), 'order, amount, request and backing identifiers are shown');
$check(str_contains($html, 'Do not retry conversion') && str_contains($html, 'read-only'), 'panel warns against an unsafe retry and has no action controls');
$check(!str_contains($html, 'customer_id') && !str_contains($html, 'payment_method') && !str_contains($html, 'token'), 'private card/token fields are not rendered');

$GLOBALS['requested_payment_review_can_edit'] = false;
$check($render() === '', 'users without order-edit capability see no payment metadata');
$GLOBALS['requested_payment_review_can_edit'] = true;
$GLOBALS['requested_payment_review_order'] = new WC_Order([
    '_roxy_rs_payment_attempt' => '{"key":"fixture-key","hash":"' . str_repeat('a', 64) . '","started_at":"<script>","request_id":55,"backing_id":77}',
    '_roxy_rs_payment_result' => '{"key":"fixture-key","hash":"' . str_repeat('a', 64) . '","intent_id":"pi_abc123","status":"succeeded"}',
], 'roxy_requested_showings', true);
$html = $render();
$check(str_contains($html, 'pi_abc123') && str_contains($html, 'succeeded'), 'saved valid PaymentIntent and provider status are shown');
$check(!str_contains($html, '<script>'), 'receipt data is escaped before rendering');
$GLOBALS['requested_payment_review_order'] = new WC_Order([
    '_roxy_rs_payment_attempt' => '{"key":"fixture-key","hash":"' . str_repeat('a', 64) . '"}',
    '_roxy_rs_payment_result' => '{"key":"different-key","hash":"' . str_repeat('a', 64) . '","intent_id":"pi_abc123","status":"succeeded"}',
], 'roxy_requested_showings', true);
$html = $render();
$check(!str_contains($html, 'pi_abc123') && str_contains($html, 'none verified'), 'provider result from a different attempt is not presented as verified');
$GLOBALS['requested_payment_review_order'] = new WC_Order([], 'checkout');
$check($render() === '', 'unrelated WooCommerce orders reveal no Requested Showings panel');

echo "OK: $checks requested-showing payment-review render checks passed\n";
