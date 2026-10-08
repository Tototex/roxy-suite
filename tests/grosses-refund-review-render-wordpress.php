<?php
/** Actual WordPress HTML rendering of candidate Settings; no saved data or mail. */
if (!defined('WP_CLI') || !WP_CLI) exit;
$candidate = $args[0] ?? '';
if (!is_file($candidate)) throw new RuntimeException('Candidate Settings is missing.');
$namespace = 'RoxyGrossesReviewRender_' . bin2hex(random_bytes(4));
eval('?>' . str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', file_get_contents($candidate)));
$method = new ReflectionMethod($namespace . '\\Settings', 'render_reports_tab');
$record = ['id' => 123456, 'created_at' => '2039-06-07 12:00:00', 'status' => 'emailed',
  'report_end_date' => '2039-06-07', 'row_count' => 1, 'summary_tickets' => 3, 'summary_gross' => 36,
  'summary' => ['total_tickets' => 3, 'gross_total' => 36],
  'rows' => [['report_date' => '2039-06-07', 'general_qty' => 3, 'total_tickets' => 3, 'gross_total' => 36]],
  'refund_review' => ['2039-06-07' => ['before' => [], 'after' => []]]];
$render = static function (?array $selected, array $rows) use ($method): string {
  ob_start();
  try { $method->invoke(null, '2039-06-07', $selected, $rows); return ob_get_contents(); }
  finally { ob_end_clean(); }
};
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $label);
  $checks++; echo 'PASS: ' . $label . PHP_EOL;
};
$html = $render($record, [$record]);
$check(str_contains($html, 'Refund correction needs review.'), 'selected snapshot displays review warning');
$check(str_contains($html, 'has not been changed or automatically resent'), 'warning explains immutable email and deliberate review');
$check(str_contains($html, 'Refund review required'), 'saved-report list flags the affected snapshot');
$check(!str_contains($html, 'Email This Saved Report'), 'emailed snapshot has no draft send control');
$check(str_contains($html, '$36.00'), 'render retains original saved nominal gross');
$hostile = $record; $hostile['refund_review'] = ['<script>unsafe</script>' => []];
$html = $render($hostile, [$hostile]);
$check(!str_contains($html, '<script>unsafe</script>') && str_contains($html, '&lt;script&gt;unsafe&lt;/script&gt;'), 'review date evidence is HTML escaped');
unset($record['refund_review']); $html = $render($record, [$record]);
$check(!str_contains($html, 'Refund correction needs review.') && !str_contains($html, 'Refund review required'), 'unflagged reports have no refund warning');
echo "Passed {$checks} actual WordPress refund-review rendering checks. No data writes or mail.\n";
