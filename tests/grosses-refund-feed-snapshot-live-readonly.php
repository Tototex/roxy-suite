<?php
/** Actual historical refund-feed reconciliation. Read-only; aggregate output only. */
if (!defined('WP_CLI') || !WP_CLI) exit;
$namespace = 'RoxyGrossesHistoricalProbe_' . bin2hex(random_bytes(4));
eval('namespace ' . $namespace . '; final class Settings { public static function get_report_timezone(){return \\RoxyGrosses\\Settings::get_report_timezone();} public static function get_all(){return \\RoxyGrosses\\Settings::get_all();} public static function square_access_token(){return \\RoxyGrosses\\Settings::square_access_token();} public static function line_list($v){return \\RoxyGrosses\\Settings::line_list($v);} }');
foreach (array_slice($args, 0, 3) as $file) {
  if (!is_file($file)) throw new RuntimeException('Candidate source missing.');
  eval('?>' . str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', file_get_contents($file)));
}
$class = $namespace . '\\RefundSnapshot';
$now = new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles'));
$snapshot = $class::load_from_refund_feed($now->modify('-365 days')->format('Y-m-d'), $now);
$adjustments = 0; $issues = []; $return_item_types = [];
$returns = (new ReflectionProperty($class, 'return_orders'))->getValue($snapshot);
foreach ($returns as $order) foreach ($order['returns'] as $return) foreach ($return['return_line_items'] ?? [] as $item) {
  $type = is_string($item['item_type'] ?? null) ? $item['item_type'] : '<omitted>';
  $return_item_types[$type] = ($return_item_types[$type] ?? 0) + 1;
}
foreach ($snapshot->original_sale_dates() as $date) {
  $result = $snapshot->reconcile_sale_day($date, $snapshot->source_orders_for_date($date));
  $adjustments += count($result['adjustments']);
  foreach ($result['issues'] as $issue) $issues[$issue['reason']] = ($issues[$issue['reason']] ?? 0) + 1;
}
echo 'READ_ONLY_HISTORICAL_SNAPSHOT ' . wp_json_encode([
  'days' => 365, 'original_sale_days' => count($snapshot->original_sale_dates()),
  'itemized_adjustments' => $adjustments, 'issues' => $issues,
  'pending_original_days' => count($snapshot->pending_source_dates()),
  'return_item_types' => $return_item_types,
]) . PHP_EOL;
