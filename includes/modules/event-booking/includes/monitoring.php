<?php
if (!defined('ABSPATH')) exit;

add_action(ROXY_EB_HEALTH_CHECK_HOOK, 'roxy_eb_daily_health_check');

function roxy_eb_daily_health_check(): void {
    $errors = [];
    $settings = roxy_eb_get_settings();
    $page_url = home_url('/rent-the-roxy/');
    $page = wp_remote_get($page_url, ['timeout' => 20, 'redirection' => 3]);
    if (is_wp_error($page)) {
        $errors[] = 'Booking page request failed: ' . $page->get_error_message();
    } else {
        $code = (int) wp_remote_retrieve_response_code($page);
        $html = (string) wp_remote_retrieve_body($page);
        if ($code < 200 || $code >= 400) $errors[] = "Booking page returned HTTP {$code}.";
        if (strpos($html, 'id="roxy-eb-calendar"') === false) $errors[] = 'Booking calendar markup is missing.';
        $asset = wp_remote_get(ROXY_EB_ASSETS_URL . 'roxy-eb.js?ver=' . rawurlencode(ROXY_EB_VERSION), ['timeout' => 20, 'redirection' => 3]);
        if (is_wp_error($asset) || (int) wp_remote_retrieve_response_code($asset) >= 400) $errors[] = 'Booking JavaScript asset is unavailable.';
    }
    try {
        $start = new DateTimeImmutable('today', wp_timezone());
        $items = roxy_eb_get_calendar_blocks($start, $start->modify('+60 days'));
        if (!is_array($items)) $errors[] = 'Availability check did not return a list.';
        foreach ((array) $items as $item) {
            if (empty($item['start']) || empty($item['end'])) { $errors[] = 'Availability returned an event without start/end times.'; break; }
        }
    } catch (Throwable $e) { $errors[] = 'Availability check failed: ' . $e->getMessage(); }

    $previous = get_option('roxy_eb_health_last_result', []);
    $failed = !empty($errors);
    $today = wp_date('Y-m-d', null, wp_timezone());
    $state = ['ok' => !$failed, 'checked_at' => current_time('mysql'), 'errors' => $errors, 'notice_date' => (string) ($previous['notice_date'] ?? '')];
    update_option('roxy_eb_health_last_result', $state, false);
    if ($failed && (!$previous || !empty($previous['ok']) || $state['notice_date'] !== $today)) {
        $to = sanitize_email($settings['internal_email'] ?? get_option('admin_email'));
        if (!is_email($to)) $to = get_option('admin_email');
        wp_mail($to, 'Newport Roxy booking calendar check failed', "The booking calendar health check found a problem:\n\n- " . implode("\n- ", $errors) . "\n\nChecked: " . current_time('mysql') . "\nPage: {$page_url}");
        $state['notice_date'] = $today;
        update_option('roxy_eb_health_last_result', $state, false);
    }
}
