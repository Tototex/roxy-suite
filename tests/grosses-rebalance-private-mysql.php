<?php
/** WP-CLI fixture: actual Reporter/Store allocation against randomized private table copies. */
if (!defined('WP_CLI') || !WP_CLI) exit;
global $wpdb;

$store_path = $args[0] ?? '';
$reporter_path = $args[1] ?? '';
if (!is_file($store_path) || !is_file($reporter_path)) throw new RuntimeException('Pass candidate Store and Reporter paths.');
$suffix = bin2hex(random_bytes(5));
$namespace = 'RoxyGrossesRebalanceFixture' . $suffix;
$maps = [
  'roxy_grosses_entries' => 'roxy_fixture_' . $suffix . '_movie',
  'roxy_grosses_live_entries' => 'roxy_fixture_' . $suffix . '_live',
  'roxy_grosses_rental_entries' => 'roxy_fixture_' . $suffix . '_rental',
];
$owned_tables = [];
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $label);
  $count++;
  echo "PASS: {$label}\n";
};
$silenced = $wpdb->suppress_errors(true);
$digest = static function () use ($wpdb, $maps): array {
  $hashes = [];
  foreach (array_keys($maps) as $name) {
    $rows = $wpdb->get_results('SELECT * FROM `' . $wpdb->prefix . $name . '` ORDER BY id', ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($rows)) throw new RuntimeException('Original record digest failed.');
    $hashes[$name] = hash('sha256', json_encode($rows));
  }
  return $hashes;
};
$before = $digest();
$write_guard = static function ($sql) use ($wpdb, $maps) {
  if (preg_match('/^\s*(?:INSERT\s+INTO|UPDATE|REPLACE\s+INTO|DELETE\s+FROM|CREATE\s+TABLE|DROP\s+TABLE|ALTER\s+TABLE|TRUNCATE\s+TABLE)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $match)) {
    $allowed = array_map(static fn($name) => $wpdb->prefix . $name, array_values($maps));
    if (!in_array($match[1], $allowed, true)) throw new RuntimeException('Blocked write outside owned private tables.');
  }
  return $sql;
};
$http_guard = static fn() => new WP_Error('private_fixture', 'Network disabled in allocation fixture.');
$mail_guard = static function () { throw new RuntimeException('Email disabled in allocation fixture.'); };
add_filter('query', $write_guard, PHP_INT_MAX);
add_filter('pre_http_request', $http_guard, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX, 2);
try {
  foreach ($maps as $production_name => $fixture_name) {
    if (!preg_match('/^roxy_fixture_[a-f0-9]{10}_(movie|live|rental)$/', $fixture_name)) throw new RuntimeException('Unsafe fixture table name.');
    $fixture_table = $wpdb->prefix . $fixture_name;
    $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fixture_table));
    if ($existing) throw new RuntimeException('Randomized fixture table already exists; refusing to reuse it.');
    if ($wpdb->query('CREATE TABLE `' . $fixture_table . '` LIKE `' . $wpdb->prefix . $production_name . '`') === false) throw new RuntimeException('Could not create private schema copy.');
    $owned_tables[] = $fixture_table;
  }

  $store_source = file_get_contents($store_path);
  if (!is_string($store_source) || strpos($store_source, 'namespace RoxyGrosses;') === false) throw new RuntimeException('Unexpected Store source.');
  foreach ($maps as $production_name => $fixture_name) $store_source = str_replace("'{$production_name}'", "'{$fixture_name}'", $store_source);

  eval('namespace ' . $namespace . '; function post_type_exists(string $type): bool { return false; } class Settings { public static function get_report_timezone(): string { return "America/Los_Angeles"; } }'
    . ' class Metadata { public static function enrich_movie_row(array $row): array { return $row; } }'
    . ' class Square {
      public static int $depth=0; public static array $calls=[];
      public static function with_sale_snapshot(callable $fn) { self::$depth++; try{return $fn();}finally{self::$depth--;} }
      public static function fetch_orders_for_date(string $date): array {
        if(self::$depth<1) throw new \\RuntimeException("Snapshot scope missing.");
        self::$calls[]=$date;
        return [["closed_at"=>$date."T19:00:00-07:00","line_items"=>[["item_type"=>"ITEM","catalog_object_id"=>"snack","name"=>"Snack","quantity"=>"1","total_money"=>["amount"=>1001,"currency"=>"USD"]]]]];
      }
      public static function concession_reporting_categories(array $ids): array { return ["snack"=>true]; }
      public static function is_in_store_purchase_item(string $id,array $map=[]): bool { return $id==="snack"; }
    } class RefundSnapshot {}'
  );
  $store_source = preg_replace('/^<\?php\s*/', '', $store_source, 1);
  $store_source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $store_source);
  eval($store_source);
  $reporter_source = file_get_contents($reporter_path);
  if (!is_string($reporter_source) || strpos($reporter_source, 'namespace RoxyGrosses;') === false) throw new RuntimeException('Unexpected Reporter source.');
  $reporter_source = preg_replace('/^<\?php\s*/', '', $reporter_source, 1);
  $reporter_source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $reporter_source);
  eval($reporter_source);

  $store = '\\' . $namespace . '\\Store';
  $reporter = '\\' . $namespace . '\\Reporter';
  $date = '2038-05-01';
  $now = current_time('mysql');
  $seeds = [
    [$store::entries_table_name(), ['report_date'=>$date,'movie_title'=>'Fixture Movie','normalized_title'=>'fixture movie','show_time'=>'7:00 PM','general_qty'=>2,'total_tickets'=>2,'gross_total'=>24,'concessions_total'=>0,'is_locked'=>0]],
    [$store::live_entries_table_name(), ['report_date'=>$date,'show_title'=>'Fixture Live','normalized_title'=>'fixture live','show_time'=>'7:00 PM','presale_qty'=>1,'online_qty'=>1,'total_tickets'=>2,'gross_total'=>20,'concessions_total'=>0]],
    [$store::rental_entries_table_name(), ['report_date'=>$date,'rental_title'=>'Fixture Rental','normalized_title'=>'fixture rental','show_time'=>'7:00 PM','invoice_amount'=>80,'concessions_total'=>0]],
  ];
  $dates = [$date, '2038-05-02'];
  foreach ($dates as $seed_date) foreach ($seeds as [$table, $row]) {
    $row['report_date'] = $seed_date;
    $row['created_at'] = $now;
    $row['updated_at'] = $now;
    if ($wpdb->insert($table, $row) !== 1) throw new RuntimeException('Could not seed private fixture row.');
  }

  $method = new ReflectionMethod($reporter, 'rebalance_concessions_for_report_dates');
  $method->setAccessible(true);
  $result = $namespace . '\\Square';
  $result::with_sale_snapshot(static fn() => $method->invoke(null, [['report_date'=>$date],['report_date'=>$date]], $dates[1]));
  foreach ($dates as $read_date) {
    $sum_cents = 0;
  foreach ([$store::entries_table_name(), $store::live_entries_table_name(), $store::rental_entries_table_name()] as $table) {
    $row = $wpdb->get_row($wpdb->prepare("SELECT concessions_total FROM `{$table}` WHERE report_date=%s", $read_date), ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($row)) throw new RuntimeException('Could not read back private allocation.');
    $sum_cents += (int) round((float) $row['concessions_total'] * 100);
  }
    $check($sum_cents === 1001, 'actual Store writes conserve all 1001 cents on ' . $read_date);
  }
  $check(class_exists($reporter), 'candidate Reporter loaded under an isolated namespace');
  $check($result::$calls === $dates, 'unique sorted target/lookback dates served the actual allocation');
  $method = new ReflectionMethod($reporter, 'rebalance_concessions_for_date');
  $method->setAccessible(true);
  $fixture_concession_totals = static function () use ($wpdb, $store, $date): array {
    $totals = [];
    foreach ([$store::entries_table_name(), $store::live_entries_table_name(), $store::rental_entries_table_name()] as $table) {
      $rows = $wpdb->get_results($wpdb->prepare("SELECT id,concessions_total FROM `{$table}` WHERE report_date=%s ORDER BY id", $date), ARRAY_A);
      if ($wpdb->last_error !== '' || !is_array($rows)) throw new RuntimeException('Could not read private allocation rollback snapshot.');
      $totals[] = $rows;
    }
    return $totals;
  };
  $before_failed_allocation = $fixture_concession_totals();

  // Fail the second update after the first table has been changed in the open
  // transaction. The actual Reporter must roll the entire day back.
  $updates_seen = 0;
  $fault = static function (string $sql) use (&$updates_seen, $store): string {
    if (stripos(ltrim($sql), 'UPDATE ') === 0) {
      foreach ([$store::entries_table_name(), $store::live_entries_table_name(), $store::rental_entries_table_name()] as $table) {
        if (strpos($sql, $table) !== false) {
          $updates_seen++;
          if ($updates_seen === 2) return 'UPDATE `' . $table . '` SET';
          break;
        }
      }
    }
    return $sql;
  };
  add_filter('query', $fault);
  $thrown = false;
  try { $result::with_sale_snapshot(static fn() => $method->invoke(null, $date)); }
  catch (Throwable $error) { $thrown = strpos($error->getMessage(), 'Could not save the concessions allocation') !== false; }
  finally { remove_filter('query', $fault); }
  $check($updates_seen === 2 && $thrown && $fixture_concession_totals() === $before_failed_allocation, 'later real Store update failure rolls back an earlier private-table allocation write');
  $movie_table = $store::entries_table_name();
  if ($wpdb->query($wpdb->prepare("UPDATE `{$movie_table}` SET is_locked=1, concessions_total=99.33 WHERE report_date=%s", $date)) === false) throw new RuntimeException('Cannot set private protection fixture.');
  $protected_refresh_failed = false;
  try { $result::with_sale_snapshot(static fn() => $method->invoke(null, $date)); }
  catch (Throwable $error) { $protected_refresh_failed = strpos($error->getMessage(), 'Locked concessions exceed') !== false; }
  $protected = $wpdb->get_var($wpdb->prepare("SELECT concessions_total FROM `{$movie_table}` WHERE report_date=%s", $date));
  $check($protected_refresh_failed && $wpdb->last_error === '' && (string)$protected === '99.33', 'actual protected amount above the source total is reported and preserved');
  $check($before === $digest(), 'all original movie/live/rental records remain unchanged');
  echo "Passed {$count} actual private MySQL allocation checks; production tables were read only.\n";
} finally {
  if (isset($fault)) remove_filter('query', $fault);
  foreach ($owned_tables as $table) {
    if (strpos($table, $wpdb->prefix . 'roxy_fixture_' . $suffix . '_') !== 0) throw new RuntimeException('Refusing cleanup outside this fixture namespace.');
    if ($wpdb->query('DROP TABLE `' . $table . '`') === false) throw new RuntimeException('Private table cleanup failed.');
  }
  remove_filter('query', $write_guard, PHP_INT_MAX);
  remove_filter('pre_http_request', $http_guard, PHP_INT_MAX);
  remove_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX);
  $wpdb->suppress_errors($silenced);
}
