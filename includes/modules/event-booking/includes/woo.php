<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_booking_meta_key() { return '_roxy_eb_booking'; }

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
        if ($key === 'Roxy Booking Data' || $key === '_roxy_eb_booking') unset($formatted_meta[$k]);
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
    $booking_id = roxy_eb_repo_insert_booking([
        'status' => $status,
        'wp_user_id' => $order ? ($order->get_user_id() ?: null) : (get_current_user_id() ?: null),
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

    $existing = roxy_eb_repo_get_booking_by_order($order_id);
    if ($existing) return;

    foreach ($order->get_items() as $item) {
        if (intval($item->get_product_id()) !== $pid) continue;
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
    foreach ($order->get_items() as $item) {
        if (intval($item->get_product_id()) === $pid) { $has = true; break; }
    }
    if (!$has) return;

    $booking = roxy_eb_repo_get_booking_by_order($order_id);
    echo '<section class="roxy-eb-thankyou" style="margin-top:24px;">';
    echo '<h2>Your Roxy Booking</h2>';

    if (!$booking) {
        echo '<p>Your booking is processing. If you do not receive a confirmation email shortly, please contact us.</p>';
        echo '</section>';
        return;
    }

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
    echo '<p><a href="' . esc_url(wc_get_account_endpoint_url('roxy-bookings')) . '">View or manage your bookings</a></p>';
    echo '</section>';
}

function roxy_eb_ajax_start_booking() {
    check_ajax_referer('roxy_eb_nonce', 'nonce');
    if (!roxy_eb_wc_ready()) wp_send_json_error(['message' => 'WooCommerce is required.']);
    $settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid <= 0) wp_send_json_error(['message' => 'Booking product not configured.']);

    $payload = isset($_POST['booking']) ? (array) $_POST['booking'] : [];
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
    $payload = isset($_POST['booking']) ? (array) $_POST['booking'] : [];

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
