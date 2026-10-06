<?php
namespace RoxyGrosses {

/** In-memory collaborators for the actual Reporter class. */
final class Settings {
  public static array $values = ['general_price' => '12', 'discount_price' => '8', 'group_price' => '6', 'theater_name' => 'Fixture Theater'];
  public static function get(string $key, $default = '') { return self::$values[$key] ?? $default; }
}
final class Square {
  public static array $orders = [];
  public static int $fetch_count = 0;
  public static function fetch_orders_for_date(string $date): array { self::$fetch_count++; return self::$orders; }
  public static function concession_reporting_categories(array $ids): array { return []; }
}
final class Store {
  public static function with_refund_review_lock(callable $operation) { return $operation(); }
  public static function assert_refund_review_lock(): void {}
  public static array $flag_calls = [];
  public static array $saved_report = [];
  public static int $writes = 0;
  public static array $original_prices = [];
  public static function nominal_ticket_prices_for_showing(string $date, int $id, array $fallback, bool $strict = false): array { return array_replace($fallback, self::$original_prices); }
  public static function flag_emailed_refund_changes(string $date, array $rows): array { self::$flag_calls[] = [$date, $rows]; return [101]; }
  public static function get_report(int $id): ?array { return self::$saved_report ?: null; }
  public static function mark_emailed(int $id): bool { self::$writes++; return true; }
  public static function insert_log(...$args): int { self::$writes++; return 1; }
  public static function upsert_history_rows(...$args): array { self::$writes++; return []; }
  public static function upsert_entries(...$args): array { self::$writes++; return []; }
}
final class RefundSnapshot {
  public static array $result = [];
  public static array $seen = [];
  public function reconcile_sale_day(string $date, array $sales): array {
    self::$seen[] = [$date, $sales];
    return self::$result;
  }
}
}

namespace {
  if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  $root = $argv[1] ?? dirname(__DIR__);
  $candidate = $argv[2] ?? ($root . '/includes/modules/grosses/includes/class-roxy-grosses-reporter.php');
  if (!is_file($candidate)) { fwrite(STDERR, "Missing candidate Reporter: {$candidate}\n"); exit(2); }
  require_once $candidate;

  $GLOBALS['roxy_fixture_mail_calls'] = 0;
  function wp_mail(...$args): bool { $GLOBALS['roxy_fixture_mail_calls']++; return true; }

