<?php
/**
 * WP-CLI installed fixture for weekend-showing clone identity/date regressions.
 * The private helper is invoked directly: the public admin handler also starts
 * social-campaign generation, which is intentionally outside this test.
 */
if (!defined('WP_CLI') || !WP_CLI) exit;
global $wpdb;

if (!class_exists('RoxyST\\CPT') || !class_exists('RoxyST\\Products')) throw new RuntimeException('Show Tickets CPT and Products classes must already be loaded.');
if (!defined('ROXY_ST_META_SHOWING_ID') || !defined('ROXY_ST_META_TICKET_TYPE')) throw new RuntimeException('Show Tickets metadata constants are unavailable.');

$owner = 'ticket-weekend-duplicate-' . wp_generate_uuid4();
$marker = 'PRIVATE ' . $owner;
$created_ids = [];
$mail_calls = 0;
$http_calls = 0;
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    ++$checks;
    echo 'PASS: ' . $message . "\n";
};

// Capture posts even if the tested helper throws after insertion but before return.
$capture = static function ($post_id, $post) use (&$created_ids, $marker): void {
    if ($post instanceof WP_Post && strpos((string) $post->post_title, $marker) === 0) $created_ids[(int) $post_id] = true;
};
$timezone = static function () { return 'America/Los_Angeles'; };
$mail_guard = static function ($pre) use (&$mail_calls) { ++$mail_calls; return false; };
$http_guard = static function ($pre) use (&$http_calls) { ++$http_calls; return new WP_Error('fixture_http_blocked', 'External HTTP is blocked by this fixture.'); };
add_action('wp_after_insert_post', $capture, 10, 4);
add_filter('pre_option_timezone_string', $timezone);
add_filter('pre_wp_mail', $mail_guard, 10, 2);
add_filter('pre_http_request', $http_guard, 10, 3);

