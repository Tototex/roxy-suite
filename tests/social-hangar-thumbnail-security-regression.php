<?php
// Isolated Hangar thumbnail URL/fetch checks. Run with: php tests/social-hangar-thumbnail-security-regression.php [candidate-file]
namespace {
    class WP_Error {}
    $hangar_wp_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-hangar-wp-includes-' . bin2hex(random_bytes(6));
    foreach (['file.php', 'media.php', 'image.php'] as $include_file) {
        $include_dir = $hangar_wp_root . DIRECTORY_SEPARATOR . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes';
        if (!is_dir($include_dir) && !mkdir($include_dir, 0700, true)) throw new RuntimeException('Could not create temporary WordPress include fixture.');
        if (file_put_contents($include_dir . DIRECTORY_SEPARATOR . $include_file, '<?php') === false) throw new RuntimeException('Could not create temporary WordPress include file.');
    }
    define('ABSPATH', $hangar_wp_root . DIRECTORY_SEPARATOR);
    register_shutdown_function(static function () use ($hangar_wp_root): void {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($hangar_wp_root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        if (is_dir($hangar_wp_root)) @rmdir($hangar_wp_root);
    });
}

namespace RoxySocial {
    if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

    final class Secrets { public static function decrypt(string $value): string { return $value === '' ? '' : 'fixture-password'; } }
    final class Store {
        public static array $poster_queue = [];
        private static int $queue_clock = 0;
        private static bool $poster_lock_held = false;
        public static function acquire_poster_lifecycle_lock(): ?array {
            if (self::$poster_lock_held || !empty($GLOBALS['hangar_fixture']['poster_lock_busy'])) return null;
            self::$poster_lock_held = true;
            return ['name' => 'fixture-poster-lock', 'connection' => '1'];
        }
        public static function owns_publish_lock(array $claim): bool {
            return self::$poster_lock_held && ($claim['name'] ?? '') === 'fixture-poster-lock';
        }
        public static function release_publish_lock(array $claim): void {
            if (self::owns_publish_lock($claim)) self::$poster_lock_held = false;
        }
        public static function find(int $id): array { return ['id' => $id, 'status' => $GLOBALS['hangar_fixture']['draft_status'] ?? 'draft']; }
        public static function update_imported_media(...$args): bool { return true; }
        public static function queue_poster_cleanup(string $path): bool {
            if (!empty($GLOBALS['hangar_fixture']['fail_poster_queue'])) return false;
            $hash = hash('sha256', $path);
            self::$poster_queue[$hash] = ['file_path' => $path, 'attempts' => self::$poster_queue[$hash]['attempts'] ?? 0, 'updated_order' => ++self::$queue_clock];
            return true;
        }
        public static function poster_cleanup_batch(int $limit = 100): ?array {
            $rows = array_values(self::$poster_queue);
            usort($rows, static fn($a, $b) => ($a['attempts'] <=> $b['attempts']) ?: ($a['updated_order'] <=> $b['updated_order']));
            return array_slice($rows, 0, $limit);
        }
        public static function record_poster_cleanup_failure(string $path): bool {
            $hash = hash('sha256', $path);
            if (!isset(self::$poster_queue[$hash])) return false;
            self::$poster_queue[$hash]['attempts']++;
            self::$poster_queue[$hash]['updated_order'] = ++self::$queue_clock;
            return true;
        }
        public static function remove_poster_cleanup(string $path): bool { unset(self::$poster_queue[hash('sha256', $path)]); return true; }
        public static function poster_path_is_referenced(string $path): ?bool {
            if (!empty($GLOBALS['hangar_fixture']['reference_read_failed'])) return null;
            if (isset($GLOBALS['hangar_fixture']['during_poster_reference_check'])) {
                $callback = $GLOBALS['hangar_fixture']['during_poster_reference_check'];
                unset($GLOBALS['hangar_fixture']['during_poster_reference_check']);
                $callback($path);
            }
            if (!empty($GLOBALS['hangar_fixture']['referenced_poster'])) return true;
            foreach ($GLOBALS['hangar_fixture']['meta'] as $metadata) {
                if (($metadata['_roxy_social_video_poster_file'] ?? null) === $path) return true;
            }
            return false;
        }
    }

