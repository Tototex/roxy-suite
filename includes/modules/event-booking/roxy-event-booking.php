<?php
/**
 * Plugin Name: Roxy Event Booking (WooCommerce + Sling)
 * Description: Private/Public event booking calendar for Newport Roxy. Customers can book time slots, pay via WooCommerce, request invoicing for business bookings, order pizza, and automatically create staffing shifts in Sling.
 * Version: 1.4.8
 * Author: Newport Roxy (AI Team)
 * Text Domain: roxy-event-booking
 * Update URI: https://github.com/Tototex/roxy-event-booking
 */

if (!defined('ABSPATH')) exit;

define('ROXY_EB_VERSION', '1.4.8');
define('ROXY_EB_PLUGIN_FILE', __FILE__);
define('ROXY_EB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ROXY_EB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ROXY_EB_ASSETS_URL', defined('ROXY_SUITE_URL') ? ROXY_SUITE_URL . 'assets/event-booking/' : ROXY_EB_PLUGIN_URL . 'assets/');
define('ROXY_EB_ASSETS_DIR', defined('ROXY_SUITE_PATH') ? ROXY_SUITE_PATH . 'assets/event-booking/' : ROXY_EB_PLUGIN_DIR . 'assets/');

require_once ROXY_EB_PLUGIN_DIR . 'includes/schema.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/settings.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/repository.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/availability.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/woo.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/shortcode.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/my-account.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/admin-pages.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/emails.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/sling.php';
require_once ROXY_EB_PLUGIN_DIR . 'includes/pizza-reminders.php';

add_action('plugins_loaded', function () {
    $dbv = get_option('roxy_eb_db_version');
    if ($dbv !== ROXY_EB_VERSION) {
        roxy_eb_install_schema();
        update_option('roxy_eb_db_version', ROXY_EB_VERSION);
    }

    roxy_eb_register_settings();
    roxy_eb_register_shortcodes();
    roxy_eb_register_my_account_endpoints();
    roxy_eb_register_admin_pages();

    if (class_exists('WooCommerce')) {
        roxy_eb_register_woo_hooks();
    }
});

// ── Invoice auto-cancel cron ───────────────────────────────────────────────────
add_action('init', function () {
    if (!wp_next_scheduled('roxy_eb_invoice_auto_cancel')) {
        wp_schedule_event(time(), 'daily', 'roxy_eb_invoice_auto_cancel');
    }
});

add_action('roxy_eb_invoice_auto_cancel', function () {
    $settings = roxy_eb_get_settings();
    $days = intval($settings['invoice_auto_cancel_days'] ?? 14);
    if ($days <= 0) return;

    global $wpdb;
    $table = roxy_eb_table_bookings();
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $stale = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, customer_email, customer_first_name, customer_last_name, doors_open_at, notes_admin
             FROM `{$table}`
             WHERE status = 'pending_invoice'
             AND created_at < %s",
            $cutoff
        ),
        ARRAY_A
    );

    if (!$stale) return;

    foreach ($stale as $booking) {
        $booking_id = intval($booking['id']);

        roxy_eb_repo_update_booking($booking_id, [
            'status'         => 'cancelled',
            'invoice_status' => 'void',
            'notes_admin'    => trim((string) ($booking['notes_admin'] ?? '')) . "\n[" . current_time('Y-m-d H:i:s') . '] Auto-cancelled: pending invoice exceeded ' . $days . ' days.',
        ]);

        if (function_exists('roxy_eb_clear_pizza_reminders')) roxy_eb_clear_pizza_reminders($booking_id);
        if (function_exists('roxy_eb_sling_enqueue_cancel')) roxy_eb_sling_enqueue_cancel($booking_id);

        // Notify the customer
        $customer_email = sanitize_email((string) ($booking['customer_email'] ?? ''));
        if ($customer_email && is_email($customer_email)) {
            $name = trim(($booking['customer_first_name'] ?? '') . ' ' . ($booking['customer_last_name'] ?? ''));
            $date_label = !empty($booking['doors_open_at']) ? date_i18n('F j, Y', strtotime($booking['doors_open_at'])) : '';
            $subject = 'Your Newport Roxy booking request has expired';
            $body  = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#111;">';
            $body .= '<h2>Booking Request Expired</h2>';
            $body .= '<p>Hi ' . esc_html($name) . ',</p>';
            $body .= '<p>Your pending booking request' . ($date_label ? ' for <strong>' . esc_html($date_label) . '</strong>' : '') . ' has been automatically cancelled because we did not receive invoice payment within ' . intval($days) . ' days.</p>';
            $body .= '<p>If you\'d still like to book the Roxy, please <a href="' . esc_url(home_url('/rent-the-roxy/')) . '">submit a new request here</a> or contact us directly.</p>';
            $body .= '<p>We hope to see you soon!</p>';
            $body .= '</div>';
            wp_mail($customer_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
        }

        error_log('[Roxy EB] Auto-cancelled pending invoice booking #' . $booking_id . ' (older than ' . $days . ' days).');
    }
});
