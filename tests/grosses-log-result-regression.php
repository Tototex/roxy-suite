<?php
define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-settings.php';
require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';

$cases = [
    [['event_type' => 'anomaly', 'success' => 1], 'Review'],
    [['event_type' => 'anomaly', 'success' => 0], 'Review'],
    [['event_type' => 'sync_tables', 'success' => 1], 'Success'],
    [['event_type' => 'send_report', 'success' => 0], 'Failed'],
    [['success' => 1], 'Success'],
];
foreach ($cases as $index => [$row, $expected]) {
    $actual = \RoxyGrosses\Settings::log_result_label($row);
    if ($actual !== $expected) throw new RuntimeException('Grosses log result case ' . ($index + 1) . ' returned ' . $actual . ', expected ' . $expected);
}
echo count($cases) . " Grosses log result checks passed.\n";

$where = new ReflectionMethod('RoxyGrosses\\Store', 'log_where_sql');
$where->setAccessible(true);
$sql_for = static function (array $filters) use ($where): string {
    return $where->invoke(null, $filters)[0];
};
foreach ([
    ['result' => 'review', 'expected' => "event_type = 'anomaly'"],
    ['result' => 'success', 'expected' => "success = 1 AND event_type <> 'anomaly'"],
    ['result' => 'failed', 'expected' => "success = 0 AND event_type <> 'anomaly'"],
] as $filter) {
    if (!str_contains($sql_for(['result' => $filter['result']]), $filter['expected'])) {
        throw new RuntimeException('Grosses result filter ' . $filter['result'] . ' did not match its displayed status semantics.');
    }
}
[$combined_sql, $combined_params] = $where->invoke(null, ['event_type' => 'sync_tables', 'result' => 'review']);
if (strpos($combined_sql, 'event_type = %s') === false || strpos($combined_sql, "event_type = 'anomaly'") === false || $combined_params !== ['sync_tables']) {
    throw new RuntimeException('Combined event and result filters did not remain conjunctive and parameterized.');
}
echo "4 Grosses log result-filter checks passed.\n";
