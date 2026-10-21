<?php
// Spawned only by the private refund/admission Woo lifecycle fixture.
if (!defined('WP_CLI') || !WP_CLI) exit;
[$order_id, $ticket_id, $label, $ready_path] = $args;
$order_id = (int) $order_id;
$ticket_id = (int) $ticket_id;
$order = wc_get_order($order_id);
if (!$order || strpos((string) $order->get_customer_note(), (string) $label) !== 0
  || (int) get_post_meta($ticket_id, '_roxy_ticket_order_id', true) !== $order_id
  || !class_exists('RoxyST\\Tickets')) {
  throw new RuntimeException('Private refund/admission fixture identity required');
}
$item_id = (int) get_post_meta($ticket_id, '_roxy_ticket_order_item_id', true);
$item = $order->get_item($item_id);
$preflight = [
  'order_status' => (string) $order->get_status(),
  'ticket_state' => (string) get_post_meta($ticket_id, '_roxy_ticket_state', true),
  'ticket_refunded' => (string) get_post_meta($ticket_id, '_roxy_ticket_refunded', true),
  'linked' => $item && in_array($ticket_id, (array) $item->get_meta('_roxy_ticket_ids', true), true),
  'eligible' => \RoxyST\Tickets::ticket_is_eligible($ticket_id, false),
  'refund_qty' => $item_id > 0 ? (float) $order->get_qty_refunded_for_item($item_id) : null,
  'refund_ids' => array_map('intval', array_keys((array) $order->get_refunds())),
  'item_qty' => $item ? (float) $item->get_quantity() : null,
  'item_refund_meta' => $item ? $item->get_meta('_qty_refunded', true) : null,
];
file_put_contents((string) $ready_path, 'ready');
$admitted = \RoxyST\Tickets::check_in_ticket($ticket_id, 55, 'ticket');
echo 'REFUND_RACE_PREFLIGHT=' . wp_json_encode($preflight) . PHP_EOL;
echo 'REFUND_RACE_ADMISSION=' . (int) $admitted . PHP_EOL;
