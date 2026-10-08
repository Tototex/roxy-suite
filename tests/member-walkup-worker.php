<?php
if (!defined('WP_CLI') || !WP_CLI) exit;
$root=$args[0]??'';$sub_id=(int)($args[1]??0);$show=(int)($args[2]??0);
$sub=wcs_get_subscription($sub_id);
if(!$sub || $sub->get_customer_note()!=='PRIVATE MEMBER WALKUP FIXTURE 2026-10-05' || get_the_title($show)!=='PRIVATE MEMBER WALKUP FIXTURE 2026-10-05') throw new RuntimeException('Private fixture required');
require __DIR__.'/ticket-fixture-loader.php';roxy_fixture_members($root);roxy_fixture_capacity($root);roxy_fixture_tickets($root,'WalkupFixtureTickets');
$method=new ReflectionMethod(\RoxyST\WalkupFixtureTickets::class,'member_admission_payload');
$result=$method->invoke(null,$sub_id,$show,1,'manual_admit');
echo 'WALKUP_RESULT='.(!empty($result['ok'])?'1':'0').PHP_EOL;
