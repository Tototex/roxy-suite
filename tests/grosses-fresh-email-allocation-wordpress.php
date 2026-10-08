<?php
/** WP-CLI integration fixture: actual Store persistence, isolated Reporter orchestration, no production Grosses writes. */
if (!defined('WP_CLI') || !WP_CLI) exit;

global $wpdb;
$store_path = $args[0] ?? (dirname(__DIR__).'/includes/modules/grosses/includes/class-roxy-grosses-store.php');
$reporter_path = $args[1] ?? (dirname(__DIR__).'/includes/modules/grosses/includes/class-roxy-grosses-reporter.php');
foreach ([$store_path, $reporter_path] as $path) if (!is_file($path)) throw new RuntimeException('Candidate file missing: '.$path);

$token = 'freshalloc_'.bin2hex(random_bytes(6));
$table_map = [
  'roxy_grosses_reports' => $token.'_reports',
  'roxy_grosses_logs' => $token.'_logs',
  'roxy_grosses_history' => $token.'_history',
  'roxy_grosses_entries' => $token.'_entries',
  'roxy_grosses_live_entries' => $token.'_live',
  'roxy_grosses_rental_entries' => $token.'_rental',
];
$owned_tables = [];
$private_names = [];
$table_exists = static function (string $name) use ($wpdb): bool {
  return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name))) === $name;
};
$quote = static function (string $name): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('Unsafe SQL identifier in fixture.');
  return '`'.$name.'`';
};
foreach ($table_map as $production => $suffix) {
  $production_table = $wpdb->prefix.$production;
  $private_table = $wpdb->prefix.$suffix;
  if (!preg_match('/^'.preg_quote($wpdb->prefix, '/').preg_quote($token, '/').'_[A-Za-z0-9_]+$/', $private_table)) throw new RuntimeException('Fixture table name escaped its private prefix.');
  if (!$table_exists($production_table)) throw new RuntimeException('Required production schema is missing: '.$production_table);
  if ($table_exists($private_table)) throw new RuntimeException('Randomized fixture table already exists; refusing to touch it.');
  $private_names[$production] = $private_table;
}

