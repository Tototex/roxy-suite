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
    final class Store {
        public static $row;
        public static $fail_id = false;
        public static $locked = false;
        public static $deny_lock = false;
        public static function acquire_publish_lock($id){if(self::$deny_lock||self::$locked)return null;self::$locked=true;return ['name'=>'fixture','connection'=>'1'];}
        public static function owns_publish_lock($claim){return self::$locked;}
        public static function release_publish_lock($claim){self::$locked=false;}
        public static function compare_publish_status($id,$expected,$status){if(self::$row['status']!==$expected)return false;self::$row['status']=$status;return true;}
        public static function find($id){return self::$row;}
        public static function table_name(){return 'fixture_social';}
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
    define('MINUTE_IN_SECONDS',60);define('ARRAY_A','ARRAY_A');
    function current_time($kind,$gmt=false){return $kind==='mysql'?'2026-10-04 12:00:00':1791133200;}
    function wp_timezone(){return new \DateTimeZone('UTC');}
    function wp_date($format,$stamp,$zone){return gmdate($format,$stamp);}
    final class PublisherFixtureDb {
        public function prepare($sql,...$args){return $sql;}
        public function get_col($sql){return [1];}
        public function get_results($sql,$format){return $GLOBALS['due_rows']??[];}
    }
    function esc_url_raw($s){return $s;}
    function sanitize_text_field($s){return $s;}
    function wp_schedule_single_event($timestamp,$hook,$args=[]){$GLOBALS['schedule_state']=\RoxySocial\Store::$row['status'];if(!$GLOBALS['schedule_ok'])return false;$GLOBALS['scheduled_events'][]=[$timestamp,$hook,$args];return true;}
    function wp_next_scheduled($hook,$args=[]){foreach($GLOBALS['scheduled_events'] as $event)if($event[1]===$hook&&$event[2]===$args)return $event[0];return false;}
    function is_wp_error($r){return $r instanceof \RuntimeException;}
    function wp_remote_retrieve_response_code($r){return $r['status'];}
    function wp_remote_retrieve_body($r){return $r['body'];}
    function http_fixture($url,$args){
        $GLOBALS['calls'][]=[$url,$args,\RoxySocial\Store::$row];
        if(!empty($GLOBALS['lose_lock']))\RoxySocial\Store::$locked=false;
        if(!$GLOBALS['responses'])throw new \LogicException('Unexpected HTTP fixture call');
        return array_shift($GLOBALS['responses']);
    }
    function wp_remote_post($url,$args){return http_fixture($url,$args);}
    function wp_remote_get($url,$args){return http_fixture($url,$args);}
    function wp_remote_request($url,$args){return http_fixture($url,$args);}
    function add_query_arg($query,$url){return $url.'?'.http_build_query($query);}
    function response($data,$status=200){return ['status'=>$status,'body'=>json_encode($data)];}
    function reset_fixture($platform='both',$status='approved'){
        \RoxySocial\Store::$row=['id'=>1,'status'=>$status,'platform'=>$platform,'post_text'=>'Fixture caption','media_type'=>'image','media_url'=>'https://fixture.test/poster.jpg','facebook_post_id'=>null,'instagram_media_id'=>null,'instagram_container_id'=>null,'updated_at'=>'2020-01-01 00:00:00'];
        \RoxySocial\Store::$fail_id=false;\RoxySocial\Store::$locked=false;\RoxySocial\Store::$deny_lock=false;\RoxySocial\Meta::$facebook=true;\RoxySocial\Meta::$instagram=true;
        \RoxySocial\Campaigns::$verified=true;
        $GLOBALS['calls']=[];$GLOBALS['responses']=[];$GLOBALS['scheduled_events']=[];
        $GLOBALS['lose_lock']=false;$GLOBALS['schedule_ok']=true;$GLOBALS['schedule_state']=null;
    }
    function check($ok,$label){if(!$ok){fwrite(STDERR,"::error title=Social publisher regression failed::".$label."\n");throw new \RuntimeException($label);}echo "PASS: $label\n";}
    require ($argv[1]??dirname(__DIR__)).'/includes/modules/social-publisher/includes/class-roxy-social-publisher.php';
    reset_fixture();\RoxySocial\Campaigns::$verified=false;
    check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='needs_review'&&!$GLOBALS['calls'],'stale caption schedule stops before any provider call');
    reset_fixture('both','failed');\RoxySocial\Store::$row['facebook_post_id']='123';\RoxySocial\Campaigns::$verified=false;
    check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['facebook_post_id']==='123'&&!$GLOBALS['calls'],'schedule change on partial retry retains existing public ID and requires review');
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
    reset_fixture();\RoxySocial\Store::$deny_lock=true;
    check(!\RoxySocial\Publisher::publish_now(1)&&!$GLOBALS['calls']&&\RoxySocial\Store::$row['status']==='approved','competing worker cannot publish or change status');
    reset_fixture();$GLOBALS['responses']=[response(['id'=>'123']),response(['id'=>'234']),response(['id'=>'345'])];
    \RoxySocial\Publisher::publish_now(1);
    check(!\RoxySocial\Store::$locked,'successful worker releases ownership');
    $GLOBALS['calls']=[];\RoxySocial\Publisher::process_queued(1);
    check(!$GLOBALS['calls'],'delivered-again completed cron cannot republish');
    reset_fixture('facebook','publishing');$GLOBALS['responses']=[];
    \RoxySocial\Publisher::process_queued(1);
    check(\RoxySocial\Store::$row['status']==='needs_review'&&!\RoxySocial\Store::$locked,'interrupted provider call requires review and releases ownership');
    reset_fixture();$GLOBALS['schedule_ok']=false;
    check(!\RoxySocial\Publisher::queue_publish_now(1)&&$GLOBALS['schedule_state']==='publishing'&&\RoxySocial\Store::$row['status']==='approved','queue claims before scheduling and restores status on schedule failure');
    reset_fixture();
    check(\RoxySocial\Publisher::queue_publish_now(1)&&!\RoxySocial\Publisher::queue_publish_now(1),'second queue request cannot claim an already queued draft');
    reset_fixture();$GLOBALS['lose_lock']=true;$GLOBALS['responses']=[response(['id'=>'123'])];
    check(!\RoxySocial\Publisher::publish_now(1)&&count($GLOBALS['calls'])===1&&\RoxySocial\Store::$row['facebook_post_id']===null&&\RoxySocial\Store::$row['status']==='publishing','lost worker cannot persist IDs, overwrite next worker or call next provider');
    $GLOBALS['wpdb']=new PublisherFixtureDb();reset_fixture('both','publishing');\RoxySocial\Store::$deny_lock=true;
    \RoxySocial\Publisher::publish_due();
    check(\RoxySocial\Store::$row['status']==='publishing'&&!$GLOBALS['calls'],'stale sweep cannot disturb active owner');
    reset_fixture('both','publishing');\RoxySocial\Store::$row['facebook_post_id']='123';\RoxySocial\Publisher::publish_due();
    check(\RoxySocial\Store::$row['status']==='needs_review'&&\RoxySocial\Store::$row['facebook_post_id']==='123'&&!$GLOBALS['calls'],'abandoned worker enters review, preserves IDs and never automatically republishes');
    reset_fixture('both','publishing');\RoxySocial\Store::$row['updated_at']='2099-01-01 00:00:00';\RoxySocial\Publisher::publish_due();
    check(\RoxySocial\Store::$row['status']==='publishing','stale read rechecks latest row before recovery');

    reset_fixture('instagram');\RoxySocial\Store::$row['media_type']='video';$GLOBALS['responses']=[response(['id'=>'container-video'])];
    check(!\RoxySocial\Publisher::publish_now(1)&&\RoxySocial\Store::$row['status']==='failed'&&\RoxySocial\Store::$row['instagram_container_id']==='container-video'
        &&count($GLOBALS['calls'])===1&&$GLOBALS['scheduled_events'][0][1]==='roxy_social_video_status_retry'&&$GLOBALS['scheduled_events'][0][2]===[1,1],
        'video container creation returns immediately and queues the first bounded status retry');
    $GLOBALS['responses']=[response(['status_code'=>'FINISHED']),response(['id'=>'instagram-media'])];
    check(\RoxySocial\Publisher::queue_video_status_retry(1,1),'video retry claims only the failed row with its saved container');
    \RoxySocial\Publisher::process_queued(1,1);
    check(\RoxySocial\Store::$row['status']==='posted'&&\RoxySocial\Store::$row['instagram_media_id']==='instagram-media'
        &&\RoxySocial\Store::$row['instagram_container_id']===null&&count($GLOBALS['calls'])===2
        &&!str_contains($GLOBALS['calls'][1][0],'/media?'),
        'finished video publishes the existing container without blocking polls or creating a duplicate container');

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
    $GLOBALS['responses']=[response(['status_code'=>'IN_PROGRESS'])];\RoxySocial\Publisher::process_queued(1,5);
    check(\RoxySocial\Store::$row['status']==='needs_review'
        &&!array_filter($GLOBALS['scheduled_events'],static fn($event)=>$event[1]==='roxy_social_video_status_retry'),
        'video remaining in progress after five scheduled checks stops automatic polling');

    reset_fixture('instagram','failed');\RoxySocial\Store::$row['media_type']='video';\RoxySocial\Store::$row['instagram_container_id']='legacy-container';
    \RoxySocial\Store::$row['last_error']='Instagram video is still processing; the old retry event did not run';
    $GLOBALS['wpdb']=new PublisherFixtureDb();$GLOBALS['due_rows']=[\RoxySocial\Store::$row];\RoxySocial\Publisher::publish_due();
    check(\RoxySocial\Store::$row['status']==='publishing'&&$GLOBALS['scheduled_events'][0][1]==='roxy_social_publish_single'
        &&$GLOBALS['scheduled_events'][0][2]===[1,5],
        'legacy stuck video receives one final status-check recovery instead of an unbounded retry loop');
}
