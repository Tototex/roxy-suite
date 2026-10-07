<?php
// Isolated Requested Showings subscriber-entitlement checks. Run with: php tests/requested-entitlement-regression.php [repo-root]
namespace RoxyST {
    final class Reservations {
        public static $used = 0;
        public static function quantity_for_showing(int $showing_id, int $exclude_order_id = 0, int $subscriber_user_id = 0): int { return self::$used; }
    }
    final class Holds {
        public static $calls = 0;
        public static $allow = true;
        public static function claim($order): bool {
            self::$calls++;
            $order->events[] = 'hold_claim';
            if (empty($order->saved)) throw new \RuntimeException('Hold claim received an unsaved order');
            return self::$allow;
        }
    }
}

namespace {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
    if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
    if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
    if (!defined('DB_NAME')) define('DB_NAME', 'requested_entitlement_fixture');
    if (!defined('ROXY_ST_META_SHOWING_ID')) define('ROXY_ST_META_SHOWING_ID', '_roxy_showing_id');

    final class FixtureRedirect extends \RuntimeException { public $url; public function __construct($url) { $this->url = $url; } }
    final class WP_Error {
        private $message;
        public function __construct($code = '', $message = '') { $this->message = (string) $message; }
        public function get_error_message() { return $this->message; }
    }
    final class FixtureSubscription {
        private $status; private $qty;
        public function __construct($status, $qty = 1) { $this->status = $status; $this->qty = $qty; }
        public function has_status($status) { return $this->status === $status; }
        public function get_items() { return [new FixtureSubscriptionItem($this->qty)]; }
    }
    final class FixtureSubscriptionItem { private $qty; public function __construct($qty) { $this->qty = $qty; } public function get_quantity() { return $this->qty; } }
    final class FixtureToken {
        public function get_gateway_id() { return 'stripe'; }
        public function get_display_name() { return 'Fixture card'; }
        public function get_id() { return 5; }
        public function get_token() { return 'not-a-real-token'; }
    }
    final class WC_Payment_Tokens { public static function get_customer_tokens($user_id) { return [new FixtureToken()]; } }
    final class FixtureProduct { public $id; public function __construct($id) { $this->id = $id; } }
    final class FixtureOrderItem {
        private $product; private $qty;
        public function __construct($product, $qty) { $this->product = $product; $this->qty = $qty; }
        public function get_product_id() { return $this->product; }
        public function get_quantity() { return $this->qty; }
    }
    class WC_Order {
        public $id = 7001; public $customer_id = 77; public $saved = false; public $events = []; public $lines = []; public $completed = 0; public $meta = []; public $paid = false;
        public function add_product($product, $qty) { $this->lines[] = new FixtureOrderItem($product->id, $qty); return count($this->lines); }
        public function get_items($type = 'line_item') { return $this->lines; }
        public function get_item($id) { return $this->lines[$id - 1] ?? null; }
        public function add_meta_data($key, $value, $unique = false) { $this->meta[$key] = $value; }
        public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
        public function get_customer_id() { return $this->customer_id; }
        public function is_paid() { return $this->paid; }
        public function calculate_totals() { $this->events[] = 'calculate'; }
        public function save() { $this->saved = true; $this->events[] = 'save'; }
        public function set_created_via($value) {}
        public function set_payment_method($value) {}
        public function set_payment_method_title($value) {}
        public function payment_complete($key = '') { $this->events[] = 'payment_complete'; $this->completed++; }
        public function get_id() { return $this->id; }
    }

