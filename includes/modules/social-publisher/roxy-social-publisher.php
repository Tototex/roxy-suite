<?php
/**
 * Social Publisher — creates reviewable drafts and publishes approved posts.
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/class-roxy-social-store.php';
require_once __DIR__ . '/includes/class-roxy-social-campaigns.php';
require_once __DIR__ . '/includes/class-roxy-social-admin.php';
require_once __DIR__ . '/includes/class-roxy-social-hangar.php';
require_once __DIR__ . '/includes/class-roxy-social-meta.php';
require_once __DIR__ . '/includes/class-roxy-social-publisher.php';

add_action('plugins_loaded', function () {
    if (get_option('roxy_social_schema_version') !== '1.5') {
        \RoxySocial\Store::install_schema();
        global $wpdb;
        $wpdb->query("UPDATE " . \RoxySocial\Store::table_name() . " SET status = 'draft' WHERE status = 'skipped'");
        update_option('roxy_social_schema_version', '1.5');
    }
    \RoxySocial\Campaigns::init();
    \RoxySocial\Admin::init();
    add_action('delete_attachment', ['\\RoxySocial\\Hangar', 'delete_video_thumbnail']);
    add_action('roxy_social_cleanup', ['\\RoxySocial\\Store', 'cleanup_expired']);
    if (!wp_next_scheduled('roxy_social_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'roxy_social_cleanup');
    add_filter('cron_schedules', static function (array $schedules): array { $schedules['roxy_five_minutes'] = ['interval' => 300, 'display' => 'Every five minutes']; return $schedules; });
    add_action('roxy_social_publish_due', ['\\RoxySocial\\Publisher', 'publish_due']);
    add_action('roxy_social_publish_single', ['\\RoxySocial\\Publisher', 'process_queued']);
    if (!wp_next_scheduled('roxy_social_publish_due')) wp_schedule_event(time() + 300, 'roxy_five_minutes', 'roxy_social_publish_due');
});

function roxy_social_install_schema(): void {
    \RoxySocial\Store::install_schema();
}
