<?php
namespace RoxyGrosses {
  final class Settings {
    public static function get_report_timezone(): string { return 'America/Los_Angeles'; }
    public static function get(string $key, $default = '') { return $default; }
    public static function set_status(array $status): void {}
  }
  final class Square {
    public static int $depth = 0;
    public static array $cache = [];
    public static array $calls = [];
    public static array $range_calls = [];
    public static array $orders = [];
    public static function with_sale_snapshot(callable $operation) {
      $outer = self::$depth === 0;
      if ($outer) self::$cache = [];
      self::$depth++;
      try { return $operation(); }
      finally { self::$depth--; if ($outer) self::$cache = []; }
    }
    public static function fetch_orders_for_date(string $date): array {
      if (self::$depth < 1) throw new \RuntimeException('Square read escaped its operation snapshot.');
      if (!array_key_exists($date, self::$cache)) {
        self::$calls[] = $date;
        self::$cache[$date] = self::$orders[$date] ?? [];
      }
      return self::$cache[$date];
    }
    public static function fetch_orders_for_range(string $from, string $to): array {
      if (self::$depth < 1) throw new \RuntimeException('Square range read escaped its operation snapshot.');
      self::$range_calls[] = [$from, $to];
      $orders = [];
      foreach (self::$orders as $date => $date_orders) if ($date >= $from && $date <= $to) $orders = array_merge($orders, $date_orders);
      return $orders;
    }
    public static function concession_reporting_categories(array $ids): array { return ['snack' => true]; }
    public static function is_in_store_purchase_item(string $id, array $map = []): bool { return $id === 'snack'; }
  }
  final class Store {
    public static array $entry_calls = [];
    public static array $movie = [];
    public static array $live = [];
    public static array $rental = [];
    public static array $updates = [];
    public static array $allocation_batches = [];
    public static array $logs = [];
    public static bool $fail_concession_read = false;
    public static array $unmatched_concessions = [];
    public static bool $fail_unmatched_write = false;
    public static bool $fail_update = false;
    public static int $fail_update_number = 0;
    public static int $report_id = 100;
    public static function with_concession_allocation_lock(string $date, callable $operation) { return $operation(); }
    public static function with_concession_allocation_transaction(callable $operation) {
      $snapshot = [self::$movie,self::$live,self::$rental];
      try { return $operation(); }
      catch (\Throwable $error) { [self::$movie,self::$live,self::$rental] = $snapshot; throw $error; }
    }
    public static function upsert_entries(array $rows, string $mode): array { self::$entry_calls[] = $rows; return ['created'=>count($rows),'updated'=>0,'skipped'=>0]; }
    public static function upsert_live_entries(array $rows, string $mode): array { return ['created'=>count($rows),'updated'=>0,'skipped'=>0]; }
    public static function upsert_history_rows(...$args): array { return []; }
    public static function insert_log(...$args): int { self::$logs[] = $args; return 1; }
    public static function create_report(...$args): int { return self::$report_id; }
    public static function list_entries(array $filters = [], int $limit = 1000, int $offset = 0): array { return self::$movie[$filters['day'] ?? ''] ?? []; }
    public static function list_live_entries(array $filters = [], int $limit = 1000, int $offset = 0): array { return self::$live[$filters['day'] ?? ''] ?? []; }
    public static function list_rental_entries(array $filters = [], int $limit = 1000, int $offset = 0): array { return self::$rental[$filters['day'] ?? ''] ?? []; }
    public static function list_all_entries_for_rebalance(string $dataset, string $date): array {
      $rows = match ($dataset) { 'movie' => self::$movie[$date] ?? [], 'live' => self::$live[$date] ?? [], 'rental' => self::$rental[$date] ?? [], default => throw new \InvalidArgumentException('bad fixture dataset') };
      $all = []; $cursor = 0;
      for ($page = 0; $page < 200; $page++) {
        $batch = array_slice($rows, $cursor, 500);
        if (!$batch) return $all;
        $all = array_merge($all, $batch);
        $cursor += count($batch);
      }
      throw new \RuntimeException('fixture pagination safety exceeded');
    }
    private static function save(string $kind, int $id, array $data): bool {
      self::$updates[] = [$kind, $id, $data];
      if (self::$fail_update || (self::$fail_update_number > 0 && count(self::$updates) === self::$fail_update_number)) return false;
      if ($kind === 'movie') foreach (self::$movie as &$rows) foreach ($rows as &$row) if ((int) ($row['id'] ?? 0) === $id) $row = array_merge($row, $data);
      if ($kind === 'live') foreach (self::$live as &$rows) foreach ($rows as &$row) if ((int) ($row['id'] ?? 0) === $id) $row = array_merge($row, $data);
      if ($kind === 'rental') foreach (self::$rental as &$rows) foreach ($rows as &$row) if ((int) ($row['id'] ?? 0) === $id) $row = array_merge($row, $data);
      return true;
    }
    public static function update_entry(int $id, array $data, bool $manual = false): bool { return self::save('movie', $id, $data); }
    public static function update_live_entry(int $id, array $data, bool $manual = false): bool { return self::save('live', $id, $data); }
    public static function update_rental_entry(int $id, array $data, bool $manual = false): bool { return self::save('rental', $id, $data); }
    public static function update_concessions_allocation_batch(string $kind, array $allocations): bool {
      foreach (array_chunk($allocations, 200, true) as $batch) {
        self::$allocation_batches[] = [$kind, count($batch)];
        foreach ($batch as $id => $cents) {
          if (!self::save($kind, (int) $id, ['concessions_total' => (int) $cents / 100])) return false;
        }
      }
      return true;
    }
    public static function concessions_by_date(string $from, string $to): array {
      if (self::$fail_concession_read) throw new \RuntimeException('fixture concession read failure');
      $totals = [];
      foreach (['movie'=>self::$movie,'live'=>self::$live,'rental'=>self::$rental] as $kind=>$days) {
        foreach ($days as $date=>$rows) {
          if ($date < $from || $date > $to) continue;
          foreach ($rows as $row) $totals[$date][$kind] = ($totals[$date][$kind] ?? 0.0) + (float) ($row['concessions_total'] ?? 0);
        }
      }
      foreach ($totals as $date=>$row) {
        $totals[$date]['assigned_total'] = round((float) ($row['movie'] ?? 0) + (float) ($row['live'] ?? 0) + (float) ($row['rental'] ?? 0), 2);
      }
      return $totals;
    }
    public static function record_unmatched_concession_lines(string $date, array $lines): int {
      if (self::$fail_unmatched_write) throw new \RuntimeException('fixture queue write failure');
      self::$unmatched_concessions[$date] = $lines; return count($lines);
    }
  }
  final class RefundSnapshot {}
  final class Fixture {
    public static bool $zero_refund = false;
    public static bool $no_sales = false;
    public static function movie_rows(string $date): array {
      Square::fetch_orders_for_date($date);
      Square::fetch_orders_for_date($date);
      $prior = (new \DateTimeImmutable($date, new \DateTimeZone('America/Los_Angeles')))->modify('-1 day')->format('Y-m-d');
      Square::fetch_orders_for_date($prior);
      $rows = [
        ['report_date'=>$prior,'film_title'=>'Prior','show_time'=>'7:00 PM','showing_id'=>1,'general_qty'=>1,'total_tickets'=>1,'gross_total'=>12.0],
        ['report_date'=>$date,'film_title'=>'Requested','show_time'=>'7:00 PM','showing_id'=>2,'general_qty'=>1,'total_tickets'=>1,'gross_total'=>12.0],
      ];
      if (self::$no_sales) return [];
      if (self::$zero_refund) foreach ($rows as &$row) { $row['general_qty']=0; $row['total_tickets']=0; $row['gross_total']=0.0; $row['refund_adjusted']=true; }
      return $rows;
    }
    public static function live_rows(string $date): array {
      Square::fetch_orders_for_date($date);
      Square::fetch_orders_for_date($date);
      return [['report_date'=>$date,'show_title'=>'Live','show_time'=>'7:00 PM','showing_id'=>4,'presale_qty'=>1,'total_tickets'=>1,'gross_total'=>10.0]];
    }
  }
}

