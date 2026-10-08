<?php
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

class FixtureWpdb {
  public string $prefix = 'wp_';
  public string $last_error = '';
  public ?array $result = ['event_id' => 'evt-1', 'event_created_at' => '2026-10-08 19:30:00'];
  public array $results = [];
  public array $prepared_args = [];
  public string $query = '';
  public string $results_query = '';
  public function prepare(string $query, ...$args): string { $this->prepared_args = $args; return $query; }
  public function get_row(string $query, $output = null) { $this->query = $query; return $this->result; }
  public function get_results(string $query, $output = null) { $this->results_query = $query; return $this->results; }
}

$GLOBALS['wpdb'] = new FixtureWpdb();
require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';
$checks = 0;
$check = static function ($condition, string $message) use (&$checks): void { if (!$condition) throw new RuntimeException($message); echo "PASS: {$message}\n"; $checks++; };
$throws = static function (callable $callback, string $message) use ($check): void { try { $callback(); $check(false, $message); } catch (RuntimeException $error) { if ($error->getMessage() === $message) throw $error; $check(true, $message); } };
$refund = ['id' => 'refund-1', 'payment_id' => 'payment-1', 'location_id' => 'location-1', 'amount_money' => ['amount' => 1234, 'currency' => 'USD']];
$event = \RoxyGrosses\Store::refund_completion_event($refund, '2026-10-08 19:00:00');
$check($event === ['event_id' => 'evt-1', 'event_created_at' => '2026-10-08 19:30:00'], 'real Store method returns matching completion-event provenance');
$check($GLOBALS['wpdb']->prepared_args === ['refund-1', 'payment-1', 'location-1', 1234, '2026-10-08 19:00:00'], 'query binds refund, payment, location, exact cents, and updated_at identity');
$check(str_contains($GLOBALS['wpdb']->query, "status = 'COMPLETED'") && str_contains($GLOBALS['wpdb']->query, "currency = 'USD'") && str_contains($GLOBALS['wpdb']->query, 'ORDER BY event_created_at DESC,id DESC'), 'lookup requires completed USD evidence and deterministic newest event ordering');
$GLOBALS['wpdb']->result = null;
$check(\RoxyGrosses\Store::refund_completion_event($refund, '2026-10-08 19:00:00') === null, 'no matching event preserves proxy fallback');
$GLOBALS['wpdb']->last_error = 'simulated read failure';
$throws(static fn() => \RoxyGrosses\Store::refund_completion_event($refund, '2026-10-08 19:00:00'), 'database read failure fails closed');
$GLOBALS['wpdb']->last_error = '';
$GLOBALS['wpdb']->result = ['event_id' => '', 'event_created_at' => '2026-10-08 19:30:00'];
$throws(static fn() => \RoxyGrosses\Store::refund_completion_event($refund, '2026-10-08 19:00:00'), 'malformed persisted event identity fails closed');
$GLOBALS['wpdb']->result = ['event_id' => 'evt-2', 'event_created_at' => 'not-a-date'];
$throws(static fn() => \RoxyGrosses\Store::refund_completion_event($refund, '2026-10-08 19:00:00'), 'malformed persisted event timestamp fails closed');
$throws(static fn() => \RoxyGrosses\Store::refund_completion_event(array_replace($refund, ['location_id' => '']), '2026-10-08 19:00:00'), 'missing refund identity fails closed before query');
$GLOBALS['wpdb']->last_error = '';
$GLOBALS['wpdb']->results = [
  ['event_id'=>'evt-new','refund_id'=>'refund-1','payment_id'=>'payment-1','location_id'=>'location-1','amount_cents'=>'1234','currency'=>'USD','event_created_at'=>'2026-10-08 19:50:00','refund_updated_at'=>'2026-10-08 19:00:00'],
  ['event_id'=>'evt-old','refund_id'=>'refund-1','payment_id'=>'payment-1','location_id'=>'location-1','amount_cents'=>'1234','currency'=>'USD','event_created_at'=>'2026-10-08 19:30:00','refund_updated_at'=>'2026-10-08 19:00:00'],
];
$events = \RoxyGrosses\Store::completed_refund_events_created_between('2026-10-08 07:00:00', '2026-10-09 07:00:00');
$check(count($events) === 1 && $events[0]['event_id'] === 'evt-new', 'event-day query deduplicates a refund to its newest matching completion event');
$check($GLOBALS['wpdb']->prepared_args === ['2026-10-08 07:00:00', '2026-10-09 07:00:00'] && str_contains($GLOBALS['wpdb']->results_query, 'event_created_at >= %s') && str_contains($GLOBALS['wpdb']->results_query, "status = 'COMPLETED'") && str_contains($GLOBALS['wpdb']->results_query, 'LIMIT 101'), 'event-day discovery uses half-open UTC bounds and a bounded completed-USD query');
$throws(static fn() => \RoxyGrosses\Store::completed_refund_events_created_between('2026-02-30 00:00:00', '2026-03-01 00:00:00'), 'invalid event-day window fails before query');
$GLOBALS['wpdb']->last_error = 'simulated candidate read failure';
$throws(static fn() => \RoxyGrosses\Store::completed_refund_events_created_between('2026-10-08 07:00:00', '2026-10-09 07:00:00'), 'event-day database failure fails closed');
$GLOBALS['wpdb']->last_error = '';
$GLOBALS['wpdb']->results = array_fill(0, 101, $events[0]);
$throws(static fn() => \RoxyGrosses\Store::completed_refund_events_created_between('2026-10-08 07:00:00', '2026-10-09 07:00:00'), 'event-day read beyond the 100-event cap fails closed');
echo "Passed {$checks} Square refund evidence-store assertions.\n";
