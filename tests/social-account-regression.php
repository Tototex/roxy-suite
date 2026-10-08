<?php
// Real Meta selection/verification methods with isolated HTTP/options fixtures.
define('ABSPATH',__DIR__);
function wp_salt($scheme){return 'account-fixture-salt';}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['writes'][]=$key;$GLOBALS['options'][$key]=$value;return true;}
function sanitize_text_field($v){return $v;}function roxy_suite_user_can_access_admin(){return true;}function check_admin_referer(...$args){}
function add_query_arg($query,$url){return $url.'?'.http_build_query($query);}
function admin_url($path){return 'https://fixture.test/wp-admin/'.$path;}
function wp_remote_get($url,$args){$GLOBALS['requests'][]=[$url,$args];return array_shift($GLOBALS['responses'])??['status'=>500,'body'=>'{}'];}
function wp_remote_retrieve_response_code($r){return $r['status'];}function wp_remote_retrieve_body($r){return $r['body'];}function is_wp_error($r){return false;}
class RedirectResult extends RuntimeException{}
function wp_safe_redirect($url){throw new RedirectResult($url);}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$root=$argv[1]??dirname(__DIR__);require $root.'/includes/modules/social-publisher/includes/class-roxy-social-secrets.php';require $root.'/includes/modules/social-publisher/includes/class-roxy-social-meta.php';
$select=new ReflectionMethod(\RoxySocial\Meta::class,'connected_page');$select->setAccessible(true);
$response=static function($data,$status=200){return ['status'=>$status,'body'=>json_encode($data)];};
$wrong=['id'=>'11','name'=>'Other business','access_token'=>'wrong-fixture','instagram_business_account'=>['id'=>'111']];
$right=['id'=>'22','name'=>'Intended business','access_token'=>'right-fixture','instagram_business_account'=>['id'=>'222','username'=>'fixture']];
$GLOBALS['requests']=[];$GLOBALS['responses']=[$response(['data'=>[$wrong],'paging'=>['next'=>'https://untrusted.test/never-follow','cursors'=>['after'=>'page-two']]]),$response(['data'=>[$right]])];
$found=$select->invoke(null,'fixture-token','22');
check($found['id']==='22'&&count($GLOBALS['requests'])===2,'configured page found on later page, never first unrelated business');
check(parse_url($GLOBALS['requests'][1][0],PHP_URL_HOST)==='graph.facebook.com'&&str_contains($GLOBALS['requests'][1][0],'after=page-two'),'cursor pagination reconstructs trusted URL, not next URL');
foreach([[$wrong,$right],[$right,$wrong]] as $pages){$GLOBALS['responses']=[$response(['data'=>$pages])];check($select->invoke(null,'fixture','22')['id']==='22','result ordering cannot change selected business');}
$cases=[['select_page','',[]],['page_missing','22',[$response(['data'=>[$wrong]])]],['failed','22',[$response(['data'=>[$right]],500)]],['failed','22',[['status'=>200,'body'=>'not-json']]],['failed','22',[$response(['data'=>[$wrong],'paging'=>['next'=>'ignored','cursors'=>['after'=>'same']]]),$response(['data'=>[$wrong],'paging'=>['next'=>'ignored','cursors'=>['after'=>'same']]])]]];
foreach($cases as [$error,$id,$responses]){$GLOBALS['responses']=$responses;try{$select->invoke(null,'fixture',$id);throw new LogicException('Expected failure');}catch(RuntimeException $e){check($e->getMessage()===$error,'missing/invalid/page/HTTP/cursor case fails closed: '.$error);}}
$GLOBALS['options']=['roxy_social_meta_page_id'=>'22','roxy_social_meta_instagram_user_id'=>'999','roxy_social_meta_access_token'=>\RoxySocial\Secrets::encrypt('fixture-access-token')];$GLOBALS['writes']=[];$GLOBALS['responses']=[$response(['data'=>[$right]])];
try{\RoxySocial\Meta::verify_connection();}catch(RedirectResult $e){check(str_contains($e->getMessage(),'instagram_mismatch')&&!$GLOBALS['writes'],'Instagram mismatch preserves all connection settings');}
$GLOBALS['options']['roxy_social_meta_instagram_user_id']='222';$GLOBALS['responses']=[$response(['data'=>[$right]])];
try{\RoxySocial\Meta::verify_connection();}catch(RedirectResult $e){check(str_contains($e->getMessage(),'meta_verified=success')&&get_option('roxy_social_meta_page_id')==='22'&&\RoxySocial\Meta::page_access_token()==='right-fixture','explicit matched Page/Instagram verification stores only intended fixture connection');}
