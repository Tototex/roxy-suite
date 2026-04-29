<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_booking_meta_key() { return '_roxy_eb_booking'; }
function roxy_eb_booking_adjustment_meta_key() { return '_roxy_eb_booking_adjustment'; }

function roxy_eb_order_edit_cutoff_days(): int {
    return 4;
}

function roxy_eb_pizza_edit_cutoff_days(): int {
    return roxy_eb_order_edit_cutoff_days();
}

function roxy_eb_can_customer_edit_order($booking, ?DateTimeImmutable $now = null): bool {
    if (!is_array($booking)) return false;
    if (!in_array((string) ($booking['status'] ?? ''), ['confirmed', 'pending_invoice'], true)) return false;
    if (!$now) $now = new DateTimeImmutable('now', wp_timezone());
    try {
        $doors = roxy_eb_mysql_to_dt((string) ($booking['doors_open_at'] ?? ''));
    } catch (Throwable $e) {
        return false;
    }
    return $now < $doors->modify('-' . roxy_eb_order_edit_cutoff_days() . ' days');
}

function roxy_eb_can_customer_edit_pizza($booking, ?DateTimeImmutable $now = null): bool {
    return roxy_eb_can_customer_edit_order($booking, $now);
}

function roxy_eb_booking_adjustment_order_ids($booking): array {
    if (!is_array($booking)) return [];
    $raw = $booking['woo_adjustment_order_ids'] ?? null;
    if (is_array($raw)) {
        return array_values(array_filter(array_map('intval', $raw)));
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) return [];
    return array_values(array_filter(array_map('intval', $decoded)));
}

function roxy_eb_booking_append_adjustment_order_id($booking_id, $order_id) {
    $booking_id = intval($booking_id);
    $order_id = intval($order_id);
    if ($booking_id <= 0 || $order_id <= 0) return;
    $booking = roxy_eb_repo_get_booking($booking_id);
    if (!$booking) return;
    $ids = roxy_eb_booking_adjustment_order_ids($booking);
    if (!in_array($order_id, $ids, true)) {
        $ids[] = $order_id;
        roxy_eb_repo_update_booking($booking_id, ['woo_adjustment_order_ids' => wp_json_encode(array_values($ids))]);
    }
}

function roxy_eb_booking_component_totals(int $base_price, int $extra_hours, int $pizza_requested, int $pizza_quantity, int $bulk_requested, int $bulk_popcorn_qty, int $bulk_soda_qty): array {
    $settings = roxy_eb_get_settings();
    $extra_price = $extra_hours * intval($settings['extra_hour_price'] ?? 100);
    $pizza_total = $pizza_requested ? ($pizza_quantity * intval($settings['pizza_price'] ?? 18)) : 0;
    $bulk_concessions_total = $bulk_requested ? (($bulk_popcorn_qty + $bulk_soda_qty) * intval($settings['bulk_item_price'] ?? 3)) : 0;

    return [
        'base_price' => $base_price,
        'extra_price' => $extra_price,
        'pizza_total' => $pizza_total,
        'bulk_concessions_total' => $bulk_concessions_total,
        'total_price' => $base_price + $extra_price + $pizza_total + $bulk_concessions_total,
    ];
}

function roxy_eb_build_order_change_from_request($booking, array $input) {
    if (!is_array($booking)) {
        return new WP_Error('booking_missing', 'Booking not found.');
    }

    $duration_hours = max(2, min(8, intval($input['duration_hours'] ?? (2 + intval($booking['extra_hours'] ?? 0)))));
    $extra_hours = max(0, $duration_hours - 2);

    $raw_pizza_quantity = max(0, intval($input['pizza_quantity'] ?? 0));
    $pizza_requested = (!empty($input['pizza_requested']) || $raw_pizza_quantity > 0) ? 1 : 0;
    $pizza_quantity = $pizza_requested ? max(1, $raw_pizza_quantity) : 0;
    $pizza_order_details = $pizza_requested ? sanitize_textarea_field($input['pizza_order_details'] ?? '') : '';
    $raw_bulk_popcorn_qty = max(0, intval($input['bulk_popcorn_qty'] ?? 0));
    $raw_bulk_soda_qty = max(0, intval($input['bulk_soda_qty'] ?? 0));
    $bulk_requested = (!empty($input['bulk_concessions_requested']) || $raw_bulk_popcorn_qty > 0 || $raw_bulk_soda_qty > 0) ? 1 : 0;
    $bulk_popcorn_qty = $bulk_requested ? $raw_bulk_popcorn_qty : 0;
    $bulk_soda_qty = $bulk_requested ? $raw_bulk_soda_qty : 0;
    $notes_admin = sanitize_textarea_field($input['notes_admin'] ?? ($input['order_notes'] ?? ''));

    if ($pizza_requested && $pizza_quantity < 1) {
        return new WP_Error('invalid_pizza_qty', 'Pizza quantity is required.');
    }
    if ($pizza_requested && $pizza_order_details === '') {
        return new WP_Error('invalid_pizza_details', 'Pizza order details are required.');
    }

    if ($bulk_requested && (!roxy_eb_valid_bulk_qty($bulk_popcorn_qty) || !roxy_eb_valid_bulk_qty($bulk_soda_qty))) {
        return new WP_Error('invalid_bulk_qty', 'Bulk concessions must be 0, or between 25 and 250 for each item.');
    }

    try {
        $doors_open = roxy_eb_mysql_to_dt((string) ($booking['doors_open_at'] ?? ''));
    } catch (Throwable $e) {
        return new WP_Error('invalid_booking_time', 'Booking time could not be loaded.');
    }

    $calc = roxy_eb_calc_times($doors_open, $extra_hours);
    if (!roxy_eb_time_within_operating_hours($doors_open, intval($calc['guest_hours']))) {
        return new WP_Error('invalid_duration', 'Those added hours would fall outside operating hours.');
    }
    if (!roxy_eb_is_slot_available($calc['reserved_start'], $calc['reserved_end'], intval($booking['id'] ?? 0))) {
        return new WP_Error('time_unavailable', 'Those added hours are not available for this booking.');
    }

    $pricing = roxy_eb_booking_component_totals(
        intval($booking['base_price'] ?? 0),
        $extra_hours,
        $pizza_requested,
        $pizza_quantity,
        $bulk_requested,
        $bulk_popcorn_qty,
        $bulk_soda_qty
    );

    return [
        'duration_hours' => $duration_hours,
        'extra_hours' => $extra_hours,
        'show_start_at' => $calc['show_start']->format('Y-m-d H:i:s'),
        'doors_close_at' => $calc['doors_close']->format('Y-m-d H:i:s'),
        'reserved_start_at' => $calc['reserved_start']->format('Y-m-d H:i:s'),
        'reserved_end_at' => $calc['reserved_end']->format('Y-m-d H:i:s'),
        'notes_admin' => ($notes_admin !== '') ? $notes_admin : null,
        'pizza_requested' => $pizza_requested,
        'pizza_quantity' => $pizza_quantity,
        'pizza_order_details' => $pizza_requested ? $pizza_order_details : null,
        'pizza_total' => intval($pricing['pizza_total']),
        'bulk_concessions_requested' => $bulk_requested,
        'bulk_popcorn_qty' => $bulk_popcorn_qty,
        'bulk_soda_qty' => $bulk_soda_qty,
        'bulk_concessions_total' => intval($pricing['bulk_concessions_total']),
        'extra_price' => intval($pricing['extra_price']),
        'total_price' => intval($pricing['total_price']),
        'delta_total' => intval($pricing['total_price']) - intval($booking['total_price'] ?? 0),
    ];
}