  $checks = 0;
  $failures = [];
  $assert = static function ($condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) $failures[] = $message;
  };
  $throws = static function (callable $call, string $message) use ($assert): void {
    try { $call(); $assert(false, $message); }
    catch (\Throwable $error) { $assert(true, $message); }
  };
  $timezone = new \DateTimeZone('America/Los_Angeles');
  $showing = static function (int $id, string $title, string $at) use ($timezone): array {
    $start = new \DateTimeImmutable($at, $timezone);
    return ['id' => $id, 'title' => $title, 'time_label' => $start->format('g:i A'), 'start_at' => $start];
  };
  $ticket_order = static function (string $id, string $closed_at, string $line_uid, int $quantity, int $total_cents): array {
    return ['id' => $id, 'state' => 'COMPLETED', 'closed_at' => $closed_at,
      'total_money' => ['amount' => $total_cents, 'currency' => 'USD'],
      'line_items' => [['uid' => $line_uid, 'item_type' => 'ITEM', 'name' => 'General', 'quantity' => (string) $quantity]],
    ];
  };
  $reconcile = static function (array $orders, array $adjusted_orders, array $adjustments = [], array $issues = []): void {
    \RoxyGrosses\RefundSnapshot::$result = ['orders' => $adjusted_orders, 'adjustments' => $adjustments, 'issues' => $issues];
    \RoxyGrosses\RefundSnapshot::$seen = [];
    \RoxyGrosses\Store::$flag_calls = [];
    \RoxyGrosses\Square::$orders = $orders;
    \RoxyGrosses\Square::$fetch_count = 0;
  };
  $invoke = static function (string $date, array $showings, ?\RoxyGrosses\RefundSnapshot $snapshot = null): array {
    $method = new \ReflectionMethod(\RoxyGrosses\Reporter::class, 'build_reports_for_date_showings');
    $method->setAccessible(true);
    return $method->invoke(null, $date, $showings, $snapshot);
  };
  $adjustment = static function (string $order_id, string $line_uid, int $quantity): array {
    return ['source_order_id' => $order_id, 'source_line_item_uid' => $line_uid, 'quantity' => $quantity];
  };

  $date = '2039-04-07';
  $showings = [$showing(501, 'Returned Film', '2039-04-07 19:00:00'), $showing(502, 'Other Film', '2039-04-07 21:30:00')];
  $returned_sale = $ticket_order('sale-returned', '2039-04-07T19:00:00-07:00', 'line-returned', 3, 3600);
  $unaffected_sale = $ticket_order('sale-other', '2039-04-07T21:30:00-07:00', 'line-other', 2, 2400);
  $raw_orders = [$returned_sale, $unaffected_sale];
  $adjusted_orders = $raw_orders;
  $adjusted_orders[0]['line_items'][0]['quantity'] = '2';
  $reconcile($raw_orders, $adjusted_orders, [$adjustment('sale-returned', 'line-returned', 1)]);
  $rows = $invoke($date, $showings, new \RoxyGrosses\RefundSnapshot());
  $by_showing = [];
  foreach ($rows as $row) $by_showing[$row['showing_id']] = $row;
  $assert(($by_showing[501]['general_qty'] ?? null) === 2 && ($by_showing[501]['general_gross'] ?? null) === 24.0, '3-to-2 refund uses current nominal general price 12, yielding gross 24');
  $assert(($by_showing[502]['general_qty'] ?? null) === 2 && ($by_showing[502]['general_gross'] ?? null) === 24.0, 'unaffected second sale remains at its original quantity and nominal gross');
  $assert(\RoxyGrosses\Square::$orders === $raw_orders, 'raw Square orders and money totals remain untouched');
  $assert(\RoxyGrosses\RefundSnapshot::$seen[0][0] === $date, 'reconciler receives the exact original sale date');
  $assert(count(\RoxyGrosses\Store::$flag_calls) === 1 && \RoxyGrosses\Store::$flag_calls[0][0] === $date, 'changed report flags the matching emailed original sale date');
  $flag_rows = \RoxyGrosses\Store::$flag_calls[0][1];
  $flagged_row = array_values(array_filter($flag_rows, static fn($row) => $row['showing_id'] === 501))[0] ?? [];
  $assert(($flagged_row['general_qty'] ?? null) === 2 && ($flagged_row['gross_total'] ?? null) === 24.0, 'Store receives post-refund quantities and nominal gross');
  $assert(!empty($by_showing[501]['refund_adjusted']), 'returned sale row carries the refund-adjusted marker');
  \RoxyGrosses\Store::$original_prices = ['general' => 10];
  $extra_original_sale = $ticket_order('sale-same-show', '2039-04-07T19:00:00-07:00', 'line-unchanged', 1, 1200);
  $reconcile(array_merge($raw_orders, [$extra_original_sale]), array_merge($adjusted_orders, [$extra_original_sale]), [$adjustment('sale-returned', 'line-returned', 1)]);
  $original_price_rows = $invoke($date, $showings, new \RoxyGrosses\RefundSnapshot());
  $by_id = array_column($original_price_rows, null, 'showing_id');
  $assert($by_id[501]['general_qty'] === 3 && $by_id[501]['gross_total'] === 30.0, 'original unit price applies before all lines are accumulated for the corrected showing');
  $assert($by_id[502]['gross_total'] === 24.0, 'historical price does not leak into another showing');
  \RoxyGrosses\Store::$original_prices = [];

  // A full refund leaves a zero-quantity showing row present and marked for review.
  $full_sale = $ticket_order('sale-full', '2039-04-07T19:00:00-07:00', 'line-full', 1, 1200);
  $full_adjusted = $full_sale;
  $full_adjusted['line_items'][0]['quantity'] = '0';
  $reconcile([$full_sale], [$full_adjusted], [$adjustment('sale-full', 'line-full', 1)]);
  $full_rows = $invoke($date, [$showings[0]], new \RoxyGrosses\RefundSnapshot());
  $assert(count($full_rows) === 1 && $full_rows[0]['general_qty'] === 0 && $full_rows[0]['gross_total'] === 0.0, 'full-refund zero-quantity row remains in builder output');
  $assert(!empty($full_rows[0]['refund_adjusted']), 'full-refund zero row retains refund-adjusted marker');

  // The identical sale data for another day is not adjusted unless that date is reconciled.
  $next_date = '2039-04-08';
  $next_sale = $ticket_order('sale-next-day', '2039-04-08T19:00:00-07:00', 'line-next', 3, 3600);
  $reconcile([$next_sale], [$next_sale]);
  $next_rows = $invoke($next_date, [$showing(601, 'Next Film', '2039-04-08 19:00:00')], new \RoxyGrosses\RefundSnapshot());
  $assert($next_rows[0]['general_qty'] === 3 && $next_rows[0]['gross_total'] === 36.0, 'separate original sale date remains unchanged');
  $assert(\RoxyGrosses\Store::$flag_calls === [], 'unadjusted other date does not create refund-review flags');

  // Invalid provider/item reconciliation and ambiguous original-showing attribution fail before flags.
  foreach ([
    ['custom', [['reason' => 'custom_amount_return']]],
    ['unverified', [['reason' => 'refund_unverified']]],
  ] as [$label, $issues]) {
    $reconcile([$returned_sale], [$returned_sale], [], $issues);
    $throws(static fn() => $invoke($date, [$showings[0]], new \RoxyGrosses\RefundSnapshot()), "{$label} reconciliation issue aborts report calculation");
    $assert(\RoxyGrosses\Store::$flag_calls === [], "{$label} reconciliation issue does not flag or persist a correction");
  }
  $reconcile([$returned_sale], [$adjusted_orders[0]], [$adjustment('sale-returned', 'line-returned', 1)]);
  $ambiguous = [$showing(701, 'Film One', '2039-04-07 19:00:00'), $showing(702, 'Film Two', '2039-04-07 20:00:00')];
  $throws(static fn() => $invoke($date, $ambiguous, new \RoxyGrosses\RefundSnapshot()), 'refund whose order time matches multiple showings fails closed');
  $assert(\RoxyGrosses\Store::$flag_calls === [] && $GLOBALS['roxy_fixture_mail_calls'] === 0, 'ambiguous allocation aborts before review flagging or email');

  // A flagged saved snapshot is refused before any email or Store mutation.
  \RoxyGrosses\Store::$saved_report = ['id' => 999, 'report_end_date' => $date, 'status' => 'emailed',
    'summary' => ['total_tickets' => 3, 'gross_total' => 36], 'rows' => [['report_date' => $date]],
    'refund_review' => [$date => ['before' => [], 'after' => []]]];
  \RoxyGrosses\Store::$writes = 0;
  $mail_before = $GLOBALS['roxy_fixture_mail_calls'];
  $saved_result = \RoxyGrosses\Reporter::send_saved_report(999);
  $assert(empty($saved_result['success']) && strpos((string) ($saved_result['message'] ?? ''), 'refund review') !== false, 'saved report with refund-review evidence is refused');
  $assert($GLOBALS['roxy_fixture_mail_calls'] === $mail_before && \RoxyGrosses\Store::$writes === 0, 'saved-report refusal sends no mail and performs no Store writes');

  if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    fwrite(STDERR, sprintf("%d checks, %d failures\n", $checks, count($failures)));
    exit(1);
  }
  printf("%d checks passed\n", $checks);
}
