<?php
namespace RoxySocial;

if (!defined('ABSPATH')) exit;

final class Hangar {
    private const BASE_URL = 'https://hangar.paperairmedia.com/';
    private const MAX_VIDEO_BYTES = 500 * 1024 * 1024;
    private const USER_OPTION = 'roxy_social_hangar_user';
    private const PASS_OPTION = 'roxy_social_hangar_pass';

    public static function save_credentials(string $user, string $password): bool {
        try { $encrypted=self::encrypt($password); } catch (\Throwable $error) { return false; }
        update_option(self::USER_OPTION, sanitize_text_field($user), false);
        update_option(self::PASS_OPTION, $encrypted, false);
        return (string)get_option(self::USER_OPTION,'')===sanitize_text_field($user) && hash_equals($encrypted,(string)get_option(self::PASS_OPTION,''));
    }

    public static function has_credentials(): bool {
        return (string) get_option(self::USER_OPTION, '') !== '' && self::decrypt((string) get_option(self::PASS_OPTION, '')) !== '';
    }

    public static function search(string $term, string $type = '', string $date_sort = ''): array {
        $term = trim($term);
        if ($term === '' || !self::has_credentials()) return [];
        $cookies = self::login_cookies();
        if (!$cookies) return [];

        $url = add_query_arg(['type' => 'genericSearch', 'searchTerm' => $term], self::BASE_URL . 'digitalAssets/');
        $response = wp_remote_get($url, ['timeout' => 30, 'redirection' => 3, 'cookies' => $cookies]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) return [];
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) return [];
        $results = [];
        foreach ($data as $asset) {
            if (!is_array($asset) || empty($asset['asset_id'])) continue;
            $results[] = [
                'asset_id' => (int) $asset['asset_id'],
                'asset_name' => sanitize_text_field((string) ($asset['asset_name'] ?? '')),
                'asset_category' => sanitize_text_field((string) ($asset['asset_category'] ?? '')),
                'filename' => sanitize_text_field((string) ($asset['filename'] ?? '')),
                'description' => sanitize_textarea_field((string) ($asset['asset_description'] ?? '')),
                'file_type' => sanitize_text_field((string) ($asset['file_type'] ?? '')),
                'runtime' => sanitize_text_field((string) ($asset['file_human_attribute'] ?? '')),
                'start_date' => sanitize_text_field((string) ($asset['start_date'] ?? '')),
                'expiration_date' => sanitize_text_field((string) ($asset['expiration_date'] ?? '')),
                'thumbnail_url' => self::validated_thumbnail_url((string) ($asset['thumbFilePath'] ?? '')) ?? '',
            ];
            if ($results[count($results) - 1]['thumbnail_url'] !== '') {
                set_transient('roxy_social_hangar_thumb_' . (int) $asset['asset_id'], $results[count($results) - 1]['thumbnail_url'], HOUR_IN_SECONDS);
            }
        }
        if ($type !== '') $results = array_values(array_filter($results, static function ($asset) use ($type) { return strcasecmp((string) $asset['asset_category'], $type) === 0; }));
        if ($date_sort === 'oldest' || $date_sort === 'newest') usort($results, static function ($a, $b) use ($date_sort) { $left = strtotime((string) $a['start_date']) ?: 0; $right = strtotime((string) $b['start_date']) ?: 0; return $date_sort === 'oldest' ? $left <=> $right : $right <=> $left; });
        return $results;
    }

    public static function import_social_asset(int $asset_id, string $filename, int $post_id, int $draft_id): int {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'm4v'];
        if ($asset_id <= 0 || $draft_id <= 0 || !in_array($extension, $allowed, true) || !self::has_credentials()) return 0;
        $draft = Store::find($draft_id);
        if (!$draft || (string) $draft['status'] !== 'draft' || !empty($draft['facebook_post_id']) || !empty($draft['instagram_media_id']) || !empty($draft['instagram_container_id'])) return 0;
        $tmp = wp_tempnam($filename);
        if (!$tmp) return 0;
        $response = wp_remote_get(self::download_url($asset_id), [
            'timeout' => 300,
            'redirection' => 3,
            'cookies' => self::login_cookies(),
            'stream' => true,
            'filename' => $tmp,
            'limit_response_size' => self::MAX_VIDEO_BYTES,
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) { @unlink($tmp); return 0; }
        $size = filesize($tmp);
        if ($size === false || $size <= 0 || $size >= self::MAX_VIDEO_BYTES) { @unlink($tmp); return 0; }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_sideload(['name' => sanitize_file_name($filename), 'tmp_name' => $tmp], $post_id);
        if (is_wp_error($attachment_id)) { @unlink($tmp); return 0; }
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) { @unlink($tmp); return 0; }
        $is_video = in_array($extension, ['mp4', 'mov', 'm4v'], true);
        if (!self::tag_imported_attachment($attachment_id, $asset_id, $is_video)
            || !Store::update_imported_media($draft_id, $attachment_id, $is_video ? 'video' : 'image', self::cleanup_time($draft), $asset_id, $filename, $draft)) {
            // This new upload was never assigned. Do not touch the previously
            // selected media or any approved publication on a stale response.
            wp_delete_attachment($attachment_id, true);
            return 0;
        }
        if ($is_video) self::save_video_thumbnail($attachment_id, $asset_id, $filename);
        return $attachment_id;
    }

    /** Mark an imported attachment before a draft starts referencing it. */
    private static function tag_imported_attachment(int $attachment_id, int $asset_id, bool $temporary): bool {
        if ($attachment_id <= 0 || $asset_id <= 0) return false;
        $temporary_value = $temporary ? '1' : '0';
        update_post_meta($attachment_id, '_roxy_social_temporary', $temporary_value);
        update_post_meta($attachment_id, '_roxy_hangar_asset_id', $asset_id);
        return (string) get_post_meta($attachment_id, '_roxy_social_temporary', true) === $temporary_value
            && (int) get_post_meta($attachment_id, '_roxy_hangar_asset_id', true) === $asset_id;
    }

    private static function save_video_thumbnail(int $attachment_id, int $asset_id, string $filename): void {
        $thumbnail = (string) get_transient('roxy_social_hangar_thumb_' . $asset_id);
        if ($thumbnail === '') return;
        $image = self::fetch_thumbnail($thumbnail, 5 * 1024 * 1024);
        if (!$image) return;
        $uploads = wp_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['path']) || empty($uploads['basedir']) || empty($uploads['url']) || !wp_mkdir_p($uploads['path'])) return;
        $base_name = sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME) . '-poster.' . $image['extension']);
        $poster_name = wp_unique_filename($uploads['path'], $base_name);
        $poster_path = trailingslashit($uploads['path']) . $poster_name;

        // wp_unique_filename() is a check-then-use operation. Open exclusively
        // so a concurrent upload or pre-existing file can never be overwritten.
        $handle = @fopen($poster_path, 'x+b');
        if (!is_resource($handle)) return;
        $written = 0;
        $length = strlen($image['body']);
        while ($written < $length) {
            $count = @fwrite($handle, substr($image['body'], $written));
            if ($count === false || $count === 0) break;
            $written += $count;
        }
        $flushed = $written === $length && @fflush($handle);
        $closed = @fclose($handle);
        if (!$flushed || !$closed) {
            @unlink($poster_path);
            return;
        }

        $poster_url = trailingslashit($uploads['url']) . $poster_name;
        $old_url = get_post_meta($attachment_id, '_roxy_social_video_poster_url', true);
        $old_file = get_post_meta($attachment_id, '_roxy_social_video_poster_file', true);
        update_post_meta($attachment_id, '_roxy_social_video_poster_url', $poster_url);
        update_post_meta($attachment_id, '_roxy_social_video_poster_file', $poster_path);
        if ((string) get_post_meta($attachment_id, '_roxy_social_video_poster_url', true) !== $poster_url
            || (string) get_post_meta($attachment_id, '_roxy_social_video_poster_file', true) !== $poster_path) {
            // Restore the prior pair; do not leave metadata pointing at a
            // partial replacement when the database write fails.
            if ($old_url === '' || $old_url === null) delete_post_meta($attachment_id, '_roxy_social_video_poster_url');
            else update_post_meta($attachment_id, '_roxy_social_video_poster_url', $old_url);
            if ($old_file === '' || $old_file === null) delete_post_meta($attachment_id, '_roxy_social_video_poster_file');
            else update_post_meta($attachment_id, '_roxy_social_video_poster_file', $old_file);
            @unlink($poster_path);
        }
    }

    public static function delete_video_thumbnail(int $attachment_id): void {
        $file = (string) get_post_meta($attachment_id, '_roxy_social_video_poster_file', true);
        $uploads = wp_upload_dir();
        $upload_root = !empty($uploads['basedir']) ? realpath($uploads['basedir']) : false;
        $poster_realpath = $file !== '' ? realpath($file) : false;
        $upload_prefix = $upload_root !== false ? rtrim($upload_root, '/\\') . DIRECTORY_SEPARATOR : '';
        if ($upload_root !== false && $poster_realpath !== false && is_file($poster_realpath)
            && strpos($poster_realpath, $upload_prefix) === 0
            && preg_match('/-poster(?:-[0-9]+)?\.[A-Za-z0-9]+$/', basename($poster_realpath))) {
            @unlink($poster_realpath);
        }
        delete_post_meta($attachment_id, '_roxy_social_video_poster_url');
        delete_post_meta($attachment_id, '_roxy_social_video_poster_file');
    }

    private static function cleanup_time(array $draft): ?string {
        global $wpdb;
        $latest = $wpdb->get_var($wpdb->prepare('SELECT MAX(scheduled_for) FROM ' . Store::table_name() . ' WHERE campaign_key = %s', (string) $draft['campaign_key']));
        if (!$latest) return null;
        $date = date_create($latest, wp_timezone());
        return $date ? $date->modify('+72 hours')->format('Y-m-d H:i:s') : null;
    }

    public static function thumbnail_response(int $asset_id): void {
        $path = (string) get_transient('roxy_social_hangar_thumb_' . $asset_id);
        $url = self::validated_thumbnail_url($path);
        if ($url === null || !self::has_credentials()) {
            status_header(404);
            exit;
        }
        $image = self::fetch_thumbnail($url, 2 * 1024 * 1024);
        if (!$image) {
            status_header(404);
            exit;
        }
        header('Content-Type: ' . $image['mime']);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        echo $image['body'];
        exit;
    }

    /** Return a canonical Hangar URL; never accept a caller-controlled host. */
    private static function validated_thumbnail_url(string $path): ?string {
        if ($path === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $path) || strpos($path, '//') === 0) return null;
        if (preg_match('#^https://#i', $path)) {
            $parts = parse_url($path);
            if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) parse_url(self::BASE_URL, PHP_URL_HOST))
                || isset($parts['user']) || isset($parts['pass'])
                || (isset($parts['port']) && (int) $parts['port'] !== 443)) return null;
            $relative = (string) ($parts['path'] ?? '/');
            if (isset($parts['query'])) $relative .= '?' . $parts['query'];
        } else {
            $relative = $path;
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relative)) return null;
        }
        $path_only = explode('?', $relative, 2)[0];
        $decoded = $path_only;
        for ($i = 0; $i < 8; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) break;
            $decoded = $next;
            if ($i === 7) return null;
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) return null;
        foreach (explode('/', $decoded) as $segment) if ($segment === '..' || $segment === '.') return null;
        return rtrim(self::BASE_URL, '/') . '/' . ltrim($relative, '/');
    }

    /** Fetch and verify raster bytes before either proxying or saving them. */
    private static function fetch_thumbnail(string $url, int $max_bytes): ?array {
        $url = self::validated_thumbnail_url($url);
        if ($url === null) return null;
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'redirection' => 0,
            'cookies' => self::login_cookies(),
            'limit_response_size' => $max_bytes,
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) return null;
        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '' || strlen($body) > $max_bytes || !function_exists('getimagesizefromstring')) return null;
        $info = @getimagesizefromstring($body);
        if (!is_array($info)) return null;
        $formats = [
            IMAGETYPE_JPEG => ['mime' => 'image/jpeg', 'extension' => 'jpg'],
            IMAGETYPE_PNG => ['mime' => 'image/png', 'extension' => 'png'],
            IMAGETYPE_GIF => ['mime' => 'image/gif', 'extension' => 'gif'],
            IMAGETYPE_WEBP => ['mime' => 'image/webp', 'extension' => 'webp'],
        ];
        $format = $formats[$info[2] ?? 0] ?? null;
        $header_type = strtolower(trim(explode(';', (string) wp_remote_retrieve_header($response, 'content-type'))[0]));
        if (!$format || $header_type !== $format['mime'] || strtolower((string) ($info['mime'] ?? '')) !== $format['mime']) return null;
        return ['body' => $body, 'mime' => $format['mime'], 'extension' => $format['extension']];
    }

    public static function import_featured_image(int $asset_id, string $filename, int $post_id): int {
        if ($asset_id <= 0 || $post_id <= 0 || !self::has_credentials()) return 0;
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) return 0;
        $response = wp_remote_get(self::download_url($asset_id), [
            'timeout' => 60,
            'redirection' => 3,
            'cookies' => self::login_cookies(),
            'limit_response_size' => 25 * 1024 * 1024,
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) return 0;
        $body = wp_remote_retrieve_body($response);
        if ($body === '' || strlen($body) > 25 * 1024 * 1024) return 0;
        $tmp = wp_tempnam($filename);
        if (!$tmp || !self::write_download_temp_file($tmp, $body)) return 0;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_sideload(['name' => sanitize_file_name($filename), 'tmp_name' => $tmp], $post_id);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return 0;
        }
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || !self::assign_featured_image($post_id, $attachment_id, $asset_id)) {
            // The new file was not successfully attached to this post. Keep
            // the existing featured image and remove only this request's file.
            if ($attachment_id > 0 && (int) get_post_thumbnail_id($post_id) !== $attachment_id) wp_delete_attachment($attachment_id, true);
            return 0;
        }
        return (int) $attachment_id;
    }

    /** Persist and read back the asset identity and featured-image assignment. */
    private static function assign_featured_image(int $post_id, int $attachment_id, int $asset_id): bool {
        if ($post_id <= 0 || $attachment_id <= 0 || $asset_id <= 0) return false;
        $previous_id = (int) get_post_thumbnail_id($post_id);
        $saved = update_post_meta($attachment_id, '_roxy_hangar_asset_id', $asset_id);
        if ($saved === false && (int) get_post_meta($attachment_id, '_roxy_hangar_asset_id', true) !== $asset_id) return false;

        try { set_post_thumbnail($post_id, $attachment_id); } catch (\Throwable $error) { return false; }
        $current_id = (int) get_post_thumbnail_id($post_id);
        if ($current_id === $attachment_id) return true;

        // A failed setter should leave the old image alone. If a hook changed
        // it anyway, make a best-effort restoration before reporting failure.
        if ($current_id !== $previous_id) {
            if ($previous_id > 0) set_post_thumbnail($post_id, $previous_id);
            elseif (function_exists('delete_post_thumbnail')) delete_post_thumbnail($post_id);
        }
        return false;
    }

    private static function write_download_temp_file(string $path, string $body): bool {
        if ($path === '' || !is_file($path) || $body === '') {
            if ($path !== '' && is_file($path)) @unlink($path);
            return false;
        }
        $written = @file_put_contents($path, $body);
        if ($written !== strlen($body)) {
            @unlink($path);
            return false;
        }
        return true;
    }

    public static function download_url(int $asset_id): string {
        return add_query_arg('assetId', $asset_id, self::BASE_URL . 'download.php');
    }

    private static function login_cookies(): array {
        static $request_cache = [];
        $user = (string) get_option(self::USER_OPTION, '');
        $password=self::decrypt((string)get_option(self::PASS_OPTION,''));
        if ($user === '' || $password==='') return [];
        $cache_key = hash('sha256', $user . "\0" . $password);
        if (array_key_exists($cache_key, $request_cache)) return $request_cache[$cache_key];
        $login = wp_remote_post(self::BASE_URL . 'login.php', [
            'timeout' => 20,
            'redirection' => 0,
            'body' => [
                'user' => $user,
                'pass' => $password,
            ],
        ]);
        $cookies = !is_wp_error($login) && in_array((int) wp_remote_retrieve_response_code($login), [200, 302], true)
            ? wp_remote_retrieve_cookies($login)
            : [];
        if (!is_array($cookies)) $cookies = [];
        $request_cache[$cache_key] = $cookies;
        return $cookies;
    }

    private static function encrypt(string $value): string {
        return Secrets::encrypt($value);
    }

    private static function decrypt(string $value): string {
        return Secrets::decrypt($value);
    }
}
