<?php
/** WP-CLI, actual candidate Store against fixture-owned schemas/options only. */
global $wpdb;
$source=file_get_contents($args[0]);
$namespace='RoxyGrossesMigrationFixture38';
$source=str_replace('namespace RoxyGrosses;',"namespace $namespace;",$source);
$maps=['roxy_grosses_reports'=>'roxy_fixture38_reports','roxy_grosses_history'=>'roxy_fixture38_history','roxy_grosses_entries'=>'roxy_fixture38_entries'];
foreach($maps as $old=>$new)$source=str_replace("'".$old."'","'".$new."'",$source);
foreach(['roxy_grosses_history_backfilled','roxy_grosses_entries_migrated','roxy_grosses_migration_'] as $name)$source=str_replace("'".$name."'","'".str_replace('roxy_grosses','roxy_fixture38',$name)."'",$source);
eval('namespace '.$namespace.'; class Metadata {public static function enrich_movie_row(array $row, bool $force = false, bool $allow_remote = true):array{return $row;}}');
eval('?>'.$source);
$store=$namespace.'\\Store'; $tables=[]; $options=[]; $checks=0;
$assert=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";$checks++;};
$silent=$wpdb->suppress_errors(true);
$digest=static function($table)use($wpdb){$rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id",ARRAY_A);if($wpdb->last_error!=='')throw new RuntimeException('Fixture digest failed');return hash('sha256',json_encode($rows));};
try {
  foreach([$store::HISTORY_BACKFILL_OPTION,$store::ENTRY_MIGRATION_OPTION] as $option){if(get_option($option,null)!==null)throw new RuntimeException('Fixture option exists');$options[]=$option;}
  foreach($maps as $old=>$new){$table=$wpdb->prefix.$new;if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)))throw new RuntimeException('Fixture table exists');if($wpdb->query("CREATE TABLE {$table} LIKE {$wpdb->prefix}{$old}")===false)throw new RuntimeException('Cannot create fixture schema');$tables[]=$table;}
  $history=$store::history_table_name(); $entries=$store::entries_table_name(); $reports=$store::table_name();
  $guard=static function($sql)use($wpdb){if(preg_match('/^\s*(INSERT|UPDATE|REPLACE|DELETE|ALTER|TRUNCATE)\b/i',$sql)&&strpos($sql,$wpdb->prefix.'roxy_grosses_')!==false)throw new RuntimeException('Fixture attempted production reporting mutation');return $sql;};
  add_filter('query',$guard);
  $row=['report_date'=>'2038-01-02','showing_id'=>19000001,'show_time'=>'18:00','film_title'=>'Fixture corrected','general_qty'=>2,'total_tickets'=>2,'gross_total'=>20];
  $store::upsert_history_rows([array_merge($row,['gross_total'=>222])],'newer',null);
  $store::upsert_entries([array_merge($row,['movie_title'=>$row['film_title'],'gross_total'=>333,'live_qty'=>7,'concessions_total'=>99,'notes'=>'Manager correction','is_locked'=>0])]);
  $corrected=(int)$wpdb->insert_id; $before=$store::get_entry($corrected); $history_before=$digest($history);
  $new=array_merge($row,['report_date'=>'2038-01-03','showing_id'=>19000002,'film_title'=>'Fixture new','gross_total'=>30]);
  $assert($store::create_report('2038-01-03',2,'fixture','draft',['gross_total'=>50,'total_tickets'=>4],[$row,$new])>0,'saved source fixture exists');
  $no_id_a=['report_date'=>'2038-01-05','film_title'=>'No ID first showing','show_time'=>'17:00','general_qty'=>1,'total_tickets'=>1,'gross_total'=>10];
  $no_id_b=['report_date'=>'2038-01-05','film_title'=>'No ID second showing','show_time'=>'20:00','general_qty'=>1,'total_tickets'=>1,'gross_total'=>10];
  $assert($store::create_report('2038-01-05',1,'fixture','draft',['gross_total'=>20,'total_tickets'=>2],[$no_id_a,$no_id_b])>0,'saved report with multiple no-ID showings exists');
  $no_id_report_id=(int)$wpdb->insert_id;
  $no_id_payload=$wpdb->get_var($wpdb->prepare('SELECT payload_json FROM '.$reports.' WHERE id=%d',$no_id_report_id));
  if(!is_string($no_id_payload)||$wpdb->last_error!=='')throw new RuntimeException('Could not preserve saved migration fixture payload');
  update_option($store::HISTORY_BACKFILL_OPTION,'0.0.1',false);update_option($store::ENTRY_MIGRATION_OPTION,'0.0.1',false);
  $store::maybe_backfill_history();
  $assert($before===$store::get_entry($corrected)&&$history_before===$digest($history),'old completed version markers do not replay historical data');
  $assert(get_option($store::ENTRY_MIGRATION_OPTION)==='0.0.1','legacy marker retained without version bump');
  delete_option($store::HISTORY_BACKFILL_OPTION);delete_option($store::ENTRY_MIGRATION_OPTION);
  $invalid_payloads=[
    'invalid JSON'=>'not-json',
    'missing row list'=>wp_json_encode(['summary'=>[]]),
    'non-list row collection'=>wp_json_encode(['rows'=>['key'=>'not-a-list']]),
    'malformed row'=>wp_json_encode(['rows'=>['not-an-array']]),
    'invalid calendar date'=>wp_json_encode(['rows'=>[array_merge($no_id_a,['report_date'=>'2038-02-30'])]]),
    'missing movie title'=>wp_json_encode(['rows'=>[array_merge($no_id_a,['film_title'=>''])]]),
  ];
  foreach($invalid_payloads as $label=>$payload_json) {
    if(!is_string($payload_json)||$wpdb->update($reports,['payload_json'=>$payload_json],['id'=>$no_id_report_id])===false)throw new RuntimeException('Could not install malformed saved-report fixture');
    $history_before_invalid_payload=$digest($history);
    $store::maybe_backfill_history();
    $assert((string)get_option($store::HISTORY_BACKFILL_OPTION,'')===''&&$history_before_invalid_payload===$digest($history),"$label saved-report payload is rejected without history writes or completion stamp");
  }
  $assert($wpdb->update($reports,['payload_json'=>$no_id_payload],['id'=>$no_id_report_id])!==false,'saved-report payload restored for retry');
  $store::maybe_backfill_history();
  $assert(get_option($store::HISTORY_BACKFILL_OPTION)==='1'&&get_option($store::ENTRY_MIGRATION_OPTION)==='1','fresh migrations stamp independent one-time completion');
  $assert($before===$store::get_entry($corrected),'unlocked correction including free/concession/notes survives initial migration');
  $assert((float)$wpdb->get_var("SELECT gross_total FROM {$history} WHERE report_date='2038-01-02'")===222.0,'newer historical row is not replaced by old saved snapshot');
  $assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$history} WHERE report_date='2038-01-05'")===2,'distinct same-date showings without source IDs retain separate derived identities');
  $assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$entries} WHERE report_date='2038-01-03'")===1&&(float)$wpdb->get_var("SELECT gross_total FROM {$entries} WHERE report_date='2038-01-03'")===30.0,'missing historical entry inserted with original source values');
  $baseline=[$digest($history),$digest($entries)];$store::maybe_backfill_history();
  $assert($baseline===[$digest($history),$digest($entries)],'repeat bootstrap does not change rows or timestamps');
  foreach(['saved read','history read','history write','entry write','marker write','ownership read'] as $fault) {
    if($fault==='saved read'||$fault==='history write')delete_option($store::HISTORY_BACKFILL_OPTION);
    else delete_option($store::ENTRY_MIGRATION_OPTION);
    $third=array_merge($row,['report_date'=>'2038-01-04','showing_id'=>19000003,'film_title'=>'Fixture third']);
    if($fault==='history write'||$fault==='entry write') {
      $wpdb->query("DELETE FROM {$entries} WHERE report_date='2038-01-04'");
      $wpdb->query("DELETE FROM {$history} WHERE report_date='2038-01-04'");
      if($fault==='history write')$store::create_report('2038-01-04',1,'fixture','draft',[],[$third]);
      else $store::upsert_history_rows([$third]);
    }
    $fired=false;
    $inject=static function($sql)use(&$fired,$fault,$reports,$history,$entries,$store,$wpdb){
      $match=($fault==='saved read'&&strpos($sql,'SELECT id, mode, payload_json FROM '.$reports)===0)
        ||($fault==='history read'&&strpos($sql,'SELECT id, report_date, showing_id')===0&&strpos($sql,$history)!==false)
        ||($fault==='history write'&&strpos($sql,"INSERT INTO `$history`")===0)
        ||($fault==='entry write'&&strpos($sql,"INSERT INTO `$entries`")===0)
        ||($fault==='marker write'&&preg_match('/^INSERT INTO `'.preg_quote($wpdb->options,'/').'`/',$sql)&&strpos($sql,$store::ENTRY_MIGRATION_OPTION)!==false)
        ||($fault==='ownership read'&&strpos($sql,'SELECT IS_USED_LOCK(')===0);
      if(!$fired&&$match){$fired=true;return $fault==='ownership read'?'SELECT 0':'SELECT * FROM roxy_fixture38_missing_table';}return $sql;
    };
    // INSERT faults need a false SQL result, not a valid empty SELECT.
    add_filter('query',$inject);$store::maybe_backfill_history();remove_filter('query',$inject);
    $option=in_array($fault,['saved read','history write'],true)?$store::HISTORY_BACKFILL_OPTION:$store::ENTRY_MIGRATION_OPTION;
    $assert($fired&&(string)get_option($option,'')==='',"$fault failure remains retryable without completion stamp");
    $assert($before===$store::get_entry($corrected),"$fault preserves populated correction");
    $store::maybe_backfill_history();
    $assert(get_option($option)==='1',"$fault recovery retries successfully");
  }
  // Force both migrations across multiple bounded pages, then fail page two and retry.
  delete_option($store::HISTORY_BACKFILL_OPTION);
  for($i=0;$i<205;$i++) {
    $date=gmdate('Y-m-d',strtotime('2041-01-01 +'.$i.' days'));
    $saved=['report_date'=>$date,'showing_id'=>19400000+$i,'film_title'=>'Paged saved '.$i,'show_time'=>'18:00','total_tickets'=>1,'gross_total'=>1];
    if(!$store::create_report($date,1,'paged-fixture','draft',['total_tickets'=>1,'gross_total'=>1],[$saved]))throw new RuntimeException('Could not seed paged saved-report fixture');
  }
  $saved_page=0;$saved_fault=false;
  $saved_inject=static function($sql)use(&$saved_page,&$saved_fault,$reports){
    if(strpos($sql,'SELECT id, mode, payload_json FROM '.$reports.' WHERE id <')===0&&++$saved_page===2){$saved_fault=true;return 'SELECT * FROM roxy_fixture38_missing_table';}
    return $sql;
  };
  add_filter('query',$saved_inject);$store::maybe_backfill_history();remove_filter('query',$saved_inject);
  $assert($saved_fault&&(string)get_option($store::HISTORY_BACKFILL_OPTION,'')==='','second saved-report page failure leaves migration retryable');
  $store::maybe_backfill_history();
  $assert(get_option($store::HISTORY_BACKFILL_OPTION)==='1'&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$history} WHERE report_date BETWEEN '2041-01-01' AND '2041-07-24'")===205,'paged saved-report retry completes every row through its captured boundary');

  delete_option($store::ENTRY_MIGRATION_OPTION);
  $legacy=[];
  for($i=0;$i<205;$i++)$legacy[]=['report_date'=>gmdate('Y-m-d',strtotime('2042-01-01 +'.$i.' days')),'showing_id'=>19500000+$i,'film_title'=>'Paged legacy '.$i,'show_time'=>'19:00','total_tickets'=>2,'gross_total'=>12,'source_mode'=>'legacy-fixture'];
  $store::upsert_history_rows($legacy,'legacy-fixture');
  $history_page=0;$history_fault=false;
  $history_inject=static function($sql)use(&$history_page,&$history_fault,$history){
    if(strpos($sql,'SELECT id, report_date, showing_id, show_time')===0&&strpos($sql,' FROM '.$history.' WHERE id <= ')!==false&&strpos($sql,'ORDER BY report_date ASC, film_title ASC, id ASC LIMIT 100')!==false&&++$history_page===2){$history_fault=true;return 'SELECT * FROM roxy_fixture38_missing_table';}
    return $sql;
  };
  add_filter('query',$history_inject);$store::maybe_backfill_history();remove_filter('query',$history_inject);
  $assert($history_fault&&(string)get_option($store::ENTRY_MIGRATION_OPTION,'')==='','second legacy-history page failure leaves entry migration retryable');
  $store::maybe_backfill_history();
  $assert(get_option($store::ENTRY_MIGRATION_OPTION)==='1'&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$entries} WHERE report_date BETWEEN '2042-01-01' AND '2042-07-24'")===205,'paged legacy-history retry inserts every row and is completion-stamped');
  $assert($before===$store::get_entry($corrected),'paged migration and retries preserve a current correction');
  delete_option($store::ENTRY_MIGRATION_OPTION);
  $bad_date='2042-07-25';
  if($wpdb->insert($history,['created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),'report_date'=>$bad_date,'showing_id'=>19600000,'film_title'=>'','source_mode'=>'malformed-fixture'])===false)throw new RuntimeException('Could not seed malformed legacy-history row');
  $bad_id=(int)$wpdb->insert_id;
  $store::maybe_backfill_history();
  $assert((string)get_option($store::ENTRY_MIGRATION_OPTION,'')===''&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$entries} WHERE report_date=%s AND showing_id=%d",$bad_date,19600000))===0,'legacy history with an empty title stays retryable and is not silently marked complete');
  if($wpdb->update($history,['film_title'=>'Repaired legacy title'],['id'=>$bad_id])===false)throw new RuntimeException('Could not repair malformed legacy-history fixture');
  $store::maybe_backfill_history();
  $assert(get_option($store::ENTRY_MIGRATION_OPTION)==='1'&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$entries} WHERE report_date=%s AND showing_id=%d",$bad_date,19600000))===1,'legacy history migration resumes after malformed source data is repaired');
  delete_option($store::ENTRY_MIGRATION_OPTION);
  $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->suppress_errors(true);
  $lock='roxy_fixture38_migration_'.substr(hash('sha256',$wpdb->prefix),0,24);
  if((string)$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new RuntimeException('Cannot establish independent migration claim');
  try {$store::maybe_backfill_history();$assert((string)get_option($store::ENTRY_MIGRATION_OPTION,'')==='','independent migration owner prevents overlapping bootstrap');}
  finally {$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
  $store::maybe_backfill_history();$assert(get_option($store::ENTRY_MIGRATION_OPTION)==='1','migration resumes after independent claim release');
  echo "Passed $checks actual MySQL non-destructive migration checks. Production reporting untouched.\n";
} finally {
  if(isset($inject))remove_filter('query',$inject);
  if(isset($saved_inject))remove_filter('query',$saved_inject);
  if(isset($history_inject))remove_filter('query',$history_inject);
  if(isset($guard))remove_filter('query',$guard);
  foreach($tables as $table)$wpdb->query("DROP TABLE {$table}");
  foreach($options as $option)delete_option($option);
  $wpdb->suppress_errors($silent);
}
