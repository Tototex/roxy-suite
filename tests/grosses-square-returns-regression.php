<?php
/** Standalone regression fixtures for the pure Square return reconciler. */

$root = isset($argv[1]) ? rtrim($argv[1], "\\/") : dirname(__DIR__);
$candidate = isset($argv[2]) ? $argv[2] : $root . '/includes/modules/grosses/includes/class-roxy-grosses-returns.php';
if (!is_file($candidate)) {
  fwrite(STDERR, "Missing candidate helper: {$candidate}\n");
  exit(2);
}
require_once $candidate;

$checks = 0;
$failures = [];
$assert = static function ($condition, string $message) use (&$checks, &$failures): void {
  $checks++;
  if (!$condition) $failures[] = $message;
};
$sales = static function (int $quantity = 3, string $order_id = 'sale-1'): array {
  return [[
    'id' => $order_id,
    'source_date' => '2026-10-06',
    'line_items' => [
      ['uid' => 'ticket-a', 'item_type' => 'ITEM', 'quantity' => (string) $quantity, 'name' => 'Ticket'],
      ['uid' => 'merch-b', 'item_type' => 'ITEM', 'quantity' => '2', 'name' => 'Merch'],
    ],
    'tenders' => [['amount_money' => ['amount' => 3000, 'currency' => 'USD']]],
  ]];
};
$return = static function (string $return_id, string $uid, $quantity, array $extra = []): array {
  return array_replace([
    'id' => $return_id,
    'state' => 'COMPLETED',
    '_refund_verified' => true,
    'returns' => [[
      'source_order_id' => 'sale-1',
      'return_line_items' => [[
        'uid' => $uid,
        'source_line_item_uid' => 'ticket-a',
        'quantity' => $quantity,
        'item_type' => 'ITEM',
      ]],
    ]],
  ], $extra);
};
$qty = static function (array $result, int $sale_index = 0, int $line_index = 0) {
  return $result['orders'][$sale_index]['line_items'][$line_index]['quantity'] ?? null;
};
$unchanged = static function (array $result, array $original) use ($assert): void {
  $assert($result['orders'] === $original, 'invalid source must remain byte-for-byte structurally unchanged');
  $assert($result['adjustments'] === [], 'invalid source must not yield partial adjustments');
  $assert(count($result['issues']) > 0, 'invalid return must be reported');
};
$reconcile = static function (array $sale_rows, array $return_rows): array {
  return \RoxyGrosses\Returns::reconcile($sale_rows, $return_rows);
};

// Two partials accumulate once against exact source IDs; unrelated fields/lines survive.
$original = $sales();
$result = $reconcile($original, [$return('ret-1', 'return-line-1', '1'), $return('ret-2', 'return-line-2', '1.0')]);
$assert($qty($result) === '1', 'multiple partial returns should subtract their cumulative quantity');
$assert($result['orders'][0]['line_items'][1] === $original[0]['line_items'][1], 'unreturned line should be preserved');
$assert($result['orders'][0]['tenders'] === $original[0]['tenders'], 'money/tenders must not be prorated or rewritten');
$assert($result['adjustments'] === [[
  'source_order_id' => 'sale-1', 'source_line_item_uid' => 'ticket-a', 'quantity' => 2, 'source_date' => '2026-10-06',
]], 'adjustment should include exact source identity, integer quantity, and existing source date');
$assert($result['issues'] === [], 'valid partial returns should have no issues');

// An exact repeated API row is idempotent; a conflicting repeated identity poisons its source.
$one = $return('ret-repeat', 'return-line-repeat', '1');
$result = $reconcile($original, [$one, $one]);
$assert($qty($result) === '2' && count($result['adjustments']) === 1, 'exact duplicate should only subtract once');
$conflict = $one;
$conflict['returns'][0]['return_line_items'][0]['quantity'] = '2';
$result = $reconcile($original, [$one, $return('ret-other', 'return-line-good', '1'), $conflict]);
$unchanged($result, $original);
$assert(in_array('conflicting_duplicate_return_item', array_column($result['issues'], 'reason'), true), 'conflicting duplicate identity should be explicit');

// Zero is retained as a quantity-bearing source line, not deleted.
$result = $reconcile($sales(1), [$return('ret-all', 'return-line-all', '1')]);
$assert($qty($result) === '0', 'fully returned source line should remain with zero quantity');

// Only completed and explicitly provider-verified return orders are eligible.
foreach ([
  ['unverified', $return('ret-unverified', 'r1', '1', ['_refund_verified' => false]), 'refund_unverified'],
  ['pending', $return('ret-pending', 'r2', '1', ['state' => 'PENDING']), 'return_not_completed'],
  ['failed', $return('ret-failed', 'r3', '1', ['state' => 'FAILED']), 'return_not_completed'],
] as [$label, $bad, $reason]) {
  $result = $reconcile($original, [$bad]);
  $unchanged($result, $original);
  $assert(in_array($reason, array_column($result['issues'], 'reason'), true), "{$label} return should report {$reason}");
}

