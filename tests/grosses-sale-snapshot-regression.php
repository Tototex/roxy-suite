<?php
namespace RoxyGrosses {
  final class Settings {
    public static string $timezone = 'America/Los_Angeles';
    public static string $environment = 'sandbox';
    public static string $locations = 'snapshot-location-a';
    public static function get_report_timezone(): string { return self::$timezone; }
    public static function get_all(): array { return ['square_environment'=>self::$environment,'square_location_ids'=>self::$locations]; }
    public static function square_access_token(): string { return 'fake-sale-snapshot-token'; }
    public static function line_list(string $value): array { return array_values(array_filter(array_map('trim', explode("\n", $value)), 'strlen')); }
  }
}

namespace {
  if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
  final class SaleSnapshotNetworkError extends \RuntimeException { public function get_error_message(): string { return $this->getMessage(); } }
  $GLOBALS['sale_snapshot_calls'] = [];
  $GLOBALS['sale_snapshot_responses'] = [];
  function wp_json_encode($value) { return json_encode($value); }
  function is_wp_error($value): bool { return $value instanceof SaleSnapshotNetworkError; }
  function wp_remote_retrieve_response_code($response): int { return (int) $response['status']; }
  function wp_remote_retrieve_body($response): string { return (string) $response['body']; }
  function wp_remote_post($url, $args) {
    $GLOBALS['sale_snapshot_calls'][] = [$url, $args];
    if (!$GLOBALS['sale_snapshot_responses']) throw new \LogicException('Unexpected Square network request.');
    return array_shift($GLOBALS['sale_snapshot_responses']);
  }
  function wp_remote_get($url, $args) { return wp_remote_post($url, $args); }
  function sale_snapshot_response(array $orders = [], int $status = 200): array { return ['status'=>$status,'body'=>json_encode(['orders'=>$orders])]; }
  function sale_snapshot_reset(array $responses): void { $GLOBALS['sale_snapshot_calls']=[]; $GLOBALS['sale_snapshot_responses']=$responses; }
  function sale_snapshot_check(bool $ok, string $label): void { if (!$ok) throw new \RuntimeException('FAIL: '.$label); echo 'PASS: '.$label."\n"; }
  function sale_snapshot_must_throw(callable $callback, string $label): void {
    try { $callback(); } catch (\RuntimeException $e) { sale_snapshot_check(true, $label); return; }
    throw new \RuntimeException('FAIL: expected exception: '.$label);
  }

  $candidate = $argv[1] ?? (dirname(__DIR__).'/includes/modules/grosses/includes/class-roxy-grosses-square.php');
  if (!is_file($candidate)) throw new \RuntimeException('Square candidate file missing: '.$candidate);
  require $candidate;
  $square = '\\RoxyGrosses\\Square';

  // The same date is fetched once, and nested managed operations share the outer snapshot.
  sale_snapshot_reset([sale_snapshot_response([['id'=>'sale-a','line_items'=>[['name'=>'original']]]])]);
  $result = $square::with_sale_snapshot(static function () use ($square) {
    $first = $square::fetch_orders_for_date('2026-10-03');
    $nested = $square::with_sale_snapshot(static fn() => $square::fetch_orders_for_date('2026-10-03'));
    return [$first, $nested];
  });
  sale_snapshot_check(count($GLOBALS['sale_snapshot_calls'])===1 && $result[0]===$result[1], 'same-date request is fetched once and nested scopes share snapshot');

  // Return-by-value arrays must not let a caller poison the immutable cached copy.
  sale_snapshot_reset([sale_snapshot_response([['id'=>'sale-copy','line_items'=>[['name'=>'before']]]])]);
  $square::with_sale_snapshot(static function () use ($square) {
    $mutated = $square::fetch_orders_for_date('2026-10-03');
    $mutated[0]['line_items'][0]['name'] = 'caller mutation';
    $again = $square::fetch_orders_for_date('2026-10-03');
    sale_snapshot_check($again[0]['line_items'][0]['name']==='before', 'caller mutation cannot alter cached order array');
  });
  sale_snapshot_check(count($GLOBALS['sale_snapshot_calls'])===1, 'mutation check used one provider request');

