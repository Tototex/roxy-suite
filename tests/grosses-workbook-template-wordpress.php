<?php
// Run with wp eval-file tests/grosses-workbook-template-wordpress.php [candidate-root].
// Builds private ZIP fixtures only; no site data or mail/provider APIs are touched.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
$root = $args[0] ?? dirname(__DIR__);
$source_path = $root . '/includes/modules/grosses/includes/class-roxy-grosses-workbook.php';
if (!is_file($source_path)) throw new RuntimeException('Grosses Workbook candidate file is missing.');

$namespace = 'RoxyGrossesTemplateFixture' . bin2hex(random_bytes(4));
$source = file_get_contents($source_path);
if (!is_string($source) || substr_count($source, 'namespace RoxyGrosses;') !== 1) throw new RuntimeException('Could not isolate the Grosses Workbook class.');
$source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $source);
eval('?>' . $source);
$workbook = $namespace . '\\Workbook';
$validate = new ReflectionMethod($workbook, 'validate_template_package');
$validate->setAccessible(true);

$dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'roxy-template-check-' . bin2hex(random_bytes(12));
if (!mkdir($dir, 0700)) throw new RuntimeException('Could not create private test fixture directory.');
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
};
$base_entries = [
    '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
    '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
    'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>',
    'xl/worksheets/sheet3.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>',
    'xl/worksheets/sheet4.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>',
];
$make_archive = static function (string $name, array $entries) use ($dir): string {
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create ZIP fixture.');
    foreach ($entries as $entry => $contents) {
        if (!$zip->addFromString($entry, $contents)) throw new RuntimeException('Could not add ZIP fixture entry.');
    }
    if (!$zip->close()) throw new RuntimeException('Could not close ZIP fixture.');
    return $path;
};
$make_duplicate_archive = static function () use ($dir, $base_entries): string {
    $path = $dir . DIRECTORY_SEPARATOR . 'duplicate.xlsx';
    $entries = $base_entries;
    $entries[] = ['xl/worksheets/sheet3.xml', $base_entries['xl/worksheets/sheet3.xml']];
    $local_records = '';
    $central_records = '';
    $offset = 0;
    $entry_count = 0;
    foreach ($entries as $name => $contents) {
        if (is_int($name)) [$name, $contents] = $contents;
        $name = (string) $name;
        $contents = (string) $contents;
        $name_bytes = $name;
        $name_length = strlen($name_bytes);
        $content_length = strlen($contents);
        $crc = hexdec(hash('crc32b', $contents));
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $content_length, $content_length, $name_length, 0)
            . $name_bytes . $contents;
        $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $content_length, $content_length,
            $name_length, 0, 0, 0, 0, 0, $offset) . $name_bytes;
        $local_records .= $local;
        $central_records .= $central;
        $offset += strlen($local);
        $entry_count++;
    }
    $end_record = pack('VvvvvVVv', 0x06054b50, 0, 0, $entry_count, $entry_count, strlen($central_records), strlen($local_records), 0);
    if (file_put_contents($path, $local_records . $central_records . $end_record) === false) {
        throw new RuntimeException('Could not create duplicate ZIP fixture.');
    }
    return $path;
};
$accepts = static function (string $path, string $name) use ($validate): bool {
    try { $validate->invoke(null, $path, $name); return true; }
    catch (Throwable $error) { return false; }
};
$paths = [];
try {
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) throw new RuntimeException('ZipArchive and DOM are required for this test.');
    $valid = $make_archive('valid.xlsx', $base_entries);
    $paths[] = $valid;
    $check($accepts($valid, 'template.xlsx'), 'valid workbook package with required Grosses sheets is accepted');
    $check(!$accepts($valid, 'template.xls'), 'non-xlsx filename is rejected');

    $missing = $make_archive('missing-sheet.xlsx', array_diff_key($base_entries, ['xl/worksheets/sheet4.xml' => true]));
    $paths[] = $missing;
    $check(!$accepts($missing, 'missing-sheet.xlsx'), 'workbook missing a required sheet is rejected');

    $invalid_xml_entries = $base_entries;
    $invalid_xml_entries['xl/workbook.xml'] = '<workbook><broken></workbook>';
    $invalid_xml = $make_archive('invalid-xml.xlsx', $invalid_xml_entries);
    $paths[] = $invalid_xml;
    $check(!$accepts($invalid_xml, 'invalid-xml.xlsx'), 'malformed required OOXML is rejected');

    $duplicate = $make_duplicate_archive();
    $paths[] = $duplicate;
    $check(!$accepts($duplicate, 'duplicate.xlsx'), 'duplicate package entry names are rejected');

    $too_many_entries = $base_entries;
    for ($i = 0; $i < 2044; $i++) $too_many_entries['custom/entry-' . $i . '.xml'] = '<x/>';
    $too_many = $make_archive('too-many.xlsx', $too_many_entries);
    $paths[] = $too_many;
    $check(!$accepts($too_many, 'too-many.xlsx'), 'archive exceeding the entry-count safety limit is rejected');

    $large_source = $dir . DIRECTORY_SEPARATOR . 'large-sparse.bin';
    $paths[] = $large_source;
    $large_handle = fopen($large_source, 'x+b');
    if (!$large_handle) throw new RuntimeException('Could not create oversized archive fixture.');
    $sparse_ready = ftruncate($large_handle, 26214401);
    fclose($large_handle);
    if (!$sparse_ready) throw new RuntimeException('Could not create oversized archive fixture.');
    $large_archive_path = $dir . DIRECTORY_SEPARATOR . 'oversized.xlsx';
    $paths[] = $large_archive_path;
    $large_archive = new ZipArchive();
    if ($large_archive->open($large_archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create oversized ZIP fixture.');
    foreach ($base_entries as $entry => $contents) {
        if (!$large_archive->addFromString($entry, $contents)) throw new RuntimeException('Could not add oversized ZIP fixture entry.');
    }
    if (!$large_archive->addFile($large_source, 'xl/media/large.bin')
        || !$large_archive->setCompressionName('xl/media/large.bin', ZipArchive::CM_STORE)
        || !$large_archive->close()) throw new RuntimeException('Could not finish oversized ZIP fixture.');
    $check(filesize($large_archive_path) > 26214400 && !$accepts($large_archive_path, 'oversized.xlsx'), 'archive exceeding the compressed-size limit is rejected');

    $not_zip = $dir . DIRECTORY_SEPARATOR . 'not-a-zip.xlsx';
    file_put_contents($not_zip, 'not an OOXML archive');
    $paths[] = $not_zip;
    $check(!$accepts($not_zip, 'not-a-zip.xlsx'), 'non-ZIP content with an xlsx extension is rejected');
} finally {
    foreach ($paths as $path) if (is_file($path)) unlink($path);
    if (is_dir($dir)) rmdir($dir);
}
echo "OK: $checks Grosses workbook-template checks passed\n";
