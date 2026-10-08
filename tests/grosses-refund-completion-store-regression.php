<?php
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

class FixtureWpdb {
  public string $prefix = 'wp_';
  public string $last_error = '';
  public ?array $result = ['event_id' => 'evt-1', 'event_created_at' => '2026-10-08 19:30:00'];
  public array $prepared_args = [];
  public string $query = '';
  public function prepare(string $query, ...$args): string { $this->prepared_args = $args; return $query; }
  public function get_row(string $query, $output = null) { $this->query = $query; return $this->result; }
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
echo "Passed {$checks} Square refund evidence-store assertions.\n";
