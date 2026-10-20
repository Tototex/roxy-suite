<?php
// Isolated customer booking-history read failure regression; no live WordPress state.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

final class BookingHistoryReadWpError {
    private string $code;
    private string $message;
    public function __construct(string $code, string $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}
class_alias(BookingHistoryReadWpError::class, 'WP_Error');
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function sanitize_email($email): string { return filter_var((string) $email, FILTER_SANITIZE_EMAIL); }
function is_user_logged_in(): bool { return true; }
function wp_get_current_user() { return (object) ['user_email' => 'customer@example.test']; }
function get_current_user_id(): int { return 42; }
function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function roxy_fixture_eb_table_bookings(): string { return 'fixture_bookings'; }
function roxy_fixture_eb_order_edit_cutoff_days(): int { return 7; }

final class BookingHistoryReadWpdb {
    public string $last_error = '';
    public $result = [];
    public bool $fail_next_read = false;
    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) $query = preg_replace('/%s|%d/', "'" . addslashes((string) $arg) . "'", $query, 1);
        return $query;
    }
    public function get_results(string $query, $format = null) {
        if ($this->fail_next_read) {
            $this->last_error = 'fixture database read failure';
            $this->fail_next_read = false;
            return null;
        }
        return $this->result;
    }
}
$GLOBALS['wpdb'] = new BookingHistoryReadWpdb();

$repo = file_get_contents(dirname(__DIR__) . '/includes/modules/event-booking/includes/repository.php');
$account = file_get_contents(dirname(__DIR__) . '/includes/modules/event-booking/includes/my-account.php');
if (!is_string($repo) || !is_string($account)) throw new RuntimeException('Could not load candidate booking sources');
$repo = preg_replace('/^require_once __DIR__ \. \'\/reservations\.php\';\s*$/m', '', $repo);
$repo = str_replace('roxy_eb_', 'roxy_fixture_eb_', $repo);
$account = str_replace('roxy_eb_', 'roxy_fixture_eb_', $account);
eval('?>' . $repo);
eval('?>' . $account);

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
    echo "PASS: $message\n";
};

$GLOBALS['wpdb']->fail_next_read = true;
$failed = roxy_fixture_eb_repo_list_bookings_for_user(42, 'customer@example.test');
$check(is_wp_error($failed) && $failed->get_error_code() === 'booking_list_read_failed', 'booking history repository exposes database read failure');
$GLOBALS['wpdb']->fail_next_read = true;
ob_start();
roxy_fixture_eb_render_my_bookings();
$failure_html = ob_get_clean();
$check(str_contains($failure_html, 'temporarily unavailable') && !str_contains($failure_html, 'No bookings found.'), 'customer view distinguishes a read failure from an empty history');

$GLOBALS['wpdb']->result = [];
ob_start();
roxy_fixture_eb_render_my_bookings();
$empty_html = ob_get_clean();
$check(str_contains($empty_html, 'No bookings found.') && !str_contains($empty_html, 'temporarily unavailable'), 'customer view preserves the verified empty-history message');

echo "$checks booking history read regression checks passed.\n";
