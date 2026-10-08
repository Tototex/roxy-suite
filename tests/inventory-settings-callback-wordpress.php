<?php
/** Installed Settings API fixture: random private option only; no schedule/mail. */
if (!defined('WP_CLI') || !WP_CLI) exit;
$path=$args[0]??''; if(!is_file($path))throw new RuntimeException('Candidate Settings required.');
global $wpdb;
$key='roxy_fixture_settings50_'.bin2hex(random_bytes(6));
$namespace='InventorySettings50_'.bin2hex(random_bytes(4));
if(get_option($key,null)!==null)throw new RuntimeException('Private option already exists.');
$before=[get_option('roxy_inventory_settings'),get_option('cron')];
$code=file_get_contents($path);
$code=str_replace(['namespace RoxyInventory;',"'roxy_inventory_settings'"],['namespace '.$namespace.';',var_export($key,true)],$code);
eval('?>'.$code); $settings=$namespace.'\\Settings';
$checks=0; $assert=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS: '.$label.PHP_EOL;};
$block=static function($sql)use($key){if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i',$sql)&&strpos($sql,$key)===false)throw new RuntimeException('Fixture attempted non-private SQL mutation.');return $sql;};
$mail=static function(){throw new RuntimeException('Fixture attempted mail.');};
$http=static function(){throw new RuntimeException('Fixture attempted provider call.');};
add_filter('query',$block,PHP_INT_MAX);
add_filter('pre_wp_mail',$mail,PHP_INT_MAX,2);
add_filter('pre_http_request',$http,PHP_INT_MAX,3);
try {
    $saved=['jason_email'=>'old@example.invalid','timezone'=>'America/New_York','schedule_time'=>'21:15','schedule_enabled'=>'1','direct_vendor_sending_enabled'=>'0','admin_alert_email'=>'alerts@example.invalid','tripp_order_instructions'=>'Old Tripp','odom_order_instructions'=>'Old Odom'];
    $assert(add_option($key,$saved),'private baseline option created');
    $settings::register();
    foreach([[],['jason_email'=>['bad']],array_replace($saved,['timezone'=>'bad']),array_diff_key($saved,['odom_order_instructions'=>true])]as$input){
        $assert(update_option($key,$input)===false&&get_option($key)===$saved,'actual registered callback rejects input with unchanged option');
    }
    $valid=array_replace($saved,['schedule_time'=>'22:45','jason_email'=>'manager@example.invalid']);
    $normalized=$settings::sanitize($valid);
    $assert(update_option($key,$valid)&&get_option($key)===$normalized,'actual registered callback accepts complete normalized option');
    $assert(update_option($key,$valid)===false&&get_option($key)===$normalized,'actual repeated valid save is unchanged');
    $off=$valid;unset($off['schedule_enabled'],$off['direct_vendor_sending_enabled']);
    $assert(update_option($key,$off)&&get_option($key)['schedule_enabled']==='0'&&get_option($key)['direct_vendor_sending_enabled']==='0','actual unchecked boxes turn off');
    $assert([get_option('roxy_inventory_settings'),get_option('cron')]===$before,'production settings and schedule unchanged');
} finally {
    remove_filter('sanitize_option_'.$key,[$settings,'sanitize_registered']);
    delete_option($key);
    remove_filter('query',$block,PHP_INT_MAX);
    remove_filter('pre_wp_mail',$mail,PHP_INT_MAX);
    remove_filter('pre_http_request',$http,PHP_INT_MAX);
    if(get_option($key,null)!==null)throw new RuntimeException('Private option cleanup failed.');
}
echo 'Passed '.$checks.' installed registered-settings checks; private option removed.'.PHP_EOL;
