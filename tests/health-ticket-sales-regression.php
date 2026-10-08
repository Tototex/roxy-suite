<?php
namespace RoxyST {
    final class Sales {
        public static $value = 0;
        public static bool $throws = false;
        public static function sold_qty_for_showing(int $showing_id): int {
            if (self::$throws) throw new \RuntimeException('simulated sales query exception');
            return self::$value;
        }
    }
}

namespace {
    define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
    function roxy_suite_module_enabled($key): bool { return true; }
    function wp_timezone() { return new DateTimeZone('UTC'); }
    function wp_date($format, $timestamp = null, $timezone = null): string { return '2026-10-08T12:00'; }
    function get_posts($args): array { return [(object) ['ID' => 17]]; }
    function get_the_title($id): string { return 'Fixture show'; }
    function get_post_meta($id, $key, $single = false) { return 123; }

    $GLOBALS['wpdb'] = (object) ['last_error' => ''];
    require dirname(__DIR__) . '/includes/class-roxy-suite-health.php';
    $method = new \ReflectionMethod(\RoxySuite\Health::class, 'functional_show_tickets');
    $checks = 0;
    $check = static function (bool $condition, string $message) use (&$checks): void {
        if (!$condition) throw new \RuntimeException($message);
        $checks++;
    };
    $find_sales = static function () use ($method): array {
        foreach ($method->invoke(null) as $item) if ($item['label'] === 'Tickets sold (next show)') return $item;
        throw new \RuntimeException('Ticket-sales diagnostic item is missing.');
    };

    \RoxyST\Sales::$value = 7;
    $item = $find_sales();
    $check($item['status'] === 'pass' && $item['detail'] === '7 sold — Fixture show', 'valid ticket count remains green');
    \RoxyST\Sales::$value = PHP_INT_MAX;
    $item = $find_sales();
    $check($item['status'] === 'warn' && $item['detail'] === 'Unavailable', 'sales-reader failure sentinel is never displayed as a count');
    \RoxyST\Sales::$value = -1;
    $check($find_sales()['status'] === 'warn', 'negative ticket count is unavailable');
    \RoxyST\Sales::$throws = true;
    $check($find_sales()['status'] === 'warn', 'thrown sales read is unavailable, not a fatal');
    echo "$checks ticket-sales health checks passed\n";
}
