<?php
namespace RoxyRS;

if (!defined('ABSPATH')) {
    exit;
}

class Frontend {
    public static function init(): void {
        add_shortcode('roxy_requested_showings', [__CLASS__, 'shortcode_index']);
        add_shortcode('roxy_requested_showing_submit', [__CLASS__, 'shortcode_submit']);
        add_filter('the_content', [__CLASS__, 'filter_single_content']);
        add_filter('template_include', [__CLASS__, 'maybe_use_standalone_template']);
        add_action('admin_post_roxy_rs_submit_request', [__CLASS__, 'handle_submit_request']);
        add_action('admin_post_nopriv_roxy_rs_submit_request', [__CLASS__, 'handle_submit_request']);
        add_action('admin_post_roxy_rs_commit_backing', [__CLASS__, 'handle_commit_backing']);
        add_action('wp_ajax_roxy_rs_available_showtimes', [__CLASS__, 'ajax_available_showtimes']);
        add_action('wp_ajax_nopriv_roxy_rs_available_showtimes', [__CLASS__, 'ajax_available_showtimes']);
    }

    public static function maybe_use_standalone_template(string $template): string {
        if (is_page('movie-requests')) {
            $custom = ROXY_RS_PATH . 'templates/page-movie-requests.php';
            if (file_exists($custom)) {
                return $custom;
            }
        }

        return $template;
    }

