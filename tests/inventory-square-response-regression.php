<?php
// Actual Square class; fake read-only HTTP and Store boundaries. No provider or DB writes.
namespace RoxyGrosses {
    class Settings {
        public static function get_all() { return ['square_location_ids'=>'L1,L2,L1']; }
        public static function line_list($v) { return explode(',', $v); }
        public static function square_access_token() { return 'fixture'; }
    }
}
namespace RoxyInventory {
    class Store {
        public static $saved=[]; public static $commits=0; public static $deactivated=[];
        public static $stock=['v1'=>19];
        public static function with_lock($r,$f) { return $f(); }
        public static function transaction($f) { $r=$f(); self::$commits++; return $r; }
        public static function stock_snapshot() { return self::$stock; }
        public static function upsert_product($p) { self::$saved[]=$p; self::$stock[$p['square_variation_id']]=$p['on_hand']; }
        public static function deactivate_missing($ids) { self::$deactivated=$ids; return 0; }
        public static function mark_stock_increases($s) { return 0; }
        public static function log(...$a) { return true; }
    }
}
namespace {
define('ABSPATH', __DIR__);
function current_time($f) { return '2026-10-06 12:00:00'; }
function wp_json_encode($v) { return json_encode($v); }
function is_wp_error($v) { return $v instanceof RuntimeException; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_get($url,$args) { return response('catalog',$args); }
function wp_remote_post($url,$args) { return response('counts',$args); }
function response($kind,$args) {
    $GLOBALS['requests'][]=[$kind,$args];
    if (!empty($GLOBALS['loop'])) return ['code'=>200,'body'=>json_encode(['cursor'=>'repeat'])];
    $r=array_shift($GLOBALS['responses'][$kind]);
    if ($r===null) throw new RuntimeException('Unexpected extra HTTP request');
    return $r;
}
function encoded($v,$code=200) { return ['code'=>$code,'body'=>json_encode($v)]; }
function catalog() { return ['objects'=>[['id'=>'i1','type'=>'ITEM','item_data'=>['name'=>'Candy','variations'=>[['id'=>'v1','type'=>'ITEM_VARIATION','item_variation_data'=>['name'=>'Regular','item_id'=>'i1']]]]]]]; }
function countrow($q='3',$loc='L1') { return ['catalog_object_id'=>'v1','location_id'=>$loc,'state'=>'IN_STOCK','quantity'=>$q]; }
function resetfixture($cat=null,$counts=null) {
    $GLOBALS['requests']=[]; $GLOBALS['loop']=false;
    $GLOBALS['responses']=['catalog'=>[$cat??encoded(catalog())],'counts'=>[$counts??encoded(['counts'=>[countrow()]])]];
    \RoxyInventory\Store::$saved=[]; \RoxyInventory\Store::$commits=0;
    \RoxyInventory\Store::$stock=['v1'=>19];
}
$root=$argv[1]??dirname(__DIR__);
require $root.'/includes/modules/inventory/includes/class-roxy-inventory-square.php';
$checks=0;
function check($ok,$label) { global $checks; if(!$ok)throw new RuntimeException($label);++$checks;echo 'PASS: '.$label."\n"; }
function rejects($label) { $failed=false;try{\RoxyInventory\Square::pull();}catch(Throwable $e){$failed=true;}check($failed && !\RoxyInventory\Store::$saved && \RoxyInventory\Store::$commits===0 && \RoxyInventory\Store::$stock===['v1'=>19],$label.' preserves saved inventory'); }
resetfixture(); $items=\RoxyInventory\Square::pull();
check($items['v1']['on_hand']===3.0 && \RoxyInventory\Store::$commits===1,'valid response commits once');
$body=json_decode($GLOBALS['requests'][1][1]['body'],true);
check($body['location_ids']===['L1','L2'] && $body['states']===['IN_STOCK'],'locations deduplicated and available-stock filter retained');
check($GLOBALS['requests'][0][1]['timeout']<=35 && $GLOBALS['requests'][1][1]['timeout']<=35,'network calls share bounded timeout');
foreach (['invalid json'=>['code'=>200,'body'=>'bad'], 'JSON list'=>encoded([]),'null'=>encoded(null),'HTTP failure'=>encoded((object)[],500),'provider errors'=>encoded(['errors'=>[['detail'=>'private']]]),'invalid errors'=>encoded(['errors'=>(object)[]]),'object collection'=>encoded(['objects'=>(object)[]]),'scalar collection'=>encoded(['objects'=>'bad']),'scalar record'=>encoded(['objects'=>['bad']])] as $name=>$r) { resetfixture($r); rejects($name); }
foreach (['counts object'=>encoded(['counts'=>(object)[]]),'counts null'=>encoded(['counts'=>null]),'scalar count'=>encoded(['counts'=>['bad']]),'quantity junk'=>encoded(['counts'=>[countrow('3x')]]),'quantity exponent'=>encoded(['counts'=>[countrow('1e2')]]),'quantity too precise'=>encoded(['counts'=>[countrow('1.123456')]]),'quantity numeric'=>encoded(['counts'=>[countrow(3)]]),'quantity overflow'=>encoded(['counts'=>[countrow('10000000000')]]),'wrong location'=>encoded(['counts'=>[countrow('3','other')]]),'duplicate count'=>encoded(['counts'=>[countrow(),countrow()]])] as $name=>$r) { resetfixture(null,$r); rejects($name); }
$row=countrow();$row['catalog_object_id']='other';resetfixture(null,encoded(['counts'=>[$row]]));rejects('unrequested variation');
resetfixture(null,encoded(['unexpected'=>'not inventory']));rejects('unrecognized response is not empty stock');
foreach (['','0',null,[],false] as $cursor) { resetfixture(null,encoded(['counts'=>[countrow()],'cursor'=>$cursor])); if($cursor==='0'){ $GLOBALS['responses']['counts'][]=encoded(['counts'=>[]]);check(\RoxyInventory\Square::pull()['v1']['on_hand']===3.0,'cursor zero is followed instead of ignored'); }else rejects('invalid cursor '.json_encode($cursor)); }
resetfixture(null,encoded(['counts'=>[countrow()],'cursor'=>'again']));$GLOBALS['responses']['counts'][]=encoded(['counts'=>[countrow('4','L2')],'cursor'=>'again']);rejects('repeated count cursor');
resetfixture();$GLOBALS['loop']=true;rejects('repeated catalog cursor');check(count($GLOBALS['requests'])===2,'loop stops promptly');
$cat=catalog();$cat['objects'][]=$cat['objects'][0];resetfixture(encoded($cat));rejects('duplicate catalog identity');
$cat=catalog();$cat['objects'][0]['item_data']['variations'][]=$cat['objects'][0]['item_data']['variations'][0];resetfixture(encoded($cat));rejects('duplicate variation identity');
$cat=catalog();$cat['objects'][0]['item_data']['variations'][0]['item_variation_data']['item_id']='wrong';resetfixture(encoded($cat));rejects('wrong variation parent');
$cat=catalog();$cat['objects'][0]['item_data']['variations']=(object)[];resetfixture(encoded($cat));rejects('object variation collection');
resetfixture(null,encoded(['counts'=>[countrow('3')],'cursor'=>'page2']));$GLOBALS['responses']['counts'][]=encoded(['counts'=>[countrow('4','L2')]]);
check(\RoxyInventory\Square::pull()['v1']['on_hand']===7.0,'different locations sum across pages');
resetfixture(null,encoded(['cursor'=>'counts-page-2']));$GLOBALS['responses']['counts'][]=encoded(['counts'=>[countrow('4')]]);
check(\RoxyInventory\Square::pull()['v1']['on_hand']===4.0,'cursor-only count page followed by a complete page uses returned count instead of zero');
resetfixture(null,encoded(['cursor'=>'empty-counts-page-2']));$GLOBALS['responses']['counts'][]=encoded(['counts'=>[]]);
check(\RoxyInventory\Square::pull()['v1']['on_hand']===0,'cursor-only count page followed by an explicit empty terminal collection is a valid empty result');
resetfixture(encoded(['cursor'=>'catalog-page-2']));$GLOBALS['responses']['catalog'][]=encoded(catalog());
check(\RoxyInventory\Square::pull()['v1']['on_hand']===3.0,'cursor-only catalog page followed by a complete page retains returned item');
resetfixture(encoded(['cursor'=>'empty-catalog-page-2']));$GLOBALS['responses']['catalog'][]=encoded(['objects'=>[]]);
rejects('cursor-only catalog page followed by an empty terminal collection');
resetfixture(null,encoded(['cursor'=>'counts-page-2']));$GLOBALS['responses']['counts'][]=encoded((object)[],500);
rejects('count pagination request failure after cursor-only page');
resetfixture(encoded(['cursor'=>'catalog-page-2']));$GLOBALS['responses']['catalog'][]=encoded((object)[],500);
rejects('catalog pagination request failure after cursor-only page');
resetfixture(null,encoded(['counts'=>[countrow('-2.5')]]));check(\RoxyInventory\Square::pull()['v1']['on_hand']===-2.5,'legitimate negative decimal Square stock retained');
foreach ([(object)[],['counts'=>[]]] as $empty) { resetfixture(null,encoded($empty));check(\RoxyInventory\Square::pull()['v1']['on_hand']===0,'absent/empty counts legitimately mean no stock'); }
resetfixture(encoded((object)[]));rejects('successful empty catalog is not treated as a complete inventory pull');
$method=new ReflectionMethod(\RoxyInventory\Square::class,'page_budget');$method->setAccessible(true);
foreach ([[microtime(true)-1,0],[microtime(true)+10,100]] as [$deadline,$pages]) { $failed=false;try{$method->invokeArgs(null,[$deadline,&$pages]);}catch(Throwable $e){$failed=true;}check($failed,'expired/page-count budget fails closed'); }
echo 'Passed '.$checks.' isolated inventory response checks.'."\n";
}
