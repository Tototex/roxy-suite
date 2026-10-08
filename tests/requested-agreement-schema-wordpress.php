<?php
// wp eval-file tests/requested-agreement-schema-wordpress.php [candidate-root]
// Additive dbDelta migration fixture uses a unique throwaway table and preserves a seeded row.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
$root = $args[0] ?? dirname(__DIR__);
$schema_path = $root . '/includes/modules/requested-showings/includes/schema.php';
if (!is_file($schema_path)) throw new RuntimeException('Requested Showings schema candidate is missing.');
global $wpdb;
$old_prefix = $wpdb->prefix;
$suffix = 'rsagr' . bin2hex(random_bytes(4)) . '_';
$wpdb->prefix = $old_prefix . $suffix;
$table = $wpdb->prefix . 'roxy_requested_showing_backings';
$namespace = 'RequestedAgreementSchemaFixture' . bin2hex(random_bytes(4));
$source = file_get_contents($schema_path);
$source = str_replace('function roxy_rs_table_backings', 'function roxy_rs_table_backings_fixture', $source);
$source = str_replace('roxy_rs_table_backings()', 'roxy_rs_table_backings_fixture()', $source);
eval('namespace ' . $namespace . ';' . substr($source, 5));
$install_schema = $namespace . '\\roxy_rs_install_schema';
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    ++$checks;
    echo 'PASS: ' . $label . PHP_EOL;
};
try {
    $install_schema();
    $wpdb->query('ALTER TABLE `' . $table . '` DROP COLUMN agreement_json');
    $now = current_time('mysql');
    if ($wpdb->insert($table, ['created_at'=>$now,'updated_at'=>$now,'request_id'=>987654321,'user_id'=>123456789]) === false) {
        throw new RuntimeException('Could not seed private legacy backing row.');
    }
    $existing_id = (int) $wpdb->insert_id;
    $before = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . $table . '` WHERE id=%d', $existing_id), ARRAY_A);
    $install_schema();
    $column = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE %s', 'agreement_json'));
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . $table . '` WHERE id=%d', $existing_id), ARRAY_A);
    $check(is_string($column) && $column !== '', 'schema upgrade adds agreement storage');
    $check(is_array($row) && is_array($before) && array_diff_assoc($before, $row) === [], 'existing backing row survives the additive upgrade unchanged');
} finally {
    $wpdb->query('DROP TABLE IF EXISTS `' . $table . '`');
    $wpdb->prefix = $old_prefix;
}
echo "OK: $checks installed WordPress agreement schema checks passed\n";
