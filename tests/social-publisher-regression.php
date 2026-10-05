<?php
// Isolated real Publisher methods. No WordPress bootstrap, provider calls or sleeps.
namespace RoxySocial {
    final class Meta {
        public static $facebook = true;
        public static $instagram = true;
        public static function page_id(){return self::$facebook ? '11' : '';}
        public static function page_access_token(){return self::$facebook ? 'fixture-page' : '';}
        public static function instagram_user_id(){return self::$instagram ? '22' : '';}
        public static function access_token(){return self::$instagram ? 'fixture-user' : '';}
    }
    final class Store {
        public static $row;
        public static $fail_id = false;
        public static function find($id){return self::$row;}
        public static function update_publish_result($id,$status,$error='',$fb='',$ig=''){
            if (self::$fail_id && ($fb!=='' || $ig!=='')) return false;
            self::$row['status']=$status;self::$row['last_error']=$error;
            if($fb!=='')self::$row['facebook_post_id']=$fb;
            if($ig!=='')self::$row['instagram_media_id']=$ig;
            return true;
        }
        public static function clear_publish_id($id,$platform){self::$row[$platform==='facebook'?'facebook_post_id':'instagram_media_id']=null;return true;}
        public static function set_instagram_container_id($id,$value){self::$row['instagram_container_id']=$value;return true;}
        public static function clear_instagram_container_id($id){self::$row['instagram_container_id']=null;return true;}
    }
}
namespace {
    define('ABSPATH',__DIR__);
    function esc_url_raw($s){return $s;}
    function sanitize_text_field($s){return $s;}
    function wp_schedule_single_event(...$args){return true;}
    function is_wp_error($r){return $r instanceof \RuntimeException;}
    function wp_remote_retrieve_response_code($r){return $r['status'];}
    function wp_remote_retrieve_body($r){return $r['body'];}
    function http_fixture($url,$args){
        $GLOBALS['calls'][]=[$url,$args,\RoxySocial\Store::$row];
        if(!$GLOBALS['responses'])throw new \LogicException('Unexpected HTTP fixture call');
        return array_shift($GLOBALS['responses']);
    }
    function wp_remote_post($url,$args){return http_fixture($url,$args);}
    function wp_remote_request($url,$args){return http_fixture($url,$args);}
    function response($data,$status=200){return ['status'=>$status,'body'=>json_encode($data)];}
    function reset_fixture($platform='both',$status='approved'){
        \RoxySocial\Store::$row=['id'=>1,'status'=>$status,'platform'=>$platform,'post_text'=>'Fixture caption','media_type'=>'image','media_url'=>'https://fixture.test/poster.jpg','facebook_post_id'=>null,'instagram_media_id'=>null,'instagram_container_id'=>null];
        \RoxySocial\Store::$fail_id=false;\RoxySocial\Meta::$facebook=true;\RoxySocial\Meta::$instagram=true;
        $GLOBALS['calls']=[];$GLOBALS['responses']=[];
    }
    function check($ok,$label){if(!$ok)throw new \RuntimeException($label);echo "PASS: $label\n";}
    require ($argv[1]??dirname(__DIR__)).'/includes/modules/social-publisher/includes/class-roxy-social-publisher.php';
    $decode=new \ReflectionMethod(\RoxySocial\Publisher::class,'decode_response');$decode->setAccessible(true);
    foreach([response(['id'=>'123'],500),response([]),['status'=>200,'body'=>'not JSON'],response(['id'=>['123']]),response(['id'=>'']),new \RuntimeException('timeout')] as $r){
        $v=$decode->invoke(null,$r,true,false);check(!empty($v['error'])&&!empty($v['ambiguous']),'unknown or invalid publication never becomes success');
    }
    foreach([response(['success'=>false]),response([]),response(['success'=>true],429)] as $r){check(!empty($decode->invoke(null,$r,false,true)['error']),'unconfirmed deletion retains ID');}
    check(($decode->invoke(null,response(['id'=>'11_123']),true,false)['id']??'')==='11_123','valid provider ID accepted');
    reset_fixture();$GLOBALS['responses']=[response(['id'=>'123']),response(['id'=>'234']),response(['id'=>'345'])];
    check(\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='posted','both confirmed platforms mark posted');
    check($GLOBALS['calls'][1][2]['facebook_post_id']==='123','Facebook ID durable before Instagram call');
    check($GLOBALS['calls'][2][2]['instagram_container_id']==='234'&&\RoxySocial\Store::$row['instagram_container_id']===null,'container retained until publication ID saved');
    reset_fixture();$GLOBALS['responses']=[response([])];
    check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='needs_review'&&count($GLOBALS['calls'])===1,'ambiguous Facebook outcome halts other platform and retry');
    reset_fixture();\RoxySocial\Store::$fail_id=true;$GLOBALS['responses']=[response(['id'=>'123'])];
    check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='needs_review'&&count($GLOBALS['calls'])===1,'failed ID persistence stops next platform');
    reset_fixture('facebook');\RoxySocial\Meta::$instagram=false;$GLOBALS['responses']=[response(['id'=>'123'])];
    check(\RoxySocial\Publisher::publish_now(1)&&count($GLOBALS['calls'])===1,'Facebook-only publishing needs no Instagram connection');
    reset_fixture('instagram');\RoxySocial\Meta::$facebook=false;$GLOBALS['responses']=[response(['id'=>'234']),response(['id'=>'345'])];
    check(\RoxySocial\Publisher::publish_now(1)&&count($GLOBALS['calls'])===2,'Instagram-only publishing needs no Facebook page');
    reset_fixture();\RoxySocial\Meta::$instagram=false;
    check(!\RoxySocial\Publisher::publish_now(1)&&!$GLOBALS['calls'],'missing required platform fails before provider calls');
    reset_fixture('both','failed');\RoxySocial\Store::$row['facebook_post_id']='123';$GLOBALS['responses']=[response(['id'=>'234']),response(['id'=>'345'])];
    check(\RoxySocial\Publisher::publish_now(1)&&!str_contains($GLOBALS['calls'][0][0],'/photos'),'partial publish retry skips known Facebook success');
    reset_fixture('both','failed');\RoxySocial\Store::$row['facebook_post_id']='123';\RoxySocial\Store::$row['instagram_media_id']='345';$GLOBALS['responses']=[response(['success'=>true]),response(['success'=>false])];
    check(!\RoxySocial\Publisher::remove_published(1)&&\RoxySocial\Store::$row['facebook_post_id']===null&&\RoxySocial\Store::$row['instagram_media_id']==='345','partial removal clears only confirmed ID and preserves failed status');
    $GLOBALS['calls']=[];$GLOBALS['responses']=[response(['success'=>true])];
    check(\RoxySocial\Publisher::remove_published(1)&&count($GLOBALS['calls'])===1&&str_ends_with($GLOBALS['calls'][0][0],'/345'),'retry removes only remaining platform');
    reset_fixture('both','posted');
    check(!\RoxySocial\Publisher::remove_published(1)&&!$GLOBALS['calls']&&\RoxySocial\Store::$row['status']==='posted','missing recorded IDs cannot report removed');
    reset_fixture('facebook','posted');\RoxySocial\Store::$row['facebook_post_id']='123';$GLOBALS['responses']=[response(['success'=>false])];
    check(!\RoxySocial\Publisher::remove_published(1)&&\RoxySocial\Store::$row['facebook_post_id']==='123','false success cannot erase published ID');
}
