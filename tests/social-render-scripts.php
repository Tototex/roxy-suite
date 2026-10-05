<?php
// Read-only actual WordPress-rendered inline scripts from a staged Admin class.
if (!defined('WP_CLI')||!WP_CLI) exit;
$stage=$args[0]??'';
$code=preg_replace('/^<\?php\s*/','',file_get_contents($stage.'/includes/modules/social-publisher/includes/class-roxy-social-admin.php'),1);
$code=str_replace('final class Admin','final class FixtureAdmin',$code);
$code=str_replace('dirname(__DIR__)',var_export($stage.'/includes/modules/social-publisher',true),$code);
eval($code);
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
if(!$admins)throw new RuntimeException('Read-only rendering requires an administrator fixture context');
wp_set_current_user((int)$admins[0]);
$scripts=[];
foreach(['featured','hangar','drafts'] as $kind){
    ob_start();
    if($kind==='featured')\RoxySocial\FixtureAdmin::render_showing_media_picker(30411);
    elseif($kind==='hangar') {$method=new ReflectionMethod(\RoxySocial\FixtureAdmin::class,'render_hangar_page');$method->setAccessible(true);$method->invoke(null);}
    else {$_GET=[];\RoxySocial\FixtureAdmin::render_page();}
    $html=ob_get_clean();
    preg_match_all('/<script\b[^>]*>([\s\S]*?)<\/script>/i',$html,$matches);
    foreach($matches[1] as $script)$scripts[]=['view'=>$kind,'script'=>$script];
}
echo wp_json_encode($scripts);
