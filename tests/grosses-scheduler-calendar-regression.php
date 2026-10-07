<?php
// Standalone synthetic WP-Cron fixtures; no sends, database writes, or external calls.
namespace RoxyGrosses {
  final class Settings {
    public static array $values = [];
    public static function get_all(): array { return self::$values; }
    public static function get_report_timezone(): string { return (string) (self::$values['report_timezone'] ?? 'America/Los_Angeles'); }
  }
  final class Store {
    public static array $logs = [];
    public static function insert_log(...$args): void { self::$logs[] = $args; }
  }
  final class Reporter {
    public static array $sync_calls = [];
    public static array $closed_day_calls = [];
    public static array $send_modes = [];
    public static int $send_calls = 0;
    public static int $paid_rows = 0;
    public static array $refresh_results = [];
    public static function sync_automatic_tables(...$args): array { self::$sync_calls[] = $args; return ['success' => true, 'movie_paid_rows' => self::$paid_rows, 'movie_rows' => 0, 'live_rows' => 0]; }
    public static function refresh_closed_day(...$args): array { self::$closed_day_calls[] = $args; return self::$refresh_results ? array_shift(self::$refresh_results) : ['success' => true, 'message' => 'reviewed']; }
    public static function send_report(...$args): array { self::$send_calls++; self::$send_modes[] = $args; return ['success' => true]; }
  }
  final class Workbook {
    public static function send_advertiser_summary(...$args): array { return ['success' => true]; }
  }
}

namespace {
  class WP_Error {}
  final class SchedulerFixtureWpdb {
    public string $last_error = '';
    public bool $lock_held = false;
    public function prepare(string $query, ...$args): string { return json_encode(['sql'=>$query,'args'=>$args]); }
    public function get_var(string $query) {
      $parts=json_decode($query,true); $sql=(string)($parts['sql']??$query);
      if (str_contains($sql,'GET_LOCK')) {
        if (($GLOBALS['queue_lock_mode']??'')==='busy') return 0;
        $this->lock_held=true; return 1;
      }
      if (str_contains($sql,'IS_USED_LOCK')) return $this->lock_held && ($GLOBALS['queue_lock_mode']??'')!=='lost' ? 1 : 0;
      if (str_contains($sql,'RELEASE_LOCK')) { $this->lock_held=false; return 1; }
      return null;
    }
  }
  define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  function add_action(...$args): void {}
  function wp_installing(): bool { return false; }
  function is_wp_error($value): bool { return $value instanceof WP_Error; }
  function wp_schedule_single_event(int $timestamp, string $hook, array $args = []) {
    if (($GLOBALS['schedule_failure'] ?? false) === 'error') return new WP_Error();
    if (($GLOBALS['schedule_failure'] ?? false) === 'noop') return true;
    if (!empty($GLOBALS['schedule_failure'])) return false;
    foreach ($GLOBALS['cron_events'] as $event) {
      if ($event['timestamp'] === $timestamp && $event['hook'] === $hook && ($event['args'] ?? []) === $args) return false;
    }
    $GLOBALS['cron_events'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'schedule' => false, 'args' => $args];
    return true;
  }
  function wp_schedule_event(int $timestamp, string $schedule, string $hook, array $args = []): bool {
    $GLOBALS['cron_events'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'schedule' => $schedule, 'args' => $args];
    return true;
  }
  function wp_next_scheduled(string $hook, array $args = []) {
    $matches = array_values(array_filter($GLOBALS['cron_events'], static fn(array $event): bool => $event['hook'] === $hook && ($event['args'] ?? []) === $args));
    if (!$matches) return false;
    usort($matches, static fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);
    return $matches[0]['timestamp'];
  }
  function wp_get_scheduled_event(string $hook, array $args = []) {
    $timestamp = wp_next_scheduled($hook, $args);
    if (!$timestamp) return false;
    foreach ($GLOBALS['cron_events'] as $event) {
      if ($event['hook'] === $hook && $event['timestamp'] === $timestamp && ($event['args'] ?? []) === $args) return (object) $event;
    }
    return false;
  }
  function wp_unschedule_event(int $timestamp, string $hook, array $args = []) {
    if (($GLOBALS['unschedule_failure'] ?? false) === 'error') return new WP_Error();
    if (($GLOBALS['unschedule_failure'] ?? false) === 'noop') return true;
    if (!empty($GLOBALS['unschedule_failure'])) return false;
    $GLOBALS['cron_events'] = array_values(array_filter($GLOBALS['cron_events'], static fn(array $event): bool => !($event['hook'] === $hook && $event['timestamp'] === $timestamp && ($event['args'] ?? []) === $args)));
    return true;
  }
  function _get_cron_array(): array {
    $cron = [];
    foreach ($GLOBALS['cron_events'] as $event) {
      $args = $event['args'] ?? [];
      $cron[$event['timestamp']][$event['hook']][md5(serialize($args))] = ['schedule' => $event['schedule'] ?? false, 'args' => $args];
    }
    return $cron;
  }
  function get_option(string $key, $default = false) { return $GLOBALS['fixture_options'][$key] ?? $default; }
  function update_option(string $key, $value, bool $autoload = true): bool {
    if (($GLOBALS['queue_write_mode']??'')==='fail') return false;
    if (($GLOBALS['queue_write_mode']??'')!=='noop') $GLOBALS['fixture_options'][$key] = $value;
    return true;
  }
  function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string {
    $date = new DateTimeImmutable('@' . ($timestamp ?? time()));
    return $date->setTimezone($timezone ?? new DateTimeZone('UTC'))->format($format);
  }
  function current_time(string $type, bool $gmt = false): string { return '2026-10-07 08:00:00'; }

