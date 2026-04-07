<?php
require_once '/home1/anrvxfmy/public_html/wp-load.php';
require_once '/home1/anrvxfmy/public_html/wp-content/plugins/roxy-suite/roxy-suite.php';
$rows = \RoxyGrosses\Workbook::advertiser_rows_for_month(2026, 3);
echo json_encode(array_slice($rows, 0, 10), JSON_PRETTY_PRINT), PHP_EOL;
?>
