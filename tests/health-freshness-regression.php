<?php
namespace RoxyInventory {
  final class Scheduler {
    public static function run(): void { $GLOBALS['health_freshness_side_effects']['jobs']++; }
  }
  final class Store {
    public static ?array $latest = ['created_at' => '', 'status' => 'success'];
    public static bool $fail_latest = false;
    public static int $latest_calls = 0;
    public static function latest_run(string $type): ?array {
      self::$latest_calls++;
      if (self::$fail_latest) throw new \RuntimeException('simulated checked Store read failure');
      return self::$latest;
    }
  }
}

namespace {
  if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
  if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
  if (!defined('ROXY_EB_HEALTH_CHECK_HOOK')) define('ROXY_EB_HEALTH_CHECK_HOOK', 'roxy_eb_daily_health_check');

  final class HealthFreshnessFixtureWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public bool $table_read_fails = false;
    public bool $success_read_fails = false;
    public string $success_at = '';
    public function prepare(string $sql, ...$args): string {
      foreach ($args as $arg) $sql = preg_replace('/%[sd]/', "'" . addslashes((string) $arg) . "'", $sql, 1);
      return $sql;
    }
    public function get_var(string $sql) {
      $this->last_error = '';
      if (stripos($sql, 'SHOW TABLES LIKE') === 0) {
        if ($this->table_read_fails) { $this->last_error = 'simulated table lookup failure'; return null; }
        preg_match("/'((?:\\\\.|[^'])*)'/", $sql, $match);
        $table = stripslashes($match[1] ?? '');
        return in_array($table, ['wp_roxy_inventory_products','wp_roxy_inventory_vendors','wp_roxy_inventory_orders','wp_roxy_inventory_runs'], true) ? $table : null;
      }
      if (stripos($sql, 'SELECT created_at FROM wp_roxy_inventory_runs') === 0) {
        if ($this->success_read_fails) { $this->last_error = 'simulated success query failure'; return null; }
        return $this->success_at !== '' ? $this->success_at : null;
      }
      throw new \RuntimeException('Unexpected Health SQL: ' . $sql);
    }
  }

  $GLOBALS['wpdb'] = new HealthFreshnessFixtureWpdb();
  $GLOBALS['health_freshness_options'] = [];
  $GLOBALS['health_freshness_side_effects'] = ['http'=>0,'mail'=>0,'scheduled'=>0,'jobs'=>0];
  function roxy_suite_module_enabled(string $key): bool { return true; }
  function get_option(string $key, $default = false) {
    if ($key === 'roxy_inventory_settings') return ['schedule_enabled'=>'1'];
    return $GLOBALS['health_freshness_options'][$key] ?? $default;
  }
  function admin_url(string $path = ''): string { return 'https://fixture.invalid/' . $path; }
  function wp_next_scheduled(string $hook) { return time() + 300; }
  function wp_schedule_event(...$args) { $GLOBALS['health_freshness_side_effects']['scheduled']++; return true; }
  function wp_remote_get(...$args) { $GLOBALS['health_freshness_side_effects']['http']++; throw new \RuntimeException('HTTP forbidden in Health fixture.'); }
  function wp_mail(...$args) { $GLOBALS['health_freshness_side_effects']['mail']++; throw new \RuntimeException('Mail forbidden in Health fixture.'); }
  function wp_timezone(): \DateTimeZone { return new \DateTimeZone('UTC'); }
  function get_post($id) { return null; }
  function roxy_eb_get_settings(): array { return ['sling_mode'=>'disabled','booking_product_id'=>0]; }
  function roxy_eb_daily_health_check(): void { $GLOBALS['health_freshness_side_effects']['jobs']++; }

  $candidate = $argv[1] ?? (dirname(__DIR__) . '/includes/class-roxy-suite-health.php');
  if (!is_file($candidate)) throw new \RuntimeException('Candidate Health file not found: ' . $candidate);
  require_once $candidate;

  $checks = 0;
  $check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new \RuntimeException('FAIL: ' . $label);
    $checks++;
    echo "PASS: {$label}\n";
  };
  $private = static function (string $method): \ReflectionMethod {
    $reflection = new \ReflectionMethod(\RoxySuite\Health::class, $method);
    $reflection->setAccessible(true);
    return $reflection;
  };
  $event_item = static function () use ($private): array {
    $module = $private('module_event_booking_structural')->invoke(null);
    foreach ($module['items'] as $item) if ($item['label'] === 'Last daily booking monitor') return $item;
    throw new \RuntimeException('Event Booking freshness item missing.');
  };
  $inventory_items = static fn(): array => $private('functional_inventory')->invoke(null);
  $inventory_item = static function (array $items, string $label): array {
    foreach ($items as $item) if ($item['label'] === $label) return $item;
    throw new \RuntimeException('Inventory freshness item missing: ' . $label);
  };
  $stamp = static fn(int $delta): string => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(($delta < 0 ? '' : '+') . $delta . ' seconds')->format('Y-m-d H:i:s');

  \RoxyInventory\Store::$latest_calls = 0;
  $GLOBALS['health_freshness_options']['roxy_eb_health_last_result'] = null;
  $check($event_item()['status'] === 'warn', 'missing Event Booking monitor result warns');
  $GLOBALS['health_freshness_options']['roxy_eb_health_last_result'] = ['ok'=>true,'checked_at'=>$stamp(-7200),'errors'=>[]];
  $check($event_item()['status'] === 'pass', 'recent successful Event Booking monitor passes');
  $GLOBALS['health_freshness_options']['roxy_eb_health_last_result'] = ['ok'=>false,'checked_at'=>$stamp(-60),'errors'=>['page unavailable']];
  $check($event_item()['status'] === 'fail', 'recent failed Event Booking monitor is surfaced as failure');
  $GLOBALS['health_freshness_options']['roxy_eb_health_last_result'] = ['ok'=>true,'checked_at'=>$stamp(-40*3600),'errors'=>[]];
  $check($event_item()['status'] === 'warn', 'stale Event Booking monitor warns');
  $GLOBALS['health_freshness_options']['roxy_eb_health_last_result'] = ['ok'=>true,'checked_at'=>'2037-02-30 12:00:00','errors'=>[]];
  $check($event_item()['status'] === 'warn', 'invalid Event Booking timestamp warns');
  $GLOBALS['health_freshness_options']['roxy_eb_health_last_result'] = ['ok'=>true,'checked_at'=>$stamp(600),'errors'=>[]];
  $check($event_item()['status'] === 'warn', 'future Event Booking timestamp warns');
  $GLOBALS['health_freshness_options']['roxy_eb_health_last_result'] = ['ok'=>'yes','checked_at'=>$stamp(-60),'errors'=>[]];
  $check($event_item()['status'] === 'warn', 'malformed Event Booking outcome cannot become success');
  $GLOBALS['health_freshness_options']['roxy_eb_health_last_result'] = ['ok'=>true,'checked_at'=>$stamp(-60),'errors'=>['inconsistent outcome']];
  $check($event_item()['status'] === 'fail', 'stored errors cannot be hidden by success flag');

  $wpdb = $GLOBALS['wpdb'];
  $wpdb->success_at = $stamp(-1800);
  \RoxyInventory\Store::$latest = ['created_at'=>$wpdb->success_at,'status'=>'success'];
  \RoxyInventory\Store::$fail_latest = false;
  $items = $inventory_items();
  $check($inventory_item($items, 'Last inventory pull')['status'] === 'pass'
    && $inventory_item($items, 'Last successful inventory pull')['status'] === 'pass', 'Inventory reports checked recent successful history');
  \RoxyInventory\Store::$fail_latest = true;
  $items = $inventory_items();
  $check($inventory_item($items, 'Last inventory pull')['detail'] === 'Run history unavailable'
    && $inventory_item($items, 'Last successful inventory pull')['detail'] === 'Unavailable', 'checked Store read failure is not reported as empty history');
  \RoxyInventory\Store::$fail_latest = false;
  $wpdb->success_read_fails = true;
  $items = $inventory_items();
  $check($inventory_item($items, 'Last successful inventory pull')['detail'] === 'Unavailable', 'latest-success query error is surfaced as unavailable');
  $wpdb->success_read_fails = false;
  $wpdb->table_read_fails = true;
  $items = $inventory_items();
  $check($inventory_item($items, 'Last inventory pull')['detail'] === 'Run history unavailable', 'run-table existence query error is not mistaken for no table/history');
  $wpdb->table_read_fails = false;
  foreach ([$stamp(600), '2037-02-30 12:00:00'] as $bad_stamp) {
    $wpdb->success_at = $bad_stamp;
    $items = $inventory_items();
    $check($inventory_item($items, 'Last successful inventory pull')['status'] === 'warn', 'invalid/future Inventory success timestamp cannot appear fresh');
  }
  $wpdb->success_at = $stamp(-1800);
  \RoxyInventory\Store::$latest = ['status'=>'success'];
  $check($inventory_item($inventory_items(), 'Last inventory pull')['detail'] === 'Run history unavailable', 'incomplete latest-run record cannot appear healthy');

  $side_effects = $GLOBALS['health_freshness_side_effects'];
  $check($side_effects === ['http'=>0,'mail'=>0,'scheduled'=>0,'jobs'=>0], 'Health checks neither call HTTP/mail nor schedule or execute jobs');
  echo "Passed {$checks} isolated actual-Health freshness checks.\n";
}
