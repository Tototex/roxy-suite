<?php
// Woo-shaped, provider-free tests for the post-payment order persistence boundary.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
$GLOBALS['requested_saved_orders'] = [];
$GLOBALS['requested_order_read_override'] = null;

class WC_Order {
    public bool $save_ok = true;
    public bool $complete_ok = true;
    public bool $set_paid_timestamp = true;
    public bool $paid = false;
    public ?string $transaction_id = null;
    public ?DateTimeImmutable $date_paid = null;
    public float $total = 12.0;
    public string $currency = 'USD';
    public int $save_calls = 0;
    public int $completion_calls = 0;
    public function __construct(private int $id) {}
    public function get_id(): int { return $this->id; }
    public function get_total(): float { return $this->total; }
    public function get_currency(): string { return $this->currency; }
    public function save(): int|false {
        $this->save_calls++;
        if (!$this->save_ok) return false;
        $GLOBALS['requested_saved_orders'][$this->id] = clone $this;
        return $this->id;
    }
    public function payment_complete(string $transaction_id): bool {
        $this->completion_calls++;
        if (!$this->complete_ok) return false;
        $this->paid = true;
        $this->transaction_id = $transaction_id;
        if ($this->set_paid_timestamp && $this->date_paid === null) $this->date_paid = new DateTimeImmutable('2026-10-08T12:00:00Z');
        return $this->save() === $this->id;
    }
    public function is_paid(): bool { return $this->paid; }
    public function get_transaction_id(): string { return (string) $this->transaction_id; }
    public function get_date_paid() { return $this->date_paid; }
}

function wc_get_order(int $id) {
    if ($GLOBALS['requested_order_read_override'] instanceof WC_Order) return clone $GLOBALS['requested_order_read_override'];
    return isset($GLOBALS['requested_saved_orders'][$id]) ? clone $GLOBALS['requested_saved_orders'][$id] : false;
}

require dirname(__DIR__) . '/includes/modules/requested-showings/includes/class-roxy-rs-conversion.php';
$method = new ReflectionMethod(\RoxyRS\Conversion::class, 'complete_order_and_verify_payment');
$method->setAccessible(true);
$complete = static fn(WC_Order $order, string $transaction, int $amount = 1200, string $currency = 'USD'): bool => (bool) $method->invoke(null, $order, $transaction, $amount, $currency);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
};

$order = new WC_Order(101);
$check($complete($order, 'pi_success') && wc_get_order(101)->is_paid() && wc_get_order(101)->get_transaction_id() === 'pi_success', 'confirmed payment_complete is verified from the persisted Woo order');

$order = new WC_Order(102); $order->save_ok = false;
$check(!$complete($order, 'pi_save_failure') && $order->completion_calls === 0, 'order save failure stops before payment_complete');

$order = new WC_Order(103); $order->complete_ok = false;
$check(!$complete($order, 'pi_completion_failure') && !wc_get_order(103)->is_paid(), 'payment_complete failure is not reported as a completed order');

$order = new WC_Order(104);
$GLOBALS['requested_order_read_override'] = new WC_Order(104);
$check(!$complete($order, 'pi_readback_mismatch'), 'stale or unreadable paid-order readback fails closed');
$GLOBALS['requested_order_read_override'] = null;

$order = new WC_Order(105); $order->set_paid_timestamp = false;
$check(!$complete($order, 'pi_missing_paid_date'), 'paid order readback without a durable paid timestamp is rejected');

$order = new WC_Order(106);
$GLOBALS['requested_order_read_override'] = new WC_Order(106);
$GLOBALS['requested_order_read_override']->paid = true;
$GLOBALS['requested_order_read_override']->transaction_id = 'pi_wrong';
$GLOBALS['requested_order_read_override']->date_paid = new DateTimeImmutable('2026-10-08T12:00:00Z');
$check(!$complete($order, 'pi_expected'), 'a paid readback with a mismatched provider transaction is rejected');
$GLOBALS['requested_order_read_override'] = null;

$order = new WC_Order(107);
$check(!$complete($order, 'pi_wrong_total', 1199), 'order total drift after provider confirmation is rejected');
$order = new WC_Order(108);
$check(!$complete($order, 'pi_wrong_currency', 1200, 'CAD'), 'order currency mismatch is rejected');

echo "OK: {$checks} Requested Showings payment-completion persistence checks passed\n";
