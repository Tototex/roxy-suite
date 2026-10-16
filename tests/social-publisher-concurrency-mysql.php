<?php
// wp eval-file tests/social-publisher-concurrency-mysql.php REPO_ROOT
// Runs the real Publisher and Store against a disposable table and a stubbed provider.
namespace RoxySocial {
    final class ConcurrentFixtureMeta {
        public static function page_id(): string { return '123'; }
        public static function page_access_token(): string { return 'fixture-token'; }
        public static function instagram_user_id(): string { return ''; }
        public static function access_token(): string { return ''; }
    }
    final class ConcurrentFixtureStories {
        public static function publish(array $row, string $state = ''): array { return []; }
    }
}

namespace {
    global $wpdb;
    $root = $args[0] ?? dirname(__DIR__);
    $store_source = file_get_contents($root . '/includes/modules/social-publisher/includes/class-roxy-social-store.php');
    $publisher_source = file_get_contents($root . '/includes/modules/social-publisher/includes/class-roxy-social-publisher.php');
    if (!is_string($store_source) || !is_string($publisher_source)) throw new RuntimeException('Could not read the Social source files.');
    $store_source = str_replace('final class Store {', 'final class ConcurrentFixtureStore {', $store_source);
    eval(substr($store_source, 5));
    $publisher_source = str_replace(
        ['final class Publisher {', 'Store::', 'Meta::', 'FacebookStories::'],
        ['final class ConcurrentFixturePublisher {', 'ConcurrentFixtureStore::', 'ConcurrentFixtureMeta::', 'ConcurrentFixtureStories::'],
        $publisher_source
    );
    eval(substr($publisher_source, 5));

    function roxy_social_race_check(bool $ok, string $label): void {
        if (!$ok) throw new RuntimeException($label);
        echo "PASS: {$label}\n";
    }

    $original_prefix = $wpdb->prefix;
    $original_connection = $wpdb;
    $fixture_prefix = 'roxy_social_race_' . bin2hex(random_bytes(5)) . '_';
    $fixture_table = $fixture_prefix . \RoxySocial\ConcurrentFixtureStore::TABLE;
    $real_table = $original_prefix . \RoxySocial\ConcurrentFixtureStore::TABLE;
    $second_connection = null;
    $provider_calls = 0;
    $race_results = [];
    $filter = null;
    $suppressed = $wpdb->suppress_errors(true);

    try {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($fixture_table)))) {
            throw new RuntimeException('Randomized fixture table already exists; refusing to reuse it.');
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($real_table))) !== $real_table
            || false === $wpdb->query("CREATE TABLE `{$fixture_table}` LIKE `{$real_table}`")) {
            throw new RuntimeException('Could not create the isolated Social table clone.');
        }
        $wpdb->prefix = $fixture_prefix;
        $wpdb->insert($fixture_table, [
            'campaign_key' => 'publisher-race-fixture',
            'post_key' => 'publisher-race-' . bin2hex(random_bytes(4)),
            'showing_ids' => '[]',
            'platform' => 'facebook',
            'scheduled_for' => current_time('mysql'),
            'status' => 'approved',
            'ai_status' => 'ready',
            'post_text' => 'Original fixture caption',
            'media_type' => 'image',
            'media_url' => 'https://fixture.invalid/poster.jpg',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $id = (int) $wpdb->insert_id;
        if ($id <= 0) throw new RuntimeException('Could not create the isolated Social draft.');
        $second_connection = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $second_connection->prefix = $fixture_prefix;

        $filter = static function ($preempt, $request_args, $url) use (&$provider_calls, &$race_results, $second_connection, $id) {
            if ($preempt !== false) return $preempt;
            ++$provider_calls;
            if (!str_ends_with((string) $url, '/123/photos')) throw new RuntimeException('The test unexpectedly called a different provider endpoint.');

            // Interleave real admin/worker attempts while the first Publisher holds its DB lock.
            $first_connection = $GLOBALS['wpdb'];
            $GLOBALS['wpdb'] = $second_connection;
            try {
                $row = \RoxySocial\ConcurrentFixtureStore::find($id);
                $race_results['edit'] = $row && \RoxySocial\ConcurrentFixtureStore::update_draft(
                    $id,
                    'Concurrent manager edit',
                    (string) $row['scheduled_for'],
                    (string) $row['media_url'],
                    (string) $row['media_type'],
                    \RoxySocial\ConcurrentFixtureStore::draft_revision($row)
                );
                $attempt = (string) ($row['publish_attempt_token'] ?? '');
                \RoxySocial\ConcurrentFixturePublisher::process_queued($id, 0, $attempt);
                $race_results['duplicate'] = \RoxySocial\ConcurrentFixturePublisher::publish_now($id);
                $race_results['remove'] = \RoxySocial\ConcurrentFixturePublisher::remove_published($id);
                $race_results['status_during_request'] = (string) (\RoxySocial\ConcurrentFixtureStore::find($id)['status'] ?? '');
            } finally {
                $GLOBALS['wpdb'] = $first_connection;
            }

            // A deterministic fake response; no request reaches Meta.
            return ['response' => ['code' => 200], 'headers' => [], 'body' => wp_json_encode(['id' => '12345']), 'cookies' => []];
        };
        add_filter('pre_http_request', $filter, 10, 3);

        roxy_social_race_check(\RoxySocial\ConcurrentFixturePublisher::publish_now($id), 'real Publisher completes one stubbed Facebook publication');
        $row = \RoxySocial\ConcurrentFixtureStore::find($id);
        roxy_social_race_check($provider_calls === 1, 'one provider request occurs despite reentrant concurrent attempts');
        roxy_social_race_check(($race_results['edit'] ?? null) === false && ($race_results['status_during_request'] ?? '') === 'publishing'
            && $row['post_text'] === 'Original fixture caption', 'manager edit cannot overwrite the locked in-flight draft');
        roxy_social_race_check(($race_results['duplicate'] ?? null) === false && ($race_results['remove'] ?? null) === false
            && $row['status'] === 'posted' && $row['facebook_post_id'] === '12345', 'duplicate publisher/removal attempts cannot race the accepted provider result');
    } finally {
        if ($filter !== null) remove_filter('pre_http_request', $filter, 10);
        $wpdb = $original_connection;
        if ($second_connection) $second_connection->close();
        $wpdb->query("DROP TABLE IF EXISTS `{$fixture_table}`");
        $wpdb->prefix = $original_prefix;
        $wpdb->suppress_errors($suppressed);
    }
}
