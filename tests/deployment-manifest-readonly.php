<?php
// No WordPress bootstrap, writes, network calls or customer data output.
$manifest = json_decode(file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$root = realpath($argv[2] ?? '');
if (!$root || !is_dir($root) || ($manifest['format_version'] ?? null) !== 1 || !preg_match('/^[a-f0-9]{40}$/D', $manifest['source_sha'] ?? '') || !is_array($manifest['files'] ?? null)) throw new RuntimeException('Invalid runtime manifest/root');
$counts = ['exact'=>0, 'line_endings_only'=>0, 'missing'=>0, 'content_mismatch'=>0];
$seen = []; $differences = [];
foreach ($manifest['files'] as $file) {
    $path = $file['path'] ?? null;
    if (!is_string($path) || $path === '' || $path[0] === '/' || strpos($path, '\\') !== false || preg_match('#(?:^|/)(?:\.|\.\.)(?:/|$)#', $path) || isset($seen[$path])) throw new RuntimeException('Unsafe/duplicate manifest path');
    foreach (['sha256', 'canonical_sha256'] as $key) if (!preg_match('/^[a-f0-9]{64}$/D', $file[$key] ?? '')) throw new RuntimeException('Invalid file checksum');
    if (!is_bool($file['text'] ?? null)) throw new RuntimeException('Invalid text marker');
    $seen[$path] = true;
    $resolved = realpath($root . '/' . $path);
    if ($resolved === false) $status = 'missing';
    else {
        if (strpos($resolved, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($resolved)) throw new RuntimeException('Runtime path escapes plugin root');
        $bytes = file_get_contents($resolved);
        if (!is_string($bytes)) throw new RuntimeException('Runtime file unreadable');
        $status = hash('sha256', $bytes) === $file['sha256'] ? 'exact'
            : (hash('sha256', $file['text'] ? str_replace("\r\n", "\n", $bytes) : $bytes) === $file['canonical_sha256'] ? 'line_endings_only' : 'content_mismatch');
    }
    $counts[$status]++;
    if ($status !== 'exact') $differences[] = ['path'=>$path, 'status'=>$status];
}
// Report unexpected deployed runtime files too; never automatically remove them.
$extra = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $entry) {
    if ($entry->isLink()) throw new RuntimeException('Unexpected deployed symlink needs review');
    if (!$entry->isFile()) continue;
    // Manifest paths are portable Git paths (forward slashes); normalize the
    // filesystem path returned by DirectoryIterator on Windows before lookup.
    $path = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
    if (preg_match('#^(?:\.git|\.github|build|tests|docs|tools)(?:/|$)#', $path) || in_array($path, ['RoxyEdit.md', '.gitignore'], true) || basename($path) === '.DS_Store') continue;
    if (!isset($seen[$path])) $extra[] = $path;
}
sort($extra);
echo json_encode(['source_sha'=>$manifest['source_sha'], 'counts'=>$counts, 'differences'=>$differences, 'unexpected_files'=>$extra], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($counts['missing'] || $counts['content_mismatch'] || $extra ? 1 : 0);
