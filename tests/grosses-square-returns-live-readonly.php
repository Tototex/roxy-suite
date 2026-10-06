<?php
// Installed WP provider probe: only searches/GETs. Outputs shapes and counts,
// never customer data, source identifiers, tokens, amounts, or accounting writes.
$source = str_replace('class Square {', 'class ReturnsReadonlySquare {', file_get_contents($args[0]));
eval(substr($source, 5));
$square = 'RoxyGrosses\\ReturnsReadonlySquare';
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$orders = $square::fetch_orders_updated_between($now->modify('-30 days')->format('c'), $now->format('c'));
$return_orders = 0; $items = 0; $custom = 0; $missing_source = 0; $statuses = []; $source_ids = [];
foreach ($orders as $order) {
  if (empty($order['returns'])) continue;
  $return_orders++;
  foreach ($order['returns'] as $return) {
    if (!empty($return['source_order_id'])) $source_ids[$return['source_order_id']] = true;
    foreach (($return['return_line_items'] ?? []) as $item) {
      $items++;
      if (($item['item_type'] ?? '') === 'CUSTOM_AMOUNT') $custom++;
      if (empty($item['source_line_item_uid'])) $missing_source++;
    }
  }
  foreach (($order['refunds'] ?? []) as $reference) {
    if (empty($reference['tender_id']) || empty($reference['id'])) throw new RuntimeException('Merchant refund reference shape requires review.');
    $refund = $square::retrieve_payment_refund($reference['tender_id'] . '_' . $reference['id']);
    $statuses[$refund['status']] = ($statuses[$refund['status']] ?? 0) + 1;
  }
}
$sources = $square::retrieve_orders(array_keys($source_ids));
echo 'READ_ONLY updated_orders=' . count($orders) . ' return_orders=' . $return_orders . ' return_items=' . $items . ' custom_amount_items=' . $custom . ' no_source_uid=' . $missing_source . ' retrieved_sources=' . count($sources) . ' refund_statuses=' . json_encode($statuses) . PHP_EOL;
