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
  if ($wpdb->fail_table === $table) { unset($wpdb->tables[$table]); return []; }
  preg_match_all('/^\s*([a-z][a-z0-9_]*)\s+(?:BIGINT|INT|TINYINT|DATETIME|DATE|CHAR|VARCHAR|DECIMAL|LONGTEXT|TEXT)/im', $sql, $columns);
  $wpdb->tables[$table] = array_fill_keys($columns[1], true);
  if (preg_match('/UNIQUE KEY\s+event_id\s*\(event_id\)/i', $sql)) $wpdb->tables[$table]['event_id_unique'] = true;
  if (preg_match('/UNIQUE KEY\s+fingerprint\s*\(fingerprint\)/i', $sql)) $wpdb->tables[$table]['fingerprint_unique'] = true;
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
  public function query(string $sql) {
    $this->last_error = '';
    if (preg_match('/^ALTER TABLE ([a-z0-9_]+) ADD is_locked TINYINT\\(1\\) NOT NULL DEFAULT 0$/i', trim($sql), $match)) {
      if (!isset($this->tables[$match[1]])) { $this->last_error = 'Missing fixture table'; return false; }
      $this->tables[$match[1]]['is_locked'] = true;
      return 1;
    }
    $this->last_error = 'Unexpected fixture write';
    return false;
  }
  public function get_var(string $sql) {
    $this->last_error = '';
    if (preg_match("/SHOW INDEX FROM `?([a-z0-9_]+)`? WHERE Key_name = '(event_id|fingerprint)' AND Non_unique = 0/i", $sql, $m)) {
      $key = $m[2] === 'event_id' ? 'event_id_unique' : 'fingerprint_unique';
      return isset($this->tables[$m[1]][$key]) ? $m[2] : null;
    }
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
$check(isset($wpdb->tables['wp_roxy_grosses_unmatched_concessions']['fingerprint_unique']), 'unmatched concession queue schema includes unique line identity');
$options[\RoxyGrosses\Store::SCHEMA_OPTION] = '';
$wpdb->fail_table = 'wp_roxy_grosses_unmatched_concessions';
$check(!\RoxyGrosses\Store::install_schema(), 'missing unmatched queue table prevents schema version confirmation');
$check(get_option(\RoxyGrosses\Store::SCHEMA_OPTION, '') === '', 'missing unmatched queue leaves schema upgrade retryable');
$wpdb->fail_table = '';
$check(\RoxyGrosses\Store::install_schema(), 'unmatched queue schema retries after database recovery');
echo "Passed {$checks} Grosses schema bootstrap assertions.\n";
