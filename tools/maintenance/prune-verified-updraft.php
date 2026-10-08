<?php
// Standalone: exact archive files only; never deletes the Updraft directory/configuration.
$receipt=$argv[1]??'';$execute=($argv[2]??'')==='--delete-verified';
$root='/home1/anrvxfmy/public_html/wp-content/updraft';
if(realpath($root)!==$root||is_link($root))throw new RuntimeException('Unexpected Updraft root');
$rows=json_decode(file_get_contents($receipt),true,512,JSON_THROW_ON_ERROR);
$targets=[];$bytes=0;
foreach($rows as $row){
    if(($row['verified']??false)!==true||($row['size']??0)<=0)continue;
    if(!preg_match('/^backup_[A-Za-z0-9_-]+\.(?:zip|gz)$/D',$row['name'])||!preg_match('/^[a-f0-9]{64}$/D',$row['sha256']))throw new RuntimeException('Invalid verified archive');
    $file=$root.'/'.$row['name'];
    if(is_link($file)||!is_file($file)||realpath($file)!==$file||filesize($file)!==$row['size']||!hash_equals($row['sha256'],hash_file('sha256',$file)))throw new RuntimeException('Remote archive changed: '.$row['name']);
    $targets[]=$file;$bytes+=$row['size'];
}
echo 'VERIFIED_PRUNE_PREFLIGHT files='.count($targets).' bytes='.$bytes.PHP_EOL;
if(!$execute)exit;
foreach($targets as $file){if(!unlink($file))throw new RuntimeException('Could not remove verified archive: '.basename($file));}
echo 'VERIFIED_PRUNE_COMPLETE files='.count($targets).' bytes='.$bytes.PHP_EOL;
