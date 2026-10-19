<?php
// Isolated outbox lookup result-shape regression; no WordPress tables or mail.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

require_once dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-email-outbox.php';

final class GrossesOutboxReadShapeWpdb {
  public string $prefix = 'fixture_';
  public string $last_error = '';
  public $row = null;

  public function prepare(string $query, ...$args): string { return $query; }
  public function get_row(string $query, $format = null) { return $this->row; }
}

$GLOBALS['wpdb'] = new GrossesOutboxReadShapeWpdb();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
  if (!$ok) throw new RuntimeException($message);
  $checks++;
  echo "PASS: {$message}\n";
};
$lookup = static function (): ?array {
  return \RoxyGrosses\EmailOutbox::find('fixture-logical-key');
};

$GLOBALS['wpdb']->row = null;
$GLOBALS['wpdb']->last_error = '';
$check($lookup() === null, 'verified null lookup remains an absent key');

$GLOBALS['wpdb']->row = ['id' => 7, 'status' => 'sending', 'error_text' => ''];
$check(($lookup()['status'] ?? '') === 'sending', 'associative lookup returns the blocking attempt');

foreach ([false, 'malformed', (object) ['id' => 7, 'status' => 'sending']] as $malformed) {
  $GLOBALS['wpdb']->row = $malformed;
  $GLOBALS['wpdb']->last_error = '';
  $failed_closed = false;
  try { $lookup(); } catch (RuntimeException $error) {
    $failed_closed = strpos($error->getMessage(), 'lookup failed') !== false;
  }
  $check($failed_closed, 'non-array lookup result fails closed even without a database error');
}

$GLOBALS['wpdb']->row = null;
$GLOBALS['wpdb']->last_error = 'database read failed';
$failed_closed = false;
try { $lookup(); } catch (RuntimeException $error) {
  $failed_closed = strpos($error->getMessage(), 'lookup failed') !== false;
}
$check($failed_closed, 'null lookup with a database error is not treated as absence');

echo "Passed {$checks} Grosses outbox read-shape checks. No mail/provider calls were made.\n";
