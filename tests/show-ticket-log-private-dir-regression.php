<?php
// Isolated regression for privacy guards around the Show Tickets fallback log.
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-st-log-' . bin2hex(random_bytes(6));
$uploads = $root . DIRECTORY_SEPARATOR . 'uploads';
define('ABSPATH', $root . DIRECTORY_SEPARATOR . 'wordpress' . DIRECTORY_SEPARATOR);
define('WP_CONTENT_DIR', $root . DIRECTORY_SEPARATOR . 'wordpress' . DIRECTORY_SEPARATOR . 'wp-content');
function wp_upload_dir($time = null, $create = true) { global $uploads; return ['basedir' => $uploads]; }
function wp_mkdir_p($dir) { return is_dir($dir) || @mkdir($dir, 0700, true); }
require __DIR__ . '/../includes/modules/show-tickets/includes/class-roxy-st-log.php';
$method = new ReflectionMethod('RoxyST\\Log', 'uploads_log_path');
$method->setAccessible(true);
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
  file_put_contents($dir . '/.htaccess', '');
  $check($method->invoke(null) === $path, 'logger path remains stable after repairing rules');
  $check(file_get_contents($dir . '/.htaccess') === $rules, 'missing/weak rules are repaired');
  file_put_contents($dir . '/.htaccess', 'Require all granted');
  $check($method->invoke(null) === $path && file_get_contents($dir . '/.htaccess') === $rules, 'unsafe allow rule is replaced');
  unlink($dir . '/.htaccess');
  mkdir($dir . '/.htaccess');
  $check($method->invoke(null) === '', 'logger refuses flat-file logging when deny rules cannot be verified');
} finally {
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
  if (is_dir($root)) rmdir($root);
}
echo "Passed {$checks} Show Tickets private log directory checks.\n";
