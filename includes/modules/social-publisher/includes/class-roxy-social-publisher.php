<?php
namespace RoxySocial;

if (!defined('ABSPATH')) exit;

final class Publisher {
    private const REMOVE_REVIEW_PREFIX = 'REMOVAL_OUTCOME_UNCERTAIN:';
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

    public static function owns_worker_lock(): bool { return self::owns_lock(); }
    public static function worker_claim(): ?array { return self::$claim; }

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
        foreach ($rows as $row) {
            if (self::requires_removal_review($row)) {
                self::with_lock((int) $row['id'], static function () use ($row) {
                    $id = (int) ($row['id'] ?? 0);
                    $fresh = Store::find($id);
                    if (!$fresh || !self::requires_removal_review($fresh)
                        || !in_array((string) ($fresh['status'] ?? ''), ['approved', 'failed'], true)) return false;
                    return self::save_result($id, 'needs_review', (string) $fresh['last_error']);
                });
                continue;
            }
            if ((string) ($row['status'] ?? '') === 'failed'
                && stripos((string) ($row['last_error'] ?? ''), 'Media ID is not available') !== false) {
                self::with_lock((int) $row['id'], static function () use ($row) {
                    $id = (int) ($row['id'] ?? 0);
                    $fresh = Store::find($id);
                    if (!$fresh || (string) ($fresh['status'] ?? '') !== 'failed'
                        || !empty($fresh['instagram_media_id'])
                        || stripos((string) ($fresh['last_error'] ?? ''), 'Media ID is not available') === false) return false;
                    return self::save_result($id, 'needs_review', 'A previous Instagram publish attempt returned “Media ID is not available.” The outcome is not safe to retry automatically. Check the Instagram account, then approve a new attempt only after remote review; recorded platform IDs and the container ID have been retained.');
                });
                continue;
            }
            $attempt = 0;
            if ((string) ($row['status'] ?? '') === 'failed' && stripos((string) ($row['last_error'] ?? ''), 'Instagram video is still processing') !== false) {
                $attempt = 5;
                if (preg_match('/status check ([0-4]) of 5/i', (string) ($row['last_error'] ?? ''), $match)) $attempt = (int) $match[1] + 1;
            }
            self::queue_publish((int) $row['id'], $attempt);
        }
    }

    public static function queue_publish_now(int $id): bool {
        $row = Store::find($id);
        if (!$row || self::requires_removal_review($row) || !in_array((string) $row['status'], ['approved', 'failed'], true)) return false;
        if ((string) $row['status'] === 'failed' && !empty($row['facebook_post_id']) && !empty($row['instagram_media_id'])) return false;
        return self::queue_publish($id);
    }

    public static function process_queued(int $id, int $video_attempt = 0, string $attempt_token = ''): void {
        if (!preg_match('/^[a-f0-9-]{36}$/iD', $attempt_token)) return;
        self::with_lock($id, static function () use ($id, $video_attempt, $attempt_token) {
            $row = Store::find($id);
            if (!$row || (string) $row['status'] !== 'publishing'
                || !hash_equals((string) ($row['publish_attempt_token'] ?? ''), $attempt_token)) return false;
            if (self::requires_removal_review($row)) {
                self::save_result($id, 'needs_review', (string) $row['last_error']);
                return false;
            }
            $worker_token = wp_generate_uuid4();
            if (!Store::claim_publish_execution($id, $attempt_token, $worker_token, self::$claim)) return false;
            $row = Store::find($id);
            if (!$row || (string) ($row['publish_worker_token'] ?? '') !== $worker_token) return false;
            if (self::requires_removal_review($row)) {
                self::save_result($id, 'needs_review', (string) $row['last_error']);
                return false;
            }
            if (stripos((string) ($row['last_error'] ?? ''), 'Media ID is not available') !== false) {
                self::save_result($id, 'needs_review', 'A queued Instagram publish retry had an earlier “Media ID is not available” outcome. The queued retry was stopped. Check the Instagram account, then approve a new attempt only after remote review; recorded platform IDs and the container ID have been retained.');
                return false;
            }
            try { return self::publish_row($row, $video_attempt); }
            catch (\Throwable $error) {
                if (self::owns_lock()) self::save_result($id, 'needs_review', 'The publishing worker was interrupted. Review remote accounts before retrying.');
                error_log('Roxy Social publishing worker interrupted for draft ' . $id . '.');
                return false;
            }
        });
    }

    /** Queue one bounded status poll for a failed video container; never republish other failures. */
    public static function queue_video_status_retry(int $id, int $video_attempt): bool {
        if ($video_attempt < 1 || $video_attempt > 5) return false;
        $row = Store::find($id);
        if (!$row || (string) ($row['status'] ?? '') !== 'failed'
            || empty($row['instagram_container_id']) || !empty($row['instagram_media_id'])
            || stripos((string) ($row['last_error'] ?? ''), 'Instagram video is still processing') === false) return false;
        if (!preg_match('/status check ([0-4]) of 5/i', (string) ($row['last_error'] ?? ''), $match)
            || $video_attempt !== (int) $match[1] + 1) return false;
        return self::queue_publish($id, $video_attempt);
    }

    private static function queue_publish(int $id, int $video_attempt = 0): bool {
        return self::with_lock($id, static function () use ($id, $video_attempt) {
            $row = Store::find($id);
            if (!$row || self::requires_removal_review($row) || !in_array((string) $row['status'], ['approved', 'failed'], true)) return false;
            $attempt_token = wp_generate_uuid4();
            if (!Store::claim_publish_attempt($id, (string) $row['status'], $attempt_token, null, self::$claim)) return false;
            $args = [$id, $video_attempt, $attempt_token];
            if (wp_next_scheduled('roxy_social_publish_single', $args) || wp_schedule_single_event(time() + 1, 'roxy_social_publish_single', $args)) return true;
            Store::restore_publish_attempt($id, $attempt_token, (string) $row['status'], self::$claim);
            return false;
        });
    }

    public static function publish_now(int $id): bool {
        return self::with_lock($id, static function () use ($id) {
            $row = Store::find($id);
            if (!$row || self::requires_removal_review($row) || !in_array((string) $row['status'], ['approved', 'failed'], true)) return false;
            if ((string) $row['status'] === 'failed' && !empty($row['facebook_post_id']) && !empty($row['instagram_media_id'])) return false;
            $attempt_token = wp_generate_uuid4();
            $worker_token = wp_generate_uuid4();
            if (!Store::claim_publish_attempt($id, (string) $row['status'], $attempt_token, $worker_token, self::$claim)) return false;
            $row = Store::find($id);
            if (!$row || (string) ($row['publish_attempt_token'] ?? '') !== $attempt_token
                || (string) ($row['publish_worker_token'] ?? '') !== $worker_token) return false;
            return self::publish_row($row);
        });
    }

    /** Queue a Story-only retry only after every selected feed post already succeeded. */
    public static function queue_facebook_story_retry(int $id): bool {
        return self::with_lock($id, static function () use ($id) {
            $row = Store::find($id);
            if (!self::story_retry_is_safe($row)) return false;
            $attempt_token = wp_generate_uuid4();
            if (!Store::claim_publish_attempt($id, 'failed', $attempt_token, null, self::$claim)) return false;
            $args = [$id, $attempt_token];
            if (wp_next_scheduled('roxy_social_facebook_story_retry', $args)
                || wp_schedule_single_event(time() + 1, 'roxy_social_facebook_story_retry', $args)) return true;
            Store::restore_publish_attempt($id, $attempt_token, 'failed', self::$claim);
            return false;
        });
    }

    private static function story_retry_is_safe(?array $row): bool {
        if (!$row || (string) ($row['status'] ?? '') !== 'failed'
            || empty($row['publish_facebook_story'])
            || (string) ($row['media_type'] ?? '') !== 'video'
            || !str_starts_with((string) ($row['last_error'] ?? ''), 'Facebook Story: ')) return false;
        $story_state = json_decode((string) ($row['facebook_story_state'] ?? ''), true);
        if (!is_array($story_state) || !empty($story_state['pending_id'])) return false;
        $platform = (string) ($row['platform'] ?? 'both');
        return in_array($platform, ['facebook', 'instagram', 'both'], true)
            && ($platform === 'instagram' || !empty($row['facebook_post_id']))
            && ($platform === 'facebook' || !empty($row['instagram_media_id']))
            && empty($row['instagram_container_id']);
    }

    public static function process_facebook_story_retry(int $id, string $attempt_token): void {
        if (!preg_match('/^[a-f0-9-]{36}$/iD', $attempt_token)) return;
        self::with_lock($id, static function () use ($id, $attempt_token) {
            $row = Store::find($id);
            if (!$row || (string) ($row['status'] ?? '') !== 'publishing'
                || !hash_equals((string) ($row['publish_attempt_token'] ?? ''), $attempt_token)
                || !empty($row['publish_worker_token'])) return false;
            $worker_token = wp_generate_uuid4();
            if (!Store::claim_publish_execution($id, $attempt_token, $worker_token, self::$claim)) return false;
            $row = Store::find($id);
            if (!$row || (string) ($row['status'] ?? '') !== 'publishing'
                || (string) ($row['publish_attempt_token'] ?? '') !== $attempt_token
                || (string) ($row['publish_worker_token'] ?? '') !== $worker_token) return false;

            try {
                $story = FacebookStories::publish($row, (string) ($row['facebook_story_state'] ?? ''));
            } catch (\Throwable $error) {
                $story = ['error' => 'Facebook Story retry was interrupted. Review the Page before retrying.', 'ambiguous' => true];
            }

            if (!empty($story['state']) && !Store::set_facebook_story_state($id, (string) $story['state'], self::$claim)) {
                self::save_result($id, 'needs_review', 'Facebook Story retry completed, but its state could not be saved. Review the Page before retrying.');
                return false;
            }
            if (!empty($story['error'])) {
                self::save_result($id, !empty($story['ambiguous']) ? 'needs_review' : 'failed', 'Facebook Story: ' . (string) $story['error']);
                return false;
            }

            $state = json_decode((string) ($story['state'] ?? ''), true);
            $expected = is_array($state) ? (int) ($state['expected'] ?? 0) : 0;
            $published_ids = is_array($state) ? array_filter((array) ($state['ids'] ?? [])) : [];
            if ($expected < 1 || count($published_ids) < $expected || !empty($state['pending_id'])) {
                self::save_result($id, 'needs_review', 'Facebook Story retry returned incomplete state. Review the Page before retrying.');
                return false;
            }
            return self::save_result($id, 'posted');
        });
    }

    public static function remove_published(int $id): bool {
        return self::with_lock($id, static function () use ($id) { return self::remove_row($id); });
    }

    public static function requires_removal_review(array $row): bool {
        return Store::has_ambiguous_removal($row);
    }

    /** Mark removal complete only after a manager manually confirms the remaining IDs are absent remotely. */
    public static function confirm_remote_removal(int $id): bool {
        return self::with_lock($id, static function () use ($id) {
            $row = Store::find($id);
            if (!$row || (string) ($row['status'] ?? '') === 'publishing' || !self::requires_removal_review($row)) return false;
            return self::save_result($id, 'removed');
        });
    }

    private static function remove_row(int $id): bool {
        $row = Store::find($id);
        if (!$row || !in_array((string) $row['status'], ['posted', 'failed', 'needs_review'], true)) return false;
        // A previous DELETE may have succeeded even though its response was lost.
        // Require a human check before making another request for the same IDs.
        if (self::requires_removal_review($row)) return false;
        $story_state = json_decode((string) ($row['facebook_story_state'] ?? ''), true);
        $story_ids = is_array($story_state) ? array_filter(array_map('strval', $story_state['ids'] ?? [])) : [];
        if (is_array($story_state) && !empty($story_state['pending_id'])) $story_ids[] = (string) $story_state['pending_id'];
        if (empty($row['facebook_post_id']) && empty($row['instagram_media_id']) && !$story_ids) {self::save_result($id,(string)$row['status'],'No published IDs are recorded; verify the remote accounts manually before marking removed.');return false;}
        // Persist the review gate before crossing the remote side-effect boundary.
        // A crash or lost response after Meta deletes an object must not expose a
        // blind retry after the worker releases its lock.
        $pending_message = self::REMOVE_REVIEW_PREFIX . ' A removal request started but has not been conclusively recorded. Check every remaining Facebook, Story, and Instagram item in Meta Business Suite; remove any still present there, then confirm here.';
        if (!self::save_result($id, 'needs_review', $pending_message)) return false;
        $facebook = empty($row['facebook_post_id']) ? [] : self::delete_remote((string) $row['facebook_post_id'], Meta::page_access_token());
        $ambiguous_removal = !empty($facebook['ambiguous']);
        if (!empty($facebook['success']) && !Store::clear_publish_id($id, 'facebook', self::$claim)) {
            $facebook=['error'=>'Remote removal succeeded but its local record could not be saved. Review before retrying.','ambiguous'=>true];
            $ambiguous_removal = true;
        }
        $story_errors = [];
        foreach (array_unique($story_ids) as $story_id) {
            $removed = self::delete_remote((string) $story_id, Meta::page_access_token());
            if (empty($removed['success'])) {
                $story_errors[] = (string) ($removed['error'] ?? 'Facebook did not confirm Story removal.');
                $ambiguous_removal = $ambiguous_removal || !empty($removed['ambiguous']);
                continue;
            }
            if (is_array($story_state)) {
                $story_state['ids'] = array_values(array_filter((array) ($story_state['ids'] ?? []), static function ($id) use ($story_id) { return (string) $id !== (string) $story_id; }));
                if ((string) ($story_state['pending_id'] ?? '') === (string) $story_id) $story_state['pending_id'] = '';
                if (!Store::set_facebook_story_state($id, wp_json_encode($story_state), self::$claim)) {
                    $story_errors[] = 'A removed Facebook Story ID could not be cleared locally; review before retrying.';
                    $ambiguous_removal = true;
                }
            }
        }
        if (!$story_errors && is_array($story_state) && !Store::clear_facebook_story_state($id, self::$claim)) {
            $story_errors[] = 'Facebook Stories were removed but their local IDs could not be cleared.';
            $ambiguous_removal = true;
        }
        $instagram = empty($row['instagram_media_id']) ? [] : self::delete_remote((string) $row['instagram_media_id'], Meta::access_token());
        $ambiguous_removal = $ambiguous_removal || !empty($instagram['ambiguous']);
        if (!empty($instagram['success']) && !Store::clear_publish_id($id, 'instagram', self::$claim)) {
            $instagram=['error'=>'Remote removal succeeded but its local record could not be saved. Review before retrying.','ambiguous'=>true];
            $ambiguous_removal = true;
        }
        $errors = array_filter([
            !empty($facebook['error']) ? 'Facebook: ' . $facebook['error'] : '',
            $story_errors ? 'Facebook Story: ' . implode(' ', $story_errors) : '',
            !empty($instagram['error']) ? 'Instagram: ' . $instagram['error'] : '',
        ]);
        if ($errors) {
            $error = implode(' ', $errors);
            if ($ambiguous_removal) {
                $error = self::REMOVE_REVIEW_PREFIX . ' Do not retry from this screen. Check every remaining Facebook, Story, and Instagram item in Meta Business Suite; remove any still present there, then confirm here. Details: ' . $error;
                self::save_result($id, 'needs_review', $error);
            } else {
                self::save_result($id, (string) $row['status'], $error);
            }
            return false;
        }
        return self::save_result($id, 'removed', '');
    }

    private static function publish_row(array $row, int $video_attempt = 0): bool {
        $id = (int) ($row['id'] ?? 0);
        $media_url = esc_url_raw((string) ($row['media_url'] ?? ''));
        $media_urls = self::row_image_urls($row);
        $caption = trim((string) ($row['post_text'] ?? ''));
        $platform = (string) ($row['platform'] ?? 'both');
        if (self::requires_removal_review($row)) return false;
        if (!in_array($platform, ['facebook', 'instagram', 'both'], true)) return false;
        if (($platform !== 'instagram' && empty($row['facebook_post_id']) && (Meta::page_id() === '' || Meta::page_access_token() === ''))
            || ($platform !== 'facebook' && empty($row['instagram_media_id']) && (Meta::instagram_user_id() === '' || Meta::access_token() === ''))) {
            self::save_result($id, 'failed', 'Connect the selected platform before publishing.');
            return false;
        }
        if ($id <= 0 || $caption === '' || ($media_url === '' && $platform !== 'facebook')) { self::save_result($id, 'failed', 'The draft is missing public media or post text.'); return false; }
        if (!self::save_result($id, 'publishing')) return false;
        $facebook = ($platform === 'instagram' || !empty($row['facebook_post_id'])) ? [] : (count($media_urls) > 1
            ? self::publish_facebook_album($media_urls, $caption)
            : self::publish_facebook($media_url, $caption, (string) ($row['media_type'] ?? 'image')));
        if (!empty($facebook['id'])) {
            if (!self::save_result($id,'publishing','',(string)$facebook['id'])) {self::save_result($id,'needs_review','Facebook accepted the post but its ID could not be saved. Review remote posts before any retry.');return false;}
            $row['facebook_post_id']=(string)$facebook['id'];
        }
        if (!empty($facebook['ambiguous'])) {self::save_result($id,'needs_review','Facebook: '.(string)$facebook['error']);return false;}
        $story = [];
        $story_error = '';
        $story_ambiguous = false;
        if ($platform !== 'instagram' && !empty($row['publish_facebook_story']) && (string) ($row['media_type'] ?? '') === 'video' && empty($row['facebook_story_state'])) {
            $initialized = wp_json_encode(['version' => 1, 'expected' => 0, 'ids' => [], 'pending_id' => '']);
            if (!Store::set_facebook_story_state($id, $initialized, self::$claim)) {
                self::save_result($id, 'needs_review', 'Facebook accepted the post, but Story processing could not be initialized. Review the Page before retrying.');
                return false;
            }
            $row['facebook_story_state'] = $initialized;
        }
        if ($platform !== 'instagram' && !empty($row['publish_facebook_story']) && (string) ($row['media_type'] ?? '') === 'video') {
            $story_state = (string) ($row['facebook_story_state'] ?? '');
            $story = FacebookStories::publish($row, $story_state);
            if (!empty($story['state']) && !Store::set_facebook_story_state($id, (string) $story['state'], self::$claim)) {
                self::save_result($id, 'needs_review', 'Facebook Stories were published, but their IDs could not be saved. Review the Page before retrying.');
                return false;
            }
            if (!empty($story['error'])) {
                // Facebook Stories are an independent destination. A Story
                // processing failure must not prevent the Instagram post.
                $story_error = 'Facebook Story: ' . (string) $story['error'];
                $story_ambiguous = !empty($story['ambiguous']);
            }
        }
        $instagram = ($platform === 'facebook' || !empty($row['instagram_media_id'])) ? [] : (count($media_urls) > 1
            ? self::publish_instagram_carousel($media_urls, $caption, $id, (string) ($row['instagram_container_id'] ?? ''))
            : self::publish_instagram($media_url, $caption, (string) ($row['media_type'] ?? 'image'), $id, (string) ($row['instagram_container_id'] ?? '')));
        if (!empty($instagram['id'])) {
            if (!self::save_result($id,'publishing','',(string)($facebook['id']??''),(string)$instagram['id'])) {self::save_result($id,'needs_review','Instagram accepted the post but its ID could not be saved. Review remote posts before retrying.');return false;}
            $row['instagram_media_id']=(string)$instagram['id'];
        }
        if (!empty($instagram['error']) && stripos((string) $instagram['error'], 'Instagram video is still processing') !== false) {
            $instagram['error'] = 'Instagram video is still processing (status check ' . $video_attempt . ' of 5).';
            if ($video_attempt >= 5) {
                $instagram['error'] = 'Instagram video is still processing after five scheduled status checks. Review the container and retry manually.';
                $instagram['ambiguous'] = true;
            } elseif (!wp_next_scheduled('roxy_social_video_status_retry', [$id, $video_attempt + 1])
                && !wp_schedule_single_event(time() + 60, 'roxy_social_video_status_retry', [$id, $video_attempt + 1])) {
                $instagram['error'] = 'Instagram video is still processing, but its automatic retry could not be scheduled. Review the container and retry manually.';
                $instagram['ambiguous'] = true;
            }
        }
        $errors = array_filter([
            !empty($facebook['error']) ? 'Facebook: ' . $facebook['error'] : '',
            $story_error,
            !empty($instagram['error']) ? 'Instagram: ' . $instagram['error'] : '',
        ]);
        if ($errors) {
            $needs_review = $story_ambiguous || !empty($instagram['ambiguous']);
            if (!empty($instagram['id']) && !Store::clear_instagram_container_id($id, self::$claim)) {
                $errors[] = 'Instagram was published, but its completed container could not be cleared locally. Review before retrying.';
                $needs_review = true;
            }
            self::save_result($id, $needs_review ? 'needs_review' : 'failed', implode(' ', $errors), (string) ($facebook['id'] ?? ''), (string) ($instagram['id'] ?? ''));
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

    private static function row_image_urls(array $row): array {
        if ((string) ($row['media_type'] ?? '') !== 'image') return [];
        $urls = json_decode((string) ($row['media_urls'] ?? ''), true);
        if (!is_array($urls)) $urls = [];
        if (!$urls && !empty($row['media_url'])) $urls = [(string) $row['media_url']];
        $urls = array_values(array_filter($urls, 'is_string'));
        return array_values(array_filter(array_unique(array_map('esc_url_raw', $urls)), static function ($url) {
            return $url !== '' && preg_match('/^https?:\/\//i', $url);
        }));
    }

    private static function publish_facebook_album(array $urls, string $caption): array {
        $photo_ids = [];
        foreach ($urls as $url) {
            $photo = self::request('https://graph.facebook.com/' . rawurlencode(Meta::page_id()) . '/photos', [
                'access_token' => Meta::page_access_token(), 'url' => $url, 'published' => 'false',
            ]);
            if (!empty($photo['error']) || empty($photo['id'])) {
                if ($photo_ids) $photo['ambiguous'] = true;
                return $photo ?: ['error' => 'Facebook did not return an uploaded photo ID.'];
            }
            $photo_ids[] = (string) $photo['id'];
        }
        $body = ['access_token' => Meta::page_access_token(), 'message' => $caption];
        foreach ($photo_ids as $index => $photo_id) $body['attached_media[' . $index . ']'] = wp_json_encode(['media_fbid' => $photo_id]);
        $post = self::request('https://graph.facebook.com/' . rawurlencode(Meta::page_id()) . '/feed', $body);
        if ((!empty($post['error']) || empty($post['id'])) && $photo_ids) $post['ambiguous'] = true;
        return $post;
    }

    private static function publish_instagram_carousel(array $urls, string $caption, int $post_id, string $existing_container_id = ''): array {
        if (count($urls) < 2 || count($urls) > 10) return ['error' => 'Instagram carousels require 2 to 10 images.'];
        $user_id = Meta::instagram_user_id();
        $base = 'https://graph.facebook.com/' . rawurlencode($user_id);
        $container = $existing_container_id !== '' ? ['id' => $existing_container_id] : null;
        if (!$container) {
            $children = [];
            foreach ($urls as $url) {
                $child = self::request($base . '/media', [
                    'access_token' => Meta::access_token(), 'image_url' => $url, 'is_carousel_item' => 'true',
                ]);
                if (!empty($child['error']) || empty($child['id'])) {
                    if ($children) $child['ambiguous'] = true;
                    return $child ?: ['error' => 'Instagram did not return a carousel image ID.'];
                }
                $children[] = (string) $child['id'];
            }
            $container = self::request($base . '/media', [
                'access_token' => Meta::access_token(), 'media_type' => 'CAROUSEL',
                'children' => implode(',', $children), 'caption' => $caption,
            ]);
            if (!empty($container['error']) || empty($container['id'])) {
                if ($children) $container['ambiguous'] = true;
                return $container ?: ['error' => 'Instagram did not return a carousel container ID.'];
            }
        }
        if (!Store::set_instagram_container_id($post_id, (string) $container['id'], self::$claim)) return ['error' => 'Instagram carousel container ID could not be saved. Review before retrying.', 'ambiguous' => true];
        $status = self::get('https://graph.facebook.com/' . rawurlencode((string) $container['id']), ['fields' => 'status_code,status', 'access_token' => Meta::access_token()]);
        if (!empty($status['error'])) return ['error' => 'Instagram carousel readiness could not be verified. Review the container before retrying.', 'ambiguous' => true];
        $status_code = (string) ($status['status_code'] ?? '');
        if ($status_code === 'PUBLISHED') return ['error' => 'Instagram reports this carousel is already published but no media ID is recorded. Review the account before retrying.', 'ambiguous' => true];
        if ($status_code === 'ERROR' || $status_code === 'EXPIRED') {
            if (!Store::clear_instagram_container_id($post_id, self::$claim)) return ['error' => 'Instagram carousel container reached terminal status but its identity could not be cleared. Review before retrying.', 'ambiguous' => true];
            return ['error' => $status_code === 'ERROR'
                ? 'Instagram carousel processing failed. Review the draft before creating a fresh container.'
                : 'Instagram carousel container expired before publication. Review the draft before creating a fresh container.'];
        }
        if ($status_code !== 'FINISHED') return ['error' => 'Instagram carousel is not ready to publish. Its container is retained; review its status before retrying.', 'ambiguous' => true];
        return self::request($base . '/media_publish', ['creation_id' => $container['id'], 'access_token' => Meta::access_token()]);
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
            if ($existing_container_id === '') return ['error' => 'Instagram video is still processing; the scheduler will retry automatically.'];
            $status = self::get('https://graph.facebook.com/' . rawurlencode((string) $container['id']), ['fields' => 'status_code,status', 'access_token' => Meta::access_token()]);
            if (!empty($status['error'])) return ['error' => 'Instagram video status could not be verified. Review the provider response before retrying.'];
            $status_code = $status['status_code'] ?? '';
            if ($status_code === 'IN_PROGRESS') return ['error' => 'Instagram video is still processing; the scheduler will retry automatically.'];
            if ($status_code === 'ERROR' || $status_code === 'EXPIRED') {
                if (!Store::clear_instagram_container_id($post_id, self::$claim)) return ['error' => 'Instagram video container reached terminal status but its local identity could not be cleared. Review before retrying.', 'ambiguous' => true];
                return ['error' => $status_code === 'ERROR'
                    ? 'Instagram video processing failed; review the draft before creating a fresh container.'
                    : 'Instagram video container expired before publication. Review the draft before creating a fresh container.'];
            }
            if ($status_code === 'PUBLISHED') return ['error' => 'Instagram reports this container is already published but no media ID is recorded. Review the account before retrying.', 'ambiguous' => true];
            if ($status_code !== 'FINISHED') return ['error' => 'Instagram returned an unknown video-container status. Review the provider account before retrying.', 'ambiguous' => true];
        }
        $publish_url = 'https://graph.facebook.com/' . rawurlencode(Meta::instagram_user_id()) . '/media_publish';
        $publish_body = ['creation_id' => $container['id'], 'access_token' => Meta::access_token()];
        // A publish request may succeed remotely even when its response is
        // rejected or lost. Never repeat it automatically; ambiguous outcomes
        // require a manager to reconcile the remote account first.
        $published = self::request($publish_url, $publish_body);
        if (!empty($published['error']) && stripos((string) $published['error'], 'Media ID is not available') !== false) {
            $published['ambiguous'] = true;
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
        if (is_wp_error($response)) return ['error'=>'Meta request outcome is unknown. Review the remote account before retrying.','ambiguous'=>$require_id || $require_success];
        $status=(int)wp_remote_retrieve_response_code($response);
        $data=json_decode((string)wp_remote_retrieve_body($response),true);
        if($status<200||$status>=300) {
            $provider_error = is_array($data['error'] ?? null) ? $data['error'] : [];
            $raw_message = $provider_error['message'] ?? null;
            $message = is_scalar($raw_message) ? sanitize_text_field((string)$raw_message) : 'No success was recorded.';
            $diagnostics = [];
            foreach (['type' => 'type', 'code' => 'code', 'error_subcode' => 'subcode'] as $field => $label) {
                if (isset($provider_error[$field]) && is_scalar($provider_error[$field])) {
                    $value = sanitize_text_field((string)$provider_error[$field]);
                    if ($value !== '') $diagnostics[] = $label . ' ' . $value;
                }
            }
            foreach (['error_user_title' => 'user title', 'error_user_msg' => 'user message'] as $field => $label) {
                if (isset($provider_error[$field]) && is_scalar($provider_error[$field])) {
                    $value = sanitize_text_field((string)$provider_error[$field]);
                    if (function_exists('mb_substr')) $value = mb_substr($value, 0, 180, 'UTF-8');
                    else $value = substr($value, 0, 180);
                    if ($value !== '') $diagnostics[] = $label . ': ' . $value;
                }
            }
            if (isset($provider_error['fbtrace_id']) && is_scalar($provider_error['fbtrace_id'])) {
                $trace_id = sanitize_text_field((string)$provider_error['fbtrace_id']);
                if ($trace_id !== '' && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $trace_id)) $diagnostics[] = 'trace ' . $trace_id;
            }
            if (isset($provider_error['is_transient']) && is_bool($provider_error['is_transient'])) {
                $diagnostics[] = 'transient ' . ($provider_error['is_transient'] ? 'yes' : 'no');
            }
            if ($diagnostics) $message .= ' (' . implode('; ', $diagnostics) . ')';
            return ['error'=>'Meta returned HTTP '.$status.': '.$message,'ambiguous'=>($require_id || $require_success)&&($status>=500||$status===0||$status===408)];
        }
        if(!is_array($data))return ['error'=>'Meta returned an unreadable response. Review the remote account before retrying.','ambiguous'=>$require_id || $require_success];
        if(isset($data['error']))return ['error'=>sanitize_text_field((string)($data['error']['message']??'Meta reported an error.'))];
        if($require_success&&($data['success']??null)!==true) {
            $ambiguous = !array_key_exists('success', $data) || !is_bool($data['success']);
            return ['error'=>'Meta did not confirm removal; the recorded ID was retained.','ambiguous'=>$ambiguous];
        }
        if($require_id&&(!isset($data['id'])||!is_scalar($data['id'])||!preg_match('/^\d+(?:_\d+)?$/',(string)$data['id'])))return ['error'=>'Meta did not return a valid published ID. Review the remote account before retrying.','ambiguous'=>true];
        return $data;
    }
}
