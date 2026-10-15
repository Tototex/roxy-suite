<?php
namespace RoxySocial;

if (!defined('ABSPATH')) exit;

final class FacebookStories {
    private const SEGMENT_SECONDS = 58;

    public static function tools_available(): bool {
        $ffmpeg = self::ffmpeg_path();
        return $ffmpeg !== '' && is_executable(dirname($ffmpeg) . '/ffprobe');
    }

    public static function publish(array $row, string $state_json = ''): array {
        @set_time_limit(0);
        $id = (int) ($row['id'] ?? 0);
        $source = self::source_file($row);
        if ($id <= 0 || $source === '') return ['error' => 'Facebook Story video source is unavailable.'];
        $ffmpeg = self::ffmpeg_path();
        if ($ffmpeg === '') return ['error' => 'Facebook Story video support is unavailable: the private FFmpeg binary was not found.'];

        $duration = self::duration($ffmpeg, $source);
        if ($duration <= 0) return ['error' => 'Facebook Story video duration could not be read.'];
        $expected = (int) ceil($duration / self::SEGMENT_SECONDS);
        $state = $state_json !== '' ? json_decode($state_json, true) : null;
        if (!is_array($state)) $state = ['version' => 1, 'expected' => 0, 'ids' => [], 'pending_id' => ''];
        if ((int) ($state['expected'] ?? 0) === 0) {
            $state['expected'] = $expected;
            if (!Store::set_facebook_story_state($id, wp_json_encode($state), Publisher::worker_claim())) return ['error' => 'Facebook Story processing could not be saved.'];
        }
        if ((int) $state['expected'] !== $expected || !is_array($state['ids'] ?? null)) {
            return ['error' => 'The video or Story schedule changed after publishing began. Review the Facebook Story before retrying.', 'ambiguous' => true];
        }
        if (!empty($state['pending_id'])) return ['error' => 'A Facebook Story upload was interrupted before its result was recorded. Review the Page Story, then remove the incomplete upload or contact support before retrying.', 'ambiguous' => true];
        if (count(array_filter($state['ids'])) >= $expected) return ['state' => wp_json_encode($state)];

        $dir = trailingslashit(sys_get_temp_dir()) . 'roxy-story-' . $id . '-' . wp_generate_password(10, false, false);
        if (!wp_mkdir_p($dir)) return ['error' => 'Could not create a temporary folder for Facebook Story processing.'];
        @chmod($dir, 0700);
        $segments = [];
        try {
            $pattern = trailingslashit($dir) . 'segment-%03d.mp4';
            $command = escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -nostdin -y -cpucount 1 -threads 1 -filter_threads 1 -filter_complex_threads 1 -i ' . escapeshellarg($source)
                . ' -map 0:v:0 -map ' . escapeshellarg('0:a?') . ' -c:v libx264 -preset ultrafast -crf 24 -pix_fmt yuv420p -threads 1 -g 60 -keyint_min 60 -sc_threshold 0 -force_key_frames ' . escapeshellarg('expr:gte(t,n_forced*' . self::SEGMENT_SECONDS . ')')
                . ' -c:a aac -b:a 128k -ac 2 -f segment -segment_time ' . self::SEGMENT_SECONDS . ' -reset_timestamps 1 ' . escapeshellarg($pattern) . ' 2>&1';
            exec($command, $output, $exit_code);
            if ($exit_code !== 0) return ['error' => 'FFmpeg could not prepare the Facebook Story video. Check the source format and server logs.'];
            $segments = glob(trailingslashit($dir) . 'segment-*.mp4') ?: [];
            sort($segments, SORT_STRING);
            if (count($segments) !== $expected || count($segments) < 1) return ['error' => 'FFmpeg produced an unexpected number of Facebook Story video segments.'];
            foreach ($segments as $segment) {
                $segment_duration = self::duration($ffmpeg, $segment);
                if ($segment_duration <= 0 || $segment_duration > 60.0) return ['error' => "A prepared Facebook Story segment exceeds Meta's 60-second limit."];
            }

            $page_id = Meta::page_id();
            $token = Meta::page_access_token();
            foreach ($segments as $index => $segment) {
                if (!empty($state['ids'][$index])) continue;
                if (!Publisher::owns_worker_lock()) return ['error' => 'Publishing worker ownership was lost. Review the Facebook Page before retrying.', 'ambiguous' => true];
                $start = self::graph_post($page_id . '/video_stories', ['upload_phase' => 'start', 'access_token' => $token]);
                if (!empty($start['error'])) return ['error' => 'Facebook Story upload could not be started: ' . (string) $start['error'], 'ambiguous' => !empty($start['ambiguous'])];
                if (empty($start['video_id']) || empty($start['upload_url'])) return ['error' => 'Facebook Story upload returned incomplete upload details. Review the Page before retrying.', 'ambiguous' => true];

                $state['pending_id'] = (string) $start['video_id'];
                if (!Store::set_facebook_story_state($id, wp_json_encode($state), Publisher::worker_claim())) return ['error' => 'Facebook Story upload started, but its ID could not be recorded. Review the Page before retrying.', 'ambiguous' => true];

                $binary = file_get_contents($segment);
                if (!is_string($binary)) {
                    $state['pending_id'] = '';
                    Store::set_facebook_story_state($id, wp_json_encode($state), Publisher::worker_claim());
                    return ['error' => 'A prepared Facebook Story segment could not be read.'];
                }
                $upload_url = esc_url_raw((string) $start['upload_url']);
                $upload_host = strtolower((string) wp_parse_url($upload_url, PHP_URL_HOST));
                if (wp_parse_url($upload_url, PHP_URL_SCHEME) !== 'https' || $upload_host !== 'rupload.facebook.com') {
                    $state['pending_id'] = '';
                    Store::set_facebook_story_state($id, wp_json_encode($state), Publisher::worker_claim());
                    return ['error' => 'Facebook returned an unexpected Story upload destination.'];
                }
                $upload = wp_remote_post($upload_url, [
                    'timeout' => 45,
                    'headers' => [
                        'Authorization' => 'OAuth ' . $token,
                        'offset' => '0',
                        'file_size' => (string) filesize($segment),
                        'Content-Type' => 'application/octet-stream',
                    ],
                    'body' => $binary,
                ]);
                unset($binary);
                $uploaded = self::decode($upload, false);
                if (empty($uploaded['success'])) {
                    if (empty($uploaded['ambiguous'])) {
                        $state['pending_id'] = '';
                        if (!Store::set_facebook_story_state($id, wp_json_encode($state), Publisher::worker_claim())) {
                            return ['error' => 'Facebook rejected the Story segment, but its pending upload marker could not be cleared. Review before retrying.', 'ambiguous' => true];
                        }
                    }
                    return ['error' => 'Facebook Story segment upload failed: ' . (string) ($uploaded['error'] ?? 'Meta did not confirm the video upload.'), 'ambiguous' => !empty($uploaded['ambiguous'])];
                }
                $finish = self::graph_post($page_id . '/video_stories', [
                    'upload_phase' => 'finish', 'video_id' => (string) $start['video_id'],
                    'video_state' => 'PUBLISHED', 'access_token' => $token,
                ]);
                if (!empty($finish['error']) || empty($finish['success'])) {
                    return ['error' => 'Facebook may have received this Story segment but did not confirm publication. Review the Page before retrying: ' . (string) ($finish['error'] ?? 'No success confirmation.'), 'ambiguous' => true];
                }
                $state['ids'][$index] = (string) $start['video_id'];
                $state['pending_id'] = '';
                if (!Store::set_facebook_story_state($id, wp_json_encode($state), Publisher::worker_claim())) return ['error' => 'Facebook published a Story segment, but its ID could not be saved. Review the Page before retrying.', 'ambiguous' => true];
            }
            return ['state' => wp_json_encode($state)];
        } finally {
            foreach ($segments as $segment) if (is_file($segment)) @unlink($segment);
            foreach (glob(trailingslashit($dir) . '*') ?: [] as $file) if (is_file($file)) @unlink($file);
            @rmdir($dir);
        }
    }

