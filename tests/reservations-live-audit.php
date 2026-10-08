<?php
// Read-only WP-CLI verification against a private predeployment evidence file.
if(!defined('WP_CLI') || !WP_CLI)exit;
$root=$args[0]??dirname(__DIR__);$evidence=$args[1]??'';
require __DIR__.'/ticket-fixture-loader.php';roxy_fixture_reservations($root);
global $wpdb;
$before=json_decode(file_get_contents($evidence),true,512,JSON_THROW_ON_ERROR);
$ids=implode(',',array_map('intval',$before['ticket_ids']));if($ids==='')throw new RuntimeException('Empty baseline');
$queries=[
 'tickets'=>"SELECT * FROM `{$wpdb->posts}` WHERE ID IN ($ids) ORDER BY ID",
 'ticket_meta'=>"SELECT * FROM `{$wpdb->postmeta}` WHERE post_id IN ($ids) ORDER BY meta_id",
 'member_logs'=>"SELECT * FROM `{$wpdb->prefix}roxy_member_scans` ORDER BY id",
 'will_call'=>"SELECT * FROM `{$wpdb->prefix}roxy_will_call_checkins` ORDER BY id",
];
foreach($queries as $key=>$sql){$rows=$wpdb->get_results($sql,ARRAY_A);if($wpdb->last_error || count($rows)!==$before[$key]['count'] || !hash_equals($before[$key]['hash'],hash('sha256',wp_json_encode($rows))))throw new RuntimeException('Baseline differs: '.$key);echo 'UNCHANGED '.$key.' '.count($rows).PHP_EOL;}
$private=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_title LIKE %s",'%PRIVATE RESERVATION AUTHORITY FIXTURE 2026-10-05%'));
if($private!==0)throw new RuntimeException('Private fixture records remain');echo 'PRIVATE_RESERVATION_FIXTURES=0'.PHP_EOL;
$showings=$wpdb->get_col("SELECT p.ID FROM `{$wpdb->posts}` p JOIN `{$wpdb->postmeta}` m ON m.post_id=p.ID AND m.meta_key='_roxy_capacity' WHERE p.post_type='roxy_showing' AND p.post_status='publish' ORDER BY p.ID DESC LIMIT 20");
$times=[];foreach(array_unique($showings) as $id){$start=microtime(true);\RoxyST\FixtureReservations::quantity_for_showing((int)$id);$times[]=(microtime(true)-$start)*1000;}
echo 'READ_TIMING_MS='.wp_json_encode(['showings'=>count($times),'max'=>round(max($times?:[0]),2),'mean'=>round(array_sum($times)/max(1,count($times)),2)]).PHP_EOL;
