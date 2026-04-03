<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_register_my_account_endpoints() {
    add_action('init', function () {
        add_rewrite_endpoint('roxy-bookings', EP_ROOT | EP_PAGES);
    });

    add_filter('woocommerce_account_menu_items', function ($items) {
        $logout = $items['customer-logout'] ?? null;
        if ($logout) unset($items['customer-logout']);
        $items['roxy-bookings'] = __('My Bookings', 'roxy-event-booking');
        if ($logout) $items['customer-logout'] = $logout;
        return $items;
    });

    add_action('woocommerce_account_roxy-bookings_endpoint', 'roxy_eb_render_my_bookings');

    add_action('wp_loaded', function () {
        // no-op; rewrite flush should be manual if needed
    });

    add_action('template_redirect', function () {
        if (!is_user_logged_in()) return;
        if (!isset($_GET['roxy_eb_cancel'])) return;

        $booking_id = intval($_GET['roxy_eb_cancel']);
        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'roxy_eb_cancel_' . $booking_id)) {
            wc_add_notice('Security check failed. Please try again.', 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('roxy-bookings'));
            exit;
        }

        $booking = roxy_eb_repo_get_booking($booking_id);
        if (!$booking) {
            wc_add_notice('Booking not found.', 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('roxy-bookings'));
            exit;
        }

        $user = wp_get_current_user();
        $email = $user ? $user->user_email : '';
        $owns = (intval($booking['wp_user_id']) === get_current_user_id()) || (strcasecmp($booking['customer_email'], $email) === 0);
        if (!$owns) {
            wc_add_notice('You do not have permission to cancel this booking.', 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('roxy-bookings'));
            exit;
        }

        $result = roxy_eb_cancel_booking($booking_id, 'customer');
        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
        } else {
            wc_add_notice('Booking cancelled.', 'success');
        }

        wp_safe_redirect(wc_get_account_endpoint_url('roxy-bookings'));
        exit;
    });
}

