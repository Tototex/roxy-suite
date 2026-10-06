<?php
/** WP-CLI fixture: candidate Store writes only uniquely named private tables. */
if (!defined('WP_CLI') || !WP_CLI) exit;

$root = $args[1] ?? dirname(__DIR__);
$store_path = $args[0] ?? ($root . '/includes/modules/grosses/includes/class-roxy-grosses-store.php');
if (!is_file($store_path)) throw new RuntimeException('Candidate Store file is missing: ' . $store_path);
global $wpdb;
$prefix = $wpdb->prefix;
$token = 'rrv_' . bin2hex(random_bytes(6));
$table_suffixes = [
  'roxy_grosses_reports' => $token . '_reports',
  'roxy_grosses_logs' => $token . '_logs',
  'roxy_grosses_refund_reviews' => $token . '_refund_reviews',
];
$owned_tables = [];
$quote = static function (string $name): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('Unsafe private fixture table identifier.');
  return '`' . $name . '`';
};
$exists = static function (string $table) use ($wpdb): bool {
  return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
};
foreach ($table_suffixes as $old => $suffix) {
  $table = $prefix . $suffix;
  if (!preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table)) throw new RuntimeException('Fixture table escaped its private namespace.');
  if ($exists($table)) throw new RuntimeException('Private fixture table unexpectedly already exists: ' . $table);
}

$source = file_get_contents($store_path);
if (!is_string($source)) throw new RuntimeException('Could not read candidate Store source.');
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$fixture_namespace = 'RoxyGrossesRefundReview_' . bin2hex(random_bytes(4));
$source = str_replace('namespace RoxyGrosses;', 'namespace ' . $fixture_namespace . ';', $source);
foreach ($table_suffixes as $old => $suffix) $source = str_replace("'{$old}'", "'{$suffix}'", $source);
eval($source);
$store = '\\' . $fixture_namespace . '\\Store';
$report_table = $prefix . $table_suffixes['roxy_grosses_reports'];
$log_table = $prefix . $table_suffixes['roxy_grosses_logs'];
$review_table = $prefix . $table_suffixes['roxy_grosses_refund_reviews'];
$review_table_preexisting = $exists($review_table);
if ($review_table_preexisting) throw new RuntimeException('Private review fixture table unexpectedly already exists: ' . $review_table);

$checks = 0;
$check = static function ($ok, string $label) use (&$checks): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $label);
  $checks++;
  echo "PASS: {$label}\n";
};
$now = static fn(): string => current_time('mysql');
$row = static function (string $date, int $qty, float $gross, string $title = 'Fixture Film'): array {
  return ['report_date' => $date, 'showing_id' => 271828, 'show_time' => '19:00', 'film_title' => $title,
    'general_qty' => $qty, 'discount_qty' => 0, 'group_qty' => 0, 'live_qty' => 0,
    'total_tickets' => $qty, 'gross_total' => $gross];
};
$insert_report = static function (string $end_date, string $status, array $rows) use ($wpdb, $report_table, $now): int {
  $timestamp = $now();
  $ok = $wpdb->insert($report_table, [
    'created_at' => $timestamp, 'updated_at' => $timestamp, 'report_end_date' => $end_date,
    'lookback_days' => 7, 'mode' => 'fixture', 'status' => $status,
    'summary_gross' => 100, 'summary_tickets' => 10, 'row_count' => count($rows),
    'emailed_at' => $status === 'emailed' ? $timestamp : null,
    'payload_json' => wp_json_encode(['summary' => ['fixture' => true], 'rows' => $rows]),
  ]);
  if ($ok === false) throw new RuntimeException('Could not seed private saved-report fixture.');
  return (int) $wpdb->insert_id;
};
$read_report = static function (int $id) use ($wpdb, $report_table): array {
  $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$report_table} WHERE id = %d", $id), ARRAY_A);
  if (!is_array($result)) throw new RuntimeException('Private fixture report disappeared.');
  return $result;
};
$fixture_report_table_name = $prefix . $table_suffixes['roxy_grosses_reports'];
$lock_name = 'roxy_grosses_refund_review_' . substr(hash('sha256', $fixture_report_table_name), 0, 24);
$tables_cleaned = false;

