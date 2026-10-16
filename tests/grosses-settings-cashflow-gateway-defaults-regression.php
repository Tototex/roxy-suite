<?php
declare(strict_types=1);

namespace {
  define('ABSPATH', __DIR__ . '/../');
  $GLOBALS['settings_fixture'] = [];
  function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
  function sanitize_email(string $value): string { return trim($value); }
  function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
  function wc_format_decimal(string $value): string { return is_numeric($value) ? $value : '0'; }
  function wp_timezone_string(): string { return 'America/Los_Angeles'; }
  function wp_parse_args($args, $defaults = []): array { return array_merge($defaults, (array) $args); }
  function get_option(string $key, $default = null) {
    if ($key === \RoxyGrosses\Settings::OPTION_KEY) return $GLOBALS['settings_fixture'];
    return $key === 'admin_email' ? 'manager@example.test' : $default;
  }

  require_once __DIR__ . '/../includes/modules/grosses/includes/class-roxy-grosses-settings.php';

  $defaults = \RoxyGrosses\Settings::defaults();
  if (($defaults['cashflow_woo_gateways'] ?? null) !== "paypal\nstripe") {
    throw new RuntimeException('Cashflow defaults must include the actual approved enabled non-Square gateway IDs.');
  }

  $GLOBALS['settings_fixture'] = ['cashflow_woo_gateways' => ''];
  if (\RoxyGrosses\Settings::get('cashflow_woo_gateways') !== "paypal\nstripe") {
    throw new RuntimeException('A legacy blank default must migrate to the manager-approved non-Square gateways.');
  }

  $GLOBALS['settings_fixture'] = ['cashflow_woo_gateways' => "stripe\npaypal"];
  if (\RoxyGrosses\Settings::get('cashflow_woo_gateways') !== "paypal\nstripe") {
    throw new RuntimeException('The exact prior built-in gateway default must normalize to the actual gateway IDs.');
  }

  $GLOBALS['settings_fixture'] = ['cashflow_woo_gateways' => "paypal\nstripe\nstripe_blik\nstripe_link"];
  if (\RoxyGrosses\Settings::get('cashflow_woo_gateways') !== "paypal\nstripe") {
    throw new RuntimeException('Legacy pseudo-gateway defaults for Link/BLIK normalize to Stripe gateway ID.');
  }

  $GLOBALS['settings_fixture'] = ['cashflow_woo_gateways' => '', '_cashflow_woo_gateways_configured' => '1'];
  if (\RoxyGrosses\Settings::get('cashflow_woo_gateways') !== '') {
    throw new RuntimeException('An explicitly saved blank gateway allow-list must remain disabled.');
  }

  $GLOBALS['settings_fixture'] = ['cashflow_woo_gateways' => 'stripe'];
  if (\RoxyGrosses\Settings::get('cashflow_woo_gateways') !== 'stripe') {
    throw new RuntimeException('An existing nonblank gateway allow-list must be preserved.');
  }

  $GLOBALS['settings_fixture'] = ['cashflow_woo_gateways' => ''];
  $saved = \RoxyGrosses\Settings::sanitize(['cashflow_woo_gateways' => '']);
  $GLOBALS['settings_fixture'] = $saved;
  if (($saved['_cashflow_woo_gateways_configured'] ?? '') !== '1'
    || \RoxyGrosses\Settings::get('cashflow_woo_gateways') !== '') {
    throw new RuntimeException('Saving an empty allow-list must retain the explicit opt-out marker.');
  }

  echo "OK: Cashflow gateway defaults, legacy migration, and explicit opt-out\n";
}

namespace RoxyGrosses {
  final class Scheduler {
    public static function sync_schedule(array $settings): void {}
  }
}