function roxy_eb_build_pizza_change_from_request($booking, array $input) {
    return roxy_eb_build_order_change_from_request($booking, $input);
}

function roxy_eb_apply_booking_order_change($booking_id, array $change, string $reason = 'order_change') {
    $booking_id = intval($booking_id);
    $booking = roxy_eb_repo_get_booking($booking_id);
    if (!$booking) return new WP_Error('booking_missing', 'Booking not found.');

    $pizza_changed = (
        intval($booking['pizza_requested'] ?? 0) !== (!empty($change['pizza_requested']) ? 1 : 0)
        || intval($booking['pizza_quantity'] ?? 0) !== intval($change['pizza_quantity'] ?? 0)
        || trim((string) ($booking['pizza_order_details'] ?? '')) !== trim((string) ($change['pizza_order_details'] ?? ''))
    );

    $payload = [
        'extra_hours' => intval($change['extra_hours'] ?? intval($booking['extra_hours'] ?? 0)),
        'show_start_at' => sanitize_text_field($change['show_start_at'] ?? ($booking['show_start_at'] ?? '')),
        'doors_close_at' => sanitize_text_field($change['doors_close_at'] ?? ($booking['doors_close_at'] ?? '')),
        'reserved_start_at' => sanitize_text_field($change['reserved_start_at'] ?? ($booking['reserved_start_at'] ?? '')),
        'reserved_end_at' => sanitize_text_field($change['reserved_end_at'] ?? ($booking['reserved_end_at'] ?? '')),
        'notes_admin' => array_key_exists('notes_admin', $change) ? $change['notes_admin'] : ($booking['notes_admin'] ?? null),
        'pizza_requested' => !empty($change['pizza_requested']) ? 1 : 0,
        'pizza_quantity' => intval($change['pizza_quantity'] ?? 0),
        'pizza_order_details' => !empty($change['pizza_requested']) ? (string) ($change['pizza_order_details'] ?? '') : null,
        'pizza_total' => intval($change['pizza_total'] ?? 0),
        'bulk_concessions_requested' => !empty($change['bulk_concessions_requested']) ? 1 : 0,
        'bulk_popcorn_qty' => intval($change['bulk_popcorn_qty'] ?? 0),
        'bulk_soda_qty' => intval($change['bulk_soda_qty'] ?? 0),
        'bulk_concessions_total' => intval($change['bulk_concessions_total'] ?? 0),
        'extra_price' => intval($change['extra_price'] ?? intval($booking['extra_price'] ?? 0)),
        'total_price' => max(0, intval($change['total_price'] ?? (intval($booking['total_price'] ?? 0) + intval($change['delta_total'] ?? 0)))),
    ];

    if (empty($payload['pizza_requested'])) {
        $payload['pizza_checked_at'] = null;
        $payload['pizza_checked_by'] = null;
    } elseif ($pizza_changed) {
        $payload['pizza_checked_at'] = null;
        $payload['pizza_checked_by'] = null;
    }

    $result = roxy_eb_repo_update_booking($booking_id, $payload);
    if (is_wp_error($result)) return $result;

    $updated = roxy_eb_repo_get_booking($booking_id);
    if (!$updated) return new WP_Error('booking_missing', 'Booking not found after update.');

    if (!empty($updated['pizza_requested'])) {
        if (!empty($updated['pizza_checked_at'])) roxy_eb_clear_pizza_reminders($booking_id);
        else roxy_eb_schedule_pizza_reminder($booking_id);
    } else {
        roxy_eb_clear_pizza_reminders($booking_id);
    }

    if (($updated['sling_status'] ?? '') !== 'manual' && function_exists('roxy_eb_sling_enqueue_sync')) {
        $settings = roxy_eb_get_settings();
        if (($settings['sling_mode'] ?? 'disabled') !== 'disabled') {
            roxy_eb_sling_enqueue_sync($booking_id, $reason);
        }
    }

    return $updated;
}

function roxy_eb_apply_booking_pizza_change($booking_id, array $change, string $reason = 'pizza_change') {
    return roxy_eb_apply_booking_order_change($booking_id, $change, $reason);
}

function roxy_eb_start_booking_adjustment_checkout($booking, array $change) {
    if (!roxy_eb_wc_ready()) return new WP_Error('woo_required', 'WooCommerce is required.');
    $settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid <= 0) return new WP_Error('product_missing', 'Booking product not configured.');

    $booking_id = intval($booking['id'] ?? 0);
    if ($booking_id <= 0) return new WP_Error('booking_missing', 'Booking not found.');

    $delta = intval($change['delta_total'] ?? 0);
    if ($delta <= 0) return new WP_Error('non_positive_delta', 'No additional payment is required.');

    $cart = WC()->cart;
    foreach ($cart->get_cart() as $key => $item) {
        if (empty($item[roxy_eb_booking_adjustment_meta_key()])) continue;
        $adjustment = $item[roxy_eb_booking_adjustment_meta_key()];
        if (intval($adjustment['booking_id'] ?? 0) === $booking_id) {
            $cart->remove_cart_item($key);
        }
    }

    $adjustment = [
        'booking_id' => $booking_id,
        'delta_total' => $delta,
        'new_duration_hours' => intval($change['duration_hours'] ?? (2 + intval($booking['extra_hours'] ?? 0))),
        'new_extra_hours' => intval($change['extra_hours'] ?? 0),
        'new_show_start_at' => sanitize_text_field($change['show_start_at'] ?? ($booking['show_start_at'] ?? '')),
        'new_doors_close_at' => sanitize_text_field($change['doors_close_at'] ?? ($booking['doors_close_at'] ?? '')),
        'new_reserved_start_at' => sanitize_text_field($change['reserved_start_at'] ?? ($booking['reserved_start_at'] ?? '')),
        'new_reserved_end_at' => sanitize_text_field($change['reserved_end_at'] ?? ($booking['reserved_end_at'] ?? '')),
        'new_notes_admin' => (string) ($change['notes_admin'] ?? ''),
        'new_pizza_requested' => !empty($change['pizza_requested']) ? 1 : 0,
        'new_pizza_quantity' => intval($change['pizza_quantity'] ?? 0),
        'new_pizza_order_details' => (string) ($change['pizza_order_details'] ?? ''),
        'new_pizza_total' => intval($change['pizza_total'] ?? 0),
        'new_bulk_concessions_requested' => !empty($change['bulk_concessions_requested']) ? 1 : 0,
        'new_bulk_popcorn_qty' => intval($change['bulk_popcorn_qty'] ?? 0),
        'new_bulk_soda_qty' => intval($change['bulk_soda_qty'] ?? 0),
        'new_bulk_concessions_total' => intval($change['bulk_concessions_total'] ?? 0),
        'new_extra_price' => intval($change['extra_price'] ?? 0),
        'new_total_price' => intval($change['total_price'] ?? 0),
        'summary' => sprintf(
            'Booking update for %s on %s',
            trim((string) ($booking['customer_last_name'] ?? 'booking')),
            date_i18n('M j, Y', strtotime((string) ($booking['doors_open_at'] ?? 'now')))
        ),
    ];

    $added = $cart->add_to_cart($pid, 1, 0, [], [roxy_eb_booking_adjustment_meta_key() => $adjustment]);
    if (!$added) return new WP_Error('cart_failed', 'Could not start checkout. Please try again.');

    if (!empty($booking['customer_first_name'])) WC()->customer->set_billing_first_name($booking['customer_first_name']);
    if (!empty($booking['customer_last_name'])) WC()->customer->set_billing_last_name($booking['customer_last_name']);
    if (!empty($booking['customer_email'])) WC()->customer->set_billing_email($booking['customer_email']);
    if (!empty($booking['customer_phone'])) WC()->customer->set_billing_phone($booking['customer_phone']);

    return wc_get_checkout_url();
}

