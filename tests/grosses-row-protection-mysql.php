<?php
/** WP-CLI: candidate Store against four private empty schema copies only. */
global $wpdb;
$source=file_get_contents($args[0]);
$source=str_replace('namespace RoxyGrosses;','namespace RoxyGrossesFixture37;',$source);
$maps=['roxy_grosses_entries'=>'roxy_fixture37_entries','roxy_grosses_live_entries'=>'roxy_fixture37_live','roxy_grosses_rental_entries'=>'roxy_fixture37_rentals','roxy_grosses_legacy_weekly'=>'roxy_fixture37_legacy'];
foreach($maps as $old=>$new)$source=str_replace("'".$old."'","'".$new."'",$source);
$source=str_replace("'roxy_grosses_row_lock_schema'","'roxy_fixture37_row_lock_schema'",$source);
eval('namespace RoxyGrossesFixture37; class Metadata { public static $replace=false; public static function enrich_movie_row(array $row, bool $force = false, bool $allow_remote = true):array {return self::$replace?array_merge($row,["studio"=>"New Studio","genre"=>"New Genre"]):$row;} public static function metadata_for_movie(string $title, int $year = 0, bool $force = false):array {return ["studio"=>"New Studio","genre"=>"New Genre"];} }');
eval('?>'.$source);
$class=RoxyGrossesFixture37\Store::class;
$tables=[]; $count=0;
$check=static function($ok,$message)use(&$count){if(!$ok)throw new RuntimeException($message); echo "PASS: $message\n"; $count++;};
$option=$class::ROW_LOCK_SCHEMA_OPTION; $option_owned=false;
$silenced=$wpdb->suppress_errors(true);
try {
  if(get_option($option,null)!==null)throw new RuntimeException('Fixture option already exists');
  $option_owned=true;
  foreach($maps as $old=>$new){$table=$wpdb->prefix.$new;if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)))throw new RuntimeException('Fixture table already exists');if($wpdb->query("CREATE TABLE {$table} LIKE {$wpdb->prefix}{$old}")===false)throw new RuntimeException('Fixture schema copy failed');$tables[]=$table;}
  delete_option($option);
  $check($class::ensure_row_lock_columns(),'additive lock-column migration succeeds on private copies');
  $check($class::ensure_row_lock_columns(),'migration is repeatable');
  foreach($tables as $table)$check((bool)$wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'is_locked'"),'protection column exists '.$table);
  $datasets=[
    ['upsert_entries','update_entry','get_entry',$class::entries_table_name(),['report_date'=>'2037-01-02','movie_title'=>'Fixture Movie','show_time'=>'18:00','showing_id'=>19000001,'studio'=>'Fixture Studio','genre'=>'Fixture Genre','total_tickets'=>10,'gross_total'=>120,'concessions_total'=>40]],
    ['upsert_live_entries','update_live_entry','get_live_entry',$class::live_entries_table_name(),['report_date'=>'2037-01-02','show_title'=>'Fixture Live','show_time'=>'20:00','showing_id'=>19000002,'total_tickets'=>10,'gross_total'=>120,'concessions_total'=>40]],
    ['upsert_rental_entries','update_rental_entry','get_rental_entry',$class::rental_entries_table_name(),['report_date'=>'2037-01-02','rental_title'=>'Fixture Rental','show_time'=>'14:00','invoice_amount'=>120,'concessions_total'=>40]],
    ['upsert_legacy_weekly','update_legacy_weekly','get_legacy_weekly',$class::legacy_weekly_table_name(),['week_start_date'=>'2037-01-02','week_end_date'=>'2037-01-08','movie_title'=>'Fixture Legacy','total_attendance'=>10,'gross_total'=>120,'concessions_total'=>40]],
  ];
  $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST); $other->suppress_errors(true);
  foreach($datasets as [$upsert,$update,$get,$table,$seed]) {
    $result=$class::$upsert([$seed]); $id=(int)$wpdb->insert_id;
    $check($result['created']===1&&$id>0,"$upsert creates an unlocked row");
    $amount=array_key_exists('invoice_amount',$seed)?'invoice_amount':'gross_total';
    $check($class::$update($id,[$amount=>333,'notes'=>'Manager correction'],true),"$update manual correction saves");
    $before=$class::$get($id); $check((int)$before['is_locked']===1,"$update protects by default");
    $result=$class::$upsert([array_merge($seed,[$amount=>999,'notes'=>'Old pull','is_locked'=>0])]);
    $check($result['updated']===0&&$result['skipped']===1&&$before===$class::$get($id),"$upsert preserves complete locked row");
    $check(!$class::$update($id,['concessions_total'=>666]),"$update automatic concession refresh cannot overwrite protection");
    $check($class::$update($id,['is_locked'=>0],true),"$update explicit manager unlock works");
    $result=$class::$upsert([array_merge($seed,[$amount=>222])]);
    $after=$class::$get($id);
    $check($result['updated']===1&&(float)$after[$amount]===222.0&&$after['notes']==='Manager correction',"$upsert unlocked pull works and absent notes are retained");
    $before=$after; $race=true;
    $filter=static function($sql)use(&$race,$table,$id,$amount,$other){
      if($race&&strpos($sql,"UPDATE `$table`")===0){$race=false;if($other->update($table,['is_locked'=>1,$amount=>444,'notes'=>'Concurrent correction'],['id'=>$id])!==1)throw new RuntimeException('Independent correction failed');}
      return $sql;
    };
    add_filter('query',$filter);
    $result=$class::$upsert([array_merge($seed,[$amount=>777])]); remove_filter('query',$filter);
    $after=$class::$get($id);
    $check(!$race&&$result['updated']===0&&$result['skipped']===1&&(float)$after[$amount]===444.0&&$after['notes']==='Concurrent correction',"$upsert SQL predicate preserves independent raced correction");
    $check(!$class::$update(99999999,[$amount=>10],true),"$update missing row does not report success");
    $class::$update($id,['is_locked'=>0],true); $race=true; add_filter('query',$filter);
    $ok=$class::$update($id,['concessions_total'=>999]); remove_filter('query',$filter);
    $after=$class::$get($id);
    $check(!$race&&!$ok&&(int)$after['is_locked']===1&&(float)$after['concessions_total']===40.0,"$update automatic partial update respects independent raced correction");
    $class::$update($id,['is_locked'=>0],true);
    foreach(['SELECT','UPDATE','INSERT'] as $verb) {
      $fault=true;
      $failure_filter=static function($sql)use(&$fault,$verb,$table){
        if($fault&&preg_match('/^'. $verb .'\\b/i',$sql)&&strpos($sql,$table)!==false){$fault=false;return $verb==='SELECT'?'SELECT * FROM roxy_fixture37_missing_table':'UPDATE roxy_fixture37_missing_table SET id=1';}
        return $sql;
      };
      $before=$class::$get($id); $row=$seed;
      if($verb==='INSERT') {
        if(isset($row['report_date']))$row['report_date']='2037-02-02';
        if(isset($row['week_start_date']))$row['week_start_date']='2037-02-02';
      }
      $thrown=false; add_filter('query',$failure_filter);
      try {$class::$upsert([$row]);}catch(RuntimeException $e){$thrown=true;}
      finally {remove_filter('query',$failure_filter);}
      $check(!$fault&&$thrown&&$before===$class::$get($id)&&1===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}"),"$upsert $verb failure stops without replacement or false success");
    }
  }
  RoxyGrossesFixture37\Metadata::$replace=true;
  $movie=$datasets[0]; $table=$movie[3]; $id=(int)$wpdb->get_var("SELECT id FROM {$table} LIMIT 1");
  $class::update_entry($id,['is_locked'=>1],true); $before=$class::get_entry($id);
  $result=$class::backfill_movie_metadata(500,true);
  $check($result['updated']===0&&$before===$class::get_entry($id),'forced metadata refresh preserves protected correction');
  RoxyGrossesFixture37\Metadata::$replace=false;
  $class::update_entry($id,['is_locked'=>0,'studio'=>'Before refresh'],true);
  RoxyGrossesFixture37\Metadata::$replace=true;
  $result=$class::backfill_movie_metadata(500,true);
  $check($result['updated']===1&&$class::get_entry($id)['studio']==='New Studio','metadata refresh still updates unlocked row: '.json_encode([$result,$class::get_entry($id)['studio'],$class::get_entry($id)['is_locked'],$wpdb->last_error]));
  RoxyGrossesFixture37\Metadata::$replace=false;
  $class::update_entry($id,['studio'=>'Before race','is_locked'=>0],true); $race=true;
  RoxyGrossesFixture37\Metadata::$replace=true;
  $metadata_filter=static function($sql)use(&$race,$table,$id,$other){if($race&&strpos($sql,"UPDATE `$table`")===0){$race=false;$other->update($table,['is_locked'=>1,'studio'=>'Concurrent studio'],['id'=>$id]);}return $sql;};
  add_filter('query',$metadata_filter);$result=$class::backfill_movie_metadata(500,true);remove_filter('query',$metadata_filter);
  $check(!$race&&$result['updated']===0&&$class::get_entry($id)['studio']==='Concurrent studio','metadata refresh SQL guard preserves independent raced correction');
  // Failure after some columns exist must never stamp the migration complete.
  delete_option($option); $failed=true;
  $migration_filter=static function($sql)use(&$failed,$tables){if($failed&&strpos($sql,'SHOW COLUMNS FROM '.$tables[1])===0){$failed=false;return 'SHOW COLUMNS FROM roxy_fixture37_missing_table';}return $sql;};
  add_filter('query',$migration_filter);$ready=$class::ensure_row_lock_columns();remove_filter('query',$migration_filter);
  $check(!$ready&&!get_option($option),'failed migration read does not stamp completion');
  $check($class::ensure_row_lock_columns(),'retry after storage recovery succeeds');
  echo "Passed $count actual MySQL row-protection checks. Production rows untouched.\n";
} finally {
  if(isset($filter))remove_filter('query',$filter);
  if(isset($migration_filter))remove_filter('query',$migration_filter);
  if(isset($failure_filter))remove_filter('query',$failure_filter);
  if(isset($metadata_filter))remove_filter('query',$metadata_filter);
  foreach($tables as $table)$wpdb->query("DROP TABLE {$table}");
  if($option_owned)delete_option($option); $wpdb->suppress_errors($silenced);
}
