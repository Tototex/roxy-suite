<?php
// wp eval-file only. Read-only WooCommerce pagination-shape probe; emits no order identities or amounts.
if (!defined('ABSPATH')) exit(1);
if (!function_exists('wc_get_orders')) throw new RuntimeException('WooCommerce order API unavailable.');

$date = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) throw new RuntimeException('Pass one explicit date as YYYY-MM-DD.');
$timezone = new DateTimeZone(\RoxyGrosses\Settings::get_report_timezone());
$start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
$errors = DateTimeImmutable::getLastErrors();
if (!$start || $start->format('Y-m-d') !== $date || ($errors && ($errors['warning_count'] || $errors['error_count']))) throw new RuntimeException('Invalid date.');
$start_utc = $start->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
$end_utc = $start->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->getTimestamp();

add_filter('pre_http_request', static fn() => new WP_Error('roxy_shape_probe_no_http', 'HTTP blocked during WooCommerce-only shape probe.'), 10, 3);
add_filter('query', static function (string $query): string {
    if (preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|LOCK|UNLOCK|START|COMMIT|ROLLBACK)\b/i', $query)) {
        return 'SELECT 1 WHERE 1 = 0';
    }
    return $query;
});
add_filter('pre_wp_mail', static fn() => true);

$result = wc_get_orders([
    'type' => 'shop_order', 'date_paid' => $start_utc . '...' . ($end_utc - 1),
    'limit' => 100, 'page' => 1, 'paginate' => true, 'return' => 'objects', 'orderby' => 'ID', 'order' => 'ASC',
]);
$vars = is_object($result) ? get_object_vars($result) : null;
$orders = is_array($vars) ? ($vars['orders'] ?? null) : null;
$out = [
    'result_type' => gettype($result),
    'result_class' => is_object($result) ? get_class($result) : null,
    'property_names' => is_array($vars) ? array_keys($vars) : null,
    'orders_type' => gettype($orders),
    'orders_count' => is_array($orders) ? count($orders) : null,
    'orders_is_list' => is_array($orders) && array_is_list($orders),
    'total_pages_type' => is_array($vars) && array_key_exists('total_pages', $vars) ? gettype($vars['total_pages']) : 'missing',
    'total_pages' => is_array($vars) ? ($vars['total_pages'] ?? null) : null,
    'max_num_pages_type' => is_array($vars) && array_key_exists('max_num_pages', $vars) ? gettype($vars['max_num_pages']) : 'missing',
    'max_num_pages' => is_array($vars) ? ($vars['max_num_pages'] ?? null) : null,
    'total_type' => is_array($vars) && array_key_exists('total', $vars) ? gettype($vars['total']) : 'missing',
    'total' => is_array($vars) ? ($vars['total'] ?? null) : null,
    'wp_error' => function_exists('is_wp_error') && is_wp_error($result),
];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
