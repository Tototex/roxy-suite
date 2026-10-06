<?php
/** Installed WP mail/SQL fixture, private report/log schema copies only. */
global $wpdb;
$namespace='RoxyGrossesMailFixture37'; $tables=[]; $checks=0;
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";$checks++;};
try {
  foreach(['roxy_grosses_reports'=>'roxy_fixture37_reports','roxy_grosses_logs'=>'roxy_fixture37_logs'] as $original=>$fixture) {
    $table=$wpdb->prefix.$fixture;
    if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)))throw new RuntimeException('Fixture table already exists');
    if($wpdb->query("CREATE TABLE {$table} LIKE {$wpdb->prefix}{$original}")===false)throw new RuntimeException('Fixture schema copy failed');
    $tables[]=$table;
  }
  $source=str_replace('namespace RoxyGrosses;',"namespace $namespace;",file_get_contents($args[0]));
  $source=str_replace(["'roxy_grosses_reports'","'roxy_grosses_logs'"],["'roxy_fixture37_reports'","'roxy_fixture37_logs'"],$source);
  eval('?>'.$source);
  eval('namespace '.$namespace.'; class Settings { public static $status=[]; public static function email_list():array{return ["fixture@example.invalid"];} public static function admin_email():string{return "admin@example.invalid";} public static function get($key,$default=""){return $default;} public static function get_report_timezone():string{return "UTC";} public static function set_status(array $status):void{self::$status=$status;} }');
  eval('?>'.str_replace('namespace RoxyGrosses;',"namespace $namespace;",file_get_contents($args[1])));
  $store=$namespace.'\\Store'; $reporter=$namespace.'\\Reporter'; $settings=$namespace.'\\Settings';
  $row=['report_date'=>'2037-01-02','film_title'=>'PRIVATE saved snapshot fixture','show_time'=>'18:00','general_qty'=>2,'discount_qty'=>0,'group_qty'=>0,'gross_total'=>12.34];
  $summary=['report_date'=>'2037-01-02','gross_total'=>12.34,'total_tickets'=>2];
  $id=$store::create_report('2037-01-02',1,'fixture','draft',$summary,[$row]);
  $check($id>0,'private saved report created via actual Store');
  // Never allow this fixture to mutate actual financial history/current entries.
  $block=static function($sql)use($wpdb){foreach(['roxy_grosses_history','roxy_grosses_entries','roxy_grosses_live_entries','roxy_grosses_rental_entries','roxy_grosses_legacy_weekly'] as $name){if(preg_match('/^\s*(INSERT|UPDATE|REPLACE|DELETE)\b/i',$sql)&&strpos($sql,$wpdb->prefix.$name)!==false)throw new RuntimeException('Resend attempted financial mutation');}return $sql;};
  add_filter('query',$block);
  $mail=[]; $result=true;
  $capture=static function($prior,$atts)use(&$mail,&$result){$mail[]=$atts;return $result;};
  add_filter('pre_wp_mail',$capture,PHP_INT_MAX,2);
  $send=$reporter::send_saved_report($id);
  $check(!empty($send['success'])&&count($mail)===1,'installed WordPress send intercepted without SMTP delivery');
  $check(strpos($mail[0]['message'],$row['film_title'])!==false,'actual mail payload uses saved snapshot');
  $stored=$store::get_report($id);
  $check($stored['status']==='emailed'&&$stored['rows']===[$row],'actual Store marker updates without changing snapshot');
  $check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$tables[1]}")===1,'success logged only to private log table');
  foreach($mail[0]['attachments'] as $attachment)$check(!file_exists($attachment),'private attachment cleaned after intercepted mail');
  $id2=$store::create_report('2037-01-03',1,'fixture','draft',$summary,[$row]); $result=false; $mail=[];
  $send=$reporter::send_saved_report($id2);
  $check(empty($send['success']),'actual send failure returned');
  $stored=$store::get_report($id2);
  $check($stored['status']==='draft'&&$stored['rows']===[$row],'failed mail leaves report draft and snapshot unchanged');
  $check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$tables[1]}")===2,'failure logged only to private table');
  $check(count($mail)===2,'report and failure-alert mail both intercepted');
  echo "Passed $checks actual WordPress saved-report checks. Zero SMTP deliveries.\n";
} finally {
  if(isset($block))remove_filter('query',$block);
  if(isset($capture))remove_filter('pre_wp_mail',$capture,PHP_INT_MAX);
  foreach($tables as $table)$wpdb->query("DROP TABLE {$table}");
}
