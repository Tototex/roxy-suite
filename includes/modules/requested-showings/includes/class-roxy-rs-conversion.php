<?php
namespace RoxyRS;

if (!defined('ABSPATH')) {
    exit;
}

class Conversion {
    public static function init(): void {
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_to_showing'], 1);
        add_action('admin_post_roxy_rs_activate_request', [__CLASS__, 'handle_activate_request']);
        add_action('admin_post_roxy_rs_approve_request', [__CLASS__, 'handle_approve_request']);
        add_action('admin_post_roxy_rs_fail_request', [__CLASS__, 'handle_fail_request']);
        add_action('post_submitbox_misc_actions', [__CLASS__, 'render_submitbox_actions']);
    }

    public static function maybe_redirect_to_showing(): void {
        if (!is_singular(CPT::POST_TYPE)) {
            return;
        }
        $post_id = get_queried_object_id();
        if (!$post_id) {
            return;
        }
        $showing_id = (int) get_post_meta($post_id, CPT::META_APPROVED_SHOWING_ID, true);
        if ($showing_id > 0 && get_post_status($showing_id)) {
            wp_safe_redirect(get_permalink($showing_id), 301);
            exit;
        }
    }

    public static function render_submitbox_actions(): void {
        global $post;
        if (!$post || $post->post_type !== CPT::POST_TYPE || !current_user_can('edit_post', $post->ID)) {
            return;
        }

        echo '<div class="misc-pub-section">';
        echo self::actions_markup((int) $post->ID);
        echo '</div>';
    }

    public static function actions_markup(int $request_id): string {
        $activate_url = wp_nonce_url(admin_url('admin-post.php?action=roxy_rs_activate_request&request_id=' . $request_id), 'roxy_rs_activate_' . $request_id);
        $approve_url = wp_nonce_url(admin_url('admin-post.php?action=roxy_rs_approve_request&request_id=' . $request_id), 'roxy_rs_approve_' . $request_id);
        $fail_url = wp_nonce_url(admin_url('admin-post.php?action=roxy_rs_fail_request&request_id=' . $request_id), 'roxy_rs_fail_' . $request_id);

        $html  = '<div class="roxy-rs-action-wrap">';
        $html .= '<strong>Requested Showing Actions</strong><br>';
        $html .= '<div class="roxy-rs-action-buttons">';
        $html .= '<a class="button" href="' . esc_url($activate_url) . '">Approve Request</a>';
        $html .= '<a class="button button-primary" href="' . esc_url($approve_url) . '">Convert to Showing</a>';
        $html .= '<a class="button" href="' . esc_url($fail_url) . '">Mark Failed</a>';
        $html .= '</div>';
        $html .= '<p class="description">Use <strong>Approve Request</strong> to make the request public and let customers start backing it. Use <strong>Convert to Showing</strong> when you are ready to turn it into a real showing and charge backers.</p>';
        $html .= '</div>';

        return $html;
    }

    public static function handle_activate_request(): void {
        $request_id = max(0, (int) ($_GET['request_id'] ?? 0));
        if (!$request_id || !current_user_can('edit_post', $request_id)) {
            wp_die('Permission denied.');
        }
        check_admin_referer('roxy_rs_activate_' . $request_id);

        update_post_meta($request_id, CPT::META_STATUS, 'active');
        wp_update_post([
            'ID' => $request_id,
            'post_status' => 'publish',
        ]);

        $requester = self::requester_contact($request_id);
        if ($requester['email'] !== '') {
            $subject = sprintf('Your requested showing is now live: %s', get_the_title($request_id));
            $message = "Good news - your requested showing is now live and ready for support.\n\n"
                . "Title: " . get_the_title($request_id) . "\n"
                . "Share page: " . get_permalink($request_id) . "\n\n"
                . "You can now share this page so people can back the showing or sponsor it.\n\n"
                . "Thanks,\nNewport Roxy Theater";
            self::email_recipients([$requester['email']], $subject, $message);
        }

        wp_safe_redirect(get_edit_post_link($request_id, ''));
        exit;
    }

