<?php
/** Reporter orchestration regression: fresh email must not upsert financial allocations. */
namespace FreshEmailAllocationFixture {
  final class Settings {
    public static array $status = [];
    public static function get(string $key, $default = '') { return $default; }
    public static function email_list(): array { return ['fresh-email-fixture@example.invalid']; }
    public static function admin_email(): string { return ''; }
    public static function get_report_timezone(): string { return 'UTC'; }
    public static function set_status(array $status): void { self::$status = $status; }
  }
  final class Square {
    public static function with_sale_snapshot(callable $operation) { return $operation(); }
  }
  final class Store {
    public static array $protected_financial_rows = [];
    public static array $history_calls = [];
    public static array $reports = [];
    public static array $logs = [];
    public static int $next_id = 1;
    public static function with_refund_review_lock(callable $operation) { return $operation(); }
    public static function assert_refund_review_lock(): void {}
    public static function upsert_entries(...$args): array { throw new \RuntimeException('Fresh report email attempted to upsert financial entries.'); }
    public static function upsert_history_rows(array $rows, string $mode, ?int $report_id): array {
      self::$history_calls[] = [$rows, $mode, $report_id];
      return ['created'=>0,'updated'=>0,'skipped'=>count($rows)];
    }
    public static function create_report(string $date, int $lookback, string $mode, string $status, array $summary, array $rows): int {
      $id = self::$next_id++;
      self::$reports[$id] = compact('date','lookback','mode','status','summary','rows');
      return $id;
    }
    public static function insert_log(...$args): int { self::$logs[] = $args; return count(self::$logs); }
  }
}

namespace {
  if (!defined('ABSPATH')) define('ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-no-web-root' . DIRECTORY_SEPARATOR);
  if (!defined('WP_CONTENT_DIR')) define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
  $candidate = $argv[1] ?? (dirname(__DIR__).'/includes/modules/grosses/includes/class-roxy-grosses-reporter.php');
  if (!is_file($candidate)) throw new \RuntimeException('Reporter candidate file missing: '.$candidate);
  $source = file_get_contents($candidate);
  if (!is_string($source)) throw new \RuntimeException('Could not read Reporter candidate.');
  $build_call = '$reports = self::build_reports($report_date);';
  $replace_method_build = static function (string $source, string $method) use ($build_call): string {
    $declaration = 'function '.$method.'(';
    $start = strpos($source, $declaration);
    if ($start === false) throw new \RuntimeException('Missing orchestration method '.$method.'.');
    $next = preg_match('/\n  (?:public|private|protected) static function /', $source, $match, PREG_OFFSET_CAPTURE, $start + strlen($declaration))
      ? $match[0][1] : strlen($source);
    $body = substr($source, $start, $next - $start);
    if (substr_count($body, $build_call) !== 1) throw new \RuntimeException('Expected exactly one report-builder boundary in '.$method.'.');
    $body = str_replace($build_call, '$reports = $GLOBALS["fresh_email_fixture_rows"];', $body);
    return substr_replace($source, $body, $start, $next - $start);
  };
  $source = $replace_method_build($source, 'send_report_locked');
  $source = $replace_method_build($source, 'save_report_draft_snapshot');
  $source = str_replace('namespace RoxyGrosses;', 'namespace FreshEmailAllocationFixture;', $source);
  eval('?>'.$source);

  function wp_date(string $format, $timestamp = null, ?\DateTimeZone $timezone = null): string {
    $date = $timestamp === null ? new \DateTimeImmutable('now', $timezone ?: new \DateTimeZone('UTC')) : (new \DateTimeImmutable('@'.(string)$timestamp))->setTimezone($timezone ?: new \DateTimeZone('UTC'));
    return $date->format($format);
  }
  function get_option($key, $default = false) { return $default; }
  function sanitize_email(string $email): string { return $email; }
  $GLOBALS['fresh_email_mail_result'] = true;
  $GLOBALS['fresh_email_mail_calls'] = [];
  function wp_mail($to, $subject, $message, $headers = [], $attachments = []): bool {
    $contents = [];
    foreach ((array)$attachments as $path) $contents[] = is_file($path) ? file_get_contents($path) : false;
    $GLOBALS['fresh_email_mail_calls'][] = compact('to','subject','message','headers','attachments','contents');
    return (bool)$GLOBALS['fresh_email_mail_result'];
  }
  $check = static function (bool $ok, string $label): void {
    if (!$ok) throw new \RuntimeException('FAIL: '.$label);
    echo 'PASS: '.$label."\n";
  };

  // Fresh movie-only builder row incorrectly contains all $10 concessions; existing movie/live rows are balanced at $5 each.
  $GLOBALS['fresh_email_fixture_rows'] = [
    ['report_date'=>'2038-05-12','show_time'=>'19:00','theater_name'=>'Fixture Theater','film_title'=>'Fixture Movie','general_qty'=>5,'discount_qty'=>0,'group_qty'=>0,'total_tickets'=>5,'gross_total'=>50.00,'concessions_total'=>10.00,'source_type'=>'square_auto'],
  ];
  $store = '\\FreshEmailAllocationFixture\\Store';
  $reporter = '\\FreshEmailAllocationFixture\\Reporter';
  $protected = [
    'movie'=>['general_qty'=>5,'gross_total'=>50.00,'concessions_total'=>5.00,'category_gross'=>['general'=>50.00],'manual_lock'=>false],
    'live'=>['general_qty'=>5,'gross_total'=>30.00,'concessions_total'=>5.00,'category_gross'=>['general'=>30.00],'manual_lock'=>false],
    'rental'=>['general_qty'=>0,'gross_total'=>0.00,'concessions_total'=>0.00,'manual_lock'=>true],
  ];
  $store::$protected_financial_rows = $protected;
  $protected_concessions = static function () use ($store): float {
    return array_sum(array_map(static fn($row) => (float)($row['concessions_total'] ?? 0), $store::$protected_financial_rows));
  };
  $check($protected_concessions()===10.0, 'fixture begins with balanced $5 movie plus $5 live concessions allocations');

