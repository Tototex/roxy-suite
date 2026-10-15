<?php
/** WP-CLI fixture: actual Grosses Store sync against private Grosses tables and a private booking source table. */
if (!defined('WP_CLI') || !WP_CLI) exit;

global $wpdb;
$store_path = $args[0] ?? '';
if (!is_file($store_path)) throw new RuntimeException('Pass the candidate Grosses Store path.');
$source = file_get_contents($store_path);
if (!is_string($source) || strpos($source, 'namespace RoxyGrosses;') === false) throw new RuntimeException('Unexpected candidate Grosses Store source.');
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$suffix = bin2hex(random_bytes(4));
$namespace = 'RoxyGrossesBookingSyncFixture' . $suffix;
$tables = [
  'roxy_grosses_reports' => 'roxy_fixture_' . $suffix . '_reports',
  'roxy_grosses_logs' => 'roxy_fixture_' . $suffix . '_logs',
  'roxy_grosses_history' => 'roxy_fixture_' . $suffix . '_history',
  'roxy_grosses_entries' => 'roxy_fixture_' . $suffix . '_entries',
  'roxy_grosses_live_entries' => 'roxy_fixture_' . $suffix . '_live',
  'roxy_grosses_rental_entries' => 'roxy_fixture_' . $suffix . '_rentals',
  'roxy_grosses_legacy_weekly' => 'roxy_fixture_' . $suffix . '_legacy',
  'roxy_grosses_import_batches' => 'roxy_fixture_' . $suffix . '_batches',
  'roxy_grosses_import_files' => 'roxy_fixture_' . $suffix . '_files',
  'roxy_grosses_refund_reviews' => 'roxy_fixture_' . $suffix . '_refund_reviews',
  'roxy_grosses_refund_webhook_events' => 'roxy_fixture_' . $suffix . '_refund_events',
  'roxy_grosses_unmatched_concessions' => 'roxy_fixture_' . $suffix . '_unmatched',
  'roxy_event_bookings' => 'roxy_fixture_' . $suffix . '_bookings',
];
$options = [
  'roxy_grosses_schema_version' => 'roxy_fixture_' . $suffix . '_schema_version',
  'roxy_grosses_history_backfilled' => 'roxy_fixture_' . $suffix . '_history_backfilled',
  'roxy_grosses_entries_migrated' => 'roxy_fixture_' . $suffix . '_entries_migrated',
  'roxy_grosses_row_lock_schema' => 'roxy_fixture_' . $suffix . '_row_lock_schema',
];
$source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $source);
foreach ($tables as $old => $new) $source = str_replace("'" . $old . "'", "'" . $new . "'", $source);
foreach ($options as $old => $new) $source = str_replace("'" . $old . "'", "'" . $new . "'", $source);
eval($source);
$store = '\\' . $namespace . '\\Store';
$check_count = 0;
$check = static function (bool $ok, string $message) use (&$check_count): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $message);
  ++$check_count;
  echo "PASS: {$message}\n";
};
$old_suppress = $wpdb->suppress_errors(true);
$created_tables = [];
try {
  if (!$store::install_schema() || !$store::ensure_row_lock_columns()) throw new RuntimeException('Could not install private Grosses fixture tables.');
  foreach ($tables as $name) {
    $full = $wpdb->prefix . $name;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($full)))) $created_tables[] = $full;
  }
  $bookings = $wpdb->prefix . $tables['roxy_event_bookings'];
  if ($wpdb->query("CREATE TABLE `{$bookings}` (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    visibility VARCHAR(16) NOT NULL,
    status VARCHAR(24) NOT NULL,
    invoice_status VARCHAR(16) NOT NULL DEFAULT 'not_needed',
    reserved_start_at DATETIME NOT NULL,
    reserved_end_at DATETIME NOT NULL,
    doors_open_at DATETIME NULL,
    business_name VARCHAR(190) NOT NULL DEFAULT '',
    event_format VARCHAR(24) NOT NULL DEFAULT '',
    movie_title VARCHAR(190) NOT NULL DEFAULT '',
    live_description VARCHAR(190) NOT NULL DEFAULT '',
    customer_first_name VARCHAR(100) NOT NULL DEFAULT '',
    customer_last_name VARCHAR(100) NOT NULL DEFAULT '',
    total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00
  ) ENGINE=InnoDB") === false) throw new RuntimeException('Could not create private Event Booking source table.');
  $created_tables[] = $bookings;

  $start = '2026-06-10 16:00:00';
  $end = '2026-06-10 20:00:00';
  $wpdb->insert($bookings, [
    'id' => 900001, 'visibility' => 'private', 'status' => 'confirmed', 'invoice_status' => 'paid',
    'reserved_start_at' => $start, 'reserved_end_at' => $end, 'doors_open_at' => '2026-06-10 16:30:00',
    'business_name' => 'Fixture Company', 'customer_first_name' => 'Casey', 'customer_last_name' => 'Tester', 'total_price' => '425.00',
  ]);
  $wpdb->insert($bookings, [
    'id' => 900002, 'visibility' => 'public', 'status' => 'confirmed', 'reserved_start_at' => $start,
    'reserved_end_at' => $end, 'total_price' => '999.00',
  ]);
  if ($wpdb->last_error !== '') throw new RuntimeException('Could not seed private Event Booking rows.');

  $first = $store::sync_event_bookings_to_rentals('2026-06-01', '2026-06-30');
  $rows = $wpdb->get_results('SELECT * FROM ' . $store::rental_entries_table_name() . ' ORDER BY id ASC', ARRAY_A);
  $check($first['created'] === 1 && count($rows) === 1, 'imports only the private booking in the requested date range');
  $row = $rows[0];
  $check($row['source_type'] === 'event_booking' && $row['source_ref'] === 'booking:900001'
      && $row['reserved_start_at'] === $start && $row['reserved_end_at'] === $end,
    'persists stable booking identity and exact reservation interval');
  $check($row['rental_title'] === 'Private Rental - Fixture Company' && $row['show_time'] === '4:30 PM'
      && $row['customer_name'] === 'Casey Tester' && $row['status'] === 'Paid' && (float) $row['invoice_amount'] === 425.0,
    'maps rental title, doors-open time, customer, invoice status and amount');

  $repeat = $store::sync_event_bookings_to_rentals('2026-06-01', '2026-06-30');
  $check($repeat['created'] === 0 && $repeat['updated'] === 0 && $repeat['skipped'] === 1,
    'repeated sync is idempotent');

  $wpdb->update($bookings, ['reserved_end_at' => '2026-06-10 21:00:00', 'total_price' => '450.00'], ['id' => 900001]);
  $changed = $store::sync_event_bookings_to_rentals('2026-06-01', '2026-06-30');
  $after = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $store::rental_entries_table_name() . ' WHERE id = %d', (int) $row['id']), ARRAY_A);
  $check($changed['updated'] === 1 && $after['reserved_end_at'] === '2026-06-10 21:00:00' && (float) $after['invoice_amount'] === 450.0,
    'subsequent source edits update the unlocked synchronized rental');

  $wpdb->update($store::rental_entries_table_name(), ['concessions_total' => '123.45'], ['id' => (int) $row['id']]);
  $wpdb->update($bookings, ['status' => 'cancelled', 'invoice_status' => 'paid', 'total_price' => '450.00'], ['id' => 900001]);
  $cancelled = $store::sync_event_bookings_to_rentals('2026-06-01', '2026-06-30');
  $after_cancel = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $store::rental_entries_table_name() . ' WHERE id = %d', (int) $row['id']), ARRAY_A);
  $check($cancelled['updated'] === 1 && $after_cancel['status'] === 'Cancelled' && (float) $after_cancel['invoice_amount'] === 0.0,
    'cancelled source booking remains visible and its invoice amount is excluded');
  $check((float) $after_cancel['concessions_total'] === 123.45,
    'cancellation sync preserves actual Square concession sales');
  $summary = $store::rental_entries_summary(['date_from' => '2026-06-01', 'date_to' => '2026-06-30']);
  $check($summary['row_count'] === 1 && $summary['invoice_row_count'] === 0 && $summary['invoice_total'] === 0.0
      && $summary['concessions'] === 123.45 && $summary['average_invoice'] === 0.0,
    'rental totals retain cancelled rows and Square concessions but exclude cancelled invoices from average');

  $wpdb->update($store::rental_entries_table_name(), ['is_locked' => 1], ['id' => (int) $row['id']]);
  $wpdb->update($bookings, ['status' => 'confirmed', 'reserved_end_at' => '2026-06-10 22:00:00', 'total_price' => '500.00'], ['id' => 900001]);
  $locked = $store::sync_event_bookings_to_rentals('2026-06-01', '2026-06-30');
  $protected = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $store::rental_entries_table_name() . ' WHERE id = %d', (int) $row['id']), ARRAY_A);
  $check($locked['skipped'] === 1 && $protected['reserved_end_at'] === '2026-06-10 21:00:00' && $protected['status'] === 'Cancelled' && (float) $protected['invoice_amount'] === 0.0,
    'manager-locked rental retains its saved values when source booking changes');

  echo "PASS: {$check_count} private booking synchronization checks\n";
} finally {
  foreach ($created_tables as $table) $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
  foreach ($options as $option) delete_option($option);
  $wpdb->suppress_errors($old_suppress);
}