function roxy_eb_wc_ready(): bool {
    if (!function_exists('WC') && !class_exists('WooCommerce') && !defined('WC_VERSION')) return false;
    if (!function_exists('WC')) return false;

    if (!function_exists('wc_load_cart') && defined('WC_ABSPATH')) {
        $cartFns = WC_ABSPATH . 'includes/wc-cart-functions.php';
        $noticeFns = WC_ABSPATH . 'includes/wc-notice-functions.php';
        if (file_exists($cartFns)) include_once $cartFns;
        if (file_exists($noticeFns)) include_once $noticeFns;
    }

    try {
        if (function_exists('wc_load_cart')) wc_load_cart();
    } catch (Throwable $e) {}

    $wc = WC();
    if (!$wc) return false;

    if (empty($wc->cart) && method_exists($wc, 'initialize_cart')) {
        try { $wc->initialize_cart(); } catch (Throwable $e) {}
    }

    return !empty(WC()->cart);
}

function roxy_eb_register_woo_hooks() {
    add_action('wp_ajax_roxy_eb_start_booking', 'roxy_eb_ajax_start_booking');
    add_action('wp_ajax_nopriv_roxy_eb_start_booking', 'roxy_eb_ajax_start_booking');

    add_action('wp_ajax_roxy_eb_submit_invoice_booking', 'roxy_eb_ajax_submit_invoice_booking');
    add_action('wp_ajax_nopriv_roxy_eb_submit_invoice_booking', 'roxy_eb_ajax_submit_invoice_booking');

    add_filter('woocommerce_get_item_data', 'roxy_eb_display_cart_item_meta', 10, 2);
    add_action('woocommerce_checkout_create_order_line_item', 'roxy_eb_add_order_item_meta', 10, 4);
    add_action('woocommerce_before_calculate_totals', 'roxy_eb_apply_dynamic_price', 20, 1);
    add_action('woocommerce_checkout_process', 'roxy_eb_validate_checkout_slot');
    add_action('woocommerce_payment_complete', 'roxy_eb_on_payment_complete', 10, 1);
    add_action('woocommerce_thankyou', 'roxy_eb_thankyou_booking_details', 20, 1);
    add_action('woocommerce_order_refunded', 'roxy_eb_on_order_refunded', 10, 2);
    add_action('woocommerce_order_status_refunded', 'roxy_eb_on_order_status_refunded', 10, 2);
    add_filter('woocommerce_order_item_get_formatted_meta_data', 'roxy_eb_filter_formatted_item_meta', 10, 2);
}

function roxy_eb_filter_formatted_item_meta($formatted_meta, $item) {
    if (empty($formatted_meta) || !is_array($formatted_meta)) return $formatted_meta;
    foreach ($formatted_meta as $k => $meta) {
        $key = is_object($meta) && isset($meta->key) ? (string)$meta->key : '';
        if ($key === 'Roxy Booking Data' || $key === '_roxy_eb_booking' || $key === '_roxy_eb_booking_adjustment') unset($formatted_meta[$k]);
    }
    return $formatted_meta;
}

function roxy_eb_on_order_status_refunded($order_id, $order = null) {
    roxy_eb_maybe_cancel_booking_for_order($order_id);
}

function roxy_eb_on_order_refunded($order_id, $refund_id) {
    $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
    if (!$order) return;
    $total = (float) $order->get_total();
    $refunded_total = (float) $order->get_total_refunded();
    $is_fully_refunded = ($order->has_status('refunded') || ($total > 0 && ($refunded_total + 0.0001) >= $total));
    if (!$is_fully_refunded) return;
    roxy_eb_maybe_cancel_booking_for_order($order_id);
}

function roxy_eb_maybe_cancel_booking_for_order($order_id) {
    $order_id = intval($order_id);
    if ($order_id <= 0) return;
    $booking = roxy_eb_repo_get_booking_by_order($order_id);
    if (!$booking) return;
    if (($booking['status'] ?? '') === 'cancelled') return;

    if (function_exists('roxy_eb_cancel_booking')) {
        roxy_eb_cancel_booking(intval($booking['id']), 'admin');
    } else {
        roxy_eb_repo_update_booking(intval($booking['id']), ['status' => 'cancelled']);
    }
    roxy_eb_clear_pizza_reminders(intval($booking['id']));
}

