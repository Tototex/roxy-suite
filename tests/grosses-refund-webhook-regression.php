<?php
define('ABSPATH', __DIR__);

function rest_url($path = '') { return 'https://example.com/wp-json/' . ltrim($path, '/'); }
class WP_REST_Response { public $data; public $status; public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; } }
eval('namespace RoxyGrosses; class Settings { static $key = "secret"; static function get($name, $default = "") { return self::$key; } } class Store { static function refund_webhook_table_name() { return "wp_refund_events"; } }');

class FixtureWpdb {
  public $last_error = '';
  public $rows = [];
  public function insert($table, $data) {
    $this->last_error = '';
    if (isset($this->rows[$data['event_id']])) { $this->last_error = 'Duplicate entry'; return false; }
    $this->rows[$data['event_id']] = $data;
    return 1;
  }
  public function prepare($query, ...$args) { return str_replace('%s', "'" . addslashes($args[0]) . "'", $query); }
  public function get_var($query) {
    $this->last_error = '';
    if (preg_match("/event_id = '([^']+)'/", $query, $match)) return $this->rows[stripslashes($match[1])]['payload_hash'] ?? null;
    $this->last_error = 'Unexpected query';
    return null;
  }
}
class FixtureRequest {
  private $body; private $signature;
  public function __construct($body, $signature) { $this->body = $body; $this->signature = $signature; }
  public function get_body() { return $this->body; }
  public function get_header($name) { return $name === 'x-square-hmacsha256-signature' ? $this->signature : ''; }
}

$GLOBALS['wpdb'] = new FixtureWpdb();
require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-refund-webhook.php';
$checks = 0;
$check = static function ($condition, string $message) use (&$checks): void { if (!$condition) throw new RuntimeException($message); echo "PASS: {$message}\n"; $checks++; };
$url = rest_url('roxy/v1/square-refund-events');
$check(\RoxyGrosses\RefundWebhook::expected_signature('{"hello":"world"}', 'asdf1234', 'https://example.com/webhook') === '2kRE5qRU2tR+tBGlDwMEw2avJ7QM4ikPYD/PJ3bd9Og=', 'Square HMAC matches official validation example');
$event = ['event_id' => 'evt-1', 'type' => 'refund.updated', 'created_at' => '2026-10-08T19:00:00Z', 'data' => ['object' => ['refund' => ['id' => 'rf-1', 'payment_id' => 'pay-1', 'order_id' => 'ord-1', 'location_id' => 'loc-1', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 1234, 'currency' => 'USD'], 'created_at' => '2026-10-08T18:58:00Z', 'updated_at' => '2026-10-08T19:00:00Z']]]];
$body = json_encode($event);
$signature = \RoxyGrosses\RefundWebhook::expected_signature($body, 'secret', $url);
$response = \RoxyGrosses\RefundWebhook::receive(new FixtureRequest($body, $signature));
$check($response->status === 200 && count($GLOBALS['wpdb']->rows) === 1, 'valid refund event is durably recorded');
$stored = $GLOBALS['wpdb']->rows['evt-1'];
$check($stored['amount_cents'] === 1234 && $stored['event_created_at'] === '2026-10-08 19:00:00' && $stored['status'] === 'COMPLETED', 'refund cents and provider event time are normalized as UTC metadata');
$response = \RoxyGrosses\RefundWebhook::receive(new FixtureRequest($body, $signature));
$check($response->status === 200 && !empty($response->data['duplicate']) && count($GLOBALS['wpdb']->rows) === 1, 'identical Square retry is idempotent');
$event['data']['object']['refund']['amount_money']['amount'] = 999;
$conflict_body = json_encode($event);
$conflict_sig = \RoxyGrosses\RefundWebhook::expected_signature($conflict_body, 'secret', $url);
$response = \RoxyGrosses\RefundWebhook::receive(new FixtureRequest($conflict_body, $conflict_sig));
$check($response->status === 200 && !empty($response->data['conflict']) && count($GLOBALS['wpdb']->rows) === 1, 'conflicting signed retry is ignored and not re-stored');
$response = \RoxyGrosses\RefundWebhook::receive(new FixtureRequest($body, 'invalid'));
$check($response->status === 403 && count($GLOBALS['wpdb']->rows) === 1, 'invalid signature is rejected without storage');
$other_event = ['event_id' => 'evt-2', 'type' => 'payment.updated'];
$other_body = json_encode($other_event);
$response = \RoxyGrosses\RefundWebhook::receive(new FixtureRequest($other_body, \RoxyGrosses\RefundWebhook::expected_signature($other_body, 'secret', $url)));
$check($response->status === 200 && count($GLOBALS['wpdb']->rows) === 1, 'signed unrelated event acknowledged without storage');
$malformed = '{"event_id":';
$response = \RoxyGrosses\RefundWebhook::receive(new FixtureRequest($malformed, \RoxyGrosses\RefundWebhook::expected_signature($malformed, 'secret', $url)));
$check($response->status === 400, 'signed malformed JSON is rejected');
\RoxyGrosses\Settings::$key = '';
$response = \RoxyGrosses\RefundWebhook::receive(new FixtureRequest($body, $signature));
$check($response->status === 503, 'webhook fails closed until signature key configured');
echo "Passed {$checks} Square refund webhook assertions.\n";
