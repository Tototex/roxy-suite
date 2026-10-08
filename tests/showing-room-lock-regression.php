<?php
// Isolated exercise of the real shared-room lock and showing-window validator.
// No WordPress database, provider, mail, or production-data access.
define('ABSPATH', __DIR__);
eval('namespace RoxyST; final class CPT { const POST_TYPE = "roxy_showing"; }');

class WP_Error {
    public function __construct(private string $code = '', private string $message = '') {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_timezone(): DateTimeZone { return new DateTimeZone('America/Los_Angeles'); }
function roxy_eb_datetime_to_mysql(DateTimeImmutable $date): string { return $date->format('Y-m-d H:i:s'); }
function add_filter(...$args): void {}
function remove_filter(...$args): void {}
function roxy_eb_table_bookings(): string { return 'fixture_bookings'; }
function roxy_eb_table_blocks(): string { return 'fixture_blocks'; }
function roxy_eb_get_settings(): array { return ['showtime_blocks'=>[]]; }
function roxy_eb_repo_list_bookings_in_range(string $start, string $end): array {
    return array_values(array_filter($GLOBALS['room_bookings'], static fn($row) => $row['start'] < $end && $row['end'] > $start));
}
function roxy_eb_repo_list_blocks_in_range(string $start, string $end): array {
    return array_values(array_filter($GLOBALS['room_blocks'], static fn($row) => $row['start'] < $end && $row['end'] > $start));
}
function get_posts(array $args): array { return array_keys($GLOBALS['room_showings']); }
function get_post_meta(int $id, string $key, bool $single = true): string { return (string) ($GLOBALS['room_showings'][$id] ?? ''); }
function get_the_title(int $id): string { return 'Fixture showing ' . $id; }

final class RoomLockDatabaseFixture {
    public string $prefix = 'fixture_';
    public string $posts = 'fixture_posts';
    public string $postmeta = 'fixture_postmeta';
    public string $terms = 'fixture_terms';
    public string $term_taxonomy = 'fixture_term_taxonomy';
    public string $term_relationships = 'fixture_term_relationships';
    public array $engines = [];
    public string $last_error = '';

    public function __construct() {
        foreach ([$this->posts,$this->postmeta,$this->terms,$this->term_taxonomy,$this->term_relationships,'fixture_bookings','fixture_blocks'] as $table) $this->engines[$table] = 'InnoDB';
    }
    public function prepare(string $query, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        foreach ($args as $value) {
            $quoted = is_numeric($value) ? (string) $value : "'" . str_replace("'", "''", (string) $value) . "'";
            $query = preg_replace('/%[sdf]/', $quoted, $query, 1);
        }
        return $query;
    }
    public function get_var(string $query) {
        if (strpos($query, 'CONNECTION_ID()') !== false && strpos($query, 'SELECT CONNECTION_ID()') === 0) return 41;
        if (strpos($query, 'SELECT @@SESSION.autocommit') === 0) return '1';
        if (strpos($query, 'information_schema.TABLES') !== false && preg_match("/TABLE_NAME='([^']+)'/", $query, $m)) return $this->engines[$m[1]] ?? null;
        if (preg_match("/GET_LOCK\\('([^']+)'/", $query, $m)) {
            if (isset($GLOBALS['room_lock_owners'][$m[1]])) return '0';
            $GLOBALS['room_lock_owners'][$m[1]] = 41;
            return '1';
        }
        if (preg_match("/RELEASE_LOCK\\('([^']+)'/", $query, $m)) { unset($GLOBALS['room_lock_owners'][$m[1]]); return '1'; }
        if (strpos($query, 'SELECT IF(') === 0) return empty($GLOBALS['room_owner_valid']) ? '0' : '1';
        if (preg_match("/IS_USED_LOCK\\('([^']+)'/", $query, $m)) return $GLOBALS['room_lock_owners'][$m[1]] ?? null;
        return null;
    }
    public function query(string $query) {
        if (strpos($query, 'SAVEPOINT ') === 0 || strpos($query, 'RELEASE SAVEPOINT ') === 0) return false;
        return 1;
    }
    public function suppress_errors(bool $value): bool { return false; }
}

global $wpdb;
$wpdb = new RoomLockDatabaseFixture();
$GLOBALS['room_lock_owners'] = [];
$GLOBALS['room_owner_valid'] = true;
$GLOBALS['room_bookings'] = [['start'=>'2040-01-02 10:00:00','end'=>'2040-01-02 13:00:00']];
$GLOBALS['room_blocks'] = [['start'=>'2040-01-02 14:00:00','end'=>'2040-01-02 15:00:00']];
$GLOBALS['room_showings'] = [501=>'2040-01-02T19:00'];
require dirname(__DIR__) . '/includes/modules/event-booking/includes/reservations.php';
require dirname(__DIR__) . '/includes/modules/event-booking/includes/availability.php';

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
    echo "PASS: $label\n";
};

$invoked = false;
$conflict = roxy_eb_with_showing_time_lock('2040-01-02T14:30', 0, static function () use (&$invoked) { $invoked = true; return true; });
$check(is_wp_error($conflict) && $conflict->get_error_code() === 'reservation_conflict' && !$invoked, 'showing that overlaps a booking and manual block is refused before writes');

$self_write = false;
$self = roxy_eb_with_showing_time_lock('2040-01-02T19:00', 501, static function () use (&$self_write, $wpdb) {
    $self_write = true;
    $key = 'roxy_room_' . substr(hash('sha256', $wpdb->prefix), 0, 48);
    return ($GLOBALS['room_lock_owners'][$key] ?? 0) === 41;
});
$check($self === true && $self_write, 'an existing showing can retain its own time while the shared room lock is held');

$guarded_insert = '';
$insert_result = roxy_eb_with_showing_time_lock('2040-01-02T19:00', 501, static function ($guard) use (&$guarded_insert) {
    $guarded_insert = $guard->guard_transaction_query("INSERT INTO `fixture_posts` (`post_title`) VALUES ('Safe showing')");
    return strpos($guarded_insert, 'SELECT \'Safe showing\' FROM DUAL WHERE (CONNECTION_ID()=41') !== false;
});
$check($insert_result === true, 'post inserts retain a same-statement room-lock ownership predicate');

$guarded_update = '';
$update_result = roxy_eb_with_showing_time_lock('2040-01-02T19:00', 501, static function ($guard) use (&$guarded_update) {
    $guarded_update = $guard->guard_transaction_query("UPDATE `fixture_postmeta` SET `meta_value`='new' WHERE `post_id`=501");
    return strpos($guarded_update, 'AND ((CONNECTION_ID()=41') !== false;
});
$check($update_result === true, 'post-meta updates constrain their WHERE clause by room-lock ownership');

$reconnect_rejected = false;
$connection_check = roxy_eb_with_showing_time_lock('2040-01-02T19:00', 501, static function ($guard) use (&$reconnect_rejected) {
    $GLOBALS['room_owner_valid'] = false;
    try { $guard->guard_transaction_query("DELETE FROM `fixture_posts` WHERE `ID`=501"); }
    catch (RuntimeException $error) { $reconnect_rejected = str_contains($error->getMessage(), 'ownership lost'); }
    finally { $GLOBALS['room_owner_valid'] = true; }
    return $reconnect_rejected;
});
$check($connection_check === true, 'a lost connection or lock aborts a WordPress post write before execution');

$GLOBALS['room_showings'][502] = '2040-01-02T20:00';
$other_write = false;
$other = roxy_eb_with_showing_time_lock('2040-01-02T19:00', 501, static function () use (&$other_write) { $other_write = true; return true; });
$check(is_wp_error($other) && $other->get_error_code() === 'reservation_conflict' && !$other_write, 'rescheduling ignores only itself and rejects another showing in the interval');

$GLOBALS['room_showings'] = [501=>'2040-01-02T19:00'];
$GLOBALS['room_bookings'] = [];
$GLOBALS['room_blocks'] = [];
$nested_terms_guarded = false;
$nested = roxy_eb_with_showing_time_lock('2040-01-02T19:00', 501, static function () use (&$nested_terms_guarded) {
    return roxy_eb_with_showing_time_lock('2040-01-03T19:00', 0, static function ($guard) use (&$nested_terms_guarded) {
        $sql = $guard->guard_transaction_query("INSERT INTO `fixture_term_relationships` (`object_id`,`term_taxonomy_id`) VALUES (502,1)");
        $nested_terms_guarded = strpos($sql, 'FROM DUAL WHERE (CONNECTION_ID()=41') !== false;
        return true;
    }, true);
});
$check($nested === true && $nested_terms_guarded, 'nested schedule changes guard taxonomy writes under one room claim');

$before = count($GLOBALS['room_lock_owners']);
$invalid = roxy_eb_with_showing_time_lock('2040-02-30T19:00', 0, static fn() => true);
$check(is_wp_error($invalid) && $invalid->get_error_code() === 'reservation_time' && count($GLOBALS['room_lock_owners']) === $before, 'invalid calendar date is rejected before taking a lock');

$wpdb->engines[$wpdb->postmeta] = 'MyISAM';
$unavailable = roxy_eb_with_showing_time_lock('2040-01-04T19:00', 0, static fn() => true);
$check(is_wp_error($unavailable) && $unavailable->get_error_code() === 'reservation_storage', 'nontransactional showing metadata storage fails closed');

echo "Passed $checks isolated shared-room showing checks.\n";
