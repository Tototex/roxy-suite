<?php
// Roxy Grosses module — loaded by Roxy Suite. Constants and hooks live in roxy-suite.php.

if (!defined('ABSPATH')) exit;

require_once ROXY_GROSSES_PATH . 'includes/class-roxy-grosses-settings.php';
require_once ROXY_GROSSES_PATH . 'includes/class-roxy-grosses-metadata.php';
require_once ROXY_GROSSES_PATH . 'includes/class-roxy-grosses-square.php';
require_once ROXY_GROSSES_PATH . 'includes/class-roxy-grosses-store.php';
require_once ROXY_GROSSES_PATH . 'includes/class-roxy-grosses-reporter.php';
require_once ROXY_GROSSES_PATH . 'includes/class-roxy-grosses-scheduler.php';
require_once ROXY_GROSSES_PATH . 'includes/class-roxy-grosses-workbook.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }

    \RoxyGrosses\Store::maybe_upgrade_schema();
    \RoxyGrosses\Store::maybe_backfill_history();
    \RoxyGrosses\Settings::init();
    \RoxyGrosses\Scheduler::init();
    \RoxyGrosses\Reporter::init();
    \RoxyGrosses\Workbook::init();
});
