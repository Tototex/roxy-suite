<?php
// Isolated workbook snapshot test. It uses a private temp fixture and never contacts WordPress or sends mail.
namespace {
  if (!defined('ABSPATH')) define('ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR);
  if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive is required for the workbook atomicity regression.');

  $fixture_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-workbook-atomic-' . bin2hex(random_bytes(8));
  if (!mkdir($fixture_root, 0700)) throw new RuntimeException('Could not create workbook fixture root.');
  $workbook_dir = $fixture_root . DIRECTORY_SEPARATOR . 'workbooks';
  $template_path = $fixture_root . DIRECTORY_SEPARATOR . 'template.xlsx';
  $snapshot_path = $workbook_dir . DIRECTORY_SEPARATOR . 'roxy-box-office-2026.xlsx';
  $collision_path = $workbook_dir . DIRECTORY_SEPARATOR . '.roxy-box-office-2026-123e4567-e89b-42d3-a456-426614174002.tmp.xlsx';

  function trailingslashit($path): string { return rtrim((string) $path, '/\\') . DIRECTORY_SEPARATOR; }
  function wp_generate_uuid4(): string {
    if (!empty($GLOBALS['workbook_uuid_queue'])) return array_shift($GLOBALS['workbook_uuid_queue']);
    throw new RuntimeException('Unexpected extra workbook UUID request.');
  }

  $replace_method = static function (string $source, string $signature, string $body): string {
    $start = strpos($source, $signature);
    if ($start === false) throw new RuntimeException('Workbook fixture method not found: ' . $signature);
    $next = preg_match('/\n  (?:public|private|protected) static function /', $source, $match, PREG_OFFSET_CAPTURE, $start + strlen($signature))
      ? $match[0][1] : strlen($source);
    $brace = strpos($source, '{', $start + strlen($signature));
    if ($brace === false || $brace >= $next) throw new RuntimeException('Workbook fixture method body not found: ' . $signature);
    return substr_replace($source, '{ ' . $body . " }\n", $brace, $next - $brace);
  };

  $source_path = dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-workbook.php';
  $source = file_get_contents($source_path);
  if (!is_string($source)) throw new RuntimeException('Could not read workbook source.');
  $source = $replace_method($source, 'public static function weekly_rows_for_year(int $year): array', 'return [];');
  $source = $replace_method($source, 'private static function resolve_template_path(int $year): string', 'return $GLOBALS[\'workbook_template_path\'];');
  $source = $replace_method($source, 'private static function private_workbooks_dir(): string', 'return $GLOBALS[\'workbook_fixture_dir\'];');
  $source = $replace_method($source, 'private static function populate_weekly_log_sheet(string $xml, array $weekly_rows): string', 'return $xml;');
  $source = $replace_method($source, 'private static function populate_setup_sheet(string $xml, int $year): string', 'return $xml;');
  $source = $replace_method($source, 'private static function force_recalculate_workbook(string $xml): string', 'return $xml;');
  eval('?>' . $source);

  $GLOBALS['workbook_template_path'] = $template_path;
  $GLOBALS['workbook_fixture_dir'] = $workbook_dir;
  $GLOBALS['workbook_uuid_queue'] = [
    '123e4567-e89b-42d3-a456-426614174001',
    '123e4567-e89b-42d3-a456-426614174002',
    '123e4567-e89b-42d3-a456-426614174003',
  ];
  $checks = 0;
  $check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
    echo "PASS: {$message}\n";
  };

  try {
    if (!mkdir($workbook_dir, 0700)) throw new RuntimeException('Could not create workbook output directory.');
    $zip = new \ZipArchive();
    if ($zip->open($template_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create workbook template fixture.');
    $zip->addFromString('xl/worksheets/sheet3.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>');
    $zip->addFromString('xl/worksheets/sheet4.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>');
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><calcPr/></workbook>');
    if (!$zip->close()) throw new RuntimeException('Could not finalize workbook template fixture.');

    file_put_contents($snapshot_path, 'previous complete snapshot');
    $built_path = \RoxyGrosses\Workbook::build_workbook_file(2026);
    $check($built_path === $snapshot_path && filesize($built_path) > strlen('previous complete snapshot'), 'completed workbook atomically replaces the saved private snapshot');
    $built_hash = hash_file('sha256', $snapshot_path);
    $zip = new \ZipArchive();
    $check($zip->open($snapshot_path) === true, 'published snapshot is a readable workbook archive');
    $check(is_string($zip->getFromName('xl/worksheets/sheet3.xml')) && is_string($zip->getFromName('xl/worksheets/sheet4.xml')), 'completed snapshot retains all required worksheets');
    $zip->close();

    $zip = new \ZipArchive();
    if ($zip->open($template_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not replace workbook template fixture.');
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><calcPr/></workbook>');
    if (!$zip->close()) throw new RuntimeException('Could not finalize invalid workbook template fixture.');
    file_put_contents($collision_path, 'existing generation');
    $failed = false;
    try { \RoxyGrosses\Workbook::build_workbook_file(2026); } catch (\Throwable $error) { $failed = true; }
    $check($failed && hash_file('sha256', $snapshot_path) === $built_hash, 'failed generation preserves the prior complete snapshot');
    $check(is_file($collision_path) && file_get_contents($collision_path) === 'existing generation', 'temporary filename collision retries without overwriting another generation');
    $temporary_files = glob($workbook_dir . DIRECTORY_SEPARATOR . '.roxy-box-office-*') ?: [];
    $check(count($temporary_files) === 1 && $temporary_files[0] === $collision_path, 'failed generation removes its temporary file and leaves only the collision sentinel');
    $check($GLOBALS['workbook_uuid_queue'] === [], 'forced temporary filename collision retries with a fresh identifier');
    echo "Passed {$checks} workbook snapshot atomicity checks.\n";
  } finally {
    foreach (glob($workbook_dir . DIRECTORY_SEPARATOR . '.roxy-box-office-*') ?: [] as $path) if (is_file($path)) unlink($path);
    foreach ([$snapshot_path, $template_path] as $path) if (is_file($path)) unlink($path);
    if (is_dir($workbook_dir)) rmdir($workbook_dir);
    if (is_dir($fixture_root)) rmdir($fixture_root);
  }
}
