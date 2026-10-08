<?php
// wp eval-file only. A single past date; Square GETs and Woo reads only. Never prints identifiers.
if (!defined('ABSPATH')) exit(1);

$date = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) throw new RuntimeException('Pass one explicit past date as YYYY-MM-DD.');
$timezone = new DateTimeZone(\RoxyGrosses\Settings::get_report_timezone());
$parsed_date = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
$date_errors = DateTimeImmutable::getLastErrors();
if (!$parsed_date || $parsed_date->format('Y-m-d') !== $date || ($date_errors && ($date_errors['warning_count'] || $date_errors['error_count']))
    || $parsed_date >= new DateTimeImmutable('today', $timezone)) {
    throw new RuntimeException('The live read-only check accepts only a valid past report date.');
}

$GLOBALS['roxy_cashflow_live_read'] = [
    'square_gets' => 0,
    'blocked_http' => 0,
    'blocked_database_writes' => 0,
    'blocked_mail' => 0,
];
add_filter('pre_http_request', static function ($preempt, array $request, string $url) {
    $parts = wp_parse_url($url);
    $method = strtoupper((string) ($request['method'] ?? 'GET'));
    $allowed_path = is_array($parts) && is_string($parts['path'] ?? null)
        && preg_match('#^/v2/(?:payments|refunds(?:/[^/]+)?)$#D', $parts['path']);
    if ($preempt === false && is_array($parts) && ($parts['host'] ?? '') === 'connect.squareup.com'
        && $method === 'GET' && $allowed_path) {
        ++$GLOBALS['roxy_cashflow_live_read']['square_gets'];
        return false;
    }
    ++$GLOBALS['roxy_cashflow_live_read']['blocked_http'];
    return new WP_Error('roxy_cashflow_readonly', 'Blocked non-Square-GET request during live read-only check.');
}, 10, 3);
add_filter('query', static function (string $query): string {
    if (preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|LOCK|UNLOCK|START|COMMIT|ROLLBACK)\b/i', $query)) {
        ++$GLOBALS['roxy_cashflow_live_read']['blocked_database_writes'];
        return 'SELECT 1 WHERE 1 = 0';
    }
    return $query;
});
add_filter('pre_wp_mail', static function () {
    ++$GLOBALS['roxy_cashflow_live_read']['blocked_mail'];
    return true;
});

$report = null;
$error_message = '';
try {
    $report = \RoxyGrosses\CashflowReport::for_day($date);
} catch (\Throwable $error) {
    $error_message = $error->getMessage();
}
$guard = $GLOBALS['roxy_cashflow_live_read'];
$output = [
    'status' => is_array($report) ? 'success' : 'error',
    'report_date' => $date,
    'error' => $error_message,
    'counts' => is_array($report) ? ($report['counts'] ?? null) : null,
    'totals_cents' => is_array($report) ? ($report['totals'] ?? null) : null,
    'collection_date_bases' => is_array($report) ? ($report['collection_date_bases'] ?? null) : null,
    'refund_date_bases' => is_array($report) ? ($report['refund_date_bases'] ?? null) : null,
    'square_gets' => $guard['square_gets'],
    'blocked_http_requests' => $guard['blocked_http'],
    'blocked_database_writes' => $guard['blocked_database_writes'],
    'blocked_mail_attempts' => $guard['blocked_mail'],
];
echo json_encode($output, JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($guard['blocked_http'] || $guard['blocked_database_writes'] || $guard['blocked_mail']) exit(2);
if (!is_array($report)) exit(1);
