<?php
namespace RoxyRS;

use RoxyST\Issuance;

if (!defined('ABSPATH')) {
    exit;
}

/** Durable, immutable marker for one requested-showing off-session payment attempt. */
final class PaymentAttempts {
    private const META_KEY = '_roxy_rs_payment_attempt';
    private const RESULT_META_KEY = '_roxy_rs_payment_result';
    private const MAX_AMOUNT = 2147483647;

    /**
     * Claims a single attempt before provider I/O. Any existing marker requires
     * reconciliation; this method never creates a replacement attempt.
     */
    public static function claim(\WC_Order $order, array $request): array {
        $identity = self::normalize_request($request);
        $order_id = method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
        if ($order_id <= 0) throw new \RuntimeException('Payment order identity is unavailable; reconcile manually.');
        if (method_exists($order, 'is_paid') && $order->is_paid()) throw new \RuntimeException('Payment order is already paid; reconcile the backing manually.');

        $payload = self::payload($order_id, $identity);
        $marker = [
            'version' => 1,
            'key' => self::idempotency_key($order_id, $identity),
            'hash' => hash('sha256', self::json($payload)),
            'order_id' => $order_id,
            'request_id' => $identity['request_id'],
            'backing_id' => $identity['backing_id'],
            'customer_id' => $identity['customer_id'],
            'amount' => $identity['amount'],
            'currency' => $identity['currency'],
            'started_at' => function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        ];
        $encoded = self::json($marker);

        self::transaction($order_id, static function (Issuance $writer) use ($order, $identity, $order_id, $encoded): void {
            self::assert_context($writer, $order, $order_id, $identity);
            $existing = self::marker_values($writer, $order_id);
            if ($existing !== []) throw new \RuntimeException('A payment attempt already exists; reconcile it manually.');

            $writer->post_meta($order_id, self::META_KEY, $encoded);
            self::assert_context($writer, $order, $order_id, $identity);
            $stored = $writer->post_meta_value($order_id, self::META_KEY);
            $values = self::marker_values($writer, $order_id);
            if ($stored !== $encoded || $values !== [$encoded]) {
                throw new \RuntimeException('Payment attempt marker could not be verified; reconcile manually.');
            }
        });

        return ['key' => $marker['key'], 'hash' => $marker['hash']];
    }

