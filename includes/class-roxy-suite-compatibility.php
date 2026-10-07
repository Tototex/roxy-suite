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
            // Ticket/booking transactions and membership reads still require
            // post-based order storage. Never advertise untested HPOS support.
            $utility::declare_compatibility('custom_order_tables', ROXY_SUITE_PATH . 'roxy-suite.php', false);
        }
    }

    public static function order_storage_status(): array {
        $utility = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
        if (!class_exists($utility) || !method_exists($utility, 'custom_orders_table_usage_is_enabled')) {
            return ['status' => 'warn', 'value' => 'Unknown', 'message' => 'WooCommerce order storage could not be verified. Roxy Suite does not support HPOS yet.'];
        }
        try {
            if ($utility::custom_orders_table_usage_is_enabled()) {
                return ['status' => 'fail', 'value' => 'HPOS enabled — unsupported', 'message' => 'Roxy Suite requires post-based WooCommerce order storage. Contact the administrator before using ticket, membership or booking workflows. Do not switch storage without a verified backup and migration plan.'];
            }
            return ['status' => 'pass', 'value' => 'Post-based storage', 'message' => 'Required by current Roxy Suite order and membership workflows. HPOS compatibility has not been certified.'];
        } catch (\Throwable $error) {
            return ['status' => 'warn', 'value' => 'Unknown', 'message' => 'WooCommerce order storage could not be verified. Review storage configuration before changing it.'];
        }
    }
}
