<?php
/** WP-CLI, actual Square reads only; prints no receipt/customer identifiers. */
$source=str_replace('namespace RoxyGrosses;','namespace RoxyGrossesMoneyReadonly;',file_get_contents($args[0]));
eval('?>'.$source);
$net=new ReflectionMethod(RoxyGrossesMoneyReadonly\Reporter::class,'square_line_item_concession_cents');$net->setAccessible(true);
$total=new ReflectionMethod(RoxyGrossesMoneyReadonly\Reporter::class,'square_line_item_total_cents');$total->setAccessible(true);
$now=new DateTimeImmutable('now',new DateTimeZone(RoxyGrosses\Settings::get_report_timezone()));
$count=0;
for($back=1;$back<=3;$back++) {
  $date=$now->modify("-$back days")->format('Y-m-d');
  $orders=RoxyGrosses\Square::fetch_orders_for_date($date);$lines=0;
  foreach($orders as $order)foreach((array)($order['line_items']??[]) as $line) {
    if(!isset($line['total_money']['amount']))throw new RuntimeException('Actual receipt line lacks reconciled total');
    $expected=max(0,(int)$line['total_money']['amount']-(int)($line['total_tax_money']['amount']??0));
    if($net->invoke(null,$line)!==$expected||$total->invoke(null,$line)!==$line['total_money']['amount'])throw new RuntimeException('Actual receipt calculation mismatch');
    $lines++;$count++;
  }
  echo "PASS: $date actual Square line arithmetic; ".count($orders)." orders, $lines lines\n";
}
if($count===0)throw new RuntimeException('No actual receipt lines available to verify');
echo "Passed $count actual receipt-line arithmetic comparisons. No financial writes or emails.\n";