    $GLOBALS['hangar_fixture'] = ['get' => [], 'post' => [], 'cache' => [], 'meta' => [], 'headers' => [], 'response' => null, 'search_body' => '[]', 'scheduled' => []];
    function get_option($key, $default = false) { return $key === 'roxy_social_hangar_user' ? 'fixture-user' : ($key === 'roxy_social_hangar_pass' ? 'encrypted' : $default); }
    function wp_remote_post($url, $args = []) { $GLOBALS['hangar_fixture']['post'][] = [$url, $args]; return ['response' => ['code' => 302], 'headers' => ['set-cookie' => 'sid=fixture']]; }
    function wp_remote_get($url, $args = []) {
        $GLOBALS['hangar_fixture']['get'][] = [$url, $args];
        if (!empty($GLOBALS['hangar_fixture']['throw_http'])) throw new \RuntimeException('Injected HTTP hook exception.');
        if (strpos($url, 'digitalAssets/') !== false) return ['response' => ['code' => 200], 'headers' => ['content-type' => 'application/json'], 'body' => $GLOBALS['hangar_fixture']['search_body']];
        $response = $GLOBALS['hangar_fixture']['response'] ?? ['response' => ['code' => 200], 'headers' => ['content-type' => 'image/png'], 'body' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC0lEQVR42mP8/x8AAwMCAO+/nJ8AAAAASUVORK5CYII=')];
        if (!empty($args['stream']) && !empty($args['filename'])) file_put_contents($args['filename'], (string) ($response['body'] ?? ''));
        return $response;
    }
    function wp_tempnam($filename = '') {
        $path = $GLOBALS['hangar_fixture']['temp_files_dir'] . DIRECTORY_SEPARATOR . 'download-' . bin2hex(random_bytes(6)) . '-' . basename((string) $filename);
        $handle = fopen($path, 'x+b');
        if (!is_resource($handle)) return false;
        fclose($handle);
        $GLOBALS['hangar_fixture']['temp_files'][] = $path;
        return $path;
    }
    function media_handle_sideload($file, $post_id) {
        if (!empty($GLOBALS['hangar_fixture']['throw_sideload'])) throw new \RuntimeException('Injected sideload hook exception.');
        return new \WP_Error();
    }
    function is_wp_error($value) { return $value instanceof \WP_Error; }
    function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
    function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
    function wp_remote_retrieve_header($response, $name) { return $response['headers'][strtolower($name)] ?? ''; }
    function wp_remote_retrieve_cookies($response) { return !empty($response['headers']['set-cookie']) ? ['sid=fixture'] : []; }
    function add_query_arg($key, $value = null, $url = null) {
        if (is_array($key)) { $args = $key; $target = (string) $value; }
        else { $args = [(string) $key => $value]; $target = (string) $url; }
        return $target . (strpos($target, '?') === false ? '?' : '&') . http_build_query($args);
    }
    function set_transient($key, $value, $expiration) { $GLOBALS['hangar_fixture']['cache'][$key] = $value; }
    function get_transient($key) { return $GLOBALS['hangar_fixture']['cache'][$key] ?? false; }
    function wp_next_scheduled($hook, $args = []) { foreach ($GLOBALS['hangar_fixture']['scheduled'] as $event) if ($event['hook'] === $hook && $event['args'] === $args) return 1; return false; }
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        if (!empty($GLOBALS['hangar_fixture']['reject_schedule'])) return false;
        $GLOBALS['hangar_fixture']['scheduled'][] = ['time' => $timestamp, 'hook' => $hook, 'args' => $args];
        return true;
    }
    function update_post_meta($id, $key, $value) {
        if (($GLOBALS['hangar_fixture']['fail_meta_key'] ?? '') === $key) return false;
        $GLOBALS['hangar_fixture']['meta'][$id][$key] = $value;
        if ((int) ($GLOBALS['hangar_fixture']['readonly_upload_dir_after_meta_for'] ?? 0) === (int) $id && $key === '_roxy_social_video_poster_file') {
            chmod($GLOBALS['hangar_fixture']['upload_dir'], 0500);
        }
        return true;
    }
    function get_post_meta($id, $key, $single = false) { return $GLOBALS['hangar_fixture']['meta'][$id][$key] ?? ''; }
    function delete_post_meta($id, $key) { unset($GLOBALS['hangar_fixture']['meta'][$id][$key]); return true; }
    function wp_upload_dir() {
        if (!empty($GLOBALS['hangar_fixture']['uploads_unavailable'])) return ['error' => 'fixture unavailable'];
        return ['path' => $GLOBALS['hangar_fixture']['upload_dir'], 'basedir' => $GLOBALS['hangar_fixture']['upload_dir'], 'url' => 'https://fixture.invalid/uploads'];
    }
    function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
    function wp_unique_filename($dir, $filename) { return $filename; }
    function trailingslashit($path) { return rtrim($path, '/\\') . '/'; }
    function sanitize_file_name($name) { return preg_replace('/[^A-Za-z0-9._-]/', '', (string) $name); }
    function header($value) { $GLOBALS['hangar_fixture']['headers'][] = $value; }
    function status_header($code) { $GLOBALS['hangar_fixture']['status'] = $code; }
    function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
    function sanitize_textarea_field($value) { return sanitize_text_field($value); }
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
    function esc_url_raw($value) { return (string) $value; }

