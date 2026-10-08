<?php
declare(strict_types=1);

namespace RoxyGrosses {
  final class Settings {
    public static array $values = ['cashflow_woo_gateways' => 'stripe'];
    public static function get_report_timezone(): string { return 'America/Los_Angeles'; }
    public static function get(string $key, $default = '') { return self::$values[$key] ?? $default; }
    public static function line_list(string $value): array { return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: []), 'strlen')); }
  }
  final class Store {
    public static ?array $completion_event = null;
    public static bool $fail_completion_event_read = false;
    public static function refund_completion_event(array $refund, string $updated): ?array {
      if (self::$fail_completion_event_read) throw new \RuntimeException('Injected completion-evidence read failure.');
      return self::$completion_event;
    }
  }
  final class Square {
    public static array $orders = [];
    public static array $payments = [];
    public static array $refunds = [];
    public static array $calls = [];
    public static function fetch_orders_for_date(string $date): array { self::$calls[] = ['orders', $date]; return self::$orders; }
    public static function list_payments_created_between(string $start, string $end): array { self::$calls[] = ['payments', $start, $end]; return self::$payments; }
    public static function list_payment_refunds_updated_between(string $start, string $end): array { self::$calls[] = ['refunds', $start, $end]; return self::$refunds; }
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
    $page = (int) ($args['page'] ?? 1);
    $type = (string) ($args['type'] ?? '');
    $rows = $GLOBALS['wc_pages'][$type][$page] ?? [];
    $pages = $GLOBALS['wc_page_counts'][$type] ?? 1;
    return (object) ['orders' => $rows, 'total_pages' => $pages];
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
    'amount_money' => ['amount' => 200, 'currency' => 'USD'], 'updated_at' => '2026-10-03T03:00:00Z',
  ]];
  $GLOBALS['wc_pages'] = [
    'shop_order' => [1 => [new \RoxyGrosses\FakeOrder(21, 'stripe', '30.00', 'stripe-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z'))]],
    'shop_order_refund' => [1 => [
      new \RoxyGrosses\FakeRefund(22, 21, '5.00', new \DateTimeImmutable('2026-10-03T04:00:00Z')),
      new \RoxyGrosses\FakeRefund(23, 99, '10.00', new \DateTimeImmutable('2026-10-03T05:00:00Z')),
    ]],
  ];
  $GLOBALS['wc_parent_orders'] = [
    21 => new \RoxyGrosses\FakeOrder(21, 'stripe', '30.00', 'stripe-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
    99 => new \RoxyGrosses\FakeOrder(99, 'paypal', '20.00', 'paypal-charge', new \DateTimeImmutable('2026-10-03T01:00:00Z')),
  ];
  $GLOBALS['wc_page_counts'] = ['shop_order' => 1, 'shop_order_refund' => 1];
  $report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert($report['totals']['square_collected_cents'] === 1500 && $report['totals']['woocommerce_collected_cents'] === 3000, 'daily cashflow includes completed Square payment and allow-listed Woo paid amount, excluding non-completed payments');
  $assert($report['totals']['square_refunded_cents'] === 200 && $report['totals']['woocommerce_refunded_cents'] === 500 && $report['totals']['net_cents'] === 3800, 'daily cashflow subtracts approved-gateway provider refunds on their refund date and omits unapproved-gateway refunds');
  $assert($report['counts'] === ['square_collections'=>1,'woocommerce_collections'=>1,'square_refunds'=>1,'woocommerce_refunds'=>1], 'daily report counts only provider events attributed to selected local date');
  $assert($report['refund_date_bases']['square'] === ['square_updated_at_proxy'] && $report['refund_date_bases']['woocommerce'] === ['woocommerce_refund_creation_proxy'], 'daily report retains explicit refund-date provenance');
  $assert($GLOBALS['wc_queries'][0]['date_paid'] === '1790924400...1791010799' && $GLOBALS['wc_queries'][1]['date_created'] === '1790924400...1791010799', 'WooCommerce queries use exact UTC bounds for the report timezone day');
  $assert(\RoxyGrosses\Square::$calls[0] === ['payments', '2026-10-02T07:00:00Z', '2026-10-03T07:00:00Z'], 'Square payment read uses exact UTC boundaries for the selected report-timezone day');

  \RoxyGrosses\Store::$completion_event = ['event_id' => 'refund-event-1', 'event_created_at' => '2026-10-03 03:15:00'];
  $event_report = \RoxyGrosses\CashflowReport::for_day('2026-10-02');
  $assert($event_report['totals']['square_refunded_cents'] === 200 && $event_report['refund_date_bases']['square'] === ['square_refund_completed_event'], 'combined cashflow uses a matched Square refund.updated completion event as its dated refund evidence');
  \RoxyGrosses\Store::$fail_completion_event_read = true;
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'combined cashflow fails closed when matched Square completion evidence cannot be read');
  \RoxyGrosses\Store::$fail_completion_event_read = false;
  \RoxyGrosses\Store::$completion_event = null;

  $provider_call_count_before_invalid_configuration = count(\RoxyGrosses\Square::$calls);
  \RoxyGrosses\Settings::$values['cashflow_woo_gateways'] = '';
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'combined report refuses to silently omit WooCommerce collections when gateway allow-list is empty');
  \RoxyGrosses\Settings::$values['cashflow_woo_gateways'] = 'stripe';
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-02-30'), 'invalid calendar date fails before provider reads');
  $assert(count(\RoxyGrosses\Square::$calls) === $provider_call_count_before_invalid_configuration, 'invalid date and missing gateway configuration cause no additional provider reads');
  \RoxyGrosses\Settings::$values['cashflow_woo_gateways'] = 'square_credit_card';
  $expect_throw(static fn() => \RoxyGrosses\CashflowReport::for_day('2026-10-02'), 'Square-backed Woo gateway cannot be added to separately reported Square collections');
  $assert(count(\RoxyGrosses\Square::$calls) === $provider_call_count_before_invalid_configuration, 'overlapping Square gateway configuration fails before provider reads');
  \RoxyGrosses\Settings::$values['cashflow_woo_gateways'] = 'stripe';

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
