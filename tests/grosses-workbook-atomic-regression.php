<?php
// Isolated workbook snapshot test. It uses a private temp fixture and never contacts WordPress or sends mail.
namespace RoxyGrosses {
  final class Settings {
    public static function get(string $key, $default = '') {
      $values = ['general_price' => '12', 'discount_price' => '8', 'group_price' => '5'];
      return $values[$key] ?? $default;
    }
  }
}

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
  $source = $replace_method($source, 'public static function weekly_rows_for_year(int $year): array', 'return $GLOBALS[\'workbook_weekly_rows\'];');
  $source = $replace_method($source, 'private static function resolve_template_path(int $year): string', 'return $GLOBALS[\'workbook_template_path\'];');
  $source = $replace_method($source, 'private static function private_workbooks_dir(): string', 'return $GLOBALS[\'workbook_fixture_dir\'];');
  eval('?>' . $source);

  $GLOBALS['workbook_template_path'] = $template_path;
  $GLOBALS['workbook_fixture_dir'] = $workbook_dir;
  $GLOBALS['workbook_weekly_rows'] = [[
    'film_title' => 'Fixture Film', 'studio' => 'Roxy Distribution', 'prepared_by' => 'Fixture Staff',
    'submitted' => 'Yes', 'fri_gen' => 4, 'fri_disc' => 2, 'fri_group' => 1,
    'sat_gen' => 3, 'notes' => 'Sample workbook row',
  ]];
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
    $sheet_cells = [];
    foreach (['C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z','AA','AH'] as $column) {
      $sheet_cells[] = '<c r="' . $column . '7"><v>0</v></c>';
    }
    $zip->addFromString('xl/worksheets/sheet3.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="7">' . implode('', $sheet_cells) . '</row></sheetData></worksheet>');
    $zip->addFromString('xl/worksheets/sheet4.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="4"><c r="B4"><v>0</v></c></row><row r="10"><c r="B10"><v>0</v></c></row><row r="11"><c r="B11"><v>0</v></c></row><row r="12"><c r="B12"><v>0</v></c></row></sheetData></worksheet>');
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><calcPr/></workbook>');
    if (!$zip->close()) throw new RuntimeException('Could not finalize workbook template fixture.');

    file_put_contents($snapshot_path, 'previous complete snapshot');
    $built_path = \RoxyGrosses\Workbook::build_workbook_file(2026);
    $check($built_path === $snapshot_path && filesize($built_path) > strlen('previous complete snapshot'), 'completed workbook atomically replaces the saved private snapshot');
    $built_hash = hash_file('sha256', $snapshot_path);
    $zip = new \ZipArchive();
    $check($zip->open($snapshot_path) === true, 'published snapshot is a readable workbook archive');
    $check(is_string($zip->getFromName('xl/worksheets/sheet3.xml')) && is_string($zip->getFromName('xl/worksheets/sheet4.xml')), 'completed snapshot retains all required worksheets');
    $sheet3_xml = $zip->getFromName('xl/worksheets/sheet3.xml');
    $zip->close();
    $sheet3 = new \DOMDocument();
    $check(is_string($sheet3_xml) && $sheet3->loadXML($sheet3_xml), 'populated weekly worksheet remains valid XML');
    $sheet3_xpath = new \DOMXPath($sheet3);
    $sheet3_xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $cell_text = static function (\DOMXPath $xpath, string $ref): string {
      $node = $xpath->query('//m:c[@r="' . $ref . '"]/m:is/m:t | //m:c[@r="' . $ref . '"]/m:v')->item(0);
      return $node ? (string) $node->textContent : '';
    };
    $check($cell_text($sheet3_xpath, 'C7') === 'Fixture Film' && $cell_text($sheet3_xpath, 'D7') === 'Roxy Distribution'
      && $cell_text($sheet3_xpath, 'E7') === 'Fixture Staff' && $cell_text($sheet3_xpath, 'F7') === 'Yes',
      'weekly title, studio, preparer, and submitted state populate their expected cells');
    $check($cell_text($sheet3_xpath, 'G7') === '4' && $cell_text($sheet3_xpath, 'H7') === '2'
      && $cell_text($sheet3_xpath, 'I7') === '1' && $cell_text($sheet3_xpath, 'J7') === '3'
      && $cell_text($sheet3_xpath, 'AA7') === '0', 'weekly ticket counts populate the expected weekday/category cells');
    $check($cell_text($sheet3_xpath, 'AH7') === 'Sample workbook row', 'weekly notes populate the expected notes cell');
    $zip = new \ZipArchive();
    $zip->open($snapshot_path);
    $sheet4_xml = $zip->getFromName('xl/worksheets/sheet4.xml');
    $workbook_xml = $zip->getFromName('xl/workbook.xml');
    $zip->close();
    $sheet4 = new \DOMDocument();
    $sheet4->loadXML((string) $sheet4_xml);
    $sheet4_xpath = new \DOMXPath($sheet4);
    $sheet4_xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $setup_value = static function (\DOMXPath $xpath, string $ref): string {
      $node = $xpath->query('//m:c[@r="' . $ref . '"]/m:v')->item(0);
      return $node ? (string) $node->textContent : '';
    };
    $check($setup_value($sheet4_xpath, 'B4') === '2026' && $setup_value($sheet4_xpath, 'B10') === '12'
      && $setup_value($sheet4_xpath, 'B11') === '8' && $setup_value($sheet4_xpath, 'B12') === '5',
      'setup worksheet receives the report year and configured ticket prices');
    $workbook_doc = new \DOMDocument();
    $workbook_doc->loadXML((string) $workbook_xml);
    $workbook_xpath = new \DOMXPath($workbook_doc);
    $workbook_xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $calc = $workbook_xpath->query('//m:calcPr')->item(0);
    $check($calc instanceof \DOMElement && $calc->getAttribute('calcMode') === 'auto'
      && $calc->getAttribute('fullCalcOnLoad') === '1' && $calc->getAttribute('forceFullCalc') === '1',
      'completed workbook requests full formula recalculation on open');

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