    public static function handle_fail_request(): void {
        $request_id = max(0, (int) ($_GET['request_id'] ?? 0));
        if (!$request_id || !current_user_can('edit_post', $request_id)) {
            wp_die('Permission denied.');
        }
        check_admin_referer('roxy_rs_fail_' . $request_id);
        self::mark_failed($request_id);
        wp_safe_redirect(get_edit_post_link($request_id, ''));
        exit;
    }

    public static function handle_approve_request(): void {
        $request_id = max(0, (int) ($_GET['request_id'] ?? 0));
        if (!$request_id || !current_user_can('edit_post', $request_id)) {
            wp_die('Permission denied.');
        }
        check_admin_referer('roxy_rs_approve_' . $request_id);
        $result = self::approve_request($request_id);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
        wp_safe_redirect(get_edit_post_link($request_id, ''));
        exit;
    }

    public static function maybe_mark_request_ready(int $request_id): void {
        $status = CPT::get_status($request_id);
        if (!in_array($status, ['active', 'threshold_met'], true)) {
            return;
        }

        $totals = roxy_rs_repo_backing_totals($request_id);
        $goal = CPT::funding_goal_cents($request_id);
        $ready = !empty($totals['has_sponsor']) || (int) $totals['charge_total'] >= $goal;
        if (!$ready) {
            return;
        }

        update_post_meta($request_id, CPT::META_STATUS, 'threshold_met');
        if (!get_post_meta($request_id, CPT::META_TARGET_NOTIFIED, true)) {
            self::email_admin(
                sprintf('Requested showing reached funding goal: %s', get_the_title($request_id)),
                "The requested showing \"" . get_the_title($request_id) . "\" has reached its funding goal and is ready for manager review.\n\nReview: " . admin_url('post.php?post=' . $request_id . '&action=edit')
            );
            update_post_meta($request_id, CPT::META_TARGET_NOTIFIED, current_time('mysql'));
        }
    }

    public static function approve_request(int $request_id) {
        $post = get_post($request_id);
        if (!$post || $post->post_type !== CPT::POST_TYPE) {
            return new \WP_Error('missing_request', 'Requested showing not found.');
        }

        if (!class_exists('\\WooCommerce') || !class_exists('\\RoxyST\\Products') || !class_exists('\\RoxyST\\Tickets')) {
            return new \WP_Error('dependencies_missing', 'WooCommerce and Show Tickets must be active before approval.');
        }

        $target_at = (string) get_post_meta($request_id, CPT::META_TARGET_AT, true);
        if ($target_at === '') {
            return new \WP_Error('missing_target', 'Set the target showtime before approval.');
        }

        $showing_id = (int) get_post_meta($request_id, CPT::META_APPROVED_SHOWING_ID, true);
        if ($showing_id <= 0 || get_post_type($showing_id) !== \RoxyST\CPT::POST_TYPE) {
            $showing_id = wp_insert_post([
                'post_type' => \RoxyST\CPT::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
            ], true);
            if (is_wp_error($showing_id)) {
                return $showing_id;
            }
            update_post_meta($request_id, CPT::META_APPROVED_SHOWING_ID, $showing_id);
            update_post_meta($showing_id, '_roxy_rs_request_id', $request_id);
            $thumbnail_id = get_post_thumbnail_id($request_id);
            if ($thumbnail_id) {
                set_post_thumbnail($showing_id, $thumbnail_id);
            }
        }

        update_post_meta($showing_id, '_roxy_start', $target_at);
        update_post_meta($showing_id, '_roxy_pricing_profile', (string) get_post_meta($request_id, CPT::META_PRICING_PROFILE, true) ?: 'movie_evening');
        update_post_meta($showing_id, '_roxy_trailer_url', (string) get_post_meta($request_id, CPT::META_TRAILER_URL, true));

        \RoxyST\Products::ensure_products_for_showing($showing_id);

        $backings = roxy_rs_repo_list_backings_for_request($request_id, ['pending', 'threshold_met', 'approved']);
        foreach ($backings as $backing) {
            $result = self::convert_backing_to_order($request_id, $showing_id, $backing);
            if (is_wp_error($result)) {
                roxy_rs_repo_update_backing((int) $backing['id'], [
                    'status' => 'approved',
                    'approved_showing_id' => $showing_id,
                    'admin_note' => $result->get_error_message(),
                ]);
                self::email_admin(
                    sprintf('Requested showing approval needs attention: %s', get_the_title($request_id)),
                    "Approval created the showing, but one backing could not be charged automatically.\n\nRequest: " . get_the_title($request_id) . "\nBacking ID: " . (int) $backing['id'] . "\nError: " . $result->get_error_message()
                );
                continue;
            }
        }

        update_post_meta($request_id, CPT::META_STATUS, 'approved');
        wp_update_post([
            'ID' => $request_id,
            'post_status' => 'publish',
        ]);

        $emails = self::request_recipient_emails($request_id, $backings);
        if ($emails) {
            $subject = sprintf('Requested showing confirmed: %s', get_the_title($request_id));
            $message = "Your requested showing has been confirmed and converted into a real Roxy showing.\n\n"
                . "Title: " . get_the_title($request_id) . "\n"
                . "Showing page: " . get_permalink($showing_id) . "\n\n"
                . "Any saved backing cards have now been processed, and the public showing page is live.\n\n"
                . "Thanks for helping make it happen,\nNewport Roxy Theater";
            self::email_recipients($emails, $subject, $message);
        }

        return $showing_id;
    }

