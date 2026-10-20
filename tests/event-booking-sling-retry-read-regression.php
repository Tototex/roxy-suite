<?php
// Synthetic retry-scan regression. No WordPress site, provider calls, or mail.
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

final class WP_Error {
    private string $code;
    private string $message;
    public function __construct(string $code, string $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function add_action(...$args): void {}
function current_time($format): string { return '2026-10-20 12:00:00'; }
function sanitize_text_field($value): string { return (string) $value; }
function wp_strip_all_tags($value): string { return strip_tags((string) $value); }
function wp_json_encode($value): string|false { return json_encode($value); }
function roxy_eb_table_bookings(): string { return 'wp_roxy_bookings'; }
function roxy_eb_table_sling_logs(): string { return 'wp_roxy_sling_logs'; }
function roxy_eb_get_settings(): array { return ['sling_mode' => 'direct']; }
function as_enqueue_async_action(string $hook, array $args, string $group): int {
    $GLOBALS['queued'][] = compact('hook', 'args', 'group');
    return count($GLOBALS['queued']);
}

final class SlingRetryReadDatabase {
    public string $last_error = '';
    public int $insert_id = 1;
    public $rows = [];
    public array $logs = [];
    public bool $fail_next_read = false;
    public function prepare(string $sql, ...$args): string { return $sql; }
    public function esc_like(string $value): string { return addcslashes($value, '_%\\'); }
    public function get_results(string $sql, $format = null) {
        if ($this->fail_next_read) {
            $this->last_error = 'database unavailable';
            $this->fail_next_read = false;
            return null;
        }
        return $this->rows;
    }
    public function insert(string $table, array $row): int { $this->last_error = ''; $this->logs[] = [$table, $row]; return 1; }
}

require dirname(__DIR__) . '/includes/modules/event-booking/includes/repository.php';
require dirname(__DIR__) . '/includes/modules/event-booking/includes/sling.php';
$GLOBALS['wpdb'] = new SlingRetryReadDatabase();
$GLOBALS['queued'] = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
    echo "PASS: {$message}\n";
};

$wpdb = $GLOBALS['wpdb'];
$wpdb->rows = [];
$check(roxy_eb_repo_list_sling_auth_failed_bookings() === [], 'verified empty retry scan is an empty list');
roxy_eb_sling_job_retry_failed_auth_syncs();
$check($GLOBALS['queued'] === [] && $wpdb->logs === [], 'verified empty scan queues nothing and records no error');

$wpdb->rows = [['id' => 42]];
$wpdb->last_error = 'stale prior query error';
roxy_eb_sling_job_retry_failed_auth_syncs();
$check(count($GLOBALS['queued']) === 1
    && $GLOBALS['queued'][0]['hook'] === 'roxy_eb_sling_sync_booking'
    && $GLOBALS['queued'][0]['args'] === [42, 'auto_retry_after_token_refresh'],
    'valid retry candidates enqueue their existing booking sync');

$GLOBALS['queued'] = [];
$wpdb->rows = null;
$wpdb->last_error = 'database unavailable';
$result = roxy_eb_repo_list_sling_auth_failed_bookings();
$check(is_wp_error($result) && $result->get_error_code() === 'sling_retry_read_failed', 'query error is not converted into a verified empty list');
roxy_eb_sling_job_retry_failed_auth_syncs();
$check($GLOBALS['queued'] === [], 'uncertain read queues no provider sync');
$check(count($wpdb->logs) === 1
    && $wpdb->logs[0][0] === 'wp_roxy_sling_logs'
    && $wpdb->logs[0][1]['action'] === 'auto_retry_scan'
    && str_contains($wpdb->logs[0][1]['message'], 'no retries were queued'),
    'uncertain read is recorded in Sling Logs without sending mail');

$wpdb->last_error = '';
$wpdb->rows = false;
$check(is_wp_error(roxy_eb_repo_list_sling_auth_failed_bookings()), 'malformed non-array query result fails closed');
$wpdb->last_error = '';
$wpdb->rows = [];
$check(roxy_eb_repo_list_sling_logs() === [], 'verified empty Sling Logs query remains an empty list');
$wpdb->rows = [['id' => 3, 'action' => 'sync']];
$check(roxy_eb_repo_list_sling_logs() === $wpdb->rows, 'Sling Logs query returns verified rows');
$wpdb->fail_next_read = true;
$log_error = roxy_eb_repo_list_sling_logs();
$check(is_wp_error($log_error) && $log_error->get_error_code() === 'sling_logs_read_failed', 'Sling Logs read error is not shown as an empty log');
echo "{$checks} Event Booking Sling retry-read checks passed.\n";
