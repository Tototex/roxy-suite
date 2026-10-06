<?php
namespace RoxyGrosses {
  class Store {
    public static function iterate_dataset(string $dataset, array $filters = []): \Generator {
      if ($dataset !== ($GLOBALS['export_dataset'] === 'unknown' ? 'movies' : $GLOBALS['export_dataset'])) throw new \RuntimeException('Wrong dataset');
      if (!$GLOBALS['export_authorized']) throw new \RuntimeException('Missing permission');
      if (!$GLOBALS['export_nonce']) throw new \RuntimeException('Missing nonce');
      if (($filters['year'] ?? 0) !== 2026) throw new \RuntimeException('Lost filters');
      for ($id = 1; $id <= 5001; $id++) {
        if ($GLOBALS['export_mode'] === 'failure' && $id === 1001) throw new \RuntimeException('Late page failed');
        yield [
          'report_date' => '2026-10-01', 'week_start_date' => '2026-10-01', 'week_end_date' => '2026-10-07',
          'movie_title' => 'Movie, "' . $id . '"', 'show_title' => 'Live ' . $id, 'rental_title' => 'Rental ' . $id,
          'gross_total' => 12.34, 'concessions_total' => 2.50, 'invoice_amount' => 3.75,
          'notes' => "Line one\nLine two", 'total_tickets' => 7, 'total_attendance' => 8,
        ];
      }
    }
  }
}
namespace {
  if (($argv[1] ?? '') === 'child') {
    define('ABSPATH', __DIR__);
    $GLOBALS['export_mode'] = $argv[2];
    $GLOBALS['export_dataset'] = $argv[3];
    $GLOBALS['export_authorized'] = false;
    $GLOBALS['export_nonce'] = false;
    function roxy_suite_user_can_access_admin() { return $GLOBALS['export_authorized'] = $GLOBALS['export_mode'] !== 'denied'; }
    function check_admin_referer($action) {
      if ($action !== 'roxy_grosses_export_csv' || $GLOBALS['export_mode'] === 'nonce') wp_die('Bad nonce');
      $GLOBALS['export_nonce'] = true;
    }
    function wp_die($message) { echo $message; exit; }
    function sanitize_key($value) { return $value; }
    function wp_unslash($value) { return $value; }
    function sanitize_text_field($value) { return $value; }
    function wp_date($format) { return '2026-10-05-120000'; }
    function nocache_headers() {}
    $_REQUEST = ['dataset' => $argv[3], 'history_year' => 2026, 'live_year' => 2026, 'rental_year' => 2026, 'legacy_year' => 2026];
    require $argv[4] . '/includes/modules/grosses/includes/class-roxy-grosses-reporter.php';
    \RoxyGrosses\Reporter::handle_export_csv();
    exit;
  }
  $root = $argv[1] ?? dirname(__DIR__);
  function export_assert($ok, $label) { if (!$ok) throw new \RuntimeException($label); echo "PASS: $label\n"; }
  foreach (['movies' => 12, 'live' => 10, 'rentals' => 9, 'legacy' => 11, 'unknown' => 12] as $dataset => $columns) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child success ' . escapeshellarg($dataset) . ' ' . escapeshellarg($root);
    $csv = shell_exec($command);
    $stream = fopen('php://temp', 'w+'); fwrite($stream, $csv); rewind($stream);
    $header = fgetcsv($stream); $count = 0; $first = null; $last = null;
    while (($row = fgetcsv($stream)) !== false) { $first ??= $row; $last = $row; $count++; }
    fclose($stream);
    export_assert(count($header) === $columns && $count === 5001 && count($first) === $columns && count($last) === $columns, "$dataset: actual handler exports 5,001 complete CSV rows with original columns");
    export_assert(in_array('$2.50', $first, true) && in_array('$2.50', $last, true), "$dataset: monetary formatting preserved");
    if ($dataset === 'rentals') export_assert($first[8] === "Line one\nLine two", 'rental multiline notes round-trip');
    if ($dataset === 'movies') export_assert($first[1] === 'Movie, "1"' && $last[1] === 'Movie, "5001"', 'movie comma/quote escaping round-trips');
  }
  foreach (['failure' => 'Could not complete CSV export. Please try again.', 'denied' => 'You do not have permission to export grosses data.', 'nonce' => 'Bad nonce'] as $mode => $expected) {
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg($mode) . ' movies ' . escapeshellarg($root));
    export_assert($output === $expected, "$mode: actual handler fails without returning a partial CSV");
  }
}
