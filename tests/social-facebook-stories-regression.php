<?php
/** Isolated Facebook Story upload-state regression. All Meta requests are stubbed; no account/network writes occur. */
namespace RoxySocial {
    final class Meta {
        public static function page_id(): string { return 'page-fixture'; }
        public static function page_access_token(): string { return 'fixture-token'; }
    }
    final class Publisher {
        public static function owns_worker_lock(): bool { return true; }
        public static function worker_claim(): array { return ['owner' => 'fixture']; }
    }
    final class Store {
        public static string $state = '';
        public static int $state_writes = 0;
        public static function set_facebook_story_state(int $id, string $state, ?array $claim = null): bool {
            self::$state = $state;
            ++self::$state_writes;
            return true;
        }
    }
}

namespace {
    define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
    require __DIR__ . '/../includes/modules/social-publisher/includes/class-roxy-social-facebook-stories.php';

    final class StoryFixtureError {
        public function __construct(public string $message) {}
        public function get_error_message(): string { return $this->message; }
    }
    $GLOBALS['story_fixture_ffmpeg'] = '';
    $GLOBALS['story_fixture_source'] = '';
    $GLOBALS['story_fixture_http'] = [];
    $GLOBALS['story_fixture_requests'] = 0;

    function get_option($name, $default = false) {
        return $name === 'roxy_social_ffmpeg_path' ? $GLOBALS['story_fixture_ffmpeg'] : $default;
    }
    function get_attached_file($id) { return $id === 501 ? $GLOBALS['story_fixture_source'] : ''; }
    function attachment_url_to_postid($url) { return 0; }
    function trailingslashit($value) { return rtrim((string) $value, '/\\') . DIRECTORY_SEPARATOR; }
    function wp_generate_password($length = 12, $special = true, $extra = false) { return str_repeat('a', max(1, (int) $length)); }
    function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0700, true); }
    function wp_json_encode($value) { return json_encode($value); }
    function esc_url_raw($url) { return $url; }
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    function is_wp_error($value) { return $value instanceof StoryFixtureError; }
    function sanitize_text_field($value) { return strip_tags((string) $value); }
    function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
    function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
    function wp_remote_post($url, $args = []) {
        ++$GLOBALS['story_fixture_requests'];
        if (!$GLOBALS['story_fixture_http']) throw new \RuntimeException('Unexpected unstubbed Meta request.');
        $next = array_shift($GLOBALS['story_fixture_http']);
        return is_callable($next) ? $next($url, $args) : $next;
    }

    $checks = 0;
    $assert = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new \RuntimeException('FAIL: ' . $message);
        ++$checks;
        echo 'PASS: ' . $message . PHP_EOL;
    };
    $root = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'roxy-story-fixture-' . bin2hex(random_bytes(5));
    if (!mkdir($root, 0700, true)) throw new \RuntimeException('Could not create the private media fixture directory.');
    $GLOBALS['story_fixture_source'] = $root . DIRECTORY_SEPARATOR . 'source.mp4';
    $GLOBALS['story_fixture_ffmpeg'] = $root . DIRECTORY_SEPARATOR . 'ffmpeg';
    $ffprobe = $root . DIRECTORY_SEPARATOR . 'ffprobe';
    file_put_contents($GLOBALS['story_fixture_source'], 'fixture source video');
    file_put_contents($GLOBALS['story_fixture_ffmpeg'], "#!/bin/sh\ninput=''\noutput=''\nnext_input=0\nfor arg in \"\$@\"; do case \"\$arg\" in -i) next_input=1;; *segment-%03d.mp4) output=\"\$arg\";; *) if [ \"\$next_input\" = 1 ]; then input=\"\$arg\"; next_input=0; fi;; esac; done\n[ -n \"\$output\" ] && [ -n \"\$input\" ] || exit 2\ncount=1\ngrep -q multi-segment \"\$input\" && count=2\ni=0\nwhile [ \"\$i\" -lt \"\$count\" ]; do index=\$(printf '%03d' \"\$i\"); target=\$(printf '%s' \"\$output\" | sed \"s/%03d/\$index/\"); printf segment > \"\$target\" || exit 3; i=\$((i + 1)); done\n");
    file_put_contents($ffprobe, "#!/bin/sh\nfor arg in \"\$@\"; do source=\"\$arg\"; done\ncase \"\$source\" in *source.mp4) if grep -q multi-segment \"\$source\"; then printf '70.0\\n'; else printf '10.0\\n'; fi;; *segment-000.mp4) printf '58.0\\n';; *segment-001.mp4) printf '12.0\\n';; *) exit 2;; esac\n");
    chmod($GLOBALS['story_fixture_ffmpeg'], 0700);
    chmod($ffprobe, 0700);
    $assert(\RoxySocial\FacebookStories::tools_available(), 'configured FFmpeg path is considered ready only with executable companion ffprobe');
    $row = ['id' => 8001, 'temporary_attachment_id' => 501];
    $initial = json_encode(['version' => 1, 'expected' => 0, 'ids' => [], 'pending_id' => '']);

    try {
        $GLOBALS['story_fixture_http'] = [
            ['response' => ['code' => 200], 'body' => json_encode(['video_id' => 'ambiguous-story-id', 'upload_url' => 'https://rupload.facebook.com/video/upload'])],
            new StoryFixtureError('connection closed after upload'),
        ];
        $GLOBALS['story_fixture_requests'] = 0;
        $ambiguous = \RoxySocial\FacebookStories::publish($row, $initial);
        $saved = json_decode(\RoxySocial\Store::$state, true);
        $assert(!empty($ambiguous['ambiguous']) && ($saved['pending_id'] ?? '') === 'ambiguous-story-id',
            'ambiguous upload transport failure keeps its remote ID as a retry guard');
        $requests_before_retry = $GLOBALS['story_fixture_requests'];
        $retry = \RoxySocial\FacebookStories::publish($row, \RoxySocial\Store::$state);
        $assert(!empty($retry['ambiguous']) && $GLOBALS['story_fixture_requests'] === $requests_before_retry,
            'retry with unresolved upload state makes no duplicate Meta request');

        \RoxySocial\Store::$state = '';
        $GLOBALS['story_fixture_http'] = [
            ['response' => ['code' => 200], 'body' => json_encode(['video_id' => 'rejected-story-id', 'upload_url' => 'https://rupload.facebook.com/video/upload'])],
            ['response' => ['code' => 400], 'body' => json_encode(['error' => ['message' => 'invalid segment']])],
        ];
        $definitive = \RoxySocial\FacebookStories::publish($row, $initial);
        $saved = json_decode(\RoxySocial\Store::$state, true);
        $assert(empty($definitive['ambiguous']) && ($saved['pending_id'] ?? 'not-empty') === '',
            'definitive provider rejection clears the pending upload guard for a safe retry');

        \RoxySocial\Store::$state = '';
        $GLOBALS['story_fixture_http'] = [
            ['response' => ['code' => 200], 'body' => json_encode(['video_id' => 'timeout-story-id', 'upload_url' => 'https://rupload.facebook.com/video/upload'])],
            ['response' => ['code' => 408], 'body' => json_encode(['error' => ['message' => 'upload timed out']])],
        ];
        $timeout = \RoxySocial\FacebookStories::publish($row, $initial);
        $saved = json_decode(\RoxySocial\Store::$state, true);
        $assert(!empty($timeout['ambiguous']) && ($saved['pending_id'] ?? '') === 'timeout-story-id',
            'HTTP 408 retains the pending Story ID because upload completion is uncertain');
        $requests_before_timeout_retry = $GLOBALS['story_fixture_requests'];
        $timeout_retry = \RoxySocial\FacebookStories::publish($row, \RoxySocial\Store::$state);
        $assert(!empty($timeout_retry['ambiguous']) && $GLOBALS['story_fixture_requests'] === $requests_before_timeout_retry,
            'retry after HTTP 408 sends no duplicate Meta upload request');

        \RoxySocial\Store::$state = '';
        file_put_contents($GLOBALS['story_fixture_source'], 'multi-segment fixture video');
        $GLOBALS['story_fixture_http'] = [
            ['response' => ['code' => 200], 'body' => json_encode(['video_id' => 'segment-0-id', 'upload_url' => 'https://rupload.facebook.com/video/upload'])],
            ['response' => ['code' => 200], 'body' => json_encode(['success' => true])],
            ['response' => ['code' => 200], 'body' => json_encode(['success' => true])],
            ['response' => ['code' => 200], 'body' => json_encode(['video_id' => 'segment-1-rejected-id', 'upload_url' => 'https://rupload.facebook.com/video/upload'])],
            ['response' => ['code' => 400], 'body' => json_encode(['error' => ['message' => 'invalid segment']])],
        ];
        $partial = \RoxySocial\FacebookStories::publish($row, $initial);
        $partial_state = \RoxySocial\Store::$state;
        $saved = json_decode($partial_state, true);
        $assert(empty($partial['ambiguous']) && ($saved['ids'][0] ?? '') === 'segment-0-id' && ($saved['pending_id'] ?? 'not-empty') === '',
            'two-segment Story records first successful segment before second-segment rejection');
        $GLOBALS['story_fixture_http'] = [
            ['response' => ['code' => 200], 'body' => json_encode(['video_id' => 'segment-1-retry-id', 'upload_url' => 'https://rupload.facebook.com/video/upload'])],
            ['response' => ['code' => 200], 'body' => json_encode(['success' => true])],
            ['response' => ['code' => 200], 'body' => json_encode(['success' => true])],
        ];
        $requests_before_resume = $GLOBALS['story_fixture_requests'];
        $resumed = \RoxySocial\FacebookStories::publish($row, $partial_state);
        $saved = json_decode((string) ($resumed['state'] ?? ''), true);
        $assert(empty($resumed['error']) && ($saved['ids'][0] ?? '') === 'segment-0-id' && ($saved['ids'][1] ?? '') === 'segment-1-retry-id'
            && $GLOBALS['story_fixture_requests'] === $requests_before_resume + 3,
            'resume publishes only the unfinished second segment and preserves the first remote Story ID');

        \RoxySocial\Store::$state = '';
        file_put_contents($GLOBALS['story_fixture_source'], 'fixture source video');
        $GLOBALS['story_fixture_http'] = [new StoryFixtureError('start response lost')];
        $start_ambiguous = \RoxySocial\FacebookStories::publish($row, $initial);
        $assert(!empty($start_ambiguous['ambiguous']),
            'ambiguous Story-start response is sent to manual review rather than ordinary retry');
        echo 'Passed ' . $checks . ' Facebook Story state checks.' . PHP_EOL;
    } finally {
        foreach (glob($root . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path)) unlink($path);
        foreach (glob(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'roxy-story-8001-*') ?: [] as $dir) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path)) unlink($path);
            if (is_dir($dir)) rmdir($dir);
        }
        rmdir($root);
    }
}
