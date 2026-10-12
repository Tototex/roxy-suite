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
    if (!is_dir($dir)) {
      wp_mkdir_p($dir);
    }
    if (!is_dir($dir) || !is_writable($dir)) return false;

    $index = $dir . '/index.html';
    if (!is_file($index) && @file_put_contents($index, '') === false) {
      return false;
    }
    if (!is_file($index) || !is_readable($index)) return false;

    $htaccess = $dir . '/.htaccess';
    $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
    $contents = is_file($htaccess) ? @file_get_contents($htaccess) : false;
    if (!is_string($contents) || $contents !== $rules) {
      if (@file_put_contents($htaccess, $rules) === false) return false;
      $contents = @file_get_contents($htaccess);
    }
    return is_file($htaccess) && is_readable($htaccess) && is_string($contents) && $contents === $rules;
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
    $lock = @fopen($path . '.lock', 'c');
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
      $written = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === strlen($line);
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

    $input = @fopen($path, 'rb');
    $temporary = @tempnam(dirname($path), '.roxy-st-log-cap-');
    if ($input === false || $temporary === false) {
      if (is_resource($input)) @fclose($input);
      if (is_string($temporary)) @unlink($temporary);
      return false;
    }
    $output = @fopen($temporary, 'wb');
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
