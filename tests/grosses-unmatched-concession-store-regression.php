<?php
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function current_time($type, $gmt = false) { return '2038-05-04 20:00:00'; }
function get_current_user_id() { return 42; }

final class UnmatchedFixtureWpdb {
  public string $prefix = 'wp_';
  public string $last_error = '';
  public bool $fail_query = false;
  public array $rows = [];
  public function prepare(string $sql, ...$args): string {
    foreach ($args as $arg) {
      $value = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
      $sql = preg_replace('/%[sd]/', $value, $sql, 1);
    }
    return $sql;
  }
  public function query(string $sql) {
    $this->last_error = '';
    if ($this->fail_query) { $this->last_error = 'injected queue write failure'; return false; }
    if (!preg_match("/VALUES \\('([a-f0-9]{64})','([^']*)','([^']*)','([^']*)','([^']*)','([^']*)','([^']*)',(\\d+),'([^']*)','open',1,'([^']*)','([^']*)'\\)/", $sql, $m)) {
      $this->last_error = 'unexpected insert query'; return false;
    }
    $fingerprint = $m[1];
    $fields = ['fingerprint','report_date','square_order_id','square_line_uid','catalog_object_id','item_name','closed_at','amount_cents','reason','first_seen_at','last_seen_at'];
    $values = array_slice($m, 1);
    $row = array_combine($fields, $values);
    $row['amount_cents'] = (int) $row['amount_cents'];
    $row['status'] = 'open'; $row['occurrences'] = 1; $row['resolved_at'] = null; $row['resolved_by'] = null;
    if (isset($this->rows[$fingerprint])) {
      $row['id'] = $this->rows[$fingerprint]['id'];
      $row['first_seen_at'] = $this->rows[$fingerprint]['first_seen_at'];
      $row['occurrences'] = min(4294967295, $this->rows[$fingerprint]['occurrences'] + 1);
    } else $row['id'] = count($this->rows) + 1;
    $this->rows[$fingerprint] = $row;
    return 1;
  }
  public function get_results(string $sql, $format = null): array {
    $this->last_error = '';
    $rows = array_values($this->rows);
    if (preg_match("/WHERE status = '([^']+)'/", $sql, $m)) $rows = array_values(array_filter($rows, static fn($row) => $row['status'] === $m[1]));
    usort($rows, static fn($a, $b) => ($a['status'] === 'open' ? 0 : 1) <=> ($b['status'] === 'open' ? 0 : 1));
    if (preg_match('/LIMIT (\d+)$/', $sql, $m)) $rows = array_slice($rows, 0, (int) $m[1]);
    return $rows;
  }
  public function update(string $table, array $data, array $where) {
    $this->last_error = '';
    foreach ($this->rows as &$row) {
      if ((int) $row['id'] !== (int) ($where['id'] ?? 0) || $row['status'] !== (string) ($where['status'] ?? '')) continue;
      $row = array_merge($row, $data); return 1;
    }
    unset($row);
    return 0;
  }
}

$GLOBALS['wpdb'] = new UnmatchedFixtureWpdb();
require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';
$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException('FAIL: ' . $message);
  echo 'PASS: ' . $message . PHP_EOL;
};
$line = [
  'fingerprint' => hash('sha256', '2038-05-04|order-1|line-1'), 'square_order_id' => 'order-1',
  'square_line_uid' => 'line-1', 'catalog_object_id' => 'catalog-1', 'item_name' => 'Chocolate Candy',
  'closed_at' => '2038-05-04T23:30:00-07:00', 'amount_cents' => 1250,
];
$store = 'RoxyGrosses\\Store';
$db = $GLOBALS['wpdb'];
$check($store::record_unmatched_concession_lines('2038-05-04', [$line]) === 1, 'new unmatched line is durably inserted');
$open = $store::list_unmatched_concession_lines('open', 100);
$check(count($open) === 1 && $open[0]['amount_cents'] === 1250 && $open[0]['status'] === 'open', 'open queue read returns exact amount and state');
$line['amount_cents'] = 1400;
$store::record_unmatched_concession_lines('2038-05-04', [$line]);
$open = $store::list_unmatched_concession_lines('open', 100);
$check(count($open) === 1 && $open[0]['amount_cents'] === 1400 && $open[0]['occurrences'] === 2 && $open[0]['first_seen_at'] === '2038-05-04 20:00:00', 'repeat detection refreshes line facts and increments occurrence without duplicate rows');
$check($store::resolve_unmatched_concession_line(1), 'open line can be resolved');
$resolved = $store::list_unmatched_concession_lines('all', 100);
$check(count($resolved) === 1 && $resolved[0]['status'] === 'resolved' && $resolved[0]['resolved_by'] === 42, 'resolution is recorded and retained in history');
$store::record_unmatched_concession_lines('2038-05-04', [$line]);
$open = $store::list_unmatched_concession_lines('open', 100);
$check(count($open) === 1 && $open[0]['status'] === 'open' && $open[0]['resolved_by'] === null && $open[0]['occurrences'] === 3, 'a still-unmatched line automatically reopens on a later pull');
$invalid_rejected = false;
try { $store::record_unmatched_concession_lines('2038-05-04', [['fingerprint'=>'bad','amount_cents'=>-1]]); }
catch (InvalidArgumentException $error) { $invalid_rejected = true; }
$check($invalid_rejected, 'invalid queue identities and amounts are refused');
$db->fail_query = true; $write_failed = false;
try { $store::record_unmatched_concession_lines('2038-05-04', [$line]); }
catch (RuntimeException $error) { $write_failed = str_contains($error->getMessage(), 'Could not persist'); }
$check($write_failed, 'queue write failures are returned, not hidden');
echo "OK: unmatched concession queue Store regressions\n";
