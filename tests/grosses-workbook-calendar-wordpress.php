<?php
/** WP-CLI, actual reporting reads; no file, settings, mail or financial writes. */
class_alias(RoxyGrosses\Settings::class,'RoxyGrossesCalendarReadonly\Settings');
class_alias(RoxyGrosses\Store::class,'RoxyGrossesCalendarReadonly\Store');
eval('?>'.str_replace('namespace RoxyGrosses;','namespace RoxyGrossesCalendarReadonly;',file_get_contents($args[0])));
$checks=0;
foreach([2024,2025,2026] as $year) {
  $before=RoxyGrosses\Workbook::weekly_rows_for_year($year);
  $after=RoxyGrossesCalendarReadonly\Workbook::weekly_rows_for_year($year);
  $strip=static function($row){foreach(['fri','sat','sun','mon','tue','wed','thu'] as $day)foreach(['gen','disc','group'] as $kind)unset($row[$day.'_'.$kind]);return $row;};
  if(array_map($strip,$before)!==array_map($strip,$after))throw new RuntimeException('Non-calendar weekly values changed');
  $checks++;
  foreach($after as $row) {
    foreach(['gen','disc','group'] as $kind){$sum=0;foreach(['fri','sat','sun','mon','tue','wed','thu'] as $day)$sum+=(int)$row[$day.'_'.$kind];if($sum!==(int)$row['total_'.$kind])throw new RuntimeException('Daily columns do not conserve total');}
  }
  $checks++;
  if(RoxyGrosses\Workbook::monthly_totals($year)!==RoxyGrossesCalendarReadonly\Workbook::monthly_totals($year))throw new RuntimeException('Week-start-month reporting changed');
  $checks++;
  echo "PASS: $year actual weekly non-calendar parity, daily conservation and monthly policy; ".count($after)." weekly groups\n";
}
echo "Passed $checks actual WordPress workbook calendar checks. Read-only.\n";