  $root = $argv[1] ?? dirname(__DIR__);
  $candidate = $argv[2] ?? $root . '/includes/modules/grosses/includes/class-roxy-grosses-scheduler.php';
  require $candidate;

  $checks = 0;
  function scheduler_calendar_assert(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "PASS: {$message}\n";
  }
  function scheduler_next_timestamp(string $time, string $timezone, string $now): int {
    $method = new ReflectionMethod(\RoxyGrosses\Scheduler::class, 'next_run_timestamp');
    return $method->invoke(null, $time, $timezone, new DateTimeImmutable($now, new DateTimeZone($timezone)));
  }
  function scheduler_events(string $hook): array {
    return array_values(array_filter($GLOBALS['cron_events'], static fn(array $event): bool => $event['hook'] === $hook));
  }

  $spring_before = scheduler_next_timestamp('20:00', 'America/Los_Angeles', '2025-03-08 19:00:00');
  $spring_after = scheduler_next_timestamp('20:00', 'America/Los_Angeles', '2025-03-09 19:00:00');
  scheduler_calendar_assert(
    (new DateTimeImmutable('@' . $spring_before))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d H:i T') === '2025-03-08 20:00 PST'
      && (new DateTimeImmutable('@' . $spring_after))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d H:i T') === '2025-03-09 20:00 PDT'
      && $spring_after - $spring_before === 23 * 3600,
    'spring transition preserves configured wall time while elapsed interval is 23 hours'
  );

  $fall_before = scheduler_next_timestamp('20:00', 'America/Los_Angeles', '2025-11-01 19:00:00');
  $fall_after = scheduler_next_timestamp('20:00', 'America/Los_Angeles', '2025-11-02 19:00:00');
  scheduler_calendar_assert(
    (new DateTimeImmutable('@' . $fall_before))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d H:i T') === '2025-11-01 20:00 PDT'
      && (new DateTimeImmutable('@' . $fall_after))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d H:i T') === '2025-11-02 20:00 PST'
      && $fall_after - $fall_before === 25 * 3600,
    'fall transition preserves configured wall time while elapsed interval is 25 hours'
  );

  $delayed = scheduler_next_timestamp('20:00', 'America/Los_Angeles', '2025-06-10 21:37:00');
  scheduler_calendar_assert(
    (new DateTimeImmutable('@' . scheduler_next_timestamp('02:30', 'America/Los_Angeles', '2025-03-09 04:00:00')))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d H:i') === '2025-03-10 02:30',
    'spring-gap normalization does not shift the following calendar day clock'
  );
  scheduler_calendar_assert(
    (new DateTimeImmutable('@' . $delayed))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d H:i') === '2025-06-11 20:00',
    'late daily run schedules the next configured local time without catch-up execution'
  );

