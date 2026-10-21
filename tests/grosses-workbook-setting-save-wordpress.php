<?php
// Run with wp eval-file tests/grosses-workbook-setting-save-wordpress.php [candidate-root].
// Exercises the real Grosses sanitizer through WordPress options on a disposable site.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
$root = $args[0] ?? dirname(__DIR__);
$settings_path = $root . '/includes/modules/grosses/includes/class-roxy-grosses-settings.php';
$workbook_path = $root . '/includes/modules/grosses/includes/class-roxy-grosses-workbook.php';
$scheduler_path = $root . '/includes/modules/grosses/includes/class-roxy-grosses-scheduler.php';
foreach ([$scheduler_path, $settings_path, $workbook_path] as $path) {
    if (!is_file($path)) throw new RuntimeException('Grosses settings/workbook source is missing.');
}

// Isolate the real source in a private namespace so this disposable test never
// invokes the site's scheduled worker or collides with module bootstrap state.
$namespace = 'RoxyGrossesWorkbookSettingsFixture' . bin2hex(random_bytes(4));
foreach ([$settings_path, $workbook_path] as $path) {
    $source = file_get_contents($path);
    if (!is_string($source) || substr_count($source, 'namespace RoxyGrosses;') !== 1) {
        throw new RuntimeException('Could not isolate Grosses settings/workbook source.');
    }
    $source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $source);
    if ($path === $workbook_path) {
        // The real admin handler redirects with exit. Make only that final
        // redirect return in this isolated fixture so the request result can
        // be asserted without changing production behavior.
        $redirect = 'wp_safe_redirect($url);' . "\n    exit;";
        if (substr_count($source, $redirect) !== 1) {
            throw new RuntimeException('Could not isolate the workbook upload redirect for request testing.');
        }
        $source = str_replace($redirect, 'wp_safe_redirect($url);' . "\n    return;", $source);
        $namespace_declaration = 'namespace ' . $namespace . ';';
        $source = str_replace(
            $namespace_declaration,
            $namespace_declaration . "\nfunction roxy_suite_user_can_access_admin(): bool { return !empty($GLOBALS['roxy_workbook_request_authorized']); }\nfunction wp_handle_upload(array $file, array $overrides = []): array { $path = (string) ($GLOBALS['roxy_workbook_upload_path'] ?? ''); return is_file($path) ? ['file' => $path] : ['error' => 'Synthetic upload fixture is missing.']; }",
            $source
        );
    }
    eval('?>' . $source);
}
$source = file_get_contents($scheduler_path);
if (!is_string($source) || substr_count($source, 'namespace RoxyGrosses;') !== 1) {
    throw new RuntimeException('Could not isolate Grosses scheduler source.');
}
$source = str_replace('namespace RoxyGrosses;', 'namespace ' . $namespace . ';', $source);
eval('?>' . $source);
eval('namespace ' . $namespace . '; class Store { public static array $logs = []; public static function insert_log(...$args): bool { self::$logs[] = $args; return true; } }');