    public static function run_daily_review(): void {
        $posts = get_posts([
            'post_type' => CPT::POST_TYPE,
            'post_status' => ['publish', 'draft'],
            'posts_per_page' => -1,
            'meta_query' => [[
                'key' => CPT::META_STATUS,
                'value' => ['active', 'threshold_met', 'pending_review'],
                'compare' => 'IN',
            ]],
        ]);

        foreach ($posts as $post) {
            $request_id = (int) $post->ID;
            self::maybe_mark_request_ready($request_id);

            $status = CPT::get_status($request_id);
            if (!in_array($status, ['active', 'threshold_met'], true)) {
                continue;
            }

            $deadline_at = (string) get_post_meta($request_id, CPT::META_DEADLINE_AT, true);
            if ($deadline_at === '') {
                continue;
            }

            $deadline_dt = Frontend::parse_local_datetime($deadline_at);
            if (!$deadline_dt) {
                continue;
            }

            $now = Frontend::current_site_datetime();

            if ($deadline_dt <= $now && $status !== 'threshold_met') {
                $totals = roxy_rs_repo_backing_totals($request_id);
                $goal = CPT::funding_goal_cents($request_id);
                if (empty($totals['has_sponsor']) && (int) $totals['charge_total'] < $goal) {
                    self::mark_failed($request_id);
                }
                continue;
            }

            $review_notice = get_post_meta($request_id, CPT::META_REVIEW_NOTIFIED, true);
            if (!$review_notice && $deadline_dt <= $now->modify('+3 days')) {
                self::email_admin(
                    sprintf('Requested showing deadline approaching: %s', get_the_title($request_id)),
                    "The requested showing \"" . get_the_title($request_id) . "\" is nearing its deadline.\n\nReview: " . admin_url('post.php?post=' . $request_id . '&action=edit')
                );
                update_post_meta($request_id, CPT::META_REVIEW_NOTIFIED, current_time('mysql'));
            }
        }
    }

