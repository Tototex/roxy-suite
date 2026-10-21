<?php
/** Private WordPress/Woo integration for daily Cashflow; providers and email are fully stubbed. */
if (!defined('WP_CLI') || !WP_CLI) exit;

$root = $args[0] ?? dirname(__DIR__);
$files = [
  'store' => $root . '/includes/modules/grosses/includes/class-roxy-grosses-store.php',
  'snapshot' => $root . '/includes/modules/grosses/includes/class-roxy-grosses-refund-snapshot.php',
  'report' => $root . '/includes/modules/grosses/includes/class-roxy-grosses-cashflow-report.php',
];
foreach ($files as $path) if (!is_file($path)) throw new RuntimeException('Cashflow integration candidate is missing.');
if (!function_exists('wc_get_orders') || !function_exists('wc_create_order') || !function_exists('wc_create_refund')) {
  throw new RuntimeException('WooCommerce CRUD is unavailable for this fixture.');
}

global $wpdb;
$prefix = (string) $wpdb->prefix;
$token = 'cashflow_' . bin2hex(random_bytes(6));
$table = $prefix . $token . '_refund_events';
if (!preg_match('/^' . preg_quote($prefix, '/') . preg_quote($token, '/') . '_refund_events$/D', $table)) throw new RuntimeException('Unsafe fixture table name.');
$table_q = chr(96) . $table . chr(96);
$namespace = 'RoxyGrossesCashflowFixture_' . bin2hex(random_bytes(4));
$checks = 0;
$assert = static function ($condition, string $message) use (&$checks): void {
  if (!$condition) throw new RuntimeException('FAIL: ' . $message);
  ++$checks;
  echo "PASS: {$message}\n";
};
$orders = [];
$refunds = [];
$table_created = false;
$mail_calls = 0;
$http_calls = 0;
$mail_filter = static function ($pre) use (&$mail_calls) { ++$mail_calls; return false; };
$http_filter = static function ($pre) use (&$http_calls) { ++$http_calls; return new WP_Error('fixture_http_blocked', 'External HTTP is blocked.'); };
add_filter('pre_wp_mail', $mail_filter, PHP_INT_MAX);
add_filter('pre_http_request', $http_filter, PHP_INT_MAX, 3);

