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

    add_action('wp_enqueue_scripts', function () {
        if (!function_exists('is_account_page') || !is_account_page()) return;
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('roxy-bookings')) return;
        wp_enqueue_style('roxy-eb', ROXY_EB_ASSETS_URL . 'roxy-eb.css', [], ROXY_EB_VERSION);
    });

    add_action('wp_loaded', function () {
        // no-op; rewrite flush should be manual if needed
    });

    add_action('template_redirect', function () {
        if (!is_user_logged_in()) return;
        if (!isset($_GET['roxy_eb_cancel'])) return;

        $booking_id = intval($_GET['roxy_eb_cancel']);
        $nonce = $_GET['_wpnonce'] ?? '';
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

        if (!roxy_eb_booking_owned_by_current_user($booking)) {
            wc_add_notice('You do not have permission to cancel this booking.', 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('roxy-bookings'));
            exit;
        }

        $result = roxy_eb_cancel_booking($booking_id, 'customer');
        if (is_wp_error($result)) wc_add_notice($result->get_error_message(), 'error');
        else wc_add_notice('Booking cancelled.', 'success');

        wp_safe_redirect(wc_get_account_endpoint_url('roxy-bookings'));
        exit;
    });

    add_action('template_redirect', function () {
        if (!is_user_logged_in()) return;
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (empty($_POST['roxy_eb_save_order'])) return;

        $booking_id = intval($_POST['booking_id'] ?? 0);
        $redirect_url = wc_get_account_endpoint_url('roxy-bookings');
        if ($booking_id <= 0) {
            wc_add_notice('Booking not found.', 'error');
            wp_safe_redirect($redirect_url);
            exit;
        }

        check_admin_referer('roxy_eb_edit_order_' . $booking_id);

        $booking = roxy_eb_repo_get_booking($booking_id);
        if (!$booking) {
            wc_add_notice('Booking not found.', 'error');
            wp_safe_redirect($redirect_url);
            exit;
        }

        if (!roxy_eb_booking_owned_by_current_user($booking)) {
            wc_add_notice('You do not have permission to edit this booking.', 'error');
            wp_safe_redirect($redirect_url);
            exit;
        }

        if (!roxy_eb_can_customer_edit_order($booking)) {
            wc_add_notice('Orders can only be edited more than ' . roxy_eb_order_edit_cutoff_days() . ' days before the event.', 'error');
            wp_safe_redirect($redirect_url);
            exit;
        }

        $change = roxy_eb_build_order_change_from_request($booking, $_POST);
        if (is_wp_error($change)) {
            wc_add_notice($change->get_error_message(), 'error');
            wp_safe_redirect(add_query_arg('roxy_eb_edit_order', $booking_id, $redirect_url));
            exit;
        }

        if (($booking['payment_method'] ?? 'pay_now') === 'invoice' || intval($change['delta_total']) <= 0) {
            $booking_after = roxy_eb_apply_booking_order_change($booking_id, $change, 'customer_order_update');
            if (is_wp_error($booking_after)) {
                wc_add_notice($booking_after->get_error_message(), 'error');
                wp_safe_redirect(add_query_arg('roxy_eb_edit_order', $booking_id, $redirect_url));
                exit;
            }

            $context = (($booking['payment_method'] ?? 'pay_now') === 'invoice') ? 'customer_invoice_update' : 'customer_card_no_charge';
            roxy_eb_email_internal_booking_order_changed($booking, $booking_after, $context);
            roxy_eb_email_customer_booking_updated($booking, $booking_after);
            wc_add_notice((($booking['payment_method'] ?? 'pay_now') === 'invoice') ? 'Order updated. We notified the theater team.' : 'Order updated. No additional payment was required.', 'success');
            wp_safe_redirect($redirect_url);
            exit;
        }

        $checkout_url = roxy_eb_start_booking_adjustment_checkout($booking, $change);
        if (is_wp_error($checkout_url)) {
            wc_add_notice($checkout_url->get_error_message(), 'error');
            wp_safe_redirect(add_query_arg('roxy_eb_edit_order', $booking_id, $redirect_url));
            exit;
        }

        wc_add_notice('Finish checkout to confirm your booking update.', 'notice');
        wp_safe_redirect($checkout_url);
        exit;
    });
}

