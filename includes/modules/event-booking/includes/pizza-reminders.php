<?php
if (!defined('ABSPATH')) exit;

add_action('init', function () {
    if (function_exists('as_schedule_single_action')) {
        add_action('roxy_eb_pizza_reminder_check', 'roxy_eb_pizza_reminder_job', 10, 1);
    }
});

function roxy_eb_pizza_notification_emails() {
    return ['jason@newportroxy.com', 'info@newportroxy.com'];
}

function roxy_eb_clear_pizza_reminders($booking_id) {
    if (!function_exists('as_unschedule_all_actions')) return;
    as_unschedule_all_actions('roxy_eb_pizza_reminder_check', [intval($booking_id)], 'roxy-eb');
}

function roxy_eb_schedule_pizza_reminder($booking_id) {
    $booking_id = intval($booking_id);
    if ($booking_id <= 0 || !function_exists('as_schedule_single_action')) return;

    $booking = roxy_eb_repo_get_booking($booking_id);
    if (!$booking) return;

    roxy_eb_clear_pizza_reminders($booking_id);

    if (intval($booking['pizza_requested'] ?? 0) !== 1) return;
    if (($booking['status'] ?? '') === 'cancelled') return;
    if (!empty($booking['pizza_checked_at'])) return;

    $doors_open = strtotime($booking['doors_open_at'] ?? '');
    if (!$doors_open) return;

    $first_at = $doors_open - (4 * HOUR_IN_SECONDS);
    $now = time();

    if ($doors_open <= $now) return;
    if ($first_at < $now) $first_at = $now + 60;

    as_schedule_single_action($first_at, 'roxy_eb_pizza_reminder_check', [$booking_id], 'roxy-eb');
}

function roxy_eb_pizza_reminder_job($booking_id) {
    $booking_id = intval($booking_id);
    if ($booking_id <= 0) return;

    $booking = roxy_eb_repo_get_booking($booking_id);
    if (!$booking) return;
    if (($booking['status'] ?? '') === 'cancelled') return;
    if (intval($booking['pizza_requested'] ?? 0) !== 1) return;
    if (!empty($booking['pizza_checked_at'])) return;

    $doors_open = strtotime($booking['doors_open_at'] ?? '');
    if (!$doors_open || $doors_open <= time()) return;

    roxy_eb_email_pizza_reminder($booking);

    if (function_exists('as_schedule_single_action')) {
        as_schedule_single_action(time() + (30 * MINUTE_IN_SECONDS), 'roxy_eb_pizza_reminder_check', [$booking_id], 'roxy-eb');
    }
}
