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
    public bool $social_table_read_fails = false;
    public bool $social_table_missing = false;
    public bool $social_failed_read_fails = false;
    public bool $social_overdue_read_fails = false;
    public $social_failed_count = '0';
    public $social_overdue_count = '0';
    public function prepare(string $sql, ...$args): string {
      foreach ($args as $arg) $sql = preg_replace('/%[sd]/', "'" . addslashes((string) $arg) . "'", $sql, 1);
      return $sql;
    }
    public function get_var(string $sql) {
      $this->last_error = '';
      if (stripos($sql, 'SHOW TABLES LIKE') === 0) {
        if (strpos($sql, 'roxy_social_posts') !== false) {
          if ($this->social_table_read_fails) { $this->last_error = 'simulated Social table lookup failure'; return null; }
          return $this->social_table_missing ? null : 'wp_roxy_social_posts';
        }
        if ($this->table_read_fails) { $this->last_error = 'simulated table lookup failure'; return null; }
        preg_match("/'((?:\\\\.|[^'])*)'/", $sql, $match);
        $table = stripslashes($match[1] ?? '');
        return in_array($table, ['wp_roxy_inventory_products','wp_roxy_inventory_vendors','wp_roxy_inventory_orders','wp_roxy_inventory_runs'], true) ? $table : null;
      }
      if (stripos($sql, 'SELECT created_at FROM wp_roxy_inventory_runs') === 0) {
        if ($this->success_read_fails) { $this->last_error = 'simulated success query failure'; return null; }
        return $this->success_at !== '' ? $this->success_at : null;
      }
      if (stripos($sql, "SELECT COUNT(*) FROM wp_roxy_social_posts WHERE status='failed'") === 0) {
        if ($this->social_failed_read_fails) { $this->last_error = 'simulated failed-job count error'; return null; }
        return $this->social_failed_count;
      }
      if (stripos($sql, 'SELECT COUNT(*) FROM wp_roxy_social_posts WHERE status IN') === 0) {
        if ($this->social_overdue_read_fails) { $this->last_error = 'simulated overdue-job count error'; return null; }
        return $this->social_overdue_count;
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
  function post_type_exists(string $type): bool { return true; }
  function wp_schedule_event(...$args) { $GLOBALS['health_freshness_side_effects']['scheduled']++; return true; }
  function wp_remote_get(...$args) { $GLOBALS['health_freshness_side_effects']['http']++; throw new \RuntimeException('HTTP forbidden in Health fixture.'); }
  function wp_mail(...$args) { $GLOBALS['health_freshness_side_effects']['mail']++; throw new \RuntimeException('Mail forbidden in Health fixture.'); }
  function wp_timezone(): \DateTimeZone { return new \DateTimeZone('UTC'); }
  function wp_date(string $format, ?int $timestamp = null, ?\DateTimeZone $timezone = null): string {
    return (new \DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? wp_timezone())->format($format);
  }
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
  $social_items = static fn(): array => $private('functional_social')->invoke(null);
  $social_structural = static fn(): array => $private('module_social_structural')->invoke(null);
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

  $wpdb->social_table_read_fails = false;
  $wpdb->social_table_missing = false;
  $wpdb->social_failed_read_fails = false;
  $wpdb->social_overdue_read_fails = false;
  $wpdb->social_failed_count = '0';
  $wpdb->social_overdue_count = '0';
  $social = $social_items();
  $check($inventory_item($social, 'Failed social jobs')['status'] === 'pass'
    && $inventory_item($social, 'Social jobs overdue by over an hour')['status'] === 'pass', 'valid zero Social counts remain healthy');
  $wpdb->social_failed_count = '2';
  $wpdb->social_overdue_count = 1;
  $social = $social_items();
  $check($inventory_item($social, 'Failed social jobs')['status'] === 'warn'
    && $inventory_item($social, 'Social jobs overdue by over an hour')['status'] === 'warn', 'real failed and overdue Social counts retain warning semantics');
  $wpdb->social_table_read_fails = true;
  $social = $social_items();
  $check(count($social) === 1 && $social[0]['label'] === 'Social job checks'
    && $social[0]['detail'] === 'Unavailable' && $social[0]['status'] === 'warn', 'Social table-existence SQL error is unavailable, not missing or healthy');
  $wpdb->social_table_read_fails = false;
  $wpdb->social_table_missing = true;
  $check($social_items() === [], 'missing Social table remains delegated to structural error');
  $structural = $social_structural();
  $table_item = null;
  foreach ($structural['items'] as $item) if ($item['label'] === 'wp_roxy_social_posts') $table_item = $item;
  $check(is_array($table_item) && $table_item['status'] === 'fail', 'missing Social table remains a structural error');
  $wpdb->social_table_missing = false;
  $wpdb->social_failed_read_fails = true;
  $social = $social_items();
  $check(count($social) === 2 && $inventory_item($social, 'Failed social jobs')['detail'] === 'Unavailable'
    && $inventory_item($social, 'Social jobs overdue by over an hour')['detail'] === 'Unavailable', 'failed-job count SQL error makes both Social counts unavailable');
  $wpdb->social_failed_read_fails = false;
  $wpdb->social_overdue_read_fails = true;
  $social = $social_items();
  $check(count($social) === 2 && $inventory_item($social, 'Failed social jobs')['status'] === 'warn'
    && $inventory_item($social, 'Social jobs overdue by over an hour')['detail'] === 'Unavailable', 'overdue count SQL error is not coerced to zero');
  $wpdb->social_overdue_read_fails = false;
  foreach ([null, '0junk', '-1', 1.5, '999999999999999999999999999'] as $invalid_count) {
    $wpdb->social_failed_count = $invalid_count;
    $social = $social_items();
    $check($inventory_item($social, 'Failed social jobs')['detail'] === 'Unavailable'
      && $inventory_item($social, 'Social jobs overdue by over an hour')['detail'] === 'Unavailable', 'malformed or incomplete Social count is unavailable');
  }

  $side_effects = $GLOBALS['health_freshness_side_effects'];
  $check($side_effects === ['http'=>0,'mail'=>0,'scheduled'=>0,'jobs'=>0], 'Health checks neither call HTTP/mail nor schedule or execute jobs');
  echo "Passed {$checks} isolated actual-Health freshness checks.\n";
}
