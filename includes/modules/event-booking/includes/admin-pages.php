<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_register_admin_pages() {
    // Admin menu registration is handled by RoxySuite\Admin (class-roxy-suite-admin.php).
    // The four render functions below are called directly from the tabbed Event Booking page.
}

function roxy_eb_admin_settings_page() {
    if (!roxy_suite_user_can_access_admin()) return;
    $settings = roxy_eb_get_settings();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['roxy_eb_save_settings'])) {
            check_admin_referer('roxy_eb_save_settings');
            $incoming = $_POST['settings'] ?? [];
            if (!empty($incoming['_sling_token_plain'])) $incoming['sling_auth_token_enc'] = roxy_eb_sling_encrypt_secret($incoming['_sling_token_plain']);
            unset($incoming['_sling_token_plain']);
            $settings = roxy_eb_update_settings($incoming);
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }
        if (isset($_POST['roxy_eb_sling_test_connection'])) {
            check_admin_referer('roxy_eb_save_settings');
            $incoming = $_POST['settings'] ?? [];
            if (!empty($incoming['_sling_token_plain'])) $incoming['sling_auth_token_enc'] = roxy_eb_sling_encrypt_secret($incoming['_sling_token_plain']);
            unset($incoming['_sling_token_plain']);
            $settings = roxy_eb_update_settings($incoming);
            $result = roxy_eb_sling_admin_test_and_resolve($settings);
            if (is_wp_error($result)) echo '<div class="notice notice-error"><p><strong>Sling test failed:</strong> ' . esc_html($result->get_error_message()) . '</p></div>';
            else echo '<div class="notice notice-success"><p><strong>Sling connected.</strong> ' . esc_html($result['message'] ?? 'Token stored.') . '</p></div>';
        }
        if (isset($_POST['roxy_eb_sling_create_test_shift'])) {
            check_admin_referer('roxy_eb_save_settings');
            $incoming = $_POST['settings'] ?? [];
            if (!empty($incoming['_sling_token_plain'])) $incoming['sling_auth_token_enc'] = roxy_eb_sling_encrypt_secret($incoming['_sling_token_plain']);
            unset($incoming['_sling_token_plain']);
            $settings = roxy_eb_update_settings($incoming);
            $result = roxy_eb_sling_admin_create_test_shift($settings);
            if (is_wp_error($result)) echo '<div class="notice notice-error"><p><strong>Test shift failed:</strong> ' . esc_html($result->get_error_message()) . '</p></div>';
            else echo '<div class="notice notice-success"><p><strong>Test shift created.</strong> ' . esc_html($result) . '</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>Roxy Event Booking — Settings</h1>
        <form method="post">
            <?php wp_nonce_field('roxy_eb_save_settings'); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Internal notification email</th><td><input type="email" name="settings[internal_email]" value="<?php echo esc_attr($settings['internal_email']); ?>" class="regular-text" /></td></tr>
                <tr><th scope="row">Lead time (hours)</th><td><input type="number" name="settings[lead_time_hours]" value="<?php echo esc_attr($settings['lead_time_hours']); ?>" min="0" /></td></tr>
                <tr><th scope="row">Free cancellation window (days)</th><td><input type="number" name="settings[cancel_free_days]" value="<?php echo esc_attr($settings['cancel_free_days']); ?>" min="0" /></td></tr>
                <tr><th scope="row">Guest cap</th><td><input type="number" name="settings[guest_cap]" value="<?php echo esc_attr($settings['guest_cap']); ?>" min="1" /></td></tr>
                <tr><th scope="row">Base price (≤ 25 guests)</th><td>$ <input type="number" name="settings[base_price_under]" value="<?php echo esc_attr($settings['base_price_under']); ?>" min="0" /></td></tr>
                <tr><th scope="row">Base price (≥ 26 guests)</th><td>$ <input type="number" name="settings[base_price_over]" value="<?php echo esc_attr($settings['base_price_over']); ?>" min="0" /></td></tr>
                <tr><th scope="row">Extra hour price</th><td>$ <input type="number" name="settings[extra_hour_price]" value="<?php echo esc_attr($settings['extra_hour_price']); ?>" min="0" /></td></tr>
                <tr><th scope="row">Pizza price</th><td>$ <input type="number" name="settings[pizza_price]" value="<?php echo esc_attr($settings['pizza_price'] ?? 18); ?>" min="0" /></td></tr>
                <tr><th scope="row">Bulk concession price</th><td>$ <input type="number" name="settings[bulk_item_price]" value="<?php echo esc_attr($settings['bulk_item_price'] ?? 3); ?>" min="0" /></td></tr>
                <tr><th scope="row">Operating hours</th><td>Open <input type="text" name="settings[open_time]" value="<?php echo esc_attr($settings['open_time']); ?>" placeholder="08:00" /> Close <input type="text" name="settings[close_time]" value="<?php echo esc_attr($settings['close_time']); ?>" placeholder="24:00" /></td></tr>
                <tr><th scope="row">Time increments</th><td><select name="settings[time_increment_minutes]"><?php foreach ([5,10,15,20,30,60] as $m): ?><option value="<?php echo esc_attr($m); ?>" <?php selected(intval($settings['time_increment_minutes']), $m); ?>><?php echo esc_html($m); ?> minutes</option><?php endforeach; ?></select></td></tr>
                <tr><th scope="row">Booking product</th><td><input type="number" name="settings[booking_product_id]" value="<?php echo esc_attr($settings['booking_product_id']); ?>" min="0" /></td></tr>
            </table>

            <h2>Fixed Showtime Blocks</h2>
            <table class="widefat striped">
                <thead><tr><th>Weekday</th><th>Start</th><th>End</th><th>Label</th><th>Delete</th></tr></thead>
                <tbody>
                    <?php foreach ($settings['showtime_blocks'] as $i => $b): ?>
                        <tr>
                            <td><select name="settings[showtime_blocks][<?php echo esc_attr($i); ?>][weekday]"><?php $days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']; foreach ($days as $dIdx => $dName) echo '<option value="' . esc_attr($dIdx) . '"' . selected(intval($b['weekday']), $dIdx, false) . '>' . esc_html($dName) . '</option>'; ?></select></td>
                            <td><input type="text" name="settings[showtime_blocks][<?php echo esc_attr($i); ?>][start]" value="<?php echo esc_attr($b['start']); ?>" /></td>
                            <td><input type="text" name="settings[showtime_blocks][<?php echo esc_attr($i); ?>][end]" value="<?php echo esc_attr($b['end']); ?>" /></td>
                            <td><input type="text" name="settings[showtime_blocks][<?php echo esc_attr($i); ?>][label]" value="<?php echo esc_attr($b['label']); ?>" class="regular-text"/></td>
                            <td><label><input type="checkbox" name="settings[showtime_blocks][<?php echo esc_attr($i); ?>][_delete]" value="1" /> Remove</label></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><select name="settings[showtime_blocks][new][weekday]"><?php $days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']; foreach ($days as $dIdx => $dName) echo '<option value="' . esc_attr($dIdx) . '">' . esc_html($dName) . '</option>'; ?></select></td>
                        <td><input type="text" name="settings[showtime_blocks][new][start]" value="" placeholder="18:00"/></td>
                        <td><input type="text" name="settings[showtime_blocks][new][end]" value="" placeholder="22:00"/></td>
                        <td><input type="text" name="settings[showtime_blocks][new][label]" value="" placeholder="Regular Showing"/></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>

            <h2>Sling Integration</h2>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Mode</th><td><select name="settings[sling_mode]"><option value="disabled" <?php selected($settings['sling_mode'], 'disabled'); ?>>Disabled</option><option value="webhook" <?php selected($settings['sling_mode'], 'webhook'); ?>>Webhook</option><option value="direct" <?php selected($settings['sling_mode'], 'direct'); ?>>Direct API</option></select></td></tr>
                <tr><th scope="row">Webhook URL</th><td><input type="url" name="settings[sling_webhook_url]" value="<?php echo esc_attr($settings['sling_webhook_url']); ?>" class="regular-text" /></td></tr>
                <tr><th scope="row">Direct API Base URL</th><td><input type="url" name="settings[sling_base_url]" value="<?php echo esc_attr($settings['sling_base_url']); ?>" class="regular-text" /></td></tr>
                <tr><th scope="row">Sling Authorization token</th><td><input type="password" name="settings[_sling_token_plain]" value="" class="large-text" autocomplete="off" /></td></tr>
                <tr><th scope="row">Auth failure email</th><td><input type="email" name="settings[sling_auth_fail_email]" value="<?php echo esc_attr($settings['sling_auth_fail_email'] ?? 'info@newportroxy.com'); ?>" class="regular-text" /></td></tr>
                <tr><th scope="row">Publish shifts</th><td><label><input type="checkbox" name="settings[sling_publish_shifts]" value="1" <?php checked(!empty($settings['sling_publish_shifts'])); ?> /> Publish shifts immediately</label></td></tr>
                <tr><th scope="row">Location label / External ID</th><td><input type="text" name="settings[sling_location_label]" value="<?php echo esc_attr($settings['sling_location_label'] ?? ''); ?>" class="regular-text" /></td></tr>
                <tr><th scope="row">Position label: Private Show</th><td><input type="text" name="settings[sling_position_private_show_label]" value="<?php echo esc_attr($settings['sling_position_private_show_label'] ?? 'Private Show'); ?>" class="regular-text" /></td></tr>
                <tr><th scope="row">Position label: Concessionist</th><td><input type="text" name="settings[sling_position_concessionist_label]" value="<?php echo esc_attr($settings['sling_position_concessionist_label'] ?? 'Concessionist'); ?>" class="regular-text" /></td></tr>
                <tr><th scope="row">Manual numeric IDs (optional)</th><td>
                    <div style="display:grid; grid-template-columns: 180px 1fr; gap:8px; max-width:700px;">
                        <div>Location ID</div><div><input type="text" name="settings[sling_location_id]" value="<?php echo esc_attr($settings['sling_location_id'] ?? ''); ?>" class="regular-text" /></div>
                        <div>Private Show Position ID</div><div><input type="text" name="settings[sling_position_private_show_id]" value="<?php echo esc_attr($settings['sling_position_private_show_id'] ?? ''); ?>" class="regular-text" /></div>
                        <div>Concessionist Position ID</div><div><input type="text" name="settings[sling_position_concessionist_id]" value="<?php echo esc_attr($settings['sling_position_concessionist_id'] ?? ''); ?>" class="regular-text" /></div>
                    </div>
                </td></tr>
                <tr><th scope="row">Shift title template</th><td><input type="text" name="settings[sling_shift_title_template]" value="<?php echo esc_attr($settings['sling_shift_title_template']); ?>" class="large-text" /></td></tr>
                <tr><th scope="row">Shift notes template</th><td><textarea name="settings[sling_shift_notes_template]" rows="10" class="large-text"><?php echo esc_textarea($settings['sling_shift_notes_template']); ?></textarea></td></tr>
            </table>
            <p class="submit">
                <button class="button button-primary" type="submit" name="roxy_eb_save_settings" value="1">Save Settings</button>
                <button class="button" type="submit" name="roxy_eb_sling_test_connection" value="1" style="margin-left:8px;">Test Connection</button>
                <button class="button" type="submit" name="roxy_eb_sling_create_test_shift" value="1" style="margin-left:8px;">Create Test Sling Shift</button>
            </p>
        </form>
    </div>
    <?php
}

