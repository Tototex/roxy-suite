<?php
declare(strict_types=1);

namespace RoxyGrosses {
  final class Settings {
    public static array $values = ['cashflow_woo_gateways' => "paypal\nstripe"];
    public static function get_report_timezone(): string { return 'America/Los_Angeles'; }
    public static function get(string $key, $default = '') { return self::$values[$key] ?? $default; }
    public static function line_list(string $value): array { return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: []), 'strlen')); }
  }
  final class Store {
    public static ?array $completion_event = null;
    public static array $completion_candidates = [];
    public static array $completion_candidate_calls = [];
    public static bool $fail_completion_event_read = false;
    public static function completed_refund_events_created_between(string $start, string $end): array {
      self::$completion_candidate_calls[] = [$start, $end];
      return array_values(array_filter(self::$completion_candidates, static fn(array $event): bool => $event['event_created_at'] >= $start && $event['event_created_at'] < $end));
    }
    public static function refund_completion_event(array $refund, string $updated): ?array {
      if (self::$fail_completion_event_read) throw new \RuntimeException('Injected completion-evidence read failure.');
      return self::$completion_event;
    }
  }
  final class Square {
    public static array $orders = [];
    public static array $daily_orders = [];
    public static array $payments = [];
    public static array $refunds = [];
    public static array $refunds_by_id = [];
    public static array $orders_by_id = [];
    public static array $refund_retrieve_calls = [];
    public static array $calls = [];
    public static bool $fail_daily_order_read = false;
    public static function fetch_orders_for_date(string $date): array {
      self::$calls[] = ['daily_orders', $date];
      if (self::$fail_daily_order_read) throw new \RuntimeException('Injected Square order read failure.');
      return self::$daily_orders;
    }
    public static function list_payments_created_between(string $start, string $end, ?float $deadline = null): array { self::$calls[] = ['payments', $start, $end]; return self::$payments; }
    public static function list_payment_refunds_updated_between(string $start, string $end, ?float $deadline = null): array { self::$calls[] = ['refunds', $start, $end]; return self::$refunds; }
    public static function retrieve_payment_refund(string $id, ?float $deadline = null): array {
      self::$refund_retrieve_calls[] = $id;
      if (!isset(self::$refunds_by_id[$id])) throw new \RuntimeException('Fixture refund missing.');
      return self::$refunds_by_id[$id];
    }
    public static function retrieve_orders(array $ids, ?float $deadline = null): array {
      $orders = [];
      foreach ($ids as $id) {
        if (!isset(self::$orders_by_id[$id])) throw new \RuntimeException('Fixture associated order missing.');
        $orders[] = self::$orders_by_id[$id];
      }
      return $orders;
    }
  }
  final class FakeOrder {
    public function __construct(private int $id, private string $gateway, private string $amount, private string $transaction, private \DateTimeImmutable $paid) {}
    public function get_id(): int { return $this->id; }
    public function get_payment_method(): string { return $this->gateway; }
    public function is_paid(): bool { return true; }
    public function get_currency(): string { return 'USD'; }
    public function get_total(): string { return $this->amount; }
    public function get_date_paid(): \DateTimeImmutable { return $this->paid; }
    public function get_transaction_id(): string { return $this->transaction; }
  }
  final class FakeRefund {
    public function __construct(private int $id, private int $parent, private string $amount, private \DateTimeImmutable $created, private bool $processed = true) {}
    public function get_id(): int { return $this->id; }
    public function get_parent_id(): int { return $this->parent; }
    public function get_refunded_payment(): bool { return $this->processed; }
    public function get_currency(): string { return 'USD'; }
    public function get_amount(): string { return $this->amount; }
    public function get_date_created(): \DateTimeImmutable { return $this->created; }
  }
}

