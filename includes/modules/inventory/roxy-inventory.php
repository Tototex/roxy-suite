<?php
namespace RoxyInventory;
if (!defined('ABSPATH')) exit;

require_once ROXY_INVENTORY_PATH . 'includes/class-roxy-inventory-settings.php';
require_once ROXY_INVENTORY_PATH . 'includes/class-roxy-inventory-store.php';
require_once ROXY_INVENTORY_PATH . 'includes/class-roxy-inventory-square.php';
require_once ROXY_INVENTORY_PATH . 'includes/class-roxy-inventory-scheduler.php';
require_once ROXY_INVENTORY_PATH . 'includes/class-roxy-inventory-vendor-map.php';
require_once ROXY_INVENTORY_PATH . 'includes/class-roxy-inventory-admin.php';

add_action('plugins_loaded', function () {
    Settings::ensure_defaults();
    Store::maybe_upgrade_schema();
    Settings::init();
    Scheduler::init();
    Admin::init();
    Scheduler::ensure_schedule();
});
