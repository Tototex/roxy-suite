<?php
/** Subprocess regression for Inventory handlers whose redirects terminate execution. */
$candidate = ($argv[1] ?? '') === '--worker' ? ($argv[2] ?? '') : ($argv[1] ?? dirname(__DIR__).'/includes/modules/inventory/includes/class-roxy-inventory-admin.php');
if (!is_file($candidate)) throw new RuntimeException('Inventory Admin candidate missing: '.$candidate);

if (($argv[1] ?? '') === '--worker') {
  define('ABSPATH', __DIR__);
  define('DAY_IN_SECONDS', 86400);
  $candidate = $argv[2] ?? '';
  $case = $argv[3] ?? '';
  $namespace = 'InventoryHandlerFixture_'.bin2hex(random_bytes(4));
  $GLOBALS['inventory_worker_case'] = $case;
  $GLOBALS['inventory_worker_events'] = [];
  $GLOBALS['inventory_worker_update_option'] = true;
  $GLOBALS['inventory_worker_option'] = null;
  $GLOBALS['inventory_worker_schedule_calls'] = 0;
  $GLOBALS['inventory_worker_mail_calls'] = 0;
  $GLOBALS['inventory_worker_order_status'] = '';

  eval('namespace '.$namespace.';
    final class Store {
      public static array $calls=[];
      public static function update_product(int $id,array $data):void { $GLOBALS["inventory_worker_events"][]="product-write"; if($GLOBALS["inventory_worker_case"]==="product_fail") throw new \\RuntimeException("product storage failure"); self::$calls[]=["product",$id,$data]; }
      public static function update_vendor(int $id,array $data):void { $GLOBALS["inventory_worker_events"][]="vendor-write"; if($GLOBALS["inventory_worker_case"]==="vendor_fail") throw new \\RuntimeException("vendor storage failure"); self::$calls[]=["vendor",$id,$data]; }
      public static function log(string $type,string $status,string $message):bool { self::$calls[]=["log",$type,$status]; return false; }
      public static function vendors():array { return [["name"=>"Fixture Vendor","order_method"=>"phone","email"=>"","minimum_amount"=>0.0]]; }
      public static function products():array { return [["id"=>4,"name"=>"Fixture Item","vendor"=>"Fixture Vendor","square_variation_id"=>"var-4","on_hand"=>2,"pack_size"=>1,"unit_cost"=>5,"minimum_amount"=>0]]; }
      public static function transaction(callable $callback) { return $callback(); }
      public static function order_for_submission(string $key):?array { return null; }
      public static function create_order(string $vendor,array $lines,float $total,float $minimum,string $status="pending_manager",?string $key=null):int { self::$calls[]=["create_order",$status]; return 73; }
      public static function order(int $id):?array { return ["id"=>$id,"vendor"=>"Fixture Vendor","status"=>$GLOBALS["inventory_worker_case"]==="cancel_log_failure"?"ordered":"approval_emailed"]; }
      public static function update_order_status(int $id,string $status):bool { if(str_contains($GLOBALS["inventory_worker_case"],"status_failure"))throw new \\RuntimeException("status storage failure"); $GLOBALS["inventory_worker_order_status"]=$status; self::$calls[]=["status",$id,$status]; return true; }
    }
    final class Settings {
      public const OPTION_KEY="fixture_inventory_settings";
      public static function sanitize(array $input):array { return ["fixture"=>"normalized"]; }
      public static function get(string $key,$default="") { return $key==="jason_email"?"manager@example.invalid":($key==="direct_vendor_sending_enabled"?"0":$default); }
    }
    final class Scheduler { public static function sync_schedule():void { $GLOBALS["inventory_worker_schedule_calls"]++; if($GLOBALS["inventory_worker_case"]==="settings_schedule_failure") throw new \\RuntimeException("schedule failure"); } }
  ');

  final class InventoryWorkerRedirect extends RuntimeException { public string $url; public function __construct(string $url){$this->url=$url;} }
  final class InventoryWorkerDie extends RuntimeException {}
  function roxy_suite_user_can_access_admin():bool { $GLOBALS['inventory_worker_events'][]='guard'; return true; }
  function check_admin_referer($action) { $GLOBALS['inventory_worker_events'][]='nonce:'.$action; return true; }
  function wp_die($message='') { throw new InventoryWorkerDie((string)$message); }
  function wp_safe_redirect($url) { throw new InventoryWorkerRedirect((string)$url); }
  function admin_url($path=''):string { return '/wp-admin/'.$path; }
  function add_query_arg($args,$url=''):string { return $url.(str_contains($url,'?')?'&':'?').http_build_query($args); }
  function sanitize_text_field($value):string { return trim((string)$value); }
  function sanitize_textarea_field($value):string { return trim((string)$value); }
  function sanitize_email($value):string { return trim((string)$value); }
  function sanitize_key($value):string { return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value)); }
  function wp_unslash($value) { return $value; }
  function current_time($type):string { return '2039-01-02 03:04:05'; }
  function is_email($value):bool { return filter_var($value,FILTER_VALIDATE_EMAIL)!==false; }
  function wp_json_encode($value):string { return json_encode($value); }
  function wp_salt($scheme='auth'):string { return 'fixture-hmac-key'; }
  function absint($value):int { return abs((int)$value); }
  function update_option($key,$value):bool { $GLOBALS['inventory_worker_events'][]='option-write'; if($GLOBALS['inventory_worker_update_option']) $GLOBALS['inventory_worker_option']=$value; return (bool)$GLOBALS['inventory_worker_update_option']; }
  function get_option($key,$default=false) { return $GLOBALS['inventory_worker_option'] ?? $default; }
  function wp_mail($to,$subject,$body):bool { $GLOBALS['inventory_worker_mail_calls']++; return $GLOBALS['inventory_worker_case']!=='unsent_status_failure'; }

  $source=file_get_contents($candidate);
  if(!is_string($source)) throw new RuntimeException('Could not read candidate source.');
  $source=str_replace('namespace RoxyInventory;','namespace '.$namespace.';',$source);
  eval('?>'.$source);
  $admin='\\'.$namespace.'\\Admin';
  $_POST=[]; $_SERVER['REQUEST_METHOD']='POST';
  switch($case) {
    case 'product_fail': $_POST=['id'=>9]; $call=static fn()=>$admin::save_product(); break;
    case 'vendor_fail': $_POST=['id'=>6]; $call=static fn()=>$admin::save_vendor(); break;
    case 'settings_unchanged': $GLOBALS['inventory_worker_update_option']=false; $GLOBALS['inventory_worker_option']=['fixture'=>'normalized']; $_POST=[]; $call=static fn()=>$admin::save_settings(); break;
    case 'settings_not_saved': $GLOBALS['inventory_worker_update_option']=false; $GLOBALS['inventory_worker_option']=['fixture'=>'old']; $_POST=[]; $call=static fn()=>$admin::save_settings(); break;
    case 'settings_schedule_failure': $_POST=[]; $call=static fn()=>$admin::save_settings(); break;
    case 'send_log_failure':
    case 'sent_status_failure':
    case 'unsent_status_failure':
      $store=$namespace.'\\Store';
      $vendor=$admin::review_token($store::vendors()[0], $store::products());
      $_POST=['vendor'=>'Fixture Vendor','submission_key'=>str_repeat('a',64),'review_complete'=>'1','review_token'=>$vendor,'order_qty'=>['4'=>'1']];
      $call=static fn()=>$admin::send_draft(); break;
    case 'cancel_log_failure': $_POST=['order_id'=>73]; $call=static fn()=>$admin::cancel_order(); break;
    case 'decision_log_failure':
      $expires=time()+600; $decision_method=new ReflectionMethod($admin,'decision_token'); $decision_method->setAccessible(true); $token=$decision_method->invoke(null,73,'ordered',$expires);
      $_POST=['order_id'=>73,'decision'=>'ordered','expires'=>$expires,'token'=>$token,'_wpnonce'=>'fixture'];
      $call=static fn()=>$admin::order_decision(); break;
    default: throw new RuntimeException('Unknown subprocess case: '.$case);
  }
  $redirect=''; $died='';
  try { $call(); } catch(InventoryWorkerRedirect $e) { $redirect=$e->url; } catch(InventoryWorkerDie $e) { $died=$e->getMessage(); }
  $store='\\'.$namespace.'\\Store';
  echo json_encode(['case'=>$case,'redirect'=>$redirect,'died'=>$died,'events'=>$GLOBALS['inventory_worker_events'],'store_calls'=>$store::$calls,'schedule_calls'=>$GLOBALS['inventory_worker_schedule_calls'],'mail_calls'=>$GLOBALS['inventory_worker_mail_calls'],'order_status'=>$GLOBALS['inventory_worker_order_status'],'option'=>$GLOBALS['inventory_worker_option']]);
  exit;
}