function roxy_eb_admin_blocks_page() {
    if (!roxy_suite_user_can_access_admin()) return;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['roxy_eb_add_block'])) {
        check_admin_referer('roxy_eb_add_block');
        $title = sanitize_text_field($_POST['title'] ?? 'Blocked');
        $type  = sanitize_text_field($_POST['type'] ?? 'manual_event');
        $visibility = sanitize_text_field($_POST['visibility'] ?? 'private');
        if (!in_array($visibility, ['private','public'], true)) $visibility = 'private';
        $start = sanitize_text_field($_POST['start_at'] ?? '');
        $end   = sanitize_text_field($_POST['end_at'] ?? '');
        $note  = sanitize_textarea_field($_POST['note'] ?? '');

        $tz = wp_timezone();
        try { $startDt = new DateTimeImmutable($start, $tz); $endDt = new DateTimeImmutable($end, $tz); } catch (Exception $e) { $startDt = null; $endDt = null; }
        if ($startDt && $endDt && $endDt > $startDt) {
            $res = roxy_eb_repo_insert_block([
                'title' => $title,
                'type' => $type,
                'visibility' => $visibility,
                'note' => $note ?: null,
                'start_at' => roxy_eb_datetime_to_mysql($startDt),
                'end_at' => roxy_eb_datetime_to_mysql($endDt),
                'created_by' => get_current_user_id() ?: null,
            ]);
            echo is_wp_error($res) ? '<div class="error"><p>Could not create block: ' . esc_html($res->get_error_message()) . '</p></div>' : '<div class="updated"><p>Block added.</p></div>';
        } else {
            echo '<div class="error"><p>Invalid dates.</p></div>';
        }
    }

    if (isset($_GET['delete']) && isset($_GET['_wpnonce'])) {
        $id = intval($_GET['delete']);
        if (wp_verify_nonce($_GET['_wpnonce'], 'roxy_eb_del_block_' . $id)) {
            $res = roxy_eb_repo_delete_block($id);
            echo is_wp_error($res) ? '<div class="error"><p>Could not delete: ' . esc_html($res->get_error_message()) . '</p></div>' : '<div class="updated"><p>Block deleted.</p></div>';
        }
    }

    $tz = wp_timezone();
    $start = (new DateTimeImmutable('now', $tz))->modify('-7 days');
    $end   = (new DateTimeImmutable('now', $tz))->modify('+90 days');
    $rows = roxy_eb_repo_list_blocks_in_range(roxy_eb_datetime_to_mysql($start), roxy_eb_datetime_to_mysql($end));
    ?>
    <div class="wrap">
        <h1>Calendar Blocks</h1>
        <form method="post">
            <?php wp_nonce_field('roxy_eb_add_block'); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Title</th><td><input type="text" name="title" class="regular-text" required /></td></tr>
                <tr><th scope="row">Type</th><td><select name="type"><option value="manual_event">Scheduled Event</option><option value="maintenance">Maintenance</option><option value="hold">Hold</option></select></td></tr>
                <tr><th scope="row">Visibility</th><td><select name="visibility"><option value="private">Private</option><option value="public">Public</option></select></td></tr>
                <tr><th scope="row">Start</th><td><input type="datetime-local" name="start_at" required /></td></tr>
                <tr><th scope="row">End</th><td><input type="datetime-local" name="end_at" required /></td></tr>
                <tr><th scope="row">Note (optional)</th><td><textarea name="note" rows="3" class="large-text"></textarea></td></tr>
            </table>
            <p class="submit"><button class="button button-primary" type="submit" name="roxy_eb_add_block" value="1">Add Block</button></p>
        </form>

        <table class="widefat striped">
            <thead><tr><th>Title</th><th>Start</th><th>End</th><th>Type</th><th>Visibility</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if (empty($rows)): ?><tr><td colspan="6">No blocks found.</td></tr><?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo esc_html($r['title']); ?></td>
                    <td><?php echo esc_html($r['start_at']); ?></td>
                    <td><?php echo esc_html($r['end_at']); ?></td>
                    <td><?php echo esc_html($r['type']); ?></td>
                    <td><?php echo esc_html($r['visibility'] ?? 'private'); ?></td>
                    <td><?php $url = add_query_arg(['page' => 'roxy-event-booking', 'tab' => 'calendar', 'delete' => intval($r['id']), '_wpnonce' => wp_create_nonce('roxy_eb_del_block_' . intval($r['id']))], admin_url('admin.php')); ?><a class="button" href="<?php echo esc_url($url); ?>" onclick="return confirm('Delete this block?');">Delete</a></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function roxy_eb_valid_bulk_qty($qty) {
    $qty = intval($qty);
    return ($qty === 0 || ($qty >= 25 && $qty <= 250));
}

