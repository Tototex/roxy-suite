<?php
/** WP-CLI integration fixture: actual Reporter + Store, isolated private tables only. */
if (!defined('WP_CLI') || !WP_CLI) exit;

$root = $args[2] ?? dirname(__DIR__);
$store_path = $args[0] ?? ($root . '/includes/modules/grosses/includes/class-roxy-grosses-store.php');
$reporter_path = $args[1] ?? ($root . '/includes/modules/grosses/includes/class-roxy-grosses-reporter.php');
foreach ([$store_path, $reporter_path] as $candidate) if (!is_file($candidate)) throw new RuntimeException('Candidate source file is missing: ' . $candidate);

global $wpdb;
$prefix = $wpdb->prefix;
$token = 'rint_' . bin2hex(random_bytes(6));
$suffixes = [
  'roxy_grosses_reports' => $token . '_reports',
  'roxy_grosses_entries' => $token . '_entries',
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
  if (!preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table) || $exists($table)) throw new RuntimeException('Private fixture name is invalid or already exists.');
  $tables[$old] = $table;
}

$namespace = 'RoxyGrossesRefundIntegration_' . bin2hex(random_bytes(4));
$checks = 0;
$check = static function ($ok, string $label) use (&$checks): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $label);
  $checks++; echo "PASS: {$label}\n";
};
$load_candidate = static function (string $path) use ($namespace, $suffixes): string {
  $code = file_get_contents($path);
  if (!is_string($code)) throw new RuntimeException('Could not read candidate: ' . $path);
  $code = preg_replace('/^<\?php\s*/', '', $code, 1);
  $code = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $code);
  foreach ($suffixes as $old => $suffix) $code = str_replace("'{$old}'", "'{$suffix}'", $code);
  return $code;
};

