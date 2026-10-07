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
final class Fixture58PledgeReceipts {
    public static function fingerprint(array $row): string { unset($row['created_at'],$row['updated_at'],$row['id']); ksort($row); foreach ($row as &$v) if ($v !== null) $v=(string)$v; unset($v); return hash('sha256',json_encode($row)); }
    public static function replay($request,$user,$hash): int { return 0; }
    public static function begin($request,$user,$hash): void {}
    public static function finish($request,$user,$hash,$id): void {}
}
$table = $wpdb->prefix . 'fixture_rs_ent55_' . bin2hex(random_bytes(5));
$production = roxy_rs_table_backings();
$before = hash('sha256', serialize($wpdb->get_results('SELECT * FROM ' . $production . ' ORDER BY id', ARRAY_A)));
$namespace = 'RequestedSql55_' . bin2hex(random_bytes(4));
$GLOBALS['rs55_table'] = $table;
$source = file_get_contents($path);
$source = str_replace(['\\\\RoxyRS\\\\PledgeAttempts', '\\RoxyRS\\PledgeAttempts'], ['\\\\Fixture58PledgeReceipts', '\\Fixture58PledgeReceipts'], $source);
$creation_path = $args[1] ?? '';
if (!is_file($creation_path)) throw new RuntimeException('Candidate creation helper required.');
eval('?>' . str_replace('final class ConversionClaims {', 'final class SqlRepositoryClaims57 {', file_get_contents($creation_path)));
$source = str_replace(['\\\\RoxyRS\\\\ConversionClaims', '\\RoxyRS\\ConversionClaims', '\\RoxyRS\\CPT::get_status', '\\RoxyRS\\Frontend::backing_window_open'], ['\\\\RoxyRS\\\\SqlRepositoryClaims57', '\\RoxyRS\\SqlRepositoryClaims57', 'fixture_request_status', 'fixture_request_window'], $source);
$source = str_replace(['\\\\RoxyST\\\\Capacity', '\\RoxyST\\Capacity', 'new WP_Error'], ['\\\\Fixture55Capacity', '\\Fixture55Capacity', 'new \\WP_Error'], $source);
eval('namespace ' . $namespace . ';' . substr($source, 5));
eval('namespace ' . $namespace . '; function roxy_rs_table_backings(): string { return $GLOBALS["rs55_table"]; }');
// Virtual request/deadline eligibility only; actual request leases and INSERT predicates.
eval('namespace ' . $namespace . '; function get_post($id) { return (object)["post_type"=>"roxy_req_showing"]; } function get_post_meta($id,$key,$single=true) { return ""; } function wp_cache_delete($id,$group) {} function fixture_request_status($id) { return "active"; } function fixture_request_window($value) { return true; }');
$insert = $namespace . '\\roxy_rs_repo_insert_backing';
$lock_name = $namespace . '\\roxy_rs_repo_subscriber_lock_name';
$checks = 0;
$assert = static function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException($label); $checks++; echo 'PASS: ' . $label . PHP_EOL; };
$drop_lease_at_insert = false;
$drop_request_lease = false;
$guard = static function ($sql) use ($wpdb, $table, $lock_name, &$drop_lease_at_insert, &$drop_request_lease) {
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql) && strpos($sql, $table) === false) throw new RuntimeException('Non-private SQL mutation.');
    if ($drop_lease_at_insert && preg_match('/^\s*INSERT\b/i', $sql)) {
        $drop_lease_at_insert = false;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name(501, 77)));
    }
    if ($drop_request_lease && preg_match('/^\s*INSERT\b/i', $sql)) {
        $drop_request_lease = false;
        $name = 'roxy_scope_' . substr(hash('sha256', $wpdb->prefix . ':requested-conversion:request:503'), 0, 48);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
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
    $paid_count = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
    $drop_request_lease = true;
    $assert(is_wp_error($insert(['request_id'=>503,'user_id'=>77,'general_qty'=>1,'support_qty'=>1,'charge_total'=>1200])) && (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . $table) === $paid_count, 'request lease loss at paid INSERT rejects reconnect-safe write');
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
