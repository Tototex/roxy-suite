<?php
namespace RoxySuite;

if (!defined('ABSPATH')) exit;

class Members_Dashboard {
    private const OPTION_VISIT_COST = 'roxy_suite_member_visit_cost';
    private const OPTION_FIXED_COST = 'roxy_suite_member_fixed_cost';
    private const META_TRADE = '_roxy_member_trade_subscription';

    /**
     * Batch-load scan stats for all subscription IDs in one query.
     * Returns an associative array keyed by subscription_id.
     */
    private static function scan_stats_batch(array $sub_ids): array {
        global $wpdb;
        $table = $wpdb->prefix . 'roxy_member_scans';

        // Confirm the table exists once
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            $empty = ['month' => 0, 'lifetime' => 0, 'last' => ''];
            return array_fill_keys($sub_ids, $empty);
        }

        if (empty($sub_ids)) return [];

        $month_start  = wp_date('Y-m-01 00:00:00');
        $placeholders = implode(',', array_fill(0, count($sub_ids), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    subscription_id,
                    SUM(CASE WHEN scanned_at >= %s THEN 1 ELSE 0 END) AS visits_month,
                    COUNT(*)                                             AS visits_lifetime,
                    MAX(scanned_at)                                      AS last_visit
                 FROM `{$table}`
                 WHERE subscription_id IN ($placeholders)
                 GROUP BY subscription_id",
                array_merge([$month_start], $sub_ids)
            ),
            ARRAY_A
        );

        $indexed = [];
        foreach ((array) $rows as $row) {
            $indexed[(int) $row['subscription_id']] = [
                'month'    => (int) ($row['visits_month'] ?? 0),
                'lifetime' => (int) ($row['visits_lifetime'] ?? 0),
                'last'     => (string) ($row['last_visit'] ?? ''),
            ];
        }

        // Fill in zeros for any subscription_id that had no rows at all
        $empty = ['month' => 0, 'lifetime' => 0, 'last' => ''];
        foreach ($sub_ids as $sid) {
            if (!isset($indexed[$sid])) $indexed[$sid] = $empty;
        }

