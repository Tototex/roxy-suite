<?php
/** WP-CLI regression for complete, fail-closed Grosses backfill reads. */
if (!defined('WP_CLI') || !WP_CLI) exit;

$root = $args[0] ?? dirname(__DIR__);
$store_path = $root . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';
if (!is_file($store_path)) throw new RuntimeException('Grosses Store candidate is missing.');
require_once $store_path;

global $wpdb;
$original_db = $wpdb;
$token = 'gbp_' . bin2hex(random_bytes(6));
$private_prefix = $original_db->prefix . $token . '_';
if (!preg_match('/^[A-Za-z0-9_]+$/', $private_prefix)) throw new RuntimeException('Private backfill table prefix is invalid.');
$movie_table = $private_prefix . \RoxyGrosses\Store::ENTRY_TABLE;
$live_table = $private_prefix . \RoxyGrosses\Store::LIVE_ENTRY_TABLE;
foreach ([$movie_table, $live_table] as $table) {
  if (!preg_match('/^' . preg_quote($original_db->prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table)) {
    throw new RuntimeException('Refusing a backfill fixture table outside the random private namespace.');
  }
}
$fixture_db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$fixture_db->suppress_errors(true);
$fixture_db->prefix = $private_prefix;
$created = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
  if (!$condition) throw new RuntimeException('FAIL: ' . $label);
  $checks++;
  echo "PASS: {$label}\n";
};

try {
  foreach ([$movie_table, $live_table] as $table) {
    $exists = $original_db->get_var($original_db->prepare('SHOW TABLES LIKE %s', $original_db->esc_like($table)));
    if ($exists === $table) throw new RuntimeException('Random private backfill table already exists; refusing to reuse it.');
  }
  $wpdb = $fixture_db;
  $movie_ok = $wpdb->query("CREATE TABLE `{$movie_table}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, report_date DATE NOT NULL, show_time VARCHAR(16) NOT NULL DEFAULT '', movie_title VARCHAR(255) NOT NULL DEFAULT '', PRIMARY KEY (id), KEY report_date (report_date)) ENGINE=InnoDB " . $wpdb->get_charset_collate());
  if ($movie_ok === false || $wpdb->last_error !== '') throw new RuntimeException('Could not create private movie backfill table.');
  $created[] = $movie_table;
  $live_ok = $wpdb->query("CREATE TABLE `{$live_table}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, report_date DATE NOT NULL, show_time VARCHAR(16) NOT NULL DEFAULT '', show_title VARCHAR(255) NOT NULL DEFAULT '', PRIMARY KEY (id), KEY report_date (report_date)) ENGINE=InnoDB " . $wpdb->get_charset_collate());
  if ($live_ok === false || $wpdb->last_error !== '') throw new RuntimeException('Could not create private live backfill table.');
  $created[] = $live_table;

  foreach ([[$movie_table, 'movie_title', 'Movie'], [$live_table, 'show_title', 'Live']] as [$table, $title_column, $title_prefix]) {
    for ($start = 1; $start <= 1201; $start += 300) {
      $values = [];
      $end = min(1201, $start + 299);
      for ($id = $start; $id <= $end; $id++) $values[] = $wpdb->prepare('(%s, %s, %s)', '2039-07-18', '19:30', $title_prefix . ' ' . $id);
      $inserted = $wpdb->query("INSERT INTO `{$table}` (report_date, show_time, `{$title_column}`) VALUES " . implode(',', $values));
      if ($inserted !== count($values) || $wpdb->last_error !== '') throw new RuntimeException('Could not seed private backfill rows.');
    }
  }

  $movie_rows = \RoxyGrosses\Store::list_entries_for_backfill_date('2039-07-18');
  $live_rows = \RoxyGrosses\Store::list_live_entries_for_backfill_date('2039-07-18');
  $check(count($movie_rows) === 1201 && (int) $movie_rows[0]['id'] === 1 && (int) $movie_rows[1200]['id'] === 1201, 'movie backfill scans all 1,201 date rows over multiple pages');
  $check(count($live_rows) === 1201 && (int) $live_rows[0]['id'] === 1 && (int) $live_rows[1200]['id'] === 1201, 'live backfill scans all 1,201 date rows over multiple pages');
  $check(\RoxyGrosses\Store::list_entries_for_backfill_date('2040-01-01') === []
    && \RoxyGrosses\Store::list_live_entries_for_backfill_date('2040-01-01') === [], 'verified empty movie and live backfill dates remain empty');

  $invalid_date_rejected = false;
  try { \RoxyGrosses\Store::list_entries_for_backfill_date('2039-02-30'); } catch (InvalidArgumentException $error) { $invalid_date_rejected = true; }
  $check($invalid_date_rejected, 'impossible backfill dates are rejected before querying');

  $fail_late_page = static function ($query) use ($movie_table, $wpdb) {
    if (str_contains((string) $query, $movie_table) && str_contains((string) $query, 'id > 1000')) {
      $wpdb->last_error = 'simulated third-page read failure';
      return false;
    }
    return $query;
  };
  add_filter('query', $fail_late_page);
  $late_page_failed_closed = false;
  try { \RoxyGrosses\Store::list_entries_for_backfill_date('2039-07-18'); }
  catch (RuntimeException $error) { $late_page_failed_closed = str_contains($error->getMessage(), 'Could not read all movie backfill rows'); }
  remove_filter('query', $fail_late_page);
  $check($late_page_failed_closed, 'failure on a later movie page aborts the backfill read instead of truncating it');

  echo "Passed {$checks} private-MySQL Grosses backfill pagination checks. No production tables or data were touched.\n";
} finally {
  $wpdb = $original_db;
  foreach (array_reverse($created) as $table) {
    $drop = $original_db->query("DROP TABLE IF EXISTS `{$table}`");
    if ($drop === false || !empty($original_db->last_error)) throw new RuntimeException('Could not remove a private Grosses backfill fixture table.');
  }
}
