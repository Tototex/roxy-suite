<?php
/**
 * Plugin Name: Roxy Suite
 * Description: Unified management plugin for Newport Roxy — Show Tickets, Will Call, Event Booking, Member Check, Arcade, and Legacy NFC Redirect.
 * Version: 1.0.5
 * Author: Newport Roxy (AI Team)
 * Update URI: https://github.com/Tototex/roxy-suite
 */

if (!defined('ABSPATH')) exit;

define('ROXY_SUITE_VERSION', '1.0.5');
define('ROXY_SUITE_PATH', plugin_dir_path(__FILE__));
define('ROXY_SUITE_URL', plugin_dir_url(__FILE__));

// ── Shared auto-updater ────────────────────────────────────────────────────────
require_once ROXY_SUITE_PATH . 'includes/class-roxy-suite-updater.php';

// ── Unified admin menu ─────────────────────────────────────────────────────────
require_once ROXY_SUITE_PATH . 'includes/class-roxy-suite-admin.php';
\RoxySuite\Admin::init();

// ── Health check ───────────────────────────────────────────────────────────────
require_once ROXY_SUITE_PATH . 'includes/class-roxy-suite-health.php';
add_action('wp_ajax_roxy_suite_run_tests', ['\\RoxySuite\\Health', 'ajax_run_tests']);

\RoxySuite\Updater::init([
    'plugin_file' => plugin_basename(__FILE__),
    'version'     => ROXY_SUITE_VERSION,
    'github_repo' => 'Tototex/roxy-suite',
    'slug'        => 'roxy-suite',
    'name'        => 'Roxy Suite',
]);

// ── Top-level "Roxy Suite" admin menu ─────────────────────────────────────────
add_action('admin_menu', function () {
    add_menu_page(
        'Roxy Suite',
        'Roxy Suite',
        'manage_options',
        'roxy-suite',
        'roxy_suite_dashboard_page',
        'dashicons-tickets-alt',
        56
    );
    // Dashboard (first submenu = same slug as parent, renames it)
    add_submenu_page('roxy-suite', 'Dashboard', 'Dashboard', 'manage_options', 'roxy-suite', 'roxy_suite_dashboard_page');
}, 5);

function roxy_suite_dashboard_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>Roxy Suite — Dashboard</h1>
        <?php \RoxySuite\Health::render(); ?>
    </div>
    <?php
}

// ── Legacy NFC redirect (was roxy-legacy-api-redirect) ────────────────────────
// Redirects /?sub=123 → /member-check/?sub=123 for old NFC stickers.
add_action('template_redirect', function () {
    if (!isset($_GET['sub'])) return;
    $path = isset($_SERVER['REQUEST_URI'])
        ? parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH)
        : '';
    if ($path !== '/' && $path !== '') return;
    $sub = intval($_GET['sub']);
    if ($sub <= 0) return;
    wp_safe_redirect(home_url('/member-check/?sub=' . $sub), 302);
    exit;
}, 1);

// ── Load modules ──────────────────────────────────────────────────────────────

// Arcade
require_once ROXY_SUITE_PATH . 'includes/modules/arcade/roxy-arcade.php';

// Subscription Member Check
require_once ROXY_SUITE_PATH . 'includes/modules/sub-check/roxy-sub-check.php';

// Will Call
require_once ROXY_SUITE_PATH . 'includes/modules/will-call/roxy-will-call.php';

// Show Tickets — constants must be set before plugins_loaded loads the class files
if (!defined('ROXY_ST_VER')) {
    define('ROXY_ST_VER', '0.2.10.52');
    define('ROXY_ST_PATH', ROXY_SUITE_PATH . 'includes/modules/show-tickets/');
    define('ROXY_ST_URL',  ROXY_SUITE_URL  . 'includes/modules/show-tickets/');
    define('ROXY_ST_LOG_SOURCE',       'roxy-st');
    define('ROXY_ST_META_SHOWING_ID',  '_roxy_showing_id');
    define('ROXY_ST_META_TICKET_TYPE', '_roxy_ticket_type');
}
// The module file registers its own plugins_loaded hook for class init.
require_once ROXY_SUITE_PATH . 'includes/modules/show-tickets/roxy-show-tickets.php';

// Event Booking
require_once ROXY_SUITE_PATH . 'includes/modules/event-booking/roxy-event-booking.php';

// ── Activation hook — consolidates all module DB/setup work ───────────────────
register_activation_hook(__FILE__, function () {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    // Arcade scores table
    $t = $wpdb->prefix . 'roxy_arcade_scores';
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        game_key VARCHAR(64) NOT NULL,
        best_score BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_game (user_id, game_key),
        KEY game_score (game_key, best_score),
        KEY user_id (user_id)
    ) $charset;");
    if (get_option('roxy_arcade_db_version', null) === null) {
        update_option('roxy_arcade_db_version', '1.0');
    }
    if (get_option('roxy_arcade_rewards_enabled', null) === null) {
        update_option('roxy_arcade_rewards_enabled', 0);
    }
    update_option('roxy_arcade_award_time_local', '09:00 (site timezone)');
    if (!wp_next_scheduled('roxy_arcade_monthly_award')) {
        $tz  = wp_timezone();
        $run = new DateTime('first day of next month', $tz);
        $run->setTime(9, 0, 0);
        wp_schedule_single_event($run->getTimestamp(), 'roxy_arcade_monthly_award');
    }

    // Member scan log table
    $t2 = $wpdb->prefix . 'roxy_member_scans';
    dbDelta("CREATE TABLE $t2 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        scanned_at DATETIME NOT NULL,
        subscription_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        status VARCHAR(50) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        ip VARCHAR(45) NULL,
        user_agent TEXT NULL,
        PRIMARY KEY (id),
        KEY subscription_id (subscription_id),
        KEY scanned_at (scanned_at)
    ) $charset;");

    // Will Call checkins table
    $t3 = $wpdb->prefix . 'roxy_will_call_checkins';
    dbDelta("CREATE TABLE $t3 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        customer_key VARCHAR(190) NOT NULL,
        checked_in TINYINT(1) NOT NULL DEFAULT 0,
        used_qty INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY product_customer (product_id, customer_key)
    ) $charset;");

    // Event Booking schema + product
    if (function_exists('roxy_eb_install_schema')) {
        roxy_eb_install_schema();
        if (class_exists('WooCommerce') && function_exists('roxy_eb_maybe_create_booking_product')) {
            roxy_eb_maybe_create_booking_product();
        }
        if (defined('ROXY_EB_VERSION')) {
            update_option('roxy_eb_db_version', ROXY_EB_VERSION);
        }
    }

    // Show Tickets CPT registration
    if (class_exists('WooCommerce') && class_exists('\RoxyST\CPT')) {
        \RoxyST\CPT::register();
    }

    flush_rewrite_rules();
});

// ── Deactivation hook ─────────────────────────────────────────────────────────
register_deactivation_hook(__FILE__, function () {
    // Unschedule arcade cron
    $ts = wp_next_scheduled('roxy_arcade_monthly_award');
    while ($ts) {
        wp_unschedule_event($ts, 'roxy_arcade_monthly_award');
        $ts = wp_next_scheduled('roxy_arcade_monthly_award');
    }
    flush_rewrite_rules();
});
