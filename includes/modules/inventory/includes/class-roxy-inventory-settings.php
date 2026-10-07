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
        register_setting(self::OPTION_KEY, self::OPTION_KEY, ['type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize_registered'], 'default' => self::defaults()]);
    }

    /** Strict, non-throwing validator for the WordPress Settings API only. */
    public static function sanitize_registered($input): array {
        try {
            if (!is_array($input)) return self::reject_registered_input();
            foreach (['jason_email','timezone','schedule_time','tripp_order_instructions','odom_order_instructions'] as $field) {
                if (!array_key_exists($field, $input) || !is_string($input[$field])) return self::reject_registered_input();
            }
            foreach (['admin_alert_email','schedule_enabled','direct_vendor_sending_enabled'] as $field) {
                if (array_key_exists($field, $input) && !is_string($input[$field])) return self::reject_registered_input();
            }
            foreach (['schedule_enabled','direct_vendor_sending_enabled'] as $field) {
                if (array_key_exists($field, $input) && !in_array($input[$field], ['0','1'], true)) return self::reject_registered_input();
            }
            $manager_email = sanitize_email(wp_unslash($input['jason_email']));
            if (!is_email($manager_email)) return self::reject_registered_input();
            $timezone = sanitize_text_field(wp_unslash($input['timezone']));
            if (!in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) return self::reject_registered_input();
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', wp_unslash($input['schedule_time']))) return self::reject_registered_input();
            if (isset($input['admin_alert_email']) && trim($input['admin_alert_email']) !== '' && !is_email(sanitize_email(wp_unslash($input['admin_alert_email'])))) return self::reject_registered_input();
            return self::sanitize($input);
        } catch (\Throwable $error) {
            return self::reject_registered_input();
        }
    }

    private static function reject_registered_input(): array {
        if (function_exists('add_settings_error')) {
            try { add_settings_error(self::OPTION_KEY, 'invalid_inventory_settings', 'Inventory settings were incomplete or invalid; the saved settings were preserved.', 'error'); }
            catch (\Throwable $error) { /* Keep the Settings API callback non-throwing. */ }
        }
        try {
            $saved = get_option(self::OPTION_KEY, null);
            if (is_array($saved)) return $saved;
        } catch (\Throwable $error) { /* Use defaults when the option cannot be read. */ }
        try { return self::defaults(); }
        catch (\Throwable $error) {
            return [
                'jason_email'=>'','admin_alert_email'=>'','timezone'=>'America/Los_Angeles',
                'schedule_enabled'=>'0','schedule_time'=>'23:00','direct_vendor_sending_enabled'=>'0',
                'tripp_order_instructions'=>'','odom_order_instructions'=>'',
            ];
        }
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
            'schedule_time' => preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string) ($input['schedule_time'] ?? '')) ? (string) $input['schedule_time'] : '23:00',
            'direct_vendor_sending_enabled' => empty($input['direct_vendor_sending_enabled']) ? '0' : '1',
            'tripp_order_instructions' => sanitize_textarea_field((string) ($input['tripp_order_instructions'] ?? self::get('tripp_order_instructions'))),
            'odom_order_instructions' => sanitize_textarea_field((string) ($input['odom_order_instructions'] ?? self::get('odom_order_instructions'))),
        ];
    }
}
