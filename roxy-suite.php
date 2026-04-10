<?php
/**
 * Plugin Name: Roxy Suite
 * Description: Unified management plugin for Newport Roxy — Show Tickets, Will Call, Event Booking, Member Check, Arcade, Legacy NFC Redirect, and Grosses.
 * Version: 1.0.11
 * Author: Newport Roxy (AI Team)
 * Update URI: https://github.com/Tototex/roxy-suite
 */

if (!defined('ABSPATH')) exit;

define('ROXY_SUITE_VERSION', '1.0.11');
define('ROXY_SUITE_PATH', plugin_dir_path(__FILE__));
define('ROXY_SUITE_URL', plugin_dir_url(__FILE__));

function roxy_suite_admin_capability(): string {
    return 'roxy_suite_access';
}

function roxy_suite_user_can_access_admin(): bool {
    return current_user_can(roxy_suite_admin_capability()) || current_user_can('manage_options') || current_user_can('manage_woocommerce');
}

function roxy_suite_grant_capabilities(): void {
    foreach (['administrator', 'shop_manager'] as $role_name) {
        $role = get_role($role_name);
        if ($role && !$role->has_cap(roxy_suite_admin_capability())) {
            $role->add_cap(roxy_suite_admin_capability());
        }
    }
}

add_action('init', 'roxy_suite_grant_capabilities');
add_filter('option_page_capability_roxy_st_settings', fn() => roxy_suite_admin_capability());
add_filter('option_page_capability_roxy_grosses_settings', fn() => roxy_suite_admin_capability());

// ── Roxy Grosses constants ─────────────────────────────────────────────────────
define('ROXY_GROSSES_VER',  '0.4.7');
define('ROXY_GROSSES_PATH', ROXY_SUITE_PATH . 'includes/modules/grosses/');
define('ROXY_GROSSES_URL',  ROXY_SUITE_URL  . 'includes/modules/grosses/');

// ── Shared auto-updater ────────────────────────────────────────────────────────
require_once ROXY_SUITE_PATH . 'includes/class-roxy-suite-updater.php';

// ── Unified admin menu ─────────────────────────────────────────────────────────
require_once ROXY_SUITE_PATH . 'includes/class-roxy-suite-admin.php';
\RoxySuite\Admin::init();

// ── Health check ───────────────────────────────────────────────────────────────
require_once ROXY_SUITE_PATH . 'includes/class-roxy-suite-health.php';
add_action('wp_ajax_roxy_suite_run_tests', ['\\RoxySuite\\Health', 'ajax_run_tests']);

// ── Membership dashboard ───────────────────────────────────────────────────────
require_once ROXY_SUITE_PATH . 'includes/class-roxy-suite-members-dashboard.php';

// ── Module toggle AJAX ─────────────────────────────────────────────────────────
add_action('wp_ajax_roxy_suite_toggle_module', function () {
    check_ajax_referer('roxy_suite_toggle_module', 'nonce');
    if (!roxy_suite_user_can_access_admin()) {
        wp_send_json_error('Insufficient permissions', 403);
    }
    $module  = sanitize_key((string) ($_POST['module'] ?? ''));
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] !== '0' && $_POST['enabled'] !== '';
    $allowed = ['arcade', 'sub_check', 'will_call', 'show_tickets', 'event_booking', 'grosses'];
    if (!in_array($module, $allowed, true)) {
        wp_send_json_error('Unknown module', 400);
    }
    $modules = get_option('roxy_suite_modules', []);
    if (!is_array($modules)) $modules = [];
    $modules[$module] = $enabled;
    update_option('roxy_suite_modules', $modules);
    wp_send_json_success(['module' => $module, 'enabled' => $enabled]);
});

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
        roxy_suite_admin_capability(),
        'roxy-suite',
        'roxy_suite_dashboard_page',
        'dashicons-tickets-alt',
        56
    );
    // Dashboard (first submenu = same slug as parent, renames it)
    add_submenu_page('roxy-suite', 'Dashboard', 'Dashboard', roxy_suite_admin_capability(), 'roxy-suite', 'roxy_suite_dashboard_page');
}, 5);

