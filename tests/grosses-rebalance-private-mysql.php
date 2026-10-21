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
$cron_guard = static fn($pre, $event = null, $wp_error = false) => true;
add_filter('query', $write_guard, PHP_INT_MAX);
add_filter('pre_http_request', $http_guard, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX, 2);
add_filter('pre_schedule_event', $cron_guard, PHP_INT_MAX, 3);
try {
  foreach ($maps as $production_name => $fixture_name) {
    if (!preg_match('/^roxy_fixture_[a-f0-9]{10}_(movie|live|rental)$/', $fixture_name)) throw new RuntimeException('Unsafe fixture table name.');
    $fixture_table = $wpdb->prefix . $fixture_name;
    $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fixture_table));
    if ($existing) throw new RuntimeException('Randomized fixture table already exists; refusing to reuse it.');
    if ($wpdb->query('CREATE TABLE `' . $fixture_table . '` LIKE `' . $wpdb->prefix . $production_name . '`') === false) throw new RuntimeException('Could not create private schema copy.');
    $owned_tables[] = $fixture_table;
  }
  // The live/rental lock columns are added by the normal schema upgrade path,
  // not by CREATE TABLE on fresh installs. Mirror that upgrade privately.
  foreach ([$wpdb->prefix . $maps['roxy_grosses_live_entries'], $wpdb->prefix . $maps['roxy_grosses_rental_entries']] as $fixture_table) {
    $has_lock = $wpdb->get_var("SHOW COLUMNS FROM `{$fixture_table}` LIKE 'is_locked'");
    if ($wpdb->last_error !== '') throw new RuntimeException('Could not inspect private lock-column schema.');
    if (!$has_lock && $wpdb->query("ALTER TABLE `{$fixture_table}` ADD is_locked TINYINT(1) NOT NULL DEFAULT 0") === false) throw new RuntimeException('Could not prepare private lock-column schema.');
  }

  $store_source = file_get_contents($store_path);
  if (!is_string($store_source) || strpos($store_source, 'namespace RoxyGrosses;') === false) throw new RuntimeException('Unexpected Store source.');
  foreach ($maps as $production_name => $fixture_name) $store_source = str_replace("'{$production_name}'", "'{$fixture_name}'", $store_source);

  eval('namespace ' . $namespace . '; function post_type_exists(string $type): bool { return false; } class Settings { public static function get_report_timezone(): string { return "America/Los_Angeles"; } }'
    . ' class Metadata { public static function enrich_movie_row(array $row, bool $force = false, bool $allow_remote = true): array { return $row; } public static function metadata_for_movie(string $title, int $year = 0, bool $force = false): array { return ["studio"=>"", "genre"=>""]; } }'
    . ' class Square {
      public static int $depth=0; public static array $calls=[]; public static array $order_fixtures=[];
      public static function with_sale_snapshot(callable $fn) { self::$depth++; try{return $fn();}finally{self::$depth--;} }
      public static function fetch_orders_for_date(string $date): array {
        if(self::$depth<1) throw new \\RuntimeException("Snapshot scope missing.");
        self::$calls[]=$date;
        if(array_key_exists($date,self::$order_fixtures)) return self::$order_fixtures[$date];
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

  $rental_table = $store::rental_entries_table_name();
  $overnight = ['report_date'=>'2038-05-04','rental_title'=>'Overnight Fixture Rental','normalized_title'=>'overnight fixture rental',
    'show_time'=>'11:00 PM','reserved_start_at'=>'2038-05-04 11:00:00','reserved_end_at'=>'2038-05-05 00:30:00',
    'invoice_amount'=>80,'concessions_total'=>0,'created_at'=>$now,'updated_at'=>$now];
  if ($wpdb->insert($rental_table, $overnight) !== 1) throw new RuntimeException('Could not seed private overnight rental fixture.');
  $overlapping_rentals = $store::list_all_entries_for_rebalance('rental', '2038-05-05');
  $overnight_found = false;
  foreach ($overlapping_rentals as $rental) if ((string) ($rental['rental_title'] ?? '') === 'Overnight Fixture Rental') $overnight_found = true;
  $check($overnight_found, 'rental allocation lookup on May 5 includes prior-day booking windows crossing midnight');

  $method = new ReflectionMethod($reporter, 'rebalance_concessions_for_report_dates');
  if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
  $result = $namespace . '\\Square';
  try {
    $result::with_sale_snapshot(static fn() => $method->invoke(null, [['report_date'=>$date],['report_date'=>$date]], $dates[1]));
  } catch (Throwable $error) {
    throw new RuntimeException('Initial private allocation failed: ' . $error->getMessage() . ' Database detail: ' . $wpdb->last_error . ' Query: ' . $wpdb->last_query, 0, $error);
  }
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
  if (PHP_VERSION_ID < 80100) $method->setAccessible(true);

  $overnight_date = '2038-05-05';
  $result::$order_fixtures[$overnight_date] = [[
    'closed_at'=>$overnight_date.'T00:10:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>2514,'currency'=>'USD']]],
  ]];
  $result::with_sale_snapshot(static fn() => $method->invoke(null, $overnight_date));
  $overnight_cents = (int) round((float) $wpdb->get_var($wpdb->prepare(
    "SELECT concessions_total FROM `{$rental_table}` WHERE rental_title=%s", 'Overnight Fixture Rental'
  )) * 100);
  $check($overnight_cents === 2514, '12:10 AM Square concessions are attributed to prior-night rental ending at 12:30 AM');
  unset($result::$order_fixtures[$overnight_date]);

  // Exercise the real Store and Reporter with more than 1,000 editable rows
  // and 100 Square lines, while keeping persistence in bounded multi-row writes.
  $large_date = '2038-05-03';
  $movie_table = $store::entries_table_name();
  for ($i = 0; $i < 1001; $i++) {
    $title = 'Batch Fixture ' . $i;
    $row = ['report_date'=>$large_date,'movie_title'=>$title,'normalized_title'=>strtolower($title),'show_time'=>'7:00 PM','general_qty'=>1,'total_tickets'=>1,'gross_total'=>12,'concessions_total'=>0,'is_locked'=>0,'created_at'=>$now,'updated_at'=>$now];
    if ($wpdb->insert($movie_table, $row) !== 1) throw new RuntimeException('Could not seed large private allocation fixture.');
  }
  $large_orders = [];
  for ($i = 0; $i < 100; $i++) $large_orders[] = [
    'id'=>'large-fixture-order-' . $i,
    'closed_at'=>$large_date.'T19:00:00-07:00',
    'line_items'=>[['uid'=>'large-fixture-line-' . $i,'item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1001,'currency'=>'USD']]],
  ];
  $result::$order_fixtures[$large_date] = $large_orders;
  $batch_updates = 0;
  $count_batch_updates = static function (string $sql) use (&$batch_updates, $movie_table): string {
    if (stripos(ltrim($sql), 'UPDATE ') === 0 && strpos($sql, $movie_table) !== false) $batch_updates++;
    return $sql;
  };
  add_filter('query', $count_batch_updates);
  try { $result::with_sale_snapshot(static fn() => $method->invoke(null, $large_date)); }
  finally { remove_filter('query', $count_batch_updates); }
  $large_cents = (int) $wpdb->get_var($wpdb->prepare("SELECT ROUND(SUM(concessions_total)*100) FROM `{$movie_table}` WHERE report_date=%s", $large_date));
  $check($batch_updates === 6 && $large_cents === 100100, 'real Store allocates 100 Square lines across 1,001 rows in six bounded write batches and conserves every cent');
  unset($result::$order_fixtures[$large_date]);

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
  $result::$order_fixtures[$date] = [[
    'closed_at'=>$date.'T19:00:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1299,'currency'=>'USD']]],
  ]];

  // Fail the second update after the first table has been changed in the open
  // transaction. The actual Reporter must roll the entire day back.
  $updates_seen = 0;
  $first_write_changed = false;
  $fault = static function (string $sql) use (&$updates_seen, &$first_write_changed, $store, $fixture_concession_totals, $before_failed_allocation): string {
    if (stripos(ltrim($sql), 'UPDATE ') === 0) {
      foreach ([$store::entries_table_name(), $store::live_entries_table_name(), $store::rental_entries_table_name()] as $table) {
        if (strpos($sql, $table) !== false) {
          $updates_seen++;
          if ($updates_seen === 2) {
            $first_write_changed = $fixture_concession_totals() !== $before_failed_allocation;
            return 'UPDATE `' . $table . '` SET';
          }
          break;
        }
      }
    }
    return $sql;
  };
  add_filter('query', $fault);
  $thrown = false;
  try { $result::with_sale_snapshot(static fn() => $method->invoke(null, $date)); }
  catch (Throwable $error) {
    $thrown = strpos($error->getMessage(), 'Could not save the concessions allocation') !== false;
    if ($thrown && $wpdb->last_error !== '') throw new RuntimeException('Expected injected update failure; database detail: ' . $wpdb->last_error . ' Query: ' . $wpdb->last_query, 0, $error);
  }
  finally { remove_filter('query', $fault); }
  $check($updates_seen === 2 && $first_write_changed && $thrown && $fixture_concession_totals() === $before_failed_allocation, 'later real Store update failure rolls back an earlier changed private-table allocation write');
  unset($result::$order_fixtures[$date]);
  $movie_table = $store::entries_table_name();
  if ($wpdb->query($wpdb->prepare("UPDATE `{$movie_table}` SET is_locked=1, concessions_total=99.33 WHERE report_date=%s", $date)) === false) throw new RuntimeException('Cannot set private protection fixture.');
  $protected_refresh_failed = false;
  try { $result::with_sale_snapshot(static fn() => $method->invoke(null, $date)); }
  catch (Throwable $error) { $protected_refresh_failed = strpos($error->getMessage(), 'cannot be reconciled to their matching Square lines') !== false; }
  $protected = $wpdb->get_var($wpdb->prepare("SELECT concessions_total FROM `{$movie_table}` WHERE report_date=%s", $date));
  $check($protected_refresh_failed && $wpdb->last_error === '' && (string)$protected === '99.33', 'actual protected amount above the source total is reported and preserved');

  $apply_method = new ReflectionMethod($reporter, 'apply_concessions_to_reports');
  if (PHP_VERSION_ID < 80100) $apply_method->setAccessible(true);
  $fixture_square = $result;
  $la = new DateTimeZone('America/Los_Angeles');
  $components = [
    101 => ['_entry_kind'=>'movie','_entry_id'=>101,'showing_id'=>1001,'_start_at'=>new DateTimeImmutable($date . ' 7:00 PM',$la),'show_time'=>'7:00 PM','general_qty'=>1,'concessions_total'=>5.00,'_is_locked'=>true],
    102 => ['_entry_kind'=>'live','_entry_id'=>102,'showing_id'=>1002,'_start_at'=>new DateTimeImmutable($date . ' 9:00 PM',$la),'show_time'=>'9:00 PM','online_qty'=>1,'concessions_total'=>0.00,'_is_locked'=>false],
  ];
  $fixture_square::$order_fixtures[$date] = [
    ['closed_at'=>$date.'T19:00:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>500,'currency'=>'USD']]]],
    ['closed_at'=>$date.'T21:00:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1000,'currency'=>'USD']]]],
  ];
  $fixture_square::with_sale_snapshot(static function () use ($apply_method,$date,&$components): void { $apply_method->invokeArgs(null,[&$components,$date]); });
  $check((float)$components[101]['concessions_total']===5.00 && (float)$components[102]['concessions_total']===10.00,
    'a protected show keeps its fixed cents while only the unrelated editable show receives its own source amount');

  $underfunded = $components;
  $underfunded[101]['concessions_total']=5.00;
  $underfunded[102]['concessions_total']=0.00;
  $fixture_square::$order_fixtures[$date] = [[
    'closed_at'=>$date.'T19:00:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>300,'currency'=>'USD']]],
  ]];
  $underfunded_failed=false;
  try { $fixture_square::with_sale_snapshot(static function () use ($apply_method,$date,&$underfunded): void { $apply_method->invokeArgs(null,[&$underfunded,$date]); }); }
  catch (Throwable $error) { $underfunded_failed = strpos($error->getMessage(),'cannot be reconciled to their matching Square lines') !== false; }
  $check($underfunded_failed && (float)$underfunded[101]['concessions_total']===5.00 && (float)$underfunded[102]['concessions_total']===0.00,
    'an underfunded protected show fails without shifting its shortfall onto another show');

  $constrained = [
    201 => ['_entry_kind'=>'movie','_entry_id'=>201,'showing_id'=>2001,'_start_at'=>new DateTimeImmutable($date . ' 2:00 PM',$la),'show_time'=>'2:00 PM','general_qty'=>1,'concessions_total'=>25.00,'_is_locked'=>true],
    202 => ['_entry_kind'=>'live','_entry_id'=>202,'showing_id'=>2002,'_start_at'=>new DateTimeImmutable($date . ' 4:00 PM',$la),'show_time'=>'4:00 PM','online_qty'=>1,'concessions_total'=>0.00,'_is_locked'=>false],
  ];
  $fixture_square::$order_fixtures[$date] = [
    ['closed_at'=>$date.'T13:59:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1000,'currency'=>'USD']]]],
    ['closed_at'=>$date.'T15:00:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1000,'currency'=>'USD']]]],
    ['closed_at'=>$date.'T16:01:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1000,'currency'=>'USD']]]],
  ];
  $infeasible_failed=false;
  try { $fixture_square::with_sale_snapshot(static function () use ($apply_method,$date,&$constrained): void { $apply_method->invokeArgs(null,[&$constrained,$date]); }); }
  catch (Throwable $error) { $infeasible_failed = strpos($error->getMessage(),'cannot be reconciled to their matching Square lines') !== false; }
  $check($infeasible_failed && (float)$constrained[201]['concessions_total']===25.00 && (float)$constrained[202]['concessions_total']===0.00,
    'overlapping connected sales still fail when a locked row demands more than its individually eligible lines');

  foreach ([false, true] as $reverse) {
    $feasible = [
      401 => ['_entry_kind'=>'movie','_entry_id'=>401,'showing_id'=>4001,'_start_at'=>new DateTimeImmutable($date . ' 2:00 PM',$la),'show_time'=>'2:00 PM','general_qty'=>1,'concessions_total'=>0.00,'_is_locked'=>false],
      402 => ['_entry_kind'=>'movie','_entry_id'=>402,'showing_id'=>4002,'_start_at'=>new DateTimeImmutable($date . ' 4:00 PM',$la),'show_time'=>'4:00 PM','general_qty'=>1,'concessions_total'=>10.00,'_is_locked'=>true],
    ];
    $fixture_square::$order_fixtures[$date] = [
      ['closed_at'=>$date.'T15:00:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1000,'currency'=>'USD']]]],
      ['closed_at'=>$date.'T16:01:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1000,'currency'=>'USD']]]],
    ];
    if ($reverse) $fixture_square::$order_fixtures[$date] = array_reverse($fixture_square::$order_fixtures[$date]);
    if ($reverse) $feasible = array_reverse($feasible, true);
    $fixture_square::with_sale_snapshot(static function () use ($apply_method,$date,&$feasible): void { $apply_method->invokeArgs(null,[&$feasible,$date]); });
    $check((float)$feasible[402]['concessions_total']===10.00 && (float)$feasible[401]['concessions_total']===10.00,
      'a feasible locked/editable split survives ' . ($reverse ? 'reversed' : 'normal') . ' line and row ordering');
  }

  foreach ([false, true] as $reverse) {
    $zero_lock_case = [
      501 => ['_entry_kind'=>'movie','_entry_id'=>501,'showing_id'=>5001,'_start_at'=>new DateTimeImmutable($date . ' 2:00 PM',$la),'show_time'=>'2:00 PM','general_qty'=>1,'concessions_total'=>10.00,'_is_locked'=>true],
      502 => ['_entry_kind'=>'movie','_entry_id'=>502,'showing_id'=>5002,'_start_at'=>new DateTimeImmutable($date . ' 12:00 PM',$la),'show_time'=>'12:00 PM','general_qty'=>1,'concessions_total'=>0.00,'_is_locked'=>false],
      503 => ['_entry_kind'=>'movie','_entry_id'=>503,'showing_id'=>5003,'_start_at'=>new DateTimeImmutable($date . ' 4:00 PM',$la),'show_time'=>'4:00 PM','general_qty'=>1,'concessions_total'=>0.00,'_is_locked'=>true],
    ];
    $fixture_square::$order_fixtures[$date] = [
      ['closed_at'=>$date.'T13:00:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1000,'currency'=>'USD']]]],
      ['closed_at'=>$date.'T15:00:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1000,'currency'=>'USD']]]],
    ];
    if ($reverse) $fixture_square::$order_fixtures[$date] = array_reverse($fixture_square::$order_fixtures[$date]);
    if ($reverse) $zero_lock_case = array_reverse($zero_lock_case, true);
    $fixture_square::with_sale_snapshot(static function () use ($apply_method,$date,&$zero_lock_case): void { $apply_method->invokeArgs(null,[&$zero_lock_case,$date]); });
    $check((float)$zero_lock_case[501]['concessions_total']===10.00 && (float)$zero_lock_case[502]['concessions_total']===10.00
        && (float)$zero_lock_case[503]['concessions_total']===0.00,
      'zero-valued locked rows remain protected while overlapping sales are routed ' . ($reverse ? 'in reverse order' : 'normally'));
  }

  foreach ([false, true] as $reverse) {
    $two_locks = [
      601 => ['_entry_kind'=>'movie','_entry_id'=>601,'showing_id'=>6001,'_start_at'=>new DateTimeImmutable($date . ' 2:00 PM',$la),'show_time'=>'2:00 PM','general_qty'=>1,'concessions_total'=>10.00,'_is_locked'=>true],
      602 => ['_entry_kind'=>'movie','_entry_id'=>602,'showing_id'=>6002,'_start_at'=>new DateTimeImmutable($date . ' 4:00 PM',$la),'show_time'=>'4:00 PM','general_qty'=>1,'concessions_total'=>20.00,'_is_locked'=>true],
      603 => ['_entry_kind'=>'movie','_entry_id'=>603,'showing_id'=>6003,'_start_at'=>new DateTimeImmutable($date . ' 12:00 PM',$la),'show_time'=>'12:00 PM','general_qty'=>1,'concessions_total'=>0.00,'_is_locked'=>false],
    ];
    $fixture_square::$order_fixtures[$date] = [
      ['closed_at'=>$date.'T13:00:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1000,'currency'=>'USD']]]],
      ['closed_at'=>$date.'T15:00:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1000,'currency'=>'USD']]]],
      ['closed_at'=>$date.'T17:00:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1000,'currency'=>'USD']]]],
    ];
    if ($reverse) $fixture_square::$order_fixtures[$date] = array_reverse($fixture_square::$order_fixtures[$date]);
    if ($reverse) $two_locks = array_reverse($two_locks, true);
    $fixture_square::with_sale_snapshot(static function () use ($apply_method,$date,&$two_locks): void { $apply_method->invokeArgs(null,[&$two_locks,$date]); });
    $check((float)$two_locks[601]['concessions_total']===10.00 && (float)$two_locks[602]['concessions_total']===20.00
        && (float)$two_locks[603]['concessions_total']===0.00,
      'multiple locked totals stay attached to eligible shows ' . ($reverse ? 'in reverse order' : 'in normal order'));
  }

  $orphan_locked = [
    301 => ['_entry_kind'=>'movie','_entry_id'=>301,'showing_id'=>3001,'_start_at'=>new DateTimeImmutable($date . ' 11:00 AM',$la),'show_time'=>'11:00 AM','general_qty'=>1,'concessions_total'=>5.00,'_is_locked'=>true],
    302 => ['_entry_kind'=>'live','_entry_id'=>302,'showing_id'=>3002,'_start_at'=>new DateTimeImmutable($date . ' 9:00 PM',$la),'show_time'=>'9:00 PM','online_qty'=>1,'concessions_total'=>0.00,'_is_locked'=>false],
  ];
  $fixture_square::$order_fixtures[$date] = [[
    'closed_at'=>$date.'T21:00:00-07:00','line_items'=>[['item_type'=>'ITEM','catalog_object_id'=>'snack','name'=>'Snack','quantity'=>'1','total_money'=>['amount'=>1000,'currency'=>'USD']]],
  ]];
  $orphan_failed=false;
  try { $fixture_square::with_sale_snapshot(static function () use ($apply_method,$date,&$orphan_locked): void { $apply_method->invokeArgs(null,[&$orphan_locked,$date]); }); }
  catch (Throwable $error) { $orphan_failed = strpos($error->getMessage(),'cannot be reconciled to their matching Square lines') !== false; }
  $check($orphan_failed && (float)$orphan_locked[301]['concessions_total']===5.00 && (float)$orphan_locked[302]['concessions_total']===0.00,
    'a locked row with no matching sale fails instead of surviving beside unrelated day revenue');
  $fixture_square::$order_fixtures[$date] = [];
  $zero_sales_locked = [301 => $orphan_locked[301]];
  $zero_sales_failed=false;
  try { $fixture_square::with_sale_snapshot(static function () use ($apply_method,$date,&$zero_sales_locked): void { $apply_method->invokeArgs(null,[&$zero_sales_locked,$date]); }); }
  catch (Throwable $error) { $zero_sales_failed = strpos($error->getMessage(),'cannot be reconciled to their matching Square lines') !== false; }
  $check($zero_sales_failed && (float)$zero_sales_locked[301]['concessions_total']===5.00,
    'a positive locked total cannot pass a verified zero-sales day');
  unset($fixture_square::$order_fixtures[$date]);
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
  remove_filter('pre_schedule_event', $cron_guard, PHP_INT_MAX);
  $wpdb->suppress_errors($silenced);
}
