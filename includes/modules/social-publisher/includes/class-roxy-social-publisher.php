<?php
namespace RoxySocial;

if (!defined('ABSPATH')) exit;

final class Publisher {
    private static ?array $claim = null;

    private static function with_lock(int $id, callable $work): bool {
        $claim = Store::acquire_publish_lock($id);
        if (!$claim) return false;
        self::$claim = $claim;
        try { return (bool) $work(); }
        finally { self::$claim = null; Store::release_publish_lock($claim); }
    }

    private static function owns_lock(): bool {
        return self::$claim !== null && Store::owns_publish_lock(self::$claim);
    }

    private static function save_result(int $id, string $status, string $error = '', string $facebook_id = '', string $instagram_id = ''): bool {
        return self::owns_lock() && Store::update_publish_result($id, $status, $error, $facebook_id, $instagram_id, self::$claim);
    }

    public static function publish_due(): void {
        global $wpdb;
        $stale_before = wp_date('Y-m-d H:i:s', current_time('timestamp', true) - (15 * MINUTE_IN_SECONDS), wp_timezone());
        $stale_ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Store::table_name() . ' WHERE status = %s AND updated_at <= %s', 'publishing', $stale_before)) ?: [];
        foreach ($stale_ids as $stale_id) {
            self::with_lock((int) $stale_id, static function () use ($stale_id, $stale_before) {
                $row = Store::find((int) $stale_id);
                if (!$row || $row['status'] !== 'publishing' || $row['updated_at'] > $stale_before) return false;
                return self::save_result((int) $stale_id, 'needs_review', 'The publishing worker was interrupted. Review remote accounts before approving another attempt; recorded platform IDs have been retained.');
            });
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Store::table_name() . ' WHERE ((status = %s) OR (status = %s AND instagram_media_id IS NULL AND (last_error LIKE %s OR last_error LIKE %s))) AND scheduled_for <= %s ORDER BY scheduled_for ASC, id ASC LIMIT 3', 'approved', 'failed', '%Instagram video is still processing%', '%Media ID is not available%', current_time('mysql')), ARRAY_A) ?: [];
        foreach ($rows as $row) self::queue_publish((int) $row['id']);
    }

    public static function queue_publish_now(int $id): bool {
        $row = Store::find($id);
        if (!$row || !in_array((string) $row['status'], ['approved', 'failed'], true)) return false;
        if ((string) $row['status'] === 'failed' && !empty($row['facebook_post_id']) && !empty($row['instagram_media_id'])) return false;
        return self::queue_publish($id);
    }

    public static function process_queued(int $id): void {
        self::with_lock($id, static function () use ($id) {
            $row = Store::find($id);
            if (!$row || (string) $row['status'] !== 'publishing') return false;
            try { return self::publish_row($row); }
            catch (\Throwable $error) {
                if (self::owns_lock()) self::save_result($id, 'needs_review', 'The publishing worker was interrupted. Review remote accounts before retrying.');
                error_log('Roxy Social publishing worker interrupted for draft ' . $id . '.');
                return false;
            }
        });
    }

    private static function queue_publish(int $id): bool {
        return self::with_lock($id, static function () use ($id) {
            $row = Store::find($id);
            if (!$row || !in_array((string) $row['status'], ['approved', 'failed'], true)) return false;
            if (!Store::compare_publish_status($id, (string) $row['status'], 'publishing', self::$claim)) return false;
            if (wp_next_scheduled('roxy_social_publish_single', [$id]) || wp_schedule_single_event(time() + 1, 'roxy_social_publish_single', [$id])) return true;
            Store::compare_publish_status($id, 'publishing', (string) $row['status'], self::$claim);
            return false;
        });
    }

    public static function publish_now(int $id): bool {
        return self::with_lock($id, static function () use ($id) {
            $row = Store::find($id);
            if (!$row || !in_array((string) $row['status'], ['approved', 'failed'], true)) return false;
            if ((string) $row['status'] === 'failed' && !empty($row['facebook_post_id']) && !empty($row['instagram_media_id'])) return false;
            if (!Store::compare_publish_status($id, (string) $row['status'], 'publishing', self::$claim)) return false;
            return self::publish_row($row);
        });
    }

    public static function remove_published(int $id): bool {
        return self::with_lock($id, static function () use ($id) { return self::remove_row($id); });
    }

    private static function remove_row(int $id): bool {
        $row = Store::find($id);
        if (!$row || !in_array((string) $row['status'], ['posted', 'failed', 'needs_review'], true)) return false;
        if (empty($row['facebook_post_id']) && empty($row['instagram_media_id'])) {self::save_result($id,(string)$row['status'],'No published IDs are recorded; verify the remote accounts manually before marking removed.');return false;}
        $facebook = empty($row['facebook_post_id']) ? [] : self::delete_remote((string) $row['facebook_post_id'], Meta::page_access_token());
        if (!empty($facebook['success']) && !Store::clear_publish_id($id, 'facebook', self::$claim)) $facebook=['error'=>'Remote removal succeeded but its local record could not be saved. Review before retrying.'];
        $instagram = empty($row['instagram_media_id']) ? [] : self::delete_remote((string) $row['instagram_media_id'], Meta::access_token());
        if (!empty($instagram['success']) && !Store::clear_publish_id($id, 'instagram', self::$claim)) $instagram=['error'=>'Remote removal succeeded but its local record could not be saved. Review before retrying.'];
        $errors = array_filter([
            !empty($facebook['error']) ? 'Facebook: ' . $facebook['error'] : '',
            !empty($instagram['error']) ? 'Instagram: ' . $instagram['error'] : '',
        ]);
        if ($errors) { self::save_result($id, (string) $row['status'], implode(' ', $errors)); return false; }
        return self::save_result($id, 'removed', '');
    }

    private static function publish_row(array $row): bool {
        $id = (int) ($row['id'] ?? 0);
        $media_url = esc_url_raw((string) ($row['media_url'] ?? ''));
        $caption = trim((string) ($row['post_text'] ?? ''));
        $platform = (string) ($row['platform'] ?? 'both');
        if (!in_array($platform, ['facebook', 'instagram', 'both'], true)) return false;
        if (($platform !== 'instagram' && empty($row['facebook_post_id']) && (Meta::page_id() === '' || Meta::page_access_token() === ''))
            || ($platform !== 'facebook' && empty($row['instagram_media_id']) && (Meta::instagram_user_id() === '' || Meta::access_token() === ''))) {
            self::save_result($id, 'failed', 'Connect the selected platform before publishing.');
            return false;
        }
        if ($id <= 0 || $caption === '' || ($media_url === '' && $platform !== 'facebook')) { self::save_result($id, 'failed', 'The draft is missing public media or post text.'); return false; }
        if (!self::save_result($id, 'publishing')) return false;
        $facebook = ($platform === 'instagram' || !empty($row['facebook_post_id'])) ? [] : self::publish_facebook($media_url, $caption, (string) ($row['media_type'] ?? 'image'));
        if (!empty($facebook['id'])) {
            if (!self::save_result($id,'publishing','',(string)$facebook['id'])) {self::save_result($id,'needs_review','Facebook accepted the post but its ID could not be saved. Review remote posts before any retry.');return false;}
            $row['facebook_post_id']=(string)$facebook['id'];
        }
        if (!empty($facebook['ambiguous'])) {self::save_result($id,'needs_review','Facebook: '.(string)$facebook['error']);return false;}
        $instagram = ($platform === 'facebook' || !empty($row['instagram_media_id'])) ? [] : self::publish_instagram($media_url, $caption, (string) ($row['media_type'] ?? 'image'), $id, (string) ($row['instagram_container_id'] ?? ''));
        if (!empty($instagram['id'])) {
            if (!self::save_result($id,'publishing','',(string)($facebook['id']??''),(string)$instagram['id'])) {self::save_result($id,'needs_review','Instagram accepted the post but its ID could not be saved. Review remote posts before retrying.');return false;}
            $row['instagram_media_id']=(string)$instagram['id'];
        }
        $errors = array_filter([
            !empty($facebook['error']) ? 'Facebook: ' . $facebook['error'] : '',
            !empty($instagram['error']) ? 'Instagram: ' . $instagram['error'] : '',
        ]);
        if ($errors) {
            self::save_result($id, !empty($instagram['ambiguous']) ? 'needs_review' : 'failed', implode(' ', $errors), (string) ($facebook['id'] ?? ''), (string) ($instagram['id'] ?? ''));
            if (!empty($instagram['error']) && stripos((string) $instagram['error'], 'still processing') !== false) wp_schedule_single_event(time() + 300, 'roxy_social_publish_single', [$id]);
            return false;
        }
        if (($platform!=='instagram' && empty($row['facebook_post_id']) && empty($facebook['id'])) || ($platform!=='facebook' && empty($row['instagram_media_id']) && empty($instagram['id']))) {self::save_result($id,'needs_review','A platform returned no published ID. Review remote posts before retrying.');return false;}
        $saved=self::save_result($id, 'posted', '', (string) ($facebook['id'] ?? ''), (string) ($instagram['id'] ?? ''));
        if (!$saved) return false;
        Store::clear_instagram_container_id($id, self::$claim);
        return true;
    }

    private static function publish_facebook(string $url, string $caption, string $type): array {
        $endpoint = 'https://graph.facebook.com/' . rawurlencode(Meta::page_id()) . ($url === '' ? '/feed' : ($type === 'video' ? '/videos' : '/photos'));
        $body = ['access_token' => Meta::page_access_token()];
        $body[$type === 'video' ? 'description' : 'message'] = $caption;
        if ($url !== '') $body[$type === 'video' ? 'file_url' : 'url'] = $url;
        return self::request($endpoint, $body);
    }

    private static function publish_instagram(string $url, string $caption, string $type, int $post_id, string $existing_container_id = ''): array {
        $endpoint = 'https://graph.facebook.com/' . rawurlencode(Meta::instagram_user_id()) . '/media';
        $body = ['access_token' => Meta::access_token(), 'caption' => $caption];
        if ($type === 'video') { $body['media_type'] = 'REELS'; $body['video_url'] = $url; }
        else { $body['image_url'] = $url; }
        $container = $existing_container_id !== '' ? ['id' => $existing_container_id] : self::request($endpoint, $body);
        if (!empty($container['error']) || empty($container['id'])) return $container;
        if (!Store::set_instagram_container_id($post_id, (string) $container['id'], self::$claim)) return ['error'=>'Instagram container ID could not be saved. Review before retrying.','ambiguous'=>true];
        if ($type === 'video') {
            $ready = false;
            for ($attempt = 0; $attempt < 30; $attempt++) {
                sleep(4);
                $status = self::get('https://graph.facebook.com/' . rawurlencode((string) $container['id']), ['fields' => 'status_code', 'access_token' => Meta::access_token()]);
                if (($status['status_code'] ?? '') === 'FINISHED') { $ready = true; break; }
                if (($status['status_code'] ?? '') === 'ERROR') return ['error' => 'Instagram video processing failed.'];
            }
            if (!$ready) return ['error' => 'Instagram video is still processing; the scheduler will retry automatically.'];
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
        if (!self::owns_lock()) return ['error' => 'Worker ownership was lost. Review the remote account before retrying.'];
        if ($object_id === '') return ['error' => 'The published media ID is missing.'];
        if ($token === '') return ['error' => 'Connect the selected platform before removing the published post.'];
        $response = wp_remote_request('https://graph.facebook.com/' . rawurlencode($object_id), ['method' => 'DELETE', 'timeout' => 45, 'body' => ['access_token' => $token]]);
        return self::decode_response($response,false,true);
    }

    private static function request(string $url, array $body): array {
        if (!self::owns_lock()) return ['error' => 'Worker ownership was lost. Review the remote account before retrying.', 'ambiguous' => true];
        $response = wp_remote_post($url, ['timeout' => 45, 'body' => $body]);
        return self::decode_response($response,true,false);
    }

    private static function get(string $url, array $query): array {
        $response = wp_remote_get(add_query_arg($query, $url), ['timeout' => 45]);
        return self::decode_response($response,false,false);
    }

    private static function decode_response($response,bool $require_id,bool $require_success): array {
        if (is_wp_error($response)) return ['error'=>'Meta request outcome is unknown. Review the remote account before retrying.','ambiguous'=>$require_id];
        $status=(int)wp_remote_retrieve_response_code($response);
        $data=json_decode((string)wp_remote_retrieve_body($response),true);
        if($status<200||$status>=300) return ['error'=>'Meta returned HTTP '.$status.': '.sanitize_text_field((string)($data['error']['message']??'No success was recorded.')),'ambiguous'=>$require_id&&($status>=500||$status===0)];
        if(!is_array($data))return ['error'=>'Meta returned an unreadable response. Review the remote account before retrying.','ambiguous'=>$require_id];
        if(isset($data['error']))return ['error'=>sanitize_text_field((string)($data['error']['message']??'Meta reported an error.'))];
        if($require_success&&($data['success']??null)!==true)return ['error'=>'Meta did not confirm removal; the recorded ID was retained.'];
        if($require_id&&(!isset($data['id'])||!is_scalar($data['id'])||!preg_match('/^\d+(?:_\d+)?$/',(string)$data['id'])))return ['error'=>'Meta did not return a valid published ID. Review the remote account before retrying.','ambiguous'=>true];
        return $data;
    }
}
