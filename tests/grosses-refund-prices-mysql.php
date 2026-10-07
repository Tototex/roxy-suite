<?php
/** WP-CLI regression fixture for original nominal movie ticket price lookup. */
if (!defined('WP_CLI') || !WP_CLI) exit;

$root = $args[1] ?? dirname(__DIR__);
$store_path = $args[0] ?? ($root . '/includes/modules/grosses/includes/class-roxy-grosses-store.php');
if (!is_file($store_path)) throw new RuntimeException('Candidate Store file is missing: ' . $store_path);
global $wpdb;
$prefix = $wpdb->prefix;
$token = 'rprice_' . bin2hex(random_bytes(6));
$suffixes = [
  'roxy_grosses_reports' => $token . '_reports',
  'roxy_grosses_entries' => $token . '_entries',
];
$tables = [];
$created = [];
$quote = static function (string $table): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new RuntimeException('Unsafe private table identifier.');
  return '`' . $table . '`';
};
$exists = static function (string $table) use ($wpdb): bool {
  return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
};
foreach ($suffixes as $old => $suffix) {
  $table = $prefix . $suffix;
  if (!preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table)) throw new RuntimeException('Fixture table escaped its private namespace.');
  if ($exists($table)) throw new RuntimeException('Random private table unexpectedly exists: ' . $table);
  $tables[$old] = $table;
}

$source = file_get_contents($store_path);
if (!is_string($source)) throw new RuntimeException('Could not read candidate Store source.');
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$fixture_namespace = 'RoxyGrossesRefundPrices_' . bin2hex(random_bytes(4));
$source = str_replace('namespace RoxyGrosses;', 'namespace ' . $fixture_namespace . ';', $source);
foreach ($suffixes as $old => $suffix) $source = str_replace("'{$old}'", "'{$suffix}'", $source);
eval($source);
$store = '\\' . $fixture_namespace . '\\Store';

$checks = 0;
$check = static function ($ok, string $label) use (&$checks): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $label);
  $checks++;
  echo "PASS: {$label}\n";
};
$throws = static function (callable $call, string $label) use ($check): void {
  $thrown = false;
  try { $call(); } catch (RuntimeException $error) { $thrown = true; }
  $check($thrown, $label);
};
$reports_table = $tables['roxy_grosses_reports'];
$entries_table = $tables['roxy_grosses_entries'];
$report_q = $quote($reports_table);
$entries_q = $quote($entries_table);
$fallback = ['general' => 15.0, 'discount' => 10.0, 'group' => 7.0, 'live' => 0.0];
$seed_report = static function (string $end_date, array $rows, string $status = 'emailed') use ($wpdb, $reports_table): int {
  $now = current_time('mysql');
  $ok = $wpdb->insert($reports_table, [
    'created_at' => $now, 'updated_at' => $now, 'report_end_date' => $end_date, 'lookback_days' => 0,
    'mode' => 'fixture', 'status' => $status, 'summary_gross' => 0, 'summary_tickets' => 0,
    'row_count' => count($rows), 'emailed_at' => $status === 'emailed' ? $now : null,
    'payload_json' => wp_json_encode(['summary' => [], 'rows' => $rows]),
  ]);
  if ($ok === false) throw new RuntimeException('Could not seed private report snapshot.');
  return (int) $wpdb->insert_id;
};
$seed_entry = static function (string $date, int $showing_id, array $values = []) use ($wpdb, $entries_table): void {
  $now = current_time('mysql');
  $base = [
    'created_at' => $now, 'updated_at' => $now, 'report_date' => $date,
    'movie_title' => 'Fixture Film', 'normalized_title' => 'fixture film', 'studio' => 'Studio', 'genre' => 'Drama',
    'show_time' => '7:00 PM', 'showing_id' => $showing_id, 'theater_name' => 'Fixture Theater',
    'general_qty' => 0, 'discount_qty' => 0, 'group_qty' => 0, 'subscriber_qty' => 0,
    'live_qty' => 0, 'other_qty' => 0, 'total_tickets' => 0, 'gross_total' => 0,
    'concessions_total' => 0, 'source_type' => 'fixture', 'source_ref' => '', 'source_file' => '',
    'source_batch_id' => null, 'source_report_id' => null, 'notes' => '', 'is_locked' => 0,
  ];
  if ($wpdb->insert($entries_table, array_replace($base, $values)) === false) throw new RuntimeException('Could not seed private entry row.');
};
$clear = static function () use ($wpdb, $report_q, $entries_q): void {
  if ($wpdb->query("DELETE FROM {$report_q}") === false || $wpdb->query("DELETE FROM {$entries_q}") === false) throw new RuntimeException('Could not reset private fixture rows.');
};
$snapshot_hash = static function () use ($wpdb, $report_q, $entries_q): string {
  return hash('sha256', serialize([
    $wpdb->get_results("SELECT * FROM {$report_q} ORDER BY id", ARRAY_A),
    $wpdb->get_results("SELECT * FROM {$entries_q} ORDER BY id", ARRAY_A),
  ]));
};
$lookup = static function (string $date, int $showing_id, bool $strict = true, array $required_categories = []) use ($store, $fallback, $snapshot_hash): array {
  $before = $snapshot_hash();
  $prices = $store::nominal_ticket_prices_for_showing($date, $showing_id, $fallback, $strict, $required_categories);
  if ($snapshot_hash() !== $before) throw new RuntimeException('Price lookup mutated its private report/entry fixtures.');
  return $prices;
};

