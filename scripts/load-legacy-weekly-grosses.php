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
  'dry-run',
]);

$wpLoad = isset($options['wp-load']) ? (string) $options['wp-load'] : '';
$csvPath = isset($options['csv']) ? (string) $options['csv'] : '';
$mode = isset($options['mode']) ? (string) $options['mode'] : 'update';
$dryRun = array_key_exists('dry-run', $options);

if ($wpLoad === '' || $csvPath === '') {
  fwrite(STDERR, "Usage: php load-legacy-weekly-grosses.php --wp-load=/path/to/wp-load.php --csv=/path/to/old-roxy-weekly-import.csv [--mode=update|skip] [--dry-run]\n");
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

  $weekStartDate = trim((string) ($row['week_start_date'] ?? ''));
  $movieTitle = trim((string) ($row['movie_title'] ?? ''));
  if ($weekStartDate === '' || $movieTitle === '') {
    fwrite(STDERR, "Skipping line {$lineNumber}: missing week_start_date or movie_title.\n");
    continue;
  }

  $rows[] = [
    'week_start_date' => $weekStartDate,
    'week_end_date' => trim((string) ($row['week_end_date'] ?? '')),
    'week_label' => trim((string) ($row['week_label'] ?? '')),
    'movie_title' => $movieTitle,
    'rating' => trim((string) ($row['rating'] ?? '')),
    'weeks_run' => trim((string) ($row['weeks_run'] ?? '')),
    'general_qty' => max(0, (int) ($row['general_qty'] ?? 0)),
    'discount_qty' => max(0, (int) ($row['discount_qty'] ?? 0)),
    'free_qty' => max(0, (int) ($row['free_qty'] ?? 0)),
    'total_attendance' => max(0, (int) ($row['total_attendance'] ?? 0)),
    'source_type' => trim((string) ($row['source_type'] ?? 'old_owner_weekly_import')) ?: 'old_owner_weekly_import',
    'source_file' => trim((string) ($row['source_file'] ?? '')),
    'notes' => trim((string) ($row['notes'] ?? 'Imported from prior owner weekly workbook')),
  ];
}

fclose($handle);

if ($dryRun) {
  $preview = array_slice($rows, 0, 5);
  fwrite(STDOUT, "Dry run only. Parsed " . count($rows) . " rows.\n");
  fwrite(STDOUT, wp_json_encode($preview, JSON_PRETTY_PRINT) . "\n");
  exit(0);
}

$result = \RoxyGrosses\Store::upsert_legacy_weekly($rows, $mode);
\RoxyGrosses\Store::insert_log(
  'legacy_weekly_import',
  'legacy-weekly',
  null,
  null,
  true,
  sprintf(
    'Imported legacy weekly workbook CSV %s. %d created, %d updated, %d skipped.',
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
    "Imported %d legacy weekly rows from %s\nCreated: %d\nUpdated: %d\nSkipped: %d\n",
    count($rows),
    $csvPath,
    (int) ($result['created'] ?? 0),
    (int) ($result['updated'] ?? 0),
    (int) ($result['skipped'] ?? 0)
  )
);
