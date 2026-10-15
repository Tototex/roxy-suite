<?php
/** Private WP-CLI fixture for concessions matched across movie/live report builders. */
if (!defined('WP_CLI') || !WP_CLI) exit;

$reporter_path = $args[0] ?? '';
if (!is_file($reporter_path)) throw new RuntimeException('Pass the candidate Grosses Reporter path.');
$namespace = 'RoxyGrossesMixedShowingFixture' . bin2hex(random_bytes(5));
$eval = 'namespace ' . $namespace . ';
  function post_type_exists(string $type): bool { return \\post_type_exists($type); }
  final class Settings {
    public static function get_report_timezone(): string { return "America/Los_Angeles"; }
    public static function get(string $key, $default = "") { return $key === "lookback_days" ? "0" : $default; }
  }
  final class Square {
    public static array $orders = [];
    public static int $date_fetches = 0;
    public static function fetch_orders_for_date(string $date): array { self::$date_fetches++; return self::$orders[$date] ?? []; }
    public static function concession_reporting_categories(array $ids): array { return ["snack" => true]; }
    public static function is_in_store_purchase_item(string $id, array $map = []): bool { return $id === "snack"; }
  }
  final class Store {
    public static array $unmatched = [];
    public static array $by_kind = [];
    public static function record_unmatched_concession_lines(string $date, array $lines): int { self::$unmatched[$date] = $lines; return count($lines); }
    public static function list_all_entries_for_rebalance(string $kind, string $date): array { return self::$by_kind[$kind][$date] ?? []; }
    public static function normalize_title(string $title): string {
      $title = strtolower(trim($title));
      $title = preg_replace("/\\s+/", " ", $title) ?? $title;
      return trim(str_replace(["—", "–"], "-", $title));
    }
  }
  final class RefundSnapshot {
    public static function load(...$args): self { return new self(); }
    public function reconcile_sale_day(string $date, array $orders): array { return ["orders" => $orders, "adjustments" => [], "issues" => []]; }
  }
';
eval($eval);

$source = file_get_contents($reporter_path);
if (!is_string($source) || strpos($source, 'namespace RoxyGrosses;') === false) throw new RuntimeException('Unexpected candidate Reporter source.');
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $source);
eval($source);

if (!post_type_exists('roxy_showing')) register_post_type('roxy_showing', ['public' => false, 'supports' => ['title']]);
$date = '2039-01-15';
$token = bin2hex(random_bytes(5));
$showings = [
  ['date' => $date, 'title' => 'PRIVATE movie matinee A ' . $token, 'time' => '14:00', 'profile' => 'movie_matinee'],
  ['date' => $date, 'title' => 'PRIVATE movie matinee B ' . $token, 'time' => '16:00', 'profile' => 'movie_matinee'],
  ['date' => $date, 'title' => 'PRIVATE live evening ' . $token, 'time' => '20:30', 'profile' => 'live_event'],
  ['date' => '2039-01-16', 'title' => 'PRIVATE single movie ' . $token, 'time' => '14:00', 'profile' => 'movie_matinee'],
  ['date' => '2039-01-16', 'title' => 'PRIVATE single live ' . $token, 'time' => '20:30', 'profile' => 'live_event'],
  ['date' => '2039-01-17', 'title' => 'PRIVATE overlap movie ' . $token, 'time' => '14:00', 'profile' => 'movie_matinee'],
  ['date' => '2039-01-17', 'title' => 'PRIVATE overlap live ' . $token, 'time' => '16:00', 'profile' => 'live_event'],
  ['date' => '2039-01-18', 'title' => 'PRIVATE legacy link movie ' . $token, 'time' => '14:00', 'profile' => 'movie_matinee'],
  ['date' => '2039-01-19', 'title' => 'PRIVATE movie with rental ' . $token, 'time' => '14:00', 'profile' => 'movie_matinee'],
  ['date' => '2039-01-20', 'title' => 'PRIVATE same-time movie ' . $token, 'time' => '14:00', 'profile' => 'movie_matinee'],
  ['date' => '2039-01-20', 'title' => 'PRIVATE same-time live ' . $token, 'time' => '14:00', 'profile' => 'live_event'],
  ['date' => '2039-01-21', 'title' => 'PRIVATE moved movie ' . $token, 'time' => '14:00', 'profile' => 'movie_matinee'],
  ['date' => '2039-01-21', 'title' => 'PRIVATE moved live ' . $token, 'time' => '16:00', 'profile' => 'live_event'],
];
$post_ids = [];
$check = static function (bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $message);
  echo "PASS: {$message}\n";
};
$fixture_store = '\\' . $namespace . '\\Store';
$check($fixture_store::normalize_title('A-B') !== $fixture_store::normalize_title('A B'),
  'fixture preserves production punctuation-sensitive title normalization');