    public static function shortcode_index(): string {
        self::enqueue_styles();
        $posts = get_posts([
            'post_type' => CPT::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => [[
                'key' => CPT::META_STATUS,
                'value' => ['active', 'threshold_met'],
                'compare' => 'IN',
            ]],
            'meta_key' => CPT::META_TARGET_AT,
            'orderby' => 'meta_value',
            'order' => 'ASC',
        ]);

        ob_start();
        echo '<div class="roxy-rs-index">';
        echo '<div class="roxy-rs-index-head"><h2>Requested Showings</h2><p>Back a movie you want to see. If a request gets enough support, we can turn it into a real Roxy showing and charge the saved payment method once the date is confirmed.</p></div>';
        if (!$posts) {
            echo '<div class="roxy-rs-empty-state">';
            echo '<p class="roxy-rs-kicker">Nothing Live Yet</p>';
            echo '<h3>No active requested showings right now.</h3>';
            echo '<p>Be the first to get one started. Submit a movie request below and we can build the backing page with you.</p>';
            echo '<p><a class="roxy-rs-button roxy-rs-button-primary" href="#request-a-film">Request a movie</a></p>';
            echo '</div>';
        } else {
            echo '<div class="roxy-rs-cards">';
            foreach ($posts as $post) {
                echo self::render_request_card((int) $post->ID, false);
            }
            echo '</div>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    public static function shortcode_submit(): string {
        self::enqueue_styles();
        self::enqueue_form_script();
        $notice = isset($_GET['roxy_rs_notice']) ? sanitize_key((string) wp_unslash($_GET['roxy_rs_notice'])) : '';
        $message = isset($_GET['message']) ? sanitize_text_field((string) wp_unslash($_GET['message'])) : '';
        ob_start();
        echo '<div class="roxy-rs-submit">';
        echo '<h2>Request a film</h2>';
        echo '<p>Tell us what film you want to see, when you hope to see it, and why people would show up. We will build the backing page and scheduling details on our side before opening it up for support.</p>';
        if ($notice !== '') {
            echo '<div class="roxy-rs-notice roxy-rs-notice-' . esc_attr($notice === 'success' ? 'success' : 'error') . '">' . esc_html($message) . '</div>';
        }
        if (!is_user_logged_in()) {
            echo '<div class="roxy-rs-login-note"><strong>Login required.</strong> Sign in before submitting a requested showing so we can keep track of request owners and follow up cleanly. <a href="' . esc_url(wp_login_url(get_permalink() ?: home_url('/'))) . '">Log in</a> or create an account first.</div>';
            echo '</div>';
            return (string) ob_get_clean();
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="roxy-rs-form">';
        wp_nonce_field('roxy_rs_submit_request');
        echo '<input type="hidden" name="action" value="roxy_rs_submit_request">';
        echo '<input type="hidden" name="target_at" id="roxy-rs-target-at" value="">';
        echo '<label>Film title<input type="text" name="title" required></label>';
        echo '<label>Your name<input type="text" name="requester_name" required></label>';
        echo '<label>Your email<input type="email" name="requester_email" required></label>';
        echo '<div class="roxy-rs-two">';
        echo '<label>Preferred target date<input type="date" name="target_date" id="roxy-rs-target-date" required></label>';
        echo '<label>Available showtime<select name="target_slot" id="roxy-rs-target-slot" required disabled><option value="">Choose a date first</option></select></label>';
        echo '</div>';
        echo '<div class="roxy-rs-deadline-box">';
        echo '<div><strong>Backing deadline:</strong> <span id="roxy-rs-deadline-label">Choose a showtime to calculate it.</span></div>';
        echo '<div class="roxy-rs-help-text">Backers need to commit at least ' . esc_html((string) Settings::deadline_days_before_target()) . ' days before the target showtime so we have time to secure and schedule the film.</div>';
        echo '</div>';
        echo '<p class="roxy-rs-help-text">For the best chance of approval, request a target date at least ' . esc_html((string) Settings::min_lead_days()) . ' days out. We will only show times that fit the current theater calendar and do not conflict with existing bookings or showings.</p>';
        echo '<label>Why should we book it?<textarea name="notes" rows="5" placeholder="Audience, community tie-in, special reason, etc."></textarea></label>';
        echo '<button type="submit" class="roxy-rs-button roxy-rs-button-action">Submit request</button>';
        echo '</form>';
        echo '</div>';
        return (string) ob_get_clean();
    }

    public static function filter_single_content(string $content): string {
        if (!is_singular(CPT::POST_TYPE) || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $content;
        }

        self::enqueue_styles();
        return $content . self::render_single_request((int) $post_id);
    }

    public static function render_request_card(int $post_id, bool $detailed = false): string {
        $status = CPT::get_status($post_id);
        $totals = roxy_rs_repo_backing_totals($post_id);
        $goal = CPT::funding_goal_cents($post_id);
        $target_at = (string) get_post_meta($post_id, CPT::META_TARGET_AT, true);
        $deadline_at = (string) get_post_meta($post_id, CPT::META_DEADLINE_AT, true);
        $summary = $totals['has_sponsor']
            ? 'Sponsored'
            : wp_strip_all_tags(wc_price(((int) $totals['charge_total']) / 100)) . ' / ' . wp_strip_all_tags(wc_price($goal / 100)) . ' pledged';

        ob_start();
        echo '<article class="roxy-rs-card">';
        if (has_post_thumbnail($post_id)) {
            echo '<div class="roxy-rs-card-poster"><a href="' . esc_url(get_permalink($post_id)) . '">' . get_the_post_thumbnail($post_id, 'medium_large') . '</a></div>';
        }
        echo '<div class="roxy-rs-card-body">';
        echo '<p class="roxy-rs-kicker">Requested Showing</p>';
        echo '<h3><a href="' . esc_url(get_permalink($post_id)) . '">' . esc_html(get_the_title($post_id)) . '</a></h3>';
        if ($target_at !== '') {
            $target_dt = self::parse_local_datetime($target_at);
            echo '<p><strong>Target showtime:</strong> ' . esc_html($target_dt ? $target_dt->format('l, F j, Y g:i A') : $target_at) . '</p>';
        }
        if ($deadline_at !== '') {
            $deadline_dt = self::parse_local_datetime($deadline_at);
            echo '<p><strong>Backing deadline:</strong> ' . esc_html($deadline_dt ? $deadline_dt->format('l, F j, Y g:i A') : $deadline_at) . '</p>';
        }
        echo '<p><strong>Status:</strong> ' . esc_html(CPT::statuses()[$status] ?? 'Pending Review') . '</p>';
        echo '<p><strong>Progress:</strong> ' . esc_html($summary) . '</p>';
        if ($detailed) {
            echo self::render_backing_form($post_id, $status, $totals);
        } else {
            echo '<p><a class="roxy-rs-button roxy-rs-button-primary" href="' . esc_url(get_permalink($post_id)) . '">View request</a></p>';
        }
        echo '</div>';
        echo '</article>';
        return (string) ob_get_clean();
    }

    private static function render_single_request(int $post_id): string {
        $status = CPT::get_status($post_id);
        $totals = roxy_rs_repo_backing_totals($post_id);
        ob_start();
        echo '<div class="roxy-rs-single-wrap">';
        echo self::render_request_card($post_id, true);
        echo '</div>';
        return (string) ob_get_clean();
    }

    private static function render_backing_form(int $post_id, string $status, array $totals): string {
        $goal = CPT::funding_goal_cents($post_id);
        $profile = (string) get_post_meta($post_id, CPT::META_PRICING_PROFILE, true);
        if ($profile === '') {
            $profile = 'movie_evening';
        }
        $prices = self::ticket_prices($post_id);
        $sponsor_amount = CPT::sponsor_commitment_cents($post_id, (int) $totals['charge_total']);
        $sponsor_tickets = CPT::sponsor_ticket_qty($post_id);
        $deadline_at = (string) get_post_meta($post_id, CPT::META_DEADLINE_AT, true);
        $trailer_url = (string) get_post_meta($post_id, CPT::META_TRAILER_URL, true);
        $notice = isset($_GET['roxy_rs_notice']) ? sanitize_key((string) wp_unslash($_GET['roxy_rs_notice'])) : '';
        $message = isset($_GET['message']) ? sanitize_text_field((string) wp_unslash($_GET['message'])) : '';

        ob_start();
        if ($trailer_url !== '') {
            echo '<p><a href="' . esc_url($trailer_url) . '" target="_blank" rel="noopener">Watch trailer</a></p>';
        }
        echo '<div class="roxy-rs-progress"><div class="roxy-rs-progress-bar" style="width:' . esc_attr((string) min(100, round(($goal > 0 ? (((int) $totals['charge_total']) / $goal) * 100 : 0), 1))) . '%"></div></div>';
        echo '<p class="roxy-rs-help-text">' . wp_kses_post(wc_price(((int) $totals['charge_total']) / 100)) . ' pledged of ' . wp_kses_post(wc_price($goal / 100)) . ', '
            . esc_html(number_format_i18n((int) $totals['support_qty'])) . ' paid backer tickets, '
            . esc_html(number_format_i18n((int) $totals['subscriber_qty'])) . ' subscriber reservations. '
            . (!empty($totals['has_sponsor']) ? 'A sponsor has already satisfied the goal. ' : '')
            . 'Cards are only charged once the showing is confirmed and scheduled.</p>';

        if ($notice !== '' && isset($_GET['request_id']) && (int) $_GET['request_id'] === $post_id) {
            echo '<div class="roxy-rs-notice roxy-rs-notice-' . esc_attr($notice === 'success' ? 'success' : 'error') . '">' . esc_html($message) . '</div>';
        }

        if (!in_array($status, ['active', 'threshold_met'], true)) {
            echo '<p>This request is not currently accepting backers.</p>';
            return (string) ob_get_clean();
        }

        $deadline_dt = self::parse_local_datetime($deadline_at);
        if ($deadline_dt && $deadline_dt < self::current_site_datetime()) {
            echo '<p>The backing window has closed.</p>';
            return (string) ob_get_clean();
        }

        if (!is_user_logged_in()) {
            echo '<div class="roxy-rs-login-note"><strong>Login required.</strong> Backers and sponsors use the saved payment method on their account so we can charge only after the showing is confirmed and scheduled. <a href="' . esc_url(wp_login_url(get_permalink($post_id))) . '">Log in</a> or create an account first.</div>';
            return (string) ob_get_clean();
        }

        $tokens = Conversion::saved_payment_tokens(get_current_user_id());
        $has_tokens = !empty($tokens);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="roxy-rs-form">';
        wp_nonce_field('roxy_rs_commit_backing');
        echo '<input type="hidden" name="action" value="roxy_rs_commit_backing">';
        echo '<input type="hidden" name="request_id" value="' . esc_attr((string) $post_id) . '">';

        if ($profile === 'movie_matinee') {
            echo '<label>Matinee tickets (' . wp_kses_post(wc_price($prices['matinee'] / 100)) . ')<input type="number" min="0" step="1" name="general_qty" value="0"></label>';
        } else {
            echo '<div class="roxy-rs-two">';
            echo '<label>General tickets (' . wp_kses_post(wc_price($prices['general'] / 100)) . ')<input type="number" min="0" step="1" name="general_qty" value="0"></label>';
            echo '<label>Discount tickets (' . wp_kses_post(wc_price($prices['discount'] / 100)) . ')<input type="number" min="0" step="1" name="discount_qty" value="0"></label>';
            echo '</div>';
        }

        echo '<label>Subscriber reservations (do not count toward the funding goal)<input type="number" min="0" step="1" name="subscriber_qty" value="0"></label>';
        if (!empty($totals['has_sponsor'])) {
            echo '<p class="roxy-rs-help-text">Sponsorship has already been claimed for this request. You can still back tickets or reserve subscriber seats.</p>';
        } elseif ($sponsor_amount > 0) {
            echo '<label class="roxy-rs-check"><input type="checkbox" name="sponsor_request" value="1"> Sponsor this showing for ' . wp_kses_post(wc_price($sponsor_amount / 100)) . ' and include ' . esc_html(number_format_i18n($sponsor_tickets)) . ' ticket(s)</label>';
        }

        if (!$has_tokens) {
            $payment_methods_url = function_exists('wc_get_account_endpoint_url')
                ? wc_get_account_endpoint_url('payment-methods')
                : home_url('/my-account/payment-methods/');
            echo '<div class="roxy-rs-notice roxy-rs-notice-error">No saved card found on your account. <a href="' . esc_url($payment_methods_url) . '">Add a payment method</a> in My Account before backing paid tickets or sponsoring this request. Subscriber reservations can still be submitted without one.</div>';
        } else {
            echo '<label>Saved payment method<select name="payment_token_id">';
            foreach ($tokens as $token) {
                echo '<option value="' . esc_attr((string) $token['id']) . '">' . esc_html($token['label']) . '</option>';
            }
            echo '</select></label>';
        }

        echo '<p class="roxy-rs-help-text">Paid backer tickets count toward the funding goal in dollars. Subscriber reservations do not. Sponsorship satisfies the goal immediately. Once the showing is confirmed and scheduled, there are no refunds.</p>';
        echo '<button type="submit" class="roxy-rs-button roxy-rs-button-action">Back this request</button>';
        echo '</form>';
        return (string) ob_get_clean();
    }

    public static function handle_submit_request(): void {
        check_admin_referer('roxy_rs_submit_request');
        if (!is_user_logged_in()) {
            self::redirect_notice('error', 'Please log in before submitting a film request.');
        }

        $title = sanitize_text_field((string) wp_unslash($_POST['title'] ?? ''));
        $requester_name = sanitize_text_field((string) wp_unslash($_POST['requester_name'] ?? ''));
        $requester_email = sanitize_email((string) wp_unslash($_POST['requester_email'] ?? ''));
        $target_at = sanitize_text_field((string) wp_unslash($_POST['target_at'] ?? ''));
        $notes = sanitize_textarea_field((string) wp_unslash($_POST['notes'] ?? ''));

        if ($title === '' || $requester_name === '' || $requester_email === '' || $target_at === '') {
            self::redirect_notice('error', 'Please complete all required fields.');
        }

        $selected_slot = self::validate_requested_slot($target_at);
        if (is_wp_error($selected_slot)) {
            self::redirect_notice('error', $selected_slot->get_error_message());
        }

        $deadline_at = self::deadline_for_target($target_at);
        $pricing_profile = (string) ($selected_slot['profile'] ?? 'movie_evening');

        $post_id = wp_insert_post([
            'post_type' => CPT::POST_TYPE,
            'post_status' => 'draft',
            'post_title' => $title,
            'post_content' => $notes,
            'post_excerpt' => 'Requested by ' . $requester_name . ' (' . $requester_email . ')',
            'post_author' => get_current_user_id(),
        ], true);

        if (is_wp_error($post_id)) {
            self::redirect_notice('error', 'Could not save that request.');
        }

        update_post_meta($post_id, CPT::META_STATUS, 'pending_review');
        update_post_meta($post_id, CPT::META_TARGET_AT, $target_at);
        update_post_meta($post_id, CPT::META_DEADLINE_AT, $deadline_at);
        update_post_meta($post_id, CPT::META_PRICING_PROFILE, $pricing_profile);
        $default_goal = Settings::funding_goal_cents();
        $default_sponsor = max($default_goal, Settings::sponsor_amount_cents());
        update_post_meta($post_id, CPT::META_MIN_SUPPORT, 0);
        update_post_meta($post_id, CPT::META_FUNDING_GOAL, $default_goal);
        update_post_meta($post_id, CPT::META_SPONSOR_AMOUNT, $default_sponsor);
        update_post_meta($post_id, CPT::META_SPONSOR_TICKETS, Settings::sponsor_ticket_qty());
        update_post_meta($post_id, CPT::META_TRAILER_URL, '');
        update_post_meta($post_id, CPT::META_REQUESTER_NAME, $requester_name);
        update_post_meta($post_id, CPT::META_REQUESTER_EMAIL, $requester_email);

        $subject = sprintf('Requested showing submitted: %s', $title);
        $message = "A new requested showing was submitted.\n\nTitle: {$title}\nRequester: {$requester_name}\nEmail: {$requester_email}\nTarget: {$target_at}\n\nNotes:\n{$notes}\n\nReview: " . admin_url('post.php?post=' . (int) $post_id . '&action=edit');
        wp_mail(get_option('admin_email'), $subject, $message);

        self::redirect_notice('success', 'Request submitted. We will review it before it goes public.');
    }

    public static function handle_commit_backing(): void {
        check_admin_referer('roxy_rs_commit_backing');
        if (!is_user_logged_in()) {
            wp_die('Login required.');
        }

        $request_id = (int) ($_POST['request_id'] ?? 0);
        $request = get_post($request_id);
        if (!$request || $request->post_type !== CPT::POST_TYPE) {
            self::redirect_request_notice($request_id, 'error', 'Requested showing not found.');
        }

        $status = CPT::get_status($request_id);
        if (!in_array($status, ['active', 'threshold_met'], true)) {
            self::redirect_request_notice($request_id, 'error', 'This request is not currently accepting backers.');
        }

        $general_qty = max(0, (int) wp_unslash($_POST['general_qty'] ?? 0));
        $discount_qty = max(0, (int) wp_unslash($_POST['discount_qty'] ?? 0));
        $subscriber_qty = max(0, (int) wp_unslash($_POST['subscriber_qty'] ?? 0));
        $sponsor_request = !empty($_POST['sponsor_request']);
        $token_id = max(0, (int) wp_unslash($_POST['payment_token_id'] ?? 0));

        $profile = (string) get_post_meta($request_id, CPT::META_PRICING_PROFILE, true);
        if ($profile === 'movie_matinee') {
            $discount_qty = 0;
        }

        $support_qty = $general_qty + $discount_qty;
        $totals = roxy_rs_repo_backing_totals($request_id);
        if ($support_qty <= 0 && $subscriber_qty <= 0 && !$sponsor_request) {
            self::redirect_request_notice($request_id, 'error', 'Choose at least one ticket, subscriber reservation, or sponsorship.');
        }

        $tokens = Conversion::saved_payment_tokens(get_current_user_id());
        $requires_payment = $support_qty > 0 || $sponsor_request;
        if ($requires_payment && (!$token_id || !isset($tokens[$token_id]))) {
            self::redirect_request_notice($request_id, 'error', 'Choose a saved payment method first.');
        }

        $lock_key = self::backing_lock_key($request_id, get_current_user_id(), [
            'general_qty' => $general_qty,
            'discount_qty' => $discount_qty,
            'subscriber_qty' => $subscriber_qty,
            'sponsor_request' => $sponsor_request ? 1 : 0,
            'token_id' => $requires_payment ? $token_id : 0,
        ]);
        if (get_transient($lock_key)) {
            self::redirect_request_notice($request_id, 'success', 'We already saved that backing request. Please refresh the page to see the latest progress.');
        }

        $prices = self::ticket_prices($request_id);
        $charge_total = 0;
        if ($profile === 'movie_matinee') {
            $charge_total += $general_qty * $prices['matinee'];
        } else {
            $charge_total += $general_qty * $prices['general'];
            $charge_total += $discount_qty * $prices['discount'];
        }

        $backing_type = 'backer';
        $sponsor_amount = 0;
        $sponsor_ticket_qty = 0;
        if ($sponsor_request) {
            if (!empty($totals['has_sponsor'])) {
                self::redirect_request_notice($request_id, 'error', 'This request already has a sponsor. You can still back tickets or reserve subscriber seats.');
            }
            $backing_type = 'sponsor';
            $sponsor_amount = CPT::sponsor_commitment_cents($request_id, (int) $totals['charge_total'], $charge_total);
            if ($sponsor_amount <= 0) {
                self::redirect_request_notice($request_id, 'error', 'This request no longer needs a sponsor. You can still back tickets or reserve subscriber seats.');
            }
            $sponsor_ticket_qty = CPT::sponsor_ticket_qty($request_id);
            $charge_total += $sponsor_amount;
        } elseif ($support_qty <= 0 && $subscriber_qty > 0) {
            $backing_type = 'subscriber';
        }

        $backing_id = roxy_rs_repo_insert_backing([
            'request_id' => $request_id,
            'user_id' => get_current_user_id(),
            'status' => 'pending',
            'backing_type' => $backing_type,
            'payment_token_id' => $requires_payment ? $token_id : null,
            'general_qty' => $general_qty,
            'discount_qty' => $discount_qty,
            'subscriber_qty' => $subscriber_qty,
            'support_qty' => $support_qty,
            'sponsor_amount' => $sponsor_amount,
            'sponsor_ticket_qty' => $sponsor_ticket_qty,
            'charge_total' => $charge_total,
        ]);

        if (is_wp_error($backing_id)) {
            self::redirect_request_notice($request_id, 'error', $backing_id->get_error_message());
        }

        set_transient($lock_key, (int) $backing_id, 5 * MINUTE_IN_SECONDS);

        Conversion::maybe_mark_request_ready($request_id);
        self::redirect_request_notice($request_id, 'success', 'Your backing was saved. We will only charge the saved payment method after the showing is confirmed and scheduled.');
    }

    public static function ajax_available_showtimes(): void {
        check_ajax_referer('roxy_rs_availability', 'nonce');
        $date = sanitize_text_field((string) wp_unslash($_GET['date'] ?? ''));
        if ($date === '') {
            wp_send_json_error(['message' => 'Choose a date first.'], 400);
        }

        $slots = self::available_showtimes_for_date($date);
        wp_send_json_success([
            'slots' => array_values($slots),
        ]);
    }

    public static function ticket_prices(int $request_id): array {
        $general = (string) get_post_meta($request_id, CPT::META_GENERAL_PRICE, true);
        $discount = (string) get_post_meta($request_id, CPT::META_DISCOUNT_PRICE, true);
        $matinee = (string) get_post_meta($request_id, CPT::META_MATINEE_PRICE, true);

        if ($general === '' && class_exists('\\RoxyST\\Settings')) {
            $general = (string) \RoxyST\Settings::get_price('general_price', 12);
        }
        if ($discount === '' && class_exists('\\RoxyST\\Settings')) {
            $discount = (string) \RoxyST\Settings::get_price('discount_price', 8);
        }
        if ($matinee === '' && class_exists('\\RoxyST\\Settings')) {
            $matinee = (string) \RoxyST\Settings::get_price('matinee_price', 8);
        }

        return [
            'general' => (int) round((float) $general * 100),
            'discount' => (int) round((float) $discount * 100),
            'matinee' => (int) round((float) $matinee * 100),
        ];
    }

    private static function redirect_notice(string $notice, string $message): void {
        $redirect = add_query_arg([
            'roxy_rs_notice' => $notice,
            'message' => $message,
        ], wp_get_referer() ?: home_url('/'));
        wp_safe_redirect($redirect);
        exit;
    }

    private static function redirect_request_notice(int $request_id, string $notice, string $message): void {
        $redirect = add_query_arg([
            'roxy_rs_notice' => $notice,
            'message' => $message,
            'request_id' => $request_id,
        ], get_permalink($request_id) ?: home_url('/'));
        wp_safe_redirect($redirect);
        exit;
    }

    private static function enqueue_styles(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $css = '.roxy-rs-index,.roxy-rs-single-wrap,.roxy-rs-submit{margin:24px 0}.roxy-rs-standalone-wrap{display:grid;gap:36px}.roxy-rs-hero{display:grid;gap:16px;max-width:920px}.roxy-rs-hero h1{margin:0;font-size:clamp(42px,6vw,72px);line-height:.95;font-style:italic;font-weight:900;letter-spacing:-.03em}.roxy-rs-hero>p{margin:0;max-width:980px;font-size:18px;line-height:1.55}.roxy-rs-hero-actions{display:flex;gap:16px;flex-wrap:wrap;align-items:center}.roxy-rs-list-block,.roxy-rs-explainer,.roxy-rs-submit-block{display:grid;gap:12px}.roxy-rs-list-block>h2,.roxy-rs-explainer>h2,.roxy-rs-submit-block>h2{margin:0}.roxy-rs-list-block>p,.roxy-rs-explainer>p{margin:0;max-width:900px}.roxy-rs-cards{display:grid;gap:24px}.roxy-rs-card{display:grid;grid-template-columns:minmax(140px,220px) 1fr;gap:20px;padding:24px;border:1px solid rgba(255,255,255,.15);border-radius:18px;background:rgba(20,22,30,.9)}.roxy-rs-card img{width:100%;height:auto;border-radius:14px}.roxy-rs-kicker{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#ffcf35;margin:0 0 8px}.roxy-rs-card h3{margin:0 0 8px}.roxy-rs-card p{margin:0 0 10px}.roxy-rs-empty-state{display:grid;gap:12px;padding:28px 30px;border:1px solid rgba(255,255,255,.12);border-radius:18px;background:linear-gradient(180deg,rgba(24,27,36,.96),rgba(17,18,25,.96));box-shadow:0 18px 38px rgba(0,0,0,.18);max-width:820px}.roxy-rs-empty-state h3{margin:0;font-size:30px;line-height:1.1}.roxy-rs-empty-state p{margin:0;max-width:680px;font-size:16px;line-height:1.6;color:#d9d9d9}.roxy-rs-empty-state .roxy-rs-button{justify-self:start}.roxy-rs-info-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-top:10px}.roxy-rs-info-card{padding:18px 20px;border-radius:18px;border:1px solid rgba(255,255,255,.12);background:linear-gradient(180deg,rgba(24,27,36,.96),rgba(17,18,25,.96));box-shadow:0 18px 38px rgba(0,0,0,.18)}.roxy-rs-info-card h3{margin:0 0 10px;font-size:24px;line-height:1.15}.roxy-rs-info-card p{margin:0;font-size:15px;line-height:1.6;color:#d9d9d9}.roxy-rs-policy-box{display:grid;gap:10px;padding:18px 20px;border-radius:18px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);margin-top:6px}.roxy-rs-policy-box p{margin:0;font-size:15px;line-height:1.55}.roxy-rs-form{display:grid;gap:14px;margin-top:16px}.roxy-rs-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.roxy-rs-form input,.roxy-rs-form textarea,.roxy-rs-form select{width:100%;padding:12px 14px;border-radius:12px;border:1px solid rgba(0,0,0,.15)}.roxy-rs-deadline-box{padding:14px 16px;border-radius:14px;background:rgba(255,255,255,.08)}.roxy-rs-button{display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:999px;text-decoration:none;border:none;cursor:pointer;font-weight:700}.roxy-rs-button-primary,.roxy-rs-button-primary:link,.roxy-rs-button-primary:visited,.roxy-rs-button-primary:hover,.roxy-rs-button-primary:focus{background:#ffcf35;color:#111 !important}.roxy-rs-button-secondary,.roxy-rs-button-secondary:link,.roxy-rs-button-secondary:visited,.roxy-rs-button-secondary:hover,.roxy-rs-button-secondary:focus{color:#67f0e8 !important}.roxy-rs-button-action,.roxy-rs-button-action:link,.roxy-rs-button-action:visited,.roxy-rs-button-action:hover,.roxy-rs-button-action:focus{background:#111;color:#fff !important;-webkit-text-fill-color:#fff;border:2px solid rgba(255,255,255,.92)}.roxy-rs-button-action[disabled]{opacity:.55;cursor:not-allowed;color:#fff !important;-webkit-text-fill-color:#fff}.roxy-rs-help-text,.roxy-rs-login-note{font-size:14px;color:#ddd}.roxy-rs-login-note{padding:14px 16px;border-radius:14px;background:rgba(255,255,255,.08)}.roxy-rs-notice{padding:12px 14px;border-radius:12px;margin:10px 0}.roxy-rs-notice-success{background:#dff7e8;color:#114b24}.roxy-rs-notice-error{background:#fbe4e4;color:#6a1717}.roxy-rs-progress{height:10px;border-radius:999px;background:rgba(255,255,255,.1);overflow:hidden;margin:12px 0 10px}.roxy-rs-progress-bar{height:100%;background:#ffcf35}.roxy-rs-check{display:flex;gap:10px;align-items:flex-start}.roxy-rs-check input{width:auto;margin-top:4px}.roxy-rs-target-note{font-size:13px;color:#bbb}@media (max-width: 960px){.roxy-rs-info-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width: 700px){.roxy-rs-card{grid-template-columns:1fr}.roxy-rs-two{grid-template-columns:1fr}.roxy-rs-hero h1{font-size:clamp(34px,10vw,52px)}.roxy-rs-hero>p{font-size:16px}.roxy-rs-info-grid{grid-template-columns:1fr}}';
        wp_register_style('roxy-rs-inline', false, [], ROXY_RS_VERSION);
        wp_enqueue_style('roxy-rs-inline');
        wp_add_inline_style('roxy-rs-inline', $css);
    }

    private static function enqueue_form_script(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        wp_register_script(
            'roxy-rs-form',
            ROXY_RS_URL . 'assets/roxy-rs.js',
            [],
            ROXY_RS_VERSION,
            true
        );
        wp_enqueue_script('roxy-rs-form');
        wp_localize_script('roxy-rs-form', 'RoxyRS', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('roxy_rs_availability'),
            'minLeadDays' => Settings::min_lead_days(),
        ]);
    }

    private static function validate_requested_slot(string $target_at) {
        $target_dt = self::parse_local_datetime($target_at);
        if (!$target_dt) {
            return new \WP_Error('invalid_target', 'Choose a valid target showtime.');
        }

        $date = $target_dt->format('Y-m-d');
        foreach (self::available_showtimes_for_date($date) as $slot) {
            if ((string) ($slot['target_at'] ?? '') === $target_at) {
                return $slot;
            }
        }

        return new \WP_Error('slot_unavailable', 'That showtime is no longer available. Please choose another date or time.');
    }

    private static function available_showtimes_for_date(string $date): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }

