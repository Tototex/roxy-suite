<?php
require __DIR__ . '/social-film-context-regression.php';
function sanitize_text_field($v) { return trim(strip_tags($v)); }
function sanitize_textarea_field($v) { return trim(strip_tags($v)); }
function wp_parse_url($v,$part) { return parse_url($v,$part); }
$reference = ['title'=>'Street Fighter', 'release_year'=>2026, 'genre'=>'Action', 'synopsis'=>str_repeat('Two fighters enter a tournament. ',4), 'source_url'=>'https://example.test/movie'];
$result = RoxySocial\AI::normalize_references([$reference, ['title'=>'','release_year'=>'','genre'=>'','synopsis'=>'','source_url'=>'']]);
check(count($result)===1 && $result['streetfighter']['release_year']===2026);
foreach (['release_year'=>'2026junk','synopsis'=>'Too short','source_url'=>'javascript:alert(1)','title'=>['not'=>'scalar']] as $field=>$value) {
    $bad=$reference; $bad[$field]=$value; $rejected=false;
    try { RoxySocial\AI::normalize_references([$bad]); } catch (InvalidArgumentException $e) { $rejected=true; }
    check($rejected);
}
$rejected=false;
try { RoxySocial\AI::normalize_references([$reference,$reference]); } catch (InvalidArgumentException $e) { $rejected=true; }
check($rejected);
$fixture_options['roxy_social_film_references']=$result;
$fixture_posts[1]=(object)['post_excerpt'=>'','post_content'=>''];
check(str_contains($method->invoke(null,['showing_ids'=>'1'],'Streetfighter'),'Two fighters enter'));
$fixture_posts[1]->post_excerpt=str_repeat('A manager supplied a different confirmed synopsis. ',3);
check(str_contains($method->invoke(null,['showing_ids'=>'1'],'Streetfighter'),'different confirmed synopsis'));
echo "8 film-reference editing checks passed\n";
