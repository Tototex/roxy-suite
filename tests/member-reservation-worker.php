<?php
if (!defined('WP_CLI') || !WP_CLI) exit;
$root=$args[0]??'';$sub_id=(int)($args[1]??0);$show=(int)($args[2]??0);
$sub=wcs_get_subscription($sub_id);
if(!$sub || $sub->get_customer_note()!=='PRIVATE MEMBER RESERVATION FIXTURE 2026-10-05' || get_the_title($show)!=='PRIVATE MEMBER RESERVATION FIXTURE 2026-10-05') throw new RuntimeException('Private fixture required');
require __DIR__.'/ticket-fixture-loader.php';
roxy_fixture_members($root);roxy_fixture_tickets($root,'MemberFixtureTickets');
$undo_ticket=(int)($args[3]??0);
if($undo_ticket>0) {
  $order=wc_get_order((int)get_post_meta($undo_ticket,'_roxy_ticket_order_id',true));
  if(!$order || $order->get_customer_note()!=='PRIVATE MEMBER RESERVATION FIXTURE 2026-10-05' || (int)get_post_meta($undo_ticket,'_roxy_ticket_showing_id',true)!==$show)throw new RuntimeException('Private Undo identity required');
  echo 'MEMBER_RESULT='.(int)\RoxyST\MemberFixtureTickets::undo_check_in_ticket($undo_ticket,'member').PHP_EOL;return;
}
$method=new ReflectionMethod(\RoxyST\MemberFixtureTickets::class,'member_admission_payload');
$result=$method->invoke(null,$sub_id,$show,1,'manual_admit');
echo 'MEMBER_RESULT='.(!empty($result['ok'])?'1':'0').PHP_EOL;