  $settings = [
    'schedule_enabled' => '1', 'schedule_time' => '20:00',
    'advertiser_schedule_enabled' => '1', 'advertiser_schedule_time' => '09:00',
    'advertiser_schedule_day' => '1', 'report_timezone' => 'America/Los_Angeles',
  ];
  \RoxyGrosses\Settings::$values = $settings;
  $GLOBALS['cron_events'] = [];
  $GLOBALS['wpdb'] = new SchedulerFixtureWpdb();
  $GLOBALS['queue_lock_mode'] = '';
  $GLOBALS['queue_write_mode'] = '';
  $GLOBALS['schedule_failure'] = false;
  $GLOBALS['unschedule_failure'] = false;
  \RoxyGrosses\Store::$logs = [];
  $fixed_now = new DateTimeImmutable('2025-06-10 18:00:00', new DateTimeZone('America/Los_Angeles'));
  \RoxyGrosses\Scheduler::sync_schedule($settings, $fixed_now);
  $report_hook = \RoxyGrosses\Scheduler::report_hook();
  $advertiser_hook = \RoxyGrosses\Scheduler::advertiser_hook();
  scheduler_calendar_assert(
    count(scheduler_events($report_hook)) === 1 && count(scheduler_events($advertiser_hook)) === 1
      && scheduler_events($report_hook)[0]['schedule'] === false && scheduler_events($report_hook)[0]['args'] === ['2025-06-10']
      && scheduler_events($advertiser_hook)[0]['schedule'] === false,
    'sync registers a date-keyed report event and one monthly advertiser event'
  );
  scheduler_calendar_assert(
    !wp_next_scheduled($report_hook) && \RoxyGrosses\Scheduler::scheduled_time_local($report_hook) !== '',
    'argument-aware scheduler discovery finds dated hooks that wp_next_scheduled default args miss'
  );

