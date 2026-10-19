<?php
// Verify the production Arcade award-lock helper against independent MySQL sessions.
if (!defined('WP_CLI') || !WP_CLI) exit;
$root = $args[0] ?? dirname(__DIR__);
$source = file_get_contents($root . '/includes/modules/arcade/roxy-arcade.php');
if (!is_string($source)) throw new RuntimeException('Could not load Arcade source.');
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$source = preg_replace("/define\('ROXY_ARCADE_VERSION', '[^']+'\);/", '', $source);
$source = str_replace(['class Roxy_Arcade {', 'Roxy_Arcade::init();'], ['class ArcadeAwardLockFixture {', ''], $source);
eval($source);

$acquire = new ReflectionMethod('ArcadeAwardLockFixture', 'acquire_award_lock');
$release = new ReflectionMethod('ArcadeAwardLockFixture', 'release_award_lock');
$owns = new ReflectionMethod('ArcadeAwardLockFixture', 'award_lock_is_owned');
$name_for_month = new ReflectionMethod('ArcadeAwardLockFixture', 'award_lock_name');
$first = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$second = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$first_lock = null;
$second_lock = null;
$check = static function ($condition, $label) {
    if (!$condition) throw new RuntimeException($label);
    echo "PASS: $label\n";
};

try {
    $month = '2099-08';
    $name = $name_for_month->invoke(null, $month);
    $check(strlen($name) <= 64 && strpos($name, 'roxy_arcade_award_') === 0
        && $name === $name_for_month->invoke(null, $month),
        'production lock name is deterministic, plugin-namespaced, and within 64 characters');
    $first_lock = $acquire->invoke(null, $month, $first);
    $check(is_array($first_lock) && $owns->invoke(null, $first_lock),
        'connection A acquires the production lock and verifies its connection ownership');
    $second_lock = $acquire->invoke(null, $month, $second);
    $check($second_lock === false && $owns->invoke(null, $first_lock),
        'connection B is rejected while independent connection A owns the lock');

    $release->invoke(null, $first_lock);
    $first_lock = null;
    $second_lock = $acquire->invoke(null, $month, $second);
    $check(is_array($second_lock) && $owns->invoke(null, $second_lock),
        'connection B acquires the lock after connection A releases it');

    $misdirected = $second_lock;
    $misdirected['db'] = $first;
    $release->invoke(null, $misdirected);
    $check($owns->invoke(null, $second_lock),
        'release attempted through a non-owner connection leaves B’s lock intact');
    $check($acquire->invoke(null, $month, $second) === false && $owns->invoke(null, $second_lock),
        'same-process reacquisition remains blocked after a misdirected release attempt');
    $release->invoke(null, $second_lock);
    $second_lock = null;

    $first_lock = $acquire->invoke(null, $month, $first);
    $check(is_array($first_lock) && $owns->invoke(null, $first_lock),
        'lock can be reopened by connection A after the previous owner releases it');
    $release->invoke(null, $first_lock);
    $first_lock = null;
} finally {
    if (is_array($first_lock)) $release->invoke(null, $first_lock);
    if (is_array($second_lock)) $release->invoke(null, $second_lock);
    $first->close();
    $second->close();
}
