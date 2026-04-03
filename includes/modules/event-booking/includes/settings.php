<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_option_key() { return 'roxy_eb_settings'; }

function roxy_eb_defaults() {
    return [
        'internal_email' => 'info@newportroxy.com',
        'guest_cap' => 250,
        'lead_time_hours' => 48,
        'cancel_free_days' => 7,
        'base_price_under' => 250,
        'base_price_over'  => 300,
        'extra_hour_price' => 100,
        'pizza_price' => 18,
        'bulk_item_price' => 3,
        'open_time' => '08:00',
        'close_time' => '24:00',
        'time_increment_minutes' => 15,
        'showtime_blocks' => [
            ['weekday' => 5, 'start' => '18:00', 'end' => '22:00', 'label' => 'Regular Showing (Fri)'],
            ['weekday' => 6, 'start' => '18:00', 'end' => '22:00', 'label' => 'Regular Showing (Sat)'],
            ['weekday' => 0, 'start' => '13:00', 'end' => '17:00', 'label' => 'Regular Showing (Sun)'],
        ],
        'booking_product_id' => 0,

        'sling_mode' => 'disabled',
        'sling_base_url' => 'https://api.getsling.com',
        'sling_auth_email' => '',
        'sling_auth_password_enc' => '',
        'sling_auth_token_enc' => '',
        'sling_auth_token_obtained_at' => '',
        'sling_webhook_url' => '',
        'sling_auth_fail_email' => 'info@newportroxy.com',
        'sling_publish_shifts' => 0,
        'sling_location_label' => 'Newport Roxy Theater',
        'sling_position_private_show_label' => 'Private Show',
        'sling_position_concessionist_label' => 'Concessionist',
        'sling_location_id' => '',
        'sling_position_private_show_id' => '',
        'sling_position_concessionist_id' => '',
        'sling_location_id_resolved' => '',
        'sling_position_private_show_id_resolved' => '',
        'sling_position_concessionist_id_resolved' => '',
        'sling_shift_title_template' => '{VISIBILITY_EVENT} — {LAST_NAME} — {GUESTS} guests',
        'sling_shift_notes_template' => "Customer: {FIRST_NAME} {LAST_NAME}
Business: {BUSINESS_NAME}
Customer type: {CUSTOMER_TYPE}
Phone: {PHONE}
Email: {EMAIL}

Payment: {PAYMENT_METHOD}
Invoice status: {INVOICE_STATUS}

Type: {TYPE}
{DETAILS_LABEL}: {EVENT_DETAILS}

Doors open: {DOORS_OPEN}
Show starts: {SHOW_START}
Guests: {GUESTS}

Pizza: {PIZZA_REQUESTED}
Pizza quantity: {PIZZA_QUANTITY}
Pizza total: {PIZZA_TOTAL}
Pizza handled: {PIZZA_STATUS}
Pizza order:
{PIZZA_ORDER}

Bulk concessions: {BULK_CONCESSIONS_REQUESTED}
Bulk popcorn: {BULK_POPCORN_QTY}
Bulk soda: {BULK_SODA_QTY}
Bulk concessions total: {BULK_CONCESSIONS_TOTAL}

Notes:
{NOTES}",
    ];
}

function roxy_eb_get_settings() {
    $saved = get_option(roxy_eb_option_key(), []);
    $defaults = roxy_eb_defaults();
    $merged = array_merge($defaults, is_array($saved) ? $saved : []);
    if (empty($merged['showtime_blocks']) || !is_array($merged['showtime_blocks'])) {
        $merged['showtime_blocks'] = $defaults['showtime_blocks'];
    }
    return $merged;
}

