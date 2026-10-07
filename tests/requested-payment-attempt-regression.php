<?php
// Isolated payment-attempt marker tests. Run: php tests/requested-payment-attempt-regression.php [repo-root]
namespace RoxyST {
    final class Issuance {
        public function __construct(private int $order_id) {}
        public function assert_owner(): void {
            if ($GLOBALS['payment_fixture']['connection'] !== 41) throw new \RuntimeException('fixture connection lost');
        }
        public function run(callable $callback) {
            $fixture =& $GLOBALS['payment_fixture'];
            if ($fixture['in_transaction']) throw new \RuntimeException('fixture nested transaction');
            $before = $fixture['meta'];
            $fixture['in_transaction'] = true;
            try {
                $this->assert_owner();
                $result = $callback($this);
                $this->assert_owner();
                if ($fixture['fail_commit']) throw new \RuntimeException('fixture commit failed');
                $fixture['commits']++;
                return $result;
            } catch (\Throwable $error) {
                $fixture['meta'] = $before;
                $fixture['rollbacks']++;
                throw $error;
            } finally {
                $fixture['in_transaction'] = false;
            }
        }
        public function post_meta_value(int $id, string $key) {
            $this->assert_owner();
            $values = $GLOBALS['payment_fixture']['meta'][$id][$key] ?? [];
            return $values[0] ?? '';
        }
        public function post_meta(int $id, string $key, $value, bool $remove = false): void {
            $this->assert_owner();
            if ($GLOBALS['payment_fixture']['fail_write']) throw new \RuntimeException('fixture write failed');
            if ($GLOBALS['payment_fixture']['lose_connection_on_write']) $GLOBALS['payment_fixture']['connection'] = 99;
            else $GLOBALS['payment_fixture']['meta'][$id][$key] = [(string) $value];
        }
    }
}

namespace {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
    if (!defined('DB_NAME')) define('DB_NAME', 'payment_attempt_fixture');

    final class FixtureWpdb {
        public string $prefix = 'wp_private_';
        public string $posts = 'wp_private_posts';
        public string $postmeta = 'wp_private_postmeta';
        public string $last_error = '';
        public function prepare($sql, ...$args) {
            if (count($args) === 1 && is_array($args[0])) $args = $args[0];
            foreach ($args as $arg) {
                $replacement = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
                $sql = preg_replace('/%[sd]/', $replacement, $sql, 1);
            }
            return $sql;
        }
        public function get_var($sql) {
            if (strpos($sql, 'SELECT post_type FROM') === 0) return $GLOBALS['payment_fixture']['post_type'];
            if (strpos($sql, 'SELECT post_status FROM') === 0) return $GLOBALS['payment_fixture']['status'];
            return null;
        }
        public function get_col($sql) {
            if (strpos($sql, 'SELECT meta_value FROM') !== 0) return [];
            preg_match("/post_id=([0-9]+)/", $sql, $id);
            preg_match("/meta_key='([^']*)'/", $sql, $key);
            return $GLOBALS['payment_fixture']['meta'][(int) ($id[1] ?? 0)][$key[1] ?? ''] ?? [];
        }
    }
    final class WC_Order {
        public function __construct(private int $id = 123, private bool $paid = false, private string $total = '12.50', private string $currency = 'USD') {}
        public function get_id(): int { return $this->id; }
        public function is_paid(): bool { return $this->paid; }
        public function get_total(): string { return $this->total; }
        public function get_currency(): string { return $this->currency; }
    }
    function current_time($type, $gmt = false) { return '2026-10-06 19:00:00'; }
    function wp_json_encode($value, $options = 0) { return json_encode($value, $options); }

    $GLOBALS['wpdb'] = new FixtureWpdb();
    $root = $argv[1] ?? dirname(__DIR__);
    require $root . '/includes/modules/requested-showings/includes/class-roxy-rs-payment-attempts.php';