    private static function source_file(array $row): string {
        $attachment_id = (int) ($row['temporary_attachment_id'] ?? 0);
        if (!$attachment_id && !empty($row['media_url'])) $attachment_id = (int) attachment_url_to_postid((string) $row['media_url']);
        $path = $attachment_id > 0 ? (string) get_attached_file($attachment_id) : '';
        return $path !== '' && is_readable($path) ? $path : '';
    }

    private static function ffmpeg_path(): string {
        $home = (string) getenv('HOME');
        $wordpress_parent = rtrim(dirname(rtrim(ABSPATH, '/')), '/');
        $candidates = [
            (string) get_option('roxy_social_ffmpeg_path', ''),
            ($home !== '' ? rtrim($home, '/') . '/.local/ffmpeg-8.1/bin/ffmpeg' : ''),
            ($wordpress_parent !== '' ? $wordpress_parent . '/.local/ffmpeg-8.1/bin/ffmpeg' : ''),
        ];
        foreach ($candidates as $path) if ($path !== '' && is_executable($path)) return $path;
        return '';
    }

    private static function duration(string $ffmpeg, string $source): float {
        $probe = dirname($ffmpeg) . '/ffprobe';
        if (!is_executable($probe)) return 0;
        $command = escapeshellarg($probe) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($source) . ' 2>&1';
        exec($command, $output, $exit_code);
        return $exit_code === 0 && isset($output[0]) && is_numeric($output[0]) ? (float) $output[0] : 0;
    }

    private static function graph_post(string $path, array $body): array {
        $path = implode('/', array_map('rawurlencode', explode('/', $path)));
        return self::decode(wp_remote_post('https://graph.facebook.com/' . $path, ['timeout' => 45, 'body' => $body]), false);
    }

    private static function decode($response, bool $require_success): array {
        if (is_wp_error($response)) return ['error' => 'Meta request outcome could not be confirmed.', 'ambiguous' => true];
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $detail = (string) ($data['error']['message'] ?? '');
            if ($detail === '' && isset($data['error']) && is_scalar($data['error'])) $detail = (string) $data['error'];
            if ($detail === '') $detail = substr((string) wp_remote_retrieve_body($response), 0, 240);
            return ['error' => 'Meta returned HTTP ' . $status . ': ' . sanitize_text_field($detail !== '' ? $detail : 'No success was recorded.'), 'ambiguous' => $status === 408 || $status >= 500 || $status === 0];
        }
        if (!is_array($data)) return ['error' => 'Meta returned an unreadable response.', 'ambiguous' => true];
        if (isset($data['error'])) return ['error' => sanitize_text_field((string) ($data['error']['message'] ?? 'Meta reported an error.'))];
        if ($require_success && ($data['success'] ?? null) !== true) return ['error' => 'Meta did not confirm the video upload.'];
        return $data;
    }
}
