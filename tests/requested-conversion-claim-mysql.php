<?php
/** Private request-shaped SQL fixture, not a real showing/order/provider approval. */
if (!defined('WP_CLI') || !WP_CLI) exit;
global $wpdb;
$path = $args[0] ?? '';
if (!is_file($path)) throw new RuntimeException('Candidate creation helper required.');
eval('?>' . str_replace('final class ConversionClaims {', 'final class SqlConversionClaims57 {', file_get_contents($path)));
$helper = '\\RoxyRS\\SqlConversionClaims57';
$label = 'PRIVATE CONVERSION CLAIM ' . bin2hex(random_bytes(8));
$id = 0; $checks = 0;
$assert = static function ($ok, $text) use (&$checks) { if (!$ok) throw new RuntimeException($text); $checks++; echo 'PASS: ' . $text . PHP_EOL; };
$throws = static function ($call) { try { $call(); return false; } catch (Throwable $error) { return true; } };
$mail = static function () { throw new RuntimeException('Unexpected mail'); };
$http = static function () { throw new RuntimeException('Unexpected provider request'); };
$baseline = static function () use ($wpdb, &$id): string {
    return hash('sha256', serialize($wpdb->get_results($wpdb->prepare("SELECT p.ID,p.post_status,m.meta_id,m.meta_key,m.meta_value FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type IN ('roxy_req_showing','roxy_showing','shop_order') AND p.ID<>%d ORDER BY p.ID,m.meta_id", $id), ARRAY_A)));
};
$guard = static function ($sql) use ($wpdb, &$id) {
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql)
        && (strpos($sql, $wpdb->postmeta) === false
            || !preg_match('/(?:SELECT\s+' . $id . '\s*,|`?post_id`?\s*=\s*[\'\"]?' . $id . '\b)/i', $sql))) throw new RuntimeException('Non-private creation SQL mutation');
    return $sql;
};
add_filter('pre_wp_mail', $mail, PHP_INT_MAX); add_filter('pre_http_request', $http, PHP_INT_MAX);
try {
    if ($wpdb->insert($wpdb->posts, ['post_type'=>'roxy_req_showing','post_status'=>'draft','post_title'=>$label,'post_date'=>current_time('mysql'),'post_date_gmt'=>current_time('mysql', true)]) !== 1) throw new RuntimeException('Private request creation failed');
    $id = (int) $wpdb->insert_id;
    if ($id <= 0) throw new RuntimeException('Private identity missing');
    $before = $baseline();
    add_filter('query', $guard, PHP_INT_MAX);
    $lease = $helper::lease('private-fixture:' . $id);
    try { $lease->assert_owner(); $assert(true, 'actual SQL lease verifies owned connection'); }
    finally { $lease->release_lease(); }
    $assert($throws(static fn()=>$lease->assert_owner()), 'released lease no longer authorizes work');
    $helper::begin_creation($id, 'showing');
    $values = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_roxy_rs_creation_showing'", $id));
    $marker = json_decode($values[0] ?? '', true);
    $assert(count($values) === 1 && ($marker['request_id'] ?? 0) === $id, 'one committed showing creation record survives outside transaction');
    $assert($throws(static fn()=>$helper::begin_creation($id, 'showing')), 'another showing creation is denied');
    $helper::begin_creation($id, 'order', 77);
    $assert($throws(static fn()=>$helper::begin_creation($id, 'order', 77)), 'another creation for the same backing is denied');
    $helper::begin_creation($id, 'order', 78);
    $assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE '_roxy_rs_creation_order_%%'", $id)) === 2, 'different backings retain independent creation records');
    $assert($before === $baseline(), 'original requests, showings and orders remain unchanged');
    echo "Passed $checks actual SQL creation checks; no real approval or provider call.\n";
} finally {
    remove_filter('query', $guard, PHP_INT_MAX);
    if ($id > 0) {
        $owned = $wpdb->get_row($wpdb->prepare("SELECT post_type,post_title FROM {$wpdb->posts} WHERE ID=%d", $id), ARRAY_A);
        if (!$owned || $owned['post_type'] !== 'roxy_req_showing' || $owned['post_title'] !== $label) throw new RuntimeException('Private cleanup ownership changed');
        if ($wpdb->delete($wpdb->postmeta, ['post_id'=>$id]) === false || $wpdb->delete($wpdb->posts, ['ID'=>$id]) !== 1) throw new RuntimeException('Private cleanup failed');
        wp_cache_delete($id, 'posts'); wp_cache_delete($id, 'post_meta');
    }
    remove_filter('pre_wp_mail', $mail, PHP_INT_MAX); remove_filter('pre_http_request', $http, PHP_INT_MAX);
}
