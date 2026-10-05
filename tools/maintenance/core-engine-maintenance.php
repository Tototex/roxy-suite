<?php
// WP-CLI eval-file only. Four explicit live targets; disposable restoration tables only.
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('CLI only');
global $wpdb;
$phase=$args[0]??'';
$directory='/home1/anrvxfmy/deploy-backups/core-engine-20261005';
$targets=['NnW_posts'=>'ID','NnW_postmeta'=>'meta_id','NnW_woocommerce_order_items'=>'order_item_id','NnW_woocommerce_order_itemmeta'=>'meta_id'];
if($wpdb->prefix!=='NnW_')throw new RuntimeException('Unexpected production prefix');
function roxy_engine_info($table){global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT ENGINE,TABLE_ROWS,DATA_LENGTH,INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table),ARRAY_A);}
function roxy_engine_digest($table,$key){
    global $wpdb;$ctx=hash_init('sha256');$count=0;$last=null;
    do{
        $query=$last===null?"SELECT * FROM `$table` ORDER BY `$key` ASC LIMIT 500":$wpdb->prepare("SELECT * FROM `$table` WHERE `$key` > %d ORDER BY `$key` ASC LIMIT 500",$last);
        $rows=$wpdb->get_results($query,ARRAY_A);
        if($wpdb->last_error)throw new RuntimeException('Digest query failed: '.$table);
        foreach($rows as $row){hash_update($ctx,serialize($row));$last=(int)$row[$key];$count++;}
    }while(count($rows)===500);
    return ['rows'=>$count,'sha256'=>hash_final($ctx)];
}
function roxy_engine_compare($table,$key,$expected){$actual=roxy_engine_digest($table,$key);if($actual!==$expected)throw new RuntimeException('Record digest mismatch: '.$table);echo 'RECORDS_MATCH '.$table.' rows='.$actual['rows'].PHP_EOL;}
$baseline_file=$directory.'/baseline.json';
if($phase==='baseline'){
    if(file_exists($baseline_file))throw new RuntimeException('Baseline already exists; do not overwrite');
    $baseline=[];foreach($targets as $table=>$key){$info=roxy_engine_info($table);if(($info['ENGINE']??'')!=='MyISAM')throw new RuntimeException('Expected original MyISAM target: '.$table);$baseline[$table]=roxy_engine_digest($table,$key);echo 'BASELINE '.$table.' rows='.$baseline[$table]['rows'].PHP_EOL;}
    if(false===file_put_contents($baseline_file,json_encode($baseline,JSON_PRETTY_PRINT)))throw new RuntimeException('Baseline write failed');chmod($baseline_file,0600);exit;
}
$baseline=json_decode(file_get_contents($baseline_file),true,512,JSON_THROW_ON_ERROR);
if(array_keys($baseline)!==array_keys($targets))throw new RuntimeException('Invalid baseline target list');
$fixtures=[];foreach($targets as $table=>$key)$fixtures[$table]='roxy_engine_verify_20261005_'.substr($table,4);
if($phase==='prepare-restore'){
    foreach($fixtures as $fixture)if(roxy_engine_info($fixture))throw new RuntimeException('Fixture target already exists: '.$fixture);
    $input=fopen($directory.'/core-tables.sql','rb');$output=fopen($directory.'/restore-test.sql','xb');if(!$input||!$output)throw new RuntimeException('Restore preparation files unavailable');
    $creates=[];
    while(($line=fgets($input))!==false){
        if(preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([^`]+)`/',$line,$m)){
            if(!isset($fixtures[$m[2]]))throw new RuntimeException('Unexpected dump table');
            if($m[1]==='CREATE TABLE')$creates[]=$m[2];
            $line=substr_replace($line,'`'.$fixtures[$m[2]].'`',strlen($m[1])+1,strlen($m[2])+2);
        }elseif(preg_match('/^(?:USE |CREATE DATABASE|ALTER TABLE|LOCK TABLES|TRUNCATE |RENAME TABLE|DELETE FROM|UPDATE )/i',$line))throw new RuntimeException('Unexpected dump statement');
        if(false===fwrite($output,$line))throw new RuntimeException('Restore preparation write failed');
    }
    fclose($input);fclose($output);chmod($directory.'/restore-test.sql',0600);
    sort($creates);$expected=array_keys($targets);sort($expected);if($creates!==$expected)throw new RuntimeException('Dump does not contain exactly four expected CREATE statements');
    file_put_contents($directory.'/fixture-targets.json',json_encode(array_values($fixtures)));chmod($directory.'/fixture-targets.json',0600);
    echo 'RESTORE_TEST_PREPARED four isolated tables'.PHP_EOL;exit;
}
if($phase==='verify-restored'||$phase==='convert-test'||$phase==='verify-test'){
    foreach($targets as $table=>$key){$fixture=$fixtures[$table];if(!roxy_engine_info($fixture))throw new RuntimeException('Fixture not restored: '.$fixture);roxy_engine_compare($fixture,$key,$baseline[$table]);}
    if($phase==='convert-test'){
        foreach($fixtures as $fixture){if(false===$wpdb->query("ALTER TABLE `$fixture` ENGINE=InnoDB"))throw new RuntimeException('Fixture conversion failed: '.$fixture);if((roxy_engine_info($fixture)['ENGINE']??'')!=='InnoDB')throw new RuntimeException('Fixture engine mismatch');echo 'TEST_CONVERTED '.$fixture.PHP_EOL;}
    }
    if($phase!=='verify-restored')foreach($targets as $table=>$key){if((roxy_engine_info($fixtures[$table])['ENGINE']??'')!=='InnoDB')throw new RuntimeException('Expected converted test engine');roxy_engine_compare($fixtures[$table],$key,$baseline[$table]);}
    echo 'RESTORE_CONVERSION_TEST_OK phase='.$phase.PHP_EOL;exit;
}
if($phase==='convert-live'){
    if(($args[1]??'')!=='--approved-live-conversion')throw new RuntimeException('Explicit live conversion flag required');
    if(!is_file($directory.'/offsite-backup-verified.json'))throw new RuntimeException('Offsite backup verification receipt missing');
    foreach($targets as $table=>$key){if((roxy_engine_info($fixtures[$table])['ENGINE']??'')!=='InnoDB')throw new RuntimeException('Converted restore fixture missing');roxy_engine_compare($fixtures[$table],$key,$baseline[$table]);roxy_engine_compare($table,$key,$baseline[$table]);if((roxy_engine_info($table)['ENGINE']??'')!=='MyISAM')throw new RuntimeException('Original engine changed before conversion');}
    foreach($targets as $table=>$key){if(false===$wpdb->query("ALTER TABLE `$table` ENGINE=InnoDB"))throw new RuntimeException('Live conversion failed: '.$table);if((roxy_engine_info($table)['ENGINE']??'')!=='InnoDB')throw new RuntimeException('Live engine mismatch');roxy_engine_compare($table,$key,$baseline[$table]);echo 'LIVE_CONVERTED '.$table.PHP_EOL;}
    echo 'LIVE_CORE_CONVERSION_OK'.PHP_EOL;exit;
}
if($phase==='verify-live'){
    foreach($targets as $table=>$key){if((roxy_engine_info($table)['ENGINE']??'')!=='InnoDB')throw new RuntimeException('Live engine mismatch');roxy_engine_compare($table,$key,$baseline[$table]);}
    echo 'LIVE_CORE_VERIFIED'.PHP_EOL;exit;
}
if($phase==='remove-test-tables'){
    $owned=json_decode(file_get_contents($directory.'/fixture-targets.json'),true,512,JSON_THROW_ON_ERROR);if($owned!==array_values($fixtures))throw new RuntimeException('Fixture ownership receipt mismatch');
    foreach($fixtures as $fixture){if(false===$wpdb->query("DROP TABLE IF EXISTS `$fixture`"))throw new RuntimeException('Fixture cleanup failed');echo 'REMOVED_DISPOSABLE '.$fixture.PHP_EOL;}exit;
}
throw new RuntimeException('Unknown maintenance phase');