try {
  foreach ([$report_table, $log_table] as $table) {
    if ($exists($table)) throw new RuntimeException('Fixture table appeared before creation; refusing to touch it.');
    $quoted = $quote($table);
    if ($table === $report_table) {
      $sql = "CREATE TABLE {$quoted} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        report_end_date DATE NOT NULL, lookback_days INT NOT NULL, mode VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL,
        summary_gross DECIMAL(12,2) NOT NULL, summary_tickets INT NOT NULL, row_count INT NOT NULL,
        emailed_at DATETIME NULL, payload_json LONGTEXT NOT NULL
      ) ENGINE=InnoDB";
    } else {
      $sql = "CREATE TABLE {$quoted} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, event_type VARCHAR(32) NOT NULL) ENGINE=InnoDB";
    }
    if ($wpdb->query($sql) === false) throw new RuntimeException('Could not create owned private table: ' . $table);
    $owned_tables[] = $table;
  }

  $target_date = '2038-03-04';
  $other_date = '2038-03-05';
  $before_target = $row($target_date, 10, 100.00);
  $before_other = $row($other_date, 4, 40.00, 'Other Date Film');
  $emailed = $insert_report($other_date, 'emailed', [$before_target, $before_other]);
  $draft = $insert_report($other_date, 'draft', [$before_target]);
  $unrelated = $insert_report('2038-03-03', 'emailed', [$before_target]);
  $already_correct = $insert_report($other_date, 'emailed', [$row($target_date, 8, 80.00)]);
  $multi_date = $insert_report($other_date, 'emailed', [$before_target, $before_other]);
  $snapshots_before = [];
  foreach ([$emailed, $draft, $unrelated, $already_correct, $multi_date] as $id) $snapshots_before[$id] = $read_report($id);

  $new_target = $row($target_date, 8, 80.00);
  $flagged = $store::flag_emailed_refund_changes($target_date, [$new_target]);
  if ($exists($review_table) && !in_array($review_table, $owned_tables, true)) $owned_tables[] = $review_table;
  $check(in_array($emailed, $flagged, true) && !in_array($draft, $flagged, true) && !in_array($unrelated, $flagged, true), 'only matching emailed snapshots are flagged; draft and unrelated date are untouched');
  $after_emailed = $read_report($emailed);
  $check($after_emailed['payload_json'] === $snapshots_before[$emailed]['payload_json'] && $after_emailed['status'] === 'emailed', 'flagging leaves original emailed payload and status unchanged');
  $check($read_report($draft) === $snapshots_before[$draft] && $read_report($unrelated) === $snapshots_before[$unrelated], 'draft/unrelated snapshots remain byte-for-byte unchanged');
  $review = $store::refund_reviews([$emailed, $draft, $unrelated]);
  $check(isset($review[$emailed][$target_date]) && !isset($review[$draft]) && !isset($review[$unrelated]), 'bulk getter returns only the persisted matching review');
  $saved = $store::get_report($emailed);
  $listed = $store::list_reports(50);
  $listed_report = null;
  foreach ($listed as $candidate) if ((int) $candidate['id'] === $emailed) $listed_report = $candidate;
  $check(($saved['refund_review'][$target_date] ?? null) === $review[$emailed][$target_date], 'single saved-report getter includes refund review evidence');
  $check(($listed_report['refund_review'][$target_date] ?? null) === $review[$emailed][$target_date], 'saved-report list includes refund review evidence');
  $check(!isset($store::refund_reviews([$already_correct])[$already_correct]), 'already-corrected snapshot is not flagged');
  $check($wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $lock_name)) === null, 'connection lock is released after successful operation');

  $evidence_before_repeat = $review[$emailed][$target_date];
  $quoted_review_table = $quote($review_table);
  $review_row_before_repeat = $wpdb->get_row($wpdb->prepare("SELECT created_at, updated_at, changes_json FROM {$quoted_review_table} WHERE report_id = %d", $emailed), ARRAY_A);
  $store::flag_emailed_refund_changes($target_date, [$new_target]);
  $review_row_after_repeat = $wpdb->get_row($wpdb->prepare("SELECT created_at, updated_at, changes_json FROM {$quoted_review_table} WHERE report_id = %d", $emailed), ARRAY_A);
  $check($review_row_after_repeat === $review_row_before_repeat && $evidence_before_repeat === $store::refund_reviews([$emailed])[$emailed][$target_date], 'repeated identical correction preserves evidence and timestamps');

  // Multiple dates in one emailed report merge without replacing prior date evidence.
  $store::flag_emailed_refund_changes($other_date, [$row($other_date, 3, 30.00, 'Other Date Film')]);
  $multi_review = $store::refund_reviews([$multi_date])[$multi_date] ?? [];
  $check(isset($multi_review[$target_date], $multi_review[$other_date]), 'multiple sale dates merge into the same emailed report review');
  $check($read_report($multi_date)['payload_json'] === $snapshots_before[$multi_date]['payload_json'], 'multi-date review does not rewrite the saved snapshot');

  // Query failure on a new evidence insert must preserve prior flags and release the advisory lock.
  $failure_report = $insert_report('2038-03-06', 'emailed', [$row('2038-03-06', 5, 50.00)]);
  $evidence_baseline = $store::refund_reviews([$emailed, $multi_date]);
  $query_fault = true;
  $missing_table = $prefix . $token . '_missing_review_table';
  if ($exists($missing_table)) throw new RuntimeException('Fault target unexpectedly exists.');
  $fault_filter = static function (string $query) use (&$query_fault, $review_table, $quote, $missing_table): string {
    if ($query_fault && preg_match('/^INSERT INTO `?' . preg_quote($review_table, '/') . '`?\s/i', $query)) {
      $query_fault = false;
      return 'INSERT INTO ' . $quote($missing_table) . ' (report_id) VALUES (1)';
    }
    return $query;
  };
  add_filter('query', $fault_filter);
  $thrown = false;
  try { $store::flag_emailed_refund_changes('2038-03-06', [$row('2038-03-06', 2, 20.00)]); }
  catch (RuntimeException $error) { $thrown = true; }
  finally { remove_filter('query', $fault_filter); }
  $check(!$query_fault && $thrown, 'injected review insert failure is reported');
  $check(!isset($store::refund_reviews([$failure_report])[$failure_report]), 'failed first insert leaves no partial review row');
  $check($store::refund_reviews([$emailed, $multi_date]) === $evidence_baseline, 'failed insert leaves prior review evidence unchanged');
  $check($wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $lock_name)) === null, 'connection lock is released after insert failure');

  // A different DB connection holding this prefix-scoped lock blocks without touching evidence.
  $other_db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
  $other_db->suppress_errors(true);
  if ((int) $other_db->get_var($other_db->prepare('SELECT GET_LOCK(%s, 0)', $lock_name)) !== 1) throw new RuntimeException('Could not acquire independent fixture lock.');
  $contended = false;
  try {
    try { $store::flag_emailed_refund_changes('2038-03-06', [$row('2038-03-06', 2, 20.00)]); }
    catch (RuntimeException $error) { $contended = true; }
  } finally { $other_db->get_var($other_db->prepare('SELECT RELEASE_LOCK(%s)', $lock_name)); }
  $check($contended, 'independent connection lock contention fails safely');
  $check($store::refund_reviews([$emailed, $multi_date]) === $evidence_baseline, 'lock contention does not change review evidence');

  // All cleanup targets are exact, private names created and recorded by this fixture.
  foreach (array_reverse($owned_tables) as $table) {
    if (!preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table)) throw new RuntimeException('Refusing cleanup outside fixture-owned table namespace.');
    if ($exists($table)) {
      if ($wpdb->query('DROP TABLE ' . $quote($table)) === false) throw new RuntimeException('Could not clean owned fixture table: ' . $table);
      $owned_tables = array_values(array_diff($owned_tables, [$table]));
    }
  }
  $tables_cleaned = true;
  echo "Passed {$checks} refund-review MySQL checks. Production tables and mail untouched.\n";
} finally {
  if (isset($fault_filter)) remove_filter('query', $fault_filter);
  if (!$tables_cleaned) {
    foreach (array_reverse($owned_tables) as $table) {
      if (preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table) && $exists($table)) {
        $wpdb->query('DROP TABLE ' . $quote($table));
      }
    }
    // The lazily created review table was absent before this private random namespace was used.
    if (!$review_table_preexisting && $exists($review_table)
      && preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_refund_reviews$/', $review_table)) {
      $wpdb->query('DROP TABLE ' . $quote($review_table));
    }
  }
}
