<?php
namespace RoxySocial;

if (!defined('ABSPATH')) exit;

final class Hangar {
    private const BASE_URL = 'https://hangar.paperairmedia.com/';
    private const USER_OPTION = 'roxy_social_hangar_user';
    private const PASS_OPTION = 'roxy_social_hangar_pass';

    public static function save_credentials(string $user, string $password): void {
        update_option(self::USER_OPTION, sanitize_text_field($user), false);
        update_option(self::PASS_OPTION, self::encrypt($password), false);
    }

    public static function has_credentials(): bool {
        return (string) get_option(self::USER_OPTION, '') !== '' && (string) get_option(self::PASS_OPTION, '') !== '';
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
                'thumbnail_url' => esc_url_raw((string) ($asset['thumbFilePath'] ?? '')),
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
        $tmp = wp_tempnam($filename);
        if (!$tmp) return 0;
        $response = wp_remote_get(self::download_url($asset_id), [
            'timeout' => 300,
            'redirection' => 3,
            'cookies' => self::login_cookies(),
            'stream' => true,
            'filename' => $tmp,
            'limit_response_size' => 200 * 1024 * 1024,
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) { @unlink($tmp); return 0; }
        $size = filesize($tmp);
        if ($size === false || $size <= 0 || $size > 200 * 1024 * 1024) { @unlink($tmp); return 0; }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_sideload(['name' => sanitize_file_name($filename), 'tmp_name' => $tmp], $post_id);
        if (is_wp_error($attachment_id)) { @unlink($tmp); return 0; }
        $draft = Store::find($draft_id);
        if (!$draft || !Store::update_imported_media($draft_id, (int) $attachment_id, in_array($extension, ['mp4', 'mov', 'm4v'], true) ? 'video' : 'image', self::cleanup_time($draft), $asset_id, $filename)) return 0;
        update_post_meta((int) $attachment_id, '_roxy_social_temporary', in_array($extension, ['mp4', 'mov', 'm4v'], true) ? '1' : '0');
        update_post_meta((int) $attachment_id, '_roxy_hangar_asset_id', $asset_id);
        if (in_array($extension, ['mp4', 'mov', 'm4v'], true)) self::save_video_thumbnail((int) $attachment_id, $asset_id, $filename);
        return (int) $attachment_id;
    }

    private static function save_video_thumbnail(int $attachment_id, int $asset_id, string $filename): void {
        $thumbnail = (string) get_transient('roxy_social_hangar_thumb_' . $asset_id);
        if ($thumbnail === '') return;
        if (strpos($thumbnail, 'http') !== 0) $thumbnail = self::BASE_URL . ltrim($thumbnail, '/');
        $response = wp_remote_get($thumbnail, ['timeout' => 30, 'redirection' => 3, 'cookies' => self::login_cookies(), 'limit_response_size' => 5 * 1024 * 1024]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) return;
        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        if (strpos($content_type, 'image/') !== 0) return;
        $body = wp_remote_retrieve_body($response);
        if ($body === '') return;
        $uploads = wp_upload_dir();
        if (!empty($uploads['error']) || !wp_mkdir_p($uploads['path'])) return;
        $poster_name = wp_unique_filename($uploads['path'], sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME) . '-poster.jpg'));
        if (false === file_put_contents(trailingslashit($uploads['path']) . $poster_name, $body)) return;
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
        if ($path === '' || !self::has_credentials()) {
            status_header(404);
            exit;
        }
        if (strpos($path, 'http') !== 0) $path = self::BASE_URL . ltrim($path, '/');
        $response = wp_remote_get($path, ['timeout' => 20, 'redirection' => 3, 'cookies' => self::login_cookies(), 'limit_response_size' => 2 * 1024 * 1024]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
            status_header(404);
            exit;
        }
        $type = wp_remote_retrieve_header($response, 'content-type');
        if (is_string($type) && strpos($type, 'image/') === 0) header('Content-Type: ' . $type);
        header('Cache-Control: private, max-age=3600');
        echo wp_remote_retrieve_body($response);
        exit;
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
        $login = wp_remote_post(self::BASE_URL . 'login.php', [
            'timeout' => 20,
            'redirection' => 3,
            'body' => [
                'user' => (string) get_option(self::USER_OPTION, ''),
                'pass' => self::decrypt((string) get_option(self::PASS_OPTION, '')),
            ],
        ]);
        if (is_wp_error($login) || (int) wp_remote_retrieve_response_code($login) >= 400) return [];
        return wp_remote_retrieve_cookies($login);
    }

    private static function encrypt(string $value): string {
        if ($value === '' || !function_exists('openssl_encrypt')) return '';
        $key = hash('sha256', wp_salt('auth'), true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    private static function decrypt(string $value): string {
        if ($value === '' || !function_exists('openssl_decrypt')) return '';
        $raw = base64_decode($value, true);
        if (!is_string($raw) || strlen($raw) <= 16) return '';
        $key = hash('sha256', wp_salt('auth'), true);
        return (string) openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    }
}
