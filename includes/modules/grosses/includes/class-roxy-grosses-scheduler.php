<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Scheduler {
  private const REPORT_HOOK = 'roxy_grosses_scheduled_send';
  private const CLOSED_DAY_HOOK = 'roxy_grosses_closed_day_refresh';
  private const CLOSED_DAY_TIME = '01:00';
  private const ADVERTISER_HOOK = 'roxy_grosses_monthly_advertiser_send';
  private const LAST_AUTO_DATE_KEY = 'roxy_grosses_last_auto_date';
  private const LAST_ADVERTISER_MONTH_KEY = 'roxy_grosses_last_advertiser_month';
  private const LAST_SYNC_RESULT_KEY = 'roxy_grosses_last_scheduled_sync_result';
  private const LAST_ADVERTISER_RESULT_KEY = 'roxy_grosses_last_advertiser_send_result';
  private const CLOSED_DAY_PENDING_KEY = 'roxy_grosses_closed_day_refresh_queue';
  private const CLOSED_DAY_MAX_RETRIES = 3;
  private const CLOSED_DAY_RETRY_SECONDS = 900;

  public static function init(): void {
    add_action(self::REPORT_HOOK, [__CLASS__, 'run_scheduled_send'], 10, 1);
    add_action(self::CLOSED_DAY_HOOK, [__CLASS__, 'run_closed_day_refresh'], 10, 2);
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
      $timestamp = self::next_run_timestamp((string) ($settings['schedule_time'] ?? '22:00'), $timezone, $now);
      $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
      if (!self::schedule_single($timestamp, self::REPORT_HOOK, [$date])) $failed[] = 'report';
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
        $result = $name === 'report'
          ? self::ensure_report_hook($enabled, $time, $timezone, $now)
          : self::ensure_hook($hook, $enabled, $time, $timezone, $now);
      } catch (\Throwable $error) {
        $result = ['success' => false, 'change' => null, 'error' => $error->getMessage()];
      }
      if (!empty($result['change'])) $changes[$name] = $result['change'];
      if (empty($result['success'])) $failures[$name] = $result['error'] ?? 'WP-Cron operation failed.';
    }

    try {
      $queue_result = self::ensure_pending_closed_day_refreshes($now);
      if (empty($queue_result['success'])) $failures['closed_day_refresh'] = $queue_result['error'] ?? 'Could not restore a pending closed-day refresh.';
      if (!empty($queue_result['changed'])) $changes['closed_day_refresh'] = 'restored';
    } catch (\Throwable $error) {
      $failures['closed_day_refresh'] = $error->getMessage();
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
    // Settings changes must not cancel a closed-day review already queued for
    // an emailed provisional report.
    foreach ([self::REPORT_HOOK, self::ADVERTISER_HOOK] as $hook) {
      if (!self::unschedule_hook($hook)['success']) $success = false;
    }
    return $success;
  }

  public static function run_scheduled_send($intended_date = null): void {
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
      if (!self::valid_report_date($intended_date)) {
        self::record_run_result(self::LAST_SYNC_RESULT_KEY, [
          'version' => 1, 'run_id' => (string) $run_id, 'status' => 'failed',
          'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
          'message' => 'The legacy zero-argument report event was not migrated to an intended report date; it was skipped safely.',
        ]);
        Store::insert_log('scheduled_sync', 'scheduled-sync', null, null, false, 'A legacy zero-argument report event was skipped because its intended date was unavailable.');
        return;
      }
      $report_date = $intended_date;

      if (get_option(self::LAST_AUTO_DATE_KEY) === $report_date) {
        self::record_run_result(self::LAST_SYNC_RESULT_KEY, [
          'version' => 1, 'run_id' => (string) $run_id, 'status' => 'skipped',
          'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
          'message' => 'This report date already completed.',
        ]);
        Store::insert_log('scheduled_sync', 'scheduled-sync', null, $report_date, true, 'Scheduled grosses sync skipped because this report date already completed.');
        return;
      }

      // Persist the follow-up before report generation/mail so a process crash
      // cannot leave a completed provisional send without its closed-day check.
      if (!self::schedule_closed_day_refresh($report_date, $timezone, $now)) {
        Store::insert_log('closed_day_refresh', 'closed-day-refresh', null, $report_date, false, 'Could not schedule the after-midnight closed-day refresh.');
        self::record_run_result(self::LAST_SYNC_RESULT_KEY, [
          'version' => 1, 'run_id' => (string) $run_id, 'status' => 'failed',
          'started_at' => (string) $started_at, 'completed_at' => (string) current_time('mysql', true),
          'message' => 'The provisional email was withheld because its closed-day refresh could not be durably queued.',
        ]);
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

  public static function run_closed_day_refresh($intended_date = null, $attempt = 0): void {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $now = new \DateTimeImmutable('now', $timezone);
    if (!self::valid_report_date($intended_date) || !is_int($attempt) || $attempt < 0 || $attempt > self::CLOSED_DAY_MAX_RETRIES) {
      Store::insert_log('closed_day_refresh', 'closed-day-refresh', null, self::valid_report_date($intended_date) ? $intended_date : null, false, 'Closed-day refresh was skipped because its date or retry state is invalid.');
      return;
    }
    if ($intended_date >= $now->format('Y-m-d')) {
      Store::insert_log('closed_day_refresh', 'closed-day-refresh', null, $intended_date, false, 'Closed-day refresh was skipped because its intended date is not yet closed.');
      self::ensure_pending_closed_day_refreshes($now);
      return;
    }

    try {
      $result = Reporter::refresh_closed_day($intended_date);
      Store::insert_log('closed_day_refresh', 'closed-day-refresh', null, $intended_date, !empty($result['success']), (string) ($result['message'] ?? 'Closed-day refresh completed.'));
      if (!empty($result['success'])) {
        self::clear_pending_closed_day_refresh($intended_date);
      } else {
        self::retry_closed_day_refresh($intended_date, $attempt, (string) ($result['message'] ?? 'Closed-day refresh failed.'), $now);
      }
    } catch (\Throwable $error) {
      Store::insert_log('closed_day_refresh', 'closed-day-refresh', null, $intended_date, false, self::error_text($error));
      self::retry_closed_day_refresh($intended_date, $attempt, self::error_text($error), $now);
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

  public static function closed_day_hook(): string {
    return self::CLOSED_DAY_HOOK;
  }

  /** Read-only state for Health: a pending date without a matching event is not healthy. */
  public static function closed_day_refresh_health(): array {
    $queue = self::read_closed_day_queue();
    if (!is_array($queue)) return ['status' => 'failed', 'date' => '', 'attempt' => 0, 'scheduled' => false, 'message' => 'Closed-day refresh queue is unreadable.'];
    if (!$queue) return ['status' => 'idle', 'date' => '', 'attempt' => 0, 'scheduled' => false];
    ksort($queue);
    $date = (string) array_key_first($queue);
    $record = $queue[$date] ?? [];
    if (!self::valid_report_date($date) || !is_array($record)) return ['status' => 'failed', 'date' => $date, 'attempt' => 0, 'scheduled' => false, 'message' => 'Closed-day refresh queue contains malformed state.'];
    $attempt = max(0, (int) ($record['attempt'] ?? 0));
    $retry_at = max(0, (int) ($record['retry_at'] ?? 0));
    $events = self::scheduled_events(self::CLOSED_DAY_HOOK);
    $scheduled = false;
    foreach ($events as $event) if ($event['args'] === [$date, $attempt] && $event['timestamp'] >= $retry_at && empty($event['schedule'])) $scheduled = true;
    $status = ($record['status'] ?? '') === 'failed' ? 'failed' : ($scheduled ? 'scheduled' : 'unscheduled');
    return ['status' => $status, 'date' => $date, 'attempt' => $attempt, 'scheduled' => $scheduled, 'message' => (string) ($record['message'] ?? '')];
  }

  public static function scheduled_time_iso(string $hook): string {
    $events = self::scheduled_events($hook);
    $timestamp = $events[0]['timestamp'] ?? wp_next_scheduled($hook);
    if (!$timestamp) {
      return '';
    }

    return gmdate('Y-m-d H:i:s', (int) $timestamp);
  }

  public static function scheduled_time_local(string $hook): string {
    $events = self::scheduled_events($hook);
    $timestamp = $events[0]['timestamp'] ?? wp_next_scheduled($hook);
    if (!$timestamp) {
      return '';
    }

    return wp_date('Y-m-d H:i:s T', (int) $timestamp, new \DateTimeZone(Settings::get_report_timezone()));
  }

  private static function unschedule_hook(string $hook): array {
    $changed = false;
    try {
      if (function_exists('_get_cron_array')) {
        foreach (self::scheduled_events($hook) as $event) {
          if (!self::unschedule_event($hook, $event)) return ['success' => false, 'changed' => $changed];
          $changed = true;
        }
      } else {
        $timestamp = wp_next_scheduled($hook);
        while ($timestamp) {
          $result = wp_unschedule_event($timestamp, $hook);
          if (!self::cron_operation_succeeded($result)) return ['success' => false, 'changed' => $changed];
          $changed = true;
          $next = wp_next_scheduled($hook);
          if ($next === $timestamp) return ['success' => false, 'changed' => $changed];
          $timestamp = $next;
        }
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

  /** Migrate legacy no-argument sends and keep the intended calendar date in cron args. */
  private static function ensure_report_hook(bool $enabled, string $time, string $timezone, \DateTimeImmutable $now): array {
    if (!$enabled) {
      $cleared = self::unschedule_hook(self::REPORT_HOOK);
      return ['success' => $cleared['success'], 'change' => $cleared['changed'] ? 'cleared' : null, 'error' => 'Could not clear disabled report schedule.'];
    }

    $events = self::scheduled_events(self::REPORT_HOOK);
    $valid = [];
    foreach ($events as $event) {
      $args = $event['args'];
      $event_local = (new \DateTimeImmutable('@' . $event['timestamp']))->setTimezone(new \DateTimeZone($timezone));
      $date = $args[0] ?? null;
      $legacy = $args === [] && empty($event['schedule']) && $event_local->format('H:i') === $time;
      $dated = count($args) === 1 && self::valid_report_date($date) && $date === $event_local->format('Y-m-d') && empty($event['schedule']) && $event_local->format('H:i') === $time;
      if ($legacy || $dated) $valid[] = [$event, $event_local->format('Y-m-d'), $legacy];
    }

    if ($valid) {
      usort($valid, static fn(array $a, array $b): int => $a[0]['timestamp'] <=> $b[0]['timestamp']);
      [$keep, $date, $legacy] = $valid[0];
      $changed = false;
      foreach ($events as $event) {
        if ($event['timestamp'] === $keep['timestamp'] && $event['args'] === $keep['args']) continue;
        if (!self::unschedule_event(self::REPORT_HOOK, $event)) return ['success' => false, 'change' => null, 'error' => 'Could not remove duplicate or stale report event.'];
        $changed = true;
      }
      if ($legacy) {
        if (!self::unschedule_event(self::REPORT_HOOK, $keep)) return ['success' => false, 'change' => null, 'error' => 'Could not migrate legacy report event.'];
        if (!self::schedule_single($keep['timestamp'], self::REPORT_HOOK, [$date])) return ['success' => false, 'change' => null, 'error' => 'Could not register dated report event.'];
        return ['success' => true, 'change' => 'migrated', 'error' => ''];
      }
      return ['success' => true, 'change' => $changed ? 'repaired' : null, 'error' => ''];
    }

    $cleared = self::unschedule_hook(self::REPORT_HOOK);
    if (!$cleared['success']) return ['success' => false, 'change' => null, 'error' => 'Could not clear stale report event.'];
    $timestamp = self::next_run_timestamp($time, $timezone, $now);
    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
    if (!self::schedule_single($timestamp, self::REPORT_HOOK, [$date])) return ['success' => false, 'change' => null, 'error' => 'Could not register replacement dated report event.'];
    return ['success' => true, 'change' => 'scheduled', 'error' => ''];
  }

  private static function schedule_closed_day_refresh(string $report_date, \DateTimeZone $timezone, \DateTimeImmutable $now): bool {
    $report_day = \DateTimeImmutable::createFromFormat('!Y-m-d', $report_date, $timezone);
    if (!$report_day || $report_day->format('Y-m-d') !== $report_date) return false;
    [$hour, $minute] = array_pad(array_map('intval', explode(':', self::CLOSED_DAY_TIME)), 2, 0);
    $timestamp = $report_day->modify('+1 day')->setTime($hour, $minute, 0)->getTimestamp();
    if ($timestamp <= $now->getTimestamp()) $timestamp = $now->getTimestamp() + 1;
    $args = [$report_date, 0];
    if (!self::save_closed_day_pending($report_date, 0, 'pending', '', $timestamp)) return false;
    foreach (self::scheduled_events(self::CLOSED_DAY_HOOK) as $event) if ($event['args'] === $args && $event['timestamp'] >= $timestamp && empty($event['schedule'])) return true;
    foreach (self::scheduled_events(self::CLOSED_DAY_HOOK) as $event) {
      if (($event['args'][0] ?? null) === $report_date && !self::unschedule_event(self::CLOSED_DAY_HOOK, $event)) return true;
    }
    // The option is the durable queue; ensure_schedule retries event creation.
    self::schedule_single($timestamp, self::CLOSED_DAY_HOOK, $args);
    return true;
  }

  private static function ensure_pending_closed_day_refreshes(\DateTimeImmutable $now): array {
    $queue = self::read_closed_day_queue();
    if (!is_array($queue)) return ['success' => false, 'changed' => false, 'error' => 'Closed-day refresh queue is unreadable.'];
    $changed = false;
    foreach ($queue as $date => $record) {
      if (!self::valid_report_date((string) $date) || !is_array($record) || ($record['status'] ?? '') !== 'pending') continue;
      $attempt = max(0, min(self::CLOSED_DAY_MAX_RETRIES, (int) ($record['attempt'] ?? 0)));
      $args = [(string) $date, $attempt];
      $found = false;
      $retry_at = max(0, (int) ($record['retry_at'] ?? 0));
      foreach (self::scheduled_events(self::CLOSED_DAY_HOOK) as $event) {
        if ($event['args'] === $args && $event['timestamp'] >= $retry_at && empty($event['schedule'])) { $found = true; break; }
      }
      if ($found) continue;
      foreach (self::scheduled_events(self::CLOSED_DAY_HOOK) as $event) {
        if (($event['args'][0] ?? null) === (string) $date && !self::unschedule_event(self::CLOSED_DAY_HOOK, $event)) {
          return ['success' => false, 'changed' => $changed, 'error' => 'Could not remove a stale retry event.'];
        }
      }
      $retry_at = max((int) ($record['retry_at'] ?? 0), $now->getTimestamp() + 60);
      if (!self::schedule_single($retry_at, self::CLOSED_DAY_HOOK, $args)) {
        return ['success' => false, 'changed' => $changed, 'error' => 'Could not restore a pending closed-day refresh event.'];
      }
      $changed = true;
    }
    return ['success' => true, 'changed' => $changed, 'error' => ''];
  }

  private static function retry_closed_day_refresh(string $date, int $attempt, string $message, \DateTimeImmutable $now): void {
    if ($attempt >= self::CLOSED_DAY_MAX_RETRIES) {
      self::save_closed_day_pending($date, $attempt, 'failed', $message, 0);
      Store::insert_log('closed_day_refresh', 'closed-day-refresh', null, $date, false, 'Closed-day refresh exhausted its bounded retries; manager attention is required.');
      return;
    }
    $next_attempt = $attempt + 1;
    $retry_at = $now->getTimestamp() + self::CLOSED_DAY_RETRY_SECONDS;
    if (!self::save_closed_day_pending($date, $next_attempt, 'pending', $message, $retry_at)) {
      Store::insert_log('closed_day_refresh', 'closed-day-refresh', null, $date, false, 'Could not persist the closed-day refresh retry state.');
      return;
    }
    if (!self::schedule_single($retry_at, self::CLOSED_DAY_HOOK, [$date, $next_attempt])) {
      Store::insert_log('closed_day_refresh', 'closed-day-refresh', null, $date, false, 'Closed-day retry remains queued but cron registration failed; Health will report it unscheduled.');
    }
  }

  private static function save_closed_day_pending(string $date, int $attempt, string $status, string $message, int $retry_at): bool {
    return self::with_closed_day_queue_lock(static function (string $lock) use ($date, $attempt, $status, $message, $retry_at): bool {
      $queue = self::read_closed_day_queue();
      if (!is_array($queue)) return false;
      $queue[$date] = ['attempt' => $attempt, 'status' => $status, 'message' => $message, 'retry_at' => $retry_at];
      ksort($queue);
      if (!self::owns_closed_day_queue_lock($lock)) return false;
      update_option(self::CLOSED_DAY_PENDING_KEY, $queue, false);
      if (!self::owns_closed_day_queue_lock($lock)) return false;
      $saved = self::read_closed_day_queue();
      return is_array($saved) && ($saved[$date] ?? null) === $queue[$date];
    });
  }

  private static function clear_pending_closed_day_refresh(string $date): bool {
    return self::with_closed_day_queue_lock(static function (string $lock) use ($date): bool {
      $queue = self::read_closed_day_queue();
      if (!is_array($queue)) return false;
      unset($queue[$date]);
      if (!self::owns_closed_day_queue_lock($lock)) return false;
      update_option(self::CLOSED_DAY_PENDING_KEY, $queue, false);
      if (!self::owns_closed_day_queue_lock($lock)) return false;
      $saved = self::read_closed_day_queue();
      return is_array($saved) && !isset($saved[$date]);
    });
  }

  /** Serialize the option's read-modify-write cycle across concurrent cron workers. */
  private static function with_closed_day_queue_lock(callable $operation): bool {
    global $wpdb;
    $lock = 'roxy_grosses_closed_queue_' . substr(hash('sha256', self::CLOSED_DAY_PENDING_KEY), 0, 24);
    $acquired = false;
    try {
      $acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) === 1;
      if (!$acquired || !self::owns_closed_day_queue_lock($lock)) return false;
      return (bool) $operation($lock);
    } catch (\Throwable $error) {
      return false;
    } finally {
      if ($acquired) {
        try { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
        catch (\Throwable $error) { /* The queue operation result remains fail-closed. */ }
      }
    }
  }

  /**
   * Read the durable queue after discarding WordPress's request-local option
   * cache. Another cron worker may have committed a date while this request
   * was still running; stale cache data must never overwrite that date.
   */
  private static function read_closed_day_queue() {
    if (function_exists('wp_cache_delete')) {
      wp_cache_delete(self::CLOSED_DAY_PENDING_KEY, 'options');
    }
    return get_option(self::CLOSED_DAY_PENDING_KEY, []);
  }

  private static function owns_closed_day_queue_lock(string $lock): bool {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock)) === 1;
  }

  private static function schedule_single(int $timestamp, string $hook, array $args = []): bool {
    try {
      if (!self::cron_operation_succeeded(wp_schedule_single_event($timestamp, $hook, $args))) return false;
      foreach (self::scheduled_events($hook) as $event) {
        if ($event['timestamp'] === $timestamp && $event['args'] === $args && empty($event['schedule'])) return true;
      }
      return false;
    } catch (\Throwable $error) {
      return false;
    }
  }

  private static function scheduled_events(string $hook): array {
    if (!function_exists('_get_cron_array')) return [];
    $cron = _get_cron_array();
    if (!is_array($cron)) return [];
    $events = [];
    foreach ($cron as $timestamp => $hooks) {
      foreach (($hooks[$hook] ?? []) as $event) {
        if (!is_array($event) || !is_array($event['args'] ?? [])) continue;
        $events[] = ['timestamp' => (int) $timestamp, 'hook' => $hook, 'args' => $event['args'] ?? [], 'schedule' => $event['schedule'] ?? false];
      }
    }
    usort($events, static fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);
    return $events;
  }

  private static function unschedule_event(string $hook, array $event): bool {
    try {
      if (!self::cron_operation_succeeded(wp_unschedule_event((int) $event['timestamp'], $hook, $event['args']))) return false;
      foreach (self::scheduled_events($hook) as $remaining) {
        if ($remaining['timestamp'] === (int) $event['timestamp'] && $remaining['args'] === $event['args']) return false;
      }
      return true;
    } catch (\Throwable $error) { return false; }
  }

  private static function valid_report_date($date): bool {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
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
      $result = Reporter::send_report($report_date, $mode === 'scheduled-sync' ? 'scheduled-provisional' : $mode);
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
