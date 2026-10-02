<?php
// Standalone regression checks: no WordPress database or email delivery.
namespace RoxyGrosses {
    class Settings {
        public static function get_all() { return ['square_location_ids' => 'L1,L2']; }
        public static function line_list($value) { return explode(',', $value); }
        public static function square_access_token() { return 'test'; }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    define('ARRAY_A', 'ARRAY_A');
    function sanitize_text_field($value) { return (string) $value; }
    function current_time($format) { return '2026-10-01 12:00:00'; }
    function wp_json_encode($value) { return json_encode($value); }
    function get_option($key, $default = false) { return $GLOBALS['test_options'][$key] ?? ($key === 'admin_email' ? 'manager@example.test' : $default); }
    function wp_parse_args($saved, $defaults) { return array_merge($defaults, $saved); }
    function sanitize_email($value) { return (string) $value; }
    function sanitize_textarea_field($value) { return (string) $value; }
    function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
    function add_query_arg($key, $value, $url) { return $url . '&' . $key . '=' . $value; }
    function is_wp_error($value) { return false; }
    function wp_remote_retrieve_response_code($response) { return $response['code']; }
    function wp_remote_retrieve_body($response) { return json_encode($response['data']); }
    function wp_remote_get($url, $args) {
        return ['code' => 200, 'data' => ['objects' => [[
            'type' => 'ITEM', 'id' => 'item', 'item_data' => ['name' => 'Test', 'variations' => [[
                'type' => 'ITEM_VARIATION', 'id' => 'variation',
                'item_variation_data' => ['name' => 'Regular', 'price_money' => ['amount' => 999]],
            ]]],
        ]]]];
    }
    function wp_remote_post($url, $args) {
        $body = json_decode($args['body'], true);
        check($body['states'] === ['IN_STOCK'], 'Request filters available stock');
        if (isset($body['cursor'])) {
            check($body['cursor'] === 'page2', 'Next page requested');
            if (!empty($GLOBALS['fail_page2'])) return ['code' => 500, 'data' => []];
            return ['code' => 200, 'data' => ['counts' => [['catalog_object_id' => 'variation', 'state' => 'IN_STOCK', 'quantity' => '4']]]];
        }
        return ['code' => 200, 'data' => ['cursor' => 'page2', 'counts' => [
            ['catalog_object_id' => 'variation', 'state' => 'IN_STOCK', 'quantity' => '3'],
            ['catalog_object_id' => 'variation', 'state' => 'WASTE', 'quantity' => '99'],
        ]]];
    }
    class TestDatabase {
        public $prefix = 'test_';
        public $products = [];
        public $orders = [];
        public function prepare($sql, ...$args) {
            if (count($args) === 1 && is_array($args[0])) $args = $args[0];
            foreach ($args as $arg) $sql = preg_replace('/%[sdf]/', is_numeric($arg) ? (string) $arg : "'" . $arg . "'", $sql, 1);
            return $sql;
        }
        public function get_row($sql, $format) {
            if (strpos($sql, 'square_variation_id=') !== false) {
                preg_match("/square_variation_id='([^']+)'/", $sql, $match);
                return $this->products[$match[1]] ?? null;
            }
            preg_match('/id=(\d+)/', $sql, $match);
            return $this->orders[(int) ($match[1] ?? 0)] ?? null;
        }
        public function get_results($sql, $format) { return []; }
        public function query($sql) { return 0; }
        public function insert($table, $data) {
            if (strpos($table, 'products') !== false) $this->products[$data['square_variation_id']] = $data + ['id' => 1];
            return 1;
        }
        public function update($table, $data, $where) {
            if (strpos($table, 'orders') !== false) {
                $id = $where['id'];
                if (!isset($this->orders[$id]) || (isset($where['status']) && $this->orders[$id]['status'] !== $where['status'])) return 0;
                $this->orders[$id] = array_merge($this->orders[$id], $data);
            } else {
                foreach ($this->products as &$p) if ($p['id'] === $where['id']) $p = array_merge($p, $data);
                unset($p);
            }
            return 1;
        }
    }
    function check($condition, $message) {
        if (!$condition) throw new \RuntimeException($message);
    }
    require $argv[1] . '/class-roxy-inventory-store.php';
    require $argv[1] . '/class-roxy-inventory-settings.php';
    require $argv[1] . '/class-roxy-inventory-square.php';
    require $argv[1] . '/class-roxy-inventory-scheduler.php';
    require $argv[1] . '/class-roxy-inventory-admin.php';
}
namespace RoxyInventory {
    class Vendor_Map { public static function assign($name) { return ''; } }
}
namespace {
    $wpdb = new TestDatabase();
    foreach (['ordered', 'rejected'] as $decision) {
        $wpdb->orders[1] = ['id' => 1, 'status' => 'pending_manager'];
        check(\RoxyInventory\Store::update_order_status(1, 'approval_emailed'), 'Submission status saved');
        check(\RoxyInventory\Store::update_order_status(1, $decision), 'Manager decision saved');
        check(!\RoxyInventory\Store::update_order_status(1, 'approval_emailed'), 'Closed decision cannot be reopened');
    }
    foreach (['pending_manager', 'approval_emailed', 'ordered'] as $status) {
        $wpdb->orders[1] = ['id' => 1, 'status' => $status];
        check(\RoxyInventory\Store::update_order_status(1, 'cancelled'), 'Cancellation saves for ' . $status);
    }
    $wpdb->orders[1] = ['id' => 1, 'status' => 'pending_manager'];
    check(\RoxyInventory\Store::update_order_status(1, 'email_failed'), 'Email failure releases pending status');
    check(!\RoxyInventory\Store::update_order_status(999, 'ordered'), 'Missing order rejected');
    \RoxyInventory\Square::pull();
    check($wpdb->products['variation']['on_hand'] === 7.0, 'All pages summed; waste excluded');
    check($wpdb->products['variation']['unit_cost'] === 0, 'New purchase cost starts at zero');
    $wpdb->products['variation']['unit_cost'] = 1.50;
    \RoxyInventory\Square::pull();
    check($wpdb->products['variation']['unit_cost'] === 1.50, 'Existing purchase cost preserved');
    $wpdb->products['variation']['on_hand'] = 42;
    $GLOBALS['fail_page2'] = true;
    try { \RoxyInventory\Square::pull(); throw new \RuntimeException('Failed page was accepted'); }
    catch (\RuntimeException $e) { check($e->getMessage() !== 'Failed page was accepted', 'Pull fails on API error'); }
    check($wpdb->products['variation']['on_hand'] === 42, 'Failed later page preserves saved stock');
    \RoxyInventory\Scheduler::run();
    check(!method_exists(\RoxyInventory\Admin::class, 'email_ready_drafts'), 'Automatic order email routine removed');
    $vendor = ['name' => 'Tripp', 'order_method' => 'email', 'email' => 'vendor@example.test'];
    $lines = [['product' => 'Beer - Goose IPA', 'quantity' => 24, 'pack_size' => 12]];
    foreach (['Tripp', 'Odom'] as $name) {
        $vendor['name'] = $name;
        $email = \RoxyInventory\Admin::order_email($vendor, $lines, 36, 10, false);
        check(strpos($email['body'], '12oz bottles or cans only.') === 0, 'Instructions at top for ' . $name);
        check(strpos($email['body'], 'Wine is boxed only.') !== false, 'Boxed wine instructions');
        check(strpos($email['body'], '24 units') !== false && strpos($email['body'], 'pack size') === false, 'Total units unambiguous');
        check($email['to'] === 'manager@example.test', 'Manager receives approval');
        check(strpos($email['body'], 'Forward to: vendor@example.test') !== false, 'Forwarding address included');
        check(strpos($email['body'], 'token=') === false, 'Forwarding exposes no decision token');
        $direct = \RoxyInventory\Admin::order_email($vendor, $lines, 36, 10, true);
        check($direct['to'] === 'vendor@example.test', 'Direct order addressed to vendor');
        check(strpos($direct['body'], 'Manager review') === false, 'Direct email contains only vendor content');
    }
    $wpdb->orders[1] = ['id' => 1, 'status' => 'pending_manager'];
    check(\RoxyInventory\Store::update_order_status(1, 'ordered'), 'Direct email marks order Ordered');
    check(\RoxyInventory\Settings::get('direct_vendor_sending_enabled') === '0', 'Approval remains enabled by default');
    check(\RoxyInventory\Settings::sanitize(['direct_vendor_sending_enabled' => '1'])['direct_vendor_sending_enabled'] === '1', 'Direct setting can be saved');
    echo "PASS: order statuses, cancellation, pagination, stock-state filtering, zero initial costs, preserved costs, failed-page preservation, silent nightly failure.\n";
    echo "PASS: Tripp/Odom instructions, forwarding addresses, manager and direct recipients, no forwarded decision tokens, direct status and settings.\n";
}