function roxy_eb_mark_pizza_handled($booking_id, $handled) {
    $booking_id = intval($booking_id);
    if ($booking_id <= 0) return;
    if ($handled) {
        roxy_eb_repo_update_booking($booking_id, [
            'pizza_checked_at' => current_time('mysql'),
            'pizza_checked_by' => get_current_user_id() ?: null,
        ]);
        roxy_eb_clear_pizza_reminders($booking_id);
    } else {
        roxy_eb_repo_update_booking($booking_id, [
            'pizza_checked_at' => null,
            'pizza_checked_by' => null,
        ]);
        roxy_eb_schedule_pizza_reminder($booking_id);
    }
}

function roxy_eb_is_booking_archived($booking, ?DateTimeImmutable $now = null) {
    if (!is_array($booking) || empty($booking['doors_close_at'])) return false;
    try {
        $close = roxy_eb_mysql_to_dt($booking['doors_close_at']);
    } catch (Throwable $e) {
        return false;
    }
    if (!$close) return false;
    if (!$now) $now = new DateTimeImmutable('now', wp_timezone());
    return $now >= $close->modify('+4 hours');
}

function roxy_eb_admin_bookings_page() {
    if (!roxy_suite_user_can_access_admin()) return;

    if (isset($_GET['roxy_eb_action']) && $_GET['roxy_eb_action'] === 'retry_sling' && isset($_GET['booking_id'])) {
        $booking_id = intval($_GET['booking_id']);
        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'roxy_eb_admin_retry_sling_' . $booking_id)) echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
        else { roxy_eb_sling_enqueue_sync($booking_id, 'manual_retry'); echo '<div class="notice notice-success"><p>Sling sync queued.</p></div>'; }
    }

    if (isset($_GET['roxy_eb_action']) && $_GET['roxy_eb_action'] === 'cancel' && isset($_GET['booking_id'])) {
        $booking_id = intval($_GET['booking_id']);
        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'roxy_eb_admin_cancel_' . $booking_id)) echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
        else {
            $res = roxy_eb_cancel_booking($booking_id, 'admin');
            if (is_wp_error($res)) echo '<div class="notice notice-error"><p>' . esc_html($res->get_error_message()) . '</p></div>';
            else { roxy_eb_clear_pizza_reminders($booking_id); echo '<div class="notice notice-success"><p>Booking cancelled.</p></div>'; }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['roxy_eb_toggle_pizza']) && isset($_POST['booking_id'])) {
        $booking_id = intval($_POST['booking_id']);
        check_admin_referer('roxy_eb_toggle_pizza_' . $booking_id);
        roxy_eb_mark_pizza_handled($booking_id, !empty($_POST['pizza_handled']));
        echo '<div class="notice notice-success"><p>Pizza status updated.</p></div>';
    }

    if (isset($_GET['roxy_eb_action']) && $_GET['roxy_eb_action'] === 'update' && isset($_GET['booking_id'])) {
        $booking_id = intval($_GET['booking_id']);
        $nonce = sanitize_text_field($_POST['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'roxy_eb_admin_edit_' . $booking_id)) echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
        else {
            $booking_before = roxy_eb_repo_get_booking($booking_id);
            if (!$booking_before) echo '<div class="notice notice-error"><p>Booking not found.</p></div>';
            else {
                $tz = wp_timezone();
                $date = sanitize_text_field($_POST['doors_open_date'] ?? '');
                $time = sanitize_text_field($_POST['doors_open_time'] ?? '');
                $guest_count = max(1, intval($_POST['guest_count'] ?? 1));
                $duration_hours = max(2, intval($_POST['duration_hours'] ?? (2 + intval($booking_before['extra_hours']))));
                $sling_status = sanitize_text_field($_POST['sling_status'] ?? ($booking_before['sling_status'] ?? 'unscheduled'));
                if (!in_array($sling_status, ['unscheduled','scheduled','manual','error'], true)) $sling_status = 'unscheduled';
                $notes_admin = sanitize_textarea_field($_POST['notes_admin'] ?? ($booking_before['notes_admin'] ?? ''));
                $customer_type = sanitize_text_field($_POST['customer_type'] ?? ($booking_before['customer_type'] ?? 'personal'));
                $business_name = sanitize_text_field($_POST['business_name'] ?? ($booking_before['business_name'] ?? ''));
                $payment_method = sanitize_text_field($_POST['payment_method'] ?? ($booking_before['payment_method'] ?? 'pay_now'));
                if ($customer_type !== 'business') { $customer_type = 'personal'; $business_name = ''; $payment_method = 'pay_now'; }
                $invoice_status = sanitize_text_field($_POST['invoice_status'] ?? ($booking_before['invoice_status'] ?? 'not_needed'));
                if (!in_array($invoice_status, ['not_needed','pending','sent','paid','void'], true)) $invoice_status = 'not_needed';
                $settings_now = roxy_eb_get_settings();
                $pizza_requested = !empty($_POST['pizza_requested']) ? 1 : 0;
                $pizza_quantity = $pizza_requested ? max(1, intval($_POST['pizza_quantity'] ?? 1)) : 0;
                $pizza_order_details = $pizza_requested ? sanitize_textarea_field($_POST['pizza_order_details'] ?? '') : '';
                $pizza_total = $pizza_requested ? ($pizza_quantity * intval($settings_now['pizza_price'] ?? 18)) : 0;
                $bulk_concessions_requested = !empty($_POST['bulk_concessions_requested']) ? 1 : 0;
                $bulk_popcorn_qty = $bulk_concessions_requested ? intval($_POST['bulk_popcorn_qty'] ?? 0) : 0;
                $bulk_soda_qty = $bulk_concessions_requested ? intval($_POST['bulk_soda_qty'] ?? 0) : 0;
                if ($bulk_concessions_requested && (!roxy_eb_valid_bulk_qty($bulk_popcorn_qty) || !roxy_eb_valid_bulk_qty($bulk_soda_qty))) {
                    echo '<div class="notice notice-error"><p>Bulk concessions must be 0, or between 25 and 250 for each item.</p></div>';
                    return;
                }
                $bulk_concessions_total = $bulk_concessions_requested ? (($bulk_popcorn_qty + $bulk_soda_qty) * intval($settings_now['bulk_item_price'] ?? 3)) : 0;
                $send_email = !empty($_POST['email_customer']);

                $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time, $tz);
                if (!$dt) {
                    echo '<div class="notice notice-error"><p>Invalid date/time.</p></div>';
                } else {
                    $extra_hours = max(0, $duration_hours - 2);
                    $times = roxy_eb_calc_times($dt, $extra_hours);

                    if (!roxy_eb_is_slot_available($times['reserved_start'], $times['reserved_end'], $booking_id)) {
                        echo '<div class="notice notice-error"><p>That time conflicts with another booking or blocked event.</p></div>';
                    } else {
                        $base_price = intval($booking_before['base_price']);
                        $extra_price = $extra_hours * intval(roxy_eb_get_settings()['extra_hour_price'] ?? 100);
                        $total_price = $base_price + $extra_price + $pizza_total + $bulk_concessions_total;

                        $update = [
                            'guest_count' => $guest_count,
                            'tier' => roxy_eb_tier_from_guest_count($guest_count),
                            'staff_shifts_required' => roxy_eb_shifts_from_guest_count($guest_count),
                            'extra_hours' => $extra_hours,
                            'doors_open_at' => roxy_eb_datetime_to_mysql($dt),
                            'show_start_at' => roxy_eb_datetime_to_mysql($times['show_start']),
                            'doors_close_at' => roxy_eb_datetime_to_mysql($times['doors_close']),
                            'reserved_start_at' => roxy_eb_datetime_to_mysql($times['reserved_start']),
                            'reserved_end_at' => roxy_eb_datetime_to_mysql($times['reserved_end']),
                            'sling_status' => $sling_status,
                            'notes_admin' => $notes_admin,
                            'customer_type' => $customer_type,
                            'business_name' => $business_name ?: null,
                            'payment_method' => $payment_method,
                            'invoice_status' => $invoice_status,
                            'pizza_requested' => $pizza_requested,
                            'pizza_quantity' => $pizza_quantity,
                            'pizza_order_details' => $pizza_requested ? $pizza_order_details : null,
                            'pizza_total' => $pizza_total,
                            'bulk_concessions_requested' => $bulk_concessions_requested,
                            'bulk_popcorn_qty' => $bulk_popcorn_qty,
                            'bulk_soda_qty' => $bulk_soda_qty,
                            'bulk_concessions_total' => $bulk_concessions_total,
                            'extra_price' => $extra_price,
                            'total_price' => $total_price,
                        ];
                        if (!empty($_POST['pizza_handled'])) {
                            $update['pizza_checked_at'] = current_time('mysql');
                            $update['pizza_checked_by'] = get_current_user_id() ?: null;
                        } else {
                            $update['pizza_checked_at'] = null;
                            $update['pizza_checked_by'] = null;
                        }

                        $res = roxy_eb_repo_update_booking($booking_id, $update);
                        if (is_wp_error($res)) echo '<div class="notice notice-error"><p>' . esc_html($res->get_error_message()) . '</p></div>';
                        else {
                            $booking_after = roxy_eb_repo_get_booking($booking_id);
                            if (!empty($booking_after['pizza_checked_at'])) roxy_eb_clear_pizza_reminders($booking_id);
                            else roxy_eb_schedule_pizza_reminder($booking_id);
                            echo '<div class="notice notice-success"><p>Booking updated.</p></div>';

                            if ($booking_after && ($booking_after['sling_status'] ?? '') !== 'manual' && function_exists('roxy_eb_sling_enqueue_sync')) {
                                $settings = roxy_eb_get_settings();
                                if (($settings['sling_mode'] ?? 'disabled') !== 'disabled') roxy_eb_sling_enqueue_sync($booking_id, 'admin_edit');
                            }
                            if ($send_email && $booking_after) {
                                roxy_eb_email_customer_booking_updated($booking_before, $booking_after);
                                echo '<div class="notice notice-info"><p>Email sent to customer.</p></div>';
                            }
                        }
                    }
                }
            }
        }
    }

    $tz = wp_timezone();
    $now = new DateTimeImmutable('now', $tz);
    $show_archived = !empty($_GET['show_archived']);
    $start = $now->modify('-30 days');
    $end   = $now->modify('+365 days');
    $rows = roxy_eb_repo_list_bookings_in_range(roxy_eb_datetime_to_mysql($start), roxy_eb_datetime_to_mysql($end));
    $display_rows = [];
    foreach ($rows as $r) {
        $booking = roxy_eb_repo_get_booking($r['id']);
        if (!$booking) continue;
        $booking['_is_archived'] = roxy_eb_is_booking_archived($booking, $now);
        if (!$show_archived && $booking['_is_archived']) continue;
        $display_rows[] = $booking;
    }
    ?>
    <div class="wrap">
        <h1>Bookings</h1>
        <p>Confirmed and invoice-pending bookings. Pizza reminders run until pizza is marked handled. Bookings are hidden from this list 4 hours after doors close unless you show archived.</p>

        <form method="get" style="margin:12px 0 16px;">
            <input type="hidden" name="page" value="roxy-event-booking" />
            <label>
                <input type="checkbox" name="show_archived" value="1" <?php checked($show_archived); ?> onchange="this.form.submit()">
                Show archived
            </label>
        </form>
<?php
if (isset($_GET['roxy_eb_action']) && $_GET['roxy_eb_action'] === 'edit' && isset($_GET['booking_id'])):
    $edit_id = intval($_GET['booking_id']);
    $b = roxy_eb_repo_get_booking($edit_id);
    if ($b):
        $doorsOpen = roxy_eb_mysql_to_dt($b['doors_open_at']);
        $dateVal = $doorsOpen ? $doorsOpen->format('Y-m-d') : '';
        $timeVal = $doorsOpen ? $doorsOpen->format('H:i') : '';
        $durVal = 2 + intval($b['extra_hours']);
        $cur = $b['sling_status'] ?: 'unscheduled';
        if ($cur === 'ok') $cur = 'scheduled';
?>
    <div class="card" style="max-width:980px; padding:16px; margin:12px 0;">
        <h2 style="margin-top:0;">Edit Booking #<?php echo esc_html($edit_id); ?></h2>
        <form method="post" action="<?php echo esc_url(add_query_arg(['page'=>'roxy-event-booking','roxy_eb_action'=>'update','booking_id'=>$edit_id], admin_url('admin.php'))); ?>">
            <?php wp_nonce_field('roxy_eb_admin_edit_' . $edit_id); ?>
            <table class="form-table">
                <tr><th scope="row"><label for="doors_open_date">Date</label></th><td><input type="date" id="doors_open_date" name="doors_open_date" value="<?php echo esc_attr($dateVal); ?>" required></td></tr>
                <tr><th scope="row"><label for="doors_open_time">Doors open time</label></th><td><input type="time" id="doors_open_time" name="doors_open_time" value="<?php echo esc_attr($timeVal); ?>" required></td></tr>
                <tr><th scope="row"><label for="guest_count">Guests</label></th><td><input type="number" min="1" max="250" id="guest_count" name="guest_count" value="<?php echo esc_attr(intval($b['guest_count'])); ?>" required></td></tr>
                <tr><th scope="row"><label for="duration_hours">Duration (hours)</label></th><td><select id="duration_hours" name="duration_hours"><?php for ($h=2; $h<=8; $h++): ?><option value="<?php echo esc_attr($h); ?>" <?php selected($durVal, $h); ?>><?php echo esc_html($h); ?> hours</option><?php endfor; ?></select></td></tr>
                <tr><th scope="row"><label for="customer_type">Customer type</label></th><td><select id="customer_type" name="customer_type"><option value="personal" <?php selected($b['customer_type'], 'personal'); ?>>Personal</option><option value="business" <?php selected($b['customer_type'], 'business'); ?>>Business</option></select></td></tr>
                <tr><th scope="row"><label for="business_name">Business name</label></th><td><input type="text" id="business_name" name="business_name" value="<?php echo esc_attr($b['business_name'] ?? ''); ?>" class="regular-text"></td></tr>
                <tr><th scope="row"><label for="payment_method">Payment method</label></th><td><select id="payment_method" name="payment_method"><option value="pay_now" <?php selected($b['payment_method'], 'pay_now'); ?>>Pay now</option><option value="invoice" <?php selected($b['payment_method'], 'invoice'); ?>>Invoice</option></select></td></tr>
                <tr><th scope="row"><label for="invoice_status">Invoice status</label></th><td><select id="invoice_status" name="invoice_status"><?php foreach (['not_needed'=>'Not needed','pending'=>'Pending','sent'=>'Sent','paid'=>'Paid','void'=>'Void'] as $k=>$label): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($b['invoice_status'] ?? 'not_needed', $k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr>
                <tr><th scope="row"><label for="sling_status">Sling status</label></th><td><select id="sling_status" name="sling_status"><?php foreach (['unscheduled'=>'Unscheduled','scheduled'=>'Scheduled','manual'=>'Manual','error'=>'Error'] as $k=>$label): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($cur, $k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr>
                <tr><th scope="row">Pizza requested</th><td><label><input type="checkbox" name="pizza_requested" value="1" <?php checked(!empty($b['pizza_requested'])); ?>> Pizza included</label></td></tr>
                <tr><th scope="row"><label for="pizza_quantity">Pizza quantity</label></th><td><input type="number" min="0" id="pizza_quantity" name="pizza_quantity" value="<?php echo esc_attr(intval($b['pizza_quantity'] ?? 0)); ?>"></td></tr>
                <tr><th scope="row"><label for="pizza_order_details">Pizza order</label></th><td><textarea id="pizza_order_details" name="pizza_order_details" rows="4" style="width:420px;max-width:100%;"><?php echo esc_textarea($b['pizza_order_details'] ?? ''); ?></textarea></td></tr>
                <tr><th scope="row">Bulk concessions requested</th><td><label><input type="checkbox" name="bulk_concessions_requested" value="1" <?php checked(!empty($b['bulk_concessions_requested'])); ?>> Bulk concessions included</label><p class="description">Each item must be 0, or 25 to 250.</p></td></tr>
                <tr><th scope="row"><label for="bulk_popcorn_qty">Bulk popcorn qty</label></th><td><input type="number" min="0" max="250" id="bulk_popcorn_qty" name="bulk_popcorn_qty" value="<?php echo esc_attr(intval($b['bulk_popcorn_qty'] ?? 0)); ?>"></td></tr>
                <tr><th scope="row"><label for="bulk_soda_qty">Bulk soda qty</label></th><td><input type="number" min="0" max="250" id="bulk_soda_qty" name="bulk_soda_qty" value="<?php echo esc_attr(intval($b['bulk_soda_qty'] ?? 0)); ?>"></td></tr>
                <tr><th scope="row">Pizza handled</th><td><label><input type="checkbox" name="pizza_handled" value="1" <?php checked(!empty($b['pizza_checked_at'])); ?>> Mark pizza handled</label><?php if (!empty($b['pizza_checked_at'])): ?><p class="description">Handled at <?php echo esc_html($b['pizza_checked_at']); ?></p><?php endif; ?></td></tr>
                <tr><th scope="row"><label for="notes_admin">Event notes</label></th><td><textarea id="notes_admin" name="notes_admin" rows="3" style="width:420px;max-width:100%;"><?php echo esc_textarea($b['notes_admin'] ?? ''); ?></textarea></td></tr>
                <tr><th scope="row">Notify customer</th><td><label><input type="checkbox" name="email_customer" value="1"> Email customer about this change</label></td></tr>
            </table>
            <p><button type="submit" class="button button-primary">Save changes</button> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=roxy-event-booking' . ($show_archived ? '&show_archived=1' : ''))); ?>">Done</a></p>
        </form>
    </div>
<?php endif; endif; ?>

        <table class="widefat striped">
            <thead><tr>
                <th>Doors Open</th>
                <th>Customer</th>
                <th>Payment</th>
                <th>Pizza</th>
                <th>Guests</th>
                <th>Duration</th>
                <th>Status</th>
                <th>Sling</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
                <?php if (empty($display_rows)): ?>
                    <tr><td colspan="9">No bookings found.</td></tr>
                <?php else: foreach ($display_rows as $booking): $dur = 2 + intval($booking['extra_hours']); $ss = $booking['sling_status'] ?? ''; if ($ss === 'ok') $ss = 'scheduled'; ?>
                    <tr<?php echo !empty($booking['_is_archived']) ? ' style="opacity:.75;"' : ''; ?>>
                        <td><?php echo esc_html($booking['doors_open_at']); ?></td>
                        <td><?php echo esc_html(trim($booking['customer_first_name'] . ' ' . $booking['customer_last_name'])); ?><br><small><?php echo esc_html($booking['customer_email']); ?></small><?php if (!empty($booking['business_name'])): ?><br><small><?php echo esc_html($booking['business_name']); ?></small><?php endif; ?></td>
                        <td><?php echo esc_html(($booking['payment_method'] === 'invoice') ? 'Invoice' : 'Pay now'); ?><?php if (($booking['payment_method'] ?? '') === 'invoice'): ?><br><small><?php echo esc_html(ucfirst($booking['invoice_status'] ?? 'pending')); ?></small><?php endif; ?></td>
                        <td>
                            <?php if (!empty($booking['pizza_requested'])): ?>
                                <?php echo esc_html(intval($booking['pizza_quantity'])); ?> pizza(s)<br>
                                <small><?php echo !empty($booking['pizza_checked_at']) ? 'Handled' : 'Not handled'; ?></small>
                                <form method="post" style="margin-top:6px;">
                                    <?php wp_nonce_field('roxy_eb_toggle_pizza_' . intval($booking['id'])); ?>
                                    <input type="hidden" name="booking_id" value="<?php echo esc_attr(intval($booking['id'])); ?>">
                                    <input type="hidden" name="roxy_eb_toggle_pizza" value="1">
                                    <label><input type="checkbox" name="pizza_handled" value="1" <?php checked(!empty($booking['pizza_checked_at'])); ?> onchange="this.form.submit()"> handled</label>
                                </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?php echo esc_html(intval($booking['guest_count'])); ?></td>
                        <td><?php echo esc_html($dur . 'h'); ?></td>
                        <td>
                            <?php echo esc_html($booking['status']); ?>
                            <?php if (!empty($booking['_is_archived'])): ?><br><small>Archived</small><?php endif; ?>
                        </td>
                        <td><?php echo esc_html($ss ?: '—'); ?></td>
                        <td>
                            <?php
                                $base_args = ['page' => 'roxy-event-booking'];
                                if ($show_archived) $base_args['show_archived'] = 1;
                            ?>
                            <?php if ($booking['status'] !== 'cancelled'): ?>
                                <?php $editUrl = add_query_arg(array_merge($base_args, ['roxy_eb_action' => 'edit', 'booking_id' => intval($booking['id'])]), admin_url('admin.php')); ?>
                                <a class="button" href="<?php echo esc_url($editUrl); ?>">Edit</a>
                                <?php $cancelUrl = add_query_arg(array_merge($base_args, ['roxy_eb_action' => 'cancel', 'booking_id' => intval($booking['id']), '_wpnonce' => wp_create_nonce('roxy_eb_admin_cancel_' . intval($booking['id']))]), admin_url('admin.php')); ?>
                                <a class="button" href="<?php echo esc_url($cancelUrl); ?>" onclick="return confirm('Cancel this booking?');">Cancel</a>
                                <?php if (($ss === 'error' || $ss === 'failed')): ?>
                                    <?php $retryUrl = add_query_arg(array_merge($base_args, ['roxy_eb_action' => 'retry_sling', 'booking_id' => intval($booking['id']), '_wpnonce' => wp_create_nonce('roxy_eb_admin_retry_sling_' . intval($booking['id']))]), admin_url('admin.php')); ?>
                                    <a class="button" href="<?php echo esc_url($retryUrl); ?>">Retry Sling Sync</a>
                                <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function roxy_eb_admin_sling_logs_page() {
    if (!roxy_suite_user_can_access_admin()) return;
    $booking_filter = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
    $rows = roxy_eb_repo_list_sling_logs(200, $booking_filter);
    ?>
    <div class="wrap">
        <h1>Sling Logs</h1>
        <form method="get" style="margin: 12px 0;">
            <input type="hidden" name="page" value="roxy-event-booking" />
            <input type="hidden" name="tab" value="sling-logs" />
            <label>Booking ID: <input type="number" name="booking_id" value="<?php echo esc_attr($booking_filter ?: ''); ?>" min="0" /></label>
            <button class="button">Filter</button>
            <?php if ($booking_filter): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=roxy-event-booking&tab=sling-logs')); ?>">Clear</a><?php endif; ?>
        </form>
        <table class="widefat striped">
            <thead><tr><th>Time</th><th>Booking</th><th>Action</th><th>Endpoint</th><th>HTTP</th><th>Message</th></tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="6">No logs found.</td></tr><?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><code><?php echo esc_html($r['created_at']); ?></code></td>
                    <td><?php echo $r['booking_id'] ? '<a href="' . esc_url(admin_url('admin.php?page=roxy-event-booking&booking_id=' . intval($r['booking_id']))) . '">' . intval($r['booking_id']) . '</a>' : ''; ?></td>
                    <td><?php echo esc_html($r['action']); ?></td>
                    <td><code><?php echo esc_html($r['endpoint']); ?></code></td>
                    <td><?php echo esc_html($r['http_code']); ?></td>
                    <td><?php echo esc_html($r['message']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
