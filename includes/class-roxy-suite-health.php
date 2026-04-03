<?php
namespace RoxySuite;

if (!defined('ABSPATH')) exit;

/**
 * Roxy Suite Health Check
 * Runs lightweight diagnostic checks for every module and returns structured results.
 */
class Health {

    const PASS = 'pass';
    const WARN = 'warn';
    const FAIL = 'fail';

    // ── Public entry point ──────────────────────────────────────────────────────

    public static function run(): array {
        return [
            self::module_core(),
            self::module_show_tickets(),
            self::module_will_call(),
            self::module_member_check(),
            self::module_event_booking(),
            self::module_arcade(),
        ];
    }

    // ── Modules ─────────────────────────────────────────────────────────────────

    private static function module_core(): array {
        $php_ok = version_compare(PHP_VERSION, '7.4', '>=');
        $wp_ok  = version_compare(get_bloginfo('version'), '6.0', '>=');
        $wc_ok  = class_exists('WooCommerce');
        $as_ok  = class_exists('ActionScheduler') || function_exists('as_enqueue_async_action');

        return self::module('Core / Environment', null, [
            self::item('PHP version', PHP_VERSION, $php_ok ? self::PASS : self::FAIL,
                $php_ok ? '' : 'Requires PHP 7.4+'),
            self::item('WordPress version', get_bloginfo('version'), $wp_ok ? self::PASS : self::WARN,
                $wp_ok ? '' : 'Recommend WP 6.0+'),
            self::item('WooCommerce', $wc_ok ? 'Active' : 'Not found', $wc_ok ? self::PASS : self::FAIL,
                $wc_ok ? '' : 'WooCommerce is required by multiple modules'),
            self::item('Action Scheduler', $as_ok ? 'Available' : 'Not found', $as_ok ? self::PASS : self::WARN,
                $as_ok ? '' : 'Required by Event Booking for Sling sync'),
        ]);
    }

    private static function module_show_tickets(): array {
        $cpt_showing = post_type_exists('roxy_showing');
        $cpt_ticket  = post_type_exists('roxy_ticket');
        $settings    = get_option('roxy_st_settings');
        $has_settings = $settings !== false;

        return self::module('Show Tickets', admin_url('admin.php?page=roxy-show-tickets'), [
            self::item('roxy_showing CPT', $cpt_showing ? 'Registered' : 'Missing',
                $cpt_showing ? self::PASS : self::FAIL),
            self::item('roxy_ticket CPT', $cpt_ticket ? 'Registered' : 'Missing',
                $cpt_ticket ? self::PASS : self::FAIL),
            self::item('Settings', $has_settings ? 'Saved' : 'Using defaults',
                self::PASS),
        ]);
    }

    private static function module_will_call(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'roxy_will_call_checkins';
        $exists = self::table_exists($table);

        return self::module('Will Call', admin_url('admin.php?page=roxy-will-call'), [
            self::item($table, $exists ? 'Exists' : 'Missing',
                $exists ? self::PASS : self::FAIL,
                $exists ? '' : 'Run plugin deactivation + reactivation to rebuild'),
        ]);
    }

    private static function module_member_check(): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'roxy_member_scans';
        $exists = self::table_exists($table);

        $page = get_page_by_path('member-check');
        $page_ok = $page instanceof \WP_Post;

        $wcs = class_exists('WC_Subscriptions') || class_exists('WC_Subscriptions_Manager');

