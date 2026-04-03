<?php
/**
 * Plugin Name: Roxy Show Tickets (WooCommerce)
 * Description: Show-specific ticketing with per-showing hidden products (avoids cart collisions), capacity controls, and subscriber tickets per show (based on active subscriptions).
 * Version: 0.2.10.52
 * Author: Newport Roxy (AI Team)
 * Update URI: https://github.com/Tototex/roxy-show-tickets
 */

if (!defined('ABSPATH')) exit;

// Constants (ROXY_ST_VER, ROXY_ST_PATH, ROXY_ST_URL, etc.) are defined in
// roxy-suite.php before this file is required. Includes load here so that
// relative paths resolve correctly for the module directory.
require_once ROXY_ST_PATH . 'includes/class-roxy-st-cpt.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-log.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-settings.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-sales.php';
require_once ROXY_ST_PATH . 'includes/lib/psyon/qrcode.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-tickets.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-products.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-capacity.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-frontend.php';

add_action('plugins_loaded', function () {
  if (!class_exists('WooCommerce')) return;

  \RoxyST\Settings::init();
  \RoxyST\Sales::init();
  \RoxyST\Tickets::init();
  \RoxyST\CPT::init();
  \RoxyST\Products::init();
  \RoxyST\Capacity::init();
  \RoxyST\Frontend::init();
});
