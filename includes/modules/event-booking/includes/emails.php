<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_email_internal_booking_confirmed($order, $booking_id) {
    $settings = roxy_eb_get_settings();
    $to = $settings['internal_email'] ?: get_option('admin_email');
    $booking = roxy_eb_repo_get_booking($booking_id);
    if (!$booking) return;

    $subject = 'New Roxy Booking — ' . $booking['doors_open_at'] . ' — ' . $booking['customer_last_name'];
    $body = roxy_eb_render_email_booking_summary($booking, $order, true);

    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

function roxy_eb_email_customer_booking_confirmed($order, $booking_id) {
    $booking = roxy_eb_repo_get_booking($booking_id);
    if (!$booking) return;

    $to = $booking['customer_email'];
    $subject = 'Your Newport Roxy booking is confirmed — ' . date_i18n('M j, Y g:i A', strtotime($booking['doors_open_at']));
    $body = roxy_eb_render_email_booking_summary_html($booking, $order);

    wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
}

function roxy_eb_email_internal_invoice_booking($booking) {
    if (!$booking) return;
    $settings = roxy_eb_get_settings();
    $to = $settings['internal_email'] ?: get_option('admin_email');
    $name = trim(($booking['business_name'] ?: '') ?: ($booking['customer_first_name'] . ' ' . $booking['customer_last_name']));
    $subject = 'INVOICE BOOKING — ' . $booking['doors_open_at'] . ' — ' . $name;
    $body = roxy_eb_render_email_booking_summary($booking, null, true);
    $body .= "\n\nACTION NEEDED\nSend invoice / payment instructions to the customer.\n";
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

function roxy_eb_email_customer_invoice_booking($booking) {
    if (!$booking || empty($booking['customer_email'])) return;
    $to = $booking['customer_email'];
    $subject = 'Your Newport Roxy booking request was received';
    $body = roxy_eb_render_email_invoice_customer_html($booking);
    wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
}

function roxy_eb_email_pizza_reminder($booking) {
    if (!$booking) return;
    $to = implode(',', roxy_eb_pizza_notification_emails());
    $subject = 'PIZZA NOT MARKED HANDLED — Booking #' . intval($booking['id']) . ' — ' . date_i18n('M j, Y g:i A', strtotime($booking['doors_open_at']));
    $admin_link = admin_url('admin.php?page=roxy-eb&roxy_eb_action=edit&booking_id=' . intval($booking['id']));
    $body = "Pizza has not been marked handled yet.\n\n"
          . "Booking ID: #" . intval($booking['id']) . "\n"
          . "Customer: " . trim($booking['customer_first_name'] . ' ' . $booking['customer_last_name']) . "\n"
          . "Business: " . ($booking['business_name'] ?: '—') . "\n"
          . "Doors open: " . $booking['doors_open_at'] . "\n"
          . "Pizza quantity: " . intval($booking['pizza_quantity']) . "\n"
          . "Pizza order:\n" . ($booking['pizza_order_details'] ?: '—') . "\n\n"
          . "Mark handled here:\n" . $admin_link . "\n";
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

function roxy_eb_render_email_invoice_customer_html($booking) {
    $rows = roxy_eb_booking_rows_for_email($booking);
    $html  = '<div style="font-family: Arial, Helvetica, sans-serif; line-height:1.5; color:#111; max-width:640px; margin:0 auto;">';
    $html .= '<h2 style="margin:0 0 10px;">Booking request received</h2>';
    $html .= '<p style="margin:0 0 18px;">Thanks for your booking request. We have your time reserved and will follow up with invoice/payment details.</p>';
    $html .= roxy_eb_render_rows_table_html($rows);
    $html .= '<p style="margin:18px 0 0;"><strong>What happens next?</strong><br>Our team will review the request and send invoice or payment details.</p>';
    $html .= '</div>';
    return $html;
}

function roxy_eb_render_email_booking_summary_html($booking, $order) {
    $rows = roxy_eb_booking_rows_for_email($booking);
    $pricing = roxy_eb_booking_pricing_rows($booking);

    $account_url = 'https://newportroxy.com/my-account/roxy-bookings/';
    if (function_exists('wc_get_account_endpoint_url')) {
        $maybe = wc_get_account_endpoint_url('roxy-bookings');
        if (!empty($maybe)) $account_url = $maybe;
    }

    $html  = '<div style="font-family: Arial, Helvetica, sans-serif; line-height:1.5; color:#111; max-width:640px; margin:0 auto;">';
    $html .= '<h2 style="margin:0 0 10px;">Booking confirmed!</h2>';
    $html .= '<p style="margin:0 0 18px;">Thanks for booking with the Newport Roxy Theater. Here are your details:</p>';
    $html .= roxy_eb_render_rows_table_html($rows);
    $html .= '<h3 style="margin:18px 0 8px;">Pricing</h3>';
    $html .= roxy_eb_render_rows_table_html($pricing);
    $html .= '<p style="margin:0 0 14px;"><strong>Cancellation</strong><br>Free cancellation up to 7 days before your event. Within 7 days, please contact us to cancel.</p>';
    $html .= '<p style="margin:0 0 18px;"><a href="' . esc_url($account_url) . '" style="display:inline-block; padding:10px 14px; background:#111; color:#fff; text-decoration:none; border-radius:6px;">Manage your booking here</a></p>';
    $html .= '</div>';
    return $html;
}

function roxy_eb_booking_rows_for_email($booking) {
    $rows = [];
    $rows[] = ['Doors open', date_i18n('l, F j, Y g:i A', strtotime($booking['doors_open_at']))];
    $rows[] = ['Show starts', date_i18n('l, F j, Y g:i A', strtotime($booking['show_start_at'])) . ' (about 30 minutes after doors)'];
    $rows[] = ['Doors close', date_i18n('l, F j, Y g:i A', strtotime($booking['doors_close_at']))];
    $rows[] = ['Duration', (2 + intval($booking['extra_hours'])) . ' hours'];
    $rows[] = ['Guests', intval($booking['guest_count'])];
    $rows[] = ['Customer type', ucfirst($booking['customer_type'] ?: 'personal')];
    if (!empty($booking['business_name'])) $rows[] = ['Business', $booking['business_name']];
    $rows[] = ['Payment', ($booking['payment_method'] === 'invoice') ? 'Invoice' : 'Pay now'];
    if (($booking['payment_method'] ?? '') === 'invoice') $rows[] = ['Invoice status', ucfirst($booking['invoice_status'] ?: 'pending')];
    $rows[] = ['Event format', ucfirst($booking['event_format'])];
    if ($booking['event_format'] === 'movie' && !empty($booking['movie_title'])) $rows[] = ['Movie title', $booking['movie_title']];
    if ($booking['event_format'] === 'live' && !empty($booking['live_description'])) $rows[] = ['Live event', $booking['live_description']];
    if (!empty($booking['pizza_requested'])) {
        $rows[] = ['Pizza quantity', intval($booking['pizza_quantity'])];
        $rows[] = ['Pizza order', $booking['pizza_order_details']];
    }
    if (!empty($booking['bulk_concessions_requested'])) {
        $rows[] = ['Bulk popcorn', intval($booking['bulk_popcorn_qty'])];
        $rows[] = ['Bulk soda', intval($booking['bulk_soda_qty'])];
    }
    if (!empty($booking['notes_admin'])) $rows[] = ['Notes', $booking['notes_admin']];
    $rows[] = ['Visibility', ucfirst($booking['visibility'])];
    return $rows;
}

function roxy_eb_booking_pricing_rows($booking) {
    $base  = (float)($booking['base_price'] ?? 0);
    $extra = (float)($booking['extra_price'] ?? 0);
    $pizza = (float)($booking['pizza_total'] ?? 0);
    $bulk = (float)($booking['bulk_concessions_total'] ?? 0);
    $total = (float)($booking['total_price'] ?? 0);
    return [
        ['Base', '$' . number_format($base, 2)],
        ['Extra hours', '$' . number_format($extra, 2)],
        ['Pizza', '$' . number_format($pizza, 2)],
        ['Bulk concessions', '$' . number_format($bulk, 2)],
        [($booking['payment_method'] === 'invoice' ? 'Total due' : 'Total paid'), '$' . number_format($total, 2)],
    ];
}

function roxy_eb_render_rows_table_html($rows) {
    $html = '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse; margin:0 0 18px;">';
    foreach ($rows as $r) {
        $html .= '<tr>'
            . '<td style="padding:8px 10px; border:1px solid #e5e5e5; background:#fafafa; width:38%;"><strong>' . esc_html($r[0]) . '</strong></td>'
            . '<td style="padding:8px 10px; border:1px solid #e5e5e5;">' . nl2br(esc_html($r[1])) . '</td>'
            . '</tr>';
    }
    $html .= '</table>';
    return $html;
}

function roxy_eb_email_internal_booking_failed($order, $error) {
    $settings = roxy_eb_get_settings();
    $to = $settings['internal_email'] ?: get_option('admin_email');
    $subject = 'Roxy Booking ERROR — Order #' . $order->get_id();
    $body = "A booking could not be created for an order.\n\nOrder: #" . $order->get_id() . "\nCustomer: " . $order->get_formatted_billing_full_name() . "\nEmail: " . $order->get_billing_email() . "\n\nError:\n" . $error . "\n";
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

function roxy_eb_email_internal_booking_conflict($order) {
    $settings = roxy_eb_get_settings();
    $to = $settings['internal_email'] ?: get_option('admin_email');
    $subject = 'Roxy Booking CONFLICT — Order #' . $order->get_id();
    $body = "A booking payment completed, but the selected time became unavailable before we could create the booking record.\n\nOrder: #" . $order->get_id() . "\nCustomer: " . $order->get_formatted_billing_full_name() . "\nEmail: " . $order->get_billing_email() . "\n\nA refund was attempted. Please confirm the refund status in WooCommerce.\n";
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

function roxy_eb_email_internal_sling_failed($order, $booking_id, $error) {
    $settings = roxy_eb_get_settings();
    $to = $settings['internal_email'] ?: get_option('admin_email');
    $subject = 'Roxy Booking — Sling sync failed — Booking #' . intval($booking_id);
    $body = "Booking #" . intval($booking_id) . " was confirmed, but Sling shift creation failed.\n\nOrder: #" . $order->get_id() . "\n\nError:\n" . $error . "\n";
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

function roxy_eb_email_customer_booking_updated($booking_before, $booking_after) {
    if (empty($booking_after['customer_email'])) return;
    $to = $booking_after['customer_email'];
    $account_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('roxy-bookings') : home_url('/');
    $subject = 'Your Newport Roxy booking was updated';

    $lines = [];
    $lines[] = "Hi " . ($booking_after['customer_first_name'] ?: 'there') . ",";
    $lines[] = "";
    $lines[] = "Your booking was updated by our staff. Here are the details:";
    $lines[] = "";
    foreach (roxy_eb_booking_rows_for_email($booking_after) as $row) {
        $lines[] = $row[0] . ': ' . preg_replace('/\s+/', ' ', trim((string)$row[1]));
    }
    $lines[] = "";
    $lines[] = "You can view your bookings here:";
    $lines[] = $account_url;
    $lines[] = "";
    $lines[] = "— Newport Roxy";

    $body = implode("\n", $lines);
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

function roxy_eb_render_email_booking_summary($booking, $order = null, $internal = false) {
    $lines = [];
    $lines[] = (($booking['payment_method'] ?? '') === 'invoice') ? "Invoice booking request" : "Booking confirmed!";
    $lines[] = "";
    foreach (roxy_eb_booking_rows_for_email($booking) as $row) {
        $lines[] = $row[0] . ': ' . preg_replace('/\s+/', ' ', trim((string)$row[1]));
    }
    $lines[] = "";
    $lines[] = "Pricing";
    foreach (roxy_eb_booking_pricing_rows($booking) as $row) {
        $lines[] = $row[0] . ': ' . $row[1];
    }

    if ($internal) {
        $lines[] = "";
        $lines[] = "Customer";
        $lines[] = trim($booking['customer_first_name'] . " " . $booking['customer_last_name']);
        $lines[] = $booking['customer_email'];
        $lines[] = $booking['customer_phone'];
        if ($order) {
            $lines[] = "";
            $lines[] = "Order: #" . $order->get_id();
        }
    }
    return implode("\n", $lines);
}
