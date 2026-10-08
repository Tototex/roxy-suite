<?php
// Bounded, formula-safe CSV streaming with a fake 1,201-row database.
define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');
function add_action(...$args){}function add_filter(...$args){}function register_activation_hook(...$args){}
function roxy_suite_user_can_access_admin(){return true;}
function wp_die($message){throw new RuntimeException($message);}
class ShortWriteStream {
    public $context;public static $data='';public static $chunk=4;public static $stop_after=0;public static $writes=0;
    function stream_open($path,$mode,$options,&$opened_path){self::$data='';self::$writes=0;return true;}
    function stream_write($data){self::$writes++;if(self::$stop_after>0&&self::$writes>self::$stop_after)return 0;$piece=substr($data,0,self::$chunk);self::$data.=$piece;return strlen($piece);}
    function stream_flush(){return true;}
    function stream_stat(){return [];}
    function stream_close(){}
}
stream_wrapper_register('roxyshort',ShortWriteStream::class);
class TestDatabase {
    public $prefix='test_';public $last_error='';public $batch_sizes=[];public $queries=[];public $max_id=1201;public $fail_on_page=0;public $read_calls=0;
    function get_var($sql){return $this->max_id;}
    function prepare($sql,...$params){return vsprintf($sql,$params);}
    function get_results($sql,$format){
        $this->queries[]=$sql;$this->read_calls++;if($this->fail_on_page===$this->read_calls){$this->last_error='fixture database read failure';return false;}$this->last_error='';preg_match('/id>(\d+)/',$sql,$match);$last=(int)$match[1];$rows=[];
        for($id=$last+1;$id<=min($last+500,1201);$id++)$rows[]=['id'=>$id,'scanned_at'=>'2026-10-03 10:00:00','subscription_id'=>7,'is_active'=>1,'status'=>'active','user_id'=>8,'ip'=>'','user_agent'=>$id===1?'=HYPERLINK("evil")':'Fixture','showing_id'=>50,'source'=>'manual_admit_walkup','quantity'=>3];
        $this->batch_sizes[]=count($rows);return $rows;
    }
}
$GLOBALS['wpdb']=new TestDatabase;
require ($argv[1]??dirname(__DIR__)).'/includes/modules/sub-check/roxy-sub-check.php';
$export=new ReflectionMethod(Roxy_Sub_Check::class,'export_scan_log_csv');$export->setAccessible(true);
$temp_dirs_before=glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'roxy-scan-export-*')?:[];ob_start();$export->invoke(null,7);$csv=ob_get_clean();$temp_dirs_after=glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'roxy-scan-export-*')?:[];
if(count($temp_dirs_after)!==count($temp_dirs_before))throw new RuntimeException('Successful export must remove its private temporary directory');
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
$GLOBALS['wpdb']->max_id=1201;$GLOBALS['wpdb']->fail_on_page=2;$GLOBALS['wpdb']->read_calls=0;$GLOBALS['wpdb']->batch_sizes=[];
$before_dirs=glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'roxy-scan-export-*')?:[];$failed_page_output='';$failed_page=false;ob_start();
try{$export->invoke(null,7);}catch(RuntimeException $error){$failed_page=str_contains($error->getMessage(),'Could not complete the scan log export');}
$failed_page_output=ob_get_clean();$after_dirs=glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'roxy-scan-export-*')?:[];
if(!$failed_page||$failed_page_output!==''||count($after_dirs)!==count($before_dirs))throw new RuntimeException('A later database-page failure must clean private output and send no partial CSV');
echo "PASS: later-page failure sends no partial CSV and removes its private temporary export\n";
$private_check=new ReflectionMethod(Roxy_Sub_Check::class,'scan_export_temp_is_private');$private_check->setAccessible(true);$temp_root=realpath(sys_get_temp_dir());
$_SERVER['DOCUMENT_ROOT']=$temp_root;
if($private_check->invoke(null,$temp_root)!==false)throw new RuntimeException('System temp under the document root must be rejected');
$_SERVER['DOCUMENT_ROOT']=dirname(__DIR__);
if($private_check->invoke(null,$temp_root)!==true)throw new RuntimeException('System temp outside configured web roots should be accepted');
echo "PASS: system temporary storage under the web document root is rejected\n";
$GLOBALS['wpdb']->max_id=0;$GLOBALS['wpdb']->last_error='';$_SERVER['DOCUMENT_ROOT']=$temp_root;$temp_failure_output='';$temp_failure=false;ob_start();
try{$export->invoke(null,0);}catch(RuntimeException $error){$temp_failure=str_contains($error->getMessage(),'Could not complete the scan log export');}
$temp_failure_output=ob_get_clean();$_SERVER['DOCUMENT_ROOT']=dirname(__DIR__);
if(!$temp_failure||$temp_failure_output!=='')throw new RuntimeException('Private temp-storage failure must return before emitting response bytes');
echo "PASS: unavailable private temp storage fails without partial output\n";
$write_row=new ReflectionMethod(Roxy_Sub_Check::class,'put_private_scan_export_row');$write_row->setAccessible(true);$expected_handle=fopen('php://temp','w+b');fputcsv($expected_handle,['column','value,with comma'],',','"','');rewind($expected_handle);$expected_row=stream_get_contents($expected_handle);fclose($expected_handle);
$short_stream=fopen('roxyshort://successful-short-write','w');ShortWriteStream::$stop_after=0;$write_row->invoke(null,$short_stream,['column','value,with comma']);fclose($short_stream);
if(ShortWriteStream::$data!==$expected_row||ShortWriteStream::$writes<2)throw new RuntimeException('Repeated short writes must complete the entire CSV row');
$short_stream=fopen('roxyshort://failed-short-write','w');ShortWriteStream::$stop_after=1;$write_failed=false;
try{$write_row->invoke(null,$short_stream,['column','value,with comma']);}catch(RuntimeException $error){$write_failed=str_contains($error->getMessage(),'could not be written completely');}
fclose($short_stream);ShortWriteStream::$stop_after=0;
if(!$write_failed)throw new RuntimeException('A zero-byte continuation must fail the export');
echo "PASS: short writes are completed; stalled writes abort without treating a truncated row as complete\n";
