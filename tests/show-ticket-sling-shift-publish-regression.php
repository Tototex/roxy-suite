<?php
/** Isolated Sling shift publication-setting regression; no provider requests or post writes. */
define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
require __DIR__ . '/../includes/modules/show-tickets/includes/class-roxy-st-sling-shifts.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) throw new RuntimeException('FAIL: ' . $message);
  ++$checks;
  echo 'PASS: ' . $message . PHP_EOL;
};

$assert(\RoxyST\SlingShifts::publish_immediately([]) === false,
  'default/off setting does not publish immediately');
$assert(\RoxyST\SlingShifts::publish_immediately(['sling_publish_shifts' => 0]) === false,
  'explicitly disabled setting does not publish immediately');
$assert(\RoxyST\SlingShifts::publish_immediately(['sling_publish_shifts' => 1]) === true,
  'enabled setting requests immediate publication');

echo 'Passed ' . $checks . ' Sling shift publication checks.' . PHP_EOL;
