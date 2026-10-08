<?php
namespace RoxyRS;

if (!defined('ABSPATH')) {
    exit;
}

class Conversion {
    private const DAILY_REVIEW_RESULT_OPTION = 'roxy_rs_daily_review_last_result';

    private static function schema_is_ready(): bool {
        return !defined('ROXY_RS_SCHEMA_READY') || ROXY_RS_SCHEMA_READY === true;
    }

    public static function init(): void {
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_to_showing'], 1);
        add_action('admin_post_roxy_rs_activate_request', [__CLASS__, 'handle_activate_request']);
        add_action('admin_post_roxy_rs_approve_request', [__CLASS__, 'handle_approve_request']);
        add_action('admin_post_roxy_rs_fail_request', [__CLASS__, 'handle_fail_request']);
        add_action('post_submitbox_misc_actions', [__CLASS__, 'render_submitbox_actions']);
        add_action('add_meta_boxes_shop_order', [__CLASS__, 'register_payment_review_metabox']);
        add_filter('woocommerce_order_formatted_line_subtotal', [__CLASS__, 'format_agreed_gross_line'], 10, 3);
        add_filter('woocommerce_get_order_item_totals', [__CLASS__, 'format_agreed_gross_totals'], 10, 3);
        add_filter('woocommerce_get_formatted_order_total', [__CLASS__, 'format_agreed_gross_order_total'], 10, 4);
    }

    /** Show the whole-dollar amount the customer agreed to, not its internal tax-exclusive split. */
    public static function format_agreed_gross_line(string $formatted, $item, $order): string {
        if (!$order instanceof \WC_Order || !self::has_verified_agreement_marker($order)
            || !is_object($item) || !method_exists($item, 'get_total') || !method_exists($item, 'get_total_tax')) return $formatted;
        return wc_price((float) $item->get_total() + (float) $item->get_total_tax(), ['currency' => $order->get_currency()]);
    }