        if (
            !function_exists('roxy_eb_get_settings')
            || !function_exists('roxy_eb_parse_hhmm')
            || !function_exists('roxy_eb_calc_times')
            || !function_exists('roxy_eb_time_within_operating_hours')
            || !function_exists('roxy_eb_is_slot_available')
        ) {
            return [];
        }

        $settings = roxy_eb_get_settings();
        $timezone = wp_timezone();
        try {
            $day = new \DateTimeImmutable($date . ' 00:00:00', $timezone);
        } catch (\Throwable $e) {
            return [];
        }

        $slots = [];
        $increment = max(5, (int) ($settings['time_increment_minutes'] ?? 15));
        $open = roxy_eb_parse_hhmm((string) ($settings['open_time'] ?? '08:00'));
        $close = roxy_eb_parse_hhmm((string) ($settings['close_time'] ?? '24:00'));
        if (!$open || !$close) {
            return [];
        }

        [$open_h, $open_m] = $open;
        [$close_h, $close_m] = $close;
        $open_minutes = ($open_h * 60) + $open_m;
        $close_minutes = ($close_h === 24 && $close_m === 0) ? 1440 : (($close_h * 60) + $close_m);

        $guest_hours = 2;
        $last_start = max($open_minutes, $close_minutes - ($guest_hours * 60));

