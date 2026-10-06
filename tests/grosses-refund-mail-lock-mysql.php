<?php
/** WP-CLI integration fixture for refund-review lock/mail serialization. */
if (!defined('WP_CLI') || !WP_CLI) exit;

$store_path = $args[0] ?? dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';
$reporter_path = $args[1] ?? dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-reporter.php';
foreach ([$store_path, $reporter_path] as $candidate) if (!is_file($candidate)) throw new RuntimeException('Candidate source file is missing: ' . $candidate);

global $wpdb;
$prefix = $wpdb->prefix;
$token = 'rml_' . bin2hex(random_bytes(6));
$suffixes = [
  'roxy_grosses_reports' => $token . '_reports',
  'roxy_grosses_logs' => $token . '_logs',
  'roxy_grosses_refund_reviews' => $token . '_refund_reviews',
];
$tables = []; $created = [];
$quote = static function (string $table): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new RuntimeException('Unsafe private table identifier.');
  return '`' . $table . '`';
};
$exists = static fn(string $table): bool => $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
foreach ($suffixes as $old => $suffix) {
  $table = $prefix . $suffix;
  if (!preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table) || $exists($table)) throw new RuntimeException('Random private fixture table is invalid or already exists.');
  $tables[$old] = $table;
}

$namespace = 'RoxyGrossesRefundMailLock_' . bin2hex(random_bytes(4));
$load_candidate = static function (string $path) use ($namespace, $suffixes): string {
  $source = file_get_contents($path);
  if (!is_string($source)) throw new RuntimeException('Could not read candidate source: ' . $path);
  $source = preg_replace('/^<\?php\s*/', '', $source, 1);
  $source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $source);
  foreach ($suffixes as $old => $suffix) $source = str_replace("'{$old}'", "'{$suffix}'", $source);
  return $source;
};
$checks = 0;
$check = static function ($ok, string $label) use (&$checks): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $label);
  $checks++; echo "PASS: {$label}\n";
};

