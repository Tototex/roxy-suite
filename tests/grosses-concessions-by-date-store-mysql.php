<?php
// Disposable-MySQL verification of saved Grosses daily concession totals.
if (!defined('ABSPATH')) throw new RuntimeException('This fixture must run inside WordPress.');
require_once __DIR__ . '/../includes/modules/grosses/includes/class-roxy-grosses-store.php';

global $wpdb;
$original_prefix = $wpdb->prefix;
$fixture_prefix = 'wp_rgprobe_' . strtolower(bin2hex(random_bytes(4))) . '_';
$table_suffixes = [
  \RoxyGrosses\Store::ENTRY_TABLE,
  \RoxyGrosses\Store::LIVE_ENTRY_TABLE,
  \RoxyGrosses\Store::RENTAL_ENTRY_TABLE,
];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) throw new RuntimeException($message);
  $checks++;
};
$table = static fn(string $suffix): string => '`' . $fixture_prefix . $suffix . '`';
$create = static function (string $suffix) use ($wpdb, $table): void {
  $sql = 'CREATE TABLE ' . $table($suffix) . ' (report_date DATE NOT NULL, concessions_total DECIMAL(12,2) NOT NULL) ENGINE=InnoDB';
  if ($wpdb->query($sql) === false || $wpdb->last_error !== '') throw new RuntimeException('Could not create an isolated Grosses fixture table.');
};

try {
  $wpdb->prefix = $fixture_prefix;
  foreach ($table_suffixes as $suffix) $create($suffix);

  $empty = \RoxyGrosses\Store::concessions_by_date('2026-10-11', '2026-10-11');
  $assert($empty === [], 'actual MySQL empty Movie, Live, and Rental reads are a verified zero-sales result');

  foreach ([
    \RoxyGrosses\Store::ENTRY_TABLE => 'movie',
    \RoxyGrosses\Store::LIVE_ENTRY_TABLE => 'live-show',
    \RoxyGrosses\Store::RENTAL_ENTRY_TABLE => 'rental',
  ] as $suffix => $label) {
    if ($wpdb->query('DROP TABLE ' . $table($suffix)) === false || $wpdb->last_error !== '') throw new RuntimeException('Could not prepare an isolated Grosses query-failure case.');
    try {
      \RoxyGrosses\Store::concessions_by_date('2026-10-11', '2026-10-11');
      throw new RuntimeException("Missing $label table was accepted as an empty total.");
    } catch (RuntimeException $error) {
      $assert(str_contains($error->getMessage(), 'Could not read '), "$label database read failure is surfaced instead of treated as zero");
    } finally {
      $create($suffix);
    }
  }

  foreach ([
    [\RoxyGrosses\Store::ENTRY_TABLE, 12.34],
    [\RoxyGrosses\Store::LIVE_ENTRY_TABLE, 5.00],
    [\RoxyGrosses\Store::RENTAL_ENTRY_TABLE, 2.66],
  ] as [$suffix, $amount]) {
    $result = $wpdb->insert($fixture_prefix . $suffix, ['report_date' => '2026-10-11', 'concessions_total' => $amount], ['%s', '%f']);
    if ($result !== 1 || $wpdb->last_error !== '') throw new RuntimeException('Could not seed isolated Grosses totals.');
  }
  $totals = \RoxyGrosses\Store::concessions_by_date('2026-10-11', '2026-10-11');
  $assert(($totals['2026-10-11']['movies'] ?? null) === 12.34, 'actual Movie total is read from the persisted daily row');
  $assert(($totals['2026-10-11']['live'] ?? null) === 5.0, 'actual Live total is read from the persisted daily row');
  $assert(($totals['2026-10-11']['rentals'] ?? null) === 2.66, 'actual Rental total is read from the persisted daily row');
  $assert(($totals['2026-10-11']['assigned_total'] ?? null) === 20.0, 'actual persisted daily categories reconcile to their combined total');
  echo "$checks Grosses concessions-by-date MySQL checks passed; disposable tables only.\n";
} finally {
  $wpdb->prefix = $fixture_prefix;
  foreach ($table_suffixes as $suffix) $wpdb->query('DROP TABLE IF EXISTS ' . $table($suffix));
  $wpdb->prefix = $original_prefix;
}
