<?php
// Standalone regression: saved-report email uses its snapshot without rewriting live data.
namespace RoxyGrosses {
  final class Settings {
    public static function email_list(): array { return ['fixture@example.invalid']; }
    public static function admin_email(): string { return 'admin@example.invalid'; }
    public static function get($key, $default = '') { return $key === 'admin_email' ? 'admin@example.invalid' : $default; }
    public static function get_report_timezone(): string { return 'UTC'; }
    public static function set_status(array $status): void { $GLOBALS['fixture_status'] = $status; }
  }
  final class Store {
    public static array $snapshot = [];
    public static array $entries = [];
    public static array $history = [];
    public static array $logs = [];
    public static array $emailed = [];
    public static int $upsert_calls = 0;
    public static function get_report(int $id): ?array { return self::$snapshot; }
    public static function upsert_history_rows(...$args): int { self::$upsert_calls++; self::$history[] = $args; return 1; }
    public static function upsert_entries(...$args): array { self::$upsert_calls++; self::$entries[] = $args; return []; }
    public static function insert_log(...$args): int { self::$logs[] = $args; return 1; }
    public static function mark_emailed(int $id): bool { self::$emailed[] = $id; return true; }
  }
}

namespace {
  define('ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-no-web-root' . DIRECTORY_SEPARATOR);
  define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
  function add_action(...$args): void {}
  function sanitize_email($value): string { return (string) $value; }
  function get_option($key, $default = false) { return $default; }
  function wp_date($format, $timestamp = null, $timezone = null): string { return '2026-10-05 12:00:00'; }
  function wp_mail($to, $subject, $body, $headers = [], $attachments = []) {
    $attachment_text = '';
    foreach ($attachments as $attachment) {
      if (is_readable($attachment)) $attachment_text .= file_get_contents($attachment);
    }
    $GLOBALS['fixture_mail'][] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'attachment' => $attachment_text];
    return ($GLOBALS['fixture_mail_result'] ?? true);
  }

  $root = $argv[1] ?? dirname(__DIR__);
  require ($argv[2] ?? $root . '/includes/modules/grosses/includes/class-roxy-grosses-reporter.php');

  function saved_report_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    $GLOBALS['fixture_checks']++;
    echo "PASS: {$message}\n";
  }

  $original_entries = [['id' => 81, 'movie_title' => 'Manually corrected live row', 'is_locked' => 1, 'gross_total' => 999.99]];
  $original_history = [['report_date' => '2026-09-30', 'film_title' => 'Current history row', 'gross_total' => 456.78]];
  \RoxyGrosses\Store::$entries = $original_entries;
  \RoxyGrosses\Store::$history = $original_history;
  \RoxyGrosses\Store::$snapshot = [
    'report_end_date' => '2026-09-30',
    'summary' => ['gross_total' => 12.34, 'total_tickets' => 2],
    'rows' => [[
      'report_date' => '2026-09-30', 'show_time' => '19:00', 'film_title' => 'Saved snapshot title',
      'general_qty' => 2, 'discount_qty' => 0, 'group_qty' => 0, 'gross_total' => 12.34,
    ]],
  ];

  $GLOBALS['fixture_checks'] = 0;
  $GLOBALS['fixture_mail'] = [];
  $GLOBALS['fixture_mail_result'] = true;
  $result = \RoxyGrosses\Reporter::send_saved_report(41);
  saved_report_assert(!empty($result['success']), 'saved report sends successfully');
  saved_report_assert(str_contains($GLOBALS['fixture_mail'][0]['body'], 'Saved snapshot title') && str_contains($GLOBALS['fixture_mail'][0]['attachment'], 'Saved snapshot title'), 'email body and CSV use saved snapshot, not regenerated data');
  saved_report_assert(\RoxyGrosses\Store::$entries === $original_entries && \RoxyGrosses\Store::$history === $original_history && \RoxyGrosses\Store::$upsert_calls === 0, 'successful resend performs zero entry/history mutations');
  saved_report_assert(\RoxyGrosses\Store::$emailed === [41] && count(\RoxyGrosses\Store::$logs) === 1 && ($GLOBALS['fixture_status']['mode'] ?? '') === 'saved-report', 'successful resend keeps emailed marker, log, and status');

  \RoxyGrosses\Store::$logs = [];
  \RoxyGrosses\Store::$emailed = [];
  $GLOBALS['fixture_status'] = [];
  $GLOBALS['fixture_mail'] = [];
  $GLOBALS['fixture_mail_result'] = false;
  $result = \RoxyGrosses\Reporter::send_saved_report(41);
  saved_report_assert(empty($result['success']), 'email failure is reported');
  saved_report_assert(\RoxyGrosses\Store::$entries === $original_entries && \RoxyGrosses\Store::$history === $original_history && \RoxyGrosses\Store::$upsert_calls === 0, 'failed send performs zero entry/history mutations');
  saved_report_assert(\RoxyGrosses\Store::$emailed === [] && count(\RoxyGrosses\Store::$logs) === 1 && $GLOBALS['fixture_status'] === [], 'failed send keeps failure log but does not mark emailed or update status');

  echo $GLOBALS['fixture_checks'] . " saved-report read-only checks passed; all mail is intercepted locally.\n";
}
