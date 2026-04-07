<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "This script must be run from the command line.\n");
  exit(1);
}

$options = getopt('', [
  'wp-load:',
  'csv:',
  'mode::',
  'theater::',
  'dry-run',
]);

$wpLoad = isset($options['wp-load']) ? (string) $options['wp-load'] : '';
$csvPath = isset($options['csv']) ? (string) $options['csv'] : '';
$mode = isset($options['mode']) ? (string) $options['mode'] : 'update';
$defaultTheater = isset($options['theater']) ? (string) $options['theater'] : 'Newport Roxy Theater';
$dryRun = array_key_exists('dry-run', $options);

if ($wpLoad === '' || $csvPath === '') {
  fwrite(STDERR, "Usage: php load-legacy-grosses.php --wp-load=/path/to/wp-load.php --csv=/path/to/legacy-grosses-import-all.csv [--mode=update|skip] [--theater='Newport Roxy Theater'] [--dry-run]\n");
  exit(1);
}

if (!in_array($mode, ['update', 'skip'], true)) {
  fwrite(STDERR, "Invalid mode. Use --mode=update or --mode=skip.\n");
  exit(1);
}

if (!is_file($wpLoad)) {
  fwrite(STDERR, "wp-load.php not found at: {$wpLoad}\n");
  exit(1);
}

if (!is_file($csvPath)) {
  fwrite(STDERR, "CSV not found at: {$csvPath}\n");
  exit(1);
}

require_once $wpLoad;

$pluginBootstrap = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'roxy-suite.php';
if (!class_exists('RoxyGrosses\\Store') && is_file($pluginBootstrap)) {
  require_once $pluginBootstrap;
}

if (!class_exists('RoxyGrosses\\Store')) {
  fwrite(STDERR, "Could not load Roxy Grosses store class.\n");
  exit(1);
}

\RoxyGrosses\Store::maybe_upgrade_schema();
\RoxyGrosses\Store::maybe_backfill_history();

$handle = fopen($csvPath, 'rb');
if ($handle === false) {
  fwrite(STDERR, "Could not open CSV: {$csvPath}\n");
  exit(1);
}

$header = fgetcsv($handle);
if (!is_array($header)) {
  fclose($handle);
  fwrite(STDERR, "CSV is empty or invalid: {$csvPath}\n");
  exit(1);
}

$header = array_map(static function ($value): string {
  $value = (string) $value;
  $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
  return trim($value, " \t\n\r\0\x0B\"'");
}, $header);

$rows = [];
$lineNumber = 1;

while (($data = fgetcsv($handle)) !== false) {
  $lineNumber++;
  if (count($data) !== count($header)) {
    fwrite(STDERR, "Skipping line {$lineNumber}: column count mismatch.\n");
    continue;
  }

  $row = array_combine($header, $data);
  if (!is_array($row)) {
    fwrite(STDERR, "Skipping line {$lineNumber}: could not read CSV row.\n");
    continue;
  }

  $reportDate = trim((string) ($row['report_date'] ?? ''));
  $movieTitle = trim((string) ($row['movie_title'] ?? ''));
  if ($reportDate === '' || $movieTitle === '') {
    fwrite(STDERR, "Skipping line {$lineNumber}: missing report_date or movie_title.\n");
    continue;
  }

  $rows[] = [
    'report_date' => $reportDate,
    'movie_title' => $movieTitle,
    'show_time' => trim((string) ($row['show_time'] ?? '')),
    'showing_id' => null,
    'theater_name' => trim((string) ($row['theater_name'] ?? '')) !== '' ? trim((string) $row['theater_name']) : $defaultTheater,
    'general_qty' => max(0, (int) ($row['general_qty'] ?? 0)),
    'discount_qty' => max(0, (int) ($row['discount_qty'] ?? 0)),
    'group_qty' => max(0, (int) ($row['group_qty'] ?? 0)),
    'subscriber_qty' => 0,
    'live_qty' => 0,
    'other_qty' => 0,
    'total_tickets' => max(0, (int) ($row['total_tickets'] ?? 0)),
    'gross_total' => round((float) ($row['gross_total'] ?? 0), 2),
    'source_type' => trim((string) ($row['source_type'] ?? 'legacy_excel_import')) !== '' ? trim((string) $row['source_type']) : 'legacy_excel_import',
    'source_ref' => trim((string) ($row['source_ref'] ?? 'legacy_excel')) !== '' ? trim((string) $row['source_ref']) : 'legacy_excel',
    'source_file' => trim((string) ($row['source_file'] ?? '')),
    'notes' => trim((string) ($row['notes'] ?? 'Imported from legacy weekly grosses workbook')),
    'is_locked' => 0,
  ];
}

fclose($handle);

if ($dryRun) {
  $preview = array_slice($rows, 0, 5);
  fwrite(STDOUT, "Dry run only. Parsed " . count($rows) . " rows.\n");
  fwrite(STDOUT, wp_json_encode($preview, JSON_PRETTY_PRINT) . "\n");
  exit(0);
}

$result = \RoxyGrosses\Store::upsert_entries($rows, $mode);
\RoxyGrosses\Store::insert_log(
  'legacy_import',
  'legacy-excel',
  null,
  null,
  true,
  sprintf(
    'Imported legacy grosses CSV %s. %d created, %d updated, %d skipped.',
    basename($csvPath),
    (int) ($result['created'] ?? 0),
    (int) ($result['updated'] ?? 0),
    (int) ($result['skipped'] ?? 0)
  ),
  [
    'csv_path' => $csvPath,
    'row_count' => count($rows),
    'mode' => $mode,
  ]
);

fwrite(
  STDOUT,
  sprintf(
    "Imported %d rows from %s\nCreated: %d\nUpdated: %d\nSkipped: %d\n",
    count($rows),
    $csvPath,
    (int) ($result['created'] ?? 0),
    (int) ($result['updated'] ?? 0),
    (int) ($result['skipped'] ?? 0)
  )
);
