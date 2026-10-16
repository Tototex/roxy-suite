<?php
declare(strict_types=1);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__);

$namespace = 'RoxyGrossesNoSalesFixture';
$GLOBALS['grosses_no_sales_options'] = [];
$GLOBALS['grosses_no_sales_checks'] = 0;

function update_option($key, $value, $autoload = null) {
  $GLOBALS['grosses_no_sales_options'][$key] = $value;
  return true;
}

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
  $GLOBALS['grosses_no_sales_checks']++;
  echo "PASS: {$message}\n";
};

eval('namespace ' . $namespace . ';
  class Settings { public static function get_report_timezone(): string { return "America/Los_Angeles"; } }
  class Reporter {
    public static array $sync = [];
    public static array $send = [];
    public static int $send_calls = 0;
    public static function sync_automatic_tables(string $date, string $mode): array { return self::$sync; }
    public static function send_report(string $date, string $mode): array { self::$send_calls++; return self::$send; }
  }
  class Store {
    public static array $logs = [];
    public static function insert_log(...$args): bool { self::$logs[] = $args; return true; }
  }
');

$source_path = dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-scheduler.php';
$source = file_get_contents($source_path);
if (!is_string($source) || $source === '') throw new RuntimeException('Could not load Grosses scheduler source.');
$source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $source);
eval('?>' . $source);

$scheduler = $namespace . '\\Scheduler';
$reporter = $namespace . '\\Reporter';
$store = $namespace . '\\Store';
$run_for_date = new ReflectionMethod($scheduler, 'run_for_date');
if (PHP_VERSION_ID < 80100) $run_for_date->setAccessible(true);
$invoke = static fn(string $date, string $mode, bool $mark_complete): array => $run_for_date->invoke(null, $date, $mode, $mark_complete);

$reporter::$sync = ['success' => true, 'movie_rows' => 0, 'movie_paid_rows' => 0, 'live_rows' => 0];
$reporter::$send_calls = 0;
$store::$logs = [];
$empty = $invoke('2038-07-15', 'scheduled-sync', true);
$check(!empty($empty['success']), 'verified zero-ticket day completes successfully');
$check($reporter::$send_calls === 0, 'zero-ticket day does not attempt a grosses email');
$check(($GLOBALS['grosses_no_sales_options']['roxy_grosses_last_auto_date'] ?? '') === '2038-07-15', 'zero-ticket day records its completed date');
$check(count($store::$logs) === 1 && $store::$logs[0][4] === true && str_contains($store::$logs[0][5], 'No paid movie ticket rows were found'), 'zero-ticket day is logged as a successful no-send result');

$reporter::$sync = ['success' => true, 'movie_rows' => 0, 'movie_paid_rows' => 0, 'live_rows' => 2];
$GLOBALS['grosses_no_sales_options'] = [];
$store::$logs = [];
$live_only = $invoke('2038-07-16', 'scheduled-sync', true);
$check(!empty($live_only['success']) && $reporter::$send_calls === 0, 'live-show-only day with no paid movie tickets does not enter movie email flow');
$check(($GLOBALS['grosses_no_sales_options']['roxy_grosses_last_auto_date'] ?? '') === '2038-07-16', 'live-show-only no-ticket day is marked complete after successful sync');

$reporter::$sync = ['success' => false, 'message' => 'simulated Square or storage failure'];
$GLOBALS['grosses_no_sales_options'] = [];
$store::$logs = [];
$failed_sync = $invoke('2038-07-17', 'scheduled-sync', true);
$check(empty($failed_sync['success']), 'actual sync failure remains a failure');
$check(!isset($GLOBALS['grosses_no_sales_options']['roxy_grosses_last_auto_date']), 'actual sync failure does not mark the report date complete');
$check($reporter::$send_calls === 0, 'actual sync failure does not enter the email flow');

$reporter::$sync = ['success' => true, 'movie_rows' => 1, 'movie_paid_rows' => 1, 'live_rows' => 0];
$reporter::$send = ['success' => false, 'message' => 'simulated report-send failure'];
$GLOBALS['grosses_no_sales_options'] = [];
$send_failure = $invoke('2038-07-18', 'scheduled-sync', true);
$check(empty($send_failure['success']) && $reporter::$send_calls === 1, 'paid-ticket day retains the report-send path and reports its failure');
$check(!isset($GLOBALS['grosses_no_sales_options']['roxy_grosses_last_auto_date']), 'failed paid-ticket report is not marked complete');

echo $GLOBALS['grosses_no_sales_checks'] . " Grosses scheduled no-sales checks passed. No email or production state was touched.\n";