namespace {
  define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  $checks = 0;
  $failures = [];
  $assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    ++$checks;
    if (!$condition) $failures[] = $message;
  };
  $expect_throw = static function (callable $callback, string $message) use (&$checks, &$failures): void {
    ++$checks;
    try { $callback(); $failures[] = $message; }
    catch (\Throwable $error) {}
  };

  $GLOBALS['wc_pages'] = [];
  $GLOBALS['wc_queries'] = [];
  function wc_get_orders(array $args) {
    $GLOBALS['wc_queries'][] = $args;
    if (!empty($GLOBALS['wc_query_fail'])) return false;
    $page = (int) ($args['page'] ?? 1);
    $type = (string) ($args['type'] ?? '');
    $rows = $GLOBALS['wc_pages'][$type][$page] ?? [];
    $pages = $GLOBALS['wc_page_counts'][$type] ?? 1;
    switch ($GLOBALS['wc_page_result_shape'] ?? 'total_pages') {
      case 'max_num_pages': return (object) ['orders' => $rows, 'max_num_pages' => $pages];
      case 'both': return (object) ['orders' => $rows, 'max_num_pages' => $pages, 'total_pages' => $pages];
      case 'contradictory': return (object) ['orders' => $rows, 'max_num_pages' => $pages, 'total_pages' => $pages + 1];
      case 'string': return (object) ['orders' => $rows, 'max_num_pages' => (string) $pages];
      default: return (object) ['orders' => $rows, 'total_pages' => $pages];
    }
  }
  function wc_get_order(int $id) { return $GLOBALS['wc_parent_orders'][$id] ?? false; }
  function is_wp_error($value): bool { return false; }

  require_once __DIR__ . '/../includes/modules/grosses/includes/class-roxy-grosses-refund-snapshot.php';
  require_once __DIR__ . '/../includes/modules/grosses/includes/class-roxy-grosses-cashflow-report.php';

  \RoxyGrosses\Square::$payments = [
    ['id' => 'sq-payment', 'location_id' => 'loc', 'order_id' => 'sq-order', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 1500, 'currency' => 'USD'], 'created_at' => '2026-10-03T02:00:00Z'],
    ['id' => 'sq-pending', 'location_id' => 'loc', 'status' => 'PENDING', 'amount_money' => ['amount' => 9900, 'currency' => 'USD'], 'created_at' => '2026-10-03T02:00:00Z'],
  ];
  \RoxyGrosses\Square::$refunds = [[
    'id' => 'sq-refund', 'payment_id' => 'sq-payment', 'location_id' => 'loc', 'status' => 'COMPLETED',
    'amount_money' => ['amount' => 200, 'currency' => 'USD'], 'updated_at' => '2026-10-03T03:00:00Z', 'order_id' => 'sq-order',
  ]];
  $GLOBALS['wc_pages'] = [
    'shop_order' => [1 => [
      new \RoxyGrosses\FakeOrder(21, 'stripe', '30.00', 'stripe-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
      new \RoxyGrosses\FakeOrder(99, 'paypal', '20.00', 'paypal-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
      // Stripe Link and BLIK arrive with the actual Woo gateway ID `stripe`.
      new \RoxyGrosses\FakeOrder(101, 'stripe', '11.00', 'stripe-blik-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
      new \RoxyGrosses\FakeOrder(102, 'stripe', '7.00', 'stripe-link-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
    ]],
    'shop_order_refund' => [1 => [
      new \RoxyGrosses\FakeRefund(22, 21, '5.00', new \DateTimeImmutable('2026-10-03T04:00:00Z')),
      new \RoxyGrosses\FakeRefund(23, 99, '10.00', new \DateTimeImmutable('2026-10-03T05:00:00Z')),
      new \RoxyGrosses\FakeRefund(24, 21, '7.50', new \DateTimeImmutable('2026-10-03T06:00:00Z'), false),
      new \RoxyGrosses\FakeRefund(25, 100, '9.00', new \DateTimeImmutable('2026-10-03T06:30:00Z'), false),
      new \RoxyGrosses\FakeRefund(26, 101, '1.00', new \DateTimeImmutable('2026-10-03T05:30:00Z')),
      new \RoxyGrosses\FakeRefund(27, 102, '2.00', new \DateTimeImmutable('2026-10-03T05:45:00Z')),
    ]],
  ];
  \RoxyGrosses\Square::$daily_orders = [[
    'id' => 'sq-exchange', 'state' => 'COMPLETED', 'closed_at' => '2026-10-03T06:30:00Z',
    'returns' => [['uid' => 'return-1']],
    'tenders' => [['amount_money' => ['amount' => 1250, 'currency' => 'USD']]],
  ], ['id' => 'sq-order', 'state' => 'COMPLETED', 'created_at' => '2026-10-02T03:00:00Z', 'closed_at' => '2026-10-03T06:30:00Z']];
  $GLOBALS['wc_parent_orders'] = [
    21 => new \RoxyGrosses\FakeOrder(21, 'stripe', '30.00', 'stripe-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
    99 => new \RoxyGrosses\FakeOrder(99, 'paypal', '20.00', 'paypal-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
    101 => new \RoxyGrosses\FakeOrder(101, 'stripe', '11.00', 'stripe-blik-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
    102 => new \RoxyGrosses\FakeOrder(102, 'stripe', '7.00', 'stripe-link-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
    100 => new \RoxyGrosses\FakeOrder(100, 'cash', '9.00', 'cash-ref', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
  ];
  $GLOBALS['wc_page_counts'] = ['shop_order' => 1, 'shop_order_refund' => 1];
  $report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert($report['totals']['square_collected_cents'] === 1500 && $report['totals']['woocommerce_collected_cents'] === 6800, 'daily cashflow includes PayPal and all Stripe gateway checkouts (including Link/BLIK) while excluding non-completed payments');
  $assert($report['totals']['square_refunded_cents'] === 200 && $report['totals']['woocommerce_refunded_cents'] === 1800 && $report['totals']['net_cents'] === 6300, 'daily cashflow subtracts refund-day events for PayPal and Stripe gateway checkouts and omits offline gateway refunds');
  $assert($report['counts'] === ['square_collections'=>1,'woocommerce_collections'=>4,'square_refunds'=>1,'woocommerce_refunds'=>4], 'daily report counts only events from approved provider gateway IDs attributed to selected local date');
  $assert($report['refund_date_bases']['square'] === ['square_updated_at_proxy'] && $report['refund_date_bases']['woocommerce'] === ['woocommerce_refund_creation_proxy'], 'daily report retains explicit refund-date provenance');
  $assert($report['refund_date_basis_counts'] === [
    'square' => ['square_updated_at_proxy' => 1],
    'woocommerce' => ['woocommerce_refund_creation_proxy' => 4],
  ], 'daily report counts refund date evidence only for refunds assigned to the selected day');
  $proxy_dated_refunds = $report['review_exceptions']['square_refunds_using_update_time_proxy'];
  $assert(count($proxy_dated_refunds) === 1 && $proxy_dated_refunds[0]['refund_id'] === 'sq-refund'
    && $proxy_dated_refunds[0]['payment_id'] === 'sq-payment' && $proxy_dated_refunds[0]['order_id'] === 'sq-order'
    && $proxy_dated_refunds[0]['refund_date'] === '2026-10-02' && $proxy_dated_refunds[0]['amount_cents'] === 200
    && str_contains($proxy_dated_refunds[0]['review'], 'not settlement evidence'),
    'Cashflow exposes each included Square refund whose date relies on mutable updated_at proxy evidence');
  $assert(count($report['review_exceptions']['woocommerce_manual_refunds']) === 1
    && $report['review_exceptions']['woocommerce_manual_refunds'][0]['refund_id'] === 24
    && $report['review_exceptions']['woocommerce_manual_refunds'][0]['amount_cents'] === 750,
    'manual WooCommerce refund is visible as a read-only review exception');
  $assert(!in_array(25, array_column($report['review_exceptions']['woocommerce_manual_refunds'], 'refund_id'), true),
    'manual refund from an offline gateway outside the configured allow-list is not included in review exceptions');
  $assert(count($report['review_exceptions']['square_returns_and_exchanges']) === 1
    && $report['review_exceptions']['square_returns_and_exchanges'][0]['order_id'] === 'sq-exchange'
    && $report['review_exceptions']['square_returns_and_exchanges'][0]['tender_cents'] === 1250,
    'completed Square return/exchange is visible with its tender amount for review');
  $unmatched_square_refunds = $report['review_exceptions']['square_payment_refunds_without_itemized_returns'];
  $assert(count($unmatched_square_refunds) === 1 && $unmatched_square_refunds[0]['refund_id'] === 'sq-refund'
    && $unmatched_square_refunds[0]['amount_cents'] === 200
    && $unmatched_square_refunds[0]['original_sale_date'] === '2026-10-02'
    && str_contains($unmatched_square_refunds[0]['review'], 'included in Cashflow'),
    'completed Square payment refund remains in cash totals and review uses the associated order close date, not its earlier creation date, for original sale day');
  \RoxyGrosses\Square::$daily_orders[0]['returns'] = [[
    'uid' => 'return-1', 'source_order_id' => 'sq-order', 'return_line_items' => [[
      'uid' => 'return-line-1', 'source_line_item_uid' => 'sale-line-1', 'quantity' => '1', 'item_type' => 'ITEM',
    ]],
  ]];
  \RoxyGrosses\Square::$daily_orders[0]['refunds'] = [['id' => 'sq-refund', 'tender_id' => 'different-payment']];
  $wrong_payment_report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert(count($wrong_payment_report['review_exceptions']['square_payment_refunds_without_itemized_returns']) === 1,
    'refund reference for a different tender does not falsely clear the unmatched-refund review');
  \RoxyGrosses\Square::$daily_orders[1]['closed_at'] = 'not-a-square-timestamp';
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'),
    'malformed linked Square order close timestamp fails closed rather than guessing the original sale date');
  \RoxyGrosses\Square::$daily_orders[1]['closed_at'] = '2026-10-03T06:30:00Z';
  unset(\RoxyGrosses\Square::$daily_orders[1]['closed_at']);
  $missing_closed_at_report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert($missing_closed_at_report['review_exceptions']['square_payment_refunds_without_itemized_returns'][0]['original_sale_date'] === '',
    'missing associated-order close timestamp is unavailable rather than substituted with order creation date');
  \RoxyGrosses\Square::$daily_orders[1]['closed_at'] = '2026-10-03T06:30:00Z';
  \RoxyGrosses\Square::$daily_orders[0]['refunds'] = [['id' => 'sq-refund', 'tender_id' => 'sq-payment']];
  \RoxyGrosses\Square::$daily_orders[0]['returns'] = [['uid' => 'return-1', 'source_order_id' => 'sq-order']];
  $unitemized_refund_report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert(count($unitemized_refund_report['review_exceptions']['square_payment_refunds_without_itemized_returns']) === 1,
    'a completed return container without return_line_items cannot clear the payment-refund review');
  \RoxyGrosses\Square::$daily_orders[0]['returns'][0]['return_line_items'] = [[
    'uid' => 'return-line-1', 'quantity' => '1', 'item_type' => 'ITEM',
  ]];
  $malformed_itemized_refund_report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert(count($malformed_itemized_refund_report['review_exceptions']['square_payment_refunds_without_itemized_returns']) === 1,
    'a return line without its source sale-line identity cannot clear the payment-refund review');
  \RoxyGrosses\Square::$daily_orders[0]['returns'][0]['return_line_items'] = [[
    'uid' => 'return-line-1', 'source_line_item_uid' => 'sale-line-1', 'quantity' => '1', 'item_type' => 'ITEM',
  ]];
  $matched_refund_report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert($matched_refund_report['review_exceptions']['square_payment_refunds_without_itemized_returns'] === [],
    'itemized return referencing the completed payment refund clears the unmatched-refund review exception');
  \RoxyGrosses\Square::$daily_orders[0]['refunds'] = [];
  \RoxyGrosses\Square::$refunds[0]['order_id'] = 'missing-associated-order';
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'),
    'associated-order read failure aborts Cashflow instead of claiming a refund is unmatched');
  \RoxyGrosses\Square::$refunds[0]['order_id'] = 'sq-return-other-day';
  \RoxyGrosses\Square::$orders_by_id['sq-return-other-day'] = [
    'id' => 'sq-return-other-day', 'state' => 'COMPLETED', 'returns' => [[
      'uid' => 'return-cross-day', 'source_order_id' => 'sq-order', 'return_line_items' => [[
        'uid' => 'return-line-cross-day', 'source_line_item_uid' => 'sale-line-1', 'quantity' => '1', 'item_type' => 'ITEM',
      ]],
    ]],
    'refunds' => [['id' => 'sq-refund', 'tender_id' => 'sq-payment']],
  ];
  $linked_order_report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert($linked_order_report['review_exceptions']['square_payment_refunds_without_itemized_returns'] === [],
    'refund-linked itemized order is retrieved when it falls outside the selected order-day list');
  unset(\RoxyGrosses\Square::$refunds[0]['order_id']);
  \RoxyGrosses\Square::$orders_by_id = [];
  $GLOBALS['wc_page_result_shape'] = 'max_num_pages';
  $max_num_pages_report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert($max_num_pages_report['totals'] === $report['totals'], 'WooCommerce documented max_num_pages pagination produces complete daily totals');
  $GLOBALS['wc_page_result_shape'] = 'both';
  $both_page_counts_report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert($both_page_counts_report['totals'] === $report['totals'], 'matching WooCommerce page-count properties are accepted');
  $GLOBALS['wc_page_result_shape'] = 'contradictory';
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'contradictory WooCommerce page-count properties fail closed');
  $GLOBALS['wc_page_result_shape'] = 'string';
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'malformed WooCommerce page-count type fails closed');
  unset($GLOBALS['wc_page_result_shape']);
  \RoxyGrosses\Square::$daily_orders = [[
    'id' => 'sq-return-no-tenders', 'state' => 'COMPLETED', 'closed_at' => '2026-10-03T06:30:00Z',
    'returns' => [['uid' => 'return-no-tenders']],
  ]];
  $no_tender_report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert(count($no_tender_report['review_exceptions']['square_returns_and_exchanges']) === 1
    && $no_tender_report['review_exceptions']['square_returns_and_exchanges'][0]['tender_cents'] === null,
    'Square completed return without tender data remains visible with amount unavailable');
  \RoxyGrosses\Square::$daily_orders = [[
    'id' => 'sq-exchange', 'state' => 'COMPLETED', 'closed_at' => '2026-10-03T06:30:00Z',
    'returns' => [['uid' => 'return-1']],
    'tenders' => [['amount_money' => ['amount' => 1250, 'currency' => 'USD']]],
  ]];
  $assert($report['totals']['woocommerce_refunded_cents'] === 1800 && $report['totals']['net_cents'] === 6300,
    'manual Woo refund and Square exchange tender remain excluded from cashflow totals');
  $assert($GLOBALS['wc_queries'][0]['date_paid'] === '1790924400...1791010799' && $GLOBALS['wc_queries'][1]['date_created'] === '1790924400...1791010799', 'WooCommerce queries use exact UTC bounds for the report timezone day');
  $assert(\RoxyGrosses\Square::$calls[0] === ['payments', '2026-10-02T07:00:00Z', '2026-10-03T07:00:00Z'], 'Square payment read uses exact UTC boundaries for the selected report-timezone day');

  \RoxyGrosses\Square::$fail_daily_order_read = true;
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'Square order review read failure fails the report closed');
  \RoxyGrosses\Square::$fail_daily_order_read = false;
  \RoxyGrosses\Square::$daily_orders = [['id'=>'bad-exchange','state'=>'COMPLETED','closed_at'=>'not-a-date','returns'=>[['uid'=>'r']]]];
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'malformed Square return review data fails closed');
  \RoxyGrosses\Square::$daily_orders = [[
    'id'=>'bad-exchange','state'=>'COMPLETED','closed_at'=>'2026-10-03T06:30:00Z','returns'=>['malformed'],
    'tenders'=>[],
  ]];
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'malformed itemized Square return fails closed');
  \RoxyGrosses\Square::$daily_orders = [[
    'id' => 'sq-exchange', 'state' => 'COMPLETED', 'closed_at' => '2026-10-03T06:30:00Z',
    'returns' => [['uid' => 'return-1']], 'tenders' => [['amount_money' => ['amount' => 1250, 'currency' => 'USD']]],
  ]];

  $GLOBALS['wc_query_fail'] = true;
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'WooCommerce refund/order read failure fails the report closed');
  $GLOBALS['wc_query_fail'] = false;
  $GLOBALS['wc_pages']['shop_order_refund'][1][2] = new \RoxyGrosses\FakeRefund(24, 21, 'bad', new \DateTimeImmutable('2026-10-03T06:00:00Z'), false);
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'malformed manual Woo refund fails closed instead of being silently omitted');
  $GLOBALS['wc_pages']['shop_order_refund'][1][2] = new \RoxyGrosses\FakeRefund(24, 21, '7.50', new \DateTimeImmutable('2026-10-03T06:00:00Z'), false);

  \RoxyGrosses\Store::$completion_event = ['event_id' => 'refund-event-1', 'event_created_at' => '2026-10-03 03:15:00'];
  $event_report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert($event_report['totals']['square_refunded_cents'] === 200 && $event_report['refund_date_bases']['square'] === ['square_refund_completed_event']
    && $event_report['review_exceptions']['square_refunds_using_update_time_proxy'] === [],
    'matched Square refund.updated event dates the included refund without a proxy-date review item');
  \RoxyGrosses\Store::$fail_completion_event_read = true;
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'combined cashflow fails closed when matched Square completion evidence cannot be read');
  \RoxyGrosses\Store::$fail_completion_event_read = false;
  \RoxyGrosses\Store::$completion_event = null;

  // Refund updated_at and its signed completion event may fall on different
  // Pacific dates. Discover by event day rather than inventing a lookback.
  $cross_midnight_refund = [
    'id'=>'sq-cross-midnight','payment_id'=>'sq-cross-payment','location_id'=>'loc','status'=>'COMPLETED',
    'amount_money'=>['amount'=>275,'currency'=>'USD'],'updated_at'=>'2026-10-03T06:45:00Z',
  ];
  \RoxyGrosses\Store::$completion_event = ['event_id'=>'refund-event-cross','event_created_at'=>'2026-10-03 07:15:00'];
  \RoxyGrosses\Store::$completion_candidates = [[
    'event_id'=>'refund-event-cross','refund_id'=>'sq-cross-midnight','event_created_at'=>'2026-10-03 07:15:00',
    'refund_updated_at'=>'2026-10-03 06:45:00',
  ]];
  \RoxyGrosses\Square::$refunds = [];
  \RoxyGrosses\Square::$refunds_by_id = ['sq-cross-midnight'=>$cross_midnight_refund];
  \RoxyGrosses\Square::$refund_retrieve_calls = [];
  $cross_midnight = \RoxyGrosses\CashflowReport::for_day('2026-10-03');
  $assert($cross_midnight['totals']['square_refunded_cents'] === 275
    && $cross_midnight['refund_date_bases']['square'] === ['square_refund_completed_event'], 'event-day discovery includes a completed refund whose Square updated_at falls on the prior Pacific day');
  $assert($cross_midnight['refund_date_basis_counts']['square'] === ['square_refund_completed_event' => 1], 'cross-midnight report attributes provenance to its selected event day only');
  $assert(\RoxyGrosses\Square::$refund_retrieve_calls === ['sq-cross-midnight'], 'cross-day completion evidence retrieves the current Square refund once for exact validation');
  $assert(end(\RoxyGrosses\Store::$completion_candidate_calls) === ['2026-10-03 07:00:00','2026-10-04 07:00:00'], 'completion-event discovery uses exact UTC bounds for the selected Pacific day');
  \RoxyGrosses\Store::$completion_event = ['event_id'=>'different-event','event_created_at'=>'2026-10-03 07:15:00'];
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-03'), 'event-day candidate that does not exactly match the current refund fails closed');
  \RoxyGrosses\Store::$completion_event = ['event_id'=>'refund-event-cross','event_created_at'=>'2026-10-03 07:15:00'];
  \RoxyGrosses\Store::$completion_candidates = [];
  \RoxyGrosses\Square::$refunds = [$cross_midnight_refund];
  $prior_day = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert($prior_day['totals']['square_refunded_cents'] === 0 && $prior_day['refund_date_bases']['square'] === []
    && $prior_day['refund_date_basis_counts']['square'] === [], 'completion-event dating prevents the refund and its provenance being attributed again to the updated_at day');
  \RoxyGrosses\Store::$completion_event = null;
  \RoxyGrosses\Square::$refunds_by_id = [];
  \RoxyGrosses\Square::$refunds = [[
    'id' => 'sq-refund', 'payment_id' => 'sq-payment', 'location_id' => 'loc', 'status' => 'COMPLETED',
    'amount_money' => ['amount' => 200, 'currency' => 'USD'], 'updated_at' => '2026-10-03T03:00:00Z',
  ]];

  $provider_call_count_before_invalid_configuration = count(\RoxyGrosses\Square::$calls);
  \RoxyGrosses\Settings::$values['cashflow_woo_gateways'] = '';
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'combined report refuses to silently omit WooCommerce collections when gateway allow-list is empty');
  \RoxyGrosses\Settings::$values['cashflow_woo_gateways'] = "paypal\nstripe";
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-02-30'), 'invalid calendar date fails before provider reads');
  $assert(count(\RoxyGrosses\Square::$calls) === $provider_call_count_before_invalid_configuration, 'invalid date and missing gateway configuration cause no additional provider reads');
  \RoxyGrosses\Settings::$values['cashflow_woo_gateways'] = 'square_credit_card';
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'Square-backed Woo gateway cannot be added to separately reported Square collections');
  \RoxyGrosses\Settings::$values['cashflow_woo_gateways'] = 'stripe_link';
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'Stripe sub-method is not accepted as a standalone Woo gateway ID');
  \RoxyGrosses\Settings::$values['cashflow_woo_gateways'] = "paypal\nstripe\nbank_transfer";
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'unapproved Woo gateway cannot be included in financial projections');
  $assert(count(\RoxyGrosses\Square::$calls) === $provider_call_count_before_invalid_configuration, 'overlapping Square gateway configuration fails before provider reads');
  \RoxyGrosses\Settings::$values['cashflow_woo_gateways'] = "paypal\nstripe";

  $GLOBALS['wc_queries'] = [];
  $page_one = [];
  for ($id = 100; $id < 200; ++$id) $page_one[] = new \RoxyGrosses\FakeOrder($id, 'stripe', '0.01', 'tx-' . $id, new \DateTimeImmutable('2026-10-03T01:00:00Z'));
  $GLOBALS['wc_pages']['shop_order'] = [
    1 => $page_one,
    2 => [new \RoxyGrosses\FakeOrder(200, 'stripe', '0.01', 'tx-200', new \DateTimeImmutable('2026-10-03T01:00:00Z'))],
  ];
  $GLOBALS['wc_page_counts'] = ['shop_order' => 2, 'shop_order_refund' => 1];
  $paged = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert($paged['counts']['woocommerce_collections'] === 101 && $paged['totals']['woocommerce_collected_cents'] === 101, 'WooCommerce financial reads traverse every bounded page before returning complete totals');
  $assert(count(array_filter($GLOBALS['wc_queries'], static fn(array $query): bool => ($query['type'] ?? '') === 'shop_order')) === 2, 'WooCommerce read requests exactly both required order pages');

  $GLOBALS['wc_parent_orders'] = [];
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'missing WooCommerce refund parent fails closed instead of silently omitting gateway attribution');
  $GLOBALS['wc_parent_orders'] = [
    21 => new \RoxyGrosses\FakeOrder(21, 'stripe', '30.00', 'stripe-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
    99 => new \RoxyGrosses\FakeOrder(99, 'paypal', '20.00', 'paypal-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
  ];

  $GLOBALS['wc_queries'] = [];
  $GLOBALS['wc_pages']['shop_order'][1] = array_slice($page_one, 0, 99);
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'short nonterminal WooCommerce page fails closed rather than presenting partial totals');

  if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    fwrite(STDERR, sprintf("%d checks, %d failures\n", $checks, count($failures)));
    exit(1);
  }
  printf("%d checks passed\n", $checks);
}
