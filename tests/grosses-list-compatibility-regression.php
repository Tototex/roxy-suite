<?php
/** Isolated PHP 8.0-compatible list semantics for Grosses classes. */
if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
$root = $argv[1] ?? dirname(__DIR__);
$files = [
  $root . '/includes/modules/grosses/includes/class-roxy-grosses-store.php',
  $root . '/includes/modules/grosses/includes/class-roxy-grosses-square.php',
  $root . '/includes/modules/grosses/includes/class-roxy-grosses-refund-snapshot.php',
];
foreach ($files as $file) {
  if (!is_file($file)) throw new RuntimeException('Missing candidate file: ' . $file);
  $source = file_get_contents($file);
  if (!is_string($source)) throw new RuntimeException('Unreadable candidate file: ' . $file);
  foreach (token_get_all($source) as $token) {
    if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'array_is_list') throw new RuntimeException('Native array_is_list remains in ' . $file);
  }
  require_once $file;
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) throw new RuntimeException('FAIL: ' . $message);
  ++$checks;
};
$cases = [
  [[] , true, 'empty array'],
  [[0 => 'a'], true, 'single sequential key'],
  [['a', 'b', 'c'], true, 'sequential keys'],
  [[0 => 'a', 2 => 'b'], false, 'sparse keys'],
  [['first' => 'a'], false, 'associative keys'],
  [[1 => 'a', 0 => 'b'], false, 'out-of-order keys'],
];
foreach ([
  'RoxyGrosses\\Store',
  'RoxyGrosses\\Square',
  'RoxyGrosses\\RefundSnapshot',
] as $class) {
  $method = new ReflectionMethod($class, 'is_list');
  $method->setAccessible(true);
  foreach ($cases as [$value, $expected, $label]) {
    $check($method->invoke(null, $value) === $expected, $class . ' ' . $label);
  }
}
echo "Passed {$checks} Grosses list-compatibility checks.\n";
