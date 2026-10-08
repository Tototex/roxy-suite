<?php
/**
 * Roxy Suite's existing access grant and module-flag compatibility handling.
 *
 * This deliberately preserves the current administrator/shop_manager grant
 * and the historical enabled baseline for modules that already existed.
 * Newly introduced module flags must not be added to the legacy defaults.
 */
namespace RoxySuite;

final class Governance {
    private const OPTION_NAME = 'roxy_suite_modules';

    /** @var list<string> Modules currently understood by this plugin. */
    private const MODULE_IDS = [
        'arcade',
        'sub_check',
        'will_call',
        'show_tickets',
        'requested_showings',
        'event_booking',
        'grosses',
        'inventory',
        'social_publisher',
    ];

    /** @var array<string, bool> Historical baseline; future modules stay off. */
    private const LEGACY_MODULE_DEFAULTS = [
        'arcade' => true,
        'sub_check' => true,
        'will_call' => true,
        'show_tickets' => true,
        'requested_showings' => true,
        'event_booking' => true,
        'grosses' => true,
        'inventory' => true,
        'social_publisher' => true,
    ];

    private static bool $module_flags_loaded = false;
    private static ?array $module_flags = null;
    private const MODULE_LOCK_TIMEOUT_SECONDS = 5;

    public static function admin_capability(): string {
        return 'roxy_suite_access';
    }

    /** Keep the existing grant limited to the activation-time roles. */
    public static function grant_capabilities(): void {
        foreach (['administrator', 'shop_manager'] as $role_name) {
            $role = get_role($role_name);
            if ($role && !$role->has_cap(self::admin_capability())) {
                $role->add_cap(self::admin_capability());
            }
        }
    }

    /** @return list<string> */
    public static function module_ids(): array {
        return self::MODULE_IDS;
    }

    /**
     * Read and migrate the option. Ordinary reads of a complete option are
     * read-only; migrations are serialized and cached only after readback.
     * Invalid stored data and failed persistence fail closed without rewriting.
     *
     * @return array<string, bool>|null Null means invalid/unavailable settings.
     */
    private static function module_flags(): ?array {
        if (self::$module_flags_loaded) {
            return self::$module_flags;
        }

        $stored = self::read_module_option();
        if ($stored['valid'] && $stored['complete']) {
            self::$module_flags = $stored['flags'];
            self::$module_flags_loaded = true;
            return self::$module_flags;
        }
        if (!$stored['valid']) {
            return self::mark_module_flags_corrupt();
        }

        $result = self::with_module_lock(static function (): ?array {
            // Re-read after acquiring the connection-scoped lock to avoid
            // overwriting a toggle made by a concurrent request.
            $current = self::read_module_option();
            if (!$current['valid']) {
                return null;
            }
            if ($current['complete']) {
                return $current['flags'];
            }
            if (!self::persist_and_verify($current['flags'])) {
                return null;
            }
            return $current['flags'];
        });

        if (!is_array($result)) {
            return self::mark_module_flags_corrupt();
        }
        self::$module_flags = $result;
        self::$module_flags_loaded = true;
        return self::$module_flags;
    }

    /** @return array{valid: bool, complete: bool, flags: array<string, bool>} */
    private static function read_module_option(): array {
        // Module toggles are serialized across requests. Discard the local
        // option cache before a locked read so a worker cannot overwrite a
        // flag committed by the preceding worker.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_NAME, 'options');
        }
        $missing = new \stdClass();
        $stored = get_option(self::OPTION_NAME, $missing);
        if ($stored !== $missing && !is_array($stored)) {
            return ['valid' => false, 'complete' => false, 'flags' => []];
        }
        if (is_array($stored)) {
            foreach ($stored as $key => $enabled) {
                if (!is_string($key) || !is_bool($enabled)) {
                    return ['valid' => false, 'complete' => false, 'flags' => []];
                }
            }
        }

        // Whitelist known keys so a boolean written by a newer/different
        // version cannot silently activate when a future module is introduced.
        $flags = [];
        foreach (self::MODULE_IDS as $key) {
            if (is_array($stored) && array_key_exists($key, $stored)) {
                $flags[$key] = $stored[$key];
            } else {
                // This exactly preserves the old ?? true behavior for existing
                // modules. A newly added module must be omitted here (false).
                $flags[$key] = self::LEGACY_MODULE_DEFAULTS[$key] ?? false;
            }
        }

        $complete = is_array($stored) && self::same_flags($stored, $flags);
        return ['valid' => true, 'complete' => $complete, 'flags' => $flags];
    }

    /** Compare key/value content without relying on PHP array insertion order. */
    private static function same_flags(array $left, array $right): bool {
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($right as $key => $value) {
            if (!array_key_exists($key, $left) || $left[$key] !== $value) {
                return false;
            }
        }
        return true;
    }

    private static function persist_and_verify(array $flags): bool {
        update_option(self::OPTION_NAME, $flags);
        $saved = get_option(self::OPTION_NAME, null);
        return is_array($saved) && self::same_flags($saved, $flags);
    }

    /**
     * Serialize option read/modify/write operations on the active DB connection.
     * @return mixed Null means no lock could be acquired.
     */
    private static function with_module_lock(callable $callback) {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return null;
        }

        $database = defined('DB_NAME') ? (string) DB_NAME : '';
        $prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : '';
        $lock_name = 'roxy_suite_' . substr(md5($database . ':' . $prefix . ':' . self::OPTION_NAME), 0, 40);
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, self::MODULE_LOCK_TIMEOUT_SECONDS));
        if ((string) $acquired !== '1') {
            return null;
        }

        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    private static function mark_module_flags_corrupt(): ?array {
        self::$module_flags = null;
        self::$module_flags_loaded = true;
        return null;
    }

    public static function module_settings_are_valid(): bool {
        return self::module_flags() !== null;
    }

    public static function module_enabled(string $key): bool {
        if (!in_array($key, self::MODULE_IDS, true)) {
            return false;
        }
        $flags = self::module_flags();
        return $flags !== null && array_key_exists($key, $flags) && $flags[$key] === true;
    }

    public static function set_module_enabled(string $key, bool $enabled): bool {
        if (!in_array($key, self::MODULE_IDS, true)) {
            return false;
        }
        $result = self::with_module_lock(static function () use ($key, $enabled): ?array {
            $current = self::read_module_option();
            if (!$current['valid']) {
                return null;
            }
            $flags = $current['flags'];
            $flags[$key] = $enabled;
            return self::persist_and_verify($flags) ? $flags : null;
        });
        if (!is_array($result)) {
            self::$module_flags = null;
            self::$module_flags_loaded = true;
            return false;
        }
        self::$module_flags = $result;
        self::$module_flags_loaded = true;
        return true;
    }
}
