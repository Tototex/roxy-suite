<?php
/** Actual cleanup SQL/locks on a private temp table; virtual attachments only. */
if(!defined('WP_CLI')||!WP_CLI)exit;
global $wpdb;
$path=$args[0]??'';if(!is_file($path))throw new RuntimeException('Candidate Store required.');
$namespace='SocialCleanup53_'.bin2hex(random_bytes(4));
$suffix='fixture_social_cleanup53_'.bin2hex(random_bytes(5));$table=$wpdb->prefix.$suffix;$queue_suffix=$suffix.'_queue';$queue_table=$wpdb->prefix.$queue_suffix;$poster_queue_suffix=$suffix.'_poster_queue';$poster_queue_table=$wpdb->prefix.$poster_queue_suffix;
$yoast_table=$wpdb->prefix.'fixture_yoast_indexable_'.$suffix;
$reference_posts=$wpdb->prefix.'rcp_'.$suffix;
$production=$wpdb->prefix.'roxy_social_posts';
$before=hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$production.' ORDER BY id',ARRAY_A)));
$code=file_get_contents($path);
$code=str_replace(['namespace RoxySocial;',"'roxy_social_posts'","'roxy_social_media_cleanup'","'roxy_social_poster_cleanup'"],['namespace '.$namespace.';',var_export($suffix,true),var_export($queue_suffix,true),var_export($poster_queue_suffix,true)],$code);
eval('?>'.$code);$store=$namespace.'\\Store';
eval('namespace '.$namespace.';
function apply_filters($hook,$value){return $hook==="roxy_social_enable_detached_media_cleanup"?($GLOBALS["cleanup53_detached_enabled"]??$value):$value;}
function get_post($id){return !empty($GLOBALS["cleanup53_exists"])?(object)["ID"=>$id,"post_type"=>"attachment"]:null;}
function get_post_meta($id,$key,$single=true){return $key==="_roxy_social_temporary"?($GLOBALS["cleanup53_owner"]?"1":"0"):($key==="_roxy_hangar_asset_id"?"888":null);}
function wp_get_attachment_url($id){return "https://fixture.example.invalid/cleanup53-".$id.".mp4";}
function wp_delete_attachment($id,$force=false){$GLOBALS["cleanup53_delete_calls"]++;if($GLOBALS["cleanup53_delete_fail"])return false;$GLOBALS["cleanup53_exists"]=false;return (object)["ID"=>$id];}
function wp_schedule_single_event($timestamp,$hook,$args=[]){$GLOBALS["cleanup53_scheduled"][]=[$timestamp,$hook,$args];return true;}
');
$checks=0;$assert=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS: '.$label.PHP_EOL;};
$block=static function($sql)use($table,$queue_table,$poster_queue_table,$yoast_table,$reference_posts){if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i',$sql)&&strpos($sql,$table)===false&&strpos($sql,$queue_table)===false&&strpos($sql,$poster_queue_table)===false&&strpos($sql,$yoast_table)===false&&strpos($sql,$reference_posts)===false)throw new RuntimeException('Non-private cleanup SQL mutation.');return $sql;};
$mail=static function(){throw new RuntimeException('Unexpected mail.');};$http=static function(){throw new RuntimeException('Unexpected HTTP.');};
$old=$wpdb->suppress_errors(true);$created=false;
try {
    foreach([
        'social rows'=>'CREATE TEMPORARY TABLE '.$table.' LIKE '.$production,
        'media cleanup queue'=>'CREATE TEMPORARY TABLE '.$queue_table.' (attachment_id BIGINT UNSIGNED NOT NULL, social_post_id BIGINT UNSIGNED NOT NULL, cleanup_after DATETIME NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (attachment_id), KEY cleanup_after (cleanup_after), KEY social_post_id (social_post_id))',
        'poster cleanup queue'=>'CREATE TEMPORARY TABLE '.$poster_queue_table.' (path_hash CHAR(64) NOT NULL, file_path TEXT NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (path_hash), KEY retry_batch (attempts, updated_at, path_hash))',
        'Yoast image reference'=>'CREATE TEMPORARY TABLE '.$yoast_table.' (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, open_graph_image_id VARCHAR(32) NULL, twitter_image_id VARCHAR(32) NULL, PRIMARY KEY (id))',
        'post content reference'=>'CREATE TEMPORARY TABLE '.$reference_posts.' (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, post_content LONGTEXT NOT NULL, PRIMARY KEY (ID))',
    ] as $label=>$sql){if($wpdb->query($sql)===false)throw new RuntimeException('Private cleanup schema failed for '.$label.': '.($wpdb->last_error?:$sql));}$created=true;
    add_filter('query',$block,PHP_INT_MAX);add_filter('pre_wp_mail',$mail,PHP_INT_MAX);add_filter('pre_http_request',$http,PHP_INT_MAX);
$virtual_base=random_int(50000000000,90000000000);
$reset=static function()use($wpdb,$table,$virtual_base){
        if($wpdb->query('DELETE FROM '.$table)===false)throw new RuntimeException('Private reset failed.');
        $GLOBALS['cleanup53_exists']=true;$GLOBALS['cleanup53_owner']=true;$GLOBALS['cleanup53_delete_fail']=false;$GLOBALS['cleanup53_delete_calls']=0;
        $row=['id'=>1,'campaign_key'=>'PRIVATE','post_key'=>'PRIVATE-cleanup','showing_ids'=>'','platform'=>'both','scheduled_for'=>'2000-01-01 12:00:00','status'=>'posted','ai_status'=>'ready','post_text'=>'Private virtual media fixture','media_url'=>'https://fixture.example.invalid/cleanup53-'.$virtual_base.'.mp4','media_type'=>'video','temporary_attachment_id'=>$virtual_base,'cleanup_after'=>'2000-01-04 12:00:00','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')];
        if($wpdb->insert($table,$row)!==1)throw new RuntimeException('Private row failed.');return $row;
    };
    $reset();$GLOBALS['cleanup53_owner']=false;
    $assert($store::cleanup_expired()===0&&$GLOBALS['cleanup53_delete_calls']===0&&$store::find(1)['temporary_attachment_id']!==null,'unowned virtual attachment retained with tracking');
    $row=$reset();$row['id']=2;$row['post_key']='PRIVATE-shared';$row['status']='draft';$row['cleanup_after']=null;$wpdb->insert($table,$row);
    $assert($store::cleanup_expired()===0&&$GLOBALS['cleanup53_delete_calls']===0&&$store::find(1)['temporary_attachment_id']!==null,'actual shared Social row prevents media deletion');
    $reset();$GLOBALS['cleanup53_delete_fail']=true;
    $failed_delete_result=$store::cleanup_expired();$failed_delete_row=$store::find(1);
    $assert($failed_delete_result===0&&$GLOBALS['cleanup53_delete_calls']===1&&($failed_delete_row['temporary_attachment_id']??null)!==null,'failed virtual deletion preserves actual SQL pointer; '.json_encode(['result'=>$failed_delete_result,'delete_calls'=>$GLOBALS['cleanup53_delete_calls'],'attachment_id'=>$failed_delete_row['temporary_attachment_id']??null,'db_error'=>$wpdb->last_error]));
    $GLOBALS['cleanup53_delete_fail']=false;
    $assert($store::cleanup_expired()===1&&$GLOBALS['cleanup53_delete_calls']===2&&$store::find(1)['temporary_attachment_id']===null,'retry succeeds and guarded SQL clears pointer only afterward');
    $reset();$GLOBALS['cleanup53_exists']=false;
    $assert($store::cleanup_expired()===0&&$GLOBALS['cleanup53_delete_calls']===0&&$store::find(1)['temporary_attachment_id']===null,'already-absent media repairs tracking without another deletion');
    $reset();$wpdb->update($table,['cleanup_after'=>'2099-01-01 12:00:00'],['id'=>1]);
    $assert($store::cleanup_expired()===0&&$GLOBALS['cleanup53_delete_calls']===0,'future local deadline remains untouched');
    $wpdb->query('DELETE FROM '.$table);$wpdb->query('DELETE FROM '.$queue_table);
    $detached=['attachment_id'=>$virtual_base+50,'social_post_id'=>88,'cleanup_after'=>'2000-01-04 12:00:00','created_at'=>current_time('mysql')];
    $wpdb->insert($queue_table,$detached);$GLOBALS['cleanup53_exists']=true;$GLOBALS['cleanup53_delete_calls']=0;$GLOBALS['cleanup53_detached_enabled']=false;
    $assert($store::cleanup_expired()===0&&$GLOBALS['cleanup53_delete_calls']===0&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$queue_table)===1,'detached owned media remains queued and untouched without explicit opt-in');
    $GLOBALS['cleanup53_detached_enabled']=true;
    $assert($store::cleanup_expired()===1&&$GLOBALS['cleanup53_delete_calls']===1&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$queue_table)===0,'explicitly enabled detached cleanup deletes after deadline and removes its queue record');
    foreach(['open_graph_image_id','twitter_image_id'] as $image_column){
        $row=$reset();$GLOBALS['cleanup53_delete_calls']=0;
        if($wpdb->insert($yoast_table,[$image_column=>(string)$virtual_base])!==1)throw new RuntimeException('Could not seed the private Yoast image reference.');
        $result=$store::cleanup_expired();$current=$store::find(1);
        $assert($result===0&&$GLOBALS['cleanup53_delete_calls']===0&&(int)($current['temporary_attachment_id']??0)===$virtual_base,'Yoast '.$image_column.' keeps the referenced attachment and cleanup pointer; '.json_encode(['result'=>$result,'delete_calls'=>$GLOBALS['cleanup53_delete_calls'],'attachment_id'=>$current['temporary_attachment_id']??null,'db_error'=>$wpdb->last_error,'yoast_rows'=>$wpdb->get_results('SELECT * FROM '.$yoast_table,ARRAY_A)]));
        if($wpdb->delete($yoast_table,[$image_column=>(string)$virtual_base])===false)throw new RuntimeException('Could not clear private Yoast image reference.');
    }
    $poster_path=sys_get_temp_dir().'/fixture-hangar-poster-'.bin2hex(random_bytes(6)).'-poster.png';
    $original_posts_table=$wpdb->posts;
    $wpdb->posts=$reference_posts;
    $assert($store::queue_poster_cleanup($poster_path),'poster cleanup persists a path in its private durable table');
    $filename=basename($poster_path);
    if($wpdb->insert($reference_posts,['post_content'=>'<img src="https://example.test/wp-content/uploads/'.$filename.'">'])!==1)throw new RuntimeException('Could not seed private poster-content reference.');
    $reference_post_id=(int)$wpdb->insert_id;
    $assert($store::poster_path_is_referenced($poster_path)===true,'WordPress post content reference prevents generated poster deletion');
    if($wpdb->delete($reference_posts,['ID'=>$reference_post_id])===false)throw new RuntimeException('Could not remove private poster-content reference.');
    $assert($store::poster_path_is_referenced($poster_path)===false,'unreferenced generated poster remains eligible for cleanup');
    $wpdb->posts=$wpdb->prefix.'roxy_cleanup_fixture_missing_'.$suffix;
    $assert($store::poster_path_is_referenced($poster_path)===null,'post-content query failure fails closed and retains the poster');
    $wpdb->posts=$original_posts_table;
    $poster_rows=$store::poster_cleanup_batch(100);
    $assert(is_array($poster_rows)&&count($poster_rows)===1&&($poster_rows[0]['file_path']??'')===$poster_path,'poster cleanup reads a bounded durable batch');
    $assert($store::record_poster_cleanup_failure($poster_path)&&(int)($store::poster_cleanup_batch(100)[0]['attempts']??0)===1,'poster cleanup failure count persists for retry diagnostics');
    $assert($store::remove_poster_cleanup($poster_path)&&$store::poster_cleanup_batch(100)===[],'verified poster cleanup removes only its exact queue record');
    $assert(hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$production.' ORDER BY id',ARRAY_A)))===$before,'all production Social rows unchanged');
    $row=$reset();$GLOBALS['cleanup53_exists']=true;$GLOBALS['cleanup53_scheduled']=[];
    for($id=2;$id<=101;$id++){$later=$row;$later['id']=$id;$later['post_key']='PRIVATE-cleanup-'.$id;$later['temporary_attachment_id']=$virtual_base+$id;$later['media_url']='https://fixture.example.invalid/cleanup53-'.$later['temporary_attachment_id'].'.mp4';if($wpdb->insert($table,$later)!==1)throw new RuntimeException('Private pagination row failed.');}
    $pagination_result=$store::cleanup_expired();$pagination_schedule=$GLOBALS['cleanup53_scheduled'][0]??null;
    $assert($pagination_result===1&&($pagination_schedule[1]??'')==='roxy_social_cleanup_page'&&($pagination_schedule[2]??[])===[100,101],'actual private-table cleanup schedules exactly the remaining bounded high-water page; '.json_encode(['result'=>$pagination_result,'scheduled'=>$GLOBALS['cleanup53_scheduled']]));
    $assert(hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$production.' ORDER BY id',ARRAY_A)))===$before,'pagination leaves production Social rows unchanged');
} finally {
    if($created&&($wpdb->query('DROP TEMPORARY TABLE '.$yoast_table)===false||$wpdb->query('DROP TEMPORARY TABLE '.$reference_posts)===false||$wpdb->query('DROP TEMPORARY TABLE '.$poster_queue_table)===false||$wpdb->query('DROP TEMPORARY TABLE '.$queue_table)===false||$wpdb->query('DROP TEMPORARY TABLE '.$table)===false))throw new RuntimeException('Private cleanup schema removal failed.');
    remove_filter('query',$block,PHP_INT_MAX);remove_filter('pre_wp_mail',$mail,PHP_INT_MAX);remove_filter('pre_http_request',$http,PHP_INT_MAX);$wpdb->suppress_errors($old);
}
echo 'Passed '.$checks.' actual cleanup-SQL assertions with virtual media; private schema removed.'.PHP_EOL;
