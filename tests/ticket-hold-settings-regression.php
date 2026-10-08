<?php
define('ABSPATH',__DIR__);$root=$argv[1]??dirname(__DIR__);
function get_option($key,$default=[]){return $GLOBALS['saved']??$default;}
function wc_format_decimal($value){return (string)(float)$value;}
function sanitize_text_field($value){return trim((string)$value);}
function esc_url_raw($value){return $value;}
function wp_parse_args($saved,$defaults){return array_merge($defaults,$saved);}
function esc_attr($value){return htmlspecialchars((string)$value,ENT_QUOTES);}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;}
require $root.'/includes/modules/show-tickets/includes/class-roxy-st-settings.php';
check(\RoxyST\Settings::defaults()['ticket_hold_minutes']==='','default preserves existing Woo hold duration');
$GLOBALS['saved']=['ticket_hold_minutes'=>'30'];
check(\RoxyST\Settings::sanitize([])['ticket_hold_minutes']==='30','older settings form cannot silently reset saved ticket hold');
check(\RoxyST\Settings::sanitize(['ticket_hold_minutes'=>''])['ticket_hold_minutes']==='','explicit blank restores inheritance');
check(\RoxyST\Settings::sanitize(['ticket_hold_minutes'=>'15'])['ticket_hold_minutes']==='15','ticket override accepts whole minutes');
check(\RoxyST\Settings::sanitize(['ticket_hold_minutes'=>'0'])['ticket_hold_minutes']==='1','zero cannot disable safe ticket holds');
check(\RoxyST\Settings::sanitize(['ticket_hold_minutes'=>'999999999'])['ticket_hold_minutes']==='525600','excessive duration is bounded');
check(\RoxyST\Settings::sanitize(['general_price'=>'0'])['general_price']==='0','intentional zero ticket pricing remains intact');
ob_start();\RoxyST\Settings::render_field(['key'=>'ticket_hold_minutes']);$html=ob_get_clean();
check(str_contains($html,'step="1"')&&str_contains($html,'min="1"')&&str_contains($html,'roxy_st_settings[ticket_hold_minutes]'),'hold settings render integer field and correct option key');
