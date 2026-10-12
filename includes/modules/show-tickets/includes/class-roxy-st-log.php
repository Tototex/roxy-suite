<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

/**
 * Lightweight logger that writes to both WooCommerce logs and a guarded flat file in uploads.
 */
class Log {
  // The fallback is secondary to WooCommerce's logger, so keep its disk use
  // deliberately small and bounded: active file plus two rotated generations.
  private const MAX_FILE_BYTES = 262144;
  private const ROTATED_FILES = 2;

  private static function uploads_log_path(): string {
    $up = wp_upload_dir(null, false);
    $base = isset($up['basedir']) ? $up['basedir'] : WP_CONTENT_DIR . '/uploads';
    $dir = rtrim($base, '/').'/roxy-st-logs';
    if (!self::ensure_log_directory($dir)) return '';
    return $dir . '/roxy-st.log';
  }

  private static function ensure_log_directory(string $dir): bool {
    clearstatcache(true, $dir);
    if (is_link($dir)) return false;
    if (!is_dir($dir)) {
      wp_mkdir_p($dir);
    }
    clearstatcache(true, $dir);
    if (is_link($dir) || !self::is_real_directory($dir) || !is_writable($dir)) return false;

    $index = $dir . '/index.html';
    if (self::path_is_link_or_non_file($index)) return false;
    if (!file_exists($index)) {
      $handle = @fopen($index, 'x');
      if ($handle === false) {
        if (self::path_is_link_or_non_file($index) || !self::is_regular_file($index)) return false;
      } else {
        @fclose($handle);
      }
    }
    if (!self::is_regular_file($index) || !is_readable($index)) return false;

    $htaccess = $dir . '/.htaccess';
    $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
    if (self::path_is_link_or_non_file($htaccess)) return false;
    $contents = file_exists($htaccess) ? self::read_regular_file($htaccess) : false;
    if (!is_string($contents) || $contents !== $rules) {
      // Rename replaces a raced-in symlink itself instead of writing through
      // it. Keep the temporary file in the guarded directory and verify it.
      $temporary = @tempnam($dir, '.roxy-st-deny-');
      if (!is_string($temporary) || dirname($temporary) !== $dir || !self::is_regular_file($temporary)
        || @file_put_contents($temporary, $rules, LOCK_EX) !== strlen($rules)) {
        if (is_string($temporary)) @unlink($temporary);
        return false;
      }
      clearstatcache(true, $dir);
      if (is_link($dir) || !self::is_real_directory($dir) || self::path_is_link_or_non_file($htaccess)
        || !@rename($temporary, $htaccess)) {
        @unlink($temporary);
        return false;
      }
      $contents = self::read_regular_file($htaccess);
    }
    return self::is_regular_file($htaccess) && is_readable($htaccess) && is_string($contents) && $contents === $rules;
  }

  private static function is_real_directory(string $path): bool {
    clearstatcache(true, $path);
    $stat = @lstat($path);
    return is_array($stat) && (($stat['mode'] & 0170000) === 0040000);
  }

  private static function is_regular_file(string $path): bool {
    clearstatcache(true, $path);
    $stat = @lstat($path);
    return is_array($stat) && (($stat['mode'] & 0170000) === 0100000);
  }

  private static function path_is_link_or_non_file(string $path): bool {
    clearstatcache(true, $path);
    if (is_link($path)) return true;
    return file_exists($path) && !self::is_regular_file($path);
  }

  /** Open a regular file and verify the descriptor still names that exact path. */
  private static function open_regular_file(string $path, string $mode) {
    if (self::path_is_link_or_non_file($path)) return false;
    $handle = @fopen($path, $mode);
    if ($handle === false) return false;
    $opened = @fstat($handle);
    clearstatcache(true, $path);
    $current = @lstat($path);
    if (!is_array($opened) || !is_array($current)
      || (($opened['mode'] & 0170000) !== 0100000)
      || (($current['mode'] & 0170000) !== 0100000)
      || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
      @fclose($handle);
      return false;
    }
    return $handle;
  }

  private static function read_regular_file(string $path) {
    $handle = self::open_regular_file($path, 'rb');
    if ($handle === false) return false;
    $contents = @stream_get_contents($handle);
    @fclose($handle);
    return $contents;
  }

  private static function write_file(string $level, string $message): void {
    $path = self::uploads_log_path();
    if ($path === '') return;
    $line = gmdate('Y-m-d\TH:i:sP') . ' ' . $level . ' ' . $message . "\n";
    // A single pathological context must not defeat the per-file size limit.
    if (strlen($line) > self::MAX_FILE_BYTES) {
      $marker = " [fallback log line truncated]\n";
      $line = substr($line, 0, self::MAX_FILE_BYTES - strlen($marker)) . $marker;
    }

    // Lock a stable sibling rather than the log inode: the active log is
    // renamed during rotation, and locking that inode alone lets waiting
    // writers append to a rotated generation concurrently with another rotate.
    $lock = self::open_regular_file($path . '.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
      if (is_resource($lock)) @fclose($lock);
      error_log('Roxy Show Tickets could not lock its protected fallback log.');
      return;
    }

    $written = false;
    try {
      if (!self::cap_existing_log($path)) {
        error_log('Roxy Show Tickets could not safely cap its existing fallback log.');
        return;
      }
      clearstatcache(true, $path);
      $size = is_file($path) ? @filesize($path) : 0;
      if ($size === false) $size = self::MAX_FILE_BYTES;
      if ($size + strlen($line) > self::MAX_FILE_BYTES) {
        if (!self::rotate_log($path)) {
          error_log('Roxy Show Tickets could not safely rotate its protected fallback log.');
          return;
        }
      }
      // Exclusive stable lock serializes rotation and append. Use a single
      // append write so a failed/short write is detectable without truncating.
      $log_file = self::open_regular_file($path, 'ab');
      if ($log_file !== false) {
        $written = @fwrite($log_file, $line) === strlen($line) && @fflush($log_file);
        @fclose($log_file);
      }
    } finally {
      @flock($lock, LOCK_UN);
      @fclose($lock);
    }

    if (!$written) {
      error_log('Roxy Show Tickets could not append to its protected fallback log.');
    }
  }

