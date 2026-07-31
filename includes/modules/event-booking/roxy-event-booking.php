<?php
/**
 * Plugin Name: Roxy Event Booking (WooCommerce + Sling)
 * Description: Private/Public event booking calendar for Newport Roxy. Customers can book time slots, pay via WooCommerce, request invoicing for business bookings, order pizza, and automatically create staffing shifts in Sling.
 * Version: 1.4.10
 * Author: Newport Roxy (AI Team)
 * Text Domain: roxy-event-booking
 * Update URI: https://github.com/Tototex/roxy-event-booking
 */

if (!defined('ABSPATH')) exit;

define('ROXY_EB_VERSION', '1.4.10');
define('ROXY_EB_PLUGIN_FILE', __FILE__);
define('ROXY_EB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ROXY_EB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ROXY_EB_ASSETS_URL', defined('ROXY_SUITE_URL') ? ROXY_SUITE_URL . 'assets/event-booking/' : ROXY_EB_PLUGIN_URL . 'assets/');
define('ROXY_EB_ASSETS_DIR', defined('ROXY_SUITE_PATH') ? ROXY_SUITE_PATH . 'assets/event-booking/' : ROXY_EB_PLUGIN_DIR . 'assets/');
define('ROXY_EB_SLING_LOG_RETENTION_DAYS', 180);
define('ROXY_EB_SLING_PRUNE_HOOK', 'roxy_eb_prune_sling_logs_daily');

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

add_action(ROXY_EB_SLING_PRUNE_HOOK, 'roxy_eb_repo_prune_old_sling_logs');

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

    if (!wp_next_scheduled(ROXY_EB_SLING_PRUNE_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', ROXY_EB_SLING_PRUNE_HOOK);
    }
});
