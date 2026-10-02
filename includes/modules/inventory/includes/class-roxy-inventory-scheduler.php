<?php
namespace RoxyInventory;
if (!defined('ABSPATH')) exit;

class Scheduler {
    private const HOOK = 'roxy_inventory_nightly_pull';
    public static function init(): void { add_action(self::HOOK, [__CLASS__, 'run']); add_action('init', [__CLASS__, 'ensure_schedule'], 31); }
    public static function sync_schedule(): void { self::clear_schedule(); if (Settings::get('schedule_enabled') !== '1') return; wp_schedule_event(self::next_time(), 'daily', self::HOOK); }
    public static function ensure_schedule(): void { if (Settings::get('schedule_enabled') === '1' && !wp_next_scheduled(self::HOOK)) wp_schedule_event(self::next_time(), 'daily', self::HOOK); if (Settings::get('schedule_enabled') !== '1') self::clear_schedule(); }
    public static function clear_schedule(): void { while ($ts = wp_next_scheduled(self::HOOK)) wp_unschedule_event($ts, self::HOOK); }
    public static function run(): void { try { Square::pull(); } catch (\Throwable $e) { Store::log('pull','failed',$e->getMessage()); } }
    private static function next_time(): int { $tz = new \DateTimeZone((string) Settings::get('timezone','America/Los_Angeles')); $now = new \DateTimeImmutable('now',$tz); [$h,$m] = array_pad(array_map('intval',explode(':',(string) Settings::get('schedule_time','23:00'))),2,0); $next = $now->setTime($h,$m,0); if ($next <= $now) $next = $next->modify('+1 day'); return $next->getTimestamp(); }
}
