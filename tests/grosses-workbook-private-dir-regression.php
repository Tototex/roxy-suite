<?php
/** Private-directory access-rule fixtures; no WordPress data or live files touched. */
define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
$fixture_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-workbook-private-' . bin2hex(random_bytes(8));
if (!mkdir($fixture_root, 0700)) throw new RuntimeException('Could not create private fixture root.');
define('WP_CONTENT_DIR', $fixture_root . DIRECTORY_SEPARATOR . 'content');

class Settings { public const OPTION_KEY = 'roxy_grosses_fixture_settings'; }
function get_option($key, $default = false) { return $GLOBALS['workbook_options'][$key] ?? $default; }
function update_option($key, $value) {
    if (!empty($GLOBALS['workbook_option_write_fails'])) return false;
    $GLOBALS['workbook_options'][$key] = $value;
    return true;
}
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_file_name($value) { return basename(preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $value)); }
function wp_normalize_path($value) { return str_replace('\\', '/', (string) $value); }
function trailingslashit($value) { return rtrim((string) $value, '/\\') . DIRECTORY_SEPARATOR; }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
function wp_unique_filename($dir, $filename) {
    if (!empty($GLOBALS['workbook_unique_candidates'])) return array_shift($GLOBALS['workbook_unique_candidates']);
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $candidate = $filename;
    $suffix = 1;
    while (file_exists(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $candidate)) {
        $candidate = $name . '-' . $suffix++ . ($extension !== '' ? '.' . $extension : '');
    }
    return $candidate;
}

$source_path = dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-workbook.php';
$source = file_get_contents($source_path);
if (!is_string($source) || substr_count($source, 'namespace RoxyGrosses;') !== 1) {
    throw new RuntimeException('Could not isolate the Grosses Workbook class.');
}
$namespace = 'RoxyWorkbookPrivateFixture_' . bin2hex(random_bytes(4));
eval('namespace ' . $namespace . '; class Settings { public const OPTION_KEY = "roxy_grosses_fixture_settings"; }');
$source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $source);
eval('?>' . $source);

$root_method = new ReflectionMethod($namespace . '\\Workbook', 'private_root_dir');
$root_method->setAccessible(true);
$workbooks_method = new ReflectionMethod($namespace . '\\Workbook', 'private_workbooks_dir');
$workbooks_method->setAccessible(true);
$move_method = new ReflectionMethod($namespace . '\\Workbook', 'move_file_to_private_storage');
$move_method->setAccessible(true);
$set_template_method = new ReflectionMethod($namespace . '\\Workbook', 'set_uploaded_template');
$set_template_method->setAccessible(true);
$ensure_template_method = new ReflectionMethod($namespace . '\\Workbook', 'ensure_uploaded_template_private');
$ensure_template_method->setAccessible(true);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
};

try {
    $private_dir = $root_method->invoke(null);
    $index = $private_dir . DIRECTORY_SEPARATOR . 'index.html';
    $rules_path = $private_dir . DIRECTORY_SEPARATOR . '.htaccess';
    $rules = file_get_contents($rules_path);
    $check(is_file($index) && is_readable($index), 'private directory creates a readable index file');
    $canonical_rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
    $check($rules === $canonical_rules, 'fresh directory receives version-compatible deny-only rules');

    if (file_put_contents($rules_path, "Require all denied\n") === false) throw new RuntimeException('Could not create partial rules fixture.');
    $root_method->invoke(null);
    $partial = file_get_contents($rules_path);
    $check($partial === $canonical_rules, 'partial rules are replaced with the canonical deny-only policy');

    if (file_put_contents($rules_path, "Options +Indexes\nRequire all granted\n") === false) throw new RuntimeException('Could not weaken fixture rules.');
    $root_method->invoke(null);
    $repaired = file_get_contents($rules_path);
    $check($repaired === $canonical_rules && strpos($repaired, 'Require all granted') === false
        && strpos($repaired, 'Options +Indexes') === false, 'weakened or conflicting rules are replaced by deny-only policy');

    $root_method->invoke(null);
    $rechecked = file_get_contents($rules_path);
    $check($rechecked === $canonical_rules, 'repeated verification leaves the canonical policy unchanged');

    $workbooks_dir = $workbooks_method->invoke(null);
    $occupied = $workbooks_dir . DIRECTORY_SEPARATOR . 'collision.xlsx';
    $source = $fixture_root . DIRECTORY_SEPARATOR . 'source.xlsx';
    file_put_contents($occupied, 'preserve-existing-file');
    file_put_contents($source, 'new-template-bytes');
    $GLOBALS['workbook_unique_candidates'] = ['collision.xlsx', 'collision-1.xlsx'];
    $moved = $move_method->invoke(null, $source, $workbooks_dir, 'collision.xlsx');
    $check(file_get_contents($occupied) === 'preserve-existing-file', 'exclusive move preserves a destination created during the filename race');
    $check($moved !== $occupied && file_get_contents($moved) === 'new-template-bytes' && !file_exists($source), 'private move retries collision and verifies complete contents before removing source');

    $template = ['path' => $moved, 'name' => 'collision.xlsx', 'uploaded_at' => '2026-10-08 12:00:00'];
    $check($set_template_method->invoke(null, $template) === true, 'uploaded template setting is confirmed by read-back');
    $GLOBALS['workbook_option_write_fails'] = true;
    $old_settings = $GLOBALS['workbook_options'][Settings::OPTION_KEY];
    $failed_template = ['path' => $workbooks_dir . DIRECTORY_SEPARATOR . 'not-saved.xlsx', 'name' => 'not-saved.xlsx', 'uploaded_at' => '2026-10-08 13:00:00'];
    $check($set_template_method->invoke(null, $failed_template) === false
        && $GLOBALS['workbook_options'][Settings::OPTION_KEY] === $old_settings, 'failed setting write is reported and preserves the existing template setting');

    $legacy = $fixture_root . DIRECTORY_SEPARATOR . 'legacy-template.xlsx';
    file_put_contents($legacy, 'preserve-public-source-on-save-failure');
    $GLOBALS['workbook_options'][Settings::OPTION_KEY]['workbook_template_upload'] = [
        'path' => $legacy, 'name' => 'legacy-template.xlsx', 'uploaded_at' => '2026-10-07 10:00:00',
    ];
    $migration_failed = false;
    try { $ensure_template_method->invoke(null); } catch (RuntimeException $error) { $migration_failed = true; }
    $private_templates = $workbooks_dir;
    $private_copies = glob($private_templates . DIRECTORY_SEPARATOR . 'legacy-template*.xlsx') ?: [];
    $check($migration_failed && file_get_contents($legacy) === 'preserve-public-source-on-save-failure' && !$private_copies,
        'failed legacy migration preserves the old source and removes its unreferenced private copy');
    unset($GLOBALS['workbook_option_write_fails']);
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture_root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    rmdir($fixture_root);
}

echo "Passed {$checks} private workbook directory checks.\n";