function roxy_eb_display_cart_item_meta($item_data, $cart_item) {
    if (!empty($cart_item[roxy_eb_booking_adjustment_meta_key()])) {
        $a = $cart_item[roxy_eb_booking_adjustment_meta_key()];
        $item_data[] = ['name' => 'Type', 'value' => 'Booking update'];
        $item_data[] = ['name' => 'Booking', 'value' => '#' . intval($a['booking_id'] ?? 0)];
        $item_data[] = ['name' => 'Duration', 'value' => esc_html(intval($a['new_duration_hours'] ?? 2) . ' hours')];
        $item_data[] = ['name' => 'Pizza quantity', 'value' => !empty($a['new_pizza_requested']) ? esc_html(intval($a['new_pizza_quantity'] ?? 0) . ' pizza(s)') : 'No pizza'];
        if (!empty($a['new_pizza_requested']) && !empty($a['new_pizza_order_details'])) {
            $item_data[] = ['name' => 'Pizza order', 'value' => esc_html($a['new_pizza_order_details'])];
        }
        if (!empty($a['new_bulk_concessions_requested'])) {
            $item_data[] = ['name' => 'Bulk popcorn', 'value' => esc_html(intval($a['new_bulk_popcorn_qty'] ?? 0))];
            $item_data[] = ['name' => 'Bulk soda', 'value' => esc_html(intval($a['new_bulk_soda_qty'] ?? 0))];
        }
        if (!empty($a['new_notes_admin'])) {
            $item_data[] = ['name' => 'Order notes', 'value' => esc_html($a['new_notes_admin'])];
        }
        return $item_data;
    }

    if (empty($cart_item[roxy_eb_booking_meta_key()])) return $item_data;
    $b = $cart_item[roxy_eb_booking_meta_key()];
    $item_data[] = ['name' => 'Doors open', 'value' => esc_html($b['doors_open_local'] ?? '')];
    $item_data[] = ['name' => 'Duration', 'value' => esc_html(($b['guest_hours'] ?? '') . ' hours')];
    $item_data[] = ['name' => 'Guests', 'value' => esc_html($b['guest_count'] ?? '')];
    $item_data[] = ['name' => 'Customer type', 'value' => esc_html(ucfirst($b['customer_type'] ?? 'personal'))];
    if (!empty($b['business_name'])) $item_data[] = ['name' => 'Business', 'value' => esc_html($b['business_name'])];
    $item_data[] = ['name' => 'Payment method', 'value' => esc_html(($b['payment_method'] ?? 'pay_now') === 'invoice' ? 'Invoice' : 'Pay now')];
    $item_data[] = ['name' => 'Type', 'value' => esc_html(ucfirst($b['event_format'] ?? ''))];
    $item_data[] = ['name' => 'Visibility', 'value' => esc_html(ucfirst($b['visibility'] ?? ''))];
    if (!empty($b['pizza_requested'])) {
        $item_data[] = ['name' => 'Pizza', 'value' => esc_html(intval($b['pizza_quantity'] ?? 0) . ' pizza(s)')];
        if (!empty($b['pizza_order_details'])) $item_data[] = ['name' => 'Pizza order', 'value' => esc_html($b['pizza_order_details'])];
    }
    if (!empty($b['bulk_concessions_requested'])) {
        $item_data[] = ['name' => 'Bulk popcorn', 'value' => esc_html(intval($b['bulk_popcorn_qty'] ?? 0))];
        $item_data[] = ['name' => 'Bulk soda', 'value' => esc_html(intval($b['bulk_soda_qty'] ?? 0))];
    }
    if (!empty($b['notes'])) $item_data[] = ['name' => 'Notes', 'value' => esc_html($b['notes'])];
    return $item_data;
}

function roxy_eb_add_order_item_meta($item, $cart_item_key, $values, $order) {
    if (!empty($values[roxy_eb_booking_adjustment_meta_key()])) {
        $a = $values[roxy_eb_booking_adjustment_meta_key()];
        $item->add_meta_data('_roxy_eb_booking_adjustment', wp_json_encode($a), true);
        $item->add_meta_data('Type', 'Booking update', true);
        $item->add_meta_data('Booking ID', '#' . intval($a['booking_id'] ?? 0), true);
        $item->add_meta_data('Duration', intval($a['new_duration_hours'] ?? 2) . ' hours', true);
        $item->add_meta_data('Pizza quantity', !empty($a['new_pizza_requested']) ? intval($a['new_pizza_quantity'] ?? 0) : 'No pizza', true);
        if (!empty($a['new_pizza_requested']) && !empty($a['new_pizza_order_details'])) {
            $item->add_meta_data('Pizza order', sanitize_textarea_field($a['new_pizza_order_details']), true);
        }
        if (!empty($a['new_bulk_concessions_requested'])) {
            $item->add_meta_data('Bulk popcorn', intval($a['new_bulk_popcorn_qty'] ?? 0), true);
            $item->add_meta_data('Bulk soda', intval($a['new_bulk_soda_qty'] ?? 0), true);
        }
        if (!empty($a['new_notes_admin'])) {
            $item->add_meta_data('Order notes', sanitize_textarea_field($a['new_notes_admin']), true);
        }
        return;
    }

    if (empty($values[roxy_eb_booking_meta_key()])) return;
    $b = $values[roxy_eb_booking_meta_key()];

    $item->add_meta_data('_roxy_eb_booking', wp_json_encode($b), true);

    $fields = [
        'Doors open' => $b['doors_open_local'] ?? '',
        'Show starts' => !empty($b['show_start_at']) ? date_i18n('l, F j, Y g:i A', strtotime($b['show_start_at'])) : '',
        'Duration' => !empty($b['guest_hours']) ? intval($b['guest_hours']) . ' hours' : '',
        'Guests' => isset($b['guest_count']) ? (string) intval($b['guest_count']) : '',
        'Customer type' => !empty($b['customer_type']) ? ucfirst($b['customer_type']) : '',
        'Business name' => sanitize_text_field($b['business_name'] ?? ''),
        'Payment method' => (($b['payment_method'] ?? 'pay_now') === 'invoice' ? 'Invoice' : 'Pay now'),
        'Type' => !empty($b['event_format']) ? ucfirst(sanitize_text_field($b['event_format'])) : '',
        'Visibility' => !empty($b['visibility']) ? ucfirst(sanitize_text_field($b['visibility'])) : '',
        'Movie title' => !empty($b['movie_title']) ? sanitize_text_field($b['movie_title']) : '',
        'Pizza quantity' => !empty($b['pizza_requested']) ? (string) intval($b['pizza_quantity'] ?? 0) : '',
        'Pizza order' => !empty($b['pizza_requested']) ? sanitize_textarea_field($b['pizza_order_details'] ?? '') : '',
        'Bulk popcorn' => !empty($b['bulk_concessions_requested']) ? (string) intval($b['bulk_popcorn_qty'] ?? 0) : '',
        'Bulk soda' => !empty($b['bulk_concessions_requested']) ? (string) intval($b['bulk_soda_qty'] ?? 0) : '',
        'Notes' => !empty($b['notes']) ? sanitize_textarea_field($b['notes']) : '',
    ];
    foreach ($fields as $name => $value) {
        if ($value !== '') $item->add_meta_data($name, $value, true);
    }
}

function roxy_eb_apply_dynamic_price($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (!$cart || $cart->is_empty()) return;

    $settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid <= 0) return;

    foreach ($cart->get_cart() as $key => $item) {
        if (intval($item['product_id']) !== $pid) continue;
        if (!empty($item[roxy_eb_booking_adjustment_meta_key()])) {
            $a = $item[roxy_eb_booking_adjustment_meta_key()];
            $item['data']->set_price(floatval(intval($a['delta_total'] ?? 0)));
            continue;
        }
        if (empty($item[roxy_eb_booking_meta_key()])) continue;
        $b = $item[roxy_eb_booking_meta_key()];
        $total = floatval($b['total_price'] ?? 0);
        $item['data']->set_price($total);
    }
}

