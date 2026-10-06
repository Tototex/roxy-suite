<?php
namespace RoxyGrosses {
  final class Settings {
    public static array $values = [];
    public static function get_report_timezone(): string { return 'America/Los_Angeles'; }
    public static function get(string $key, $default = '') { return self::$values[$key] ?? $default; }
    public static function set_status(array $status): void {}
  }

  final class Square {
    public static array $updated_orders = [];
    public static array $payment_refunds = [];
    public static array $source_orders = [];
    public static array $date_orders = [];
    public static array $search_windows = [];
    public static int $payment_lookups = 0;
    public static function reset(): void {
      self::$updated_orders = self::$payment_refunds = self::$source_orders = self::$date_orders = self::$search_windows = [];
      self::$payment_lookups = 0;
    }
    public static function fetch_orders_updated_between(string $start, string $end, ?float $deadline = null, bool $returns_only = false): array {
      self::$search_windows[] = [$start, $end, $returns_only];
      $from = new \DateTimeImmutable($start);
      $to = new \DateTimeImmutable($end);
      return array_values(array_filter(self::$updated_orders, static function ($order) use ($from, $to, $returns_only): bool {
        if ($returns_only && empty($order['returns'])) return false;
        try { $updated = new \DateTimeImmutable((string) ($order['updated_at'] ?? '')); }
        catch (\Throwable $error) { return false; }
        return $updated >= $from && $updated <= $to;
      }));
    }
    public static function retrieve_payment_refund(string $id, ?float $deadline = null): array {
      self::$payment_lookups++;
      if (!isset(self::$payment_refunds[$id])) throw new \RuntimeException('Fixture payment refund unavailable.');
      return self::$payment_refunds[$id];
    }
    public static function retrieve_orders(array $ids, ?float $deadline = null): array {
      $orders = [];
      foreach ($ids as $id) {
        if (!isset(self::$source_orders[$id])) throw new \RuntimeException('Fixture source sale unavailable.');
        $orders[] = self::$source_orders[$id];
      }
      return $orders;
    }
    public static function fetch_orders_for_date(string $date): array { return self::$date_orders[$date] ?? []; }
    public static function concession_reporting_categories(array $ids): array { return []; }
  }

  final class Store {
    public static array $movie_rows = [];
    public static array $refund_updates = [];
    public static array $upserted_movie_rows = [];
    public static array $logs = [];
    public static bool $throw_refund_update = false;
    public static function reset(): void {
      self::$movie_rows = self::$refund_updates = self::$upserted_movie_rows = self::$logs = [];
      self::$throw_refund_update = false;
    }
    public static function entries_table_name(): string { return 'wp_roxy_fixture_grosses_entries'; }
    public static function nominal_ticket_prices_for_showing(string $date, int $id, array $fallback, bool $strict = false): array {
      $row = self::$movie_rows[$id] ?? null;
      if ($strict && $row && $row['general_qty'] > 0) return array_replace($fallback, ['general' => $row['gross_total'] / $row['general_qty']]);
      return $fallback;
    }
    public static function update_refunded_movie_quantities(array $reports): array {
      if (self::$throw_refund_update) throw new \RuntimeException('Injected historical update failure.');
      $updated = 0; $protected = 0;
      foreach ($reports as $report) {
        if (empty($report['refund_adjusted'])) continue;
        $showing_id = (int) ($report['showing_id'] ?? 0);
        self::$refund_updates[] = $report;
        if (!isset(self::$movie_rows[$showing_id])) throw new \RuntimeException('Fixture historical row missing.');
        if (!empty(self::$movie_rows[$showing_id]['is_locked'])) { $protected++; continue; }
        // Mirror the production helper: ticket counts and nominal ticket gross only.
        foreach (['general_qty', 'discount_qty', 'group_qty', 'live_qty', 'total_tickets'] as $field) {
          self::$movie_rows[$showing_id][$field] = max(0, (int) ($report[$field] ?? 0));
        }
        self::$movie_rows[$showing_id]['gross_total'] = round((float) ($report['gross_total'] ?? 0), 2);
        self::$movie_rows[$showing_id]['updated_at'] = 'fixture-updated';
        $updated++;
      }
      return ['updated' => $updated, 'protected' => $protected];
    }
    public static function flag_emailed_refund_changes(string $date, array $rows): array { return []; }
    public static function upsert_entries(array $rows, string $mode = 'update'): array {
      self::$upserted_movie_rows = array_merge(self::$upserted_movie_rows, $rows);
      return ['created' => 0, 'updated' => count($rows), 'skipped' => 0];
    }
    public static function upsert_live_entries(array $rows, string $mode = 'update'): array { return ['created' => 0, 'updated' => count($rows), 'skipped' => 0]; }
    public static function list_entries(array $filters = [], int $limit = 1000, int $offset = 0): array { return []; }
    public static function list_live_entries(array $filters = [], int $limit = 1000, int $offset = 0): array { return []; }
    public static function list_rental_entries(array $filters = [], int $limit = 1000, int $offset = 0): array { return []; }
    public static function insert_log(...$args): int { self::$logs[] = $args; return count(self::$logs); }
  }
}

