<?php
/** Actual cleanup SQL/locks on a private temp table; virtual attachments only. */
if(!defined('WP_CLI')||!WP_CLI)exit;
global $wpdb;
$path=$args[0]??'';if(!is_file($path))throw new RuntimeException('Candidate Store required.');
$namespace='SocialCleanup53_'.bin2hex(random_bytes(4));
$suffix='fixture_social_cleanup53_'.bin2hex(random_bytes(5));$table=$wpdb->prefix.$suffix;$queue_suffix=$suffix.'_queue';$queue_table=$wpdb->prefix.$queue_suffix;
$production=$wpdb->prefix.'roxy_social_posts';
$before=hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$production.' ORDER BY id',ARRAY_A)));
$core_properties=['posts'=>'posts','postmeta'=>'postmeta','usermeta'=>'usermeta','termmeta'=>'termmeta','commentmeta'=>'commentmeta','options'=>'options'];
$core_original=[];$core_private=[];
$code=file_get_contents($path);
$code=str_replace(['namespace RoxySocial;',"'roxy_social_posts'","'roxy_social_media_cleanup'"],['namespace '.$namespace.';',var_export($suffix,true),var_export($queue_suffix,true)],$code);
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
$block=static function($sql)use($table,$queue_table,&$core_private){if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i',$sql)&&strpos($sql,$table)===false&&strpos($sql,$queue_table)===false&&!array_filter($core_private,static fn($name)=>strpos($sql,$name)!==false))throw new RuntimeException('Non-private cleanup SQL mutation.');return $sql;};
$mail=static function(){throw new RuntimeException('Unexpected mail.');};$http=static function(){throw new RuntimeException('Unexpected HTTP.');};
$old=$wpdb->suppress_errors(true);$created=false;
try {
    if($wpdb->query('CREATE TEMPORARY TABLE '.$table.' LIKE '.$production)===false||$wpdb->query('CREATE TEMPORARY TABLE '.$queue_table.' (attachment_id BIGINT UNSIGNED NOT NULL, social_post_id BIGINT UNSIGNED NOT NULL, cleanup_after DATETIME NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (attachment_id), KEY cleanup_after (cleanup_after), KEY social_post_id (social_post_id))')===false)throw new RuntimeException('Private cleanup schema failed.');$created=true;
    foreach($core_properties as $property=>$source_property){
        $source=$wpdb->{$source_property};$private=$wpdb->prefix.$suffix.'_wp_'.$property;
        if(!is_string($source)||!preg_match('/^[A-Za-z0-9_]+$/D',$source)||$wpdb->query('CREATE TEMPORARY TABLE '.$private.' LIKE '.$source)===false)throw new RuntimeException('Private WordPress reference schema failed for '.$property.'.');
        $core_original[$property]=$source;$core_private[]=$private;$wpdb->{$property}=$private;
    }
    add_filter('query',$block,PHP_INT_MAX);add_filter('pre_wp_mail',$mail,PHP_INT_MAX);add_filter('pre_http_request',$http,PHP_INT_MAX);
$virtual_base=random_int(50000000000,90000000000);
$reset=static function()use($wpdb,$table,$virtual_base){
        if($wpdb->query('DELETE FROM '.$table)===false)throw new RuntimeException('Private reset failed.');
        $GLOBALS['cleanup53_exists']=true;$GLOBALS['cleanup53_owner']=true;$GLOBALS['cleanup53_delete_fail']=false;$GLOBALS['cleanup53_delete_calls']=0;
        $row=['id'=>1,'campaign_key'=>'PRIVATE','post_key'=>'PRIVATE-cleanup','showing_ids'=>'','platform'=>'both','scheduled_for'=>'2000-01-01 12:00:00','status'=>'posted','ai_status'=>'ready','post_text'=>'Private virtual media fixture','media_url'=>'https://fixture.example.invalid/cleanup53-'.$virtual_base.'.mp4','media_type'=>'video','temporary_attachment_id'=>$virtual_base,'cleanup_after'=>'2000-01-04 12:00:00','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')];
        if($wpdb->insert($table,$row)!==1)throw new RuntimeException('Private row failed.');return $row;
    };
    $clear_core_refs=static function()use($wpdb,$core_private){foreach($core_private as $private){if($wpdb->query('DELETE FROM '.$private)===false)throw new RuntimeException('Private WordPress reference reset failed.');}};
    $reference_cases=[
        'posts'=>'post-content URL',
        'postmeta'=>'post metadata JSON ID',
        'usermeta'=>'user metadata JSON ID',
        'termmeta'=>'term metadata JSON ID',
        'commentmeta'=>'comment metadata JSON ID',
        'options'=>'option JSON ID',
    ];
    foreach($reference_cases as $property=>$label){
        $clear_core_refs();$row=$reset();$attachment_id=$virtual_base;$url='https://fixture.example.invalid/cleanup53-'.$attachment_id.'.mp4';
        if($property==='posts'){
            $now=current_time('mysql');$gmt=current_time('mysql',true);
            $inserted=$wpdb->insert($wpdb->posts,['post_author'=>0,'post_date'=>$now,'post_date_gmt'=>$gmt,'post_content'=>$url,'post_title'=>'Private cleanup reference','post_excerpt'=>'','post_status'=>'draft','comment_status'=>'closed','ping_status'=>'closed','post_password'=>'','post_name'=>'private-cleanup-reference-'.$attachment_id,'to_ping'=>'','pinged'=>'','post_modified'=>$now,'post_modified_gmt'=>$gmt,'post_content_filtered'=>'','post_parent'=>0,'guid'=>'https://fixture.example.invalid/?p='.$attachment_id,'menu_order'=>0,'post_type'=>'post','post_mime_type'=>'','comment_count'=>0]);
        }else{
            $json=wp_json_encode(['fixture_attachment_id'=>$attachment_id]);
            $values=match($property){
                'postmeta'=>['post_id'=>99999991,'meta_key'=>'_roxy_cleanup_fixture_'.$attachment_id,'meta_value'=>$json],
                'usermeta'=>['user_id'=>99999992,'meta_key'=>'_roxy_cleanup_fixture_'.$attachment_id,'meta_value'=>$json],
                'termmeta'=>['term_id'=>99999993,'meta_key'=>'_roxy_cleanup_fixture_'.$attachment_id,'meta_value'=>$json],
                'commentmeta'=>['comment_id'=>99999994,'meta_key'=>'_roxy_cleanup_fixture_'.$attachment_id,'meta_value'=>$json],
                'options'=>['option_name'=>'_roxy_cleanup_fixture_'.$attachment_id,'option_value'=>$json,'autoload'=>'no'],
            };
            $inserted=$wpdb->insert($wpdb->{$property},$values);
        }
        if($inserted!==1)throw new RuntimeException('Private '.$property.' reference fixture insert failed.');
        $result=$store::cleanup_expired();$kept=$store::find(1);
        $assert($result===0&&$GLOBALS['cleanup53_delete_calls']===0&&(int)($kept['temporary_attachment_id']??0)===$attachment_id,$label.' in real WordPress-schema table retains the attachment');
    }
    $clear_core_refs();
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
    $assert(hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$production.' ORDER BY id',ARRAY_A)))===$before,'all production Social rows unchanged');
    $row=$reset();$GLOBALS['cleanup53_exists']=true;$GLOBALS['cleanup53_scheduled']=[];
    for($id=2;$id<=101;$id++){$later=$row;$later['id']=$id;$later['post_key']='PRIVATE-cleanup-'.$id;$later['temporary_attachment_id']=$virtual_base+$id;$later['media_url']='https://fixture.example.invalid/cleanup53-'.$later['temporary_attachment_id'].'.mp4';if($wpdb->insert($table,$later)!==1)throw new RuntimeException('Private pagination row failed.');}
    $pagination_result=$store::cleanup_expired();$pagination_schedule=$GLOBALS['cleanup53_scheduled'][0]??null;
    $assert($pagination_result===1&&($pagination_schedule[1]??'')==='roxy_social_cleanup_page'&&($pagination_schedule[2]??[])===[100,101],'actual private-table cleanup schedules exactly the remaining bounded high-water page; '.json_encode(['result'=>$pagination_result,'scheduled'=>$GLOBALS['cleanup53_scheduled']]));
    $assert(hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$production.' ORDER BY id',ARRAY_A)))===$before,'pagination leaves production Social rows unchanged');
} finally {
    foreach($core_original as $property=>$source)$wpdb->{$property}=$source;
    foreach(array_reverse($core_private) as $private)if($wpdb->query('DROP TEMPORARY TABLE '.$private)===false)throw new RuntimeException('Private WordPress reference table removal failed.');
    if($created&&($wpdb->query('DROP TEMPORARY TABLE '.$queue_table)===false||$wpdb->query('DROP TEMPORARY TABLE '.$table)===false))throw new RuntimeException('Private cleanup schema removal failed.');
    remove_filter('query',$block,PHP_INT_MAX);remove_filter('pre_wp_mail',$mail,PHP_INT_MAX);remove_filter('pre_http_request',$http,PHP_INT_MAX);$wpdb->suppress_errors($old);
}
echo 'Passed '.$checks.' actual cleanup-SQL assertions with virtual media; private schema removed.'.PHP_EOL;
