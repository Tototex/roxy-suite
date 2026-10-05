<?php
// Actual WordPress showing reads/rendering; in-memory campaign sink; no provider calls/writes.
namespace RoxySocial {
    final class ReadScheduleStore {public static $generated=[];public static function upsert($row){self::$generated[]=$row;return count(self::$generated);}}
    final class ReadScheduleAI {public static function queue_campaign($key){}}
    final class ReadScheduleHangar {public static function has_credentials(){return false;}}
}
namespace {
    global $wpdb;
    $root=$args[0]??dirname(__DIR__);
    $source=file_get_contents($root.'/includes/modules/social-publisher/includes/class-roxy-social-campaigns.php');
    $source=str_replace(['final class Campaigns {','Store::','AI::','Hangar::'],['final class ReadScheduleCampaigns {','ReadScheduleStore::','ReadScheduleAI::','ReadScheduleHangar::'],$source);eval(substr($source,5));
    $text=file_get_contents($root.'/includes/modules/social-publisher/includes/class-roxy-social-ai.php');$text=str_replace(['final class AI {','Campaigns::'],['final class ReadScheduleTextAI {','ReadScheduleCampaigns::'],$text);eval(substr($text,5));
    function schedule_live_check($ok,$label){if(!$ok)throw new \RuntimeException($label);echo "PASS: $label\n";}
    $table=\RoxySocial\Store::table_name();$before=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A)));
    $shows=get_posts(['post_type'=>'roxy_showing','post_status'=>'publish','posts_per_page'=>10,'orderby'=>'meta_value','meta_key'=>'_roxy_start','order'=>'ASC','meta_query'=>[['key'=>'_roxy_start','value'=>wp_date('Y-m-d').'T00:00','compare'=>'>=','type'=>'CHAR']]]);
    schedule_live_check(count($shows)>0,'upcoming published showing records available for actual read-only verification');
    $footer=new \ReflectionMethod(\RoxySocial\ReadScheduleTextAI::class,'schedule_footer');
    foreach($shows as $show){
        $draft=['showing_ids'=>(string)$show->ID,'scheduled_for'=>wp_date('Y-m-d').' 00:00:00','post_text'=>'Incorrect caption date is not authoritative'];
        $rows=\RoxySocial\ReadScheduleCampaigns::verified_showtimes($draft);
        $value=$footer->invoke(null,$draft,'scheduled day');
        schedule_live_check(count($rows)===1&&str_contains($value,$rows[0]['line'])&&str_contains($value,home_url('/tickets/')),'canonical live record produces exact year/date/time and local ticket footer');
    }
    foreach($shows as $show){
        $start=new \DateTimeImmutable((string)get_post_meta($show->ID,'_roxy_start',true),wp_timezone());
        if((int)$start->format('N')<5)continue;
        \RoxySocial\ReadScheduleCampaigns::generate_for_showing((int)$show->ID);
        if(!\RoxySocial\ReadScheduleStore::$generated)continue;
        foreach(\RoxySocial\ReadScheduleStore::$generated as $draft){
            $expected=implode("\n",array_map(static fn($row)=>wp_date('D, M j \\a\\t g:i A',$row['timestamp'],wp_timezone()),\RoxySocial\ReadScheduleCampaigns::verified_showtimes($draft)));
            schedule_live_check(str_contains($draft['post_text'],"Showtimes:\n".$expected."\n\nTickets:"),'actual campaign query/filter output matches each linked published showing');
            schedule_live_check(\RoxySocial\ReadScheduleCampaigns::verified_caption_schedule($draft),'actual generated campaign passes canonical approval/publication schedule gate');
        }
        break;
    }
    if(\RoxySocial\ReadScheduleStore::$generated)echo "PASS: live campaign generator exercised with in-memory write sink\n";
    else echo "SKIP: no eligible future-weekend showing for live campaign generation; deterministic future fixtures cover that branch\n";
    $after=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A)));
    schedule_live_check(hash_equals($before,$after),'all production Social rows unchanged by actual schedule checks');
}
