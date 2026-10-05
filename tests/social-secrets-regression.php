<?php
// Isolated credential fixtures only. Never loads real WordPress options/salts.
define('ABSPATH',__DIR__);
function wp_salt($scheme){return $GLOBALS['salt']??'isolated-test-salt';}
function sanitize_text_field($value){return $value;}function wp_unslash($value){return $value;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['writes'][]=$key;$GLOBALS['options'][$key]=$value;return true;}
function roxy_suite_user_can_access_admin(){return true;}function check_admin_referer(...$args){}
function wp_die($message){throw new RuntimeException($message);}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$root=$argv[1]??dirname(__DIR__);
require $root.'/includes/modules/social-publisher/includes/class-roxy-social-secrets.php';
require $root.'/includes/modules/social-publisher/includes/class-roxy-social-hangar.php';
require $root.'/includes/modules/social-publisher/includes/class-roxy-social-meta.php';
use RoxySocial\Secrets;use RoxySocial\Hangar;use RoxySocial\Meta;
$GLOBALS['options']=['roxy_social_hangar_user'=>'existing','roxy_social_hangar_pass'=>'existing-encrypted-fixture'];$GLOBALS['writes']=[];
if(!function_exists('openssl_encrypt')){
    check(!Hangar::save_credentials('replacement','fixture-secret')&&!$GLOBALS['writes'],'unavailable encryption preserves Hangar user and password');
    $_POST=['meta_app_id'=>'replacement','meta_access_token'=>'fixture-token'];
    try{Meta::save_settings();throw new LogicException('Expected encryption failure');}catch(RuntimeException $error){check(str_contains($error->getMessage(),'encryption failed')&&!$GLOBALS['writes'],'Meta encrypts before writes and preserves all settings on crypto outage');}
    exit;
}
$one=Secrets::encrypt('fixture-secret');$two=Secrets::encrypt('fixture-secret');
check(str_starts_with($one,'roxy:v2:')&&Secrets::decrypt($one)==='fixture-secret','authenticated versioned encryption round trip');
check($one!==$two,'random nonce makes repeated plaintext ciphertext distinct');
$raw=base64_decode(substr($one,8),true);$raw[12]=chr(ord($raw[12])^1);
check(Secrets::decrypt('roxy:v2:'.base64_encode($raw))==='','tampered authentication tag fails closed');
$raw=base64_decode(substr($one,8),true);$raw[strlen($raw)-1]=chr(ord($raw[strlen($raw)-1])^1);
check(Secrets::decrypt('roxy:v2:'.base64_encode($raw))==='','tampered ciphertext fails closed');
$GLOBALS['salt']='wrong-fixture-salt';check(Secrets::decrypt($one)==='','wrong key fails closed');unset($GLOBALS['salt']);
foreach(['roxy:v3:invalid','roxy:v2:invalid','%%%%',base64_encode('short')] as $invalid)check(Secrets::decrypt($invalid)==='','malformed/unknown envelope rejected');
$iv=random_bytes(16);$legacy=base64_encode($iv.openssl_encrypt('legacy-fixture','aes-256-cbc',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,$iv));
check(Secrets::decrypt($legacy)==='legacy-fixture','existing CBC credentials remain readable without rewriting');
check(!Hangar::save_credentials('replacement','')&&!$GLOBALS['writes'],'empty encryption cannot clear existing Hangar password');
check(Hangar::save_credentials('fixture-user','new-fixture-secret')&&str_starts_with(get_option('roxy_social_hangar_pass'),'roxy:v2:'),'Hangar successful new save uses authenticated format');
check(Secrets::decrypt(get_option('roxy_social_hangar_pass'))==='new-fixture-secret','saved fixture password remains readable');
$GLOBALS['options']['roxy_social_meta_access_token']=$one;check(!Meta::credentials_unreadable(),'healthy saved credential is not flagged');
$GLOBALS['salt']='rotated-fixture-salt';check(Meta::credentials_unreadable()&&!Hangar::has_credentials(),'salt rotation exposes reconnect requirement without clearing saved values');
