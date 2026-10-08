<?php
define('ABSPATH',__DIR__);
function wp_salt($scheme){return $GLOBALS['salt']??'grosses-isolated-salt';}
function wp_timezone_string(){return 'America/Los_Angeles';}
function wp_date($format,...$args){return $format==='Y' ? '2026' : '2026-10-05';}
function get_option($key,$default=false){return $key==='roxy_grosses_settings' ? ($GLOBALS['saved']??$default) : $default;}
function sanitize_text_field($v){return trim($v);}function sanitize_email($v){return $v;}function sanitize_textarea_field($v){return $v;}
function wc_format_decimal($v){return $v;}function wp_parse_args($a,$b){return array_merge($b,$a);}
function add_settings_error(...$args){$GLOBALS['settings_error']=true;}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
eval('namespace RoxyGrosses; class Scheduler {static function sync_schedule($settings){$GLOBALS["schedule_changed"]=true;}}');
$root=$argv[1]??dirname(__DIR__);require $root.'/includes/modules/grosses/includes/class-roxy-grosses-settings.php';
$encrypt=new ReflectionMethod(\RoxyGrosses\Settings::class,'encrypt_secret');$encrypt->setAccessible(true);
$decrypt=new ReflectionMethod(\RoxyGrosses\Settings::class,'decrypt_secret');$decrypt->setAccessible(true);
$GLOBALS['saved']=['square_access_token'=>'existing-fixture','square_webhook_signature_key'=>'webhook-fixture','schedule_time'=>'23:00'];
if(!function_exists('openssl_encrypt')){
  $result=\RoxyGrosses\Settings::sanitize(['square_access_token'=>'replacement']);
  check($result===$GLOBALS['saved']&&!isset($GLOBALS['schedule_changed'])&&!empty($GLOBALS['settings_error']),'crypto outage preserves entire settings, token and schedule');exit;
}
$cipher=$encrypt->invoke(null,'fixture-token');check(str_starts_with($cipher,'gcm:v2:')&&$decrypt->invoke(null,$cipher)==='fixture-token','versioned authenticated token round trip');
$raw=base64_decode(substr($cipher,7));$raw[12]=chr(ord($raw[12])^1);check($decrypt->invoke(null,'gcm:v2:'.base64_encode($raw))==='','corrupt tag cannot return a token');
$GLOBALS['salt']='rotated-fixture';check($decrypt->invoke(null,$cipher)==='','salt rotation fails closed');unset($GLOBALS['salt']);
check($decrypt->invoke(null,'gcm:v99:invalid')==='','unknown encrypted format rejected');
$iv=random_bytes(12);$tag='';$legacy=openssl_encrypt('legacy-gcm-fixture','aes-256-gcm',hash('sha256','roxy-grosses|roxy-grosses',true),OPENSSL_RAW_DATA,$iv,$tag);check($decrypt->invoke(null,'gcm:'.base64_encode($iv.$tag.$legacy))==='legacy-gcm-fixture','legacy authenticated envelope remains readable');
$result=\RoxyGrosses\Settings::sanitize(['square_access_token'=>'']);check(str_starts_with($result['square_access_token'],'gcm:v2:')&&$decrypt->invoke(null,$result['square_access_token'])==='existing-fixture','blank token save migrates old plaintext rather than replacing credential');
check(str_starts_with($result['square_webhook_signature_key'],'gcm:v2:')&&$decrypt->invoke(null,$result['square_webhook_signature_key'])==='webhook-fixture','webhook signature key is encrypted on settings save');
$GLOBALS['saved']['square_access_token']=$cipher;$result=\RoxyGrosses\Settings::sanitize(['square_access_token'=>'']);check($result['square_access_token']===$cipher,'blank save preserves existing encrypted value byte-for-byte');
eval('namespace RoxyGrosses; class Store {static function dashboard_snapshot(...$args){throw new \\RuntimeException("private database diagnostic");}}');
ob_start(); \RoxyGrosses\Settings::render_dashboard_panel(); echo 'Other admin content remains available'; $html=ob_get_clean();
check(str_contains($html,'Grosses dashboard data is unavailable') && str_contains($html,'Other admin content remains available'),'analytics read failure renders a notice and returns without breaking other admin content');
check(!str_contains($html,'Movies Ticket Gross') && !str_contains($html,'private database diagnostic'),'failed analytics renders no incomplete totals or internal database diagnostic');
