<?php
declare(strict_types=1);

namespace {
  define('ABSPATH', __DIR__ . '/../');
  function wp_timezone_string(): string { return 'America/Los_Angeles'; }
  function get_option(string $key, $default = null) { return $key === 'admin_email' ? 'manager@example.test' : $default; }

  require_once __DIR__ . '/../includes/modules/grosses/includes/class-roxy-grosses-settings.php';

  $defaults = \RoxyGrosses\Settings::defaults();
  if (($defaults['cashflow_woo_gateways'] ?? null) !== "stripe\npaypal") {
    throw new RuntimeException('Cashflow defaults must include the verified active Stripe and PayPal Standard gateway IDs.');
  }

  echo "OK: Cashflow defaults include Stripe and PayPal Standard\n";
}
