<?php
namespace RoxySuite;

if (!defined('ABSPATH')) exit;

/**
 * Shared GitHub auto-updater for Roxy Suite.
 * Replaces the five identical per-plugin updater classes that previously
 * lived in roxy-arcade, roxy-sub-check, roxy-will-call, roxy-show-tickets,
 * and roxy-event-booking.
 */
class Updater {

    private static array $config = [];
    private static ?array $release = null;

    public static function init(array $config): void {
        self::$config = wp_parse_args($config, [
            'plugin_file' => '',
            'version'     => '',
            'github_repo' => '',
            'slug'        => '',
            'name'        => 'Roxy Suite',
        ]);

        if (
            self::$config['plugin_file'] === '' ||
            self::$config['version']     === '' ||
            self::$config['github_repo'] === '' ||
            self::$config['slug']        === ''
        ) {
            return;
        }

        // Never let update checks block public page rendering. Bluehost shared
        // hosting can be slow to external APIs, so keep GitHub calls in admin,
        // cron, or WP-CLI contexts only.
        if (!is_admin() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'filter_update_plugins']);
        add_filter('plugins_api', [__CLASS__, 'filter_plugins_api'], 20, 3);
        add_filter('upgrader_pre_download', [__CLASS__, 'filter_upgrader_pre_download'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'handle_upgrader_process_complete'], 20, 2);
    }

    private static function get_cache_key(): string {
        return 'roxy_updater_' . md5(self::$config['github_repo'] . '|' . self::$config['slug']);
    }

    public static function filter_update_plugins($transient) {
        if (!is_object($transient)) {
            $transient = new \stdClass();
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = [];
        }

        $release = self::get_latest_release();
        if (!$release) {
            unset($transient->response[self::$config['plugin_file']], $transient->no_update[self::$config['plugin_file']]);
            return $transient;
        }

        $plugin_file     = self::$config['plugin_file'];
        $current_version = (string) self::$config['version'];
        $new_version     = (string) $release['version'];

        if (version_compare($new_version, $current_version, '>')) {
            unset($transient->no_update[$plugin_file]);
            $transient->response[$plugin_file] = (object) [
                'slug'        => self::$config['slug'],
                'plugin'      => $plugin_file,
                'new_version' => $new_version,
                'package'     => $release['download_url'],
                'url'         => $release['html_url'],
            ];
            return $transient;
        }

        unset($transient->response[$plugin_file]);
        $transient->no_update[$plugin_file] = (object) [
            'slug'        => self::$config['slug'],
            'plugin'      => $plugin_file,
            'new_version' => $current_version,
            'package'     => '',
            'url'         => $release['html_url'],
        ];

        return $transient;
    }

    public static function filter_plugins_api($result, string $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::$config['slug']) {
            return $result;
        }

        $release = self::get_latest_release();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name'          => self::$config['name'],
            'slug'          => self::$config['slug'],
            'version'       => $release['version'],
            'download_link' => $release['download_url'],
            'sections'      => [
                'description' => 'Auto updates from GitHub.',
            ],
        ];
    }

    /**
     * Verify our release archive before WordPress passes it to the unpacker.
     * All other plugin, theme, core, and language package downloads pass through.
     */
    public static function filter_upgrader_pre_download($reply, $package, $upgrader, $hook_extra) {
        if (!self::is_our_plugin_update($hook_extra, $package)) {
            return $reply;
        }
        if (is_wp_error($reply)) {
            return $reply;
        }

        $release = self::get_latest_release();
        if (!$release || empty($release['download_url']) || empty($release['archive_sha256'])) {
            return new \WP_Error(
                'roxy_suite_unverified_release',
                'Roxy Suite cannot be updated because this release has no valid integrity manifest. Publish a new version with the release workflow to enable verified updates.'
            );
        }

        if (!is_string($package) || !hash_equals((string) $release['download_url'], $package)) {
            return new \WP_Error('roxy_suite_unexpected_package', 'Roxy Suite update package did not match the verified release.');
        }

        $downloaded_here = ($reply === false);
        if ($downloaded_here) {
            $download = download_url($package, 300);
            if (is_wp_error($download)) {
                return $download;
            }
        } elseif (is_string($reply)) {
            $download = $reply;
        } else {
            return new \WP_Error('roxy_suite_invalid_pre_download_reply', 'Roxy Suite received an invalid pre-download result.');
        }

        // This binds the downloaded bytes to GitHub's manifest; it is not independent signature authentication.
        $actual_hash = is_string($download) && is_file($download) ? @hash_file('sha256', $download) : false;
        if (!is_string($actual_hash) || !hash_equals((string) $release['archive_sha256'], strtolower($actual_hash))) {
            if ($downloaded_here && is_string($download) && is_file($download)) {
                @unlink($download);
            }
            return new \WP_Error('roxy_suite_archive_hash_mismatch', 'Roxy Suite update package failed its SHA-256 integrity check.');
        }

        return $download;
    }

    private static function is_our_plugin_update($hook_extra, $package): bool {
        if (!is_array($hook_extra) || ($hook_extra['action'] ?? '') !== 'update' || ($hook_extra['type'] ?? '') !== 'plugin') {
            return false;
        }

        $plugin_file = (string) self::$config['plugin_file'];
        if ($plugin_file === '') {
            return false;
        }

        if (array_key_exists('plugin', $hook_extra)) {
            return is_string($hook_extra['plugin']) && $hook_extra['plugin'] === $plugin_file;
        }

        if (isset($hook_extra['plugins']) && is_array($hook_extra['plugins']) && count($hook_extra['plugins']) === 1) {
            $plugins = array_values($hook_extra['plugins']);
            return $plugins[0] === $plugin_file;
        }

        return self::is_configured_archive_url($package);
    }

    private static function is_configured_archive_url($url): bool {
        if (!is_string($url)) {
            return false;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== 'github.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $repo = trim((string) self::$config['github_repo'], '/');
        $prefix = '/' . $repo . '/releases/download/';
        $path = (string) ($parts['path'] ?? '');
        if (strpos($path, $prefix) !== 0) {
            return false;
        }
        $segments = explode('/', substr($path, strlen($prefix)));
        if (count($segments) !== 2) {
            return false;
        }
        $version = self::version_from_tag($segments[0]);
        return $version !== null && $segments[1] === self::$config['slug'] . '-' . $version . '.zip';
    }

    public static function handle_upgrader_process_complete($upgrader, $hook_extra): void {
        if (empty(self::$config['plugin_file'])) {
            return;
        }

        $action  = isset($hook_extra['action'])  ? (string) $hook_extra['action']  : '';
        $type    = isset($hook_extra['type'])    ? (string) $hook_extra['type']    : '';
        $plugins = isset($hook_extra['plugins']) && is_array($hook_extra['plugins']) ? $hook_extra['plugins'] : [];

        if ($action !== 'update' || $type !== 'plugin') {
            return;
        }
        if (!in_array(self::$config['plugin_file'], $plugins, true)) {
            return;
        }

        self::clear_update_cache();
    }

    private static function clear_update_cache(): void {
        self::$release = null;
        delete_site_transient(self::get_cache_key());
        delete_site_transient(self::get_cache_key() . '_failed');
        delete_site_transient('update_plugins');
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }
    }

    private static function get_latest_release(): ?array {
        if (self::$release !== null) {
            return self::$release;
        }

        $cache_key = self::get_cache_key();
        $cached    = get_site_transient($cache_key);
        if (is_array($cached) && self::is_valid_cached_release($cached)) {
            self::$release = $cached;
            return self::$release;
        }
        // A provider outage must not add a network timeout to every admin request.
        if (get_site_transient($cache_key . '_failed')) {
            return null;
        }
        // Reserve a short retry window before the request, including malformed responses.
        set_site_transient($cache_key . '_failed', 1, 5 * MINUTE_IN_SECONDS);

        $url      = 'https://api.github.com/repos/' . self::$config['github_repo'] . '/releases/latest';
        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress',
            ],
            'timeout' => 5,
            'limit_response_size' => 1048576,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $release_body = wp_remote_retrieve_body($response);
        if (!is_string($release_body) || strlen($release_body) > 1048576) {
            return null;
        }
        $data = json_decode($release_body, true);
        if (!is_array($data)
            || !isset($data['tag_name'])
            || !is_string($data['tag_name'])
            || $data['tag_name'] === ''
            || empty($data['assets'])
            || !is_array($data['assets'])) {
            return null;
        }

        $version = self::version_from_tag($data['tag_name']);
        if ($version === null) {
            return null;
        }

        $archive_name  = self::$config['slug'] . '-' . $version . '.zip';
        $manifest_name = self::$config['slug'] . '-' . $version . '.manifest.json';
        $archive_url   = '';
        $manifest_url  = '';
        foreach ($data['assets'] as $asset) {
            if (!is_array($asset)
                || !isset($asset['name'], $asset['browser_download_url'])
                || !is_string($asset['name'])
                || !is_string($asset['browser_download_url'])) {
                continue;
            }
            $name                 = $asset['name'];
            $browser_download_url = $asset['browser_download_url'];

            if ($name === $archive_name && self::is_release_asset_url($browser_download_url, $name)) {
                $archive_url = $browser_download_url;
            } elseif ($name === $manifest_name && self::is_release_asset_url($browser_download_url, $name)) {
                $manifest_url = $browser_download_url;
            }
        }

        if ($archive_url === '' || $manifest_url === '') {
            return null;
        }

        $manifest_response = wp_remote_get($manifest_url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress',
            ],
            'timeout' => 5,
            'limit_response_size' => 1048576,
        ]);
        if (is_wp_error($manifest_response) || (int) wp_remote_retrieve_response_code($manifest_response) < 200 || (int) wp_remote_retrieve_response_code($manifest_response) >= 300) {
            return null;
        }

        $manifest_body = wp_remote_retrieve_body($manifest_response);
        if (!is_string($manifest_body) || strlen($manifest_body) > 1048576) {
            return null;
        }
        $manifest = json_decode($manifest_body, true);
        if (!self::validate_manifest($manifest, $version, $archive_name)) {
            return null;
        }

        self::$release = [
            'version'        => $version,
            'download_url'   => $archive_url,
            'archive_sha256' => strtolower($manifest['archive']['sha256']),
            'html_url'       => is_string($data['html_url'] ?? null) ? $data['html_url'] : '',
        ];

        set_site_transient($cache_key, self::$release, 10 * MINUTE_IN_SECONDS);
        delete_site_transient($cache_key . '_failed');

        return self::$release;
    }

    private static function version_from_tag(string $tag): ?string {
        if (strpos($tag, 'v') !== 0) {
            return null;
        }
        $version = substr($tag, 1);
        return preg_match('/\A[0-9]+(?:\.[0-9]+){1,3}(?:-[0-9A-Za-z.-]+)?\z/D', $version) ? $version : null;
    }

    private static function is_release_asset_url(string $url, string $asset_name): bool {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== 'github.com') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $repo = trim((string) self::$config['github_repo'], '/');
        $path = (string) ($parts['path'] ?? '');
        if (substr($asset_name, -14) === '.manifest.json') {
            $version = substr($asset_name, strlen((string) self::$config['slug']) + 1, -14);
        } elseif (substr($asset_name, -4) === '.zip') {
            $version = substr($asset_name, strlen((string) self::$config['slug']) + 1, -4);
        } else {
            return false;
        }
        return self::version_from_tag('v' . $version) === $version
            && $path === '/' . $repo . '/releases/download/v' . $version . '/' . $asset_name;
    }

    private static function validate_manifest($manifest, string $version, string $archive_name): bool {
        if (!is_array($manifest)
            || ($manifest['format_version'] ?? null) !== 1
            || !is_string($manifest['source_sha'] ?? null)
            || !preg_match('/\A[0-9a-f]{40}\z/iD', $manifest['source_sha'])
            || ($manifest['version'] ?? null) !== $version
            || !is_array($manifest['archive'] ?? null)
            || ($manifest['archive']['file'] ?? null) !== $archive_name
            || !is_string($manifest['archive']['sha256'] ?? null)
            || !preg_match('/\A[0-9a-f]{64}\z/iD', $manifest['archive']['sha256'])
            || !is_array($manifest['files'] ?? null)
            || $manifest['files'] === []
            || count($manifest['files']) > 10000) {
            return false;
        }

        $seen = [];
        foreach ($manifest['files'] as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null) || !is_string($file['sha256'] ?? null)
                || !preg_match('/\A[0-9a-f]{64}\z/iD', $file['sha256'])) {
                return false;
            }
            $path = $file['path'];
            if (strpos($path, "\0") !== false || strpos($path, '\\') !== false || strpos($path, '%') !== false
                || strpos($path, '/') === 0 || preg_match('/\A[A-Za-z]:/', $path)
                || preg_match('/[\x00-\x20\x7f:]/', $path)
                || strpos($path, 'roxy-suite/') !== 0 || substr($path, -1) === '/') {
                return false;
            }
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    return false;
                }
            }
            if (isset($seen[$path])) {
                return false;
            }
            $seen[$path] = true;
        }

        return true;
    }

    private static function is_valid_cached_release(array $release): bool {
        $version = isset($release['version']) && is_string($release['version']) ? $release['version'] : '';
        $archive_name = self::$config['slug'] . '-' . $version . '.zip';
        return self::version_from_tag('v' . $version) === $version
            && isset($release['download_url'], $release['archive_sha256'])
            && is_string($release['download_url'])
            && self::is_release_asset_url($release['download_url'], $archive_name)
            && is_string($release['archive_sha256'])
            && preg_match('/\A[0-9a-f]{64}\z/iD', $release['archive_sha256']);
    }
}
