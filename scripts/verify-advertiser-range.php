<?php
require_once '/home1/anrvxfmy/public_html/wp-load.php';
require_once '/home1/anrvxfmy/public_html/wp-content/plugins/roxy-suite/roxy-suite.php';
$rows = \RoxyGrosses\Workbook::advertiser_rows_for_period(2025, 4, 2026, 3);
echo json_encode([
  'row_count' => count($rows),
  'first_rows' => array_slice($rows, 0, 8),
  'last_rows' => array_slice($rows, -4),
], JSON_PRETTY_PRINT), PHP_EOL;
?>
