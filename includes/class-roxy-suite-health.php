<?php
namespace RoxySuite;

if (!defined('ABSPATH')) exit;

/**
 * Roxy Suite Health Check
 *
 * run_structural() — lightweight checks run on page load (tables, CPTs, options, plugin deps)
 * run_full()       — full suite including live data queries and Sling HTTP ping; called via AJAX
 *
 * Each module card includes an enable/disable toggle (except Core).
 * Disabled modules show a grey badge and skip all health checks.
 */
class Health {

    const PASS = 'pass';
    const WARN = 'warn';
    const FAIL = 'fail';

    // ── AJAX handler ────────────────────────────────────────────────────────────

    public static function ajax_run_tests(): void {
        check_ajax_referer('roxy_suite_run_tests', 'nonce');
        if (!roxy_suite_user_can_access_admin()) {
            wp_send_json_error('Insufficient permissions', 403);
        }

        $start   = microtime(true);
        $modules = self::run_full();
        $elapsed = round((microtime(true) - $start) * 1000);

        wp_send_json_success([
            'modules'    => $modules,
            'elapsed_ms' => $elapsed,
        ]);
    }

    // ── Public entry points ─────────────────────────────────────────────────────

    /** Structural checks only — fast, safe for page load. */
    public static function run_structural(): array {
        return [
            self::module_core_structural(),
            self::module_show_tickets_structural(),
            self::module_will_call_structural(),
            self::module_member_check_structural(),
            self::module_event_booking_structural(),
            self::module_arcade_structural(),
            self::module_grosses_structural(),
        ];
    }

    /** Full suite — structural + live data/network checks. Run on demand only. */
    public static function run_full(): array {
        $modules = self::run_structural();

        // Merge functional items into each module by key (name), skip disabled modules
        $functional = [
            'Core / Environment' => self::functional_core(),
            'Show Tickets'       => self::functional_show_tickets(),
            'Event Booking'      => self::functional_event_booking(),
            'Will Call'          => self::functional_will_call(),
            'Arcade'             => self::functional_arcade(),
            'Grosses'            => self::functional_grosses(),
        ];

        foreach ($modules as &$mod) {
            // Skip functional checks for disabled modules
            if (!($mod['enabled'] ?? true)) continue;

            $extra = $functional[$mod['name']] ?? [];
            if ($extra) {
                $mod['items'] = array_merge($mod['items'], $extra);
                // Recompute overall
                $mod['overall'] = self::PASS;
                foreach ($mod['items'] as $item) {
                    if ($item['status'] === self::FAIL) { $mod['overall'] = self::FAIL; break; }
                    if ($item['status'] === self::WARN)   $mod['overall'] = self::WARN;
                }
            }
        }
        unset($mod);

        return $modules;
    }

    // ── Structural module checks ────────────────────────────────────────────────

    private static function module_core_structural(): array {
        $php_ok = version_compare(PHP_VERSION, '7.4', '>=');
        $wp_ok  = version_compare(get_bloginfo('version'), '6.0', '>=');
        $wc_ok  = class_exists('WooCommerce');
        $as_ok  = class_exists('ActionScheduler') || function_exists('as_enqueue_async_action');

        // Core has no toggle key — it is always enabled
        return self::module('Core / Environment', null, [
            self::item('PHP version', PHP_VERSION,
                $php_ok ? self::PASS : self::FAIL, $php_ok ? '' : 'Requires PHP 7.4+'),
            self::item('WordPress version', get_bloginfo('version'),
                $wp_ok ? self::PASS : self::WARN, $wp_ok ? '' : 'Recommend WP 6.0+'),
            self::item('WooCommerce', $wc_ok ? 'Active' : 'Not found',
                $wc_ok ? self::PASS : self::FAIL, $wc_ok ? '' : 'WooCommerce is required'),
            self::item('Action Scheduler', $as_ok ? 'Available' : 'Not found',
                $as_ok ? self::PASS : self::WARN, $as_ok ? '' : 'Required by Event Booking'),
        ]);
    }