  $GLOBALS['cron_events'] = [];
  \RoxyGrosses\Scheduler::ensure_schedule($settings, new DateTimeImmutable('2025-06-10 21:37:00', new DateTimeZone('America/Los_Angeles')));
  scheduler_calendar_assert(
    count(scheduler_events($report_hook)) === 1
      && (new DateTimeImmutable('@' . scheduler_events($report_hook)[0]['timestamp']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d H:i') === '2025-06-11 20:00',
    'delayed single-event repair schedules one next-day wall-clock run, not an immediate catch-up'
  );

  $GLOBALS['cron_events'][] = ['timestamp' => $fixed_now->getTimestamp() + 10, 'hook' => $report_hook, 'schedule' => 'daily'];
  \RoxyGrosses\Scheduler::ensure_schedule($settings, $fixed_now);
  scheduler_calendar_assert(
    count(scheduler_events($report_hook)) === 1 && scheduler_events($report_hook)[0]['schedule'] === false,
    'ensure migrates legacy recurring event to one configured-time single event'
  );

  $legacy_timestamp = (new DateTimeImmutable('2025-06-12 20:00:00', new DateTimeZone('America/Los_Angeles')))->getTimestamp();
  $GLOBALS['cron_events'] = [['timestamp' => $legacy_timestamp, 'hook' => $report_hook, 'schedule' => false, 'args' => []]];
  \RoxyGrosses\Scheduler::ensure_schedule($settings, $fixed_now);
  scheduler_calendar_assert(
    count(scheduler_events($report_hook)) === 1 && scheduler_events($report_hook)[0]['timestamp'] === $legacy_timestamp
      && scheduler_events($report_hook)[0]['args'] === ['2025-06-12'],
    'ensure migrates a legacy zero-argument single using its intended local scheduled date'
  );
  $GLOBALS['fixture_options'] = [];
  \RoxyGrosses\Scheduler::run_scheduled_send();
  scheduler_calendar_assert(
    ($GLOBALS['fixture_options']['roxy_grosses_last_scheduled_sync_result']['status'] ?? '') === 'failed'
      && \RoxyGrosses\Reporter::$send_calls === 0,
    'unmigrated zero-argument callback fails safe instead of inventing the current report date'
  );

  \RoxyGrosses\Scheduler::ensure_schedule($settings, $fixed_now);
  scheduler_calendar_assert(count(scheduler_events($report_hook)) === 1 && count(scheduler_events($advertiser_hook)) === 1, 'repeated ensure does not duplicate scheduled events');

  $edited = array_merge($settings, ['schedule_time' => '21:15', 'advertiser_schedule_time' => '10:30']);
  \RoxyGrosses\Scheduler::ensure_schedule($edited, $fixed_now);
  scheduler_calendar_assert(
    (new DateTimeImmutable('@' . scheduler_events($report_hook)[0]['timestamp']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('H:i') === '21:15'
      && (new DateTimeImmutable('@' . scheduler_events($advertiser_hook)[0]['timestamp']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('H:i') === '10:30'
      && count(scheduler_events($report_hook)) === 1 && count(scheduler_events($advertiser_hook)) === 1,
    'ensure repairs configuration-edited local event times and keeps one event per hook'
  );
  $last_log = end(\RoxyGrosses\Store::$logs);
  scheduler_calendar_assert(
    $last_log[4] === true && isset($last_log[6]['changes']['report']),
    'successful local-time repairs retain schedule_repair success logging'
  );

  $GLOBALS['cron_events'] = [[
    'timestamp' => (new DateTimeImmutable('2025-06-10 20:00:00', new DateTimeZone('UTC')))->getTimestamp(),
    'hook' => $report_hook,
    'schedule' => false,
  ]];
  \RoxyGrosses\Scheduler::ensure_schedule($settings, $fixed_now);
  scheduler_calendar_assert(
    count(scheduler_events($report_hook)) === 1
      && (new DateTimeImmutable('@' . scheduler_events($report_hook)[0]['timestamp']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d H:i') === '2025-06-10 20:00',
    'timezone-edited event is matched and repaired using its displayed clock in the configured timezone'
  );

  $GLOBALS['cron_events'] = [[
    'timestamp' => (new DateTimeImmutable('2025-06-09 20:00:00', new DateTimeZone('America/Los_Angeles')))->getTimestamp(),
    'hook' => $report_hook,
    'schedule' => false,
    'args' => ['2025-06-09'],
  ]];
  $log_count = count(\RoxyGrosses\Store::$logs);
  \RoxyGrosses\Scheduler::ensure_schedule(array_merge($settings, ['advertiser_schedule_enabled' => '0']), $fixed_now);
  scheduler_calendar_assert(
    count(scheduler_events($report_hook)) === 1 && scheduler_events($report_hook)[0]['timestamp'] === $GLOBALS['cron_events'][0]['timestamp']
      && count(\RoxyGrosses\Store::$logs) === $log_count,
    'matching overdue one-shot stays in place for delayed WP-Cron execution without repair log'
  );

  $GLOBALS['cron_events'] = [];
  $GLOBALS['schedule_failure'] = 'error';
  \RoxyGrosses\Scheduler::ensure_schedule($settings, $fixed_now);
  $GLOBALS['schedule_failure'] = false;
  $last_log = end(\RoxyGrosses\Store::$logs);
  scheduler_calendar_assert(
    !$GLOBALS['cron_events'] && $last_log[4] === false && isset($last_log[6]['failures']),
    'failed event registration is logged as failure and does not claim successful repair'
  );

  $GLOBALS['cron_events'] = [];
  $GLOBALS['schedule_failure'] = 'noop';
  \RoxyGrosses\Scheduler::ensure_schedule($settings, $fixed_now);
  $GLOBALS['schedule_failure'] = false;
  $last_log = end(\RoxyGrosses\Store::$logs);
  scheduler_calendar_assert(
    !scheduler_events($report_hook) && $last_log[4] === false && isset($last_log[6]['failures']['report']),
    'true-without-registration is rejected by the cron postcondition check'
  );

  $GLOBALS['cron_events'] = [[
    'timestamp' => $fixed_now->setTime(19, 0)->getTimestamp(),
    'hook' => $report_hook,
    'schedule' => false,
  ]];
  $GLOBALS['unschedule_failure'] = 'error';
  \RoxyGrosses\Scheduler::ensure_schedule(array_merge($settings, ['advertiser_schedule_enabled' => '0']), $fixed_now);
  $GLOBALS['unschedule_failure'] = false;
  $last_log = end(\RoxyGrosses\Store::$logs);
  scheduler_calendar_assert(
    count(scheduler_events($report_hook)) === 1 && $last_log[4] === false && isset($last_log[6]['failures']),
    'failed stale-event removal is logged as failure without scheduling a replacement or fatal error'
  );

  $GLOBALS['cron_events'] = [[
    'timestamp' => $fixed_now->setTime(19, 0)->getTimestamp(),
    'hook' => $report_hook,
    'schedule' => false,
    'args' => ['2025-06-10'],
  ]];
  $GLOBALS['unschedule_failure'] = 'noop';
  \RoxyGrosses\Scheduler::ensure_schedule($settings, $fixed_now);
  $GLOBALS['unschedule_failure'] = false;
  $last_log = end(\RoxyGrosses\Store::$logs);
  scheduler_calendar_assert(
    count(scheduler_events($report_hook)) === 1 && $last_log[4] === false && isset($last_log[6]['failures']['report']),
    'true-without-removal is rejected and does not claim schedule repair'
  );

  $GLOBALS['fixture_options'] = [];
  \RoxyGrosses\Settings::$values = $settings;
  \RoxyGrosses\Reporter::$paid_rows = 1;
  \RoxyGrosses\Scheduler::run_scheduled_send('2000-01-02');
  scheduler_calendar_assert(
    \RoxyGrosses\Reporter::$send_calls === 1 && end(\RoxyGrosses\Reporter::$send_modes) === ['2000-01-02', 'scheduled-provisional'],
    'dated scheduled report runs once for its intended date and is marked provisional'
  );
  scheduler_calendar_assert(
    count(scheduler_events(\RoxyGrosses\Scheduler::report_hook())) === 1
      && count(array_filter($GLOBALS['cron_events'], static fn(array $event): bool => $event['hook'] === 'roxy_grosses_closed_day_refresh' && ($event['args'] ?? []) === ['2000-01-02', 0])) === 1,
    'delayed report event retains its date and queues a date-keyed after-midnight refresh'
  );
  $send_count = \RoxyGrosses\Reporter::$send_calls;
  $save_pending = new ReflectionMethod(\RoxyGrosses\Scheduler::class, 'save_closed_day_pending');
  $clear_pending = new ReflectionMethod(\RoxyGrosses\Scheduler::class, 'clear_pending_closed_day_refresh');
  $queue_key = 'roxy_grosses_closed_day_refresh_queue';
  $GLOBALS['fixture_options'][$queue_key] = ['2000-01-01'=>['attempt'=>1,'status'=>'pending','message'=>'other worker date','retry_at'=>123]];
  scheduler_calendar_assert($save_pending->invoke(null,'2000-01-02',0,'pending','',456)
    && count($GLOBALS['fixture_options'][$queue_key])===2 && isset($GLOBALS['fixture_options'][$queue_key]['2000-01-01']),
    'serialized queue update preserves a different date already queued');
  $queue_before = $GLOBALS['fixture_options'][$queue_key];
  $GLOBALS['queue_lock_mode'] = 'busy';
  scheduler_calendar_assert(!$save_pending->invoke(null,'2000-01-03',0,'pending','',789)
    && $GLOBALS['fixture_options'][$queue_key]===$queue_before,
    'busy shared queue lock fails closed without losing another date');
  $GLOBALS['queue_lock_mode'] = 'lost';
  scheduler_calendar_assert(!$clear_pending->invoke(null,'2000-01-01')
    && $GLOBALS['fixture_options'][$queue_key]===$queue_before,
    'lost queue-lock ownership prevents a clear write');
  $GLOBALS['queue_lock_mode'] = '';
  $GLOBALS['fixture_options'][$queue_key] = 'unreadable queue evidence';
  scheduler_calendar_assert(!$save_pending->invoke(null,'2000-01-04',0,'pending','',999)
    && $GLOBALS['fixture_options'][$queue_key]==='unreadable queue evidence',
    'malformed queue evidence is preserved and not silently reset');
  $GLOBALS['fixture_options'][$queue_key] = $queue_before;
  $GLOBALS['queue_write_mode'] = 'fail';
  scheduler_calendar_assert(!$save_pending->invoke(null,'2000-01-05',0,'pending','',999)
    && $GLOBALS['fixture_options'][$queue_key]===$queue_before && !$GLOBALS['wpdb']->lock_held,
    'failed option write is reported and the shared queue lock is released');
  $GLOBALS['queue_write_mode'] = 'noop';
  scheduler_calendar_assert(!$save_pending->invoke(null,'2000-01-06',0,'pending','',999)
    && $GLOBALS['fixture_options'][$queue_key]===$queue_before && !$GLOBALS['wpdb']->lock_held,
    'successful-but-no-op queue write fails verification and preserves existing dates');
  $GLOBALS['queue_write_mode'] = '';
  $GLOBALS['fixture_options'][$queue_key] = [];
  \RoxyGrosses\Reporter::$refresh_results = [['success' => false, 'message' => 'temporary failure'], ['success' => true, 'message' => 'reviewed']];
  $GLOBALS['cron_events'] = array_values(array_filter($GLOBALS['cron_events'], static fn(array $event): bool => !($event['hook'] === 'roxy_grosses_closed_day_refresh' && ($event['args'] ?? []) === ['2000-01-02', 0])));
  \RoxyGrosses\Scheduler::run_closed_day_refresh('2000-01-02');
  scheduler_calendar_assert(
    \RoxyGrosses\Reporter::$closed_day_calls === [['2000-01-02']] && \RoxyGrosses\Reporter::$send_calls === $send_count
      && \RoxyGrosses\Scheduler::closed_day_refresh_health()['status'] === 'scheduled'
      && \RoxyGrosses\Scheduler::closed_day_refresh_health()['attempt'] === 1,
    'closed-day failure is durably queued for a bounded retry without sending email'
  );
  $GLOBALS['cron_events'] = array_values(array_filter($GLOBALS['cron_events'], static fn(array $event): bool => !($event['hook'] === 'roxy_grosses_closed_day_refresh' && ($event['args'] ?? []) === ['2000-01-02', 1])));
  \RoxyGrosses\Scheduler::run_closed_day_refresh('2000-01-02', 1);
  scheduler_calendar_assert(
    \RoxyGrosses\Reporter::$closed_day_calls === [['2000-01-02'], ['2000-01-02']] && \RoxyGrosses\Reporter::$send_calls === $send_count
      && \RoxyGrosses\Scheduler::closed_day_refresh_health()['status'] === 'idle',
    'successful retry clears pending status and still never sends an automatic correction'
  );
  $schedule_closed = new ReflectionMethod(\RoxyGrosses\Scheduler::class, 'schedule_closed_day_refresh');
  $GLOBALS['schedule_failure'] = 'noop';
  $scheduled = $schedule_closed->invoke(null, '2000-01-03', new DateTimeZone('America/Los_Angeles'), new DateTimeImmutable('2000-01-03 02:00:00', new DateTimeZone('America/Los_Angeles')));
  $GLOBALS['schedule_failure'] = false;
  scheduler_calendar_assert(!$scheduled && \RoxyGrosses\Scheduler::closed_day_refresh_health()['status'] === 'unscheduled', 'failed cron registration leaves a durable health-visible queue item');
  \RoxyGrosses\Scheduler::ensure_schedule($settings, $fixed_now);
  scheduler_calendar_assert(\RoxyGrosses\Scheduler::closed_day_refresh_health()['status'] === 'scheduled', 'scheduler repair restores an unscheduled durable closed-day refresh');
  \RoxyGrosses\Reporter::$refresh_results = array_fill(0, 4, ['success' => false, 'message' => 'persistent failure']);
  foreach ([0, 1, 2, 3] as $attempt) {
    $GLOBALS['cron_events'] = array_values(array_filter($GLOBALS['cron_events'], static fn(array $event): bool => !($event['hook'] === 'roxy_grosses_closed_day_refresh' && ($event['args'] ?? []) === ['2000-01-03', $attempt])));
    \RoxyGrosses\Scheduler::run_closed_day_refresh('2000-01-03', $attempt);
  }
  scheduler_calendar_assert(\RoxyGrosses\Scheduler::closed_day_refresh_health()['status'] === 'failed', 'closed-day retry budget terminates visibly instead of looping forever');
  $GLOBALS['fixture_options']['roxy_grosses_closed_day_refresh_queue'] = [];
  \RoxyGrosses\Settings::$values = array_merge($settings, ['schedule_enabled' => '0', 'advertiser_schedule_enabled' => '0']);
  \RoxyGrosses\Scheduler::run_scheduled_send();
  scheduler_calendar_assert(
    ($GLOBALS['fixture_options']['roxy_grosses_last_scheduled_sync_result']['status'] ?? '') === 'skipped',
    'disabled daily scheduler records a skipped outcome without running a report'
  );
  \RoxyGrosses\Scheduler::run_monthly_advertiser_send();
  scheduler_calendar_assert(
    ($GLOBALS['fixture_options']['roxy_grosses_last_advertiser_send_result']['status'] ?? '') === 'skipped',
    'disabled advertiser scheduler records a skipped outcome without sending email'
  );

  $disabled = array_merge($edited, ['schedule_enabled' => '0', 'advertiser_schedule_enabled' => '0']);
  $GLOBALS['cron_events'] = [];
  \RoxyGrosses\Scheduler::sync_schedule($disabled, $fixed_now);
  scheduler_calendar_assert(!scheduler_events($report_hook) && !scheduler_events($advertiser_hook), 'disabled schedules clear both pending hooks');

  echo "{$checks} grosses scheduler calendar checks passed; WP-Cron is fully stubbed.\n";
}