$settings_class = $namespace . '\\Settings';
$workbook_class = $namespace . '\\Workbook';
$store_class = $namespace . '\\Store';
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
$prior_cron_row = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", 'cron'), ARRAY_A);
$prior_cron_row = is_array($prior_cron_row) ? $prior_cron_row : null;
$root_path = trailingslashit(WP_CONTENT_DIR) . 'roxy-grosses-private';
$templates_path = trailingslashit($root_path) . 'templates';
$root_existed = is_dir($root_path);
$templates_existed = is_dir($templates_path);
$private_dir_method = new ReflectionMethod($workbook_class, 'private_templates_dir');
$private_dir_method->setAccessible(true);
$store_upload_method = new ReflectionMethod($workbook_class, 'store_uploaded_template');
$store_upload_method->setAccessible(true);
$file_path = '';
$stored_upload_path = '';
$upload_source = '';
$invalid_source = '';
$retained_upload_path = '';
$retained_upload_source = '';
$request_upload_source = '';
$request_private_path = '';
$prior_request = $_REQUEST;
$prior_post = $_POST;
$prior_files = $_FILES;
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
    $settings_before_credentials = get_option($option_key, []);
    if (!is_array($settings_before_credentials)) $settings_before_credentials = [];
    $settings_before_credentials['square_access_token'] = 'synthetic-square-token-for-template-save';
    $settings_before_credentials['square_webhook_signature_key'] = 'synthetic-webhook-key-for-template-save';
    update_option($option_key, $settings_before_credentials);
    $credentials_before_template_save = get_option($option_key, []);
    $square_ciphertext = is_array($credentials_before_template_save) ? ($credentials_before_template_save['square_access_token'] ?? null) : null;
    $webhook_ciphertext = is_array($credentials_before_template_save) ? ($credentials_before_template_save['square_webhook_signature_key'] ?? null) : null;
    $check(is_string($square_ciphertext) && str_starts_with($square_ciphertext, 'gcm:')
        && is_string($webhook_ciphertext) && str_starts_with($webhook_ciphertext, 'gcm:'),
        'fixture credentials are stored encrypted before the internal workbook-setting save');

    $saved = $settings_class::save_workbook_template($template);
    $check($saved === true, 'private workbook setting persists and reads back through WordPress option sanitization');
    $current = get_option($option_key, []);
    $check(is_array($current) && ($current['workbook_template_upload'] ?? null) === $template,
        'persisted workbook metadata exactly matches the validated private template');
    $check(is_array($current)
        && ($current['square_access_token'] ?? null) === $square_ciphertext
        && ($current['square_webhook_signature_key'] ?? null) === $webhook_ciphertext,
        'saving private workbook metadata preserves both existing encrypted credentials byte-for-byte');
    $decrypt_secret = new ReflectionMethod($settings_class, 'decrypt_secret');
    $check($decrypt_secret->invoke(null, $square_ciphertext) === 'synthetic-square-token-for-template-save',
        'Square ciphertext decrypts directly after the internal workbook-setting save');
    $check($decrypt_secret->invoke(null, $webhook_ciphertext) === 'synthetic-webhook-key-for-template-save',
        'webhook ciphertext decrypts directly after the internal workbook-setting save');
    $decrypted_credentials = $settings_class::get_all();
    $check(($decrypted_credentials['square_access_token'] ?? null) === 'synthetic-square-token-for-template-save',
        'Square credential remains decryptable after the internal workbook-setting save');
    $check(($decrypted_credentials['square_webhook_signature_key'] ?? null) === 'synthetic-webhook-key-for-template-save',
        'webhook credential remains decryptable after the internal workbook-setting save');

    $current['theater_name'] = 'Disposable workbook integration fixture';
    update_option($option_key, $current);
    $after_ordinary_save = get_option($option_key, []);
    $check(is_array($after_ordinary_save)
        && ($after_ordinary_save['workbook_template_upload'] ?? null) === $template
        && ($after_ordinary_save['theater_name'] ?? '') === 'Disposable workbook integration fixture',
        'ordinary settings saves retain the uploaded private workbook while updating other settings');
    $decrypted_after_ordinary_save = $settings_class::get_all();
    $check(is_array($after_ordinary_save)
        && ($after_ordinary_save['square_access_token'] ?? null) === $square_ciphertext
        && ($after_ordinary_save['square_webhook_signature_key'] ?? null) === $webhook_ciphertext
        && ($decrypted_after_ordinary_save['square_access_token'] ?? null) === 'synthetic-square-token-for-template-save'
        && ($decrypted_after_ordinary_save['square_webhook_signature_key'] ?? null) === 'synthetic-webhook-key-for-template-save',
        'ordinary settings saves preserve and decrypt both existing credentials');

    $outside_path = trailingslashit(WP_CONTENT_DIR) . 'outside-' . bin2hex(random_bytes(8)) . '.xlsx';
    if (file_put_contents($outside_path, 'outside private templates fixture') === false) {
        throw new RuntimeException('Could not create outside-path rejection fixture.');
    }
    try {
        $rejected = $settings_class::save_workbook_template([
            'path' => $outside_path,
            'name' => basename($outside_path),
            'uploaded_at' => current_time('mysql'),
        ]);
        $check($rejected === false, 'unsafe workbook paths are rejected before the settings option is written');
        $after_rejection = get_option($option_key, []);
        $check(is_array($after_rejection)
            && ($after_rejection['workbook_template_upload'] ?? null) === $template,
            'private-path rejection leaves the existing workbook setting untouched');
    } finally {
        if (is_file($outside_path)) @unlink($outside_path);
    }

    $mismatch_path = trailingslashit($private_dir) . 'readback-mismatch-' . bin2hex(random_bytes(8)) . '.xlsx';
    if (file_put_contents($mismatch_path, 'private synthetic mismatch fixture') === false) {
        throw new RuntimeException('Could not create private read-back mismatch fixture.');
    }
    try {
        $mismatch_template = [
            'path' => $mismatch_path,
            'name' => basename($mismatch_path),
            'uploaded_at' => current_time('mysql'),
        ];
        $keep_existing = static function ($value, $old_value, $option) use ($option_key) {
            return $option === $option_key ? $old_value : $value;
        };
        add_filter('pre_update_option_' . $option_key, $keep_existing, 999, 3);
        try {
            $mismatch = $settings_class::save_workbook_template($mismatch_template);
        } finally {
            remove_filter('pre_update_option_' . $option_key, $keep_existing, 999);
        }
        $check($mismatch === null, 'settings read-back mismatch is distinguished from private-path rejection');
        $after_mismatch = get_option($option_key, []);
        $check(is_array($after_mismatch)
            && ($after_mismatch['workbook_template_upload'] ?? null) === $template,
            'a settings read-back mismatch preserves the previously saved private template');
    } finally {
        if (is_file($mismatch_path)) @unlink($mismatch_path);
    }

    if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive is required for the uploaded-template workflow fixture.');
    $upload_source = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-workbook-upload-' . bin2hex(random_bytes(8)) . '.xlsx';
    $zip = new ZipArchive();
    if ($zip->open($upload_source, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Could not create synthetic uploaded workbook.');
    foreach ([
        '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
        'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>',
        'xl/worksheets/sheet3.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>',
        'xl/worksheets/sheet4.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>',
    ] as $entry => $xml) {
        if (!$zip->addFromString($entry, $xml)) throw new RuntimeException('Could not add synthetic workbook package entry.');
    }
    if (!$zip->close()) throw new RuntimeException('Could not finish synthetic uploaded workbook.');
    $upload_result = $store_upload_method->invoke(null, $upload_source, 'synthetic-upload.xlsx');
    $stored_upload_path = (string) ($upload_result['path'] ?? '');
    $check(($upload_result['success'] ?? false) === true
        && ($upload_result['message'] ?? '') === 'Workbook template uploaded successfully.',
        'actual upload-processing path validates and accepts a well-formed synthetic workbook');
    $check(!is_file($upload_source) && is_file($stored_upload_path)
        && dirname($stored_upload_path) === $private_dir,
        'accepted upload is moved out of temporary storage into the private template directory');
    $uploaded_settings = get_option($option_key, []);
    $check(is_array($uploaded_settings)
        && ($uploaded_settings['workbook_template_upload']['path'] ?? '') === $stored_upload_path
        && ($uploaded_settings['workbook_template_upload']['name'] ?? '') === 'synthetic-upload.xlsx',
        'accepted private upload is persisted through the real sanitized WordPress option');
    $check(count($store_class::$logs) === 1
        && ($store_class::$logs[0][0] ?? '') === 'upload_workbook_template',
        'accepted upload records exactly one success audit entry');

    $invalid_source = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-workbook-invalid-' . bin2hex(random_bytes(8)) . '.xlsx';
    if (file_put_contents($invalid_source, 'not an OOXML workbook') === false) throw new RuntimeException('Could not create invalid upload fixture.');
    $invalid_result = $store_upload_method->invoke(null, $invalid_source, 'invalid.xlsx');
    $check(($invalid_result['success'] ?? true) === false
        && strpos((string) ($invalid_result['message'] ?? ''), 'not a readable Excel workbook archive') !== false
        && !is_file($invalid_source),
        'invalid upload is rejected and its temporary copy is removed');
    $check(count($store_class::$logs) === 1,
        'rejected upload does not record a success audit entry');

    $retained_upload_name = 'synthetic-readback-mismatch-' . bin2hex(random_bytes(8)) . '.xlsx';
    $retained_upload_source = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $retained_upload_name;
    $zip = new ZipArchive();
    if ($zip->open($retained_upload_source, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Could not create read-back mismatch workbook fixture.');
    foreach ([
        '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
        'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>',
        'xl/worksheets/sheet3.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>',
        'xl/worksheets/sheet4.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>',
    ] as $entry => $xml) {
        if (!$zip->addFromString($entry, $xml)) throw new RuntimeException('Could not add read-back mismatch package entry.');
    }
    if (!$zip->close()) throw new RuntimeException('Could not finish read-back mismatch workbook.');
    $retained_upload_path = trailingslashit($private_dir) . $retained_upload_name;
    $keep_existing_option = static function ($value, $old_value, $option) use ($option_key) {
        return $option === $option_key ? $old_value : $value;
    };
    add_filter('pre_update_option_' . $option_key, $keep_existing_option, 999, 3);
    try {
        $mismatch_result = $store_upload_method->invoke(null, $retained_upload_source, $retained_upload_name);
    } finally {
        remove_filter('pre_update_option_' . $option_key, $keep_existing_option, 999);
    }
    $after_workflow_mismatch = get_option($option_key, []);
    $check(($mismatch_result['success'] ?? true) === false
        && strpos((string) ($mismatch_result['message'] ?? ''), 'settings could not be read back') !== false,
        'upload reports a controlled error when the full settings option does not read back');
    $check(is_file($retained_upload_path) && !is_file($retained_upload_source)
        && is_array($after_workflow_mismatch)
        && ($after_workflow_mismatch['workbook_template_upload']['path'] ?? '') === $stored_upload_path,
        'settings read-back failure preserves the existing reference and retains the new private copy safely');
    $check(count($store_class::$logs) === 1,
        'settings read-back failure does not record a success audit entry');

    // Exercise the actual admin-post handler, including its capability and
    // nonce gates, upload-result handoff, private move, settings write, audit
    // log, and redirect. The upload API is replaced only in this isolated
    // namespace because WP-CLI cannot create a PHP is_uploaded_file() request.
    $request_upload_source = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-workbook-request-source-' . bin2hex(random_bytes(8)) . '.xlsx';
    $zip = new ZipArchive();
    if ($zip->open($request_upload_source, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Could not create synthetic admin-request workbook.');
    foreach ([
        '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
        'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>',
        'xl/worksheets/sheet3.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>',
        'xl/worksheets/sheet4.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>',
    ] as $entry => $xml) {
        if (!$zip->addFromString($entry, $xml)) throw new RuntimeException('Could not add synthetic admin-request workbook part.');
    }
    if (!$zip->close()) throw new RuntimeException('Could not finish synthetic admin-request workbook.');

    $request_upload_name = 'synthetic-admin-request-' . bin2hex(random_bytes(4)) . '.xlsx';
    $redirect_location = '';
    $redirect_filter = static function ($location) use (&$redirect_location) {
        $redirect_location = (string) $location;
        return false;
    };
    add_filter('wp_redirect', $redirect_filter, 999, 2);
    $GLOBALS['roxy_workbook_request_authorized'] = true;
    $GLOBALS['roxy_workbook_upload_path'] = $request_upload_source;
    $_REQUEST = ['_wpnonce' => wp_create_nonce('roxy_grosses_upload_template')];
    $_POST = ['action' => 'roxy_grosses_upload_template'];
    $_FILES = ['workbook_template' => [
        'name' => $request_upload_name,
        'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'tmp_name' => $request_upload_source,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($request_upload_source),
    ]];
    try {
        $workbook_class::handle_upload_template();
    } finally {
        remove_filter('wp_redirect', $redirect_filter, 999);
        unset($GLOBALS['roxy_workbook_request_authorized']);
        unset($GLOBALS['roxy_workbook_upload_path']);
        $_REQUEST = $prior_request;
        $_POST = $prior_post;
        $_FILES = $prior_files;
    }
    parse_str((string) parse_url($redirect_location, PHP_URL_QUERY), $redirect_query);
    $check(($redirect_query['roxy_grosses_notice'] ?? '') === 'success'
        && ($redirect_query['tab'] ?? '') === 'workbook'
        && ($redirect_query['message'] ?? '') === 'Workbook template uploaded successfully.',
        'admin upload handler accepts the valid nonce and redirects with the successful workbook notice');
    $request_settings = get_option($option_key, []);
    $request_private_path = (string) ($request_settings['workbook_template_upload']['path'] ?? '');
    $check($request_private_path !== '' && dirname($request_private_path) === $private_dir
        && is_file($request_private_path)
        && !is_file($request_upload_source),
        'admin upload handler moves the synthetic upload into private storage and persists its reference');
    $check(count($store_class::$logs) === 2
        && ($store_class::$logs[1][0] ?? '') === 'upload_workbook_template',
        'admin upload handler writes exactly one success audit entry');
} finally {
    if ($file_path !== '' && is_file($file_path)) @unlink($file_path);
    if ($stored_upload_path !== '' && is_file($stored_upload_path)) @unlink($stored_upload_path);
    if ($upload_source !== '' && is_file($upload_source)) @unlink($upload_source);
    if ($invalid_source !== '' && is_file($invalid_source)) @unlink($invalid_source);
    if ($retained_upload_path !== '' && is_file($retained_upload_path)) @unlink($retained_upload_path);
    if ($retained_upload_source !== '' && is_file($retained_upload_source)) @unlink($retained_upload_source);
    if ($request_private_path !== '' && is_file($request_private_path)) @unlink($request_private_path);
    if ($request_upload_source !== '' && is_file($request_upload_source)) @unlink($request_upload_source);
    if ($prior_row !== null) {
        $wpdb->update($wpdb->options, $prior_row, ['option_name' => $option_key], ['%s', '%s'], ['%s']);
    } else {
        $wpdb->delete($wpdb->options, ['option_name' => $option_key], ['%s']);
    }
    if ($prior_cron_row !== null) {
        $wpdb->update($wpdb->options, $prior_cron_row, ['option_name' => 'cron'], ['%s', '%s'], ['%s']);
    } else {
        $wpdb->delete($wpdb->options, ['option_name' => 'cron'], ['%s']);
    }
    wp_cache_delete($option_key, 'options');
    wp_cache_delete('cron', 'options');
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