function roxy_eb_validate_checkout_slot() {
    if (!roxy_eb_wc_ready()) return;
    $cart = WC()->cart;
    if (!$cart || $cart->is_empty()) return;

    $settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid <= 0) return;

    foreach ($cart->get_cart() as $item) {
        if (intval($item['product_id']) !== $pid) continue;
        if (!empty($item[roxy_eb_booking_adjustment_meta_key()])) {
            $a = $item[roxy_eb_booking_adjustment_meta_key()];
            $booking_id = intval($a['booking_id'] ?? 0);
            $booking = $booking_id > 0 ? roxy_eb_repo_get_booking($booking_id) : null;
            if (!$booking) {
                wc_add_notice('Booking update data is missing. Please start your edit again.', 'error');
                return;
            }
            $doorsOpen = roxy_eb_mysql_to_dt($booking['doors_open_at']);
            $extraHours = intval($a['new_extra_hours'] ?? ($booking['extra_hours'] ?? 0));
            $calc = roxy_eb_calc_times($doorsOpen, $extraHours);
            if (!roxy_eb_time_within_operating_hours($doorsOpen, intval($calc['guest_hours']))) {
                wc_add_notice('Those added hours are outside operating hours.', 'error');
                return;
            }
            if (!roxy_eb_is_slot_available($calc['reserved_start'], $calc['reserved_end'], $booking_id)) {
                wc_add_notice('Sorry — those added hours are no longer available. Please edit your booking again.', 'error');
                return;
            }
            continue;
        }
        $b = $item[roxy_eb_booking_meta_key()] ?? null;
        if (!$b) {
            wc_add_notice('Booking data missing. Please start your booking again.', 'error');
            return;
        }
        $validation = roxy_eb_validate_booking_payload($b);
        if (is_wp_error($validation)) {
            wc_add_notice($validation->get_error_message(), 'error');
            return;
        }

        $tz = wp_timezone();
        $doorsOpen = new DateTimeImmutable($b['doors_open_at'], $tz);
        $extraHours = intval($b['extra_hours']);
        $calc = roxy_eb_calc_times($doorsOpen, $extraHours);

        if (!roxy_eb_check_lead_time($doorsOpen)) {
            wc_add_notice('Bookings must be made at least 48 hours in advance. Please contact us for sooner bookings.', 'error');
            return;
        }
        if (!roxy_eb_time_within_operating_hours($doorsOpen, intval($calc['guest_hours']))) {
            wc_add_notice('Selected time is outside operating hours.', 'error');
            return;
        }
        if (!roxy_eb_is_slot_available($calc['reserved_start'], $calc['reserved_end'])) {
            wc_add_notice('Sorry — that time is no longer available. Please pick another slot.', 'error');
            return;
        }

        $email = WC()->checkout()->get_value('billing_email');
        $phone = WC()->checkout()->get_value('billing_phone');
        if (empty($email) || empty($phone)) {
            wc_add_notice('Email and phone are required for event bookings.', 'error');
            return;
        }
    }
}

function roxy_eb_validate_booking_payload($payload) {
    $settings = roxy_eb_get_settings();
    $errors = [];

    $first = sanitize_text_field($payload['first_name'] ?? '');
    $last = sanitize_text_field($payload['last_name'] ?? '');
    $email = sanitize_email($payload['email'] ?? '');
    $phone = sanitize_text_field($payload['phone'] ?? '');

    if ($first === '') $errors[] = 'First name is required.';
    if ($last === '') $errors[] = 'Last name is required.';
    if (!$email || !is_email($email)) $errors[] = 'Valid email is required.';
    if ($phone === '') $errors[] = 'Phone number is required.';

    $guest_count = intval($payload['guest_count'] ?? 0);
    if ($guest_count < 1 || $guest_count > intval($settings['guest_cap'] ?? 250)) $errors[] = 'Guest count is invalid.';

    $customer_type = sanitize_text_field($payload['customer_type'] ?? 'personal');
    if (!in_array($customer_type, ['personal', 'business'], true)) $errors[] = 'Invalid customer type.';

    $business_name = sanitize_text_field($payload['business_name'] ?? '');
    if ($customer_type === 'business' && $business_name === '') $errors[] = 'Business name is required.';

    $payment_method = sanitize_text_field($payload['payment_method'] ?? 'pay_now');
    if ($customer_type === 'personal') $payment_method = 'pay_now';
    if (!in_array($payment_method, ['pay_now', 'invoice'], true)) $errors[] = 'Invalid payment method.';
    if ($customer_type !== 'business' && $payment_method === 'invoice') $errors[] = 'Invoice is only available for business bookings.';

    $event_format = sanitize_text_field($payload['event_format'] ?? 'movie');
    if (!in_array($event_format, ['movie', 'live'], true)) $errors[] = 'Invalid event format.';

    $visibility = sanitize_text_field($payload['visibility'] ?? 'private');
    if (!in_array($visibility, ['private', 'public'], true)) $errors[] = 'Invalid visibility setting.';

    if ($event_format === 'movie' && sanitize_text_field($payload['movie_title'] ?? '') === '') $errors[] = 'Movie title is required.';
    if ($event_format === 'live' && sanitize_textarea_field($payload['live_description'] ?? '') === '') $errors[] = 'Live event description is required.';

    $pizza_requested = !empty($payload['pizza_requested']) ? 1 : 0;
    $pizza_quantity = intval($payload['pizza_quantity'] ?? 0);
    $pizza_order_details = sanitize_textarea_field($payload['pizza_order_details'] ?? '');
    if ($pizza_requested) {
        if ($pizza_quantity < 1) $errors[] = 'Pizza quantity is required.';
        if ($pizza_order_details === '') $errors[] = 'Pizza order details are required.';
    }

    $doors_open = sanitize_text_field($payload['doors_open_at'] ?? '');
    $tz = wp_timezone();
    try {
        $doorsOpen = new DateTimeImmutable($doors_open, $tz);
    } catch (Exception $e) {
        $doorsOpen = null;
        $errors[] = 'Invalid start time.';
    }

    if ($doorsOpen) {
        $calc = roxy_eb_calc_times($doorsOpen, max(0, intval($payload['extra_hours'] ?? 0)));
        $inc = intval($settings['time_increment_minutes'] ?? 15);
        $minute = intval($doorsOpen->format('i'));
        if (($minute % $inc) !== 0) $errors[] = 'Start times must be in ' . $inc . '-minute increments.';
        if (!roxy_eb_check_lead_time($doorsOpen)) $errors[] = 'Bookings must be made at least ' . intval($settings['lead_time_hours']) . ' hours in advance.';
        if (!roxy_eb_time_within_operating_hours($doorsOpen, intval($calc['guest_hours']))) $errors[] = 'Selected time is outside operating hours.';
        if (!roxy_eb_is_slot_available($calc['reserved_start'], $calc['reserved_end'])) $errors[] = 'That time is not available.';
    }

    if ($errors) return new WP_Error('invalid_booking', implode(' ', $errors));
    return true;
}