namespace {
  if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  $root = $argv[1] ?? dirname(__DIR__);
  $reporter_path = $argv[2] ?? ($root . '/includes/modules/grosses/includes/class-roxy-grosses-reporter.php');
  $returns_path = $argv[3] ?? ($root . '/includes/modules/grosses/includes/class-roxy-grosses-returns.php');
  $snapshot_path = $argv[4] ?? ($root . '/includes/modules/grosses/includes/class-roxy-grosses-refund-snapshot.php');
  foreach ([$returns_path, $snapshot_path, $reporter_path] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "Missing candidate file: {$path}\n"); exit(2); }
  }
  require_once $returns_path;
  require_once $snapshot_path;
  require_once $reporter_path;

  final class FixtureWpdb {
    public bool $lock_owned = false;
    public bool $deny_lock = false;
    public bool $lose_lock_on_owns_check = false;
    public int $owns_checks = 0;
    public function prepare(string $query, ...$args): string {
      foreach ($args as $arg) {
        $quoted = is_int($arg) || is_float($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
        $query = preg_replace('/%[sd]/', $quoted, $query, 1);
      }
      return $query;
    }
    public function get_var(string $query) {
      if (strpos($query, 'GET_LOCK(') !== false) {
        if ($this->deny_lock) return 0;
        $this->lock_owned = true;
        return 1;
      }
      if (strpos($query, 'IS_USED_LOCK(') !== false) {
        $this->owns_checks++;
        if ($this->lose_lock_on_owns_check) { $this->lock_owned = false; return 0; }
        return $this->lock_owned ? 1 : 0;
      }
      if (strpos($query, 'RELEASE_LOCK(') !== false) { $this->lock_owned = false; return 1; }
      return null;
    }
  }
  $GLOBALS['wpdb'] = new FixtureWpdb();
  $GLOBALS['fixture_options'] = ['admin_email' => ''];
  $GLOBALS['fixture_showings'] = [];
  $GLOBALS['fixture_posts'] = [];
  $GLOBALS['fixture_mail_calls'] = 0;

  function get_option(string $key, $default = false) { return $GLOBALS['fixture_options'][$key] ?? $default; }
  function update_option(string $key, $value, $autoload = null): bool { $GLOBALS['fixture_options'][$key] = $value; return true; }
  function post_type_exists(string $type): bool { return $type === 'roxy_showing'; }
  function get_posts(array $args = []): array {
    $bounds = $args['meta_query'][0]['value'] ?? [];
    if (count($bounds) !== 2) return [];
    return array_values(array_map('intval', array_filter(array_keys($GLOBALS['fixture_showings']), static function ($id) use ($bounds): bool {
      $start = (string) ($GLOBALS['fixture_showings'][$id]['_roxy_start'] ?? '');
      return $start >= $bounds[0] && $start < $bounds[1];
    })));
  }
  function get_post_meta(int $post_id, string $key, bool $single = false) {
    return $GLOBALS['fixture_showings'][$post_id][$key] ?? '';
  }
  function get_the_title(int $post_id): string { return (string) ($GLOBALS['fixture_showings'][$post_id]['title'] ?? ''); }
  function sanitize_email(string $email): string { return $email; }
  function wp_date(string $format, $timestamp = null, ?DateTimeZone $timezone = null): string { return gmdate($format, $timestamp === null ? 0 : (is_int($timestamp) ? $timestamp : strtotime((string) $timestamp))); }
  function wp_mail(...$args): bool { $GLOBALS['fixture_mail_calls']++; return true; }

  $checks = 0;
  $failures = [];
  $assert = static function ($condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) $failures[] = $message;
  };
  $result = static function (array $sync_result, string $message) use ($assert): void {
    $assert(!empty($sync_result['success']), $message . ': sync succeeds (' . ($sync_result['message'] ?? '') . ')');
  };
  $tz = new DateTimeZone('America/Los_Angeles');
  $old_date = '2037-05-01';
  $current_date = '2037-06-15';
  $now = new DateTimeImmutable($current_date . ' 12:00:00', $tz);
  $old_showing_id = 370501;
  $current_showing_id = 370615;
  $original_sale = [
    'id' => 'sale-original-370501', 'state' => 'COMPLETED', 'closed_at' => $old_date . 'T19:00:00-07:00',
    'total_money' => ['amount' => 3600, 'currency' => 'USD'],
    'line_items' => [['uid' => 'ticket-line-1', 'item_type' => 'ITEM', 'name' => 'General', 'quantity' => '3']],
  ];
  $return_order = [
    'id' => 'return-late-1', 'state' => 'COMPLETED', 'updated_at' => '2037-06-10T12:00:00-07:00',
    'refunds' => [['id' => 'refund-1', 'tender_id' => 'payment-1']],
    'returns' => [['source_order_id' => $original_sale['id'], 'return_line_items' => [[
      'uid' => 'return-line-1', 'source_line_item_uid' => 'ticket-line-1', 'quantity' => '1', 'item_type' => 'ITEM',
    ]]]],
  ];
  $seed = static function (string $status = 'PENDING', bool $protected = false) use ($old_date, $current_date, $old_showing_id, $current_showing_id, $original_sale, $return_order): void {
    \RoxyGrosses\Settings::$values = ['lookback_days' => '2', 'general_price' => '12', 'discount_price' => '8', 'group_price' => '6', 'theater_name' => 'Fixture Theater', 'admin_email' => ''];
    \RoxyGrosses\Square::reset();
    \RoxyGrosses\Store::reset();
    $GLOBALS['wpdb'] = new FixtureWpdb();
    $GLOBALS['fixture_options'] = ['admin_email' => ''];
    $GLOBALS['fixture_mail_calls'] = 0;
    $GLOBALS['fixture_showings'] = [
      $old_showing_id => ['title' => 'Original Film', '_roxy_start' => $old_date . 'T19:00', '_roxy_pricing_profile' => 'movie_evening'],
      $current_showing_id => ['title' => 'Current Film', '_roxy_start' => $current_date . 'T19:00', '_roxy_pricing_profile' => 'movie_evening'],
    ];
    \RoxyGrosses\Store::$movie_rows[$old_showing_id] = [
      'id' => 1, 'report_date' => $old_date, 'showing_id' => $old_showing_id, 'film_title' => 'Original Film', 'show_time' => '7:00 PM',
      'general_qty' => 3, 'discount_qty' => 0, 'group_qty' => 0, 'live_qty' => 0, 'total_tickets' => 3, 'gross_total' => 36.0,
      'concessions_total' => 17.25, 'studio' => 'Manual Studio', 'notes' => 'Keep', 'is_locked' => $protected ? 1 : 0,
    ];
    \RoxyGrosses\Store::$movie_rows[$current_showing_id] = [
      'id' => 2, 'report_date' => $current_date, 'showing_id' => $current_showing_id, 'film_title' => 'Current Film', 'show_time' => '7:00 PM',
      'general_qty' => 4, 'discount_qty' => 0, 'group_qty' => 0, 'live_qty' => 0, 'total_tickets' => 4, 'gross_total' => 48.0,
      'concessions_total' => 33.50, 'studio' => 'Current Studio', 'notes' => 'Unrelated current row', 'is_locked' => 0,
    ];
    \RoxyGrosses\Square::$updated_orders = [$return_order];
    \RoxyGrosses\Square::$payment_refunds['payment-1_refund-1'] = ['id' => 'payment-1_refund-1', 'payment_id' => 'payment-1', 'status' => $status];
    \RoxyGrosses\Square::$source_orders[$original_sale['id']] = $original_sale;
    \RoxyGrosses\Square::$date_orders[$old_date] = [$original_sale];
    \RoxyGrosses\Square::$date_orders[$current_date] = [];
  };
  $call_sync = static function (DateTimeImmutable $clock, string $date = '2037-06-15'): array {
    return \RoxyGrosses\Reporter::sync_automatic_tables($date, 'fixture-auto', $clock);
  };

  // Pending payment retains the original-day retry checkpoint, then a completed refund
  // updates the old row once while preserving concessions/manual metadata.
  $seed('PENDING');
  $first = $call_sync($now);
  $result($first, 'pending refund scan');
  $assert(($GLOBALS['fixture_options']['roxy_grosses_refund_pending_from'] ?? '') === $old_date, 'pending checkpoint records the original sale date');
  $assert(\RoxyGrosses\Store::$refund_updates === [] && \RoxyGrosses\Store::$movie_rows[$old_showing_id]['general_qty'] === 3, 'pending refund does not reduce original-day quantity');
  $assert(($GLOBALS['fixture_options']['roxy_grosses_refund_scan_date'] ?? '') === $current_date, 'successful pending scan advances scan checkpoint');
  $original_updated_at = '2037-06-10T12:00:00-07:00';
  \RoxyGrosses\Square::$payment_refunds['payment-1_refund-1']['status'] = 'COMPLETED';
  $second_clock = new DateTimeImmutable('2037-06-16 12:00:00', $tz);
  \RoxyGrosses\Square::$search_windows = [];
  $second = $call_sync($second_clock);
  $result($second, 'completed pending refund retry');
  $assert(\RoxyGrosses\Square::$search_windows && (new DateTimeImmutable(\RoxyGrosses\Square::$search_windows[0][0])) <= new DateTimeImmutable($old_date . ' 00:00:00', $tz), 'pending original date expands retry search even with unchanged return-order updated_at');
  $assert(\RoxyGrosses\Square::$updated_orders[0]['updated_at'] === $original_updated_at, 'fixture keeps return-order updated_at unchanged across provider-status completion');
  $assert(\RoxyGrosses\Store::$movie_rows[$old_showing_id]['general_qty'] === 2 && \RoxyGrosses\Store::$movie_rows[$old_showing_id]['gross_total'] === 24.0, 'late refund updates original historical ticket quantity and nominal gross');
  $assert(\RoxyGrosses\Store::$movie_rows[$old_showing_id]['concessions_total'] === 17.25 && \RoxyGrosses\Store::$movie_rows[$old_showing_id]['studio'] === 'Manual Studio' && \RoxyGrosses\Store::$movie_rows[$old_showing_id]['notes'] === 'Keep', 'historical partial update leaves concessions and manual metadata untouched');
  $assert(\RoxyGrosses\Store::$movie_rows[$current_showing_id]['general_qty'] === 4 && \RoxyGrosses\Store::$movie_rows[$current_showing_id]['gross_total'] === 48.0 && \RoxyGrosses\Store::$movie_rows[$current_showing_id]['concessions_total'] === 33.50, 'late old-sale refund does not alter current-date movie row');
  $assert((\RoxyGrosses\Store::$refund_updates[0]['report_date'] ?? '') === $old_date && (int) (\RoxyGrosses\Store::$refund_updates[0]['general_qty'] ?? -1) === 2, 'partial-update helper receives the corrected original sale date and quantity');
  $assert(($second['refund_movie_rows_updated'] ?? null) === 1 && count(\RoxyGrosses\Store::$refund_updates) === 1, 'sync exposes exactly one historical movie-row update');
  $assert(($GLOBALS['fixture_options']['roxy_grosses_refund_pending_from'] ?? 'not-empty') === '', 'completed refund clears pending checkpoint');
  $assert(($GLOBALS['fixture_options']['roxy_grosses_refund_scan_date'] ?? '') === '2037-06-16', 'completed retry advances scan checkpoint only after success');
  $assert($GLOBALS['fixture_mail_calls'] === 0, 'refund refresh sends no email');

  // Re-running with the same provider data does not subtract the prior correction again.
  $repeat = $call_sync(new DateTimeImmutable('2037-06-17 12:00:00', $tz));
  $result($repeat, 'idempotent repeat scan');
  $assert(\RoxyGrosses\Store::$movie_rows[$old_showing_id]['general_qty'] === 2 && \RoxyGrosses\Store::$movie_rows[$old_showing_id]['gross_total'] === 24.0, 'repeat scan does not cumulatively deduct the same return');
  $assert(count(\RoxyGrosses\Store::$refund_updates) === 1, 'unchanged old provider update outside normal overlap does not reapply the historical correction');

  // A definitive failure is not applied and does not create a pending-original-date retry.
  $seed('FAILED');
  $failed_status = $call_sync($now);
  $result($failed_status, 'failed payment-refund status scan');
  $assert(\RoxyGrosses\Store::$refund_updates === [] && \RoxyGrosses\Store::$movie_rows[$old_showing_id]['general_qty'] === 3, 'FAILED payment refund never reduces source sale quantity');
  $assert(($GLOBALS['fixture_options']['roxy_grosses_refund_pending_from'] ?? '') === '', 'definitively failed refund is not retained as pending');

  // Custom amount ambiguity stops before persistence/checkpoint advancement.
  $seed('COMPLETED');
  \RoxyGrosses\Square::$updated_orders[0]['returns'][0]['return_line_items'][0]['item_type'] = 'CUSTOM_AMOUNT';
  $custom = $call_sync($now);
  $assert(empty($custom['success']) && \RoxyGrosses\Store::$refund_updates === [], 'custom-amount return fails closed before historical update');
  $assert(!isset($GLOBALS['fixture_options']['roxy_grosses_refund_scan_date']) && !isset($GLOBALS['fixture_options']['roxy_grosses_refund_pending_from']), 'custom-amount failure does not stamp either checkpoint');
  $assert($GLOBALS['fixture_mail_calls'] === 0, 'custom-amount failure causes no email with admin alerts disabled');
  $seed('COMPLETED');
  $GLOBALS['fixture_options']['roxy_grosses_refund_scan_date'] = '2037-06-01';
  $after_outage = $call_sync(new DateTimeImmutable('2037-06-25 12:00:00', $tz));
  $result($after_outage, 'multi-week outage catch-up');
  $assert(\RoxyGrosses\Store::$movie_rows[$old_showing_id]['general_qty'] === 2 && (new DateTimeImmutable(\RoxyGrosses\Square::$search_windows[0][0])) <= new DateTimeImmutable('2037-06-01 00:00:00', $tz), 'outage scans from persisted last success, not merely yesterday');
  $seed('COMPLETED');
  \RoxyGrosses\Settings::$values['general_price'] = '15';
  $price_changed = $call_sync($now);
  $result($price_changed, 'current price changed after original sale');
  $assert(\RoxyGrosses\Store::$movie_rows[$old_showing_id]['gross_total'] === 24.0, 'automatic historical refund preserves original nominal price rather than current 15');

  // A transient historical storage error and a lost advisory lock both retain the old cursor.
  $seed('COMPLETED');
  $GLOBALS['fixture_options']['roxy_grosses_refund_scan_date'] = '2037-06-14';
  \RoxyGrosses\Square::$updated_orders[0]['updated_at'] = $now->format('c');
  \RoxyGrosses\Store::$throw_refund_update = true;
  $failed_update = $call_sync($now);
  $assert(empty($failed_update['success']) && $GLOBALS['fixture_options']['roxy_grosses_refund_scan_date'] === '2037-06-14', 'historical update failure preserves prior scan cursor');
  $assert(($GLOBALS['fixture_options']['roxy_grosses_refund_pending_from'] ?? '') === '', 'failed historical update leaves prior pending marker unchanged');

  $seed('COMPLETED');
  $GLOBALS['fixture_options']['roxy_grosses_refund_scan_date'] = '2037-06-14';
  \RoxyGrosses\Square::$updated_orders[0]['updated_at'] = $now->format('c');
  $GLOBALS['wpdb']->lose_lock_on_owns_check = true;
  $lost_lock = $call_sync($now);
  $assert(empty($lost_lock['success']) && $GLOBALS['fixture_options']['roxy_grosses_refund_scan_date'] === '2037-06-14', 'lost lock aborts before cursor advancement');
  $assert(\RoxyGrosses\Store::$refund_updates === [] && \RoxyGrosses\Store::$movie_rows[$old_showing_id]['general_qty'] === 3, 'lost lock before historical write leaves source totals unchanged');
  $assert(!$GLOBALS['wpdb']->lock_owned, 'sync releases claimed connection-owned lock on failure');

  $seed('COMPLETED');
  $GLOBALS['fixture_options']['roxy_grosses_refund_scan_date'] = '2037-06-14';
  \RoxyGrosses\Square::$updated_orders[0]['updated_at'] = $now->format('c');
  $GLOBALS['wpdb']->deny_lock = true;
  $contended = $call_sync($now);
  $assert(empty($contended['success']) && $GLOBALS['fixture_options']['roxy_grosses_refund_scan_date'] === '2037-06-14', 'lock contention fails safely without advancing prior checkpoint');
  $assert(\RoxyGrosses\Square::$search_windows === [] && \RoxyGrosses\Store::$refund_updates === [], 'lock contention performs no provider scan or historical update');

  // Manually protected historical rows remain unchanged and protection counts are visible.
  $seed('COMPLETED', true);
  $protected = $call_sync($now);
  $result($protected, 'manual-protection sync');
  $assert(($protected['refund_movie_rows_protected'] ?? null) === 1 && ($protected['refund_movie_rows_updated'] ?? null) === 0, 'sync result reports protected historical row count');
  $assert(\RoxyGrosses\Store::$movie_rows[$old_showing_id]['general_qty'] === 3 && \RoxyGrosses\Store::$movie_rows[$old_showing_id]['concessions_total'] === 17.25, 'protected historical row remains unchanged');
  $assert($GLOBALS['fixture_mail_calls'] === 0, 'all sync fixtures avoid sending mail');

  if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    fwrite(STDERR, sprintf("%d checks, %d failures\n", $checks, count($failures)));
    exit(1);
  }
  printf("%d checks passed\n", $checks);
}
