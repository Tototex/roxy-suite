<?php
if (!defined('WP_CLI') || !WP_CLI) exit;

$called = false;
$result = roxy_eb_with_showing_time_lock('2035-10-30T14:30', 0, static function () use (&$called) {
  $called = true;
  return true;
});

if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
if ($result !== true || !$called) throw new RuntimeException('The room-lock callback did not complete.');
echo "PASS: The protected room-time transaction completed." . PHP_EOL;