    public static function mark_failed(int $request_id): void {
        update_post_meta($request_id, CPT::META_STATUS, 'failed');
        update_post_meta($request_id, CPT::META_FAILURE_NOTIFIED, current_time('mysql'));

        $backings = roxy_rs_repo_list_backings_for_request($request_id, ['pending', 'threshold_met', 'approved']);
        foreach ($backings as $backing) {
            roxy_rs_repo_update_backing((int) $backing['id'], ['status' => 'failed']);
        }

        $emails = self::request_recipient_emails($request_id, $backings);
        if ($emails) {
            $subject = sprintf('Requested showing did not move forward: %s', get_the_title($request_id));
            $message = "Thanks for supporting \"" . get_the_title($request_id) . "\".\n\n"
                . "This request did not reach its funding goal before the deadline, so it has been closed and no cards were charged.\n\n"
                . "We appreciate the support,\nNewport Roxy Theater";
            self::email_recipients($emails, $subject, $message);
        }
    }

    public static function saved_payment_tokens(int $user_id): array {
        if (!class_exists('\\WC_Payment_Tokens')) {
            return [];
        }

        $tokens = \WC_Payment_Tokens::get_customer_tokens($user_id);
        $out = [];
        foreach ($tokens as $token) {
            if (!is_object($token)) {
                continue;
            }
            $gateway = method_exists($token, 'get_gateway_id') ? (string) $token->get_gateway_id() : '';
            if (strpos($gateway, 'stripe') !== 0) {
                continue;
            }
            $label = method_exists($token, 'get_display_name') ? (string) $token->get_display_name() : ('Saved card #' . (int) $token->get_id());
            $out[(int) $token->get_id()] = [
                'id' => (int) $token->get_id(),
                'label' => $label,
                'token' => method_exists($token, 'get_token') ? (string) $token->get_token() : '',
                'gateway' => $gateway,
            ];
        }
        return $out;
    }

