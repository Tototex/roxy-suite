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
        $lease = null;
        try {
            if ($request_id <= 0 || !class_exists(ConversionClaims::class)) throw new \RuntimeException('Conversion safety checks are unavailable.');
            $lease = ConversionClaims::lease('request:' . $request_id);
            wp_cache_delete($request_id, 'post_meta');
            return self::approve_request_owned($request_id, $lease);
        } catch (\Throwable $error) {
            return new \WP_Error('conversion_review_required', 'Conversion is busy or could not be verified. Review saved showing, order and payment records before retrying.');
        } finally {
            if ($lease !== null) $lease->release_lease();
        }
    }

    private static function approve_request_owned(int $request_id, \RoxyST\Issuance $lease) {
        $lease->assert_owner();
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

        // Close pledging under the same lease held by repository INSERTs.
        update_post_meta($request_id, CPT::META_STATUS, 'conversion_review');
        wp_cache_delete($request_id, 'post_meta');
        if (get_post_meta($request_id, CPT::META_STATUS, true) !== 'conversion_review') {
            return new \WP_Error('request_close_failed', 'The request could not be closed for conversion. No new showing or payment was attempted.');
        }
        $lease->assert_owner();
        $showing_id = (int) get_post_meta($request_id, CPT::META_APPROVED_SHOWING_ID, true);
        if ($showing_id <= 0 || get_post_type($showing_id) !== \RoxyST\CPT::POST_TYPE) {
            // A missing prior showing must not be silently replaced.
            if ($showing_id > 0) return new \WP_Error('showing_review_required', 'The linked showing is missing. Reconcile it before creating another.');
            ConversionClaims::begin_creation($request_id, 'showing');
            $lease->assert_owner();
            $showing_id = wp_insert_post([
                'post_type' => \RoxyST\CPT::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => CPT::public_excerpt((string) $post->post_excerpt),
            ], true);
            if (is_wp_error($showing_id)) {
                return $showing_id;
            }
            $lease->assert_owner();
            update_post_meta($request_id, CPT::META_APPROVED_SHOWING_ID, $showing_id);
            wp_cache_delete($request_id, 'post_meta');
            if ((int) get_post_meta($request_id, CPT::META_APPROVED_SHOWING_ID, true) !== (int) $showing_id) {
                return new \WP_Error('showing_link_failed', 'The showing link could not be verified. Review it before retrying.');
            }
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
        $needs_review = false;
        foreach ($backings as $backing) {
            $lease->assert_owner();
            $result = self::convert_backing_to_order($request_id, $showing_id, $backing);
            if (is_wp_error($result)) {
                $needs_review = true;
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

        $lease->assert_owner();
        if ($needs_review) return new \WP_Error('backing_review_required', 'The showing exists, but one or more backings require review. Customer payment confirmation was not sent.');
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
        global $wpdb;
        $wpdb->last_error = '';
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
        if ($wpdb->last_error !== '' || !is_array($posts)) {
            throw new \RuntimeException('Requested-showing daily review could not verify its request list.');
        }

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
        $lease = null;
        try {
            $id = (int) ($backing['id'] ?? 0);
            if ($id <= 0 || !class_exists(ConversionClaims::class)) throw new \RuntimeException('Backing conversion identity is unavailable.');
            $lease = ConversionClaims::lease('backing:' . $id);
            $fresh = roxy_rs_repo_get_backing($id);
            if (!$fresh || (int) ($fresh['request_id'] ?? 0) !== $request_id
                || !in_array($fresh['status'] ?? '', ['pending', 'threshold_met', 'approved'], true)) {
                return new \WP_Error('backing_review_required', 'The saved backing changed or could not be verified. Review it before retrying.');
            }
            $lease->assert_owner();
            return self::convert_backing_owned($request_id, $showing_id, $fresh, $lease);
        } catch (\Throwable $error) {
            return new \WP_Error('backing_review_required', 'Backing conversion is busy or requires reconciliation. Review saved orders and payments before retrying.');
        } finally {
            if ($lease !== null) $lease->release_lease();
        }
    }

    private static function convert_backing_owned(int $request_id, int $showing_id, array $backing, \RoxyST\Issuance $lease) {
        $lease->assert_owner();
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

        if (!function_exists('roxy_rs_repo_canonical_quantity')) return new \WP_Error('invalid_backing_quantity', 'Backing ticket quantities could not be verified.');
        $quantities = [];
        foreach (['general_qty', 'discount_qty', 'subscriber_qty', 'sponsor_ticket_qty'] as $quantity_key) {
            $quantity = roxy_rs_repo_canonical_quantity($backing[$quantity_key] ?? 0);
            if ($quantity === null) return new \WP_Error('invalid_backing_quantity', 'Backing ticket quantities could not be verified.');
            $quantities[$quantity_key] = $quantity;
        }
        $general_qty = $quantities['general_qty'];
        $discount_qty = $quantities['discount_qty'];
        $subscriber_qty = $quantities['subscriber_qty'];
        $sponsor_tickets = $quantities['sponsor_ticket_qty'];
        if ($profile === 'movie_matinee' && $discount_qty > 0) return new \WP_Error('invalid_backing_quantity', 'Matinee backing contains an unsupported ticket quantity.');
        $expected_ticket_qty = 0;
        $expected_lines = $profile === 'movie_matinee'
            ? [$general_qty, $subscriber_qty, $sponsor_tickets]
            : [$general_qty, $discount_qty, $subscriber_qty, $sponsor_tickets];
        foreach ($expected_lines as $line_qty) {
            if ($line_qty > PHP_INT_MAX - $expected_ticket_qty) return new \WP_Error('invalid_backing_quantity', 'Backing ticket quantities exceed the safe limit.');
            $expected_ticket_qty += $line_qty;
        }
        $subscriber_product = null;
        if ($subscriber_qty > 0) {
            if ($user_id <= 0 || empty($products['subscriber'])) return new \WP_Error('subscriber_product_unavailable', 'Subscriber eligibility or ticket mapping could not be verified.');
            $subscriber_product = wc_get_product($products['subscriber']);
            if (!$subscriber_product) return new \WP_Error('subscriber_product_unavailable', 'Subscriber eligibility or ticket mapping could not be verified.');
            if (!class_exists('\\RoxyST\\Capacity') || !method_exists('\\RoxyST\\Capacity', 'subscription_entitlement_count')) {
                return new \WP_Error('subscriber_entitlement_unavailable', 'Subscriber eligibility could not be verified.');
            }
        }

        $order = $existing_order_id > 0 ? wc_get_order($existing_order_id) : false;
        if ($existing_order_id > 0 && !$order) return new \WP_Error('existing_order_missing', 'The backing order could not be verified. Review it before retrying.');
        if ($order) {
            if (!method_exists($order, 'get_id') || (int) $order->get_id() !== $existing_order_id
                || !method_exists($order, 'get_customer_id') || (int) $order->get_customer_id() !== $user_id
                || !method_exists($order, 'get_meta')
                || (int) $order->get_meta('_roxy_rs_request_id', true) !== $request_id
                || (int) $order->get_meta('_roxy_rs_backing_id', true) !== $backing_id) {
                return new \WP_Error('existing_order_identity_mismatch', 'The backing order identity does not match this request. Review it before retrying.');
            }
            if (method_exists($order, 'is_paid') && $order->is_paid()) {
                return new \WP_Error('existing_order_already_paid', 'The backing order is already paid. Do not charge it again; reconcile the backing record.');
            }
        }

        if ($subscriber_qty > 0) {
            if (!class_exists('\\RoxyST\\Capacity') || !class_exists('\\RoxyST\\Reservations')) {
                return new \WP_Error('subscriber_entitlement_unavailable', 'Subscriber entitlement and use could not be verified.');
            }
            try {
                $entitlement = \RoxyST\Capacity::subscription_entitlement_count($user_id);
                $used = \RoxyST\Reservations::quantity_for_showing($showing_id, $existing_order_id, $user_id);
                $walkups = class_exists('\\Roxy_Sub_Check') ? (int) \Roxy_Sub_Check::walkup_quantity_for_showing($showing_id, $user_id) : 0;
                if ($entitlement <= 0) return new \WP_Error('subscriber_entitlement_missing', 'The backing owner no longer has an eligible subscriber membership.');
                if ($subscriber_qty > $entitlement - $used - $walkups) return new \WP_Error('subscriber_entitlement_exceeded', 'The backing owner no longer has enough subscriber entitlement for this showing.');
            } catch (\Throwable $error) {
                return new \WP_Error('subscriber_entitlement_unavailable', 'Subscriber entitlement and use could not be verified.');
            }
        }

        if (!$order) {
            ConversionClaims::begin_creation($request_id, 'order', $backing_id);
            $lease->assert_owner();
            $order = wc_create_order(['customer_id' => $user_id]);
            if (is_wp_error($order)) {
                return $order;
            }

            self::apply_customer_details($order, $user_id);

            if ($profile === 'movie_matinee') {
                $qty = $general_qty;
                if ($qty > 0 && !empty($products['matinee'])) {
                    $order->add_product(wc_get_product($products['matinee']), $qty);
                }
            } else {
                if ($general_qty > 0 && !empty($products['adult'])) {
                    $order->add_product(wc_get_product($products['adult']), $general_qty);
                }
                if ($discount_qty > 0 && !empty($products['discount'])) {
                    $order->add_product(wc_get_product($products['discount']), $discount_qty);
                }
            }

            if ($subscriber_qty > 0 && !empty($products['subscriber'])) {
                $order->add_product($subscriber_product, $subscriber_qty);
            }

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
            $lease->assert_owner();
            if ((int) $order->get_id() <= 0 || (int) $order->get_customer_id() !== $user_id
                || (int) $order->get_meta('_roxy_rs_request_id', true) !== $request_id
                || (int) $order->get_meta('_roxy_rs_backing_id', true) !== $backing_id) {
                return new \WP_Error('new_order_identity_mismatch', 'The saved order identity could not be verified. Review it before retrying.');
            }
            $linked = roxy_rs_repo_update_backing($backing_id, [
                'woo_order_id' => (int) $order->get_id(),
                'approved_showing_id' => $showing_id,
            ]);
            if (is_wp_error($linked)) return $linked;
            $saved_backing = roxy_rs_repo_get_backing($backing_id);
            if (!$saved_backing || (int) ($saved_backing['woo_order_id'] ?? 0) !== (int) $order->get_id()
                || (int) ($saved_backing['request_id'] ?? 0) !== $request_id
                || (int) ($saved_backing['user_id'] ?? 0) !== $user_id) {
                return new \WP_Error('backing_order_link_failed', 'The backing order could not be durably linked. Review it before retrying.');
            }
        }

        $lease->assert_owner();
        $ticket_items = method_exists($order, 'get_items') ? $order->get_items('line_item') : [];
        if (!is_array($ticket_items) || ($expected_ticket_qty > 0 && !$ticket_items) || ($expected_ticket_qty === 0 && $ticket_items)) {
            return new \WP_Error('ticket_order_unverified', 'The saved ticket lines do not match the backing. No payment was attempted.');
        }
        $persisted_ticket_qty = 0;
        foreach ($ticket_items as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id') || !method_exists($item, 'get_quantity')
                || (int) get_post_meta((int) $item->get_product_id(), \ROXY_ST_META_SHOWING_ID, true) !== $showing_id) {
                return new \WP_Error('ticket_order_showing_mismatch', 'The backing order contains tickets for a different showing. No payment was attempted.');
            }
            $line_qty = $item->get_quantity();
            if (!is_numeric($line_qty) || (float) $line_qty <= 0 || (float) $line_qty !== (float) (int) $line_qty || (int) $line_qty > PHP_INT_MAX - $persisted_ticket_qty) {
                return new \WP_Error('ticket_order_unverified', 'The saved ticket quantities could not be verified. No payment was attempted.');
            }
            $persisted_ticket_qty += (int) $line_qty;
        }
        if ($persisted_ticket_qty !== $expected_ticket_qty) return new \WP_Error('ticket_order_unverified', 'The saved ticket lines do not match the backing. No payment was attempted.');
        if ($subscriber_qty > 0 && (!$ticket_items || !is_array($ticket_items))) {
            return new \WP_Error('subscriber_order_unverified', 'Subscriber tickets could not be verified on the saved order. No payment was attempted.');
        }
        if ($subscriber_qty > 0) {
            $persisted_subscriber_qty = 0;
            foreach ($ticket_items as $item) {
                if ((int) $item->get_product_id() === (int) $products['subscriber']) {
                    $line_qty = $item->get_quantity();
                    $persisted_subscriber_qty += (int) $line_qty;
                }
            }
            if ($persisted_subscriber_qty !== $subscriber_qty) return new \WP_Error('subscriber_order_unverified', 'Subscriber tickets could not be verified on the saved order. No payment was attempted.');
        }
        if (is_array($ticket_items) && $ticket_items) {
            if (!class_exists('\\RoxyST\\Holds') || !method_exists('\\RoxyST\\Holds', 'claim')) {
                return new \WP_Error('seat_claim_unavailable', 'Ticket capacity could not be safely reserved. No payment was attempted.');
            }
            try {
                if (!\RoxyST\Holds::claim($order)) return new \WP_Error('seat_claim_failed', 'Ticket capacity could not be safely reserved. No payment was attempted.');
            } catch (\Throwable $error) {
                return new \WP_Error('seat_claim_failed', 'Ticket capacity could not be safely reserved. No payment was attempted.');
            }
        }

        $charge_total = (int) ($backing['charge_total'] ?? 0);
        $lease->assert_owner();
        if ($charge_total > 0) {
            $token_id = (int) ($backing['payment_token_id'] ?? 0);
            $charge = self::charge_order_with_saved_token($order, $user_id, $token_id);
            if (is_wp_error($charge)) {
                // An uncertain provider response is not proof that no money
                // moved. Preserve the order for reconciliation, not recharging.
                $order->update_meta_data('_roxy_seat_review', 1);
                $order->update_status('on-hold', 'Requested showing payment requires manager review: ' . $charge->get_error_message());
                return $charge;
            }
            $lease->assert_owner();
            $recorded = roxy_rs_repo_update_backing($backing_id, [
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
            $lease->assert_owner();
            $recorded = roxy_rs_repo_update_backing($backing_id, [
                'status' => 'charged',
                'approved_showing_id' => $showing_id,
                'woo_order_id' => $order->get_id(),
                'charge_intent_id' => 'no-charge',
            ]);
        }

        $lease->assert_owner();
        if (is_wp_error($recorded)) return new \WP_Error('backing_result_unrecorded', 'Payment or no-charge completion occurred, but the backing record needs reconciliation. Do not charge it again.');
        $completed_backing = roxy_rs_repo_get_backing($backing_id);
        $expected_intent = $charge_total > 0 ? (string) ($charge['intent_id'] ?? '') : 'no-charge';
        if (!$completed_backing || ($completed_backing['status'] ?? '') !== 'charged'
            || (int) ($completed_backing['woo_order_id'] ?? 0) !== (int) $order->get_id()
            || (int) ($completed_backing['approved_showing_id'] ?? 0) !== $showing_id
            || (string) ($completed_backing['charge_intent_id'] ?? '') !== $expected_intent) {
            return new \WP_Error('backing_result_unverified', 'Payment or no-charge completion occurred, but its backing link could not be verified. Do not charge it again.');
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

        $total = (float) $order->get_total();
        if (!is_finite($total) || $total <= 0 || $total > 21474836.47) {
            return new \WP_Error('payment_review_required', 'The saved order amount requires review. No payment was attempted.');
        }
        $amount = (int) round($total * 100);
        $request = [
                'amount' => $amount,
                'currency' => strtolower((string) $order->get_currency()),
                'customer' => $stripe_customer_id,
                'payment_method' => $payment_method,
                'confirm' => 'true',
                'off_session' => 'true',
                'description' => sprintf('Requested showing approval order #%d', $order->get_id()),
                'metadata[order_id]' => (string) $order->get_id(),
        ];
        if (!class_exists(PaymentAttempts::class)) return new \WP_Error('payment_review_required', 'Payment safety checks are unavailable. No payment was attempted.');
        try {
            $claim = PaymentAttempts::claim($order, [
                'request_id' => (int) $order->get_meta('_roxy_rs_request_id', true),
                'backing_id' => (int) $order->get_meta('_roxy_rs_backing_id', true),
                'customer_id' => $user_id,
                'amount' => $amount,
                'currency' => $request['currency'],
            ]);
            $request_hash = hash('sha256', wp_json_encode($request));
            $idempotency = static function ($default, $body) use ($claim, $request_hash) {
                return is_array($body) && hash_equals($request_hash, hash('sha256', wp_json_encode($body))) ? $claim['key'] : $default;
            };
            add_filter('wc_stripe_idempotency_key', $idempotency, PHP_INT_MAX, 2);
            try {
                if (!PaymentAttempts::verify($order, $claim)) throw new \RuntimeException('Payment attempt could not be verified.');
                $intent = \WC_Stripe_API::request($request, 'payment_intents');
            } finally {
                remove_filter('wc_stripe_idempotency_key', $idempotency, PHP_INT_MAX);
            }
            if (is_wp_error($intent) || !is_object($intent) || !empty($intent->error)
                || !is_string($intent->id ?? null) || !preg_match('/^pi_[A-Za-z0-9]+$/D', $intent->id)
                || !is_string($intent->status ?? null) || strlen($intent->status) > 64
                || !is_int($intent->amount ?? null) || $intent->amount !== $amount
                || !is_string($intent->currency ?? null) || strtolower($intent->currency) !== $request['currency']
                || ($intent->customer ?? null) !== $stripe_customer_id
                || (string) ($intent->metadata->order_id ?? '') !== (string) $order->get_id()) {
                return new \WP_Error('payment_review_required', 'The payment result could not be confirmed. Review the saved order and provider records before retrying.');
            }
            if (!PaymentAttempts::record_result($order, $claim, $intent->id, $intent->status)) {
                return new \WP_Error('payment_review_required', 'The payment result could not be safely recorded. Review the saved order and provider records; do not recharge it.');
            }
            if ($intent->status !== 'succeeded' || !is_int($intent->amount_received ?? null) || $intent->amount_received !== $amount) {
                $order->add_order_note('Requested showing payment review: provider intent ' . $intent->id . ' returned ' . $intent->status . '. No automatic retry will be attempted.');
                return new \WP_Error('payment_review_required', 'Payment is not confirmed as captured. Review the saved order and provider records; do not recharge it.');
            }
            $order->set_payment_method('stripe');
            $order->set_payment_method_title('Credit / Debit Card');
            $order->set_transaction_id($intent->id);
            $order->save();
            $order->payment_complete($intent->id);
            $order->add_order_note('Requested showing backing charged off-session from saved payment method.');
            return ['intent_id' => $intent->id];
        } catch (\Throwable $error) {
            return new \WP_Error('payment_review_required', 'Payment requires reconciliation. Review the saved order and provider records before any further payment attempt.');
        }
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
