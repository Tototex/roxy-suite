<?php
/**
 * Exercise the real Inventory schema/provenance upgrade against random private
 * tables in the disposable WordPress/MySQL fixture.
 */
if (!defined('WP_CLI') || !WP_CLI) exit;

$root = $args[0] ?? dirname(__DIR__);
$store_file = rtrim((string)$root, '/\\') . '/includes/modules/inventory/includes/class-roxy-inventory-store.php';
if (!is_file($store_file)) throw new RuntimeException('Inventory Store candidate file is missing.');
require_once $store_file;

global $wpdb;
$original_prefix = (string)$wpdb->prefix;
$token = 'inv_cost_' . bin2hex(random_bytes(6)) . '_';
$private_prefix = $original_prefix . $token;
if (!preg_match('/^[A-Za-z0-9_]+$/', $private_prefix)) throw new RuntimeException('Private Inventory prefix is invalid.');

$tables = [
    $private_prefix . 'roxy_inventory_products',
    $private_prefix . 'roxy_inventory_vendors',
    $private_prefix . 'roxy_inventory_runs',
    $private_prefix . 'roxy_inventory_orders',
];
foreach ($tables as $table) {
    if (!preg_match('/^' . preg_quote($original_prefix, '/') . preg_quote($token, '/') . 'roxy_inventory_[a-z_]+$/', $table)) {
        throw new RuntimeException('Refusing an Inventory fixture table outside its unique private namespace.');
    }
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
        throw new RuntimeException('Random private Inventory fixture table already exists; refusing to reuse it.');
    }
}

$option_names = ['roxy_inventory_cost_provenance_v1', 'roxy_inventory_db_version'];
$saved_options = [];
foreach ($option_names as $name) {
    $saved_options[$name] = [
        'exists' => $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $name)) !== null,
        'value' => get_option($name, null),
    ];
}
$checks = 0;
$check = static function ($condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo "PASS: {$label}\n";
};
$table_exists = static function (string $table) use ($wpdb): bool {
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
};
$database_errors = [];
$capture_database_error = static function ($error) use (&$database_errors): void { $database_errors[] = (string)$error; };
add_action('wpdb_error', $capture_database_error, 10, 1);

