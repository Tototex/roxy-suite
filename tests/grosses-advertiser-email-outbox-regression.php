<?php
// Standalone advertiser email guard test. All mail is intercepted; no provider/database use.
namespace RoxyGrosses {
  final class Settings {
    public static function advertiser_email_list(): array { return ['advertiser@example.invalid']; }
    public static function admin_email(): string { return 'admin@example.invalid'; }
    public static function get_report_timezone(): string { return 'UTC'; }
    public static function studio_mappings(): array { return []; }
    public static function get(string $key, $default = '') {
      return match ($key) {
        'advertiser_email_subject' => 'Roxy advertiser summary for {month_name} {year}',
        'advertiser_email_body' => 'Attached is the advertiser summary workbook for {month_name} {year}.',
        'theater_name' => 'Fixture Theater',
        default => $default,
      };
    }
  }
  final class Store {
    public static array $logs = [];
    public static function entry_rows_for_year(int $year): array {
      return $year === 2026 ? [
        ['report_date'=>'2026-08-15','film_title'=>'August Fixture','show_time'=>'19:00','general_qty'=>2,'discount_qty'=>0,'group_qty'=>0,'total_tickets'=>2,'gross_total'=>20.00],
        ['report_date'=>'2026-09-12','film_title'=>'Fixture Film','show_time'=>'19:00','general_qty'=>3,'discount_qty'=>1,'group_qty'=>0,'total_tickets'=>4,'gross_total'=>40.00],
      ] : [];
    }
    public static function insert_log(...$args): int { self::$logs[]=$args; return count(self::$logs); }
  }
  final class Reporter {
    public static function notify_admin_failure(...$args): void {}
  }
  final class EmailOutbox {
    public static array $rows = [];
    public static int $next_id = 1;
    public static function claim(string $key, string $kind, int $source_id, ?string $report_date, array $payload, array $context): array {
      if (isset(self::$rows[$key])) return ['claimed'=>false,'id'=>self::$rows[$key]['id'],'status'=>self::$rows[$key]['status']];
      $id=self::$next_id++;
      self::$rows[$key]=['id'=>$id,'status'=>'sending','payload'=>$payload,'context'=>$context];
      return ['claimed'=>true,'id'=>$id,'status'=>'sending'];
    }
    public static function finish(int $id, string $status, string $error = ''): bool {
      foreach (self::$rows as &$row) if ($row['id']===$id) { $row['status']=$status; return true; }
      return false;
    }
  }
}

