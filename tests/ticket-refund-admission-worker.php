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
file_put_contents((string) $ready_path, 'ready');
$admitted = \RoxyST\Tickets::check_in_ticket($ticket_id, 55, 'ticket');
echo 'REFUND_RACE_ADMISSION=' . (int) $admitted . PHP_EOL;