    private static function module_show_tickets_structural(): array {
        if (!self::module_enabled('show_tickets')) {
            return self::module('Show Tickets', admin_url('admin.php?page=roxy-show-tickets'), [], 'show_tickets');
        }

        $cpt_showing  = post_type_exists('roxy_showing');
        $cpt_ticket   = post_type_exists('roxy_ticket');
        $has_settings = get_option('roxy_st_settings') !== false;

        return self::module('Show Tickets', admin_url('admin.php?page=roxy-show-tickets'), [
            self::item('roxy_showing CPT', $cpt_showing ? 'Registered' : 'Missing',
                $cpt_showing ? self::PASS : self::FAIL),
            self::item('roxy_ticket CPT', $cpt_ticket ? 'Registered' : 'Missing',
                $cpt_ticket ? self::PASS : self::FAIL),
            self::item('Settings', $has_settings ? 'Saved' : 'Using defaults', self::PASS),
        ], 'show_tickets');
    }

    private static function module_will_call_structural(): array {
        if (!self::module_enabled('will_call')) {
            return self::module('Will Call', admin_url('admin.php?page=roxy-will-call'), [], 'will_call');
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'roxy_will_call_checkins';
        $exists = self::table_exists($table);

        return self::module('Will Call', admin_url('admin.php?page=roxy-will-call'), [
            self::item($table, $exists ? 'Exists' : 'Missing',
                $exists ? self::PASS : self::FAIL,
                $exists ? '' : 'Reactivate plugin to rebuild'),
        ], 'will_call');
    }

    private static function module_member_check_structural(): array {
        if (!self::module_enabled('sub_check')) {
            return self::module('Member Check', admin_url('admin.php?page=roxy-scan-log'), [], 'sub_check');
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'roxy_member_scans';
        $t_ok   = self::table_exists($table);

        // /member-check/ is a custom rewrite rule, not a real WP page
        $rules   = get_option('rewrite_rules', []);
        $rule_ok = is_array($rules) && isset($rules['^member-check/?$']);

        $wcs = class_exists('WC_Subscriptions') || class_exists('WC_Subscriptions_Manager');

        return self::module('Member Check', admin_url('admin.php?page=roxy-scan-log'), [
            self::item($table, $t_ok ? 'Exists' : 'Missing',
                $t_ok ? self::PASS : self::FAIL),
            self::item('/member-check/ rewrite rule', $rule_ok ? 'Active' : 'Missing',
                $rule_ok ? self::PASS : self::FAIL,
                $rule_ok ? '' : 'Go to Settings → Permalinks and click Save Changes to flush rewrite rules'),
            self::item('WooCommerce Subscriptions', $wcs ? 'Active' : 'Not found',
                $wcs ? self::PASS : self::WARN,
                $wcs ? '' : 'Required for subscription lookups'),
        ], 'sub_check');
    }

    private static function module_event_booking_structural(): array {
        if (!self::module_enabled('event_booking')) {
            return self::module('Event Booking', admin_url('admin.php?page=roxy-event-booking'), [], 'event_booking');
        }

        global $wpdb;
        $t_b  = $wpdb->prefix . 'roxy_event_bookings';
        $t_bl = $wpdb->prefix . 'roxy_event_blocks';
        $t_l  = $wpdb->prefix . 'roxy_event_sling_logs';

        $settings    = function_exists('roxy_eb_get_settings') ? roxy_eb_get_settings() : [];
        $sling_mode  = $settings['sling_mode'] ?? 'disabled';
        $product_id  = (int) ($settings['booking_product_id'] ?? 0);
        $product_ok  = $product_id > 0 && get_post($product_id) instanceof \WP_Post;

        return self::module('Event Booking', admin_url('admin.php?page=roxy-event-booking'), [
            self::item($t_b,  self::table_exists($t_b)  ? 'Exists' : 'Missing', self::table_exists($t_b)  ? self::PASS : self::FAIL),
            self::item($t_bl, self::table_exists($t_bl) ? 'Exists' : 'Missing', self::table_exists($t_bl) ? self::PASS : self::FAIL),
            self::item($t_l,  self::table_exists($t_l)  ? 'Exists' : 'Missing', self::table_exists($t_l)  ? self::PASS : self::FAIL),
            self::item('Sling mode', ucfirst($sling_mode),
                $sling_mode === 'disabled' ? self::WARN : self::PASS,
                $sling_mode === 'disabled' ? 'Sling scheduling is off' : ''),
            self::item('Booking product', $product_ok ? "ID $product_id" : ($product_id > 0 ? "ID $product_id (not found)" : 'Not set'),
                $product_ok ? self::PASS : self::WARN,
                $product_ok ? '' : 'Set a booking product ID in EB Settings'),
        ], 'event_booking');
    }

    private static function module_arcade_structural(): array {
        if (!self::module_enabled('arcade')) {
            return self::module('Arcade', admin_url('admin.php?page=roxy-arcade-settings'), [], 'arcade');
        }

        global $wpdb;
        $table           = $wpdb->prefix . 'roxy_arcade_scores';
        $exists          = self::table_exists($table);
        $rewards_enabled = (bool) get_option('roxy_arcade_rewards_enabled', 0);
        $cron            = (bool) wp_next_scheduled('roxy_arcade_monthly_award');

        return self::module('Arcade', admin_url('admin.php?page=roxy-arcade-settings'), [
            self::item($table, $exists ? 'Exists' : 'Missing',
                $exists ? self::PASS : self::FAIL),
            self::item('Monthly award cron',
                $cron ? 'Scheduled' : ($rewards_enabled ? 'Not scheduled' : 'Not needed (rewards off)'),
                (!$cron && $rewards_enabled) ? self::WARN : self::PASS,
                (!$cron && $rewards_enabled) ? 'Reactivate plugin to reschedule' : ''),
        ], 'arcade');
    }

    private static function module_grosses_structural(): array {
        if (!self::module_enabled('grosses')) {
            return self::module('Grosses', admin_url('admin.php?page=roxy-grosses'), [], 'grosses');
        }

        global $wpdb;
        $t_reports = $wpdb->prefix . 'roxy_grosses_reports';
        $t_logs    = $wpdb->prefix . 'roxy_grosses_logs';
        $t_r_ok    = self::table_exists($t_reports);
        $t_l_ok    = self::table_exists($t_logs);

        $settings       = get_option('roxy_grosses_settings', []);
        $has_token      = !empty($settings['square_access_token']);
        $sched_enabled  = ($settings['schedule_enabled'] ?? '0') === '1';

        return self::module('Grosses', admin_url('admin.php?page=roxy-grosses'), [
            self::item($t_reports, $t_r_ok ? 'Exists' : 'Missing',
                $t_r_ok ? self::PASS : self::FAIL,
                $t_r_ok ? '' : 'Reactivate plugin to create table'),
            self::item($t_logs, $t_l_ok ? 'Exists' : 'Missing',
                $t_l_ok ? self::PASS : self::FAIL,
                $t_l_ok ? '' : 'Reactivate plugin to create table'),
            self::item('Square access token', $has_token ? 'Configured' : 'Not set',
                $has_token ? self::PASS : self::WARN,
                $has_token ? '' : 'Add a Square token in Grosses → Settings'),
            self::item('Auto-send schedule', $sched_enabled ? 'Enabled (daily at configured time)' : 'Disabled',
                $sched_enabled ? self::PASS : self::WARN,
                $sched_enabled ? '' : 'Auto-send is off — configure in Grosses → Settings'),
        ], 'grosses');
    }

    // ── Functional checks (on-demand only) ─────────────────────────────────────

    private static function functional_core(): array {
        $items = [];

        if (class_exists('WooCommerce') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            $enabled  = array_filter($gateways, fn($g) => $g->enabled === 'yes');
            $count    = count($enabled);
            $names    = implode(', ', array_map(fn($g) => $g->get_title(), $enabled));
            $items[]  = self::item(
                'WC payment gateways',
                $count > 0 ? "$count enabled ($names)" : '0 enabled',
                $count > 0 ? self::PASS : self::FAIL,
                $count > 0 ? '' : 'No payment gateways active — ticket purchases will fail'
            );

            $checkout_id = wc_get_page_id('checkout');
            $items[] = self::item(
                'WC checkout page',
                $checkout_id > 0 ? "ID $checkout_id" : 'Not set',
                $checkout_id > 0 ? self::PASS : self::WARN,
                $checkout_id > 0 ? '' : 'WooCommerce checkout page is not configured'
            );
        }

        return $items;
    }

    private static function functional_show_tickets(): array {
        if (!self::module_enabled('show_tickets')) return [];

        $items = [];

        $now  = date('Y-m-d\TH:i', current_time('timestamp'));
        $upcoming = get_posts([
            'post_type'      => 'roxy_showing',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'meta_key'       => '_roxy_start',
            'meta_value'     => $now,
            'meta_compare'   => '>=',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        ]);

        $count   = count($upcoming);
        $items[] = self::item(
            'Upcoming shows',
            $count > 0 ? "$count published" : 'None found',
            $count > 0 ? self::PASS : self::WARN,
            $count > 0 ? '' : 'No upcoming shows are published'
        );

        if ($count > 0) {
            $next_id      = (int) $upcoming[0]->ID;
            $next_title   = get_the_title($next_id);
            $has_product  = (bool) get_post_meta($next_id, '_roxy_pid_adult', true);

            $items[] = self::item(
                'Next show has ticket products',
                $has_product ? "Yes ($next_title)" : "No — $next_title",
                $has_product ? self::PASS : self::FAIL,
                $has_product ? '' : 'Save the showing to auto-generate ticket products'
            );

            if ($has_product && class_exists('\RoxyST\Sales')) {
                $sold    = \RoxyST\Sales::sold_qty_for_showing($next_id);
                $items[] = self::item(
                    'Tickets sold (next show)',
                    "$sold sold — $next_title",
                    self::PASS
                );
            }
        }

        return $items;
    }

    private static function functional_event_booking(): array {
        if (!self::module_enabled('event_booking')) return [];

        global $wpdb;
        $items = [];

        if (!function_exists('roxy_eb_table_bookings')) return $items;

        $table = roxy_eb_table_bookings();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE status IN ('confirmed','pending','pending_invoice') AND doors_open_at >= %s",
            current_time('mysql')
        ));

        $items[] = self::item(
            'Upcoming confirmed bookings',
            $count > 0 ? "$count booking(s)" : 'None',
            $count > 0 ? self::PASS : self::WARN,
            $count > 0 ? '' : 'No upcoming bookings found (may be off-season)'
        );

        // Live Sling ping — only for direct API mode
        if (function_exists('roxy_eb_get_settings') && function_exists('roxy_eb_sling_admin_test_and_resolve')) {
            $settings   = roxy_eb_get_settings();
            $sling_mode = $settings['sling_mode'] ?? 'disabled';

            if ($sling_mode === 'direct') {
                $result  = roxy_eb_sling_admin_test_and_resolve($settings);
                $ok      = !is_wp_error($result);
                $detail  = $ok ? ($result['message'] ?? 'Connected') : $result->get_error_message();
                $items[] = self::item(
                    'Sling API live ping',
                    $detail,
                    $ok ? self::PASS : self::FAIL,
                    $ok ? '' : 'Check Sling token in Event Booking → Settings'
                );
            } elseif ($sling_mode === 'webhook') {
                $items[] = self::item('Sling API', 'Webhook mode — no live ping needed', self::PASS);
            } else {
                $items[] = self::item('Sling API', 'Disabled', self::WARN, 'Sling scheduling is off');
            }
        }

        return $items;
    }

