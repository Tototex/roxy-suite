<?php
// Isolated Store checks for verified-empty versus failed backfill date reads.
namespace {
  if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  final class BackfillDateWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public $result = [];
    public int $reads = 0;
    public function get_col(string $query) { $this->reads++; return $this->result; }
    public function prepare(string $query, ...$args): string { return $query; }
    public function esc_like(string $value): string { return addcslashes($value, '_%\\'); }
  }
  $wpdb = new BackfillDateWpdb();
}

namespace {
  require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';
  require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-reporter.php';
  $checks = 0;
  $check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new \RuntimeException('FAIL: ' . $label);
    $checks++;
    echo "PASS: {$label}\n";
  };
  $throws_storage_error = static function (callable $read): bool {
    try { $read(); } catch (\RuntimeException $error) { return str_contains($error->getMessage(), 'Could not read Grosses'); }
    return false;
  };

  $wpdb->result = ['2026-10-06', '2026-10-07'];
  $check(\RoxyGrosses\Store::distinct_entry_dates() === $wpdb->result, 'movie backfill date query returns verified dates');
  $wpdb->result = [];
  $check(\RoxyGrosses\Store::distinct_entry_dates() === [], 'movie backfill date query preserves verified empty result');
  $wpdb->result = null;
  $wpdb->last_error = 'simulated database unavailable';
  $check($throws_storage_error(static fn() => \RoxyGrosses\Store::distinct_entry_dates()), 'movie backfill database error is not reported as no matching dates');
  $wpdb->last_error = '';
  $check($throws_storage_error(static fn() => \RoxyGrosses\Store::distinct_live_entry_dates()), 'live backfill false/null read is not reported as no matching dates');
  $wpdb->result = [];
  $check(\RoxyGrosses\Store::distinct_live_entry_dates() === [], 'live backfill date query preserves verified empty result');
  $wpdb->last_error = 'simulated live table read failure';
  $check($throws_storage_error(static fn() => \RoxyGrosses\Store::distinct_live_entry_dates()), 'live backfill database error is surfaced');
  $movie_error = \RoxyGrosses\Reporter::backfill_free_tickets();
  $movie_concession_error = \RoxyGrosses\Reporter::backfill_movie_concessions();
  $live_error = \RoxyGrosses\Reporter::backfill_live_concessions();
  $check(($movie_error['success'] ?? true) === false && str_contains($movie_error['message'] ?? '', 'Could not read movie dates')
    && str_contains($movie_error['message'] ?? '', 'No rows were changed.'), 'movie free-ticket backfill reports unavailable read without mutation');
  $check(($movie_concession_error['success'] ?? true) === false && str_contains($movie_concession_error['message'] ?? '', 'Could not read movie dates'), 'movie concession backfill reports unavailable read');
  $check(($live_error['success'] ?? true) === false && str_contains($live_error['message'] ?? '', 'Could not read live-show dates'), 'live concession backfill reports unavailable read');

  echo "PASS: {$checks} Grosses backfill date-read checks\n";
}
