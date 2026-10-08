<?php
namespace RoxyGrosses {

/** Isolated API/settings fakes; no WordPress functions, persistence, or mail. */
final class Settings {
  public static string $timezone = 'America/Los_Angeles';
  public static function get_report_timezone(): string { return self::$timezone; }
}
final class FakeWooRefund {
  private $amount;
  public function __construct(
    private int $id,
    private int $order_id,
    private bool $payment_api,
    private string $currency,
    $amount,
    private ?\DateTimeInterface $created
  ) { $this->amount = $amount; }
  public function get_id(): int { return $this->id; }
  public function get_parent_id(): int { return $this->order_id; }
  public function get_refunded_payment(): bool { return $this->payment_api; }
  public function get_currency(): string { return $this->currency; }
  public function get_amount() { return $this->amount; }
  public function get_date_created(): ?\DateTimeInterface { return $this->created; }
}
final class FakeWooCollectionOrder {
  public function __construct(
    private int $id,
    private string $gateway,
    private bool $paid,
    private string $currency,
    private $total,
    private ?\DateTimeInterface $paid_at,
    private string $transaction_id
  ) {}
  public function get_id(): int { return $this->id; }
  public function get_payment_method(): string { return $this->gateway; }
  public function is_paid(): bool { return $this->paid; }
  public function get_currency(): string { return $this->currency; }
  public function get_total() { return $this->total; }
  public function get_date_paid(): ?\DateTimeInterface { return $this->paid_at; }
  public function get_transaction_id(): string { return $this->transaction_id; }
}
final class Square {
  public static array $orders = [];
  public static array $refunds = [];
  public static array $sources = [];
  public static array $calls = [];
  public static array $feed = [];
  public static function reset(): void { self::$orders = self::$refunds = self::$sources = self::$calls = self::$feed = []; }
  public static function list_payment_refunds_updated_between(string $start, string $end, ?float $deadline = null): array { self::$calls['feed'] = [$start, $end]; return self::$feed; }
  public static function fetch_orders_updated_between(string $start, string $end, ?float $deadline = null, bool $returns_only = false): array {
    self::$calls['window'] = [$start, $end];
    self::$calls['returns_only'] = $returns_only;
    self::$calls['fetch'] = (self::$calls['fetch'] ?? 0) + 1;
    return self::$orders;
  }
  public static function retrieve_payment_refund(string $id, ?float $deadline = null): array {
    self::$calls['refund_ids'][] = $id;
    if (!isset(self::$refunds[$id])) throw new \RuntimeException('Missing fake payment refund');
    return self::$refunds[$id];
  }
  public static function retrieve_orders(array $ids, ?float $deadline = null): array {
    self::$calls['source_batches'][] = $ids;
    if (isset(self::$calls['missing_source'])) throw new \RuntimeException('A referenced Square source order is unavailable; no correction was calculated.');
    $result = [];
    foreach ($ids as $id) if (isset(self::$sources[$id])) $result[] = self::$sources[$id];
    return $result;
  }
}

}
namespace {
  if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  $root = isset($argv[1]) ? rtrim($argv[1], "\\/") : dirname(__DIR__);
  $returns_path = $argv[2] ?? ($root . '/includes/modules/grosses/includes/class-roxy-grosses-returns.php');
  $snapshot_path = $argv[3] ?? ($root . '/includes/modules/grosses/includes/class-roxy-grosses-refund-snapshot.php');
  foreach ([$returns_path, $snapshot_path] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "Missing candidate file: {$path}\n"); exit(2); }
    require_once $path;
  }

  $checks = 0;
  $failures = [];
  $assert = static function ($condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) $failures[] = $message;
  };
  $expect_throw = static function (callable $fn, string $message) use ($assert): void {
    try { $fn(); $assert(false, $message); }
    catch (\Throwable $error) { $assert(true, $message); }
  };
  $sale = static function (string $id, string $closed_at = '2026-08-12T19:00:00Z'): array {
    return ['id' => $id, 'closed_at' => $closed_at, 'state' => 'COMPLETED', 'line_items' => [
      ['uid' => 'line-' . $id, 'item_type' => 'ITEM', 'quantity' => '3', 'name' => 'Ticket'],
    ]];
  };
  $ret = static function (string $order_id, string $source_id, string $item_uid, string $refund_id, string $payment_id, string $quantity = '1'): array {
    return [
      'id' => $order_id, 'state' => 'COMPLETED',
      'refunds' => [['id' => $refund_id, 'tender_id' => $payment_id]],
      'returns' => [['source_order_id' => $source_id, 'return_line_items' => [[
        'uid' => 'return-' . $item_uid, 'source_line_item_uid' => 'line-' . $source_id,
        'quantity' => $quantity, 'item_type' => 'ITEM',
      ]]]],
    ];
  };
  $snapshot_class = '\\RoxyGrosses\\RefundSnapshot';
  $reset = static function (): void { \RoxyGrosses\Square::reset(); \RoxyGrosses\Settings::$timezone = 'America/Los_Angeles'; };
  $setup_source = static function (string $id, string $closed_at = '2026-08-12T19:00:00Z'): void {
    \RoxyGrosses\Square::$sources[$id] = ['id' => $id, 'state' => 'COMPLETED', 'closed_at' => $closed_at];
  };

  // Window begins at original earliest local sale date, not report-window recency.
  $reset(); $setup_source('sale-A');
  \RoxyGrosses\Square::$orders = [$ret('return-A', 'sale-A', 'A', 'refund-A', 'payment-A')];
  \RoxyGrosses\Square::$refunds['payment-A_refund-A'] = ['id' => 'payment-A_refund-A', 'payment_id' => 'payment-A', 'status' => 'COMPLETED'];
  $snapshot = $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z'));
  $assert(\RoxyGrosses\Square::$calls['window'][0] === '2026-08-01T00:00:00-07:00', 'discovery must start at earliest original date in site timezone');
  $assert(\RoxyGrosses\Square::$calls['window'][1] === '2026-08-20T12:00:00+00:00', 'discovery must end at supplied now');
  $assert(\RoxyGrosses\Square::$calls['returns_only'] === true, 'discovery must request returns only');
  $assert(\RoxyGrosses\Square::$calls['source_batches'] === [['sale-A']], 'source sales must be retrieved in a batch');
  $assert($snapshot->original_sale_dates() === ['2026-08-12'], 'original sale date should be converted to configured site timezone');
  $result = $snapshot->reconcile_sale_day('2026-08-12', [$sale('sale-A')]);
  $assert($result['orders'][0]['line_items'][0]['quantity'] === '2' && $result['issues'] === [], 'completed confirmed payment refund should reduce only its matching sale');

  $reset(); $setup_source('sale-oct', '2026-10-03T22:00:00Z');
  \RoxyGrosses\Square::$orders = [$ret('return-oct', 'sale-oct', 'oct', 'refund-oct', 'payment-oct')];
  \RoxyGrosses\Square::$refunds['payment-oct_refund-oct'] = ['id' => 'payment-oct_refund-oct', 'payment_id' => 'payment-oct', 'status' => 'COMPLETED'];
  $snapshot = $snapshot_class::load('2026-10-01', new \DateTimeImmutable('2026-10-10T12:00:00Z'));
  $assert($snapshot->original_sale_dates() === ['2026-10-03'], 'RFC3339 UTC close time must map to original Pacific sale date');
  $result = $snapshot->reconcile_sale_day('2026-10-03', [$sale('sale-oct', '2026-10-03T22:00:00Z')]);
  $assert($result['orders'][0]['line_items'][0]['quantity'] === '2', 'sale-day reconciliation should accept a correctly windowed RFC3339 sale');
  $expect_throw(static fn() => $snapshot->reconcile_sale_day('2026-10-04', [$sale('sale-oct', '2026-10-03T22:00:00Z')]), 'sale supplied outside requested original-date window must fail closed');
  $invalid_timestamp_sale = $sale('sale-oct', '2026-10-03T22:00:00Z');
  $invalid_timestamp_sale['closed_at'] = '2026-10-03 22:00:00Z';
  $expect_throw(static fn() => $snapshot->reconcile_sale_day('2026-10-03', [$invalid_timestamp_sale]), 'non-RFC3339 sale timestamp must fail closed');

  // Payment statuses are deduped by tender+refund identity; only COMPLETED is trusted.
  $reset(); $setup_source('sale-A'); $setup_source('sale-B'); $setup_source('sale-C'); $setup_source('sale-D');
  \RoxyGrosses\Square::$orders = [
    $ret('return-completed', 'sale-A', 'a', 'same-refund', 'same-payment'),
    $ret('return-pending', 'sale-B', 'b', 'pending-refund', 'payment-B'),
    $ret('return-failed', 'sale-C', 'c', 'failed-refund', 'payment-C'),
    array_replace($ret('return-unverified', 'sale-D', 'd', 'unused-refund', 'payment-D'), ['refunds' => []]),
  ];
  \RoxyGrosses\Square::$refunds = [
    'same-payment_same-refund' => ['id' => 'same-payment_same-refund', 'payment_id' => 'same-payment', 'status' => 'COMPLETED'],
    'payment-B_pending-refund' => ['id' => 'payment-B_pending-refund', 'payment_id' => 'payment-B', 'status' => 'PENDING'],
    'payment-C_failed-refund' => ['id' => 'payment-C_failed-refund', 'payment_id' => 'payment-C', 'status' => 'FAILED'],
  ];
  $snapshot = $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z'));
  $assert(count(\RoxyGrosses\Square::$calls['refund_ids']) === 3, 'each distinct payment refund should be checked, while unverified has none');
  $assert(count(array_filter(\RoxyGrosses\Square::$calls['refund_ids'], static fn($id) => $id === 'same-payment_same-refund')) === 1, 'duplicate payment refund lookup must be deduplicated');
  $complete_day_sales = array_map($sale, ['sale-A', 'sale-B', 'sale-C', 'sale-D']);
  $result = $snapshot->reconcile_sale_day('2026-08-12', $complete_day_sales);
  foreach (['sale-B', 'sale-C', 'sale-D'] as $offset => $id) {
    $sale_row = $sale($id);
    $assert($result['orders'][$offset + 1] === $sale_row, "{$id} pending/failed/unverified return must not reduce quantities");
    $assert(count(array_filter($result['issues'], static fn($issue) => $issue['source_order_id'] === $id && $issue['reason'] === 'refund_unverified')) === ($id === 'sale-D' ? 1 : 0), "{$id} only unverifiable returns require a blocking issue; proven pending/failed states remain unchanged");
  }
  $assert(count($result['pending']) === 1 && $snapshot->pending_source_dates() === ['2026-08-12'], 'pending refunds retain original source date for durable retry without suppressing already-collected sales');

  // Cash refunds are separate dated events, attributed by verified completion time, not source sale day.
  $reset(); $setup_source('sale-financial');
  \RoxyGrosses\Square::$orders = [$ret('return-financial', 'sale-financial', 'f', 'refund-financial', 'payment-financial')];
  \RoxyGrosses\Square::$refunds['payment-financial_refund-financial'] = [
    'id' => 'payment-financial_refund-financial', 'payment_id' => 'payment-financial', 'order_id' => 'return-financial',
    'location_id' => 'loc-1', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 1234, 'currency' => 'USD'],
    'created_at' => '2026-08-13T06:00:00Z', 'updated_at' => '2026-08-14T06:30:00Z',
  ];
  $snapshot = $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z'));
  $financial = $snapshot->completed_return_financial_refunds();
  $assert(count($financial) === 1 && $financial[0]['amount_cents'] === 1234 && $financial[0]['refund_date'] === '2026-08-13' && $financial[0]['refund_updated_at'] === '2026-08-14 06:30:00', 'completed refund uses verified cents and UTC update timestamp to derive Pacific refund date');
  $assert($financial[0]['order_id'] === 'return-financial' && $financial[0]['payment_id'] === 'payment-financial', 'financial refund event retains immutable Square identities');
  $unlinked_refund = [
    'id' => 'refund-unlinked', 'payment_id' => 'payment-unlinked', 'location_id' => 'loc-2',
    'status' => 'COMPLETED', 'amount_money' => ['amount' => 250, 'currency' => 'USD'],
    'updated_at' => '2026-08-14T07:30:00Z',
  ];
  $all_financial = $snapshot_class::from_financial_refund_feed([$unlinked_refund, [
    'id' => 'refund-pending', 'payment_id' => 'payment-pending', 'location_id' => 'loc-2', 'status' => 'PENDING',
  ]])->completed_financial_refunds();
  $assert(count($all_financial) === 1 && $all_financial[0]['refund_id'] === 'refund-unlinked' && $all_financial[0]['order_id'] === null, 'unlinked completed Square refund is retained as a financial event while pending refund is excluded');
  $assert($all_financial[0]['refund_date'] === '2026-08-14' && $all_financial[0]['amount_cents'] === 250, 'unlinked refund keeps exact cents and uses the Pacific date derived from updated_at');
  $expect_throw(static fn() => $snapshot_class::from_financial_refund_feed([$unlinked_refund, $unlinked_refund]), 'duplicate financial refund identities fail closed');
  $expect_throw(static fn() => $snapshot_class::from_financial_refund_feed([array_replace($unlinked_refund, ['payment_id' => ''])])->completed_financial_refunds(), 'completed refund without a payment identity fails closed');
  $expect_throw(static fn() => $snapshot_class::from_financial_refund_feed([array_replace($unlinked_refund, ['amount_money' => ['amount' => '250', 'currency' => 'USD']])])->completed_financial_refunds(), 'non-integer refund cents fail closed');
  $expect_throw(static fn() => $snapshot_class::from_financial_refund_feed([array_replace($unlinked_refund, ['updated_at' => '2026-02-30T07:30:00Z'])])->completed_financial_refunds(), 'invalid unlinked refund timestamp fails closed');
  $woo_events = \RoxyGrosses\WooRefundEvents::from_order_refunds([
    new \RoxyGrosses\FakeWooRefund(11, 101, true, 'USD', '-12.34', new \DateTimeImmutable('2026-08-14T07:30:00Z')),
    new \RoxyGrosses\FakeWooRefund(12, 102, false, 'USD', '8.00', new \DateTimeImmutable('2026-08-14T08:30:00Z')),
  ]);
  $assert(count($woo_events) === 1 && $woo_events[0]['refund_id'] === 11 && $woo_events[0]['order_id'] === 101, 'Woo financial projection includes only gateway-processed refunds with stable IDs');
  $assert($woo_events[0]['amount_cents'] === 1234 && $woo_events[0]['refund_date'] === '2026-08-14' && $woo_events[0]['payment_api_processed'] === true, 'Woo refund preserves exact cents and converts created timestamp into the Pacific refund day');
  $expect_throw(static fn() => \RoxyGrosses\WooRefundEvents::from_order_refunds([new \RoxyGrosses\FakeWooRefund(11, 101, true, 'USD', '1.001', new \DateTimeImmutable('2026-08-14T07:30:00Z'))]), 'Woo refund fractional cents fail closed');
  $expect_throw(static fn() => \RoxyGrosses\WooRefundEvents::from_order_refunds([new \RoxyGrosses\FakeWooRefund(11, 101, true, 'CAD', '1.00', new \DateTimeImmutable('2026-08-14T07:30:00Z'))]), 'Woo refund non-USD currency fails closed');
  $expect_throw(static fn() => \RoxyGrosses\WooRefundEvents::from_order_refunds([new \RoxyGrosses\FakeWooRefund(11, 101, true, 'USD', '1.00', null)]), 'Woo refund without creation timestamp fails closed');
  $woo_collections = \RoxyGrosses\WooCollectionEvents::from_orders([
    new \RoxyGrosses\FakeWooCollectionOrder(201, 'stripe', true, 'USD', '42.37', new \DateTimeImmutable('2026-10-03T06:30:00Z'), 'ch_private_1'),
    new \RoxyGrosses\FakeWooCollectionOrder(202, 'stripe', false, 'USD', '99.00', new \DateTimeImmutable('2026-10-03T06:40:00Z'), 'pending_1'),
    new \RoxyGrosses\FakeWooCollectionOrder(203, 'cod', true, 'USD', '25.00', new \DateTimeImmutable('2026-10-03T06:45:00Z'), 'cash_1'),
    new \RoxyGrosses\FakeWooCollectionOrder(204, 'stripe', true, 'USD', '0.00', new \DateTimeImmutable('2026-10-03T06:50:00Z'), 'free_1'),
  ], ['stripe']);
  $assert(count($woo_collections) === 1 && $woo_collections[0]['amount_cents'] === 4237, 'Woo collection projection includes positive paid orders only from the explicit online gateway allow-list');
  $assert($woo_collections[0]['transaction_id'] === 'ch_private_1' && $woo_collections[0]['collection_date'] === '2026-10-02', 'Woo collection retains transaction identity and Pacific paid date');
  $expect_throw(static fn() => \RoxyGrosses\WooCollectionEvents::from_orders([new \RoxyGrosses\FakeWooCollectionOrder(201, 'stripe', true, 'USD', '1.00', null, 'tx')], ['stripe']), 'paid Woo collection without paid timestamp fails closed');
  $expect_throw(static fn() => \RoxyGrosses\WooCollectionEvents::from_orders([new \RoxyGrosses\FakeWooCollectionOrder(201, 'stripe', true, 'USD', '1.001', new \DateTimeImmutable('2026-10-03T06:30:00Z'), 'tx')], ['stripe']), 'Woo collection fractional cents fail closed');
  $expect_throw(static fn() => \RoxyGrosses\WooCollectionEvents::from_orders([new \RoxyGrosses\FakeWooCollectionOrder(201, 'stripe', true, 'CAD', '1.00', new \DateTimeImmutable('2026-10-03T06:30:00Z'), 'tx')], ['stripe']), 'Woo collection non-USD order fails closed');
  $expect_throw(static fn() => \RoxyGrosses\WooCollectionEvents::from_orders([
    new \RoxyGrosses\FakeWooCollectionOrder(201, 'stripe', true, 'USD', '1.00', new \DateTimeImmutable('2026-10-03T06:30:00Z'), 'same_tx'),
    new \RoxyGrosses\FakeWooCollectionOrder(202, 'stripe', true, 'USD', '2.00', new \DateTimeImmutable('2026-10-03T06:31:00Z'), 'same_tx'),
  ], ['stripe']), 'duplicate Woo payment transaction fails closed');
  $collection_events = \RoxyGrosses\SquareCollectionEvents::from_orders([
    ['id' => 'order-1', 'location_id' => 'loc-1', 'state' => 'COMPLETED', 'total_money' => ['amount' => 12345, 'currency' => 'USD'], 'closed_at' => '2026-10-03T06:30:00Z', 'tenders' => [['payment_id' => 'payment-1']]],
    ['id' => 'order-2', 'location_id' => 'loc-1', 'state' => 'COMPLETED', 'total_money' => ['amount' => 0, 'currency' => 'USD'], 'closed_at' => '2026-10-03T07:30:00Z', 'tenders' => [['id' => 'payment-2']]],
    ['id' => 'order-3', 'location_id' => 'loc-1', 'state' => 'COMPLETED', 'total_money' => ['amount' => 0, 'currency' => 'USD'], 'closed_at' => '2026-10-03T07:35:00Z', 'tenders' => [[]]],
    ['id' => 'order-4', 'state' => 'CANCELED'],
  ]);
  $assert(count($collection_events) === 3, 'collection projection includes completed orders only');
  $assert($collection_events[0]['amount_cents'] === 12345 && $collection_events[0]['payment_ids'] === ['payment-1'] && $collection_events[0]['tender_ids_complete'] === true, 'completed Square collection preserves exact cents and payment deduplication identity');
  $assert($collection_events[0]['collection_date'] === '2026-10-02' && $collection_events[0]['collected_at'] === '2026-10-03 06:30:00', 'collection date uses Pacific timezone while retaining UTC close time');
  $assert($collection_events[1]['payment_ids'] === ['payment-2'] && $collection_events[1]['tender_ids_complete'] === true, 'Square tender id is used as the payment identity when payment_id is absent');
  $assert($collection_events[2]['payment_ids'] === [] && $collection_events[2]['tender_ids_complete'] === false, 'tender without either stable identity is explicitly marked incomplete');
  $expect_throw(static fn() => \RoxyGrosses\SquareCollectionEvents::from_orders([['id' => 'x', 'location_id' => 'l', 'state' => 'COMPLETED', 'total_money' => ['amount' => 1, 'currency' => 'USD'], 'closed_at' => '2026-10-03T07:30:00Z'], ['id' => 'x', 'location_id' => 'l', 'state' => 'COMPLETED', 'total_money' => ['amount' => 1, 'currency' => 'USD'], 'closed_at' => '2026-10-03T07:30:00Z']]), 'duplicate completed Square order identity fails closed');
  $expect_throw(static fn() => \RoxyGrosses\SquareCollectionEvents::from_orders([['id' => 'x', 'location_id' => 'l', 'state' => 'COMPLETED', 'total_money' => ['amount' => 1, 'currency' => 'USD'], 'closed_at' => '2026-10-03T07:30:00Z', 'tenders' => [['payment_id' => 'p', 'id' => 'p'], ['payment_id' => 'p', 'id' => 'p']]]]), 'duplicate tender payment identity fails closed');
  $expect_throw(static fn() => \RoxyGrosses\SquareCollectionEvents::from_orders([['id' => 'x', 'location_id' => 'l', 'state' => 'COMPLETED', 'total_money' => ['amount' => 1, 'currency' => 'USD'], 'closed_at' => '2026-10-03T07:30:00Z', 'tenders' => [['payment_id' => 'payment-a', 'id' => 'tender-b']]]]), 'tender and payment IDs that disagree fail closed');
  $expect_throw(static fn() => \RoxyGrosses\SquareCollectionEvents::from_orders([
    ['id' => 'x', 'location_id' => 'l', 'state' => 'COMPLETED', 'total_money' => ['amount' => 1, 'currency' => 'USD'], 'closed_at' => '2026-10-03T07:30:00Z', 'tenders' => [['payment_id' => 'p']]],
    ['id' => 'y', 'location_id' => 'l', 'state' => 'COMPLETED', 'total_money' => ['amount' => 1, 'currency' => 'USD'], 'closed_at' => '2026-10-03T07:31:00Z', 'tenders' => [['payment_id' => 'p']]],
  ]), 'payment identity reused across Square orders fails closed');
  $expect_throw(static fn() => \RoxyGrosses\SquareCollectionEvents::from_orders([['id' => 'x', 'location_id' => 'l', 'state' => 'COMPLETED', 'total_money' => ['amount' => '1', 'currency' => 'USD'], 'closed_at' => '2026-10-03T07:30:00Z']]), 'non-integer Square collection cents fail closed');
  $expect_throw(static fn() => \RoxyGrosses\SquareCollectionEvents::from_orders([['id' => 'x', 'location_id' => 'l', 'state' => 'COMPLETED', 'total_money' => ['amount' => 1, 'currency' => 'CAD'], 'closed_at' => '2026-10-03T07:30:00Z']]), 'non-USD Square collection fails closed');
  $expect_throw(static fn() => \RoxyGrosses\SquareCollectionEvents::from_orders([['id' => 'x', 'location_id' => 'l', 'state' => 'COMPLETED', 'total_money' => ['amount' => 1, 'currency' => 'USD'], 'closed_at' => '2026-02-30T07:30:00Z']]), 'invalid Square collection date fails closed');
  $expect_throw(static fn() => \RoxyGrosses\SquareCollectionEvents::from_orders([['id' => 'x', 'location_id' => 'l', 'state' => 'COMPLETED', 'total_money' => ['amount' => 1, 'currency' => 'USD'], 'closed_at' => '2026-10-03 07:30:00Z']]), 'non-RFC3339 Square collection timestamp fails closed');
  \RoxyGrosses\Square::$refunds['payment-financial_refund-financial']['updated_at'] = '2026-02-30T06:30:00Z';
  $expect_throw(static fn() => $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z'))->completed_return_financial_refunds(), 'invalid completed refund date must fail closed');
  \RoxyGrosses\Square::$refunds['payment-financial_refund-financial']['updated_at'] = '2026-08-14T06:30:00Z';
  \RoxyGrosses\Square::$refunds['payment-financial_refund-financial']['amount_money']['currency'] = 'CAD';
  $expect_throw(static fn() => $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z'))->completed_return_financial_refunds(), 'unsupported financial refund currency must fail closed');

  // Dated filtering must keep returns for this day, not poison it with another day's return.
  $reset(); $setup_source('sale-A', '2026-08-12T19:00:00Z'); $setup_source('sale-B', '2026-08-13T19:00:00Z');
  \RoxyGrosses\Square::$orders = [
    $ret('return-today', 'sale-A', 'a', 'refund-today', 'payment-today'),
    $ret('return-tomorrow', 'sale-B', 'b', 'refund-tomorrow', 'payment-tomorrow'),
  ];
  \RoxyGrosses\Square::$refunds = [
    'payment-today_refund-today' => ['id' => 'payment-today_refund-today', 'payment_id' => 'payment-today', 'status' => 'COMPLETED'],
    'payment-tomorrow_refund-tomorrow' => ['id' => 'payment-tomorrow_refund-tomorrow', 'payment_id' => 'payment-tomorrow', 'status' => 'FAILED'],
  ];
  $snapshot = $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z'));
  $result = $snapshot->reconcile_sale_day('2026-08-12', [$sale('sale-A')]);
  $assert($result['orders'][0]['line_items'][0]['quantity'] === '2' && $result['issues'] === [], 'another sale date failure must not block this date reconciliation');

  // One payment refund cannot be counted against two separate return orders.
  $reset(); $setup_source('sale-A');
  \RoxyGrosses\Square::$orders = [$ret('return-owner-A', 'sale-A', 'a', 'refund-owner', 'payment-owner'), $ret('return-owner-B', 'sale-A', 'b', 'refund-owner', 'payment-owner')];
  \RoxyGrosses\Square::$refunds['payment-owner_refund-owner'] = ['id' => 'payment-owner_refund-owner', 'payment_id' => 'payment-owner', 'status' => 'COMPLETED'];
  $expect_throw(static fn() => $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'reusing one payment refund across return orders must fail closed');

  $reset(); $setup_source('sale-A');
  \RoxyGrosses\Square::$orders = [$ret('return-owner-A', 'sale-A', 'a', 'refund-owner', 'payment-owner')];
  \RoxyGrosses\Square::$refunds['payment-owner_refund-owner'] = ['id' => 'payment-owner_refund-owner', 'payment_id' => 'wrong-payment', 'status' => 'COMPLETED'];
  $expect_throw(static fn() => $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'mismatched payment refund owner must fail closed');
  \RoxyGrosses\Square::$refunds['payment-owner_refund-owner'] = ['id' => 'payment-owner_refund-owner', 'status' => 'COMPLETED'];
  $expect_throw(static fn() => $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'missing payment refund owner must fail closed');
  \RoxyGrosses\Square::$refunds['payment-owner_refund-owner'] = ['id' => 'payment-owner_refund-owner', 'payment_id' => 'payment-owner', 'order_id' => 'wrong-return', 'status' => 'COMPLETED'];
  $expect_throw(static fn() => $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'mismatched payment refund return order must fail closed');

  // A source that cannot be found, or whose original timestamp is invalid, fails the load closed.
  $reset(); \RoxyGrosses\Square::$orders = [$ret('return-missing', 'missing-source', 'x', 'refund-x', 'payment-x')];
  \RoxyGrosses\Square::$refunds['payment-x_refund-x'] = ['id' => 'payment-x_refund-x', 'payment_id' => 'payment-x', 'status' => 'COMPLETED'];
  \RoxyGrosses\Square::$calls['missing_source'] = true;
  $expect_throw(static fn() => $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'missing source lookup must fail closed');
  $reset(); $setup_source('sale-bad-date', 'not-a-date');
  \RoxyGrosses\Square::$orders = [$ret('return-bad-date', 'sale-bad-date', 'x', 'refund-x', 'payment-x')];
  \RoxyGrosses\Square::$refunds['payment-x_refund-x'] = ['id' => 'payment-x_refund-x', 'payment_id' => 'payment-x', 'status' => 'COMPLETED'];
  $expect_throw(static fn() => $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'invalid original close date must fail closed');
  $expect_throw(static fn() => $snapshot_class::load('2026-02-30', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'invalid earliest sale date must fail closed');

  // A verified return whose source is not in the supplied dated sales remains a surfaced issue.
  $reset(); $setup_source('sale-outside', '2026-08-12T19:00:00Z');
  \RoxyGrosses\Square::$orders = [$ret('return-outside', 'sale-outside', 'o', 'refund-o', 'payment-o')];
  \RoxyGrosses\Square::$refunds['payment-o_refund-o'] = ['id' => 'payment-o_refund-o', 'payment_id' => 'payment-o', 'status' => 'COMPLETED'];
  $snapshot = $snapshot_class::load('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z'));
  $result = $snapshot->reconcile_sale_day('2026-08-12', [$sale('different-sale')]);
  $assert($result['orders'] === [$sale('different-sale')] && count($result['issues']) === 1, 'unknown source on relevant date must be surfaced without altering unrelated sale');
  $assert($result['issues'][0]['reason'] === 'unknown_source_order', 'unknown source should retain its exact issue reason');

  // Historical refund feed reuses authoritative payment objects and batches returns.
  $reset(); $setup_source('sale-A');
  $return_order = $ret('return-A', 'sale-A', 'a', 'refund-A', 'payment-A');
  \RoxyGrosses\Square::$sources['return-A'] = $return_order;
  \RoxyGrosses\Square::$feed = [['id'=>'payment-A_refund-A','payment_id'=>'payment-A','order_id'=>'return-A','status'=>'COMPLETED']];
  $snapshot = $snapshot_class::load_from_refund_feed('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z'));
  $result = $snapshot->reconcile_sale_day('2026-08-12', [$sale('sale-A')]);
  $assert($result['orders'][0]['line_items'][0]['quantity'] === '2' && !$result['issues'], 'refund-specific historical feed produces the same itemized correction');
  $assert(\RoxyGrosses\Square::$calls['source_batches'] === [['return-A'],['sale-A']], 'historical feed batches return and source orders independently');
  $assert(empty(\RoxyGrosses\Square::$calls['refund_ids']) && empty(\RoxyGrosses\Square::$calls['fetch']), 'historical feed avoids per-refund GETs and scanning every ordinary sale');
  \RoxyGrosses\Square::$feed[] = \RoxyGrosses\Square::$feed[0];
  $expect_throw(static fn() => $snapshot_class::load_from_refund_feed('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'duplicate historical payment identity fails closed');
  array_pop(\RoxyGrosses\Square::$feed);
  \RoxyGrosses\Square::$sources['return-A']['refunds'] = 'malformed';
  $expect_throw(static fn() => $snapshot_class::load_from_refund_feed('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'malformed historical refund-reference list fails closed');
  \RoxyGrosses\Square::$sources['return-A']['refunds'] = ['wrong-key'=>$return_order['refunds'][0]];
  $expect_throw(static fn() => $snapshot_class::load_from_refund_feed('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'associative historical refund-reference list fails closed');
  \RoxyGrosses\Square::$sources['return-A']['refunds'] = ['malformed'];
  $expect_throw(static fn() => $snapshot_class::load_from_refund_feed('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'malformed historical refund-reference identity fails closed');
  \RoxyGrosses\Square::$sources['return-A'] = $return_order;
  \RoxyGrosses\Square::$feed[0]['id'] = 'wrong-identity';
  $expect_throw(static fn() => $snapshot_class::load_from_refund_feed('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'unmatched historical payment refund identity fails closed');
  \RoxyGrosses\Square::$feed[0]['id'] = 'payment-A_refund-A';
  unset(\RoxyGrosses\Square::$feed[0]['order_id']);
  $expect_throw(static fn() => $snapshot_class::load_from_refund_feed('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'missing historical return-order reference is surfaced for review');
  \RoxyGrosses\Square::$feed[0]['status'] = 'FAILED';
  $snapshot = $snapshot_class::load_from_refund_feed('2026-08-01', new \DateTimeImmutable('2026-08-20T12:00:00Z'));
  $assert($snapshot->original_sale_dates() === [], 'proven failed historical refunds need no source correction');
  $expect_throw(static fn() => $snapshot_class::load_from_refund_feed('2026-02-30', new \DateTimeImmutable('2026-08-20T12:00:00Z')), 'invalid historical feed calendar date fails before discovery');

  if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    fwrite(STDERR, sprintf("%d checks, %d failures\n", $checks, count($failures)));
    exit(1);
  }
  printf("%d checks passed\n", $checks);
}
