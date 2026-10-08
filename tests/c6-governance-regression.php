<?php
/**
 * Isolated C6 governance regression checks. All options and roles below are
 * in-memory fakes; this test never boots WordPress or touches a real install.
 */

$GLOBALS['c6_fake_options'] = [];
$GLOBALS['c6_option_writes'] = [];
$GLOBALS['c6_fake_roles'] = [];
$GLOBALS['c6_noop_writes'] = false;

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['c6_fake_options'])
        ? $GLOBALS['c6_fake_options'][$name]
        : $default;
}

function update_option($name, $value) {
    $GLOBALS['c6_option_writes'][] = [$name, $value, $GLOBALS['c6_fake_wpdb']->lock_held];
    if (!$GLOBALS['c6_noop_writes']) {
        $GLOBALS['c6_fake_options'][$name] = $value;
    }
    return true;
}

function get_role($name) {
    return $GLOBALS['c6_fake_roles'][$name] ?? null;
}

final class C6FakeWpdb {
    public string $prefix = 'wp_';
    public bool $lock_held = false;
    public bool $lock_busy = false;
    public int $lock_attempts = 0;

    public function prepare(string $query, ...$args): array {
        return ['query' => $query, 'args' => $args];
    }

    public function get_var(array $query) {
        if (str_contains($query['query'], 'GET_LOCK')) {
            $this->lock_attempts++;
            if ($this->lock_busy || $this->lock_held) return null;
            $this->lock_held = true;
            return '1';
        }
        if (str_contains($query['query'], 'RELEASE_LOCK')) {
            $this->lock_held = false;
            return '1';
        }
        return null;
    }
}

$GLOBALS['c6_fake_wpdb'] = new C6FakeWpdb();
$GLOBALS['wpdb'] = $GLOBALS['c6_fake_wpdb'];

final class C6FakeRole {
    public array $caps = [];
    public int $add_calls = 0;

    public function has_cap($cap): bool {
        return !empty($this->caps[$cap]);
    }

    public function add_cap($cap): void {
        $this->caps[$cap] = true;
        $this->add_calls++;
    }
}

require_once dirname(__DIR__) . '/includes/class-roxy-suite-governance.php';

function c6_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function c6_reset_module_cache(): void {
    $class = new ReflectionClass(\RoxySuite\Governance::class);
    $loaded = $class->getProperty('module_flags_loaded');
    $loaded->setAccessible(true);
    $loaded->setValue(null, false);
    $flags = $class->getProperty('module_flags');
    $flags->setAccessible(true);
    $flags->setValue(null, null);
}

function c6_reset_fake_database(): void {
    $GLOBALS['c6_fake_wpdb']->lock_held = false;
    $GLOBALS['c6_fake_wpdb']->lock_busy = false;
    $GLOBALS['c6_fake_wpdb']->lock_attempts = 0;
    $GLOBALS['c6_noop_writes'] = false;
}

$legacy_modules = [
    'arcade', 'sub_check', 'will_call', 'show_tickets', 'requested_showings',
    'event_booking', 'grosses', 'inventory', 'social_publisher',
];

// A truly absent option migrates to the prior all-enabled baseline exactly once.
c6_reset_module_cache();
$GLOBALS['c6_fake_options'] = [];
$GLOBALS['c6_option_writes'] = [];
c6_reset_fake_database();
c6_check(\RoxySuite\Governance::module_settings_are_valid(), 'missing option should migrate as valid');
c6_check(count($GLOBALS['c6_option_writes']) === 1, 'missing option should be written once');
c6_check($GLOBALS['c6_option_writes'][0][2] === true, 'missing-option migration must write under DB lock');
c6_check(!$GLOBALS['c6_fake_wpdb']->lock_held, 'migration must release DB lock');
foreach ($legacy_modules as $module) {
    c6_check(\RoxySuite\Governance::module_enabled($module), "legacy module {$module} should preserve enabled baseline");
}
c6_check(!\RoxySuite\Governance::module_enabled('future_module'), 'unknown/future module should remain disabled');
c6_check(count($GLOBALS['c6_option_writes']) === 1, 'reads after migration should not rewrite the option');

