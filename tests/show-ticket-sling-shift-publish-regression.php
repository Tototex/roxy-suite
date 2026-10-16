<?php
/** Isolated Sling publication-state regression with stubbed API functions and in-memory post metadata. */
define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
define('MINUTE_IN_SECONDS', 60);
eval('namespace RoxyST; final class CPT { const POST_TYPE = "roxy_showing"; } final class Events { const POST_TYPE = "roxy_event"; const META_SHOWINGS = "_showings"; }');

class WP_Error {
  private $message;
  public function __construct($code = '', $message = '') { $this->message = $message; }
  public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function current_user_can($capability, $post_id = null) { return true; }
function check_admin_referer($action) { return true; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim((string) $value); }
function absint($value) { return abs((int) $value); }
function wp_timezone() { return new DateTimeZone('America/Los_Angeles'); }
function get_post_type($post_id) { return 'roxy_showing'; }
function get_the_title($post_id) { return 'Fixture showing'; }
function get_post_meta($post_id, $key, $single = true) { return $GLOBALS['sling_test_meta'][$post_id][$key] ?? ''; }
function update_post_meta($post_id, $key, $value) { $GLOBALS['sling_test_meta'][$post_id][$key] = $value; return true; }
function delete_post_meta($post_id, $key) { unset($GLOBALS['sling_test_meta'][$post_id][$key]); return true; }
function wp_generate_password($length, $special = false, $extra = false) { return 'fixturekey123'; }
function set_transient($key, $value, $expiration) { return true; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?fixture=1'; }
function wp_safe_redirect($url) { throw new RuntimeException('fixture_redirect'); }
function wp_die($message = '') { throw new RuntimeException((string) $message); }
function roxy_eb_get_settings() { return $GLOBALS['sling_test_settings']; }
function roxy_eb_sling_get_mapping() { return ['position_private_show_id' => 1, 'position_concessionist_id' => 2, 'location_id' => 3]; }
function roxy_eb_sling_api_create_shift($payload, $context) { ++$GLOBALS['sling_test_created']; return 'fixture-shift-' . $GLOBALS['sling_test_created']; }
function roxy_eb_sling_api_publish_shift($shift_id, $context) { $GLOBALS['sling_test_published'][] = $shift_id; return true; }

require __DIR__ . '/../includes/modules/show-tickets/includes/class-roxy-st-sling-shifts.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) throw new RuntimeException('FAIL: ' . $message);
  ++$checks;
  echo 'PASS: ' . $message . PHP_EOL;
};

$assert(\RoxyST\SlingShifts::publish_immediately([]) === false,
  'default/off setting does not publish immediately');
$assert(\RoxyST\SlingShifts::publish_immediately(['sling_publish_shifts' => 0]) === false,
  'explicitly disabled setting does not publish immediately');
$assert(\RoxyST\SlingShifts::publish_immediately(['sling_publish_shifts' => 1]) === true,
  'enabled setting requests immediate publication');

$GLOBALS['sling_test_created'] = 0;
$GLOBALS['sling_test_published'] = [];
$GLOBALS['sling_test_meta'] = [];
$run_create = static function (array $settings): void {
  $GLOBALS['sling_test_settings'] = array_merge(['sling_mode' => 'direct'], $settings);
  $_POST = [
    'scope' => 'showing', 'post_id' => 10, 'employee_count' => 1,
    'showing_ids' => [10], 'shift_starts' => ['2035-10-30T13:30'],
    'shift_ends' => ['2035-10-30T19:00'], 'showing_selected' => ['1'],
  ];
  try { \RoxyST\SlingShifts::handle_create(); }
  catch (RuntimeException $error) { if ($error->getMessage() === 'fixture_redirect') return; throw $error; }
  throw new RuntimeException('FAIL: expected the normal post-action redirect');
};

$run_create([]);
$saved_shifts = $GLOBALS['sling_test_meta'][10][\RoxyST\SlingShifts::META_SHIFT_IDS] ?? [];
$assert(count($saved_shifts) === 1 && $saved_shifts['1']['published'] === false,
  'new shifts remain marked unpublished when automatic publication is disabled');
$assert(count($GLOBALS['sling_test_published']) === 0,
  'disabled setting makes no publication API request');

$GLOBALS['sling_test_meta'] = [];
$run_create(['sling_publish_shifts' => 1]);
$saved_shifts = $GLOBALS['sling_test_meta'][10][\RoxyST\SlingShifts::META_SHIFT_IDS] ?? [];
$assert(count($saved_shifts) === 1 && $saved_shifts['1']['published'] === true,
  'a successfully published new shift is marked published');
$assert(count($GLOBALS['sling_test_published']) === 1,
  'enabled setting publishes the created shift exactly once');

$GLOBALS['sling_test_meta'][10][\RoxyST\SlingShifts::META_SHIFT_IDS] = [
  '1' => ['shift_id' => 'existing-shift', 'role' => 'private_show', 'published' => false],
];
$run_create(['sling_publish_shifts' => 1]);
$saved_shifts = $GLOBALS['sling_test_meta'][10][\RoxyST\SlingShifts::META_SHIFT_IDS] ?? [];
$assert($saved_shifts['1']['published'] === true && end($GLOBALS['sling_test_published']) === 'existing-shift',
  'an existing unpublished shift is published when the setting is enabled later');

echo 'Passed ' . $checks . ' Sling shift publication checks.' . PHP_EOL;
