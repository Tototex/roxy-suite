<?php
// Private temporary files only; exercise the actual read-only comparator process.
$candidate = $argv[1] ?? (__DIR__ . '/deployment-manifest-readonly.php');
if (!is_file($candidate)) throw new RuntimeException('Comparator missing');
$dir = sys_get_temp_dir() . '/roxy_manifest_' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create private fixture');
$root = $dir . '/plugin';
if (!mkdir($root, 0700)) throw new RuntimeException('Cannot create private root');
$checks = 0;
$check = static function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException('FAIL: ' . $label); $checks++; echo 'PASS: ' . $label . PHP_EOL; };
$entry = static fn($path, $bytes) => ['path'=>$path,'sha256'=>hash('sha256',$bytes),'canonical_sha256'=>hash('sha256',str_replace("\r\n","\n",$bytes)),'text'=>true];
$base = ['format_version'=>1,'source_sha'=>str_repeat('a',40),'files'=>[$entry('a.php', "<?php\necho 1;\n"),$entry('b.js', "fixture\n")]];
$run = static function ($manifest) use ($candidate, $dir, $root) {
    file_put_contents($dir . '/manifest.json', json_encode($manifest));
    $process = proc_open([PHP_BINARY, $candidate, $dir . '/manifest.json', $root], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start comparator');
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), json_decode($output, true)];
};
try {
    file_put_contents($root.'/a.php', "<?php\necho 1;\n");
    file_put_contents($root.'/b.js', "fixture\r\n");
    [$code,$result] = $run($base);
    $check($code===0 && $result['counts']['exact']===1 && $result['counts']['line_endings_only']===1, 'exact bytes and benign line-ending differences distinguished');
    $missing = $base; $missing['files'][] = $entry('missing.php','missing');
    [$code,$result] = $run($missing);
    $check($code===1 && $result['counts']['missing']===1, 'missing deployed runtime file fails verification');
    file_put_contents($root.'/a.php', "<?php\necho 2;\n");
    [$code,$result] = $run($base);
    $check($code===1 && $result['counts']['content_mismatch']===1, 'real source drift differs from formatting');
    file_put_contents($root.'/a.php', "<?php\necho 1;\n");
    file_put_contents($root.'/unexpected.css', 'fixture');
    [$code,$result] = $run($base);
    $check($code===1 && $result['unexpected_files']===['unexpected.css'], 'unexpected live files reported without deleting them');
    $check(is_file($root.'/unexpected.css'), 'comparator leaves unexpected files untouched');
    unlink($root.'/unexpected.css');
    $unsafe = $base; $unsafe['files'][0]['path']='../outside.php';
    [$code] = $run($unsafe); $check($code!==0, 'parent traversal rejected');
    $duplicate = $base; $duplicate['files'][]=$duplicate['files'][0];
    [$code] = $run($duplicate); $check($code!==0, 'duplicate manifest paths rejected');
    echo "OK: {$checks} private manifest checks; no website changes\n";
} finally {
    foreach (['plugin/a.php','plugin/b.js','plugin/unexpected.css','manifest.json'] as $name) if (is_file($dir.'/'.$name)) unlink($dir.'/'.$name);
    rmdir($root); rmdir($dir);
}
