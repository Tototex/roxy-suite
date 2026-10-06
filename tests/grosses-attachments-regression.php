<?php
// Standalone synthetic fixtures: no WordPress database writes or real email delivery.
namespace RoxyGrosses {
  class Settings {
    public static function email_list(): array { return $GLOBALS['recipients']; }
    public static function admin_email(): string { return 'test@example.invalid'; }
    public static function get($key, $default = '') { return $default; }
  }
  class Store { public static function insert_log(...$args): void {} }
  function fputcsv($handle, $row) {
    if ($GLOBALS['write_fail'] ?? false) return false;
    return \fputcsv($handle, $row);
  }
  function fflush($handle) { return ($GLOBALS['flush_fail'] ?? false) ? false : \fflush($handle); }
  function sys_get_temp_dir() { return $GLOBALS['temp_override'] ?? \sys_get_temp_dir(); }
  function chmod($path, $mode) { return ($GLOBALS['chmod_fail'] ?? false) ? false : \chmod($path, $mode); }
}
namespace {
  define('ABSPATH', '/home1/anrvxfmy/public_html/');
  define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
  function wp_date($format) { return '2026-10-05'; }
  function sanitize_title($title) { return strtolower(preg_replace('/[^a-zA-Z0-9-]/', '-', $title)); }
  function number_format_i18n($value) { return number_format($value); }
  function wp_mail($to, $subject, $body, $headers, $attachments) {
    $GLOBALS['sent_path'] = $attachments[0];
    if (!is_readable($attachments[0])) throw new \RuntimeException('Missing mail attachment');
    $GLOBALS['sent_csv'] = file_get_contents($attachments[0]);
    if ($GLOBALS['mail_mode'] === 'throw') throw new \RuntimeException('Synthetic provider exception');
    return $GLOBALS['mail_mode'] !== 'false';
  }
  require ($argv[1] ?? dirname(__DIR__)) . '/includes/modules/grosses/includes/class-roxy-grosses-reporter.php';
  function invoke_attachment($method, ...$args) {
    return (new \ReflectionMethod(\RoxyGrosses\Reporter::class, $method))->invoke(null, ...$args);
  }
  $checks = 0;
  function attachment_assert($ok, $label) {
    if (!$ok) throw new \RuntimeException($label);
    $GLOBALS['checks']++;
    echo "PASS: $label\n";
  }
  $report = ['report_date'=>'2026-10-05', 'show_time'=>'12:00', 'theater_name'=>'Roxy', 'film_title'=>"Film, \"quoted\"\nSecond line", 'general_qty'=>2, 'discount_qty'=>3, 'group_qty'=>4, 'gross_total'=>12.34];
  $live = ['report_date'=>'2026-10-05', 'show_title'=>'Synthetic Live', 'presale_qty'=>2, 'online_qty'=>3, 'door_qty'=>4, 'group_sub_qty'=>5, 'total_tickets'=>14, 'gross_total'=>12.34, 'concessions_total'=>2.50];
  if (($argv[2] ?? '') === 'shutdown') {
    echo invoke_attachment('write_csv', [$report]);
    exit; // Production shutdown callback must remove the unsent attachment.
  }
  $a = invoke_attachment('write_csv', [$report]);
  $b = invoke_attachment('write_csv', [$report]);
  attachment_assert($a !== $b && dirname($a) !== dirname($b), 'same-date runs have separate attachment directories');
  attachment_assert(!str_starts_with($a, ABSPATH) && str_ends_with($a, '.csv'), 'attachment outside web root with friendly CSV name');
  attachment_assert((fileperms(dirname($a)) & 0777) === 0700 && (fileperms($a) & 0777) === 0600, 'directory and attachment private permissions');
  $stream = fopen($a, 'r'); $header = fgetcsv($stream); $row = fgetcsv($stream); $total = fgetcsv($stream); fclose($stream);
  attachment_assert(count($header) === 9 && $row[3] === $report['film_title'], 'original columns and multiline quote escaping preserved');
  attachment_assert($row[7] === '9' && $total[7] === '9' && $total[8] === '$12.34', 'paid attendance and gross totals preserved');
  invoke_attachment('remove_csv_attachment', $a);
  attachment_assert(!file_exists($a) && !is_dir(dirname($a)) && is_readable($b), 'cleanup isolated from another active report');
  invoke_attachment('remove_csv_attachment', $a); invoke_attachment('remove_csv_attachment', $b);
  $path = invoke_attachment('write_csv', [array_merge($report, ['report_date'=>'../../escape'])]);
  attachment_assert(dirname($path) === realpath(dirname($path)) && !str_contains(basename($path), '/'), 'unsafe date cannot escape private directory');
  invoke_attachment('remove_csv_attachment', $path);
  $path = invoke_attachment('write_live_csv', array_merge($live, ['show_title'=>str_repeat('Long title ', 80)]), false);
  attachment_assert(strlen(basename($path)) <= 180 && str_ends_with($path, '.csv'), 'long show titles retain bounded CSV attachment filenames');
  invoke_attachment('remove_csv_attachment', $path);
  foreach ([false, true] as $include) {
    $path = invoke_attachment('write_live_csv', $live, $include);
    $stream = fopen($path, 'r'); $head = fgetcsv($stream); $row = fgetcsv($stream); fclose($stream);
    attachment_assert(count($head) === ($include ? 11 : 9) && $row[8] === '$12.34' && (!$include || $row[10] === '$14.84'), 'live concessions option and monetary columns ' . (int)$include);
    invoke_attachment('remove_csv_attachment', $path);
  }
  foreach (['write_fail', 'flush_fail'] as $fault) {
    $GLOBALS[$fault] = true; $failed = false;
    try { invoke_attachment('write_csv', [$report]); } catch (\RuntimeException $e) { $failed = true; }
    $GLOBALS[$fault] = false;
    $owned = (new \ReflectionProperty(\RoxyGrosses\Reporter::class, 'csv_attachments'))->getValue();
    attachment_assert($failed && !$owned, "$fault: failed writer returns no attachment and cleans up");
  }
  $GLOBALS['recipients'] = ['test@example.invalid'];
  foreach (['success', 'false', 'throw'] as $mode) {
    $GLOBALS['mail_mode'] = $mode;
    foreach (['send_email', 'send_live_grosses_email'] as $method) {
      $result = null; $thrown = false;
      try { $result = $method === 'send_email' ? invoke_attachment($method, [$report], []) : invoke_attachment($method, $live, $GLOBALS['recipients'], true); }
      catch (\RuntimeException $e) { $thrown = true; }
      attachment_assert($mode === 'throw' ? $thrown : $result['success'] === ($mode === 'success'), "$method: mail $mode result preserved");
      attachment_assert(!file_exists($GLOBALS['sent_path']) && !is_dir(dirname($GLOBALS['sent_path'])), "$method: mail $mode cleans attachment and directory");
    }
  }
  $GLOBALS['recipients'] = [];
  $result = invoke_attachment('send_email', [$report], []);
  $owned = (new \ReflectionProperty(\RoxyGrosses\Reporter::class, 'csv_attachments'))->getValue();
  attachment_assert(!$result['success'] && !$owned, 'missing recipients cleaned without sending');
  $unowned = tempnam(sys_get_temp_dir(), 'roxy-fixture-');
  invoke_attachment('remove_csv_attachment', $unowned);
  attachment_assert(is_file($unowned), 'cleanup refuses files not owned by the request');
  unlink($unowned);
  $failed = false;
  try { invoke_attachment('write_private_csv', 'fixture.csv', static function ($handle) { throw new \RuntimeException('Writer exception'); }); }
  catch (\RuntimeException $e) { $failed = true; }
  attachment_assert($failed && !(new \ReflectionProperty(\RoxyGrosses\Reporter::class, 'csv_attachments'))->getValue(), 'writer exception cleans its file and directory');
  $GLOBALS['chmod_fail'] = true; $failed = false;
  try { invoke_attachment('write_csv', [$report]); } catch (\RuntimeException $e) { $failed = true; }
  $GLOBALS['chmod_fail'] = false;
  attachment_assert($failed && !(new \ReflectionProperty(\RoxyGrosses\Reporter::class, 'csv_attachments'))->getValue(), 'permission failure refuses attachment and cleans directory');
  foreach ([ABSPATH, '/nonexistent-roxy-report-fixture'] as $unsafe_temp) {
    $GLOBALS['temp_override'] = $unsafe_temp; $failed = false;
    try { invoke_attachment('write_csv', [$report]); } catch (\RuntimeException $e) { $failed = true; }
    attachment_assert($failed, 'unavailable or web-root temporary directory fails closed');
  }
  unset($GLOBALS['temp_override']);
  $child_path = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($argv[1] ?? dirname(__DIR__)) . ' shutdown');
  attachment_assert(str_ends_with($child_path, '.csv') && !file_exists($child_path) && !is_dir(dirname($child_path)), 'shutdown cleans an unsent attachment');
  echo "$checks attachment checks passed. No mail delivered.\n";
}
