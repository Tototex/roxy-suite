<?php
// Actual review route/rendering, isolated permission/nonce/Store/redirect fixtures.
namespace RoxySocial {
    final class Store {
        public static $status='needs_review';
        public static $writes=0;
        public static function find($id){return $id===1 ? ['status'=>self::$status] : null;}
        public static function update_status($id,$status,$revision){if($id!==1||$revision!==str_repeat('a',64))return false;self::$writes++;self::$status=$status;return true;}
    }
}
namespace {
    define('ABSPATH',__DIR__);
    final class RedirectResult extends \RuntimeException {}
    function roxy_suite_user_can_access_admin(){return true;}
    function check_admin_referer($action){$GLOBALS['nonces'][]=$action;}
    function sanitize_key($s){return $s;}
    function admin_url($p){return 'https://fixture.test/wp-admin/'.$p;}
    function wp_safe_redirect($url){throw new RedirectResult($url);}
    function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES);}
    function esc_url($s){return esc_attr($s);}
    function esc_html($s){return esc_attr($s);}
    function wp_nonce_field(...$args){return '<input type="hidden" name="_wpnonce" value="fixture-nonce">';}
    function check($ok,$label){if(!$ok)throw new \RuntimeException($label);echo "PASS: $label\n";}
    require ($argv[1]??dirname(__DIR__)).'/includes/modules/social-publisher/includes/class-roxy-social-admin.php';
    foreach([['GET',null,false],['POST',null,false],['POST','0',false],['POST','1',true]] as [$method,$confirmation,$expected]){
        \RoxySocial\Store::$status='needs_review';\RoxySocial\Store::$writes=0;$GLOBALS['nonces']=[];
        $_SERVER['REQUEST_METHOD']=$method;$_GET=[];$_POST=[];$request=['id'=>1,'status'=>'approved','draft_revision'=>str_repeat('a',64)];
        if($confirmation!==null)$request['remote_review_confirmed']=$confirmation;
        if($method==='POST')$_POST=$request;else $_GET=$request;
        try{\RoxySocial\Admin::handle_status();throw new \LogicException('Expected redirect');}
        catch(RedirectResult $e){check(\RoxySocial\Store::$writes===($expected?1:0)&&str_contains($e->getMessage(),'status_changed='.($expected?'1':'0')),'GET/unconfirmed POST never approves; confirmed POST reports actual result');}
        check($GLOBALS['nonces']===['roxy_social_status_1'],'permission route still checks scoped nonce before mutation');
    }
    \RoxySocial\Store::$status='needs_review';\RoxySocial\Store::$writes=0;$_POST['draft_revision']='stale';
    try{\RoxySocial\Admin::handle_status();}catch(RedirectResult $e){check(\RoxySocial\Store::$writes===0&&str_contains($e->getMessage(),'status_changed=0'),'confirmed but stale form still cannot approve a changed draft');}
    $render=new \ReflectionMethod(\RoxySocial\Admin::class,'action_link');$render->setAccessible(true);
    ob_start();$render->invoke(null,1,'approved','Approve after remote review',true,str_repeat('a',64));$html=ob_get_clean();
    check(str_contains($html,'method="post"')&&str_contains($html,'name="remote_review_confirmed" value="1"')&&!str_contains($html,'<a '),'Needs Review approval is an explicit POST form, not a prefetchable GET link');
}
