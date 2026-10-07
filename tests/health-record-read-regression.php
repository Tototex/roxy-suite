<?php
// Isolated actual Health reads; no WordPress bootstrap or external calls.
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
function roxy_suite_module_enabled($key): bool { return true; }
function roxy_rs_table_backings(): string { return 'wp_roxy_requested_showing_backings'; }
function get_option($key, $default = false) { return $default; }
function get_posts($args): array {
    if ($GLOBALS['posts_error']) $GLOBALS['wpdb']->last_error = 'fixture query error';
    return [];
}
final class HealthRecordFixture {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public $count = '0';
    public bool $exists = true;
    public bool $count_error = false;
    public bool $lookup_error = false;
    public bool $throws = false;
    public function prepare($sql, ...$args) { return str_replace('%s', "'" . $args[0] . "'", $sql); }
    public function get_var($sql) {
        if ($this->throws) throw new RuntimeException('fixture SQL exception');
        if (strpos($sql, 'SHOW TABLES') === 0) {
            if ($this->lookup_error) $this->last_error = 'fixture lookup failure';
            preg_match("/LIKE '([^']+)'/", $sql, $match);
            return $this->exists ? $match[1] : null;
        }
        if ($this->count_error) $this->last_error = 'fixture count failure';
        return $this->count;
    }
}
$GLOBALS['wpdb'] = new HealthRecordFixture();
$GLOBALS['posts_error'] = false;
require $argv[1] ?? dirname(__DIR__) . '/includes/class-roxy-suite-health.php';
$checks = 0;
$call = static function ($method, ...$args) { return (new ReflectionMethod(\RoxySuite\Health::class, $method))->invoke(null, ...$args); };
$check = static function ($condition, $message) use (&$checks) {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
foreach (['0', '42', 0, 42, '99999999999999999999'] as $value) {
    $wpdb->count = $value;
    $item = $call('record_count_item', 'Records', 'wp_fixture');
    $check($item['status'] === 'pass' && $item['detail'] === (string) $value . ' total', 'valid integer count');
}
foreach ([null, false, [], '-1', '1.5', '1e2', 'unknown', ''] as $value) {
    $wpdb->count = $value;
    $check($call('record_count_item', 'Records', 'wp_fixture')['status'] === 'warn', 'unknown/malformed count not zero');
}
$wpdb->count = '0';
foreach (['count_error', 'lookup_error', 'throws'] as $fault) {
    $wpdb->$fault = true;
    $check($call('record_count_item', 'Records', 'wp_fixture')['status'] === 'warn', $fault . ' not green');
    $wpdb->$fault = false;
}
$wpdb->exists = false;
$check($call('record_count_item', 'Records', 'wp_fixture')['status'] === 'warn', 'missing table not empty');
$wpdb->exists = true;
$check($call('record_count_item', 'Records', 'wp_fixture;DROP')['status'] === 'warn', 'invalid identifier denied');
foreach (['functional_will_call', 'functional_arcade', 'functional_requested_showings'] as $module) {
    $wpdb->count_error = true;
    $items = $call($module);
    $count_item = $items[count($items) - 1];
    $check($count_item['status'] === 'warn' && $count_item['detail'] === 'Read unavailable', $module . ' uses checked count');
    $wpdb->count_error = false;
}
$GLOBALS['posts_error'] = true;
$check($call('functional_requested_showings')[0]['status'] === 'warn', 'failed request query not empty list');
$GLOBALS['posts_error'] = false;
$check($call('functional_requested_showings')[0]['status'] === 'pass', 'verified empty request list remains valid');
echo "$checks record-read checks passed\n";
