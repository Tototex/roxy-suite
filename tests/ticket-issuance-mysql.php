<?php
// WP-CLI only: four disposable tables, no order hooks/provider calls/emails.
if (!defined('WP_CLI') || !WP_CLI) exit;
$root = $args[0] ?? dirname(__DIR__);
require __DIR__.'/ticket-fixture-loader.php';
roxy_fixture_issuance($root);
global $wpdb;
$original = ['prefix'=>$wpdb->prefix,'posts'=>$wpdb->posts,'postmeta'=>$wpdb->postmeta];
$prefix = $wpdb->prefix.'roxy_issue_test_20261005_';
$tables = [$prefix.'posts'=>$wpdb->posts,$prefix.'postmeta'=>$wpdb->postmeta,$prefix.'woocommerce_order_items'=>$wpdb->prefix.'woocommerce_order_items',$prefix.'woocommerce_order_itemmeta'=>$wpdb->prefix.'woocommerce_order_itemmeta'];
$created = [];
$check = static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
$second = new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
try {
  foreach($tables as $table=>$source){
    if($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table)))throw new RuntimeException('Fixture target exists; do not overwrite');
  }
  foreach($tables as $table=>$source){if($wpdb->query("CREATE TABLE `$table` LIKE `$source`")===false)throw new RuntimeException('Cannot create fixture');$created[]=$table;}
  $wpdb->prefix=$prefix;$wpdb->posts=$prefix.'posts';$wpdb->postmeta=$prefix.'postmeta';
  $operation=new \RoxyST\FixtureIssuance(123);
  $id=$operation->run(static function($operation)use($check,$second,$wpdb){
    $id=$operation->create('PRIVATE SQL TICKET FIXTURE');
    $operation->post_meta($id,'_roxy_ticket_order_id',123);
    $operation->post_meta($id,'_roxy_ticket_order_id',123);
    $operation->item_ids(10,[$id]);
    $check((int)$second->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}`")===0,'other connection cannot see uncommitted ticket');
    return $id;
  });
  $check($id>0&&(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}`")===1,'complete ticket projection commits');
  $check((int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->postmeta}`")===1,'unchanged metadata is successful without duplication');
  $check(maybe_unserialize($wpdb->get_var("SELECT meta_value FROM `{$prefix}woocommerce_order_itemmeta` WHERE order_item_id=10"))===[$id],'ticket identities linked to order item');
  $before=(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}`");
  $fail=static function($sql)use($prefix){return strpos($sql,"INSERT INTO `{$prefix}woocommerce_order_itemmeta`")===0?'INSERT INTO `roxy_missing_issue_failure_target` VALUES (1)':$sql;};
  add_filter('query',$fail);
  $old_suppress=$wpdb->suppress_errors(true);
  try {
    try{(new \RoxyST\FixtureIssuance(123))->run(static function($operation){$id=$operation->create('MUST ROLLBACK');$operation->post_meta($id,'_roxy_ticket_order_id',123);$operation->item_ids(11,[$id]);});throw new RuntimeException('Injected write did not fail');}
    catch(RuntimeException $e){$check($e->getMessage()==='Ticket write failed','later write failure is not reported as success');}
  }finally{remove_filter('query',$fail);$wpdb->suppress_errors($old_suppress);}
  $check((int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}`")===$before&&(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->postmeta}`")===1,'later failure rolls back earlier post and metadata');
  $held=new \RoxyST\FixtureIssuance(123);$lock=(new ReflectionProperty($held,'lock'));$lock->setAccessible(true);$key=$lock->getValue($held);
  $check((string)$second->get_var($second->prepare('SELECT GET_LOCK(%s,0)',$key))==='1','second connection acquires competing order lock');
  try{try{$held->run(static fn()=>null);throw new RuntimeException('Contended operation ran');}catch(RuntimeException $e){$check($e->getMessage()==='Ticket issuance is busy','overlapping order operation is blocked');}}
  finally{$second->get_var($second->prepare('SELECT RELEASE_LOCK(%s)',$key));}
  $group=new \RoxyST\FixtureIssuance([456,123],['private-group','private-showing']);
  $reverse=new \RoxyST\FixtureIssuance([123,456],['private-showing','private-group']);
  $locks=new ReflectionProperty($group,'locks');$locks->setAccessible(true);$keys=$locks->getValue($group);
  $check($keys===$locks->getValue($reverse)&&count($keys)===4,'multi-scope group lock order is deterministic across reversed input');
  $blocked=end($keys);$second->get_var($second->prepare('SELECT GET_LOCK(%s,0)',$blocked));
  try {try {$group->run(static fn()=>null);throw new RuntimeException('Contended group ran');}catch(RuntimeException $e){$check($e->getMessage()==='Ticket issuance is busy','group contention refuses all mutations');}}
  finally {$free=true;foreach(array_slice($keys,0,-1) as $owned)$free=$free&&(int)$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$owned))===1;$check($free,'partial acquisition failure releases all earlier owned locks');$second->get_var($second->prepare('SELECT RELEASE_LOCK(%s)',$blocked));}
  $check($group->run(static fn()=>true)===true,'group succeeds after contention clears');
  (new \RoxyST\FixtureIssuance(123))->run(static function($operation)use($second,$wpdb,$check){
    $predicate=new ReflectionMethod($operation,'predicate');$predicate->setAccessible(true);
    $guard=$predicate->invoke($operation);
    $result=$second->query("INSERT INTO `{$wpdb->postmeta}` (post_id,meta_key,meta_value) SELECT 999,'_stray','blocked' FROM DUAL WHERE $guard");
    $check($result===0,'same guarded SQL replayed by another connection cannot write');
  });
  $wpdb->query('START TRANSACTION');
  try{
    $wpdb->insert($wpdb->postmeta,['post_id'=>888,'meta_key'=>'_outer','meta_value'=>'keep']);
    try{(new \RoxyST\FixtureIssuance(123))->run(static fn()=>null);throw new RuntimeException('Nested operation ran');}catch(RuntimeException $e){$check($e->getMessage()==='External transaction active','outer transaction is refused without implicit commit');}
    $check((int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE post_id=888")===1&&(int)$second->get_var("SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE post_id=888")===0,'outer transaction remains intact and uncommitted');
  }finally{$wpdb->query('ROLLBACK');}
  $check((int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE post_id IN (888,999)")===0,'no nested or stray writes remain');
  echo 'TICKET_ISSUANCE_MYSQL_OK'.PHP_EOL;
  $lease=new \RoxyST\FixtureIssuance([123,456],['lease-showing-a','lease-showing-b']);$lease_keys=$locks->getValue($lease);
  try {
    $lease->acquire_lease();$owner=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
    $check((string)$wpdb->get_var('SELECT @@SESSION.autocommit')==='1','confirmation lease does not start an external transaction');
    $owned=true;foreach($lease_keys as $key)$owned=$owned&&(int)$second->get_var($second->prepare('SELECT IS_USED_LOCK(%s)',$key))===$owner;
    $check($owned,'confirmation lease owns all deterministic order/showing locks');
    $check((new \RoxyST\FixtureIssuance([456,123],['lease-showing-b','lease-showing-a']))->run(static fn($writer)=>true),'hold transaction can reenter its own confirmation lease');
    $owned=true;foreach($lease_keys as $key)$owned=$owned&&(int)$second->get_var($second->prepare('SELECT IS_USED_LOCK(%s)',$key))===$owner;
    $check($owned,'inner transaction releases only its references, leaving confirmation lease intact');
  } finally{$lease->release_lease();}
  $free=true;foreach($lease_keys as $key)$free=$free&&(int)$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$key))===1;$check($free,'explicit confirmation release frees every leased lock');
  $blocked=end($lease_keys);$second->get_var($second->prepare('SELECT GET_LOCK(%s,0)',$blocked));
  try{try{$lease->acquire_lease();throw new RuntimeException('Contended lease acquired');}catch(RuntimeException $e){$check($e->getMessage()==='Seat confirmation is busy','competing confirmation refuses a partial lease');}}
  finally{$lease->release_lease();$free=true;foreach(array_slice($lease_keys,0,-1) as $key)$free=$free&&(int)$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$key))===1;$check($free,'partial confirmation failure releases earlier acquired references');$second->get_var($second->prepare('SELECT RELEASE_LOCK(%s)',$blocked));}
  echo 'TICKET_LEASE_MYSQL_OK'.PHP_EOL;
}finally{
  $second->close();$wpdb->prefix=$original['prefix'];$wpdb->posts=$original['posts'];$wpdb->postmeta=$original['postmeta'];
  foreach($created as $table)if($wpdb->query("DROP TABLE `$table`")===false)throw new RuntimeException('Fixture cleanup failed');
}
