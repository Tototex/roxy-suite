<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Scheduler {
  private const HOOK = 'roxy_grosses_scheduled_send';
  private const LAST_AUTO_DATE_KEY = 'roxy_grosses_last_auto_date';

  public static function init(): void {
    add_action(self::HOOK, [__CLASS__, 'run_scheduled_send']);
  }

  public static function sync_schedule(?array $settings = null): void {
    $settings = is_array($settings) ? $settings : Settings::get_all();
    self::clear_schedule();

    if (($settings['schedule_enabled'] ?? '0') !== '1') {
      return;
    }

    wp_schedule_event(self::next_run_timestamp((string) ($settings['schedule_time'] ?? '22:00'), (string) ($settings['report_timezone'] ?? Settings::get_report_timezone())), 'daily', self::HOOK);
  }

  public static function clear_schedule(): void {
    $timestamp = wp_next_scheduled(self::HOOK);
    while ($timestamp) {
      wp_unschedule_event($timestamp, self::HOOK);
      $timestamp = wp_next_scheduled(self::HOOK);
    }
  }

  public static function run_scheduled_send(): void {
    $settings = Settings::get_all();
    if (($settings['schedule_enabled'] ?? '0') !== '1') {
      return;
    }

    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $now = new \DateTimeImmutable('now', $timezone);
    $day = strtolower(substr($now->format('D'), 0, 3));
    $report_date = $now->format('Y-m-d');

    if (!in_array($day, (array) ($settings['schedule_days'] ?? []), true)) {
      return;
    }

    if (get_option(self::LAST_AUTO_DATE_KEY) === $report_date) {
      return;
    }

    $result = Reporter::send_report($report_date, 'scheduled');
    if (!empty($result['success'])) {
      update_option(self::LAST_AUTO_DATE_KEY, $report_date);
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
}