$wpdb->prefix = $private_prefix;
try {
    delete_option('roxy_inventory_cost_provenance_v1');
    update_option('roxy_inventory_db_version', 'legacy-fixture-version', false);

    // This is the pre-provenance product schema: all ordering/stock fields are
    // present, but the status/source/date/SKU columns have not been added yet.
    $legacy_products = $tables[0];
    $created = $wpdb->query("CREATE TABLE `{$legacy_products}` (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        square_variation_id VARCHAR(80) NOT NULL,
        square_item_id VARCHAR(80) NULL,
        name VARCHAR(190) NOT NULL,
        sku VARCHAR(190) NULL,
        vendor VARCHAR(100) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        on_hand DECIMAL(12,2) NOT NULL DEFAULT 0,
        pack_size DECIMAL(12,2) NOT NULL DEFAULT 1,
        reorder_point DECIMAL(12,2) NOT NULL DEFAULT 0,
        target_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        override_qty DECIMAL(12,2) NULL,
        calculated_at DATETIME NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY square_variation_id (square_variation_id),
        KEY vendor (vendor),
        KEY active (active)
    ) ENGINE=InnoDB " . $wpdb->get_charset_collate());
    if ($created === false || !empty($wpdb->last_error)) throw new RuntimeException('Could not create the private legacy Inventory schema: ' . $wpdb->last_error);

    $now = current_time('mysql');
    $wpdb->insert($legacy_products, [
        'square_variation_id' => 'fixture-cost-positive', 'square_item_id' => 'fixture-item-positive',
        'name' => 'Legacy candy with price', 'sku' => 'LEGACY-1', 'vendor' => 'Vistar',
        'active' => 1, 'on_hand' => 7, 'pack_size' => 12, 'reorder_point' => 10,
        'target_stock' => 36, 'unit_cost' => 1.53, 'override_qty' => 2,
        'calculated_at' => $now, 'updated_at' => $now,
    ]);
    $positive_id = (int)$wpdb->insert_id;
    $wpdb->insert($legacy_products, [
        'square_variation_id' => 'fixture-cost-zero', 'square_item_id' => 'fixture-item-zero',
        'name' => 'Legacy unknown cost', 'sku' => 'LEGACY-0', 'vendor' => 'Vistar',
        'active' => 1, 'on_hand' => 0, 'pack_size' => 36, 'reorder_point' => 10,
        'target_stock' => 72, 'unit_cost' => 0, 'override_qty' => null,
        'calculated_at' => $now, 'updated_at' => $now,
    ]);
    $zero_id = (int)$wpdb->insert_id;
    if ($positive_id < 1 || $zero_id < 1 || !empty($wpdb->last_error)) throw new RuntimeException('Could not seed private legacy Inventory rows: ' . $wpdb->last_error);

    \RoxyInventory\Store::install_schema();

    $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$legacy_products}`", 0);
    if (!is_array($columns) || !empty($wpdb->last_error)) throw new RuntimeException('Could not inspect upgraded Inventory columns: ' . $wpdb->last_error);
    $required = ['tracking_status', 'unit_cost_status', 'unit_cost_source', 'unit_cost_checked_at', 'supplier_sku'];
    $check(count(array_diff($required, $columns)) === 0, 'installer adds and verifies all tracking and cost provenance columns');

    $required_indexes = [
        $tables[0] => ['PRIMARY' => [0, 'id'], 'square_variation_id' => [0, 'square_variation_id'], 'vendor' => [1, 'vendor'], 'active' => [1, 'active']],
        $tables[1] => ['PRIMARY' => [0, 'id'], 'name' => [0, 'name']],
        $tables[2] => ['PRIMARY' => [0, 'id'], 'created_at' => [1, 'created_at']],
        $tables[3] => ['PRIMARY' => [0, 'id'], 'submission_key' => [0, 'submission_key'], 'vendor' => [1, 'vendor'], 'created_at' => [1, 'created_at']],
    ];
    foreach ($required_indexes as $table => $expected_indexes) {
        $index_rows = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        if (!is_array($index_rows) || !empty($wpdb->last_error)) throw new RuntimeException('Could not inspect upgraded Inventory indexes: ' . $wpdb->last_error);
        $actual_indexes = [];
        foreach ($index_rows as $index_row) $actual_indexes[(string)$index_row['Key_name']] = [(int)$index_row['Non_unique'], (string)$index_row['Column_name']];
        foreach ($expected_indexes as $name => $expected) {
            if (($actual_indexes[$name] ?? null) !== $expected) throw new RuntimeException('Inventory upgrade is missing or misconfigures index ' . $name . ' on ' . $table . '.');
        }
    }
    $check(true, 'existing and idempotency indexes are present with the required uniqueness');

    $positive = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$legacy_products}` WHERE id=%d", $positive_id), ARRAY_A);
    $zero = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$legacy_products}` WHERE id=%d", $zero_id), ARRAY_A);
    if (!empty($wpdb->last_error)) throw new RuntimeException('Could not read upgraded private Inventory rows: ' . $wpdb->last_error);
    $check(is_array($positive) && $positive['unit_cost_status'] === 'estimate' && (float)$positive['unit_cost'] === 1.53,
        'positive legacy cost becomes an estimate without changing the amount');
    $check(is_array($zero) && $zero['unit_cost_status'] === 'unknown' && (float)$zero['unit_cost'] === 0.0,
        'zero legacy cost remains unknown rather than being treated as free');
    $check((int)$positive['on_hand'] === 7 && (float)$positive['pack_size'] === 12.0
        && (float)$positive['reorder_point'] === 10.0 && (float)$positive['target_stock'] === 36.0
        && (float)$positive['override_qty'] === 2.0 && $positive['vendor'] === 'Vistar'
        && $positive['sku'] === 'LEGACY-1', 'existing stock, order rules, override, vendor, and SKU remain unchanged');
    $check($positive['unit_cost_source'] === null && $positive['unit_cost_checked_at'] === null
        && (string)$positive['supplier_sku'] === '', 'legacy estimates do not acquire invented source/date/SKU provenance');
    $check((int)get_option('roxy_inventory_cost_provenance_v1', 0) === 1,
        'provenance migration marker is saved only after the data migration');
    $check($table_exists($tables[1]) && $table_exists($tables[2]) && $table_exists($tables[3]),
        'installer creates the remaining Inventory tables under the same private prefix');
    $check($database_errors === [], 'schema upgrade emits no WordPress/MySQL query errors');

    echo "Passed {$checks} real WordPress/MySQL Inventory cost migration checks. No mail, Square, WooCommerce, or production tables were used.\n";
} finally {
    remove_action('wpdb_error', $capture_database_error, 10);
    foreach ($tables as $table) {
        if (preg_match('/^' . preg_quote($original_prefix, '/') . preg_quote($token, '/') . 'roxy_inventory_[a-z_]+$/', $table) && $table_exists($table)) {
            if ($wpdb->query("DROP TABLE `{$table}`") === false) error_log('Could not remove private Inventory fixture table: ' . $table);
        }
    }
    $wpdb->prefix = $original_prefix;
    foreach ($saved_options as $name => $saved) {
        if ($saved['exists']) update_option($name, $saved['value'], false);
        else delete_option($name);
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