    private static function convert_backing_to_order(int $request_id, int $showing_id, array $backing) {
        $backing_id = (int) ($backing['id'] ?? 0);
        if ($backing_id <= 0) {
            return new \WP_Error('missing_backing', 'Backing record missing.');
        }

        if (!function_exists('wc_create_order')) {
            return new \WP_Error('woo_missing', 'WooCommerce order helpers are unavailable.');
        }

        $existing_order_id = (int) ($backing['woo_order_id'] ?? 0);
        $user_id = (int) ($backing['user_id'] ?? 0);
        $products = self::showing_products($showing_id);
        $profile = (string) get_post_meta($showing_id, '_roxy_pricing_profile', true);
        $profile = $profile ?: 'movie_evening';

        $order = $existing_order_id > 0 ? wc_get_order($existing_order_id) : false;
        if (!$order) {
            $order = wc_create_order(['customer_id' => $user_id]);
            if (is_wp_error($order)) {
                return $order;
            }

            self::apply_customer_details($order, $user_id);

            if ($profile === 'movie_matinee') {
                $qty = (int) ($backing['general_qty'] ?? 0);
                if ($qty > 0 && !empty($products['matinee'])) {
                    $order->add_product(wc_get_product($products['matinee']), $qty);
                }
            } else {
                $general_qty = (int) ($backing['general_qty'] ?? 0);
                $discount_qty = (int) ($backing['discount_qty'] ?? 0);
                if ($general_qty > 0 && !empty($products['adult'])) {
                    $order->add_product(wc_get_product($products['adult']), $general_qty);
                }
                if ($discount_qty > 0 && !empty($products['discount'])) {
                    $order->add_product(wc_get_product($products['discount']), $discount_qty);
                }
            }

            $subscriber_qty = (int) ($backing['subscriber_qty'] ?? 0);
            if ($subscriber_qty > 0 && !empty($products['subscriber'])) {
                $order->add_product(wc_get_product($products['subscriber']), $subscriber_qty);
            }

            $sponsor_tickets = (int) ($backing['sponsor_ticket_qty'] ?? 0);
            if ($sponsor_tickets > 0) {
                $sponsor_product_id = $profile === 'movie_matinee' ? ($products['matinee'] ?? 0) : ($products['adult'] ?? 0);
                if ($sponsor_product_id > 0) {
                    $item_id = $order->add_product(wc_get_product($sponsor_product_id), $sponsor_tickets);
                    $item = $order->get_item($item_id);
                    if ($item) {
                        $item->set_subtotal(0);
                        $item->set_total(0);
                        $item->save();
                    }
                }
            }

            $sponsor_amount = (int) ($backing['sponsor_amount'] ?? 0);
            if ($sponsor_amount > 0) {
                $fee = new \WC_Order_Item_Fee();
                $fee->set_name('Requested showing sponsorship');
                $fee->set_amount($sponsor_amount / 100);
                $fee->set_total($sponsor_amount / 100);
                $order->add_item($fee);
            }

            $order->set_created_via('roxy_requested_showings');
            $order->add_meta_data('_roxy_rs_request_id', $request_id, true);
            $order->add_meta_data('_roxy_rs_backing_id', $backing_id, true);
            $order->calculate_totals();
            $order->save();
        }

        $charge_total = (int) ($backing['charge_total'] ?? 0);
        if ($charge_total > 0) {
            $token_id = (int) ($backing['payment_token_id'] ?? 0);
            $charge = self::charge_order_with_saved_token($order, $user_id, $token_id);
            if (is_wp_error($charge)) {
                $order->update_status('failed', 'Requested showing approval charge failed: ' . $charge->get_error_message());
                return $charge;
            }
            roxy_rs_repo_update_backing($backing_id, [
                'status' => 'charged',
                'approved_showing_id' => $showing_id,
                'woo_order_id' => $order->get_id(),
                'charge_intent_id' => (string) ($charge['intent_id'] ?? ''),
            ]);
        } else {
            $order->set_payment_method('');
            $order->set_payment_method_title('No charge');
            $order->save();
            $order->payment_complete('roxy-rs-nocharge-' . $backing_id);
            roxy_rs_repo_update_backing($backing_id, [
                'status' => 'charged',
                'approved_showing_id' => $showing_id,
                'woo_order_id' => $order->get_id(),
                'charge_intent_id' => 'no-charge',
            ]);
        }

        if (class_exists('\\RoxyST\\Tickets')) {
            \RoxyST\Tickets::sync_order_tickets($order->get_id());
        }

        return $order->get_id();
    }

    private static function showing_products(int $showing_id): array {
        return [
            'adult' => (int) get_post_meta($showing_id, '_roxy_pid_adult', true),
            'discount' => (int) get_post_meta($showing_id, '_roxy_pid_discount', true),
            'matinee' => (int) get_post_meta($showing_id, '_roxy_pid_matinee', true),
            'subscriber' => (int) get_post_meta($showing_id, '_roxy_pid_subscriber', true),
        ];
    }

