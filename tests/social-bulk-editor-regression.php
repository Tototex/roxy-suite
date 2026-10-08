<?php
namespace RoxySocial {
    class Store {
        public static $saved;
        public static function all_recent() { return array_map(static function($id) { return ['id'=>$id,'status'=>'approved','scheduled_for'=>'2026-10-09 10:00:00','campaign_key'=>'movie-20261009','post_text'=>'Original '.$id,'media_url'=>'','media_type'=>'image','trailer_url'=>'','facebook_post_id'=>null,'instagram_media_id'=>null,'instagram_container_id'=>null,'last_error'=>null]; }, [1,2,3]); }
        public static function draft_revision($r) { return 'revision-'.$r['id']; }
        public static function find($id) { return ['id'=>$id]; }
        public static function update_draft(...$args) { self::$saved=$args; return $args[5] === 'revision-'.$args[0]; }
    }
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
    function wp_get_referer() { return $GLOBALS['referer'] ?? ''; }
    function wp_parse_url($v,$part) { return parse_url($v,$part); }
    function add_query_arg($a,$u) { return $u.'?'.http_build_query($a); }
    function wp_doing_ajax() { return true; }
    function check_admin_referer($v) { if ($v !== 'roxy_social_update_draft_1') throw new \RuntimeException('Wrong nonce scope'); }
    function wp_send_json_success($v) { throw new \RuntimeException(json_encode(['success'=>true,'data'=>$v])); }
    function wp_send_json_error($v,$code) { throw new \RuntimeException(json_encode(['success'=>false,'data'=>$v])); }
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
    $_POST['draft_revision']='stale';
    try { \RoxySocial\Admin::update_draft(); } catch (\RuntimeException $e) { $result=json_decode($e->getMessage(),true); }
    $check($result['success']===false);
    echo $checks," bulk editor server checks passed\n";
}