function roxy_eb_booking_owned_by_current_user($booking): bool {
    if (!is_array($booking) || !is_user_logged_in()) return false;
    $user = wp_get_current_user();
    $email = $user ? $user->user_email : '';
    return (intval($booking['wp_user_id'] ?? 0) === get_current_user_id()) || (strcasecmp((string) ($booking['customer_email'] ?? ''), (string) $email) === 0);
}

function roxy_eb_render_my_booking_order_modal($booking, bool $open = false) {
    $booking_id = intval($booking['id']);
    $doors = roxy_eb_mysql_to_dt($booking['doors_open_at']);
    $duration = 2 + intval($booking['extra_hours']);
    ?>
    <div
        id="roxy-eb-order-modal-<?php echo esc_attr($booking_id); ?>"
        class="roxy-eb-modal roxy-eb-order-modal"
        aria-hidden="<?php echo $open ? 'false' : 'true'; ?>"
        data-roxy-eb-order-modal
        style="display: <?php echo $open ? 'flex' : 'none'; ?>;"
    >
        <div class="roxy-eb-modal__backdrop" data-roxy-eb-close-modal></div>
        <div class="roxy-eb-modal__dialog roxy-eb-order-modal__dialog">
            <div class="roxy-eb-modal__top">
                <div>
                    <h4 class="roxy-eb-order-modal__title">Edit Order</h4>
                    <p class="roxy-eb-order-modal__subtitle"><?php echo esc_html($doors->format('l, F j, Y g:i A')); ?></p>
                </div>
            </div>

            <form method="post" class="roxy-eb-order-modal__form">
                <?php wp_nonce_field('roxy_eb_edit_order_' . $booking_id); ?>
                <input type="hidden" name="booking_id" value="<?php echo esc_attr($booking_id); ?>">
                <input type="hidden" name="roxy_eb_save_order" value="1">

                <div class="roxy-eb-grid">
                    <label>
                        <span>Duration (hours)</span>
                        <select name="duration_hours">
                            <?php for ($hours = 2; $hours <= 8; $hours++): ?>
                                <option value="<?php echo esc_attr($hours); ?>" <?php selected($duration, $hours); ?>><?php echo esc_html($hours); ?> hours</option>
                            <?php endfor; ?>
                        </select>
                        <small class="roxy-eb-help">Extra hours will only save if the calendar stays available.</small>
                    </label>

                    <label>
                        <span>Pizza quantity</span>
                        <input type="number" min="0" name="pizza_quantity" value="<?php echo esc_attr(intval($booking['pizza_quantity'] ?? 0)); ?>">
                        <small class="roxy-eb-help">Use 0 for no pizza.</small>
                    </label>

                    <label>
                        <span>Bulk popcorn qty</span>
                        <input type="number" min="0" max="250" step="25" name="bulk_popcorn_qty" value="<?php echo esc_attr(intval($booking['bulk_popcorn_qty'] ?? 0)); ?>">
                        <small class="roxy-eb-help">Use 0 for none, or 25-250.</small>
                    </label>

                    <label>
                        <span>Bulk soda qty</span>
                        <input type="number" min="0" max="250" step="25" name="bulk_soda_qty" value="<?php echo esc_attr(intval($booking['bulk_soda_qty'] ?? 0)); ?>">
                        <small class="roxy-eb-help">Use 0 for none, or 25-250.</small>
                    </label>

                    <label class="roxy-eb-span-2">
                        <span>Pizza order details</span>
                        <textarea name="pizza_order_details" rows="4"><?php echo esc_textarea((string) ($booking['pizza_order_details'] ?? '')); ?></textarea>
                    </label>

                    <label class="roxy-eb-span-2">
                        <span>Order notes</span>
                        <textarea name="notes_admin" rows="4"><?php echo esc_textarea((string) ($booking['notes_admin'] ?? '')); ?></textarea>
                    </label>
                </div>

                <div class="roxy-eb-alert roxy-eb-alert--info roxy-eb-order-modal__alert">
                    If your original booking was paid by card, any added charges will go through checkout now. Invoice bookings update immediately and notify the theater team.
                </div>

                <div class="roxy-eb-actions">
                    <button type="submit" class="roxy-eb-btn roxy-eb-btn--primary">Save Order Changes</button>
                    <button type="button" class="roxy-eb-btn roxy-eb-btn--ghost" data-roxy-eb-close-modal>Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php
}

