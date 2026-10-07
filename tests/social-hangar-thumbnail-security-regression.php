<?php
// Isolated Hangar thumbnail URL/fetch checks. Run with: php tests/social-hangar-thumbnail-security-regression.php [candidate-file]
namespace { class WP_Error {} }

namespace RoxySocial {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
    if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

    final class Secrets { public static function decrypt(string $value): string { return $value === '' ? '' : 'fixture-password'; } }

    $GLOBALS['hangar_fixture'] = ['get' => [], 'post' => [], 'cache' => [], 'meta' => [], 'headers' => [], 'response' => null, 'search_body' => '[]'];
    function get_option($key, $default = false) { return $key === 'roxy_social_hangar_user' ? 'fixture-user' : ($key === 'roxy_social_hangar_pass' ? 'encrypted' : $default); }
    function wp_remote_post($url, $args = []) { $GLOBALS['hangar_fixture']['post'][] = [$url, $args]; return ['response' => ['code' => 302], 'headers' => ['set-cookie' => 'sid=fixture']]; }
    function wp_remote_get($url, $args = []) {
        $GLOBALS['hangar_fixture']['get'][] = [$url, $args];
        if (strpos($url, 'digitalAssets/') !== false) return ['response' => ['code' => 200], 'headers' => ['content-type' => 'application/json'], 'body' => $GLOBALS['hangar_fixture']['search_body']];
        return $GLOBALS['hangar_fixture']['response'] ?? ['response' => ['code' => 200], 'headers' => ['content-type' => 'image/png'], 'body' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/nJ8AAAAASUVORK5CYII=')];
    }
    function is_wp_error($value) { return $value instanceof \WP_Error; }
    function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
    function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
    function wp_remote_retrieve_header($response, $name) { return $response['headers'][strtolower($name)] ?? ''; }
    function wp_remote_retrieve_cookies($response) { return !empty($response['headers']['set-cookie']) ? ['sid=fixture'] : []; }
    function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
    function set_transient($key, $value, $expiration) { $GLOBALS['hangar_fixture']['cache'][$key] = $value; }
    function get_transient($key) { return $GLOBALS['hangar_fixture']['cache'][$key] ?? false; }
    function update_post_meta($id, $key, $value) { $GLOBALS['hangar_fixture']['meta'][$id][$key] = $value; return true; }
    function wp_upload_dir() { return ['path' => $GLOBALS['hangar_fixture']['upload_dir'], 'url' => 'https://fixture.invalid/uploads']; }
    function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
    function wp_unique_filename($dir, $filename) { return $filename; }
    function trailingslashit($path) { return rtrim($path, '/\\') . DIRECTORY_SEPARATOR; }
    function sanitize_file_name($name) { return preg_replace('/[^A-Za-z0-9._-]/', '', (string) $name); }
    function header($value) { $GLOBALS['hangar_fixture']['headers'][] = $value; }
    function status_header($code) { $GLOBALS['hangar_fixture']['status'] = $code; }
    function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
    function sanitize_textarea_field($value) { return sanitize_text_field($value); }
    function esc_url_raw($value) { return (string) $value; }

    $candidate = $argv[1] ?? dirname(__DIR__) . '/includes/modules/social-publisher/includes/class-roxy-social-hangar.php';
    if (!is_file($candidate)) throw new \RuntimeException('Candidate Hangar class missing: ' . $candidate);
    require $candidate;
    $validate = new \ReflectionMethod(Hangar::class, 'validated_thumbnail_url');
    $validate->setAccessible(true);
    $fetch = new \ReflectionMethod(Hangar::class, 'fetch_thumbnail');
    $fetch->setAccessible(true);
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

    $upload_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roxy-hangar-thumbnail-' . bin2hex(random_bytes(8));
    if (!mkdir($upload_dir, 0700)) throw new \RuntimeException('Could not create owned temporary upload folder.');
    $GLOBALS['hangar_fixture']['upload_dir'] = $upload_dir;
    register_shutdown_function(static function () use ($upload_dir): void {
        foreach (glob($upload_dir . DIRECTORY_SEPARATOR . '*') ?: [] as $owned_file) if (is_file($owned_file)) @unlink($owned_file);
        if (is_dir($upload_dir)) @rmdir($upload_dir);
    });
    $save_poster = new \ReflectionMethod(Hangar::class, 'save_video_thumbnail');
    $save_poster->setAccessible(true);
    $GLOBALS['hangar_fixture']['cache']['roxy_social_hangar_thumb_31'] = '/posters/source.png';
    $GLOBALS['hangar_fixture']['response'] = ['response' => ['code' => 200], 'headers' => ['content-type' => 'image/png'], 'body' => $png];
    $save_poster->invoke(null, 501, 31, 'movie.mp4');
    $poster_path = $GLOBALS['hangar_fixture']['meta'][501]['_roxy_social_video_poster_file'] ?? '';
    $check(str_ends_with($poster_path, 'movie-poster.png') && is_file($poster_path), 'poster uses detected PNG extension and writes file');
    $check(file_get_contents($poster_path) === $png && ($GLOBALS['hangar_fixture']['meta'][501]['_roxy_social_video_poster_url'] ?? '') === 'https://fixture.invalid/uploads/movie-poster.png', 'poster bytes and metadata match validated image');
    $before_files = count(glob($upload_dir . DIRECTORY_SEPARATOR . '*') ?: []);
    $before_meta = $GLOBALS['hangar_fixture']['meta'][501];
    $GLOBALS['hangar_fixture']['cache']['roxy_social_hangar_thumb_32'] = '/posters/bad.png';
    $GLOBALS['hangar_fixture']['response'] = ['response' => ['code' => 200], 'headers' => ['content-type' => 'image/svg+xml'], 'body' => '<svg/>' ];
    $save_poster->invoke(null, 502, 32, 'bad-video.mp4');
    $check(count(glob($upload_dir . DIRECTORY_SEPARATOR . '*') ?: []) === $before_files && !isset($GLOBALS['hangar_fixture']['meta'][502]), 'rejected SVG poster produces no file or metadata writes');
    $check($GLOBALS['hangar_fixture']['meta'][501] === $before_meta, 'rejected poster leaves prior metadata untouched');

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
