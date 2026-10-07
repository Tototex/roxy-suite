<?php
// Real private schemas and independent DB connections; no production order/stock changes.
if(!defined('WP_CLI') || !WP_CLI)exit('WP-CLI only');
global $wpdb;
$primary=$wpdb; $prefix=$primary->prefix;
$candidate=$args[0]??'';
if(!is_file($candidate))throw new RuntimeException('Supply Store candidate');
$source=file_get_contents($candidate);
$source=preg_replace('/^<\?php\s*namespace RoxyInventory;/','namespace RoxyInventoryConnectionFixture;',$source,1,$changed);
if($changed!==1)throw new RuntimeException('Cannot isolate candidate');
eval($source); $store='RoxyInventoryConnectionFixture\\Store';
$fixture=$prefix.'inv_conn_'.bin2hex(random_bytes(8)).'_';
$tables=[]; $a=null; $b=null; $checks=0;
$guard=static function($sql)use($prefix){
    if(preg_match('/^\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE|CREATE\s+TABLE)\s+`?([a-zA-Z0-9_]+)/i',$sql,$m) && strpos($m[1],$prefix.'roxy_inventory_')===0)throw new RuntimeException('Production inventory write forbidden');
    return $sql;
};
$mail=static fn()=>throw new RuntimeException('No mail in database fixture');
$http=static fn()=>new WP_Error('fixture_http','No HTTP in database fixture');
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);++$checks;echo 'PASS_OWNER: '.$label.PHP_EOL;};
$digest=static function()use($primary,$prefix){$d=[];foreach(['products','vendors','orders','runs']as$t){$r=$primary->get_results('SELECT * FROM '.$prefix.'roxy_inventory_'.$t.' ORDER BY id',ARRAY_A);if($primary->last_error||!is_array($r))throw new RuntimeException('Evidence read failed');$d[$t]=hash('sha256',wp_json_encode($r));}return $d;};
add_filter('query',$guard,PHP_INT_MAX);
add_filter('pre_wp_mail',$mail,PHP_INT_MAX);
add_filter('pre_http_request',$http,PHP_INT_MAX);
$capture=null; $startfault=null;
$in_transaction=static function($db){
    $name='inv_test_probe_'.bin2hex(random_bytes(8));
    if($db->query('SAVEPOINT '.$name)===false)throw new RuntimeException('Transaction probe failed');
    return $db->query('RELEASE SAVEPOINT '.$name)!==false;
};
try {
    $before=$digest();
    foreach(['products','vendors','orders','runs']as$t){
        $table=$fixture.'roxy_inventory_'.$t;
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Unsafe fixture identity');
        if($primary->query("CREATE TABLE `$table` LIKE `{$prefix}roxy_inventory_$t`")===false)throw new RuntimeException('Private schema creation failed');
        $tables[]=$table;
    }
    $a=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$b=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    $a->prefix=$fixture;$b->prefix=$fixture;$a->suppress_errors(true);$b->suppress_errors(true);
    $products=$fixture.'roxy_inventory_products'; $orders=$fixture.'roxy_inventory_orders';
    if($a->insert($products,['square_variation_id'=>'private-v','name'=>'Private fixture','on_hand'=>25,'updated_at'=>current_time('mysql')])!==1)throw new RuntimeException('Fixture product insert failed');
    $id=(int)$a->insert_id; $wpdb=$a;
    if($b->insert($products,['square_variation_id'=>'foreign-v','name'=>'Foreign original','on_hand'=>1,'updated_at'=>current_time('mysql')])!==1)throw new RuntimeException('Foreign fixture insert failed');
    $foreign_id=(int)$b->insert_id;
    $check((int)$a->get_var('SELECT CONNECTION_ID()')!==(int)$b->get_var('SELECT CONNECTION_ID()'),'independent private connections');
    $failed=false;
    try{$store::transaction(static function()use($store,$id,$a,$fixture){
        $store::update_product($id,['on_hand'=>99]);
        $key='roxy_inv_'.substr(hash('sha256',$fixture.'roxy_inventory_products|state'),0,48);
        if((string)$a->get_var($a->prepare('SELECT RELEASE_LOCK(%s)',$key))!=='1')throw new RuntimeException('Failed to inject actual lock loss');
        $store::update_product($id,['on_hand'=>100]);
    });}catch(Throwable $e){$failed=true;}
    $check($failed && (float)$b->get_var("SELECT on_hand FROM `$products` WHERE id=$id")===25.0,'actual lock release stops later write and rolls back original transaction');
    $check(!$in_transaction($a),'lost named lock does not leave transaction open');
    $check(!has_filter('query',[$store,'guard_transaction_query']),'SQL guard removed after failure');
    $ran=false;$failed=false;
    $startfault=static function($sql)use($a,$fixture){if($sql==='START TRANSACTION'){$key='roxy_inv_'.substr(hash('sha256',$fixture.'roxy_inventory_products|state'),0,48);$a->get_var($a->prepare('SELECT RELEASE_LOCK(%s)',$key));}return $sql;};
    add_filter('query',$startfault,PHP_INT_MAX);
    try{$store::transaction(static function()use(&$ran){$ran=true;});}catch(Throwable $e){$failed=true;}
    remove_filter('query',$startfault,PHP_INT_MAX);$startfault=null;
    $check($failed && !$ran && !$in_transaction($a),'ownership lost at START boundary rolls back before callback');
    $failed=false;
    try{$store::with_lock('pull',static function()use($store,$a,$id,$fixture){$store::transaction(static function()use($store,$a,$id,$fixture){
        $store::update_product($id,['on_hand'=>99]);
        $key='roxy_inv_'.substr(hash('sha256',$fixture.'roxy_inventory_products|pull'),0,48);
        $a->get_var($a->prepare('SELECT RELEASE_LOCK(%s)',$key));
        $store::update_product($id,['on_hand'=>100]);
    });});}catch(Throwable $e){$failed=true;}
    $check($failed && (float)$b->get_var("SELECT on_hand FROM `$products` WHERE id=$id")===25.0 && !$in_transaction($a),'outer pull ownership loss also rolls back nested state change');
    $captured=[];$failed=false;
    if($b->query('START TRANSACTION')===false || $b->update($products,['name'=>'Foreign pending'],['id'=>$foreign_id])!==1)throw new RuntimeException('Foreign transaction setup failed');
    try{$store::transaction(static function()use($store,$id,$a,$b,$products,$orders,&$captured,&$capture){
        $capture=static function($sql)use($products,$orders,&$captured){if(preg_match('/^\s*(?:INSERT|UPDATE)\b/i',$sql)&&(strpos($sql,$products)!==false||strpos($sql,$orders)!==false))$captured[]=$sql;return $sql;};
        add_filter('query',$capture,PHP_INT_MAX);
        $store::update_product($id,['on_hand'=>99,'name'=>'Name WHERE quoted']);
        if($a->query("UPDATE `$products` SET on_hand=101 WHERE id=$id OR id=0")!==1)throw new RuntimeException('OR predicate fixture write failed');
        $store::create_order('PRIVATE CONNECTION FIXTURE',[['product'=>'Candy WHERE quoted','quantity'=>12]],18,0,'pending_manager',str_repeat('a',64));
        remove_filter('query',$capture,PHP_INT_MAX);$capture=null;
        $a->close();global $wpdb;$wpdb=$b;
        $store::update_product($id,['on_hand'=>100]);
    });}catch(Throwable $e){$failed=true;}
    $check($failed && (float)$b->get_var("SELECT on_hand FROM `$products` WHERE id=$id")===25.0 && (int)$b->get_var("SELECT COUNT(*) FROM `$orders`")===0,'closed/replaced connection rolls back prior writes and cannot continue');
    $check($in_transaction($b) && $b->get_var("SELECT name FROM `$products` WHERE id=$foreign_id")==='Foreign pending','replacement connection foreign transaction is neither committed nor rolled back');
    $check(count($captured)===3,'actual quoted update, OR update and insert SQL captured');
    foreach($captured as $sql){
        $check(strpos($sql,'CONNECTION_ID()=')!==false&&strpos($sql,'IS_USED_LOCK(')!==false,'ownership predicate attached to actual write');
        $check($b->query($sql)===0,'captured stale SQL replay on replacement connection affects zero rows');
    }
    $check((float)$b->get_var("SELECT on_hand FROM `$products` WHERE id=$id")===25.0 && (int)$b->get_var("SELECT COUNT(*) FROM `$orders`")===0,'native stale statement guards preserve stock/order state');
    if($b->query('ROLLBACK')===false)throw new RuntimeException('Foreign fixture rollback failed');
    $wpdb=$b;
    if($b->query('START TRANSACTION')===false||$b->update($products,['on_hand'=>77],['id'=>$id])===false)throw new RuntimeException('Outer transaction setup failed');
    $failed=false;try{$store::update_product($id,['on_hand'=>100]);}catch(Throwable $e){$failed=true;}
    $check($failed && $in_transaction($b) && (float)$b->get_var("SELECT on_hand FROM `$products` WHERE id=$id")===77.0,'pre-existing transaction rejected without committing or rolling it back');
    if($b->query('ROLLBACK')===false)throw new RuntimeException('Outer fixture rollback failed');
    if($b->query('SET SESSION autocommit=0')===false)throw new RuntimeException('Autocommit setup failed');
    $failed=false;try{$store::update_product($id,['on_hand'=>100]);}catch(Throwable $e){$failed=true;}
    $check($failed,'external autocommit-off transaction rejected');
    $b->query('ROLLBACK');$b->query('SET SESSION autocommit=1');
    $store::update_product($id,['on_hand'=>26]);
    $check((float)$b->get_var("SELECT on_hand FROM `$products` WHERE id=$id")===26.0,'new independent operation succeeds after owned failure');
    $check($digest()===$before,'all four production inventory datasets unchanged');
} finally {
    if($capture)remove_filter('query',$capture,PHP_INT_MAX);
    if($startfault)remove_filter('query',$startfault,PHP_INT_MAX);
    $wpdb=$primary;
    if($a)$a->close();if($b)$b->close();
    foreach(array_reverse($tables)as$table){if($primary->query("DROP TABLE `$table`")===false)throw new RuntimeException('Owned fixture cleanup failed');}
    remove_filter('query',$guard,PHP_INT_MAX);
    remove_filter('pre_wp_mail',$mail,PHP_INT_MAX);
    remove_filter('pre_http_request',$http,PHP_INT_MAX);
}
echo 'Passed '.$checks.' actual Inventory connection-ownership checks; owned private schemas removed.'.PHP_EOL;
