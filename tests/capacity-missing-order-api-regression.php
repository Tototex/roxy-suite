<?php
// Verify a missing Woo order API cannot be mistaken for an empty purchase history.
define('ABSPATH', __DIR__);
define('ROXY_ST_META_SHOWING_ID', '_roxy_showing_id');
define('ROXY_ST_META_TICKET_TYPE', '_roxy_ticket_type');
function get_current_user_id() { return 7; }
function wcs_get_users_subscriptions($user_id) {
  return [new class {
    public function has_status($status) { return $status === 'active'; }
    public function get_items() { return [new class { public function get_quantity() { return 1; } }]; }
  }];
}
class Roxy_Sub_Check { public static function walkup_quantity_for_showing($showing_id, $user_id = 0) { return 0; } }
require ($argv[1] ?? dirname(__DIR__)) . '/includes/modules/show-tickets/includes/class-roxy-st-capacity.php';
$check = static function ($condition, $label) {
  if (!$condition) throw new RuntimeException($label);
  echo "PASS: $label\n";
};
$check(\RoxyST\Capacity::purchased_subscriber_qty_for_showing_user(50, 7) === PHP_INT_MAX, 'missing Woo order query API is treated as unknown usage');
$check(\RoxyST\Capacity::subscriber_limit_remaining_for_showing(50, 7, false) === 0, 'unknown paid usage grants no subscriber entitlement');
echo "PASS: missing subscriber-order API regression\n";
