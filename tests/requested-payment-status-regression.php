<?php
/** Actual Conversion payment helper; mocked provider/attempt store, no database or real charge. */
namespace RoxyRS {
    final class PaymentAttempts {
        public static array $claimed = [];
        public static bool $verify_ok = true;
        public static bool $record_ok = true;
        public static function claim($order, array $request): array {
            if (isset(self::$claimed[$order->get_id()])) throw new \RuntimeException('Existing attempt requires review');
            return self::$claimed[$order->get_id()] = ['key' => 'fixture-stable-' . $order->get_id(), 'hash' => hash('sha256', \wp_json_encode($request))];
        }
        public static function verify($order, array $claim): bool { return self::$verify_ok; }
        public static function record_result($order, array $claim, string $id, string $status): bool { return self::$record_ok; }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    function wp_json_encode($value) { return json_encode($value); }
    function add_filter($hook, $callback, $priority = 10, $args = 1): void { $GLOBALS['payment_filters'][$hook] = $callback; }
    function remove_filter($hook, $callback, $priority = 10): void { unset($GLOBALS['payment_filters'][$hook]); }
    function get_user_option($key, $user) { return 'cus_fixture'; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function wc_get_order($id) { return isset($GLOBALS['payment_saved_orders'][$id]) ? clone $GLOBALS['payment_saved_orders'][$id] : false; }
    final class WP_Error { public function __construct(public string $code, public string $message) {} }
    final class WC_Payment_Tokens { public static function get($id) { return new class { public function get_user_id() { return 77; } public function get_token() { return 'pm_fixture'; } }; } }
    final class WC_Order {
        public int $completed = 0;
        public int $saves = 0;
        public float $total = 12;
        public string $transaction = '';
        public bool $save_fails = false;
        public bool $paid = false;
        public $date_paid = null;
        public function __construct(public int $id) {}
        public function get_id() { return $this->id; }
        public function get_total() { return $this->total; }
        public function get_currency() { return 'USD'; }
        public function get_meta($key, $single = true) { return $key === '_roxy_rs_request_id' ? 501 : 1; }
        public function set_payment_method($value) {}
        public function set_payment_method_title($value) {}
        public function set_transaction_id($value) { $this->transaction = $value; }
        public function save() { if ($this->save_fails) throw new \RuntimeException('Fixture persistence failure'); $this->saves++; $GLOBALS['payment_saved_orders'][$this->id] = clone $this; return $this->id; }
        public function payment_complete($id) { $this->completed++; $this->paid = true; $this->transaction = $id; $this->date_paid = new \DateTimeImmutable('2026-10-08T12:00:00Z'); return $this->save() === $this->id; }
        public function is_paid() { return $this->paid; }
        public function get_transaction_id() { return $this->transaction; }
        public function get_date_paid() { return $this->date_paid; }
        public function add_order_note($note) {}
    }
    final class WC_Stripe_API {
        public static int $calls = 0;
        public static string $status = 'succeeded';
        public static bool $throw = false;
        public static bool $missing_id = false;
        public static bool $wrong_amount = false;
        public static array $keys = [];
        public static function request($request, $endpoint) {
            self::$calls++;
            if ($endpoint !== 'payment_intents') throw new \RuntimeException('Unexpected endpoint');
            $callback = $GLOBALS['payment_filters']['wc_stripe_idempotency_key'] ?? null;
            if (!$callback) throw new \RuntimeException('Missing idempotency hook');
            self::$keys[] = $callback('random-provider-default', $request);
            if ($callback('other-default', ['unrelated' => true]) !== 'other-default') throw new \RuntimeException('Unrelated request key changed');
            if (self::$throw) throw new \RuntimeException('Response lost after provider acceptance');
            return (object) ['id' => self::$missing_id ? '' : 'pi_fixture55', 'status' => self::$status,
                'amount' => self::$wrong_amount ? 999 : $request['amount'], 'amount_received' => $request['amount'],
                'currency' => $request['currency'], 'customer' => $request['customer'], 'metadata' => (object) ['order_id' => $request['metadata[order_id]']]];
        }
    }
    $root = $argv[1] ?? dirname(__DIR__);
    require $root . '/includes/modules/requested-showings/includes/class-roxy-rs-conversion.php';
    $charge = new \ReflectionMethod('RoxyRS\\Conversion', 'charge_order_with_saved_token');
    $charge->setAccessible(true);
    $checks = 0;
    $assert = static function ($ok, $label) use (&$checks) { if (!$ok) throw new \RuntimeException($label); $checks++; echo 'PASS: ' . $label . PHP_EOL; };
    $reset = static function () {
        \RoxyRS\PaymentAttempts::$claimed = []; \RoxyRS\PaymentAttempts::$verify_ok = true; \RoxyRS\PaymentAttempts::$record_ok = true;
        WC_Stripe_API::$calls = 0; WC_Stripe_API::$status = 'succeeded'; WC_Stripe_API::$throw = false; WC_Stripe_API::$missing_id = false; WC_Stripe_API::$keys = [];
        WC_Stripe_API::$wrong_amount = false;
        $GLOBALS['payment_saved_orders'] = [];
        $GLOBALS['payment_filters'] = [];
    };
    $reset(); $order = new WC_Order(701);
    $result = $charge->invoke(null, $order, 77, 5);
    $assert(is_array($result) && $result['intent_id'] === 'pi_fixture55' && $order->completed === 1, 'only confirmed succeeded intent completes payment');
    $assert(WC_Stripe_API::$keys === ['fixture-stable-701'] && !$GLOBALS['payment_filters'], 'stable scoped key used and temporary filter removed');
    $assert(is_wp_error($charge->invoke(null, $order, 77, 5)) && WC_Stripe_API::$calls === 1, 'same saved attempt never produces another provider call');
    foreach (['processing', 'requires_capture', 'requires_action', 'requires_payment_method', 'canceled'] as $status) {
        $reset(); WC_Stripe_API::$status = $status; $order = new WC_Order(702);
        $assert(is_wp_error($charge->invoke(null, $order, 77, 5)) && $order->completed === 0 && !$GLOBALS['payment_filters'], $status . ' remains unpaid and requires review');
    }
    $reset(); WC_Stripe_API::$throw = true; $order = new WC_Order(703);
    $assert(is_wp_error($charge->invoke(null, $order, 77, 5)) && $order->completed === 0 && !$GLOBALS['payment_filters'], 'lost response preserves an uncertain attempt without completion');
    WC_Stripe_API::$throw = false;
    $assert(is_wp_error($charge->invoke(null, $order, 77, 5)) && WC_Stripe_API::$calls === 1, 'lost response cannot be recharged on ordinary retry');
    $reset(); WC_Stripe_API::$missing_id = true; $order = new WC_Order(704);
    $assert(is_wp_error($charge->invoke(null, $order, 77, 5)) && $order->completed === 0, 'missing intent identity cannot be marked paid');
    $reset(); WC_Stripe_API::$wrong_amount = true; $order = new WC_Order(709);
    $assert(is_wp_error($charge->invoke(null, $order, 77, 5)) && $order->completed === 0, 'mismatched returned payment amount cannot complete order');
    $reset(); \RoxyRS\PaymentAttempts::$verify_ok = false; $order = new WC_Order(705);
    $assert(is_wp_error($charge->invoke(null, $order, 77, 5)) && WC_Stripe_API::$calls === 0 && !$GLOBALS['payment_filters'], 'failed persisted attempt verification blocks provider call');
    $reset(); \RoxyRS\PaymentAttempts::$record_ok = false; $order = new WC_Order(706);
    $assert(is_wp_error($charge->invoke(null, $order, 77, 5)) && $order->completed === 0, 'unrecordable provider result cannot complete payment');
    $reset(); $order = new WC_Order(707); $order->save_fails = true;
    $assert(is_wp_error($charge->invoke(null, $order, 77, 5)) && $order->completed === 0 && WC_Stripe_API::$calls === 1, 'persistence failure after success requires reconciliation');
    $assert(is_wp_error($charge->invoke(null, $order, 77, 5)) && WC_Stripe_API::$calls === 1, 'persistence failure cannot create another intent');
    $reset(); $order = new WC_Order(708); $order->total = 0;
    $assert(is_wp_error($charge->invoke(null, $order, 77, 5)) && WC_Stripe_API::$calls === 0, 'paid backing with zero saved total is rejected before attempt');
    echo 'Passed ' . $checks . ' mocked-provider orchestration checks. No real payment.' . PHP_EOL;
}
