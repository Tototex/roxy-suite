<?php
/** Guard schema-version stamping when dbDelta leaves a required Grosses table incomplete. */
define('ABSPATH', __DIR__ . '/');
const ARRAY_A = 'ARRAY_A';

$options = [];
function get_option($name, $default = false) { global $options; return $options[$name] ?? $default; }
function update_option($name, $value, $autoload = null) { global $options; $options[$name] = $value; return true; }
function esc_sql($value) { return addslashes($value); }
function dbDelta($sql) {
  global $wpdb;
  if (!preg_match('/CREATE TABLE\s+([a-zA-Z0-9_]+)/i', $sql, $match)) return [];
  $table = $match[1];
  if ($wpdb->fail_table === $table) return [];
  preg_match_all('/^\s*([a-z][a-z0-9_]*)\s+(?:BIGINT|INT|TINYINT|DATETIME|DATE|VARCHAR|DECIMAL|LONGTEXT|TEXT)/im', $sql, $columns);
  $wpdb->tables[$table] = array_fill_keys($columns[1], true);
  return [];
}

class FixtureWpdb {
  public string $prefix = 'wp_';
  public string $last_error = '';
  public string $fail_table = '';
  public array $tables = [];
  public function get_charset_collate(): string { return ''; }
  public function prepare(string $sql, ...$args): string { return vsprintf(str_replace('%s', "'%s'", $sql), $args); }
  public function esc_like(string $value): string { return addcslashes($value, '_%\\'); }
  public function get_var(string $sql) {
    $this->last_error = '';
    if (preg_match('/SHOW TABLES LIKE \'([^\']+)\'/i', $sql, $m)) { $name = stripslashes($m[1]); return isset($this->tables[$name]) ? $name : null; }
    if (preg_match('/SHOW COLUMNS FROM `?([a-z0-9_]+)`? LIKE \'([a-z0-9_]+)\'/i', $sql, $m)) return isset($this->tables[$m[1]][$m[2]]) ? $m[2] : null;
    $this->last_error = 'Unexpected fixture query';
    return null;
  }
}

$wpdb = new FixtureWpdb();
require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';
$checks = 0;
$check = static function ($condition, string $message) use (&$checks): void {
  if (!$condition) throw new RuntimeException($message);
  echo "PASS: {$message}\n";
  $checks++;
};
$wpdb->fail_table = 'wp_roxy_grosses_logs';
$check(!\RoxyGrosses\Store::install_schema(), 'partial dbDelta result is not reported as installed');
$check(get_option(\RoxyGrosses\Store::SCHEMA_OPTION, '') === '', 'schema version remains retryable after missing table');
$wpdb->fail_table = '';
$check(\RoxyGrosses\Store::install_schema(), 'schema retry succeeds after database recovers');
$check(get_option(\RoxyGrosses\Store::SCHEMA_OPTION) === \RoxyGrosses\Store::SCHEMA_VERSION, 'schema version is stamped only after required tables and columns verify');
echo "Passed {$checks} Grosses schema bootstrap assertions.\n";
