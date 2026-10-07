<?php
/** Private SQL backing/lease fixture. Virtual entitlement; no orders, membership or payment changes. */
if (!defined('WP_CLI') || !WP_CLI) exit;
global $wpdb;
$path = $args[0] ?? '';
if (!is_file($path)) throw new RuntimeException('Repository candidate required.');
final class Fixture55Capacity {
    public static int $entitlement = 2;
    public static function subscription_entitlement_count(int $user): int { return self::$entitlement; }
}
$table = $wpdb->prefix . 'fixture_rs_ent55_' . bin2hex(random_bytes(5));
$production = roxy_rs_table_backings();
$before = hash('sha256', serialize($wpdb->get_results('SELECT * FROM ' . $production . ' ORDER BY id', ARRAY_A)));
$namespace = 'RequestedSql55_' . bin2hex(random_bytes(4));
$GLOBALS['rs55_table'] = $table;
$source = file_get_contents($path);
$source = str_replace(['\\\\RoxyST\\\\Capacity', '\\RoxyST\\Capacity', 'new WP_Error'], ['\\\\Fixture55Capacity', '\\Fixture55Capacity', 'new \\WP_Error'], $source);
eval('namespace ' . $namespace . ';' . substr($source, 5));
eval('namespace ' . $namespace . '; function roxy_rs_table_backings(): string { return $GLOBALS["rs55_table"]; }');
$insert = $namespace . '\\roxy_rs_repo_insert_backing';
$lock_name = $namespace . '\\roxy_rs_repo_subscriber_lock_name';
$checks = 0;
$assert = static function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException($label); $checks++; echo 'PASS: ' . $label . PHP_EOL; };
$drop_lease_at_insert = false;
$guard = static function ($sql) use ($wpdb, $table, $lock_name, &$drop_lease_at_insert) {
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql) && strpos($sql, $table) === false) throw new RuntimeException('Non-private SQL mutation.');
    if ($drop_lease_at_insert && preg_match('/^\s*INSERT\b/i', $sql)) {
        $drop_lease_at_insert = false;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name(501, 77)));
    }
    return $sql;
};
$mail = static function () { throw new RuntimeException('Unexpected mail.'); };
$http = static function () { throw new RuntimeException('Unexpected provider call.'); };
$created = false;
$previous = $wpdb->suppress_errors(true);
try {
    if ($wpdb->query('CREATE TEMPORARY TABLE ' . $table . ' LIKE ' . $production) === false) throw new RuntimeException('Private schema failed.');
    $created = true;
    add_filter('query', $guard, PHP_INT_MAX);
    add_filter('pre_wp_mail', $mail, PHP_INT_MAX);
    add_filter('pre_http_request', $http, PHP_INT_MAX);
    $row = ['request_id' => 501, 'user_id' => 77, 'subscriber_qty' => 1];
    $name = $lock_name(501, 77);
    $first = $insert($row);
    $assert(is_int($first) && $first > 0 && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table) === 1, 'guarded subscriber insert works with actual prepared SQL');
    $assert($wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $name)) === null, 'successful insert releases actual named lease');
    $second = $insert($row);
    $assert(is_int($second) && $second > $first, 'second unit fits remaining request entitlement');
    $assert(is_wp_error($insert($row)) && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table) === 2, 'aggregate outstanding entitlement denies third pledge');
    $assert($wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $name)) === null, 'excess denial releases actual named lease');
    Fixture55Capacity::$entitlement = 0;
    $assert(is_wp_error($insert(['request_id' => 502, 'user_id' => 77, 'subscriber_qty' => 1])), 'missing entitlement denies before SQL insert');
    Fixture55Capacity::$entitlement = 2;
    $wpdb->query('DELETE FROM ' . $table);
    $drop_lease_at_insert = true;
    $assert(is_wp_error($insert($row)) && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table) === 0, 'ownership loss at actual INSERT rejects the write');
    $assert($wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $name)) === null, 'lost lease remains unowned');
    $paid = $insert(['request_id' => 503, 'user_id' => 77, 'general_qty' => 1, 'support_qty' => 1, 'charge_total' => 1200]);
    $assert(is_int($paid) && $paid > 0, 'paid-only repository insertion remains compatible');
    $assert(is_wp_error($insert(['request_id' => 504, 'user_id' => 77, 'general_qty' => '1.5'])), 'fractional persisted quantity rejected');
    $assert($before === hash('sha256', serialize($wpdb->get_results('SELECT * FROM ' . $production . ' ORDER BY id', ARRAY_A))), 'all production backing rows unchanged');
    echo 'Passed ' . $checks . ' actual private SQL assertions; no real order/payment created.' . PHP_EOL;
} finally {
    if ($created && $wpdb->query('DROP TEMPORARY TABLE ' . $table) === false) throw new RuntimeException('Private schema cleanup failed.');
    remove_filter('query', $guard, PHP_INT_MAX);
    remove_filter('pre_wp_mail', $mail, PHP_INT_MAX);
    remove_filter('pre_http_request', $http, PHP_INT_MAX);
    $wpdb->suppress_errors($previous);
    unset($GLOBALS['rs55_table']);
}
