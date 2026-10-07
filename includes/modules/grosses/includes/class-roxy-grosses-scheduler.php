<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Scheduler {
  private const REPORT_HOOK = 'roxy_grosses_scheduled_send';
  private const ADVERTISER_HOOK = 'roxy_grosses_monthly_advertiser_send';
  private const LAST_AUTO_DATE_KEY = 'roxy_grosses_last_auto_date';
  private const LAST_ADVERTISER_MONTH_KEY = 'roxy_grosses_last_advertiser_month';
  private const LAST_SYNC_RESULT_KEY = 'roxy_grosses_last_scheduled_sync_result';
  private const LAST_ADVERTISER_RESULT_KEY = 'roxy_grosses_last_advertiser_send_result';

  public static function init(): void {
    add_action(self::REPORT_HOOK, [__CLASS__, 'run_scheduled_send']);
    add_action(self::ADVERTISER_HOOK, [__CLASS__, 'run_monthly_advertiser_send']);
    add_action('init', [__CLASS__, 'ensure_schedule'], 30);
  }

  public static function sync_schedule(?array $settings = null, ?\DateTimeImmutable $now = null): void {
    $settings = is_array($settings) ? $settings : Settings::get_all();
    if (!self::clear_schedule()) {
      self::log_schedule_repair(false, 'Could not clear the prior gross schedule while applying settings.', ['changes' => ['settings' => 'clear_failed']]);
      return;
    }
    $timezone = (string) ($settings['report_timezone'] ?? Settings::get_report_timezone());
    $now = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->setTimezone(new \DateTimeZone($timezone));
    $failed = [];

    if (($settings['schedule_enabled'] ?? '0') === '1') {
      if (!self::schedule_single(self::next_run_timestamp((string) ($settings['schedule_time'] ?? '22:00'), $timezone, $now), self::REPORT_HOOK)) $failed[] = 'report';
    }

    if (($settings['advertiser_schedule_enabled'] ?? '0') === '1') {
      if (!self::schedule_single(self::next_run_timestamp((string) ($settings['advertiser_schedule_time'] ?? '09:00'), $timezone, $now), self::ADVERTISER_HOOK)) $failed[] = 'advertiser';
    }

    if ($failed) {
      self::log_schedule_repair(false, 'Could not register one or more gross schedule events.', ['changes' => array_fill_keys($failed, 'schedule_failed')]);
    }
  }

  public static function ensure_schedule($settings = null, ?\DateTimeImmutable $now = null): void {
    if (wp_installing()) {
      return;
    }

    $settings = is_array($settings) ? $settings : Settings::get_all();
    $timezone = (string) ($settings['report_timezone'] ?? Settings::get_report_timezone());
    $now = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->setTimezone(new \DateTimeZone($timezone));
    $changes = [];
    $failures = [];

    foreach ([
      'report' => [self::REPORT_HOOK, ($settings['schedule_enabled'] ?? '0') === '1', (string) ($settings['schedule_time'] ?? '22:00')],
      'advertiser' => [self::ADVERTISER_HOOK, ($settings['advertiser_schedule_enabled'] ?? '0') === '1', (string) ($settings['advertiser_schedule_time'] ?? '09:00')],
    ] as $name => [$hook, $enabled, $time]) {
      try {
        $result = self::ensure_hook($hook, $enabled, $time, $timezone, $now);
      } catch (\Throwable $error) {
        $result = ['success' => false, 'change' => null, 'error' => $error->getMessage()];
      }
      if (!empty($result['change'])) $changes[$name] = $result['change'];
      if (empty($result['success'])) $failures[$name] = $result['error'] ?? 'WP-Cron operation failed.';
    }

    if ($failures) {
      self::log_schedule_repair(false, 'Grosses schedule repair failed; one or more hooks may need attention.', [
        'changes' => $changes,
        'failures' => $failures,
        'report_enabled' => ($settings['schedule_enabled'] ?? '0') === '1',
        'advertiser_enabled' => ($settings['advertiser_schedule_enabled'] ?? '0') === '1',
      ]);
    } elseif ($changes) {
      try {
        self::log_schedule_repair(true, 'Grosses scheduler automatically repaired cron registration.', [
          'changes' => $changes,
          'report_enabled' => ($settings['schedule_enabled'] ?? '0') === '1',
          'advertiser_enabled' => ($settings['advertiser_schedule_enabled'] ?? '0') === '1',
          'report_next_gmt' => self::scheduled_time_iso(self::REPORT_HOOK),
          'advertiser_next_gmt' => self::scheduled_time_iso(self::ADVERTISER_HOOK),
        ]);
      } catch (\Throwable $error) {
        self::log_schedule_repair(false, 'Grosses scheduler repaired events but could not verify/log the resulting schedule.', [
          'changes' => $changes,
          'error' => $error->getMessage(),
        ]);
      }
    }
  }

  public static function clear_schedule(): bool {
    $success = true;
    foreach ([self::REPORT_HOOK, self::ADVERTISER_HOOK] as $hook) {
      if (!self::unschedule_hook($hook)['success']) $success = false;
    }
    return $success;
  }

  public static function run_scheduled_send(): void {
    $run_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
    $started_at = current_time('mysql', true);
    self::record_run_result(self::LAST_SYNC_RESULT_KEY, [
      'version' => 1, 'run_id' => (string) $run_id, 'status' => 'running',
      'started_at' => (string) $started_at, 'completed_at' => '', 'message' => '',
    ]);
    try {
      $settings = Settings::get_all();
      if (($settings['schedule_enabled'] ?? '0') !== '1') {
        self::record_run_result(self::LAST_SYNC_RESULT_KEY, [
          'version' => 1, 'run_id' => (string) $run_id, 'status' => 'skipped',
          'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
          'message' => 'Automatic scheduling is disabled.',
        ]);
        Store::insert_log('scheduled_sync', 'scheduled-sync', null, null, false, 'Scheduled grosses send fired while automatic scheduling was disabled.');
        return;
      }

      $timezone = new \DateTimeZone(Settings::get_report_timezone());
      $now = new \DateTimeImmutable('now', $timezone);
      $report_date = $now->format('Y-m-d');

      if (get_option(self::LAST_AUTO_DATE_KEY) === $report_date) {
        self::record_run_result(self::LAST_SYNC_RESULT_KEY, [
          'version' => 1, 'run_id' => (string) $run_id, 'status' => 'skipped',
          'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
          'message' => 'This report date already completed.',
        ]);
        Store::insert_log('scheduled_sync', 'scheduled-sync', null, $report_date, true, 'Scheduled grosses sync skipped because this report date already completed.');
        return;
      }

      $result = self::run_for_date($report_date, 'scheduled-sync', true);
      self::record_run_result(self::LAST_SYNC_RESULT_KEY, [
        'version' => 1, 'run_id' => (string) $run_id,
        'status' => !empty($result['success']) ? 'completed' : 'failed',
        'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
        'message' => (string) ($result['message'] ?? ''),
      ]);
    } catch (\Throwable $error) {
      self::record_run_result(self::LAST_SYNC_RESULT_KEY, [
        'version' => 1, 'run_id' => (string) $run_id, 'status' => 'failed',
        'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
        'message' => self::error_text($error),
      ]);
      throw $error;
    } finally {
      self::ensure_schedule();
    }
  }

  public static function run_now(?string $report_date = null): array {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $report_date = $report_date ?: (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
    return self::run_for_date($report_date, 'run-now', true);
  }

  public static function run_monthly_advertiser_send(): void {
    $run_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
    $started_at = current_time('mysql', true);
    self::record_run_result(self::LAST_ADVERTISER_RESULT_KEY, [
      'version' => 1, 'run_id' => (string) $run_id, 'status' => 'running',
      'started_at' => (string) $started_at, 'completed_at' => '', 'message' => '',
    ]);
    try {
      $settings = Settings::get_all();
      if (($settings['advertiser_schedule_enabled'] ?? '0') !== '1') {
        self::record_run_result(self::LAST_ADVERTISER_RESULT_KEY, [
          'version' => 1, 'run_id' => (string) $run_id, 'status' => 'skipped',
          'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
          'message' => 'Advertiser scheduling is disabled.',
        ]);
        return;
      }

      $timezone = new \DateTimeZone(Settings::get_report_timezone());
      $now = new \DateTimeImmutable('now', $timezone);
      $configured_day = max(1, min(31, (int) ($settings['advertiser_schedule_day'] ?? 1)));
      $scheduled_day = min($configured_day, (int) $now->format('t'));
      if ((int) $now->format('j') !== $scheduled_day) {
        self::record_run_result(self::LAST_ADVERTISER_RESULT_KEY, [
          'version' => 1, 'run_id' => (string) $run_id, 'status' => 'skipped',
          'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
          'message' => 'The advertiser schedule is not due today.',
        ]);
        return;
      }

      $target = $now->modify('first day of last month');
      $month_key = $target->format('Y-m');

      if (get_option(self::LAST_ADVERTISER_MONTH_KEY) === $month_key) {
        self::record_run_result(self::LAST_ADVERTISER_RESULT_KEY, [
          'version' => 1, 'run_id' => (string) $run_id, 'status' => 'skipped',
          'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
          'message' => 'This advertiser month already completed.',
        ]);
        return;
      }

      $result = Workbook::send_advertiser_summary((int) $target->format('Y'), (int) $target->format('m'), 'scheduled-advertiser');
      if (!empty($result['success'])) {
        self::record_run_result(self::LAST_ADVERTISER_RESULT_KEY, [
          'version' => 1, 'run_id' => (string) $run_id, 'status' => 'completed',
          'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
          'message' => (string) ($result['message'] ?? ''),
        ]);
        update_option(self::LAST_ADVERTISER_MONTH_KEY, $month_key);
        Store::insert_log('advertiser_send', 'scheduled-advertiser', null, $target->format('Y-m-01'), true, 'Scheduled advertiser summary sent successfully.', [
          'month' => $month_key,
        ]);
      } else {
        self::record_run_result(self::LAST_ADVERTISER_RESULT_KEY, [
          'version' => 1, 'run_id' => (string) $run_id, 'status' => 'failed',
          'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
          'message' => (string) ($result['message'] ?? 'Scheduled advertiser summary failed.'),
        ]);
        Store::insert_log('advertiser_send', 'scheduled-advertiser', null, $target->format('Y-m-01'), false, (string) ($result['message'] ?? 'Scheduled advertiser summary failed.'), [
          'month' => $month_key,
        ]);
      }
    } catch (\Throwable $error) {
      self::record_run_result(self::LAST_ADVERTISER_RESULT_KEY, [
        'version' => 1, 'run_id' => (string) $run_id, 'status' => 'failed',
        'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
        'message' => self::error_text($error),
      ]);
      throw $error;
    } finally {
      self::ensure_schedule();
    }
  }

  public static function report_hook(): string {
    return self::REPORT_HOOK;
  }

  public static function advertiser_hook(): string {
    return self::ADVERTISER_HOOK;
  }

  public static function scheduled_time_iso(string $hook): string {
    $timestamp = wp_next_scheduled($hook);
    if (!$timestamp) {
      return '';
    }

    return gmdate('Y-m-d H:i:s', (int) $timestamp);
  }

  public static function scheduled_time_local(string $hook): string {
    $timestamp = wp_next_scheduled($hook);
    if (!$timestamp) {
      return '';
    }

    return wp_date('Y-m-d H:i:s T', (int) $timestamp, new \DateTimeZone(Settings::get_report_timezone()));
  }

  private static function unschedule_hook(string $hook): array {
    $changed = false;
    try {
      $timestamp = wp_next_scheduled($hook);
      while ($timestamp) {
        $result = wp_unschedule_event($timestamp, $hook);
        if (!self::cron_operation_succeeded($result)) return ['success' => false, 'changed' => $changed];
        $changed = true;
        $next = wp_next_scheduled($hook);
        if ($next === $timestamp) return ['success' => false, 'changed' => $changed];
        $timestamp = $next;
      }
    } catch (\Throwable $error) {
      return ['success' => false, 'changed' => $changed];
    }
    return ['success' => true, 'changed' => $changed];
  }

  private static function ensure_hook(string $hook, bool $enabled, string $time, string $timezone, \DateTimeImmutable $now): array {
    if (!$enabled) {
      $cleared = self::unschedule_hook($hook);
      return ['success' => $cleared['success'], 'change' => $cleared['changed'] ? 'cleared' : null, 'error' => 'Could not clear disabled schedule.'];
    }

    $event = wp_get_scheduled_event($hook);
    if ($event && empty($event->schedule)) {
      $event_local_time = (new \DateTimeImmutable('@' . (int) $event->timestamp))->setTimezone(new \DateTimeZone($timezone))->format('H:i');
      if ($event_local_time === $time) {
        return ['success' => true, 'change' => null];
      }
    }

    $cleared = self::unschedule_hook($hook);
    if (!$cleared['success']) return ['success' => false, 'change' => null, 'error' => 'Could not clear stale or recurring event.'];
    $timestamp = self::next_run_timestamp($time, $timezone, $now);
    if (!self::schedule_single($timestamp, $hook)) return ['success' => false, 'change' => null, 'error' => 'Could not register replacement single event.'];

    return ['success' => true, 'change' => $event ? 'replaced' : 'scheduled'];
  }

  private static function schedule_single(int $timestamp, string $hook): bool {
    try {
      return self::cron_operation_succeeded(wp_schedule_single_event($timestamp, $hook));
    } catch (\Throwable $error) {
      return false;
    }
  }

  private static function cron_operation_succeeded($result): bool {
    return $result === true;
  }

  private static function log_schedule_repair(bool $success, string $message, array $context): void {
    try {
      Store::insert_log('schedule_repair', 'self-heal', null, null, $success, $message, $context);
    } catch (\Throwable $error) {
      // Scheduler repair/logging must not break WordPress bootstrap.
    }
  }

  private static function record_run_result(string $key, array $result): void {
    try {
      if (function_exists('update_option')) update_option($key, $result, false);
    } catch (\Throwable $error) {
      // Telemetry must never change the report or email outcome.
    }
  }

  private static function error_text(\Throwable $error): string {
    $message = (string) $error->getMessage();
    if (function_exists('sanitize_text_field')) return sanitize_text_field($message);
    return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);
  }

  private static function next_run_timestamp(string $time, string $timezone, ?\DateTimeImmutable $now = null): int {
    $tz = new \DateTimeZone($timezone);
    $now = ($now ?? new \DateTimeImmutable('now', $tz))->setTimezone($tz);
    [$hour, $minute] = array_pad(array_map('intval', explode(':', $time)), 2, 0);
    $next = $now->setTime($hour, $minute, 0);

    if ($next <= $now) {
      // Reapply the requested clock on the next calendar date: today's DST
      // gap may have normalized 02:30 to 03:30, which must not leak tomorrow.
      $next = $now->modify('+1 day')->setTime($hour, $minute, 0);
    }

    return $next->getTimestamp();
  }

  private static function run_for_date(string $report_date, string $mode, bool $mark_complete): array {
    $sync_result = Reporter::sync_automatic_tables($report_date, $mode);
    if (empty($sync_result['success'])) {
      return $sync_result;
    }

    if (($sync_result['movie_paid_rows'] ?? 0) > 0) {
      $result = Reporter::send_report($report_date, $mode === 'scheduled-sync' ? 'scheduled' : $mode);
      if (!empty($result['success']) && $mark_complete) {
        update_option(self::LAST_AUTO_DATE_KEY, $report_date);
      }
      return $result;
    }

    $message = sprintf(
      'Automatic sync completed for %s. Movies: %d row(s), Live Shows: %d row(s). No paid movie ticket rows were found, so no grosses email was sent.',
      $report_date,
      (int) ($sync_result['movie_rows'] ?? 0),
      (int) ($sync_result['live_rows'] ?? 0)
    );

    Store::insert_log('scheduled_sync', $mode, null, $report_date, true, $message, [
      'movie_rows' => (int) ($sync_result['movie_rows'] ?? 0),
      'movie_paid_rows' => (int) ($sync_result['movie_paid_rows'] ?? 0),
      'live_rows' => (int) ($sync_result['live_rows'] ?? 0),
    ]);
    if ($mark_complete) {
      update_option(self::LAST_AUTO_DATE_KEY, $report_date);
    }

    return [
      'success' => true,
      'message' => $message,
      'movie_rows' => (int) ($sync_result['movie_rows'] ?? 0),
      'movie_paid_rows' => (int) ($sync_result['movie_paid_rows'] ?? 0),
      'live_rows' => (int) ($sync_result['live_rows'] ?? 0),
    ];
  }
}
