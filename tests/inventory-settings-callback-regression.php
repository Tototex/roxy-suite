<?php
/** Isolated tests for the Inventory Settings API's registered sanitizer. */
if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
$candidate = $argv[1] ?? dirname(__DIR__) . '/includes/modules/inventory/includes/class-roxy-inventory-settings.php';
if (!is_file($candidate)) throw new RuntimeException('Settings candidate missing: ' . $candidate);
$GLOBALS['inventory_settings_option'] = null;
$GLOBALS['inventory_settings_errors'] = [];
$GLOBALS['inventory_registered_setting'] = null;

function get_option($key, $default = false) {
    if ($key === 'admin_email') return 'admin@example.test';
    return $GLOBALS['inventory_settings_option'] ?? $default;
}
function add_option($key, $value): bool { $GLOBALS['inventory_settings_option'] = $value; return true; }
function register_setting($group, $name, $args): void { $GLOBALS['inventory_registered_setting'] = $args; }
function add_action($hook, $callback): void {}
function add_settings_error($setting, $code, $message, $type = 'error'): void { $GLOBALS['inventory_settings_errors'][] = compact('setting','code','message','type'); }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value): string { return trim((string) $value); }
function sanitize_email($value): string { return trim((string) $value); }
function is_email($value): bool { return filter_var($value, FILTER_VALIDATE_EMAIL) !== false; }
function wp_unslash($value) { return $value; }
function wp_parse_args($args, $defaults = []): array { return array_merge($defaults, is_array($args) ? $args : []); }

require $candidate;
\RoxyInventory\Settings::register();
$callback = $GLOBALS['inventory_registered_setting']['sanitize_callback'] ?? null;
if (!is_callable($callback)) throw new RuntimeException('Registered sanitizer callback missing.');
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    ++$checks;
};
$form = static fn(): array => [
    'jason_email' => 'manager@example.test',
    'timezone' => 'America/Los_Angeles',
    'schedule_time' => '23:00',
    'tripp_order_instructions' => 'Tripp instructions',
    'odom_order_instructions' => 'Odom instructions',
];
$saved = ['jason_email'=>'old@example.test','timezone'=>'America/New_York','partial'=>'must remain'];
$invalid = [
    'non-array' => 'truncated',
    'empty' => [],
    'missing-tail' => array_diff_key($form(), ['odom_order_instructions'=>true]),
    'array-manager' => array_replace($form(), ['jason_email'=>['manager@example.test']]),
    'array-instructions' => array_replace($form(), ['tripp_order_instructions'=>['bad']]),
    'bad-manager-email' => array_replace($form(), ['jason_email'=>'not-an-email']),
    'bad-alert-email' => array_replace($form(), ['admin_alert_email'=>'not-an-email']),
    'array-alert-email' => array_replace($form(), ['admin_alert_email'=>['alerts@example.test']]),
    'bad-timezone' => array_replace($form(), ['timezone'=>'Not/A_Zone']),
    'offset-not-iana' => array_replace($form(), ['timezone'=>'+05:00']),
    'bad-time' => array_replace($form(), ['schedule_time'=>'25:90']),
    'bad-checkbox' => array_replace($form(), ['schedule_enabled'=>'yes']),
    'array-checkbox' => array_replace($form(), ['direct_vendor_sending_enabled'=>['1']]),
];
foreach ($invalid as $label => $input) {
    $GLOBALS['inventory_settings_option'] = $saved;
    $before = count($GLOBALS['inventory_settings_errors']);
    $result = $callback($input);
    $check($result === $saved, $label . ' returns the existing raw option unchanged');
    $check(count($GLOBALS['inventory_settings_errors']) === $before + 1, $label . ' adds a Settings API error');
}

$GLOBALS['inventory_settings_option'] = ['admin_alert_email'=>'alerts@example.test'];
$unchecked = $callback($form());
$check($unchecked['schedule_enabled'] === '0' && $unchecked['direct_vendor_sending_enabled'] === '0', 'omitted checkboxes normalize to off');
$check($unchecked['admin_alert_email'] === 'alerts@example.test', 'omitted optional alert email preserves current value');
$checked = $callback(array_replace($form(), ['schedule_enabled'=>'1','direct_vendor_sending_enabled'=>'1']));
$check($checked['schedule_enabled'] === '1' && $checked['direct_vendor_sending_enabled'] === '1', 'explicit on checkboxes are accepted');
$check($callback($checked) === $checked, 'complete normalized option is idempotent when sanitized again');

$GLOBALS['inventory_settings_option'] = null;
$fallback = $callback([]);
$check($fallback === \RoxyInventory\Settings::defaults(), 'invalid input with no saved option returns defaults');
echo "Passed {$checks} Inventory registered-settings callback checks.\n";
