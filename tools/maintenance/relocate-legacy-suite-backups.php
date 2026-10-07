<?php
// Exact dated backup relocation only. Run after independently verifying the archive on the I drive.
$archive_path='/tmp/roxy-outcome-review-20261007/legacy-suite-backups-20260420.tar.gz';
$expected_archive='dd0a71cefd247ebdc603f350d87fa3afdc097f76d8dc79227b2e3ea63644e1b2';
$root='/home1/anrvxfmy/public_html/wp-content/plugins/roxy-suite';
$destination='/home1/anrvxfmy/roxy-suite-legacy-recovery-20261007';
$paths=[
    'includes/modules/show-tickets/includes/class-roxy-st-sales.php.bak-20260420-presale',
    'includes/modules/show-tickets/includes/class-roxy-st-cpt.php.bak-20260420-hide-legacy-field',
    'includes/modules/grosses/includes/class-roxy-grosses-reporter.php.bak-20260420-concession-window',
    'includes/modules/grosses/includes/class-roxy-grosses-reporter.php.bak-20260420-live-email',
    'includes/modules/grosses/includes/class-roxy-grosses-settings.php.bak-20260420-live-email',
    'includes/modules/grosses/includes/class-roxy-grosses-settings.php.bak-20260420-presale',
    'includes/modules/grosses/includes/class-roxy-grosses-store.php.bak-20260420-presale',
    'includes/modules/grosses/includes/class-roxy-grosses-reporter.php.bak-20260420-presale',
    'includes/modules/grosses/includes/class-roxy-grosses-reporter.php.bak-20260420-live-email-footer',
    'includes/modules/grosses/includes/class-roxy-grosses-store.php.bak-20260420-concession-window'
];
if (realpath($root)!==$root || realpath($destination)!==$destination || is_link($destination)
    || strpos($destination,'/home1/anrvxfmy/public_html/')===0
    || !hash_equals($expected_archive,hash_file('sha256',$archive_path))) throw new RuntimeException('Recovery roots/archive failed verification.');
$archive=new PharData($archive_path);
$total=0;
foreach($paths as $path) {
    $source=$root.'/'.$path;
    if(is_link($source)||!is_file($source)||realpath($source)!==$source||file_exists($destination.'/'.$path)
        ||!isset($archive[$path])||!hash_equals(hash_file('sha256',$source),hash('sha256',$archive[$path]->getContent())))
        throw new RuntimeException('Exact backup changed or destination occupied: '.$path);
    $total+=filesize($source);
}
echo 'Verified '.count($paths).' exact dated backups ('.$total." bytes).\n";
if(($argv[1]??'')!=='relocate')exit(0);
foreach($paths as $path) {
    $parent=dirname($destination.'/'.$path);
    if(!is_dir($parent)&&!mkdir($parent,0700,true))throw new RuntimeException('Recovery directory creation failed.');
    if(realpath($parent)!==$parent||strpos($parent,$destination.'/')!==0)throw new RuntimeException('Recovery directory escaped target.');
    if(!rename($root.'/'.$path,$destination.'/'.$path))throw new RuntimeException('Recovery relocation failed: '.$path);
    if(file_exists($root.'/'.$path)||!hash_equals(hash('sha256',$archive[$path]->getContent()),hash_file('sha256',$destination.'/'.$path)))
        throw new RuntimeException('Recovery postcondition failed: '.$path);
    echo 'Recoverably relocated: '.$path."\n";
}

