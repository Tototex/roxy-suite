<?php
/** Private WP-CLI fixture: Store API capacity/allowance checks; synthetic Woo orders only, no payments. */
if (!defined('ABSPATH') || !class_exists('WC_Cart')) throw new RuntimeException('Loaded Woo required.');
global $wpdb;
$ids = [];
$orders = [];
$users = [];
$removed_actions = [];
$mail_interceptor = static fn() => true;
$saved_user_id = get_current_user_id();
$saved = [WC()->cart, WC()->session, WC()->customer];
$checks = 0;
$GLOBALS['roxy_store_api_fixture_subscriptions'] = [];
if (!function_exists('wcs_get_users_subscriptions')) {
    // The registered disposable workflow installs WooCommerce, not WCS. This
    // test-local adapter supplies only an in-memory active entitlement.
    function wcs_get_users_subscriptions($user_id) {
        return $GLOBALS['roxy_store_api_fixture_subscriptions'][(int) $user_id] ?? [];
    }
}
$assert = static function ($ok, $label) use (&$checks) {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
    $checks++;
};
try {
    foreach ([RoxyST\CPT::POST_TYPE, 'product'] as $type) {
        if (!$wpdb->insert($wpdb->posts, [
            'post_title' => 'PRIVATE Store API capacity fixture',
            'post_type' => $type,
            'post_status' => 'draft',
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', true),
        ])) throw new RuntimeException('Private fixture post could not be created.');
        $ids[] = (int) $wpdb->insert_id;
    }
    [$showing_id, $product_id] = $ids;
    foreach ([
        [$showing_id, '_roxy_capacity', 3],
        [$showing_id, '_roxy_start', current_datetime()->modify('+1 day')->format('Y-m-d\\TH:i')],
        [$product_id, ROXY_ST_META_SHOWING_ID, $showing_id],
        [$product_id, ROXY_ST_META_TICKET_TYPE, 'adult'],
    ] as [$id, $key, $value]) {
        if (!add_post_meta($id, $key, $value)) throw new RuntimeException('Private fixture metadata could not be created.');
    }
    // Keep this test focused on Capacity: the independent publication gate is covered separately.
    if (class_exists(RoxyST\Eligibility::class)) {
        remove_filter('woocommerce_is_purchasable', [RoxyST\Eligibility::class, 'purchasable'], 99);
        remove_filter('woocommerce_add_to_cart_validation', [RoxyST\Eligibility::class, 'validate_add'], 5);
        remove_action('woocommerce_check_cart_items', [RoxyST\Eligibility::class, 'validate_cart'], 9990);
        remove_action('woocommerce_checkout_process', [RoxyST\Eligibility::class, 'validate_cart'], 9990);
    }
    RoxyST\Capacity::init();
    WC()->session = new WC_Session_Handler(); // Never initialize or persist a customer session.
    WC()->customer = new WC_Customer(0, false);
    WC()->cart = new WC_Cart();

    $product = new WC_Product_Simple();
    $product->set_id($product_id);
    $product->set_name('Private capacity fixture ticket');
    $product->set_status('publish');
    $product->set_price(12);
    $product->set_virtual(true);
    $product->set_tax_status('none');
    $controller = new Automattic\WooCommerce\StoreApi\Utilities\CartController();
    $request = new WP_REST_Request('POST', '/wc/store/v1/cart/add-item');
    $request->set_param('quantity', 4);
    wc_clear_notices();
    $blocked = false;
    $api_error = null;
    try { $controller->validate_add_to_cart($product, $request); }
    catch (Automattic\WooCommerce\StoreApi\Exceptions\RouteException $error) { $blocked = true; $api_error = $error; }
    $assert($blocked, 'Store API rejects an add-to-cart quantity above available capacity');
    if ($api_error instanceof Automattic\WooCommerce\StoreApi\Exceptions\RouteException) {
        echo 'Store API capacity error: ' . $api_error->error_code . ' — ' . wp_strip_all_tags($api_error->getMessage()) . "\n";
    }
    $assert($api_error instanceof Automattic\WooCommerce\StoreApi\Exceptions\RouteException
        && $api_error->error_code === 'woocommerce_rest_add_to_cart_error'
        && str_contains(wp_strip_all_tags($api_error->getMessage()), 'not have enough seats remaining'),
        'Store API response carries the expected seat-limit error');

    $request->set_param('quantity', 2);
    wc_clear_notices();
    $controller->validate_add_to_cart($product, $request);
    $assert(wc_notice_count('error') === 0, 'Store API accepts a quantity within available capacity');

    // Simulate a cart created while capacity was higher; changing the limit must block checkout.
    WC()->cart->cart_contents = ['private-capacity-fixture' => [
        'product_id' => $product_id, 'variation_id' => 0, 'variation' => [], 'quantity' => 2,
        'data' => $product, 'data_hash' => wc_get_cart_item_data_hash($product),
    ]];
    update_post_meta($showing_id, '_roxy_capacity', 1);
    wc_clear_notices();
    $blocked = false;
    try { $controller->validate_cart(); }
    catch (Automattic\WooCommerce\StoreApi\Exceptions\InvalidCartException $error) { $blocked = true; }
    $assert($blocked, 'Store API rejects checkout when a stale cart now exceeds capacity');
    $assert(wc_notice_count('error') === 0, 'Store API translates the capacity notice into its cart error without leaking notices');

    update_post_meta($showing_id, '_roxy_capacity', 3);
    wc_clear_notices();
    $controller->validate_cart();
    $assert(wc_notice_count('error') === 0, 'Store API allows checkout validation when capacity is available');

    // A separate DB connection attempts a production capacity write while
    // checkout owns the same showing-scoped lease. This exercises actual
    // MySQL GET_LOCK contention, not just a mocked unavailable-lock result.
    $primary_db = $wpdb;
    $contender_db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $lease_owner = new RoxyST\Issuance([], 'walkup:' . $showing_id);
    $lease_owner->acquire_lease();
    try {
        $owner_connection = (int) $primary_db->get_var('SELECT CONNECTION_ID()');
        $wpdb = $contender_db;
        $contender_connection = (int) $contender_db->get_var('SELECT CONNECTION_ID()');
        $assert($owner_connection > 0 && $contender_connection > 0 && $owner_connection !== $contender_connection,
            'capacity contention uses two independent MySQL connections');
        $capacity_writer = new ReflectionMethod(RoxyST\CPT::class, 'save_capacity_with_seat_lock');
        $capacity_writer->setAccessible(true);
        $contended_save = $capacity_writer->invoke(null, $showing_id, 4);
        $assert($contended_save === false, 'a competing capacity change fails while another connection holds the showing seat lease');
        $stored_capacity = (int) $contender_db->get_var($contender_db->prepare(
            "SELECT meta_value FROM {$contender_db->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 1",
            $showing_id, '_roxy_capacity'
        ));
        $assert($stored_capacity === 3, 'contended capacity edit leaves the persisted limit unchanged');
    } finally {
        $wpdb = $primary_db;
        $lease_owner->release_lease();
        $contender_db->close();
    }

    // The cart validator can cache zero paid subscriber usage. A purchase made
    // after that read must still be seen by the Store API's durable hold claim.
    if (!function_exists('wcs_get_users_subscriptions') || !class_exists(RoxyST\Holds::class)) {
        throw new RuntimeException('Subscriber entitlement API and ticket holds are required for the Store API regression.');
    }
    update_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, 'subscriber');
    $user_id = wp_insert_user([
        'user_login' => 'roxy-store-api-subscriber-' . bin2hex(random_bytes(5)),
        'user_pass' => wp_generate_password(32),
        'user_email' => 'fixture-' . bin2hex(random_bytes(5)) . '@example.test',
        'role' => 'customer',
    ]);
    if (is_wp_error($user_id)) throw new RuntimeException('Private subscriber user could not be created.');
    $users[] = (int) $user_id;
    wp_set_current_user((int) $user_id);
    $entitlement_item = new class { public function get_quantity() { return 1; } };
    $entitlement = new class($entitlement_item) {
        private $item;
        public function __construct($item) { $this->item = $item; }
        public function has_status($status) { return $status === 'active'; }
        public function get_items() { return [$this->item]; }
    };
    $GLOBALS['roxy_store_api_fixture_subscriptions'][(int) $user_id] = [$entitlement];
    $assert(RoxyST\Capacity::subscription_entitlement_count((int) $user_id) === 1,
        'in-memory fixture grants exactly one active subscriber admission');

    WC()->customer = new WC_Customer((int) $user_id, false);
    WC()->cart = new WC_Cart();
    $request->set_param('quantity', 1);
    wc_clear_notices();
    $controller->validate_add_to_cart($product, $request);
    $assert(wc_notice_count('error') === 0, 'Store API accepts one subscriber seat within the fresh paid-usage allowance');

    // Create a private, zero-value synthetic paid order after the zero-usage
    // read above. No gateway or payment is invoked; mail is intercepted.
    $product->set_price(0); // Subscriber tickets are complimentary.
    add_filter('pre_wp_mail', $mail_interceptor, PHP_INT_MAX);
    foreach ([
        ['woocommerce_order_status_changed', [RoxyST\Tickets::class, 'on_order_changed'], 30],
        ['woocommerce_order_status_changed', [RoxyST\Sales::class, 'on_order_changed'], 20],
    ] as [$hook, $callback, $priority]) {
        $registered_priority = has_action($hook, $callback);
        if ($registered_priority !== false) {
            remove_action($hook, $callback, $registered_priority);
            $removed_actions[] = [$hook, $callback, $registered_priority];
        }
    }
    $paid_order = wc_create_order(['customer_id' => (int) $user_id, 'customer_note' => 'PRIVATE synthetic subscriber-usage fixture']);
    if (is_wp_error($paid_order)) throw new RuntimeException('Private synthetic usage order could not be created.');
    $orders[] = $paid_order;
    $paid_order->add_product($product, 1);
    $paid_order->calculate_totals();
    $paid_order->save();
    $paid_order->update_status('processing');
    // Match the marker written by the ticket order projection. Without it,
    // wc_get_orders() would not find this synthetic order in the paid-usage
    // query even if the request-local cache were cleared.
    $usage_marker = '_roxy_contains_showing_' . $showing_id;
    update_post_meta($paid_order->get_id(), $usage_marker, '1');
    $fresh_paid_order_ids = wc_get_orders([
        'customer_id' => (int) $user_id,
        'status' => ['wc-processing', 'wc-completed'],
        'limit' => -1,
        'return' => 'ids',
        'type' => 'shop_order',
        'meta_query' => [['key' => $usage_marker, 'value' => '1']],
    ]);
    $assert(is_array($fresh_paid_order_ids) && in_array((int) $paid_order->get_id(), array_map('intval', $fresh_paid_order_ids), true),
        'synthetic paid order carries the production showing marker and is visible to a fresh usage query');
    $assert(RoxyST\Capacity::purchased_subscriber_qty_for_showing_user($showing_id, (int) $user_id) === 0,
        'request-local paid-usage cache remains stale after the synthetic paid order is added');
    $assert(RoxyST\Reservations::quantity_for_showing($showing_id, 0, (int) $user_id) === 1,
        'private paid subscriber order consumes the account allowance in fresh reservation data');

    WC()->cart->cart_contents = ['private-subscriber-fixture' => [
        'product_id' => $product_id, 'variation_id' => 0, 'variation' => [], 'quantity' => 1,
        'data' => $product, 'data_hash' => wc_get_cart_item_data_hash($product),
    ]];
    wc_clear_notices();
    $controller->validate_cart();
    $assert(wc_notice_count('error') === 0, 'fixture reproduces a stale request-local cart allowance after new paid usage appears');

    $candidate_order = wc_create_order(['customer_id' => (int) $user_id, 'customer_note' => 'PRIVATE Store API hold claim fixture']);
    if (is_wp_error($candidate_order)) throw new RuntimeException('Private Store API candidate order could not be created.');
    $orders[] = $candidate_order;
    $candidate_order->add_product($product, 1);
    $candidate_order->calculate_totals();
    $candidate_order->save();
    $blocked = false;
    try { RoxyST\Holds::claim_store_api($candidate_order); }
    catch (Automattic\WooCommerce\StoreApi\Exceptions\RouteException $error) {
        $blocked = $error->error_code === 'roxy_seat_unavailable';
    }
    $assert($blocked, 'Store API durable hold claim rejects paid subscriber usage added after stale cart validation');
    $assert(!(int) get_post_meta($candidate_order->get_id(), '_roxy_seat_hold_managed', true)
        && !(int) get_post_meta($candidate_order->get_id(), '_roxy_seat_hold_until', true),
        'rejected Store API subscriber claim creates no durable seat hold');

    // Positive control: the same order must be claimable when one more active
    // subscriber admission is available, proving the prior rejection was the
    // fresh-usage limit rather than an unrelated Store API hold error.
    $GLOBALS['roxy_store_api_fixture_subscriptions'][(int) $user_id][] = $entitlement;
    $assert(RoxyST\Capacity::subscription_entitlement_count((int) $user_id) === 2,
        'positive control increases the in-memory subscriber entitlement to two');
    $accepted = false;
    try {
        RoxyST\Holds::claim_store_api($candidate_order);
        $accepted = true;
    } catch (Automattic\WooCommerce\StoreApi\Exceptions\RouteException $error) {}
    $assert($accepted && (int) get_post_meta($candidate_order->get_id(), '_roxy_seat_hold_managed', true) === 1
        && (int) get_post_meta($candidate_order->get_id(), '_roxy_seat_hold_until', true) > time(),
        'same Store API order receives a durable hold when entitlement is sufficient');

    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN (%d,%d) AND post_status='draft'", $showing_id, $product_id)) === 2, 'fixture records remain private drafts');
    echo "Passed $checks installed Store API capacity checks. Only private synthetic Woo records were created; no checkout or payment was made.\n";
} finally {
    $cleanup_errors = [];
    foreach ($orders as $order) {
        $order_id = (int) $order->get_id();
        try {
            $order->delete(true);
            if (wc_get_order($order_id)) $cleanup_errors[] = 'Private Woo order cleanup failed.';
        } catch (Throwable $error) {
            $cleanup_errors[] = 'Private Woo order cleanup failed: ' . $error->getMessage();
        }
    }
    unset($GLOBALS['roxy_store_api_fixture_subscriptions']);
    foreach ($ids as $id) {
        try {
            $wpdb->delete($wpdb->postmeta, ['post_id' => $id]);
            $wpdb->delete($wpdb->posts, ['ID' => $id]);
            clean_post_cache($id);
            if (get_post($id) !== null) $cleanup_errors[] = 'Private fixture post cleanup failed.';
        } catch (Throwable $error) {
            $cleanup_errors[] = 'Private fixture post cleanup failed: ' . $error->getMessage();
        }
    }
    if ($users) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($users as $user_id) {
            try {
                wp_delete_user($user_id);
                if (get_userdata($user_id)) $cleanup_errors[] = 'Private fixture user cleanup failed.';
            } catch (Throwable $error) {
                $cleanup_errors[] = 'Private fixture user cleanup failed: ' . $error->getMessage();
            }
        }
    }
    remove_filter('pre_wp_mail', $mail_interceptor, PHP_INT_MAX);
    foreach ($removed_actions as [$hook, $callback, $priority]) add_action($hook, $callback, $priority, 1);
    wp_set_current_user($saved_user_id);
    [WC()->cart, WC()->session, WC()->customer] = $saved;
    if ($cleanup_errors) throw new RuntimeException(implode(' ', array_unique($cleanup_errors)));
}
