<?php
namespace RoxyInventory;
if (!defined('ABSPATH')) exit;

class Settings {
    public const OPTION_KEY = 'roxy_inventory_settings';

    public static function defaults(): array {
        return [
            'jason_email' => get_option('admin_email'),
            'admin_alert_email' => get_option('admin_email'),
            'timezone' => 'America/Los_Angeles',
            'schedule_enabled' => '1',
            'schedule_time' => '23:00',
            'direct_vendor_sending_enabled' => '0',
            'tripp_order_instructions' => "12oz bottles or cans only. Units are the desired total number of cans or bottles.\nWine is boxed only.",
            'odom_order_instructions' => "12oz bottles or cans only. Units are the desired total number of cans or bottles.\nWine is boxed only.",
        ];
    }

    public static function ensure_defaults(): void {
        if (get_option(self::OPTION_KEY, null) === null) add_option(self::OPTION_KEY, self::defaults());
    }

    public static function all(): array {
        $saved = get_option(self::OPTION_KEY, []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    public static function get(string $key, $default = '') { $all = self::all(); return $all[$key] ?? $default; }

    public static function init(): void { add_action('admin_init', [__CLASS__, 'register']); }

    public static function register(): void {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, ['type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize'], 'default' => self::defaults()]);
    }

    public static function sanitize($input): array {
        $input = is_array($input) ? $input : [];
        $tz = sanitize_text_field((string) ($input['timezone'] ?? self::get('timezone')));
        try { new \DateTimeZone($tz); } catch (\Exception $e) { $tz = 'America/Los_Angeles'; }
        return [
            'jason_email' => sanitize_email((string) ($input['jason_email'] ?? '')),
            'admin_alert_email' => sanitize_email((string) ($input['admin_alert_email'] ?? self::get('admin_alert_email'))),
            'timezone' => $tz,
            'schedule_enabled' => empty($input['schedule_enabled']) ? '0' : '1',
            'schedule_time' => preg_match('/^\d{2}:\d{2}$/', (string) ($input['schedule_time'] ?? '')) ? (string) $input['schedule_time'] : '23:00',
            'direct_vendor_sending_enabled' => empty($input['direct_vendor_sending_enabled']) ? '0' : '1',
            'tripp_order_instructions' => sanitize_textarea_field((string) ($input['tripp_order_instructions'] ?? self::get('tripp_order_instructions'))),
            'odom_order_instructions' => sanitize_textarea_field((string) ($input['odom_order_instructions'] ?? self::get('odom_order_instructions'))),
        ];
    }
}
