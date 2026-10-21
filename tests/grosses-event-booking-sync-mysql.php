<?php
/** WP-CLI fixture: actual Grosses Store sync against private Grosses tables and a private booking source table. */
if (!defined('WP_CLI') || !WP_CLI) exit;

global $wpdb;
$store_path = $args[0] ?? '';
if (!is_file($store_path)) throw new RuntimeException('Pass the candidate Grosses Store path.');
$source = file_get_contents($store_path);
if (!is_string($source) || strpos($source, 'namespace RoxyGrosses;') === false) throw new RuntimeException('Unexpected candidate Grosses Store source.');
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$settings_path = dirname($store_path) . '/class-roxy-grosses-settings.php';
if (!class_exists('\\RoxyGrosses\\Settings', false) && is_file($settings_path)) require_once $settings_path;
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
eval('namespace ' . $namespace . ';'
  . ' final class Settings { public static function get_report_timezone(): string { return "America/Los_Angeles"; } }'
  . ' final class Square {'
  . ' public static function concession_reporting_categories(array $ids): array { return array_fill_keys($ids, true); }'
  . ' public static function is_in_store_purchase_item(string $id, array $map = []): bool { return !empty($map[$id]); }'
  . ' }'
);
$store = '\\' . $namespace . '\\Store';
$check_count = 0;
$check = static function (bool $ok, string $message) use (&$check_count): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $message);
  ++$check_count;
  echo "PASS: {$message}\n";
};
$old_suppress = $wpdb->suppress_errors(true);
$original_timezone_string = get_option('timezone_string', null);
$original_gmt_offset = get_option('gmt_offset', null);
$original_grosses_settings = get_option('roxy_grosses_settings', null);
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

  $initial_preview = $store::event_booking_sync_preview_dates('2026-06-01', '2026-06-30');
  $check(in_array('2026-06-10', $initial_preview, true), 'pre-sync preview includes sale dates for new rentals so Square data can be loaded before source writes');
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

  // Chain the real booking sync to the real Reporter allocator and rental dashboard
  // summary using only randomized private Grosses tables and a local Square fixture.
  if (!class_exists('\\' . $namespace . '\\Reporter', false)) {
    $reporter_path = dirname($store_path) . '/class-roxy-grosses-reporter.php';
    $reporter_source = file_get_contents($reporter_path);
    if (!is_string($reporter_source) || strpos($reporter_source, 'namespace RoxyGrosses;') === false) throw new RuntimeException('Unexpected candidate Grosses Reporter source.');
    $reporter_source = preg_replace('/^<\?php\s*/', '', $reporter_source, 1);
    $reporter_source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $reporter_source);
    eval($reporter_source);
  }
  update_option('timezone_string', 'America/Los_Angeles');
  update_option('roxy_grosses_settings', ['report_timezone' => 'America/Los_Angeles']);
  $wpdb->insert($bookings, [
    'id' => 900010, 'visibility' => 'private', 'status' => 'confirmed', 'invoice_status' => 'paid',
    'reserved_start_at' => '2038-06-10 16:00:00', 'reserved_end_at' => '2038-06-10 20:00:00',
    'doors_open_at' => '2038-06-10 16:30:00', 'business_name' => 'Attribution Fixture', 'total_price' => '425.00',
  ]);
  if ($wpdb->last_error !== '') throw new RuntimeException('Could not seed private attribution booking.');
  $attribution_sync = $store::sync_event_bookings_to_rentals('2038-06-01', '2038-06-30');
  $check($attribution_sync['created'] === 1 && in_array('2038-06-10', $attribution_sync['dates'], true),
    'nightly booking sync creates a rental row and marks its report date for concessions reallocation');
  $movie_table = $store::entries_table_name();
  $wpdb->insert($movie_table, [
    'report_date' => '2038-06-10', 'movie_title' => 'Nearby Movie', 'normalized_title' => 'nearby movie',
    'show_time' => '6:15 PM', 'general_qty' => 1, 'total_tickets' => 1, 'gross_total' => '12.00',
    'concessions_total' => '0.00', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
  ]);
  if ($wpdb->last_error !== '') throw new RuntimeException('Could not seed private neighboring movie row.');
  $rebalance = new ReflectionMethod('\\' . $namespace . '\\Reporter', 'rebalance_concessions_for_date');
  if (PHP_VERSION_ID < 80100) $rebalance->setAccessible(true);
  $square_fixture = [[
    'id' => 'rental-attribution-order-fixture', 'closed_at' => '2038-06-10T18:00:00-07:00',
    'line_items' => [[
      'uid' => 'rental-attribution-line-fixture', 'item_type' => 'ITEM', 'catalog_object_id' => 'fixture-snack',
      'name' => 'Concessions', 'quantity' => '1', 'total_money' => ['amount' => 14177, 'currency' => 'USD'],
    ]],
  ]];
  $rebalance->invoke(null, '2038-06-10', $square_fixture);
  $allocated_rental = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM ' . $store::rental_entries_table_name() . ' WHERE source_ref = %s', 'booking:900010'
  ), ARRAY_A);
  $allocated_movie = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM `{$movie_table}` WHERE report_date = %s AND movie_title = %s", '2038-06-10', 'Nearby Movie'
  ), ARRAY_A);
  if ($wpdb->last_error !== '' || !is_array($allocated_rental) || !is_array($allocated_movie)) {
    throw new RuntimeException('Could not read back private rental attribution rows.');
  }
  $dashboard_summary = $store::rental_entries_summary(['date_from' => '2038-06-10', 'date_to' => '2038-06-10']);
  $check((float) $allocated_rental['concessions_total'] === 141.77 && (float) $allocated_movie['concessions_total'] === 0.0,
    'real Reporter allocation attributes the Square sale to its exact private rental window instead of a nearby movie');
  $check($dashboard_summary['row_count'] === 1 && $dashboard_summary['invoice_total'] === 425.0
      && $dashboard_summary['concessions'] === 141.77,
    'real Rentals dashboard summary includes the synchronized booking invoice and attributed Square concessions');

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
  $wpdb->insert($store::rental_entries_table_name(), [
    'report_date' => '2026-06-11', 'rental_title' => 'Private Rental - Fixture Company', 'normalized_title' => 'private rental - fixture company',
    'rental_type' => 'Private Rental', 'show_time' => '1:00 PM', 'status' => 'canceled', 'invoice_amount' => '99.00',
    'concessions_total' => '0.00', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
  ]);
  if ($wpdb->last_error !== '') throw new RuntimeException('Could not seed legacy canceled invoice fixture.');
  $summary = $store::rental_entries_summary(['date_from' => '2026-06-01', 'date_to' => '2026-06-30']);
  $check($summary['row_count'] === 2 && $summary['invoice_row_count'] === 0 && $summary['invoice_total'] === 0.0
      && $summary['concessions'] === 123.45 && $summary['average_invoice'] === 0.0,
    'rental totals exclude cancelled invoices even if a legacy row retained an amount, while preserving cancelled rows and Square concessions');
  $top_rentals = $store::top_rentals(['date_from' => '2026-06-01', 'date_to' => '2026-06-30'], 5, 'invoice');
  $fixture_top_rental = array_values(array_filter($top_rentals, static function ($rental) {
    return ($rental['rental_title'] ?? '') === 'Private Rental - Fixture Company';
  }));
  $check(count($fixture_top_rental) === 1 && (int) $fixture_top_rental[0]['row_count'] === 2
      && (float) $fixture_top_rental[0]['invoice_total'] === 0.0 && (float) $fixture_top_rental[0]['concessions'] === 123.45,
    'top-rentals aggregation excludes stale cancelled invoices while retaining rental row count and concessions');

  $wpdb->insert($bookings, [
    'id' => 900003, 'visibility' => 'private', 'status' => 'confirmed', 'invoice_status' => 'paid',
    'reserved_start_at' => '2026-05-10 16:00:00', 'reserved_end_at' => '2026-05-10 20:00:00',
    'doors_open_at' => '2026-05-10 16:30:00', 'business_name' => 'Earlier Fixture Rental', 'total_price' => '250.00',
  ]);
  $may_sync = $store::sync_event_bookings_to_rentals('2026-05-01', '2026-05-31');
  $earlier = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $store::rental_entries_table_name() . ' WHERE source_ref = %s', 'booking:900003'), ARRAY_A);
  $wpdb->update($bookings, ['status' => 'cancelled', 'invoice_status' => 'void'], ['id' => 900003]);
  $later_sync = $store::sync_event_bookings_to_rentals('2026-06-01', '2026-06-30');
  $earlier_after = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $store::rental_entries_table_name() . ' WHERE source_ref = %s', 'booking:900003'), ARRAY_A);
  $check($may_sync['created'] === 1 && $later_sync['updated'] === 1 && !in_array('2026-05-10', $later_sync['dates'], true)
      && $earlier_after['status'] === 'Cancelled' && (float) $earlier_after['invoice_amount'] === 0.0,
    'nightly sync notices an out-of-window cancellation without scheduling an out-of-window Square rebalance');

  $wpdb->update($store::rental_entries_table_name(), ['is_locked' => 1], ['id' => (int) $row['id']]);
  $wpdb->update($bookings, ['status' => 'confirmed', 'reserved_end_at' => '2026-06-10 22:00:00', 'total_price' => '500.00'], ['id' => 900001]);
  $locked = $store::sync_event_bookings_to_rentals('2026-06-01', '2026-06-30');
  $protected = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $store::rental_entries_table_name() . ' WHERE id = %d', (int) $row['id']), ARRAY_A);
  $check($locked['skipped'] === 2 && $protected['reserved_end_at'] === '2026-06-10 21:00:00' && $protected['status'] === 'Cancelled' && (float) $protected['invoice_amount'] === 0.0,
    'manager-locked rental retains its saved values when source booking changes');

  $wpdb->insert($bookings, [
    'id' => 900004, 'visibility' => 'private', 'status' => 'confirmed', 'invoice_status' => 'paid',
    'reserved_start_at' => '2026-06-10 16:00:00', 'reserved_end_at' => '2026-06-10 20:00:00',
    'doors_open_at' => '2026-06-10 16:30:00', 'business_name' => 'Rescheduled Fixture', 'total_price' => '125.00',
  ]);
  $store::sync_event_bookings_to_rentals('2026-06-01', '2026-06-30');
  $wpdb->update($bookings, ['reserved_start_at' => '2026-06-11 16:00:00', 'reserved_end_at' => '2026-06-11 20:00:00'], ['id' => 900004]);
  $move_preview = $store::event_booking_sync_preview_dates('2026-06-11', '2026-06-11');
  $moved = $store::sync_event_bookings_to_rentals('2026-06-11', '2026-06-11');
  $moved_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $store::rental_entries_table_name() . ' WHERE source_ref = %s', 'booking:900004'), ARRAY_A);
  $check(in_array('2026-06-10', $move_preview, true) && in_array('2026-06-11', $move_preview, true)
      && in_array('2026-06-10', $moved['dates'], true) && in_array('2026-06-11', $moved['dates'], true)
      && $moved_row['report_date'] === '2026-06-11',
    'rescheduling a linked booking across report dates previews and rebalances both the old and new dates');

  update_option('timezone_string', 'America/Los_Angeles');
  update_option('roxy_grosses_settings', ['report_timezone' => 'UTC']);
  $wpdb->insert($bookings, [
    'id' => 900005, 'visibility' => 'private', 'status' => 'confirmed', 'invoice_status' => 'paid',
    'reserved_start_at' => '2026-06-10 23:30:00', 'reserved_end_at' => '2026-06-11 00:30:00',
    'doors_open_at' => '2026-06-10 23:45:00', 'business_name' => 'Timezone Fixture', 'total_price' => '75.00',
  ]);
  $timezone_preview = $store::event_booking_sync_preview_dates('2026-06-11', '2026-06-11');
  $timezone_sync = $store::sync_event_bookings_to_rentals('2026-06-11', '2026-06-11');
  $timezone_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $store::rental_entries_table_name() . ' WHERE source_ref = %s', 'booking:900005'), ARRAY_A);
  $check($timezone_preview === ['2026-06-11'] && $timezone_sync['created'] === 1 && in_array('2026-06-11', $timezone_sync['dates'], true)
      && $timezone_row['report_date'] === '2026-06-11' && $timezone_row['show_time'] === '6:45 AM'
      && $timezone_row['reserved_start_at'] === '2026-06-10 23:30:00',
    'site-local booking wall times convert to the Grosses report timezone before report-date selection');
  if (!class_exists('\\RoxyGrosses\\Reporter', false)) require_once dirname($store_path) . '/class-roxy-grosses-reporter.php';
  $window_parser = new ReflectionMethod('\\RoxyGrosses\\Reporter', 'rental_window_datetime');
  if (PHP_VERSION_ID < 80100) $window_parser->setAccessible(true);
  $report_window_start = $window_parser->invoke(null, '2026-06-10 23:30:00');
  $check($report_window_start instanceof DateTimeImmutable && $report_window_start->format('Y-m-d H:i:s e') === '2026-06-11 06:30:00 UTC',
    'rental attribution parses persisted wall time in the WordPress timezone before converting to report timezone');

  $wpdb->insert($bookings, [
    'id' => 900006, 'visibility' => 'private', 'status' => 'confirmed', 'invoice_status' => 'paid',
    'reserved_start_at' => '2026-06-12 16:00:00', 'reserved_end_at' => '2026-06-12 20:00:00',
    'doors_open_at' => '2026-06-12 16:30:00', 'business_name' => 'Locked Cancellation Fixture', 'total_price' => '300.00',
  ]);
  $store::sync_event_bookings_to_rentals('2026-06-01', '2026-06-30');
  $locked_cancel_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $store::rental_entries_table_name() . ' WHERE source_ref = %s', 'booking:900006'), ARRAY_A);
  $wpdb->update($store::rental_entries_table_name(), [
    'is_locked' => 1, 'rental_title' => 'Manager-edited rental title', 'concessions_total' => '87.65',
  ], ['id' => (int) $locked_cancel_row['id']]);
  $wpdb->update($bookings, ['status' => 'cancelled', 'invoice_status' => 'void'], ['id' => 900006]);
  $locked_cancel_sync = $store::sync_event_bookings_to_rentals('2026-06-01', '2026-06-30');
  $locked_cancel_after = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $store::rental_entries_table_name() . ' WHERE id = %d', (int) $locked_cancel_row['id']), ARRAY_A);
  $check($locked_cancel_sync['updated'] === 1 && $locked_cancel_after['status'] === 'Cancelled'
      && (float) $locked_cancel_after['invoice_amount'] === 0.0 && (float) $locked_cancel_after['concessions_total'] === 87.65
      && (int) $locked_cancel_after['is_locked'] === 1 && $locked_cancel_after['rental_title'] === 'Manager-edited rental title',
    'source cancellation clears locked rental invoice while preserving manager edits, lock, row and recorded concessions');

  if ($wpdb->query("DROP TABLE `{$bookings}`") === false) throw new RuntimeException('Could not remove the private source fixture for the unavailable-source test.');
  foreach ([
    static function () use ($store): void { $store::event_booking_sync_preview_dates('2026-06-01', '2026-06-30'); },
    static function () use ($store): void { $store::sync_event_bookings_to_rentals('2026-06-01', '2026-06-30'); },
  ] as $unavailable_call) {
    try {
      $unavailable_call();
      $check(false, 'missing Event Booking source must not silently appear as zero rentals');
    } catch (RuntimeException $error) {
      $check(strpos($error->getMessage(), 'source table is unavailable') !== false,
        'missing Event Booking source is reported as an explicit availability error');
    }
  }

  echo "PASS: {$check_count} private booking synchronization checks\n";
} finally {
  foreach ($created_tables as $table) $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
  foreach ($options as $option) delete_option($option);
  if ($original_timezone_string === null) delete_option('timezone_string'); else update_option('timezone_string', $original_timezone_string);
  if ($original_gmt_offset === null) delete_option('gmt_offset'); else update_option('gmt_offset', $original_gmt_offset);
  if ($original_grosses_settings === null) delete_option('roxy_grosses_settings'); else update_option('roxy_grosses_settings', $original_grosses_settings);
  $wpdb->suppress_errors($old_suppress);
}
