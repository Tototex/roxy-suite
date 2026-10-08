<?php
// Actual dbDelta against a uniquely named disposable table; no real options changed.
if (!defined('WP_CLI') || !WP_CLI) exit;
global $wpdb;
$stage=$args[0]??'';
$code=preg_replace('/^<\?php\s*/','',file_get_contents($stage.'/includes/modules/sub-check/roxy-sub-check.php'),1);
$code=str_replace('Roxy_Sub_Check','SchemaFixture_Roxy_Sub_Check',$code);
$code=str_replace('SchemaFixture_Roxy_Sub_Check::init();','',$code);
$code=str_replace("get_option('roxy_member_scans_schema_version')","(\$GLOBALS['member_fixture_version']??false)",$code);
$code=str_replace("update_option('roxy_member_scans_schema_version',self::SCHEMA_VERSION,false);","\$GLOBALS['member_fixture_version']=self::SCHEMA_VERSION;",$code);
eval($code);
$original=$wpdb->prefix;
$table=$original.'stability_schema_'.bin2hex(random_bytes(6)).'_roxy_member_scans';
$check=static function($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS_SCHEMA: $message\n";};
$reset=static function(){foreach(['schema_ready','schema_attempted'] as $name){$property=new ReflectionProperty(SchemaFixture_Roxy_Sub_Check::class,$name);$property->setAccessible(true);$property->setValue(null,false);}};
$create=new ReflectionMethod(SchemaFixture_Roxy_Sub_Check::class,'create_log_table');$create->setAccessible(true);
try {
    $wpdb->prefix=substr($table,0,-strlen('roxy_member_scans'));
    $check($wpdb->query("CREATE TABLE `$table` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, scanned_at DATETIME NOT NULL, subscription_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY(id))")!==false,'isolated legacy schema created');
    $check($wpdb->insert($table,['scanned_at'=>'2026-10-02 19:00:00','subscription_id'=>2147483017])===1,'legacy history fixture inserted');
    $check($create->invoke(null),'legacy schema upgraded with actual dbDelta');
    $check((int)$wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE subscription_id=2147483017")===1,'upgrade retains historical row');
    $check(($GLOBALS['member_fixture_version']??'')==='1','schema version recorded only after verified columns');
    $reset();$check($create->invoke(null),'next request recognizes upgraded schema');
    $check($wpdb->query("DROP TABLE `$table`")!==false,'only isolated table removed to test missing table');
    $reset();$check($create->invoke(null),'missing table recovered despite current schema version');
    $check(count($wpdb->get_col("SHOW COLUMNS FROM `$table`"))===11,'recovered table includes all eleven required columns');
} finally {
    $wpdb->prefix=$original;
    $wpdb->query("DROP TABLE IF EXISTS `$table`");
    unset($GLOBALS['member_fixture_version']);
}