namespace {
  if (!defined('ABSPATH')) define('ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR);
  if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
  $private_content_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-advertiser-private-' . bin2hex(random_bytes(8));
  if (!mkdir($private_content_dir, 0700)) throw new RuntimeException('Could not create private workbook test root.');
  define('WP_CONTENT_DIR', $private_content_dir);
  function trailingslashit($path): string { return rtrim((string) $path, '/\\') . DIRECTORY_SEPARATOR; }
  function wp_mkdir_p($path): bool { return is_dir($path) || mkdir($path, 0700, true); }
  function wp_date($format, $timestamp = null, $timezone = null): string { return '2026-10-07 12:00:00'; }
  function is_email($email): bool { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
  function wp_mail($to, $subject, $body, $headers = [], $attachments = []): bool {
    $attachment = $attachments[0] ?? '';
    $GLOBALS['advertiser_mail_calls'][] = ['to'=>$to,'subject'=>$subject,'body'=>$body,'headers'=>$headers,'attachment'=>$attachment];
    if (!is_file($attachment) || file_get_contents($attachment) !== 'private advertiser workbook fixture') {
      throw new RuntimeException('Unexpected advertiser attachment.');
    }
    if (!empty($GLOBALS['advertiser_mail_throw'])) throw new RuntimeException('fixture mail transport exception');
    return (bool) ($GLOBALS['advertiser_mail_result'] ?? true);
  }

  $candidate = $argv[1] ?? dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-workbook.php';
  if (!is_file($candidate)) throw new RuntimeException('Workbook candidate is missing.');
  $source = file_get_contents($candidate);
  if (!is_string($source)) throw new RuntimeException('Could not read Workbook candidate.');
  $start = strpos($source, 'private static function write_simple_xlsx(');
  if ($start === false) throw new RuntimeException('Advertiser workbook writer not found.');
  $next = preg_match('/\n  (?:public|private|protected) static function /', $source, $match, PREG_OFFSET_CAPTURE, $start + 1)
    ? $match[0][1] : strlen($source);
  $method = substr($source, $start, $next - $start);
  $brace = strpos($method, '{');
  if ($brace === false) throw new RuntimeException('Advertiser workbook writer body not found.');
  $method = substr($method, 0, $brace) . '{ $GLOBALS[\'advertiser_fixture_files\'][] = $path; if (file_put_contents($path, \'private advertiser workbook fixture\') === false) throw new RuntimeException(\'Could not write advertiser fixture attachment.\'); }' . "\n";
  $source = substr_replace($source, $method, $start, $next - $start);
  eval('?>' . $source);

  $GLOBALS['advertiser_fixture_files'] = [];
  $GLOBALS['advertiser_mail_calls'] = [];
  $GLOBALS['advertiser_mail_result'] = true;
  $checks = 0;
  $check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
    echo "PASS: {$message}\n";
  };

  try {
    $first = \RoxyGrosses\Workbook::send_advertiser_summary(2026, 9, 'manual-advertiser', 2026, 9);
    $first_count = count($GLOBALS['advertiser_mail_calls']);
    $second = \RoxyGrosses\Workbook::send_advertiser_summary(2026, 9, 'scheduled-advertiser', 2026, 9);
    $check(!empty($first['success']) && $first_count === 1, 'first advertiser email sends through intercepted mail' . (empty($first['success']) ? ': ' . (string) ($first['message'] ?? 'no error detail') : ''));
    $check(!empty($second['success']) && !empty($second['duplicate_suppressed']) && count($GLOBALS['advertiser_mail_calls']) === 1, 'scheduled/manual advertiser duplicate is suppressed across modes');

    $resend_id = '123e4567-e89b-42d3-a456-426614174000';
    $resend = \RoxyGrosses\Workbook::send_advertiser_summary(2026, 9, 'manual-advertiser', 2026, 9, $resend_id, true);
    $resend_replay = \RoxyGrosses\Workbook::send_advertiser_summary(2026, 9, 'manual-advertiser', 2026, 9, $resend_id, true);
    $check(!empty($resend['success']) && count($GLOBALS['advertiser_mail_calls']) === 2, 'explicit advertiser resend uses a new confirmed logical key');
    $check(!empty($resend_replay['success']) && count($GLOBALS['advertiser_mail_calls']) === 2, 'replay of one intentional advertiser resend does not send twice');

    $GLOBALS['advertiser_mail_result'] = false;
    $uncertain = \RoxyGrosses\Workbook::send_advertiser_summary(2026, 8, 'manual-advertiser', 2026, 8);
    $uncertain_count = count($GLOBALS['advertiser_mail_calls']);
    $blocked_retry = \RoxyGrosses\Workbook::send_advertiser_summary(2026, 8, 'scheduled-advertiser', 2026, 8);
    $check(empty($uncertain['success']) && $uncertain_count === 3, 'unconfirmed advertiser mail outcome fails closed');
    $check(empty($blocked_retry['success']) && count($GLOBALS['advertiser_mail_calls']) === $uncertain_count, 'uncertain advertiser outcome blocks retry across modes');
    $GLOBALS['advertiser_mail_result'] = true;
    $GLOBALS['advertiser_mail_throw'] = true;
    $thrown = \RoxyGrosses\Workbook::send_advertiser_summary(2026, 8, 'manual-advertiser', 2026, 8, '123e4567-e89b-42d3-a456-426614174001', true);
    unset($GLOBALS['advertiser_mail_throw']);
    $check(empty($thrown['success']) && count($GLOBALS['advertiser_mail_calls']) === 4, 'thrown advertiser mail outcome is recorded as uncertain');
    $attachment_paths = array_column($GLOBALS['advertiser_mail_calls'], 'attachment');
    $check(count(array_unique($attachment_paths)) === count($attachment_paths), 'each advertiser send receives a distinct workbook attachment path');
    $check(count(array_filter($attachment_paths, 'is_file')) === 0, 'private advertiser attachments are removed after each mail attempt');
    echo "Passed {$checks} advertiser email outbox checks; all mail was intercepted.\n";
  } finally {
    foreach ($GLOBALS['advertiser_fixture_files'] as $fixture_file) if (is_file($fixture_file)) unlink($fixture_file);
    $advertiser_dir = $private_content_dir . DIRECTORY_SEPARATOR . 'roxy-grosses-private' . DIRECTORY_SEPARATOR . 'advertiser';
    foreach (['index.html', '.htaccess'] as $file) if (is_file($advertiser_dir . DIRECTORY_SEPARATOR . $file)) unlink($advertiser_dir . DIRECTORY_SEPARATOR . $file);
    if (is_dir($advertiser_dir)) rmdir($advertiser_dir);
    $private_root = dirname($advertiser_dir);
    foreach (['index.html', '.htaccess'] as $file) if (is_file($private_root . DIRECTORY_SEPARATOR . $file)) unlink($private_root . DIRECTORY_SEPARATOR . $file);
    if (is_dir($private_root)) rmdir($private_root);
    if (is_dir($private_content_dir)) rmdir($private_content_dir);
  }
}
