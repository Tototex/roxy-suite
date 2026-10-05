<?php
// One controlled future, unapproved text-only draft for browser save/stale-form checks.
// wp eval-file ... create PRIVATE_BACKUP_DIR | cleanup PRIVATE_BACKUP_DIR ID
$mode=$args[0]??'';$backup=$args[1]??'';$marker='ROXY STABILITY PRIVATE TEST 20261004 checkpoint15';
if(!str_starts_with($backup,'/home1/anrvxfmy/deploy-backups/')||!is_dir($backup))throw new RuntimeException('Private backup directory required');
if($mode==='create') {
    $id=\RoxySocial\Store::create_manual(['platform'=>'facebook','scheduled_for'=>'2099-12-31 10:00:00','post_text'=>$marker.' — never approve or publish. Original caption.','media_type'=>'image','media_url'=>'']);
    $row=\RoxySocial\Store::find($id);
    if(!$id||!$row||$row['status']!=='draft')throw new RuntimeException('Private fixture draft creation failed');
    $path=$backup.'/private-ui-draft-'.$id.'.json';
    if(file_put_contents($path,wp_json_encode($row))===false)throw new RuntimeException('Fixture backup failed');
    chmod($path,0600);
    echo 'SOCIAL_UI_FIXTURE_ID='.$id.PHP_EOL;
} elseif($mode==='cleanup') {
    $id=(int)($args[2]??0);$row=\RoxySocial\Store::find($id);
    if(!$row||$row['campaign_key']!=='manual'||$row['status']!=='draft'||$row['scheduled_for']!=='2099-12-31 10:00:00'||!str_contains($row['post_text'],$marker)||!empty($row['facebook_post_id'])||!empty($row['instagram_media_id'])||!empty($row['instagram_container_id']))throw new RuntimeException('Refusing cleanup of a changed/nonfixture draft');
    $path=$backup.'/private-ui-draft-'.$id.'.json';
    if(file_put_contents($path,wp_json_encode($row))===false)throw new RuntimeException('Final fixture backup failed');chmod($path,0600);
    if(\RoxySocial\Store::delete_unposted($id)===null||\RoxySocial\Store::find($id)!==null)throw new RuntimeException('Fixture cleanup failed');
    echo 'SOCIAL_UI_FIXTURE_REMOVED='.$id.' private_backup_retained=yes'.PHP_EOL;
} else throw new RuntimeException('Unknown fixture mode');
