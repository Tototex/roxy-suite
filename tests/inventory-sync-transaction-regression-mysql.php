<?php
/**
 * Exercise the actual Inventory Square pull and Store transaction against
 * uniquely named private MySQL tables. Square HTTP is fully mocked; no live
 * provider calls, mail, options, or production Inventory rows are changed.
 *
 * Run with WP-CLI in a disposable WordPress/MySQL installation:
 *   wp --path=/path/to/wordpress eval-file tests/inventory-sync-transaction-regression-mysql.php /path/to/roxy-suite
 */

if (!defined('WP_CLI') || !WP_CLI) exit('WP-CLI only');

$site_host = (string)parse_url(site_url('/'), PHP_URL_HOST);
if ($site_host !== 'roxy-test.invalid' && $site_host !== 'localhost' && $site_host !== '127.0.0.1' && substr($site_host, -8) !== '.invalid') {
    throw new RuntimeException('Refusing to run the Inventory fixture on a non-disposable WordPress site.');
}
if (!defined('DB_NAME') || DB_NAME !== 'roxy_test') {
    throw new RuntimeException('Refusing to run the Inventory fixture outside the dedicated roxy_test database.');
}

$root = $args[0] ?? dirname(__DIR__);
$store_file = rtrim((string)$root, '/\\') . '/includes/modules/inventory/includes/class-roxy-inventory-store.php';
$square_file = rtrim((string)$root, '/\\') . '/includes/modules/inventory/includes/class-roxy-inventory-square.php';
$vendor_map_file = rtrim((string)$root, '/\\') . '/includes/modules/inventory/includes/class-roxy-inventory-vendor-map.php';
foreach ([$store_file, $square_file, $vendor_map_file] as $source_file) {
    if (!is_file($source_file) || !is_readable($source_file)) throw new RuntimeException('Inventory fixture source is missing or unreadable.');
}