    function payment_reset(string $status = 'wc-pending', bool $paid = false): WC_Order {
        $GLOBALS['payment_fixture'] = [
            'connection'=>41, 'post_type'=>'shop_order', 'status'=>$status, 'in_transaction'=>false, 'fail_write'=>false,
            'fail_commit'=>false, 'lose_connection_on_write'=>false, 'commits'=>0, 'rollbacks'=>0,
            'meta'=>[123=>['_customer_user'=>['30'],'_roxy_rs_request_id'=>['10'],'_roxy_rs_backing_id'=>['20'],'_order_total'=>['12.50'],'_order_currency'=>['USD']]],
        ];
        return new WC_Order(123, $paid);
    }
    function payment_request(): array {
        return ['request_id'=>10,'backing_id'=>20,'customer_id'=>30,'amount'=>1250,'currency'=>'USD','payment_token'=>'SECRET_FIXTURE_VALUE'];
    }
    function payment_check(bool $condition, string $label): void {
        if (!$condition) throw new RuntimeException('FAIL: ' . $label);
        $GLOBALS['payment_checks']++;
    }
    function payment_throws(callable $callback): bool {
        try { $callback(); } catch (Throwable $error) { return true; }
        return false;
    }

    $GLOBALS['payment_checks'] = 0;
    $order = payment_reset();
    $claim = \RoxyRS\PaymentAttempts::claim($order, payment_request());
    $raw = $GLOBALS['payment_fixture']['meta'][123]['_roxy_rs_payment_attempt'][0] ?? '';
    $marker = json_decode($raw, true);
    payment_check(is_array($marker) && $claim === ['key'=>$marker['key'],'hash'=>$marker['hash']], 'claim persists and returns its immutable key/hash');
    payment_check($GLOBALS['payment_fixture']['commits'] === 1 && !$GLOBALS['payment_fixture']['in_transaction'], 'marker commits in the issuance transaction');
    payment_check(!str_contains($raw, 'SECRET_FIXTURE_VALUE') && !isset($marker['payment_token']), 'marker contains no payment secret');
    payment_check(\RoxyRS\PaymentAttempts::verify($order, $claim), 'committed marker is verified before provider call');
    payment_check(\RoxyRS\PaymentAttempts::record_result($order, $claim, 'pi_fixture123', 'succeeded'), 'provider result is separately persisted transactionally');
    $result_raw = $GLOBALS['payment_fixture']['meta'][123]['_roxy_rs_payment_result'][0] ?? '';
    $result = json_decode($result_raw, true);
    payment_check(is_array($result) && $result['key'] === $claim['key'] && $result['hash'] === $claim['hash']
        && $result['intent_id'] === 'pi_fixture123' && $result['status'] === 'succeeded', 'result record binds key/hash/intent/status without secret payload');
    payment_check(!\RoxyRS\PaymentAttempts::verify($order, $claim), 'existing result blocks another pre-provider verification');
    payment_check(!\RoxyRS\PaymentAttempts::record_result($order, $claim, 'bad-intent', 'succeeded'), 'invalid provider intent identity is rejected');
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'duplicate claim requires manual reconciliation');
    payment_check($GLOBALS['payment_fixture']['meta'][123]['_roxy_rs_payment_attempt'][0] === $raw, 'duplicate claim never overwrites the original marker');

    $order = payment_reset(); $GLOBALS['payment_fixture']['fail_write'] = true;
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'metadata write failure aborts claim');
    payment_check(!isset($GLOBALS['payment_fixture']['meta'][123]['_roxy_rs_payment_attempt']) && $GLOBALS['payment_fixture']['rollbacks'] === 1, 'write failure leaves no marker after rollback');

    $order = payment_reset(); $GLOBALS['payment_fixture']['fail_commit'] = true;
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'commit failure is reported as uncertain');
    payment_check(!isset($GLOBALS['payment_fixture']['meta'][123]['_roxy_rs_payment_attempt']), 'failed commit does not appear successful in fixture');

    $order = payment_reset(); $GLOBALS['payment_fixture']['lose_connection_on_write'] = true;
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'connection loss after write fails closed');

    $order = payment_reset('wc-completed');
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'raw completed order status is rejected');
    $order = payment_reset('wc-pending', true);
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'Woo paid order is rejected');

    $order = payment_reset(); $GLOBALS['payment_fixture']['meta'][123]['_roxy_rs_backing_id'] = ['21'];
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'raw backing identity mismatch is rejected');
    $order = payment_reset(); $GLOBALS['payment_fixture']['meta'][123]['_customer_user'] = ['31'];
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'raw customer identity mismatch is rejected');
    $order = payment_reset(); $GLOBALS['payment_fixture']['meta'][123]['_roxy_rs_request_id'] = ['11'];
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'raw request identity mismatch is rejected');
    $order = payment_reset(); $GLOBALS['payment_fixture']['meta'][123]['_customer_user'][] = '30';
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'duplicate raw owner metadata is rejected');
    $order = payment_reset(); $GLOBALS['payment_fixture']['meta'][123]['_order_total'][] = '12.50';
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'duplicate raw amount metadata is rejected');
    $order = payment_reset(); $GLOBALS['payment_fixture']['meta'][123]['_order_currency'][] = 'USD';
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'duplicate raw currency metadata is rejected');
    $order = payment_reset(); $GLOBALS['payment_fixture']['meta'][123]['_order_total'] = ['12.51'];
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'raw order amount mismatch is rejected');
    $order = payment_reset(); $GLOBALS['payment_fixture']['meta'][123]['_order_currency'] = ['CAD'];
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'raw order currency mismatch is rejected');
    $order = payment_reset('wc-on-hold'); $GLOBALS['payment_fixture']['meta'][123]['_date_paid'] = ['2026-10-06 19:00:00'];
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'existing paid timestamp is rejected even when status is on-hold');
    $order = payment_reset(); $GLOBALS['payment_fixture']['post_type'] = 'shop_subscription';
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'raw non-order post type is rejected');
    $order = payment_reset('wc-cancelled');
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'cancelled raw order status is rejected');
    $order = payment_reset('trash');
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'trashed raw order status is rejected');
    $order = payment_reset(); $GLOBALS['payment_fixture']['meta'][123]['_order_total'] = ['12.50'];
    $order = new WC_Order(123, false, '13.00', 'USD');
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'Woo object amount mismatch is rejected');
    $order = new WC_Order(123, false, '12.50', 'CAD');
    payment_check(payment_throws(static fn()=>\RoxyRS\PaymentAttempts::claim($order, payment_request())), 'Woo object currency mismatch is rejected');

    $order = payment_reset(); $claim = \RoxyRS\PaymentAttempts::claim($order, payment_request());
    payment_check(\RoxyRS\PaymentAttempts::record_result($order, $claim, 'pi_fixture456', 'requires_action'), 'valid non-success provider status is recorded as review evidence');
    $pending_result = json_decode($GLOBALS['payment_fixture']['meta'][123]['_roxy_rs_payment_result'][0], true);
    payment_check(($pending_result['status'] ?? '') === 'requires_action', 'pending result is recorded without marking the order paid');
    $order = payment_reset(); $claim = \RoxyRS\PaymentAttempts::claim($order, payment_request());
    $GLOBALS['payment_fixture']['fail_write'] = true;
    payment_check(!\RoxyRS\PaymentAttempts::record_result($order, $claim, 'pi_fixture789', 'succeeded'), 'result write failure returns false');
    $order = payment_reset(); $claim = \RoxyRS\PaymentAttempts::claim($order, payment_request());
    $GLOBALS['payment_fixture']['fail_commit'] = true;
    payment_check(!\RoxyRS\PaymentAttempts::record_result($order, $claim, 'pi_fixture789', 'succeeded'), 'result commit failure returns false');

    $order = payment_reset(); $claim = \RoxyRS\PaymentAttempts::claim($order, payment_request());
    $changed_claim = $claim; $changed_claim['hash'] = str_repeat('0', 64);
    payment_check(!\RoxyRS\PaymentAttempts::verify($order, $changed_claim), 'changed claim hash cannot verify old marker');
    $GLOBALS['payment_fixture']['meta'][123]['_roxy_rs_payment_attempt'][0] = '{broken';
    payment_check(!\RoxyRS\PaymentAttempts::verify($order, $claim), 'corrupt marker fails closed at pre-provider verification');

    echo 'OK: ' . $GLOBALS['payment_checks'] . " requested payment-attempt checks\n";
}