    /** Keep tax accounting private while presenting gross line, fee, and subtotal amounts to customers. */
    public static function format_agreed_gross_totals(array $rows, $order, string $tax_display): array {
        if (!$order instanceof \WC_Order || !self::has_verified_agreement_marker($order)) return $rows;

        foreach ($rows as $key => $row) {
            if (($row['type'] ?? '') === 'tax') {
                unset($rows[$key]);
            }
        }

        $gross_subtotal = 0.0;
        foreach ($order->get_items('line_item') as $item) {
            $gross_subtotal += (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();
        }
        if (isset($rows['cart_subtotal'])) {
            $rows['cart_subtotal']['value'] = wc_price($gross_subtotal, ['currency' => $order->get_currency()]);
        }
        foreach ($order->get_items('fee') as $fee) {
            $key = 'fee_' . $fee->get_id();
            if (isset($rows[$key])) {
                $rows[$key]['value'] = wc_price((float) $fee->get_total() + (float) $fee->get_total_tax(), ['currency' => $order->get_currency()]);
            }
        }
        return $rows;
    }

    /** Ensure a Woo tax-display setting cannot add tax detail to a nontaxable order total. */
    public static function format_agreed_gross_order_total(string $formatted, $order, string $tax_display = '', bool $display_refunded = true): string {
        if (!$order instanceof \WC_Order || !self::has_verified_agreement_marker($order)) return $formatted;
        $total = (float) $order->get_total();
        $gross = wc_price($total, ['currency' => $order->get_currency()]);
        $refunded = $display_refunded ? (float) $order->get_total_refunded() : 0.0;
        if ($refunded > 0) {
            $remaining = wc_price(max(0.0, $total - $refunded), ['currency' => $order->get_currency()]);
            return '<del aria-hidden="true">' . wp_strip_all_tags($gross) . '</del> <ins>' . $remaining . '</ins>';
        }
        return $gross;
    }

    private static function has_verified_agreement_marker(\WC_Order $order): bool {
        $agreement_hash = (string) $order->get_meta('_roxy_rs_agreement_hash', true);
        $ledger_hash = (string) $order->get_meta('_roxy_rs_agreement_ledger_hash', true);
        return $order->get_created_via() === 'roxy_requested_showings'
            && preg_match('/^[a-f0-9]{64}$/', $agreement_hash) === 1
            && preg_match('/^[a-f0-9]{64}$/', $ledger_hash) === 1
            && hash_equals($ledger_hash, self::agreement_items_hash($order));
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

    /** Add a read-only reconciliation panel to Requested Showing WooCommerce orders. */
    public static function register_payment_review_metabox(): void {
        add_meta_box(
            'roxy_rs_payment_review',
            'Requested Showing Payment Review',
            [__CLASS__, 'render_payment_review_metabox'],
            'shop_order',
            'side',
            'high'
        );
    }

    public static function render_payment_review_metabox($post): void {
        $order_id = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
        if ($order_id <= 0 || !current_user_can('edit_shop_order', $order_id) || !function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order || $order->get_created_via() !== 'roxy_requested_showings') return;

        $attempt_raw = $order->get_meta('_roxy_rs_payment_attempt', true);
        $result_raw = $order->get_meta('_roxy_rs_payment_result', true);
        $review = (string) $order->get_meta('_roxy_seat_review', true) === '1';
        if (!$review && $attempt_raw === '' && $result_raw === '') return;

        if ($review || !$order->is_paid()) {
            echo '<p><strong>Payment may need manual reconciliation.</strong></p>';
            echo '<p>This panel is read-only. Do not retry conversion or manually mark this order paid. Verify the PaymentIntent in Stripe using the order ID, amount, currency, and customer before taking any action.</p>';
        } else {
            echo '<p>Payment attempt details are shown for audit. WooCommerce records this order as paid.</p>';
        }
        echo '<p><strong>Woo order:</strong> #' . esc_html((string) $order->get_order_number()) . ' (ID ' . esc_html((string) $order->get_id()) . ')<br>';
        echo '<strong>Amount:</strong> ' . wp_kses_post(wc_price((float) $order->get_total(), ['currency' => $order->get_currency()])) . '<br>';
        echo '<strong>Currency:</strong> ' . esc_html(strtoupper((string) $order->get_currency())) . '<br>';
        echo '<strong>Order status:</strong> ' . esc_html(wc_get_order_status_name($order->get_status())) . '</p>';

        $attempt = self::decode_review_metadata($attempt_raw);
        if ($attempt) {
            echo '<p><strong>Attempt started (UTC):</strong> ' . esc_html((string) ($attempt['started_at'] ?? 'Unknown')) . '<br>';
            echo '<strong>Request / backing:</strong> ' . esc_html((string) ($attempt['request_id'] ?? 'Unknown')) . ' / ' . esc_html((string) ($attempt['backing_id'] ?? 'Unknown')) . '</p>';
        } else {
            echo '<p><strong>Attempt marker:</strong> missing or unreadable; investigate before any payment action.</p>';
        }

        $result = self::decode_review_metadata($result_raw);
        $result_statuses = ['succeeded', 'processing', 'requires_capture', 'requires_action', 'requires_payment_method', 'requires_confirmation', 'canceled'];
        $result_matches_attempt = $attempt
            && is_string($attempt['key'] ?? null) && is_string($attempt['hash'] ?? null)
            && is_string($result['key'] ?? null) && is_string($result['hash'] ?? null)
            && hash_equals($attempt['key'], $result['key']) && hash_equals($attempt['hash'], $result['hash']);
        if ($result_matches_attempt && in_array((string) ($result['status'] ?? ''), $result_statuses, true)
            && preg_match('/^pi_[A-Za-z0-9]+$/D', (string) ($result['intent_id'] ?? ''))) {
            echo '<p><strong>Saved PaymentIntent:</strong> <code>' . esc_html((string) $result['intent_id']) . '</code><br>';
            echo '<strong>Saved provider status:</strong> ' . esc_html((string) ($result['status'] ?? 'Unknown')) . '</p>';
        } else {
            echo '<p><strong>Saved provider result:</strong> none verified. Search Stripe by the Woo order ID and requested-showing order description.</p>';
        }
    }

    /** Parse only the JSON receipt fields needed for a manager's read-only review. */
    private static function decode_review_metadata($value): array {
        if (!is_string($value) || $value === '') return [];
        $data = json_decode($value, true);
        if (!is_array($data)) return [];
        return $data;
    }

    public static function actions_markup(int $request_id): string {
        if (!self::schema_is_ready()) {
            return '<div class="roxy-rs-action-wrap"><strong>Requested Showing Actions</strong><p class="description">Actions are temporarily unavailable because the request data store could not be verified.</p></div>';
        }
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
        if (!self::schema_is_ready()) wp_die('Requested-showing actions are temporarily unavailable while the request data store is repaired.');

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
        if (!self::schema_is_ready()) wp_die('Requested-showing actions are temporarily unavailable while the request data store is repaired.');
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
        if (!self::schema_is_ready()) return;
        $status = CPT::get_status($request_id);
        if (!in_array($status, ['active', 'threshold_met'], true)) {
            return;
        }

        $totals = roxy_rs_repo_backing_totals($request_id);
        $goal = CPT::funding_goal_cents($request_id);
        $sponsor_amount = CPT::sponsor_amount_cents($request_id);
        if (is_wp_error($goal) || is_wp_error($sponsor_amount)) {
            throw new \RuntimeException('Requested-showing funding currency needs review; readiness was not evaluated.');
        }
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
        if (!self::schema_is_ready()) return new \WP_Error('requested_showing_schema_unavailable', 'Requested-showing actions are temporarily unavailable while the request data store is repaired.');
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
        if (!self::schema_is_ready()) return new \WP_Error('requested_showing_schema_unavailable', 'Requested-showing actions are temporarily unavailable while the request data store is repaired.');
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
        $funding_goal = CPT::funding_goal_cents($request_id);
        $sponsor_amount = CPT::sponsor_amount_cents($request_id);
        if (is_wp_error($funding_goal) || is_wp_error($sponsor_amount)) {
            return new \WP_Error('currency_review_required', 'Saved funding amounts or their unit marker need review. No conversion was attempted.');
        }

        // Close pledging under the same lease held by repository INSERTs.
        update_post_meta($request_id, CPT::META_STATUS, 'conversion_review');
        wp_cache_delete($request_id, 'post_meta');
        if (get_post_meta($request_id, CPT::META_STATUS, true) !== 'conversion_review') {
            return new \WP_Error('request_close_failed', 'The request could not be closed for conversion. No new showing or payment was attempted.');
        }
        $lease->assert_owner();
        $showing_id = (int) get_post_meta($request_id, CPT::META_APPROVED_SHOWING_ID, true);
        $write_showing = static function () use ($request_id, $post, $target_at, &$showing_id, $lease) {
            if ($showing_id <= 0 || get_post_type($showing_id) !== \RoxyST\CPT::POST_TYPE) {
                // A missing prior showing must not be silently replaced.
                if ($showing_id > 0) return new \WP_Error('showing_review_required', 'The linked showing is missing. Reconcile it before creating another.');
                ConversionClaims::begin_creation($request_id, 'showing');
                $lease->assert_owner();
                $created = wp_insert_post([
                    'post_type' => \RoxyST\CPT::POST_TYPE,
                    'post_status' => 'publish',
                    'post_title' => $post->post_title,
                    'post_content' => $post->post_content,
                    'post_excerpt' => CPT::public_excerpt((string) $post->post_excerpt),
                ], true);
                if (is_wp_error($created) || !$created) return is_wp_error($created) ? $created : new \WP_Error('showing_create_failed', 'The showing could not be created.');
                $showing_id = (int) $created;
                $lease->assert_owner();
                update_post_meta($request_id, CPT::META_APPROVED_SHOWING_ID, $showing_id);
                wp_cache_delete($request_id, 'post_meta');
                if ((int) get_post_meta($request_id, CPT::META_APPROVED_SHOWING_ID, true) !== $showing_id) {
                    return new \WP_Error('showing_link_failed', 'The showing link could not be verified. Review it before retrying.');
                }
                update_post_meta($showing_id, '_roxy_rs_request_id', $request_id);
                $thumbnail_id = get_post_thumbnail_id($request_id);
                if ($thumbnail_id) set_post_thumbnail($showing_id, $thumbnail_id);
            }

            update_post_meta($showing_id, '_roxy_start', $target_at);
            update_post_meta($showing_id, '_roxy_pricing_profile', (string) get_post_meta($request_id, CPT::META_PRICING_PROFILE, true) ?: 'movie_evening');
            update_post_meta($showing_id, '_roxy_trailer_url', (string) get_post_meta($request_id, CPT::META_TRAILER_URL, true));
            if ((string) get_post_meta($showing_id, '_roxy_start', true) !== $target_at) return new \WP_Error('showing_time_write', 'The showing date could not be verified. Review it before retrying.');
            return true;
        };
        $showing_write_result = function_exists('roxy_eb_with_showing_time_lock')
            ? roxy_eb_with_showing_time_lock($target_at, $showing_id, $write_showing)
            : $write_showing();
        if (is_wp_error($showing_write_result)) return $showing_write_result;

        $lease->assert_owner();

        \RoxyST\Products::ensure_products_for_showing($showing_id);

        $backings = roxy_rs_repo_list_backings_for_request($request_id, ['pending', 'threshold_met', 'approved']);
        $needs_review = false;
        foreach ($backings as $backing) {
            $lease->assert_owner();
            $result = self::convert_backing_to_order($request_id, $showing_id, $backing);
            if (is_wp_error($result)) {
                $needs_review = true;
                $backing_id = (int) ($backing['id'] ?? 0);
                roxy_rs_repo_update_backing((int) $backing['id'], [
                    'status' => 'approved',
                    'approved_showing_id' => $showing_id,
                    'admin_note' => $result->get_error_message(),
                ]);
                $review_backing = roxy_rs_repo_get_backing($backing_id);
                $review_order_id = (int) ($review_backing['woo_order_id'] ?? 0);
                $review_order = $review_order_id > 0 && function_exists('wc_get_order') ? wc_get_order($review_order_id) : false;
                $order_review_line = '';
                if ($review_order instanceof \WC_Order
                    && (int) $review_order->get_meta('_roxy_rs_request_id', true) === $request_id
                    && (int) $review_order->get_meta('_roxy_rs_backing_id', true) === $backing_id) {
                    $order_review_line = "\nWoo order ID: " . $review_order_id . "\nReview order: " . admin_url('post.php?post=' . $review_order_id . '&action=edit');
                }
                self::email_admin(
                    sprintf('Requested showing approval needs attention: %s', get_the_title($request_id)),
                    "Approval created the showing, but one backing could not be charged automatically.\n\nRequest: " . get_the_title($request_id) . "\nBacking ID: " . $backing_id . $order_review_line . "\nError: " . $result->get_error_message()
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
        $run_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
        $started_at = current_time('mysql', true);
        $lease = null;
        $telemetry_started = false;

        try {
            if (!self::schema_is_ready()) throw new \RuntimeException('Requested-showing schema unavailable; daily review was skipped.');
            // Prevent overlapping cron workers from reviewing the same request
            // set. Isolated repository fixtures may load this class without the
            // full ticket storage layer, so they intentionally skip the lease.
            if (class_exists(ConversionClaims::class)) {
                $lease = ConversionClaims::lease('daily-review');
            }
            $telemetry_started = self::save_daily_review_result([
                'version' => 1,
                'run_id' => (string) $run_id,
                'status' => 'running',
                'started_at' => (string) $started_at,
                'completed_at' => '',
                'error' => '',
            ]);

            self::perform_daily_review();

            if ($telemetry_started) {
                self::save_daily_review_result([
                    'version' => 1,
                    'run_id' => (string) $run_id,
                    'status' => 'completed',
                    'started_at' => (string) $started_at,
                    'completed_at' => (string) current_time('mysql', true),
                    'error' => '',
                ]);
            }
        } catch (\Throwable $error) {
            if ($telemetry_started) {
                self::save_daily_review_result([
                    'version' => 1,
                    'run_id' => (string) $run_id,
                    'status' => 'failed',
                    'started_at' => (string) $started_at,
                    'completed_at' => (string) current_time('mysql', true),
                    'error' => self::daily_review_error_text($error),
                ]);
            }
            throw $error;
        } finally {
            if ($lease !== null) {
                $lease->release_lease();
            }
        }
    }

    private static function perform_daily_review(): void {
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
                $sponsor_amount = CPT::sponsor_amount_cents($request_id);
                if (is_wp_error($goal) || is_wp_error($sponsor_amount)) {
                    throw new \RuntimeException('Requested-showing funding currency needs review; daily closure was not evaluated.');
                }
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

    /** Save scheduler state without changing the review outcome on telemetry failure. */
    private static function save_daily_review_result(array $result): bool {
        if (!function_exists('update_option')) {
            return false;
        }

        try {
            // Best-effort observation only: cached reads cannot prove durability.
            return update_option(self::DAILY_REVIEW_RESULT_OPTION, $result, false) === true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    private static function daily_review_error_text(\Throwable $error): string {
        $message = (string) $error->getMessage();
        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($message);
        }
        if (function_exists('wp_strip_all_tags')) {
            return (string) wp_strip_all_tags($message);
        }
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);
    }

    public static function mark_failed(int $request_id): void {
        if (!self::schema_is_ready()) return;
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

        try {
            $agreement = Agreement::validate((string) ($backing['agreement_json'] ?? ''), $backing);
        } catch (\Throwable $error) {
            return new \WP_Error('backing_agreement_invalid', 'This backing has no verifiable saved price agreement. Review the pledge before conversion; no payment was attempted.');
        }
        if ((int) $agreement['request_id'] !== $request_id || $agreement['profile'] !== $profile
            || $agreement['currency'] !== strtoupper((string) get_woocommerce_currency())) {
            return new \WP_Error('backing_agreement_mismatch', 'The saved price agreement no longer matches this showing or store currency. Review it before conversion; no payment was attempted.');
        }

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
        $required_ticket_products = [];
        if ($profile === 'movie_matinee') {
            if ($general_qty > 0) $required_ticket_products['matinee'] = $products['matinee'] ?? 0;
        } else {
            if ($general_qty > 0) $required_ticket_products['adult'] = $products['adult'] ?? 0;
            if ($discount_qty > 0) $required_ticket_products['discount'] = $products['discount'] ?? 0;
        }
        if ($sponsor_tickets > 0) {
            $sponsor_product_key = $profile === 'movie_matinee' ? 'matinee' : 'adult';
            $required_ticket_products[$sponsor_product_key] = $products[$sponsor_product_key] ?? 0;
        }
        foreach ($required_ticket_products as $product_key => $product_id) {
            $ticket_product = $product_id > 0 ? wc_get_product($product_id) : false;
            if (!$ticket_product) return new \WP_Error('ticket_product_unavailable', 'A required ticket product mapping could not be verified. No order or payment was created.');
            if (self::ticket_product_is_taxable($ticket_product)) {
                return new \WP_Error('ticket_product_taxable', 'Requested-showing admission products must be configured as nontaxable, matching the theater’s Square setup. No order or payment was created.');
            }
        }
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
            if (strtoupper((string) $order->get_currency()) !== $agreement['currency']
                || self::money_to_cents($order->get_total()) !== (int) $agreement['total_cents']) {
                return new \WP_Error('existing_order_total_mismatch', 'The saved order total no longer matches the customer agreement. Review it before any payment attempt.');
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
            $order->set_currency($agreement['currency']);
            $order->set_created_via('roxy_requested_showings');
            $order->add_meta_data('_roxy_rs_request_id', $request_id, true);
            $order->add_meta_data('_roxy_rs_backing_id', $backing_id, true);
            $order->add_meta_data('_roxy_rs_agreement_hash', (string) $agreement['hash'], true);
            $order->save();
            $lease->assert_owner();
            if ((int) $order->get_id() <= 0 || (int) $order->get_customer_id() !== $user_id
                || (int) $order->get_meta('_roxy_rs_request_id', true) !== $request_id
                || (int) $order->get_meta('_roxy_rs_backing_id', true) !== $backing_id
                || (string) $order->get_meta('_roxy_rs_agreement_hash', true) !== (string) $agreement['hash']
                || strtoupper((string) $order->get_currency()) !== $agreement['currency']) {
                return new \WP_Error('new_order_identity_mismatch', 'The new order identity could not be durably saved. Review it before retrying; no payment was attempted.');
            }
            $early_link = roxy_rs_repo_update_backing($backing_id, [
                'woo_order_id' => (int) $order->get_id(),
                'approved_showing_id' => $showing_id,
            ]);
            if (is_wp_error($early_link)) return $early_link;
            $early_backing = roxy_rs_repo_get_backing($backing_id);
            if (!$early_backing || (int) ($early_backing['woo_order_id'] ?? 0) !== (int) $order->get_id()
                || (int) ($early_backing['request_id'] ?? 0) !== $request_id
                || (int) ($early_backing['user_id'] ?? 0) !== $user_id) {
                return new \WP_Error('backing_order_link_failed', 'The new order was saved but its backing link could not be verified. Review it before retrying; no payment was attempted.');
            }

            if ($profile === 'movie_matinee') {
                $qty = $general_qty;
                if ($qty > 0 && !empty($products['matinee'])) {
                    $item_id = $order->add_product(wc_get_product($products['matinee']), $qty);
                    $item = $item_id ? $order->get_item($item_id, false) : false;
                    if (!$item || !self::set_agreed_gross($order, $item, (int) $agreement['unit_prices_cents']['matinee'] * $qty)) return new \WP_Error('agreement_price_apply_failed', 'The agreed matinee price could not be applied safely. No payment was attempted.');
                }
            } else {
                if ($general_qty > 0 && !empty($products['adult'])) {
                    $item_id = $order->add_product(wc_get_product($products['adult']), $general_qty);
                    $item = $item_id ? $order->get_item($item_id, false) : false;
                    if (!$item || !self::set_agreed_gross($order, $item, (int) $agreement['unit_prices_cents']['general'] * $general_qty)) return new \WP_Error('agreement_price_apply_failed', 'The agreed general ticket price could not be applied safely. No payment was attempted.');
                }
                if ($discount_qty > 0 && !empty($products['discount'])) {
                    $item_id = $order->add_product(wc_get_product($products['discount']), $discount_qty);
                    $item = $item_id ? $order->get_item($item_id, false) : false;
                    if (!$item || !self::set_agreed_gross($order, $item, (int) $agreement['unit_prices_cents']['discount'] * $discount_qty)) return new \WP_Error('agreement_price_apply_failed', 'The agreed discount ticket price could not be applied safely. No payment was attempted.');
                }
            }

            if ($subscriber_qty > 0 && !empty($products['subscriber'])) {
                $item_id = $order->add_product($subscriber_product, $subscriber_qty);
                $item = $item_id ? $order->get_item($item_id, false) : false;
                if (!$item) return new \WP_Error('subscriber_order_unverified', 'The subscriber reservation could not be added safely. No payment was attempted.');
                $item->set_subtotal(0);
                $item->set_total(0);
                $item->set_taxes(false);
                $item->save();
            }

            if ($sponsor_tickets > 0) {
                $sponsor_product_id = $profile === 'movie_matinee' ? ($products['matinee'] ?? 0) : ($products['adult'] ?? 0);
                if ($sponsor_product_id > 0) {
                    $item_id = $order->add_product(wc_get_product($sponsor_product_id), $sponsor_tickets);
                    $item = $item_id ? $order->get_item($item_id, false) : false;
                    if (!$item) return new \WP_Error('sponsor_ticket_order_unverified', 'The sponsored ticket reservation could not be added safely. No payment was attempted.');
                    $item->set_subtotal(0);
                    $item->set_total(0);
                    $item->set_taxes(false);
                    $item->save();
                }
            }

            $sponsor_amount = (int) ($backing['sponsor_amount'] ?? 0);
            if ($sponsor_amount > 0) {
                $fee = new \WC_Order_Item_Fee();
                $fee->set_name('Requested showing sponsorship');
                if (!self::set_agreed_fee_gross($order, $fee, $sponsor_amount)) return new \WP_Error('agreement_sponsor_price_apply_failed', 'The agreed sponsorship amount could not be applied safely. No payment was attempted.');
                $order->add_item($fee);
            }

            if (!method_exists($order, 'update_taxes')) return new \WP_Error('agreement_tax_ledger_failed', 'The agreed order tax ledger could not be calculated safely. No payment was attempted.');
            $order->update_taxes();
            $order->calculate_totals(false);
            $line_ledger_hash = self::agreement_items_hash($order);
            if ($line_ledger_hash === '') return new \WP_Error('agreement_tax_ledger_failed', 'The agreed order tax ledger could not be captured safely. No payment was attempted.');
            $order->add_meta_data('_roxy_rs_agreement_ledger_hash', $line_ledger_hash, true);
            $order->save();
            $lease->assert_owner();
            if ((int) $order->get_id() <= 0 || (int) $order->get_customer_id() !== $user_id
                || (int) $order->get_meta('_roxy_rs_request_id', true) !== $request_id
                || (int) $order->get_meta('_roxy_rs_backing_id', true) !== $backing_id
                || (string) $order->get_meta('_roxy_rs_agreement_hash', true) !== (string) $agreement['hash']
                || (string) $order->get_meta('_roxy_rs_agreement_ledger_hash', true) !== self::agreement_items_hash($order)
                || strtoupper((string) $order->get_currency()) !== $agreement['currency']
                || self::money_to_cents($order->get_total()) !== (int) $agreement['total_cents']) {
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
        if ((string) $order->get_meta('_roxy_rs_agreement_hash', true) !== (string) $agreement['hash']
            || (string) $order->get_meta('_roxy_rs_agreement_ledger_hash', true) !== self::agreement_items_hash($order)
            || strtoupper((string) $order->get_currency()) !== $agreement['currency']
            || self::money_to_cents($order->get_total()) !== (int) $agreement['total_cents']
            || !self::verify_agreement_items($order, $ticket_items, $products, $profile, $agreement)) {
            return new \WP_Error('ticket_order_agreement_mismatch', 'The saved order no longer matches the agreed customer price. No payment was attempted.');
        }
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

    /** Hash the full persisted order/tax ledger so offsetting edits cannot hide behind an unchanged total. */
    private static function agreement_items_hash(\WC_Order $order): string {
        if (!function_exists('wp_json_encode') || !function_exists('wc_format_decimal')) return '';
        $normalize_taxes = static function ($taxes): ?array {
            if (!is_array($taxes)) return null;
            $normalized = [];
            foreach ($taxes as $rate_id => $amount) {
                if (!is_numeric($amount) || !is_finite((float) $amount)) return null;
                $normalized[(string) $rate_id] = wc_format_decimal((float) $amount, 6);
            }
            ksort($normalized, SORT_STRING);
            return $normalized;
        };
        $lines = [];
        foreach ($order->get_items('line_item') as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id') || !method_exists($item, 'get_quantity')
                || !method_exists($item, 'get_subtotal') || !method_exists($item, 'get_total') || !method_exists($item, 'get_taxes')
                || !method_exists($item, 'get_product')) return '';
            $product = $item->get_product();
            if (!$product || !method_exists($product, 'get_tax_class') || !method_exists($product, 'get_tax_status')) return '';
            $quantity = $item->get_quantity();
            $taxes = $item->get_taxes();
            if (!is_numeric($quantity) || (float) $quantity <= 0 || (float) $quantity !== (float) (int) $quantity
                || !is_array($taxes) || !isset($taxes['total'], $taxes['subtotal'])) return '';
            $line_taxes = $normalize_taxes($taxes['total']);
            $subtotal_taxes = $normalize_taxes($taxes['subtotal']);
            if ($line_taxes === null || $subtotal_taxes === null) return '';
            $lines[] = [
                'product_id' => (int) $item->get_product_id(),
                'quantity' => (int) $quantity,
                'tax_class' => (string) $product->get_tax_class(),
                'tax_status' => (string) $product->get_tax_status(),
                'subtotal' => wc_format_decimal((float) $item->get_subtotal(), 6),
                'total' => wc_format_decimal((float) $item->get_total(), 6),
                'subtotal_taxes' => $subtotal_taxes,
                'taxes' => $line_taxes,
            ];
        }
        $fees = [];
        foreach ($order->get_items('fee') as $fee) {
            if (!is_object($fee) || !method_exists($fee, 'get_name') || !method_exists($fee, 'get_amount')
                || !method_exists($fee, 'get_total') || !method_exists($fee, 'get_taxes')
                || !method_exists($fee, 'get_tax_class') || !method_exists($fee, 'get_tax_status')) return '';
            $taxes = $fee->get_taxes();
            $fee_taxes = is_array($taxes) && isset($taxes['total']) ? $normalize_taxes($taxes['total']) : null;
            if ($fee_taxes === null) return '';
            $fees[] = [
                'name' => (string) $fee->get_name(),
                'amount' => wc_format_decimal((float) $fee->get_amount(), 6),
                'total' => wc_format_decimal((float) $fee->get_total(), 6),
                'tax_class' => (string) $fee->get_tax_class(),
                'tax_status' => (string) $fee->get_tax_status(),
                'taxes' => $fee_taxes,
            ];
        }
        $tax_lines = [];
        foreach ($order->get_items('tax') as $tax_line) {
            if (!is_object($tax_line) || !method_exists($tax_line, 'get_rate_id')
                || !method_exists($tax_line, 'get_tax_total') || !method_exists($tax_line, 'get_shipping_tax_total')) return '';
            $tax_lines[] = [
                'rate_id' => (int) $tax_line->get_rate_id(),
                'tax_total' => wc_format_decimal((float) $tax_line->get_tax_total(), 6),
                'shipping_tax_total' => wc_format_decimal((float) $tax_line->get_shipping_tax_total(), 6),
            ];
        }
        $sort_rows = static function (array &$rows): void {
            usort($rows, static fn($left, $right) => strcmp((string) wp_json_encode($left), (string) wp_json_encode($right)));
        };
        $sort_rows($lines);
        $sort_rows($fees);
        $sort_rows($tax_lines);
        $data = [
            'currency' => strtoupper((string) $order->get_currency()),
            'prices_include_tax' => method_exists($order, 'get_prices_include_tax') ? (bool) $order->get_prices_include_tax() : false,
            'total' => wc_format_decimal((float) $order->get_total(), 6),
            'total_tax' => wc_format_decimal((float) $order->get_total_tax(), 6),
            'discount' => wc_format_decimal((float) $order->get_discount_total(), 6),
            'discount_tax' => wc_format_decimal((float) $order->get_discount_tax(), 6),
            'shipping' => wc_format_decimal((float) $order->get_shipping_total(), 6),
            'shipping_tax' => wc_format_decimal((float) $order->get_shipping_tax(), 6),
            'lines' => $lines,
            'fees' => $fees,
            'tax_lines' => $tax_lines,
        ];
        $json = wp_json_encode($data);
        return is_string($json) ? hash('sha256', $json) : '';
    }

    /** Verify every persisted order line and fee against the saved agreement before charging. */
    private static function verify_agreement_items(\WC_Order $order, array $ticket_items, array $products, string $profile, array $agreement): bool {
        $quantities = $agreement['quantities'] ?? [];
        $unit_prices = $agreement['unit_prices_cents'] ?? [];
        $general_qty = (int) ($quantities['general_qty'] ?? -1);
        $discount_qty = (int) ($quantities['discount_qty'] ?? -1);
        $subscriber_qty = (int) ($quantities['subscriber_qty'] ?? -1);
        $sponsor_ticket_qty = (int) ($quantities['sponsor_ticket_qty'] ?? -1);
        if (min($general_qty, $discount_qty, $subscriber_qty, $sponsor_ticket_qty) < 0) return false;

        $general_key = $profile === 'movie_matinee' ? 'matinee' : 'general';
        $expected = [];
        $definitions = [
            [$products[$general_key === 'matinee' ? 'matinee' : 'adult'] ?? 0, $general_qty + $sponsor_ticket_qty, $general_qty, (int) ($unit_prices[$general_key] ?? -1)],
            [$products['discount'] ?? 0, $discount_qty, $discount_qty, (int) ($unit_prices['discount'] ?? -1)],
            [$products['subscriber'] ?? 0, $subscriber_qty, 0, 0],
        ];
        foreach ($definitions as [$product_id, $expected_qty, $paid_qty, $unit_cents]) {
            if ($expected_qty === 0) continue;
            if ($product_id <= 0 || $unit_cents < 0 || isset($expected[$product_id])) return false;
            if ($paid_qty > 0 && $unit_cents > intdiv(2147483647, $paid_qty)) return false;
            $expected[$product_id] = ['quantity' => $expected_qty, 'gross_cents' => $unit_cents * $paid_qty];
        }

        $actual = [];
        foreach ($ticket_items as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id') || !method_exists($item, 'get_quantity')
                || !method_exists($item, 'get_total') || !method_exists($item, 'get_total_tax')
                || !method_exists($item, 'get_subtotal') || !method_exists($item, 'get_subtotal_tax')) return false;
            $product_id = (int) $item->get_product_id();
            if (!isset($expected[$product_id])) return false;
            $quantity = $item->get_quantity();
            if (!is_numeric($quantity) || (float) $quantity <= 0 || (float) $quantity !== (float) (int) $quantity) return false;
            $line_total = self::money_to_cents((float) $item->get_total() + (float) $item->get_total_tax());
            $line_subtotal = self::money_to_cents((float) $item->get_subtotal() + (float) $item->get_subtotal_tax());
            if ($line_total < 0 || $line_subtotal !== $line_total) return false;
            if (!isset($actual[$product_id])) $actual[$product_id] = ['quantity' => 0, 'gross_cents' => 0];
            if ((int) $quantity > PHP_INT_MAX - $actual[$product_id]['quantity']
                || $line_total > 2147483647 - $actual[$product_id]['gross_cents']) return false;
            $actual[$product_id]['quantity'] += (int) $quantity;
            $actual[$product_id]['gross_cents'] += $line_total;
        }
        if ($actual !== $expected) return false;

        $sponsor_amount = (int) ($agreement['sponsor_amount_cents'] ?? -1);
        $fees = $order->get_items('fee');
        if ($sponsor_amount < 0 || count($fees) !== ($sponsor_amount > 0 ? 1 : 0)) return false;
        if ($sponsor_amount > 0) {
            $fee = reset($fees);
            if (!is_object($fee) || !method_exists($fee, 'get_name') || !method_exists($fee, 'get_total') || !method_exists($fee, 'get_total_tax')
                || $fee->get_name() !== 'Requested showing sponsorship'
                || self::money_to_cents((float) $fee->get_total() + (float) $fee->get_total_tax()) !== $sponsor_amount) return false;
        }
        if ($order->get_items('coupon') || $order->get_items('shipping')
            || self::money_to_cents((float) $order->get_shipping_total() + (float) $order->get_shipping_tax()) !== 0
            || self::money_to_cents((float) $order->get_discount_total() + (float) $order->get_discount_tax()) !== 0) return false;
        return true;
    }

    /** Apply a gross, customer-agreed line total while retaining Woo's tax breakdown. */
    private static function set_agreed_gross(\WC_Order $order, $item, int $gross_cents): bool {
        if ($gross_cents < 0 || !method_exists($item, 'get_product')) return false;
        $product = $item->get_product();
        if (!$product) return false;
        // Requested-showing admission tickets are nontaxable in Roxy's Square/Woo setup.
        // Fail closed if a ticket product is accidentally configured as taxable; never
        // manufacture a tax allocation or expose it in reporting for these orders.
        if (self::ticket_product_is_taxable($product)) return false;
        $gross = $gross_cents / 100;
        $item->set_subtotal($gross);
        $item->set_total($gross);
        $item->set_taxes(false);
        $item->save();
        return self::money_to_cents($item->get_total()) === $gross_cents
            && self::money_to_cents($item->get_total_tax()) === 0;
    }

    /** Sponsorship is also recorded without collecting or reporting ticket tax. */
    private static function set_agreed_fee_gross(\WC_Order $order, \WC_Order_Item_Fee $fee, int $gross_cents): bool {
        if ($gross_cents < 0 || !method_exists($fee, 'set_tax_status')) return false;
        $fee->set_tax_status('none');
        $gross = $gross_cents / 100;
        $fee->set_amount($gross);
        $fee->set_total($gross);
        $fee->set_taxes(false);
        return self::money_to_cents($fee->get_total()) === $gross_cents
            && self::money_to_cents($fee->get_total_tax()) === 0;
    }

    private static function money_to_cents($amount): int {
        if (!is_numeric($amount) || !is_finite((float) $amount)) return -1;
        $cents = round((float) $amount * 100);
        if ($cents < 0 || $cents > 2147483647 || abs(((float) $amount * 100) - $cents) > 0.00001) return -1;
        return (int) $cents;
    }

    private static function ticket_product_is_taxable($product): bool {
        if (is_object($product) && method_exists($product, 'get_tax_status')) {
            return (string) $product->get_tax_status() !== 'none';
        }
        return !is_object($product) || !method_exists($product, 'is_taxable') || (bool) $product->is_taxable();
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
