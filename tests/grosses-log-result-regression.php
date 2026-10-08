<?php
define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-settings.php';

$cases = [
    [['event_type' => 'anomaly', 'success' => 1], 'Review'],
    [['event_type' => 'sync_tables', 'success' => 1], 'Success'],
    [['event_type' => 'send_report', 'success' => 0], 'Failed'],
    [['success' => 1], 'Success'],
];
foreach ($cases as $index => [$row, $expected]) {
    $actual = \RoxyGrosses\Settings::log_result_label($row);
    if ($actual !== $expected) throw new RuntimeException('Grosses log result case ' . ($index + 1) . ' returned ' . $actual . ', expected ' . $expected);
}
echo count($cases) . " Grosses log result checks passed.\n";
