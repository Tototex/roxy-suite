<?php
/**
 * Plugin Name: Roxy Requested Showings
 * Description: Crowd-supported requested movie showings that convert into real Roxy showings after manager approval.
 * Version: 0.2.0
 * Author: Newport Roxy (AI Team)
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ROXY_RS_VERSION', '0.2.0');
define('ROXY_RS_SCHEMA_VERSION', '3');
define('ROXY_RS_PATH', plugin_dir_path(__FILE__));
define('ROXY_RS_URL', plugin_dir_url(__FILE__));
define('ROXY_RS_CRON_HOOK', 'roxy_rs_daily_review');
define('ROXY_RS_REWRITE_OPTION', 'roxy_rs_rewrite_version');

require_once ROXY_RS_PATH . 'includes/schema.php';
require_once ROXY_RS_PATH . 'includes/repository.php';
require_once ROXY_RS_PATH . 'includes/class-roxy-rs-settings.php';
require_once ROXY_RS_PATH . 'includes/class-roxy-rs-cpt.php';
require_once ROXY_RS_PATH . 'includes/class-roxy-rs-frontend.php';
require_once ROXY_RS_PATH . 'includes/class-roxy-rs-payment-attempts.php';
require_once ROXY_RS_PATH . 'includes/class-roxy-rs-conversion-claims.php';
require_once ROXY_RS_PATH . 'includes/class-roxy-rs-pledge-attempts.php';
require_once ROXY_RS_PATH . 'includes/class-roxy-rs-agreement.php';
require_once ROXY_RS_PATH . 'includes/class-roxy-rs-conversion.php';

$dbv = get_option('roxy_rs_db_version');
$schema_ready = false;
global $wpdb;
try {
    if ($dbv !== ROXY_RS_VERSION || (string) get_option('roxy_rs_db_schema_version', '') !== ROXY_RS_SCHEMA_VERSION) {
        roxy_rs_install_schema();
        update_option('roxy_rs_db_version', ROXY_RS_VERSION);
        update_option('roxy_rs_db_schema_version', ROXY_RS_SCHEMA_VERSION);
    } else {
        $wpdb_table = roxy_rs_table_backings();
        $wpdb->last_error = '';
        $agreement_column = $wpdb->get_var("SHOW COLUMNS FROM `$wpdb_table` LIKE 'agreement_json'");
        $schema_error = $wpdb->last_error !== '';
        $attempts_table = roxy_rs_table_payment_attempts();
        $wpdb->last_error = '';
        $attempt_engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $attempts_table
        ));
        $attempt_schema_error = $wpdb->last_error !== '';
        $wpdb->last_error = '';
        $attempt_primary = $wpdb->get_results("SHOW INDEX FROM `$attempts_table` WHERE Key_name='PRIMARY'", ARRAY_A);
        $attempt_primary_valid = $wpdb->last_error === '' && is_array($attempt_primary) && count($attempt_primary) === 1
            && (string) ($attempt_primary[0]['Column_name'] ?? '') === 'order_id'
            && (int) ($attempt_primary[0]['Non_unique'] ?? 1) === 0
            && (int) ($attempt_primary[0]['Seq_in_index'] ?? 0) === 1;
        $attempt_columns_valid = true;
        foreach (['order_id', 'attempt_json', 'result_json', 'created_at', 'updated_at'] as $column) {
            $wpdb->last_error = '';
            $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$attempts_table` LIKE %s", $column));
            if ($wpdb->last_error !== '' || $found !== $column) $attempt_columns_valid = false;
        }
        if ($schema_error || $attempt_schema_error || !$attempt_columns_valid || !$attempt_primary_valid || $agreement_column !== 'agreement_json' || $attempt_engine !== 'InnoDB') {
            roxy_rs_install_schema();
            update_option('roxy_rs_db_version', ROXY_RS_VERSION);
            update_option('roxy_rs_db_schema_version', ROXY_RS_SCHEMA_VERSION);
        }
    }
    $schema_ready = true;
} catch (\Throwable $error) {
    error_log('Roxy Requested Showings could not verify its required database schema.');
}
define('ROXY_RS_SCHEMA_READY', $schema_ready);

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
