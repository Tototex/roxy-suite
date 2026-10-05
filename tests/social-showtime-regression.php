<?php
namespace RoxySocial {
    final class Store {
        public static $row; public static $generated=[];
        public static function find($id){return self::$row;}
        public static function upsert($data){self::$generated[]=$data;return count(self::$generated);}
        public static function save_ai_result($expected,$text,$error=''){
            if($expected!==self::$row)return false;
            if($error!==''){self::$row['status']='needs_review';self::$row['last_error']=$error;return true;}
            self::$row['post_text']=$text;self::$row['ai_status']='ready';return true;
        }
        public static function campaign_rows($key){return [];}
        public static function save_draft_snapshot($expected,$values){if($expected!==self::$row)return false;self::$row=array_merge(self::$row,$values);return true;}
        public static function approve_snapshot($expected){if($expected!==self::$row)return false;self::$row['status']='approved';return true;}
    }
    final class Hangar {public static function has_credentials(){return false;}}
}
namespace {
    define('ABSPATH',__DIR__);
    function wp_timezone(){return new \DateTimeZone('America/Los_Angeles');}
    function wp_date($format,$stamp,$zone){return (new \DateTimeImmutable('@'.$stamp))->setTimezone($zone)->format($format);}
    function get_post($id){return $GLOBALS['shows'][$id]??null;}
    function get_post_meta($id,$key,$single){return $GLOBALS['starts'][$id]??'';}
    function get_posts($args){return array_values($GLOBALS['shows']);}
    function get_the_title($id){return htmlspecialchars(get_post($id)->post_title,ENT_QUOTES);}
    function get_the_post_thumbnail_url(...$args){return '';}
    function get_permalink($id){return 'https://fixture.test/showings/'.$id;}
    function wp_list_pluck($rows,$key){return array_map(static fn($row)=>$row->$key,$rows);}
    function sanitize_title($s){return strtolower(str_replace(' ','-',$s));}
    function sanitize_text_field($s){return $s;}
    function esc_url_raw($s){return $s;}
    function untrailingslashit($s){return rtrim($s,'/');}
    function home_url($path){return 'https://fixture.test'.$path;}
    function get_option($key,$fallback=false){return ['roxy_social_ai_enabled'=>true,'roxy_social_ai_endpoint'=>'https://fixture.invalid','roxy_social_ai_model'=>'fixture','roxy_social_auto_approve'=>$GLOBALS['auto_approve']??false][$key]??$fallback;}
    function wp_parse_url($s,$component){return parse_url($s,$component);}
    function wp_json_encode($v){return json_encode($v);}
    function wp_next_scheduled(...$args){return false;}
    function wp_schedule_single_event(...$args){return true;}
    function is_wp_error($r){return false;}
    function wp_remote_retrieve_response_code($r){return 200;}
    function wp_remote_retrieve_body($r){return $r['body'];}
    function wp_remote_post($url,$args){$GLOBALS['calls']++;$GLOBALS['prompt']=$args['body'];if($GLOBALS['mutation'])($GLOBALS['mutation'])();return ['body'=>json_encode(['message'=>['content'=>"A warm local invitation.\nMonday at 8:00 PM\n10:00 AM surprise"]])];}
    function check($ok,$label){if(!$ok)throw new \RuntimeException($label);echo "PASS: $label\n";}
    function reset_fixture(){
        $GLOBALS['shows']=[];$GLOBALS['starts']=[];
        foreach([1=>'2026-10-09T18:00',2=>'2026-10-09T21:00',3=>'2026-10-10T16:00',4=>'2026-10-11T13:00'] as $id=>$start){$GLOBALS['shows'][$id]=(object)['ID'=>$id,'post_type'=>'roxy_showing','post_status'=>'publish','post_title'=>'Fixture Movie'];$GLOBALS['starts'][$id]=$start;}
        \RoxySocial\Store::$row=['id'=>1,'showing_ids'=>'1,2,3,4','campaign_key'=>'fixture-movie-20261009','status'=>'draft','ai_status'=>'pending','scheduled_for'=>'2026-10-05 10:00:00','post_text'=>'Untrusted caption: Fri at 7:30 PM'];
        $GLOBALS['calls']=0;$GLOBALS['mutation']=null;$GLOBALS['auto_approve']=false;\RoxySocial\Store::$generated=[];
    }
    $root=$argv[1]??dirname(__DIR__);
    require $root.'/includes/modules/social-publisher/includes/class-roxy-social-campaigns.php';
    require $root.'/includes/modules/social-publisher/includes/class-roxy-social-ai.php';
    reset_fixture();$rows=\RoxySocial\Campaigns::verified_showtimes(\RoxySocial\Store::$row);
    check(count($rows)===4&&str_contains($rows[0]['line'],'6:00 PM')&&str_contains($rows[1]['line'],'9:00 PM'),'multiple Friday shows retained from canonical records');
    $caption=\RoxySocial\Store::$row;$caption['post_text']=implode("\n",array_column($rows,'line'));
    check(\RoxySocial\Campaigns::verified_caption_schedule($caption),'verified full schedule accepted at approval/publish gate');
    $GLOBALS['starts'][1]='2026-10-09T19:00';check(!\RoxySocial\Campaigns::verified_caption_schedule($caption),'showtime change after approval invalidates frozen caption without rewriting it');
    $GLOBALS['starts'][1]='2026-10-09T18:00';$caption['post_text'].="\nSun at 2:30 PM";check(!\RoxySocial\Campaigns::verified_caption_schedule($caption),'additional assumed weekday line cannot pass schedule gate');
    check(\RoxySocial\Campaigns::verified_caption_schedule(['campaign_key'=>'manual','post_text'=>'General theater news']),'unlinked manual announcements remain publishable');
    reset_fixture();$GLOBALS['auto_approve']=true;\RoxySocial\Store::$row=array_merge(\RoxySocial\Store::$row,['ai_status'=>'ready','media_url'=>'https://fixture.test/movie.jpg','hangar_asset_id'=>1]);\RoxySocial\Campaigns::maybe_auto_approve(1);
    check(\RoxySocial\Store::$row['status']==='needs_review','actual automatic-approval helper refuses mismatched caption schedule');
    reset_fixture();$GLOBALS['auto_approve']=true;\RoxySocial\Store::$row=array_merge(\RoxySocial\Store::$row,['ai_status'=>'ready','media_url'=>'https://fixture.test/movie.jpg','hangar_asset_id'=>1,'post_text'=>implode("\n",array_column(\RoxySocial\Campaigns::verified_showtimes(\RoxySocial\Store::$row),'line'))]);\RoxySocial\Campaigns::maybe_auto_approve(1);
    check(\RoxySocial\Store::$row['status']==='approved','actual automatic-approval helper permits verified schedule');
    reset_fixture();\RoxySocial\Store::$row['scheduled_for']='2026-10-10 10:00:00';$rows=\RoxySocial\Campaigns::verified_showtimes(\RoxySocial\Store::$row);
    check(array_column($rows,'id')===[3,4],'Saturday filtering removes every Friday showing by date, not position');
    reset_fixture();\RoxySocial\Store::$row['showing_ids']='3';\RoxySocial\AI::generate_text(1,'fixture-movie-20261009');$text=\RoxySocial\Store::$row['post_text'];
    check(str_contains($text,'Sat, Oct 10, 2026 at 4:00 PM')&&!str_contains($text,'Fri,')&&!str_contains($text,'Sun,')&&!str_contains($text,'7:30')&&!str_contains($text,'2:30'),'Saturday-only schedule never invents absent Friday/Sunday times');
    check(!str_contains($text,'8:00 PM')&&!str_contains($text,'10:00 AM'),'generated time lines are removed for every weekday and unlabeled clocks');
    reset_fixture();$GLOBALS['starts'][1]='2027-01-01T18:00';$GLOBALS['starts'][2]='2026-12-31T21:00';\RoxySocial\Store::$row['showing_ids']='1,2';\RoxySocial\Store::$row['scheduled_for']='2026-12-30 10:00:00';$rows=\RoxySocial\Campaigns::verified_showtimes(\RoxySocial\Store::$row);
    check(array_column($rows,'id')===[2,1]&&str_contains($rows[1]['line'],'2027'),'holiday weekday and year rollover use real dates and chronological order');
    foreach(['missing','draft','deleted','invalid-date','invalid-refs','past-only'] as $case){reset_fixture();if($case==='missing')unset($GLOBALS['shows'][1]);if($case==='draft')$GLOBALS['shows'][1]->post_status='draft';if($case==='deleted')$GLOBALS['shows'][1]->post_status='trash';if($case==='invalid-date')$GLOBALS['starts'][1]='2026-02-30T18:00';if($case==='invalid-refs')\RoxySocial\Store::$row['showing_ids']='1,no';if($case==='past-only')\RoxySocial\Store::$row['scheduled_for']='2026-10-12 10:00:00';\RoxySocial\AI::generate_text(1,'fixture-movie-20261009');check($GLOBALS['calls']===0&&\RoxySocial\Store::$row['status']==='needs_review','unverifiable schedule stops before AI: '.$case);}
    reset_fixture();$GLOBALS['mutation']=static function(){$GLOBALS['starts'][1]='2026-10-09T19:00';};\RoxySocial\AI::generate_text(1,'fixture-movie-20261009');
    check(\RoxySocial\Store::$row['status']==='needs_review'&&str_contains(\RoxySocial\Store::$row['last_error'],'changed'),'showing edit during AI generation prevents saving stale schedule');
    reset_fixture();$GLOBALS['mutation']=static function(){foreach($GLOBALS['shows'] as $show)$show->post_title='Renamed Movie';};\RoxySocial\AI::generate_text(1,'fixture-movie-20261009');
    check(\RoxySocial\Store::$row['status']==='needs_review','title edit during generation invalidates its captured facts');
    reset_fixture();$GLOBALS['shows'][2]->post_title='Other Film';\RoxySocial\AI::generate_text(1,'fixture-movie-20261009');
    check($GLOBALS['calls']===0&&\RoxySocial\Store::$row['status']==='needs_review','mixed-film showing references require review');
    $method=new \ReflectionMethod(\RoxySocial\AI::class,'next_showing_context');$context=$method->invoke(null,\RoxySocial\Store::$row,'fixture');
    check(str_contains($context,'No verified next-showing'),'future publication dates never masquerade as next-showing dates');
    // Campaign generator runs against an always-future weekend to remain repeatable.
    reset_fixture();$future=(new \DateTimeImmutable('2099-10-09',wp_timezone()))->modify('Friday this week');
    foreach([1=>[0,'18:00'],2=>[0,'21:00'],3=>[1,'16:00'],4=>[2,'13:00']] as $id=>[$offset,$clock])$GLOBALS['starts'][$id]=$future->modify('+'.$offset.' days')->format('Y-m-d').'T'.$clock;
    \RoxySocial\Campaigns::generate_for_showing(1);$generated=\RoxySocial\Store::$generated;
    check(count($generated)===5&&$generated[3]['showing_ids']==='3,4'&&$generated[4]['showing_ids']==='4','actual campaign generator filters Saturday/Sunday by calendar date');
    check(str_contains($generated[0]['post_text'],'6:00 PM')&&str_contains($generated[0]['post_text'],'9:00 PM'),'campaign captions preserve multiple shows on the same day');
    \RoxySocial\Store::$generated=[];foreach($GLOBALS['shows'] as $show)$show->post_title='Film & Event';\RoxySocial\Campaigns::generate_for_showing(1);
    check(count(\RoxySocial\Store::$generated)===5,'title display filters cannot hide canonical ampersand/apostrophe showing matches');
    reset_fixture();$GLOBALS['shows']=[3=>$GLOBALS['shows'][3]];$GLOBALS['starts']=[3=>$future->modify('+1 day')->format('Y-m-d').'T16:00'];\RoxySocial\Campaigns::generate_for_showing(3);
    check(count(\RoxySocial\Store::$generated)===4,'Saturday-only campaign does not create an empty Sunday promotion');
}
