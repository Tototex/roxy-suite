<?php
// Run with wp eval-file tests/grosses-workbook-setting-save-wordpress.php [candidate-root].
// Exercises the real Grosses sanitizer through WordPress options on a disposable site.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
$root = $args[0] ?? dirname(__DIR__);
$settings_path = $root . '/includes/modules/grosses/includes/class-roxy-grosses-settings.php';
$workbook_path = $root . '/includes/modules/grosses/includes/class-roxy-grosses-workbook.php';
foreach ([$settings_path, $workbook_path] as $path) {
    if (!is_file($path)) throw new RuntimeException('Grosses settings/workbook source is missing.');
}

// Isolate the real source in a private namespace so this disposable test never
// invokes the site's scheduled worker or collides with module bootstrap state.
$namespace = 'RoxyGrossesWorkbookSettingsFixture' . bin2hex(random_bytes(4));
eval('namespace ' . $namespace . '; class Scheduler { public static function sync_schedule(array $settings): void {} }');
foreach ([$settings_path, $workbook_path] as $path) {
    $source = file_get_contents($path);
    if (!is_string($source) || substr_count($source, 'namespace RoxyGrosses;') !== 1) {
        throw new RuntimeException('Could not isolate Grosses settings/workbook source.');
    }
    $source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $source);
    eval('?>' . $source);
}

$settings_class = $namespace . '\\Settings';
$workbook_class = $namespace . '\\Workbook';
$settings_class::register_settings();
$option_key = $settings_class::OPTION_KEY;
global $wpdb;
$prior_engine = strtoupper((string) $wpdb->get_var($wpdb->prepare(
    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
    $wpdb->options
)));
if (!in_array($prior_engine, ['INNODB', 'MYISAM'], true)) throw new RuntimeException('Could not verify disposable WordPress options-table engine.');
$prior_row = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $option_key), ARRAY_A);
$prior_row = is_array($prior_row) ? $prior_row : null;
$root_path = trailingslashit(WP_CONTENT_DIR) . 'roxy-grosses-private';
$templates_path = trailingslashit($root_path) . 'templates';
$root_existed = is_dir($root_path);
$templates_existed = is_dir($templates_path);
$private_dir_method = new ReflectionMethod($workbook_class, 'private_templates_dir');
$private_dir_method->setAccessible(true);
$file_path = '';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
};

try {
    if ($prior_engine !== 'MYISAM' && $wpdb->query("ALTER TABLE {$wpdb->options} ENGINE=MyISAM") === false) {
        throw new RuntimeException('Could not switch the disposable options table to MyISAM.');
    }
    $active_engine = strtoupper((string) $wpdb->get_var($wpdb->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $wpdb->options
    )));
    $check($active_engine === 'MYISAM', 'fixture exercises the production MyISAM options-table engine');

    $private_dir = $private_dir_method->invoke(null);
    $file_path = trailingslashit($private_dir) . 'settings-save-' . bin2hex(random_bytes(8)) . '.xlsx';
    if (file_put_contents($file_path, 'private synthetic workbook fixture') === false) {
        throw new RuntimeException('Could not create private workbook settings fixture.');
    }
    $template = [
        'path' => $file_path,
        'name' => basename($file_path),
        'uploaded_at' => current_time('mysql'),
    ];
    $saved = $settings_class::save_workbook_template($template);
    $check($saved === true, 'private workbook setting persists and reads back through WordPress option sanitization');
    $current = get_option($option_key, []);
    $check(is_array($current) && ($current['workbook_template_upload'] ?? null) === $template,
        'persisted workbook metadata exactly matches the validated private template');

    $current['theater_name'] = 'Disposable workbook integration fixture';
    update_option($option_key, $current);
    $after_ordinary_save = get_option($option_key, []);
    $check(is_array($after_ordinary_save)
        && ($after_ordinary_save['workbook_template_upload'] ?? null) === $template
        && ($after_ordinary_save['theater_name'] ?? '') === 'Disposable workbook integration fixture',
        'ordinary settings saves retain the uploaded private workbook while updating other settings');
} finally {
    if ($file_path !== '' && is_file($file_path)) @unlink($file_path);
    if ($prior_row !== null) {
        $wpdb->update($wpdb->options, $prior_row, ['option_name' => $option_key], ['%s', '%s'], ['%s']);
    } else {
        $wpdb->delete($wpdb->options, ['option_name' => $option_key], ['%s']);
    }
    wp_cache_delete($option_key, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    if ($prior_engine !== 'MYISAM') {
        $wpdb->query("ALTER TABLE {$wpdb->options} ENGINE=InnoDB");
    }
    if (!$templates_existed && is_dir($templates_path)) {
        foreach (['.htaccess', 'index.html'] as $name) {
            $path = trailingslashit($templates_path) . $name;
            if (is_file($path)) @unlink($path);
        }
        @rmdir($templates_path);
    }
    if (!$root_existed && is_dir($root_path)) {
        foreach (['.htaccess', 'index.html'] as $name) {
            $path = trailingslashit($root_path) . $name;
            if (is_file($path)) @unlink($path);
        }
        @rmdir($root_path);
    }
}

echo "OK: {$checks} actual WordPress Grosses workbook-settings checks passed\n";
