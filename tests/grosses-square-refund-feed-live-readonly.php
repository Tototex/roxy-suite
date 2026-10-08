<?php
/** Candidate refund feed against actual Square; read-only, aggregate output only. */
if (!defined('WP_CLI') || !WP_CLI) exit;
$candidate = $args[0] ?? '';
if (!is_file($candidate)) throw new RuntimeException('Candidate Square source is missing.');
$requests = 0;
$guard = static function($pre, $request, $url) use (&$requests) {
  $host = parse_url($url, PHP_URL_HOST);
  $path = parse_url($url, PHP_URL_PATH);
  $method = strtoupper($request['method'] ?? 'GET');
  if (in_array($host, ['connect.squareup.com','connect.squareupsandbox.com'], true)
      && $method === 'GET' && $path === '/v2/refunds') {
    ++$requests;
    return $pre;
  }
  return new WP_Error('fixture_http_guard', 'Only Square refund-feed reads are allowed.');
};
$mail_guard = static fn() => new WP_Error('fixture_mail_guard', 'Email is forbidden in this read-only fixture.');
add_filter('pre_http_request', $guard, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX);
try {
$namespace = 'RoxyGrossesRefundFeedProbe_' . bin2hex(random_bytes(4));
eval('namespace ' . $namespace . '; final class Settings { public static function get_all(){return \\RoxyGrosses\\Settings::get_all();} public static function square_access_token(){return \\RoxyGrosses\\Settings::square_access_token();} public static function line_list($v){return \\RoxyGrosses\\Settings::line_list($v);} }');
eval('?>' . str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', file_get_contents($candidate)));
$square = $namespace . '\\Square';
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$refunds = $square::list_payment_refunds_updated_between($now->modify('-365 days')->format('c'), $now->format('c'));
$statuses = []; $linked = 0; $with_orders = 0; $valid_us_amounts = 0;
foreach ($refunds as $refund) {
  $statuses[$refund['status']] = ($statuses[$refund['status']] ?? 0) + 1;
  if (is_string($refund['payment_id'] ?? null) && $refund['payment_id'] !== '') $linked++;
  if (is_string($refund['order_id'] ?? null) && $refund['order_id'] !== '') $with_orders++;
  if (is_int($refund['amount_money']['amount'] ?? null) && $refund['amount_money']['amount'] >= 0 && ($refund['amount_money']['currency'] ?? '') === 'USD') $valid_us_amounts++;
}
if ($requests < 1) throw new RuntimeException('No live Square refund-feed request was made.');
echo 'READ_ONLY_REFUND_FEED ' . wp_json_encode(['days' => 365, 'requests' => $requests, 'refunds' => count($refunds), 'statuses' => $statuses,
  'linked_payment' => $linked, 'return_order_reference' => $with_orders, 'valid_us_amount' => $valid_us_amounts]) . PHP_EOL;
} finally {
  remove_filter('pre_http_request', $guard, PHP_INT_MAX);
  remove_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX);
}