        return $indexed;
    }

    public static function render(): void {
        if (!roxy_suite_user_can_access_admin()) return;

        // ── CSV export ─────────────────────────────────────────────────────────
        if (!empty($_GET['roxy_members_export']) && current_user_can('manage_options')) {
            check_admin_referer('roxy_members_export');
            $filters  = self::filters();
            $snapshot = self::snapshot($filters);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="roxy-members-' . date('Y-m-d') . '.csv"');
            header('Pragma: no-cache');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Email', 'Subscription ID', 'Products', 'Status', 'Subs', 'Monthly Revenue', 'Visits This Month', 'Lifetime Visits', 'Last Visit', 'Next Payment', 'Trade/Comp']);
            foreach ($snapshot['rows'] as $row) {
                fputcsv($out, [
                    $row['name'],
                    $row['email'],
                    $row['subscription_id'],
                    $row['products'],
                    $row['status'],
                    $row['subs'],
                    number_format((float) $row['monthly_revenue'], 2, '.', ''),
                    $row['visits_month'],
                    $row['visits_lifetime'],
                    $row['last_visit'],
                    $row['next_payment'],
                    $row['trade'] ? 'Yes' : 'No',
                ]);
            }
            fclose($out);
            exit;
        }

        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        self::handle_member_actions();
        self::handle_settings_update();

        $filters = self::filters();
        $snapshot = self::snapshot($filters);
        $visit_cost = self::visit_cost();
        $fixed_cost = self::fixed_cost();
        $scan_log_url = admin_url('admin.php?page=roxy-ticket-ops&tab=member-check-log');
        $subs_url = admin_url('admin.php?page=wc-orders--shop_subscription');
        $dashboard_url = admin_url('admin.php?page=roxy-suite&tab=membership');
        ?>
        <style>
        .rs-members-panel{background:#fff;border:1px solid #dcdcde;border-radius:4px;margin:16px 0;padding:16px}
        .rs-members-panel h2{margin:0 0 6px}
        .rs-members-panel p{max-width:920px}
        .rs-members-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:14px 0 18px}
        .rs-members-kpi{border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7;padding:12px}
        .rs-members-kpi-label{color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
        .rs-members-kpi-value{font-size:24px;font-weight:700;margin-top:5px}
        .rs-members-kpi-note{font-size:12px;color:#646970;margin-top:5px}
        .rs-members-toolbar{display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin:12px 0 16px}
        .rs-members-toolbar label{display:block;font-weight:600;margin-bottom:4px}
        .rs-members-toolbar input[type=number]{width:120px}
        .rs-members-toolbar input[type=search]{width:280px}
        .rs-members-photo{width:48px;height:48px;border-radius:10px;object-fit:cover;border:1px solid #dcdcde;background:#f6f7f7}
        .rs-members-photo-empty{width:48px;height:48px;border-radius:10px;border:1px dashed #a7aaad;color:#646970;display:flex;align-items:center;justify-content:center;font-size:11px;background:#f6f7f7;cursor:pointer}
        .rs-members-photo-button{border:0;background:transparent;padding:0;cursor:pointer}
        .rs-members-photo-button:focus .rs-members-photo,.rs-members-photo-button:focus .rs-members-photo-empty{box-shadow:0 0 0 2px #2271b1}
        .rs-members-name{font-weight:700}
        .rs-members-subtle{color:#646970;font-size:12px}
        .rs-members-money{white-space:nowrap}
        .rs-members-badge{display:inline-block;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;background:#f0f0f1;color:#3c434a;margin-top:4px}
        .rs-members-badge-trade{background:#fcf0c8;color:#8a5a00}
        .rs-members-row-trade{opacity:.72}
        .rs-members-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
        </style>

        <section class="rs-members-panel">
            <h2>Membership Dashboard</h2>
            <p>Counts subscription quantity, not just Woo subscription records. If one active subscription order contains four memberships, this dashboard counts four active subs.</p>

            <?php if (!empty($snapshot['notice'])): ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html($snapshot['notice']); ?></p></div>
            <?php endif; ?>

            <div class="rs-members-kpis">
                <?php self::kpi('Active Subs', number_format_i18n($snapshot['active_subs']), 'Quantity across counted active and pending-cancel subscriptions.'); ?>
                <?php self::kpi('Woo Subscription Records', number_format_i18n($snapshot['active_subscriptions']), 'Counted Woo subscription records.'); ?>
                <?php self::kpi('Monthly Revenue', self::money($snapshot['mrr']), 'Normalized to monthly recurring revenue.'); ?>
                <?php self::kpi('Visits This Month', number_format_i18n($snapshot['visits_month']), 'From the Roxy member scan log.'); ?>
                <?php self::kpi('Estimated Monthly Cost', self::money($snapshot['estimated_cost']), self::money($visit_cost) . ' per scan + fixed monthly cost.'); ?>
                <?php self::kpi('Estimated Net', self::money($snapshot['estimated_net']), 'Monthly revenue minus estimated visit and fixed costs.'); ?>
                <?php self::kpi('Missing Photos', number_format_i18n($snapshot['missing_photos']), 'Click a missing photo cell to add one.'); ?>
                <?php self::kpi('Trade / Comp Records', number_format_i18n($snapshot['trade_records']), 'Flagged subscriptions excluded from totals.'); ?>
            </div>

            <form method="get" class="rs-members-toolbar">
                <input type="hidden" name="page" value="roxy-suite">
                <input type="hidden" name="tab" value="membership">
                <div>
                    <label for="roxy_member_search">Search</label>
                    <input id="roxy_member_search" name="member_search" type="search" placeholder="name, email, subscription, product" value="<?php echo esc_attr($filters['search']); ?>">
                </div>
                <div>
                    <label for="roxy_member_status">Status</label>
                    <select id="roxy_member_status" name="member_status">
                        <?php foreach (['' => 'Active + pending-cancel', 'active' => 'Active only', 'pending-cancel' => 'Pending-cancel only'] as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['status'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="roxy_member_photo_filter">Photo</label>
                    <select id="roxy_member_photo_filter" name="member_photo">
                        <?php foreach (['' => 'All photos', 'missing' => 'Missing photos', 'has' => 'Has photo'] as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['photo'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="roxy_member_trade_filter">Counting</label>
                    <select id="roxy_member_trade_filter" name="member_trade">
                        <?php foreach (['counted' => 'Counted only', 'all' => 'Include trade/comp', 'trade' => 'Trade/comp only'] as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['trade'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="button button-primary" type="submit">Filter</button>
                <a class="button" href="<?php echo esc_url($dashboard_url); ?>">Clear</a>
            </form>

            <form method="post" class="rs-members-toolbar">
                <?php wp_nonce_field('roxy_suite_members_dashboard_settings', 'roxy_members_nonce'); ?>
                <div>
                    <label for="roxy_member_visit_cost">Cost per visit</label>
                    <input id="roxy_member_visit_cost" name="roxy_member_visit_cost" type="number" min="0" step="0.01" value="<?php echo esc_attr(number_format($visit_cost, 2, '.', '')); ?>">
                </div>
                <div>
                    <label for="roxy_member_fixed_cost">Fixed monthly cost</label>
                    <input id="roxy_member_fixed_cost" name="roxy_member_fixed_cost" type="number" min="0" step="0.01" value="<?php echo esc_attr(number_format($fixed_cost, 2, '.', '')); ?>">
                </div>
                <button class="button button-secondary" type="submit" name="roxy_members_save" value="1">Save estimates</button>
                <a class="button" href="<?php echo esc_url($scan_log_url); ?>">View Scan Log</a>
                <a class="button" href="<?php echo esc_url($subs_url); ?>">Open Woo Subscriptions</a>
                <?php if (current_user_can('manage_options')): ?>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array_merge(['roxy_members_export' => '1'], (array) ($_GET ?? [])), admin_url('admin.php?page=roxy-suite&tab=membership')), 'roxy_members_export')); ?>">Export CSV</a>
                <?php endif; ?>
            </form>

            <form method="post" id="rs-members-action-form" style="display:none;">
                <?php wp_nonce_field('roxy_suite_members_dashboard_action', 'roxy_members_action_nonce'); ?>
                <input type="hidden" name="roxy_members_action" id="rs-members-action" value="">
                <input type="hidden" name="subscription_id" id="rs-members-subscription-id" value="">
                <input type="hidden" name="attachment_id" id="rs-members-attachment-id" value="">
                <input type="hidden" name="trade_value" id="rs-members-trade-value" value="">
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th>Member</th>
                        <th>Subscription</th>
                        <th>Subs</th>
                        <th>Monthly Revenue</th>
                        <th>Visits This Month</th>
                        <th>Lifetime Visits</th>
                        <th>Last Visit</th>
                        <th>Next Payment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($snapshot['rows'])): ?>
                    <tr><td colspan="10">No memberships found for this filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($snapshot['rows'] as $row): ?>
                        <tr class="<?php echo !empty($row['trade']) ? 'rs-members-row-trade' : ''; ?>">
                            <td><?php self::photo_cell($row); ?></td>
                            <td>
                                <div class="rs-members-name"><?php echo esc_html($row['name']); ?></div>
                                <div class="rs-members-subtle"><?php echo esc_html($row['email']); ?></div>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($row['edit_url']); ?>">#<?php echo esc_html((string)$row['subscription_id']); ?></a>
                                <div class="rs-members-subtle"><?php echo esc_html($row['products']); ?></div>
                                <div class="rs-members-subtle"><?php echo esc_html($row['status']); ?></div>
                                <?php if (!empty($row['trade'])): ?>
                                    <span class="rs-members-badge rs-members-badge-trade">Trade / comp</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(number_format_i18n($row['subs'])); ?></td>
                            <td class="rs-members-money"><?php echo esc_html(self::money($row['monthly_revenue'])); ?></td>
                            <td><?php echo esc_html(number_format_i18n($row['visits_month'])); ?></td>
                            <td><?php echo esc_html(number_format_i18n($row['visits_lifetime'])); ?></td>
                            <td><?php echo esc_html($row['last_visit'] ?: '-'); ?></td>
                            <td><?php echo esc_html($row['next_payment'] ?: '-'); ?></td>
                            <td>
                                <div class="rs-members-actions">
                                    <button type="button" class="button button-small rs-members-set-photo" data-sub-id="<?php echo esc_attr((string)$row['subscription_id']); ?>">Photo</button>
                                    <button type="button" class="button button-small rs-members-toggle-trade" data-sub-id="<?php echo esc_attr((string)$row['subscription_id']); ?>" data-trade-value="<?php echo !empty($row['trade']) ? '0' : '1'; ?>">
                                        <?php echo !empty($row['trade']) ? 'Count it' : 'Mark trade'; ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
        <script>
        (function($){
            const form = $('#rs-members-action-form');
            function submitAction(action, subId, attachmentId, tradeValue) {
                $('#rs-members-action').val(action || '');
                $('#rs-members-subscription-id').val(subId || '');
                $('#rs-members-attachment-id').val(attachmentId || '');
                $('#rs-members-trade-value').val(tradeValue || '');
                form.trigger('submit');
            }
            $('.rs-members-set-photo').on('click', function(e){
                e.preventDefault();
                const subId = $(this).data('sub-id');
                if (!window.wp || !wp.media) return;
                const frame = wp.media({
                    title: 'Select member photo',
                    button: { text: 'Use this photo' },
                    multiple: false
                });
                frame.on('select', function(){
                    const attachment = frame.state().get('selection').first().toJSON();
                    submitAction('save_photo', subId, attachment.id, '');
                });
                frame.open();
            });
            $('.rs-members-toggle-trade').on('click', function(e){
                e.preventDefault();
                submitAction('toggle_trade', $(this).data('sub-id'), '', $(this).data('trade-value'));
            });
        })(jQuery);
        </script>
        <?php
    }

    private static function handle_member_actions(): void {
        if (empty($_POST['roxy_members_action'])) return;
        if (empty($_POST['roxy_members_action_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['roxy_members_action_nonce'])), 'roxy_suite_members_dashboard_action')) return;

        $action = sanitize_key((string) wp_unslash($_POST['roxy_members_action']));
        $sub_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;
        if ($sub_id <= 0) return;

        if (function_exists('wcs_get_subscription') && !wcs_get_subscription($sub_id)) {
            return;
        }

        if ($action === 'save_photo') {
            $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
            if ($attachment_id > 0) {
                update_post_meta($sub_id, self::photo_meta_key(), $attachment_id);
                echo '<div class="notice notice-success is-dismissible"><p>Member photo updated.</p></div>';
            }
        } elseif ($action === 'toggle_trade') {
            $trade = !empty($_POST['trade_value']) && (string) wp_unslash($_POST['trade_value']) !== '0';
            update_post_meta($sub_id, self::META_TRADE, $trade ? 1 : 0);
            echo '<div class="notice notice-success is-dismissible"><p>Trade / comp flag updated.</p></div>';
        }
    }

    private static function handle_settings_update(): void {
        if (empty($_POST['roxy_members_save'])) return;
        if (empty($_POST['roxy_members_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['roxy_members_nonce'])), 'roxy_suite_members_dashboard_settings')) return;

        $visit_cost = isset($_POST['roxy_member_visit_cost']) ? max(0, (float) wp_unslash($_POST['roxy_member_visit_cost'])) : 0;
        $fixed_cost = isset($_POST['roxy_member_fixed_cost']) ? max(0, (float) wp_unslash($_POST['roxy_member_fixed_cost'])) : 0;
        update_option(self::OPTION_VISIT_COST, $visit_cost);
        update_option(self::OPTION_FIXED_COST, $fixed_cost);

        echo '<div class="notice notice-success is-dismissible"><p>Membership dashboard estimates saved.</p></div>';
    }

    private static function filters(): array {
        $status = isset($_GET['member_status']) ? sanitize_key((string) wp_unslash($_GET['member_status'])) : '';
        if (!in_array($status, ['', 'active', 'pending-cancel'], true)) $status = '';

        $photo = isset($_GET['member_photo']) ? sanitize_key((string) wp_unslash($_GET['member_photo'])) : '';
        if (!in_array($photo, ['', 'missing', 'has'], true)) $photo = '';

        $trade = isset($_GET['member_trade']) ? sanitize_key((string) wp_unslash($_GET['member_trade'])) : 'counted';
        if (!in_array($trade, ['counted', 'all', 'trade'], true)) $trade = 'counted';

        return [
            'search' => isset($_GET['member_search']) ? sanitize_text_field((string) wp_unslash($_GET['member_search'])) : '',
            'status' => $status,
            'photo' => $photo,
            'trade' => $trade,
        ];
    }

    private static function snapshot(array $filters): array {
        $rows = [];
        $notice = '';
        $active_subs = 0;
        $active_subscriptions = 0;
        $mrr = 0.0;
        $visits_month = 0;
        $missing_photos = 0;
        $trade_records = 0;

        if (!function_exists('wcs_get_subscriptions')) {
            return [
                'notice' => 'WooCommerce Subscriptions is not available, so the membership dashboard cannot load live subscription data.',
                'active_subs' => 0,
                'active_subscriptions' => 0,
                'mrr' => 0,
                'visits_month' => 0,
                'estimated_cost' => 0,
                'estimated_net' => 0,
                'missing_photos' => 0,
                'trade_records' => 0,
                'rows' => [],
            ];
        }

        $subscriptions = wcs_get_subscriptions([
            'subscription_status' => ['active', 'pending-cancel'],
            'subscriptions_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'DESC',
        ]);

        if (!is_array($subscriptions)) $subscriptions = [];

        // Collect all sub IDs up front so we can batch-load scan stats in one query
        $all_sub_ids = [];
        foreach ($subscriptions as $subscription) {
            if (!is_object($subscription) || !method_exists($subscription, 'get_id')) continue;
            $all_sub_ids[] = (int) $subscription->get_id();
        }
        $scan_stats_cache = self::scan_stats_batch($all_sub_ids);

        foreach ($subscriptions as $subscription) {
            if (!is_object($subscription) || !method_exists($subscription, 'get_id')) continue;

            $sub_id = (int) $subscription->get_id();
            $subs = self::subscription_quantity($subscription);
            $monthly_revenue = self::monthly_revenue($subscription);
            $scan_stats = $scan_stats_cache[$sub_id] ?? ['month' => 0, 'lifetime' => 0, 'last' => ''];
            $user = method_exists($subscription, 'get_user') ? $subscription->get_user() : null;
            $name = self::customer_name($subscription, $user);
            $email = is_object($user) && !empty($user->user_email) ? (string) $user->user_email : '';
            $photo_url = self::photo_url($sub_id);
            $next_payment = method_exists($subscription, 'get_date') ? $subscription->get_date('next_payment') : '';
            $status = method_exists($subscription, 'get_status') ? (string) $subscription->get_status() : '';
            $trade = self::is_trade_subscription($sub_id);
            $products = self::product_summary($subscription);

            if ($filters['status'] !== '' && $status !== $filters['status']) continue;
            if ($filters['photo'] === 'missing' && $photo_url !== '') continue;
            if ($filters['photo'] === 'has' && $photo_url === '') continue;

            $haystack = strtolower(trim($name . ' ' . $email . ' ' . $products . ' ' . $status . ' ' . $sub_id));
            if ($filters['search'] !== '' && strpos($haystack, strtolower($filters['search'])) === false) continue;

            if ($trade) $trade_records++;
            if ($filters['trade'] === 'counted' && $trade) continue;
            if ($filters['trade'] === 'trade' && !$trade) continue;

            $rows[] = [
                'subscription_id' => $sub_id,
                'name' => $name,
                'email' => $email,
                'photo_url' => $photo_url,
                'products' => $products,
                'subs' => $subs,
                'monthly_revenue' => $monthly_revenue,
                'visits_month' => (int) $scan_stats['month'],
                'visits_lifetime' => (int) $scan_stats['lifetime'],
                'last_visit' => self::format_datetime($scan_stats['last']),
                'next_payment' => $next_payment ? date_i18n(get_option('date_format'), strtotime($next_payment)) : '',
                'status' => $status,
                'trade' => $trade,
                'edit_url' => admin_url('post.php?post=' . $sub_id . '&action=edit'),
            ];

            if ($photo_url === '') $missing_photos++;

            if (!$trade) {
                $active_subscriptions++;
                $active_subs += $subs;
                $mrr += $monthly_revenue;
                $visits_month += (int) $scan_stats['month'];
            }
        }

        usort($rows, function ($a, $b) {
            return [$b['visits_month'], $b['monthly_revenue'], $b['subscription_id']] <=> [$a['visits_month'], $a['monthly_revenue'], $a['subscription_id']];
        });

        $estimated_cost = ($visits_month * self::visit_cost()) + self::fixed_cost();

        return [
            'notice' => $notice,
            'active_subs' => $active_subs,
            'active_subscriptions' => $active_subscriptions,
            'mrr' => $mrr,
            'visits_month' => $visits_month,
            'estimated_cost' => $estimated_cost,
            'estimated_net' => $mrr - $estimated_cost,
            'missing_photos' => $missing_photos,
            'trade_records' => $trade_records,
            'rows' => $rows,
        ];
    }

    private static function subscription_quantity($sub): int {
        if (!is_object($sub) || !method_exists($sub, 'get_items')) return 1;
        $qty = 0;
        foreach ($sub->get_items() as $item) {
            if (is_object($item) && method_exists($item, 'get_quantity')) {
                $qty += max(0, (int) $item->get_quantity());
            }
        }
        return max(1, $qty);
    }

    private static function monthly_revenue($sub): float {
        $total = is_object($sub) && method_exists($sub, 'get_total') ? (float) $sub->get_total() : 0.0;
        $interval = is_object($sub) && method_exists($sub, 'get_billing_interval') ? max(1, (int) $sub->get_billing_interval()) : 1;
        $period = is_object($sub) && method_exists($sub, 'get_billing_period') ? (string) $sub->get_billing_period() : 'month';

        switch ($period) {
            case 'day':
                return ($total / $interval) * (365.25 / 12);
            case 'week':
                return ($total / $interval) * (52.1786 / 12);
            case 'year':
                return ($total / $interval) / 12;
            case 'month':
            default:
                return $total / $interval;
        }
    }

    private static function scan_stats(int $sub_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'roxy_member_scans';
        $month_start = wp_date('Y-m-01 00:00:00');

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return ['month' => 0, 'lifetime' => 0, 'last' => ''];
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN scanned_at >= %s THEN 1 ELSE 0 END) AS visits_month,
                    COUNT(*) AS visits_lifetime,
                    MAX(scanned_at) AS last_visit
                 FROM {$table}
                 WHERE subscription_id = %d",
                $month_start,
                $sub_id
            ),
            ARRAY_A
        );

        return [
            'month' => isset($row['visits_month']) ? (int) $row['visits_month'] : 0,
            'lifetime' => isset($row['visits_lifetime']) ? (int) $row['visits_lifetime'] : 0,
            'last' => isset($row['last_visit']) ? (string) $row['last_visit'] : '',
        ];
    }

    private static function customer_name($sub, $user): string {
        $first = is_object($sub) && method_exists($sub, 'get_billing_first_name') ? (string) $sub->get_billing_first_name() : '';
        $last = is_object($sub) && method_exists($sub, 'get_billing_last_name') ? (string) $sub->get_billing_last_name() : '';
        $name = trim($first . ' ' . $last);
        if ($name !== '') return $name;
        if (is_object($user) && !empty($user->display_name)) return (string) $user->display_name;
        return 'Unknown member';
    }

    private static function product_summary($sub): string {
        if (!is_object($sub) || !method_exists($sub, 'get_items')) return '';
        $parts = [];
        foreach ($sub->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_name')) continue;
            $qty = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 1;
            $parts[] = $item->get_name() . ($qty > 1 ? ' x' . $qty : '');
        }
        return implode(', ', $parts);
    }

    private static function photo_url(int $sub_id): string {
        $photo_id = absint(get_post_meta($sub_id, self::photo_meta_key(), true));
        if (!$photo_id) return '';
        $img = wp_get_attachment_image_src($photo_id, 'thumbnail');
        return is_array($img) && !empty($img[0]) ? (string) $img[0] : '';
    }

    private static function photo_cell(array $row): void {
        if (!empty($row['photo_url'])) {
            echo '<button type="button" class="rs-members-photo-button rs-members-set-photo" data-sub-id="' . esc_attr((string)$row['subscription_id']) . '" title="Replace member photo">';
            echo '<img class="rs-members-photo" src="' . esc_url($row['photo_url']) . '" alt="Member photo">';
            echo '</button>';
            return;
        }
        echo '<button type="button" class="rs-members-photo-button rs-members-set-photo" data-sub-id="' . esc_attr((string)$row['subscription_id']) . '" title="Add member photo">';
        echo '<div class="rs-members-photo-empty">No photo</div>';
        echo '</button>';
    }

    private static function photo_meta_key(): string {
        return class_exists('\\Roxy_Sub_Check') ? \Roxy_Sub_Check::META_PHOTO_ID : '_roxy_member_photo_id';
    }

    private static function is_trade_subscription(int $sub_id): bool {
        return (bool) get_post_meta($sub_id, self::META_TRADE, true);
    }

    private static function format_datetime(string $dt): string {
        if ($dt === '') return '';
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($dt));
    }

    private static function visit_cost(): float {
        return max(0, (float) get_option(self::OPTION_VISIT_COST, 0));
    }

    private static function fixed_cost(): float {
        return max(0, (float) get_option(self::OPTION_FIXED_COST, 0));
    }

    private static function money(float $amount): string {
        if (function_exists('wc_price')) {
            return wp_strip_all_tags(wc_price($amount));
        }
        return '$' . number_format_i18n($amount, 2);
    }

    private static function kpi(string $label, string $value, string $note = ''): void {
        echo '<div class="rs-members-kpi">';
        echo '<div class="rs-members-kpi-label">' . esc_html($label) . '</div>';
        echo '<div class="rs-members-kpi-value">' . esc_html($value) . '</div>';
        if ($note !== '') {
            echo '<div class="rs-members-kpi-note">' . esc_html($note) . '</div>';
        }
        echo '</div>';
    }
}