    private static function functional_will_call(): array {
        if (!self::module_enabled('will_call')) return [];

        global $wpdb;
        $table = $wpdb->prefix . 'roxy_will_call_checkins';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        return [
            self::item('Check-in records', "$count total rows", self::PASS),
        ];
    }

    private static function functional_arcade(): array {
        if (!self::module_enabled('arcade')) return [];

        global $wpdb;
        $table = $wpdb->prefix . 'roxy_arcade_scores';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        return [
            self::item('Score records', "$count total", self::PASS),
        ];
    }

    private static function functional_grosses(): array {
        if (!self::module_enabled('grosses')) return [];

        global $wpdb;
        $t_reports = $wpdb->prefix . 'roxy_grosses_reports';

        if (!self::table_exists($t_reports)) {
            return [self::item('Report table', 'Missing — run activation', self::FAIL)];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$t_reports}`");

        $status      = get_option('roxy_grosses_last_report', []);
        $last_date   = is_array($status) && !empty($status['report_date']) ? $status['report_date'] : 'Never';
        $last_mode   = is_array($status) && !empty($status['mode']) ? $status['mode'] : '';

        return [
            self::item('Saved reports', "$count total", self::PASS),
            self::item('Last report', $last_date . ($last_mode ? " ($last_mode)" : ''), self::PASS),
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private static function module_enabled(string $key): bool {
        return roxy_suite_module_enabled($key);
    }

    private static function table_exists(string $table): bool {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function item(string $label, string $detail, string $status, string $note = ''): array {
        return compact('label', 'detail', 'status', 'note');
    }

    /**
     * Build a module data struct.
     *
     * @param string      $name   Display name (also used as key in run_full() functional map)
     * @param string|null $link   Optional admin URL for the module settings page
     * @param array       $items  Health check items (empty array for disabled modules)
     * @param string      $key    Module key for toggle ('' = not toggleable, e.g. Core)
     */
    private static function module(string $name, ?string $link, array $items, string $key = ''): array {
        $enabled = $key === '' || self::module_enabled($key);

        if (!$enabled) {
            return [
                'name'    => $name,
                'link'    => $link,
                'items'   => [],
                'overall' => 'disabled',
                'key'     => $key,
                'enabled' => false,
            ];
        }

        $overall = self::PASS;
        foreach ($items as $item) {
            if ($item['status'] === self::FAIL) { $overall = self::FAIL; break; }
            if ($item['status'] === self::WARN)   $overall = self::WARN;
        }

        return [
            'name'    => $name,
            'link'    => $link,
            'items'   => $items,
            'overall' => $overall,
            'key'     => $key,
            'enabled' => true,
        ];
    }

    // ── Render (Dashboard page) ──────────────────────────────────────────────────

    public static function render(): void {
        $modules = self::run_structural();

        if (function_exists('roxy_suite_module_enabled') && roxy_suite_module_enabled('grosses') && class_exists('\\RoxyGrosses\\Settings')) {
            \RoxyGrosses\Settings::render_dashboard_panel('roxy-suite');
        }

        $enabled_modules = array_filter($modules, fn($m) => $m['enabled'] ?? true);
        $pass_count = count(array_filter($enabled_modules, fn($m) => $m['overall'] === self::PASS));
        $total      = count($enabled_modules);
        $bar_color  = $pass_count === $total ? '#00a32a' : ($pass_count >= $total / 2 ? '#dba617' : '#d63638');
        $nonce      = wp_create_nonce('roxy_suite_run_tests');
        $tog_nonce  = wp_create_nonce('roxy_suite_toggle_module');
        $ajax_url   = admin_url('admin-ajax.php');
        ?>
        <style>
        .rs-health-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-top:16px}
        .rs-health-card{background:#fff;border:1px solid #dcdcde;border-radius:4px;overflow:hidden}
        .rs-health-card.rs-disabled{opacity:.75}
        .rs-health-card-header{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #dcdcde;background:#f9f9f9}
        .rs-health-card-header h3{margin:0;font-size:14px}
        .rs-health-card-header a{text-decoration:none;color:inherit}
        .rs-health-card-header a:hover{text-decoration:underline}
        .rs-header-right{display:flex;align-items:center;gap:8px}
        .rs-health-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0}
        .rs-health-dot.pass{background:#00a32a}.rs-health-dot.warn{background:#dba617}.rs-health-dot.fail{background:#d63638}.rs-health-dot.disabled{background:#8c8f94}
        .rs-disabled-badge{font-size:11px;font-weight:600;color:#8c8f94;text-transform:uppercase;letter-spacing:.5px}
        /* Toggle switch */
        .rs-module-toggle{position:relative;display:inline-block;width:36px;height:20px;flex-shrink:0}
        .rs-module-toggle input{opacity:0;width:0;height:0;position:absolute}
        .rs-toggle-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#ccc;border-radius:20px;transition:.2s}
        .rs-toggle-slider:before{position:absolute;content:"";height:14px;width:14px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s}
        .rs-module-toggle input:checked + .rs-toggle-slider{background:#00a32a}
        .rs-module-toggle input:checked + .rs-toggle-slider:before{transform:translateX(16px)}
        .rs-module-toggle input:disabled + .rs-toggle-slider{cursor:not-allowed;opacity:.6}
        .rs-health-table{width:100%;border-collapse:collapse;font-size:13px}
        .rs-health-table td{padding:8px 16px;border-bottom:1px solid #f0f0f0;vertical-align:top}
        .rs-health-table tr:last-child td{border-bottom:none}
        .rs-label{color:#3c434a;max-width:200px;word-break:break-all}
        .rs-detail{color:#646970;font-size:12px}
        .rs-note{color:#8c5309;font-size:11px;margin-top:2px}
        .rs-health-icon{font-size:16px;line-height:1}
        .rs-health-bar{display:flex;align-items:center;gap:12px;padding:12px 16px;background:<?php echo esc_attr($bar_color); ?>;color:#fff;border-radius:4px;margin-bottom:4px}
        .rs-health-bar strong{font-size:15px}
        .rs-health-bar span{opacity:.85;font-size:13px}
        .rs-health-actions{display:flex;align-items:center;gap:12px;margin-top:16px}
        .rs-health-timestamp{font-size:12px;color:#646970}
        #rs-run-tests.rs-loading{opacity:.6;pointer-events:none}
        </style>

        <div class="rs-health-bar">
            <strong id="rs-summary-text"><?php echo esc_html("$pass_count / $total modules operational (structural)"); ?></strong>
            <span>Roxy Suite v<?php echo esc_html(ROXY_SUITE_VERSION); ?></span>
        </div>

        <div class="rs-health-actions">
            <button id="rs-run-tests" class="button button-primary">&#9654; Run Full Tests</button>
            <span class="rs-health-timestamp" id="rs-timestamp"></span>
        </div>

        <div class="rs-health-grid" id="rs-health-grid"
             data-ajax-url="<?php echo esc_attr($ajax_url); ?>"
             data-toggle-nonce="<?php echo esc_attr($tog_nonce); ?>">
            <?php self::render_cards($modules); ?>
        </div>

        <script>
        (function(){
            const btn        = document.getElementById('rs-run-tests');
            const grid       = document.getElementById('rs-health-grid');
            const summary    = document.getElementById('rs-summary-text');
            const timestamp  = document.getElementById('rs-timestamp');
            const ajaxUrl    = grid.dataset.ajaxUrl;
            const togNonce   = grid.dataset.toggleNonce;
            const testNonce  = <?php echo wp_json_encode($nonce); ?>;

            // ── Module toggle ─────────────────────────────────────────────────
            grid.addEventListener('change', function(e) {
                const input = e.target;
                if (!input.classList.contains('rs-toggle-input')) return;

                const module  = input.dataset.module;
                const enabled = input.checked ? '1' : '0';
                input.disabled = true;

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'roxy_suite_toggle_module',
                        nonce:  togNonce,
                        module: module,
                        enabled: enabled,
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Reload so menus + health checks reflect the new state
                        window.location.reload();
                    } else {
                        alert('Toggle failed: ' + (data.data || 'Unknown error'));
                        input.checked  = !input.checked;
                        input.disabled = false;
                    }
                })
                .catch(err => {
                    alert('Request failed: ' + err.message);
                    input.checked  = !input.checked;
                    input.disabled = false;
                });
            });

            // ── Full test run ─────────────────────────────────────────────────
            btn.addEventListener('click', function() {
                btn.textContent = '⏳ Running tests…';
                btn.classList.add('rs-loading');

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'roxy_suite_run_tests',
                        nonce:  testNonce,
                    })
                })
                .then(r => r.json())
                .then(data => {
                    btn.textContent = '▶ Run Full Tests';
                    btn.classList.remove('rs-loading');

                    if (!data.success) {
                        alert('Test run failed: ' + (data.data || 'Unknown error'));
                        return;
                    }

                    const modules  = data.data.modules;
                    const elapsed  = data.data.elapsed_ms;
                    const active   = modules.filter(m => m.enabled !== false);
                    const passCount = active.filter(m => m.overall === 'pass').length;

                    summary.textContent = passCount + ' / ' + active.length + ' modules fully operational (full test)';

                    const bar = summary.closest('.rs-health-bar');
                    bar.style.background = passCount === active.length ? '#00a32a'
                        : passCount >= active.length / 2 ? '#dba617' : '#d63638';

                    timestamp.textContent = 'Completed in ' + elapsed + ' ms — ' + new Date().toLocaleTimeString();

                    grid.innerHTML = modules.map(mod => renderCard(mod)).join('');
                })
                .catch(err => {
                    btn.textContent = '▶ Run Full Tests';
                    btn.classList.remove('rs-loading');
                    alert('Request failed: ' + err.message);
                });
            });

            function icon(status) {
                return status === 'pass' ? '✅' : status === 'warn' ? '⚠️' : '❌';
            }

            function esc(str) {
                const d = document.createElement('div');
                d.textContent = String(str);
                return d.innerHTML;
            }

            function renderCard(mod) {
                const isEnabled = mod.enabled !== false;
                const overall   = mod.overall || 'pass';

                const nameHtml = mod.link
                    ? `<a href="${esc(mod.link)}">${esc(mod.name)} →</a>`
                    : esc(mod.name);

                const dotHtml = isEnabled
                    ? `<span class="rs-health-dot ${esc(overall)}"></span>`
                    : `<span class="rs-disabled-badge">Disabled</span>`;

                const toggleHtml = mod.key
                    ? `<label class="rs-module-toggle" title="${isEnabled ? 'Disable module' : 'Enable module'}">
                           <input type="checkbox" class="rs-toggle-input"
                               data-module="${esc(mod.key)}"
                               ${isEnabled ? 'checked' : ''}>
                           <span class="rs-toggle-slider"></span>
                       </label>`
                    : '';

                let bodyHtml = '';
                if (!isEnabled) {
                    bodyHtml = `<table class="rs-health-table"><tbody>
                        <tr><td colspan="3" style="color:#8c8f94;font-style:italic;padding:12px 16px;">
                            Module disabled — use the toggle to re-enable.
                        </td></tr></tbody></table>`;
                } else {
                    const rows = mod.items.map(item => `
                        <tr>
                            <td style="width:24px;padding-right:4px"><span class="rs-health-icon">${icon(item.status)}</span></td>
                            <td class="rs-label">${esc(item.label)}${item.note ? `<div class="rs-note">${esc(item.note)}</div>` : ''}</td>
                            <td class="rs-detail">${esc(item.detail)}</td>
                        </tr>`).join('');
                    bodyHtml = `<table class="rs-health-table"><tbody>${rows}</tbody></table>`;
                }

                return `
                    <div class="rs-health-card${isEnabled ? '' : ' rs-disabled'}">
                        <div class="rs-health-card-header">
                            <h3>${nameHtml}</h3>
                            <div class="rs-header-right">${dotHtml}${toggleHtml}</div>
                        </div>
                        ${bodyHtml}
                    </div>`;
            }
        })();
        </script>
        <?php
    }

    private static function render_cards(array $modules): void {
        foreach ($modules as $mod):
            $is_enabled = $mod['enabled'] ?? true;
            $overall    = $mod['overall'] ?? self::PASS;
        ?>
            <div class="rs-health-card<?php echo $is_enabled ? '' : ' rs-disabled'; ?>">
                <div class="rs-health-card-header">
                    <h3>
                        <?php if ($mod['link']): ?>
                            <a href="<?php echo esc_url($mod['link']); ?>"><?php echo esc_html($mod['name']); ?> →</a>
                        <?php else: ?>
                            <?php echo esc_html($mod['name']); ?>
                        <?php endif; ?>
                    </h3>
                    <div class="rs-header-right">
                        <?php if ($is_enabled): ?>
                            <span class="rs-health-dot <?php echo esc_attr($overall); ?>"></span>
                        <?php else: ?>
                            <span class="rs-disabled-badge">Disabled</span>
                        <?php endif; ?>
                        <?php if ($mod['key'] !== ''): ?>
                            <label class="rs-module-toggle" title="<?php echo $is_enabled ? 'Disable module' : 'Enable module'; ?>">
                                <input type="checkbox" class="rs-toggle-input"
                                    data-module="<?php echo esc_attr($mod['key']); ?>"
                                    <?php echo $is_enabled ? 'checked' : ''; ?>>
                                <span class="rs-toggle-slider"></span>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!$is_enabled): ?>
                    <table class="rs-health-table"><tbody>
                        <tr><td colspan="3" style="color:#8c8f94;font-style:italic;padding:12px 16px;">
                            Module disabled — use the toggle to re-enable.
                        </td></tr>
                    </tbody></table>
                <?php else: ?>
                    <table class="rs-health-table">
                        <?php foreach ($mod['items'] as $item): ?>
                        <tr>
                            <td style="width:24px;padding-right:4px">
                                <span class="rs-health-icon"><?php
                                    echo match($item['status']) {
                                        self::PASS => '✅', self::WARN => '⚠️', default => '❌'
                                    };
                                ?></span>
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
                <?php endif; ?>
            </div>
        <?php endforeach;
    }
}
