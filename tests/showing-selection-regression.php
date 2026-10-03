<?php
namespace RoxyST { class Settings {} }
namespace {
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
function check($ok,$label) { if (!$ok) throw new \RuntimeException($label); echo "PASS: $label\n"; }
function wp_timezone() { return new \DateTimeZone('America/Los_Angeles'); }
function wp_date($format,$timestamp=null) { return '2026-10-02'; }
function current_time($format) { return strtotime('2026-10-02 18:00'); }
function date_i18n($format,$ts) { return date($format,$ts); }
function get_the_title($id) { return 'Showing ' . $id; }
function get_post_meta($id,$key,$single=true) { return $key === '_roxy_start' ? ($id<=500 ? '2026-01-01 19:30' : '2026-10-02 19:30') : ''; }
function add_action(...$args) {}
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function selected($a,$b,$echo=false) { return (int)$a===(int)$b?'selected':''; }
function esc_attr($value) { return htmlspecialchars((string)$value); }
function esc_html($value) { return htmlspecialchars((string)$value); }
function get_posts($args) {
    $GLOBALS['last_query'] = $args;
    $ids = range(1,503);
    foreach ($args['meta_query'] ?? [] as $clause) {
        if (($clause['compare'] ?? '') === '>=') $ids = array_values(array_filter($ids,fn($id)=>get_post_meta($id,'_roxy_start',true)>=$clause['value']));
    }
    if (($args['order']??'') === 'DESC') $ids = array_reverse($ids);
    return array_map(fn($id)=>(object)['ID'=>$id],array_slice($ids,0,$args['numberposts']));
}
$root = $argv[1] ?? dirname(__DIR__);
require $root . '/includes/modules/show-tickets/includes/class-roxy-st-cpt.php';
require $root . '/includes/modules/show-tickets/includes/class-roxy-st-frontend.php';
require $root . '/includes/modules/show-tickets/includes/class-roxy-st-tickets.php';
require $root . '/includes/modules/will-call/roxy-will-call.php';
$shows = \RoxyST\Tickets::get_door_mode_showings();
check(array_column($shows,'id') === [501,502,503], 'T12: current shows survive 500 historical entries before limit');
check(\RoxyST\Tickets::get_default_door_mode_showing_id() === 501, 'T12: default selection queries relevant shows first');
$html = roxy_will_call_showing_dropdown(0,false);
check(strpos($html,'value="501"')!==false && strpos($html,'value="1"')===false, 'T12: will-call current shows not hidden by archive limit');
$html = roxy_will_call_showing_dropdown(0,true);
check(strpos($html,'value="503"')!==false && $GLOBALS['last_query']['order']==='DESC', 'T12: archive selector starts with recent history');
date_default_timezone_set('UTC');
$schema = new \ReflectionMethod(\RoxyST\Frontend::class,'schema_start_date'); $schema->setAccessible(true);
check($schema->invoke(null,'2026-10-03T19:30') === '2026-10-03T19:30:00-07:00', 'T16: local evening not converted as UTC');
check($schema->invoke(null,'2026-11-07T19:30') === '2026-11-07T19:30:00-08:00', 'T16: local timezone reflects DST');
check($schema->invoke(null,'not a date') === '', 'T16: malformed schema date safely omitted');
$weekend = new \ReflectionMethod(\RoxyST\CPT::class,'weekend_anchor_from_start'); $weekend->setAccessible(true);
check($weekend->invoke(null,'2026-10-02T01:00')->format('Y-m-d') === '2026-10-02', 'T16: early Friday remains Friday, not UTC Thursday');
}