        for ($minutes = $open_minutes; $minutes <= $last_start; $minutes += $increment) {
            $doors_open = $day->setTime((int) floor($minutes / 60), $minutes % 60);
            if (!roxy_eb_time_within_operating_hours($doors_open, $guest_hours)) {
                continue;
            }

            $times = roxy_eb_calc_times($doors_open, 0);
            if (!roxy_eb_is_slot_available($times['reserved_start'], $times['reserved_end'])) {
                continue;
            }

            $target_dt = $times['show_start'];
            $target_at = $target_dt->format('Y-m-d\TH:i');
            if (self::slot_conflicts(
                $times['reserved_start']->format('Y-m-d H:i:s'),
                $times['reserved_end']->format('Y-m-d H:i:s'),
                $target_at
            )) {
                continue;
            }

            $profile = ((int) $target_dt->format('G') < 17) ? 'movie_matinee' : 'movie_evening';
            $deadline_at = self::deadline_for_target($target_at);
            $deadline_dt = self::parse_local_datetime($deadline_at);

            $slots[] = [
                'target_at' => $target_at,
                'profile' => $profile,
                'label' => $target_dt->format('l, F j') . ' | ' . $target_dt->format('g:i A') . ' | ' . ($profile === 'movie_matinee' ? 'Matinee' : 'Evening'),
                'deadline_at' => $deadline_dt ? $deadline_dt->format('Y-m-d\TH:i') : $deadline_at,
            ];
        }

