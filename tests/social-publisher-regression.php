<?php
// Isolated real Publisher methods. No WordPress bootstrap, provider calls or sleeps.
namespace RoxySocial {
    final class Campaigns {public static $verified=true;public static function verified_caption_schedule($row){return self::$verified;}}
    final class Meta {
        public static $facebook = true;
        public static $instagram = true;
        public static function page_id(){return self::$facebook ? '11' : '';}
        public static function page_access_token(){return self::$facebook ? 'fixture-page' : '';}
        public static function instagram_user_id(){return self::$instagram ? '22' : '';}
        public static function access_token(){return self::$instagram ? 'fixture-user' : '';}
    }
    final class FacebookStories {
        public static $result = [];
        public static $calls = 0;
        public static function publish($row, $state = '') { self::$calls++; return self::$result; }
    }
    final class Store {
        public static $row;
        public static $fail_id = false;
        public static $locked = false;
        public static $deny_lock = false;
        public static function claim_publish_attempt($id,$expected,$token,$worker,$claim){if(self::$row['status']!==$expected)return false;self::$row['status']='publishing';self::$row['publish_attempt_token']=$token;self::$row['publish_worker_token']=$worker;return true;}
        public static function claim_publish_execution($id,$token,$worker,$claim){if(self::$row['status']!=='publishing'||(self::$row['publish_attempt_token']??'')!==$token||!empty(self::$row['publish_worker_token']))return false;self::$row['publish_worker_token']=$worker;return true;}
        public static function restore_publish_attempt($id,$token,$status,$claim){if((self::$row['publish_attempt_token']??'')!==$token)return false;self::$row['status']=$status;self::$row['publish_attempt_token']=self::$row['publish_worker_token']=null;return true;}
        public static function acquire_publish_lock($id){if(self::$deny_lock||self::$locked)return null;self::$locked=true;return ['name'=>'fixture','connection'=>'1'];}
        public static function owns_publish_lock($claim){return self::$locked;}
        public static function release_publish_lock($claim){self::$locked=false;}
        public static function compare_publish_status($id,$expected,$status){if(self::$row['status']!==$expected)return false;self::$row['status']=$status;if($status!=='publishing')self::$row['publish_attempt_token']=self::$row['publish_worker_token']=null;if($expected==='needs_review'&&$status==='approved')self::$row['last_error']=null;return true;}
        public static function find($id){return self::$row;}
        public static function table_name(){return 'fixture_social';}
        public static function update_publish_result($id,$status,$error='',$fb='',$ig=''){
            if (self::$fail_id && ($fb!=='' || $ig!=='')) return false;
            self::$row['status']=$status;self::$row['last_error']=$error;
            if($status!=='publishing')self::$row['publish_attempt_token']=self::$row['publish_worker_token']=null;
            if($fb!=='')self::$row['facebook_post_id']=$fb;
            if($ig!=='')self::$row['instagram_media_id']=$ig;
            return true;
        }
        public static function clear_publish_id($id,$platform){self::$row[$platform==='facebook'?'facebook_post_id':'instagram_media_id']=null;return true;}
        public static function set_facebook_story_state($id,$state,$claim=null){self::$row['facebook_story_state']=$state;return true;}
        public static function clear_facebook_story_state($id,$claim=null){self::$row['facebook_story_state']=null;return true;}
        public static function set_instagram_container_id($id,$value){self::$row['instagram_container_id']=$value;return true;}
        public static function clear_instagram_container_id($id){self::$row['instagram_container_id']=null;return true;}
    }
}
namespace {
    define('ABSPATH',__DIR__);
    define('MINUTE_IN_SECONDS',60);define('ARRAY_A','ARRAY_A');
    function current_time($kind,$gmt=false){return $kind==='mysql'?'2026-10-04 12:00:00':1791133200;}
    function wp_timezone(){return new \DateTimeZone('UTC');}
    function wp_generate_uuid4(){static $n=0;return sprintf('00000000-0000-4000-8000-%012d',++$n);}
    function wp_date($format,$stamp,$zone){return gmdate($format,$stamp);}
    final class PublisherFixtureDb {
        public function prepare($sql,...$args){return $sql;}
        public function get_col($sql){return [1];}
        public function get_results($sql,$format){return $GLOBALS['due_rows']??[];}
    }
    function esc_url_raw($s){return $s;}
    function sanitize_text_field($s){return $s;}
    function wp_json_encode($value){return json_encode($value);}
    function wp_schedule_single_event($timestamp,$hook,$args=[]){$GLOBALS['schedule_state']=\RoxySocial\Store::$row['status'];if(!$GLOBALS['schedule_ok'])return false;$GLOBALS['scheduled_events'][]=[$timestamp,$hook,$args];return true;}
    function wp_next_scheduled($hook,$args=[]){foreach($GLOBALS['scheduled_events'] as $event)if($event[1]===$hook&&$event[2]===$args)return $event[0];return false;}
    function is_wp_error($r){return $r instanceof \RuntimeException;}
    function wp_remote_retrieve_response_code($r){return $r['status'];}
    function wp_remote_retrieve_body($r){return $r['body'];}
    function http_fixture($url,$args){
        $GLOBALS['calls'][]=[$url,$args,\RoxySocial\Store::$row];
        if(!empty($GLOBALS['lose_lock']))\RoxySocial\Store::$locked=false;
        if(!empty($GLOBALS['reenter_worker_on_http'])){$GLOBALS['reenter_worker_on_http']=false;\RoxySocial\Store::$locked=false;\RoxySocial\Publisher::process_queued(1,0,(string)(\RoxySocial\Store::$row['publish_attempt_token']??''));}
        if(!$GLOBALS['responses'])throw new \LogicException('Unexpected HTTP fixture call');
        return array_shift($GLOBALS['responses']);
    }
    function wp_remote_post($url,$args){return http_fixture($url,$args);}
    function wp_remote_get($url,$args){return http_fixture($url,$args);}
    function wp_remote_request($url,$args){return http_fixture($url,$args);}
    function add_query_arg($query,$url){return $url.'?'.http_build_query($query);}
    function response($data,$status=200){return ['status'=>$status,'body'=>json_encode($data)];}
    function reset_fixture($platform='both',$status='approved'){
        \RoxySocial\Store::$row=['id'=>1,'status'=>$status,'platform'=>$platform,'post_text'=>'Fixture caption','media_type'=>'image','media_url'=>'https://fixture.test/poster.jpg','publish_facebook_story'=>0,'facebook_post_id'=>null,'instagram_media_id'=>null,'instagram_container_id'=>null,'facebook_story_state'=>null,'publish_attempt_token'=>null,'publish_worker_token'=>null,'updated_at'=>'2020-01-01 00:00:00'];
        \RoxySocial\Store::$fail_id=false;\RoxySocial\Store::$locked=false;\RoxySocial\Store::$deny_lock=false;\RoxySocial\Meta::$facebook=true;\RoxySocial\Meta::$instagram=true;
        \RoxySocial\FacebookStories::$result=[];\RoxySocial\FacebookStories::$calls=0;
        \RoxySocial\Campaigns::$verified=true;
        $GLOBALS['calls']=[];$GLOBALS['responses']=[];$GLOBALS['scheduled_events']=[];
        $GLOBALS['lose_lock']=false;$GLOBALS['reenter_worker_on_http']=false;$GLOBALS['schedule_ok']=true;$GLOBALS['schedule_state']=null;
    }
    function check($ok,$label){if(!$ok){fwrite(STDERR,"::error title=Social publisher regression failed::".$label."\n");throw new \RuntimeException($label);}echo "PASS: $label\n";}
    require ($argv[1]??dirname(__DIR__)).'/includes/modules/social-publisher/includes/class-roxy-social-publisher.php';
    reset_fixture();\RoxySocial\Campaigns::$verified=false;$GLOBALS['responses']=[response(['id'=>'123']),response(['id'=>'234']),response(['id'=>'345'])];
    check(\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='posted'&&count($GLOBALS['calls'])===3,'caption schedule warning does not block publication to selected accounts');
    reset_fixture();\RoxySocial\Store::$row['media_type']='video';\RoxySocial\Store::$row['instagram_container_id']='234';
    $GLOBALS['responses']=[response(['id'=>'123']),response(['status_code'=>'FINISHED']),response(['id'=>'456'])];
    check(\RoxySocial\Publisher::publish_now(1)
        &&\RoxySocial\Store::$row['facebook_post_id']==='123'
        &&\RoxySocial\Store::$row['instagram_media_id']==='456'
        &&\RoxySocial\Store::$row['status']==='posted'
        &&\RoxySocial\FacebookStories::$calls===0
        &&count($GLOBALS['calls'])===3,
        'normal video feed post does not automatically publish as a Facebook Story');
    reset_fixture();\RoxySocial\Store::$row['media_type']='video';\RoxySocial\Store::$row['publish_facebook_story']=1;\RoxySocial\Store::$row['instagram_container_id']='234';
    \RoxySocial\FacebookStories::$result=['error'=>'Facebook Story video support is unavailable: the private FFmpeg binary was not found.'];
    $GLOBALS['responses']=[response(['id'=>'123']),response(['status_code'=>'FINISHED']),response(['id'=>'456'])];
    check(!\RoxySocial\Publisher::publish_now(1)
        &&\RoxySocial\Store::$row['facebook_post_id']==='123'
        &&\RoxySocial\Store::$row['instagram_media_id']==='456'
        &&\RoxySocial\Store::$row['status']==='failed'
        &&str_contains((string) \RoxySocial\Store::$row['last_error'],'Facebook Story:')
        &&\RoxySocial\FacebookStories::$calls===1
        &&count($GLOBALS['calls'])===3
        &&str_ends_with($GLOBALS['calls'][2][0],'/media_publish'),
        'Facebook Story processing failure does not block Instagram Reel publication and retains per-platform results');
    reset_fixture('both','failed');\RoxySocial\Store::$row['media_type']='video';\RoxySocial\Store::$row['publish_facebook_story']=1;
    \RoxySocial\Store::$row['facebook_post_id']='123';\RoxySocial\Store::$row['instagram_media_id']='456';
    \RoxySocial\Store::$row['facebook_story_state']=json_encode(['version'=>1,'expected'=>1,'ids'=>[],'pending_id'=>'']);
    \RoxySocial\Store::$row['last_error']='Facebook Story: video tooling was unavailable.';
    \RoxySocial\FacebookStories::$result=['state'=>json_encode(['version'=>1,'expected'=>1,'ids'=>['0'=>'789'],'pending_id'=>''])];
    check(\RoxySocial\Publisher::queue_facebook_story_retry(1)
        &&\RoxySocial\Store::$row['status']==='publishing'
        &&\RoxySocial\FacebookStories::$calls===0
        &&count($GLOBALS['scheduled_events'])===1
        &&$GLOBALS['scheduled_events'][0][1]==='roxy_social_facebook_story_retry',
        'Story-only retry claims the draft and queues a dedicated worker');
    $story_retry_args=$GLOBALS['scheduled_events'][0][2];
    \RoxySocial\Publisher::process_facebook_story_retry(...$story_retry_args);
    check(\RoxySocial\Store::$row['status']==='posted'
        &&\RoxySocial\Store::$row['facebook_post_id']==='123'
        &&\RoxySocial\Store::$row['instagram_media_id']==='456'
        &&\RoxySocial\FacebookStories::$calls===1
        &&count($GLOBALS['calls'])===0,
        'Story-only retry completes the unfinished Story without repeating normal Facebook or Instagram posts');
    \RoxySocial\Publisher::process_facebook_story_retry(...$story_retry_args);
    check(\RoxySocial\FacebookStories::$calls===1,'duplicate Story retry callback cannot republish the Story');
    reset_fixture('both','failed');\RoxySocial\Store::$row['media_type']='video';\RoxySocial\Store::$row['publish_facebook_story']=1;
    \RoxySocial\Store::$row['facebook_post_id']='123';\RoxySocial\Store::$row['instagram_media_id']='456';
    \RoxySocial\Store::$row['facebook_story_state']=json_encode(['version'=>1,'expected'=>1,'ids'=>[],'pending_id'=>'ambiguous-story-id']);
    \RoxySocial\Store::$row['last_error']='Facebook Story: interrupted upload';
    check(!\RoxySocial\Publisher::queue_facebook_story_retry(1)
        &&\RoxySocial\Store::$row['status']==='failed'
        &&count($GLOBALS['scheduled_events'])===0
        &&\RoxySocial\FacebookStories::$calls===0,
        'ambiguous pending Story upload is not offered to the retry queue');
    reset_fixture('both','failed');\RoxySocial\Store::$row['facebook_post_id']='123';\RoxySocial\Campaigns::$verified=false;$GLOBALS['responses']=[response(['id'=>'234']),response(['id'=>'345'])];
    check(\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['facebook_post_id']==='123'&&\RoxySocial\Store::$row['status']==='posted'&&count($GLOBALS['calls'])===2,'schedule wording does not block safe retry to only the missing account');
    $decode=new \ReflectionMethod(\RoxySocial\Publisher::class,'decode_response');$decode->setAccessible(true);
    $meta_error=$decode->invoke(null,response(['error'=>['message'=>'Fatal','type'=>'OAuthException','code'=>9007,'error_subcode'=>2207001]],400),true,false);
    check(($meta_error['error']??'')==='Meta returned HTTP 400: Fatal (type OAuthException; code 9007; subcode 2207001)'&&empty($meta_error['ambiguous']),
        'definitive Meta failures retain safe provider diagnostics without becoming ambiguous');
    $detailed_meta_error=$decode->invoke(null,response(['error'=>[
        'message'=>'Fatal','error_user_title'=>'Media unavailable','error_user_msg'=>'Check the media format.','fbtrace_id'=>'AbC_123-X','is_transient'=>false,
        'error_data'=>['sensitive'=>'must not be copied'],
    ]],400),true,false);
    check(($detailed_meta_error['error']??'')==='Meta returned HTTP 400: Fatal (user title: Media unavailable; user message: Check the media format.; trace AbC_123-X; transient no)'
        &&!str_contains((string)$detailed_meta_error['error'],'sensitive')&&empty($detailed_meta_error['ambiguous']),
        'Meta user diagnostics and trace ID are retained safely without raw error_data or changing retry classification');
    foreach([response(['id'=>'123'],500),response(['id'=>'123'],408),response([]),['status'=>200,'body'=>'not JSON'],response(['id'=>['123']]),response(['id'=>'']),new \RuntimeException('timeout')] as $r){
        $v=$decode->invoke(null,$r,true,false);check(!empty($v['error'])&&!empty($v['ambiguous']),'unknown or invalid publication never becomes success');
    }
    reset_fixture('facebook');$GLOBALS['responses']=[response(['error'=>['message'=>'request timed out']],408)];
    check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='needs_review'&&count($GLOBALS['calls'])===1,
        'Facebook HTTP 408 is ambiguous and cannot be blindly retried as a new post');
    foreach([response(['success'=>false]),response([]),response(['success'=>true],429)] as $r){check(!empty($decode->invoke(null,$r,false,true)['error']),'unconfirmed deletion retains ID');}
    check(($decode->invoke(null,response(['id'=>'11_123']),true,false)['id']??'')==='11_123','valid provider ID accepted');
    reset_fixture();$GLOBALS['responses']=[response(['id'=>'123']),response(['id'=>'234']),response(['id'=>'345'])];
    check(\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='posted','both confirmed platforms mark posted');
    check($GLOBALS['calls'][1][2]['facebook_post_id']==='123','Facebook ID durable before Instagram call');
    check($GLOBALS['calls'][2][2]['instagram_container_id']==='234'&&\RoxySocial\Store::$row['instagram_container_id']===null,'container retained until publication ID saved');
    reset_fixture();\RoxySocial\Store::$row['media_urls']=json_encode(['https://fixture.test/one.jpg','https://fixture.test/two.png','https://fixture.test/three.webp']);
    $GLOBALS['responses']=[response(['id'=>'101']),response(['id'=>'102']),response(['id'=>'103']),response(['id'=>'104']),response(['id'=>'201']),response(['id'=>'202']),response(['id'=>'203']),response(['id'=>'204']),response(['status_code'=>'FINISHED','status'=>'Finished']),response(['id'=>'205'])];
    check(\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='posted'&&count($GLOBALS['calls'])===10,'three-image post publishes successfully to both platforms');
    check(str_ends_with($GLOBALS['calls'][0][0],'/11/photos')&&($GLOBALS['calls'][0][1]['body']['published']??'')==='false'
        &&str_ends_with($GLOBALS['calls'][3][0],'/11/feed')&&isset($GLOBALS['calls'][3][1]['body']['attached_media[2]']),
        'Facebook multi-image post uploads unpublished photos then creates one album feed post');
    check(($GLOBALS['calls'][4][1]['body']['is_carousel_item']??'')==='true'
        &&($GLOBALS['calls'][7][1]['body']['media_type']??'')==='CAROUSEL'
        &&($GLOBALS['calls'][7][1]['body']['children']??'')==='201,202,203'
        &&str_contains($GLOBALS['calls'][8][0], 'fields=status_code%2Cstatus')
        &&str_ends_with($GLOBALS['calls'][9][0],'/media_publish'),
        'Instagram multi-image post waits for a ready carousel container before publishing one parent');
    foreach ([
        ['facebook', [response(['id'=>'701']), response(['error'=>['message'=>'invalid image']],400)], 2, 'Facebook album child upload'],
        ['facebook', [response(['id'=>'701']), response(['id'=>'702']), response(['error'=>['message'=>'feed rejected']],400)], 3, 'Facebook album parent creation'],
        ['instagram', [response(['id'=>'801']), response(['error'=>['message'=>'invalid image']],400)], 2, 'Instagram carousel child upload'],
        ['instagram', [response(['id'=>'801']), response(['id'=>'802']), response(['error'=>['message'=>'container rejected']],400)], 3, 'Instagram carousel parent creation'],
    ] as [$partial_platform, $partial_responses, $expected_calls, $partial_label]) {
        reset_fixture($partial_platform);
        \RoxySocial\Store::$row['media_urls']=json_encode(['https://fixture.test/one.jpg','https://fixture.test/two.png']);
        \RoxySocial\Store::$row['media_url']='https://fixture.test/one.jpg';
        if ($partial_platform === 'facebook') \RoxySocial\Meta::$instagram=false;
        else \RoxySocial\Meta::$facebook=false;
        $GLOBALS['responses']=$partial_responses;
        check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='needs_review'
            &&count($GLOBALS['calls'])===$expected_calls,
            $partial_label . ' with previously uploaded children enters review');
        $calls_before_review_retry=count($GLOBALS['calls']);
        check(!\RoxySocial\Publisher::publish_now(1)&&count($GLOBALS['calls'])===$calls_before_review_retry,
            $partial_label . ' cannot be blindly retried while in review');
    }
    reset_fixture();\RoxySocial\Store::$row['media_urls']=json_encode(['https://fixture.test/one.jpg','https://fixture.test/two.png','https://fixture.test/three.webp']);
    $GLOBALS['responses']=[response(['id'=>'101']),response(['id'=>'102']),response(['id'=>'103']),response(['id'=>'104']),response(['id'=>'201']),response(['id'=>'202']),response(['id'=>'203']),response(['id'=>'204']),response(['status_code'=>'IN_PROGRESS','status'=>'In progress'])];
    check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='needs_review'
        &&\RoxySocial\Store::$row['instagram_container_id']==='204'
        &&count(array_filter($GLOBALS['calls'],static fn($call)=>str_ends_with($call[0],'/media_publish')) )===0,
        'Instagram carousel that is still processing is retained for review without a publish attempt');
    reset_fixture();$GLOBALS['responses']=[response([])];
    check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='needs_review'&&count($GLOBALS['calls'])===1,'ambiguous Facebook outcome halts other platform and retry');
    reset_fixture();\RoxySocial\Store::$fail_id=true;$GLOBALS['responses']=[response(['id'=>'123'])];
    check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='needs_review'&&count($GLOBALS['calls'])===1,'failed ID persistence stops next platform');
    foreach ([400, 500] as $status) {
        reset_fixture('instagram');$GLOBALS['responses']=[response(['id'=>'234567']),response(['error'=>['message'=>'Media ID is not available']],$status)];
        check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='needs_review'
            &&\RoxySocial\Store::$row['instagram_container_id']==='234567'
            &&count(array_filter($GLOBALS['calls'],static fn($call)=>str_ends_with($call[0],'/media_publish')) )===1,
            'Instagram Media ID unavailable response '.$status.' gets one publish attempt, retains container and requires review');
    }
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
    reset_fixture('facebook','failed');\RoxySocial\Store::$row['facebook_post_id']='123';\RoxySocial\Store::$row['facebook_story_state']=json_encode(['expected'=>2,'ids'=>['456','789'],'pending_id'=>'']);$GLOBALS['responses']=[response(['success'=>true]),response(['success'=>true]),response(['success'=>false])];
    check(!\RoxySocial\Publisher::remove_published(1)&&\RoxySocial\Store::$row['facebook_post_id']===null&&json_decode(\RoxySocial\Store::$row['facebook_story_state'],true)['ids']===['789'],'partial Story removal clears each confirmed segment and retains only the failed ID');
    $GLOBALS['calls']=[];$GLOBALS['responses']=[response(['success'=>true])];
    check(\RoxySocial\Publisher::remove_published(1)&&\RoxySocial\Store::$row['facebook_story_state']===null&&count($GLOBALS['calls'])===1&&str_ends_with($GLOBALS['calls'][0][0],'/789'),'retry removes only the remaining Story segment');
    reset_fixture('both','posted');
    check(!\RoxySocial\Publisher::remove_published(1)&&!$GLOBALS['calls']&&\RoxySocial\Store::$row['status']==='posted','missing recorded IDs cannot report removed');
    reset_fixture('facebook','posted');\RoxySocial\Store::$row['facebook_post_id']='123';$GLOBALS['responses']=[response(['success'=>false])];
    check(!\RoxySocial\Publisher::remove_published(1)&&\RoxySocial\Store::$row['facebook_post_id']==='123','false success cannot erase published ID');
    reset_fixture();\RoxySocial\Store::$deny_lock=true;
    check(!\RoxySocial\Publisher::publish_now(1)&&!$GLOBALS['calls']&&\RoxySocial\Store::$row['status']==='approved','competing worker cannot publish or change status');
    reset_fixture();check(\RoxySocial\Publisher::queue_publish_now(1)&&!\RoxySocial\Store::$locked,'queued worker releases ownership before cron execution');
    $queued_args=$GLOBALS['scheduled_events'][0][2];$persisted_attempt=$queued_args[2];
    \RoxySocial\Publisher::process_queued($queued_args[0],$queued_args[1],wp_generate_uuid4());
    check(!$GLOBALS['calls']&&\RoxySocial\Store::$row['publish_attempt_token']===$persisted_attempt,'stale callback token cannot take over a newer queued attempt');
    \RoxySocial\Store::$row['publish_worker_token']=wp_generate_uuid4();
    \RoxySocial\Publisher::process_queued(...$queued_args);
    check(!$GLOBALS['calls']&&\RoxySocial\Store::$row['status']==='publishing','duplicate callback cannot claim an already executing attempt');
    \RoxySocial\Store::$row['publish_worker_token']=null;$GLOBALS['responses']=[response(['id'=>'123']),response(['id'=>'234']),response(['id'=>'345'])];
    \RoxySocial\Publisher::process_queued(...$queued_args);
    check(\RoxySocial\Store::$row['status']==='posted'&&!\RoxySocial\Store::$locked,'queued worker publishes its persisted attempt');
    $GLOBALS['calls']=[];\RoxySocial\Publisher::process_queued(...$queued_args);
    check(!$GLOBALS['calls'],'delivered-again completed cron cannot republish');
    reset_fixture('facebook','publishing');$GLOBALS['responses']=[];\RoxySocial\Store::$row['publish_attempt_token']=wp_generate_uuid4();
    \RoxySocial\Publisher::process_queued(1,0,\RoxySocial\Store::$row['publish_attempt_token']);
    check(\RoxySocial\Store::$row['status']==='needs_review'&&!\RoxySocial\Store::$locked,'interrupted provider call requires review and releases ownership');
    reset_fixture();$GLOBALS['schedule_ok']=false;
    check(!\RoxySocial\Publisher::queue_publish_now(1)&&$GLOBALS['schedule_state']==='publishing'&&\RoxySocial\Store::$row['status']==='approved','queue claims before scheduling and restores status on schedule failure');
    reset_fixture();
    check(\RoxySocial\Publisher::queue_publish_now(1)&&!\RoxySocial\Publisher::queue_publish_now(1),'second queue request cannot claim an already queued draft');
    reset_fixture();$GLOBALS['lose_lock']=true;$GLOBALS['responses']=[response(['id'=>'123'])];
    check(!\RoxySocial\Publisher::publish_now(1)&&count($GLOBALS['calls'])===1&&\RoxySocial\Store::$row['facebook_post_id']===null&&\RoxySocial\Store::$row['status']==='publishing','lost worker cannot persist IDs, overwrite next worker or call next provider');
    reset_fixture();check(\RoxySocial\Publisher::queue_publish_now(1),'attempt-token duplicate-worker fixture queues');
    $lost_connection_args=$GLOBALS['scheduled_events'][0][2];$GLOBALS['reenter_worker_on_http']=true;$GLOBALS['responses']=[response(['id'=>'accepted-remotely'])];
    \RoxySocial\Publisher::process_queued(...$lost_connection_args);
    check(count($GLOBALS['calls'])===1&&\RoxySocial\Store::$row['facebook_post_id']===null
        &&\RoxySocial\Store::$row['status']==='publishing'&&!empty(\RoxySocial\Store::$row['publish_worker_token']),
        'connection-loss retry with the same queued attempt cannot issue a duplicate provider request');
    $GLOBALS['wpdb']=new PublisherFixtureDb();reset_fixture('both','publishing');\RoxySocial\Store::$deny_lock=true;
    \RoxySocial\Publisher::publish_due();
    check(\RoxySocial\Store::$row['status']==='publishing'&&!$GLOBALS['calls'],'stale sweep cannot disturb active owner');
    reset_fixture('both','publishing');\RoxySocial\Store::$row['facebook_post_id']='123';\RoxySocial\Publisher::publish_due();
    check(\RoxySocial\Store::$row['status']==='needs_review'&&\RoxySocial\Store::$row['facebook_post_id']==='123'&&!$GLOBALS['calls'],'abandoned worker enters review, preserves IDs and never automatically republishes');
    reset_fixture('both','publishing');\RoxySocial\Store::$row['updated_at']='2099-01-01 00:00:00';\RoxySocial\Publisher::publish_due();
    check(\RoxySocial\Store::$row['status']==='publishing','stale read rechecks latest row before recovery');

    reset_fixture('instagram');\RoxySocial\Store::$row['media_type']='video';$GLOBALS['responses']=[response(['id'=>'234567'])];
    $video_created = !\RoxySocial\Publisher::publish_now(1);
    check($video_created&&\RoxySocial\Store::$row['status']==='failed'&&\RoxySocial\Store::$row['instagram_container_id']==='234567'
        &&count($GLOBALS['calls'])===1&&($GLOBALS['scheduled_events'][0][1]??'')==='roxy_social_video_status_retry'&&($GLOBALS['scheduled_events'][0][2]??[])===[1,1],
        'video container creation returns immediately and queues the first bounded status retry: ' . json_encode(['result'=>$video_created,'status'=>\RoxySocial\Store::$row['status'],'error'=>\RoxySocial\Store::$row['last_error'],'container'=>\RoxySocial\Store::$row['instagram_container_id'],'calls'=>count($GLOBALS['calls']),'events'=>$GLOBALS['scheduled_events']]));
    $GLOBALS['responses']=[response(['status_code'=>'FINISHED']),response(['id'=>'345678'])];
    check(\RoxySocial\Publisher::queue_video_status_retry(1,1),'video retry claims only the failed row with its saved container');
    $retry_args=$GLOBALS['scheduled_events'][count($GLOBALS['scheduled_events'])-1][2];\RoxySocial\Publisher::process_queued(...$retry_args);
    check(\RoxySocial\Store::$row['status']==='posted'&&\RoxySocial\Store::$row['instagram_media_id']==='345678'
        &&\RoxySocial\Store::$row['instagram_container_id']===null&&count($GLOBALS['calls'])===3
        &&str_ends_with($GLOBALS['calls'][2][0],'/media_publish')
        &&str_contains($GLOBALS['calls'][1][0],'/234567?fields=status_code'),
        'finished video publishes the existing container without blocking polls or creating a duplicate container: ' . json_encode(['status'=>\RoxySocial\Store::$row['status'],'error'=>\RoxySocial\Store::$row['last_error'],'media_id'=>\RoxySocial\Store::$row['instagram_media_id'],'container'=>\RoxySocial\Store::$row['instagram_container_id'],'calls'=>$GLOBALS['calls']]));

    reset_fixture('instagram','failed');\RoxySocial\Store::$row['media_type']='video';\RoxySocial\Store::$row['instagram_container_id']='expired-container';
    \RoxySocial\Store::$row['last_error']='Instagram video is still processing';$GLOBALS['responses']=[response(['status_code'=>'EXPIRED'])];
    check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='failed'
        &&\RoxySocial\Store::$row['instagram_container_id']===null
        &&!array_filter($GLOBALS['scheduled_events'],static fn($event)=>$event[1]==='roxy_social_video_status_retry'),
        'expired video container identity clears for a reviewed fresh attempt and stops automatic retries');

    reset_fixture('instagram','failed');\RoxySocial\Store::$row['media_type']='video';\RoxySocial\Store::$row['instagram_container_id']='published-container';
    \RoxySocial\Store::$row['last_error']='Instagram video is still processing';$GLOBALS['responses']=[response(['status_code'=>'PUBLISHED'])];
    check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='needs_review'
        &&count($GLOBALS['calls'])===1,'already-published container without a saved media ID requires review and is not republished');

    reset_fixture('instagram','failed');\RoxySocial\Store::$row['media_type']='video';\RoxySocial\Store::$row['instagram_container_id']='slow-container';
    \RoxySocial\Store::$row['last_error']='Instagram video is still processing (status check 4 of 5)';
    check(\RoxySocial\Publisher::queue_video_status_retry(1,5),'fifth video status check is queued with an explicit attempt count');
    $GLOBALS['responses']=[response(['status_code'=>'IN_PROGRESS'])];$fifth_args=$GLOBALS['scheduled_events'][count($GLOBALS['scheduled_events'])-1][2];\RoxySocial\Publisher::process_queued(...$fifth_args);
    check(\RoxySocial\Store::$row['status']==='needs_review'
        &&!array_filter($GLOBALS['scheduled_events'],static fn($event)=>$event[1]==='roxy_social_video_status_retry'),
        'video remaining in progress after five scheduled checks stops automatic polling');

    reset_fixture('instagram','failed');\RoxySocial\Store::$row['media_type']='video';\RoxySocial\Store::$row['instagram_container_id']='legacy-container';
    \RoxySocial\Store::$row['last_error']='Instagram video is still processing; the old retry event did not run';
    $GLOBALS['wpdb']=new PublisherFixtureDb();$GLOBALS['due_rows']=[\RoxySocial\Store::$row];\RoxySocial\Publisher::publish_due();
    check(\RoxySocial\Store::$row['status']==='publishing'&&$GLOBALS['scheduled_events'][0][1]==='roxy_social_publish_single'
        &&$GLOBALS['scheduled_events'][0][2][0]===1&&$GLOBALS['scheduled_events'][0][2][1]===5&&count($GLOBALS['scheduled_events'][0][2])===3,
        'legacy stuck video receives one final status-check recovery instead of an unbounded retry loop');

    reset_fixture('instagram','failed');\RoxySocial\Store::$row['instagram_container_id']='legacy-container';
    \RoxySocial\Store::$row['last_error']='Meta returned HTTP 500: Media ID is not available';
    \RoxySocial\Store::$row['facebook_post_id']='facebook-123';
    $GLOBALS['wpdb']=new PublisherFixtureDb();$GLOBALS['due_rows']=[\RoxySocial\Store::$row];\RoxySocial\Publisher::publish_due();
    check(\RoxySocial\Store::$row['status']==='needs_review'&&\RoxySocial\Store::$row['facebook_post_id']==='facebook-123'
        &&\RoxySocial\Store::$row['instagram_container_id']==='legacy-container'&&!$GLOBALS['scheduled_events']&&!$GLOBALS['calls'],
        'legacy ambiguous Instagram publish failure is moved to review without automatic retry or losing IDs');

    reset_fixture('instagram','publishing');\RoxySocial\Store::$row['instagram_container_id']='queued-container';
    \RoxySocial\Store::$row['last_error']='Meta returned HTTP 500: Media ID is not available';
    \RoxySocial\Store::$row['facebook_post_id']='facebook-queued';
    \RoxySocial\Publisher::process_queued(1);
    check(\RoxySocial\Store::$row['status']==='publishing'&&\RoxySocial\Store::$row['facebook_post_id']==='facebook-queued'
        &&\RoxySocial\Store::$row['instagram_container_id']==='queued-container'&&!$GLOBALS['calls'],
        'legacy queued callback without an attempt token is rejected without provider calls or state overwrite');
    \RoxySocial\Store::$row['status']='needs_review';\RoxySocial\Store::$row['last_error']='Prior ambiguity';
    check(\RoxySocial\Store::compare_publish_status(1,'needs_review','approved')&&\RoxySocial\Store::$row['last_error']===null,
        'explicit manager approval after remote review clears only the stale ambiguity marker');
}
