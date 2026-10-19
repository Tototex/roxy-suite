<?php
// Unknown order-storage state must never fall through to the legacy order-table query.
define('ABSPATH', __DIR__);
define('ROXY_ST_META_SHOWING_ID', '_roxy_showing_id');

function check($condition, string $label): void {
  if (!$condition) throw new RuntimeException($label);
  echo "PASS: $label\n";
}

function expect_storage_failure(string $label): void {
  try {
    \RoxyST\Reservations::quantity_for_showing(123);
  } catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'order storage could not be verified'), $label);
    return;
  }
  throw new RuntimeException($label);
}

require dirname(__DIR__) . '/includes/modules/show-tickets/includes/class-roxy-st-reservations.php';
$GLOBALS['wpdb'] = (object) ['last_error' => ''];
expect_storage_failure('missing WooCommerce storage detector fails closed');

eval('namespace Automattic\\WooCommerce\\Utilities; final class OrderUtil {
  public static $result = null;
  public static $throws = false;
  public static function custom_orders_table_usage_is_enabled() {
    if (self::$throws) throw new \\RuntimeException("fixture failure");
    return self::$result;
  }
}');

\Automattic\WooCommerce\Utilities\OrderUtil::$result = null;
expect_storage_failure('null WooCommerce storage result fails closed');
\Automattic\WooCommerce\Utilities\OrderUtil::$result = '0';
expect_storage_failure('string WooCommerce storage result fails closed');
\Automattic\WooCommerce\Utilities\OrderUtil::$throws = true;
expect_storage_failure('throwing WooCommerce storage detector fails closed');
