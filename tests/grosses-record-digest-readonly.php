<?php
/** WP-CLI read-only projection, stable across additive lock columns. */
global $wpdb;
foreach(['roxy_grosses_entries','roxy_grosses_live_entries','roxy_grosses_rental_entries','roxy_grosses_legacy_weekly'] as $name) {
  $table=$wpdb->prefix.$name;
  $columns=$wpdb->get_col("SHOW COLUMNS FROM {$table}");
  if($wpdb->last_error!==''||!$columns)throw new RuntimeException('Digest column read failed');
  if($name!=='roxy_grosses_entries')$columns=array_values(array_diff($columns,['is_locked']));
  $select=implode(',',array_map(static fn($column)=>'`'.str_replace('`','``',$column).'`',$columns));
  $rows=$wpdb->get_results("SELECT {$select} FROM {$table} ORDER BY id",ARRAY_A);
  if($wpdb->last_error!=='')throw new RuntimeException('Digest row read failed');
  echo $name.' count='.count($rows).' sha256='.hash('sha256',json_encode($rows))."\n";
}
