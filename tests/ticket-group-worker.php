<?php
if(!defined('WP_CLI')||!WP_CLI)exit;
[$root,$order_id,$context_id,$customer_key]=$args;$order_id=(int)$order_id;
$order=wc_get_order($order_id);
if(!$order||(float)$order->get_total()!==0.0||!str_starts_with($order->get_customer_note(),'PRIVATE ISSUANCE FIXTURE 2026-10-05')||$customer_key!=='private-group-'.$order_id)throw new RuntimeException('Private group fixture required');
require __DIR__.'/ticket-fixture-loader.php';roxy_fixture_tickets($root,'GroupConcurrentTickets');
$ids=\RoxyST\GroupConcurrentTickets::get_order_ticket_ids($order_id);
try {\RoxyST\GroupConcurrentTickets::apply_will_call_group($ids,3,false,['context_id'=>(int)$context_id,'customer_key'=>$customer_key,'baseline_used'=>0]);echo "GROUP_RESULT=1\n";}
catch(Throwable $e){echo "GROUP_RESULT=0\n";}
