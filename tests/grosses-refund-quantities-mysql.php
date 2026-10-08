<?php
// Actual Store ticket-only correction, exclusively on a private schema copy.
global $wpdb;
$suffix = 'roxy_refund_qty_' . bin2hex(random_bytes(6));
$table = $wpdb->prefix . $suffix;
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) throw new RuntimeException('Private fixture table exists.');
if ($wpdb->query("CREATE TABLE $table LIKE " . $wpdb->prefix . 'roxy_grosses_entries') === false) throw new RuntimeException('Could not create private fixture.');
$checks = 0;
$check = static function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException($label); $checks++; echo "PASS: $label\n"; };
$namespace = 'RefundQtyFixture41';
$source = str_replace(['namespace RoxyGrosses;', "'roxy_grosses_entries'"], ["namespace $namespace;", "'$suffix'"], file_get_contents($args[0]));
eval('?>' . $source);
$store = $namespace . '\\Store';
$filter = null;
$suppress = $wpdb->suppress_errors(true);
try {
  $seed = ['created_at'=>'2037-01-02 00:00:00', 'updated_at'=>'2037-01-02 00:00:00', 'report_date'=>'2037-01-02', 'movie_title'=>'Private Film', 'normalized_title'=>$store::normalize_title('Private Film'), 'show_time'=>'6:00 PM', 'showing_id'=>271828, 'general_qty'=>3, 'total_tickets'=>3, 'gross_total'=>36, 'concessions_total'=>12.34, 'studio'=>'Private Studio', 'genre'=>'Private Genre', 'notes'=>'preserve correction notes', 'subscriber_qty'=>5, 'other_qty'=>7, 'source_type'=>'fixture', 'source_ref'=>'original'];
  if ($wpdb->insert($table, $seed) === false) throw new RuntimeException('Seed failed.');
  $id = (int) $wpdb->insert_id;
  $read = static fn() => $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
  $before = $read();
  $report = ['report_date'=>'2037-01-02', 'film_title'=>'Private Film', 'show_time'=>'6:00 PM', 'showing_id'=>271828, 'general_qty'=>2, 'discount_qty'=>0, 'group_qty'=>0, 'live_qty'=>0, 'total_tickets'=>2, 'gross_total'=>24, 'concessions_total'=>999, 'refund_adjusted'=>true];
  $result = $store::update_refunded_movie_quantities([$report]);
  $after = $read();
  $check($result === ['updated'=>1, 'protected'=>0] && (int)$after['general_qty']===2 && (float)$after['gross_total']===24.0, 'actual partial ticket refund corrects original quantity and nominal gross');
  $untouched = ['concessions_total','studio','genre','notes','source_type','source_ref','subscriber_qty','other_qty','is_locked','report_date','created_at'];
  foreach ($untouched as $field) $check($after[$field] === $before[$field], 'ticket-only update preserves ' . $field);
  $report['general_qty']=$report['total_tickets']=0; $report['gross_total']=0;
  $store::update_refunded_movie_quantities([$report]);
  $check((int)$read()['total_tickets']===0 && (float)$read()['gross_total']===0.0 && (float)$read()['concessions_total']===12.34, 'full refund reaches zero without deleting row or changing concession allocation');
  $wpdb->update($table, ['is_locked'=>1], ['id'=>$id]);
  $locked = $read();
  $check($store::update_refunded_movie_quantities([$report]) === ['updated'=>0,'protected'=>1] && $read()===$locked, 'manual protection prevents every correction-column write');
  $wpdb->update($table, ['is_locked'=>0, 'general_qty'=>3, 'total_tickets'=>3, 'gross_total'=>36], ['id'=>$id]);
  $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
  $raced = false;
  $filter = static function ($sql) use ($table,$id,$other,&$raced) {
    if (!$raced && preg_match('/^UPDATE `?' . preg_quote($table,'/') . '`?\s/i',$sql)) {
      $raced = true;
      if ($other->update($table, ['is_locked'=>1], ['id'=>$id])===false) throw new RuntimeException('Independent lock correction failed.');
    }
    return $sql;
  };
  add_filter('query',$filter);
  $result = $store::update_refunded_movie_quantities([$report]);
  remove_filter('query',$filter); $filter=null;
  $check($raced && $result===['updated'=>0,'protected'=>1] && (int)$read()['general_qty']===3, 'independent correction racing the UPDATE retains its protected ticket figures');
  $wpdb->update($table, ['is_locked'=>0], ['id'=>$id]);
  $missing = $report; $missing['report_date']='2037-01-03';
  $failed = false;
  try { $store::update_refunded_movie_quantities([$missing]); } catch (RuntimeException $error) { $failed=true; }
  $check($failed && (int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===1, 'missing original row fails review rather than creating a guessed historical row');
  $duplicate = $seed; $duplicate['movie_title']='Different Title'; $duplicate['normalized_title']=$store::normalize_title($duplicate['movie_title']);
  if ($wpdb->insert($table,$duplicate)===false) throw new RuntimeException('Ambiguity seed failed.');
  $failed=false;
  try { $store::update_refunded_movie_quantities([$report]); } catch (RuntimeException $error) { $failed=true; }
  $check($failed && (int)$read()['general_qty']===3, 'duplicate showing linkage fails rather than choosing the first stored row');
  echo "$checks actual MySQL ticket-only refund checks passed. No production data or mail changed.\n";
} finally {
  if ($filter) remove_filter('query',$filter);
  $wpdb->suppress_errors($suppress);
  if (!preg_match('/^' . preg_quote($wpdb->prefix,'/') . 'roxy_refund_qty_[a-f0-9]{12}$/',$table)) throw new RuntimeException('Unsafe cleanup target.');
  $wpdb->query("DROP TABLE $table");
}