function roxy_eb_normalize_booking_payload($payload) {
    $settings = roxy_eb_get_settings();
    $tz = wp_timezone();

    $first = sanitize_text_field($payload['first_name'] ?? '');
    $last = sanitize_text_field($payload['last_name'] ?? '');
    $email = sanitize_email($payload['email'] ?? '');
    $phone = sanitize_text_field($payload['phone'] ?? '');
    $guest_count = intval($payload['guest_count'] ?? 0);
    $customer_type = sanitize_text_field($payload['customer_type'] ?? 'personal');
    $business_name = sanitize_text_field($payload['business_name'] ?? '');
    $payment_method = sanitize_text_field($payload['payment_method'] ?? 'pay_now');
    if ($customer_type === 'personal') $payment_method = 'pay_now';

    $event_format = sanitize_text_field($payload['event_format'] ?? 'movie');
    $visibility = sanitize_text_field($payload['visibility'] ?? 'private');
    $movie_title = sanitize_text_field($payload['movie_title'] ?? '');
    $live_desc = sanitize_textarea_field($payload['live_description'] ?? '');
    $notes = sanitize_textarea_field($payload['notes'] ?? '');
    $extra_hours = max(0, intval($payload['extra_hours'] ?? 0));
    $doorsOpen = new DateTimeImmutable(sanitize_text_field($payload['doors_open_at'] ?? ''), $tz);
    $calc = roxy_eb_calc_times($doorsOpen, $extra_hours);

    $base = ($guest_count <= 25) ? intval($settings['base_price_under']) : intval($settings['base_price_over']);
    $extra_price = $extra_hours * intval($settings['extra_hour_price']);
    $pizza_requested = !empty($payload['pizza_requested']) ? 1 : 0;
    $pizza_quantity = $pizza_requested ? max(1, intval($payload['pizza_quantity'] ?? 0)) : 0;
    $pizza_order_details = $pizza_requested ? sanitize_textarea_field($payload['pizza_order_details'] ?? '') : '';
    $pizza_total = $pizza_requested ? ($pizza_quantity * intval($settings['pizza_price'] ?? 18)) : 0;
    $bulk_requested = !empty($payload['bulk_concessions_requested']) ? 1 : 0;
    $bulk_popcorn_qty = $bulk_requested ? intval($payload['bulk_popcorn_qty'] ?? 0) : 0;
    $bulk_soda_qty = $bulk_requested ? intval($payload['bulk_soda_qty'] ?? 0) : 0;
    $bulk_concessions_total = $bulk_requested ? (($bulk_popcorn_qty + $bulk_soda_qty) * intval($settings['bulk_item_price'] ?? 3)) : 0;
    $total = $base + $extra_price + $pizza_total + $bulk_concessions_total;

    return [
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'phone' => $phone,
        'guest_count' => $guest_count,
        'customer_type' => $customer_type,
        'business_name' => $business_name,
        'payment_method' => $payment_method,
        'event_format' => $event_format,
        'movie_title' => $event_format === 'movie' ? $movie_title : '',
        'live_description' => $event_format === 'live' ? $live_desc : '',
        'notes' => $notes,
        'visibility' => $visibility,
        'extra_hours' => $extra_hours,
        'guest_hours' => intval($calc['guest_hours']),
        'doors_open_at' => $doorsOpen->format('Y-m-d H:i:s'),
        'doors_open_local' => $doorsOpen->format('l, F j, Y g:i A'),
        'show_start_at' => $calc['show_start']->format('Y-m-d H:i:s'),
        'doors_close_at' => $calc['doors_close']->format('Y-m-d H:i:s'),
        'reserved_start_at' => $calc['reserved_start']->format('Y-m-d H:i:s'),
        'reserved_end_at' => $calc['reserved_end']->format('Y-m-d H:i:s'),
        'base_price' => $base,
        'extra_price' => $extra_price,
        'pizza_requested' => $pizza_requested,
        'pizza_quantity' => $pizza_quantity,
        'pizza_order_details' => $pizza_order_details,
        'pizza_total' => $pizza_total,
        'bulk_concessions_requested' => $bulk_requested,
        'bulk_popcorn_qty' => $bulk_popcorn_qty,
        'bulk_soda_qty' => $bulk_soda_qty,
        'bulk_concessions_total' => $bulk_concessions_total,
        'total_price' => $total,
    ];
}

function roxy_eb_tier_from_guest_count($guest_count) {
    return intval($guest_count) <= 25 ? 'under_25' : 'over_26';
}

function roxy_eb_shifts_from_guest_count($guest_count) {
    return intval($guest_count) <= 25 ? 1 : 2;
}

function roxy_eb_create_booking_from_payload($payload, $order = null, $status = 'confirmed') {
    $guestCount = intval($payload['guest_count']);
    $wp_user_id = null;
    if ($order) {
        $wp_user_id = $order->get_user_id() ?: null;
    } elseif (array_key_exists('wp_user_id', $payload)) {
        $candidate_user_id = intval($payload['wp_user_id']);
        $wp_user_id = $candidate_user_id > 0 ? $candidate_user_id : null;
    } else {
        $wp_user_id = get_current_user_id() ?: null;
    }

    $booking_id = roxy_eb_repo_insert_booking([
        'status' => $status,
        'wp_user_id' => $wp_user_id,
        'customer_first_name' => sanitize_text_field($payload['first_name']),
        'customer_last_name' => sanitize_text_field($payload['last_name']),
        'customer_email' => sanitize_email($payload['email']),
        'customer_phone' => sanitize_text_field($payload['phone']),
        'customer_type' => sanitize_text_field($payload['customer_type'] ?? 'personal'),
        'business_name' => !empty($payload['business_name']) ? sanitize_text_field($payload['business_name']) : null,
        'payment_method' => sanitize_text_field($payload['payment_method'] ?? 'pay_now'),
        'invoice_status' => (($payload['payment_method'] ?? 'pay_now') === 'invoice') ? 'pending' : 'not_needed',
        'guest_count' => $guestCount,
        'tier' => roxy_eb_tier_from_guest_count($guestCount),
        'staff_shifts_required' => roxy_eb_shifts_from_guest_count($guestCount),
        'event_format' => sanitize_text_field($payload['event_format']),
        'movie_title' => !empty($payload['movie_title']) ? sanitize_text_field($payload['movie_title']) : null,
        'live_description' => !empty($payload['live_description']) ? sanitize_textarea_field($payload['live_description']) : null,
        'visibility' => sanitize_text_field($payload['visibility']),
        'notes_admin' => !empty($payload['notes']) ? sanitize_textarea_field($payload['notes']) : null,
        'doors_open_at' => sanitize_text_field($payload['doors_open_at']),
        'show_start_at' => sanitize_text_field($payload['show_start_at']),
        'doors_close_at' => sanitize_text_field($payload['doors_close_at']),
        'reserved_start_at' => sanitize_text_field($payload['reserved_start_at']),
        'reserved_end_at' => sanitize_text_field($payload['reserved_end_at']),
        'extra_hours' => intval($payload['extra_hours']),
        'base_price' => intval($payload['base_price']),
        'extra_price' => intval($payload['extra_price']),
        'pizza_requested' => !empty($payload['pizza_requested']) ? 1 : 0,
        'pizza_quantity' => intval($payload['pizza_quantity'] ?? 0),
        'pizza_order_details' => !empty($payload['pizza_order_details']) ? sanitize_textarea_field($payload['pizza_order_details']) : null,
        'pizza_total' => intval($payload['pizza_total'] ?? 0),
        'bulk_concessions_requested' => !empty($payload['bulk_concessions_requested']) ? 1 : 0,
        'bulk_popcorn_qty' => intval($payload['bulk_popcorn_qty'] ?? 0),
        'bulk_soda_qty' => intval($payload['bulk_soda_qty'] ?? 0),
        'bulk_concessions_total' => intval($payload['bulk_concessions_total'] ?? 0),
        'total_price' => intval($payload['total_price']),
        'woo_order_id' => $order ? intval($order->get_id()) : null,
        'sling_status' => 'unscheduled',
    ]);

    if (is_wp_error($booking_id)) return $booking_id;

    roxy_eb_schedule_pizza_reminder(intval($booking_id));

    $settings = roxy_eb_get_settings();
    if (($settings['sling_mode'] ?? 'disabled') !== 'disabled' && function_exists('roxy_eb_sling_enqueue_sync')) {
        roxy_eb_sling_enqueue_sync($booking_id, (($payload['payment_method'] ?? 'pay_now') === 'invoice') ? 'invoice_booking' : 'payment_complete');
    }

    return $booking_id;
}