    final class FixtureWpdb {
        public $prefix = 'wp_'; public $last_error = ''; public $insert_id = 0;
        public $outstanding = 0; public $rows = []; public $lock_busy = false; public $read_error = false; public $throw_read = false; public $lose_lock_after_read = false;
        public $connection_error = false; public $owner_mismatch = false; public $release_count = 0; public $guarded_insert_sql = '';
        private $locked = false; private $connection = 51;
        public function prepare($sql, ...$args) {
            if (count($args) === 1 && is_array($args[0])) $args = $args[0];
            foreach ($args as $arg) { $replacement = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'"; $sql = preg_replace('/%[sd]/', $replacement, $sql, 1); }
            return $sql;
        }
        public function get_var($sql) {
            if (strpos($sql, 'GET_LOCK(') !== false) { if ($this->lock_busy) return 0; $this->locked = true; return 1; }
            if ($sql === 'SELECT CONNECTION_ID()') { if ($this->connection_error) { $this->last_error = 'connection read fault'; $this->connection_error = false; } return (string) $this->connection; }
            if (strpos($sql, 'IS_USED_LOCK(') !== false) return $this->locked ? ($this->owner_mismatch ? '99' : (string) $this->connection) : null;
            if (strpos($sql, 'RELEASE_LOCK(') !== false) { $this->release_count++; $this->locked = false; return 1; }
            if (strpos($sql, 'SUM(subscriber_qty)') !== false) {
                if ($this->throw_read) throw new \RuntimeException('fixture read exception');
                if ($this->read_error) { $this->last_error = 'fixture query error'; return null; }
                $value = $this->outstanding;
                if ($this->lose_lock_after_read) $this->locked = false;
                return $value;
            }
            return null;
        }
        public function get_row($sql, $output = null) {
            if (strpos($sql, 'SELECT * FROM wp_roxy_requested_showing_backings') === 0) {
                preg_match('/WHERE id = [\'\"]?(\d+)/', $sql, $m);
                return $this->rows[(int) ($m[1] ?? 0)] ?? null;
            }
            return ['support_qty'=>0,'subscriber_qty'=>$this->outstanding,'charge_total'=>0,'sponsor_amount'=>0,'sponsor_ticket_qty'=>0,'has_sponsor'=>0];
        }
        public function query($sql) {
            $this->guarded_insert_sql = $sql;
            if (strpos($sql, 'INSERT INTO wp_roxy_requested_showing_backings') !== 0
                || strpos($sql, 'CONNECTION_ID()') === false || strpos($sql, 'IS_USED_LOCK(') === false
                || !$this->locked || $this->owner_mismatch) return 0;
            preg_match('/\((.*?)\) SELECT (.*?) WHERE CONNECTION_ID\(\)/s', $sql, $m);
            if (!$m) return 0;
            $columns = array_map(static fn($v) => trim($v, " `"), explode(',', $m[1]));
            $values = str_getcsv($m[2], ',', "'", '\\');
            if (count($columns) !== count($values)) { $this->last_error = 'fixture guarded insert parse error'; return false; }
            $row = array_combine($columns, $values);
            foreach ($row as $key => $value) if ($value === 'NULL') $row[$key] = null;
            $this->insert_id++;
            $row['id'] = $this->insert_id;
            $this->rows[$this->insert_id] = $row;
            $this->outstanding += (int) $row['subscriber_qty'];
            return 1;
        }
        public function insert($table, $data) { $this->insert_id++; $data['id']=$this->insert_id; $this->rows[$this->insert_id] = $data; $this->outstanding += (int) $data['subscriber_qty']; return 1; }
        public function update($table, $data, $where) { $id=(int)($where['id']??0); if(isset($this->rows[$id]))$this->rows[$id]=array_merge($this->rows[$id],$data); return 1; }
        public function is_locked() { return $this->locked; }
    }

    $root = $argv[1] ?? dirname(__DIR__);
    require $root . '/includes/modules/requested-showings/includes/schema.php';
    require $root . '/includes/modules/requested-showings/includes/repository.php';
    require $root . '/includes/modules/requested-showings/includes/class-roxy-rs-cpt.php';
    require $root . '/includes/modules/requested-showings/includes/class-roxy-rs-settings.php';
    require $root . '/includes/modules/show-tickets/includes/class-roxy-st-capacity.php';
    require $root . '/includes/modules/requested-showings/includes/class-roxy-rs-conversion.php';
    require $root . '/includes/modules/requested-showings/includes/class-roxy-rs-frontend.php';

