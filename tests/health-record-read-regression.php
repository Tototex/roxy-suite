<?php
// Isolated actual Health reads; no WordPress bootstrap or external calls.
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
function roxy_suite_module_enabled($key): bool { return true; }
function roxy_rs_table_backings(): string { return 'wp_roxy_requested_showing_backings'; }
function roxy_eb_table_bookings(): string { return 'wp_roxy_event_bookings'; }
function current_time($format) { return '2026-10-08 12:00:00'; }
function get_option($key, $default = false) { return $GLOBALS['health_options'][$key] ?? $default; }
function get_posts($args): array {
    if ($GLOBALS['posts_error']) $GLOBALS['wpdb']->last_error = 'fixture query error';
    return $GLOBALS['posts_results'] ?? [];
}
function wp_date($format, $timestamp = null, $timezone = null): string { return '2026-10-08T12:00'; }
function wp_timezone() { return new DateTimeZone('UTC'); }
function get_the_title($id): string { return 'Fixture show'; }
function get_post_meta($id, $key, $single = false) { return true; }
final class HealthRecordFixture {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public $count = '0';
    public $count_result = null;
    public bool $exists = true;
    public bool $count_error = false;
    public bool $lookup_error = false;
    public bool $throws = false;
    public $log_row = null;
    public bool $log_error = false;
    public function prepare($sql, ...$args) { return str_replace('%s', "'" . $args[0] . "'", $sql); }
    public function get_var($sql) {
        if ($this->throws) throw new RuntimeException('fixture SQL exception');
        if (strpos($sql, 'SHOW TABLES') === 0) {
            if ($this->lookup_error) $this->last_error = 'fixture lookup failure';
            preg_match("/LIKE '([^']+)'/", $sql, $match);
            return $this->exists ? $match[1] : null;
        }
        if ($this->count_error) $this->last_error = 'fixture count failure';
        return $this->count_result ?? $this->count;
    }
    public function get_row($sql, $format) {
        if ($this->log_error) $this->last_error = 'fixture log failure';
        return $this->log_row;
    }
}
$GLOBALS['wpdb'] = new HealthRecordFixture();
$GLOBALS['posts_error'] = false;
$GLOBALS['posts_results'] = [];
$GLOBALS['health_options'] = [];
require $argv[1] ?? dirname(__DIR__) . '/includes/class-roxy-suite-health.php';
$checks = 0;
$call = static function ($method, ...$args) { return (new ReflectionMethod(\RoxySuite\Health::class, $method))->invoke(null, ...$args); };
$check = static function ($condition, $message) use (&$checks) {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
foreach (['0', '42', 0, 42, '99999999999999999999'] as $value) {
    $wpdb->count = $value;
    $item = $call('record_count_item', 'Records', 'wp_fixture');
    $check($item['status'] === 'pass' && $item['detail'] === (string) $value . ' total', 'valid integer count');
}
foreach ([null, false, [], '-1', '1.5', '1e2', 'unknown', ''] as $value) {
    $wpdb->count = $value;
    $check($call('record_count_item', 'Records', 'wp_fixture')['status'] === 'warn', 'unknown/malformed count not zero');
}
$wpdb->count = '0';
foreach (['count_error', 'lookup_error', 'throws'] as $fault) {
    $wpdb->$fault = true;
    $check($call('record_count_item', 'Records', 'wp_fixture')['status'] === 'warn', $fault . ' not green');
    $wpdb->$fault = false;
}
$table_item = new ReflectionMethod(\RoxySuite\Health::class, 'table_item');
$wpdb->exists = true;
$wpdb->lookup_error = false;
$check($table_item->invoke(null, 'Fixture table', 'wp_fixture')['status'] === 'pass', 'existing table check is green only after verified lookup');
$wpdb->lookup_error = true;
$check($table_item->invoke(null, 'Fixture table', 'wp_fixture')['detail'] === 'Unavailable', 'table lookup failure is not reported as missing');
$wpdb->lookup_error = false;
$wpdb->exists = false;
$check($table_item->invoke(null, 'Fixture table', 'wp_fixture')['status'] === 'fail', 'verified missing table remains a failure');
$wpdb->exists = true;
$check($table_item->invoke(null, 'Fixture table', 'wp_fixture;DROP')['status'] === 'warn', 'invalid table identity is unavailable');
$wpdb->throws = true;
$check($table_item->invoke(null, 'Fixture table', 'wp_fixture')['detail'] === 'Unavailable', 'thrown table lookup is unavailable');
$wpdb->throws = false;
$wpdb->exists = false;
$check($call('record_count_item', 'Records', 'wp_fixture')['status'] === 'warn', 'missing table not empty');
$wpdb->exists = true;
$check($call('record_count_item', 'Records', 'wp_fixture;DROP')['status'] === 'warn', 'invalid identifier denied');
foreach (['functional_will_call', 'functional_arcade', 'functional_requested_showings'] as $module) {
    $wpdb->count_error = true;
    $items = $call($module);
    $count_item = $items[count($items) - 1];
    $check($count_item['status'] === 'warn' && $count_item['detail'] === 'Read unavailable', $module . ' uses checked count');
    $wpdb->count_error = false;
}
$event_booking = static function () use ($call) { return $call('functional_event_booking')[0]; };
$wpdb->count_result = '0';
$event_zero = $event_booking();
$check($event_zero['status'] === 'warn' && $event_zero['detail'] === 'None' && str_contains($event_zero['note'], 'No upcoming bookings found'), 'verified zero booking count remains a truthful empty result');
$wpdb->count_error = true;
$event_failed = $event_booking();
$check($event_failed['status'] === 'warn' && $event_failed['detail'] === 'Unavailable' && str_contains($event_failed['note'], 'not a zero-booking result'), 'booking SQL failure is unavailable, not zero');
$wpdb->count_error = false;
foreach ([false, 'unknown', '-1', '1.5', '99999999999999999999'] as $bad_count) {
    $wpdb->count_result = $bad_count;
    $check($event_booking()['detail'] === 'Unavailable', 'malformed/overflow booking count is unavailable');
}
$wpdb->count_result = null;
$grosses_log_item = static function () use ($call) {
    $items = $call('functional_grosses');
    foreach ($items as $item) if ($item['label'] === 'Latest log event') return $item;
    throw new RuntimeException('Latest Grosses log health item missing.');
};
$wpdb->lookup_error = true;
$grosses_unavailable = $call('functional_grosses');
$check($grosses_unavailable[0]['status'] === 'warn' && $grosses_unavailable[0]['detail'] === 'Unavailable', 'Grosses table lookup failure is not reported as missing');
$wpdb->lookup_error = false;
$wpdb->log_row = null;
$check($grosses_log_item()['detail'] === 'No logs found', 'verified empty Grosses logs remain a truthful empty result');
$wpdb->log_error = true;
$log_unavailable = $grosses_log_item();
$check($log_unavailable['status'] === 'warn' && $log_unavailable['detail'] === 'Log history unavailable', 'Grosses log SQL failure cannot appear as empty/successful history');
$wpdb->log_error = false;
$wpdb->log_row = false;
$check($grosses_log_item()['detail'] === 'Log history unavailable', 'malformed Grosses log query result is unavailable');
$wpdb->log_row = ['event_type'=>'sync','mode'=>'auto','success'=>true,'created_at'=>'2026-10-08 12:00:00','message'=>''];
$check($grosses_log_item()['detail'] === 'Log history unavailable', 'malformed Grosses log success flag is not accepted');
$wpdb->log_row['success'] = 1;
$check($grosses_log_item()['status'] === 'pass', 'verified successful Grosses log remains green');
$wpdb->log_row = null;
$GLOBALS['health_options']['roxy_grosses_settings'] = ['schedule_enabled'=>'1'];
$GLOBALS['health_options']['roxy_grosses_last_auto_date'] = '2026-02-30';
$grosses_items = $call('functional_grosses');
$auto_item = null;
foreach ($grosses_items as $item) if ($item['label'] === 'Last automatic run') $auto_item = $item;
$check(is_array($auto_item) && $auto_item['status'] === 'warn' && str_contains($auto_item['note'], 'Could not interpret'), 'impossible automatic Grosses date cannot normalize into a status');
$GLOBALS['health_options']['roxy_grosses_last_auto_date'] = (new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))->format('Y-m-d');
$grosses_items = $call('functional_grosses');
foreach ($grosses_items as $item) if ($item['label'] === 'Last automatic run') $auto_item = $item;
$check(is_array($auto_item) && $auto_item['status'] === 'warn' && str_contains($auto_item['note'], 'future'), 'future automatic Grosses date warns');
$GLOBALS['health_options']['roxy_grosses_last_auto_date'] = ['not-a-date'];
$grosses_items = $call('functional_grosses');
foreach ($grosses_items as $item) if ($item['label'] === 'Last automatic run') $auto_item = $item;
$check(is_array($auto_item) && $auto_item['status'] === 'warn' && $auto_item['detail'] === 'Invalid marker', 'non-string automatic Grosses date warns without coercion');
$GLOBALS['health_options'] = [];
$GLOBALS['posts_error'] = true;
$check($call('functional_requested_showings')[0]['status'] === 'warn', 'failed request query not empty list');
$show_items = $call('functional_show_tickets');
$check($show_items[0]['detail'] === 'Unavailable' && str_contains($show_items[0]['note'], 'not an empty schedule'), 'failed show query is unavailable, not an empty schedule');
$GLOBALS['posts_error'] = false;
$check($call('functional_requested_showings')[0]['status'] === 'pass', 'verified empty request list remains valid');
$check($call('functional_show_tickets')[0]['detail'] === 'None found', 'verified empty show query remains distinguishable from failure');
$GLOBALS['posts_results'] = [(object) ['ID'=>123]];
$check($call('functional_show_tickets')[0]['status'] === 'pass', 'verified upcoming show still reports healthy');
echo "$checks record-read checks passed\n";