        return $slots;
    }

    private static function slot_conflicts(string $range_start, string $range_end, string $target_at): bool {
        if ($range_start === '' || $range_end === '') {
            return true;
        }

        if (function_exists('roxy_eb_repo_list_bookings_in_range')) {
            $bookings = roxy_eb_repo_list_bookings_in_range($range_start, $range_end);
            if (!empty($bookings)) {
                return true;
            }
        }

        if (function_exists('roxy_eb_repo_list_blocks_in_range')) {
            $blocks = roxy_eb_repo_list_blocks_in_range($range_start, $range_end);
            if (!empty($blocks)) {
                return true;
            }
        }

        if (class_exists('\\RoxyST\\CPT')) {
            $range_start_dt = self::parse_local_datetime($range_start);
            $range_end_dt = self::parse_local_datetime($range_end);
            $showings = get_posts([
                'post_type' => \RoxyST\CPT::POST_TYPE,
                'post_status' => ['publish', 'draft', 'future', 'private'],
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => '_roxy_start',
                'meta_query' => [[
                    'key' => '_roxy_start',
                    'value' => [
                        $range_start_dt ? $range_start_dt->format('Y-m-d\TH:i') : $range_start,
                        $range_end_dt ? $range_end_dt->format('Y-m-d\TH:i') : $range_end,
                    ],
                    'compare' => 'BETWEEN',
                    'type' => 'CHAR',
                ]],
            ]);
            if (!empty($showings)) {
                return true;
            }
        }

        $requests = get_posts([
            'post_type' => CPT::POST_TYPE,
            'post_status' => ['publish', 'draft', 'future', 'private'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => CPT::META_TARGET_AT,
                    'value' => $target_at,
                    'compare' => '=',
                ],
                [
                    'key' => CPT::META_STATUS,
                    'value' => ['pending_review', 'active', 'threshold_met', 'approved'],
                    'compare' => 'IN',
                ],
            ],
        ]);
        return !empty($requests);
    }

    private static function deadline_for_target(string $target_at): string {
        $target_dt = self::parse_local_datetime($target_at);
        if (!$target_dt) {
            return '';
        }

        return $target_dt->modify('-' . Settings::deadline_days_before_target() . ' days')->format('Y-m-d\TH:i');
    }

    private static function backing_lock_key(int $request_id, int $user_id, array $payload): string {
        $normalized = wp_json_encode([
            'request_id' => $request_id,
            'user_id' => $user_id,
            'payload' => $payload,
        ]);

        return 'roxy_rs_backing_' . md5((string) $normalized);
    }

    public static function current_site_datetime(): \DateTimeImmutable {
        return current_datetime();
    }

    public static function parse_local_datetime(string $value): ?\DateTimeImmutable {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timezone = wp_timezone();
        $formats = ['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }

        try {
            return new \DateTimeImmutable(str_replace('T', ' ', $value), $timezone);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