function roxy_eb_render_my_bookings() {
    if (!is_user_logged_in()) {
        echo '<p>Please log in to view your bookings.</p>';
        return;
    }

    $user = wp_get_current_user();
    $rows = roxy_eb_repo_list_bookings_for_user(get_current_user_id(), $user->user_email);

    echo '<div class="roxy-eb-wrap roxy-eb-account-bookings">';
    echo '<style>
        .roxy-eb-account-bookings { color:#fff; }
        .roxy-eb-account-bookings p { color:#fff; }
        .roxy-eb-account-bookings .shop_table { margin-top:18px; }
        .roxy-eb-account-bookings .shop_table th, .roxy-eb-account-bookings .shop_table td { vertical-align:top; }
        .roxy-eb-account-bookings .roxy-eb-order-edit-trigger.button {
            box-sizing:border-box !important;
            min-width:118px !important;
            height:38px !important;
            padding:0 14px !important;
            line-height:36px !important;
            transform:none !important;
            transition:background-color .15s ease, color .15s ease, border-color .15s ease !important;
        }
        .roxy-eb-account-bookings .roxy-eb-order-edit-trigger.button:hover,
        .roxy-eb-account-bookings .roxy-eb-order-edit-trigger.button:focus {
            transform:none !important;
            min-width:118px !important;
            height:38px !important;
            padding:0 14px !important;
        }
        .roxy-eb-account-bookings .roxy-eb-order-modal {
            position:fixed !important;
            inset:0 !important;
            z-index:999999 !important;
            align-items:center !important;
            justify-content:center !important;
            padding:24px !important;
        }
        .roxy-eb-account-bookings .roxy-eb-order-modal[aria-hidden="false"] {
            display:flex !important;
        }
        .roxy-eb-account-bookings .roxy-eb-order-modal__dialog {
            color:#111 !important;
            font-family: Arial, Helvetica, sans-serif !important;
            width:min(760px, calc(100vw - 32px)) !important;
            max-height:calc(100vh - 48px) !important;
            margin:0 !important;
            overflow:auto !important;
            background:#fff !important;
            border-radius:18px !important;
            padding:26px 28px !important;
            box-shadow:0 24px 80px rgba(0,0,0,.45) !important;
        }
        .roxy-eb-account-bookings .roxy-eb-order-modal__dialog * {
            font-family: Arial, Helvetica, sans-serif !important;
        }
        .roxy-eb-account-bookings .roxy-eb-order-modal__dialog h4,
        .roxy-eb-account-bookings .roxy-eb-order-modal__dialog p,
        .roxy-eb-account-bookings .roxy-eb-order-modal__dialog span,
        .roxy-eb-account-bookings .roxy-eb-order-modal__dialog label { color:#111 !important; }
        .roxy-eb-account-bookings .roxy-eb-order-modal__check > label { display:inline-flex; align-items:center; gap:8px; margin-top:8px; }
        .roxy-eb-account-bookings .roxy-eb-order-modal__title { margin:0; color:#111 !important; font-size:26px; line-height:1.15; font-weight:700; letter-spacing:0; }
        .roxy-eb-account-bookings .roxy-eb-order-modal__subtitle { margin:6px 0 0; color:#444 !important; font-size:14px; }
        .roxy-eb-account-bookings .roxy-eb-order-modal__form { margin-top:18px; }
        .roxy-eb-account-bookings .roxy-eb-order-modal__form .roxy-eb-grid { gap:16px; }
        .roxy-eb-account-bookings .roxy-eb-order-modal__form .roxy-eb-grid label,
        .roxy-eb-account-bookings .roxy-eb-order-modal__form .roxy-eb-order-modal__check { font-size:14px; font-weight:400; color:#333; }
        .roxy-eb-account-bookings .roxy-eb-order-modal__form label span,
        .roxy-eb-account-bookings .roxy-eb-order-modal__form .roxy-eb-order-modal__check > span {
            font-size:14px;
            font-weight:600;
            color:#222 !important;
        }
        .roxy-eb-account-bookings .roxy-eb-order-modal__form input,
        .roxy-eb-account-bookings .roxy-eb-order-modal__form select,
        .roxy-eb-account-bookings .roxy-eb-order-modal__form textarea {
            border:1px solid #d8d8d8 !important;
            border-radius:10px !important;
            padding:12px 14px !important;
            box-shadow:none !important;
            min-height:44px;
            font-size:14px !important;
            font-weight:400 !important;
        }
        .roxy-eb-account-bookings .roxy-eb-order-modal__form textarea { min-height:92px; }
        .roxy-eb-account-bookings .roxy-eb-order-modal__alert {
            margin-top:20px;
            border:1px dashed #d8d8d8;
            background:#fafafa !important;
            color:#333 !important;
            border-radius:14px;
            padding:14px 16px;
        }
        .roxy-eb-account-bookings .roxy-eb-actions { margin-top:18px; gap:12px; }
        .roxy-eb-account-bookings .roxy-eb-actions .roxy-eb-btn {
            border-radius:0 !important;
            min-width:160px;
            min-height:46px;
            font-size:16px;
            font-weight:600;
        }
        .roxy-eb-account-bookings .roxy-eb-actions .roxy-eb-btn--primary {
            background:#000 !important;
            border-color:#000 !important;
            color:#fff !important;
        }
        .roxy-eb-account-bookings .roxy-eb-actions .roxy-eb-btn--ghost,
        .roxy-eb-account-bookings .roxy-eb-modal__top .roxy-eb-btn--ghost {
            background:#fff !important;
            border:2px solid #222 !important;
            color:#222 !important;
        }
        .roxy-eb-account-bookings .roxy-eb-account-actions { display:flex; flex-wrap:wrap; gap:8px; }
        .roxy-eb-account-bookings .roxy-eb-account-actions .button,
        .roxy-eb-account-bookings .roxy-eb-account-actions .roxy-eb-order-edit-trigger {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:36px;
            line-height:1;
            text-decoration:none;
        }
    </style>';
    echo '<h3>My Bookings</h3>';
    echo '<p>You can cancel for free up to 7 days before your event. Within 7 days, please contact us to cancel.</p>';
    echo '<p>You can edit your order more than ' . esc_html(roxy_eb_order_edit_cutoff_days()) . ' days before your event.</p>';

    if (empty($rows)) {
        echo '<p>No bookings found.</p></div>';
        return;
    }

    $settings = roxy_eb_get_settings();
    $free_days = intval($settings['cancel_free_days'] ?? 7);
    $open_modal_booking_id = !empty($_GET['roxy_eb_edit_order']) ? intval($_GET['roxy_eb_edit_order']) : 0;
    $modal_bookings = [];

    echo '<table class="shop_table shop_table_responsive">';
    echo '<thead><tr><th>Date</th><th>Doors</th><th>Duration</th><th>Guests</th><th>Notes</th><th>Status</th><th>Actions</th></tr></thead><tbody>';

    foreach ($rows as $booking) {
        $doors = roxy_eb_mysql_to_dt($booking['doors_open_at']);
        $duration = 2 + intval($booking['extra_hours']);
        $status_key = (string) $booking['status'];
        $status_label = ucwords(str_replace('_', ' ', $status_key));
        $status_colors = [
            'confirmed'       => '#16a34a',
            'pending_invoice' => '#d97706',
            'pending'         => '#d97706',
            'cancelled'       => '#dc2626',
        ];
        $badge_color = $status_colors[$status_key] ?? '#6b7280';
        $status = '<span style="display:inline-block;padding:2px 9px;border-radius:999px;background:' . esc_attr($badge_color) . ';color:#fff;font-size:11px;font-weight:700;white-space:nowrap;">' . esc_html($status_label) . '</span>';
        $notes = '';
        if (!empty($booking['notes_admin'])) {
            $notes = wp_strip_all_tags((string) $booking['notes_admin']);
            if (strlen($notes) > 80) $notes = substr($notes, 0, 77) . '...';
        }

        $can_cancel = in_array((string) $booking['status'], ['confirmed', 'pending_invoice'], true)
            && (new DateTimeImmutable('now', wp_timezone()) < $doors->modify('-' . $free_days . ' days'));
        $can_edit_order = roxy_eb_can_customer_edit_order($booking);
        $actions = [];

        if ($can_cancel) {
            $cancel_url = add_query_arg([
                'roxy_eb_cancel' => intval($booking['id']),
                '_wpnonce' => wp_create_nonce('roxy_eb_cancel_' . intval($booking['id'])),
            ], wc_get_account_endpoint_url('roxy-bookings'));
            $actions[] = '<a class="button" href="' . esc_url($cancel_url) . '" onclick="return confirm(\'Cancel this booking?\');">Cancel</a>';
        } elseif (in_array((string) $booking['status'], ['confirmed', 'pending_invoice'], true)) {
            $actions[] = '<span>Contact us to cancel</span>';
        }

        if ($can_edit_order) {
            $modal_id = 'roxy-eb-order-modal-' . intval($booking['id']);
            $actions[] = '<button type="button" class="button roxy-eb-order-edit-trigger" data-target="' . esc_attr($modal_id) . '" onclick="var m=document.getElementById(\'' . esc_attr($modal_id) . '\'); if(m){m.hidden=false;m.style.display=\'flex\';m.setAttribute(\'aria-hidden\',\'false\');document.body.classList.add(\'roxy-eb-modal-open\');} return false;">Edit Order</button>';
            $modal_bookings[] = [$booking, $open_modal_booking_id === intval($booking['id'])];
        }

        if (!$actions) $actions[] = '&mdash;';

        echo '<tr>';
        echo '<td data-title="Date">' . esc_html($doors->format('D, M j, Y')) . '</td>';
        echo '<td data-title="Doors">' . esc_html($doors->format('g:i A')) . '</td>';
        echo '<td data-title="Duration">' . esc_html($duration . 'h') . '</td>';
        echo '<td data-title="Guests">' . esc_html(intval($booking['guest_count'])) . '</td>';
        echo '<td data-title="Notes">' . esc_html($notes) . '</td>';
        echo '<td data-title="Status">' . $status . '</td>';
        echo '<td data-title="Actions"><div class="roxy-eb-account-actions">' . implode(' ', $actions) . '</div></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    foreach ($modal_bookings as $modal_booking) {
        roxy_eb_render_my_booking_order_modal($modal_booking[0], $modal_booking[1]);
    }

    echo '<script>
        (function(){
            const openButtons = document.querySelectorAll(".roxy-eb-order-edit-trigger");
            const closeButtons = document.querySelectorAll("[data-roxy-eb-close-modal]");
            function setModalState(id, open){
                const modal = document.getElementById(id);
                if (!modal) return;
                modal.hidden = !open;
                modal.style.display = open ? "flex" : "none";
                modal.setAttribute("aria-hidden", open ? "false" : "true");
                document.body.classList.toggle("roxy-eb-modal-open", open);
            }
            openButtons.forEach(function(button){
                button.addEventListener("click", function(){
                    setModalState(button.getAttribute("data-target"), true);
                });
            });
            closeButtons.forEach(function(button){
                button.addEventListener("click", function(){
                    const modal = button.closest("[data-roxy-eb-order-modal]");
                    if (modal) setModalState(modal.id, false);
                });
            });
        })();
    </script>';
    echo '</div>';
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
    $free_days = intval($settings['cancel_free_days'] ?? 7);

    $doors = roxy_eb_mysql_to_dt($booking['doors_open_at']);
    $now = new DateTimeImmutable('now', wp_timezone());
    $is_free_window = $now < $doors->modify('-' . $free_days . ' days');

    if ($by === 'customer' && !$is_free_window) {
        return new WP_Error('too_late', 'This booking is within ' . $free_days . ' days. Please contact us to cancel.');
    }

    $in_progress[$booking_id] = true;
    try {
        $order_ids = [];
        $primary_order_id = intval($booking['woo_order_id']);
        if ($primary_order_id > 0) $order_ids[] = $primary_order_id;
        foreach (roxy_eb_booking_adjustment_order_ids($booking) as $adjustment_order_id) {
            if (!in_array($adjustment_order_id, $order_ids, true)) $order_ids[] = $adjustment_order_id;
        }

        if ($order_ids && class_exists('WC_Order')) {
            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order) continue;

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

        // Build audit note before updating
        $cancel_actor = $by === 'admin' ? ('admin:' . get_current_user_id()) : ('customer:' . get_current_user_id());
        $cancel_note  = '[' . current_time('Y-m-d H:i:s') . '] Cancelled by ' . $cancel_actor . '.';
        $existing_notes = trim((string) ($booking['notes_admin'] ?? ''));
        $merged_notes   = $existing_notes !== '' ? ($existing_notes . "\n" . $cancel_note) : $cancel_note;

        roxy_eb_repo_update_booking($booking_id, [
            'status' => 'cancelled',
            'invoice_status' => ($booking['payment_method'] ?? '') === 'invoice' ? 'void' : ($booking['invoice_status'] ?? 'not_needed'),
            'notes_admin' => $merged_notes,
        ]);

        error_log('[Roxy EB] Booking #' . $booking_id . ' cancelled by ' . $cancel_actor);

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
