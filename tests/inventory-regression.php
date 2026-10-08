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
    define('DAY_IN_SECONDS', 86400);
    function sanitize_text_field($value) { return (string) $value; }
    function current_time($format) { return '2026-10-01 12:00:00'; }
    function wp_json_encode($value) { return json_encode($value); }
    function get_option($key, $default = false) { return $GLOBALS['test_options'][$key] ?? ($key === 'admin_email' ? 'manager@example.test' : $default); }
    function wp_parse_args($saved, $defaults) { return array_merge($defaults, $saved); }
    function wp_unslash($value) { return $value; }
    function wp_get_scheduled_event($hook) { return (object) ['schedule' => '']; }
    function sanitize_email($value) { return (string) $value; }
    function sanitize_textarea_field($value) { return (string) $value; }
    function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
    function add_query_arg($key, $value, $url) { return $url . '&' . $key . '=' . $value; }
    function is_wp_error($value) { return false; }
    function add_filter(...$args) {}
    function remove_filter(...$args) {}
    function wp_remote_retrieve_response_code($response) { return $response['code']; }
    function wp_remote_retrieve_body($response) { return json_encode($response['data']); }
    function wp_remote_get($url, $args) {
        if (!empty($GLOBALS['empty_catalog'])) return ['code' => 200, 'data' => ['objects' => []]];
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
            return ['code' => 200, 'data' => ['counts' => [['catalog_object_id' => 'variation', 'location_id'=>'L2', 'state' => 'IN_STOCK', 'quantity' => '4']]]];
        }
        return ['code' => 200, 'data' => ['cursor' => 'page2', 'counts' => [
            ['catalog_object_id' => 'variation', 'location_id'=>'L1', 'state' => 'IN_STOCK', 'quantity' => '3'],
            ['catalog_object_id' => 'variation', 'state' => 'WASTE', 'quantity' => '99'],
        ]]];
    }
    class TestDatabase {
        public $prefix = 'test_';
        public $products = [];
        public $orders = [];
        public $fail_log = false;
        public $runs = [];
        private $saved;
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
        public function get_var($sql) { if(preg_match('/SELECT id FROM .*products WHERE id=(\d+)/',$sql,$m)){foreach($this->products as $p)if($p['id']==(int)$m[1])return $p['id'];return null;}return 1; }
        public function suppress_errors($value) { return false; }
        public function query($sql) {
            $GLOBALS['test_queries'][] = $sql;
            if (strpos($sql,'RELEASE SAVEPOINT ')===0) return false;
            if ($sql === 'START TRANSACTION') $this->saved = [$this->products, $this->orders, $this->runs];
            if ($sql === 'ROLLBACK') [$this->products, $this->orders, $this->runs] = $this->saved;
            return 0;
        }
        public function insert($table, $data) {
            if (strpos($table, 'runs') !== false) {
                if ($this->fail_log) return false;
                $this->runs[] = $data;
            }
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
    $known_costs = \RoxyInventory\Admin::cost_summary([
        ['product'=>['unit_cost'=>2.5],'qty'=>3],
        ['product'=>['unit_cost'=>0],'qty'=>0],
    ]);
    check($known_costs === ['known_total'=>7.5,'incomplete'=>false], 'Zero-quantity unknown-cost rows do not affect known estimate');
    $unknown_costs = \RoxyInventory\Admin::cost_summary([
        ['product'=>['unit_cost'=>2.5],'qty'=>3],
        ['product'=>['unit_cost'=>0],'qty'=>1],
    ]);
    check($unknown_costs === ['known_total'=>7.5,'incomplete'=>true], 'Positive quantity with zero cost marks estimate incomplete without inventing a price');
    $history_costs = \RoxyInventory\Admin::cost_summary([
        ['quantity'=>2,'unit_cost'=>'2.25'],
        ['quantity'=>3,'unit_cost'=>0],
    ]);
    check($history_costs === ['known_total'=>4.5,'incomplete'=>true], 'Raw stored history quantity field recognizes positive unknown-cost lines');
    foreach (['NaN','INF',NAN,INF,'1e309','not-a-price'] as $invalid_cost) {
        $summary = \RoxyInventory\Admin::cost_summary([['quantity'=>1,'unit_cost'=>$invalid_cost]]);
        check($summary === ['known_total'=>0.0,'incomplete'=>true], 'Malformed or non-finite history cost is unknown: '.$invalid_cost);
        check(!\RoxyInventory\Admin::verified_positive_cost($invalid_cost), 'Malformed or non-finite unit cost fails positive-cost validation: '.$invalid_cost);
    }
    check(\RoxyInventory\Admin::verified_positive_cost('2.25'), 'Finite positive unit cost remains known');
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
    $before_empty_catalog = [$wpdb->products, $wpdb->orders, $wpdb->runs];
    $GLOBALS['empty_catalog'] = true;
    $empty_catalog_rejected = false;
    try { \RoxyInventory\Square::pull(); } catch (\RuntimeException $e) { $empty_catalog_rejected = strpos($e->getMessage(), 'no inventory variations') !== false; }
    unset($GLOBALS['empty_catalog']);
    check($empty_catalog_rejected && [$wpdb->products, $wpdb->orders, $wpdb->runs] === $before_empty_catalog, 'Empty successful catalog response cannot deactivate products or reset orders');
    $wpdb->products['variation']['on_hand'] = 42;
    $GLOBALS['fail_page2'] = true;
    try { \RoxyInventory\Square::pull(); throw new \RuntimeException('Failed page was accepted'); }
    catch (\RuntimeException $e) { check($e->getMessage() !== 'Failed page was accepted', 'Pull fails on API error'); }
    check($wpdb->products['variation']['on_hand'] === 42, 'Failed later page preserves saved stock');
    \RoxyInventory\Scheduler::run();
    $GLOBALS['fail_page2'] = false;
    $wpdb->fail_log = true;
    $before = [$wpdb->products, $wpdb->orders, $wpdb->runs];
    $failed = false;
    try { \RoxyInventory\Square::pull(); } catch (\RuntimeException $e) { $failed = true; }
    check($failed && [$wpdb->products, $wpdb->orders, $wpdb->runs] === $before, 'Failed pull activity rolls back stock and order changes');
    check(\RoxyInventory\Store::log('approval_email', 'success', 'Fixture') === false, 'Post-email logging failure returns false without throwing');
    $wpdb->fail_log = false;
    \RoxyInventory\Square::pull();
    check($wpdb->products['variation']['on_hand'] === 7.0 && end($wpdb->runs)['status'] === 'success', 'Retry commits stock with matching activity');
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
    $priced_line=[['product'=>'Candy','quantity'=>1,'pack_size'=>12,'unit_cost'=>15.25,'unit_cost_status'=>'confirmed','unit_cost_source'=>'Vistar price book','unit_cost_checked_at'=>'2026-10-08','supplier_sku'=>'ABC123']];
    $priced_email=\RoxyInventory\Admin::order_email($vendor,$priced_line,15.25,0,false);
    check(strpos($priced_email['body'],'Supplier SKU: ABC123')!==false && strpos($priced_email['body'],'source: Vistar price book; checked: 2026-10-08')!==false,'Supplier SKU and quote provenance are included in forwarded order email');
    $wpdb->orders[1] = ['id' => 1, 'status' => 'pending_manager'];
    check(\RoxyInventory\Store::update_order_status(1, 'ordered'), 'Direct email marks order Ordered');
    check(\RoxyInventory\Settings::get('direct_vendor_sending_enabled') === '0', 'Approval remains enabled by default');
    check(\RoxyInventory\Settings::sanitize(['direct_vendor_sending_enabled' => '1'])['direct_vendor_sending_enabled'] === '1', 'Direct setting can be saved');
    foreach (['24:00','23:60','99:99','23:00junk'] as $time) check(\RoxyInventory\Settings::sanitize(['schedule_time'=>$time])['schedule_time']==='23:00','Invalid schedule time safely defaults');
    check(\RoxyInventory\Settings::sanitize(['schedule_time'=>'22:45'])['schedule_time']==='22:45','Valid editable schedule time preserved');
    $seed = new \ReflectionMethod(\RoxyInventory\Store::class, 'seed_vendors');
    $seed->setAccessible(true);
    $GLOBALS['test_queries'] = [];
    $seed->invoke(null);
    foreach ($GLOBALS['test_queries'] as $query) {
        check(strpos($query, 'ON DUPLICATE KEY UPDATE name=name') !== false, 'Existing vendor settings are never replaced by seed defaults');
        check(strpos($query, 'email=IF') === false && strpos($query, 'minimum_amount=IF') === false, 'Blank email and zero minimum remain intentional');
    }
    echo "PASS: order statuses, cancellation, pagination, stock-state filtering, zero initial costs, preserved costs, failed-page preservation, silent nightly failure.\n";
    echo "PASS: Tripp/Odom instructions, forwarding addresses, manager and direct recipients, no forwarded decision tokens, direct status and settings.\n";
    echo "PASS: activity failure rolls back pull, post-email logging is nonthrowing, retry commits activity and stock.\n";
}
