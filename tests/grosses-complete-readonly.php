<?php
// Actual reporting data, SELECT only. Candidate may be staged outside the website.
if (!defined('WP_CLI') || !WP_CLI) exit;
$root = $args[0] ?? dirname(__DIR__);
global $wpdb;
$source = file_get_contents($root . '/includes/modules/grosses/includes/class-roxy-grosses-store.php');
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$source = str_replace('namespace RoxyGrosses;', 'namespace RoxyGrossesCompleteReadonly;', $source);
eval($source);
$suffixes = ['movies' => 'roxy_grosses_entries', 'live' => 'roxy_grosses_live_entries', 'rentals' => 'roxy_grosses_rental_entries', 'legacy' => 'roxy_grosses_legacy_weekly'];
foreach ($suffixes as $dataset => $suffix) {
  $table = $wpdb->prefix . $suffix;
  $date = $dataset === 'legacy' ? 'week_start_date' : 'report_date';
  $title = ['movies' => 'movie_title', 'live' => 'show_title', 'rentals' => 'rental_title', 'legacy' => 'movie_title'][$dataset];
  $order = "COALESCE($date, '') DESC, " . ($dataset === 'legacy' ? '' : "COALESCE(show_time, '') DESC, ") . "COALESCE($title, '') ASC, id ASC";
  $expected = $wpdb->get_results("SELECT * FROM $table ORDER BY $order", ARRAY_A);
  if ($wpdb->last_error) throw new RuntimeException('Baseline read failed');
  $actual = iterator_to_array(\RoxyGrossesCompleteReadonly\Store::iterate_dataset($dataset), false);
  if ($expected !== $actual) throw new RuntimeException("$dataset reporting parity failed");
  echo "PASS: $dataset complete actual reporting parity, " . count($actual) . " rows\n";
}
