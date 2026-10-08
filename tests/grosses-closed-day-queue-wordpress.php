<?php
// Actual WP options/advisory-lock fixture. Only a unique option is written; no jobs or mail.
if(!defined('WP_CLI')||!WP_CLI)exit(1);
global $wpdb;
$ns='ClosedQueueFixture'.bin2hex(random_bytes(4));
$key='roxy_fixture_closed_queue_'.bin2hex(random_bytes(8));
$path=$args[0];
$source=str_replace(['namespace RoxyGrosses;',"'roxy_grosses_closed_day_refresh_queue'"],['namespace '.$ns.';',"'".$key."'"],file_get_contents($path));
eval('?>'.$source);
$class=$ns.'\\Scheduler';
$save=new ReflectionMethod($class,'save_closed_day_pending');$save->setAccessible(true);
$clear=new ReflectionMethod($class,'clear_pending_closed_day_refresh');$clear->setAccessible(true);
$lock='roxy_grosses_closed_queue_'.substr(hash('sha256',$key),0,24);
$checks=0;$other=null;$block=null;
$assert=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS: $label\n";};
if(get_option($key,null)!==null)throw new RuntimeException('Private option already exists.');
try {
    $assert($save->invoke(null,'2038-05-01',0,'pending','',1),'actual WP queue stores verified first date');
    $assert($save->invoke(null,'2038-05-02',0,'pending','',2)&&count(get_option($key,[]))===2,'actual WP queue retains independent dates');
    $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->suppress_errors(true);
    $assert((int)$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$lock))===1,'independent connection holds fixture queue lock');
    $before=get_option($key,[]);
    $assert(!$save->invoke(null,'2038-05-03',0,'pending','',3)&&get_option($key,[])===$before,'contended queue write changes nothing');
    // Prime request-local option cache, then simulate a different worker's successful write.
    $fresh=$before;$fresh['2038-05-04']=['attempt'=>0,'status'=>'pending','message'=>'other worker','retry_at'=>4];
    if($other->update($wpdb->options,['option_value'=>maybe_serialize($fresh)],['option_name'=>$key])===false)throw new RuntimeException('Independent private option update failed.');
    $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$lock));
    $assert($save->invoke(null,'2038-05-05',0,'pending','',5),'queue saves after independent worker releases lock');
    $raw=maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)));
    $assert(isset($raw['2038-05-04'],$raw['2038-05-05'])&&count($raw)===4,'fresh locked read preserves other worker date despite primed local cache');
    $block=static fn($new,$old)=>$old;
    add_filter('pre_update_option_'.$key,$block,10,2);
    $assert(!$save->invoke(null,'2038-05-06',0,'pending','',6),'blocked/no-op option write is not reported successful');
    remove_filter('pre_update_option_'.$key,$block,10);$block=null;
    update_option($key,'corrupt-private-evidence',false);
    $assert(!$save->invoke(null,'2038-05-06',0,'pending','',6)&&get_option($key)==='corrupt-private-evidence','corrupt queue evidence is retained without replacement');
    update_option($key,$raw,false);
    $assert($clear->invoke(null,'2038-05-01')&&!isset(get_option($key,[])['2038-05-01'])&&isset(get_option($key,[])['2038-05-04']),'successful clear removes only its own date');
    $assert($wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)',$lock))===null,'queue advisory lock released');
} finally {
    if($block)remove_filter('pre_update_option_'.$key,$block,10);
    if($other){$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$lock));$other->close();}
    delete_option($key);
}
echo "$checks actual WP queue checks passed; only private option removed, no jobs/mail.\n";