function roxy_suite_dashboard_page() {
    if (!roxy_suite_user_can_access_admin()) return;
    $tabs = [
        'membership' => 'Membership Dashboard',
        'grosses' => 'Grosses Dashboard',
        'status' => 'Plugin Status',
    ];
    $active_tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'membership';
    if (!isset($tabs[$active_tab])) {
        $active_tab = 'membership';
    }
    $base_url = admin_url('admin.php?page=roxy-suite');
    ?>
    <div class="wrap">
        <h1>Roxy Suite Dashboard</h1>
        <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
            <?php foreach ($tabs as $slug => $label): ?>
                <a class="nav-tab<?php echo $active_tab === $slug ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', $slug, $base_url)); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($active_tab === 'membership'): ?>
            <?php if (function_exists('roxy_suite_module_enabled') && roxy_suite_module_enabled('sub_check')): ?>
                <?php \RoxySuite\Members_Dashboard::render(); ?>
            <?php else: ?>
                <div class="notice notice-warning"><p>Membership tools are disabled.</p></div>
            <?php endif; ?>
        <?php elseif ($active_tab === 'grosses'): ?>
            <?php if (function_exists('roxy_suite_module_enabled') && roxy_suite_module_enabled('grosses') && class_exists('\\RoxyGrosses\\Settings')): ?>
                <?php \RoxyGrosses\Settings::render_dashboard_panel('roxy-suite', 'grosses'); ?>
            <?php else: ?>
                <div class="notice notice-warning"><p>Grosses tools are disabled.</p></div>
            <?php endif; ?>
        <?php else: ?>
            <?php \RoxySuite\Health::render(); ?>
        <?php endif; ?>
    </div>
    <?php
}

// ── Module enable/disable helper ───────────────────────────────────────────────
function roxy_suite_module_enabled(string $key): bool {
    $modules = get_option('roxy_suite_modules', []);
    if (!is_array($modules)) return true;
    return ($modules[$key] ?? true) === true;
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
if (roxy_suite_module_enabled('arcade')) {
    require_once ROXY_SUITE_PATH . 'includes/modules/arcade/roxy-arcade.php';
}

// Subscription Member Check
if (roxy_suite_module_enabled('sub_check')) {
    require_once ROXY_SUITE_PATH . 'includes/modules/sub-check/roxy-sub-check.php';
}

// Will Call
if (roxy_suite_module_enabled('will_call')) {
    require_once ROXY_SUITE_PATH . 'includes/modules/will-call/roxy-will-call.php';
}

// Show Tickets — constants must be set before plugins_loaded loads the class files
if (roxy_suite_module_enabled('show_tickets')) {
    if (!defined('ROXY_ST_VER')) {
        define('ROXY_ST_VER', '0.2.10.53');
        define('ROXY_ST_PATH', ROXY_SUITE_PATH . 'includes/modules/show-tickets/');
        define('ROXY_ST_URL',  ROXY_SUITE_URL  . 'includes/modules/show-tickets/');
        define('ROXY_ST_LOG_SOURCE',       'roxy-st');
        define('ROXY_ST_META_SHOWING_ID',  '_roxy_showing_id');
        define('ROXY_ST_META_TICKET_TYPE', '_roxy_ticket_type');
    }
    // The module file registers its own plugins_loaded hook for class init.
    require_once ROXY_SUITE_PATH . 'includes/modules/show-tickets/roxy-show-tickets.php';
}

// Event Booking
if (roxy_suite_module_enabled('event_booking')) {
    require_once ROXY_SUITE_PATH . 'includes/modules/event-booking/roxy-event-booking.php';
}

// Grosses
if (roxy_suite_module_enabled('grosses')) {
    require_once ROXY_SUITE_PATH . 'includes/modules/grosses/roxy-grosses.php';
}

// ── Activation hook — consolidates all module DB/setup work ───────────────────
register_activation_hook(__FILE__, function () {
    roxy_suite_grant_capabilities();
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

    // Grosses schema + defaults
    if (class_exists('\\RoxyGrosses\\Store')) {
        \RoxyGrosses\Settings::ensure_defaults();
        \RoxyGrosses\Store::install_schema();
        \RoxyGrosses\Scheduler::sync_schedule();
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
    // Unschedule grosses cron
    if (class_exists('\\RoxyGrosses\\Scheduler')) {
        \RoxyGrosses\Scheduler::clear_schedule();
    }
    flush_rewrite_rules();
});
