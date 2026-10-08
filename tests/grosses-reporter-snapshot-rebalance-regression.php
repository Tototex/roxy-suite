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
    public static function concession_reporting_categories(array $ids): array { return ['snack' => true]; }
    public static function is_in_store_purchase_item(string $id, array $map = []): bool { return $id === 'snack'; }
  }
  final class Store {
    public static array $entry_calls = [];
    public static array $movie = [];
    public static array $live = [];
    public static array $rental = [];
    public static array $updates = [];
    public static bool $fail_update = false;
    public static int $report_id = 100;
    public static function upsert_entries(array $rows, string $mode): array { self::$entry_calls[] = $rows; return ['created'=>count($rows),'updated'=>0,'skipped'=>0]; }
    public static function upsert_live_entries(array $rows, string $mode): array { return ['created'=>count($rows),'updated'=>0,'skipped'=>0]; }
    public static function upsert_history_rows(...$args): array { return []; }
    public static function insert_log(...$args): int { return 1; }
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
      if (self::$fail_update) return false;
      if ($kind === 'movie') foreach (self::$movie as &$rows) foreach ($rows as &$row) if ((int) ($row['id'] ?? 0) === $id) $row = array_merge($row, $data);
      if ($kind === 'live') foreach (self::$live as &$rows) foreach ($rows as &$row) if ((int) ($row['id'] ?? 0) === $id) $row = array_merge($row, $data);
      if ($kind === 'rental') foreach (self::$rental as &$rows) foreach ($rows as &$row) if ((int) ($row['id'] ?? 0) === $id) $row = array_merge($row, $data);
      return true;
    }
    public static function update_entry(int $id, array $data, bool $manual = false): bool { return self::save('movie', $id, $data); }
    public static function update_live_entry(int $id, array $data, bool $manual = false): bool { return self::save('live', $id, $data); }
    public static function update_rental_entry(int $id, array $data, bool $manual = false): bool { return self::save('rental', $id, $data); }
    public static function concessions_by_date(string $from, string $to): array { return []; }
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
  \RoxyGrosses\Reporter::reconciliation_rows('2038-05-01', '2038-05-02');
  $check(\RoxyGrosses\Square::$depth === 0 && \RoxyGrosses\Square::$cache === [] && \RoxyGrosses\Square::$calls === ['2038-05-01','2038-05-02'], 'reconciliation range shares one scope across dates and clears afterward');

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
  \RoxyGrosses\Square::$calls = [];
  \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_report_dates', [[['report_date'=>'2038-05-01'],['report_date'=>'2038-05-01'],['report_date'=>'2038-05-02']], '2038-05-02']));
  $check(\RoxyGrosses\Square::$calls === $dates, 'affected report dates are unique, sorted, and include the requested date');
  foreach ($dates as $date) {
    $cents = 0;
    foreach ([\RoxyGrosses\Store::$movie,\RoxyGrosses\Store::$live,\RoxyGrosses\Store::$rental] as $dataset) $cents += (int) round((float) ($dataset[$date][0]['concessions_total'] ?? 0) * 100);
    $check($cents === 1001, "movie/live/rental allocation conserves all 1001 cents on {$date}");
  }

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
  $check(count(\RoxyGrosses\Store::$updates) === 1001 && $allocated_cents === 1001, 'rebalance reads and conserves every row beyond the former 1,000-row cap');

  // A storage failure on an unlocked row must abort the allocation explicitly;
  // a locked row remains protected and is skipped rather than treated as failure.
  \RoxyGrosses\Store::$fail_update = true;
  $failed = false;
  try { \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', ['2038-05-01'])); }
  catch (Throwable $error) { $failed = str_contains($error->getMessage(), 'Could not save the concessions allocation'); }
  $check($failed, 'unlocked Store update failure is explicit');
  \RoxyGrosses\Store::$fail_update = false;
  \RoxyGrosses\Store::$movie['2038-05-01'][0]['is_locked'] = 1;
  $locked_cents = (int) round((float) \RoxyGrosses\Store::$movie['2038-05-01'][0]['concessions_total'] * 100);
  $locked = \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', ['2038-05-01']));
  $saved_day_cents = 0;
  foreach ([\RoxyGrosses\Store::$movie,\RoxyGrosses\Store::$live,\RoxyGrosses\Store::$rental] as $dataset) $saved_day_cents += (int) round((float) ($dataset['2038-05-01'][0]['concessions_total'] ?? 0) * 100);
  $check($locked['updated'] === 2 && (int) round((float) \RoxyGrosses\Store::$movie['2038-05-01'][0]['concessions_total'] * 100) === $locked_cents && $saved_day_cents === 1001, 'locked movie amount stays fixed and remaining allocation is conserved across editable rows');
  \RoxyGrosses\Store::$movie['2038-05-01'][0]['concessions_total'] = 20.0;
  $updates_before_mismatch = count(\RoxyGrosses\Store::$updates);
  $mismatch_failed = false;
  try { \RoxyGrosses\Square::with_sale_snapshot(static fn() => $call('rebalance_concessions_for_date', ['2038-05-01'])); }
  catch (Throwable $error) { $mismatch_failed = str_contains($error->getMessage(), 'Locked concessions exceed'); }
  $check($mismatch_failed && count(\RoxyGrosses\Store::$updates) === $updates_before_mismatch, 'locked amount above matching Square total fails before any allocation write');

  echo "OK: {$checks} reporter snapshot/rebalance checks\n";
}