// Existing explicit booleans win; absent legacy keys retain their prior default.
c6_reset_module_cache();
$GLOBALS['c6_option_writes'] = [];
$GLOBALS['c6_fake_options'] = [
    'roxy_suite_modules' => ['arcade' => false, 'inventory' => true, 'future_module' => true],
];
c6_reset_fake_database();
c6_check(\RoxySuite\Governance::module_settings_are_valid(), 'valid existing option should remain valid');
c6_check(!\RoxySuite\Governance::module_enabled('arcade'), 'explicit disabled state must be preserved');
c6_check(\RoxySuite\Governance::module_enabled('inventory'), 'explicit enabled state must be preserved');
c6_check(\RoxySuite\Governance::module_enabled('will_call'), 'missing known legacy key retains old enabled behavior');
c6_check(!\RoxySuite\Governance::module_enabled('future_module'), 'unknown module is never enabled by an option entry');
c6_check(count($GLOBALS['c6_option_writes']) === 1, 'valid legacy option should be normalized once');
c6_check($GLOBALS['c6_fake_options']['roxy_suite_modules']['arcade'] === false, 'migration must persist explicit disabled state');
c6_check($GLOBALS['c6_fake_options']['roxy_suite_modules']['inventory'] === true, 'migration must persist explicit enabled state');
c6_check(!array_key_exists('future_module', $GLOBALS['c6_fake_options']['roxy_suite_modules']), 'unknown future flag must not be retained for later implicit activation');

// Complete settings require no ordinary-init write or lock acquisition.
c6_reset_module_cache();
$GLOBALS['c6_option_writes'] = [];
$GLOBALS['c6_fake_options']['roxy_suite_modules'] = array_fill_keys($legacy_modules, true);
c6_reset_fake_database();
c6_check(\RoxySuite\Governance::module_settings_are_valid(), 'complete option should be valid');
c6_check($GLOBALS['c6_option_writes'] === [], 'complete option must not be written on ordinary reads');
c6_check($GLOBALS['c6_fake_wpdb']->lock_attempts === 0, 'complete option must not acquire migration lock');

// Toggle re-reads inside the lock, preserving a concurrent change to another flag.
c6_reset_module_cache();
$GLOBALS['c6_option_writes'] = [];
$GLOBALS['c6_fake_options']['roxy_suite_modules'] = array_fill_keys($legacy_modules, true);
c6_check(\RoxySuite\Governance::module_enabled('arcade'), 'initial state should cache enabled module');
$GLOBALS['c6_fake_options']['roxy_suite_modules']['grosses'] = false; // another request's committed update
c6_reset_fake_database();
c6_check(\RoxySuite\Governance::set_module_enabled('arcade', false), 'toggle should persist under lock');
c6_check($GLOBALS['c6_fake_options']['roxy_suite_modules']['arcade'] === false, 'requested toggle should persist');
c6_check($GLOBALS['c6_fake_options']['roxy_suite_modules']['grosses'] === false, 'concurrent unrelated toggle must not be overwritten');
c6_check($GLOBALS['c6_option_writes'][0][2] === true, 'toggle write must occur while DB lock is held');

// A no-op/failing write is not reported as success and is never cached as valid.
c6_reset_module_cache();
$GLOBALS['c6_option_writes'] = [];
$GLOBALS['c6_fake_options'] = [];
c6_reset_fake_database();
$GLOBALS['c6_noop_writes'] = true;
c6_check(!\RoxySuite\Governance::module_settings_are_valid(), 'failed migration readback must fail closed');
c6_check(!\RoxySuite\Governance::module_enabled('arcade'), 'unpersisted migration must not be cached as enabled');
c6_check(count($GLOBALS['c6_option_writes']) === 1 && $GLOBALS['c6_option_writes'][0][2] === true, 'failed migration attempt should be serialized');