// Only Reporter needs settings/provider symbols here; lock-contention paths must stop before provider or email work.
eval('namespace ' . $namespace . ';
  final class Settings {
    public static function email_list(): array { return ["fixture@example.invalid"]; }
    public static function admin_email(): string { return ""; }
    public static function get($key, $default = "") { return $default; }
    public static function get_report_timezone(): string { return "UTC"; }
  }
  final class Square { public static function with_sale_snapshot(callable $operation) { return $operation(); } public static function fetch_orders_for_date(string $date): array { throw new \\RuntimeException("Unexpected provider call in lock fixture."); } }
  final class RefundSnapshot {}
');
eval($load_candidate($store_path));
eval($load_candidate($reporter_path));
$store = '\\' . $namespace . '\\Store';
$reporter = '\\' . $namespace . '\\Reporter';
$report_table = $tables['roxy_grosses_reports']; $log_table = $tables['roxy_grosses_logs'];
$reports_q = $quote($report_table);
$lock = 'roxy_grosses_refund_review_' . substr(hash('sha256', $report_table), 0, 24);
$mail = [];
$capture_mail = static function ($pre, $atts) use (&$mail) { $mail[] = $atts; return true; };
$other_db = null; $query_counter = null; $loss_filter = null; $tables_cleaned = false;

try {
  foreach (['roxy_grosses_reports' => $report_table, 'roxy_grosses_logs' => $log_table] as $production => $private) {
    if ($exists($private)) throw new RuntimeException('Private table appeared before fixture creation.');
    $sql = 'CREATE TABLE ' . $quote($private) . ' LIKE ' . $quote($prefix . $production);
    if ($wpdb->query($sql) === false) throw new RuntimeException('Could not create private schema copy: ' . $private);
    $created[] = $private;
  }
  if ($store::table_name() !== $report_table || $store::log_table_name() !== $log_table) throw new RuntimeException('Candidate Store escaped private table mapping.');

  $date = '2039-07-08';
  $original = ['report_date'=>$date,'showing_id'=>390708,'show_time'=>'7:00 PM','film_title'=>'Private lock fixture film',
    'general_qty'=>3,'discount_qty'=>0,'group_qty'=>0,'live_qty'=>0,'total_tickets'=>3,'gross_total'=>36.00];
  $changed = array_replace($original, ['general_qty'=>2,'total_tickets'=>2,'gross_total'=>24.00]);
  $id = $store::create_report($date, 0, 'fixture', 'draft', ['gross_total'=>36.00,'total_tickets'=>3], [$original]);
  if ($id <= 0 || !$store::mark_emailed($id)) throw new RuntimeException('Could not seed private emailed report.');
  $stored_before = $wpdb->get_row($wpdb->prepare("SELECT status, payload_json FROM {$reports_q} WHERE id=%d", $id), ARRAY_A);
  add_filter('pre_wp_mail', $capture_mail, PHP_INT_MAX, 2);
  $other_db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
  $other_db->suppress_errors(true);

  // A separate connection owns the lock: saved-report send must return before mail dispatch.
  if ((int) $other_db->get_var($other_db->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) throw new RuntimeException('Could not acquire independent private fixture lock.');
  $blocked_send = $reporter::send_saved_report($id);
  $other_db->get_var($other_db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
  $stored_after_block = $wpdb->get_row($wpdb->prepare("SELECT status, payload_json FROM {$reports_q} WHERE id=%d", $id), ARRAY_A);
  $check(empty($blocked_send['success']) && count($mail) === 0, 'independent review lock contention blocks saved send with zero mail');
  $check($stored_after_block === $stored_before, 'contended send leaves private saved report unchanged');

  // Nested review evidence uses the existing outer lock; it neither reacquires nor releases it early.
  $lock_ops = ['get'=>0,'release'=>0];
  $query_counter = static function (string $sql) use (&$lock_ops): string {
    if (preg_match('/^\s*SELECT\s+GET_LOCK\s*\(/i', $sql)) $lock_ops['get']++;
    if (preg_match('/^\s*SELECT\s+RELEASE_LOCK\s*\(/i', $sql)) $lock_ops['release']++;
    return $sql;
  };
  add_filter('query', $query_counter);
  $nested = $store::with_refund_review_lock(static function () use ($store, $other_db, $lock, $date, $changed, $query_counter): array {
    $flagged = $store::flag_emailed_refund_changes($date, [$changed]);
    $still_owned = (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock)) === 1;
    remove_filter('query', $query_counter);
    $contender = $other_db->get_var($other_db->prepare('SELECT GET_LOCK(%s, 0)', $lock));
    if ((int) $contender === 1) $other_db->get_var($other_db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    add_filter('query', $query_counter);
    return [$flagged, $still_owned, (int) $contender];
  });
  remove_filter('query', $query_counter); $query_counter = null;
  $check(count($nested[0]) === 1 && $nested[1] && $nested[2] === 0, 'nested refund flag keeps outer lock owned and excludes competing connection');
  $check($lock_ops === ['get'=>1,'release'=>1], 'nested flag performs no second acquire or premature release');
  $review_table = $prefix . $suffixes['roxy_grosses_refund_reviews'];
  if ($exists($review_table)) $created[] = $review_table;

  // Force loss on the final ownership assertion immediately before saved-report mail.
  $loss_date = '2039-07-09';
  $loss_row = array_replace($original, ['report_date'=>$loss_date]);
  $loss_id = $store::create_report($loss_date, 0, 'fixture', 'draft', ['gross_total'=>36.00,'total_tickets'=>3], [$loss_row]);
  if ($loss_id <= 0 || !$store::mark_emailed($loss_id)) throw new RuntimeException('Could not seed unflagged private report for lock-loss case.');
  $owns_checks = 0; $released_for_injection = false;
  $loss_filter = static function (string $sql) use (&$owns_checks, &$released_for_injection, $wpdb, $lock): string {
    if (!$released_for_injection && preg_match('/^\s*SELECT\s+IS_USED_LOCK\s*\(/i', $sql)) {
      $owns_checks++;
      if ($owns_checks === 2) {
        $released_for_injection = true;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
      }
    }
    return $sql;
  };
  add_filter('query', $loss_filter);
  $lost_send = $reporter::send_saved_report($loss_id);
  remove_filter('query', $loss_filter); $loss_filter = null;
  $check($released_for_injection && empty($lost_send['success']) && count($mail) === 0, 'ownership loss at pre-dispatch assertion stops email');
  $check($other_db->get_var($other_db->prepare('SELECT IS_USED_LOCK(%s)', $lock)) === null, 'lock is released after ownership-assertion exception');

  // A callback exception still unwinds the outer lock depth and releases the connection lock.
  $thrown = false;
  try { $store::with_refund_review_lock(static function (): void { throw new RuntimeException('fixture throw'); }); }
  catch (RuntimeException $error) { $thrown = true; }
  $acquired_after_throw = (int) $other_db->get_var($other_db->prepare('SELECT GET_LOCK(%s, 0)', $lock)) === 1;
  if ($acquired_after_throw) $other_db->get_var($other_db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
  $check($thrown && $acquired_after_throw, 'callback exception releases lock for another connection');

  if ($exists($review_table) && !in_array($review_table, $created, true)) $created[] = $review_table;
  foreach (array_reverse(array_unique($created)) as $table) {
    if (!preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table)) throw new RuntimeException('Refusing cleanup outside random fixture namespace.');
    if ($exists($table) && $wpdb->query('DROP TABLE ' . $quote($table)) === false) throw new RuntimeException('Could not clean private table: ' . $table);
  }
  $tables_cleaned = true;
  echo "Passed {$checks} private-MySQL refund-mail-lock checks. Mail intercepted; no provider call.\n";
} finally {
  remove_filter('pre_wp_mail', $capture_mail, PHP_INT_MAX);
  if ($query_counter) remove_filter('query', $query_counter);
  if ($loss_filter) remove_filter('query', $loss_filter);
  if (!$tables_cleaned) {
    $review_table = $prefix . $suffixes['roxy_grosses_refund_reviews'];
    if ($exists($review_table) && preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_refund_reviews$/', $review_table)) $created[] = $review_table;
    foreach (array_reverse(array_unique($created)) as $table) {
      if (preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table) && $exists($table)) $wpdb->query('DROP TABLE ' . $quote($table));
    }
  }
}
