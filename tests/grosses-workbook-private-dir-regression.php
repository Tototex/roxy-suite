<?php
/** Private-directory access-rule fixtures; no WordPress data or live files touched. */
define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
$fixture_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-workbook-private-' . bin2hex(random_bytes(8));
if (!mkdir($fixture_root, 0700)) throw new RuntimeException('Could not create private fixture root.');
define('WP_CONTENT_DIR', $fixture_root . DIRECTORY_SEPARATOR . 'content');

function trailingslashit($value) { return rtrim((string) $value, '/\\') . DIRECTORY_SEPARATOR; }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }

$source_path = dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-workbook.php';
$source = file_get_contents($source_path);
if (!is_string($source) || substr_count($source, 'namespace RoxyGrosses;') !== 1) {
    throw new RuntimeException('Could not isolate the Grosses Workbook class.');
}
$namespace = 'RoxyWorkbookPrivateFixture_' . bin2hex(random_bytes(4));
$source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $source);
eval('?>' . $source);

$root_method = new ReflectionMethod($namespace . '\\Workbook', 'private_root_dir');
$root_method->setAccessible(true);
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
    $check(is_string($rules) && preg_match('/^\\s*Require\\s+all\\s+denied\\s*$/mi', $rules)
        && preg_match('/^\\s*Deny\\s+from\\s+all\\s*$/mi', $rules), 'fresh directory receives both Apache deny directives');

    if (file_put_contents($rules_path, "Require all denied\n") === false) throw new RuntimeException('Could not create partial rules fixture.');
    $root_method->invoke(null);
    $partial = file_get_contents($rules_path);
    $check(is_string($partial) && substr_count($partial, 'Require all denied') === 1
        && substr_count($partial, 'Deny from all') === 1, 'partial rules are completed without duplicating existing directives');

    if (file_put_contents($rules_path, "Options +Indexes\n") === false) throw new RuntimeException('Could not weaken fixture rules.');
    $root_method->invoke(null);
    $repaired = file_get_contents($rules_path);
    $check(is_string($repaired) && strpos($repaired, 'Options +Indexes') !== false
        && preg_match('/^\\s*Require\\s+all\\s+denied\\s*$/mi', $repaired)
        && preg_match('/^\\s*Deny\\s+from\\s+all\\s*$/mi', $repaired), 'existing weakened rules are preserved and deny directives restored');

    $root_method->invoke(null);
    $rechecked = file_get_contents($rules_path);
    $check(is_string($rechecked) && substr_count($rechecked, 'Require all denied') === 1
        && substr_count($rechecked, 'Deny from all') === 1, 'repeated verification does not duplicate access rules');
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture_root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    rmdir($fixture_root);
}

echo "Passed {$checks} private workbook directory checks.\n";
