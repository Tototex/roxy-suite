<?php
// wp eval-file tests/grosses-square-live-readonly.php PRIVATE_STAGE_ROOT
// Search-only Square call; no reporting, exports, deliveries or accounting writes.
$root=$args[0]??dirname(__DIR__);
$source=file_get_contents($root.'/includes/modules/grosses/includes/class-roxy-grosses-square.php');
$source=str_replace('class Square {','class ReadonlyFixtureSquare {',$source);
eval(substr($source,5));
$date=(new DateTimeImmutable('now',new DateTimeZone(\RoxyGrosses\Settings::get_report_timezone())))->modify('-1 day')->format('Y-m-d');
$orders=\RoxyGrosses\ReadonlyFixtureSquare::fetch_orders_for_date($date);
echo 'READONLY_SQUARE_SEARCH_OK date='.$date.' orders='.count($orders).PHP_EOL;