function roxy_eb_on_payment_complete($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid <= 0) return;

    $processed_adjustment = false;
    foreach ($order->get_items() as $item) {
        if (intval($item->get_product_id()) !== $pid) continue;
        $raw_adjustment = $item->get_meta('_roxy_eb_booking_adjustment', true);
        if (!empty($raw_adjustment)) {
            $adjustment = json_decode((string) $raw_adjustment, true);
            if (is_array($adjustment)) {
                $result = roxy_eb_apply_booking_adjustment_from_order($order, $adjustment);
                if (is_wp_error($result)) {
                    $order->add_order_note('Roxy booking adjustment failed: ' . $result->get_error_message());
                }
                $processed_adjustment = true;
            }
        }
    }

    $existing = roxy_eb_repo_get_booking_by_order($order_id);
    if ($existing && !$processed_adjustment) return;

    foreach ($order->get_items() as $item) {
        if (intval($item->get_product_id()) !== $pid) continue;
        if (!empty($item->get_meta('_roxy_eb_booking_adjustment', true))) continue;
        $raw = $item->get_meta('_roxy_eb_booking', true);
        if (empty($raw)) $raw = $item->get_meta('Roxy Booking Data', true);
        $b = json_decode((string)$raw, true);
        if (!is_array($b)) continue;

        $tz = wp_timezone();
        $doorsOpen = new DateTimeImmutable($b['doors_open_at'], $tz);
        $calc = roxy_eb_calc_times($doorsOpen, intval($b['extra_hours'] ?? 0));

        if (!roxy_eb_is_slot_available($calc['reserved_start'], $calc['reserved_end'])) {
            roxy_eb_handle_conflict_refund($order, $b);
            return;
        }

        $booking_id = roxy_eb_create_booking_from_payload($b, $order, 'confirmed');
        if (is_wp_error($booking_id)) {
            $order->add_order_note('Roxy booking creation failed: ' . $booking_id->get_error_message());
            roxy_eb_email_internal_booking_failed($order, $booking_id->get_error_message());
            return;
        }

        roxy_eb_email_internal_booking_confirmed($order, $booking_id);
        roxy_eb_email_customer_booking_confirmed($order, $booking_id);
        return;
    }
}

function roxy_eb_apply_booking_adjustment_from_order($order, array $adjustment) {
    $booking_id = intval($adjustment['booking_id'] ?? 0);
    if ($booking_id <= 0) {
        return new WP_Error('booking_missing', 'Booking adjustment is missing its booking ID.');
    }

    $booking_before = roxy_eb_repo_get_booking($booking_id);
    if (!$booking_before) {
        return new WP_Error('booking_missing', 'Booking not found for adjustment.');
    }

    $change = [
        'duration_hours' => intval($adjustment['new_duration_hours'] ?? (2 + intval($booking_before['extra_hours'] ?? 0))),
        'extra_hours' => intval($adjustment['new_extra_hours'] ?? intval($booking_before['extra_hours'] ?? 0)),
        'show_start_at' => sanitize_text_field($adjustment['new_show_start_at'] ?? ($booking_before['show_start_at'] ?? '')),
        'doors_close_at' => sanitize_text_field($adjustment['new_doors_close_at'] ?? ($booking_before['doors_close_at'] ?? '')),
        'reserved_start_at' => sanitize_text_field($adjustment['new_reserved_start_at'] ?? ($booking_before['reserved_start_at'] ?? '')),
        'reserved_end_at' => sanitize_text_field($adjustment['new_reserved_end_at'] ?? ($booking_before['reserved_end_at'] ?? '')),
        'notes_admin' => sanitize_textarea_field($adjustment['new_notes_admin'] ?? ($booking_before['notes_admin'] ?? '')),
        'pizza_requested' => !empty($adjustment['new_pizza_requested']) ? 1 : 0,
        'pizza_quantity' => intval($adjustment['new_pizza_quantity'] ?? 0),
        'pizza_order_details' => !empty($adjustment['new_pizza_requested']) ? sanitize_textarea_field($adjustment['new_pizza_order_details'] ?? '') : null,
        'pizza_total' => intval($adjustment['new_pizza_total'] ?? 0),
        'bulk_concessions_requested' => !empty($adjustment['new_bulk_concessions_requested']) ? 1 : 0,
        'bulk_popcorn_qty' => intval($adjustment['new_bulk_popcorn_qty'] ?? 0),
        'bulk_soda_qty' => intval($adjustment['new_bulk_soda_qty'] ?? 0),
        'bulk_concessions_total' => intval($adjustment['new_bulk_concessions_total'] ?? 0),
        'extra_price' => intval($adjustment['new_extra_price'] ?? intval($booking_before['extra_price'] ?? 0)),
        'total_price' => intval($adjustment['new_total_price'] ?? intval($booking_before['total_price'] ?? 0)),
        'delta_total' => intval($adjustment['delta_total'] ?? 0),
    ];

    $booking_after = roxy_eb_apply_booking_order_change($booking_id, $change, 'customer_order_checkout');
    if (is_wp_error($booking_after)) {
        return $booking_after;
    }

    roxy_eb_booking_append_adjustment_order_id($booking_id, $order->get_id());
    $order->add_order_note('Roxy booking update applied to booking #' . $booking_id . ' for $' . number_format((float) $change['delta_total'], 2) . '.');
    roxy_eb_email_internal_booking_order_changed($booking_before, $booking_after, 'customer_card_checkout');
    roxy_eb_email_customer_booking_updated($booking_before, $booking_after);

    return true;
}

function roxy_eb_handle_conflict_refund($order, $b) {
    $order->add_order_note('Roxy booking conflict: slot became unavailable at payment completion.');
    try {
        $amount = floatval($b['total_price'] ?? $order->get_total());
        wc_create_refund([
            'amount'   => $amount,
            'reason'   => 'Booking time no longer available',
            'order_id' => $order->get_id(),
        ]);
        $order->add_order_note('Refunded due to booking conflict.');
    } catch (Exception $e) {
        $order->add_order_note('Refund attempt failed: ' . $e->getMessage());
    }
    roxy_eb_email_internal_booking_conflict($order);
}