// Namespace-local fakes prevent provider/calendar access while exercising candidate Reporter and Store methods.
eval('namespace ' . $namespace . ';
  final class Settings {
    public static function get_report_timezone(): string { return "America/Los_Angeles"; }
    public static function get(string $key, $default = "") { return ["theater_name"=>"Fixture Theater", "lookback_days"=>0, "general_price"=>15, "discount_price"=>9, "group_price"=>6][$key] ?? $default; }
  }
  final class Square {
    public static function fetch_orders_for_date(string $date): array { return []; }
    public static function concession_reporting_categories(array $ids): array { return []; }
  }
  final class RefundSnapshot {
    public array $result = [];
    public function reconcile_sale_day(string $date, array $orders): array { return $this->result[$date] ?? ["orders"=>$orders,"adjustments"=>[],"issues"=>[]]; }
  }
  function post_type_exists($type): bool { return $type === "roxy_showing"; }
  function get_posts($args): array { return []; }
  function get_post_meta($id, $key, $single = false) { return ""; }
  function get_the_title($id): string { return ""; }
');
eval($load_candidate($store_path));
eval($load_candidate($reporter_path));
$store = '\\' . $namespace . '\\Store';
$reporter = '\\' . $namespace . '\\Reporter';
$snapshot_class = '\\' . $namespace . '\\RefundSnapshot';
$report_table = $tables['roxy_grosses_reports']; $entries_table = $tables['roxy_grosses_entries'];
$logs_table = $tables['roxy_grosses_logs']; $reviews_table = $tables['roxy_grosses_refund_reviews'];
$report_q = $quote($report_table); $entries_q = $quote($entries_table);
$tables_cleaned = false; $mail_calls = 0;
$mail_filter = static function ($pre) use (&$mail_calls) { $mail_calls++; return true; };

try {
  $ddl = [
    $report_table => "CREATE TABLE {$report_q} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, report_end_date DATE NOT NULL, lookback_days INT UNSIGNED NOT NULL DEFAULT 0, mode VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, summary_gross DECIMAL(12,2) NOT NULL DEFAULT 0, summary_tickets INT UNSIGNED NOT NULL DEFAULT 0, row_count INT UNSIGNED NOT NULL DEFAULT 0, emailed_at DATETIME NULL, payload_json LONGTEXT NOT NULL) ENGINE=InnoDB",
    $entries_table => "CREATE TABLE {$entries_q} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, report_date DATE NOT NULL, movie_title VARCHAR(190) NOT NULL DEFAULT '', normalized_title VARCHAR(190) NOT NULL DEFAULT '', studio VARCHAR(190) NOT NULL DEFAULT '', genre VARCHAR(190) NOT NULL DEFAULT '', show_time VARCHAR(32) NOT NULL DEFAULT '', showing_id BIGINT UNSIGNED NULL, theater_name VARCHAR(190) NOT NULL DEFAULT '', general_qty INT UNSIGNED NOT NULL DEFAULT 0, discount_qty INT UNSIGNED NOT NULL DEFAULT 0, group_qty INT UNSIGNED NOT NULL DEFAULT 0, subscriber_qty INT UNSIGNED NOT NULL DEFAULT 0, live_qty INT UNSIGNED NOT NULL DEFAULT 0, other_qty INT UNSIGNED NOT NULL DEFAULT 0, total_tickets INT UNSIGNED NOT NULL DEFAULT 0, gross_total DECIMAL(12,2) NOT NULL DEFAULT 0, concessions_total DECIMAL(12,2) NOT NULL DEFAULT 0, source_type VARCHAR(32) NOT NULL DEFAULT '', source_ref VARCHAR(64) NOT NULL DEFAULT '', source_file VARCHAR(255) NOT NULL DEFAULT '', source_batch_id BIGINT UNSIGNED NULL, source_report_id BIGINT UNSIGNED NULL, notes TEXT NULL, is_locked TINYINT(1) NOT NULL DEFAULT 0, UNIQUE KEY day_title_time (report_date, normalized_title(100), show_time), KEY report_date (report_date), KEY showing_id (showing_id)) ENGINE=InnoDB",
    $logs_table => "CREATE TABLE " . $quote($logs_table) . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, event_type VARCHAR(32) NOT NULL DEFAULT '') ENGINE=InnoDB",
  ];
  foreach ($ddl as $table => $sql) {
    if ($exists($table) || $wpdb->query($sql) === false) throw new RuntimeException('Could not safely create private table: ' . $table);
    $created[] = $table;
  }
  if ($store::table_name() !== $report_table || $store::entries_table_name() !== $entries_table || $store::log_table_name() !== $logs_table) throw new RuntimeException('Candidate Store did not resolve to private tables.');

  $date = '2039-06-07'; $showing_id = 390607; $show_time = '7:00 PM';
  $row = static function (int $qty, float $gross) use ($date, $showing_id, $show_time): array {
    return ['report_date'=>$date,'showing_id'=>$showing_id,'film_title'=>'Fixture Film','show_time'=>$show_time,
      'general_qty'=>$qty,'general_gross'=>$gross,'discount_qty'=>0,'discount_gross'=>0.0,'group_qty'=>0,'group_gross'=>0.0,
      'live_qty'=>0,'live_gross'=>0.0,'total_tickets'=>$qty,'gross_total'=>$gross,'concessions_total'=>12.34];
  };
  $insert_emailed = static function (array $saved_row) use ($store, $date): int {
    $id = $store::create_report($date, 0, 'fixture', 'draft', ['gross_total'=>$saved_row['gross_total'],'total_tickets'=>$saved_row['total_tickets']], [$saved_row]);
    if ($id <= 0 || !$store::mark_emailed($id)) throw new RuntimeException('Could not seed private emailed snapshot.');
    return $id;
  };
  $seed_entry = static function (array $changes = []) use ($wpdb, $entries_table, $date, $showing_id, $show_time, $store): int {
    $now = current_time('mysql');
    $base = ['created_at'=>$now,'updated_at'=>$now,'report_date'=>$date,'movie_title'=>'Fixture Film','normalized_title'=>$store::normalize_title('Fixture Film'),
      'studio'=>'Fixture Studio','genre'=>'Drama','show_time'=>$show_time,'showing_id'=>$showing_id,'theater_name'=>'Fixture Theater',
      'general_qty'=>3,'discount_qty'=>0,'group_qty'=>0,'subscriber_qty'=>4,'live_qty'=>0,'other_qty'=>5,'total_tickets'=>3,
      'gross_total'=>36.00,'concessions_total'=>12.34,'source_type'=>'manual','source_ref'=>'preserve','notes'=>'fixture notes','is_locked'=>0];
    if ($wpdb->insert($entries_table, array_replace($base, $changes)) === false) throw new RuntimeException('Could not seed private canonical row.');
    return (int) $wpdb->insert_id;
  };
  $read_entry = static fn(int $id): array => (array) $wpdb->get_row($wpdb->prepare("SELECT * FROM {$entries_q} WHERE id=%d", $id), ARRAY_A);
  $build = static function (array $reconciled) use ($snapshot_class, $reporter, $date, $showing_id): array {
    $snapshot = new $snapshot_class(); $snapshot->result[$date] = $reconciled;
    $reflection = new ReflectionMethod($reporter, 'build_reports_for_date_showings');
    $reflection->setAccessible(true);
    return $reflection->invoke(null, $date, [[
      'id'=>$showing_id,'title'=>'Fixture Film','time_label'=>'7:00 PM',
      'start_at'=>new DateTimeImmutable($date . ' 19:00:00', new DateTimeZone('America/Los_Angeles')),
    ]], $snapshot, true);
  };

  // Partial return: Reporter uses saved sale-day nominal price; Store flags the emailed snapshot and updates ticket fields only.
  $saved_id = $insert_emailed($row(3, 36.00));
  $entry_id = $seed_entry(); $entry_before = $read_entry($entry_id);
  $sale = ['id'=>'sale-partial','closed_at'=>$date . 'T19:00:00-07:00','line_items'=>[['uid'=>'ticket-a','name'=>'General','quantity'=>2]]];
  $partial_rows = $build(['orders'=>[$sale],'adjustments'=>[['source_order_id'=>'sale-partial','source_line_item_uid'=>'ticket-a','quantity'=>1]],'issues'=>[]]);
  $partial = $partial_rows[0];
  $check((int)$partial['general_qty'] === 2 && (float)$partial['gross_total'] === 24.0, 'actual Reporter corrects partial refund at original saved nominal price, not current fallback');
  $check(!empty($partial['refund_adjusted']) && in_array($saved_id, $store::flag_emailed_refund_changes($date, $partial_rows), true), 'actual Store flags the changed original emailed snapshot');
  $updated = $store::update_refunded_movie_quantities($partial_rows);
  $entry_after = $read_entry($entry_id);
  $check($updated === ['updated'=>1,'protected'=>0] && (int)$entry_after['general_qty']===2 && (float)$entry_after['gross_total']===24.0, 'actual Store applies corrected historical quantity and gross');
  $check($entry_after['concessions_total']===$entry_before['concessions_total'] && $entry_after['subscriber_qty']===$entry_before['subscriber_qty'] && $entry_after['other_qty']===$entry_before['other_qty'] && $entry_after['notes']===$entry_before['notes'], 'canonical correction preserves concessions and unrelated/manual fields');
  $saved = $store::get_report($saved_id);
  $check((int)$saved['rows'][0]['general_qty']===3 && (float)$saved['rows'][0]['gross_total']===36.0 && isset($saved['refund_review'][$date]), 'emailed snapshot remains unchanged and carries separate review evidence');

  // Full return requires no invented unit price and preserves a zero-ticket canonical row.
  $zero_date = '2039-06-08';
  $zero_show = $showing_id + 1;
  $zero_saved_row = array_replace($row(2, 24.00), ['report_date'=>$zero_date,'showing_id'=>$zero_show]);
  $zero_saved_id = $store::create_report($zero_date, 0, 'fixture', 'draft', ['gross_total'=>24.0,'total_tickets'=>2], [$zero_saved_row]);
  if ($zero_saved_id <= 0 || !$store::mark_emailed($zero_saved_id)) throw new RuntimeException('Could not seed full-return emailed snapshot.');
  $zero_entry_id = $seed_entry(['report_date'=>$zero_date,'showing_id'=>$zero_show,'general_qty'=>2,'total_tickets'=>2,'gross_total'=>24.00]);
  $zero_sale = ['id'=>'sale-zero','closed_at'=>$zero_date . 'T19:00:00-07:00','line_items'=>[['uid'=>'ticket-z','name'=>'General','quantity'=>0]]];
  $zero_snapshot = new $snapshot_class();
  $zero_snapshot->result[$zero_date] = ['orders'=>[$zero_sale],'adjustments'=>[['source_order_id'=>'sale-zero','source_line_item_uid'=>'ticket-z','quantity'=>2]],'issues'=>[]];
  $reflection = new ReflectionMethod($reporter, 'build_reports_for_date_showings'); $reflection->setAccessible(true);
  $zero_rows = $reflection->invoke(null, $zero_date, [['id'=>$zero_show,'title'=>'Fixture Film','time_label'=>'7:00 PM','start_at'=>new DateTimeImmutable($zero_date . ' 19:00:00', new DateTimeZone('America/Los_Angeles'))]], $zero_snapshot, true);
  $zero = $zero_rows[0];
  $check(!empty($zero['refund_adjusted']) && (int)$zero['total_tickets']===0 && (float)$zero['gross_total']===0.0, 'full return builds an explicit zero-ticket corrected row without price evidence');
  $check(in_array($zero_saved_id, $store::flag_emailed_refund_changes($zero_date, $zero_rows), true), 'full-return correction flags its emailed snapshot');
  $zero_update = $store::update_refunded_movie_quantities($zero_rows); $zero_after = $read_entry($zero_entry_id);
  $check($zero_update === ['updated'=>1,'protected'=>0] && (int)$zero_after['total_tickets']===0 && (float)$zero_after['gross_total']===0.0 && (float)$zero_after['concessions_total']===12.34, 'full return reaches zero while preserving canonical row and concessions');

  add_filter('pre_wp_mail', $mail_filter);
  $resend = $reporter::send_saved_report($saved_id);
  remove_filter('pre_wp_mail', $mail_filter);
  $check(empty($resend['success']) && $mail_calls===0, 'flagged saved report resend refuses before attempting mail');
  $check($store::table_name()===$report_table && $store::entries_table_name()===$entries_table && $store::log_table_name()===$logs_table, 'candidate table access remains confined to randomized private tables');
  $check(in_array($reviews_table, $store::backup_table_names(), true), 'Grosses backup table list includes lazy-created refund review evidence');

  // Include the lazy-created review table in cleanup only if it belongs to this exact random fixture namespace.
  if ($exists($reviews_table)) $created[] = $reviews_table;
  foreach (array_reverse(array_unique($created)) as $table) {
    if (!preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table)) throw new RuntimeException('Refusing cleanup outside private fixture namespace.');
    if ($exists($table) && $wpdb->query('DROP TABLE ' . $quote($table)) === false) throw new RuntimeException('Could not clean private fixture table: ' . $table);
  }
  $tables_cleaned = true;
  echo "Passed {$checks} actual Reporter/Store private-MySQL integration checks; no provider or mail sent.\n";
} finally {
  remove_filter('pre_wp_mail', $mail_filter);
  if (!$tables_cleaned) {
    if ($exists($reviews_table) && preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_refund_reviews$/', $reviews_table)) $created[] = $reviews_table;
    foreach (array_reverse(array_unique($created)) as $table) {
      if (preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table) && $exists($table)) $wpdb->query('DROP TABLE ' . $quote($table));
    }
  }
}
