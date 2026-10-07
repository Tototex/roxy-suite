<?php
// wp eval-file ... [candidate repository]. Only an owned private table is written.
global $wpdb;
if (!defined('ABSPATH') || !isset($wpdb)) throw new RuntimeException('WordPress is required.');
$original_prefix=$wpdb->prefix;
$original=roxy_rs_table_backings();
$private_prefix=$original_prefix.'roxy_fund_'.bin2hex(random_bytes(6)).'_';
$table=$private_prefix.'roxy_requested_showing_backings';
if (!preg_match('/^[A-Za-z0-9_]+$/D',$table) || strlen($table)>64 || $table===$original) throw new RuntimeException('Invalid private target.');
$read='roxy_rs_repo_backing_totals';
if (!empty($args[0])) {
 $source=file_get_contents($args[0]);
 if (!is_string($source) || strpos($source,'<?php')!==0) throw new RuntimeException('Candidate repository unavailable.');
 // Only namespace relocation; execute the complete candidate against real wpdb.
 eval('namespace RoxyFundingProbe; use \\RuntimeException; '.substr($source,5));
 $read='RoxyFundingProbe\\roxy_rs_repo_backing_totals';
}
$checks=0;
$check=static function(bool $ok,string $message) use (&$checks): void {
 if (!$ok) throw new RuntimeException('FAIL: '.$message);
 $checks++; echo 'PASS: '.$message.PHP_EOL;
};
$digest=static function() use($wpdb,$original): string {
 $rows=$wpdb->get_results("SELECT id,status,support_qty,subscriber_qty,charge_total,sponsor_amount,sponsor_ticket_qty FROM `$original` ORDER BY id",ARRAY_A);
 if ($wpdb->last_error!=='' || !is_array($rows)) throw new RuntimeException('Original backing digest unavailable.');
 return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
};
$before=$digest();
$network=0; $mail=0; $force_failure=false;
$http_guard=static function() use(&$network){$network++;throw new RuntimeException('HTTP forbidden.');};
$mail_guard=static function() use(&$mail){$mail++;throw new RuntimeException('Mail forbidden.');};
$query_guard=static function(string $sql) use($table,&$force_failure): string {
 if (preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql)
     && strpos($sql,'`'.$table.'`')===false) throw new RuntimeException('Nonfixture mutation denied.');
 if ($force_failure && strpos($sql,'FROM '.$table)!==false) return str_replace('FROM '.$table,'FROM '.$table.'_absent',$sql);
 return $sql;
};
add_filter('pre_http_request',$http_guard,PHP_INT_MAX);
add_filter('pre_wp_mail',$mail_guard,PHP_INT_MAX);
add_filter('query',$query_guard,PHP_INT_MAX);
$old_suppress=$wpdb->suppress_errors(true);
$created=false;
try {
 if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==null) throw new RuntimeException('Private target exists.');
 if ($wpdb->query("CREATE TABLE `$table` LIKE `$original`")===false) throw new RuntimeException('Private table creation failed: '.$wpdb->last_error);
 $created=true;
 $wpdb->prefix=$private_prefix;
 $empty=$read(41);
 $check($empty===['support_qty'=>0,'subscriber_qty'=>0,'charge_total'=>0,'sponsor_amount'=>0,'sponsor_ticket_qty'=>0,'has_sponsor'=>false],'real empty aggregate is valid zero funding');
 foreach ([
  ['status'=>'pending','support_qty'=>3,'charge_total'=>300],
  ['status'=>'approved','subscriber_qty'=>2],
  ['status'=>'charged','sponsor_amount'=>500,'sponsor_ticket_qty'=>2,'charge_total'=>500],
  ['status'=>'failed','support_qty'=>99,'charge_total'=>9999,'sponsor_amount'=>9999],
 ] as $row) {
  $row+=['created_at'=>'2026-10-06 12:00:00','updated_at'=>'2026-10-06 12:00:00','request_id'=>41,'user_id'=>0];
  if ($wpdb->insert($table,$row)===false) throw new RuntimeException('Private seed failed.');
 }
 $totals=$read(41);
 $check($totals===['support_qty'=>3,'subscriber_qty'=>2,'charge_total'=>800,'sponsor_amount'=>500,'sponsor_ticket_qty'=>2,'has_sponsor'=>true],'real pending/approved/charged sums exclude failed rows');
 $check($read(42)===$empty,'another request cannot inherit totals');
 $force_failure=true;
 $thrown=false;
 try {$read(41);}catch(RuntimeException $error){$thrown=true;}
 $force_failure=false;
 $check($thrown,'actual failed SELECT cannot become zero funding');
 $check($read(41)===$totals,'subsequent verified read recovers without changing funding');
 $check($wpdb->get_var("SELECT COUNT(*) FROM `$table`")==='4','failed read did not delete or modify private backings');
 $wpdb->prefix=$original_prefix;
 $check($digest()===$before,'original backing data remains unchanged');
 $check($network===0 && $mail===0,'no HTTP or mail executed');
} finally {
 $wpdb->prefix=$original_prefix;
 $force_failure=false;
 if ($created && $wpdb->query("DROP TABLE `$table`")===false) throw new RuntimeException('Owned private table cleanup failed.');
 $wpdb->suppress_errors($old_suppress);
 remove_filter('query',$query_guard,PHP_INT_MAX);
 remove_filter('pre_http_request',$http_guard,PHP_INT_MAX);
 remove_filter('pre_wp_mail',$mail_guard,PHP_INT_MAX);
}
echo 'Passed '.$checks.' actual private-MySQL funding checks; owned table removed.'.PHP_EOL;