        return self::module('Member Check', admin_url('admin.php?page=roxy-scan-log'), [
            self::item($table, $exists ? 'Exists' : 'Missing',
                $exists ? self::PASS : self::FAIL),
            self::item('/member-check/ page', $page_ok ? 'Found' : 'Missing',
                $page_ok ? self::PASS : self::FAIL,
                $page_ok ? '' : 'Create a page with slug "member-check" containing the shortcode'),
            self::item('WooCommerce Subscriptions', $wcs ? 'Active' : 'Not found',
                $wcs ? self::PASS : self::WARN,
                $wcs ? '' : 'Required for subscription status lookups'),
        ]);
    }

    private static function module_event_booking(): array {
        global $wpdb;

        $t_bookings = $wpdb->prefix . 'roxy_event_bookings';
        $t_blocks   = $wpdb->prefix . 'roxy_event_blocks';
        $t_logs     = $wpdb->prefix . 'roxy_event_sling_logs';

        $b_ok = self::table_exists($t_bookings);
        $bl_ok = self::table_exists($t_blocks);
        $l_ok = self::table_exists($t_logs);

        $settings   = function_exists('roxy_eb_get_settings') ? roxy_eb_get_settings() : [];
        $sling_mode = $settings['sling_mode'] ?? 'disabled';
        $sling_label = ucfirst($sling_mode);
        $product_id  = (int) ($settings['booking_product_id'] ?? 0);
        $product_ok  = $product_id > 0 && get_post($product_id) instanceof \WP_Post;

        return self::module('Event Booking', admin_url('admin.php?page=roxy-event-booking'), [
            self::item($t_bookings, $b_ok ? 'Exists' : 'Missing',
                $b_ok ? self::PASS : self::FAIL),
            self::item($t_blocks, $bl_ok ? 'Exists' : 'Missing',
                $bl_ok ? self::PASS : self::FAIL),
            self::item($t_logs, $l_ok ? 'Exists' : 'Missing',
                $l_ok ? self::PASS : self::FAIL),
            self::item('Sling integration', $sling_label,
                $sling_mode === 'disabled' ? self::WARN : self::PASS,
                $sling_mode === 'disabled' ? 'Sling scheduling is off' : ''),
            self::item('Booking product', $product_ok ? "ID $product_id" : ($product_id > 0 ? "ID $product_id (not found)" : 'Not set'),
                $product_ok ? self::PASS : self::WARN,
                $product_ok ? '' : 'Set a booking product ID in EB Settings'),
        ]);
    }

    private static function module_arcade(): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'roxy_arcade_scores';
        $exists = self::table_exists($table);
        $cron   = (bool) wp_next_scheduled('roxy_arcade_monthly_award');
        $rewards_enabled = (bool) get_option('roxy_arcade_rewards_enabled', 0);

        return self::module('Arcade', admin_url('admin.php?page=roxy-arcade-settings'), [
            self::item($table, $exists ? 'Exists' : 'Missing',
                $exists ? self::PASS : self::FAIL),
            self::item('Monthly award cron',
                $cron ? 'Scheduled' : ($rewards_enabled ? 'Not scheduled' : 'Not needed (rewards off)'),
                !$cron && $rewards_enabled ? self::WARN : self::PASS,
                !$cron && $rewards_enabled ? 'Reactivate plugin to reschedule' : ''),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private static function table_exists(string $table): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function item(string $label, string $detail, string $status, string $note = ''): array {
        return compact('label', 'detail', 'status', 'note');
    }

    private static function module(string $name, ?string $link, array $items): array {
        // Module-level rollup: worst individual status wins
        $overall = self::PASS;
        foreach ($items as $item) {
            if ($item['status'] === self::FAIL) { $overall = self::FAIL; break; }
            if ($item['status'] === self::WARN)   $overall = self::WARN;
        }
        return compact('name', 'link', 'items', 'overall');
    }

    // ── Render ───────────────────────────────────────────────────────────────────

    public static function render(): void {
        $modules = self::run();

        $pass_count = count(array_filter($modules, fn($m) => $m['overall'] === self::PASS));
        $total      = count($modules);

        $bar_color = $pass_count === $total ? '#00a32a' : ($pass_count >= $total / 2 ? '#dba617' : '#d63638');
        ?>
        <style>
        .rs-health-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; margin-top: 20px; }
        .rs-health-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; }
        .rs-health-card-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #dcdcde; background: #f9f9f9; }
        .rs-health-card-header h3 { margin: 0; font-size: 14px; }
        .rs-health-card-header a { text-decoration: none; color: inherit; }
        .rs-health-card-header a:hover { text-decoration: underline; }
        .rs-health-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
        .rs-health-dot.pass { background: #00a32a; }
        .rs-health-dot.warn { background: #dba617; }
        .rs-health-dot.fail { background: #d63638; }
        .rs-health-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .rs-health-table td { padding: 8px 16px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        .rs-health-table tr:last-child td { border-bottom: none; }
        .rs-health-table .rs-label { color: #3c434a; max-width: 200px; word-break: break-all; }
        .rs-health-table .rs-detail { color: #646970; font-size: 12px; }
        .rs-health-table .rs-note { color: #8c5309; font-size: 11px; margin-top: 2px; }
        .rs-health-icon { font-size: 16px; line-height: 1; }
        .rs-health-summary { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: <?php echo esc_attr($bar_color); ?>; color: #fff; border-radius: 4px; margin-bottom: 4px; }
        .rs-health-summary strong { font-size: 15px; }
        .rs-health-summary span { opacity: .85; font-size: 13px; }
        </style>

        <div class="rs-health-summary">
            <strong><?php echo esc_html("$pass_count / $total modules fully operational"); ?></strong>
            <span>Roxy Suite v<?php echo esc_html(ROXY_SUITE_VERSION); ?></span>
        </div>

        <div class="rs-health-grid">
        <?php foreach ($modules as $mod): ?>
            <div class="rs-health-card">
                <div class="rs-health-card-header">
                    <h3>
                        <?php if ($mod['link']): ?>
                            <a href="<?php echo esc_url($mod['link']); ?>"><?php echo esc_html($mod['name']); ?> →</a>
                        <?php else: ?>
                            <?php echo esc_html($mod['name']); ?>
                        <?php endif; ?>
                    </h3>
                    <span class="rs-health-dot <?php echo esc_attr($mod['overall']); ?>"></span>
                </div>
                <table class="rs-health-table">
                    <?php foreach ($mod['items'] as $item): ?>
                    <tr>
                        <td style="width:24px;padding-right:4px;">
                            <span class="rs-health-icon">
                                <?php
                                echo match ($item['status']) {
                                    self::PASS => '✅',
                                    self::WARN => '⚠️',
                                    default    => '❌',
                                };
                                ?>
                            </span>
                        </td>
                        <td class="rs-label">
                            <?php echo esc_html($item['label']); ?>
                            <?php if ($item['note'] !== ''): ?>
                                <div class="rs-note"><?php echo esc_html($item['note']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="rs-detail"><?php echo esc_html($item['detail']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endforeach; ?>
        </div>
        <?php
    }
}
