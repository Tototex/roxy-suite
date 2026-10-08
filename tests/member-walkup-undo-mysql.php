<?php
// Exercises the production admission transaction against private disposable MySQL tables.
if (!defined('WP_CLI') || !WP_CLI) exit;
$root=$args[0]??dirname(__DIR__);
require $root.'/includes/modules/show-tickets/includes/class-roxy-st-issuance.php';
global $wpdb;
$scan=$wpdb->prefix.'roxy_member_scans';
$items=$wpdb->prefix.'woocommerce_order_items';
$itemmeta=$wpdb->prefix.'woocommerce_order_itemmeta';
$created=[];
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
try {
  foreach([$scan,$items,$itemmeta] as $table) {
    $existing=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
    if($wpdb->last_error || $existing===$table)throw new RuntimeException('Refusing to overwrite existing fixture table '.$table);
  }
  $sql=[
    "CREATE TABLE `$scan` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,scanned_at DATETIME NOT NULL,subscription_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NULL,status VARCHAR(50) NULL,is_active TINYINT(1) NOT NULL DEFAULT 0,showing_id BIGINT UNSIGNED NULL,source VARCHAR(60) NOT NULL DEFAULT 'nfc_scan',quantity INT NOT NULL DEFAULT 1,ip VARCHAR(45) NULL,user_agent TEXT NULL,PRIMARY KEY(id),KEY showing_id(showing_id)) ENGINE=InnoDB",
    "CREATE TABLE `$items` (order_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,order_item_name TEXT NOT NULL,order_item_type VARCHAR(200) NOT NULL,PRIMARY KEY(order_item_id)) ENGINE=InnoDB",
    "CREATE TABLE `$itemmeta` (meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,order_item_id BIGINT UNSIGNED NOT NULL,meta_key VARCHAR(255) NULL,meta_value LONGTEXT NULL,PRIMARY KEY(meta_id),KEY order_item_id(order_item_id)) ENGINE=InnoDB",
  ];
  foreach([$scan,$items,$itemmeta] as $index=>$table) {
    if($wpdb->query($sql[$index])===false)throw new RuntimeException('Could not create private transaction fixture table '.$table);
    $created[]=$table;
  }
  $insert=static function(int $sub,int $show,int $qty,string $source='manual_admit_walkup')use($wpdb,$scan):int {
    $wpdb->insert($scan,['scanned_at'=>'2026-10-08 18:00:00','subscription_id'=>$sub,'user_id'=>null,'status'=>'active','is_active'=>1,'showing_id'=>$show,'source'=>$source,'quantity'=>$qty,'ip'=>'','user_agent'=>'Fixture']);
    if($wpdb->last_error || (int)$wpdb->insert_id<=0)throw new RuntimeException('Could not seed private visit');
    return (int)$wpdb->insert_id;
  };
  $undo=static function(int $visit,int $sub,int $show)use($wpdb):array {
    $operation=new \RoxyST\Issuance([],'walkup:'.$show);
    return $operation->run(static fn(\RoxyST\Issuance $owned):array=>$owned->undo_member_walkup($visit,$sub,$show));
  };
  $visit=$insert(712345,812345,3);
  $wrong=false;try{$undo($visit,712346,812345);}catch(Throwable $error){$wrong=true;}
  $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$scan` WHERE id=%d",$visit),ARRAY_A);
  $check($wrong && $row && (int)$row['is_active']===1 && (int)$row['quantity']===3,'wrong member identity is rejected without changing the visit');
  $audit=$undo($visit,712345,812345);
  $remaining=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$scan` WHERE id=%d",$audit['replacement_visit_id']),ARRAY_A);
  $event=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$scan` WHERE id=%d",$audit['undo_audit_id']),ARRAY_A);
  $original=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$scan` WHERE id=%d",$visit),ARRAY_A);
  $check($audit['quantity_after']===2 && (int)$original['is_active']===0 && (int)$original['quantity']===3 && (int)$remaining['is_active']===1 && (int)$remaining['quantity']===2 && $event['source']==='manual_undo_walkup' && (int)$event['is_active']===0 && $event['status']==='undo:'.$visit.';r:'.$audit['replacement_visit_id'],'partial Undo preserves the original row, remaining people, and linked inactive audit event');
  $state=wp_json_encode($wpdb->get_results("SELECT * FROM `$scan` ORDER BY id",ARRAY_A));
  $stale=false;try{$undo($visit,712345,812345);}catch(Throwable $error){$stale=true;}
  $check($stale && $state===wp_json_encode($wpdb->get_results("SELECT * FROM `$scan` ORDER BY id",ARRAY_A)),'stale duplicate Undo makes no second state change');
  $last=$undo($audit['replacement_visit_id'],712345,812345);
  $check($last['quantity_after']===1 && $last['replacement_visit_id']>0,'partial Undo can be repeated only against its newly issued exact identity');
  $single=$insert(712346,812345,1,'nfc_admit_walkup');
  $full=$undo($single,712346,812345);
  $single_row=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$scan` WHERE id=%d",$single),ARRAY_A);
  $full_event=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$scan` WHERE id=%d",$full['undo_audit_id']),ARRAY_A);
  $check($full['quantity_after']===0 && $full['replacement_visit_id']===0 && (int)$single_row['is_active']===0 && $full_event['status']==='undo:'.$single.';r:0','full NFC walk-up Undo deactivates the exact visit and audits the absence of a replacement');
  $wrong_source=$insert(712345,812345,1,'manual_admit_reserved');
  $reserved=false;try{$undo($wrong_source,712345,812345);}catch(Throwable $error){$reserved=true;}
  $reserved_row=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$scan` WHERE id=%d",$wrong_source),ARRAY_A);
  $check($reserved && (int)$reserved_row['is_active']===1,'reserved admissions cannot use the walk-up Undo endpoint');
  echo 'MEMBER_WALKUP_UNDO_MYSQL_OK'.PHP_EOL;
} finally {
  foreach(array_reverse($created) as $table)$wpdb->query("DROP TABLE IF EXISTS `$table`");
}
