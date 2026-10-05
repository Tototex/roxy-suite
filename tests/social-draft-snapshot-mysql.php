<?php
// Actual Store/Campaigns methods, temporary SQL rows only; no AI/media provider calls.
global $wpdb;
$root=$args[0]??dirname(__DIR__);
$source=file_get_contents($root.'/includes/modules/social-publisher/includes/class-roxy-social-store.php');
$source=str_replace(['final class Store {','wp_get_attachment_url('],['final class DraftFixtureStore {','\\roxy_snapshot_attachment_url('],$source);
eval(substr($source,5));
$campaign=file_get_contents($root.'/includes/modules/social-publisher/includes/class-roxy-social-campaigns.php');
$campaign=str_replace(['final class Campaigns {','Store::',"get_option('roxy_social_auto_approve', false)",'AI::enabled()','self::verified_caption_schedule($draft)'],['final class SnapshotFixtureCampaigns {','DraftFixtureStore::','\\roxy_snapshot_auto_approve()','\\roxy_snapshot_ai_enabled()','\\roxy_snapshot_schedule_verified()'],$campaign);
eval(substr($campaign,5));
function roxy_snapshot_attachment_url($id){return 'https://fixture.test/'.$id.'.jpg';}
function roxy_snapshot_auto_approve(){return true;}
function roxy_snapshot_ai_enabled(){return $GLOBALS['snapshot_ai_enabled']??true;}
function roxy_snapshot_schedule_verified(){return $GLOBALS['snapshot_schedule_verified']??true;}
function roxy_snapshot_check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function roxy_snapshot_row($overrides=[]){
    global $wpdb;
    $table=\RoxySocial\DraftFixtureStore::table_name();
    $wpdb->query("DELETE FROM `$table`");
    $data=array_merge(['id'=>1,'campaign_key'=>'fixture-20261009','post_key'=>'fixture-post','showing_ids'=>'','platform'=>'both','scheduled_for'=>'2026-10-05 10:00:00','status'=>'draft','ai_status'=>'pending','post_text'=>'Original fixture caption','media_type'=>'image','media_url'=>'https://fixture.test/original.jpg','hangar_asset_id'=>111,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],$overrides);
    if(!$wpdb->insert($table,$data))throw new RuntimeException('Fixture insert failed');
    return \RoxySocial\DraftFixtureStore::find(1);
}
$prefix=$wpdb->prefix;
$fixture_prefix='roxy_draft_fixture_'.bin2hex(random_bytes(5)).'_';
$table=$fixture_prefix.\RoxySocial\DraftFixtureStore::TABLE;
$real_table=\RoxySocial\Store::table_name();
$production_before=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `$real_table` ORDER BY id",ARRAY_A)));
$suppressed=$wpdb->suppress_errors(true);
try {
    if(false===$wpdb->query("CREATE TEMPORARY TABLE `$table` LIKE `$real_table`"))throw new RuntimeException('Temporary schema clone failed');
    $wpdb->prefix=$fixture_prefix;
    $row=roxy_snapshot_row();
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::save_ai_result($row,'Generated fixture caption')&&\RoxySocial\DraftFixtureStore::find(1)['ai_status']==='ready','unchanged pending draft accepts AI text and readiness together');
    $row=roxy_snapshot_row();$wpdb->update($table,['post_text'=>'Manual edit'],['id'=>1]);
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::save_ai_result($row,'Stale generated caption')&&\RoxySocial\DraftFixtureStore::find(1)['post_text']==='Manual edit','same-second manual edit rejects stale AI result');
    $row=roxy_snapshot_row();$wpdb->update($table,['status'=>'approved'],['id'=>1]);
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::save_ai_result($row,'Stale generated caption'),'manager approval during AI work freezes approved text');
    $row=roxy_snapshot_row();$wpdb->update($table,['media_url'=>'https://fixture.test/new.jpg'],['id'=>1]);
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::save_ai_result($row,'Stale generated caption'),'media change also invalidates AI snapshot');
    $row=roxy_snapshot_row();
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::save_ai_result($row,'','AI failed')&&\RoxySocial\DraftFixtureStore::find(1)['status']==='needs_review','failed AI enters review, not ready auto-approval');
    \RoxySocial\SnapshotFixtureCampaigns::maybe_auto_approve(1);
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::find(1)['status']==='needs_review','auto-approval cannot rescue a Needs Review job');
    $row=roxy_snapshot_row(['ai_status'=>'ready']);\RoxySocial\SnapshotFixtureCampaigns::maybe_auto_approve(1);
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::find(1)['status']==='approved','ready unchanged new draft can still auto-approve');
    $row=roxy_snapshot_row();\RoxySocial\SnapshotFixtureCampaigns::maybe_auto_approve(1);
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::find(1)['status']==='draft','pending AI draft cannot auto-approve');
    roxy_snapshot_row(['ai_status'=>'ready','hangar_asset_id'=>null]);\RoxySocial\SnapshotFixtureCampaigns::maybe_auto_approve(1);
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::find(1)['status']==='draft','auto-approval waits for assigned Hangar media, not fallback poster');
    $GLOBALS['snapshot_ai_enabled']=false;roxy_snapshot_row(['ai_status'=>'manual']);\RoxySocial\SnapshotFixtureCampaigns::maybe_auto_approve(1);
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::find(1)['status']==='draft','manual edits require approval even with AI disabled');$GLOBALS['snapshot_ai_enabled']=true;
    foreach(['facebook_post_id','instagram_media_id','instagram_container_id','last_error'] as $field){roxy_snapshot_row(['ai_status'=>'ready',$field=>'123']);\RoxySocial\SnapshotFixtureCampaigns::maybe_auto_approve(1);roxy_snapshot_check(\RoxySocial\DraftFixtureStore::find(1)['status']==='draft','existing publication/review evidence blocks automatic approval: '.$field);}
    $row=roxy_snapshot_row();$wpdb->update($table,['post_text'=>'Newer caption'],['id'=>1]);
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::approve_snapshot($row),'stale automatic approval cannot approve unseen caption');
    $row=roxy_snapshot_row();$wpdb->update($table,['status'=>'approved'],['id'=>1]);
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::update_imported_media(1,999,'image',null,123,'fixture.jpg',$row),'approval while media downloads rejects late assignment');
    $row=roxy_snapshot_row();$wpdb->update($table,['media_url'=>'https://fixture.test/manual.jpg'],['id'=>1]);
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::update_imported_media(1,999,'image',null,123,'fixture.jpg',$row),'manual media replacement rejects stale download');
    $row=roxy_snapshot_row();
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::update_imported_media(1,999,'image',null,123,'fixture.jpg',$row)&&\RoxySocial\DraftFixtureStore::find(1)['media_url']==='https://fixture.test/999.jpg','unchanged draft still accepts imported media');
    $row=roxy_snapshot_row();\RoxySocial\DraftFixtureStore::save_ai_result($row,'Generated caption first');
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::update_imported_media(1,999,'image',null,123,'fixture.jpg',$row)&&\RoxySocial\DraftFixtureStore::find(1)['post_text']==='Generated caption first','AI-first completion safely rebases media without losing generated text');
    $row=roxy_snapshot_row();\RoxySocial\DraftFixtureStore::update_draft(1,'Manager caption','2026-10-05 10:00:00',null,null,\RoxySocial\DraftFixtureStore::draft_revision($row));
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::update_imported_media(1,999,'image',null,123,'fixture.jpg',$row),'media rebase never overrides a manual caption edit');
    $row=roxy_snapshot_row(['status'=>'approved','ai_status'=>'ready']);$revision=\RoxySocial\DraftFixtureStore::draft_revision($row);
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::update_draft(1,'Manager edit','2026-10-06 10:00:00',null,null,$revision)&&\RoxySocial\DraftFixtureStore::find(1)['status']==='draft','manual edit revokes prior approval');
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::update_draft(1,'Stale edit','2026-10-06 10:00:00',null,null,$revision),'second old form cannot overwrite newer save');
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::update_draft(1,'No revision','2026-10-06 10:00:00'),'missing form revision fails closed');
    $current=\RoxySocial\DraftFixtureStore::find(1);
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::save_ai_result($current,'Unexpected AI overwrite'),'queued AI cannot replace completed manual caption');
    \RoxySocial\DraftFixtureStore::upsert(['campaign_key'=>'fixture-20261009','post_key'=>'fixture-post','showing_ids'=>'','scheduled_for'=>'2026-10-07 10:00:00','post_text'=>'Regenerated source caption']);
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::find(1)['post_text']==='Manager edit','campaign regeneration preserves completed manual caption');
    $row=roxy_snapshot_row();$revision=\RoxySocial\DraftFixtureStore::draft_revision($row);
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::update_status(1,'approved',$revision),'manager can approve exactly the displayed snapshot');
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::update_status(1,'draft',$revision),'stale status link cannot change newer row');
    $row=roxy_snapshot_row(['status'=>'publishing']);$revision=\RoxySocial\DraftFixtureStore::draft_revision($row);
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::update_status(1,'approved',$revision)&&\RoxySocial\DraftFixtureStore::delete_unposted(1)===null&&\RoxySocial\DraftFixtureStore::clear_media(1)===null,'publishing draft cannot be approved, deleted or have media removed');
    $row=roxy_snapshot_row(['status'=>'posted','facebook_post_id'=>'123']);
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::update_draft(1,'Invalid edit','2026-10-06 10:00:00',null,null,\RoxySocial\DraftFixtureStore::draft_revision($row)),'posted payload stays frozen');
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::update_text(1,'Invalid text')&&!\RoxySocial\DraftFixtureStore::update_media(1,999,'invalid.jpg')&&!\RoxySocial\DraftFixtureStore::update_ai_status(1,'pending'),'legacy mutators cannot rewrite published payload');
    $GLOBALS['snapshot_schedule_verified']=false;$GLOBALS['snapshot_ai_enabled']=true;
    $row=roxy_snapshot_row(['ai_status'=>'ready']);\RoxySocial\SnapshotFixtureCampaigns::maybe_auto_approve(1);
    roxy_snapshot_check(\RoxySocial\DraftFixtureStore::find(1)['status']==='needs_review','actual Campaigns-to-Store rejection path persists review instead of calling a private helper');
    $GLOBALS['snapshot_schedule_verified']=true;
    $row=roxy_snapshot_row(['ai_status'=>'ready']);$wpdb->update($table,['post_text'=>'Changed caption'],['id'=>1]);
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::review_snapshot($row,'Stale schedule'),'stale schedule rejection cannot overwrite newer snapshot');
    $row=roxy_snapshot_row(['status'=>'approved','ai_status'=>'ready']);
    roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::review_snapshot($row,'Late error'),'background schedule rejection cannot demote an approved snapshot');
    $row=roxy_snapshot_row();$claim=\RoxySocial\DraftFixtureStore::acquire_publish_lock(1);$first=$wpdb;$second=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$second->prefix=$fixture_prefix;
    try {$wpdb=$second;roxy_snapshot_check(!\RoxySocial\DraftFixtureStore::save_ai_result($row,'Competing result'),'AI completion cannot claim another worker-owned draft');}
    finally {$wpdb=$first;if($claim)\RoxySocial\DraftFixtureStore::release_publish_lock($claim);$second->close();}
} finally {
    $wpdb->prefix=$prefix;
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    $wpdb->suppress_errors($suppressed);
}
$production_after=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `$real_table` ORDER BY id",ARRAY_A)));
roxy_snapshot_check(hash_equals($production_before,$production_after),'production Social rows unchanged by snapshot fixture');