  /** Keep an oversized pre-retention log's newest bytes without mutating the original on failure. */
  private static function cap_existing_log(string $path): bool {
    if (!file_exists($path)) return true;
    if (!is_file($path) || is_link($path)) return false;
    clearstatcache(true, $path);
    $size = @filesize($path);
    if ($size === false) return false;
    if ($size <= self::MAX_FILE_BYTES) return true;

    $input = self::open_regular_file($path, 'rb');
    $temporary = @tempnam(dirname($path), '.roxy-st-log-cap-');
    if ($input === false || $temporary === false) {
      if (is_resource($input)) @fclose($input);
      if (is_string($temporary)) @unlink($temporary);
      return false;
    }
    $output = self::open_regular_file($temporary, 'wb');
    if ($output === false) {
      @fclose($input);
      @unlink($temporary);
      return false;
    }

    $offset = max(0, $size - self::MAX_FILE_BYTES);
    $copied = @fseek($input, $offset) === 0
      ? @stream_copy_to_stream($input, $output, self::MAX_FILE_BYTES)
      : false;
    $expected = min($size, self::MAX_FILE_BYTES);
    $ok = $copied === $expected && @fflush($output);
    @fclose($input);
    @fclose($output);

    // On filesystems where rename cannot replace an existing file, leave the
    // original untouched and fail closed; never truncate it in place.
    if (!$ok || !@rename($temporary, $path)) {
      @unlink($temporary);
      return false;
    }
    clearstatcache(true, $path);
    return @filesize($path) === $expected;
  }

  /**
   * Rotate the active log while retaining enough rollback information to keep
   * the current file intact if any rename or replacement-file creation fails.
   */
  private static function rotate_log(string $path): bool {
    $archives = [];
    for ($i = 1; $i <= self::ROTATED_FILES; $i++) {
      $archives[$i] = $path . '.' . $i;
      if ((file_exists($archives[$i]) || is_link($archives[$i])) && (!is_file($archives[$i]) || is_link($archives[$i]))) {
        return false;
      }
    }
    if (!is_file($path) || is_link($path)) return false;

    // Staging the oldest generation preserves it until the new active log has
    // been created. If prior cleanup failed, retry it before starting another
    // rotation so a transient unlink failure cannot wedge future writes.
    $backup = $path . '.rotation-backup';
    if (file_exists($backup) || is_link($backup)) {
      if (!is_file($backup) || is_link($backup) || !@unlink($backup)) return false;
    }
    $had_oldest = is_file($archives[self::ROTATED_FILES]);
    if ($had_oldest && !@rename($archives[self::ROTATED_FILES], $backup)) return false;

    $moved = [];
    for ($i = self::ROTATED_FILES - 1; $i >= 1; $i--) {
      if (!is_file($archives[$i])) continue;
      if (!@rename($archives[$i], $archives[$i + 1])) {
        self::rollback_rotation($path, $archives, $moved, false, $backup, $had_oldest);
        return false;
      }
      $moved[] = $i;
    }

    if (!@rename($path, $archives[1])) {
      self::rollback_rotation($path, $archives, $moved, false, $backup, $had_oldest);
      return false;
    }

    $new = @fopen($path, 'x');
    if ($new === false) {
      self::rollback_rotation($path, $archives, $moved, true, $backup, $had_oldest);
      return false;
    }
    @fclose($new);

    if ($had_oldest && !@unlink($backup)) {
      // The new active file is already safe. Keep the old generation in its
      // staging name if cleanup is denied; it is still bounded to one extra
      // file, and future calls won't touch it or risk the active log.
      error_log('Roxy Show Tickets could not remove the oldest rotated fallback log.');
    }
    return true;
  }

  /** Undo each completed rename in reverse order; never unlink the active log. */
  private static function rollback_rotation(string $path, array $archives, array $moved, bool $active_moved, string $backup, bool $had_oldest): void {
    if ($active_moved && is_file($archives[1]) && !file_exists($path)) {
      @rename($archives[1], $path);
    }
    foreach (array_reverse($moved) as $from) {
      $to = $archives[$from + 1];
      if (is_file($to) && !file_exists($archives[$from])) @rename($to, $archives[$from]);
    }
    if ($had_oldest && is_file($backup) && !file_exists($archives[self::ROTATED_FILES])) {
      @rename($backup, $archives[self::ROTATED_FILES]);
    }
  }

  public static function info(string $message, array $context = []): void {
    if (function_exists('wc_get_logger')) {
      wc_get_logger()->info($message, ['source' => ROXY_ST_LOG_SOURCE] + $context);
    }
    self::write_file('Info', $message . (empty($context) ? '' : ' ' . wp_json_encode($context)));
  }

  public static function warn(string $message, array $context = []): void {
    if (function_exists('wc_get_logger')) {
      wc_get_logger()->warning($message, ['source' => ROXY_ST_LOG_SOURCE] + $context);
    }
    self::write_file('Warning', $message . (empty($context) ? '' : ' ' . wp_json_encode($context)));
  }

  public static function error(string $message, array $context = []): void {
    if (function_exists('wc_get_logger')) {
      wc_get_logger()->error($message, ['source' => ROXY_ST_LOG_SOURCE] + $context);
    }
    self::write_file('Error', $message . (empty($context) ? '' : ' ' . wp_json_encode($context)));
  }
}