function roxy_eb_thankyou_booking_details($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    $settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid <= 0) return;

    $has = false;
    $has_adjustment = false;
    $adjustment_booking_id = 0;
    foreach ($order->get_items() as $item) {
        if (intval($item->get_product_id()) !== $pid) continue;
        $has = true;
        $raw_adjustment = $item->get_meta('_roxy_eb_booking_adjustment', true);
        if (!empty($raw_adjustment)) {
            $adjustment = json_decode((string) $raw_adjustment, true);
            if (is_array($adjustment)) {
                $has_adjustment = true;
                $adjustment_booking_id = intval($adjustment['booking_id'] ?? 0);
                break;
            }
        }
    }
    if (!$has) return;

    $booking = $has_adjustment && $adjustment_booking_id > 0
        ? roxy_eb_repo_get_booking($adjustment_booking_id)
        : roxy_eb_repo_get_booking_by_order($order_id);
    echo '<section class="roxy-eb-thankyou" style="margin-top:24px;">';
    echo '<h2>' . ($has_adjustment ? 'Your Booking Update' : 'Your Roxy Booking') . '</h2>';

    if (!$booking) {
        echo '<p>' . ($has_adjustment ? 'Your booking update is processing. If you do not receive a confirmation email shortly, please contact us.' : 'Your booking is processing. If you do not receive a confirmation email shortly, please contact us.') . '</p>';
        echo '</section>';
        return;
    }

    if ($has_adjustment) {
        echo '<p>Your booking details have been updated.</p>';
        echo '<ul>';
        echo '<li><strong>Doors open:</strong> ' . esc_html($booking['doors_open_at']) . '</li>';
        echo '<li><strong>Duration:</strong> ' . esc_html(2 + intval($booking['extra_hours'])) . ' hours</li>';
        echo '<li><strong>Pizza:</strong> ' . (!empty($booking['pizza_requested']) ? esc_html(intval($booking['pizza_quantity']) . ' pizza(s)') : 'No pizza') . '</li>';
        if (!empty($booking['pizza_requested'])) {
            echo '<li><strong>Pizza order:</strong> ' . nl2br(esc_html($booking['pizza_order_details'])) . '</li>';
        }
        if (!empty($booking['bulk_concessions_requested'])) {
            echo '<li><strong>Bulk popcorn:</strong> ' . esc_html(intval($booking['bulk_popcorn_qty'])) . '</li>';
            echo '<li><strong>Bulk soda:</strong> ' . esc_html(intval($booking['bulk_soda_qty'])) . '</li>';
        }
        if (!empty($booking['notes_admin'])) {
            echo '<li><strong>Order notes:</strong> ' . nl2br(esc_html($booking['notes_admin'])) . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<ul>';
        echo '<li><strong>Doors open:</strong> ' . esc_html($booking['doors_open_at']) . '</li>';
        echo '<li><strong>Show starts:</strong> ' . esc_html($booking['show_start_at']) . '</li>';
        echo '<li><strong>Duration:</strong> ' . esc_html(2 + intval($booking['extra_hours'])) . ' hours</li>';
        echo '<li><strong>Guests:</strong> ' . esc_html(intval($booking['guest_count'])) . '</li>';
        echo '<li><strong>Payment:</strong> ' . esc_html(($booking['payment_method'] === 'invoice') ? 'Invoice' : 'Pay now') . '</li>';
        if (!empty($booking['business_name'])) echo '<li><strong>Business:</strong> ' . esc_html($booking['business_name']) . '</li>';
        if (!empty($booking['pizza_requested'])) {
            echo '<li><strong>Pizza:</strong> ' . esc_html(intval($booking['pizza_quantity'])) . ' pizza(s)</li>';
            echo '<li><strong>Pizza order:</strong> ' . nl2br(esc_html($booking['pizza_order_details'])) . '</li>';
        }
        if (!empty($booking['bulk_concessions_requested'])) {
            echo '<li><strong>Bulk popcorn:</strong> ' . esc_html(intval($booking['bulk_popcorn_qty'])) . '</li>';
            echo '<li><strong>Bulk soda:</strong> ' . esc_html(intval($booking['bulk_soda_qty'])) . '</li>';
        }
        echo '</ul>';
    }
    echo '<p><a href="' . esc_url(wc_get_account_endpoint_url('roxy-bookings')) . '">View or manage your bookings</a></p>';
    echo '</section>';
}

function roxy_eb_ajax_start_booking() {
    check_ajax_referer('roxy_eb_nonce', 'nonce');
    roxy_eb_rate_limit_public_request('start_booking', 300, 20);
    if (!roxy_eb_wc_ready()) wp_send_json_error(['message' => 'WooCommerce is required.']);
    $settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid <= 0) wp_send_json_error(['message' => 'Booking product not configured.']);

    $payload = isset($_POST['booking']) ? (array) wp_unslash($_POST['booking']) : [];
    $validation = roxy_eb_validate_booking_payload($payload);
    if (is_wp_error($validation)) wp_send_json_error(['message' => $validation->get_error_message()]);

    $booking_data = roxy_eb_normalize_booking_payload($payload);

    if (($booking_data['payment_method'] ?? 'pay_now') === 'invoice') {
        wp_send_json_error(['message' => 'Invoice bookings must use the invoice submission action.']);
    }

    $cart = WC()->cart;
    foreach ($cart->get_cart() as $key => $item) {
        if (intval($item['product_id']) === $pid) $cart->remove_cart_item($key);
    }

    $added = $cart->add_to_cart($pid, 1, 0, [], [ roxy_eb_booking_meta_key() => $booking_data ]);
    if (!$added) wp_send_json_error(['message' => 'Could not start checkout. Please try again.']);

    WC()->customer->set_billing_first_name($booking_data['first_name']);
    WC()->customer->set_billing_last_name($booking_data['last_name']);
    WC()->customer->set_billing_email($booking_data['email']);
    WC()->customer->set_billing_phone($booking_data['phone']);

    wp_send_json_success(['redirect' => wc_get_checkout_url()]);
}

function roxy_eb_ajax_submit_invoice_booking() {
    check_ajax_referer('roxy_eb_nonce', 'nonce');
    roxy_eb_rate_limit_public_request('submit_invoice_booking', HOUR_IN_SECONDS, 6);
    $payload = isset($_POST['booking']) ? (array) wp_unslash($_POST['booking']) : [];

    $payload['customer_type'] = sanitize_text_field($payload['customer_type'] ?? 'personal');
    $payload['payment_method'] = sanitize_text_field($payload['payment_method'] ?? 'pay_now');
    if (($payload['customer_type'] ?? 'personal') !== 'business') {
        wp_send_json_error(['message' => 'Invoice bookings are only available for business customers.']);
    }
    if (($payload['payment_method'] ?? '') !== 'invoice') {
        wp_send_json_error(['message' => 'Invalid invoice booking request.']);
    }

    $validation = roxy_eb_validate_booking_payload($payload);
    if (is_wp_error($validation)) wp_send_json_error(['message' => $validation->get_error_message()]);

    $booking_data = roxy_eb_normalize_booking_payload($payload);
    $booking_id = roxy_eb_create_booking_from_payload($booking_data, null, 'pending_invoice');

    if (is_wp_error($booking_id)) {
        wp_send_json_error(['message' => $booking_id->get_error_message()]);
    }

    $booking = roxy_eb_repo_get_booking($booking_id);
    roxy_eb_email_internal_invoice_booking($booking);
    roxy_eb_email_customer_invoice_booking($booking);

    $redirect = add_query_arg('roxy_eb_submitted', 'invoice', wp_get_referer() ?: home_url('/'));
    wp_send_json_success([
        'redirect' => $redirect,
        'message' => 'Your booking request was received. We will follow up with invoice details.',
    ]);
}