function roxy_eb_render_my_bookings() {
    if (!is_user_logged_in()) {
        echo '<p>Please log in to view your bookings.</p>';
        return;
    }
    $user = wp_get_current_user();
    $rows = roxy_eb_repo_list_bookings_for_user(get_current_user_id(), $user->user_email);

    echo '<h3>My Bookings</h3>';
    echo '<p>You can cancel for free up to 7 days before your event. Within 7 days, please contact us to cancel.</p>';

    if (empty($rows)) {
        echo '<p>No bookings found.</p>';
        return;
    }

    echo '<table class="shop_table shop_table_responsive">';
    echo '<thead><tr><th>Date</th><th>Doors</th><th>Duration</th><th>Guests</th><th>Notes</th><th>Status</th><th>Actions</th></tr></thead><tbody>';

    $settings = roxy_eb_get_settings();
    $freeDays = intval($settings['cancel_free_days'] ?? 7);

    foreach ($rows as $b) {
        $doors = roxy_eb_mysql_to_dt($b['doors_open_at']);
        $dur = 2 + intval($b['extra_hours']);
        $status = esc_html(ucfirst($b['status']));
        $notes = '';
        if (!empty($b['notes_admin'])) {
            $notes = wp_strip_all_tags((string)$b['notes_admin']);
            if (strlen($notes) > 80) $notes = substr($notes, 0, 77) . '...';
        }
        $cancelable_statuses = ['confirmed', 'pending_invoice'];
        $can_cancel = in_array($b['status'], $cancelable_statuses, true) && (new DateTimeImmutable('now', wp_timezone()) < $doors->modify('-' . $freeDays . ' days'));

        echo '<tr>';
        echo '<td data-title="Date">' . esc_html($doors->format('D, M j, Y')) . '</td>';
        echo '<td data-title="Doors">' . esc_html($doors->format('g:i A')) . '</td>';
        echo '<td data-title="Duration">' . esc_html($dur . 'h') . '</td>';
        echo '<td data-title="Guests">' . esc_html(intval($b['guest_count'])) . '</td>';
        echo '<td data-title="Notes">' . esc_html($notes) . '</td>';
        echo '<td data-title="Status">' . $status . '</td>';
        echo '<td data-title="Actions">';
        if ($can_cancel) {
            $url = add_query_arg([
                'roxy_eb_cancel' => intval($b['id']),
                '_wpnonce' => wp_create_nonce('roxy_eb_cancel_' . intval($b['id'])),
            ], wc_get_account_endpoint_url('roxy-bookings'));
            echo '<a class="button" href="' . esc_url($url) . '" onclick="return confirm(\'Cancel this booking?\');">Cancel</a>';
        } elseif (in_array($b['status'], ['confirmed', 'pending_invoice'], true)) {
            echo '<span>Contact us to cancel</span>';
        } else {
            echo '—';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

function roxy_eb_attempt_gateway_refund($order, $booking_id) {
    if (!$order || !is_a($order, 'WC_Order')) {
        return new WP_Error('invalid_order', 'Invalid WooCommerce order.');
    }

    $order_id = intval($order->get_id());
    $refundable = floatval($order->get_total()) - floatval($order->get_total_refunded());
    $refundable = round(max(0.0, $refundable), wc_get_price_decimals());

    if ($refundable <= 0) {
        return [
            'refunded' => false,
            'amount'   => 0.0,
            'refund'   => null,
        ];
    }

    $refund = wc_create_refund([
        'amount'         => $refundable,
        'reason'         => 'Roxy booking cancelled',
        'order_id'       => $order_id,
        'refund_payment' => true,
        'restock_items'  => false,
    ]);

    if (is_wp_error($refund)) {
        return $refund;
    }

    return [
        'refunded' => true,
        'amount'   => $refundable,
        'refund'   => $refund,
    ];
}

function roxy_eb_cancel_booking($booking_id, $by = 'customer') {
    static $in_progress = [];

    $booking_id = intval($booking_id);
    if ($booking_id <= 0) return new WP_Error('not_found', 'Booking not found.');
    if (!empty($in_progress[$booking_id])) return true;

    $booking = roxy_eb_repo_get_booking($booking_id);
    if (!$booking) return new WP_Error('not_found', 'Booking not found.');

    if (!in_array($booking['status'], ['confirmed', 'pending_invoice'], true)) {
        return new WP_Error('invalid_status', 'This booking cannot be cancelled.');
    }

    $settings = roxy_eb_get_settings();
    $freeDays = intval($settings['cancel_free_days'] ?? 7);

    $doors = roxy_eb_mysql_to_dt($booking['doors_open_at']);
    $now = new DateTimeImmutable('now', wp_timezone());
    $is_free_window = $now < $doors->modify('-' . $freeDays . ' days');

    if ($by === 'customer' && !$is_free_window) {
        return new WP_Error('too_late', 'This booking is within ' . $freeDays . ' days. Please contact us to cancel.');
    }

    $in_progress[$booking_id] = true;
    try {
        $order_id = intval($booking['woo_order_id']);
        if ($order_id > 0 && class_exists('WC_Order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                try {
                    $refund_result = roxy_eb_attempt_gateway_refund($order, $booking_id);
                    if (is_wp_error($refund_result)) {
                        $message = $refund_result->get_error_message();
                        $order->add_order_note('Roxy booking cancellation refund failed (booking #' . $booking_id . '): ' . $message);
                        roxy_eb_email_internal_booking_failed($order, 'Refund failed: ' . $message);
                        return new WP_Error('refund_failed', 'Automatic refund failed: ' . $message . ' Please contact us.');
                    }

                    if (!empty($refund_result['refunded'])) {
                        $order->add_order_note('Roxy booking cancelled and gateway-refunded $' . number_format((float) $refund_result['amount'], 2) . ' (booking #' . $booking_id . ').');
                    } else {
                        $order->add_order_note('Roxy booking cancelled (booking #' . $booking_id . '). No refundable balance remained on the order.');
                    }
                } catch (Throwable $e) {
                    $message = $e->getMessage();
                    $order->add_order_note('Roxy booking cancellation refund exception (booking #' . $booking_id . '): ' . $message);
                    roxy_eb_email_internal_booking_failed($order, 'Refund failed: ' . $message);
                    return new WP_Error('refund_failed', 'Automatic refund failed: ' . $message . ' Please contact us.');
                }
            }
        }

        roxy_eb_repo_update_booking($booking_id, [
            'status' => 'cancelled',
            'invoice_status' => ($booking['payment_method'] ?? '') === 'invoice' ? 'void' : ($booking['invoice_status'] ?? 'not_needed'),
        ]);

        if (function_exists('roxy_eb_clear_pizza_reminders')) {
            roxy_eb_clear_pizza_reminders($booking_id);
        }
        if (function_exists('roxy_eb_sling_enqueue_cancel')) {
            roxy_eb_sling_enqueue_cancel($booking_id);
        }

        return true;
    } finally {
        unset($in_progress[$booking_id]);
    }
}
