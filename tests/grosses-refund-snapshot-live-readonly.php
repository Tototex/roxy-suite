<?php
// Actual API read-only integration of staged classes. No Store/Reporter calls.
$namespace = 'RoxyGrossesReturnProbe41';
eval('namespace ' . $namespace . '; class Settings { public static function get_report_timezone(){return \\RoxyGrosses\\Settings::get_report_timezone();} public static function get_all(){return \\RoxyGrosses\\Settings::get_all();} public static function square_access_token(){return \\RoxyGrosses\\Settings::square_access_token();} public static function line_list($v){return \\RoxyGrosses\\Settings::line_list($v);} }');
foreach ([$args[0], $args[1], $args[2]] as $file) eval('?>' . str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', file_get_contents($file)));
$class = $namespace . '\\RefundSnapshot';
$now = new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles'));
$snapshot = $class::load($now->modify('-30 days')->format('Y-m-d'), $now);
$sources = (new ReflectionProperty($class, 'sources'))->getValue($snapshot);
$adjustments = 0; $issues = [];
foreach ($snapshot->original_sale_dates() as $date) {
  $sales = array_values(array_filter($sources, static fn($sale) => $sale['source_date'] === $date));
  $result = $snapshot->reconcile_sale_day($date, $sales);
  $adjustments += count($result['adjustments']);
  foreach ($result['issues'] as $issue) $issues[$issue['reason']] = ($issues[$issue['reason']] ?? 0) + 1;
}
echo 'READ_ONLY_SNAPSHOT original_sale_days=' . count($snapshot->original_sale_dates()) . ' itemized_adjustments=' . $adjustments . ' issues=' . json_encode($issues) . PHP_EOL;
