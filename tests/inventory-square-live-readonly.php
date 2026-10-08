<?php
// WP-CLI only. Real catalog/count READ endpoints; all candidate Store writes stay in memory.
if (!defined('WP_CLI') || !WP_CLI) exit('WP-CLI only');
$candidate=$args[0]??'';
if(!is_file($candidate))throw new RuntimeException('Supply candidate Square source');
$receipt_requests=0;
$guard=static function($pre,$request,$url) use (&$receipt_requests){
    $path=parse_url($url,PHP_URL_PATH); $host=parse_url($url,PHP_URL_HOST);
    $method=strtoupper($request['method']??'GET');
    if(in_array($host,['connect.squareup.com','connect.squareupsandbox.com'],true)) {
        if($method==='GET' && $path==='/v2/catalog/list')return $pre;
        if($method==='POST' && $path==='/v2/inventory/counts/batch-retrieve')return $pre;
        if($method==='POST' && $path==='/v2/inventory/changes/batch-retrieve') { ++$receipt_requests; return $pre; }
    }
    return new WP_Error('fixture_http_guard','Only Square catalog/count/change-history read endpoints allowed.');
};
$sqlguard=static function($sql){
    global $wpdb;
    if(preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE)\b/i',$sql) && strpos($sql,$wpdb->prefix.'roxy_inventory_')!==false)throw new RuntimeException('Live inventory mutation forbidden in read-only fixture');
    return $sql;
};
$mailguard=static fn()=>throw new RuntimeException('No mail permitted in read-only fixture');
add_filter('pre_http_request',$guard,PHP_INT_MAX,3);
add_filter('query',$sqlguard,PHP_INT_MAX);
add_filter('pre_wp_mail',$mailguard,PHP_INT_MAX);
global $wpdb;
$digest=static function()use($wpdb){
    $result=[];
    foreach(['products','vendors','orders','runs'] as $suffix){
        $rows=$wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'roxy_inventory_'.$suffix.' ORDER BY id',ARRAY_A);
        if($wpdb->last_error || !is_array($rows))throw new RuntimeException('Evidence read failed');
        $result[$suffix]=hash('sha256',wp_json_encode($rows));
    }
    return $result;
};
try {
    $before=$digest();
    eval('namespace RoxyInventoryReadFixture;
        class Store {
            public static int $writes=0;
            public static function with_lock($r,$f){return $f();}
            public static function transaction($f){return $f();}
            public static function stock_snapshot(){return [];}
            public static function upsert_product($p){++self::$writes;}
            public static function deactivate_missing($ids){return 0;}
            public static function mark_stock_increases($s){return 0;}
            public static function orders_waiting_for_receipt(){
                global $wpdb;
                $table=$wpdb->prefix.\'roxy_inventory_orders\';
                $rows=$wpdb->get_results("SELECT id,payload,created_at FROM {$table} WHERE status=\'ordered\' ORDER BY id",ARRAY_A);
                if($wpdb->last_error || !is_array($rows))throw new RuntimeException(\'Open-order read failed\');
                return $rows;
            }
            public static function log(...$args){return true;}
        }
    ');
    $source=file_get_contents($candidate);
    if(!is_string($source))throw new RuntimeException('Candidate read failed');
    $source=preg_replace('/^<\?php\s*namespace RoxyInventory;/','namespace RoxyInventoryReadFixture;',$source,1,$replaced);
    if($replaced!==1)throw new RuntimeException('Candidate namespace was not isolated');
    eval($source);
    $items=\RoxyInventoryReadFixture\Square::pull();
    if(count($items)!==\RoxyInventoryReadFixture\Store::$writes)throw new RuntimeException('Not all candidate products reached the memory boundary');
    if($receipt_requests<1)throw new RuntimeException('No live Square receipt-history request was made for the current ordered inventory.');
    if($before!==$digest())throw new RuntimeException('Live inventory evidence changed');
    echo 'PASS_LIVE_READ: variations='.count($items).'; receipt_history_requests='.$receipt_requests.'; actual catalog/count/receipt-history parser succeeds; four live inventory datasets unchanged; no email or provider mutation.'.PHP_EOL;
} finally {
    remove_filter('pre_http_request',$guard,PHP_INT_MAX);
    remove_filter('query',$sqlguard,PHP_INT_MAX);
    remove_filter('pre_wp_mail',$mailguard,PHP_INT_MAX);
}
