<?php
namespace RoxyInventory;
if (!defined('ABSPATH')) exit;

class Scheduler {
    private const HOOK = 'roxy_inventory_nightly_pull';
    public static function init(): void { add_action(self::HOOK, [__CLASS__, 'run']); add_action('init', [__CLASS__, 'ensure_schedule'], 31); }
    public static function sync_schedule(): void {
        self::clear_schedule();
        if (Settings::get('schedule_enabled') !== '1') return;
        $time = self::next_time();
        if (wp_schedule_single_event($time, self::HOOK) === false) throw new \RuntimeException('The inventory pull schedule could not be registered.');
        $event = wp_get_scheduled_event(self::HOOK);
        if (!$event || (int)$event->timestamp !== $time || !empty($event->schedule)) throw new \RuntimeException('The inventory pull schedule could not be verified.');
    }
    public static function ensure_schedule(): void {
        try { self::repair_schedule(); }
        catch (\Throwable $e) { error_log('Roxy Inventory schedule needs attention: ' . $e->getMessage()); }
    }
    private static function repair_schedule(): void {
        if (Settings::get('schedule_enabled') !== '1') { self::clear_schedule(); return; }
        $event = wp_get_scheduled_event(self::HOOK);
        // Replace the old fixed-interval job; each subsequent run chooses local 11 PM anew.
        if (!$event || !empty($event->schedule)) self::sync_schedule();
    }
    public static function clear_schedule(): void {
        // A failed removal must not loop forever and stall every site request.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $ts = wp_next_scheduled(self::HOOK);
            if (!$ts) return;
            if (wp_unschedule_event($ts, self::HOOK) === false || wp_next_scheduled(self::HOOK) === $ts) throw new \RuntimeException('The previous inventory pull schedule could not be removed.');
        }
        throw new \RuntimeException('Too many inventory pull events; administrator review is required.');
    }
    public static function run(): void { try { Square::pull(); } catch (\Throwable $e) { Store::log('pull','failed',$e->getMessage()); } finally { self::ensure_schedule(); } }
    private static function next_time(?\DateTimeImmutable $now = null): int { $tz = new \DateTimeZone((string) Settings::get('timezone','America/Los_Angeles')); $now = $now ? $now->setTimezone($tz) : new \DateTimeImmutable('now',$tz); [$h,$m] = array_pad(array_map('intval',explode(':',(string) Settings::get('schedule_time','23:00'))),2,0); $next = $now->setTime($h,$m,0); if ($next <= $now) $next = $next->modify('+1 day'); return $next->getTimestamp(); }
}
