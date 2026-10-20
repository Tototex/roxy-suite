<?php
namespace RoxySocial {
    class Store {
        public static $saved;
        public static function all_recent() { return array_map(static function($id) { $no_ids=!empty($GLOBALS['social_removal_review_no_ids']);return ['id'=>$id,'status'=>$id===4?(!empty($GLOBALS['social_removal_review'])?'needs_review':'failed'):'approved','scheduled_for'=>'2026-10-09 10:00:00','campaign_key'=>'movie-20261009','post_text'=>'Original '.$id,'media_url'=>$id===4?'https://example.test/video.mp4':'','media_type'=>$id===4?'video':'image','platform'=>'both','publish_facebook_story'=>$id===4?1:0,'facebook_story_state'=>$id===4&&!$no_ids?json_encode(['expected'=>1,'ids'=>[],'pending_id'=>$GLOBALS['story_pending_id']??'']):null,'trailer_url'=>'','facebook_post_id'=>$id===4&&!$no_ids?'123':null,'instagram_media_id'=>$id===4&&!$no_ids?'456':null,'instagram_container_id'=>null,'last_error'=>$id===4?(!empty($GLOBALS['social_removal_review'])?'REMOVAL_OUTCOME_UNCERTAIN: verify remote posts':'Facebook Story: temporary failure'):null]; }, [1,2,3,4]); }
        public static function draft_revision($r) { return 'revision-'.$r['id']; }
        public static function find($id) { return ['id'=>$id]; }
        public static function update_draft(...$args) { self::$saved=$args; return $args[5] === 'revision-'.$args[0]; }
    }
    class Campaigns { public static function caption_schedule_warning($row) { return ''; } }
}
namespace {
    define('ABSPATH', __DIR__);
    function roxy_suite_user_can_access_admin() { return true; }
    function sanitize_key($v) { return preg_replace('/[^a-z_]/','',strtolower($v)); }
    function sanitize_textarea_field($v) { return $v; }
    function sanitize_text_field($v) { return $v; }
    function wp_unslash($v) { return stripslashes($v); }
    function admin_url($v) { return 'https://example.test/wp-admin/'.$v; }
    function content_url($v) { return 'https://example.test/wp-content/'.$v; }
    function get_option($k,$d=false) { return $GLOBALS['social_fixture_options'][$k] ?? $d; }
    function esc_url($v) { return htmlspecialchars($v,ENT_QUOTES); }
    function esc_attr($v) { return htmlspecialchars($v,ENT_QUOTES); }
    function esc_html($v) { return htmlspecialchars($v,ENT_QUOTES); }
    function esc_textarea($v) { return htmlspecialchars($v,ENT_QUOTES); }
    function checked($a,$b,$c) { return ''; }
    function wp_nonce_field($a,$b,$c,$d) { return '<input name="_wpnonce" value="test-nonce" type="hidden">'; }
    function wp_create_nonce($v) { return 'test-nonce'; }
    function wp_nonce_url($v,$a) { return $v.'&_wpnonce=test-nonce'; }
    function wp_json_encode($v,...$a) { return json_encode($v,...$a); }
    function wp_timezone() { return new \DateTimeZone('America/Los_Angeles'); }
    function wp_date($format,$stamp,$zone) { return (new \DateTimeImmutable('@'.$stamp))->setTimezone($zone)->format($format); }
    function current_time($v) { return time(); }
    function attachment_url_to_postid($v) { return 0; }
    function wp_get_referer() { return $GLOBALS['referer'] ?? ''; }
    function wp_parse_url($v,$part) { return parse_url($v,$part); }
    function add_query_arg($a,$u) { return $u.'?'.http_build_query($a); }
    function wp_doing_ajax() { return true; }
    function check_admin_referer($v) { if ($v !== 'roxy_social_update_draft_1') throw new \RuntimeException('Wrong nonce scope'); }
    function wp_send_json_success($v) { throw new \RuntimeException(json_encode(['success'=>true,'data'=>$v])); }
    function wp_send_json_error($v,$code) { throw new \RuntimeException(json_encode(['success'=>false,'data'=>$v])); }
    require __DIR__.'/../includes/modules/social-publisher/includes/class-roxy-social-publisher.php';
    require __DIR__.'/../includes/modules/social-publisher/includes/class-roxy-social-admin.php';
    $_GET=['status'=>'approved']; $_REQUEST=[];
    ob_start(); \RoxySocial\Admin::render_page(); $html=ob_get_clean();
    if (($argv[1] ?? '') === '--render') { echo '<script>window.ajaxurl="https://example.test/wp-admin/admin-ajax.php";</script>'.$html; exit; }
    $checks=0;
    $check=static function($ok) use (&$checks) { if (!$ok) throw new \RuntimeException('Check failed '.($checks+1)); $checks++; };
    $method=new \ReflectionMethod(\RoxySocial\Admin::class,'drafts_url');
    $GLOBALS['referer']='https://example.test/wp-admin/admin.php?page=roxy-social-posts&status=approved';
    $check(str_contains($method->invoke(null,['updated'=>'1']),'status=approved'));
    $_REQUEST=['return_status'=>'posted','status'=>'draft'];
    $check(str_contains($method->invoke(null),'status=posted'));
    $_REQUEST=['return_status'=>'https://evil.test'];
    $check(str_contains($method->invoke(null),'status=draft'));
    $check(substr_count($html,'class="button button-primary roxy-social-save-all"')===2);
    $check(strpos($html,'>All</a>')>strpos($html,'>Failed</a>'));
    $check(substr_count($html,'type="submit" hidden')===3);
    $check(str_contains($html,'name="return_status" value="approved"'));
    $_POST=['id'=>1,'post_text'=>"We\\'re ready",'scheduled_for'=>'2026-10-09T10:00','draft_revision'=>'revision-1','media_changed'=>'0'];
    try { \RoxySocial\Admin::update_draft(); } catch (\RuntimeException $e) { $result=json_decode($e->getMessage(),true); }
    $check($result['success']===true);
    $check(\RoxySocial\Store::$saved[1]==="We're ready");
    $check(\RoxySocial\Store::$saved[3]===null);
    $check(\RoxySocial\Store::$saved[7]===false);
    $_POST['publish_facebook_story']='1';
    try { \RoxySocial\Admin::update_draft(); } catch (\RuntimeException $e) { $result=json_decode($e->getMessage(),true); }
    $check($result['success']===true&&\RoxySocial\Store::$saved[7]===true);
    unset($_POST['publish_facebook_story']);
    $_POST['draft_revision']='stale';
    try { \RoxySocial\Admin::update_draft(); } catch (\RuntimeException $e) { $result=json_decode($e->getMessage(),true); }
    $check($result['success']===false);
    $_GET=['status'=>'failed'];
    ob_start(); \RoxySocial\Admin::render_page(); $failed_html=ob_get_clean();
    $check(str_contains($failed_html,'Retry Facebook Story')
        &&str_contains($failed_html,'roxy_social_retry_facebook_story')
        &&str_contains($failed_html,'The normal Facebook and Instagram posts will not be repeated.')
        &&!str_contains($failed_html,'>Retry publish</a>'));
    $GLOBALS['story_pending_id']='ambiguous-story-id';
    ob_start(); \RoxySocial\Admin::render_page(); $pending_failed_html=ob_get_clean();
    $check(!str_contains($pending_failed_html,'Retry Facebook Story')
        &&!str_contains($pending_failed_html,'>Retry publish</a>'));
    $GLOBALS['social_removal_review']=true;$_GET=['status'=>'needs_review'];
    ob_start(); \RoxySocial\Admin::render_page(); $removal_review_html=ob_get_clean();
    $check(str_contains($removal_review_html,'Confirm all remaining posts are gone')
        &&str_contains($removal_review_html,'name="_wpnonce"')
        &&!str_contains($removal_review_html,'>Retry remove</a>')
        &&!str_contains($removal_review_html,'Approve after remote review'));
    $GLOBALS['social_removal_review_no_ids']=true;$GLOBALS['story_pending_id']='';
    ob_start(); \RoxySocial\Admin::render_page(); $empty_removal_review_html=ob_get_clean();
    $check(str_contains($empty_removal_review_html,'Confirm all remaining posts are gone')
        &&!str_contains($empty_removal_review_html,'>Retry remove</a>'));
    echo $checks," bulk editor server checks passed\n";
}
