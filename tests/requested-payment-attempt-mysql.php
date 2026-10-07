<?php
/** Actual Issuance/SQL on an owned private order-shaped post; no Woo save, payment, mail or customer mutation. */
if (!defined('WP_CLI') || !WP_CLI) exit;
global $wpdb;
$path = $args[0] ?? '';
if (!is_file($path)) throw new RuntimeException('Payment helper candidate required.');
$source = str_replace('final class PaymentAttempts {', 'final class SqlPaymentAttempts56 {', file_get_contents($path));
eval('?>' . $source);
$helper = '\\RoxyRS\\SqlPaymentAttempts56';
$label = 'PRIVATE PAYMENT ATTEMPT SQL ' . bin2hex(random_bytes(8));
$id = 0; $checks = 0;
$assert = static function ($ok, $text) use (&$checks) { if (!$ok) throw new RuntimeException($text); $checks++; echo 'PASS: ' . $text . PHP_EOL; };
$throwing = static function ($callback): bool { try { $callback(); return false; } catch (Throwable $error) { return true; } };
$mail = static function () { throw new RuntimeException('Unexpected mail.'); };
$http = static function () { throw new RuntimeException('Unexpected provider request.'); };
$guard = static function ($sql) use ($wpdb, &$id) {
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql)
        && (strpos($sql, $wpdb->postmeta) === false
            || !preg_match('/(?:SELECT\s+' . $id . '\s*,|`?post_id`?\s*=\s*[\'\"]?' . $id . '\b)/i', $sql))) {
        throw new RuntimeException('Non-private attempt SQL mutation.');
    }
    return $sql;
};
$original_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order'");
$baseline = static function () use ($wpdb, &$id): string {
    return hash('sha256', serialize($wpdb->get_results($wpdb->prepare("SELECT p.ID,p.post_status,m.meta_id,m.meta_key,m.meta_value FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type='shop_order' AND p.ID<>%d ORDER BY p.ID,m.meta_id", $id), ARRAY_A)));
};
$previous = $wpdb->suppress_errors(true);
add_filter('pre_wp_mail', $mail, PHP_INT_MAX); add_filter('pre_http_request', $http, PHP_INT_MAX);
try {
    if ($wpdb->insert($wpdb->posts, ['post_type'=>'shop_order','post_status'=>'wc-pending','post_title'=>$label,'post_date'=>current_time('mysql'),'post_date_gmt'=>current_time('mysql', true)]) !== 1) throw new RuntimeException('Private post creation failed.');
    $id = (int) $wpdb->insert_id;
    if ($id <= 0) throw new RuntimeException('Private identity missing.');
    $before = $baseline();
    foreach (['_customer_user'=>'77','_roxy_rs_request_id'=>'501','_roxy_rs_backing_id'=>'1','_order_total'=>'12.50','_order_currency'=>'USD'] as $key=>$value) {
        if ($wpdb->insert($wpdb->postmeta, ['post_id'=>$id,'meta_key'=>$key,'meta_value'=>$value]) !== 1) throw new RuntimeException('Private metadata failed.');
    }
    add_filter('query', $guard, PHP_INT_MAX);
    $order = new WC_Order(); $order->set_id($id); $order->set_customer_id(77); $order->set_total('12.50'); $order->set_currency('USD');
    $request = ['request_id'=>501,'backing_id'=>1,'customer_id'=>77,'amount'=>1250,'currency'=>'usd'];
    $claim = $helper::claim($order, $request);
    $assert(is_string($claim['key'] ?? null) && $helper::verify($order, $claim), 'actual transaction commits a verifiable pre-provider attempt');
    $assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_roxy_rs_payment_attempt'", $id)) === 1, 'one immutable attempt marker is persisted');
    $assert($throwing(static fn()=>$helper::claim($order, $request)), 'another claim cannot create a second payment attempt');
    $assert($helper::record_result($order, $claim, 'pi_fixture56', 'processing'), 'pending provider result persists as review evidence');
    $assert(!$helper::verify($order, $claim), 'recorded outcome prevents another provider request');
    $assert($helper::record_result($order, $claim, 'pi_fixture56', 'processing'), 'same recorded result is idempotent');
    $assert(!$helper::record_result($order, $claim, 'pi_other56', 'succeeded'), 'different intent cannot overwrite recorded result');
    $assert($wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d",$id)) === 'wc-pending', 'helper never marks actual order-shaped post paid');
    $assert($before === $baseline(), 'every original order/status/metadata row remains unchanged');
    echo 'Passed ' . $checks . ' actual SQL attempt assertions; no provider or Woo lifecycle invoked.' . PHP_EOL;
} finally {
    remove_filter('query', $guard, PHP_INT_MAX);
    if ($id > 0) {
        $owned = $wpdb->get_row($wpdb->prepare("SELECT post_type,post_title FROM {$wpdb->posts} WHERE ID=%d", $id), ARRAY_A);
        if (!$owned || $owned['post_type'] !== 'shop_order' || $owned['post_title'] !== $label) throw new RuntimeException('Private cleanup ownership changed.');
        if ($wpdb->delete($wpdb->postmeta, ['post_id'=>$id]) === false || $wpdb->delete($wpdb->posts, ['ID'=>$id]) !== 1) throw new RuntimeException('Private cleanup failed.');
        wp_cache_delete($id, 'posts'); wp_cache_delete($id, 'post_meta');
    }
    remove_filter('pre_wp_mail', $mail, PHP_INT_MAX); remove_filter('pre_http_request', $http, PHP_INT_MAX);
    $wpdb->suppress_errors($previous);
    if ((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order'") !== $original_orders) throw new RuntimeException('Private order count did not restore.');
}