    $wpdb = new FixtureWpdb();
    $GLOBALS['wpdb'] = $wpdb;
    $GLOBALS['fixture_subscriptions'] = [];
    $GLOBALS['fixture_subscription_error'] = false;
    $GLOBALS['fixture_meta'] = [];
    $GLOBALS['fixture_transients'] = [];
    $GLOBALS['fixture_redirects'] = [];
    $GLOBALS['fixture_user'] = 77;
    $GLOBALS['fixture_order_creates'] = 0;
    $GLOBALS['fixture_order'] = null;
    $GLOBALS['fixture_payment_calls'] = 0;
    $GLOBALS['fixture_options'] = ['roxy_rs_settings'=>[]];

    function wcs_get_users_subscriptions($user_id) {
        if ($GLOBALS['fixture_subscription_error']) throw new \RuntimeException('fixture WCS unavailable');
        return $GLOBALS['fixture_subscriptions'];
    }
    function check_admin_referer($action) {}
    function is_user_logged_in() { return true; }
    function get_current_user_id() { return $GLOBALS['fixture_user']; }
    function get_post($id) { return (object) ['ID'=>$id,'post_type'=>'roxy_req_showing','post_title'=>'Fixture request','post_content'=>'']; }
    function get_post_meta($id, $key, $single = false) { return $GLOBALS['fixture_meta'][$id][$key] ?? ''; }
    function get_option($key, $default = false) { return $GLOBALS['fixture_options'][$key] ?? $default; }
    function update_post_meta($id, $key, $value) { $GLOBALS['fixture_meta'][$id][$key] = $value; return true; }
    function wp_unslash($value) { return $value; }
    function sanitize_text_field($value) { return is_scalar($value) ? (string) $value : ''; }
    function current_time($type) { return '2026-10-06 12:00:00'; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function get_transient($key) { return $GLOBALS['fixture_transients'][$key] ?? false; }
    function set_transient($key, $value, $ttl) { $GLOBALS['fixture_transients'][$key] = $value; return true; }
    function wp_json_encode($value) { return json_encode($value); }
    function get_permalink($id = 0) { return 'https://fixture.invalid/request/' . $id; }
    function home_url($path = '/') { return 'https://fixture.invalid' . $path; }
    function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
    function wp_safe_redirect($url) { $GLOBALS['fixture_redirects'][] = $url; throw new FixtureRedirect($url); }
    function wc_get_product($id) { return new FixtureProduct($id); }
    function wc_create_order($args = []) { $GLOBALS['fixture_order_creates']++; $GLOBALS['fixture_order'] = new WC_Order(); $GLOBALS['fixture_order']->customer_id=(int)($args['customer_id']??0); return $GLOBALS['fixture_order']; }
    function wc_get_order($id) { return $GLOBALS['fixture_order']; }
    function get_user_by($field, $value) { return false; }

    $checks = 0;
    function check_fixture($condition, $label) { global $checks; if (!$condition) throw new \RuntimeException('FAIL: ' . $label); $checks++; }
    function reset_fixture($subscriptions = [], $entitlement_error = false) {
        global $wpdb;
        $wpdb = new FixtureWpdb(); $GLOBALS['wpdb'] = $wpdb;
        \RoxyST\Reservations::$used = 0; \RoxyST\Holds::$allow = true; \RoxyST\Holds::$calls = 0;
        $GLOBALS['fixture_subscriptions'] = $subscriptions;
        $GLOBALS['fixture_subscription_error'] = $entitlement_error;
        $GLOBALS['fixture_meta'] = [501 => [
            \RoxyRS\CPT::META_STATUS => 'active',
            \RoxyRS\CPT::META_GENERAL_PRICE => '12',
            \RoxyRS\CPT::META_DISCOUNT_PRICE => '8',
            \RoxyRS\CPT::META_FUNDING_GOAL => '999999',
            \RoxyRS\CPT::META_PRICING_PROFILE => 'movie_evening',
        ], 801 => ['_roxy_pid_adult'=>901, '_roxy_pid_discount'=>902, '_roxy_pid_subscriber'=>903],
            901 => [ROXY_ST_META_SHOWING_ID=>801], 902 => [ROXY_ST_META_SHOWING_ID=>801], 903 => [ROXY_ST_META_SHOWING_ID=>801]];
        $GLOBALS['fixture_transients'] = []; $GLOBALS['fixture_redirects'] = [];
        $GLOBALS['fixture_order_creates'] = 0; $GLOBALS['fixture_order'] = null; $GLOBALS['fixture_payment_calls'] = 0;
        $_POST = ['request_id'=>'501','general_qty'=>'0','discount_qty'=>'0','subscriber_qty'=>'1'];
    }
    function seed_conversion_backing($order_id = 0) {
        global $wpdb;
        $wpdb->rows[1] = ['id'=>1,'request_id'=>501,'user_id'=>77,'woo_order_id'=>$order_id ?: null,'subscriber_qty'=>1,'status'=>'approved'];
        $wpdb->insert_id = 1;
    }
    function invoke_backing() {
        try { \RoxyRS\Frontend::handle_commit_backing(); }
        catch (FixtureRedirect $redirect) { return $redirect->url; }
        throw new \RuntimeException('Handler did not redirect.');
    }
    function has_error_redirect($url) { return strpos($url, 'roxy_rs_notice=error') !== false; }

    reset_fixture([]);
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'nonmember cannot pledge subscriber tickets');
    check_fixture($wpdb->release_count === 0 && !$wpdb->is_locked(), 'nonmember denial acquired no entitlement lease');
    reset_fixture([new FixtureSubscription('expired', 2)]);
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'expired WCS membership is not eligible');
    reset_fixture([new FixtureSubscription('active', 2)]);
    $_POST['subscriber_qty'] = '1.5';
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'fractional POST quantity is rejected without truncation');
    check_fixture($wpdb->release_count === 0 && !$wpdb->is_locked(), 'invalid quantity acquired no entitlement lease');
    reset_fixture([new FixtureSubscription('active', 2)]);
    $_POST['general_qty'] = '1.0';
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'fractional general quantity is rejected');
    reset_fixture([new FixtureSubscription('active', 2)]);
    $_POST['discount_qty'] = ['1'];
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'array discount quantity is rejected');
    reset_fixture([new FixtureSubscription('active', 2)]);
    $_POST['general_qty'] = (string) PHP_INT_MAX; $_POST['discount_qty'] = '1';
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'ticket quantity sum overflow is rejected');
    reset_fixture([]);
    $_POST = ['request_id'=>'501','general_qty'=>'4294967296','discount_qty'=>'0','subscriber_qty'=>'0','payment_token_id'=>'5'];
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'quantity beyond unsigned schema range is rejected');
    reset_fixture([]);
    $_POST = ['request_id'=>'501','general_qty'=>'2000000','discount_qty'=>'0','subscriber_qty'=>'0','payment_token_id'=>'5'];
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'ticket multiplication beyond signed money range is rejected before persistence');
    reset_fixture([]);
    $result = roxy_rs_repo_insert_backing(['request_id'=>501,'user_id'=>77,'general_qty'=>1,'support_qty'=>1,'charge_total'=>'2147483648']);
    check_fixture(is_wp_error($result) && count($wpdb->rows) === 0, 'repository rejects money beyond signed schema range');
    reset_fixture([]);
    $result = roxy_rs_repo_insert_backing(['request_id'=>501,'user_id'=>77,'general_qty'=>'4294967295','support_qty'=>'4294967295']);
    check_fixture(!is_wp_error($result) && $wpdb->rows[1]['general_qty'] === 4294967295, 'maximum unsigned ticket quantity remains representable');
    reset_fixture([new FixtureSubscription('active', 2)]);
    $_POST['subscriber_qty'] = ['2'];
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'array POST quantity is rejected');
    reset_fixture([new FixtureSubscription('active', 2)]);
    $_POST['subscriber_qty'] = '01';
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'noncanonical leading-zero quantity is rejected');
    reset_fixture([new FixtureSubscription('active', 2)]);
    $wpdb->outstanding = 1; $GLOBALS['wpdb'] = $wpdb; $_POST['subscriber_qty'] = '2';
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'pledge cannot exceed remaining entitlement for request');
    check_fixture($wpdb->release_count === 1 && !$wpdb->is_locked(), 'excess denial releases verified lease');
    reset_fixture([new FixtureSubscription('active', 2)]);
    $_POST['subscriber_qty'] = '1'; invoke_backing();
    check_fixture(count($wpdb->rows) === 1 && $wpdb->outstanding === 1, 'first subscriber pledge is stored');
    check_fixture($wpdb->release_count === 1 && !$wpdb->is_locked() && strpos($wpdb->guarded_insert_sql, 'IS_USED_LOCK(') !== false, 'successful guarded insert releases lease');
    invoke_backing();
    check_fixture(count($wpdb->rows) === 1, 'exact retry is idempotent through backing replay key');
    $_POST['subscriber_qty'] = '2';
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 1, 'second distinct pledge respects aggregate outstanding quantity');
    check_fixture($wpdb->release_count === 2 && !$wpdb->is_locked(), 'distinct excess retry releases verified lease');
    reset_fixture([new FixtureSubscription('active', 2)]); $wpdb->read_error = true;
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'aggregate-read error fails closed');
    check_fixture($wpdb->release_count === 1 && !$wpdb->is_locked(), 'aggregate read error releases verified lease');
    reset_fixture([new FixtureSubscription('active', 2)]); $GLOBALS['fixture_subscription_error'] = true;
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'WCS entitlement exception fails closed');
    check_fixture($wpdb->release_count === 0 && !$wpdb->is_locked(), 'entitlement exception acquired no lease');
    reset_fixture([new FixtureSubscription('active', 2)]); $wpdb->lock_busy = true;
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'connection-owned entitlement lock contention fails closed');
    check_fixture($wpdb->release_count === 0 && !$wpdb->is_locked(), 'busy lease is not released by non-owner');
    reset_fixture([new FixtureSubscription('active', 2)]); $wpdb->lose_lock_after_read = true;
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'lost lease before insert fails closed');
    check_fixture($wpdb->release_count === 0 && !$wpdb->is_locked(), 'lost lease is not released by non-owner');
    reset_fixture([new FixtureSubscription('active', 2)]); $wpdb->connection_error = true;
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'connection identity read failure fails closed');
    check_fixture($wpdb->release_count === 1 && !$wpdb->is_locked(), 'connection read error releases only after ownership is reverified');
    reset_fixture([new FixtureSubscription('active', 2)]); $wpdb->owner_mismatch = true;
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'mismatched lock owner fails closed');
    check_fixture($wpdb->release_count === 0, 'foreign lock owner is never released');
    reset_fixture([]); $GLOBALS['fixture_meta'][501][\RoxyRS\CPT::META_SPONSOR_TICKETS] = '1.5';
    $_POST = ['request_id'=>'501','general_qty'=>'0','discount_qty'=>'0','subscriber_qty'=>'0','sponsor_request'=>'1','payment_token_id'=>'5'];
    check_fixture(has_error_redirect(invoke_backing()) && count($wpdb->rows) === 0, 'malformed configured sponsor ticket quantity is rejected');
    reset_fixture([]); $_POST = ['request_id'=>'501','general_qty'=>'1','discount_qty'=>'0','subscriber_qty'=>'0','payment_token_id'=>'5'];
    invoke_backing();
    check_fixture(count($wpdb->rows) === 1 && (int) $wpdb->rows[1]['general_qty'] === 1 && (int) $wpdb->rows[1]['charge_total'] === 1200, 'paid-only backing path remains unchanged without subscriber membership');

    $convert = new \ReflectionMethod('RoxyRS\\Conversion', 'convert_backing_to_order');
    $convert->setAccessible(true);
    reset_fixture([]); seed_conversion_backing();
    $result = $convert->invoke(null, 501, 801, ['id'=>1,'request_id'=>501,'user_id'=>77,'subscriber_qty'=>1,'charge_total'=>0]);
    check_fixture(is_wp_error($result) && $GLOBALS['fixture_order_creates'] === 0, 'conversion rechecks entitlement before creating an order');
    reset_fixture([new FixtureSubscription('active', 2)]); seed_conversion_backing(); \RoxyST\Reservations::$used = 2;
    $result = $convert->invoke(null, 501, 801, ['id'=>1,'request_id'=>501,'user_id'=>77,'subscriber_qty'=>1,'charge_total'=>0]);
    check_fixture(is_wp_error($result) && $GLOBALS['fixture_order_creates'] === 0, 'conversion checks remaining entitlement before order creation');
    reset_fixture([new FixtureSubscription('active', 2)]); seed_conversion_backing(); \RoxyST\Holds::$allow = false;
    $result = $convert->invoke(null, 501, 801, ['id'=>1,'request_id'=>501,'user_id'=>77,'subscriber_qty'=>1,'charge_total'=>0]);
    check_fixture(is_wp_error($result) && $GLOBALS['fixture_order_creates'] === 1 && $GLOBALS['fixture_order']->completed === 0, 'failed persisted seat claim blocks no-charge completion');
    check_fixture((int) ($wpdb->rows[1]['woo_order_id'] ?? 0) === 7001, 'unpaid saved order is linked for safe manager retry');
    reset_fixture([new FixtureSubscription('active', 2)]); seed_conversion_backing();
    $result = $convert->invoke(null, 501, 801, ['id'=>1,'request_id'=>501,'user_id'=>77,'subscriber_qty'=>1,'charge_total'=>0]);
    $events = $GLOBALS['fixture_order']->events;
    check_fixture($result === 7001 && \RoxyST\Holds::$calls === 1 && array_search('hold_claim', $events, true) < array_search('payment_complete', $events, true), 'persisted ticket hold is claimed before no-charge completion');
    reset_fixture([new FixtureSubscription('active', 2)]); seed_conversion_backing(7002);
    $GLOBALS['fixture_order'] = new WC_Order(); $GLOBALS['fixture_order']->id = 7002; $GLOBALS['fixture_order']->saved = true; $GLOBALS['fixture_order']->paid = true;
    $GLOBALS['fixture_order']->meta = ['_roxy_rs_request_id'=>501,'_roxy_rs_backing_id'=>1];
    $result = $convert->invoke(null, 501, 801, ['id'=>1,'request_id'=>501,'user_id'=>77,'woo_order_id'=>7002,'subscriber_qty'=>1,'charge_total'=>1200]);
    check_fixture(is_wp_error($result) && $GLOBALS['fixture_order_creates'] === 0 && \RoxyST\Holds::$calls === 0, 'already-paid order requires review without another charge');
    reset_fixture([new FixtureSubscription('active', 2)]); seed_conversion_backing(7002);
    $GLOBALS['fixture_order'] = new WC_Order(); $GLOBALS['fixture_order']->id = 7002; $GLOBALS['fixture_order']->saved = true; $GLOBALS['fixture_order']->customer_id = 99;
    $GLOBALS['fixture_order']->meta = ['_roxy_rs_request_id'=>501,'_roxy_rs_backing_id'=>1];
    $result = $convert->invoke(null, 501, 801, ['id'=>1,'request_id'=>501,'user_id'=>77,'woo_order_id'=>7002,'subscriber_qty'=>1,'charge_total'=>0]);
    check_fixture(is_wp_error($result) && \RoxyST\Holds::$calls === 0, 'mismatched existing order owner is never reused or claimed');

    echo "OK: {$checks} requested entitlement checks\n";
}
