<?php
// Real WordPress/MySQL coverage for bounded active-subscriber search. No Woo
// subscription objects are loaded or modified by this read-only lookup fixture.
$root = $argv[1] ?? dirname(__DIR__);
require_once $root . '/includes/modules/sub-check/roxy-sub-check.php';

function member_search_mysql_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

register_post_type('shop_subscription', ['public' => false, 'show_ui' => false, 'supports' => []]);
register_post_status('wc-active', ['public' => true, 'label' => 'Active subscription']);
register_post_status('wc-pending-cancel', ['public' => true, 'label' => 'Pending-cancel subscription']);
register_post_status('wc-cancelled', ['public' => true, 'label' => 'Cancelled subscription']);

$user_id = wp_insert_user([
    'user_login' => 'member-needle-' . wp_generate_password(8, false),
    'user_pass' => wp_generate_password(24),
    'user_email' => 'needleunique@example.invalid',
    'display_name' => 'Roxy NeedleUnique',
]);
if (is_wp_error($user_id)) throw new RuntimeException('Could not create the isolated member-search user.');
update_user_meta($user_id, 'first_name', 'Roxy');
update_user_meta($user_id, 'last_name', 'NeedleUnique');

$create_subscription = static function (int $customer_id, string $status, string $title): int {
    $id = wp_insert_post([
        'post_type' => 'shop_subscription',
        'post_status' => $status,
        'post_title' => $title,
    ], true);
    if (is_wp_error($id) || (int) $id <= 0) throw new RuntimeException('Could not create an isolated subscription row.');
    update_post_meta((int) $id, '_customer_user', $customer_id);
    return (int) $id;
};

$active_id = $create_subscription((int) $user_id, 'wc-active', 'Active test membership');
$pending_cancel_id = $create_subscription((int) $user_id, 'wc-pending-cancel', 'Pending cancellation test membership');
$create_subscription((int) $user_id, 'wc-cancelled', 'Cancelled test membership');

// A substantial unrelated active set catches accidental object-by-object scans.
for ($i = 0; $i < 240; $i++) {
    $create_subscription(0, 'wc-active', 'Unrelated fixture row ' . $i);
}

$method = new ReflectionMethod(Roxy_Sub_Check::class, 'search_member_subscription_ids');
$method->setAccessible(true);
$by_email = $method->invoke(null, 'needleunique@', 10);
sort($by_email);
$expected = [$active_id, $pending_cancel_id];
sort($expected);
member_search_mysql_check($by_email === $expected, 'SQL search returns active and pending-cancel matches while excluding cancelled subscriptions');

$by_full_name = $method->invoke(null, 'Roxy NeedleUnique', 10);
sort($by_full_name);
member_search_mysql_check($by_full_name === $expected, 'SQL search supports a full first-and-last name');

$limited = $method->invoke(null, 'needleunique@', 1);
member_search_mysql_check(count($limited) === 1, 'SQL enforces the caller result limit before subscription hydration');

echo "MEMBER_SEARCH_MYSQL_OK\n";
