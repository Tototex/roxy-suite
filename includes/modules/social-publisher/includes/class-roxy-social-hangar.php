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
        if (!Store::update_imported_media($draft_id, (int) $attachment_id, in_array($extension, ['mp4', 'mov', 'm4v'], true) ? 'video' : 'image', self::cleanup_time($draft), $asset_id, $filename, $draft)) {
            // This new upload was never assigned. Do not touch the previously
            // selected media or any approved publication on a stale response.
            wp_delete_attachment((int) $attachment_id, true);
            return 0;
        }
        update_post_meta((int) $attachment_id, '_roxy_social_temporary', in_array($extension, ['mp4', 'mov', 'm4v'], true) ? '1' : '0');
        update_post_meta((int) $attachment_id, '_roxy_hangar_asset_id', $asset_id);
        if (in_array($extension, ['mp4', 'mov', 'm4v'], true)) self::save_video_thumbnail((int) $attachment_id, $asset_id, $filename);
        return (int) $attachment_id;
    }

    private static function save_video_thumbnail(int $attachment_id, int $asset_id, string $filename): void {
        $thumbnail = (string) get_transient('roxy_social_hangar_thumb_' . $asset_id);
        if ($thumbnail === '') return;
        $image = self::fetch_thumbnail($thumbnail, 5 * 1024 * 1024);
        if (!$image) return;
        $uploads = wp_upload_dir();
        if (!empty($uploads['error']) || !wp_mkdir_p($uploads['path'])) return;
        $poster_name = wp_unique_filename($uploads['path'], sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME) . '-poster.' . $image['extension']));
        if (false === file_put_contents(trailingslashit($uploads['path']) . $poster_name, $image['body'])) return;
        update_post_meta($attachment_id, '_roxy_social_video_poster_url', trailingslashit($uploads['url']) . $poster_name);
        update_post_meta($attachment_id, '_roxy_social_video_poster_file', trailingslashit($uploads['path']) . $poster_name);
    }

    public static function delete_video_thumbnail(int $attachment_id): void {
        $file = (string) get_post_meta($attachment_id, '_roxy_social_video_poster_file', true);
        if ($file !== '' && is_file($file)) @unlink($file);
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
        if (!$tmp || false === file_put_contents($tmp, $body)) return 0;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_sideload(['name' => sanitize_file_name($filename), 'tmp_name' => $tmp], $post_id);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return 0;
        }
        set_post_thumbnail($post_id, (int) $attachment_id);
        update_post_meta((int) $attachment_id, '_roxy_hangar_asset_id', $asset_id);
        return (int) $attachment_id;
    }

    public static function download_url(int $asset_id): string {
        return add_query_arg('assetId', $asset_id, self::BASE_URL . 'download.php');
    }

    private static function login_cookies(): array {
        $password=self::decrypt((string)get_option(self::PASS_OPTION,''));
        if ($password==='') return [];
        $login = wp_remote_post(self::BASE_URL . 'login.php', [
            'timeout' => 20,
            'redirection' => 0,
            'body' => [
                'user' => (string) get_option(self::USER_OPTION, ''),
                'pass' => $password,
            ],
        ]);
        if (is_wp_error($login) || !in_array((int) wp_remote_retrieve_response_code($login), [200, 302], true)) return [];
        return wp_remote_retrieve_cookies($login);
    }

    private static function encrypt(string $value): string {
        return Secrets::encrypt($value);
    }

    private static function decrypt(string $value): string {
        return Secrets::decrypt($value);
    }
}
