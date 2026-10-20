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
$schema_option_filter = null;
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

  // A previously verified version marker must not make a missing table look
  // ready. Simulate restoration/deletion against this private DB only.
  $schema_option_filter = static fn($pre) => '1';
  add_filter('pre_option_roxy_grosses_email_outbox_schema_version', $schema_option_filter, PHP_INT_MAX);
  if ($fixture_db->query('DROP TABLE `' . $table . '`') === false) throw new RuntimeException('Could not remove private outbox table for cached-schema recovery fixture.');
  $created = false;
  $missing_schema_ready = $outbox::ensure_schema(false);
  $missing_claim_blocked = false;
  try { $outbox::claim('grosses:missing-table', 'daily_report', 99, '2039-04-04', ['report' => 'fixture'], ['trigger' => 'manual']); }
  catch (RuntimeException $error) { $missing_claim_blocked = true; }
  remove_filter('pre_option_roxy_grosses_email_outbox_schema_version', $schema_option_filter, PHP_INT_MAX);
  $schema_option_filter = null;
  $table_still_missing = $original_db->get_var($original_db->prepare('SHOW TABLES LIKE %s', $original_db->esc_like($table))) !== $table;
  $check(!$missing_schema_ready && $table_still_missing && $missing_claim_blocked,
    'cached schema marker fails closed on a missing durable outbox table without recreating the send ledger');
  $check($outbox::ensure_schema(true), 'explicit forced schema verification can restore the private fixture after the fail-closed check');
  $created = $original_db->get_var($original_db->prepare('SHOW TABLES LIKE %s', $original_db->esc_like($table))) === $table;

  $logical_key = 'grosses:daily:2039-04-05';
  $unicode_subject = 'Fixture report ' . "\u{2014}" . ' manager ' . "\u{2019}" . ' 🍿';
  $unicode_body = 'Provisional ' . "\u{2014}" . ' “review” — 🎟️';
  $payload = [
    'to' => ['manager@example.invalid'],
    'subject' => $unicode_subject,
    'body' => $unicode_body,
    'attachment' => ['name' => 'report.csv'],
  ];
  $context = ['trigger' => 'scheduled', 'fixture' => true, 'label' => 'Scheduled — provisional 🍿'];

  // Reproduce WordPress rejecting a prepared write when its cached connection
  // charset cannot represent raw Unicode. The production outbox stores JSON
  // with Unicode escaped, so the resulting claim query remains ASCII-only.
  $fixture_charset = $fixture_db->charset;
  $fixture_db->charset = 'ascii';
  $legacy_json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  $legacy_write = $fixture_db->query($fixture_db->prepare(
    "UPDATE `{$table}` SET payload_json = %s WHERE id = 0",
    $legacy_json
  ));
  $check($legacy_write === false && strpos((string) $fixture_db->last_error, 'contains invalid data') !== false,
    'pre-fix raw Unicode write reproduces the WordPress charset rejection');
  $first = $outbox::claim($logical_key, 'daily_report', 41, '2039-04-05', $payload, $context);
  $check($first['claimed'] === true && $first['id'] > 0 && $first['status'] === 'sending', 'first atomic claim persists sending before any caller-side mail action');
  $fixture_db->charset = $fixture_charset;

  // A second independent MySQL connection attempts the exact same logical send.
  $check((string) $fixture_db->get_var('SELECT CONNECTION_ID()') !== (string) $other_db->get_var('SELECT CONNECTION_ID()'), 'contending handles are independent database connections');
  $wpdb = $other_db;
  $second = $outbox::claim($logical_key, 'daily_report', 41, '2039-04-05', ['different' => 'must not overwrite'], ['trigger' => 'manual']);
  $check($second['claimed'] === false && $second['id'] === $first['id'] && $second['status'] === 'sending', 'independent contender is rejected by unique-key SQL without changing the claim');
  $wpdb = $fixture_db;

  $before_duplicate = $outbox::find($logical_key);
  $check(($before_duplicate['payload_json'] ?? '') === json_encode($payload, JSON_UNESCAPED_SLASHES)
    && ($before_duplicate['context_json'] ?? '') === json_encode($context, JSON_UNESCAPED_SLASHES)
    && ($before_duplicate['kind'] ?? '') === 'daily_report' && (int) ($before_duplicate['source_id'] ?? -1) === 41
    && ($before_duplicate['report_date'] ?? '') === '2039-04-05' && ($before_duplicate['status'] ?? '') === 'sending',
    'duplicate SQL preserves original payload, context, source/date, and blocking state');
  $stored_payload = json_decode((string) ($before_duplicate['payload_json'] ?? ''), true);
  $stored_context = json_decode((string) ($before_duplicate['context_json'] ?? ''), true);
  $check(($stored_payload['subject'] ?? '') === $unicode_subject && ($stored_payload['body'] ?? '') === $unicode_body
    && ($stored_context['label'] ?? '') === 'Scheduled — provisional 🍿', 'ASCII-safe persisted JSON round-trips Unicode payload and context exactly');

  $check($outbox::finish((int) $first['id'], 'accepted'), 'accepted result is recorded');
  $accepted = $outbox::find($logical_key);
  $check(($accepted['status'] ?? '') === 'accepted' && !array_key_exists('delivered', $accepted), 'accepted status is not represented as delivery confirmation');
  $check(!$outbox::finish((int) $first['id'], 'uncertain', 'late alternate result'), 'finish is one-way and cannot rewrite an already-finished result');
  $check(($outbox::find($logical_key)['status'] ?? '') === 'accepted', 'late finish leaves accepted result unchanged');

  $uncertain_key = 'grosses:daily:2039-04-06';
  $uncertain_claim = $outbox::claim($uncertain_key, 'daily_report', 42, '2039-04-06', ['report' => 'uncertain fixture'], ['trigger' => 'manual']);
  $check($outbox::finish((int) $uncertain_claim['id'], 'uncertain', 'mail acceptance could not be established'), 'uncertain result can be recorded');
  $check(($outbox::find($uncertain_key)['status'] ?? '') === 'uncertain', 'uncertain result is readable and remains distinct from accepted');

  $unicode_error_key = 'grosses:daily:2039-04-07';
  $unicode_error_claim = $outbox::claim($unicode_error_key, 'daily_report', 45, '2039-04-07', ['report' => 'fixture'], ['trigger' => 'scheduled']);
  $unicode_error = 'wp_mail threw: provider response — “temporary” 🍿';
  $fixture_db->charset = 'ascii';
  $legacy_error_write = $fixture_db->query($fixture_db->prepare(
    "UPDATE `{$table}` SET status = %s, error_text = %s WHERE id = %d AND status = 'sending'",
    'uncertain', $unicode_error, (int) $unicode_error_claim['id']
  ));
  $check($legacy_error_write === false && strpos((string) $fixture_db->last_error, 'contains invalid data') !== false,
    'pre-fix raw Unicode provider error reproduces the WordPress charset rejection');
  $unicode_finish_ok = $outbox::finish((int) $unicode_error_claim['id'], 'uncertain', $unicode_error);
  $fixture_db->charset = $fixture_charset;
  $check($unicode_finish_ok && ($outbox::find($unicode_error_key)['error_text'] ?? '') === $unicode_error,
    'Unicode provider error can be durably recorded under a restrictive database charset and reads back unchanged');

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
  if ($schema_option_filter) remove_filter('pre_option_roxy_grosses_email_outbox_schema_version', $schema_option_filter, PHP_INT_MAX);
  if ($created && preg_match('/^' . preg_quote($original_prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table)) {
    $exists = $original_db->get_var($original_db->prepare('SHOW TABLES LIKE %s', $original_db->esc_like($table))) === $table;
    if ($exists) $original_db->query('DROP TABLE `' . $table . '`');
  }
}
