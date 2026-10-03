<?php
namespace RoxyInventory;
if (!defined('ABSPATH')) exit;

class Scheduler {
    private const HOOK = 'roxy_inventory_nightly_pull';
    public static function init(): void { add_action(self::HOOK, [__CLASS__, 'run']); add_action('init', [__CLASS__, 'ensure_schedule'], 31); }
    public static function sync_schedule(): void { self::clear_schedule(); if (Settings::get('schedule_enabled') !== '1') return; wp_schedule_single_event(self::next_time(), self::HOOK); }
    public static function ensure_schedule(): void {
        if (Settings::get('schedule_enabled') !== '1') { self::clear_schedule(); return; }
        $event = wp_get_scheduled_event(self::HOOK);
        // Replace the old fixed-interval job; each subsequent run chooses local 11 PM anew.
        if (!$event || !empty($event->schedule)) self::sync_schedule();
    }
    public static function clear_schedule(): void { while ($ts = wp_next_scheduled(self::HOOK)) wp_unschedule_event($ts, self::HOOK); }
    public static function run(): void { try { Square::pull(); } catch (\Throwable $e) { Store::log('pull','failed',$e->getMessage()); } finally { self::ensure_schedule(); } }
    private static function next_time(?\DateTimeImmutable $now = null): int { $tz = new \DateTimeZone((string) Settings::get('timezone','America/Los_Angeles')); $now = $now ? $now->setTimezone($tz) : new \DateTimeImmutable('now',$tz); [$h,$m] = array_pad(array_map('intval',explode(':',(string) Settings::get('schedule_time','23:00'))),2,0); $next = $now->setTime($h,$m,0); if ($next <= $now) $next = $next->modify('+1 day'); return $next->getTimestamp(); }
}
