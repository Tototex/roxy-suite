<?php
// Isolated additive-schema and data-preservation regression; no WordPress/database connection.
define('ABSPATH', __DIR__);
function get_option($key, $default = false) { return $GLOBALS['cost_schema_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) {
    if (!empty($GLOBALS['cost_schema_fail_marker'])) return false;
    if (($GLOBALS['cost_schema_options'][$key] ?? null) === $value) return false;
    $GLOBALS['cost_schema_options'][$key] = $value;
    return true;
}
function check($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
class CostSchemaDatabase {
    public string $prefix = 'test_';
    public string $last_error = '';
    public array $columns = [];
    public array $products = [];
    public bool $fail_query = false;
    public function prepare(string $sql, ...$args): string { return str_replace('%s', "'" . addslashes((string)$args[0]) . "'", $sql); }
    public function get_var(string $sql) {
        if (preg_match("/SHOW COLUMNS FROM [^ ]+ LIKE '([^']+)'/", $sql, $m)) return isset($this->columns[$m[1]]) ? ['Field'=>$m[1]] : null;
        return null;
    }
    public function query(string $sql) {
        if ($this->fail_query) return false;
        if (preg_match('/ALTER TABLE [^ ]+ ADD ([a-z_]+) /', $sql, $m)) { $this->columns[$m[1]] = true; return 1; }
        if (str_contains($sql, "SET unit_cost_status='estimate' WHERE unit_cost_status='unknown' AND unit_cost>0")) {
            foreach ($this->products as &$product) if ($product['unit_cost_status']==='unknown' && $product['unit_cost']>0) $product['unit_cost_status']='estimate';
            unset($product);
            return 1;
        }
        throw new RuntimeException('Unexpected schema-test query: ' . $sql);
    }
}
$root = $argv[1] ?? dirname(__DIR__);
require $root . '/includes/modules/inventory/includes/class-roxy-inventory-store.php';
$wpdb = new CostSchemaDatabase();
$GLOBALS['cost_schema_options'] = [];
$store = new ReflectionClass(\RoxyInventory\Store::class);
$ensure = $store->getMethod('ensure_product_column'); $ensure->setAccessible(true);
$ensure->invoke(null, 'unit_cost_status', "VARCHAR(20) NOT NULL DEFAULT 'unknown' AFTER unit_cost");
check(isset($wpdb->columns['unit_cost_status']), 'Missing Inventory cost column is added and verified');
$wpdb->fail_query = true;
check((function () use ($ensure) { try { $ensure->invoke(null, 'unit_cost_source', 'VARCHAR(190) NULL'); return false; } catch (\Throwable $e) { return true; } })(), 'Failed schema write aborts the migration');
$wpdb->fail_query = false;
$wpdb->products = [
    'priced'=>['unit_cost'=>1.53,'unit_cost_status'=>'unknown','pack_size'=>12,'target_stock'=>60],
    'zero'=>['unit_cost'=>0.0,'unit_cost_status'=>'unknown','pack_size'=>1,'target_stock'=>0],
    'already_free'=>['unit_cost'=>0.0,'unit_cost_status'=>'free','pack_size'=>36,'target_stock'=>72],
];
$migrate = $store->getMethod('migrate_cost_provenance'); $migrate->setAccessible(true);
$migrate->invoke(null);
check($wpdb->products['priced']['unit_cost_status']==='estimate' && $wpdb->products['priced']['unit_cost']===1.53, 'Existing positive configured price becomes an estimate without changing its amount');
check($wpdb->products['zero']['unit_cost_status']==='unknown' && $wpdb->products['zero']['unit_cost']===0.0, 'Existing zero cost remains unknown');
check($wpdb->products['already_free']['unit_cost_status']==='free' && $wpdb->products['already_free']['pack_size']===36 && $wpdb->products['already_free']['target_stock']===72, 'Migration preserves explicit states and unrelated order rules');
check(($GLOBALS['cost_schema_options']['roxy_inventory_cost_provenance_v1']??null)===1, 'Migration marker is stored only after successful data update');
$wpdb->products['priced']['unit_cost_status']='unknown';
$migrate->invoke(null);
check($wpdb->products['priced']['unit_cost_status']==='unknown', 'Completed migration marker prevents rerunning the price update');
unset($GLOBALS['cost_schema_options']['roxy_inventory_cost_provenance_v1']);
$wpdb->fail_query = true;
check((function () use ($migrate) { try { $migrate->invoke(null); return false; } catch (\Throwable $e) { return true; } })(), 'Failed data migration stops without reporting completion');
check(!isset($GLOBALS['cost_schema_options']['roxy_inventory_cost_provenance_v1']), 'Failed data migration leaves its retry marker unset');
$wpdb->fail_query = false;
$GLOBALS['cost_schema_fail_marker'] = true;
check((function () use ($migrate) { try { $migrate->invoke(null); return false; } catch (\Throwable $e) { return true; } })(), 'Failed migration marker save surfaces an error');
check(!isset($GLOBALS['cost_schema_options']['roxy_inventory_cost_provenance_v1']), 'Unstored migration marker is never reported as complete');
echo "Passed 11 Inventory cost schema checks.\n";
