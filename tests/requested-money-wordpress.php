<?php
// wp eval-file [candidate-root]. Private draft/meta fixture; no backing or charge.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
$root=$args[0]??dirname(__DIR__);
$namespace='RequestedMoneyFixture'.bin2hex(random_bytes(4));
$option_store_key=$namespace.'_options';
$GLOBALS[$option_store_key]=[];
$option_functions='namespace '.$namespace.'; function get_option($key,$default=false) { $storeKey='.var_export($option_store_key,true).'; return array_key_exists($key,$GLOBALS[$storeKey]) ? $GLOBALS[$storeKey][$key] : $default; } function update_option($key,$value,$autoload=null) { $storeKey='.var_export($option_store_key,true).'; $GLOBALS[$storeKey][$key]=$value; return true; }';
eval($option_functions);
foreach(['settings','cpt'] as $name) {
    $source=file_get_contents($root.'/includes/modules/requested-showings/includes/class-roxy-rs-'.$name.'.php');
    eval('?>'.str_replace('namespace RoxyRS;', 'namespace '.$namespace.';', $source));
}
$cpt=$namespace.'\\CPT';
$settings=$namespace.'\\Settings';
$option_key=$settings::OPTION_KEY;
$options=&$GLOBALS[$option_store_key];
global $wpdb;
$digest=static function() use($wpdb) {
    $posts=$wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type='roxy_req_showing' ORDER BY ID",ARRAY_A);
    if($wpdb->last_error)throw new RuntimeException('Request evidence read failed.');
    $meta=$wpdb->get_results("SELECT m.* FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='roxy_req_showing' ORDER BY m.meta_id",ARRAY_A);
    if($wpdb->last_error)throw new RuntimeException('Metadata evidence read failed.');
    return hash('sha256',wp_json_encode([$posts,$meta]));
};
$before=$digest(); $id=0; $checks=0;
$check=static function($ok,$label) use(&$checks) { if(!$ok)throw new RuntimeException($label); ++$checks; echo "PASS: $label\n"; };
$check($settings::funding_goal_cents()===30000 && $settings::sponsor_amount_cents()===30000,'actual PHP Settings preserves defaults when its option is absent');
$options[$option_key]=['funding_goal_cents'=>'999999garbage'];
$check(is_wp_error($settings::funding_goal_cents()) && is_wp_error($settings::sponsor_amount_cents()) && $settings::sponsor_ticket_qty()===2,'actual PHP Settings reports corrupt currency while unrelated integer getter remains safe');
$options[$option_key]=['funding_goal_cents'=>999999];
$check(is_wp_error($settings::sponsor_amount_cents()),'actual PHP Settings does not raise missing sponsor to an explicit high goal');
$options[$option_key]=['funding_goal_cents'=>12500,'sponsor_amount_cents'=>18000];
$check($settings::funding_goal_cents()===12500 && $settings::sponsor_amount_cents()===18000,'actual PHP Settings returns valid persisted integer cents');
$options[$option_key]='malformed-option';
$check(is_wp_error($settings::funding_goal_cents()) && is_wp_error($settings::sponsor_amount_cents()),'actual PHP Settings rejects a non-array persisted option');
$options=[];
$mail=static function() { throw new RuntimeException('Money read fixture must never send email.'); };
add_filter('pre_wp_mail',$mail);
try {
    $id=wp_insert_post(['post_type'=>'roxy_req_showing','post_status'=>'draft','post_title'=>'PRIVATE MONEY UNIT FIXTURE '.bin2hex(random_bytes(6))],true);
    if(is_wp_error($id))throw new RuntimeException('Private draft creation failed.');
    foreach(['','cents_v1'] as $unit) {
        if($unit==='')delete_post_meta($id,$cpt::META_FUNDING_UNIT_VERSION);
        else update_post_meta($id,$cpt::META_FUNDING_UNIT_VERSION,$unit);
        foreach([100,500,999,1000,30000] as $amount) {
            update_post_meta($id,$cpt::META_FUNDING_GOAL,$amount);
            update_post_meta($id,$cpt::META_SPONSOR_AMOUNT,$amount);
            $check($cpt::funding_goal_cents($id)===$amount && $cpt::sponsor_amount_cents($id)===$amount,'actual WP stored cents retained exactly: '.$unit.' '.$amount);
        }
    }
    $options[$option_key]=['funding_goal_cents'=>'broken','sponsor_amount_cents'=>'broken'];
    update_post_meta($id,$cpt::META_FUNDING_UNIT_VERSION,'cents_v1');
    update_post_meta($id,$cpt::META_FUNDING_GOAL,12500);
    update_post_meta($id,$cpt::META_SPONSOR_AMOUNT,18000);
    $check($cpt::funding_goal_cents($id)===12500 && $cpt::sponsor_amount_cents($id)===18000,'actual WP explicit request amounts are independent of corrupt global defaults');
    $options=[];
    foreach(['malformed','1.23','-100','0','99','2147483648'] as $bad) {
        update_post_meta($id,$cpt::META_FUNDING_GOAL,$bad);
        $check(is_wp_error($cpt::funding_goal_cents($id)),'invalid explicit WP amount requires review: '.$bad);
    }
    update_post_meta($id,$cpt::META_FUNDING_GOAL,100);
    update_post_meta($id,$cpt::META_FUNDING_UNIT_VERSION,'unknown_unit');
    $check(is_wp_error($cpt::funding_goal_cents($id)),'unsupported WP unit requires review');
    update_post_meta($id,$cpt::META_FUNDING_UNIT_VERSION,'cents_v1');
    update_post_meta($id,$cpt::META_FUNDING_GOAL,1000);
    update_post_meta($id,$cpt::META_SPONSOR_AMOUNT,999);
    $check(is_wp_error($cpt::sponsor_amount_cents($id)),'inconsistent actual WP sponsor requires review');
    $check($cpt::parse_currency_input('21474836.47')===2147483647 && is_wp_error($cpt::parse_currency_input('21474836.48')),'actual WP parser checks signed-cent boundary exactly');
} finally {
    if($id)wp_delete_post($id,true);
    remove_filter('pre_wp_mail',$mail);
    unset($GLOBALS[$option_store_key]);
    $check($before===$digest(),'original requested records unchanged and private fixture removed');
}
echo "$checks installed WordPress money-unit checks passed.\n";
