<?php
namespace RoxySocial;

if (!defined('ABSPATH')) exit;

final class Publisher {
    public static function publish_due(): void {
        if (!Meta::configured() || Meta::page_access_token() === '' || Meta::instagram_user_id() === '') return;
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Store::table_name() . ' WHERE status = %s AND scheduled_for <= %s ORDER BY scheduled_for ASC, id ASC LIMIT 3', 'approved', current_time('mysql')), ARRAY_A) ?: [];
        foreach ($rows as $row) self::publish_row($row);
    }

    public static function publish_now(int $id): bool {
        if (!Meta::configured() || Meta::page_access_token() === '' || Meta::instagram_user_id() === '') return false;
        $row = Store::find($id);
        if (!$row || !in_array((string) $row['status'], ['approved', 'failed'], true)) return false;
        if ((string) $row['status'] === 'failed' && !empty($row['facebook_post_id']) && !empty($row['instagram_media_id'])) return false;
        return self::publish_row($row);
    }

    public static function remove_published(int $id): bool {
        $row = Store::find($id);
        if (!$row || (string) $row['status'] !== 'posted') return false;
        $platform = (string) ($row['platform'] ?? 'both');
        $facebook = $platform === 'instagram' ? [] : self::delete_remote((string) ($row['facebook_post_id'] ?? ''), Meta::page_access_token());
        $instagram = $platform === 'facebook' ? [] : self::delete_remote((string) ($row['instagram_media_id'] ?? ''), Meta::page_access_token());
        if (empty($facebook['error']) && $platform !== 'instagram') Store::clear_publish_id($id, 'facebook');
        if (empty($instagram['error']) && $platform !== 'facebook') Store::clear_publish_id($id, 'instagram');
        $errors = array_filter([
            !empty($facebook['error']) ? 'Facebook: ' . $facebook['error'] : '',
            !empty($instagram['error']) ? 'Instagram: ' . $instagram['error'] : '',
        ]);
        if ($errors) { Store::update_publish_result($id, 'posted', implode(' ', $errors)); return false; }
        Store::update_publish_result($id, 'removed', '');
        return true;
    }

    private static function publish_row(array $row): bool {
        $id = (int) ($row['id'] ?? 0);
        $media_url = esc_url_raw((string) ($row['media_url'] ?? ''));
        $caption = trim((string) ($row['post_text'] ?? ''));
        $platform = (string) ($row['platform'] ?? 'both');
        if ($id <= 0 || $caption === '' || ($media_url === '' && $platform !== 'facebook')) { Store::update_publish_result($id, 'failed', 'The draft is missing public media or post text.'); return false; }
        Store::update_publish_result($id, 'publishing');
        $facebook = ($platform === 'instagram' || !empty($row['facebook_post_id'])) ? [] : self::publish_facebook($media_url, $caption, (string) ($row['media_type'] ?? 'image'));
        $instagram = ($platform === 'facebook' || !empty($row['instagram_media_id'])) ? [] : self::publish_instagram($media_url, $caption, (string) ($row['media_type'] ?? 'image'));
        $errors = array_filter([
            !empty($facebook['error']) ? 'Facebook: ' . $facebook['error'] : '',
            !empty($instagram['error']) ? 'Instagram: ' . $instagram['error'] : '',
        ]);
        if ($errors) { Store::update_publish_result($id, 'failed', implode(' ', $errors), (string) ($facebook['id'] ?? ''), (string) ($instagram['id'] ?? '')); return false; }
        Store::update_publish_result($id, 'posted', '', (string) ($facebook['id'] ?? ''), (string) ($instagram['id'] ?? ''));
        return true;
    }

    private static function publish_facebook(string $url, string $caption, string $type): array {
        $endpoint = 'https://graph.facebook.com/' . rawurlencode(Meta::page_id()) . ($url === '' ? '/feed' : ($type === 'video' ? '/videos' : '/photos'));
        $body = ['access_token' => Meta::page_access_token(), 'message' => $caption];
        if ($url !== '') $body[$type === 'video' ? 'file_url' : 'url'] = $url;
        return self::request($endpoint, $body);
    }

    private static function publish_instagram(string $url, string $caption, string $type): array {
        $endpoint = 'https://graph.facebook.com/' . rawurlencode(Meta::instagram_user_id()) . '/media';
        $body = ['access_token' => Meta::access_token(), 'caption' => $caption];
        if ($type === 'video') { $body['media_type'] = 'REELS'; $body['video_url'] = $url; }
        else { $body['image_url'] = $url; }
        $container = self::request($endpoint, $body);
        if (!empty($container['error']) || empty($container['id'])) return $container;
        if ($type === 'video') {
            $ready = false;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                sleep(2);
                $status = self::request('https://graph.facebook.com/' . rawurlencode((string) $container['id']), ['fields' => 'status_code', 'access_token' => Meta::access_token()]);
                if (($status['status_code'] ?? '') === 'FINISHED') { $ready = true; break; }
                if (($status['status_code'] ?? '') === 'ERROR') return ['error' => 'Instagram video processing failed.'];
            }
            if (!$ready) return ['error' => 'Instagram video is still processing; approve it again after the media is ready.'];
        }
        $publish_url = 'https://graph.facebook.com/' . rawurlencode(Meta::instagram_user_id()) . '/media_publish';
        $publish_body = ['creation_id' => $container['id'], 'access_token' => Meta::access_token()];
        $published = [];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($attempt > 0) sleep(3);
            $published = self::request($publish_url, $publish_body);
            if (empty($published['error']) || stripos((string) $published['error'], 'Media ID is not available') === false) break;
        }
        return $published;
    }

    private static function delete_remote(string $object_id, string $token): array {
        if ($object_id === '') return ['error' => 'The published media ID is missing.'];
        $response = wp_remote_request('https://graph.facebook.com/' . rawurlencode($object_id), ['method' => 'DELETE', 'timeout' => 45, 'body' => ['access_token' => $token]]);
        if (is_wp_error($response)) return ['error' => $response->get_error_message()];
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) return ['error' => 'Meta returned an unreadable response.'];
        if (!empty($data['error']['message'])) return ['error' => sanitize_text_field((string) $data['error']['message'])];
        return $data;
    }

    private static function request(string $url, array $body): array {
        $response = wp_remote_post($url, ['timeout' => 45, 'body' => $body]);
        if (is_wp_error($response)) return ['error' => $response->get_error_message()];
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) return ['error' => 'Meta returned an unreadable response.'];
        if (!empty($data['error']['message'])) return ['error' => sanitize_text_field((string) $data['error']['message'])];
        return $data;
    }
}