    /** Re-reads the committed marker transactionally before provider I/O. */
    public static function verify(\WC_Order $order, array $claim): bool {
        try {
            $order_id = method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
            if ($order_id <= 0) return false;
            self::transaction($order_id, static function (Issuance $writer) use ($order, $order_id, $claim): void {
                $marker = self::load_marker($writer, $order, $order_id, $claim);
                if (self::marker_values($writer, $order_id, self::RESULT_META_KEY) !== []) {
                    throw new \RuntimeException('Payment result already exists; do not send another request.');
                }
                self::assert_context($writer, $order, $order_id, self::identity_from_marker($marker));
            });
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    /** Persist a validated provider result before the caller marks WooCommerce paid. */
    public static function record_result(\WC_Order $order, array $claim, string $intent_id, string $status): bool {
        if (!preg_match('/^pi_[A-Za-z0-9]+$/D', $intent_id)
            || !in_array($status, ['succeeded', 'processing', 'requires_capture', 'requires_action', 'requires_payment_method', 'requires_confirmation', 'canceled'], true)) return false;
        try {
            $order_id = method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
            if ($order_id <= 0) return false;
            self::transaction($order_id, static function (Issuance $writer) use ($order, $order_id, $claim, $intent_id, $status): void {
                $marker = self::load_marker($writer, $order, $order_id, $claim);
                self::assert_context($writer, $order, $order_id, self::identity_from_marker($marker));
                $result = ['key'=>$marker['key'],'hash'=>$marker['hash'],'intent_id'=>$intent_id,'status'=>$status];
                $encoded = self::json($result);
                $existing = self::marker_values($writer, $order_id, self::RESULT_META_KEY);
                if ($existing !== []) {
                    if ($existing === [$encoded]) return;
                    throw new \RuntimeException('A different payment result is already recorded.');
                }
                $writer->post_meta($order_id, self::RESULT_META_KEY, $encoded);
                self::assert_context($writer, $order, $order_id, self::identity_from_marker($marker));
                if ($writer->post_meta_value($order_id, self::RESULT_META_KEY) !== $encoded
                    || self::marker_values($writer, $order_id, self::RESULT_META_KEY) !== [$encoded]) {
                    throw new \RuntimeException('Payment result readback failed.');
                }
            });
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    private static function transaction(int $order_id, callable $callback): void {
        if (!class_exists(Issuance::class)) throw new \RuntimeException('Transactional payment-attempt storage is unavailable.');
        try {
            (new Issuance($order_id))->run($callback);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Payment attempt could not be safely verified; reconcile manually.', 0, $error);
        }
    }

    private static function normalize_request(array $request): array {
        $out = [];
        foreach (['request_id', 'backing_id', 'customer_id'] as $key) {
            if (!isset($request[$key]) || !is_int($request[$key]) || $request[$key] <= 0) {
                throw new \RuntimeException('Payment attempt identity is invalid; reconcile manually.');
            }
            $out[$key] = $request[$key];
        }
        if (!isset($request['amount']) || !is_int($request['amount']) || $request['amount'] <= 0 || $request['amount'] > self::MAX_AMOUNT) {
            throw new \RuntimeException('Payment amount is outside the supported range; reconcile manually.');
        }
        if (!isset($request['currency']) || !is_string($request['currency']) || !preg_match('/^[A-Za-z]{3}$/D', $request['currency'])) {
            throw new \RuntimeException('Payment currency is invalid; reconcile manually.');
        }
        $out['amount'] = $request['amount'];
        $out['currency'] = strtolower($request['currency']);
        return $out;
    }

    private static function assert_context(Issuance $writer, \WC_Order $order, int $order_id, array $identity): void {
        global $wpdb;
        $writer->assert_owner();
        if ((int) $order->get_id() !== $order_id) throw new \RuntimeException('Payment order identity changed.');

        $wpdb->last_error = '';
        $post_type = $wpdb->get_var($wpdb->prepare("SELECT post_type FROM `{$wpdb->posts}` WHERE ID=%d", $order_id));
        if ($wpdb->last_error !== '' || $post_type !== 'shop_order') throw new \RuntimeException('Raw payment order type could not be verified.');
        $writer->assert_owner();
        $wpdb->last_error = '';
        $status = $wpdb->get_var($wpdb->prepare("SELECT post_status FROM `{$wpdb->posts}` WHERE ID=%d", $order_id));
        if ($wpdb->last_error !== '' || !is_string($status) || $status === '') throw new \RuntimeException('Raw payment order status could not be verified.');
        if (!in_array($status, ['wc-pending', 'wc-on-hold', 'wc-failed', 'wc-checkout-draft'], true)
            || (method_exists($order, 'is_paid') && $order->is_paid())) {
            throw new \RuntimeException('Payment order is paid or completed; reconcile manually.');
        }
        $writer->assert_owner();
        foreach ([
            '_customer_user' => $identity['customer_id'],
            '_roxy_rs_request_id' => $identity['request_id'],
            '_roxy_rs_backing_id' => $identity['backing_id'],
        ] as $key => $expected) {
            $values = self::marker_values($writer, $order_id, $key);
            if (count($values) !== 1 || !self::raw_id_matches($values[0], $expected)) {
                throw new \RuntimeException('Raw payment order ownership is missing or ambiguous; reconcile manually.');
            }
        }
        foreach (['_order_total' => $identity['amount'], '_order_currency' => $identity['currency']] as $key => $expected) {
            $values = self::marker_values($writer, $order_id, $key);
            if (count($values) !== 1) throw new \RuntimeException('Raw payment amount or currency is missing or ambiguous.');
            if ($key === '_order_total') {
                if (self::decimal_to_cents($values[0]) !== $expected) throw new \RuntimeException('Raw payment amount does not match the attempt.');
            } elseif (!is_string($values[0]) || strtolower($values[0]) !== $expected) {
                throw new \RuntimeException('Raw payment currency does not match the attempt.');
            }
        }
        foreach (['_date_paid', '_paid_date'] as $paid_key) {
            $values = self::marker_values($writer, $order_id, $paid_key);
            if (count($values) > 1 || ($values && (string) $values[0] !== '')) {
                throw new \RuntimeException('A paid timestamp exists; reconcile the order manually.');
            }
        }
        if (!method_exists($order, 'get_total') || self::decimal_to_cents($order->get_total()) !== $identity['amount']
            || !method_exists($order, 'get_currency') || !is_string($order->get_currency())
            || strtolower($order->get_currency()) !== $identity['currency']) {
            throw new \RuntimeException('Woo order amount or currency does not match the attempt.');
        }
        $writer->assert_owner();
    }

    private static function decimal_to_cents($value): ?int {
        if (is_int($value)) $value = (string) $value;
        elseif (is_float($value)) {
            if (!is_finite($value) || $value < 0 || $value > self::MAX_AMOUNT / 100) return null;
            $value = number_format($value, 2, '.', '');
        }
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $value, $matches)) return null;
        $major = $matches[1];
        if (strlen($major) > 8 || (int) $major > intdiv(self::MAX_AMOUNT, 100)) return null;
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $cents = ((int) $major * 100) + (int) $fraction;
        return $cents <= self::MAX_AMOUNT ? $cents : null;
    }

    private static function raw_id_matches($raw, int $expected): bool {
        if (is_int($raw)) return $raw === $expected;
        return is_string($raw) && preg_match('/^[1-9][0-9]*$/D', $raw) && strlen($raw) <= strlen((string) $expected)
            && (string) $raw === (string) $expected;
    }

    private static function marker_values(Issuance $writer, int $order_id, string $key = self::META_KEY): array {
        global $wpdb;
        $writer->assert_owner();
        $wpdb->last_error = '';
        $values = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM `{$wpdb->postmeta}` WHERE post_id=%d AND meta_key=%s ORDER BY meta_id",
            $order_id,
            $key
        ));
        if ($wpdb->last_error !== '' || !is_array($values)) throw new \RuntimeException('Payment attempt marker could not be read.');
        $writer->assert_owner();
        return $values;
    }

