<?php
// WP-CLI only: all fixture writes use connection-private TEMPORARY tables.
if (!defined('WP_CLI') || !WP_CLI) exit;
$root = $args[0] ?? dirname(__DIR__);
global $wpdb;
$original_prefix = $wpdb->prefix;
$source = file_get_contents($root . '/includes/modules/grosses/includes/class-roxy-grosses-store.php');
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$source = str_replace('namespace RoxyGrosses;', 'namespace RoxyGrossesCompleteFixture;', $source);
eval($source);
function complete_assert($ok, $label) {
  if (!$ok) throw new RuntimeException($label);
  echo "PASS: $label\n";
}
$datasets = ['movies' => 'roxy_grosses_entries', 'live' => 'roxy_grosses_live_entries', 'rentals' => 'roxy_grosses_rental_entries', 'legacy' => 'roxy_grosses_legacy_weekly'];
$before = [];
foreach ($datasets as $dataset => $suffix) {
  $before[$dataset] = hash('sha256', wp_json_encode($wpdb->get_results("SELECT * FROM {$original_prefix}{$suffix} ORDER BY id", ARRAY_A)));
  if ($wpdb->last_error) throw new RuntimeException('Cannot capture reporting baseline');
}
$wpdb->prefix = $original_prefix . 'complete_' . bin2hex(random_bytes(4)) . '_';
$created = [];
try {
  foreach ($datasets as $dataset => $suffix) {
    $table = $wpdb->prefix . $suffix;
    if ($wpdb->query("CREATE TEMPORARY TABLE $table (
      id BIGINT PRIMARY KEY, report_date DATE, week_start_date DATE, show_time VARCHAR(32),
      movie_title VARCHAR(190), show_title VARCHAR(190), rental_title VARCHAR(190),
      genre VARCHAR(190), studio VARCHAR(190), theater_name VARCHAR(190), source_file VARCHAR(190),
      week_label VARCHAR(190), customer_name VARCHAR(190), rental_type VARCHAR(190), notes TEXT,
      gross_total DECIMAL(12,2), concessions_total DECIMAL(12,2)
    )") === false) throw new RuntimeException('Cannot create private fixture');
    $created[] = $table;
    for ($start = 1; $start <= 5001; $start += 250) {
      $values = [];
      for ($id = $start; $id <= min(5001, $start + 249); $id++) {
        $date = '2026-10-' . str_pad((string)(1 + $id % 5), 2, '0', STR_PAD_LEFT);
        $title = $id % 3 === 0 ? 'Same' : ($id % 3 === 1 ? 'alpha' : 'ALPHA');
        $genre = $id === 5001 ? 'Winner' : 'Drama, Comedy';
        $gross = $id === 5001 ? 100000 : 1;
        $values[] = $wpdb->prepare('(%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d)',
          $id, $date, $date, $id % 2 ? '18:00' : '', $title, $title, $title,
          $genre, 'Studio', 'Theater', 'fixture.csv', 'Week', 'Customer', 'Rental', 'Notes', $gross, 2);
      }
      if ($wpdb->query("INSERT INTO $table VALUES " . implode(',', $values)) === false) throw new RuntimeException('Cannot seed fixture');
    }
    $date_column = $dataset === 'legacy' ? 'week_start_date' : 'report_date';
    $title_column = ['movies' => 'movie_title', 'live' => 'show_title', 'rentals' => 'rental_title', 'legacy' => 'movie_title'][$dataset];
    $order = "COALESCE($date_column, '') DESC, " . ($dataset === 'legacy' ? '' : "COALESCE(show_time, '') DESC, ") . "COALESCE($title_column, '') ASC, id ASC";
    $expected = array_map('intval', $wpdb->get_col("SELECT id FROM $table ORDER BY $order"));
    $rows = iterator_to_array(\RoxyGrossesCompleteFixture\Store::iterate_dataset($dataset), false);
    complete_assert(count($rows) === 5001 && array_map('intval', array_column($rows, 'id')) === $expected, "$dataset: all 5,001 rows in exact SQL order, including tied/case-insensitive names");
    $filtered = iterator_to_array(\RoxyGrossesCompleteFixture\Store::iterate_dataset($dataset, ['day' => '2026-10-02']), false);
    complete_assert(count($filtered) === 1001 && count(array_unique(array_column($filtered, 'id'))) === 1001, "$dataset: 1,001 filtered rows without duplicates");
    complete_assert(count(iterator_to_array(\RoxyGrossesCompleteFixture\Store::iterate_dataset($dataset, ['year' => 2000]))) === 0, "$dataset: empty filter remains empty");
    complete_assert(count(iterator_to_array(\RoxyGrossesCompleteFixture\Store::iterate_dataset($dataset, ['search' => 'Same', 'month' => '2026-10']))) === 1667, "$dataset: search/month filters preserved");
    // The generator's ceiling excludes a newly inserted high ID between pages.
    $iterator = \RoxyGrossesCompleteFixture\Store::iterate_dataset($dataset);
    $iterator->rewind();
    $wpdb->query("INSERT INTO $table SELECT 6000,report_date,week_start_date,show_time,movie_title,show_title,rental_title,genre,studio,theater_name,source_file,week_label,customer_name,rental_type,notes,gross_total,concessions_total FROM $table WHERE id=1");
    complete_assert(count(iterator_to_array($iterator, false)) === 5001, "$dataset: post-start inserts excluded");
    $wpdb->query("DELETE FROM $table WHERE id=6000");
  }
  $average = new ReflectionMethod(\RoxyGrossesCompleteFixture\Store::class, 'top_movie_genre_average');
  $groups = new ReflectionMethod(\RoxyGrossesCompleteFixture\Store::class, 'top_movie_genre_groups');
  complete_assert($average->invoke(null, [], 'gross')['label'] === 'Winner', 'genre average includes old rows beyond original cap');
  $result = $groups->invoke(null, [], 5, 'gross');
  complete_assert($result[0]['label'] === 'Winner' && $result[0]['primary_total'] === 100000.0, 'genre totals include all 5,001 rows');
  complete_assert($result[1]['row_count'] === 5000 && $result[1]['primary_total'] === 5000.0, 'comma-separated genre attribution unchanged');
  complete_assert($average->invoke(null, ['year' => 2000], 'gross') === null && $groups->invoke(null, ['year' => 2000]) === [], 'empty analytics preserved');
  $missing = $wpdb->prefix . 'roxy_grosses_live_entries';
  $late_failure = \RoxyGrossesCompleteFixture\Store::iterate_dataset('live');
  $late_failure->rewind();
  $wpdb->query("DROP TEMPORARY TABLE $missing");
  $old_suppress = $wpdb->suppress_errors(true);
  $failed = false;
  try { iterator_to_array($late_failure, false); } catch (RuntimeException $error) { $failed = true; }
  complete_assert($failed, 'second-page database failure throws rather than returning first-page-only success');
  $failed = false;
  try { iterator_to_array(\RoxyGrossesCompleteFixture\Store::iterate_dataset('live')); } catch (RuntimeException $error) { $failed = true; }
  $wpdb->suppress_errors($old_suppress);
  complete_assert($failed, 'database failure throws rather than yielding an empty success');
} finally {
  foreach ($created as $table) $wpdb->query("DROP TEMPORARY TABLE IF EXISTS $table");
  $wpdb->prefix = $original_prefix;
}
foreach ($datasets as $dataset => $suffix) {
  $after = hash('sha256', wp_json_encode($wpdb->get_results("SELECT * FROM {$original_prefix}{$suffix} ORDER BY id", ARRAY_A)));
  complete_assert($wpdb->last_error === '' && $after === $before[$dataset], "$dataset: original production records unchanged");
}
