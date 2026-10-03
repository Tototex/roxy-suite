<?php
// Real MySQL verification in connection-local TEMPORARY tables only.
// Does not send email, call Square, change WordPress options, or modify live rows.
if (!defined('WP_CLI') || !WP_CLI) exit('WP-CLI only');
global $wpdb;
$source=$args[0] ?? '';
if (!is_file($source)) throw new RuntimeException('Supply the staged Store source');
$code=file_get_contents($source);
$code=preg_replace('/^<\?php\s*namespace RoxyInventory;/', 'namespace RoxyInventoryFixture;', $code, 1);
eval($code);
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS_MYSQL: $label\n";};
$original_prefix=$wpdb->prefix;
$fixture_prefix=$original_prefix.'stability_fixture_'.bin2hex(random_bytes(4)).'_';
$tables=[];
$before_hash=hash('sha256',wp_json_encode($wpdb->get_results('SELECT * FROM '.$original_prefix.'roxy_inventory_orders ORDER BY id',ARRAY_A)));
$errors=$wpdb->suppress_errors(true);
try {
    foreach(['products','orders'] as $type) {
        $table=$fixture_prefix.'roxy_inventory_'.$type;
        $tables[]=$table;
        $check($wpdb->query("CREATE TEMPORARY TABLE `$table` LIKE `{$original_prefix}roxy_inventory_$type`")!==false, 'temporary '.$type.' fixture created');
    }
    $orders=$fixture_prefix.'roxy_inventory_orders';
    $wpdb->prefix=$fixture_prefix;
    $store='RoxyInventoryFixture\\Store';
    $store::upgrade_submission_identity();
    $store::upgrade_submission_identity();
    $check((bool)$wpdb->get_var("SHOW COLUMNS FROM `$orders` LIKE 'submission_key'"), 'production upgrade creates submission identity and is repeatable');
    $line=['product'=>'Fixture candy','square_variation_id'=>'fixture-v','on_hand'=>40,'quantity'=>12];
    $id=$store::create_order('TEST ONLY',[$line],18,12,'pending_manager',str_repeat('a',64));
    $check($id>0 && $store::order_for_submission(str_repeat('a',64))['id']==$id,'real submission identity stored');
    $blocked=false;try{$store::create_order('TEST ONLY',[$line],18,12);}catch(Throwable $e){$blocked=true;}
    $check($blocked,'duplicate open vendor order blocked');
    $check($store::update_order_status($id,'ordered'),'real conditional order transition');
    $products=$fixture_prefix.'roxy_inventory_products';
    $check($wpdb->insert($products,['square_variation_id'=>'fixture-v','name'=>'Fixture candy','on_hand'=>25,'updated_at'=>current_time('mysql')])===1,'fixture product stored');
    $product_id=(int)$wpdb->insert_id;
    $check($store::mark_stock_increases(['fixture-v'=>20])===1 && $store::order($id)['status']==='stock_increased','real partial arrival below submission snapshot detected');
    $prior=$store::order($id)['payload'];
    $check(!$store::update_order_payload($id,[$line],'stale'),'real stale progress rejected');
    $failed=false;
    try {$store::transaction(static function()use($store,$product_id){$store::update_product($product_id,['on_hand'=>99]);$store::update_product($product_id,['nonexistent_fixture_column'=>1]);});}catch(Throwable $e){$failed=true;}
    $stock=(float)$wpdb->get_var("SELECT on_hand FROM `$products` WHERE id=$product_id");
    $check($failed && $stock===25.0,'real failed later write rolls back earlier stock change');
    $primary=$wpdb;
    $second=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    $second->prefix=$fixture_prefix;
    $store::with_lock('isolated-concurrency',static function()use($store,$primary,$second,$check){
        global $wpdb;$wpdb=$second;$blocked=false;
        try{$store::with_lock('isolated-concurrency',static fn()=>null);}catch(Throwable $e){$blocked=true;}
        finally{$wpdb=$primary;}
        $check($blocked,'second actual database connection cannot acquire first connection claim');
    });
    $second->close();
    for($i=0;$i<102;$i++)if($wpdb->insert($orders,['vendor'=>'ARCHIVE TEST','status'=>'cancelled','estimated_total'=>0,'minimum_amount'=>0,'item_count'=>0,'payload'=>'[]','created_at'=>current_time('mysql')])!==1)throw new RuntimeException('Could not create temporary archive fixture');
    $check($store::order_count()===103 && count($store::orders(1))===50 && count($store::orders(2))===50 && count($store::orders(3))===3,'history pagination retains more than 100 orders');
    $check($store::order_count('ARCHIVE')===102 && $store::order_count('%')===0,'vendor search and literal percent escaping');
} finally {
    $wpdb->prefix=$original_prefix;
    foreach($tables as $table)$wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    $wpdb->suppress_errors($errors);
}
$after_hash=hash('sha256',wp_json_encode($wpdb->get_results('SELECT * FROM '.$original_prefix.'roxy_inventory_orders ORDER BY id',ARRAY_A)));
$check(hash_equals($before_hash,$after_hash),'all live vendor-order rows unchanged');