  // Success accepts the actual report snapshot/history/log path while financial upserts are forbidden.
  $GLOBALS['fresh_email_mail_result'] = true;
  $success = $reporter::send_report('2038-05-12', 'fixture');
  $check(!empty($success['success']) && count($GLOBALS['fresh_email_mail_calls'])===1, 'fresh report sends through intercepted mail: ' . ($success['message'] ?? 'missing result'));
  $attachment = $GLOBALS['fresh_email_mail_calls'][0]['attachments'][0] ?? '';
  $csv = $GLOBALS['fresh_email_mail_calls'][0]['contents'][0] ?? false;
  $check(is_string($csv) && str_contains($csv, 'Fixture Movie') && !str_contains($csv, 'Fixture Live Event') && !str_contains($csv, 'Fixture Rental'), 'intercepted attachment contains only the fresh movie row');
  $check(!file_exists($attachment), 'private CSV attachment is removed after intercepted send');
  $saved = reset($store::$reports);
  $check(is_array($saved) && $saved['status']==='emailed' && $saved['rows']===$GLOBALS['fresh_email_fixture_rows'], 'successful email stores the exact fresh report snapshot');
  $check(count($store::$history_calls)===2 && $store::$history_calls[1][2]===1, 'history snapshot calls remain allowed and report-linked history follows successful send');
  $check($store::$protected_financial_rows===$protected && $protected_concessions()===10.0, 'balanced and manually protected $5/$5 concessions allocations remain unchanged after success');

  // Draft orchestration uses the same fresh rows but sends no mail and cannot rewrite protected financial entries.
  $mail_count_before_draft = count($GLOBALS['fresh_email_mail_calls']);
  $history_before_draft = count($store::$history_calls);
  $draft = $reporter::save_report_draft('2038-05-12', 'fixture-review');
  $draft_id = (int)($draft['report_id'] ?? 0);
  $draft_snapshot = $store::$reports[$draft_id] ?? null;
  $check(!empty($draft['success']) && $draft_id>0 && is_array($draft_snapshot) && $draft_snapshot['status']==='draft' && $draft_snapshot['rows']===$GLOBALS['fresh_email_fixture_rows'], 'draft orchestration stores the exact fixture snapshot');
  $check(count($GLOBALS['fresh_email_mail_calls'])===$mail_count_before_draft, 'saving a draft does not invoke email');
  $check(count($store::$history_calls)===$history_before_draft+1 && $store::$history_calls[array_key_last($store::$history_calls)][2]===$draft_id, 'draft history is retained through the allowed report-linked history path');
  $check($store::$protected_financial_rows===$protected && $protected_concessions()===10.0, 'draft leaves balanced and manually protected $5/$5 concessions allocations unchanged');

  // A mail failure may log/retain the attempted history path, but cannot create an emailed snapshot or mutate protected entries.
  $before_reports = $store::$reports;
  $before_financial = $store::$protected_financial_rows;
  $before_history_count = count($store::$history_calls);
  $GLOBALS['fresh_email_mail_result'] = false;
  $failure = $reporter::send_report('2038-05-12', 'fixture');
  $check(empty($failure['success']) && count($GLOBALS['fresh_email_mail_calls'])===2, 'mail failure is returned and intercepted without real delivery');
  $failed_attachment = $GLOBALS['fresh_email_mail_calls'][1]['attachments'][0] ?? '';
  $check(!file_exists($failed_attachment), 'private CSV attachment is removed after failed intercepted send');
  $check($store::$reports===$before_reports, 'mail failure creates no emailed report snapshot');
  $check(count($store::$history_calls)===$before_history_count+1, 'pre-send history bookkeeping remains allowed on mail failure');
  $check($store::$protected_financial_rows===$before_financial && $protected_concessions()===10.0, 'mail failure leaves balanced and manually protected $5/$5 concessions allocations unchanged');

  // Empty fresh results fail before report/history/mail persistence; an error log is expected diagnostic bookkeeping.
  $before_empty_reports = $store::$reports;
  $before_empty_history = count($store::$history_calls);
  $before_empty_financial = $store::$protected_financial_rows;
  $before_empty_mail = count($GLOBALS['fresh_email_mail_calls']);
  $GLOBALS['fresh_email_fixture_rows'] = [];
  $empty = $reporter::send_report('2038-05-12', 'fixture');
  $check(empty($empty['success']), 'no-ticket/no-refund fresh result is rejected');
  $check($store::$reports===$before_empty_reports && count($store::$history_calls)===$before_empty_history, 'empty result creates no report snapshot or history write');
  $check(count($GLOBALS['fresh_email_mail_calls'])===$before_empty_mail, 'empty result sends no email');
  $check($store::$protected_financial_rows===$before_empty_financial && $protected_concessions()===10.0, 'empty result leaves protected financial rows and $5/$5 concessions unchanged');
  $check(count($store::$logs)===4 && $store::$logs[0][4]===true && $store::$logs[1][4]===true && $store::$logs[2][4]===false && $store::$logs[3][4]===false, 'email/draft successes and both failure outcomes are recorded through allowed logs');
  echo "Passed fresh email/draft allocation orchestration checks; builders replaced only at guarded method boundaries.\n";
}