$source_id = 0;
try {
    foreach ([910001,910002,910003,910004,910005,910006,910099] as $dummy_id) {
        if (get_post($dummy_id)) throw new RuntimeException('Dummy product reference unexpectedly exists; fixture aborted.');
    }
    if (wp_timezone_string() !== 'America/Los_Angeles') throw new RuntimeException('Fixture could not force the Los Angeles site timezone.');
    $clone = new ReflectionMethod('RoxyST\\CPT', 'duplicate_showing_to_next_weekend');
    $clone->setAccessible(true);
    $cases = [
        ['2026-10-31T23:30', '2026-11-07T23:30', 169],
        ['2026-03-07T23:30', '2026-03-14T23:30', 167],
    ];
    $pid_meta = ['_roxy_pid_adult','_roxy_pid_discount','_roxy_pid_matinee','_roxy_pid_live1','_roxy_pid_live2','_roxy_pid_subscriber'];
    foreach ($cases as $index => [$source_start, $expected_start, $expected_hours]) {
        $source_id = wp_insert_post([
            'post_type' => \RoxyST\CPT::POST_TYPE,
            'post_status' => 'draft',
            'post_title' => $marker . ' case-' . $index,
            'post_content' => 'Private duplicate fixture only.',
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', true),
        ], true);
        if (is_wp_error($source_id) || !$source_id) throw new RuntimeException('Could not create private draft source showing.');
        $source_id = (int) $source_id;
        $created_ids[$source_id] = true;
        $source_meta = [
            '_roxy_test_fixture_owner' => $owner,
            '_roxy_start' => $source_start,
            '_roxy_pricing_profile' => 'live_event',
            '_roxy_pid_adult' => '910001', '_roxy_pid_discount' => '910002',
            '_roxy_pid_matinee' => '910003', '_roxy_pid_live1' => '910004',
            '_roxy_pid_live2' => '910005', '_roxy_pid_subscriber' => '910006',
            '_roxy_legacy_product_ids' => "910001\n910002\n910099",
            '_roxy_sales_stats' => ['legacy_buyer_email@example.test' => ['adult' => 2]],
            '_roxy_live_label_1' => 'General Admission',
            '_roxy_live_price_1' => '18.00', '_roxy_live_future_price_1' => '22.00',
            '_roxy_live_change_at_1' => $index === 0 ? '2026-11-01T00:30' : '2026-03-08T00:30',
            '_roxy_live_label_2' => 'VIP',
            '_roxy_live_price_2' => '30.00', '_roxy_live_future_price_2' => '35.00',
            '_roxy_live_change_at_2' => $index === 0 ? '2026-11-02T00:15' : '2026-03-09T00:15',
        ];
        foreach ($source_meta as $key => $value) {
            if (!add_post_meta($source_id, $key, $value, true)) throw new RuntimeException('Could not save fixture metadata: ' . $key);
        }

        $child_id = (int) $clone->invoke(null, $source_id);
        if ($child_id > 0) $created_ids[$child_id] = true;
        if (!$child_id) throw new RuntimeException('Duplicate helper did not return a child showing ID.');
        $child = get_post($child_id);
        $check($child instanceof WP_Post && $child->post_type === \RoxyST\CPT::POST_TYPE && $child->post_status === 'draft', 'child remains a private draft showing');
        $check(get_post_meta($child_id, '_roxy_test_fixture_owner', true) === $owner, 'child carries the cleanup ownership marker');
        $actual_start = (string) get_post_meta($child_id, '_roxy_start', true);
        $check($actual_start === $expected_start, 'next weekend preserves local wall-clock date/time across DST');
        $source_local = new DateTimeImmutable($source_start, wp_timezone());
        $child_local = new DateTimeImmutable($actual_start, wp_timezone());
        $check(($child_local->getTimestamp() - $source_local->getTimestamp()) === $expected_hours * 3600, 'calendar-week arithmetic crosses DST by the expected elapsed hours');

        foreach ($pid_meta as $key) $check(get_post_meta($child_id, $key, true) === '', 'child omits stale canonical ticket ID ' . $key);
        $check(get_post_meta($child_id, '_roxy_legacy_product_ids', true) === '', 'child omits source legacy product ID list');
        $check(get_post_meta($child_id, '_roxy_sales_stats', true) === '', 'child does not copy source showing sales-stat cache');
        foreach (['_roxy_live_label_1','_roxy_live_price_1','_roxy_live_future_price_1','_roxy_live_change_at_1','_roxy_live_label_2','_roxy_live_price_2','_roxy_live_future_price_2','_roxy_live_change_at_2'] as $key) {
            $expected = $source_meta[$key];
            if ($key === '_roxy_live_change_at_1') $expected = $index === 0 ? '2026-11-08T00:30' : '2026-03-15T00:30';
            if ($key === '_roxy_live_change_at_2') $expected = $index === 0 ? '2026-11-09T00:15' : '2026-03-16T00:15';
            $check(get_post_meta($child_id, $key, true) === $expected, 'scheduled live-tier metadata preserved or date-shifted for next weekend: ' . $key);
        }
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID=%d AND post_type=%s AND post_status='draft'", $child_id, \RoxyST\CPT::POST_TYPE)) === 1, 'child database row is not publicly published');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE %s AND post_status='publish'", $marker . '%')) === 0, 'no public post was created for the fixture marker');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_title LIKE %s", $marker . '%')) === 0, 'draft duplicate generated no ticket product');
        $source_id = 0;
    }
    $check($mail_calls === 0 && $http_calls === 0, 'fixture invoked no mail or external HTTP');
    echo "Passed {$checks} installed weekend-duplicate checks. Scheduled live-price change dates advance seven local calendar days with the duplicate.\n";
} finally {
    $cleanup_failed=[];
    foreach (array_keys($created_ids) as $id) {
        $post = get_post((int) $id);
        if (!$post) continue;
        $owned = in_array($post->post_type, [\RoxyST\CPT::POST_TYPE, 'product'], true)
            && $post->post_status === 'draft'
            && strpos((string) $post->post_title, $marker) === 0;
        if (!$owned) {
            error_log('Weekend duplicate fixture refused cleanup for unverified post ID ' . (int) $id);
            $cleanup_failed[]=(int)$id;
            continue;
        }
        foreach (['_roxy_pid_adult','_roxy_pid_discount','_roxy_pid_matinee','_roxy_pid_live1','_roxy_pid_live2','_roxy_pid_subscriber','_roxy_legacy_product_ids'] as $key) delete_post_meta((int)$id,$key);
        wp_delete_post((int) $id, true);
        if (get_post((int)$id)) $cleanup_failed[]=(int)$id;
    }
    remove_action('wp_after_insert_post', $capture, 10);
    remove_filter('pre_option_timezone_string', $timezone);
    remove_filter('pre_wp_mail', $mail_guard, 10);
    remove_filter('pre_http_request', $http_guard, 10);
    if ($cleanup_failed) throw new RuntimeException('Owned fixture cleanup incomplete: '.implode(',',$cleanup_failed));
    echo 'Owned private draft fixture posts removed.'.PHP_EOL;
}