$write_guard = static function ($sql) use ($wpdb, $table_map) {
  if (preg_match('/^\s*(?:INSERT|UPDATE|REPLACE|DELETE|CREATE|DROP|ALTER|TRUNCATE)\b/i', (string)$sql)) {
    if (stripos((string)$sql, $wpdb->prefix.'roxy_grosses_') !== false) throw new RuntimeException('Blocked attempted write to a production Grosses table.');
  }
  return $sql;
};
$mail_calls = [];
$mail_result = true;
$transport_guard = static function ($mailer): void { throw new RuntimeException('Unexpected mail transport initialization; pre_wp_mail interception should have short-circuited.'); };
$http_guard = static function () { return new WP_Error('fixture_network_blocked', 'Network access is disabled in the Grosses fixture.'); };
$guard_installed = false;
$capture_installed = false;
$namespace = 'FreshEmailAllocationWP_'.bin2hex(random_bytes(5));
$check = static function ($ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: '.$label); echo 'PASS: '.$label."\n"; };

try {
  foreach ($table_map as $production => $suffix) {
    $target = $private_names[$production];
    if ($table_exists($target)) throw new RuntimeException('Fixture table appeared after preflight; refusing to overwrite.');
    if ($wpdb->query('CREATE TABLE '.$quote($target).' LIKE '.$quote($wpdb->prefix.$production)) === false) throw new RuntimeException('Could not clone private schema for '.$production);
    $owned_tables[] = $target;
  }

  add_filter('query', $write_guard, PHP_INT_MAX);
  $guard_installed = true;
  foreach (['phpmailer_init'=>$transport_guard, 'pre_http_request'=>$http_guard] as $hook=>$callback) {
    if ($hook === 'pre_http_request') add_filter($hook, $callback, PHP_INT_MAX, 3);
    else add_action($hook, $callback, PHP_INT_MAX, 1);
  }
  $capture_installed = true;

  $seed_time = current_time('mysql');
  $movie_table = $private_names['roxy_grosses_entries'];
  $live_table = $private_names['roxy_grosses_live_entries'];
  $rental_table = $private_names['roxy_grosses_rental_entries'];
  $seed = static function (string $table, array $row) use ($wpdb): void { if ($wpdb->insert($table, $row) === false) throw new RuntimeException('Could not seed private financial fixture row.'); };
  $seed($movie_table, ['created_at'=>$seed_time,'updated_at'=>$seed_time,'report_date'=>'2038-05-12','movie_title'=>'Protected Fixture Movie','normalized_title'=>'protected fixture movie','studio'=>'Fixture','genre'=>'Drama','show_time'=>'19:00','showing_id'=>880001,'theater_name'=>'Fixture Theater','general_qty'=>5,'discount_qty'=>0,'group_qty'=>0,'subscriber_qty'=>0,'live_qty'=>0,'other_qty'=>0,'total_tickets'=>5,'gross_total'=>50.00,'concessions_total'=>5.00,'source_type'=>'manual','source_ref'=>'fixture','is_locked'=>0]);
  $seed($movie_table, ['created_at'=>$seed_time,'updated_at'=>$seed_time,'report_date'=>'2038-05-12','movie_title'=>'Locked Fixture Movie','normalized_title'=>'locked fixture movie','studio'=>'Fixture','genre'=>'Drama','show_time'=>'20:00','showing_id'=>880003,'theater_name'=>'Fixture Theater','general_qty'=>0,'discount_qty'=>0,'group_qty'=>0,'subscriber_qty'=>0,'live_qty'=>0,'other_qty'=>0,'total_tickets'=>0,'gross_total'=>0.00,'concessions_total'=>0.00,'source_type'=>'manual','source_ref'=>'fixture-locked','is_locked'=>1]);
  $seed($live_table, ['created_at'=>$seed_time,'updated_at'=>$seed_time,'report_date'=>'2038-05-12','show_title'=>'Protected Fixture Live','normalized_title'=>'protected fixture live','show_time'=>'20:00','showing_id'=>880002,'theater_name'=>'Fixture Theater','presale_qty'=>0,'online_qty'=>5,'door_qty'=>0,'group_sub_qty'=>0,'total_tickets'=>5,'gross_total'=>30.00,'concessions_total'=>5.00,'source_type'=>'manual','source_ref'=>'fixture']);
  $seed($rental_table, ['created_at'=>$seed_time,'updated_at'=>$seed_time,'report_date'=>'2038-05-12','rental_title'=>'Protected Fixture Rental','normalized_title'=>'protected fixture rental','rental_type'=>'private','show_time'=>'','customer_name'=>'Fixture','status'=>'confirmed','invoice_amount'=>0.00,'concessions_total'=>0.00,'source_type'=>'manual','source_ref'=>'fixture']);

  $store_source = file_get_contents($store_path);
  $reporter_source = file_get_contents($reporter_path);
  if (!is_string($store_source) || !is_string($reporter_source)) throw new RuntimeException('Could not read candidate PHP sources.');
  $store_source = str_replace('namespace RoxyGrosses;', 'namespace '.$namespace.';', $store_source);
  foreach ($table_map as $production => $suffix) $store_source = str_replace("'{$production}'", "'{$suffix}'", $store_source);
  eval('?>'.$store_source);

  eval('namespace '.$namespace.'; final class Settings {
    public static function get(string $key, $default = "") { return $key === "admin_email" ? "" : $default; }
    public static function email_list(): array { return ["fresh-allocation-fixture@example.invalid"]; }
    public static function admin_email(): string { return ""; }
    public static function get_report_timezone(): string { return "UTC"; }
    public static function set_status(array $status): void {}
  }
  final class Square { public static function with_sale_snapshot(callable $operation) { return $operation(); } }');

  $build_call = '$reports = self::build_reports($report_date);';
  $replace_method_build = static function (string $source, string $method) use ($build_call): string {
    $start = strpos($source, 'function '.$method.'(');
    if ($start === false) throw new RuntimeException('Missing Reporter orchestration method '.$method.'.');
    $next = preg_match('/\n  (?:public|private|protected) static function /', $source, $match, PREG_OFFSET_CAPTURE, $start + 10)
      ? $match[0][1] : strlen($source);
    $body = substr($source, $start, $next-$start);
    if (substr_count($body, $build_call) !== 1) throw new RuntimeException('Expected one report-builder boundary in '.$method.'.');
    $body = str_replace($build_call, '$reports = $GLOBALS["fresh_email_wp_fixture_rows"];', $body);
    return substr_replace($source, $body, $start, $next-$start);
  };
  $reporter_source = $replace_method_build($reporter_source, 'send_report_locked');
  $reporter_source = $replace_method_build($reporter_source, 'save_report_draft_snapshot');
  $reporter_source = str_replace('namespace RoxyGrosses;', 'namespace '.$namespace.';', $reporter_source);
  eval('?>'.$reporter_source);

  $store = '\\'.$namespace.'\\Store';
  $reporter = '\\'.$namespace.'\\Reporter';
  $snapshot_rows = static function (string $table) use ($wpdb): array {
    $rows = $wpdb->get_results('SELECT * FROM `'.$table.'` ORDER BY id ASC', ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($rows)) throw new RuntimeException('Could not read private fixture table.');
    return $rows;
  };
  $financial_before = [];
  foreach ([$movie_table,$live_table,$rental_table] as $table) $financial_before[$table] = $snapshot_rows($table);
  $movie_unlocked = array_values(array_filter($financial_before[$movie_table], static fn($row) => (int)$row['is_locked']===0));
  $movie_locked = array_values(array_filter($financial_before[$movie_table], static fn($row) => (int)$row['is_locked']===1));
  $check(count($movie_unlocked)===1 && (float)$movie_unlocked[0]['concessions_total']===5.0, 'private movie entry seeded at $5 concessions and unlocked');
  $check(count($movie_locked)===1 && (float)$movie_locked[0]['concessions_total']===0.0, 'private movie control row is manually protected/locked');
  $check(count($financial_before[$live_table])===1 && (float)$financial_before[$live_table][0]['concessions_total']===5.0, 'private live entry seeded at $5 concessions');
  $check(count($financial_before[$rental_table])===1 && (float)$financial_before[$rental_table][0]['concessions_total']===0.0, 'private rental entry seeded at zero concessions');
  $financial_hash = hash('sha256', serialize($financial_before));
  $assert_financial_unchanged = static function (string $label) use ($financial_hash, $financial_before, $snapshot_rows, $check): void {
    $after = [];
    foreach (array_keys($financial_before) as $table) $after[$table] = $snapshot_rows($table);
    $check(hash('sha256', serialize($after))===$financial_hash, $label.' leaves actual private movie/live/rental rows byte-for-byte unchanged');
  };

  $GLOBALS['fresh_email_wp_fixture_rows'] = [
    ['report_date'=>'2038-05-12','showing_id'=>880001,'show_time'=>'19:00','theater_name'=>'Fixture Theater','film_title'=>'Fresh Fixture Movie','general_qty'=>5,'discount_qty'=>0,'group_qty'=>0,'total_tickets'=>5,'gross_total'=>50.00,'concessions_total'=>10.00,'source_type'=>'square_auto'],
  ];
  $expected_rows = json_decode(wp_json_encode($GLOBALS['fresh_email_wp_fixture_rows']), true);
  $mail_result = true;
  $mail_filter = static function ($prior, $atts) use (&$mail_calls, &$mail_result) {
    $attachments = (array)($atts['attachments'] ?? []); $contents=[];
    foreach ($attachments as $path) $contents[] = is_file($path) ? file_get_contents($path) : false;
    $mail_calls[] = ['atts'=>$atts,'contents'=>$contents];
    return $mail_result;
  };
  add_filter('pre_wp_mail', $mail_filter, PHP_INT_MAX, 2);
  // Replace the earlier placeholder capture closure with this request's actual email interceptor.

  $send = $reporter::send_report('2038-05-12', 'fixture');
  $check(!empty($send['success']) && count($mail_calls)===1, 'actual Reporter sends fresh report through intercepted WordPress mail');
  $csv = $mail_calls[0]['contents'][0] ?? false;
  $attachment = $mail_calls[0]['atts']['attachments'][0] ?? '';
  $check(is_string($csv) && str_contains($csv,'Fresh Fixture Movie') && str_contains($csv,'$50.00'), 'intercepted actual attachment contains the fresh movie-only report');
  $check(!file_exists($attachment), 'actual private CSV attachment is removed after successful intercepted send');
  $reports_table = $private_names['roxy_grosses_reports'];
  $report_rows = $wpdb->get_results('SELECT status, payload_json FROM `'.$reports_table.'` ORDER BY id ASC', ARRAY_A);
  $check(is_array($report_rows) && count($report_rows)===1 && $report_rows[0]['status']==='emailed' && (json_decode($report_rows[0]['payload_json'],true)['rows'] ?? null)===$expected_rows, 'actual Store persists successful emailed snapshot only in private report table');
  $assert_financial_unchanged('successful email');

  $mail_before_draft = count($mail_calls);
  $draft = $reporter::save_report_draft('2038-05-12', 'fixture-review');
  $check(!empty($draft['success']) && count($mail_calls)===$mail_before_draft, 'actual draft saves without invoking mail');
  $report_rows = $wpdb->get_results('SELECT status, payload_json FROM `'.$reports_table.'` ORDER BY id ASC', ARRAY_A);
  $draft_data = json_decode((string)($report_rows[1]['payload_json'] ?? ''), true);
  $check(count($report_rows)===2 && $report_rows[1]['status']==='draft' && ($draft_data['rows'] ?? null)===$expected_rows, 'actual Store persists draft snapshot only in private report table');
  $assert_financial_unchanged('draft save');

  $mail_result = false;
  $failed = $reporter::send_report('2038-05-12', 'fixture');
  $check(empty($failed['success']) && count($mail_calls)===2, 'actual Reporter handles intercepted email failure without external delivery');
  $failed_attachment = $mail_calls[1]['atts']['attachments'][0] ?? '';
  $check(!file_exists($failed_attachment), 'actual private CSV attachment is removed after failed intercepted send');
  $report_rows = $wpdb->get_results('SELECT status FROM `'.$reports_table.'` ORDER BY id ASC', ARRAY_A);
  $check(is_array($report_rows) && count($report_rows)===2, 'failed email creates no additional emailed snapshot');
  $assert_financial_unchanged('failed email');
  echo "Passed actual Store/Reporter fresh-email and draft allocation checks; report-builder boundary was replaced, not end-to-end tested.\n";
} finally {
  try {
    foreach (array_reverse($owned_tables) as $table) {
      if (!preg_match('/^'.preg_quote($wpdb->prefix, '/').preg_quote($token, '/').'_[A-Za-z0-9_]+$/', $table)) throw new RuntimeException('Refusing cleanup outside this fixture.');
      if ($table_exists($table) && ($wpdb->query('DROP TABLE '.$quote($table)) === false || $table_exists($table))) throw new RuntimeException('Private fixture cleanup failed.');
    }
  } finally {
    if ($capture_installed) {
      remove_action('phpmailer_init', $transport_guard, PHP_INT_MAX);
      remove_filter('pre_http_request', $http_guard, PHP_INT_MAX);
    }
    if (isset($mail_filter)) remove_filter('pre_wp_mail', $mail_filter, PHP_INT_MAX);
    if ($guard_installed) remove_filter('query', $write_guard, PHP_INT_MAX);
  }
}
