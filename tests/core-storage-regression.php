<?php
namespace Automattic\WooCommerce\Utilities {
    final class FeaturesUtil {
        public static array $calls = [];
        public static function declare_compatibility($feature, $file, $supported): bool { self::$calls[] = [$feature, $file, $supported]; return true; }
    }
    final class OrderUtil {
        public static bool $enabled = false;
        public static bool $fail = false;
        public static function custom_orders_table_usage_is_enabled(): bool { if (self::$fail) throw new \RuntimeException('fixture read failure'); return self::$enabled; }
    }
}
namespace {
    define('ABSPATH', __DIR__ . '/');
    $root = $argv[1] ?? dirname(__DIR__);
    define('ROXY_SUITE_PATH', $root . '/');
    function add_action($hook, $callback): void { $GLOBALS['storage_hooks'][$hook] = $callback; }
    require $root . '/includes/class-roxy-suite-compatibility.php';
    $checks = 0;
    function storage_check($ok, $label): void { if (!$ok) throw new \RuntimeException($label); $GLOBALS['checks']++; echo 'PASS: ' . $label . PHP_EOL; }
    \RoxySuite\Compatibility::init();
    storage_check(isset($GLOBALS['storage_hooks']['before_woocommerce_init']), 'declaration registered at required WooCommerce hook');
    call_user_func($GLOBALS['storage_hooks']['before_woocommerce_init']);
    storage_check(\Automattic\WooCommerce\Utilities\FeaturesUtil::$calls === [['custom_order_tables', $root . '/roxy-suite.php', false]], 'HPOS explicitly declared unsupported for the actual plugin file');
    $status = \RoxySuite\Compatibility::order_storage_status();
    storage_check($status['status'] === 'pass' && $status['value'] === 'Post-based storage', 'legacy order storage remains supported');
    \Automattic\WooCommerce\Utilities\OrderUtil::$enabled = true;
    $status = \RoxySuite\Compatibility::order_storage_status();
    storage_check($status['status'] === 'fail' && strpos($status['message'], 'backup') !== false, 'unsupported active HPOS is a failed diagnostic with safe recovery guidance');
    \Automattic\WooCommerce\Utilities\OrderUtil::$fail = true;
    storage_check(\RoxySuite\Compatibility::order_storage_status()['status'] === 'warn', 'storage read exception does not break diagnostics or falsely pass');
    echo 'Passed ' . $checks . ' isolated storage checks; no storage setting changed.' . PHP_EOL;
}