c6_reset_module_cache();
$GLOBALS['c6_option_writes'] = [];
$GLOBALS['c6_fake_options'] = ['roxy_suite_modules' => array_fill_keys($legacy_modules, true)];
c6_reset_fake_database();
$GLOBALS['c6_noop_writes'] = true;
c6_check(!\RoxySuite\Governance::set_module_enabled('arcade', false), 'failed toggle readback must return failure');
c6_check($GLOBALS['c6_fake_options']['roxy_suite_modules']['arcade'] === true, 'failed toggle must leave stored state unchanged');

// Lock contention prevents an unsafe read/modify/write.
c6_reset_module_cache();
$GLOBALS['c6_option_writes'] = [];
$GLOBALS['c6_noop_writes'] = false;
$GLOBALS['c6_fake_options'] = ['roxy_suite_modules' => array_fill_keys($legacy_modules, true)];
c6_reset_fake_database();
$GLOBALS['c6_fake_wpdb']->lock_busy = true;
c6_check(!\RoxySuite\Governance::set_module_enabled('arcade', false), 'contended lock must reject toggle');
c6_check($GLOBALS['c6_option_writes'] === [], 'contended lock must not write');
c6_check($GLOBALS['c6_fake_options']['roxy_suite_modules']['arcade'] === true, 'contended lock must preserve stored state');

// Corrupt data is not silently replaced and cannot be overwritten via the toggle.
foreach (['corrupt-string', ['arcade' => 'yes']] as $corrupt_option) {
    c6_reset_module_cache();
    $GLOBALS['c6_option_writes'] = [];
    $GLOBALS['c6_fake_options'] = ['roxy_suite_modules' => $corrupt_option];
    c6_reset_fake_database();
    c6_check(!\RoxySuite\Governance::module_settings_are_valid(), 'corrupt option must fail closed');
    c6_check(!\RoxySuite\Governance::module_enabled('arcade'), 'corrupt option must disable modules');
    c6_check(!\RoxySuite\Governance::set_module_enabled('arcade', true), 'toggle must reject corrupt option');
    c6_check($GLOBALS['c6_option_writes'] === [], 'corrupt option must not be overwritten');
    c6_check($GLOBALS['c6_fake_options']['roxy_suite_modules'] === $corrupt_option, 'corrupt option remains untouched');
}

// The retained grant remains exactly the existing two-role activation grant.
$admin = new C6FakeRole();
$manager = new C6FakeRole();
$editor = new C6FakeRole();
$GLOBALS['c6_fake_roles'] = [
    'administrator' => $admin,
    'shop_manager' => $manager,
    'editor' => $editor,
];
\RoxySuite\Governance::grant_capabilities();
\RoxySuite\Governance::grant_capabilities();
c6_check($admin->has_cap('roxy_suite_access') && $admin->add_calls === 1, 'administrator grant should remain idempotent');
c6_check($manager->has_cap('roxy_suite_access') && $manager->add_calls === 1, 'shop manager grant should remain idempotent');
c6_check(!$editor->has_cap('roxy_suite_access') && $editor->add_calls === 0, 'no additional role should be granted access');

// Guard the bootstrap lifecycle: grants are retained on activation, not init.
$root_source = file_get_contents(dirname(__DIR__) . '/roxy-suite.php');
c6_check(is_string($root_source), 'plugin bootstrap source should be readable');
c6_check(!preg_match("/add_action\\s*\\(\\s*['\"]init['\"]\\s*,\\s*['\"]roxy_suite_grant_capabilities['\"]/", $root_source), 'role grant must not run on init');
c6_check(strpos($root_source, "register_activation_hook(__FILE__, function () {\n    roxy_suite_grant_capabilities();") !== false, 'existing grant must remain in activation hook');
c6_check(strpos($root_source, "wp_unschedule_hook('roxy_social_cleanup_page');") !== false, 'deactivation clears paged Social cleanup events with any cursor arguments');

echo "C6 governance regression checks passed.\n";
