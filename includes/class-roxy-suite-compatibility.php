<?php
namespace RoxySuite;

if (!defined('ABSPATH')) exit;

/** Storage safety declaration; this does not migrate WooCommerce records. */
final class Compatibility {
    public static function init(): void {
        add_action('before_woocommerce_init', [__CLASS__, 'declare_storage_support']);
    }

    public static function declare_storage_support(): void {
        $utility = '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
        if (class_exists($utility) && method_exists($utility, 'declare_compatibility')) {
            // Order-facing modules use WooCommerce CRUD or verified shared
            // item tables with transactional HPOS metadata paths.
            $utility::declare_compatibility('custom_order_tables', ROXY_SUITE_PATH . 'roxy-suite.php', true);
        }
    }

    public static function order_storage_status(): array {
        $utility = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
        if (!class_exists($utility) || !method_exists($utility, 'custom_orders_table_usage_is_enabled')) {
            return ['status' => 'warn', 'value' => 'Unknown', 'message' => 'WooCommerce order storage could not be verified. Review storage configuration before use.'];
        }
        try {
            $enabled = $utility::custom_orders_table_usage_is_enabled();
            if ($enabled === true) {
                return ['status' => 'pass', 'value' => 'HPOS custom order tables', 'message' => 'Roxy Suite supports WooCommerce HPOS. Order data is accessed through WooCommerce CRUD or verified shared item tables.'];
            }
            if ($enabled !== false) {
                return ['status' => 'warn', 'value' => 'Unknown', 'message' => 'WooCommerce order storage could not be verified. Review storage configuration before changing it.'];
            }
            return ['status' => 'pass', 'value' => 'Post-based storage', 'message' => 'Roxy Suite supports legacy post-based WooCommerce order storage.'];
        } catch (\Throwable $error) {
            return ['status' => 'warn', 'value' => 'Unknown', 'message' => 'WooCommerce order storage could not be verified. Review storage configuration before changing it.'];
        }
    }

    /** True only when legacy post-based order storage is positively verified. */
    public static function supports_legacy_order_storage(): bool {
        $status = self::order_storage_status();
        return is_array($status) && ($status['status'] ?? '') === 'pass' && ($status['value'] ?? '') === 'Post-based storage';
    }
}
