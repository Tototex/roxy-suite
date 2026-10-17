<?php
namespace RoxyRS;

if (!defined('ABSPATH')) {
    exit;
}

/** Durable, immutable marker for one requested-showing off-session payment attempt. */
final class PaymentAttempts {
    private const META_KEY = '_roxy_rs_payment_attempt';
    private const RESULT_META_KEY = '_roxy_rs_payment_result';
    private const MAX_AMOUNT = 2147483647;

    /**
     * Claims the only attempt permitted for a Woo order. The unique order_id
     * key and autocommit insert make the claim durable before this returns.
     */
    public static function claim(\WC_Order $order, array $request): array {
        $identity = self::normalize_request($request);
        $order_id = self::order_id($order);
        if ($order_id <= 0) throw new \RuntimeException('Payment order identity is unavailable; reconcile manually.');

        $order = self::fresh_order($order_id);
        self::assert_context($order, $order_id, $identity);
        self::assert_no_legacy_markers($order_id, $order);

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
        global $wpdb;
        $table = self::require_table();
        $connection = self::connection_id();
        if (!self::autocommit_ready()) {
            throw new \RuntimeException('Payment attempt storage is inside another transaction; reconcile manually.');
        }
        self::assert_no_legacy_markers($order_id, $order);
        self::assert_context(self::fresh_order($order_id), $order_id, $identity);

        // The primary key is order_id. A duplicate or uncertain insert always
        // fails closed; this code never updates or replaces a claim.
        $wpdb->last_error = '';
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO `$table` (order_id, attempt_json, result_json, created_at, updated_at) VALUES (%d, %s, NULL, %s, %s)",
            $order_id,
            $encoded,
            self::now_utc(),
            self::now_utc()
        ));
        if ($result !== 1 || $wpdb->last_error !== '' || self::connection_id() !== $connection) {
            throw new \RuntimeException('A payment attempt already exists or could not be committed; reconcile it manually.');
        }
        $stored = self::row($order_id);
        if (!$stored || $stored['attempt_json'] !== $encoded || $stored['result_json'] !== null
            || self::connection_id() !== $connection) {
            throw new \RuntimeException('Committed payment attempt could not be verified; reconcile manually.');
        }
        return ['key' => $marker['key'], 'hash' => $marker['hash']];
    }

    /** Re-reads the durable committed claim immediately before provider I/O. */
    public static function verify(\WC_Order $order, array $claim): bool {
        try {
            $order_id = self::order_id($order);
            if ($order_id <= 0) return false;
            $fresh = self::fresh_order($order_id);
            $marker = self::load_marker($order_id, $fresh, $claim);
            $row = self::row($order_id);
            if (!$row || $row['result_json'] !== null
                || !hash_equals((string) $row['attempt_json'], self::json($marker))) return false;
            self::assert_context($fresh, $order_id, self::identity_from_marker($marker));
            self::assert_no_legacy_markers($order_id, $fresh);
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    /** Persist a validated provider result once; exact replays are idempotent. */
    public static function record_result(\WC_Order $order, array $claim, string $intent_id, string $status): bool {
        if (!preg_match('/^pi_[A-Za-z0-9]+$/D', $intent_id)
            || !in_array($status, ['succeeded', 'processing', 'requires_capture', 'requires_action', 'requires_payment_method', 'requires_confirmation', 'canceled'], true)) return false;
        try {
            $order_id = self::order_id($order);
            if ($order_id <= 0) return false;
            $fresh = self::fresh_order($order_id);
            $marker = self::load_marker($order_id, $fresh, $claim);
            self::assert_context($fresh, $order_id, self::identity_from_marker($marker));
            self::assert_no_legacy_markers($order_id, $fresh);
            $result = self::json(['key'=>$marker['key'],'hash'=>$marker['hash'],'intent_id'=>$intent_id,'status'=>$status]);
            global $wpdb;
            $table = self::require_table();
            $connection = self::connection_id();
            if (!self::autocommit_ready()) return false;
            $wpdb->last_error = '';
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE `$table` SET result_json=%s, updated_at=%s WHERE order_id=%d AND attempt_json=%s AND result_json IS NULL",
                $result,
                self::now_utc(),
                $order_id,
                self::json($marker)
            ));
            if ($wpdb->last_error !== '' || self::connection_id() !== $connection) return false;
            $row = self::row($order_id);
            if (!$row || $row['attempt_json'] !== self::json($marker)) return false;
            if ($changed === 1) return $row['result_json'] === $result;
            return $changed === 0 && $row['result_json'] === $result;
        } catch (\Throwable $error) {
            return false;
        }
    }

    /** Read-only source for the existing admin reconciliation panel. */
    public static function review_data(\WC_Order $order): array {
        $id = self::order_id($order);
        try {
            $row = $id > 0 ? self::row($id, false) : null;
            if ($row) {
                $attempt = json_decode($row['attempt_json'], true);
                $result = $row['result_json'] === null ? null : json_decode($row['result_json'], true);
                return [
                    'attempt'=>is_array($attempt) ? $row['attempt_json'] : '{unreadable payment attempt}',
                    'result'=>$row['result_json'] === null ? '' : (is_array($result) ? $row['result_json'] : '{unreadable payment result}'),
                ];
            }
        } catch (\Throwable $error) {
            return ['attempt'=>'{unreadable}', 'result'=>''];
        }
        return ['attempt'=>self::single_meta_value($order, self::META_KEY), 'result'=>self::single_meta_value($order, self::RESULT_META_KEY)];
    }

    private static function order_id(\WC_Order $order): int {
        return method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
    }

    private static function fresh_order(int $order_id): \WC_Order {
        if (!function_exists('wc_get_order')) throw new \RuntimeException('WooCommerce order storage is unavailable.');
        $fresh = wc_get_order($order_id);
        if (!$fresh instanceof \WC_Order || self::order_id($fresh) !== $order_id) {
            throw new \RuntimeException('WooCommerce order could not be reloaded; reconcile manually.');
        }
        if (method_exists($fresh, 'read_meta_data')) $fresh->read_meta_data(true);
        return $fresh;
    }

    private static function assert_context(\WC_Order $order, int $order_id, array $identity): void {
        if (self::order_id($order) !== $order_id || !method_exists($order, 'get_type') || $order->get_type() !== 'shop_order') {
            throw new \RuntimeException('WooCommerce payment order identity could not be verified.');
        }
        if (!method_exists($order, 'get_status') || !in_array($order->get_status(), ['pending', 'on-hold', 'failed', 'checkout-draft'], true)
            || (method_exists($order, 'is_paid') && $order->is_paid())) {
            throw new \RuntimeException('Payment order is paid or completed; reconcile manually.');
        }
        if (!method_exists($order, 'get_customer_id') || (int) $order->get_customer_id() !== $identity['customer_id']) {
            throw new \RuntimeException('WooCommerce payment order customer does not match the attempt.');
        }
        foreach (['_roxy_rs_request_id'=>$identity['request_id'], '_roxy_rs_backing_id'=>$identity['backing_id']] as $key=>$expected) {
            $values = self::meta_values($order, $key);
            if (count($values) !== 1 || !self::raw_id_matches($values[0], $expected)) {
                throw new \RuntimeException('WooCommerce payment order ownership is missing or ambiguous; reconcile manually.');
            }
        }
        if (!method_exists($order, 'get_total') || self::decimal_to_cents($order->get_total()) !== $identity['amount']
            || !method_exists($order, 'get_currency') || !is_string($order->get_currency())
            || strtolower($order->get_currency()) !== $identity['currency']) {
            throw new \RuntimeException('WooCommerce order amount or currency does not match the attempt.');
        }
        if (method_exists($order, 'get_date_paid') && $order->get_date_paid()) {
            throw new \RuntimeException('A paid timestamp exists; reconcile the order manually.');
        }
        foreach (['_date_paid', '_paid_date'] as $key) {
            $values = self::meta_values($order, $key);
            if (count($values) > 1 || ($values && (string) $values[0] !== '')) {
                throw new \RuntimeException('A paid timestamp exists; reconcile the order manually.');
            }
        }
    }

    /** Any old marker row, even empty, duplicate, or corrupt, blocks this order. */
    private static function assert_no_legacy_markers(int $order_id, \WC_Order $order): void {
        if (self::has_meta_key($order, self::META_KEY) || self::has_meta_key($order, self::RESULT_META_KEY)) {
            throw new \RuntimeException('A legacy payment attempt marker exists; reconcile it manually.');
        }
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_id FROM `{$wpdb->postmeta}` WHERE post_id=%d AND meta_key IN (%s, %s) LIMIT 1",
            $order_id,
            self::META_KEY,
            self::RESULT_META_KEY
        ));
        if ($wpdb->last_error !== '' || !is_array($rows)) throw new \RuntimeException('Legacy payment marker state could not be read.');
        if ($rows) throw new \RuntimeException('A legacy payment attempt marker exists; reconcile it manually.');
    }

    private static function has_meta_key(\WC_Order $order, string $key): bool {
        foreach (self::meta_data($order) as $meta) {
            $data = is_object($meta) && method_exists($meta, 'get_data') ? $meta->get_data() : (is_array($meta) ? $meta : []);
            if (($data['key'] ?? null) === $key) return true;
        }
        return false;
    }

    private static function meta_values(\WC_Order $order, string $key): array {
        $values = [];
        foreach (self::meta_data($order) as $meta) {
            $data = is_object($meta) && method_exists($meta, 'get_data') ? $meta->get_data() : (is_array($meta) ? $meta : []);
            if (($data['key'] ?? null) === $key) $values[] = $data['value'] ?? null;
        }
        return $values;
    }

    private static function meta_data(\WC_Order $order): array {
        if (!method_exists($order, 'get_meta_data')) throw new \RuntimeException('WooCommerce order metadata is unavailable.');
        $data = $order->get_meta_data();
        if (!is_array($data)) throw new \RuntimeException('WooCommerce order metadata could not be read.');
        return $data;
    }

    private static function single_meta_value(\WC_Order $order, string $key): string {
        $values = self::meta_values($order, $key);
        if (!$values) return '';
        if (count($values) !== 1 || !is_string($values[0]) || $values[0] === '') return '{legacy payment marker requires review}';
        return $values[0];
    }

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'roxy_rs_payment_attempts';
    }

    private static function require_table(): string {
        global $wpdb;
        $table = self::table_name();
        $wpdb->last_error = '';
        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $table
        ));
        if ($wpdb->last_error !== '' || $engine !== 'InnoDB') throw new \RuntimeException('Durable payment-attempt storage is unavailable.');
        $wpdb->last_error = '';
        $primary = $wpdb->get_results("SHOW INDEX FROM `$table` WHERE Key_name='PRIMARY'", ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($primary) || count($primary) !== 1
            || (string) ($primary[0]['Column_name'] ?? '') !== 'order_id'
            || (int) ($primary[0]['Non_unique'] ?? 1) !== 0
            || (int) ($primary[0]['Seq_in_index'] ?? 0) !== 1) {
            throw new \RuntimeException('Payment-attempt uniqueness constraint is unavailable.');
        }
        return $table;
    }

    private static function connection_id(): int {
        global $wpdb;
        $wpdb->last_error = '';
        $id = $wpdb->get_var('SELECT CONNECTION_ID()');
        if ($wpdb->last_error !== '' || !is_numeric($id) || (int) $id <= 0) throw new \RuntimeException('Payment storage connection is unavailable.');
        return (int) $id;
    }

    private static function autocommit_ready(): bool {
        global $wpdb;
        $wpdb->last_error = '';
        $autocommit = $wpdb->get_var('SELECT @@SESSION.autocommit');
        if ($wpdb->last_error !== '' || (string) $autocommit !== '1') return false;
        $probe = 'roxy_payment_probe_' . bin2hex(random_bytes(8));
        if ($wpdb->query('SAVEPOINT ' . $probe) === false) throw new \RuntimeException('Payment transaction probe failed.');
        $previous = $wpdb->suppress_errors(true);
        try {
            // A releasable savepoint indicates an outer transaction. The probe
            // name is generated locally and contains only safe ASCII bytes.
            $outer = $wpdb->query('RELEASE SAVEPOINT ' . $probe) !== false;
        } finally {
            $wpdb->suppress_errors($previous);
        }
        return !$outer;
    }

    private static function row(int $order_id, bool $required = true): ?array {
        global $wpdb;
        $table = $required ? self::require_table() : self::table_name();
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare("SELECT order_id, attempt_json, result_json FROM `$table` WHERE order_id=%d", $order_id), ARRAY_A);
        if ($wpdb->last_error !== '') {
            if (!$required && stripos($wpdb->last_error, "doesn't exist") !== false) return null;
            throw new \RuntimeException('Payment attempt record could not be read.');
        }
        if ($row === null) return null;
        if (!is_array($row) || (int) ($row['order_id'] ?? 0) !== $order_id || !is_string($row['attempt_json'] ?? null)
            || !(is_string($row['result_json'] ?? null) || $row['result_json'] === null)) {
            throw new \RuntimeException('Payment attempt record is corrupt.');
        }
        return $row;
    }

    private static function load_marker(int $order_id, \WC_Order $order, array $claim): array {
        $row = self::row($order_id);
        if (!$row) throw new \RuntimeException('Payment attempt marker is missing or ambiguous.');
        $marker = json_decode($row['attempt_json'], true);
        if (!is_array($marker)) throw new \RuntimeException('Payment attempt marker is corrupt.');
        $keys = array_keys($marker);
        $expected_fields = ['version','key','hash','order_id','request_id','backing_id','customer_id','amount','currency','started_at'];
        sort($keys);
        sort($expected_fields);
        if ($keys !== $expected_fields || ($marker['version'] ?? null) !== 1 || ($marker['order_id'] ?? null) !== $order_id
            || !is_string($marker['started_at'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $marker['started_at'])) {
            throw new \RuntimeException('Payment attempt marker is ambiguous or corrupt.');
        }
        $identity = self::identity_from_marker($marker);
        $expected_key = self::idempotency_key($order_id, $identity);
        $expected_hash = hash('sha256', self::json(self::payload($order_id, $identity)));
        if (($claim['key'] ?? null) !== $expected_key || ($claim['hash'] ?? null) !== $expected_hash
            || ($marker['key'] ?? null) !== $expected_key || ($marker['hash'] ?? null) !== $expected_hash
            || $marker['order_id'] !== $order_id) {
            throw new \RuntimeException('Payment attempt marker does not match.');
        }
        self::assert_context($order, $order_id, $identity);
        return $marker;
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
        return ['order_id'=>$order_id,'request_id'=>$identity['request_id'],'backing_id'=>$identity['backing_id'],
            'customer_id'=>$identity['customer_id'],'amount'=>$identity['amount'],'currency'=>$identity['currency']];
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

    private static function now_utc(): string {
        return function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s');
    }
}
