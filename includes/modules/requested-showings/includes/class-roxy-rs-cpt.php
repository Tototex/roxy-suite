<?php
namespace RoxyRS;

if (!defined('ABSPATH')) {
    exit;
}

class CPT {
    public const POST_TYPE = 'roxy_req_showing';

    public const META_STATUS = '_roxy_rs_status';
    public const META_TARGET_AT = '_roxy_rs_target_at';
    public const META_DEADLINE_AT = '_roxy_rs_deadline_at';
    public const META_PRICING_PROFILE = '_roxy_rs_pricing_profile';
    public const META_MIN_SUPPORT = '_roxy_rs_min_support';
    public const META_FUNDING_GOAL = '_roxy_rs_funding_goal';
    public const META_SPONSOR_AMOUNT = '_roxy_rs_sponsor_amount';
    public const META_SPONSOR_TICKETS = '_roxy_rs_sponsor_tickets';
    public const META_TRAILER_URL = '_roxy_rs_trailer_url';
    public const META_REQUESTER_NAME = '_roxy_rs_requester_name';
    public const META_REQUESTER_EMAIL = '_roxy_rs_requester_email';
    public const META_APPROVED_SHOWING_ID = '_roxy_rs_approved_showing_id';
    public const META_TARGET_NOTIFIED = '_roxy_rs_target_notified';
    public const META_REVIEW_NOTIFIED = '_roxy_rs_review_notified';
    public const META_FAILURE_NOTIFIED = '_roxy_rs_failure_notified';
    public const META_GENERAL_PRICE = '_roxy_rs_general_price';
    public const META_DISCOUNT_PRICE = '_roxy_rs_discount_price';
    public const META_MATINEE_PRICE = '_roxy_rs_matinee_price';

