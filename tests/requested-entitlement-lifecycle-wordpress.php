<?php
/**
 * Authenticated Requested Showings -> backing -> Woo conversion integration fixture.
 * Run only in a disposable WordPress/WooCommerce install via WP-CLI eval-file.
 * The subscriber uses a local WCS API double; only a zero-charge order is created.
 */
if (!defined('WP_CLI') || !WP_CLI) exit(1);

$root = rtrim((string) ($args[0] ?? ''), '/\\');
if ($root === '' || !is_file($root . '/roxy-suite.php')) throw new RuntimeException('Roxy Suite fixture root is required.');
if (!class_exists('WooCommerce') || !class_exists('RoxyRS\\Frontend') || !class_exists('RoxyRS\\Conversion')
    || !class_exists('RoxyRS\\Agreement') || !class_exists('RoxyST\\Capacity') || !class_exists('RoxyST\\Holds')) {
    throw new RuntimeException('This fixture requires the disposable site with WooCommerce, Requested Showings, and Show Tickets active.');
}
if (class_exists('WC_Subscription')) throw new RuntimeException('Do not run this fixture with a real subscription provider installed.');

final class RoxyRequestedLifecycleSubscriptionFixture {
    private string $status;
    private int $quantity;
    public function __construct(string $status, int $quantity) { $this->status = $status; $this->quantity = $quantity; }
    public function has_status($status): bool { return $this->status === $status; }
    public function get_items(): array { return [new RoxyRequestedLifecycleSubscriptionItemFixture($this->quantity)]; }
}
final class RoxyRequestedLifecycleSubscriptionItemFixture {
    private int $quantity;
    public function __construct(int $quantity) { $this->quantity = $quantity; }
    public function get_quantity(): int { return $this->quantity; }
}
function wcs_get_users_subscriptions($user_id): array {
    return $GLOBALS['r2_lifecycle_subscriptions'][(int) $user_id] ?? [];
}

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    fwrite(STDOUT, "PASS: {$label}\n");
};
$marker = 'R2_PRIVATE_' . bin2hex(random_bytes(8));
$email = strtolower($marker) . '@example.invalid';
$user_id = 0;
$request_id = 0;
$showing_id = 0;
$subscriber_product_id = 0;
$backing_id = 0;
$order_id = 0;
$mail_attempts = 0;
$http_attempts = 0;
$failure = null;
$endpoint_started = false;

$mail_blocker = static function ($preempt, $atts = []) use (&$mail_attempts) {
    $mail_attempts++;
    return true; // WordPress treats mail as handled; it is never sent.
};
$http_blocker = static function ($preempt, $args = [], $url = '') use (&$http_attempts) {
    $http_attempts++;
    return new WP_Error('r2_private_http_blocked', 'Outbound HTTP is disabled in this fixture.');
};
$mail_guard_registered = add_filter('pre_wp_mail', $mail_blocker, PHP_INT_MAX, 2) === true;
$http_guard_registered = add_filter('pre_http_request', $http_blocker, PHP_INT_MAX, 3) === true;
register_shutdown_function(static function () use (&$endpoint_started, &$request_id, &$showing_id, &$user_id, &$subscriber_product_id, $marker, $email): void {
    if ($endpoint_started) return;
    try {
        if ($request_id > 0) {
            $request = get_post($request_id);
            if ($request && $request->post_type === \RoxyRS\CPT::POST_TYPE && $request->post_title === $marker . ' request') wp_delete_post($request_id, true);
            elseif ($request) throw new RuntimeException('Partial request cleanup ownership mismatch.');
        }
        if ($showing_id > 0) {
            $showing = get_post($showing_id);
            if ($showing && $showing->post_type === \RoxyST\CPT::POST_TYPE && $showing->post_title === $marker . ' showing') {
                if ($subscriber_product_id > 0 && get_post_type($subscriber_product_id) === 'product') {
                    if ((int) get_post_meta($subscriber_product_id, ROXY_ST_META_SHOWING_ID, true) !== $showing_id
                        || strpos((string) get_the_title($subscriber_product_id), $marker) !== 0) throw new RuntimeException('Partial product cleanup ownership mismatch.');
                    wp_delete_post($subscriber_product_id, true);
                }
                wp_delete_post($showing_id, true);
            } elseif ($showing) throw new RuntimeException('Partial showing cleanup ownership mismatch.');
        }
        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if ($user && $user->user_email === $email) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($user_id);
            }
            elseif ($user) throw new RuntimeException('Partial user cleanup ownership mismatch.');
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'PARTIAL CLEANUP FAILURE: ' . $error->getMessage() . "\n");
        exit(1);
    }
});

