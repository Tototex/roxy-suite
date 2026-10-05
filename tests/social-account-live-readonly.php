<?php
// Read-only staged Graph selection. Does not save options or publish.
if(!defined('WP_CLI')||!WP_CLI)exit;
$stage=$args[0]??'';
$code=preg_replace('/^<\?php\s*/','',file_get_contents($stage.'/includes/modules/social-publisher/includes/class-roxy-social-meta.php'),1);
$code=str_replace('final class Meta','final class ReadonlyFixtureMeta',$code);eval($code);
$keys=['roxy_social_meta_page_id','roxy_social_meta_instagram_user_id','roxy_social_meta_page_access_token'];$before=[];foreach($keys as $key)$before[$key]=get_option($key,'');
$method=new ReflectionMethod(\RoxySocial\ReadonlyFixtureMeta::class,'connected_page');$method->setAccessible(true);
try{
    $selected=$method->invoke(null,\RoxySocial\Meta::access_token(),\RoxySocial\Meta::page_id());
    echo 'LIVE_PAGE_EXACT_MATCH:'.((string)($selected['id']??'')===\RoxySocial\Meta::page_id()?'yes':'no').PHP_EOL;
    echo 'LIVE_INSTAGRAM_EXACT_MATCH:'.((string)($selected['instagram_business_account']['id']??'')===\RoxySocial\Meta::instagram_user_id()?'yes':'no').PHP_EOL;
}catch(RuntimeException $error){echo 'LIVE_READONLY_VERIFY:'.$error->getMessage().PHP_EOL;}
$after=[];foreach($keys as $key)$after[$key]=get_option($key,'');
echo 'LIVE_CONNECTION_OPTIONS_UNCHANGED:'.($before===$after?'yes':'no').PHP_EOL;
