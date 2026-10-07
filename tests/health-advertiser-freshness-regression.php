<?php
namespace RoxyGrosses {
    final class Settings {
        public static string $timezone = 'America/Los_Angeles';
        public static function get_report_timezone(): string { return self::$timezone; }
    }
}

namespace {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
    $health_file = $argv[1] ?? dirname(__DIR__) . '/includes/class-roxy-suite-health.php';
    require_once $health_file;

    $method = new \ReflectionMethod(\RoxySuite\Health::class, 'advertiser_monthly_freshness');
    $method->setAccessible(true);
    $checks = 0;
    $check = static function (string $name, array $settings, $marker, string $now, string $expected) use ($method, &$checks): void {
        $item = $method->invoke(null, $settings, $marker, new \DateTimeImmutable($now));
        if (($item['status'] ?? null) !== $expected) {
            throw new \RuntimeException($name . ': expected ' . $expected . ', got ' . ($item['status'] ?? 'missing'));
        }
        $checks++;
    };
    $settings = ['advertiser_schedule_enabled' => '1', 'advertiser_schedule_day' => '15', 'advertiser_schedule_time' => '09:00'];

    $check('before due, no first-cycle warning', $settings, '', '2026-10-15 08:59:59 America/Los_Angeles', 'pass');
    $check('exact due boundary missing marker', $settings, '', '2026-10-15 09:00:00 America/Los_Angeles', 'warn');
    $check('after due expected prior month', $settings, '2026-09', '2026-10-15 09:00:01 America/Los_Angeles', 'pass');
    $check('UTC clock normalized to report timezone', $settings, '2026-09', '2026-10-15 16:00:00 UTC', 'pass');
    $check('before due still checks prior cycle', $settings, '2026-07', '2026-10-14 12:00:00 America/Los_Angeles', 'warn');
    $check('late previous cycle', $settings, '2026-08', '2026-10-20 12:00:00 America/Los_Angeles', 'warn');

    $clamp31 = ['advertiser_schedule_enabled' => '1', 'advertiser_schedule_day' => '31', 'advertiser_schedule_time' => '09:00'];
    $check('February leap-day clamp before due', $clamp31, '2023-11', '2024-02-29 08:59:00 America/Los_Angeles', 'warn');
    $check('February leap-day clamp at due', $clamp31, '2024-01', '2024-02-29 09:00:00 America/Los_Angeles', 'pass');
    $check('short month clamp', $clamp31, '2026-02', '2026-03-31 10:00:00 America/Los_Angeles', 'pass');
    $check('year rollover after due', $settings, '2025-12', '2026-01-15 09:00:00 America/Los_Angeles', 'pass');

    $disabled = ['advertiser_schedule_enabled' => '0', 'advertiser_schedule_day' => 99, 'advertiser_schedule_time' => 'bad'];
    $check('disabled schedule not needed', $disabled, 'nonsense', '2026-10-01 00:00:00 UTC', 'pass');
    $check('malformed marker', $settings, '2026-13', '2026-10-20 12:00:00 America/Los_Angeles', 'warn');
    $check('future marker', $settings, '2026-11', '2026-10-20 12:00:00 America/Los_Angeles', 'warn');
    $check('bad schedule clock fails closed', ['advertiser_schedule_enabled' => '1', 'advertiser_schedule_day' => 1, 'advertiser_schedule_time' => '25:00'], '2026-09', '2026-10-20 12:00:00 America/Los_Angeles', 'warn');

    echo "{$checks} advertiser freshness checks passed\n";
}