function roxy_eb_update_settings($new) {
    $defaults = roxy_eb_defaults();
    $existing = get_option(roxy_eb_option_key(), []);
    if (!is_array($existing)) $existing = [];
    $clean = [];

    $clean['internal_email'] = sanitize_email($new['internal_email'] ?? $defaults['internal_email']);
    $clean['guest_cap'] = max(1, intval($new['guest_cap'] ?? $defaults['guest_cap']));
    $clean['lead_time_hours'] = max(0, intval($new['lead_time_hours'] ?? $defaults['lead_time_hours']));
    $clean['cancel_free_days'] = max(0, intval($new['cancel_free_days'] ?? $defaults['cancel_free_days']));
    $clean['base_price_under'] = max(0, intval($new['base_price_under'] ?? $defaults['base_price_under']));
    $clean['base_price_over']  = max(0, intval($new['base_price_over']  ?? $defaults['base_price_over']));
    $clean['extra_hour_price'] = max(0, intval($new['extra_hour_price'] ?? $defaults['extra_hour_price']));
    $clean['pizza_price'] = max(0, intval($new['pizza_price'] ?? $defaults['pizza_price']));
    $clean['bulk_item_price'] = max(0, intval($new['bulk_item_price'] ?? $defaults['bulk_item_price']));

    $clean['open_time']  = preg_match('/^\d{2}:\d{2}$/', $new['open_time'] ?? '') ? $new['open_time'] : $defaults['open_time'];
    $clean['close_time'] = preg_match('/^\d{2}:\d{2}$/', $new['close_time'] ?? '') ? $new['close_time'] : $defaults['close_time'];
    $clean['time_increment_minutes'] = in_array(intval($new['time_increment_minutes'] ?? $defaults['time_increment_minutes']), [5,10,15,20,30,60], true)
        ? intval($new['time_increment_minutes'])
        : $defaults['time_increment_minutes'];

    $clean['booking_product_id'] = intval($new['booking_product_id'] ?? $defaults['booking_product_id']);

    $clean['showtime_blocks'] = [];
    if (!empty($new['showtime_blocks']) && is_array($new['showtime_blocks'])) {
        foreach ($new['showtime_blocks'] as $b) {
            if (!empty($b['_delete'])) continue;
            $weekday = isset($b['weekday']) ? intval($b['weekday']) : null;
            $start = $b['start'] ?? '';
            $end = $b['end'] ?? '';
            $label = sanitize_text_field($b['label'] ?? '');
            if ($weekday === null || $weekday < 0 || $weekday > 6) continue;
            if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) continue;
            $clean['showtime_blocks'][] = ['weekday' => $weekday, 'start' => $start, 'end' => $end, 'label' => $label ?: 'Showtime Block'];
        }
    }
    if (empty($clean['showtime_blocks'])) $clean['showtime_blocks'] = $defaults['showtime_blocks'];

    $mode = $new['sling_mode'] ?? $defaults['sling_mode'];
    $clean['sling_mode'] = in_array($mode, ['disabled','webhook','direct'], true) ? $mode : 'disabled';
    $clean['sling_base_url'] = esc_url_raw($new['sling_base_url'] ?? $defaults['sling_base_url']);
    $clean['sling_auth_email'] = sanitize_email($new['sling_auth_email'] ?? ($existing['sling_auth_email'] ?? ($defaults['sling_auth_email'] ?? '')));
    $clean['sling_auth_password_enc'] = sanitize_text_field(array_key_exists('sling_auth_password_enc', $new) ? ($new['sling_auth_password_enc'] ?? '') : ($existing['sling_auth_password_enc'] ?? ''));
    $clean['sling_auth_token_enc'] = sanitize_text_field(array_key_exists('sling_auth_token_enc', $new) ? ($new['sling_auth_token_enc'] ?? '') : ($existing['sling_auth_token_enc'] ?? ''));
    $clean['sling_auth_token_obtained_at'] = sanitize_text_field(array_key_exists('sling_auth_token_obtained_at', $new) ? ($new['sling_auth_token_obtained_at'] ?? '') : ($existing['sling_auth_token_obtained_at'] ?? ''));
    $clean['sling_webhook_url'] = esc_url_raw($new['sling_webhook_url'] ?? '');
    $clean['sling_auth_fail_email'] = sanitize_email($new['sling_auth_fail_email'] ?? ($existing['sling_auth_fail_email'] ?? ($defaults['sling_auth_fail_email'] ?? get_option('admin_email'))));
    $clean['sling_publish_shifts'] = !empty($new['sling_publish_shifts']) ? 1 : 0;
    $clean['sling_location_label'] = sanitize_text_field($new['sling_location_label'] ?? ($defaults['sling_location_label'] ?? ''));
    $clean['sling_position_private_show_label'] = sanitize_text_field($new['sling_position_private_show_label'] ?? ($defaults['sling_position_private_show_label'] ?? ''));
    $clean['sling_position_concessionist_label'] = sanitize_text_field($new['sling_position_concessionist_label'] ?? ($defaults['sling_position_concessionist_label'] ?? ''));
    $clean['sling_location_id'] = sanitize_text_field($new['sling_location_id'] ?? '');
    $clean['sling_position_private_show_id'] = sanitize_text_field($new['sling_position_private_show_id'] ?? '');
    $clean['sling_position_concessionist_id'] = sanitize_text_field($new['sling_position_concessionist_id'] ?? '');
    $clean['sling_location_id_resolved'] = sanitize_text_field(array_key_exists('sling_location_id_resolved', $new) ? ($new['sling_location_id_resolved'] ?? '') : ($existing['sling_location_id_resolved'] ?? ''));
    $clean['sling_position_private_show_id_resolved'] = sanitize_text_field(array_key_exists('sling_position_private_show_id_resolved', $new) ? ($new['sling_position_private_show_id_resolved'] ?? '') : ($existing['sling_position_private_show_id_resolved'] ?? ''));
    $clean['sling_position_concessionist_id_resolved'] = sanitize_text_field(array_key_exists('sling_position_concessionist_id_resolved', $new) ? ($new['sling_position_concessionist_id_resolved'] ?? '') : ($existing['sling_position_concessionist_id_resolved'] ?? ''));
    $clean['sling_shift_title_template'] = sanitize_text_field($new['sling_shift_title_template'] ?? $defaults['sling_shift_title_template']);
    $clean['sling_shift_notes_template'] = sanitize_textarea_field($new['sling_shift_notes_template'] ?? $defaults['sling_shift_notes_template']);

    update_option(roxy_eb_option_key(), $clean);
    return $clean;
}

function roxy_eb_register_settings() {
    // No WP Settings API wiring; admin page handles saving.
}

function roxy_eb_maybe_create_booking_product() {
    if (!class_exists('WC_Product_Simple')) return;

    $settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid > 0 && get_post($pid)) return;

    $product = new WC_Product_Simple();
    $product->set_name('Roxy Event Booking');
    $product->set_status('publish');
    $product->set_catalog_visibility('hidden');
    $product->set_virtual(true);
    $product->set_sold_individually(true);
    $product->set_price(0);
    $product->set_regular_price(0);
    $product->set_manage_stock(false);
    $product->set_description('Auto-generated product used by Roxy Event Booking plugin. Do not delete.');
    $new_id = $product->save();

    $settings['booking_product_id'] = $new_id;
    roxy_eb_update_settings($settings);
}
