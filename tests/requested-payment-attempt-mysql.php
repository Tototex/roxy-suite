<?php
/** Real WooCommerce CRUD + private MySQL payment-attempt contract; never calls a provider or sends mail. */
if (!defined('WP_CLI') || !WP_CLI) exit;
global $wpdb;
$root = $args[0] ?? '';
$mode = $args[1] ?? 'legacy';
if (!is_dir($root) || !in_array($mode, ['legacy', 'hpos'], true)) throw new RuntimeException('Repository root and storage mode are required.');
if (!function_exists('roxy_rs_install_schema')) require $root . '/includes/modules/requested-showings/includes/schema.php';
if (!class_exists('RoxyRS\\PaymentAttempts')) require $root . '/includes/modules/requested-showings/includes/class-roxy-rs-payment-attempts.php';
if (!function_exists('wc_create_order') || !function_exists('wc_get_order')) throw new RuntimeException('WooCommerce CRUD is required.');
$using_hpos = class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
    && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
if (($mode === 'hpos') !== $using_hpos) throw new RuntimeException('Fixture storage mode mismatch: expected ' . $mode . '.');
roxy_rs_install_schema();
$table = $wpdb->prefix . 'roxy_rs_payment_attempts';
$engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table));
if ($engine !== 'InnoDB') throw new RuntimeException('Payment-attempt table must be InnoDB.');

