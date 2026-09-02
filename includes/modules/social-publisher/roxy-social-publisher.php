<?php
/**
 * Social Publisher — creates reviewable social post drafts from showings.
 * Publishing integrations are intentionally not enabled in this first milestone.
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/class-roxy-social-store.php';
require_once __DIR__ . '/includes/class-roxy-social-campaigns.php';
require_once __DIR__ . '/includes/class-roxy-social-admin.php';
require_once __DIR__ . '/includes/class-roxy-social-hangar.php';
require_once __DIR__ . '/includes/class-roxy-social-meta.php';

add_action('plugins_loaded', function () {
    if (get_option('roxy_social_schema_version') !== '1.2') {
        \RoxySocial\Store::install_schema();
        update_option('roxy_social_schema_version', '1.2');
    }
    \RoxySocial\Campaigns::init();
    \RoxySocial\Admin::init();
    add_action('roxy_social_cleanup', ['\\RoxySocial\\Store', 'cleanup_expired']);
    if (!wp_next_scheduled('roxy_social_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'roxy_social_cleanup');
});

function roxy_social_install_schema(): void {
    \RoxySocial\Store::install_schema();
}
