<?php
// Actual option CAS against a disposable fixture key; real credentials untouched.
if(!defined('WP_CLI')||!WP_CLI)exit;
$stage=$args[0]??'';$key='roxy_stability_social_secret_'.bin2hex(random_bytes(6));
$code=preg_replace('/^<\?php\s*/','',file_get_contents($stage.'/includes/modules/social-publisher/includes/class-roxy-social-secrets.php'),1);
$code=str_replace('final class Secrets','final class FixtureSecrets',$code);
$code=preg_replace('/private const OPTIONS=\[[^;]+;/','private const OPTIONS=['.var_export($key,true).'];',$code);
eval($code);
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS_MYSQL: $label\n";};
$iv=random_bytes(16);$legacy=base64_encode($iv.openssl_encrypt('fixture-social-only','aes-256-cbc',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,$iv));
$filter=null;
try{
    $check(add_option($key,$legacy,'',false),'disposable legacy CBC fixture created');
    $check(\RoxySocial\FixtureSecrets::migrate_legacy_options(),'legacy migration succeeds with real option SQL');
    $new=get_option($key);$check(str_starts_with($new,'roxy:v2:')&&\RoxySocial\FixtureSecrets::decrypt($new)==='fixture-social-only','new ciphertext preserves fixture credential');
    $check(\RoxySocial\FixtureSecrets::migrate_legacy_options()&&get_option($key)===$new,'repeat migration is byte-for-byte no-op');
    update_option($key,'corrupted-fixture',false);$check(!\RoxySocial\FixtureSecrets::migrate_legacy_options()&&get_option($key)==='corrupted-fixture','unreadable legacy data preserved, not overwritten');
    update_option($key,$new,false);$filter=static function()use($legacy){return $legacy;};add_filter('pre_option_'.$key,$filter);
    $check(!\RoxySocial\FixtureSecrets::migrate_legacy_options(),'stale credential snapshot rejected');
    remove_filter('pre_option_'.$key,$filter);$filter=null;$check(get_option($key)===$new,'concurrent credential replacement preserved');
}finally{if($filter)remove_filter('pre_option_'.$key,$filter);delete_option($key);}
