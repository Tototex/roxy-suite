<?php
/** Verify Grosses import writer return values never hide a failed database operation. */
define('ABSPATH', __DIR__ . '/');
const ARRAY_A = 'ARRAY_A';

function current_time($type) { return '2026-10-08 12:00:00'; }
function get_current_user_id() { return 17; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_file_name($value) { return basename((string) $value); }
function wp_json_encode($value) { return json_encode($value); }
function esc_sql($value) { return addslashes($value); }

class GrossesImportWriteFixtureWpdb {
  public string $prefix = 'wp_';
  public int $insert_id = 91;
  public bool $fail = false;
  public int $update_result = 1;
  public bool $row_exists = true;
  public bool $read_error = false;
  public string $last_error = '';
  public int $insert_calls = 0;
  public int $update_calls = 0;
  public function prepare(string $sql, ...$args): string { return vsprintf(str_replace('%d', '%d', $sql), $args); }
  public function get_var(string $sql) {
    $this->last_error = $this->read_error ? 'fixture read failure' : '';
    return $this->row_exists ? 12 : null;
  }
  public function insert(string $table, array $data) {
    $this->insert_calls++;
    return $this->fail ? false : 1;
  }
  public function update(string $table, array $data, array $where) {
    $this->update_calls++;
    return $this->fail ? false : $this->update_result;
  }
}

$wpdb = new GrossesImportWriteFixtureWpdb();
require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';
$store = \RoxyGrosses\Store::class;
$checks = 0;
$check = static function ($condition, string $message) use (&$checks): void {
  if (!$condition) throw new RuntimeException($message);
  echo "PASS: {$message}\n";
  $checks++;
};

$wpdb->fail = true;
$check($store::create_import_batch('movies', 'batch') === 0, 'failed batch insert returns no ID even when insert_id is stale');
$check($store::add_import_file(12, 'file.csv', '/private/file.csv', 'csv', 'ready') === 0, 'failed file insert returns no stale ID');
$check(!$store::update_import_file_path(12, '/private/new.csv'), 'failed path update returns false');
$check(!$store::update_import_file_status(12, 'failed', 0, 0, 1, 'parse'), 'failed file status update returns false');
$check(!$store::finish_import_batch(12, 1, 0, 0, 0, 1, 'failed'), 'failed batch completion returns false');
$check($wpdb->insert_calls === 2 && $wpdb->update_calls === 3, 'each failed writer performs one database attempt without retry');
$check($store::add_import_file(0, 'file.csv', '/private/file.csv', 'csv', 'ready') === 0, 'invalid parent batch ID is rejected before database write');

$wpdb->fail = false;
$check($store::create_import_batch('movies', 'batch') === 91, 'successful batch insert returns its new ID');
$check($store::add_import_file(12, 'file.csv', '/private/file.csv', 'csv', 'ready') === 91, 'successful file insert returns its new ID');
$check($store::update_import_file_path(12, '/private/new.csv'), 'successful path update returns true');
$check($store::update_import_file_status(12, 'ready', 10, 9, 1, ''), 'successful status update returns true');
$check($store::finish_import_batch(12, 1, 3, 4, 2, 1, 'complete'), 'successful batch completion returns true');
$wpdb->update_result = 0;
$check($store::update_import_file_path(12, '/private/new.csv'), 'unchanged existing file is a successful zero-row update');
$wpdb->row_exists = false;
$check(!$store::update_import_file_path(12, '/private/new.csv'), 'zero-row update for missing file is not reported as success');
$wpdb->row_exists = true;
$wpdb->read_error = true;
$check(!$store::update_import_file_path(12, '/private/new.csv'), 'failed readback after zero-row update is not reported as success');
echo "Passed {$checks} Grosses import writer assertions.\n";