    private static function load_marker(Issuance $writer, \WC_Order $order, int $order_id, array $claim): array {
        $values = self::marker_values($writer, $order_id);
        if (count($values) !== 1 || !is_string($values[0])) throw new \RuntimeException('Payment attempt marker is missing or ambiguous.');
        $marker = json_decode($values[0], true);
        if (!is_array($marker)) throw new \RuntimeException('Payment attempt marker is corrupt.');
        $identity = self::identity_from_marker($marker);
        $expected_key = self::idempotency_key($order_id, $identity);
        $expected_hash = hash('sha256', self::json(self::payload($order_id, $identity)));
        if (($claim['key'] ?? null) !== $expected_key || ($claim['hash'] ?? null) !== $expected_hash
            || ($marker['key'] ?? null) !== $expected_key || ($marker['hash'] ?? null) !== $expected_hash
            || (int) ($marker['order_id'] ?? 0) !== $order_id) {
            throw new \RuntimeException('Payment attempt marker does not match.');
        }
        self::assert_context($writer, $order, $order_id, $identity);
        return $marker;
    }

    private static function identity_from_marker(array $marker): array {
        return self::normalize_request([
            'request_id'=>$marker['request_id'] ?? null,
            'backing_id'=>$marker['backing_id'] ?? null,
            'customer_id'=>$marker['customer_id'] ?? null,
            'amount'=>$marker['amount'] ?? null,
            'currency'=>$marker['currency'] ?? null,
        ]);
    }

    private static function payload(int $order_id, array $identity): array {
        return [
            'order_id' => $order_id,
            'request_id' => $identity['request_id'],
            'backing_id' => $identity['backing_id'],
            'customer_id' => $identity['customer_id'],
            'amount' => $identity['amount'],
            'currency' => $identity['currency'],
        ];
    }

    private static function idempotency_key(int $order_id, array $identity): string {
        global $wpdb;
        $site = (defined('DB_NAME') ? DB_NAME : '') . ':' . (string) ($wpdb->prefix ?? '');
        return 'roxy-rs-' . hash('sha256', $site . ':' . $order_id . ':' . $identity['backing_id']);
    }

    private static function json(array $value): string {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($value, JSON_UNESCAPED_SLASHES) : json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) throw new \RuntimeException('Payment attempt data could not be encoded.');
        return $encoded;
    }
}
