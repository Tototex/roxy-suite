<?php
// wp eval-file [candidate-root]. Isolated updater namespace; no installation or provider traffic.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
$root=$args[0]??dirname(__DIR__);
$namespace='UpdaterFixture'.bin2hex(random_bytes(4));
$source=file_get_contents($root.'/includes/class-roxy-suite-updater.php');
eval('?>'.str_replace('namespace RoxySuite;', 'namespace '.$namespace.';', $source));
$class=$namespace.'\\Updater';
$repo='fixture/'.strtolower($namespace);
$base='https://github.com/'.$repo.'/releases/download/v1.0.62/';
$archive=$base.'roxy-suite-1.0.62.zip';
$manifest_url=$base.'roxy-suite-1.0.62.manifest.json';
$path=wp_tempnam('roxy-updater-fixture.zip');
$checks=0; $calls=0;
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS: $label\n";};
$cache='roxy_updater_'.md5($repo.'|roxy-suite');
$http=null;
try {
    $zip=new ZipArchive();
    if($zip->open($path,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Private ZIP creation failed.');
    $content="<?php // isolated fixture; never installed\n";
    $zip->addFromString('roxy-suite/roxy-suite.php',$content); $zip->close();
    $manifest=['format_version'=>1,'version'=>'1.0.62','source_sha'=>str_repeat('a',40),
        'archive'=>['file'=>'roxy-suite-1.0.62.zip','sha256'=>hash_file('sha256',$path)],
        'files'=>[['path'=>'roxy-suite/roxy-suite.php','sha256'=>hash('sha256',$content)]]];
    $release=['tag_name'=>'v1.0.62','assets'=>[
        ['name'=>'roxy-suite-1.0.62.zip','browser_download_url'=>$archive],
        ['name'=>'roxy-suite-1.0.62.manifest.json','browser_download_url'=>$manifest_url]]];
    $http=static function($pre,$opts,$url)use($repo,$manifest_url,$release,$manifest,&$calls){
        ++$calls;
        if($url==='https://api.github.com/repos/'.$repo.'/releases/latest')$body=$release;
        elseif($url===$manifest_url)$body=$manifest;
        else throw new RuntimeException('Unexpected HTTP request: fixture prohibits provider traffic.');
        return ['headers'=>[],'response'=>['code'=>200,'message'=>'OK'],'body'=>wp_json_encode($body),'cookies'=>[]];
    };
    add_filter('pre_http_request',$http,PHP_INT_MAX,3);
    $class::init(['plugin_file'=>'roxy-suite/roxy-suite.php','version'=>'1.0.61','github_repo'=>$repo,'slug'=>'roxy-suite']);
    $result=$class::filter_update_plugins((object)['response'=>['other/plugin.php'=>(object)['new_version'=>'2']]]);
    $check(isset($result->response['roxy-suite/roxy-suite.php'])&&isset($result->response['other/plugin.php']),'actual WP update transient retains other plugins and offers verified fixture');
    $check($calls===2,'actual WP HTTP filters fetch only fake release and fake manifest');
    $hook=['action'=>'update','type'=>'plugin','plugin'=>'roxy-suite/roxy-suite.php'];
    $check($class::filter_upgrader_pre_download($path,$archive,null,$hook)===$path,'actual WP supplied ZIP accepted after SHA-256 verification');
    $other=['action'=>'update','type'=>'plugin','plugin'=>'other/plugin.php','plugins'=>['other/plugin.php','roxy-suite/roxy-suite.php']];
    $check($class::filter_upgrader_pre_download(false,'https://example.test/other.zip',null,$other)===false&&$calls===2,'actual WP mixed update leaves unrelated package untouched');
    file_put_contents($path,'tampered fixture');
    $bad=$class::filter_upgrader_pre_download($path,$archive,null,$hook);
    $check(is_wp_error($bad)&&$bad->get_error_code()==='roxy_suite_archive_hash_mismatch'&&is_file($path),'actual WP corrupted archive rejected without deleting another filter-owned file');
} finally {
    if($http)remove_filter('pre_http_request',$http,PHP_INT_MAX);
    if(is_file($path))unlink($path);
    delete_site_transient($cache);delete_site_transient($cache.'_failed');
    remove_filter('pre_set_site_transient_update_plugins',[$class,'filter_update_plugins']);
    remove_filter('plugins_api',[$class,'filter_plugins_api'],20);
    remove_filter('upgrader_pre_download',[$class,'filter_upgrader_pre_download']);
    remove_action('upgrader_process_complete',[$class,'handle_upgrader_process_complete'],20);
}
echo "PASS: $checks actual WordPress updater checks; fixture ZIP/cache removed, nothing installed\n";