$cases=['product_fail','vendor_fail','settings_unchanged','settings_not_saved','settings_schedule_failure','send_log_failure','cancel_log_failure','decision_log_failure'];
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label."\n";$checks++;};
$run=static function(string $case)use($candidate):array{
  $pipes=[]; $process=proc_open([PHP_BINARY,__FILE__,'--worker',$candidate,$case],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if(!is_resource($process))throw new RuntimeException('Could not start isolated Inventory handler subprocess.');
  fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
  if($status!==0)throw new RuntimeException('Handler subprocess failed ('.$case.'): '.$stderr.' '.$stdout);
  $decoded=json_decode(trim($stdout),true); if(!is_array($decoded))throw new RuntimeException('Invalid subprocess result ('.$case.'): '.$stdout.' '.$stderr);
  return $decoded;
};
$url_args=static function(array $result):array{parse_str((string)parse_url($result['redirect'],PHP_URL_QUERY),$query);return $query;};

foreach(['product_fail'=>'roxy_inventory_save_product','vendor_fail'=>'roxy_inventory_save_vendor'] as $case=>$nonce){
  $result=$run($case);$query=$url_args($result);
  $check(($query['ok']??'')==='0'&&str_contains(rawurldecode($query['message']??''),'could not be saved'),$case.' reports failure rather than success');
  $check(array_slice($result['events'],0,2)===['guard','nonce:'.$nonce],$case.' retains authorization and nonce checks before storage');
}
$unchanged=$run('settings_unchanged');$q=$url_args($unchanged);
$check(($q['ok']??'')==='1'&&str_contains(rawurldecode($q['message']??''),'saved')&&$unchanged['schedule_calls']===1,'unchanged sanitized settings (false update_option with equal stored value) are accepted and schedule sync runs');
$not_saved=$run('settings_not_saved');$q=$url_args($not_saved);
$check(($q['ok']??'')==='0'&&str_contains(rawurldecode($q['message']??''),'not saved')&&$not_saved['schedule_calls']===0,'false update_option with different stored settings reports failure and skips scheduling');
$schedule=$run('settings_schedule_failure');$q=$url_args($schedule);$message=rawurldecode($q['message']??'');
$check(($q['ok']??'')==='0'&&str_contains($message,'settings were saved')&&str_contains($message,'schedule synchronization failed'), 'schedule exception reports saved settings and distinct scheduling failure');
$check(array_slice($schedule['events'],0,2)===['guard','nonce:roxy_inventory_save_settings'],'settings guard and nonce remain before option mutation');
foreach(['send_log_failure','cancel_log_failure','decision_log_failure'] as $case){
  $result=$run($case);$q=$url_args($result);$message=rawurldecode($q['message']??'');
  $check(($q['ok']??'')==='0'&&str_contains($message,'activity log could not be saved')&&str_contains($message,$case==='send_log_failure'?'Do not submit':'Do not repeat')&&($q['order_id']??'')==='73',$case.' warns action completed, prevents duplicate retry, and preserves order ID');
  if($case==='send_log_failure')$check($result['mail_calls']===1&&$result['order_status']==='approval_emailed','mail-log failure reports the already-sent email and persisted order state');
  if($case==='cancel_log_failure')$check($result['order_status']==='cancelled','cancel-log failure reports the already-completed cancellation');
  if($case==='decision_log_failure')$check($result['order_status']==='ordered','decision-log failure reports the already-completed vendor decision');
}
foreach(['sent_status_failure','unsent_status_failure'] as $case) {
  $result=$run($case); $q=$url_args($result); $message=rawurldecode($q['message']??'');
  $check(($q['ok']??'')==='0' && ($q['order_id']??'')==='73' && str_contains($message,$case==='sent_status_failure'?'Order email sent':'Order email was not sent'),'status failure preserves actual mail outcome: '.$case);
  $check($result['mail_calls']===1 && $result['order_status']==='','status failure does not repeat email or invent saved status: '.$case);
}
echo "Passed {$checks} isolated Inventory handler error checks.\n";
