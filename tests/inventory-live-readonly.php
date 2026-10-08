<?php
// WP-CLI read-only deployment evidence; never exposes recipient emails or payloads.
if (!defined('WP_CLI') || !WP_CLI) exit;
global $wpdb;
$tables = [
    'products' => 'id,square_variation_id,square_item_id,name,sku,vendor,tracking_status,active,on_hand,pack_size,reorder_point,target_stock,unit_cost,override_qty,calculated_at,updated_at',
    'vendors' => '*',
    'orders' => 'id,vendor,status,estimated_total,minimum_amount,item_count,payload,created_at',
];
foreach ($tables as $suffix => $columns) {
    $rows = $wpdb->get_results('SELECT ' . $columns . ' FROM ' . $wpdb->prefix . 'roxy_inventory_' . $suffix . ' ORDER BY id', ARRAY_A);
    if ($wpdb->last_error || !is_array($rows)) throw new RuntimeException('Inventory evidence read failed.');
    echo $suffix . ': rows=' . count($rows) . ' sha256=' . hash('sha256', wp_json_encode($rows)) . "\n";
}
echo 'schema_version=' . get_option('roxy_inventory_db_version') . "\n";
echo 'submission_key_column=' . (int) (bool) $wpdb->get_var('SHOW COLUMNS FROM ' . $wpdb->prefix . "roxy_inventory_orders LIKE 'submission_key'") . "\n";