// Bad identities, custom amounts, malformed/fractional quantities, and unknown source rows fail closed.
$mutations = [
  ['missing source order', static function (&$r) { unset($r['returns'][0]['source_order_id']); }, 'missing_source_order_id'],
  ['unknown source order', static function (&$r) { $r['returns'][0]['source_order_id'] = 'not-a-sale'; }, 'unknown_source_order'],
  ['missing source line uid', static function (&$r) { unset($r['returns'][0]['return_line_items'][0]['source_line_item_uid']); }, 'missing_source_line_item_uid'],
  ['unknown source line uid', static function (&$r) { $r['returns'][0]['return_line_items'][0]['source_line_item_uid'] = 'unknown'; }, 'unknown_source_line_item_uid'],
  ['custom amount return', static function (&$r) { $r['returns'][0]['return_line_items'][0]['item_type'] = 'CUSTOM_AMOUNT'; }, 'custom_amount_return'],
  ['unknown return item type', static function (&$r) { $r['returns'][0]['return_line_items'][0]['item_type'] = 'SERVICE_CHARGE'; }, 'unsupported_return_item_type'],
  ['missing return item type', static function (&$r) { unset($r['returns'][0]['return_line_items'][0]['item_type']); }, 'unsupported_return_item_type'],
  ['fractional quantity', static function (&$r) { $r['returns'][0]['return_line_items'][0]['quantity'] = '1.5'; }, 'invalid_return_quantity'],
  ['fractional nonzero decimal', static function (&$r) { $r['returns'][0]['return_line_items'][0]['quantity'] = '1.0001'; }, 'invalid_return_quantity'],
  ['negative quantity', static function (&$r) { $r['returns'][0]['return_line_items'][0]['quantity'] = '-1'; }, 'invalid_return_quantity'],
  ['scientific quantity', static function (&$r) { $r['returns'][0]['return_line_items'][0]['quantity'] = '1e0'; }, 'invalid_return_quantity'],
  ['zero quantity', static function (&$r) { $r['returns'][0]['return_line_items'][0]['quantity'] = '0'; }, 'invalid_return_quantity'],
  ['overflow quantity', static function (&$r) { $r['returns'][0]['return_line_items'][0]['quantity'] = (string) PHP_INT_MAX . '0'; }, 'invalid_return_quantity'],
];
foreach ($mutations as [$label, $mutate, $reason]) {
  $bad = $return('ret-' . str_replace(' ', '-', $label), 'return-line', '1');
  $mutate($bad);
  $result = $reconcile($original, [$bad]);
  $unchanged($result, $original);
  $assert(in_array($reason, array_column($result['issues'], 'reason'), true), "{$label} should report {$reason}");
}

$bad_source = $sales();
$bad_source[0]['line_items'][0]['quantity'] = '1.25';
$result = $reconcile($bad_source, [$return('ret-bad-sale-qty', 'r', '1')]);
$unchanged($result, $bad_source);
$bad_source = $sales();
$bad_source[0]['line_items'][0]['item_type'] = 'CUSTOM_AMOUNT';
$result = $reconcile($bad_source, [$return('ret-custom-sale', 'r', '1')]);
$unchanged($result, $bad_source);
$result = $reconcile($original, [$return('ret-over', 'r-over', '4')]);
$unchanged($result, $original);
$assert(in_array('over_refund', array_column($result['issues'], 'reason'), true), 'cumulative over-refund should be reported');

// Ambiguous source order IDs and duplicate line UIDs cannot be guessed.
$duplicate_order = $sales();
$duplicate_order[] = $duplicate_order[0];
$result = $reconcile($duplicate_order, [$return('ret-amb-order', 'r', '1')]);
$unchanged($result, $duplicate_order);
$duplicate_line = $sales();
$duplicate_line[0]['line_items'][] = $duplicate_line[0]['line_items'][0];
$result = $reconcile($duplicate_line, [$return('ret-amb-line', 'r', '1')]);
$unchanged($result, $duplicate_line);

// Unidentifiable/global corruption is still surfaced, never silently treated as a clean result.
$result = $reconcile($original, [[
  'id' => 'ret-no-source', 'state' => 'COMPLETED', '_refund_verified' => true,
  'returns' => [['return_line_items' => [['uid' => 'orphan-return-line', 'source_line_item_uid' => 'ticket-a', 'quantity' => '1', 'item_type' => 'ITEM']]]],
]]);
$unchanged($result, $original);
$assert($result['issues'][0]['source_order_id'] === '', 'missing source identity should be reported as a global/unattributed issue');
$result = $reconcile($original, [['id' => 'ret-malformed', 'state' => 'COMPLETED', '_refund_verified' => true, 'returns' => 'not-an-array']]);
$assert($result['orders'] === $original && $result['adjustments'] === [], 'malformed unidentifiable return data must not alter sales');
$assert(in_array('malformed_returns', array_column($result['issues'], 'reason'), true), 'malformed return collection should be explicitly reported');

// A bad return invalidates only its identifiable source order; another source may reconcile.
$sales_two = $sales();
$sales_two[] = ['id' => 'sale-2', 'line_items' => [['uid' => 'ticket-c', 'quantity' => '2', 'item_type' => 'ITEM']]];
$good_two = $return('ret-sale-2', 'r-sale-2', '1');
$good_two['returns'][0]['source_order_id'] = 'sale-2';
$good_two['returns'][0]['return_line_items'][0]['source_line_item_uid'] = 'ticket-c';
$bad_one = $return('ret-sale-1-invalid', 'r-sale-1', '1');
$bad_one['returns'][0]['return_line_items'][0]['quantity'] = '1.5';
$result = $reconcile($sales_two, [$bad_one, $good_two]);
$assert($qty($result, 0, 0) === '3', 'invalid source order should remain unchanged');
$assert($qty($result, 1, 0) === '1', 'independent valid source order should still reconcile');
$assert(count($result['adjustments']) === 1 && $result['adjustments'][0]['source_order_id'] === 'sale-2', 'only valid source should produce adjustment');

if ($failures) {
  foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
  fwrite(STDERR, sprintf("%d checks, %d failures\n", $checks, count($failures)));
  exit(1);
}
printf("%d checks passed\n", $checks);