    public static function init(): void {
        if (did_action('init')) {
            self::register();
        } else {
            add_action('init', [__CLASS__, 'register']);
        }
        add_action('add_meta_boxes', [__CLASS__, 'metaboxes']);
        add_action('save_post_' . self::POST_TYPE, [__CLASS__, 'save'], 10, 2);
        add_filter('manage_edit-' . self::POST_TYPE . '_columns', [__CLASS__, 'admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [__CLASS__, 'render_admin_column'], 10, 2);
        add_action('admin_notices', [__CLASS__, 'render_admin_tabs']);
    }

    public static function register(): void {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => 'Requested Showings',
                'singular_name' => 'Requested Showing',
                'menu_name' => 'Requested Showings',
                'all_items' => 'Requested Showings',
                'add_new_item' => 'Add Requested Showing',
                'edit_item' => 'Edit Requested Showing',
                'new_item' => 'New Requested Showing',
                'view_item' => 'View Requested Showing',
                'search_items' => 'Search Requested Showings',
            ],
            'public' => true,
            'has_archive' => true,
            'rewrite' => ['slug' => 'requested-showings'],
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
            'show_in_rest' => true,
            'show_in_menu' => false,
            'menu_icon' => 'dashicons-video-alt2',
        ]);
    }

    public static function statuses(): array {
        return [
            'pending_review' => 'Pending Review',
            'active' => 'Active',
            'threshold_met' => 'Goal Hit / Awaiting Approval',
            'approved' => 'Approved / Converted',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
        ];
    }

    public static function is_public_status(string $status): bool {
        return in_array($status, ['active', 'threshold_met', 'approved'], true);
    }

    public static function get_status(int $post_id): string {
        $status = (string) get_post_meta($post_id, self::META_STATUS, true);
        return isset(self::statuses()[$status]) ? $status : 'pending_review';
    }

    public static function funding_goal_cents(int $post_id): int {
        $raw = (int) get_post_meta($post_id, self::META_FUNDING_GOAL, true);
        if ($raw <= 0) {
            return Settings::funding_goal_cents();
        }
        return $raw < 1000 ? ($raw * 100) : $raw;
    }

    public static function sponsor_amount_cents(int $post_id): int {
        $raw = (int) get_post_meta($post_id, self::META_SPONSOR_AMOUNT, true);
        if ($raw <= 0) {
            return Settings::sponsor_amount_cents();
        }
        return $raw < 1000 ? ($raw * 100) : $raw;
    }

    public static function sponsor_ticket_qty(int $post_id): int {
        $qty = (int) get_post_meta($post_id, self::META_SPONSOR_TICKETS, true);
        return $qty > 0 ? $qty : Settings::sponsor_ticket_qty();
    }

    public static function remaining_gap_cents(int $goal_cents, int $pledged_cents): int {
        return max(0, $goal_cents - max(0, $pledged_cents));
    }

    public static function sponsor_commitment_cents(int $post_id, int $existing_pledged_cents = 0, int $pending_paid_cents = 0): int {
        $base_amount = self::sponsor_amount_cents($post_id);
        return self::remaining_gap_cents($base_amount, max(0, $existing_pledged_cents) + max(0, $pending_paid_cents));
    }

    public static function format_currency_input(int $cents): string {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function parse_currency_input($value, int $default_cents): int {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '') {
            return $default_cents;
        }
        return max(0, (int) round(((float) $value) * 100));
    }

    public static function metaboxes(): void {
        add_meta_box(
            'roxy_rs_details',
            'Requested Showing Details',
            [__CLASS__, 'render_metabox'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function render_admin_tabs(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-' . self::POST_TYPE) {
            return;
        }

        echo '<nav class="nav-tab-wrapper" style="margin:12px 0 16px;">';
        echo '<a href="' . esc_url(admin_url('edit.php?post_type=' . self::POST_TYPE)) . '" class="nav-tab nav-tab-active">Requested Showings</a>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=roxy-requested-showings&tab=settings')) . '" class="nav-tab">Settings</a>';
        echo '</nav>';
    }

    public static function render_metabox($post): void {
        wp_nonce_field('roxy_rs_save', 'roxy_rs_nonce');

        $status = self::get_status((int) $post->ID);
        $target_at = (string) get_post_meta($post->ID, self::META_TARGET_AT, true);
        $deadline_at = (string) get_post_meta($post->ID, self::META_DEADLINE_AT, true);
        $pricing_profile = (string) get_post_meta($post->ID, self::META_PRICING_PROFILE, true);
        if ($pricing_profile === '') {
            $pricing_profile = 'movie_evening';
        }
        $funding_goal = self::funding_goal_cents((int) $post->ID);
        $sponsor_amount = self::sponsor_amount_cents((int) $post->ID);
        $sponsor_tickets = self::sponsor_ticket_qty((int) $post->ID);
        $trailer_url = (string) get_post_meta($post->ID, self::META_TRAILER_URL, true);
        $approved_showing_id = (int) get_post_meta($post->ID, self::META_APPROVED_SHOWING_ID, true);

        $general_price = (string) get_post_meta($post->ID, self::META_GENERAL_PRICE, true);
        $discount_price = (string) get_post_meta($post->ID, self::META_DISCOUNT_PRICE, true);
        $matinee_price = (string) get_post_meta($post->ID, self::META_MATINEE_PRICE, true);
        if ($general_price === '') {
            $general_price = function_exists('wc_format_localized_price') && class_exists('\\RoxyST\\Settings')
                ? (string) \RoxyST\Settings::get_price('general_price', 12)
                : '12';
        }
        if ($discount_price === '') {
            $discount_price = class_exists('\\RoxyST\\Settings') ? (string) \RoxyST\Settings::get_price('discount_price', 8) : '8';
        }
        if ($matinee_price === '') {
            $matinee_price = class_exists('\\RoxyST\\Settings') ? (string) \RoxyST\Settings::get_price('matinee_price', 8) : '8';
        }

        $totals = roxy_rs_repo_backing_totals((int) $post->ID);

        echo '<style>.roxy-rs-grid{display:grid;grid-template-columns:200px 1fr;gap:10px;align-items:center;max-width:920px}.roxy-rs-grid input[type=text],.roxy-rs-grid input[type=url],.roxy-rs-grid input[type=number],.roxy-rs-grid input[type=datetime-local],.roxy-rs-grid select{width:100%}.roxy-rs-help{grid-column:1/-1;color:#666;font-size:12px}.roxy-rs-actions-panel{grid-column:1/-1;padding:14px 16px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7;margin-top:8px}.roxy-rs-action-buttons{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 8px}</style>';
        echo '<div class="roxy-rs-grid">';

        echo '<label for="roxy-rs-status"><strong>Status</strong></label>';
        echo '<select id="roxy-rs-status" name="roxy_rs_status">';
        foreach (self::statuses() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($status, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';

        echo '<label for="roxy-rs-target-at"><strong>Target showtime</strong></label>';
        echo '<input id="roxy-rs-target-at" type="datetime-local" name="roxy_rs_target_at" value="' . esc_attr($target_at) . '">';

        echo '<label for="roxy-rs-deadline-at"><strong>Backing deadline</strong></label>';
        echo '<input id="roxy-rs-deadline-at" type="datetime-local" name="roxy_rs_deadline_at" value="' . esc_attr($deadline_at) . '">';
        echo '<div class="roxy-rs-help">Set the public target showtime up front so people know what they are backing. The deadline should usually be at least 1-2 weeks before the target showtime.</div>';

        echo '<label for="roxy-rs-pricing-profile"><strong>Pricing structure</strong></label>';
        echo '<select id="roxy-rs-pricing-profile" name="roxy_rs_pricing_profile">';
        echo '<option value="movie_evening" ' . selected($pricing_profile, 'movie_evening', false) . '>Movie - Evening</option>';
        echo '<option value="movie_matinee" ' . selected($pricing_profile, 'movie_matinee', false) . '>Movie - Matinee</option>';
        echo '</select>';

        echo '<label for="roxy-rs-general-price"><strong>General price</strong></label>';
        echo '<input id="roxy-rs-general-price" type="number" min="0" step="0.01" name="roxy_rs_general_price" value="' . esc_attr($general_price) . '">';

        echo '<label for="roxy-rs-discount-price"><strong>Discount price</strong></label>';
        echo '<input id="roxy-rs-discount-price" type="number" min="0" step="0.01" name="roxy_rs_discount_price" value="' . esc_attr($discount_price) . '">';

        echo '<label for="roxy-rs-matinee-price"><strong>Matinee price</strong></label>';
        echo '<input id="roxy-rs-matinee-price" type="number" min="0" step="0.01" name="roxy_rs_matinee_price" value="' . esc_attr($matinee_price) . '">';

        echo '<label for="roxy-rs-funding-goal"><strong>Funding goal</strong></label>';
        echo '<input id="roxy-rs-funding-goal" type="number" min="0" step="0.01" name="roxy_rs_funding_goal" value="' . esc_attr(self::format_currency_input($funding_goal)) . '">';

        echo '<label for="roxy-rs-sponsor-amount"><strong>Sponsor package amount</strong></label>';
        echo '<input id="roxy-rs-sponsor-amount" type="number" min="0" step="0.01" name="roxy_rs_sponsor_amount" value="' . esc_attr(self::format_currency_input($sponsor_amount)) . '">';
        echo '<div class="roxy-rs-help">Customer-facing sponsorship automatically drops as paid backers pledge money. Keep this at or above the funding goal so a sponsor can fully satisfy the request.</div>';

        echo '<label for="roxy-rs-sponsor-tickets"><strong>Sponsor included tickets</strong></label>';
        echo '<input id="roxy-rs-sponsor-tickets" type="number" min="0" step="1" name="roxy_rs_sponsor_tickets" value="' . esc_attr((string) $sponsor_tickets) . '">';

        echo '<label for="roxy-rs-trailer-url"><strong>Trailer URL</strong></label>';
        echo '<input id="roxy-rs-trailer-url" type="url" name="roxy_rs_trailer_url" placeholder="https://www.youtube.com/watch?v=..." value="' . esc_attr($trailer_url) . '">';
        echo '<div class="roxy-rs-help">Featured image, title, trailer URL, pricing structure, and target showtime all carry forward when you approve and convert this into a real showing.</div>';

        echo '<div class="roxy-rs-help"><strong>Progress:</strong> ' . wp_kses_post(wc_price(((int) $totals['charge_total']) / 100)) . ' pledged of '
            . wp_kses_post(wc_price($funding_goal / 100)) . ', '
            . esc_html(number_format_i18n((int) $totals['support_qty'])) . ' paid backer tickets, '
            . esc_html(number_format_i18n((int) $totals['subscriber_qty'])) . ' subscriber reservations, '
            . 'Sponsor: ' . esc_html(!empty($totals['has_sponsor']) ? 'Yes' : 'No')
            . ($approved_showing_id > 0 ? ' | Approved showing #' . esc_html((string) $approved_showing_id) : '')
            . '</div>';

        if ($approved_showing_id > 0) {
            echo '<div class="roxy-rs-help"><a href="' . esc_url(get_edit_post_link($approved_showing_id, '')) . '">Edit approved showing</a> | <a href="' . esc_url(get_permalink($approved_showing_id)) . '" target="_blank" rel="noopener">View public showing</a></div>';
        }

        if (class_exists('\\RoxyRS\\Conversion')) {
            echo '<div class="roxy-rs-actions-panel">';
            echo \RoxyRS\Conversion::actions_markup((int) $post->ID); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</div>';
        }

        echo '</div>';
    }

    public static function admin_columns(array $columns): array {
        return [
            'cb' => $columns['cb'] ?? '<input type="checkbox" />',
            'title' => 'Title',
            'status' => 'Status',
            'target' => 'Target Showtime',
            'deadline' => 'Deadline',
            'progress' => 'Progress',
            'date' => 'Date',
        ];
    }

    public static function render_admin_column(string $column, int $post_id): void {
        switch ($column) {
            case 'status':
                echo esc_html(self::statuses()[self::get_status($post_id)] ?? 'Pending Review');
                break;
            case 'target':
                echo esc_html((string) get_post_meta($post_id, self::META_TARGET_AT, true));
                break;
            case 'deadline':
                echo esc_html((string) get_post_meta($post_id, self::META_DEADLINE_AT, true));
                break;
            case 'progress':
                $totals = roxy_rs_repo_backing_totals($post_id);
                $goal = self::funding_goal_cents($post_id);
                echo wp_kses_post(wc_price(((int) $totals['charge_total']) / 100) . ' / ' . wc_price($goal / 100));
                if (!empty($totals['has_sponsor'])) {
                    echo ' <strong>(Sponsored)</strong>';
                }
                break;
        }
    }

    public static function save(int $post_id, $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!isset($_POST['roxy_rs_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['roxy_rs_nonce'])), 'roxy_rs_save')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $status = sanitize_key((string) ($_POST['roxy_rs_status'] ?? 'pending_review'));
        if (!isset(self::statuses()[$status])) {
            $status = 'pending_review';
        }

        update_post_meta($post_id, self::META_STATUS, $status);
        update_post_meta($post_id, self::META_TARGET_AT, sanitize_text_field((string) ($_POST['roxy_rs_target_at'] ?? '')));
        update_post_meta($post_id, self::META_DEADLINE_AT, sanitize_text_field((string) ($_POST['roxy_rs_deadline_at'] ?? '')));
        update_post_meta($post_id, self::META_PRICING_PROFILE, sanitize_key((string) ($_POST['roxy_rs_pricing_profile'] ?? 'movie_evening')));
        $funding_goal = self::parse_currency_input($_POST['roxy_rs_funding_goal'] ?? '', Settings::funding_goal_cents());
        $sponsor_amount = self::parse_currency_input($_POST['roxy_rs_sponsor_amount'] ?? '', Settings::sponsor_amount_cents());
        update_post_meta($post_id, self::META_FUNDING_GOAL, max(100, $funding_goal));
        update_post_meta($post_id, self::META_MIN_SUPPORT, 0);
        update_post_meta($post_id, self::META_SPONSOR_AMOUNT, max($funding_goal, $sponsor_amount));
        update_post_meta($post_id, self::META_SPONSOR_TICKETS, max(0, (int) ($_POST['roxy_rs_sponsor_tickets'] ?? Settings::sponsor_ticket_qty())));
        update_post_meta($post_id, self::META_TRAILER_URL, esc_url_raw((string) ($_POST['roxy_rs_trailer_url'] ?? '')));
        update_post_meta($post_id, self::META_GENERAL_PRICE, sanitize_text_field((string) ($_POST['roxy_rs_general_price'] ?? '')));
        update_post_meta($post_id, self::META_DISCOUNT_PRICE, sanitize_text_field((string) ($_POST['roxy_rs_discount_price'] ?? '')));
        update_post_meta($post_id, self::META_MATINEE_PRICE, sanitize_text_field((string) ($_POST['roxy_rs_matinee_price'] ?? '')));

        if (self::is_public_status($status) && $post->post_status !== 'publish') {
            remove_action('save_post_' . self::POST_TYPE, [__CLASS__, 'save'], 10);
            wp_update_post([
                'ID' => $post_id,
                'post_status' => 'publish',
            ]);
            add_action('save_post_' . self::POST_TYPE, [__CLASS__, 'save'], 10, 2);
        }
    }
}
