<?php
// Disposable option row only; never modifies real report settings/token.
if(!defined('WP_CLI')||!WP_CLI)exit;
global $wpdb;
$stage=$args[0]??'';$key='roxy_stability_secret_fixture_'.bin2hex(random_bytes(6));
$code=preg_replace('/^<\?php\s*/','',file_get_contents($stage.'/includes/modules/grosses/includes/class-roxy-grosses-settings.php'),1);
$code=str_replace('class Settings','class FixtureSettings',$code);
$code=str_replace("public const OPTION_KEY='roxy_grosses_settings';","public const OPTION_KEY=".var_export($key,true).';',$code);
eval($code);
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS_MYSQL: $label\n";};
$actual=get_option('roxy_grosses_settings',[]);$before=hash('sha256',wp_json_encode($actual));
$saved=['square_access_token'=>'plaintext-fixture-only','schedule_time'=>'23:00','recipient_emails'=>'fixture@example.test'];
$filter=null;
try{
    $check(add_option($key,$saved,'',false),'disposable plaintext option fixture created');
    $check(\RoxyGrosses\FixtureSettings::migrate_legacy_token(),'actual compare-and-swap migration succeeds');
    $after=get_option($key);$check(str_starts_with($after['square_access_token'],'gcm:v2:')&&\RoxyGrosses\FixtureSettings::get('square_access_token')==='plaintext-fixture-only','persisted ciphertext decodes to original fixture token');
    unset($after['square_access_token']);$other=$saved;unset($other['square_access_token']);$check($after===$other,'all other settings preserved');
    $hash=hash('sha256',wp_json_encode(get_option($key)));$check(\RoxyGrosses\FixtureSettings::migrate_legacy_token()&&$hash===hash('sha256',wp_json_encode(get_option($key))),'repeat migration leaves encrypted value byte-for-byte unchanged');
    $new=$saved;$new['schedule_time']='22:00';update_option($key,$new,false);
    $filter=static function()use($saved){return $saved;};add_filter('pre_option_'.$key,$filter);
    $check(!\RoxyGrosses\FixtureSettings::migrate_legacy_token(),'stale settings snapshot rejected by actual SQL predicate');
    remove_filter('pre_option_'.$key,$filter);$filter=null;
    $check(get_option($key)===$new,'concurrent settings edit not overwritten');
}finally{
    if($filter)remove_filter('pre_option_'.$key,$filter);delete_option($key);
}
$check($before===hash('sha256',wp_json_encode(get_option('roxy_grosses_settings',[]))),'real Grosses settings and token unchanged by fixture');
