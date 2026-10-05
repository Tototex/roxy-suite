<?php
// wp eval-file tests/social-publish-store-mysql.php PRIVATE_STAGE_ROOT
// Actual Store write/read-back methods; connection-local temporary table only.
$root=$args[0]??dirname(__DIR__);
global $wpdb;
$source=file_get_contents($root.'/includes/modules/social-publisher/includes/class-roxy-social-store.php');
$source=str_replace('final class Store {','final class PublishFixtureStore {',$source);
eval(substr($source,5));
$prefix_before=$wpdb->prefix;
$fixture_prefix='roxy_pub_fixture_'.bin2hex(random_bytes(5)).'_';
$table=$fixture_prefix.\RoxySocial\PublishFixtureStore::TABLE;
$suppressed=$wpdb->suppress_errors(true);
function roxy_publish_fixture_check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
try {
    if(false===$wpdb->query("CREATE TEMPORARY TABLE `$table` (id BIGINT PRIMARY KEY, status VARCHAR(24), last_error TEXT NULL, updated_at DATETIME, facebook_post_id VARCHAR(190) NULL, instagram_media_id VARCHAR(190) NULL, instagram_container_id VARCHAR(190) NULL)"))throw new RuntimeException('Temporary fixture table creation failed');
    $wpdb->prefix=$fixture_prefix;
    $wpdb->insert($table,['id'=>1,'status'=>'approved','updated_at'=>current_time('mysql')]);
    roxy_publish_fixture_check(\RoxySocial\PublishFixtureStore::update_publish_result(1,'publishing','','123'),'real SQL persists platform success');
    roxy_publish_fixture_check(\RoxySocial\PublishFixtureStore::update_publish_result(1,'publishing','','123'),'unchanged same-second update correctly verifies existing row');
    roxy_publish_fixture_check(!\RoxySocial\PublishFixtureStore::update_publish_result(999,'posted','','123'),'missing row cannot report durable success');
    roxy_publish_fixture_check(\RoxySocial\PublishFixtureStore::set_instagram_container_id(1,'234')&&\RoxySocial\PublishFixtureStore::find(1)['instagram_container_id']==='234','real SQL saves resumable container');
    roxy_publish_fixture_check(\RoxySocial\PublishFixtureStore::clear_publish_id(1,'facebook')&&\RoxySocial\PublishFixtureStore::find(1)['facebook_post_id']===null,'confirmed removal clears only matching ID');
    roxy_publish_fixture_check(!\RoxySocial\PublishFixtureStore::clear_publish_id(999,'facebook')&&!\RoxySocial\PublishFixtureStore::clear_publish_id(1,'invalid'),'missing row and invalid platform fail closed');
    roxy_publish_fixture_check(\RoxySocial\PublishFixtureStore::clear_instagram_container_id(1)&&\RoxySocial\PublishFixtureStore::find(1)['instagram_container_id']===null,'completed publication clears container');
    $wpdb->query("DROP TEMPORARY TABLE `$table`");
    roxy_publish_fixture_check(!\RoxySocial\PublishFixtureStore::update_publish_result(1,'posted'),'actual SQL failure cannot report posted');
} finally {
    $wpdb->prefix=$prefix_before;
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    $wpdb->suppress_errors($suppressed);
}
