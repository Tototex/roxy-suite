<?php
/** Isolated WP-CLI regression test for EmailOutbox; uses only random private tables. */
if (!defined('WP_CLI') || !WP_CLI) exit;

$class_path = $args[0] ?? dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-email-outbox.php';
if (!is_file($class_path)) throw new RuntimeException('EmailOutbox candidate file is missing: ' . $class_path);
require_once $class_path;

global $wpdb;
$original_db = $wpdb;
$original_prefix = $original_db->prefix;
$token = 'gxo_' . bin2hex(random_bytes(6));
$private_prefix = $original_prefix . $token . '_';
if (!preg_match('/^[A-Za-z0-9_]+$/', $private_prefix)) throw new RuntimeException('Private fixture prefix is invalid.');
$table = $private_prefix . \RoxyGrosses\EmailOutbox::TABLE;
if (!preg_match('/^' . preg_quote($original_db->prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table)) {
  throw new RuntimeException('Refusing a fixture table outside the random private namespace.');
}
$fixture_db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$fixture_db->suppress_errors(true);
$fixture_db->prefix = $private_prefix;
$other_db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$other_db->suppress_errors(true);
$other_db->prefix = $private_prefix;
$created = false;
$checks = 0;
$check = static function ($condition, string $label) use (&$checks): void {
  if (!$condition) throw new RuntimeException('FAIL: ' . $label);
  $checks++;
  echo "PASS: {$label}\n";
};

final class GrossesOutboxReadFailureFixture {
  public string $prefix = '';
  public string $last_error = 'simulated read failure';
  public function prepare(string $sql, ...$args): string { return $sql; }
  public function get_row($sql, $output = OBJECT) { return null; }
  public function get_results($sql, $output = OBJECT): array { return []; }
}

final class GrossesOutboxWriteFailureFixture {
  public string $prefix = '';
  public string $last_error = '';
  public int $rows_affected = 0;
  public function prepare(string $sql, ...$args): string { return $sql; }
  public function query(string $sql) { $this->last_error = 'simulated write failure'; return false; }
}

try {
  if ($original_db->get_var($original_db->prepare('SHOW TABLES LIKE %s', $original_db->esc_like($table))) === $table) {
    throw new RuntimeException('Random private outbox table already exists; refusing to reuse it.');
  }

  $wpdb = $fixture_db;
  $outbox = \RoxyGrosses\EmailOutbox::class;
  $schema_ok = $outbox::ensure_schema(true);
  $created = $original_db->get_var($original_db->prepare('SHOW TABLES LIKE %s', $original_db->esc_like($table))) === $table;
  $check($schema_ok, 'schema creation succeeds for private table');
  $check($outbox::ensure_schema(true), 'schema creation is repeatable');

  $logical_key = 'grosses:daily:2039-04-05';
  $payload = ['to' => ['manager@example.invalid'], 'subject' => 'Fixture report', 'attachment' => ['name' => 'report.csv']];
  $context = ['trigger' => 'scheduled', 'fixture' => true];
  $first = $outbox::claim($logical_key, 'daily_report', 41, '2039-04-05', $payload, $context);
  $check($first['claimed'] === true && $first['id'] > 0 && $first['status'] === 'sending', 'first atomic claim persists sending before any caller-side mail action');

  // A second independent MySQL connection attempts the exact same logical send.
  $check((string) $fixture_db->get_var('SELECT CONNECTION_ID()') !== (string) $other_db->get_var('SELECT CONNECTION_ID()'), 'contending handles are independent database connections');
  $wpdb = $other_db;
  $second = $outbox::claim($logical_key, 'daily_report', 41, '2039-04-05', ['different' => 'must not overwrite'], ['trigger' => 'manual']);
  $check($second['claimed'] === false && $second['id'] === $first['id'] && $second['status'] === 'sending', 'independent contender is rejected by unique-key SQL without changing the claim');
  $wpdb = $fixture_db;

  $before_duplicate = $outbox::find($logical_key);
  $check(($before_duplicate['payload_json'] ?? '') === json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    && ($before_duplicate['context_json'] ?? '') === json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    && ($before_duplicate['kind'] ?? '') === 'daily_report' && (int) ($before_duplicate['source_id'] ?? -1) === 41
    && ($before_duplicate['report_date'] ?? '') === '2039-04-05' && ($before_duplicate['status'] ?? '') === 'sending',
    'duplicate SQL preserves original payload, context, source/date, and blocking state');

  $check($outbox::finish((int) $first['id'], 'accepted'), 'accepted result is recorded');
  $accepted = $outbox::find($logical_key);
  $check(($accepted['status'] ?? '') === 'accepted' && !array_key_exists('delivered', $accepted), 'accepted status is not represented as delivery confirmation');
  $check(!$outbox::finish((int) $first['id'], 'uncertain', 'late alternate result'), 'finish is one-way and cannot rewrite an already-finished result');
  $check(($outbox::find($logical_key)['status'] ?? '') === 'accepted', 'late finish leaves accepted result unchanged');

  $uncertain_key = 'grosses:daily:2039-04-06';
  $uncertain_claim = $outbox::claim($uncertain_key, 'daily_report', 42, '2039-04-06', ['report' => 'uncertain fixture'], ['trigger' => 'manual']);
  $check($outbox::finish((int) $uncertain_claim['id'], 'uncertain', 'mail acceptance could not be established'), 'uncertain result can be recorded');
  $check(($outbox::find($uncertain_key)['status'] ?? '') === 'uncertain', 'uncertain result is readable and remains distinct from accepted');

  // A database write error must not clear the durable sending state.
  $wpdb = new GrossesOutboxWriteFailureFixture();
  $write_failed = $outbox::finish((int) $uncertain_claim['id'], 'accepted');
  $check($write_failed === false, 'finish write error returns false without claiming success');
  $wpdb = $fixture_db;
  $check(($outbox::find($uncertain_key)['status'] ?? '') === 'uncertain', 'failed finish write does not mutate the stored blocking result');

  // Invalid encoding and size are rejected before a database write.
  $bad_utf8_thrown = false;
  try { $outbox::claim('bad:utf8', 'daily_report', 43, null, ['bad' => "\xB1"], []); }
  catch (InvalidArgumentException $error) { $bad_utf8_thrown = true; }
  $check($bad_utf8_thrown, 'invalid UTF-8 payload is rejected');
  $too_large_thrown = false;
  try { $outbox::claim('too:large', 'daily_report', 43, null, ['blob' => str_repeat('x', 262145)], []); }
  catch (InvalidArgumentException $error) { $too_large_thrown = true; }
  $check($too_large_thrown, 'oversized JSON payload is rejected');
  $check($outbox::find('bad:utf8') === null && $outbox::find('too:large') === null, 'invalid payloads do not create claims');

  $bad_date_thrown = false;
  try { $outbox::claim('bad:date', 'daily_report', 44, '2039-02-30', [], []); }
  catch (InvalidArgumentException $error) { $bad_date_thrown = true; }
  $check($bad_date_thrown, 'impossible calendar date is rejected');
  $bad_limit_thrown = false;
  try { $outbox::recent(101); }
  catch (InvalidArgumentException $error) { $bad_limit_thrown = true; }
  $check($bad_limit_thrown, 'recent list limit is bounded');

  $wpdb = new GrossesOutboxReadFailureFixture();
  $find_error_thrown = false;
  try { $outbox::find('read:error'); }
  catch (RuntimeException $error) { $find_error_thrown = true; }
  $recent_error_thrown = false;
  try { $outbox::recent(10); }
  catch (RuntimeException $error) { $recent_error_thrown = true; }
  $check($find_error_thrown && $recent_error_thrown, 'lookup and list fail closed on database read errors');

  $wpdb = $fixture_db;
  if ($wpdb->query('DROP TABLE `' . $table . '`') === false) throw new RuntimeException('Could not clean private outbox fixture table.');
  $created = false;
  echo "Passed {$checks} isolated Grosses email outbox checks. No mail/provider calls were made.\n";
} finally {
  $wpdb = $original_db;
  if ($created && preg_match('/^' . preg_quote($original_prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table)) {
    $exists = $original_db->get_var($original_db->prepare('SHOW TABLES LIKE %s', $original_db->esc_like($table))) === $table;
    if ($exists) $original_db->query('DROP TABLE `' . $table . '`');
  }
}
