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
    public static function sync_automatic_tables(...$args): array { return ['success' => true, 'movie_paid_rows' => 0, 'movie_rows' => 0, 'live_rows' => 0]; }
    public static function send_report(...$args): array { return ['success' => true]; }
  }
  final class Workbook {
    public static function send_advertiser_summary(...$args): array { return ['success' => true]; }
  }
}

namespace {
  class WP_Error {}
  define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  function add_action(...$args): void {}
  function wp_installing(): bool { return false; }
  function is_wp_error($value): bool { return $value instanceof WP_Error; }
  function wp_schedule_single_event(int $timestamp, string $hook) {
    if (($GLOBALS['schedule_failure'] ?? false) === 'error') return new WP_Error();
    if (!empty($GLOBALS['schedule_failure'])) return false;
    foreach ($GLOBALS['cron_events'] as $event) {
      if ($event['timestamp'] === $timestamp && $event['hook'] === $hook) return false;
    }
    $GLOBALS['cron_events'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'schedule' => false];
    return true;
  }
  function wp_schedule_event(int $timestamp, string $schedule, string $hook): bool {
    $GLOBALS['cron_events'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'schedule' => $schedule];
    return true;
  }
  function wp_next_scheduled(string $hook) {
    $matches = array_values(array_filter($GLOBALS['cron_events'], static fn(array $event): bool => $event['hook'] === $hook));
    if (!$matches) return false;
    usort($matches, static fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);
    return $matches[0]['timestamp'];
  }
  function wp_get_scheduled_event(string $hook) {
    $timestamp = wp_next_scheduled($hook);
    if (!$timestamp) return false;
    foreach ($GLOBALS['cron_events'] as $event) {
      if ($event['hook'] === $hook && $event['timestamp'] === $timestamp) return (object) $event;
    }
    return false;
  }
  function wp_unschedule_event(int $timestamp, string $hook) {
    if (($GLOBALS['unschedule_failure'] ?? false) === 'error') return new WP_Error();
    if (!empty($GLOBALS['unschedule_failure'])) return false;
    $GLOBALS['cron_events'] = array_values(array_filter($GLOBALS['cron_events'], static fn(array $event): bool => !($event['hook'] === $hook && $event['timestamp'] === $timestamp)));
    return true;
  }
  function get_option(string $key, $default = false) { return $GLOBALS['fixture_options'][$key] ?? $default; }
  function update_option(string $key, $value): bool { $GLOBALS['fixture_options'][$key] = $value; return true; }
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
  $GLOBALS['schedule_failure'] = false;
  $GLOBALS['unschedule_failure'] = false;
  \RoxyGrosses\Store::$logs = [];
  $fixed_now = new DateTimeImmutable('2025-06-10 18:00:00', new DateTimeZone('America/Los_Angeles'));
  \RoxyGrosses\Scheduler::sync_schedule($settings, $fixed_now);
  $report_hook = \RoxyGrosses\Scheduler::report_hook();
  $advertiser_hook = \RoxyGrosses\Scheduler::advertiser_hook();
  scheduler_calendar_assert(
    count(scheduler_events($report_hook)) === 1 && count(scheduler_events($advertiser_hook)) === 1
      && scheduler_events($report_hook)[0]['schedule'] === false && scheduler_events($advertiser_hook)[0]['schedule'] === false,
    'sync registers one single event per enabled daily/monthly callback'
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

  $GLOBALS['fixture_options'] = [];
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
