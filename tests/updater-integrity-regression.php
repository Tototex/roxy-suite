<?php
// Isolated updater integrity checks. No WordPress load, database, or provider calls.
namespace {
    $root = $argv[1] ?? dirname(__DIR__);
    define('ABSPATH', $root);
    define('MINUTE_IN_SECONDS', 60);
    set_error_handler(static function ($severity, $message, $file, $line) {
        if (error_reporting() & $severity) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }
        return false;
    });

    final class WP_Error {
        public string $code;
        public string $message;
        public function __construct($code = '', $message = '') { $this->code = (string) $code; $this->message = (string) $message; }
    }

    function check_updater($condition, $label) {
        if (!$condition) throw new \RuntimeException($label);
        echo "PASS: $label\n";
    }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function wp_parse_args($values, $defaults) { return array_merge($defaults, $values); }
    function wp_parse_url($url) { return parse_url($url); }
    function is_admin() { return true; }
    function wp_doing_cron() { return false; }
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['registered_filters'][$hook] = [$callback, $accepted_args]; }
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
    function get_site_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
    function set_site_transient($key, $value, $ttl = 0) { $GLOBALS['transients'][$key] = $value; }
    function delete_site_transient($key) { unset($GLOBALS['transients'][$key]); }
    function wp_remote_get($url, $args = []) {
        $GLOBALS['remote_calls']++;
        $GLOBALS['remote_args'][] = $args;
        return strpos($url, '/releases/latest') !== false ? $GLOBALS['release_response'] : $GLOBALS['manifest_response'];
    }
    function wp_remote_retrieve_response_code($response) { return $response['code'] ?? 0; }
    function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
    function download_url($url, $timeout = 300) {
        $GLOBALS['download_calls']++;
        $path = tempnam(sys_get_temp_dir(), 'roxy-updater-');
        file_put_contents($path, $GLOBALS['download_bytes']);
        $GLOBALS['download_path'] = $path;
        return $path;
    }

    require $root . '/includes/class-roxy-suite-updater.php';
    \RoxySuite\Updater::init([
        'plugin_file' => 'roxy-suite/roxy-suite.php',
        'version' => '1.0.60',
        'github_repo' => 'Tototex/roxy-suite',
        'slug' => 'roxy-suite',
    ]);
    check_updater(isset($GLOBALS['registered_filters']['upgrader_pre_download']) && $GLOBALS['registered_filters']['upgrader_pre_download'][1] === 4, 'pre-download verifier registered with all four WordPress hook arguments');
    check_updater(!isset($GLOBALS['registered_filters']['upgrader_post_install']), 'updater does not force plugin reactivation');

    $config = new \ReflectionProperty(\RoxySuite\Updater::class, 'config');
    $release = new \ReflectionProperty(\RoxySuite\Updater::class, 'release');
    $config->setAccessible(true);
    $release->setAccessible(true);
    $cache_key_method = new \ReflectionMethod(\RoxySuite\Updater::class, 'get_cache_key');
    $cache_key_method->setAccessible(true);
    $cache_key = $cache_key_method->invoke(null);

    $archive_bytes = "fake zip bytes\n";
    $archive_hash = hash('sha256', $archive_bytes);
    $manifest = [
        'format_version' => 1,
        'source_sha' => str_repeat('a', 40),
        'version' => '1.0.61',
        'archive' => ['file' => 'roxy-suite-1.0.61.zip', 'sha256' => $archive_hash],
        'files' => [
            ['path' => 'roxy-suite/roxy-suite.php', 'sha256' => str_repeat('b', 64)],
            ['path' => 'roxy-suite/includes/.htaccess', 'sha256' => str_repeat('c', 64)],
        ],
    ];
    $latest = [
        'tag_name' => 'v1.0.61',
        'html_url' => 'https://github.com/Tototex/roxy-suite/releases/tag/v1.0.61',
        'assets' => [
            ['name' => 'roxy-suite-1.0.610.zip', 'browser_download_url' => 'https://github.com/Tototex/roxy-suite/releases/download/v1.0.61/roxy-suite-1.0.610.zip'],
            ['name' => 'roxy-suite-1.0.61.zip', 'browser_download_url' => 'https://github.com/Tototex/roxy-suite/releases/download/v1.0.61/roxy-suite-1.0.61.zip'],
            ['name' => 'roxy-suite-1.0.61.manifest.json', 'browser_download_url' => 'https://github.com/Tototex/roxy-suite/releases/download/v1.0.61/roxy-suite-1.0.61.manifest.json'],
        ],
    ];

    $fetch = static function ($release_data, $manifest_data) use ($release, $cache_key) {
        $GLOBALS['transients'] = [];
        $GLOBALS['remote_calls'] = 0;
        $GLOBALS['remote_args'] = [];
        $GLOBALS['release_response'] = ['code' => 200, 'body' => json_encode($release_data)];
        $GLOBALS['manifest_response'] = ['code' => 200, 'body' => json_encode($manifest_data)];
        $release->setValue(null, null);
        return (new \ReflectionMethod(\RoxySuite\Updater::class, 'get_latest_release'))->invoke(null);
    };

    $valid = $fetch($latest, $manifest);
    check_updater(is_array($valid) && $valid['version'] === '1.0.61' && $valid['archive_sha256'] === $archive_hash, 'exact versioned archive and matching manifest accepted');
    check_updater(isset($GLOBALS['transients'][$cache_key]) && isset($GLOBALS['transients'][$cache_key]['archive_sha256']), 'integrity metadata retained in updater cache');
    check_updater(($GLOBALS['remote_args'][count($GLOBALS['remote_args']) - 1]['limit_response_size'] ?? null) === 1048576, 'manifest response is capped at 1 MiB');

    $bad_tag = $latest;
    $bad_tag['tag_name'] = ['v1.0.61'];
    check_updater($fetch($bad_tag, $manifest) === null, 'non-string provider tag rejected without warnings');
    $bad_asset_name = $latest;
    $bad_asset_name['assets'][1]['name'] = ['roxy-suite-1.0.61.zip'];
    check_updater($fetch($bad_asset_name, $manifest) === null, 'non-string provider asset name rejected without warnings');
    $bad_asset_url = $latest;
    $bad_asset_url['assets'][1]['browser_download_url'] = ['https://github.com/Tototex/roxy-suite/releases/download/v1.0.61/roxy-suite-1.0.61.zip'];
    check_updater($fetch($bad_asset_url, $manifest) === null, 'non-string provider asset URL rejected without warnings');
    $GLOBALS['transients'] = [];
    $GLOBALS['remote_calls'] = 0;
    $GLOBALS['remote_args'] = [];
    $GLOBALS['release_response'] = ['code' => 200, 'body' => ['malformed' => 'body']];
    $release->setValue(null, null);
    check_updater((new \ReflectionMethod(\RoxySuite\Updater::class, 'get_latest_release'))->invoke(null) === null, 'non-string release response body rejected without warnings');

    $oversized_manifest = $manifest;
    $oversized_manifest['files'] = array_fill(0, 10001, ['path' => 'roxy-suite/file.php', 'sha256' => str_repeat('a', 64)]);
    $validate_manifest = new \ReflectionMethod(\RoxySuite\Updater::class, 'validate_manifest');
    $validate_manifest->setAccessible(true);
    check_updater(!$validate_manifest->invoke(null, $oversized_manifest, '1.0.61', 'roxy-suite-1.0.61.zip'), 'manifest with more than 10,000 file records rejected');

    $valid = $fetch($latest, $manifest);
    $update = \RoxySuite\Updater::filter_update_plugins((object) ['response' => [], 'no_update' => []]);
    check_updater(isset($update->response['roxy-suite/roxy-suite.php']), 'verified release offered to WordPress');

    foreach ([
        'wrong manifest version' => static function (&$m) { $m['version'] = '1.0.62'; },
        'invalid source SHA' => static function (&$m) { $m['source_sha'] = 'not-a-commit'; },
        'invalid archive hash' => static function (&$m) { $m['archive']['sha256'] = 'bad'; },
        'invalid per-file hash' => static function (&$m) { $m['files'][0]['sha256'] = 'bad'; },
        'traversal path' => static function (&$m) { $m['files'][0]['path'] = 'roxy-suite/../evil.php'; },
        'absolute path' => static function (&$m) { $m['files'][0]['path'] = '/roxy-suite/evil.php'; },
        'duplicate path' => static function (&$m) { $m['files'][1]['path'] = $m['files'][0]['path']; },
    ] as $label => $mutate) {
        $bad_manifest = $manifest;
        $mutate($bad_manifest);
        check_updater($fetch($latest, $bad_manifest) === null, $label . ' rejected');
    }

    $wrong_asset = $latest;
    $wrong_asset['assets'][1]['name'] = 'roxy-suite-1.0.610.zip';
    check_updater($fetch($wrong_asset, $manifest) === null, 'near-match archive filename is not selected');

    $GLOBALS['transients'] = [$cache_key => ['version' => '1.0.99', 'download_url' => 'https://evil.test/package.zip']];
    $GLOBALS['remote_calls'] = 0;
    $GLOBALS['release_response'] = ['code' => 200, 'body' => json_encode($latest)];
    $GLOBALS['manifest_response'] = ['code' => 200, 'body' => json_encode($manifest)];
    $release->setValue(null, null);
    $legacy_result = (new \ReflectionMethod(\RoxySuite\Updater::class, 'get_latest_release'))->invoke(null);
    check_updater(is_array($legacy_result) && $legacy_result['version'] === '1.0.61' && $GLOBALS['remote_calls'] === 2, 'legacy cached package cannot bypass a fresh manifest check');

    $GLOBALS['download_bytes'] = $archive_bytes;
    $GLOBALS['download_calls'] = 0;
    $verified_path = \RoxySuite\Updater::filter_upgrader_pre_download(false, $valid['download_url'], null, ['action' => 'update', 'type' => 'plugin', 'plugin' => 'roxy-suite/roxy-suite.php']);
    check_updater(is_string($verified_path) && hash_file('sha256', $verified_path) === $archive_hash && $GLOBALS['download_calls'] === 1, 'matching archive SHA-256 passes before unpack');
    unlink($verified_path);

    $prior_error = new WP_Error('earlier_download_failure', 'Earlier filter stopped the download.');
    $before_download_calls = $GLOBALS['download_calls'];
    $preserved_error = \RoxySuite\Updater::filter_upgrader_pre_download($prior_error, $valid['download_url'], null, ['action' => 'update', 'type' => 'plugin', 'plugin' => 'roxy-suite/roxy-suite.php']);
    check_updater($preserved_error === $prior_error && $GLOBALS['download_calls'] === $before_download_calls, 'prior WP_Error is preserved without redownloading');

    $preexisting_path = tempnam(sys_get_temp_dir(), 'roxy-updater-prior-');
    file_put_contents($preexisting_path, $archive_bytes);
    $verified_prior_path = \RoxySuite\Updater::filter_upgrader_pre_download($preexisting_path, $valid['download_url'], null, ['action' => 'update', 'type' => 'plugin', 'plugin' => 'roxy-suite/roxy-suite.php']);
    check_updater($verified_prior_path === $preexisting_path && $GLOBALS['download_calls'] === $before_download_calls, 'prior package path is hash-verified without redownloading');
    unlink($preexisting_path);

    $GLOBALS['download_bytes'] = 'tampered archive';
    $rejected = \RoxySuite\Updater::filter_upgrader_pre_download(false, $valid['download_url'], null, ['action' => 'update', 'type' => 'plugin', 'plugin' => 'roxy-suite/roxy-suite.php']);
    check_updater(is_wp_error($rejected) && $rejected->code === 'roxy_suite_archive_hash_mismatch' && !file_exists($GLOBALS['download_path']), 'mismatched archive rejected and temporary download removed');

    $other_reply = (object) ['untouched' => true];
    check_updater(\RoxySuite\Updater::filter_upgrader_pre_download($other_reply, 'https://example.test/other.zip', null, ['action' => 'update', 'type' => 'plugin', 'plugin' => 'other/other.php']) === $other_reply, 'unrelated plugin upgrader passes through unchanged');
    check_updater(\RoxySuite\Updater::filter_upgrader_pre_download($other_reply, 'https://example.test/theme.zip', null, ['action' => 'update', 'type' => 'theme']) === $other_reply, 'theme upgrader passes through unchanged');

    $mixed_plugins = ['roxy-suite/roxy-suite.php', 'other/other.php'];
    $before_download_calls = $GLOBALS['download_calls'];
    check_updater(\RoxySuite\Updater::filter_upgrader_pre_download(false, $valid['download_url'], null, ['action' => 'update', 'type' => 'plugin', 'plugin' => 'other/other.php', 'plugins' => $mixed_plugins]) === false && $GLOBALS['download_calls'] === $before_download_calls, 'explicit other plugin wins over a mixed plugins list');
    $release->setValue(null, null);
    $GLOBALS['transients'] = [];
    $GLOBALS['remote_calls'] = 0;
    check_updater(\RoxySuite\Updater::filter_upgrader_pre_download(false, 'https://example.test/other.zip', null, ['action' => 'update', 'type' => 'plugin', 'plugins' => $mixed_plugins]) === false && $GLOBALS['download_calls'] === $before_download_calls && $GLOBALS['remote_calls'] === 0, 'mixed list with another package passes through without download or lookup');

    $GLOBALS['download_bytes'] = $archive_bytes;
    $mixed_suite_path = \RoxySuite\Updater::filter_upgrader_pre_download(false, $valid['download_url'], null, ['action' => 'update', 'type' => 'plugin', 'plugins' => $mixed_plugins]);
    check_updater(is_string($mixed_suite_path) && hash_file('sha256', $mixed_suite_path) === $archive_hash, 'mixed list verifies only the known Suite package');
    if (is_string($mixed_suite_path) && is_file($mixed_suite_path)) unlink($mixed_suite_path);
    $before_download_calls = $GLOBALS['download_calls'];
    $old_suite_package = \RoxySuite\Updater::filter_upgrader_pre_download(false, 'https://github.com/Tototex/roxy-suite/releases/download/v1.0.60/roxy-suite-1.0.60.zip', null, ['action' => 'update', 'type' => 'plugin', 'plugins' => $mixed_plugins]);
    check_updater(is_wp_error($old_suite_package) && $old_suite_package->code === 'roxy_suite_unexpected_package' && $GLOBALS['download_calls'] === $before_download_calls, 'different-version Suite archive in mixed list is rejected without download');

    $failed_transient = (object) [
        'response' => ['roxy-suite/roxy-suite.php' => (object) ['package' => 'https://example.test/stale.zip'], 'other/other.php' => (object) ['package' => 'https://example.test/other.zip']],
        'no_update' => ['roxy-suite/roxy-suite.php' => (object) ['package' => ''], 'third/third.php' => (object) ['package' => '']],
    ];
    $GLOBALS['transients'] = [
        $cache_key => ['version' => '1.0.99', 'download_url' => 'https://evil.test/package.zip'],
        $cache_key . '_failed' => 1,
    ];
    $release->setValue(null, null);
    $cleared = \RoxySuite\Updater::filter_update_plugins($failed_transient);
    check_updater(!isset($cleared->response['roxy-suite/roxy-suite.php']) && !isset($cleared->no_update['roxy-suite/roxy-suite.php']) && isset($cleared->response['other/other.php']) && isset($cleared->no_update['third/third.php']), 'failed verification clears only stale Suite update entries');

    $GLOBALS['transients'] = [
        $cache_key => ['version' => '1.0.99', 'download_url' => 'https://evil.test/package.zip'],
        $cache_key . '_failed' => 1,
    ];
    $release->setValue(null, null);
    $blocked = \RoxySuite\Updater::filter_upgrader_pre_download(false, 'https://github.com/Tototex/roxy-suite/releases/download/v1.0.61/roxy-suite-1.0.61.zip', null, ['action' => 'update', 'type' => 'plugin', 'plugin' => 'roxy-suite/roxy-suite.php']);
    check_updater(is_wp_error($blocked), 'legacy-only cache fails closed before download');
    $blocked_mixed = \RoxySuite\Updater::filter_upgrader_pre_download(false, 'https://github.com/Tototex/roxy-suite/releases/download/v1.0.61/roxy-suite-1.0.61.zip', null, ['action' => 'update', 'type' => 'plugin', 'plugins' => $mixed_plugins]);
    check_updater(is_wp_error($blocked_mixed), 'unverified Suite package in mixed bulk list fails closed');
    $other_mixed = \RoxySuite\Updater::filter_upgrader_pre_download(false, 'https://example.test/other.zip', null, ['action' => 'update', 'type' => 'plugin', 'plugins' => $mixed_plugins]);
    check_updater($other_mixed === false, 'unrelated package in mixed bulk list remains untouched during outage');
}
