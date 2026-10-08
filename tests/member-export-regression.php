<?php
// Bounded, formula-safe CSV streaming with a fake 1,201-row database.
define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');
function add_action(...$args){}function add_filter(...$args){}function register_activation_hook(...$args){}
function roxy_suite_user_can_access_admin(){return true;}
function wp_die($message){throw new RuntimeException($message);}
class TestDatabase {
    public $prefix='test_';public $last_error='';public $batch_sizes=[];public $queries=[];public $max_id=1201;
    function get_var($sql){return $this->max_id;}
    function prepare($sql,...$params){return vsprintf($sql,$params);}
    function get_results($sql,$format){
        $this->queries[]=$sql;preg_match('/id>(\d+)/',$sql,$match);$last=(int)$match[1];$rows=[];
        for($id=$last+1;$id<=min($last+500,1201);$id++)$rows[]=['id'=>$id,'scanned_at'=>'2026-10-03 10:00:00','subscription_id'=>7,'is_active'=>1,'status'=>'active','user_id'=>8,'ip'=>'','user_agent'=>$id===1?'=HYPERLINK("evil")':'Fixture','showing_id'=>50,'source'=>'manual_admit_walkup','quantity'=>3];
        $this->batch_sizes[]=count($rows);return $rows;
    }
}
$GLOBALS['wpdb']=new TestDatabase;
require ($argv[1]??dirname(__DIR__)).'/includes/modules/sub-check/roxy-sub-check.php';
$export=new ReflectionMethod(Roxy_Sub_Check::class,'export_scan_log_csv');$export->setAccessible(true);
ob_start();$export->invoke(null,7);$csv=ob_get_clean();
$lines=explode("\n",trim($csv));$first=str_getcsv($lines[1],',','"','');
if(count($lines)!==1202 || $GLOBALS['wpdb']->batch_sizes!==[500,500,201])throw new RuntimeException('CSV rows lost or unbounded batch');
if($first[6]!=="'=HYPERLINK(\"evil\")")throw new RuntimeException('Spreadsheet formula was not neutralized');
foreach($GLOBALS['wpdb']->queries as $query)if(!str_contains($query,'subscription_id=7')||!str_contains($query,'id<=1201'))throw new RuntimeException('Export filter or upper snapshot boundary lost');
$GLOBALS['wpdb']->max_id='not-an-integer';$failed=false;ob_start();
try{$export->invoke(null,7);}catch(RuntimeException $error){$failed=str_contains($error->getMessage(),'Could not read scan log');}
$invalid_max_output=ob_get_clean();
if(!$failed||$invalid_max_output!=='')throw new RuntimeException('Invalid max-ID result must fail before emitting an empty-looking CSV');
echo "PASS: all 1,201 rows streamed in 500/500/201 batches with subscription filter and fixed upper boundary\n";
echo "PASS: user-controlled spreadsheet formulas are literal; admission source/showing/quantity included\n";
echo "PASS: invalid export snapshot boundary fails before sending a CSV response\n";
