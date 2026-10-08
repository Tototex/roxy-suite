<?php
// wp eval-file only. Aggregate configuration diagnostics; never print secret values or financial data.
if (!defined('ABSPATH')) exit(1);

$gateway_allowlist = \RoxyGrosses\Settings::line_list((string) \RoxyGrosses\Settings::get('cashflow_woo_gateways', ''));
$cashflow_gate_result = 'not_checked';
if (!$gateway_allowlist) {
    try {
        \RoxyGrosses\CashflowReport::for_day('2026-10-03');
        $cashflow_gate_result = 'unexpected_success';
    } catch (\Throwable $error) {
        $cashflow_gate_result = strpos($error->getMessage(), 'Configure the WooCommerce online payment gateway IDs') === 0
            ? 'failed_closed_before_provider_reads'
            : 'unexpected_error';
    }
}
$webhook_key_configured = \RoxyGrosses\Settings::get('square_webhook_signature_key', '') !== '';
$enabled_gateways = [];
if (function_exists('WC') && WC() && WC()->payment_gateways()) {
    foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
        if (is_object($gateway) && ($gateway->enabled ?? '') === 'yes' && is_string($gateway->id ?? null)) {
            $enabled_gateways[] = $gateway->id;
        }
    }
}

global $wpdb;
$webhook_table = \RoxyGrosses\Store::refund_webhook_table_name();
$wpdb->last_error = '';
$table_name = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $webhook_table));
$table_read_ok = $wpdb->last_error === '';
$table_exists = $table_read_ok && $table_name === $webhook_table;

$server = rest_get_server();
do_action('rest_api_init', $server);
$route_registered = array_key_exists('/roxy/v1/square-refund-events', $server->get_routes());

sort($enabled_gateways);
echo json_encode([
    'cashflow_report_loaded' => class_exists(\RoxyGrosses\CashflowReport::class),
    'enabled_woo_gateway_ids' => $enabled_gateways,
    'cashflow_gateway_allowlist_count' => count($gateway_allowlist),
    'cashflow_unconfigured_path' => $cashflow_gate_result,
    'square_refund_webhook_key_configured' => $webhook_key_configured,
    'square_refund_webhook_route_registered' => $route_registered,
    'refund_event_table_read_ok' => $table_read_ok,
    'refund_event_table_exists' => $table_exists,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
