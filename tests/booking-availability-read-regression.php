<?php
// Isolated repository read-shape regression; no WordPress tables or live state.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
final class WP_Error {
    private string $code;
    public function __construct(string $code, string $message = '') { $this->code = $code; }
    public function get_error_code(): string { return $this->code; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
class BookingAvailabilityReadWpdb {
    public string $prefix = 'fixture_';
    public string $last_error = '';
    public $result = [];
    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) $query = preg_replace('/%s|%d/', "'" . addslashes((string) $arg) . "'", $query, 1);
        return $query;
    }
    public function get_results(string $query, $format = null) { return $this->result; }
}
function roxy_fixture_eb_table_bookings(): string { return 'fixture_bookings'; }
function roxy_fixture_eb_table_blocks(): string { return 'fixture_blocks'; }
$GLOBALS['wpdb'] = new BookingAvailabilityReadWpdb();
$source = file_get_contents(dirname(__DIR__) . '/includes/modules/event-booking/includes/repository.php');
if (!is_string($source)) throw new RuntimeException('Could not load candidate repository source');
$source = preg_replace('/^require_once __DIR__ \. \'\/reservations\.php\';\s*$/m', '', $source);
$source = str_replace('roxy_eb_', 'roxy_fixture_eb_', $source);
eval('?>' . $source);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
    echo "PASS: $message\n";
};
foreach ([
    'roxy_fixture_eb_repo_list_bookings_in_range',
    'roxy_fixture_eb_repo_list_blocks_in_range',
] as $reader) {
    $GLOBALS['wpdb']->last_error = '';
    $GLOBALS['wpdb']->result = [];
    $check($reader('2040-01-02 13:00:00', '2040-01-02 10:00:00') === [], $reader . ' preserves a verified empty result');
    $rows = [['id' => 17]];
    $GLOBALS['wpdb']->result = $rows;
    $check($reader('2040-01-02 13:00:00', '2040-01-02 10:00:00') === $rows, $reader . ' returns verified rows');
    foreach ([false, null, 'malformed'] as $bad) {
        $GLOBALS['wpdb']->result = $bad;
        $result = $reader('2040-01-02 13:00:00', '2040-01-02 10:00:00');
        $check(is_wp_error($result) && $result->get_error_code() === 'reservation_read', $reader . ' fails closed on non-array query result');
    }
    $GLOBALS['wpdb']->result = [];
    $GLOBALS['wpdb']->last_error = 'database read failed';
    $check(is_wp_error($reader('2040-01-02 13:00:00', '2040-01-02 10:00:00')), $reader . ' fails closed when the database reports a read error');
}
echo "$checks Event Booking availability read-shape checks passed.\n";
