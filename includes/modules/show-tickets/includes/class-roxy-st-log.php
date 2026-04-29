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
    self::ensure_log_directory($dir);
    return $dir . '/roxy-st.log';
  }

  private static function ensure_log_directory(string $dir): void {
    if (!is_dir($dir)) {
      wp_mkdir_p($dir);
    }

    $index = $dir . '/index.html';
    if (!file_exists($index)) {
      @file_put_contents($index, '');
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
      @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
  }

  private static function write_file(string $level, string $message): void {
    $path = self::uploads_log_path();
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