try {
  // Private fixture DDL mirrors the Store's production report/entry columns used by this query.
  $ddl = [
    $reports_table => "CREATE TABLE {$report_q} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
      report_end_date DATE NOT NULL, lookback_days INT UNSIGNED NOT NULL DEFAULT 0, mode VARCHAR(32) NOT NULL DEFAULT 'manual',
      status VARCHAR(32) NOT NULL DEFAULT 'draft', summary_gross DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      summary_tickets INT UNSIGNED NOT NULL DEFAULT 0, row_count INT UNSIGNED NOT NULL DEFAULT 0,
      emailed_at DATETIME NULL, payload_json LONGTEXT NOT NULL, PRIMARY KEY (id), KEY report_end_date (report_end_date),
      KEY status (status), KEY created_at (created_at)
    ) ENGINE=InnoDB",
    $entries_table => "CREATE TABLE {$entries_q} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
      report_date DATE NOT NULL, movie_title VARCHAR(190) NOT NULL DEFAULT '', normalized_title VARCHAR(190) NOT NULL DEFAULT '',
      studio VARCHAR(190) NOT NULL DEFAULT '', genre VARCHAR(190) NOT NULL DEFAULT '', show_time VARCHAR(32) NOT NULL DEFAULT '',
      showing_id BIGINT UNSIGNED NULL, theater_name VARCHAR(190) NOT NULL DEFAULT '',
      general_qty INT UNSIGNED NOT NULL DEFAULT 0, discount_qty INT UNSIGNED NOT NULL DEFAULT 0,
      group_qty INT UNSIGNED NOT NULL DEFAULT 0, subscriber_qty INT UNSIGNED NOT NULL DEFAULT 0,
      live_qty INT UNSIGNED NOT NULL DEFAULT 0, other_qty INT UNSIGNED NOT NULL DEFAULT 0,
      total_tickets INT UNSIGNED NOT NULL DEFAULT 0, gross_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      concessions_total DECIMAL(12,2) NOT NULL DEFAULT 0.00, source_type VARCHAR(32) NOT NULL DEFAULT '',
      source_ref VARCHAR(64) NOT NULL DEFAULT '', source_file VARCHAR(255) NOT NULL DEFAULT '',
      source_batch_id BIGINT UNSIGNED NULL, source_report_id BIGINT UNSIGNED NULL, notes TEXT NULL,
      is_locked TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (id),
      UNIQUE KEY day_title_time (report_date, normalized_title(100), show_time), KEY report_date (report_date),
      KEY normalized_title (normalized_title(100)), KEY studio (studio(100)), KEY genre (genre(100)),
      KEY source_batch_id (source_batch_id), KEY source_type (source_type)
    ) ENGINE=InnoDB",
  ];
  foreach ($ddl as $table => $sql) {
    if ($exists($table)) throw new RuntimeException('Refusing to create over a preexisting fixture table.');
    if ($wpdb->query($sql) === false) throw new RuntimeException('Could not create private fixture table: ' . $table);
    $created[] = $table;
  }

  $date = '2039-05-06';
  $showing = 390506;
  $snapshot = static function (array $overrides = []) use ($date, $showing): array {
    return array_replace(['report_date' => $date, 'showing_id' => $showing, 'general_qty' => 3, 'discount_qty' => 0,
      'group_qty' => 0, 'live_qty' => 0, 'general_gross' => 36.00, 'discount_gross' => 0.00,
      'group_gross' => 0.00, 'live_gross' => 0.00, 'gross_total' => 36.00], $overrides);
  };
  $before = $snapshot_hash();

  // Most recent emailed original nominal baseline overrides today's configured fallback.
  $seed_report($date, [$snapshot()]);
  $prices = $lookup($date, $showing);
  $check(($prices['general'] ?? null) === 12.0, 'emailed original general unit price 36/3 overrides current fallback 15');
  $clear();

  // Explicit per-category totals retain distinct original general and discount prices.
  $seed_report($date, [$snapshot(['general_qty' => 2, 'discount_qty' => 1, 'general_gross' => 24.00,
    'discount_gross' => 6.00, 'gross_total' => 30.00])]);
  $prices = $lookup($date, $showing, true, ['general', 'discount']);
  $check(($prices['general'] ?? null) === 12.0 && ($prices['discount'] ?? null) === 6.0, 'mixed categories infer prices from their own saved gross amounts');
  $clear();

  // Strict historical pricing must not borrow today's discount price when only
  // general tickets were evidenced in the saved sale-day snapshot.
  $seed_report($date, [$snapshot(['general_qty' => 3, 'discount_qty' => 0, 'general_gross' => 36.00,
    'discount_gross' => 0.00, 'gross_total' => 36.00])]);
  $throws(static fn() => $lookup($date, $showing, true, ['general', 'discount']), 'strict lookup rejects a missing baseline for a remaining category');
  $prices = $lookup($date, $showing, true);
  $check(($prices['discount'] ?? null) === 10.0, 'empty required-category list preserves backward-compatible fallback behavior');
  $clear();

  // An incomplete newer corrected snapshot does not hide an older complete
  // original snapshot for categories that still have tickets.
  $seed_report($date, [$snapshot(['general_qty' => 3, 'discount_qty' => 1, 'general_gross' => 36.00,
    'discount_gross' => 6.00, 'gross_total' => 42.00])]);
  $seed_report($date, [$snapshot(['general_qty' => 3, 'discount_qty' => 0, 'general_gross' => 45.00,
    'discount_gross' => 0.00, 'gross_total' => 45.00])]);
  $prices = $lookup($date, $showing, true, ['general', 'discount']);
  $check(($prices['general'] ?? null) === 12.0 && ($prices['discount'] ?? null) === 6.0, 'strict lookup uses older complete original category evidence instead of current fallback');
  $clear();

  // Legacy single-category reports may use the saved total when a category amount is absent.
  $legacy = $snapshot(['general_qty' => 3, 'gross_total' => 33.00]);
  unset($legacy['general_gross']);
  $seed_report($date, [$legacy]);
  $prices = $lookup($date, $showing);
  $check(($prices['general'] ?? null) === 11.0, 'single-category legacy snapshot infers unit price from gross total');
  $clear();

  // A newest all-zero corrected snapshot is skipped in favor of an older original snapshot.
  $seed_report($date, [$snapshot()]);
  $seed_report($date, [$snapshot(['general_qty' => 0, 'general_gross' => 0.00, 'gross_total' => 0.00])]);
  $prices = $lookup($date, $showing);
  $check(($prices['general'] ?? null) === 12.0, 'empty latest corrected snapshot falls back to older nonempty original snapshot');
  $clear();

  // In strict mode, a unique canonical entry row can supply the one-category baseline.
  $seed_entry($date, $showing, ['general_qty' => 3, 'total_tickets' => 3, 'gross_total' => 36.00]);
  $prices = $lookup($date, $showing, true);
  $check(($prices['general'] ?? null) === 12.0, 'strict historical lookup accepts unique one-category canonical baseline');
  $clear();

  // A wrong date or showing is never accepted as a substitute for original evidence.
  $seed_report($date, [$snapshot(['report_date' => '2039-05-07'])]);
  $seed_entry('2039-05-07', $showing, ['general_qty' => 3, 'total_tickets' => 3, 'gross_total' => 36.00]);
  $throws(static fn() => $lookup($date, $showing, true), 'wrong-date price evidence fails strict lookup');
  $clear();
  $seed_report($date, [$snapshot(['showing_id' => $showing + 1])]);
  $seed_entry($date, $showing + 1, ['general_qty' => 3, 'total_tickets' => 3, 'gross_total' => 36.00]);
  $throws(static fn() => $lookup($date, $showing, true), 'wrong-showing price evidence fails strict lookup');
  $clear();

  // No strict canonical source is an error, not an invented current price.
  $throws(static fn() => $lookup($date, $showing, true), 'missing strict original-price evidence fails closed');

  // Mixed historical categories without explicit category gross are ambiguous.
  $mixed_legacy = $snapshot(['general_qty' => 2, 'discount_qty' => 1, 'gross_total' => 30.00]);
  unset($mixed_legacy['general_gross'], $mixed_legacy['discount_gross']);
  $seed_report($date, [$mixed_legacy]);
  $throws(static fn() => $lookup($date, $showing, true), 'multi-category total without category breakdown fails closed');
  $clear();

  // Corrupt/nonintegral cents are rejected instead of rounded to a guessed unit price.
  $seed_report($date, [$snapshot(['general_gross' => 'not-money'])]);
  $throws(static fn() => $lookup($date, $showing, true), 'corrupt category money fails closed');
  $clear();
  $seed_report($date, [$snapshot(['general_gross' => 10.01])]);
  $throws(static fn() => $lookup($date, $showing, true), 'gross cents not divisible by sold category quantity fails closed');

  $clear();
  $seed_report($date, [$snapshot(), $snapshot(['general_gross' => 45.00, 'gross_total' => 45.00])]);
  $throws(static fn() => $lookup($date, $showing, true), 'duplicate saved showing rows fail closed rather than choosing first');
  $clear();
  $seed_report($date, [$snapshot(['general_qty' => 'invalid'])]);
  $throws(static fn() => $lookup($date, $showing, true), 'corrupt quantity is not silently treated as an empty historical row');
  $clear();
  $seed_report($date, [$snapshot(['general_gross' => 36.004])]);
  $throws(static fn() => $lookup($date, $showing, true), 'sub-cent historical evidence is rejected rather than rounded into a price');
  $clear();
  $after = $snapshot_hash();
  $check($after === $before, 'price lookups did not mutate private saved reports or canonical entries');
  $check($store::table_name() === $reports_table && $store::entries_table_name() === $entries_table, 'candidate Store resolves only the exact private fixture tables');
  echo "Passed {$checks} private-MySQL nominal refund-price checks. No mail or production-table writes.\n";
} finally {
  foreach (array_reverse($created) as $table) {
    if (!preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table)) throw new RuntimeException('Refusing cleanup outside fixture-owned table names.');
    if ($exists($table) && $wpdb->query('DROP TABLE ' . $quote($table)) === false) throw new RuntimeException('Could not clean private fixture table: ' . $table);
  }
}
