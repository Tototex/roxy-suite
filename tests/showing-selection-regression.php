<?php
namespace RoxyST { class Settings {} }
namespace {
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
function check($ok,$label) { if (!$ok) throw new \RuntimeException($label); echo "PASS: $label\n"; }
function wp_timezone() { return new \DateTimeZone('America/Los_Angeles'); }
function current_datetime() { return new \DateTimeImmutable('2026-10-02 12:00', wp_timezone()); }
function wp_date($format,$timestamp=null) { return '2026-10-02'; }
function current_time($format) { return strtotime('2026-10-02 18:00'); }
function date_i18n($format,$ts) { return date($format,$ts); }
function get_the_title($id) { return 'Showing ' . $id; }
function get_post_meta($id,$key,$single=true) {
    if ($key !== '_roxy_start') return '';
    if ($id > 500) return '2026-10-02 19:30';
    return (new \DateTimeImmutable('2026-09-30 23:59', wp_timezone()))->modify('-' . max(0, $id - 1) . ' minutes')->format('Y-m-d H:i:s');
}
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
        if (($clause['compare'] ?? '') === 'EXISTS') $ids = array_values(array_filter($ids,fn($id)=>get_post_meta($id,$clause['key']??'',true)!==''));
    }
    $sort = is_array($args['orderby'] ?? null) ? $args['orderby'] : ['meta_value' => ($args['order'] ?? 'ASC')];
    usort($ids, function($left,$right) use ($args,$sort) {
        $left_date=get_post_meta($left,$args['meta_key']??'',true); $right_date=get_post_meta($right,$args['meta_key']??'',true);
        $date_order=strcmp($left_date,$right_date);
        if (($sort['meta_value']??'ASC')==='DESC') $date_order=-$date_order;
        if ($date_order!==0) return $date_order;
        $id_order=$left<=>$right;
        return ($sort['ID']??'ASC')==='DESC' ? -$id_order : $id_order;
    });
    return array_map(fn($id)=>(object)['ID'=>$id],array_slice($ids,$args['offset']??0,$args['numberposts']));
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
check(\roxy_will_call_archive_page_from_request(['archive_page'=>'1','archive_page_next'=>'1'],true)===2, 'T12: older navigation advances one archive page');
check(\roxy_will_call_archive_page_from_request(['archive_page'=>'2','archive_page_prev'=>'1'],true)===1, 'T12: newer navigation retreats one archive page');
check(\roxy_will_call_archive_page_from_request(['archive_page'=>['999']],true)===0, 'T12: malformed page arrays reset safely');
check(\roxy_will_call_archive_page_from_request(['archive_page'=>'1000','archive_page_next'=>'1'],true)===1000, 'T12: navigation is bounded at the configured page maximum');
$html = roxy_will_call_showing_dropdown(0,true);
check(strpos($html,'value="503"')!==false && strpos($html,'value="1"')!==false && $GLOBALS['last_query']['order']==='DESC' && $GLOBALS['last_query']['orderby']['ID']==='DESC' && $GLOBALS['last_query']['numberposts']===201 && $GLOBALS['last_query']['offset']===0, 'T12: first archive page follows date/ID order and checks one extra row');
check(strpos($html,'Older showings')!==false && strpos($html,'Newer showings')===false, 'T12: first archive page offers only an older-page control');
$html = roxy_will_call_showing_dropdown(0,true,1);
check(strpos($html,'value="198"')!==false && $GLOBALS['last_query']['offset']===200, 'T12: second archive page follows date order rather than fixture ID order');
check(strpos($html,'Older showings')!==false && strpos($html,'Newer showings')!==false, 'T12: middle archive page offers both navigation directions');
$html = roxy_will_call_showing_dropdown(0,true,2);
check(strpos($html,'value="398"')!==false && $GLOBALS['last_query']['offset']===400, 'T12: final archive page returns remaining older showings');
check(strpos($html,'Older showings')===false && strpos($html,'Newer showings')!==false, 'T12: final archive page does not offer an empty next page');
date_default_timezone_set('UTC');
$schema = new \ReflectionMethod(\RoxyST\Frontend::class,'schema_start_date'); $schema->setAccessible(true);
check($schema->invoke(null,'2026-10-03T19:30') === '2026-10-03T19:30:00-07:00', 'T16: local evening not converted as UTC');
check($schema->invoke(null,'2026-11-07T19:30') === '2026-11-07T19:30:00-08:00', 'T16: local timezone reflects DST');
check($schema->invoke(null,'not a date') === '', 'T16: malformed schema date safely omitted');
$weekend = new \ReflectionMethod(\RoxyST\CPT::class,'weekend_anchor_from_start'); $weekend->setAccessible(true);
check($weekend->invoke(null,'2026-10-02T01:00')->format('Y-m-d') === '2026-10-02', 'T16: early Friday remains Friday, not UTC Thursday');
}
