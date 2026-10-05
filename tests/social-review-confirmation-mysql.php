<?php
// Real WordPress permission/nonce/redirect and Store SQL; temporary rows only.
global $wpdb;
$root=$args[0]??dirname(__DIR__);
$source=file_get_contents($root.'/includes/modules/social-publisher/includes/class-roxy-social-store.php');$source=str_replace('final class Store {','final class ReviewFixtureStore {',$source);eval(substr($source,5));
$admin=file_get_contents($root.'/includes/modules/social-publisher/includes/class-roxy-social-admin.php');$admin=str_replace(['final class Admin {','Store::'],['final class ReviewFixtureAdmin {','ReviewFixtureStore::'],$admin);eval(substr($admin,5));
final class RoxyReviewRedirect extends RuntimeException {}
$redirect=static function($location){throw new RoxyReviewRedirect($location);};
function roxy_review_check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);if(!$admins)throw new RuntimeException('Administrator fixture context required');
wp_set_current_user((int)$admins[0]);if(!roxy_suite_user_can_access_admin())throw new RuntimeException('Fixture administrator lacks Suite permission');
$prefix=$wpdb->prefix;$fixture_prefix='roxy_review_fixture_'.bin2hex(random_bytes(5)).'_';$table=$fixture_prefix.\RoxySocial\ReviewFixtureStore::TABLE;$real=\RoxySocial\Store::table_name();
$before=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `$real` ORDER BY id",ARRAY_A)));
try {
    if(false===$wpdb->query("CREATE TEMPORARY TABLE `$table` LIKE `$real`"))throw new RuntimeException('Temporary fixture schema creation failed');
    $wpdb->prefix=$fixture_prefix;add_filter('wp_redirect',$redirect,1);
    $wpdb->insert($table,['id'=>1,'campaign_key'=>'fixture','post_key'=>'fixture','showing_ids'=>'','platform'=>'facebook','scheduled_for'=>'2099-12-31 10:00:00','status'=>'needs_review','post_text'=>'Private review fixture','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
    foreach([['GET',false,false],['POST',false,false],['POST',true,true]] as [$method,$confirmed,$expected]){
        $row=\RoxySocial\ReviewFixtureStore::find(1);$request=['id'=>1,'status'=>'approved','draft_revision'=>\RoxySocial\ReviewFixtureStore::draft_revision($row),'_wpnonce'=>wp_create_nonce('roxy_social_status_1')];
        if($confirmed)$request['remote_review_confirmed']='1';$_SERVER['REQUEST_METHOD']=$method;$_GET=$method==='GET'?$request:[];$_POST=$method==='POST'?$request:[];$_REQUEST=$request;
        try{\RoxySocial\ReviewFixtureAdmin::handle_status();throw new LogicException('Expected redirect');}
        catch(RoxyReviewRedirect $e){roxy_review_check(\RoxySocial\ReviewFixtureStore::find(1)['status']===($expected?'approved':'needs_review')&&str_contains($e->getMessage(),'status_changed='.($expected?'1':'0')),'actual WP nonce/route/SQL gate: '.$method.' confirmed='.($confirmed?'yes':'no'));}
    }
} finally {
    remove_filter('wp_redirect',$redirect,1);$wpdb->prefix=$prefix;$wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
}
$after=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `$real` ORDER BY id",ARRAY_A)));
roxy_review_check(hash_equals($before,$after),'production Social rows unchanged by review route fixture');
