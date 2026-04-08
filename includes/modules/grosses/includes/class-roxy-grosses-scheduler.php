<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Scheduler {
  private const REPORT_HOOK = 'roxy_grosses_scheduled_send';
  private const ADVERTISER_HOOK = 'roxy_grosses_monthly_advertiser_send';
  private const LAST_AUTO_DATE_KEY = 'roxy_grosses_last_auto_date';
  private const LAST_ADVERTISER_MONTH_KEY = 'roxy_grosses_last_advertiser_month';

  public static function init(): void {
    add_action(self::REPORT_HOOK, [__CLASS__, 'run_scheduled_send']);
    add_action(self::ADVERTISER_HOOK, [__CLASS__, 'run_monthly_advertiser_send']);
  }

  public static function sync_schedule(?array $settings = null): void {
    $settings = is_array($settings) ? $settings : Settings::get_all();
    self::clear_schedule();

    if (($settings['schedule_enabled'] ?? '0') === '1') {
      wp_schedule_event(
        self::next_run_timestamp((string) ($settings['schedule_time'] ?? '22:00'), (string) ($settings['report_timezone'] ?? Settings::get_report_timezone())),
        'daily',
        self::REPORT_HOOK
      );
    }

    if (($settings['advertiser_schedule_enabled'] ?? '0') === '1') {
      wp_schedule_event(
        self::next_run_timestamp((string) ($settings['advertiser_schedule_time'] ?? '09:00'), (string) ($settings['report_timezone'] ?? Settings::get_report_timezone())),
        'daily',
        self::ADVERTISER_HOOK
      );
    }
  }

  public static function clear_schedule(): void {
    foreach ([self::REPORT_HOOK, self::ADVERTISER_HOOK] as $hook) {
      $timestamp = wp_next_scheduled($hook);
      while ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
        $timestamp = wp_next_scheduled($hook);
      }
    }
  }

  public static function run_scheduled_send(): void {
    $settings = Settings::get_all();
    if (($settings['schedule_enabled'] ?? '0') !== '1') {
      return;
    }

    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $now = new \DateTimeImmutable('now', $timezone);
    $report_date = $now->format('Y-m-d');

    if (get_option(self::LAST_AUTO_DATE_KEY) === $report_date) {
      return;
    }

    self::run_for_date($report_date, 'scheduled-sync', true);
  }

  public static function run_now(?string $report_date = null): array {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $report_date = $report_date ?: (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
    return self::run_for_date($report_date, 'run-now', true);
  }

  public static function run_monthly_advertiser_send(): void {
    $settings = Settings::get_all();
    if (($settings['advertiser_schedule_enabled'] ?? '0') !== '1') {
      return;
    }

    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $now = new \DateTimeImmutable('now', $timezone);
    $configured_day = max(1, min(31, (int) ($settings['advertiser_schedule_day'] ?? 1)));
    $scheduled_day = min($configured_day, (int) $now->format('t'));
    if ((int) $now->format('j') !== $scheduled_day) {
      return;
    }

    $target = $now->modify('first day of last month');
    $month_key = $target->format('Y-m');

    if (get_option(self::LAST_ADVERTISER_MONTH_KEY) === $month_key) {
      return;
    }

    $result = Workbook::send_advertiser_summary((int) $target->format('Y'), (int) $target->format('m'), 'scheduled-advertiser');
    if (!empty($result['success'])) {
      update_option(self::LAST_ADVERTISER_MONTH_KEY, $month_key);
    }
  }

  private static function next_run_timestamp(string $time, string $timezone): int {
    $tz = new \DateTimeZone($timezone);
    $now = new \DateTimeImmutable('now', $tz);
    [$hour, $minute] = array_pad(array_map('intval', explode(':', $time)), 2, 0);
    $next = $now->setTime($hour, $minute, 0);

    if ($next <= $now) {
      $next = $next->modify('+1 day');
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
