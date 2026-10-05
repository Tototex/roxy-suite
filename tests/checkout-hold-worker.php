<?php
if(!defined('WP_CLI') || !WP_CLI)exit;
$root=$args[0];require __DIR__.'/ticket-fixture-loader.php';roxy_fixture_members($root);roxy_fixture_holds($root);
try {
 if(!empty($args[2])) {
   roxy_fixture_tickets($root,'CheckoutMemberFixtureTickets');
   $method=new ReflectionMethod(\RoxyST\CheckoutMemberFixtureTickets::class,'member_admission_payload');
   $result=$method->invoke(null,(int)$args[1],(int)$args[2],1,'manual_admit');echo 'HOLD_RESULT='.(!empty($result['ok'])?'1':'0').PHP_EOL;
 } else {\RoxyST\FixtureHolds::claim(wc_get_order((int)$args[1]));echo 'HOLD_RESULT=1'.PHP_EOL;}
}catch(Throwable $e){echo 'HOLD_RESULT=0'.PHP_EOL;}
