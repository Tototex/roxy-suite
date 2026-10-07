<?php
namespace RoxySocial {
    class Store {
        public static $row;
        public static function find($id) { return self::$row; }
        public static function draft_revision($row) { return hash('sha256',json_encode($row)); }
        public static function save_ai_result($expected,$text,$error='') {
            if (self::draft_revision($expected)!==self::draft_revision(self::$row)) return false;
            self::$row['status']=$error!==''?'needs_review':'draft';
            self::$row['ai_status']=$error!==''?'pending':'ready';
            self::$row['last_error']=$error;
            if ($text!=='') self::$row['post_text']=$text;
            return true;
        }
    }
}
namespace {
    require __DIR__.'/social-film-context-regression.php';
    $events=[]; $chat=['message'=>['content'=>'']]; $request=[]; $onChat=null;
    function wp_next_scheduled($hook,$args) { return false; }
    function wp_schedule_single_event($time,$hook,$args) { global $events; $events[]=[$hook,$args]; return true; }
    function get_option($key,$default=false) { return $key==='roxy_social_ai_enabled' ? true : $default; }
    function untrailingslashit($v) { return rtrim($v,'/'); }
    function sanitize_text_field($v) { return $v; }
    function wp_parse_url($v,$p) { return parse_url($v,$p); }
    function home_url($v) { return 'https://newportroxy.com'.$v; }
    function wp_json_encode($v) { return json_encode($v); }
    function wp_remote_post($url,$args) { global $chat,$request,$onChat; $request=json_decode($args['body'],true); if ($onChat) $onChat(); return ['body'=>$chat]; }
    $draft=['id'=>20,'campaign_key'=>'heart-of-the-beast-20261009','status'=>'draft','ai_status'=>'pending','scheduled_for'=>'2026-10-11 10:00:00','showing_ids'=>'3','post_text'=>'Today at the Roxy','last_error'=>''];
    $fixture_posts[3]->post_excerpt=str_repeat('A survival film about a veteran and his dog. ',4);
    $fixture_posts[3]->post_content='';
    \RoxySocial\Store::$row=$draft;
    \RoxySocial\AI::generate_text(20,$draft['campaign_key']);
    check(\RoxySocial\Store::$row['status']==='draft' && count($events)===1 && $events[0][1][2]===1);
    check($request['format']['required']===['caption']);
    check($fixture_cache['roxy_social_ai_failure_20']['reason']==='Ollama returned an empty caption response.');
    $chat=['message'=>['content'=>json_encode(['caption'=>'One last adventure before Monday. Settle in with popcorn and enjoy the big screen.'])]];
    \RoxySocial\AI::generate_text(20,$draft['campaign_key'],1);
    check(\RoxySocial\Store::$row['ai_status']==='ready');
    check(str_contains(\RoxySocial\Store::$row['post_text'],'Sun, Oct 11, 2026 at 2:30 PM'));
    check(!str_contains(\RoxySocial\Store::$row['post_text'],'Fri,'));
    \RoxySocial\Store::$row=$draft; $chat=['message'=>['content'=>'not json']]; $events=[];
    \RoxySocial\AI::generate_text(20,$draft['campaign_key'],2);
    check(\RoxySocial\Store::$row['status']==='needs_review' && !$events);
    check(str_contains(\RoxySocial\Store::$row['last_error'],'invalid caption format'));
    $parser=new \ReflectionMethod(\RoxySocial\AI::class,'response_caption');
    check($parser->invoke(null,json_encode(['caption'=>'Sunday at 2:30 PM with all the popcorn you need.']))==='');
    check($parser->invoke(null,json_encode(['caption'=>'Short']))==='');
    check($parser->invoke(null,json_encode(['caption'=>'This caption uses a playful and conversational tone for the theater.']))==='');
    \RoxySocial\Store::$row=$draft;
    $onChat=static function() { \RoxySocial\Store::$row['post_text']='My manual edit'; \RoxySocial\Store::$row['ai_status']='manual'; };
    \RoxySocial\AI::generate_text(20,$draft['campaign_key']);
    check(\RoxySocial\Store::$row['post_text']==='My manual edit' && \RoxySocial\Store::$row['ai_status']==='manual');
    echo "12 structured-caption and retry checks passed\n";
}