$ids = [];
$checks = 0;
$assert = static function ($ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
};
$throws = static function (callable $callback): bool { try { $callback(); return false; } catch (Throwable $error) { return true; } };
$request = ['request_id'=>501,'backing_id'=>1,'customer_id'=>1,'amount'=>1250,'currency'=>'usd','payment_token'=>'fixture-secret-must-not-persist'];
$make_order = static function (array $overrides = []) use (&$ids): WC_Order {
    $order = wc_create_order(['status'=>'pending','customer_id'=>$overrides['customer_id'] ?? 1]);
    if (is_wp_error($order) || !$order instanceof WC_Order) throw new RuntimeException('Private Woo order fixture creation failed.');
    $order->set_total($overrides['total'] ?? '12.50');
    $order->set_currency($overrides['currency'] ?? 'USD');
    $order->add_meta_data('_roxy_rs_request_id', $overrides['request_id'] ?? 501, true);
    $order->add_meta_data('_roxy_rs_backing_id', $overrides['backing_id'] ?? 1, true);
    foreach (($overrides['crud_markers'] ?? []) as [$key, $value, $unique]) $order->add_meta_data($key, $value, $unique);
    $order->save();
    $ids[] = (int) $order->get_id();
    return wc_get_order($order->get_id());
};
$provider_calls = 0;
$mail_calls = 0;
$provider_guard = static function () use (&$provider_calls) { $provider_calls++; return new WP_Error('fixture_provider_blocked', 'No provider access in this test.'); };
$mail_guard = static function () use (&$mail_calls) { $mail_calls++; return true; };
add_filter('pre_http_request', $provider_guard, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX, 2);
try {
    $original_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . ($using_hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts) . ($using_hpos ? " WHERE type='shop_order'" : " WHERE post_type='shop_order'"));
    $order = $make_order();
    $mail_before_claim = $mail_calls;
    $claim = \RoxyRS\PaymentAttempts::claim($order, $request);
    $assert($mail_calls === $mail_before_claim, 'claim does not attempt email');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE order_id=%d", $order->get_id()), ARRAY_A);
    $saved_marker = json_decode((string) ($row['attempt_json'] ?? ''), true);
    $assert(is_array($saved_marker) && ($claim['key'] ?? '') === ($saved_marker['key'] ?? null), 'single immutable claim is stored in the InnoDB order-id primary-key row');
    $assert(!str_contains((string) $row['attempt_json'], 'fixture-secret-must-not-persist'), 'claim excludes payment-token secrets');
    $mail_before_verify = $mail_calls;
    $assert(\RoxyRS\PaymentAttempts::verify($order, $claim), 'fresh Woo CRUD order and committed row verify before provider I/O');
    $assert($mail_calls === $mail_before_verify, 'verification does not attempt email');
    $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, $request)), 'second claim for one order fails closed');
    $mail_before_result = $mail_calls;
    $assert(\RoxyRS\PaymentAttempts::record_result($order, $claim, 'pi_fixture123', 'processing'), 'provider result is recorded once in durable storage');
    $assert($mail_calls === $mail_before_result, 'result persistence does not attempt email');
    $assert(\RoxyRS\PaymentAttempts::record_result($order, $claim, 'pi_fixture123', 'processing'), 'identical provider result replay is idempotent');
    $assert(!\RoxyRS\PaymentAttempts::record_result($order, $claim, 'pi_other123', 'succeeded'), 'different result cannot overwrite an immutable result');
    $assert(!\RoxyRS\PaymentAttempts::verify($order, $claim), 'any saved result blocks a second provider request');
    $review = \RoxyRS\PaymentAttempts::review_data($order);
    $assert(($review['attempt'] ?? '') === $row['attempt_json'] && json_decode($review['result'] ?? '', true)['intent_id'] === 'pi_fixture123', 'existing read-only admin review receives the durable attempt and result');

    $legacy_empty = $make_order();
    $legacy_empty->add_meta_data('_roxy_rs_payment_attempt', '', false);
    $legacy_empty->save();
    $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim($legacy_empty, $request)), 'empty legacy attempt marker fails closed');
    $assert((\RoxyRS\PaymentAttempts::review_data($legacy_empty)['attempt'] ?? '') !== '', 'empty legacy marker remains visible to the reconciliation panel');

    $legacy_corrupt = $make_order();
    $legacy_corrupt->add_meta_data('_roxy_rs_payment_attempt', '{corrupt', false);
    $legacy_corrupt->save();
    $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim($legacy_corrupt, $request)), 'corrupt legacy attempt marker fails closed');

    $legacy_result = $make_order();
    $legacy_result->add_meta_data('_roxy_rs_payment_result', 'not-json', false);
    $legacy_result->save();
    $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim($legacy_result, $request)), 'legacy result-only marker also fails closed');

    $legacy_duplicate = $make_order();
    $legacy_duplicate->add_meta_data('_roxy_rs_payment_attempt', '{"one":1}', false);
    $legacy_duplicate->add_meta_data('_roxy_rs_payment_attempt', '{"two":2}', false);
    $legacy_duplicate->save();
    $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim($legacy_duplicate, $request)), 'ambiguous duplicate legacy markers fail closed');
    $assert((\RoxyRS\PaymentAttempts::review_data($legacy_duplicate)['attempt'] ?? '') !== '', 'duplicate legacy markers remain visible to the reconciliation panel');

    // Simulate stale legacy metadata, which may remain attached to an HPOS
    // placeholder post when compatibility synchronization is disabled.
    $orphan = $make_order();
    if ($wpdb->insert($wpdb->postmeta, ['post_id'=>$orphan->get_id(),'meta_key'=>'_roxy_rs_payment_attempt','meta_value'=>'']) !== 1) throw new RuntimeException('Could not seed private legacy postmeta marker.');
    $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim($orphan, $request)), 'stale legacy postmeta marker is detected and blocks a new claim in either storage mode');

    $paid = $make_order();
    $paid->set_status('completed');
    $paid->save();
    $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim(wc_get_order($paid->get_id()), $request)), 'Woo CRUD paid state blocks a new claim');
    $mismatch = $make_order(['total'=>'12.51']);
    $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim($mismatch, $request)), 'Woo CRUD amount mismatch blocks a new claim');
    $wrong_currency = $make_order(['currency'=>'CAD']);
    $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim($wrong_currency, $request)), 'Woo CRUD currency mismatch blocks a new claim');
    $wrong_customer = $make_order(['customer_id'=>2]);
    $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim($wrong_customer, $request)), 'Woo CRUD customer mismatch blocks a new claim');
    $duplicate_owner = $make_order(['crud_markers'=>[['_roxy_rs_request_id', 502, false]]]);
    $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim($duplicate_owner, $request)), 'duplicate Woo CRUD ownership metadata blocks a new claim');
    $wrong_status = $make_order();
    $wrong_status->set_status('cancelled'); $wrong_status->save();
    $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim(wc_get_order($wrong_status->get_id()), $request)), 'Woo CRUD cancelled status blocks a new claim');

    $external_transaction = $make_order();
    if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('Could not start the private transaction-state fixture.');
    try {
        $assert($throws(static fn()=>\RoxyRS\PaymentAttempts::claim($external_transaction, $request)), 'active outer database transaction blocks claim before provider I/O');
    } finally {
        $wpdb->query('ROLLBACK');
    }

    $assert($provider_calls === 0, 'no provider request was attempted');
    $after_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . ($using_hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts) . ($using_hpos ? " WHERE type='shop_order'" : " WHERE post_type='shop_order'"));
    $assert($after_orders === $original_orders + count($ids), 'only the private fixture orders were added');
    echo 'Passed ' . $checks . ' real WooCommerce/' . strtoupper($mode) . ' payment-attempt assertions.' . PHP_EOL;
} finally {
    remove_filter('pre_http_request', $provider_guard, PHP_INT_MAX);
    remove_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX);
    foreach (array_reverse($ids) as $id) {
        $wpdb->delete($table, ['order_id'=>$id]);
        $wpdb->delete($wpdb->postmeta, ['post_id'=>$id]);
        if (function_exists('wc_delete_order')) wc_delete_order($id, true);
    }
    foreach (['roxy_rs_payment_attempts'] as $suffix) {
        $wpdb->query('DELETE FROM `' . $table . '` WHERE order_id IN (' . implode(',', array_map('intval', $ids ?: [0])) . ')');
    }
}
$remaining_rows = $ids ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE order_id IN (" . implode(',', array_map('intval', $ids)) . ')') : 0;
$remaining_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . ($using_hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts) . ($using_hpos ? " WHERE type='shop_order'" : " WHERE post_type='shop_order'"));
if ($remaining_rows !== 0 || $remaining_orders !== $original_orders) throw new RuntimeException('Private Woo order/payment-attempt fixture cleanup did not restore its baseline.');
echo 'PASS: private fixture rows and orders are fully cleaned up.' . PHP_EOL;