    private static function apply_customer_details(\WC_Order $order, int $user_id): void {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }
        $first = get_user_meta($user_id, 'billing_first_name', true) ?: $user->first_name;
        $last = get_user_meta($user_id, 'billing_last_name', true) ?: $user->last_name;
        $phone = get_user_meta($user_id, 'billing_phone', true);
        $order->set_billing_first_name((string) $first);
        $order->set_billing_last_name((string) $last);
        $order->set_billing_email((string) $user->user_email);
        if ($phone) {
            $order->set_billing_phone((string) $phone);
        }
    }

    private static function charge_order_with_saved_token(\WC_Order $order, int $user_id, int $token_id) {
        if (!class_exists('\\WC_Stripe_API') || !class_exists('\\WC_Payment_Tokens')) {
            return new \WP_Error('stripe_missing', 'Woo Stripe is required for delayed charges.');
        }

        $token = \WC_Payment_Tokens::get($token_id);
        if (!$token || (int) $token->get_user_id() !== $user_id) {
            return new \WP_Error('token_missing', 'Saved payment method not found.');
        }

        $stripe_customer_id = (string) get_user_option('_stripe_customer_id', $user_id);
        $payment_method = method_exists($token, 'get_token') ? (string) $token->get_token() : '';
        if ($stripe_customer_id === '' || $payment_method === '') {
            return new \WP_Error('stripe_customer_missing', 'Saved Stripe customer details are missing.');
        }

        $amount = (int) round(((float) $order->get_total()) * 100);
        if ($amount <= 0) {
            return ['intent_id' => 'no-charge'];
        }

        try {
            $intent = \WC_Stripe_API::request([
                'amount' => $amount,
                'currency' => strtolower(get_woocommerce_currency()),
                'customer' => $stripe_customer_id,
                'payment_method' => $payment_method,
                'confirm' => 'true',
                'off_session' => 'true',
                'description' => sprintf('Requested showing approval order #%d', $order->get_id()),
                'metadata[order_id]' => (string) $order->get_id(),
            ], 'payment_intents');
        } catch (\Throwable $e) {
            return new \WP_Error('stripe_charge_failed', $e->getMessage());
        }

        if (empty($intent) || !empty($intent->error)) {
            return new \WP_Error('stripe_charge_failed', !empty($intent->error->message) ? (string) $intent->error->message : 'Stripe charge failed.');
        }

        if (!isset($intent->status) || !in_array((string) $intent->status, ['succeeded', 'processing', 'requires_capture'], true)) {
            return new \WP_Error('stripe_charge_failed', 'Stripe did not return a successful payment status.');
        }

        $order->set_payment_method('stripe');
        $order->set_payment_method_title('Credit / Debit Card');
        $order->set_transaction_id((string) ($intent->id ?? ''));
        $order->save();
        $order->payment_complete((string) ($intent->id ?? ''));
        $order->add_order_note('Requested showing backing charged off-session from saved payment method.');

        return ['intent_id' => (string) ($intent->id ?? '')];
    }

    private static function email_admin(string $subject, string $message): void {
        wp_mail(get_option('admin_email'), $subject, $message);
    }

    private static function email_recipients(array $emails, string $subject, string $message): void {
        $emails = array_values(array_unique(array_filter(array_map('sanitize_email', $emails))));
        foreach ($emails as $email) {
            wp_mail($email, $subject, $message);
        }
    }

    private static function request_recipient_emails(int $request_id, array $backings = []): array {
        $emails = [];
        $requester = self::requester_contact($request_id);
        if ($requester['email'] !== '') {
            $emails[] = $requester['email'];
        }

        foreach ($backings as $backing) {
            $user = get_user_by('id', (int) ($backing['user_id'] ?? 0));
            if ($user && !empty($user->user_email)) {
                $emails[] = (string) $user->user_email;
            }
        }

        return array_values(array_unique(array_filter(array_map('sanitize_email', $emails))));
    }

    private static function requester_contact(int $request_id): array {
        $name = trim((string) get_post_meta($request_id, CPT::META_REQUESTER_NAME, true));
        $email = sanitize_email((string) get_post_meta($request_id, CPT::META_REQUESTER_EMAIL, true));

        if ($name !== '' || $email !== '') {
            return [
                'name' => $name,
                'email' => $email,
            ];
        }

        $post = get_post($request_id);
        $excerpt = $post ? (string) $post->post_excerpt : '';
        if ($excerpt !== '' && preg_match('/^Requested by\s+(.+?)\s+\(([^)]+)\)$/', trim($excerpt), $matches)) {
            return [
                'name' => sanitize_text_field($matches[1]),
                'email' => sanitize_email($matches[2]),
            ];
        }

        if ($post && (int) $post->post_author > 0) {
            $user = get_user_by('id', (int) $post->post_author);
            if ($user) {
                $fallback_name = trim((string) $user->display_name);
                $fallback_email = sanitize_email((string) $user->user_email);
                return [
                    'name' => $fallback_name,
                    'email' => $fallback_email,
                ];
            }
        }

        return [
            'name' => '',
            'email' => '',
        ];
    }
}
