<?php
/**
 * Plugin Name: Roxy Requested Showings
 * Description: Crowd-supported requested movie showings that convert into real Roxy showings after manager approval.
 * Version: 0.1.0
 * Author: Newport Roxy (AI Team)
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ROXY_RS_VERSION', '0.1.0');
define('ROXY_RS_PATH', plugin_dir_path(__FILE__));
define('ROXY_RS_URL', plugin_dir_url(__FILE__));
define('ROXY_RS_CRON_HOOK', 'roxy_rs_daily_review');
define('ROXY_RS_REWRITE_OPTION', 'roxy_rs_rewrite_version');

require_once ROXY_RS_PATH . 'includes/schema.php';
require_once ROXY_RS_PATH . 'includes/repository.php';
require_once ROXY_RS_PATH . 'includes/class-roxy-rs-settings.php';
require_once ROXY_RS_PATH . 'includes/class-roxy-rs-cpt.php';
require_once ROXY_RS_PATH . 'includes/class-roxy-rs-frontend.php';
require_once ROXY_RS_PATH . 'includes/class-roxy-rs-conversion.php';

$dbv = get_option('roxy_rs_db_version');
if ($dbv !== ROXY_RS_VERSION) {
    roxy_rs_install_schema();
    update_option('roxy_rs_db_version', ROXY_RS_VERSION);
}

\RoxyRS\Settings::init();
\RoxyRS\CPT::init();
\RoxyRS\Frontend::init();
\RoxyRS\Conversion::init();

add_action('init', function () {
    $rewrite_version = (string) get_option(ROXY_RS_REWRITE_OPTION, '');
    if ($rewrite_version === ROXY_RS_VERSION) {
        return;
    }

    flush_rewrite_rules(false);
    update_option(ROXY_RS_REWRITE_OPTION, ROXY_RS_VERSION);
}, 99);

if (!wp_next_scheduled(ROXY_RS_CRON_HOOK)) {
    wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', ROXY_RS_CRON_HOOK);
}

add_action(ROXY_RS_CRON_HOOK, ['\\RoxyRS\\Conversion', 'run_daily_review']);