// Isolate class names so the fixture exercises the candidate source even if a
// separate copy of Roxy Suite happens to be loaded by the disposable site.
$store_code = file_get_contents($store_file);
$square_code = file_get_contents($square_file);
$vendor_map_code = file_get_contents($vendor_map_file);
if (!is_string($store_code) || !is_string($square_code) || !is_string($vendor_map_code)) throw new RuntimeException('Could not read Inventory fixture source.');
$store_code = preg_replace('/^<\?php\s*namespace RoxyInventory;/', 'namespace RoxyInventorySyncFixture;', $store_code, 1);
$square_code = preg_replace('/^<\?php\s*namespace RoxyInventory;/', 'namespace RoxyInventorySyncFixture;', $square_code, 1);
$vendor_map_code = preg_replace('/^<\?php\s*namespace RoxyInventory;/', 'namespace RoxyInventorySyncFixture;', $vendor_map_code, 1);
$square_code = str_replace('\\RoxyGrosses\\Settings', '\\RoxyInventorySyncFixture\\Settings', $square_code);
$square_code = str_replace('\\\\RoxyGrosses\\\\Settings', '\\\\RoxyInventorySyncFixture\\\\Settings', $square_code);
if (strpos($store_code, 'namespace RoxyInventorySyncFixture;') !== 0
    || strpos($square_code, 'namespace RoxyInventorySyncFixture;') !== 0
    || strpos($vendor_map_code, 'namespace RoxyInventorySyncFixture;') !== 0) {
    throw new RuntimeException('Could not isolate Inventory fixture classes.');
}
eval('namespace RoxyInventorySyncFixture; class Settings { public static function get_all() { return ["square_location_ids" => "fixture-location", "square_environment" => "sandbox"]; } public static function line_list($value) { return explode(",", $value); } public static function square_access_token() { return "fixture-only-token"; } }');
eval($store_code);
eval($square_code);
eval($vendor_map_code);
    global $wpdb;
    $original_prefix = (string)$wpdb->prefix;
    $token = 'inv_sync_' . bin2hex(random_bytes(6)) . '_';
    $fixture_prefix = $original_prefix . $token;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $fixture_prefix)) throw new RuntimeException('Private Inventory fixture prefix is invalid.');

    $tables = [
        'products' => $fixture_prefix . 'roxy_inventory_products',
        'orders' => $fixture_prefix . 'roxy_inventory_orders',
        'runs' => $fixture_prefix . 'roxy_inventory_runs',
    ];
    foreach ($tables as $table) {
        if (!preg_match('/^' . preg_quote($original_prefix, '/') . preg_quote($token, '/') . 'roxy_inventory_[a-z_]+$/', $table)) {
            throw new RuntimeException('Refusing an Inventory fixture table outside its private namespace.');
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
            throw new RuntimeException('Private Inventory fixture table already exists; refusing to reuse it.');
        }
    }

    $checks = 0;
    $check = static function ($condition, string $label) use (&$checks): void {
        if (!$condition) throw new RuntimeException('FAIL: ' . $label);
        $checks++;
        echo 'PASS: ' . $label . "\n";
    };
    $created_tables = [];
    $created_triggers = [];
    $http_requests = [];
    $http_filter = static function ($preempt, $args, $url) use (&$http_requests) {
        $http_requests[] = ['method' => strtoupper((string)($args['method'] ?? 'GET')), 'url' => (string)$url];
        $path = (string)parse_url((string)$url, PHP_URL_PATH);
        $host = (string)parse_url((string)$url, PHP_URL_HOST);
        $allowed_host = $host === 'connect.squareup.com' || $host === 'connect.squareupsandbox.com';
        if (!$allowed_host) return new \WP_Error('inventory_fixture_blocked', 'Fixture blocked a non-Square HTTP request.');

        if ($path === '/v2/catalog/list') {
            $payload = ['objects' => [[
                'id' => 'fixture-item',
                'type' => 'ITEM',
                'item_data' => [
                    'name' => 'Fixture Candy',
                    'variations' => [
                        ['id' => 'sync-v', 'type' => 'ITEM_VARIATION', 'item_variation_data' => ['name' => 'Regular', 'item_id' => 'fixture-item']],
                        ['id' => 'new-v', 'type' => 'ITEM_VARIATION', 'item_variation_data' => ['name' => 'New', 'item_id' => 'fixture-item']],
                    ],
                ],
            ]]];
        } elseif ($path === '/v2/inventory/changes/batch-retrieve') {
            $payload = ['changes' => [[
                'type' => 'ADJUSTMENT',
                'adjustment' => [
                    'id' => 'fixture-receipt',
                    'catalog_object_id' => 'sync-v',
                    'to_location_id' => 'fixture-location',
                    'from_state' => 'NONE',
                    'to_state' => 'IN_STOCK',
                    'quantity' => '5',
                    'reason_id' => ['type' => 'RECEIVED'],
                    'created_at' => '2026-02-01T12:00:00Z',
                    'occurred_at' => '2026-02-01T11:59:00Z',
                ],
            ]]];
        } elseif ($path === '/v2/inventory/counts/batch-retrieve') {
            $payload = ['counts' => [
                ['catalog_object_id' => 'sync-v', 'location_id' => 'fixture-location', 'state' => 'IN_STOCK', 'quantity' => '9'],
                ['catalog_object_id' => 'new-v', 'location_id' => 'fixture-location', 'state' => 'IN_STOCK', 'quantity' => '2'],
            ]];
        } else {
            return new \WP_Error('inventory_fixture_blocked', 'Fixture blocked an unexpected Square endpoint.');
        }

        return [
            'headers' => [],
            'body' => wp_json_encode($payload),
            'response' => ['code' => 200, 'message' => 'Fixture OK'],
            'cookies' => [],
            'filename' => null,
        ];
    };

    $table_exists = static function (string $table) use ($wpdb): bool {
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    };
    $snapshot = static function () use ($wpdb, $tables): array {
        $state = [];
        foreach (['products', 'orders', 'runs'] as $kind) {
            $rows = $wpdb->get_results('SELECT * FROM `' . $tables[$kind] . '` ORDER BY id', ARRAY_A);
            if (!is_array($rows) || !empty($wpdb->last_error)) throw new RuntimeException('Could not snapshot private Inventory fixture rows.');
            $state[$kind] = $rows;
        }
        return hash('sha256', wp_json_encode($state));
    };
    $drop_trigger = static function (string $trigger) use (&$created_triggers, $wpdb): void {
        if (in_array($trigger, $created_triggers, true)) {
            $wpdb->query('DROP TRIGGER IF EXISTS `' . $trigger . '`');
            $created_triggers = array_values(array_diff($created_triggers, [$trigger]));
        }
    };
    $inject_trigger = static function (string $suffix, string $table, string $timing, string $event, string $condition) use (&$created_triggers, $wpdb, $fixture_prefix): string {
        $trigger = $fixture_prefix . 'fail_' . $suffix;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $trigger) || strlen($trigger) > 64) throw new RuntimeException('Private Inventory trigger name is invalid.');
        $sql = 'CREATE TRIGGER `' . $trigger . '` ' . $timing . ' ' . $event . ' ON `' . $table . '` FOR EACH ROW BEGIN IF ' . $condition . " THEN SET @roxy_inventory_fixture_hit = '" . $suffix . "'; SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture injected failure'; END IF; END";
        if ($wpdb->query($sql) === false || !empty($wpdb->last_error)) throw new RuntimeException('Could not create private failure-injection trigger: ' . $wpdb->last_error);
        $created_triggers[] = $trigger;
        return $trigger;
    };
    $failed_pull_rolls_back = static function (string $label, string $trigger, string $injected_failure) use ($check, $drop_trigger, $snapshot, &$http_requests, $wpdb): void {
        $before = $snapshot();
        $request_count = count($http_requests);
        $wpdb->query('SET @roxy_inventory_fixture_hit = NULL');
        $failed = false;
        try { \RoxyInventorySyncFixture\Square::pull(); }
        catch (Throwable $error) { $failed = true; }
        $check($failed, $label . ' is surfaced as a failed sync');
        $check((string)$wpdb->get_var('SELECT @roxy_inventory_fixture_hit') === $injected_failure, $label . ' reached its targeted MySQL failure injection');
        $check(hash_equals($before, $snapshot()), $label . ' rolls back products, orders, and activity rows unchanged');
        $check(count($http_requests) === $request_count + 3, $label . ' uses only the three mocked Square reads');
        $drop_trigger($trigger);
    };

    add_filter('pre_http_request', $http_filter, PHP_INT_MAX, 3);
    $wpdb->prefix = $fixture_prefix;
    $errors_suppressed = $wpdb->suppress_errors(true);
    try {
        $product_ddl = "CREATE TABLE `{$tables['products']}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            square_variation_id VARCHAR(80) NOT NULL,
            square_item_id VARCHAR(80) NULL,
            name VARCHAR(190) NOT NULL,
            sku VARCHAR(190) NULL,
            vendor VARCHAR(100) NULL,
            tracking_status VARCHAR(20) NOT NULL DEFAULT 'tracked',
            active TINYINT(1) NOT NULL DEFAULT 1,
            on_hand DECIMAL(12,2) NOT NULL DEFAULT 0,
            pack_size DECIMAL(12,2) NOT NULL DEFAULT 1,
            reorder_point DECIMAL(12,2) NOT NULL DEFAULT 0,
            target_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit_cost_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
            unit_cost_source VARCHAR(190) NULL,
            unit_cost_checked_at DATE NULL,
            supplier_sku VARCHAR(190) NULL,
            override_qty DECIMAL(12,2) NULL,
            calculated_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY square_variation_id (square_variation_id),
            KEY vendor (vendor),
            KEY active (active)
        ) ENGINE=InnoDB";
        $orders_ddl = "CREATE TABLE `{$tables['orders']}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor VARCHAR(100) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'approval_emailed',
            estimated_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            minimum_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            item_count INT NOT NULL DEFAULT 0,
            payload LONGTEXT NULL,
            submission_key VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY submission_key (submission_key),
            KEY vendor (vendor),
            KEY created_at (created_at)
        ) ENGINE=InnoDB";
        $runs_ddl = "CREATE TABLE `{$tables['runs']}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_type VARCHAR(30) NOT NULL,
            status VARCHAR(20) NOT NULL,
            message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at)
        ) ENGINE=InnoDB";
        foreach ([$product_ddl, $orders_ddl, $runs_ddl] as $index => $ddl) {
            if ($wpdb->query($ddl) === false || !empty($wpdb->last_error)) throw new RuntimeException('Could not create private Inventory fixture table: ' . $wpdb->last_error);
            $created_tables[] = array_values($tables)[$index];
        }

        $now = current_time('mysql');
        foreach ([
            ['square_variation_id' => 'sync-v', 'square_item_id' => 'fixture-item', 'name' => 'Old candy name', 'sku' => 'OLD', 'vendor' => 'Vistar', 'active' => 1, 'on_hand' => 4, 'pack_size' => 12, 'reorder_point' => 2, 'target_stock' => 24, 'unit_cost' => 1.5, 'unit_cost_status' => 'estimate', 'unit_cost_source' => 'fixture baseline', 'unit_cost_checked_at' => '2026-01-01', 'supplier_sku' => 'OLD-SKU', 'override_qty' => 3, 'calculated_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
            ['square_variation_id' => 'stale-v', 'square_item_id' => 'old-item', 'name' => 'Removed candy', 'sku' => 'STALE', 'vendor' => 'Vistar', 'active' => 1, 'on_hand' => 7, 'pack_size' => 6, 'reorder_point' => 1, 'target_stock' => 12, 'unit_cost' => 2.25, 'unit_cost_status' => 'verified', 'unit_cost_source' => 'fixture baseline', 'unit_cost_checked_at' => '2026-01-01', 'supplier_sku' => 'STALE-SKU', 'override_qty' => null, 'calculated_at' => null, 'updated_at' => '2026-01-01 00:00:00'],
        ] as $row) {
            if ($wpdb->insert($tables['products'], $row) !== 1) throw new RuntimeException('Could not seed private Inventory product row.');
        }
        $order_line = ['product' => 'Fixture Candy - Regular', 'square_variation_id' => 'sync-v', 'on_hand' => 4, 'quantity' => 12];
        if ($wpdb->insert($tables['orders'], [
            'vendor' => 'Fixture Vendor', 'status' => 'ordered', 'estimated_total' => 18,
            'minimum_amount' => 0, 'item_count' => 1, 'payload' => wp_json_encode([$order_line]),
            'submission_key' => null, 'created_at' => '2026-01-01 00:00:00',
        ]) !== 1) throw new RuntimeException('Could not seed private ordered-product fixture.');

        $baseline = $snapshot();
        $new_product_failure = $inject_trigger('product', $tables['products'], 'BEFORE', 'INSERT', "NEW.square_variation_id = 'new-v'");
        $failed_pull_rolls_back('product upsert failure after an earlier product update', $new_product_failure, 'product');
        $check(hash_equals($baseline, $snapshot()), 'product-upsert failure preserves exact initial fixture state');

        $deactivation_failure = $inject_trigger('stale', $tables['products'], 'BEFORE', 'UPDATE', "OLD.square_variation_id = 'stale-v' AND NEW.active = 0");
        $failed_pull_rolls_back('stale-product deactivation failure after catalog upserts', $deactivation_failure, 'stale');
        $check(hash_equals($baseline, $snapshot()), 'deactivation failure preserves exact initial fixture state');

        $receipt_failure = $inject_trigger('receipt', $tables['orders'], 'BEFORE', 'UPDATE', "OLD.id = 1 AND NEW.status = 'stock_increased'");
        $failed_pull_rolls_back('receipt-triggered stock/order-state update failure', $receipt_failure, 'receipt');
        $check(hash_equals($baseline, $snapshot()), 'receipt update failure preserves exact initial fixture state');

        $activity_failure = $inject_trigger('activity', $tables['runs'], 'BEFORE', 'INSERT', "NEW.run_type = 'pull'");
        $failed_pull_rolls_back('activity-log insert failure after inventory and receipt writes', $activity_failure, 'activity');
        $check(hash_equals($baseline, $snapshot()), 'activity-log failure preserves exact initial fixture state');

        $request_count = count($http_requests);
        $pulled = \RoxyInventorySyncFixture\Square::pull();
        $check(isset($pulled['sync-v'], $pulled['new-v']) && $pulled['sync-v']['on_hand'] === 9.0 && $pulled['new-v']['on_hand'] === 2.0,
            'successful retry returns both the updated and newly discovered Square variations');
        $products = $wpdb->get_results('SELECT square_variation_id,name,active,on_hand,vendor,pack_size,unit_cost FROM `' . $tables['products'] . '` ORDER BY square_variation_id', ARRAY_A);
        $product_map = [];
        foreach ($products as $product) $product_map[$product['square_variation_id']] = $product;
        $check(count($product_map) === 3
            && $product_map['sync-v']['name'] === 'Fixture Candy - Regular'
            && (float)$product_map['sync-v']['on_hand'] === 9.0
            && $product_map['sync-v']['active'] === '1'
            && $product_map['sync-v']['vendor'] === 'Vistar'
            && (float)$product_map['sync-v']['pack_size'] === 12.0
            && (float)$product_map['sync-v']['unit_cost'] === 1.5,
            'successful retry commits updated Square fields and preserves user-managed order rules');
        $check($product_map['new-v']['active'] === '1' && (float)$product_map['new-v']['on_hand'] === 2.0,
            'successful retry commits the new product with current stock');
        $check($product_map['stale-v']['active'] === '0' && (float)$product_map['stale-v']['on_hand'] === 7.0,
            'successful retry deactivates only the missing Square product without erasing its prior stock');
        $order = $wpdb->get_row('SELECT status,payload FROM `' . $tables['orders'] . '` WHERE id=1', ARRAY_A);
        $order_lines = json_decode((string)$order['payload'], true);
        $check($order['status'] === 'stock_increased'
            && ($order_lines[0]['square_receipt_event_id'] ?? '') === 'fixture-receipt'
            && (float)($order_lines[0]['square_receipt_quantity'] ?? 0) === 5.0
            && (float)($order_lines[0]['stock_increase_from'] ?? -1) === 4.0
            && (float)($order_lines[0]['stock_increase_to'] ?? -1) === 9.0,
            'successful retry commits receipt provenance and releases the ordered vendor');
        $run = $wpdb->get_row('SELECT run_type,status,message FROM `' . $tables['runs'] . '` ORDER BY id DESC LIMIT 1', ARRAY_A);
        $check(is_array($run) && $run['run_type'] === 'pull' && $run['status'] === 'success'
            && strpos($run['message'], '2 Square variations pulled') !== false,
            'successful retry commits the pull activity record last');
        $check(count($http_requests) === $request_count + 3, 'successful retry uses only mocked Square reads');
        foreach ($http_requests as $request) {
            $check(parse_url($request['url'], PHP_URL_HOST) === 'connect.squareupsandbox.com', 'every provider request was intercepted by the sandbox fixture');
        }
        if (!empty($wpdb->last_error)) throw new RuntimeException('Unexpected database error during successful Inventory sync fixture: ' . $wpdb->last_error);
        echo 'Passed ' . $checks . " actual Inventory Square/Store private-MySQL transaction checks. No live production rows or APIs were used.\n";
    } finally {
        foreach ($created_triggers as $trigger) $wpdb->query('DROP TRIGGER IF EXISTS `' . $trigger . '`');
        foreach ($created_tables as $table) {
            if ($table_exists($table)) $wpdb->query('DROP TABLE `' . $table . '`');
        }
        $wpdb->prefix = $original_prefix;
        $wpdb->suppress_errors($errors_suppressed);
        remove_filter('pre_http_request', $http_filter, PHP_INT_MAX);
    }