    $candidate = $argv[1] ?? dirname(__DIR__) . '/includes/modules/social-publisher/includes/class-roxy-social-hangar.php';
    if (!is_file($candidate)) throw new \RuntimeException('Candidate Hangar class missing: ' . $candidate);
    require $candidate;
    $validate = new \ReflectionMethod(Hangar::class, 'validated_thumbnail_url');
    if (PHP_VERSION_ID < 80100) $validate->setAccessible(true);
    $fetch = new \ReflectionMethod(Hangar::class, 'fetch_thumbnail');
    if (PHP_VERSION_ID < 80100) $fetch->setAccessible(true);
    if (($argv[2] ?? '') === 'proxy-child') {
        $GLOBALS['hangar_fixture']['cache']['roxy_social_hangar_thumb_22'] = 'https://hangar.paperairmedia.com/thumb.png';
        $GLOBALS['hangar_fixture']['response'] = ['response' => ['code' => 200], 'headers' => ['content-type' => 'image/png'], 'body' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/nJ8AAAAASUVORK5CYII=')];
        register_shutdown_function(static function (): void { echo "\nHEADER_REPORT:" . json_encode($GLOBALS['hangar_fixture']['headers']); });
        Hangar::thumbnail_response(22);
    }
    $checks = 0;
    $check = static function ($ok, string $label) use (&$checks): void {
        $checks++;
        if (!$ok) throw new \RuntimeException('FAIL: ' . $label);
    };

    $base = 'https://hangar.paperairmedia.com';
    $check($validate->invoke(null, '/images/a.png') === $base . '/images/a.png', 'valid rooted relative URL canonicalizes');
    $check($validate->invoke(null, 'images/a.png') === $base . '/images/a.png', 'valid relative URL canonicalizes');
    $check($validate->invoke(null, $base . ':443/images/a.png') === $base . '/images/a.png', 'exact HTTPS host and 443 accepted');
    $check($validate->invoke(null, 'HTTPS://HANGAR.PAPERAIRMEDIA.COM/images/a.png?size=small#preview') === $base . '/images/a.png?size=small', 'uppercase exact host and query accepted; fragment is not fetched');
    foreach ([
        '//evil.example/a.png', 'https://evil.example/a.png', 'http://hangar.paperairmedia.com/a.png',
        'https://user@hangar.paperairmedia.com/a.png', 'https://hangar.paperairmedia.com:444/a.png',
        'https://hangar.paperairmedia.com.evil.example/a.png', '/a/../secret.png', '/a/%2e%2e/secret.png',
        '/a/%252e%252e/secret.png', '/a/%2525252e%2525252e/secret.png',
        "images/evil\\path.png", "/bad\npath.png", 'javascript:alert(1)',
    ] as $bad) $check($validate->invoke(null, $bad) === null, 'reject unsafe URL ' . json_encode($bad));

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/nJ8AAAAASUVORK5CYII=');
    $GLOBALS['hangar_fixture']['response'] = ['response' => ['code' => 200], 'headers' => ['content-type' => 'image/png'], 'body' => $png];
    $image = $fetch->invoke(null, '/poster.png', 2048);
    $check(is_array($image) && $image['mime'] === 'image/png' && $image['extension'] === 'png', 'tiny valid PNG accepted with detected extension');
    $last = end($GLOBALS['hangar_fixture']['get']);
    $check(($last[1]['redirection'] ?? -1) === 0 && ($last[1]['limit_response_size'] ?? 0) === 2048, 'thumbnail fetch disables redirects and bounds bytes');
    $check(($last[1]['cookies'] ?? []) === ['sid=fixture'], '302 login Set-Cookie is retained for thumbnail request');
    $before = count($GLOBALS['hangar_fixture']['get']);
    $posts_before = count($GLOBALS['hangar_fixture']['post']);
    $check($fetch->invoke(null, 'https://evil.example/hidden.png', 2048) === null && count($GLOBALS['hangar_fixture']['get']) === $before && count($GLOBALS['hangar_fixture']['post']) === $posts_before, 'invalid host rejected before login or fetch network');
    foreach ([
        [['response' => ['code' => 302], 'headers' => ['content-type' => 'image/png'], 'body' => $png], 'redirect rejected'],
        [['response' => ['code' => 200], 'headers' => ['content-type' => 'text/html'], 'body' => '<svg onload=alert(1)>'], 'SVG/HTML rejected'],
        [['response' => ['code' => 200], 'headers' => ['content-type' => 'image/svg+xml'], 'body' => '<svg xmlns="http://www.w3.org/2000/svg"/>'], 'explicit SVG MIME rejected'],
        [['response' => ['code' => 200], 'headers' => ['content-type' => 'image/jpeg'], 'body' => $png], 'MIME mismatch rejected'],
        [['response' => ['code' => 200], 'headers' => [], 'body' => $png], 'missing MIME rejected'],
        [['response' => ['code' => 404], 'headers' => ['content-type' => 'image/png'], 'body' => $png], '404 rejected'],
        [['response' => ['code' => 200], 'headers' => ['content-type' => 'image/png'], 'body' => str_repeat('x', 2049)], 'oversized response rejected'],
        [new \WP_Error(), 'WP_Error rejected'],
    ] as [$response, $label]) {
        $GLOBALS['hangar_fixture']['response'] = $response;
        $check($fetch->invoke(null, '/poster.png', 2048) === null, $label);
    }
    // The WP_Error rejection case above is deliberately terminal; restore a
    // valid response before later import/sideload fixtures exercise streaming.
    $GLOBALS['hangar_fixture']['response'] = ['response' => ['code' => 200], 'headers' => ['content-type' => 'image/png'], 'body' => $png];

    $upload_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-hangar-thumbnail-' . bin2hex(random_bytes(8));
    if (!mkdir($upload_dir, 0700)) throw new \RuntimeException('Could not create owned temporary upload folder.');
    $GLOBALS['hangar_fixture']['upload_dir'] = $upload_dir;
    $GLOBALS['hangar_fixture']['temp_files_dir'] = $upload_dir;
    $GLOBALS['hangar_fixture']['temp_files'] = [];
    register_shutdown_function(static function () use ($upload_dir): void {
        foreach (glob($upload_dir . DIRECTORY_SEPARATOR . '*') ?: [] as $owned_file) if (is_file($owned_file)) @unlink($owned_file);
        if (is_dir($upload_dir)) @rmdir($upload_dir);
    });
    $save_poster = new \ReflectionMethod(Hangar::class, 'save_video_thumbnail');
    if (PHP_VERSION_ID < 80100) $save_poster->setAccessible(true);
    $write_download = new \ReflectionMethod(Hangar::class, 'write_download_temp_file');
    if (PHP_VERSION_ID < 80100) $write_download->setAccessible(true);
    $download_temp = $upload_dir . DIRECTORY_SEPARATOR . 'featured-image.tmp';
    file_put_contents($download_temp, 'seed');
    $check($write_download->invoke(null, $download_temp, $png) === true && file_get_contents($download_temp) === $png, 'featured-image temp download verifies complete byte count');
    $check($write_download->invoke(null, $download_temp, '') === false && !file_exists($download_temp), 'invalid temp download body fails and removes the request-owned temp file');
    $check($write_download->invoke(null, $upload_dir, $png) === false && is_dir($upload_dir), 'failed temp write does not remove a non-file path');

    foreach ([
        ['social-http', static function () { $failure = null; $id = Hangar::import_social_asset(901, 'asset.mp4', 11, 21, $failure); return [$id, $failure]; }, 'download', 'HTTP hook failure is reported and cleans the download temp'],
        ['social-sideload', static function () { $failure = null; $id = Hangar::import_social_asset(902, 'asset.mp4', 11, 22, $failure); return [$id, $failure]; }, 'wordpress_import', 'sideload hook failure is reported and cleans the download temp'],
        ['featured-http', static fn() => Hangar::import_featured_image(903, 'featured.png', 31), 'featured-image HTTP exception creates no temp file'],
        ['featured-sideload', static fn() => Hangar::import_featured_image(904, 'featured.png', 32), 'thrown sideload hook cleans the featured-image temp'],
    ] as $fixture) {
        [$mode, $call] = $fixture;
        $before_temps = count($GLOBALS['hangar_fixture']['temp_files']);
        $GLOBALS['hangar_fixture']['throw_http'] = str_ends_with($mode, 'http');
        $GLOBALS['hangar_fixture']['throw_sideload'] = str_ends_with($mode, 'sideload');
        $thrown = false;
        $result = null;
        try { $result = $call(); } catch (\RuntimeException $error) { $thrown = true; }
        $GLOBALS['hangar_fixture']['throw_http'] = false;
        $GLOBALS['hangar_fixture']['throw_sideload'] = false;
        $new_temps = array_slice($GLOBALS['hangar_fixture']['temp_files'], $before_temps);
        if (count($fixture) === 4) {
            $check(!$thrown && $result[0] === 0 && ($result[1]['code'] ?? '') === $fixture[2] && (!$new_temps || count(array_filter($new_temps, 'is_file')) === 0), $fixture[3]);
        } else {
            $check($thrown && (!$new_temps || count(array_filter($new_temps, 'is_file')) === 0), $fixture[2]);
        }
    }
    $GLOBALS['hangar_fixture']['draft_status'] = 'approved';
    $GLOBALS['hangar_fixture']['throw_sideload'] = true;
    $before_downloads = count($GLOBALS['hangar_fixture']['get']);
    $before_approved_temps = count($GLOBALS['hangar_fixture']['temp_files']);
    $failure = null;
    $imported = Hangar::import_social_asset(905, 'scheduled-trailer.mp4', 11, 25, $failure);
    $GLOBALS['hangar_fixture']['throw_sideload'] = false;
    $approved_temps = array_slice($GLOBALS['hangar_fixture']['temp_files'], $before_approved_temps);
    $check($imported === 0 && ($failure['code'] ?? '') === 'wordpress_import' && count($GLOBALS['hangar_fixture']['get']) > $before_downloads && (!$approved_temps || count(array_filter($approved_temps, 'is_file')) === 0), 'approved unpublished post can import media and cleans its temp after WordPress failure');
    $GLOBALS['hangar_fixture']['draft_status'] = 'publishing';
    $before_downloads = count($GLOBALS['hangar_fixture']['get']);
    $failure = null;
    $imported = Hangar::import_social_asset(906, 'scheduled-trailer.mp4', 11, 26, $failure);
    $check($imported === 0 && ($failure['code'] ?? '') === 'draft_not_editable' && count($GLOBALS['hangar_fixture']['get']) === $before_downloads, 'publishing post rejects media import before downloading');
    $GLOBALS['hangar_fixture']['draft_status'] = 'draft';
    $GLOBALS['hangar_fixture']['cache']['roxy_social_hangar_thumb_31'] = '/posters/source.png';
    $GLOBALS['hangar_fixture']['response'] = ['response' => ['code' => 200], 'headers' => ['content-type' => 'image/png'], 'body' => $png];
    $save_poster->invoke(null, 501, 31, 'movie.mp4');
    $poster_path = $GLOBALS['hangar_fixture']['meta'][501]['_roxy_social_video_poster_file'] ?? '';
    $check(str_ends_with($poster_path, 'movie-poster.png') && is_file($poster_path), 'poster uses detected PNG extension and writes file');
    $poster_bytes = file_get_contents($poster_path);
    $poster_url = $GLOBALS['hangar_fixture']['meta'][501]['_roxy_social_video_poster_url'] ?? '';
    $check($poster_bytes === $png && $poster_url === 'https://fixture.invalid/uploads/movie-poster.png', 'poster bytes and metadata match validated image (bytes=' . strlen((string) $poster_bytes) . ', expected=' . strlen($png) . ', url=' . $poster_url . ')');
    $collision_path = $upload_dir . DIRECTORY_SEPARATOR . 'movie-poster.png';
    $check(file_put_contents($collision_path, 'do-not-overwrite') !== false, 'prepare poster-name collision fixture');
    $before_collision_meta = $GLOBALS['hangar_fixture']['meta'][503] ?? [];
    $save_poster->invoke(null, 503, 31, 'movie.mp4');
    $check(file_get_contents($collision_path) === 'do-not-overwrite' && $before_collision_meta === ($GLOBALS['hangar_fixture']['meta'][503] ?? []), 'poster filename collision never overwrites an existing file');
    unlink($collision_path);
    $GLOBALS['hangar_fixture']['fail_meta_key'] = '_roxy_social_video_poster_file';
    $save_poster->invoke(null, 504, 31, 'failed-meta.mp4');
    unset($GLOBALS['hangar_fixture']['fail_meta_key']);
    $check(empty($GLOBALS['hangar_fixture']['meta'][504]) && !is_file($upload_dir . DIRECTORY_SEPARATOR . 'failed-meta-poster.png'), 'failed poster metadata write rolls back metadata and removes only its new file');
    $old_poster_path = $upload_dir . DIRECTORY_SEPARATOR . 'previous-poster.png';
    file_put_contents($old_poster_path, 'preserve-prior-poster');
    $GLOBALS['hangar_fixture']['meta'][507] = [
        '_roxy_social_video_poster_url' => 'https://fixture.invalid/uploads/previous-poster.png',
        '_roxy_social_video_poster_file' => $old_poster_path,
    ];
    $old_poster_meta = $GLOBALS['hangar_fixture']['meta'][507];
    $GLOBALS['hangar_fixture']['fail_meta_key'] = '_roxy_social_video_poster_file';
    $save_poster->invoke(null, 507, 31, 'replacement.mp4');
    unset($GLOBALS['hangar_fixture']['fail_meta_key']);
    $check($GLOBALS['hangar_fixture']['meta'][507] === $old_poster_meta && file_get_contents($old_poster_path) === 'preserve-prior-poster'
        && !is_file($upload_dir . DIRECTORY_SEPARATOR . 'replacement-poster.png'), 'failed replacement preserves prior poster metadata and bytes');
    $replace_old_path = $upload_dir . DIRECTORY_SEPARATOR . 'replace-me-poster.png';
    file_put_contents($replace_old_path, 'obsolete-poster');
    $GLOBALS['hangar_fixture']['meta'][509] = [
        '_roxy_social_video_poster_url' => 'https://fixture.invalid/uploads/replace-me-poster.png',
        '_roxy_social_video_poster_file' => $replace_old_path,
    ];
    $save_poster->invoke(null, 509, 31, 'new-poster.mp4');
    $replacement_path = $GLOBALS['hangar_fixture']['meta'][509]['_roxy_social_video_poster_file'] ?? '';
    $check(!file_exists($replace_old_path) && is_file($replacement_path) && file_get_contents($replacement_path) === $png,
        'verified poster replacement removes only the previous owned poster and retains the new file');
    $stale_path = $upload_dir . DIRECTORY_SEPARATOR . 'stale-poster.png';
    file_put_contents($stale_path, 'old-poster');
    $GLOBALS['hangar_fixture']['meta'][512] = [
        '_roxy_social_video_poster_url' => 'https://fixture.invalid/uploads/stale-poster.png',
        '_roxy_social_video_poster_file' => $stale_path,
    ];
    $GLOBALS['hangar_fixture']['readonly_upload_dir_after_meta_for'] = 512;
    $save_poster->invoke(null, 512, 31, 'replacement-cleanup.mp4');
    chmod($upload_dir, 0700);
    unset($GLOBALS['hangar_fixture']['readonly_upload_dir_after_meta_for']);
    $new_stale_replacement = $GLOBALS['hangar_fixture']['meta'][512]['_roxy_social_video_poster_file'] ?? '';
    $queued_replacement = end($GLOBALS['hangar_fixture']['scheduled']);
    $check(is_file($stale_path) && is_file($new_stale_replacement) && ($queued_replacement['args'][0] ?? '') === $stale_path,
        'failed deletion of a replaced poster queues the old owned file without disturbing the verified replacement');
    Hangar::retry_video_thumbnail_cleanup($stale_path, 1);
    $check(!file_exists($stale_path) && is_file($new_stale_replacement), 'replacement cleanup retry removes only the stale poster');
    $outside_old_path = $upload_dir . '-previous-poster.png';
    file_put_contents($outside_old_path, 'preserve-outside-poster');
    $GLOBALS['hangar_fixture']['meta'][510] = [
        '_roxy_social_video_poster_url' => 'https://fixture.invalid/previous-poster.png',
        '_roxy_social_video_poster_file' => $outside_old_path,
    ];
    $save_poster->invoke(null, 510, 31, 'new-outside-poster.mp4');
    $check(is_file($outside_old_path) && file_get_contents($outside_old_path) === 'preserve-outside-poster',
        'poster replacement never deletes a previous path outside the configured uploads directory');
    unlink($outside_old_path);
    $before_files = count(glob($upload_dir . DIRECTORY_SEPARATOR . '*') ?: []);
    $before_meta = $GLOBALS['hangar_fixture']['meta'][501];
    $GLOBALS['hangar_fixture']['cache']['roxy_social_hangar_thumb_32'] = '/posters/bad.png';
    $GLOBALS['hangar_fixture']['response'] = ['response' => ['code' => 200], 'headers' => ['content-type' => 'image/svg+xml'], 'body' => '<svg/>' ];
    $save_poster->invoke(null, 502, 32, 'bad-video.mp4');
    $check(count(glob($upload_dir . DIRECTORY_SEPARATOR . '*') ?: []) === $before_files && !isset($GLOBALS['hangar_fixture']['meta'][502]), 'rejected SVG poster produces no file or metadata writes');
    $check($GLOBALS['hangar_fixture']['meta'][501] === $before_meta, 'rejected poster leaves prior metadata untouched');

    $outside_path = $upload_dir . '-outside-poster.png';
    file_put_contents($outside_path, 'keep-outside-file');
    $GLOBALS['hangar_fixture']['meta'][505]['_roxy_social_video_poster_file'] = $outside_path;
    $delete_result = Hangar::preserve_video_thumbnail_before_delete(null, (object) ['ID' => 505, 'post_type' => 'attachment'], true);
    $check($delete_result === null && is_file($outside_path) && isset($GLOBALS['hangar_fixture']['meta'][505]['_roxy_social_video_poster_file']), 'pre-delete queues poster without unlinking or dropping the still-live attachment pointer');
    unset($GLOBALS['hangar_fixture']['meta'][505]);
    Hangar::cleanup_video_thumbnail_queue();
    $check(is_file($outside_path), 'cleanup preserves a poster path outside the configured uploads directory');
    unlink($outside_path);
    $owned_path = $upload_dir . DIRECTORY_SEPARATOR . 'owned-poster.png';
    file_put_contents($owned_path, $png);
    $GLOBALS['hangar_fixture']['meta'][506]['_roxy_social_video_poster_file'] = $owned_path;
    $delete_result = Hangar::preserve_video_thumbnail_before_delete(null, (object) ['ID' => 506, 'post_type' => 'attachment'], true);
    $check($delete_result === null && is_file($owned_path), 'attachment poster is queued before WordPress deletion');
    unset($GLOBALS['hangar_fixture']['meta'][506]);
    Hangar::cleanup_video_thumbnail_queue();
    $check(!file_exists($owned_path), 'regular cleanup removes an unreferenced plugin-named poster within uploads');
    $numbered_path = $upload_dir . DIRECTORY_SEPARATOR . 'collision-poster-1.png';
    file_put_contents($numbered_path, $png);
    $GLOBALS['hangar_fixture']['meta'][508]['_roxy_social_video_poster_file'] = $numbered_path;
    Hangar::preserve_video_thumbnail_before_delete(null, (object) ['ID' => 508, 'post_type' => 'attachment'], true);
    unset($GLOBALS['hangar_fixture']['meta'][508]);
    Hangar::cleanup_video_thumbnail_queue();
    $check(!file_exists($numbered_path), 'regular cleanup removes WordPress collision-renamed poster files');

    $retry_path = $upload_dir . DIRECTORY_SEPARATOR . 'retry-poster.png';
    file_put_contents($retry_path, $png);
    $GLOBALS['hangar_fixture']['meta'][511]['_roxy_social_video_poster_file'] = $retry_path;
    $GLOBALS['hangar_fixture']['reject_schedule'] = true;
    $retry_schedule_count = count($GLOBALS['hangar_fixture']['scheduled']);
    Hangar::preserve_video_thumbnail_before_delete(null, (object) ['ID' => 511, 'post_type' => 'attachment'], true);
    unset($GLOBALS['hangar_fixture']['meta'][511]);
    $GLOBALS['hangar_fixture']['uploads_unavailable'] = true;
    Hangar::retry_video_thumbnail_cleanup($retry_path, 5);
    $check(is_file($retry_path) && isset(Store::$poster_queue[hash('sha256', $retry_path)])
        && count($GLOBALS['hangar_fixture']['scheduled']) === $retry_schedule_count, 'cron rejection and exhausted bounded retries retain the durable poster cleanup row');
    unset($GLOBALS['hangar_fixture']['uploads_unavailable'], $GLOBALS['hangar_fixture']['reject_schedule']);
    Hangar::cleanup_video_thumbnail_queue();
    $check(!file_exists($retry_path) && !isset(Store::$poster_queue[hash('sha256', $retry_path)]), 'daily cleanup recovers a durable poster after cron rejection and retry exhaustion');

    $lock_busy_path = $upload_dir . DIRECTORY_SEPARATOR . 'lock-busy-poster.png';
    file_put_contents($lock_busy_path, $png);
    $GLOBALS['hangar_fixture']['poster_lock_busy'] = true;
    Hangar::retry_video_thumbnail_cleanup($lock_busy_path, 1);
    $check(is_file($lock_busy_path) && isset(Store::$poster_queue[hash('sha256', $lock_busy_path)]), 'poster cleanup lock contention preserves file and durable retry row');
    unset($GLOBALS['hangar_fixture']['poster_lock_busy']);
    Hangar::retry_video_thumbnail_cleanup($lock_busy_path, 1);
    $check(!file_exists($lock_busy_path), 'poster cleanup retries after lifecycle lock becomes available');

    $race_path = $upload_dir . DIRECTORY_SEPARATOR . 'poster-reference-race-poster.png';
    file_put_contents($race_path, $png);
    $GLOBALS['hangar_fixture']['during_poster_reference_check'] = static function (string $path): void {
        $writer_claim = Store::acquire_poster_lifecycle_lock();
        if ($writer_claim === null) $GLOBALS['hangar_fixture']['poster_writer_blocked'] = true;
        else {
            $GLOBALS['hangar_fixture']['meta'][520]['_roxy_social_video_poster_file'] = $path;
            Store::release_publish_lock($writer_claim);
        }
    };
    Hangar::retry_video_thumbnail_cleanup($race_path, 1);
    $check(!empty($GLOBALS['hangar_fixture']['poster_writer_blocked']) && !file_exists($race_path)
        && !isset($GLOBALS['hangar_fixture']['meta'][520]['_roxy_social_video_poster_file']), 'poster reference writer cannot interleave between cleanup reference check and unlink');

    $queue_failure_path = $upload_dir . DIRECTORY_SEPARATOR . 'queue-failure-poster.png';
    file_put_contents($queue_failure_path, $png);
    $GLOBALS['hangar_fixture']['meta'][514]['_roxy_social_video_poster_file'] = $queue_failure_path;
    $GLOBALS['hangar_fixture']['fail_poster_queue'] = true;
    $delete_result = Hangar::preserve_video_thumbnail_before_delete(null, (object) ['ID' => 514, 'post_type' => 'attachment'], true);
    $check($delete_result === false && is_file($queue_failure_path)
        && ($GLOBALS['hangar_fixture']['meta'][514]['_roxy_social_video_poster_file'] ?? '') === $queue_failure_path,
        'durable queue write failure vetoes attachment deletion and preserves its poster pointer');
    unset($GLOBALS['hangar_fixture']['fail_poster_queue']);
    Hangar::preserve_video_thumbnail_before_delete(null, (object) ['ID' => 514, 'post_type' => 'attachment'], true);
    unset($GLOBALS['hangar_fixture']['meta'][514]);
    Hangar::cleanup_video_thumbnail_queue();
    $check(!file_exists($queue_failure_path), 'vetoed attachment can be safely deleted and its poster cleaned after queue recovery');

    $shared_path = $upload_dir . DIRECTORY_SEPARATOR . 'shared-poster.png';
    file_put_contents($shared_path, $png);
    $GLOBALS['hangar_fixture']['meta'][515]['_roxy_social_video_poster_file'] = $shared_path;
    $GLOBALS['hangar_fixture']['meta'][516]['_roxy_social_video_poster_file'] = $shared_path;
    Hangar::preserve_video_thumbnail_before_delete(null, (object) ['ID' => 515, 'post_type' => 'attachment'], true);
    unset($GLOBALS['hangar_fixture']['meta'][515]);
    Hangar::cleanup_video_thumbnail_queue();
    $check(is_file($shared_path) && isset(Store::$poster_queue[hash('sha256', $shared_path)]), 'shared attachment reference prevents poster unlink and keeps its queue row');
    unset($GLOBALS['hangar_fixture']['meta'][516]);
    Hangar::cleanup_video_thumbnail_queue();
    $check(!file_exists($shared_path), 'shared poster is removed only after its final attachment reference disappears');

    $GLOBALS['hangar_fixture']['uploads_unavailable'] = true;
    Store::$poster_queue = [];
    for ($index = 0; $index < 105; $index++) Store::queue_poster_cleanup($upload_dir . DIRECTORY_SEPARATOR . sprintf('fairness-%03d-poster.png', $index));
    Hangar::cleanup_video_thumbnail_queue();
    $first_pass = count(array_filter(Store::$poster_queue, static fn($row) => $row['attempts'] > 0));
    Hangar::cleanup_video_thumbnail_queue();
    $all_attempted = count(array_filter(Store::$poster_queue, static fn($row) => $row['attempts'] > 0));
    unset($GLOBALS['hangar_fixture']['uploads_unavailable']);
    $check($first_pass === 100 && $all_attempted === 105, 'attempt-count ordering rotates bounded batches so queued paths beyond the first 100 are reached');

    $unavailable_root_path = $upload_dir . DIRECTORY_SEPARATOR . 'uploads-unavailable-poster.png';
    file_put_contents($unavailable_root_path, $png);
    $GLOBALS['hangar_fixture']['meta'][513] = ['_roxy_social_video_poster_file' => $unavailable_root_path];
    $GLOBALS['hangar_fixture']['uploads_unavailable'] = true;
    $delete_result = Hangar::preserve_video_thumbnail_before_delete(null, (object) ['ID' => 513, 'post_type' => 'attachment'], true);
    $check($delete_result === null && isset($GLOBALS['hangar_fixture']['meta'][513]['_roxy_social_video_poster_file']), 'unavailable uploads root does not discard the live attachment pointer before core deletion');
    unset($GLOBALS['hangar_fixture']['meta'][513]);
    unset($GLOBALS['hangar_fixture']['uploads_unavailable']);
    $unavailable_root_event = end($GLOBALS['hangar_fixture']['scheduled']);
    $check(is_file($unavailable_root_path)
        && ($unavailable_root_event['hook'] ?? '') === 'roxy_social_cleanup_video_poster'
        && ($unavailable_root_event['args'][0] ?? '') === $unavailable_root_path,
        'unavailable uploads root preserves owned poster path in bounded retry instead of dropping cleanup');
    Hangar::retry_video_thumbnail_cleanup($unavailable_root_path, 1);
    $check(!file_exists($unavailable_root_path), 'deferred poster cleanup succeeds when uploads root becomes available');

    $GLOBALS['hangar_fixture']['response'] = ['response' => ['code' => 200], 'headers' => ['content-type' => 'image/png'], 'body' => $png];
    $GLOBALS['hangar_fixture']['search_body'] = json_encode([
        ['asset_id' => 21, 'thumbFilePath' => '//evil.example/a.png'],
        ['asset_id' => 22, 'thumbFilePath' => '/thumbs/good.png'],
    ]);
    $results = Hangar::search('poster');
    $check(($results[0]['thumbnail_url'] ?? null) === '' && !isset($GLOBALS['hangar_fixture']['cache']['roxy_social_hangar_thumb_21']), 'invalid search thumbnail is neither returned nor cached');
    $check(($results[1]['thumbnail_url'] ?? '') === $base . '/thumbs/good.png' && ($GLOBALS['hangar_fixture']['cache']['roxy_social_hangar_thumb_22'] ?? '') === $base . '/thumbs/good.png', 'validated search thumbnail is cached canonically');
    $check(($GLOBALS['hangar_fixture']['post'][0][1]['redirection'] ?? -1) === 0, 'credential login does not follow redirects');

    $proxy_headers_tested = false;
    if (function_exists('proc_open')) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, $candidate, 'proxy-child'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($process)) {
            $proxy_headers_tested = true;
            fclose($pipes[0]);
            $child_out = stream_get_contents($pipes[1]);
            $child_err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $child_status = proc_close($process);
            $marker = strrpos((string) $child_out, 'HEADER_REPORT:');
            $reported_headers = $marker === false ? null : json_decode(substr($child_out, $marker + strlen('HEADER_REPORT:')), true);
            $check($child_status === 0 && is_array($reported_headers) && in_array('Content-Type: image/png', $reported_headers, true)
                && in_array('X-Content-Type-Options: nosniff', $reported_headers, true)
                && in_array('Cache-Control: private, max-age=3600', $reported_headers, true), 'proxy subprocess emits safe raster headers' . ($child_err !== '' ? ' (stderr captured)' : ''));
        }
    }

    echo "PASS: {$checks} Hangar thumbnail security checks\n";
    if (!$proxy_headers_tested) echo "SKIP: proxy response headers not exercised (CLI subprocess unavailable)\n";
}