try {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
        && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
        throw new RuntimeException('The fixture requires classic order storage, the currently supported storage mode.');
    }
    $entitlement_check = static function (array $subscriptions) use (&$user_id, $check): int {
        $GLOBALS['r2_lifecycle_subscriptions'][$user_id] = $subscriptions;
        return \RoxyST\Capacity::subscription_entitlement_count($user_id);
    };
    $user_id = wp_insert_user([
        'user_login' => strtolower($marker),
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => $email,
        'role' => 'subscriber',
    ]);
    if (is_wp_error($user_id) || (int) $user_id <= 0) throw new RuntimeException('Could not create private subscriber identity.');
    $user_id = (int) $user_id;
    $check($entitlement_check([new RoxyRequestedLifecycleSubscriptionFixture('active', 2)]) === 2, 'active membership grants its purchased entitlement quantity');
    $check($entitlement_check([new RoxyRequestedLifecycleSubscriptionFixture('expired', 2)]) === 0, 'expired membership grants no entitlement');
    $check($entitlement_check([]) === 0, 'nonmember grants no entitlement');
    $GLOBALS['r2_lifecycle_subscriptions'][$user_id] = [new RoxyRequestedLifecycleSubscriptionFixture('active', 2)];

    $target = (new DateTimeImmutable('now', wp_timezone()))->modify('+60 days')->setTime(19, 0);
    $target_text = $target->format('Y-m-d H:i');
    $request_result = wp_insert_post([
        'post_type' => \RoxyRS\CPT::POST_TYPE,
        'post_status' => 'draft',
        'post_title' => $marker . ' request',
        'post_content' => 'Private R2 lifecycle fixture.',
    ], true);
    if (is_wp_error($request_result) || (int) $request_result <= 0) throw new RuntimeException('Could not create private request fixture.');
    $request_id = (int) $request_result;
    $showing_result = wp_insert_post([
        'post_type' => \RoxyST\CPT::POST_TYPE,
        'post_status' => 'publish',
        'post_title' => $marker . ' showing',
    ], true);
    if (is_wp_error($showing_result) || (int) $showing_result <= 0) throw new RuntimeException('Could not create private showing fixture.');
    $showing_id = (int) $showing_result;

    update_post_meta($request_id, \RoxyRS\CPT::META_STATUS, 'active');
    update_post_meta($request_id, \RoxyRS\CPT::META_TARGET_AT, $target_text);
    update_post_meta($request_id, \RoxyRS\CPT::META_DEADLINE_AT, $target->modify('-30 days')->format('Y-m-d H:i'));
    update_post_meta($request_id, \RoxyRS\CPT::META_PRICING_PROFILE, 'movie_evening');
    update_post_meta($request_id, \RoxyRS\CPT::META_GENERAL_PRICE, '12');
    update_post_meta($request_id, \RoxyRS\CPT::META_DISCOUNT_PRICE, '8');
    update_post_meta($request_id, \RoxyRS\CPT::META_MATINEE_PRICE, '8');
    update_post_meta($request_id, \RoxyRS\CPT::META_FUNDING_UNIT_VERSION, \RoxyRS\CPT::FUNDING_UNIT_CENTS_V1);
    update_post_meta($request_id, \RoxyRS\CPT::META_FUNDING_GOAL, '999999');
    update_post_meta($request_id, \RoxyRS\CPT::META_SPONSOR_AMOUNT, '999999');
    update_post_meta($request_id, \RoxyRS\CPT::META_REQUESTER_EMAIL, $email);
    update_post_meta($request_id, \RoxyRS\CPT::META_APPROVED_SHOWING_ID, $showing_id);
    update_post_meta($showing_id, '_roxy_rs_request_id', $request_id);
    update_post_meta($showing_id, '_roxy_start', $target_text);
    update_post_meta($showing_id, '_roxy_pricing_profile', 'movie_evening');

    $product = new WC_Product_Simple();
    $product->set_name($marker . ' subscriber ticket');
    $product->set_status('private');
    $product->set_tax_status('none');
    $product->set_regular_price('0');
    $product->set_price('0');
    $subscriber_product_id = (int) $product->save();
    if ($subscriber_product_id <= 0) throw new RuntimeException('Could not create the private zero-price subscriber product.');
    update_post_meta($subscriber_product_id, ROXY_ST_META_SHOWING_ID, $showing_id);
    update_post_meta($subscriber_product_id, ROXY_ST_META_TICKET_TYPE, 'subscriber');
    update_post_meta($showing_id, '_roxy_pid_subscriber', $subscriber_product_id);

    wp_set_current_user($user_id);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'request_id' => (string) $request_id,
        'general_qty' => '0',
        'discount_qty' => '0',
        'subscriber_qty' => '1',
        '_wpnonce' => wp_create_nonce('roxy_rs_commit_backing'),
    ];
    $_REQUEST = $_POST;

    register_shutdown_function(static function () use (
        &$failure, &$request_id, &$showing_id, &$user_id, &$subscriber_product_id, &$backing_id, &$order_id,
        &$mail_attempts, &$http_attempts, $mail_guard_registered, $http_guard_registered, $marker, $email, $check, $mail_blocker, $http_blocker
    ): void {
        global $wpdb;
        try {
            if ($failure !== null) throw $failure;
            $rows = \roxy_rs_repo_list_backings_for_request($request_id, ['pending', 'threshold_met', 'approved', 'charged']);
            $owned = array_values(array_filter($rows, static fn($row) => (int) ($row['user_id'] ?? 0) === $user_id));
            if (count($owned) !== 1) throw new RuntimeException('Authenticated backing request did not persist exactly one private backing.');
            $backing = $owned[0];
            $backing_id = (int) $backing['id'];
            $check((int) $backing['subscriber_qty'] === 1 && (int) $backing['charge_total'] === 0, 'authenticated POST persisted one no-charge subscriber reservation');
            $snapshot = \RoxyRS\Agreement::validate((string) $backing['agreement_json'], $backing);
            $check(($snapshot['quantities']['subscriber_qty'] ?? 0) === 1 && ($snapshot['currency'] ?? '') === strtoupper(get_woocommerce_currency()), 'persisted backing agreement validates against its saved quantities and store currency');

            $converted = \RoxyRS\Conversion::approve_request($request_id);
            if (is_wp_error($converted)) throw new RuntimeException('Actual Requested Showings conversion failed: ' . $converted->get_error_message());
            $backing = \roxy_rs_repo_get_backing($backing_id);
            $order_id = (int) ($backing['woo_order_id'] ?? 0);
            $order = $order_id > 0 ? wc_get_order($order_id) : false;
            $check($backing && ($backing['status'] ?? '') === 'charged' && ($backing['charge_intent_id'] ?? '') === 'no-charge', 'conversion persisted the completed no-charge backing result');
            $check($order instanceof WC_Order && $order->get_customer_id() === $user_id && $order->is_paid() && (float) $order->get_total() === 0.0, 'conversion created only a zero-total Woo order for the authenticated subscriber');
            $check((int) $order->get_meta('_roxy_rs_request_id', true) === $request_id
                && (int) $order->get_meta('_roxy_rs_backing_id', true) === $backing_id, 'Woo order is durably linked to the exact private request and backing');
            $check((int) get_post_meta($order_id, '_roxy_seat_hold_managed', true) === 1
                && (int) get_post_meta($order_id, '_roxy_seat_hold_until', true) > 0, 'actual Show Tickets hold implementation persisted its seat claim before no-charge completion');
            $check(\RoxyST\Reservations::quantity_for_showing($showing_id, 0, $user_id) === 1, 'persisted Woo order is counted by the actual subscriber reservation query');
            $check((string) get_post_meta($request_id, \RoxyRS\CPT::META_STATUS, true) === 'approved'
                && get_post_status($request_id) === 'publish', 'conversion verified final request approval state');
            $check($http_guard_registered, 'outbound HTTP guard was installed (' . $http_attempts . ' blocked attempts)');
            $check($mail_guard_registered, 'outbound email guard was installed (' . $mail_attempts . ' blocked attempts)');
            fwrite(STDOUT, "OK: {$checks} private authenticated Requested Showings lifecycle checks\n");
        } catch (Throwable $error) {
            fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
            $failure = $error;
        } finally {
            remove_filter('pre_wp_mail', $mail_blocker, PHP_INT_MAX);
            remove_filter('pre_http_request', $http_blocker, PHP_INT_MAX);
            try {
                if ($request_id > 0) {
                    $request = get_post($request_id);
                    if ($request && $request->post_type === \RoxyRS\CPT::POST_TYPE && $request->post_title === $marker . ' request') {
                        $rows = \roxy_rs_repo_list_backings_for_request($request_id, []);
                        foreach ($rows as $row) {
                            if ((int) ($row['user_id'] ?? 0) !== $user_id) throw new RuntimeException('Backing cleanup ownership mismatch.');
                            $linked_order_id = (int) ($row['woo_order_id'] ?? 0);
                            if ($linked_order_id > 0) {
                                $order = wc_get_order($linked_order_id);
                                if (!$order || (int) $order->get_customer_id() !== $user_id
                                    || (int) $order->get_meta('_roxy_rs_request_id', true) !== $request_id
                                    || (int) $order->get_meta('_roxy_rs_backing_id', true) !== (int) $row['id']) {
                                    throw new RuntimeException('Woo order cleanup ownership mismatch.');
                                }
                                $ticket_ids = get_posts(['post_type'=>'roxy_ticket','post_status'=>'any','numberposts'=>-1,'fields'=>'ids','meta_query'=>[
                                    ['key'=>'_roxy_ticket_order_id','value'=>$linked_order_id],
                                    ['key'=>'_roxy_ticket_showing_id','value'=>$showing_id],
                                ]]);
                                foreach ($ticket_ids as $ticket_id) wp_delete_post((int) $ticket_id, true);
                                if (!$order->delete(true)) throw new RuntimeException('Owned private Woo order cleanup failed.');
                                if ($order_id === $linked_order_id) $order_id = 0;
                            }
                            $deleted = $wpdb->delete(\roxy_rs_table_backings(), ['id'=>(int)$row['id'],'request_id'=>$request_id,'user_id'=>$user_id], ['%d','%d','%d']);
                            if ($deleted !== 1) throw new RuntimeException('Owned private backing cleanup failed.');
                        }
                        wp_delete_post($request_id, true);
                    } elseif ($request) {
                        throw new RuntimeException('Request cleanup ownership mismatch.');
                    }
                }
                if ($showing_id > 0) {
                    $showing = get_post($showing_id);
                    if ($showing && $showing->post_type === \RoxyST\CPT::POST_TYPE
                        && $showing->post_title === $marker . ' showing'
                        && (int) get_post_meta($showing_id, '_roxy_rs_request_id', true) === $request_id) {
                        foreach (['_roxy_pid_adult','_roxy_pid_discount','_roxy_pid_matinee','_roxy_pid_live1','_roxy_pid_live2','_roxy_pid_subscriber'] as $key) {
                            $product_id = (int) get_post_meta($showing_id, $key, true);
                            if ($product_id <= 0) continue;
                            if (get_post_type($product_id) !== 'product'
                                || (int) get_post_meta($product_id, ROXY_ST_META_SHOWING_ID, true) !== $showing_id
                                || !in_array((string) get_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, true), ['adult','discount','matinee','live1','live2','subscriber'], true)) {
                                throw new RuntimeException('Ticket product cleanup ownership mismatch.');
                            }
                            wp_delete_post($product_id, true);
                        }
                        wp_delete_post($showing_id, true);
                    } elseif ($showing) {
                        throw new RuntimeException('Showing cleanup ownership mismatch.');
                    }
                }
                if ($subscriber_product_id > 0 && get_post_type($subscriber_product_id) === 'product') {
                    if ((int) get_post_meta($subscriber_product_id, ROXY_ST_META_SHOWING_ID, true) !== $showing_id
                        || strpos((string) get_the_title($subscriber_product_id), $marker) !== 0) throw new RuntimeException('Subscriber product cleanup ownership mismatch.');
                    wp_delete_post($subscriber_product_id, true);
                }
                if ($user_id > 0) {
                    $user = get_userdata($user_id);
                    if ($user && $user->user_email === $email) {
                        require_once ABSPATH . 'wp-admin/includes/user.php';
                        wp_delete_user($user_id);
                    }
                    elseif ($user) throw new RuntimeException('User cleanup ownership mismatch.');
                }
            } catch (Throwable $cleanup_error) {
                fwrite(STDERR, 'CLEANUP FAILURE: ' . $cleanup_error->getMessage() . "\n");
                $failure = $cleanup_error;
            }
            if ($failure !== null) exit(1);
        }
    });

    $endpoint_started = true;
    \RoxyRS\Frontend::handle_commit_backing();
    throw new RuntimeException('Backing handler unexpectedly returned without its redirect exit.');
} catch (Throwable $error) {
    $failure = $error;
}
