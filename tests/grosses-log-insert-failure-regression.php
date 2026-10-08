<?php
/** Ensure Grosses audit-log failure has one generic fallback and no DB retry. */
define('ABSPATH', __DIR__ . '/');

function current_time($type) { return '2026-10-08 12:00:00'; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_json_encode($value) { return json_encode($value); }

class GrossesLogFailureFixtureWpdb {
  public string $prefix = 'wp_';
  public int $insert_calls = 0;
  public int $insert_id = 0;
  public function insert(string $table, array $data) {
    $this->insert_calls++;
    return false;
  }
}

$wpdb = new GrossesLogFailureFixtureWpdb();
require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-settings.php';
require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';

$error_log_path = tempnam(sys_get_temp_dir(), 'roxy-grosses-log-failure-');
if ($error_log_path === false) throw new RuntimeException('Could not allocate isolated PHP error log.');
$previous_error_log = ini_get('error_log');
ini_set('error_log', $error_log_path);

$primary_operation_count = 0;
$primary_operation = static function () use (&$primary_operation_count): string {
  $primary_operation_count++;
  return 'primary operation completed';
};
try {
  // Model a completed primary action followed by its audit-log write.
  $primary_result = $primary_operation();
  $result = \RoxyGrosses\Store::insert_log(
    'sensitive-event',
    'sensitive-mode',
    42,
    '2026-10-08',
    true,
    'PRIVATE MESSAGE MUST NOT BE LOGGED',
    ['private' => 'PRIVATE CONTEXT MUST NOT BE LOGGED']
  );

  clearstatcache(true, $error_log_path);
  $signal = trim((string) file_get_contents($error_log_path));
  $signal_lines = preg_split('/\R/', $signal) ?: [];
  if ($result !== 0) throw new RuntimeException('Failed audit-log insert did not return 0.');
  if ($wpdb->insert_calls !== 1) throw new RuntimeException('Failed audit-log storage was retried.');
  if ($primary_operation_count !== 1 || $primary_result !== 'primary operation completed') throw new RuntimeException('Primary operation was repeated or changed by audit-log failure.');
  if (count($signal_lines) !== 1 || !str_ends_with($signal_lines[0], 'Roxy Grosses: audit log insert failed.')) throw new RuntimeException('Fallback signal was not emitted exactly once, or was not generic.');
  if (strpos($signal, 'PRIVATE') !== false || strpos($signal, 'sensitive') !== false) throw new RuntimeException('Fallback signal exposed caller data.');
  echo "5 Grosses log-insert failure checks passed.\n";
} finally {
  ini_set('error_log', (string) $previous_error_log);
  @unlink($error_log_path);
}
