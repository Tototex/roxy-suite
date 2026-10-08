<?php
// Connection-local TEMPORARY scan table; no actual log/options altered.
if (!defined('WP_CLI') || !WP_CLI) exit;
global $wpdb;
$stage=$args[0]??'';
foreach(['includes/modules/sub-check/roxy-sub-check.php'=>'Roxy_Sub_Check','includes/class-roxy-suite-members-dashboard.php'=>'Members_Dashboard'] as $path=>$name){
    $code=preg_replace('/^<\?php\s*/','',file_get_contents($stage.'/'.$path),1);
    $code=str_replace($name,'Stability_'.$name,$code);
    if($name==='Roxy_Sub_Check')$code=str_replace('Stability_Roxy_Sub_Check::init();','',$code);
    // SHOW TABLES does not list TEMPORARY tables. Bypass only this existence
    // guard in the fixture, keeping the actual aggregation SQL unchanged.
    else $code=str_replace('if (!self::scan_table_exists($table))','if (false)',$code);
    eval($code);
}
$check=static function($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS_MYSQL: $message\n";};
$original_prefix=$wpdb->prefix;
$real_table=$original_prefix.'roxy_member_scans';
$table=$original_prefix.'stability_fixture_'.bin2hex(random_bytes(4)).'_roxy_member_scans';
$before=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM $real_table ORDER BY id",ARRAY_A)));
$errors=$wpdb->suppress_errors(true);
try{
    $check($wpdb->query("CREATE TEMPORARY TABLE `$table` LIKE `$real_table`")!==false,'temporary member log fixture created');
    $wpdb->prefix=substr($table,0,-strlen('roxy_member_scans'));
    $ready=new ReflectionProperty(Stability_Roxy_Sub_Check::class,'schema_ready');$ready->setAccessible(true);$ready->setValue(null,true);
    $sub=2147483015;
    $rows=[['nfc_scan',1,1,'2026-10-03 12:00:00'],['manual_admit_walkup',1,3,'2026-10-02 19:30:00'],['manual_admit_walkup',0,5,'2026-10-03 10:00:00'],['manual_admit_reserved',1,2,'2026-09-20 19:30:00']];
    foreach($rows as [$source,$active,$quantity,$time])$check($wpdb->insert($table,['subscription_id'=>$sub,'scanned_at'=>$time,'is_active'=>$active,'quantity'=>$quantity,'source'=>$source,'showing_id'=>2147483016])===1,'isolated scan fixture inserted');
    $stats=new ReflectionMethod(\RoxySuite\Stability_Members_Dashboard::class,'scan_stats_map');$stats->setAccessible(true);$data=$stats->invoke(null,[$sub]);
    $check($data[$sub]['month']===3 && $data[$sub]['lifetime']===5,'actual SQL sums people and excludes verification/inactive scans');
    $last=new ReflectionMethod(Stability_Roxy_Sub_Check::class,'get_last_visit');$last->setAccessible(true);$expected=wp_date(get_option('date_format').' '.get_option('time_format'),(new DateTimeImmutable('2026-10-02 19:30:00',wp_timezone()))->getTimestamp(),wp_timezone());
    $check($last->invoke(null,$sub)===$expected,'latest actual admission is not latest lookup');
    $history=Stability_Roxy_Sub_Check::showing_admit_rows(2147483016);
    $check(count($history)===1 && $history[0]['qty']===3 && $history[0]['subscription_id']===$sub,'deleted membership does not erase historical walk-up attendance');
    $log=new ReflectionMethod(Stability_Roxy_Sub_Check::class,'log_scan');$log->setAccessible(true);
    $check($log->invoke(null,$sub,null,'active',1,2147483016,'manual_admit_walkup',2),'actual member log write result checked');
    $check($wpdb->query("ALTER TABLE `$table` DROP COLUMN source")!==false,'fixture-only failure injected');
    $check(!$log->invoke(null,$sub,null,'active',1,2147483016,'manual_admit_walkup',2),'actual SQL log failure returns false');
}finally{
    $wpdb->prefix=$original_prefix;$wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$table`");$wpdb->suppress_errors($errors);
}
$after=hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM $real_table ORDER BY id",ARRAY_A)));
$check(hash_equals($before,$after),'all actual member scan rows unchanged');