  // Empty success is still cached; distinct query dimensions do not collide.
  sale_snapshot_reset([sale_snapshot_response(), sale_snapshot_response([['id'=>'date-b']]), sale_snapshot_response([['id'=>'location-b']]), sale_snapshot_response([['id'=>'production']])]);
  $square::with_sale_snapshot(static function () use ($square) {
    $empty1 = $square::fetch_orders_for_date('2026-10-03');
    $empty2 = $square::fetch_orders_for_date('2026-10-03');
    sale_snapshot_check($empty1===[] && $empty2===[], 'legitimate empty result is cached');
    $date_b = $square::fetch_orders_for_date('2026-10-04');
    \RoxyGrosses\Settings::$locations = 'snapshot-location-b';
    $location_b = $square::fetch_orders_for_date('2026-10-03');
    \RoxyGrosses\Settings::$environment = 'production';
    $production = $square::fetch_orders_for_date('2026-10-03');
    sale_snapshot_check($date_b[0]['id']==='date-b' && $location_b[0]['id']==='location-b' && $production[0]['id']==='production', 'date, location, and environment produce separate snapshot entries');
  });
  sale_snapshot_check(count($GLOBALS['sale_snapshot_calls'])===4, 'only distinct date/location/environment combinations trigger requests');
  $urls = array_column($GLOBALS['sale_snapshot_calls'], 0);
  sale_snapshot_check(str_contains($urls[2], 'connect.squareupsandbox.com') && str_contains($urls[3], 'connect.squareup.com'), 'environment-specific requests use their matching Square endpoint');

  // A new outer operation always gets a fresh view; calls outside a scope are never retained.
  sale_snapshot_reset([sale_snapshot_response([['id'=>'scope-one']]), sale_snapshot_response([['id'=>'scope-two']]), sale_snapshot_response([['id'=>'outside-one']]), sale_snapshot_response([['id'=>'outside-two']])]);
  $one = $square::with_sale_snapshot(static fn() => $square::fetch_orders_for_date('2026-10-03'));
  $two = $square::with_sale_snapshot(static fn() => $square::fetch_orders_for_date('2026-10-03'));
  $outside_one = $square::fetch_orders_for_date('2026-10-03');
  $outside_two = $square::fetch_orders_for_date('2026-10-03');
  sale_snapshot_check($one[0]['id']==='scope-one' && $two[0]['id']==='scope-two' && $outside_one[0]['id']==='outside-one' && $outside_two[0]['id']==='outside-two' && count($GLOBALS['sale_snapshot_calls'])===4, 'separate operations and out-of-scope calls fetch fresh responses');

  // Exceptional exits must clear the outer snapshot and restore depth.
  sale_snapshot_reset([sale_snapshot_response([['id'=>'before-throw']]), sale_snapshot_response([['id'=>'after-throw']])]);
  sale_snapshot_must_throw(static function () use ($square) {
    $square::with_sale_snapshot(static function () use ($square) {
      $square::fetch_orders_for_date('2026-10-03');
      throw new \RuntimeException('operation failed');
    });
  }, 'operation exception propagates');
  $after_throw = $square::with_sale_snapshot(static fn() => $square::fetch_orders_for_date('2026-10-03'));
  sale_snapshot_check($after_throw[0]['id']==='after-throw' && count($GLOBALS['sale_snapshot_calls'])===2, 'exception cleanup clears cached data and nesting depth');

  // Failed retrievals are not cached, so a later attempt in the same scope can succeed.
  sale_snapshot_reset([new SaleSnapshotNetworkError('temporary network failure'), sale_snapshot_response([['id'=>'retry-success']])]);
  $retried = $square::with_sale_snapshot(static function () use ($square) {
    sale_snapshot_must_throw(static fn() => $square::fetch_orders_for_date('2026-10-03'), 'failed provider response propagates');
    return $square::fetch_orders_for_date('2026-10-03');
  });
  sale_snapshot_check($retried[0]['id']==='retry-success' && count($GLOBALS['sale_snapshot_calls'])===2, 'failed lookup is not cached and same-scope retry reaches provider');

  // Invalid calendar input remains rejected before network I/O, including within a scope.
  sale_snapshot_reset([]);
  $square::with_sale_snapshot(static function () use ($square) {
    sale_snapshot_must_throw(static fn() => $square::fetch_orders_for_date('2026-02-30'), 'invalid calendar date remains rejected');
  });
  sale_snapshot_check($GLOBALS['sale_snapshot_calls']===[], 'invalid date fails before any Square request');
}
