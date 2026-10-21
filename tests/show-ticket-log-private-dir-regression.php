<?php
namespace RoxyST {
  // Fault injection for the rotation rollback path; all other renames use the
  // platform implementation. This shim is resolved by the production class's
  // unqualified rename() calls in its namespace.
  function rename($from, $to) {
    global $roxy_st_fail_rename_to;
    if ($roxy_st_fail_rename_to !== null && $to === $roxy_st_fail_rename_to) {
      $roxy_st_fail_rename_to = null;
      return false;
    }
    return \rename($from, $to);
  }
  function unlink($path) {
    global $roxy_st_fail_unlink_path;
    if ($roxy_st_fail_unlink_path !== null && $path === $roxy_st_fail_unlink_path) {
      $roxy_st_fail_unlink_path = null;
      return false;
    }
    return \unlink($path);
  }
}
namespace {
// Isolated regression for privacy guards around the Show Tickets fallback log.
$roxy_st_fail_rename_to = null;
$roxy_st_fail_unlink_path = null;
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-st-log-' . bin2hex(random_bytes(6));
$uploads = $root . DIRECTORY_SEPARATOR . 'uploads';
define('ABSPATH', $root . DIRECTORY_SEPARATOR . 'wordpress' . DIRECTORY_SEPARATOR);
define('WP_CONTENT_DIR', $root . DIRECTORY_SEPARATOR . 'wordpress' . DIRECTORY_SEPARATOR . 'wp-content');
function wp_upload_dir($time = null, $create = true) { global $uploads; return ['basedir' => $uploads]; }
function wp_mkdir_p($dir) { return is_dir($dir) || @mkdir($dir, 0700, true); }
require __DIR__ . '/../includes/modules/show-tickets/includes/class-roxy-st-log.php';
$method = new ReflectionMethod('RoxyST\\Log', 'uploads_log_path');
if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
$write = new ReflectionMethod('RoxyST\\Log', 'write_file');
if (PHP_VERSION_ID < 80100) $write->setAccessible(true);
$checks = 0;
$check = static function ($ok, string $message) use (&$checks): void {
  $checks++;
  if (!$ok) throw new RuntimeException($message);
};
try {
  $path = $method->invoke(null);
  $dir = dirname($path);
  $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
  $check($path === $uploads . '/roxy-st-logs/roxy-st.log', 'logger returns expected path after privacy checks');
  $check(is_file($dir . '/index.html'), 'directory index is created');
  $check(file_get_contents($dir . '/.htaccess') === $rules, 'complete Apache deny rules are installed');

  // A symlinked log directory must not redirect any protection-file write.
  $outside = $root . '/outside-sentinel.txt';
  file_put_contents($outside, 'outside stays private');
  $parked_dir = $dir . '-real';
  if (!rename($dir, $parked_dir) || !symlink($outside, $dir)) throw new RuntimeException('Could not create isolated directory symlink fixture.');
  $check($method->invoke(null) === '' && file_get_contents($outside) === 'outside stays private', 'logger refuses a symlinked log directory');
  unlink($dir);
  if (!rename($parked_dir, $dir)) throw new RuntimeException('Could not restore private fixture directory.');

  unlink($dir . '/index.html');
  if (!symlink($outside, $dir . '/index.html')) throw new RuntimeException('Could not create isolated index symlink fixture.');
  $check($method->invoke(null) === '' && file_get_contents($outside) === 'outside stays private', 'logger refuses a symlinked directory index');
  unlink($dir . '/index.html');
  file_put_contents($dir . '/index.html', '');
  unlink($dir . '/.htaccess');
  if (!symlink($outside, $dir . '/.htaccess')) throw new RuntimeException('Could not create isolated privacy-file symlink fixture.');
  $check($method->invoke(null) === '' && file_get_contents($outside) === 'outside stays private', 'logger refuses a symlinked privacy file');
  unlink($dir . '/.htaccess');
  file_put_contents($dir . '/.htaccess', $rules);

  $write->invoke(null, 'Info', 'fixture message');
  $check(is_file($path) && str_ends_with(file_get_contents($path), " Info fixture message\n"), 'fallback logger appends the complete log line');
  $check(is_file($path . '.lock'), 'append and rotation use a stable sibling lock');
  file_put_contents($dir . '/.htaccess', '');
  $check($method->invoke(null) === $path, 'logger path remains stable after repairing rules');
  $check(file_get_contents($dir . '/.htaccess') === $rules, 'missing/weak rules are repaired');
  file_put_contents($dir . '/.htaccess', 'Require all granted');
  $check($method->invoke(null) === $path && file_get_contents($dir . '/.htaccess') === $rules, 'unsafe allow rule is replaced');

  // Log and lock symlinks must not write through to a file outside the log dir.
  unlink($path);
  if (!symlink($outside, $path)) throw new RuntimeException('Could not create isolated log symlink fixture.');
  $write->invoke(null, 'Error', 'must not reach outside log');
  $check(file_get_contents($outside) === 'outside stays private', 'logger refuses a symlinked active log file');
  unlink($path);
  file_put_contents($path, '');
  unlink($path . '.lock');
  if (!symlink($outside, $path . '.lock')) throw new RuntimeException('Could not create isolated lock symlink fixture.');
  $write->invoke(null, 'Error', 'must not reach outside lock');
  $check(file_get_contents($outside) === 'outside stays private' && file_get_contents($path) === '', 'logger refuses a symlinked stable lock file');
  unlink($path . '.lock');

  unlink($dir . '/.htaccess');
  mkdir($dir . '/.htaccess');
  $check($method->invoke(null) === '', 'logger refuses flat-file logging when deny rules cannot be verified');
  rmdir($dir . '/.htaccess');
  unlink($path);
  mkdir($path);
  $write->invoke(null, 'Error', 'fixture failure');
  $check(is_dir($path), 'failed log append is nonfatal and leaves the obstructing path untouched');

  // Existing logs may predate retention. Keep only their newest capped bytes
  // before appending, with the original unchanged if preparing that copy fails.
  rmdir($path);
  file_put_contents($path, str_repeat('O', 300000));
  $roxy_st_fail_rename_to = $path;
  $write->invoke(null, 'Info', 'legacy recovery');
  $check($roxy_st_fail_rename_to === null, 'oversized-log replacement failure was exercised');
  $check(filesize($path) === 300000 && file_get_contents($path) === str_repeat('O', 300000), 'failed oversized-log replacement leaves the original byte-for-byte intact');
  $write->invoke(null, 'Info', 'legacy recovery');
  $check(filesize($path . '.1') === 262144, 'pre-existing oversized log is retained at the cap');
  $check(str_contains(file_get_contents($path . '.1'), str_repeat('O', 32)), 'pre-existing log keeps its newest content');
  $check(str_contains(file_get_contents($path), 'legacy recovery'), 'new line is written after oversized-log recovery');
  unlink($path);
  unlink($path . '.1');

  // Exercise enough rotations to populate active/.1/.2, then fail the next
  // rename after the oldest archive has been staged. Every prior byte survives.
  // Make each appended line exceed half the cap so the next append is certain
  // to enter rotation; include timestamp overhead in the conservative sizing.
  $large = str_repeat('L', 150000);
  foreach (['A', 'B', 'C', 'D', 'E'] as $label) $write->invoke(null, 'Info', $label . $large);
  $before = [];
  foreach ([$path, $path . '.1', $path . '.2'] as $file) $before[$file] = file_get_contents($file);
  $check(count($before) === 3 && !in_array(false, $before, true), 'fixture populated active log and both archives before failure injection');
  $roxy_st_fail_rename_to = $path . '.2';
  $write->invoke(null, 'Info', 'F' . $large);
  $check($roxy_st_fail_rename_to === null, 'injected archive rename failure was exercised');
  foreach ($before as $file => $contents) {
    $check(is_file($file) && file_get_contents($file) === $contents, 'failed rotation preserves existing log data: ' . basename($file));
  }
  $check(!file_exists($path . '.rotation-backup'), 'failed rotation restores the staged oldest generation');

  // Retry succeeds; exactly the active file and two archives remain, all at
  // or below the fixed per-file cap.
  $write->invoke(null, 'Info', 'F' . $large);
  $log_files = array_values(array_filter(glob($path . '*'), static function ($file) use ($path): bool {
    return $file === $path || preg_match('/^' . preg_quote($path, '/') . '\.[12]$/', $file) === 1;
  }));
  $check(count($log_files) === 3, 'retention keeps only the active file and two rotated files');
  foreach ($log_files as $file) $check(filesize($file) <= 262144, 'retained file is within the size cap: ' . basename($file));
  $check(str_contains(file_get_contents($path), 'F' . $large), 'successful retry appends the deferred line');

  // A one-time failure to delete the oldest staged archive does not lose the
  // new active log. The next rotation retries cleanup and then progresses.
  $roxy_st_fail_unlink_path = $path . '.rotation-backup';
  $write->invoke(null, 'Info', str_repeat('Q', 200000));
  $check($roxy_st_fail_unlink_path === null, 'oldest-archive cleanup failure was exercised');
  $check(is_file($path) && str_contains(file_get_contents($path), str_repeat('Q', 100)), 'cleanup failure leaves the new active log intact');
  $check(is_file($path . '.rotation-backup'), 'failed cleanup leaves one bounded recovery archive');
  $check(filesize($path . '.rotation-backup') <= 262144, 'staged recovery archive is also capped');
  $write->invoke(null, 'Info', str_repeat('R', 200000));
  $check(!file_exists($path . '.rotation-backup'), 'a later rotation retries stale backup cleanup');
  $check(is_file($path) && str_contains(file_get_contents($path), str_repeat('R', 100)), 'writes continue after backup cleanup recovers');
  foreach ([$path, $path . '.1', $path . '.2'] as $file) {
    $check(is_file($file) && filesize($file) <= 262144, 'cleanup-recovery retention remains capped: ' . basename($file));
  }

  // Oversized individual contexts are explicitly truncated instead of
  // producing an unbounded single-file fallback log.
  $write->invoke(null, 'Warning', str_repeat('Z', 300000));
  $check(filesize($path) <= 262144, 'an oversized single log line is capped');
  $check(str_contains(file_get_contents($path), '[fallback log line truncated]'), 'oversized line carries a truncation marker');
} finally {
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($iterator as $entry) $entry->isLink() ? unlink($entry->getPathname()) : ($entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()));
  if (is_dir($root)) rmdir($root);
}
echo "Passed {$checks} Show Tickets private log directory checks.\n";
}
