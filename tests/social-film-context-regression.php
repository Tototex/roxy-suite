<?php
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
$fixture_posts = []; $fixture_response = []; $fixture_cache = [];
$fixture_options = [];
function get_option($key,$default=false) { global $fixture_options; return $fixture_options[$key] ?? ($key==='roxy_social_ai_enabled' ? true : $default); }
function absint($v) { return abs((int)$v); }
function get_post($id) { global $fixture_posts; return $fixture_posts[$id] ?? null; }
function wp_strip_all_tags($v) { return strip_tags($v); }
function strip_shortcodes($v) { return $v; }
function get_transient($k) { global $fixture_cache; return $fixture_cache[$k] ?? false; }
function set_transient($k,$v,$ttl) { global $fixture_cache; $fixture_cache[$k]=$v; }
function add_query_arg($args,$url) { return $url . '?' . http_build_query($args); }
function wp_remote_get($url,$args) { global $fixture_response; return $fixture_response; }
function is_wp_error($r) { return false; }
function wp_remote_retrieve_response_code($r) { return $r['code'] ?? 200; }
function wp_remote_retrieve_body($r) { return json_encode($r['body'] ?? []); }
function esc_url_raw($v) { return $v; }
function wp_json_encode($v) { return json_encode($v); }
function remove_accents($v) { return $v; }
function get_post_meta($id,$key,$single) { return [1=>'2026-10-09T14:30',2=>'2026-10-10T19:30',3=>'2026-10-11T14:30'][$id] ?? ''; }
function wp_timezone() { return new DateTimeZone('America/Los_Angeles'); }
function wp_date($format,$stamp,$zone) { return (new DateTimeImmutable('@'.$stamp))->setTimezone($zone)->format($format); }
require $argv[1] . '/class-roxy-social-ai.php';
require $argv[1] . '/class-roxy-social-campaigns.php';
$method = new ReflectionMethod(RoxySocial\AI::class, 'film_context');
$checks = 0;
function check($ok) { global $checks; if (!$ok) throw new RuntimeException('Check failed ' . ($checks+1)); $checks++; }
$draft = ['showing_ids'=>'1'];
check($method->invoke(null,$draft,'Unknown') === '');
$fixture_posts[1]=(object)['post_excerpt'=>str_repeat('A supplied film synopsis. ',5),'post_content'=>''];
check(strpos($method->invoke(null,$draft,'Unknown'),'supplied film synopsis') !== false);
$fixture_posts=[];
$page=['title'=>'Heart of the Beast (film)','extract'=>str_repeat('This film has a sourced synopsis. ',5),'fullurl'=>'https://en.wikipedia.org/wiki/Heart_of_the_Beast'];
$fixture_response=['body'=>['query'=>['pages'=>[1=>$page]]]];
check(strpos($method->invoke(null,$draft,'Heart of the Beast'),'sourced synopsis') !== false);
$fixture_cache=[];
$fixture_response=['body'=>['query'=>['pages'=>[1=>$page,2=>$page]]]];
check($method->invoke(null,$draft,'Heart of the Beast') === '');
$fixture_options['roxy_social_film_references'] = ['streetfighter' => ['title' => 'Street Fighter', 'release_year' => 2026, 'synopsis' => str_repeat('Two fighters enter a martial arts tournament. ', 3), 'source_url' => 'https://example.test/street-fighter']];
check(str_contains($method->invoke(null,$draft,'Streetfighter'),'martial arts tournament'));
$fixture_options = [];
$fixture_cache=[]; $page['title']='Street Fighter (2026 film)';
$fixture_response=['body'=>['query'=>['pages'=>[1=>$page]]]];
check(str_contains($method->invoke(null,$draft,'Streetfighter'),'sourced synopsis'));
$fixture_cache=[]; $novel=$page; $novel['title']='Street Fighter (novel)';
$fixture_response=['body'=>['query'=>['pages'=>[1=>$novel]]]];
check($method->invoke(null,$draft,'Streetfighter') === '');
$page['title']='Unrelated Movie'; $fixture_response=['body'=>['query'=>['pages'=>[1=>$page]]]];
check($method->invoke(null,$draft,'Heart of the Beast') === '');
check(!RoxySocial\Campaigns::asset_matches_title(['filename'=>'Universal_v4.1_Six_Theyre-a-10_4x5.mp4'],'Heart of the Beast'));
check(RoxySocial\Campaigns::asset_matches_title(['filename'=>'HOTB_Military_Final.mp4'],'Heart of the Beast'));
check(RoxySocial\Campaigns::asset_matches_title(['filename'=>'HOB_Nature.mp4'],'Heart of the Beast'));
check(RoxySocial\Campaigns::asset_matches_title(['filename'=>'HeartoftheBeast_V1.mp4'],'Heart of the Beast'));
check(!RoxySocial\Campaigns::asset_matches_title(['filename'=>'OTHER_HOTBLAH.mp4'],'Heart of the Beast'));
foreach ([1,2,3] as $id) $fixture_posts[$id]=(object)['post_type'=>'roxy_showing','post_status'=>'publish','post_title'=>'Heart of the Beast'];
$schedule=['campaign_key'=>'heart-of-the-beast-20261009','showing_ids'=>'1,2,3','scheduled_for'=>'2026-10-07 14:00:00','post_text'=>"Fri, Oct 9 at 2:30 PM\nSat, Oct 10 at 7:30 PM\nSun, Oct 11 at 2:30 PM"];
check(RoxySocial\Campaigns::verified_caption_schedule($schedule));
$schedule['post_text']="\xF0\x9F\x8E\xAC " . $schedule['post_text'];
check(RoxySocial\Campaigns::verified_caption_schedule($schedule));
$schedule['post_text']=str_replace('7:30 PM','6:30 PM',$schedule['post_text']);
check(!RoxySocial\Campaigns::verified_caption_schedule($schedule));
$schedule['post_text']="Friday - 2:30 PM\nSaturday - 7:30 PM\nSunday - 2:30 PM";
check(!RoxySocial\Campaigns::verified_caption_schedule($schedule));
echo $checks, " film context, schedule and asset checks passed\n";
