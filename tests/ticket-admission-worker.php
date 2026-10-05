<?php
// Spawned only by the private Woo fixture; no real-order admission or mail/provider action.
if (!defined('WP_CLI') || !WP_CLI) exit;
[$root,$order_id,$ticket_id,$actor,$source]=$args;
$order_id=(int)$order_id;$ticket_id=(int)$ticket_id;$actor=(int)$actor;
$order=wc_get_order($order_id);
if(!$order||(float)$order->get_total()!==0.0||!str_starts_with($order->get_customer_note(),'PRIVATE ISSUANCE FIXTURE 2026-10-05')||(int)get_post_meta($ticket_id,'_roxy_ticket_order_id',true)!==$order_id)throw new RuntimeException('Private fixture identity required');
$code=preg_replace('/^<\?php\s*/','',file_get_contents($root.'/includes/modules/show-tickets/includes/class-roxy-st-tickets.php'),1);
$code=str_replace('class Tickets {','class AdmissionConcurrentTickets {',$code);
eval($code);
echo 'ADMISSION_RESULT='.(int)\RoxyST\AdmissionConcurrentTickets::check_in_ticket($ticket_id,$actor,$source).PHP_EOL;