namespace {
  if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  function wp_date($format, $timestamp = null, $timezone = null) { return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?: new DateTimeZone('UTC'))->format($format); }
  function wp_json_encode($value) { return json_encode($value); }
  function sanitize_text_field($value) { return trim((string) $value); }
  function get_option($key, $default = false) { return ''; }
  function sanitize_email($value) { return (string)$value; }
  function post_type_exists($type) { return false; }
  function wp_mail(...$args) { throw new RuntimeException('Network/mail is forbidden in this fixture.'); }

  $candidate = $argv[1] ?? (dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-reporter.php');
  if (!is_file($candidate)) { fwrite(STDERR, "Missing Reporter candidate: {$candidate}\n"); exit(2); }
  $source = file_get_contents($candidate);
  $movie_call = '$reports = self::build_reports($report_date);';
  $live_call = '$rows = self::build_live_reports($report_date);';
  if (substr_count($source, $movie_call) !== 3 || substr_count($source, $live_call) !== 1) throw new RuntimeException('Reporter builder boundaries changed; fixture refused an unsafe rewrite.');
  $source = str_replace($movie_call, '$reports = \\RoxyGrosses\\Fixture::movie_rows($report_date);', $source);
  $source = str_replace($live_call, '$rows = \\RoxyGrosses\\Fixture::live_rows($report_date);', $source);
  $source = preg_replace('/^<\?php\s*/', '', $source, 1);
  eval($source);

  $checks = 0;
  $check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo "PASS: {$message}\n";
  };
  $call = static function (string $method, array $args = []) {
    $reflection = new ReflectionMethod(\RoxyGrosses\Reporter::class, $method);
    return $reflection->invokeArgs(null, $args);
  };

  $movie = \RoxyGrosses\Reporter::pull_into_database('2038-05-02');
  $check(!empty($movie['success']) && \RoxyGrosses\Square::$depth === 0 && \RoxyGrosses\Square::$cache === [], 'manual movie pull snapshot clears after completion');
  $check(\RoxyGrosses\Square::$calls === ['2038-05-02','2038-05-01'], 'manual movie pull deduplicates repeated reads and shares target/lookback snapshot');
  \RoxyGrosses\Square::$calls = [];
  $live = \RoxyGrosses\Reporter::pull_live_into_database('2038-05-02');
  $check(!empty($live['success']) && \RoxyGrosses\Square::$depth === 0 && \RoxyGrosses\Square::$cache === [], 'manual live pull snapshot clears after completion');
  $check(\RoxyGrosses\Square::$calls === ['2038-05-02'], 'manual live pull repeated date reads share one snapshot');
  \RoxyGrosses\Square::$calls = [];
  $draft = \RoxyGrosses\Reporter::save_report_draft('2038-05-02');
  $check(!empty($draft['success']) && \RoxyGrosses\Square::$depth === 0 && \RoxyGrosses\Square::$cache === [], 'draft snapshot clears after completion');
  \RoxyGrosses\Square::$calls = [];
  \RoxyGrosses\Square::$range_calls = [];
  \RoxyGrosses\Square::$orders = [
    '2038-05-01' => [['id'=>'range-a','closed_at'=>'2038-05-01T18:00:00-07:00','line_items'=>[['catalog_object_id'=>'snack','total_money'=>['amount'=>1001,'currency'=>'USD']]]]],
    '2038-05-02' => [['id'=>'range-b','closed_at'=>'2038-05-03T01:00:00Z','line_items'=>[['catalog_object_id'=>'snack','total_money'=>['amount'=>555,'currency'=>'USD']]]]],
  ];
  $range_rows = \RoxyGrosses\Reporter::reconciliation_rows('2038-05-01', '2038-05-02');
  $check(\RoxyGrosses\Square::$depth === 0 && \RoxyGrosses\Square::$cache === [] && \RoxyGrosses\Square::$range_calls === [['2038-05-01','2038-05-02']] && \RoxyGrosses\Square::$calls === [], 'reconciliation uses one snapshot-bounded Square range request instead of a request per day');
  $range_by_date = [];
  foreach ($range_rows as $row) $range_by_date[$row['date']] = $row['square_total'];
  $check(($range_by_date['2038-05-01'] ?? null) === 10.01 && ($range_by_date['2038-05-02'] ?? null) === 5.55, 'range orders group by report-timezone close date and preserve exact cents');

  // Automatic refresh may carry same-title lookback rows. Diagnostics must
  // compare only the requested day to that day's Square total, never add the
  // previous day's concessions into the current-day comparison.
  $target_date = '2038-05-11';
  $prior_date = '2038-05-10';
  \RoxyGrosses\Square::$orders = [
    $prior_date => [['line_items'=>[['catalog_object_id'=>'snack','total_money'=>['amount'=>27865,'currency'=>'USD']]]]],
    $target_date => [['line_items'=>[['catalog_object_id'=>'snack','total_money'=>['amount'=>21759,'currency'=>'USD']]]]],
  ];
  \RoxyGrosses\Store::$movie = [
    $prior_date => [['id'=>9001,'concessions_total'=>278.65]],
    $target_date => [['id'=>9002,'concessions_total'=>217.59]],
  ];
  \RoxyGrosses\Store::$live = [];
  \RoxyGrosses\Store::$rental = [];
  \RoxyGrosses\Store::$logs = [];
  \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('log_sync_anomalies', [$target_date, 'closed-day-refresh', [
    ['report_date'=>$prior_date,'film_title'=>'Same Movie','show_time'=>'7:00 PM','total_tickets'=>25,'concessions_total'=>278.65],
    ['report_date'=>$target_date,'film_title'=>'Same Movie','show_time'=>'7:00 PM','total_tickets'=>27,'concessions_total'=>217.59],
  ], []]));
  $mismatch_logs = array_values(array_filter(\RoxyGrosses\Store::$logs, static fn(array $log): bool => ($log[0] ?? '') === 'anomaly' && (($log[6]['anomaly_type'] ?? '') === 'concessions_mismatch')));
  $check($mismatch_logs === [], 'same-title prior-day concessions do not create a false current-day mismatch');

  \RoxyGrosses\Store::$movie[$target_date] = [['id'=>9002,'concessions_total'=>100.00]];
  \RoxyGrosses\Store::$live = [$target_date => [['id'=>9003,'concessions_total'=>50.00]]];
  \RoxyGrosses\Store::$rental = [$target_date => [['id'=>9004,'concessions_total'=>50.00]]];
  \RoxyGrosses\Store::$logs = [];
  \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('log_sync_anomalies', [$target_date, 'closed-day-refresh', [
    ['report_date'=>$prior_date,'film_title'=>'Same Movie','show_time'=>'7:00 PM','total_tickets'=>25,'concessions_total'=>278.65],
    ['report_date'=>$target_date,'film_title'=>'Same Movie','show_time'=>'7:00 PM','total_tickets'=>27,'concessions_total'=>100.00],
  ], [['report_date'=>$target_date,'show_title'=>'Private rental companion','show_time'=>'9:00 PM','total_tickets'=>1,'concessions_total'=>50.00]]]));
  $mismatch_logs = array_values(array_filter(\RoxyGrosses\Store::$logs, static fn(array $log): bool => ($log[0] ?? '') === 'anomaly' && (($log[6]['anomaly_type'] ?? '') === 'concessions_mismatch')));
  $check(count($mismatch_logs) === 1 && ($mismatch_logs[0][6]['square_total'] ?? null) === 217.59
    && ($mismatch_logs[0][6]['assigned_total'] ?? null) === 200.0
    && ($mismatch_logs[0][6]['difference'] ?? null) === 17.59,
    'real current-day mismatch reports persisted movie/live/rental totals despite lookback rows');

  \RoxyGrosses\Store::$fail_concession_read = true;
  \RoxyGrosses\Store::$logs = [];
  \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('log_sync_anomalies', [$target_date, 'closed-day-refresh', [
    ['report_date'=>$target_date,'film_title'=>'Same Movie','show_time'=>'7:00 PM','total_tickets'=>27,'concessions_total'=>217.59],
  ], []]));
  $unavailable_logs = array_values(array_filter(\RoxyGrosses\Store::$logs, static fn(array $log): bool => ($log[0] ?? '') === 'anomaly' && (($log[6]['anomaly_type'] ?? '') === 'concessions_totals_unavailable')));
  $check(count($unavailable_logs) === 1 && !array_filter(\RoxyGrosses\Store::$logs, static fn(array $log): bool => ($log[0] ?? '') === 'anomaly' && (($log[6]['anomaly_type'] ?? '') === 'concessions_mismatch')),
    'unavailable saved totals produce an explicit review alert, not a fabricated mismatch');
  \RoxyGrosses\Store::$fail_concession_read = false;

  foreach (['tomorrow', '2038-05-01T25:61:00Z', '2038-04-30T18:00:00-07:00'] as $bad_closed_at) {
    \RoxyGrosses\Square::$orders = ['2038-05-01' => [['id'=>'bad-range','closed_at'=>$bad_closed_at,'line_items'=>[]]]];
    $failed_closed = false;
    try { \RoxyGrosses\Reporter::reconciliation_rows('2038-05-01', '2038-05-02'); }
    catch (RuntimeException $error) { $failed_closed = true; }
    $check($failed_closed && \RoxyGrosses\Square::$depth === 0, 'malformed or out-of-range close timestamp fails closed and clears snapshot');
  }
  \RoxyGrosses\Square::$orders = [];
  $prior_calls = \RoxyGrosses\Square::$calls;
  $invalid_date_rows = \RoxyGrosses\Reporter::reconciliation_rows('2038-02-30', '2038-03-01');
  $check($invalid_date_rows === [] && \RoxyGrosses\Square::$calls === $prior_calls, 'impossible reconciliation dates are rejected before Square reads');

  \RoxyGrosses\Fixture::$zero_refund = true;
  $zero = \RoxyGrosses\Reporter::pull_into_database('2038-05-02');
  $saved_zero = end(\RoxyGrosses\Store::$entry_calls);
  $check(!empty($zero['success']) && count($saved_zero)===2 && $saved_zero[0]['total_tickets']===0, 'manual full-refund pull persists zero-ticket corrected rows');
  \RoxyGrosses\Fixture::$zero_refund = false;
  \RoxyGrosses\Fixture::$no_sales = true;
  $entries_before = count(\RoxyGrosses\Store::$entry_calls);
  $empty = \RoxyGrosses\Reporter::pull_into_database('2038-05-02');
  $check(empty($empty['success']) && count(\RoxyGrosses\Store::$entry_calls)===$entries_before, 'empty non-refund pull still fails before entry writes');
  \RoxyGrosses\Fixture::$no_sales = false;

  // Exercise the real allocator over movie, live, and rental rows on both the
  // requested and lookback dates. Each dated Square total must be conserved to cents.
  $dates = ['2038-05-01','2038-05-02'];
  foreach ($dates as $index => $date) {
    $id = 10 + $index;
    \RoxyGrosses\Store::$movie[$date] = [['id'=>$id,'show_time'=>'7:00 PM','general_qty'=>2,'discount_qty'=>0,'group_qty'=>0,'is_locked'=>0,'concessions_total'=>0]];
    \RoxyGrosses\Store::$live[$date] = [['id'=>20+$index,'show_time'=>'7:00 PM','presale_qty'=>1,'online_qty'=>1,'door_qty'=>0,'group_sub_qty'=>0,'is_locked'=>0,'concessions_total'=>0]];
    \RoxyGrosses\Store::$rental[$date] = [['id'=>30+$index,'show_time'=>'7:00 PM','is_locked'=>0,'concessions_total'=>0]];
    \RoxyGrosses\Square::$orders[$date] = [[
      'closed_at' => $date . 'T19:00:00-07:00',
      'line_items' => [['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1001,'currency'=>'USD']]],
    ]];
  }
  \RoxyGrosses\Store::$updates = [];
  \RoxyGrosses\Store::$allocation_batches = [];
  \RoxyGrosses\Square::$calls = [];
  \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_report_dates', [[['report_date'=>'2038-05-01'],['report_date'=>'2038-05-01'],['report_date'=>'2038-05-02']], '2038-05-02']));
  $check(\RoxyGrosses\Square::$calls === $dates, 'affected report dates are unique, sorted, and include the requested date');
  foreach ($dates as $date) {
    $cents = 0;
    foreach ([\RoxyGrosses\Store::$movie,\RoxyGrosses\Store::$live,\RoxyGrosses\Store::$rental] as $dataset) $cents += (int) round((float) ($dataset[$date][0]['concessions_total'] ?? 0) * 100);
    $check($cents === 1001, "movie/live/rental allocation conserves all 1001 cents on {$date}");
  }

  // A single-show day is unambiguous even when concessions are rung well
  // before showtime; multi-show dates continue to require the time window.
  $single_show_date = '2038-05-07';
  \RoxyGrosses\Store::$movie[$single_show_date] = [['id'=>8001,'show_time'=>'7:30 PM','general_qty'=>0,'discount_qty'=>0,'group_qty'=>0,'is_locked'=>0,'concessions_total'=>0]];
  \RoxyGrosses\Square::$orders[$single_show_date] = [[
    'id' => 'single-show-morning-sale',
    'closed_at' => $single_show_date . 'T10:12:00-07:00',
    'line_items' => [['uid'=>'single-show-morning-line','item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>2514,'currency'=>'USD']]],
  ]];
  \RoxyGrosses\Store::$updates = [];
  \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', [$single_show_date]));
  $single_show_concessions = (float) (\RoxyGrosses\Store::$movie[$single_show_date][0]['concessions_total'] ?? 0);
  $check($single_show_concessions === 25.14 && !isset(\RoxyGrosses\Store::$unmatched_concessions[$single_show_date]), 'single-show date attributes morning concessions to its only report row');

  // The production allocator must include rows past the old 1,000-row cap.
  $large_date = '2038-05-03';
  for ($i = 1; $i <= 1001; $i++) \RoxyGrosses\Store::$movie[$large_date][] = [
    'id' => 5000 + $i, 'show_time' => '7:00 PM', 'general_qty' => 1, 'discount_qty' => 0,
    'group_qty' => 0, 'is_locked' => 0, 'concessions_total' => 0,
  ];
  \RoxyGrosses\Square::$orders[$large_date] = [[
    'closed_at' => $large_date . 'T19:00:00-07:00',
    'line_items' => [['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1001,'currency'=>'USD']]],
  ]];
  \RoxyGrosses\Store::$updates = [];
  \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', [$large_date]));
  $allocated_cents = array_sum(array_map(static fn(array $row): int => (int) round((float) ($row['concessions_total'] ?? 0) * 100), \RoxyGrosses\Store::$movie[$large_date]));
  $check(count(\RoxyGrosses\Store::$updates) === 1001 && count(\RoxyGrosses\Store::$allocation_batches) === 6 && $allocated_cents === 1001, 'rebalance conserves every row beyond the former 1,000-row cap using six bounded write batches');

  // Eligible sales outside every report row's show-time window must be surfaced
  // before writes instead of silently disappearing from daily reconciliation.
  $unmatched_date = '2038-05-04';
  \RoxyGrosses\Store::$movie[$unmatched_date] = [
    ['id'=>7001,'show_time'=>'7:00 PM','general_qty'=>1,'discount_qty'=>0,'group_qty'=>0,'is_locked'=>0,'concessions_total'=>0],
    ['id'=>7003,'show_time'=>'9:00 PM','general_qty'=>1,'discount_qty'=>0,'group_qty'=>0,'is_locked'=>0,'concessions_total'=>0],
  ];
  \RoxyGrosses\Square::$orders[$unmatched_date] = [[
    'id' => 'unmatched-order-fixture',
    'closed_at' => $unmatched_date . 'T23:30:00-07:00',
    'line_items' => [['uid'=>'unmatched-line-fixture','item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1250,'currency'=>'USD']]],
  ]];
  $before_unmatched_writes = count(\RoxyGrosses\Store::$updates);
  $unmatched_failed = false;
  try { \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', [$unmatched_date])); }
  catch (Throwable $error) { $unmatched_failed = str_contains($error->getMessage(), '1 eligible Square concession line(s), totaling $12.50') && str_contains($error->getMessage(), 'review queue'); }
  $queued = \RoxyGrosses\Store::$unmatched_concessions[$unmatched_date] ?? [];
  $check($unmatched_failed && count($queued) === 1 && $queued[0]['amount_cents'] === 1250 && $queued[0]['square_order_id'] === 'unmatched-order-fixture' && $queued[0]['square_line_uid'] === 'unmatched-line-fixture' && $queued[0]['reason'] === 'outside_showtime_window (2 dated rows: 7:00 PM, 9:00 PM)' && preg_match('/^[a-f0-9]{64}$/D', $queued[0]['fingerprint']) === 1 && count(\RoxyGrosses\Store::$updates) === $before_unmatched_writes && (float) \RoxyGrosses\Store::$movie[$unmatched_date][0]['concessions_total'] === 0.0, 'outside-window line records the candidate show times and stops before allocation writes');

  $no_rows_date = '2038-05-06';
  \RoxyGrosses\Square::$orders[$no_rows_date] = [[
    'id' => 'unmatched-order-no-rows', 'closed_at' => $no_rows_date . 'T23:30:00-07:00',
    'line_items' => [['uid'=>'unmatched-line-no-rows','item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>250,'currency'=>'USD']]],
  ]];
  $no_rows_detected = false;
  try { \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', [$no_rows_date])); }
  catch (Throwable $error) { $no_rows_detected = str_contains($error->getMessage(), '1 eligible Square concession line(s), totaling $2.50'); }
  $no_rows_queue = \RoxyGrosses\Store::$unmatched_concessions[$no_rows_date] ?? [];
  $check($no_rows_detected && count($no_rows_queue) === 1 && $no_rows_queue[0]['reason'] === 'no_report_rows', 'eligible Square concession is queued with a distinct reason when no report rows exist');

  $missing_time_date = '2038-05-08';
  \RoxyGrosses\Store::$movie[$missing_time_date] = [['id'=>7010,'show_time'=>'','general_qty'=>1,'discount_qty'=>0,'group_qty'=>0,'is_locked'=>0,'concessions_total'=>0]];
  \RoxyGrosses\Square::$orders[$missing_time_date] = [[
    'id'=>'unmatched-order-missing-show-time','closed_at'=>$missing_time_date.'T20:00:00-07:00',
    'line_items'=>[['uid'=>'unmatched-line-missing-show-time','item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>300,'currency'=>'USD']]],
  ]];
  try { \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', [$missing_time_date])); }
  catch (Throwable $error) {}
  $missing_time_queue = \RoxyGrosses\Store::$unmatched_concessions[$missing_time_date] ?? [];
  $check(count($missing_time_queue) === 1 && $missing_time_queue[0]['reason'] === 'report_rows_missing_times', 'unmatched queue distinguishes report rows with missing show times');

  $missing_order_time_date = '2038-05-09';
  \RoxyGrosses\Store::$movie[$missing_order_time_date] = [['id'=>7011,'show_time'=>'7:00 PM','general_qty'=>1,'discount_qty'=>0,'group_qty'=>0,'is_locked'=>0,'concessions_total'=>0]];
  \RoxyGrosses\Square::$orders[$missing_order_time_date] = [[
    'id'=>'unmatched-order-missing-close-time',
    'line_items'=>[['uid'=>'unmatched-line-missing-close-time','item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>400,'currency'=>'USD']]],
  ]];
  try { \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', [$missing_order_time_date])); }
  catch (Throwable $error) {}
  $missing_order_time_queue = \RoxyGrosses\Store::$unmatched_concessions[$missing_order_time_date] ?? [];
  $check(count($missing_order_time_queue) === 1 && $missing_order_time_queue[0]['reason'] === 'missing_order_timestamp (1 dated rows: 7:00 PM)', 'unmatched queue distinguishes Square orders without a usable close time');

  $queue_failure_date = '2038-05-05';
  \RoxyGrosses\Store::$movie[$queue_failure_date] = [
    ['id'=>7002,'show_time'=>'7:00 PM','general_qty'=>1,'discount_qty'=>0,'group_qty'=>0,'is_locked'=>0,'concessions_total'=>0],
    ['id'=>7004,'show_time'=>'9:00 PM','general_qty'=>1,'discount_qty'=>0,'group_qty'=>0,'is_locked'=>0,'concessions_total'=>0],
  ];
  \RoxyGrosses\Square::$orders[$queue_failure_date] = [[
    'id' => 'unmatched-order-queue-failure', 'closed_at' => $queue_failure_date . 'T23:30:00-07:00',
    'line_items' => [['uid'=>'unmatched-line-queue-failure','item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1250,'currency'=>'USD']]],
  ]];
  \RoxyGrosses\Store::$fail_unmatched_write = true;
  $before_queue_failure_writes = count(\RoxyGrosses\Store::$updates);
  $queue_failure_aborted = false;
  try { \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', [$queue_failure_date])); }
  catch (Throwable $error) { $queue_failure_aborted = str_contains($error->getMessage(), 'fixture queue write failure'); }
  \RoxyGrosses\Store::$fail_unmatched_write = false;
  $check($queue_failure_aborted && count(\RoxyGrosses\Store::$updates) === $before_queue_failure_writes && (float) \RoxyGrosses\Store::$movie[$queue_failure_date][0]['concessions_total'] === 0.0, 'queue persistence failure aborts before any allocation write');

  // A storage failure on an unlocked row must abort the allocation explicitly;
  // a locked row remains protected and is skipped rather than treated as failure.
  $state_before_rollback = [\RoxyGrosses\Store::$movie,\RoxyGrosses\Store::$live,\RoxyGrosses\Store::$rental];
  \RoxyGrosses\Store::$fail_update_number = count(\RoxyGrosses\Store::$updates) + 2;
  $failed = false;
  try { \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', ['2038-05-01'])); }
  catch (Throwable $error) { $failed = str_contains($error->getMessage(), 'Could not save the concessions allocation'); }
  \RoxyGrosses\Store::$fail_update_number = 0;
  $check($failed && [\RoxyGrosses\Store::$movie,\RoxyGrosses\Store::$live,\RoxyGrosses\Store::$rental] === $state_before_rollback, 'a later unlocked Store update failure rolls back earlier row mutations in the allocation');
  \RoxyGrosses\Store::$movie['2038-05-01'][0]['is_locked'] = 1;
  $locked_cents = (int) round((float) \RoxyGrosses\Store::$movie['2038-05-01'][0]['concessions_total'] * 100);
  $locked = \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', ['2038-05-01']));
  $saved_day_cents = 0;
  foreach ([\RoxyGrosses\Store::$movie,\RoxyGrosses\Store::$live,\RoxyGrosses\Store::$rental] as $dataset) $saved_day_cents += (int) round((float) ($dataset['2038-05-01'][0]['concessions_total'] ?? 0) * 100);
  $check($locked['updated'] === 2 && (int) round((float) \RoxyGrosses\Store::$movie['2038-05-01'][0]['concessions_total'] * 100) === $locked_cents && $saved_day_cents === 1001, 'locked movie amount stays fixed and remaining allocation is conserved across editable rows');
  $mixed_locked_date = '2038-05-12';
  \RoxyGrosses\Store::$movie[$mixed_locked_date] = [
    ['id'=>7101,'show_time'=>'7:00 PM','general_qty'=>1,'discount_qty'=>0,'group_qty'=>0,'is_locked'=>1,'concessions_total'=>5.00],
    ['id'=>7102,'show_time'=>'9:00 PM','general_qty'=>1,'discount_qty'=>0,'group_qty'=>0,'is_locked'=>0,'concessions_total'=>0.00],
  ];
  \RoxyGrosses\Square::$orders[$mixed_locked_date] = [
    ['id'=>'locked-show-order','closed_at'=>$mixed_locked_date.'T19:00:00-07:00','line_items'=>[['uid'=>'locked-show-line','item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>500,'currency'=>'USD']]]],
    ['id'=>'editable-show-order','closed_at'=>$mixed_locked_date.'T21:00:00-07:00','line_items'=>[['uid'=>'editable-show-line','item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>500,'currency'=>'USD']]]],
  ];
  $mixed_locked = \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', [$mixed_locked_date]));
  $mixed_locked_total = (int) round((float) \RoxyGrosses\Store::$movie[$mixed_locked_date][0]['concessions_total'] * 100)
    + (int) round((float) \RoxyGrosses\Store::$movie[$mixed_locked_date][1]['concessions_total'] * 100);
  $check($mixed_locked['updated'] === 1 && (float) \RoxyGrosses\Store::$movie[$mixed_locked_date][0]['concessions_total'] === 5.0
    && (float) \RoxyGrosses\Store::$movie[$mixed_locked_date][1]['concessions_total'] === 5.0 && $mixed_locked_total === 1000,
    'locked-only sales remain fixed while editable shows receive the remaining daily cents');

  \RoxyGrosses\Store::$movie['2038-05-01'][0]['concessions_total'] = 20.0;
  $updates_before_mismatch = count(\RoxyGrosses\Store::$updates);
  $mismatch_failed = false;
  try { \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', ['2038-05-01'])); }
  catch (Throwable $error) { $mismatch_failed = str_contains($error->getMessage(), 'cannot be reconciled to their matching Square lines'); }
  $check($mismatch_failed && count(\RoxyGrosses\Store::$updates) === $updates_before_mismatch, 'locked amount above matching Square total fails before any allocation write');
  \RoxyGrosses\Store::$movie['2038-05-01'][0]['concessions_total'] = 5.01;
  \RoxyGrosses\Store::$live['2038-05-01'][0]['concessions_total'] = 5.0;
  \RoxyGrosses\Store::$live['2038-05-01'][0]['is_locked'] = 1;
  \RoxyGrosses\Store::$rental['2038-05-01'][0]['is_locked'] = 1;
  $all_locked_updates = count(\RoxyGrosses\Store::$updates);
  $all_locked = \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', ['2038-05-01']));
  $check($all_locked['updated'] === 0 && count(\RoxyGrosses\Store::$updates) === $all_locked_updates && (int) round($all_locked['concessions_total'] * 100) === 1001, 'fully locked rows matching the source total remain untouched and count as reconciled');

  echo "OK: {$checks} reporter snapshot/rebalance checks\n";
}