try {
  if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) throw new RuntimeException('Refusing to reuse an existing table.');
  $ddl = "CREATE TABLE {$table_q} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, event_id VARCHAR(191) NOT NULL, refund_id VARCHAR(191) NOT NULL,
    payment_id VARCHAR(191) NOT NULL DEFAULT '', order_id VARCHAR(191) NOT NULL DEFAULT '',
    location_id VARCHAR(191) NOT NULL DEFAULT '', status VARCHAR(32) NOT NULL DEFAULT '',
    amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0, currency CHAR(3) NOT NULL DEFAULT '',
    event_created_at DATETIME NOT NULL, refund_created_at DATETIME NULL, refund_updated_at DATETIME NULL,
    payload_hash CHAR(64) NOT NULL, PRIMARY KEY (id), UNIQUE KEY event_id (event_id),
    KEY refund_id (refund_id), KEY status (status), KEY event_created_at (event_created_at)
  ) " . $wpdb->get_charset_collate();
  if ($wpdb->query($ddl) === false) throw new RuntimeException('Could not create private webhook fixture table.');
  $table_created = true;

  $store = file_get_contents($files['store']);
  if (!is_string($store)) throw new RuntimeException('Could not read Store candidate.');
  $store = preg_replace('/^<\?php\s*/', '', $store, 1);
  $store = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $store);
  $store = str_replace("public const REFUND_WEBHOOK_TABLE = 'roxy_grosses_refund_webhook_events';", "public const REFUND_WEBHOOK_TABLE = '{$token}_refund_events';", $store);
  if (strpos($store, "public const REFUND_WEBHOOK_TABLE = '{$token}_refund_events';") === false) throw new RuntimeException('Store did not resolve to the private webhook table.');

  eval('namespace ' . $namespace . ';
    final class Settings {
      public static function get_report_timezone(): string { return "America/Los_Angeles"; }
      public static function get(string $key, $default = "") { return ["cashflow_woo_gateways" => "paypal\nstripe"][$key] ?? $default; }
      public static function get_all(): array { return ["cashflow_woo_gateways" => "paypal\nstripe", "square_location_ids" => "fixture-location"]; }
      public static function line_list(string $value): array { return array_values(array_filter(array_map("trim", preg_split("/[\\\\r\\\\n,]+/", $value) ?: []), "strlen")); }
    }
    final class Square {
      public static array $payments = [];
      public static array $refunds = [];
      private static function in_window(array $rows, string $field, string $start, string $end): array {
        $from = new \\DateTimeImmutable($start); $until = new \\DateTimeImmutable($end);
        return array_values(array_filter($rows, static function (array $row) use ($field, $from, $until): bool {
          if (!is_string($row[$field] ?? null)) return false;
          try { $at = new \\DateTimeImmutable($row[$field]); } catch (\\Throwable $error) { return false; }
          return $at >= $from && $at < $until;
        }));
      }
      public static function list_payments_created_between(string $start, string $end, ?float $deadline = null): array { return self::in_window(self::$payments, "created_at", $start, $end); }
      public static function list_payment_refunds_updated_between(string $start, string $end, ?float $deadline = null): array {
        return array_values(array_filter(self::in_window(self::$refunds, "updated_at", $start, $end), static fn(array $row): bool => ($row["location_id"] ?? "") === "fixture-location"));
      }
      public static function fetch_orders_for_date(string $date): array { return []; }
    }
  ');
  eval($store);
  foreach (['snapshot', 'report'] as $name) {
    $source = file_get_contents($files[$name]);
    if (!is_string($source)) throw new RuntimeException('Could not read candidate ' . $name . '.');
    $source = preg_replace('/^<\?php\s*/', '', $source, 1);
    $source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $source);
    eval($source);
  }

  $make_order = static function (string $day, string $amount, string $gateway, string $tag) use (&$orders, $token): WC_Order {
    $order = wc_create_order(['status' => 'processing']);
    if (is_wp_error($order) || !$order instanceof WC_Order) throw new RuntimeException('Could not create synthetic Woo order.');
    $order->set_currency('USD');
    $order->set_payment_method($gateway);
    $order->set_transaction_id($token . '-' . $tag);
    $order->set_total($amount);
    $order->set_date_paid(new WC_DateTime($day . ' 16:00:00', new DateTimeZone('UTC')));
    $order->update_meta_data('_roxy_cashflow_fixture', $token);
    $order->save();
    $orders[] = $order->get_id();
    return $order;
  };

  $parent = $make_order('2026-10-20', '10.00', 'stripe', 'parent');
  $make_order('2026-10-21', '5.35', 'paypal', 'day');
  $refund = wc_create_refund([
    'amount' => '2.25', 'reason' => 'Private Cashflow fixture', 'order_id' => $parent->get_id(),
    'refund_payment' => false, 'restock_items' => false, 'line_items' => [],
  ]);
  if (is_wp_error($refund) || !$refund instanceof WC_Order_Refund) throw new RuntimeException('Could not create synthetic non-payment Woo refund.');
  $refund->set_date_created(new WC_DateTime('2026-10-21 17:00:00', new DateTimeZone('UTC')));
  // Mark this private record as API-processed without invoking any gateway.
  $refund->update_meta_data('_refunded_payment', true);
  $refund->update_meta_data('_roxy_cashflow_fixture', $token);
  $refund->save();
  $refunds[] = $refund->get_id();

  $square_refund = [
    'id' => $token . '-sq-refund', 'payment_id' => $token . '-sq-payment', 'location_id' => 'fixture-location',
    'status' => 'COMPLETED', 'amount_money' => ['amount' => 300, 'currency' => 'USD'],
    'updated_at' => '2026-10-21T18:00:00Z',
  ];
  $prefix_class = '\\' . $namespace . '\\';
  $square_class = $prefix_class . 'Square';
  $square_class::$payments = [
    ['id' => $token . '-sq-sale-20', 'location_id' => 'fixture-location', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 700, 'currency' => 'USD'], 'created_at' => '2026-10-20T16:00:00Z'],
    ['id' => $token . '-sq-sale-21', 'location_id' => 'fixture-location', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 1200, 'currency' => 'USD'], 'created_at' => '2026-10-21T16:00:00Z'],
  ];
  $square_class::$refunds = [$square_refund, [
    'id' => $token . '-foreign-refund', 'payment_id' => $token . '-foreign-payment', 'location_id' => 'not-configured',
    'status' => 'COMPLETED', 'amount_money' => ['amount' => 900, 'currency' => 'USD'], 'updated_at' => '2026-10-21T18:30:00Z',
  ]];
  $event_id = $token . '-event';
  $wpdb->insert($table, [
    'event_id' => $event_id, 'refund_id' => $square_refund['id'], 'payment_id' => $square_refund['payment_id'],
    'location_id' => 'fixture-location', 'status' => 'COMPLETED', 'amount_cents' => 300, 'currency' => 'USD',
    'event_created_at' => '2026-10-21 18:05:00', 'refund_updated_at' => '2026-10-21 18:00:00',
    'payload_hash' => hash('sha256', $event_id),
  ]);
  $foreign_id = $token . '-foreign-event';
  $wpdb->insert($table, [
    'event_id' => $foreign_id, 'refund_id' => $token . '-foreign-refund', 'payment_id' => $token . '-foreign-payment',
    'location_id' => 'not-configured', 'status' => 'COMPLETED', 'amount_cents' => 900, 'currency' => 'USD',
    'event_created_at' => '2026-10-21 18:30:00', 'refund_updated_at' => '2026-10-21 18:30:00',
    'payload_hash' => hash('sha256', $foreign_id),
  ]);
  if ($wpdb->last_error !== '') throw new RuntimeException('Could not seed private refund event rows.');

  $store_class = $prefix_class . 'Store';
  $report_class = $prefix_class . 'CashflowReport';
  // WooCommerce/plugin setup can consult an external service when creating
  // synthetic records. The guard remains active throughout; count only calls
  // made by the read-only Cashflow projection below.
  $http_calls = 0;
  $mail_calls = 0;
  $events = $store_class::completed_refund_events_created_between('2026-10-21 07:00:00', '2026-10-22 07:00:00', ['fixture-location']);
  $assert(count($events) === 1 && ($events[0]['event_id'] ?? '') === $event_id, 'actual Store SQL excludes refund events outside configured Square locations');
  $oct20 = $report_class::for_day('2026-10-20');
  $assert($oct20['totals']['square_collected_cents'] === 700 && $oct20['totals']['woocommerce_collected_cents'] === 1000, 'actual Woo date query and Square fixture allocate collections to Oct 20');
  $assert($oct20['totals']['square_refunded_cents'] === 0 && $oct20['totals']['woocommerce_refunded_cents'] === 0, 'later refunds are not included in the original sale-day Cashflow');
  $oct21 = $report_class::for_day('2026-10-21');
  $assert($oct21['totals']['square_collected_cents'] === 1200 && $oct21['totals']['woocommerce_collected_cents'] === 535, 'actual Woo date query includes only collections paid Oct 21');
  $assert($oct21['totals']['square_refunded_cents'] === 300 && $oct21['totals']['woocommerce_refunded_cents'] === 225, 'both provider refund types are projected on the refund day');
  $assert($oct21['totals']['net_cents'] === 1210
    && $oct21['refund_date_bases']['square'] === ['square_refund_completed_event']
    && $oct21['refund_date_bases']['woocommerce'] === ['woocommerce_refund_creation_proxy'], 'combined net and refund-date provenance are correct');
  $assert($http_calls === 0 && $mail_calls === 0, 'fixture performed no external HTTP or email');
} finally {
  remove_filter('pre_wp_mail', $mail_filter, PHP_INT_MAX);
  remove_filter('pre_http_request', $http_filter, PHP_INT_MAX);
  foreach (array_reverse($refunds) as $id) { $object = wc_get_order($id); if ($object) $object->delete(true); }
  foreach (array_reverse($orders) as $id) { $object = wc_get_order($id); if ($object) $object->delete(true); }
  if ($table_created) {
    if ($wpdb->query('DROP TABLE ' . $table_q) === false) throw new RuntimeException('Private Cashflow event table cleanup failed.');
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) throw new RuntimeException('Private Cashflow event table remains.');
  }
}

printf("%d private Cashflow integration checks passed\n", $checks);
