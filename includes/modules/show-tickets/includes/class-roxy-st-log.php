<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

/**
 * Lightweight logger that writes to both WooCommerce logs and a guarded flat file in uploads.
 */
class Log {
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
    // Best-effort; never fatal.
    @file_put_contents($path, $line, FILE_APPEND);
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