try {
  foreach ($showings as $showing) {
    $id = wp_insert_post([
      'post_type' => 'roxy_showing', 'post_status' => 'draft', 'post_title' => $showing['title'],
      'post_date' => current_time('mysql'), 'post_date_gmt' => current_time('mysql', true),
    ], true);
    if (is_wp_error($id)) throw new RuntimeException('Could not create a private draft showing.');
    $post_ids[] = (int) $id;
    update_post_meta($id, '_roxy_start', $showing['date'] . 'T' . $showing['time']);
    update_post_meta($id, '_roxy_pricing_profile', $showing['profile']);
  }

  $square = '\\' . $namespace . '\\Square';
  $store = '\\' . $namespace . '\\Store';
  $reporter = '\\' . $namespace . '\\Reporter';
  $refund_snapshot = '\\' . $namespace . '\\RefundSnapshot';
  $square::$orders[$date] = [[
    'id' => 'private-mixed-show-concession-' . $token,
    'closed_at' => $date . 'T19:30:00-08:00',
    'line_items' => [[
      'uid' => 'private-concession-line-' . $token, 'item_type' => 'ITEM', 'catalog_object_id' => 'snack',
      'name' => 'Snack', 'quantity' => '1', 'total_money' => ['amount' => 2514, 'currency' => 'USD'],
    ]],
  ]];

  $movie_error = null;
  try { $movies = $reporter::build_reports($date, true, new $refund_snapshot()); }
  catch (Throwable $error) { $movie_error = $error; $movies = []; }
  $check($movie_error === null, 'movie report build does not flag an evening live-show sale as unmatched');
  $check(count($movies) === 2 && $store::$unmatched === [], 'all movie rows build and the valid cross-type sale is absent from the unmatched queue');

  $live = $reporter::build_live_reports($date, true);
  $check(count($live) === 1 && (float) $live[0]['concessions_total'] === 25.14, 'the same sale is allocated to its matching live show');

  $single_date = '2039-01-16';
  $square::$orders[$single_date] = [[
    'id' => 'private-single-movie-live-concession-' . $token,
    'closed_at' => $single_date . 'T19:30:00-08:00',
    'line_items' => [[
      'uid' => 'private-single-concession-line-' . $token, 'item_type' => 'ITEM', 'catalog_object_id' => 'snack',
      'name' => 'Snack', 'quantity' => '1', 'total_money' => ['amount' => 2514, 'currency' => 'USD'],
    ]],
  ]];
  $single_movies = $reporter::build_reports($single_date, true, new $refund_snapshot());
  $single_live = $reporter::build_live_reports($single_date, true);
  $check(count($single_movies) === 1 && (float) $single_movies[0]['concessions_total'] === 0.0,
    'one movie row does not trigger the single-show fallback when a live show exists');
  $check(count($single_live) === 1 && (float) $single_live[0]['concessions_total'] === 25.14,
    'single-movie/live day allocates the sale only to the time-matched live row');

  $overlap_date = '2039-01-17';
  $square::$orders[$overlap_date] = [[
    'id' => 'private-overlap-show-concession-' . $token,
    'closed_at' => $overlap_date . 'T15:45:00-08:00',
    'line_items' => [[
      'uid' => 'private-overlap-line-' . $token, 'item_type' => 'ITEM', 'catalog_object_id' => 'snack',
      'name' => 'Snack', 'quantity' => '1', 'total_money' => ['amount' => 2514, 'currency' => 'USD'],
    ]],
  ]];
  $overlap_movies = $reporter::build_reports($overlap_date, true, new $refund_snapshot());
  $overlap_live = $reporter::build_live_reports($overlap_date, true);
  $check(count($overlap_movies) === 1 && (float) $overlap_movies[0]['concessions_total'] === 0.0,
    'overlapping movie preview does not steal a sale closer to the live showing');
  $check(count($overlap_live) === 1 && (float) $overlap_live[0]['concessions_total'] === 25.14,
    'combined candidate matching chooses the nearest show across movie and live rows');

  $rental_date = '2039-01-19';
  $store::$by_kind['rental'][$rental_date] = [[
    'id' => 917, 'report_date' => $rental_date, 'rental_title' => 'Private rental destination',
    'show_time' => '8:00 PM', 'concessions_total' => 0.0, 'is_locked' => 0,
  ]];
  $square::$orders[$rental_date] = [[
    'id' => 'private-movie-rental-preview-' . $token,
    'closed_at' => $rental_date . 'T19:30:00-08:00',
    'line_items' => [[
      'uid' => 'private-rental-preview-line-' . $token, 'item_type' => 'ITEM', 'catalog_object_id' => 'snack',
      'name' => 'Snack', 'quantity' => '1', 'total_money' => ['amount' => 1200, 'currency' => 'USD'],
    ]],
  ]];
  $rental_movies = $reporter::build_reports($rental_date, true, new $refund_snapshot());
  $check(count($rental_movies) === 1 && (float) $rental_movies[0]['concessions_total'] === 0.0,
    'movie preview excludes concessions that the complete candidate set assigns to a closer persisted rental row');

  $apply = new ReflectionMethod($reporter, 'apply_concessions_to_reports');
  $apply->setAccessible(true);
$group_orders = new ReflectionMethod($reporter, 'square_orders_grouped_by_local_date');
$group_orders->setAccessible(true);
$local_date_groups = $group_orders->invoke(null, [
  ['id' => 'range-boundary', 'closed_at' => '2039-01-22T08:30:00Z'],
  ['id' => 'same-local-day', 'closed_at' => '2039-01-22T20:00:00-08:00'],
], '2039-01-22', '2039-01-22');
$check(count($local_date_groups['2039-01-22'] ?? []) === 2,
  'range snapshot groups UTC and offset timestamps by the configured Pacific sale date');
$bad_range_rejected = false;
try { $group_orders->invoke(null, [['id' => 'bad-time', 'closed_at' => '2039-01-22 19:00:00']], '2039-01-22', '2039-01-22'); }
catch (Throwable $error) { $bad_range_rejected = true; }
$check($bad_range_rejected, 'range grouping fails closed on timestamps without an explicit timezone');
$precise_rental_date = '2039-01-22';
$precise_rental_start = new DateTimeImmutable($precise_rental_date . ' 16:30:00', new DateTimeZone('America/Los_Angeles'));
$precise_rental_end = new DateTimeImmutable($precise_rental_date . ' 19:30:00', new DateTimeZone('America/Los_Angeles'));
$square::$orders[$precise_rental_date] = [[
  'id' => 'private-booking-exact-window-' . $token,
  'closed_at' => $precise_rental_date . 'T19:02:00-08:00',
  'line_items' => array_map(static fn(int $index): array => [
    'uid' => 'private-booking-line-' . $index . '-' . $token, 'item_type' => 'ITEM', 'catalog_object_id' => 'snack',
    'name' => 'Snack', 'quantity' => '1', 'total_money' => ['amount' => $index === 0 ? 749 : 746, 'currency' => 'USD'],
  ], range(0, 18)),
]];
$precise_rental_rows = [
  100 => ['_entry_kind' => 'movie', '_entry_id' => 100, 'showing_id' => 0, 'show_time' => '7:30 PM',
    '_start_at' => new DateTimeImmutable($precise_rental_date . ' 19:30:00', new DateTimeZone('America/Los_Angeles')), 'concessions_total' => 0.0],
  200000300 => ['_entry_kind' => 'rental', '_entry_id' => 300, 'showing_id' => 0, 'show_time' => '4:30 PM',
    '_start_at' => $precise_rental_start, '_window_start' => $precise_rental_start, '_window_end' => $precise_rental_end, 'concessions_total' => 0.0],
];
$apply->invokeArgs(null, [&$precise_rental_rows, $precise_rental_date]);
$check((float) $precise_rental_rows[200000300]['concessions_total'] === 141.77
    && (float) $precise_rental_rows[100]['concessions_total'] === 0.0,
  'all 19 Square concession lines at 7:02 PM are attributed to the 4:30–7:30 PM private booking, not the nearby 7:30 PM show');
$date_fetches_before_override = $square::$date_fetches;
$override_rows = [200000300 => $precise_rental_rows[200000300]];
$apply->invokeArgs(null, [&$override_rows, $precise_rental_date, [], null, false, $square::$orders[$precise_rental_date]]);
$check((float) $override_rows[200000300]['concessions_total'] === 141.77 && $square::$date_fetches === $date_fetches_before_override,
  'provided range snapshot is reused for allocation without another per-day Square request');

  $legacy_date = '2039-01-18';
  $legacy_start = new DateTimeImmutable($legacy_date . ' 14:00:00', new DateTimeZone('America/Los_Angeles'));
  $square::$orders[$legacy_date] = [[
    'id' => 'private-unlinked-legacy-row-' . $token,
    'closed_at' => $legacy_date . 'T06:00:00-08:00',
    'line_items' => [[
      'uid' => 'private-legacy-line-' . $token, 'item_type' => 'ITEM', 'catalog_object_id' => 'snack',
      'name' => 'Snack', 'quantity' => '1', 'total_money' => ['amount' => 1234, 'currency' => 'USD'],
    ]],
  ]];
  $legacy_rows = [77 => ['showing_id' => 0, 'show_time' => '2:00 PM', '_start_at' => $legacy_start, 'concessions_total' => 0.0]];
  $apply->invokeArgs(null, [&$legacy_rows, $legacy_date]);
  $check((float) $legacy_rows[77]['concessions_total'] === 12.34,
    'one unlinked historical report row aliases the exact current showing instead of disabling the single-show fallback');

  $ambiguous_date = '2039-01-20';
  $ambiguous_start = new DateTimeImmutable($ambiguous_date . ' 14:00:00', new DateTimeZone('America/Los_Angeles'));
  $ambiguous_rows = [78 => ['showing_id' => 0, 'show_time' => '2:00 PM', '_start_at' => $ambiguous_start, 'concessions_total' => 0.0]];
  $square::$orders[$ambiguous_date] = [[
    'id' => 'private-ambiguous-legacy-alias-' . $token,
    'closed_at' => $ambiguous_date . 'T06:00:00-08:00',
    'line_items' => [[
      'uid' => 'private-ambiguous-legacy-line-' . $token, 'item_type' => 'ITEM', 'catalog_object_id' => 'snack',
      'name' => 'Snack', 'quantity' => '1', 'total_money' => ['amount' => 1000, 'currency' => 'USD'],
    ]],
  ]];
  $ambiguous_failed = false;
  try { $apply->invokeArgs(null, [&$ambiguous_rows, $ambiguous_date]); }
  catch (Throwable $error) { $ambiguous_failed = strpos($error->getMessage(), 'could not be matched') !== false; }
  $check($ambiguous_failed && (float) $ambiguous_rows[78]['concessions_total'] === 0.0,
    'an orphan row cannot alias two distinct same-time showings to enable an unsafe single-show fallback');

  $moved_date = '2039-01-21';
  $moved_ids = [];
  foreach ($showings as $index => $showing) if ($showing['date'] === $moved_date) $moved_ids[] = $post_ids[$index];
  $store::$by_kind['movie'][$moved_date] = [[
    'id' => 923, 'report_date' => $moved_date, 'showing_id' => $moved_ids[0], 'movie_title' => $showings[count($showings)-2]['title'],
    'show_time' => '8:00 PM', 'concessions_total' => 0.0, 'is_locked' => 0,
  ]];
  $square::$orders[$moved_date] = [[
    'id' => 'private-moved-show-time-' . $token, 'closed_at' => $moved_date . 'T14:00:00-08:00',
    'line_items' => [['uid' => 'private-moved-line-' . $token, 'item_type' => 'ITEM', 'catalog_object_id' => 'snack',
      'name' => 'Snack', 'quantity' => '1', 'total_money' => ['amount' => 800, 'currency' => 'USD']]],
  ]];
  $moved_movies = $reporter::build_reports($moved_date, true, new $refund_snapshot());
  $moved_live = $reporter::build_live_reports($moved_date, true);
  $check(count($moved_movies) === 1 && (float) $moved_movies[0]['concessions_total'] === 8.0
      && count($moved_live) === 1 && (float) $moved_live[0]['concessions_total'] === 0.0,
    'preview matching follows the current showing time instead of a stale persisted time');

  $missing_destination = [];
  $missing_destination_failed = false;
  try { $apply->invokeArgs(null, [&$missing_destination, $date]); }
  catch (Throwable $error) { $missing_destination_failed = strpos($error->getMessage(), 'could not be matched') !== false; }
  $check($missing_destination_failed && count($store::$unmatched[$date] ?? []) === 1,
    'final day-wide allocation queues a valid sale when its report destination row is missing instead of silently deferring it');

  $outside_date = '2039-01-15';
  $square::$orders[$outside_date] = [[
    'id' => 'private-outside-window-' . $token,
    'closed_at' => $outside_date . 'T02:00:00-08:00',
    'line_items' => [[
      'uid' => 'private-outside-line-' . $token, 'item_type' => 'ITEM', 'catalog_object_id' => 'snack',
      'name' => 'Snack', 'quantity' => '1', 'total_money' => ['amount' => 100, 'currency' => 'USD'],
    ]],
  ]];
  $outside_failed = false;
  try { $reporter::build_reports($outside_date, true, new $refund_snapshot()); }
  catch (Throwable $error) { $outside_failed = strpos($error->getMessage(), 'could not be matched') !== false; }
  $check($outside_failed && count($store::$unmatched[$outside_date] ?? []) === 1,
    'a sale outside every show window is queued and fails closed rather than being assigned to the wrong preview');

  $missing_time_date = '2039-01-15';
  $square::$orders[$missing_time_date] = [[
    'id' => 'private-missing-timestamp-' . $token,
    'line_items' => [[
      'uid' => 'private-no-time-line-' . $token, 'item_type' => 'ITEM', 'catalog_object_id' => 'snack',
      'name' => 'Snack', 'quantity' => '1', 'total_money' => ['amount' => 100, 'currency' => 'USD'],
    ]],
  ]];
  $missing_time_failed = false;
  try { $reporter::build_reports($missing_time_date, true, new $refund_snapshot()); }
  catch (Throwable $error) { $missing_time_failed = strpos($error->getMessage(), 'could not be matched') !== false; }
  $check($missing_time_failed && count($store::$unmatched[$missing_time_date] ?? []) === 1,
    'an eligible concession line with no usable Square timestamp is queued and fails closed');

  echo "Passed private mixed movie/live Grosses builder checks; only disposable draft showings and an in-memory Square fixture were used.\n";
} finally {
  foreach ($post_ids as $id) {
    wp_delete_post($id, true);
    if (get_post($id) !== null) throw new RuntimeException('Private showing fixture cleanup failed.');
  }
}
