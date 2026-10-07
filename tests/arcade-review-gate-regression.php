<?php
// Synthetic monthly worker only. No real WP, cron, mail, scores or subscriptions.
define('ABSPATH',__DIR__); define('ARRAY_A','ARRAY_A');
function add_action(...$args) {}
function wp_timezone() { return new DateTimeZone('America/Los_Angeles'); }
function wp_date($format,$timestamp=null,$timezone=null) { return (new DateTimeImmutable('now',$timezone??wp_timezone()))->format($format); }
function wp_next_scheduled($hook) { return false; }
function wp_schedule_single_event(...$args) { $GLOBALS['scheduled'][]=$args; return true; }
function get_option($key,$default=false) { return $GLOBALS['options'][$key]??$default; }
function update_option($key,$value,...$args) { $GLOBALS['options'][$key]=$value; return true; }
function get_user_by(...$args) { return (object)['ID'=>9,'display_name'=>'Fixture Player','user_login'=>'fixture']; }
function get_user_meta(...$args) { return ''; }
function sanitize_email($value) { return $value; }
function is_user_logged_in() { return false; }
function esc_url($value) { return $value; }
function esc_html($value) { return $value; }
function wp_login_url($value) { return $value; }
function get_permalink() { return 'https://example.test/arcade/'; }
function wp_mail(...$args) { $GLOBALS['mail'][]=$args; return true; }
function wcs_create_subscription(...$args) { throw new RuntimeException('Monthly worker must never create a subscription.'); }
final class ReviewGateDatabase {
    public string $prefix='fixture_';
    public function get_results(...$args) { return $GLOBALS['leaders']; }
    public function query(...$args) { throw new RuntimeException('Monthly worker must not claim or award a prize.'); }
}
$GLOBALS['wpdb']=new ReviewGateDatabase;
require ($argv[1]??dirname(__DIR__)).'/includes/modules/arcade/roxy-arcade.php';
$checks=0;
$check=static function($ok,$label) use (&$checks) { if(!$ok)throw new RuntimeException($label); ++$checks; echo "PASS: $label\n"; };
foreach ([0,1] as $legacy_auto) {
    $GLOBALS['options']=['roxy_arcade_rewards_enabled'=>1,'roxy_arcade_auto_fulfill_rewards'=>$legacy_auto,'admin_email'=>'fixture@example.test'];
    $GLOBALS['mail']=[]; $GLOBALS['leaders']=[['user_id'=>9,'total'=>99999999]];
    Roxy_Arcade::award_monthly_winner_snapshot();
    $check(($GLOBALS['options']['roxy_arcade_last_review_candidate']['user_id']??0)===9,'worker queues review with legacy auto option '.$legacy_auto);
    $check(!isset($GLOBALS['options']['roxy_arcade_last_awarded_month']),'worker does not mark unverified score awarded');
    $check(count($GLOBALS['mail'])===1 && str_contains($GLOBALS['mail'][0][2],'not verified gameplay'),'review email explains unverified scores');
}
$GLOBALS['options']['roxy_arcade_rewards_enabled']=0; $GLOBALS['mail']=[];
Roxy_Arcade::award_monthly_winner_snapshot();
$check(!$GLOBALS['mail'],'disabled rewards remain silent');
$GLOBALS['options']['roxy_arcade_rewards_enabled']=1;
$GLOBALS['options']['roxy_arcade_last_awarded_month']=wp_date('Y-m');
Roxy_Arcade::award_monthly_winner_snapshot();
$check(!$GLOBALS['mail'],'already awarded month queues no new review');
unset($GLOBALS['options']['roxy_arcade_last_awarded_month']); $GLOBALS['leaders']=[];
Roxy_Arcade::award_monthly_winner_snapshot();
$check(!$GLOBALS['mail'],'empty leaderboard queues no review');
$check(str_contains(Roxy_Arcade::render_shortcode(),'does not guarantee a prize'),'enabled public prize description requires review');
$GLOBALS['options']['roxy_arcade_rewards_enabled']=0;
$check(str_contains(Roxy_Arcade::render_shortcode(),'Prize awards are currently disabled'),'disabled public prize description makes no prize promise');
echo "$checks Arcade review-gate checks passed.\n";
