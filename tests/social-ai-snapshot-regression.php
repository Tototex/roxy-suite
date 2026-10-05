<?php
// Real AI worker, isolated environment/provider response and snapshot-store double.
namespace RoxySocial {
    final class Store {
        public static $row;
        public static function find($id){return self::$row;}
        public static function all_recent(){return [];}
        public static function save_ai_result($expected,$text,$error=''){
            if($expected!==self::$row)return false;
            if($error!==''){self::$row['status']='needs_review';self::$row['ai_status']='pending';return true;}
            self::$row['post_text']=$text;self::$row['ai_status']='ready';return true;
        }
    }
    final class Campaigns {
        public static function maybe_auto_approve($id){$GLOBALS['approvals']++;}
    }
}
namespace {
    define('ABSPATH',__DIR__);
    function get_option($key,$fallback=false){return ['roxy_social_ai_enabled'=>true,'roxy_social_ai_endpoint'=>'https://fixture.invalid','roxy_social_ai_model'=>'fixture-model'][$key]??$fallback;}
    function untrailingslashit($s){return rtrim($s,'/');}
    function esc_url_raw($s){return $s;}
    function sanitize_text_field($s){return $s;}
    function sanitize_title($s){return strtolower($s);}
    function wp_parse_url($s,$component){return parse_url($s,$component);}
    function wp_timezone(){return new \DateTimeZone('America/Los_Angeles');}
    function wp_date($format,$stamp,$zone){return (new \DateTimeImmutable('@'.$stamp))->setTimezone($zone)->format($format);}
    function wp_json_encode($v){return json_encode($v);}
    function wp_next_scheduled(...$args){return false;}
    function wp_schedule_single_event(...$args){$GLOBALS['retries']++;return true;}
    function is_wp_error($r){return !empty($r['wp_error']);}
    function wp_remote_retrieve_response_code($r){return $r['status'];}
    function wp_remote_retrieve_body($r){return $r['body'];}
    function wp_remote_post($url,$args){$GLOBALS['calls']++;if($GLOBALS['mutation'])($GLOBALS['mutation'])();return $GLOBALS['response'];}
    function check($ok,$label){if(!$ok)throw new \RuntimeException($label);echo "PASS: $label\n";}
    function reset_fixture(){
        \RoxySocial\Store::$row=['id'=>1,'campaign_key'=>'fixture-20261009','status'=>'draft','ai_status'=>'pending','scheduled_for'=>'2026-10-05 10:00:00','post_text'=>"Fixture movie\nFri, Oct 9 at 7:30 PM\nSat, Oct 10 at 7:30 PM\nSun, Oct 11 at 2:30 PM"];
        $GLOBALS['calls']=0;$GLOBALS['approvals']=0;$GLOBALS['retries']=0;$GLOBALS['mutation']=null;$GLOBALS['response']=['status'=>200,'body'=>json_encode(['message'=>['content'=>'Generated fixture body']])];
    }
    require ($argv[1]??dirname(__DIR__)).'/includes/modules/social-publisher/includes/class-roxy-social-ai.php';
    reset_fixture();\RoxySocial\AI::generate_text(1,'fixture-20261009');
    check($GLOBALS['calls']===1&&$GLOBALS['approvals']===1&&str_contains(\RoxySocial\Store::$row['post_text'],'Generated fixture body'),'valid AI result saves text before invoking approval helper');
    foreach([['status'=>500,'body'=>'error'],['status'=>200,'body'=>'not-json'],['status'=>200,'body'=>'{}'],['wp_error'=>true]] as $r){reset_fixture();$GLOBALS['response']=$r;\RoxySocial\AI::generate_text(1,'fixture-20261009');check(\RoxySocial\Store::$row['status']==='needs_review'&&\RoxySocial\Store::$row['ai_status']==='pending'&&$GLOBALS['approvals']===0,'failed/unusable AI cannot become ready or auto-approved');}
    reset_fixture();$original=\RoxySocial\Store::$row['post_text'];$GLOBALS['mutation']=static function(){\RoxySocial\Store::$row['status']='approved';};\RoxySocial\AI::generate_text(1,'fixture-20261009');
    check(\RoxySocial\Store::$row['status']==='approved'&&\RoxySocial\Store::$row['post_text']===$original&&$GLOBALS['approvals']===0,'approval during actual AI worker rejects its late result');
    reset_fixture();$GLOBALS['mutation']=static function(){\RoxySocial\Store::$row['post_text']='Manager caption';\RoxySocial\Store::$row['ai_status']='ready';};\RoxySocial\AI::generate_text(1,'fixture-20261009');
    check(\RoxySocial\Store::$row['post_text']==='Manager caption'&&$GLOBALS['approvals']===0,'edit during actual AI worker preserves manager caption');
    reset_fixture();$GLOBALS['response']=['status'=>500,'body'=>'error'];$GLOBALS['mutation']=static function(){\RoxySocial\Store::$row['status']='approved';};\RoxySocial\AI::generate_text(1,'fixture-20261009');
    check(\RoxySocial\Store::$row['status']==='approved','late AI failure cannot demote newly approved draft');
    reset_fixture();$GLOBALS['mutation']=static function(){\RoxySocial\Store::$row['media_url']='https://fixture.test/new.jpg';};\RoxySocial\AI::generate_text(1,'fixture-20261009');
    check($GLOBALS['retries']===1&&$GLOBALS['approvals']===0,'media-first completion schedules a fresh AI snapshot instead of dropping pending work');
    foreach([['status'=>'needs_review'],['ai_status'=>'ready'],['campaign_key'=>'different']] as $override){reset_fixture();\RoxySocial\Store::$row=array_merge(\RoxySocial\Store::$row,$override);\RoxySocial\AI::generate_text(1,'fixture-20261009');check($GLOBALS['calls']===0&&$GLOBALS['approvals']===0,'reviewed/completed/wrong-campaign worker never calls AI');}
}
