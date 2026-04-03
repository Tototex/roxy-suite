<?php
if (!defined('ABSPATH')) exit;

add_action('init', function () {
    if (function_exists('as_enqueue_async_action')) {
        add_action('roxy_eb_sling_sync_booking', 'roxy_eb_sling_job_sync_booking', 10, 2);
        add_action('roxy_eb_sling_cancel_booking', 'roxy_eb_sling_job_cancel_booking', 10, 1);
    }
});

function roxy_eb_sling_enqueue_sync($booking_id, $reason = 'sync') {
    $settings = roxy_eb_get_settings();
    $mode = $settings['sling_mode'] ?? 'disabled';
    if ($mode === 'disabled') return;
    if (!function_exists('as_enqueue_async_action')) return;
    as_enqueue_async_action('roxy_eb_sling_sync_booking', [intval($booking_id), sanitize_text_field($reason)], 'roxy-eb');
}

function roxy_eb_sling_enqueue_cancel($booking_id) {
    $settings = roxy_eb_get_settings();
    $mode = $settings['sling_mode'] ?? 'disabled';
    if ($mode === 'disabled') return;
    if (!function_exists('as_enqueue_async_action')) return;
    as_enqueue_async_action('roxy_eb_sling_cancel_booking', [intval($booking_id)], 'roxy-eb');
}

function roxy_eb_sling_job_sync_booking($booking_id, $reason = 'sync') {
    $booking_id = intval($booking_id);
    $booking = roxy_eb_repo_get_booking($booking_id);
    if (!$booking) return;
    if (($booking['sling_status'] ?? '') === 'manual') return;

    if (($booking['status'] ?? '') === 'cancelled') {
        roxy_eb_sling_job_cancel_booking($booking_id);
        return;
    }

    $result = roxy_eb_sling_sync_shifts_for_booking($booking);
    if (is_wp_error($result)) {
        roxy_eb_repo_update_booking($booking_id, [
            'sling_status' => 'error',
            'sling_error'  => $result->get_error_message(),
        ]);
        return;
    }

    roxy_eb_repo_update_booking($booking_id, [
        'sling_status' => 'scheduled',
        'sling_error'  => null,
        'sling_shift_ids' => wp_json_encode($result),
    ]);
}

function roxy_eb_sling_job_cancel_booking($booking_id) {
    $booking_id = intval($booking_id);
    $booking = roxy_eb_repo_get_booking($booking_id);
    if (!$booking) return;
    if (($booking['sling_status'] ?? '') === 'manual') return;

    $res = roxy_eb_sling_cancel_shifts_for_booking($booking);
    if (is_wp_error($res)) {
        roxy_eb_repo_update_booking($booking_id, [
            'sling_status' => 'error',
            'sling_error'  => $res->get_error_message(),
        ]);
        return;
    }

    roxy_eb_repo_update_booking($booking_id, [
        'sling_status' => 'unscheduled',
        'sling_error'  => null,
        'sling_shift_ids' => null,
    ]);
}

function roxy_eb_sling_sync_shifts_for_booking($booking) {
    $settings = roxy_eb_get_settings();
    $mode = $settings['sling_mode'] ?? 'disabled';
    if ($mode === 'disabled') return [];

    $payload = roxy_eb_sling_build_payload($booking, 'sync');

    if ($mode === 'webhook') {
        $res = roxy_eb_sling_send_webhook($payload);
        if (is_wp_error($res)) return $res;
        return $booking['sling_shift_ids'] ? json_decode($booking['sling_shift_ids'], true) : [];
    }

    return roxy_eb_sling_direct_sync($payload, $booking);
}

function roxy_eb_sling_cancel_shifts_for_booking($booking) {
    $settings = roxy_eb_get_settings();
    $mode = $settings['sling_mode'] ?? 'disabled';
    if ($mode === 'disabled') return true;

    $payload = roxy_eb_sling_build_payload($booking, 'cancel');

    if ($mode === 'webhook') {
        return roxy_eb_sling_send_webhook($payload);
    }
    return roxy_eb_sling_direct_cancel($payload, $booking);
}

function roxy_eb_sling_build_payload($booking, $action) {
    $settings = roxy_eb_get_settings();

    $doorsOpen = roxy_eb_mysql_to_dt($booking['doors_open_at']);
    $showStart = roxy_eb_mysql_to_dt($booking['show_start_at']);
    $reservedStart = roxy_eb_mysql_to_dt($booking['reserved_start_at']);
    $reservedEnd = roxy_eb_mysql_to_dt($booking['reserved_end_at']);

    $extraHours = intval($booking['extra_hours'] ?? 0);
    $shiftStart = $doorsOpen->modify('-30 minutes');
    $shiftEnd = $shiftStart->modify('+' . (3 + max(0, $extraHours)) . ' hours');
    $guestHours = 3 + max(0, $extraHours);

    $event_details = (($booking['event_format'] ?? '') === 'movie') ? (string)($booking['movie_title'] ?? '') : (string)($booking['live_description'] ?? '');
    $type = (($booking['event_format'] ?? '') === 'movie') ? 'Movie' : 'Live Event';
    $detailsLabel = (($booking['event_format'] ?? '') === 'movie') ? 'Movie' : 'Details';
    $visibilityEvent = (strtolower((string)($booking['visibility'] ?? '')) === 'public') ? 'Public Event' : 'Private Event';
    $notes_shared = (string)($booking['notes_admin'] ?? '');

    $pizzaRequested = !empty($booking['pizza_requested']) ? 'Yes' : 'No';
    $pizzaStatus = !empty($booking['pizza_checked_at']) ? 'Handled' : 'Not handled';
    $paymentMethod = (($booking['payment_method'] ?? 'pay_now') === 'invoice') ? 'Invoice' : 'Pay now';

    $repl = [
        '{FIRST_NAME}' => (string)($booking['customer_first_name'] ?? ''),
        '{LAST_NAME}' => (string)($booking['customer_last_name'] ?? ''),
        '{EMAIL}' => (string)($booking['customer_email'] ?? ''),
        '{PHONE}' => (string)($booking['customer_phone'] ?? ''),
        '{BUSINESS_NAME}' => (string)($booking['business_name'] ?? ''),
        '{CUSTOMER_TYPE}' => ucfirst((string)($booking['customer_type'] ?? 'personal')),
        '{PAYMENT_METHOD}' => $paymentMethod,
        '{INVOICE_STATUS}' => ucfirst((string)($booking['invoice_status'] ?? 'not_needed')),
        '{GUESTS}' => strval(intval($booking['guest_count'] ?? 0)),
        '{FORMAT}' => ucfirst((string)($booking['event_format'] ?? '')),
        '{TYPE}' => $type,
        '{VISIBILITY}' => ucfirst((string)($booking['visibility'] ?? '')),
        '{EVENT_DETAILS}' => (string)$event_details,
        '{DETAILS_LABEL}' => $detailsLabel,
        '{VISIBILITY_EVENT}' => $visibilityEvent,
        '{DOORS_OPEN}' => $doorsOpen->format('D M j, Y g:i A'),
        '{SHOW_START}' => $showStart->format('D M j, Y g:i A'),
        '{DURATION_HOURS}' => strval($guestHours),
        '{ORDER_ID}' => strval(intval($booking['woo_order_id'] ?? 0)),
        '{BOOKING_ID}' => strval(intval($booking['id'] ?? 0)),
        '{PIZZA_REQUESTED}' => $pizzaRequested,
        '{PIZZA_QUANTITY}' => strval(intval($booking['pizza_quantity'] ?? 0)),
        '{PIZZA_TOTAL}' => '$' . number_format((float)($booking['pizza_total'] ?? 0), 2),
        '{PIZZA_ORDER}' => (string)($booking['pizza_order_details'] ?? ''),
        '{PIZZA_STATUS}' => $pizzaStatus,
        '{NOTES}' => (string)$notes_shared,
    ];

    $title = strtr($settings['sling_shift_title_template'] ?? 'Roxy Event', $repl);
    $notes_tpl = (string)($settings['sling_shift_notes_template'] ?? '');
    if (trim($notes_tpl) === '') {
        $notes_tpl = "Customer: {FIRST_NAME} {LAST_NAME}
Business: {BUSINESS_NAME}
Customer type: {CUSTOMER_TYPE}
Phone: {PHONE}
Email: {EMAIL}

Payment: {PAYMENT_METHOD}
Invoice status: {INVOICE_STATUS}

Type: {TYPE}
{DETAILS_LABEL}: {EVENT_DETAILS}

Doors open: {DOORS_OPEN}
Show starts: {SHOW_START}
Guests: {GUESTS}

Pizza: {PIZZA_REQUESTED}
Pizza quantity: {PIZZA_QUANTITY}
Pizza total: {PIZZA_TOTAL}
Pizza handled: {PIZZA_STATUS}
Pizza order:
{PIZZA_ORDER}

Notes:
{NOTES}";
    }
    $notes = strtr($notes_tpl, $repl);

    if (!empty($booking['pizza_requested'])) {
        $pizza_lines = [
            '**Pizza Ordered**',
            '',
            'Notes: Order pizza within 4 hours of event from Westside 509-447-2200. Mention that is is concession pizza for the Roxy, and cost should be $13 per pizza. You must pickup pizza before doors open. Confirm on website pizza has been ordered or reminders will be sent out.',
            '',
            'Pizza Order;',
            (string)($booking['pizza_order_details'] ?? ''),
            '',
        ];
        $notes = implode("
", $pizza_lines) . ltrim($notes);
    }

    if (!empty($booking['bulk_concessions_requested'])) {
        $bulk_lines = [
            '**Bulk Concessions Ordered**',
            '',
            'Bulk Popcorn: ' . intval($booking['bulk_popcorn_qty'] ?? 0),
            'Bulk Soda: ' . intval($booking['bulk_soda_qty'] ?? 0),
            'Bulk Concessions Total: $' . number_format((float)($booking['bulk_concessions_total'] ?? 0), 2),
            '',
        ];
        $notes = implode("\n", $bulk_lines) . ltrim($notes);
    }

    return [
        'action' => $action,
        'booking_id' => intval($booking['id']),
        'order_id' => intval($booking['woo_order_id']),
        'title' => $title,
        'notes' => $notes,
        'reserved_start_at' => $reservedStart->format(DATE_ATOM),
        'reserved_end_at' => $reservedEnd->format(DATE_ATOM),
        'shift_start_at' => $shiftStart->format(DATE_ATOM),
        'shift_end_at' => $shiftEnd->format(DATE_ATOM),
        'customer' => [
            'first_name' => $booking['customer_first_name'],
            'last_name'  => $booking['customer_last_name'],
            'email'      => $booking['customer_email'],
            'phone'      => $booking['customer_phone'],
            'type'       => $booking['customer_type'] ?? 'personal',
            'business_name' => $booking['business_name'] ?? '',
        ],
        'event' => [
            'format' => $booking['event_format'],
            'visibility' => $booking['visibility'],
            'guest_count' => intval($booking['guest_count']),
            'movie_title' => $booking['movie_title'],
            'live_description' => $booking['live_description'],
            'payment_method' => $booking['payment_method'] ?? 'pay_now',
            'invoice_status' => $booking['invoice_status'] ?? 'not_needed',
            'pizza_requested' => intval($booking['pizza_requested'] ?? 0),
            'pizza_quantity' => intval($booking['pizza_quantity'] ?? 0),
            'pizza_order_details' => $booking['pizza_order_details'] ?? '',
            'pizza_total' => intval($booking['pizza_total'] ?? 0),
            'pizza_handled' => !empty($booking['pizza_checked_at']) ? 1 : 0,
            'bulk_concessions_requested' => intval($booking['bulk_concessions_requested'] ?? 0),
            'bulk_popcorn_qty' => intval($booking['bulk_popcorn_qty'] ?? 0),
            'bulk_soda_qty' => intval($booking['bulk_soda_qty'] ?? 0),
            'bulk_concessions_total' => intval($booking['bulk_concessions_total'] ?? 0),
        ],
        'sling' => [
            'location_label' => $settings['sling_location_label'] ?? '',
            'position_private_show_label' => $settings['sling_position_private_show_label'] ?? 'Private Show',
            'position_concessionist_label' => $settings['sling_position_concessionist_label'] ?? 'Concessionist',
        ],
    ];
}

function roxy_eb_sling_desired_roles($guest_count) {
    $roles = ['private_show'];
    if (intval($guest_count) >= 26) $roles[] = 'concessionist';
    return $roles;
}

function roxy_eb_sling_send_webhook($payload) {
    $settings = roxy_eb_get_settings();
    $url = $settings['sling_webhook_url'] ?? '';
    if (empty($url)) return new WP_Error('missing_webhook', 'Sling webhook URL not set.');

    $resp = wp_remote_post($url, [
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('webhook_failed', 'Webhook returned HTTP ' . $code . ': ' . substr($body, 0, 300));
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

function roxy_eb_sling_direct_sync($payload, $booking) {
    $settings = roxy_eb_get_settings();
    $ids = roxy_eb_sling_decode_shift_ids($booking['sling_shift_ids'] ?? null);
    $desired = roxy_eb_sling_desired_roles(intval($booking['guest_count']));
    $map = roxy_eb_sling_get_mapping();
    if (is_wp_error($map)) return $map;

    $locationId = $map['location_id'];
    $positionIds = [
        'private_show' => $map['position_private_show_id'],
        'concessionist' => $map['position_concessionist_id'],
    ];

    foreach ($desired as $role) {
        if (!empty($ids[$role]['shift_id'])) continue;
        $shift_id = roxy_eb_sling_api_create_shift([
            'summary' => $payload['title'] . "\n\n" . $payload['notes'],
            'assigneeNotes' => $payload['notes'],
            'location' => ['id' => intval($locationId)],
            'position' => ['id' => intval($positionIds[$role] ?? 0)],
            'dtstart' => $payload['shift_start_at'],
            'dtend'   => $payload['shift_end_at'],
            'available' => true,
            'availableSlots' => 1,
        ], ['booking_id' => intval($booking['id']), 'order_id' => intval($booking['woo_order_id'] ?? 0), 'action' => 'create_shift', 'role' => $role]);
        if (is_wp_error($shift_id)) return $shift_id;
        $ids[$role] = ['shift_id' => $shift_id, 'position_id' => $positionIds[$role] ?? null];
        if (!empty($settings['sling_publish_shifts'])) {
            $pub = roxy_eb_sling_api_publish_shift($shift_id, ['booking_id' => intval($booking['id']), 'order_id' => intval($booking['woo_order_id'] ?? 0), 'action' => 'publish_shift', 'role' => $role]);
            if (is_wp_error($pub)) return $pub;
        }
    }

    foreach ($desired as $role) {
        if (empty($ids[$role]['shift_id'])) continue;
        $shift_id = $ids[$role]['shift_id'];
        $update = [
            'summary' => $payload['title'] . "\n\n" . $payload['notes'],
            'assigneeNotes' => $payload['notes'],
            'location' => ['id' => intval($locationId)],
            'position' => ['id' => intval($positionIds[$role] ?? 0)],
            'dtstart' => $payload['shift_start_at'],
            'dtend'   => $payload['shift_end_at'],
            'available' => true,
            'availableSlots' => 1,
        ];
        $res = roxy_eb_sling_api_update_shift($shift_id, $update, ['booking_id' => intval($booking['id']), 'order_id' => intval($booking['woo_order_id'] ?? 0), 'action' => 'update_shift', 'role' => $role]);
        if (is_wp_error($res)) return $res;
        if (!empty($settings['sling_publish_shifts'])) {
            $pub = roxy_eb_sling_api_publish_shift($shift_id, ['booking_id' => intval($booking['id']), 'order_id' => intval($booking['woo_order_id'] ?? 0), 'action' => 'publish_shift', 'role' => $role]);
            if (is_wp_error($pub)) return $pub;
        }
    }

    foreach ($ids as $role => $data) {
        if (in_array($role, $desired, true)) continue;
        if (empty($data['shift_id'])) continue;
        $res = roxy_eb_sling_api_delete_shift($data['shift_id'], ['booking_id' => intval($booking['id']), 'order_id' => intval($booking['woo_order_id'] ?? 0), 'action' => 'delete_shift', 'role' => $role]);
        if (is_wp_error($res)) return $res;
        unset($ids[$role]);
    }

    return $ids;
}

function roxy_eb_sling_direct_cancel($payload, $booking) {
    $ids = roxy_eb_sling_decode_shift_ids($booking['sling_shift_ids'] ?? null);
    if (empty($ids)) return true;
    foreach ($ids as $role => $data) {
        if (empty($data['shift_id'])) continue;
        $res = roxy_eb_sling_api_delete_shift($data['shift_id'], ['booking_id' => intval($booking['id']), 'order_id' => intval($booking['woo_order_id'] ?? 0), 'action' => 'delete_shift', 'role' => $role]);
        if (is_wp_error($res)) return $res;
    }
    return true;
}

function roxy_eb_sling_decode_shift_ids($raw) {
    if (empty($raw)) return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    if (array_values($decoded) === $decoded) {
        $out = [];
        foreach ($decoded as $i => $id) {
            $key = ($i === 0) ? 'private_show' : 'concessionist';
            if (is_scalar($id)) $out[$key] = ['shift_id' => $id];
        }
        return $out;
    }
    return $decoded;
}

function roxy_eb_sling_get_mapping() {
    $settings = roxy_eb_get_settings();
    $location = $settings['sling_location_id_resolved'] ?: ($settings['sling_location_id'] ?? '');
    $pos_private = $settings['sling_position_private_show_id_resolved'] ?: ($settings['sling_position_private_show_id'] ?? '');
    $pos_conc = $settings['sling_position_concessionist_id_resolved'] ?: ($settings['sling_position_concessionist_id'] ?? '');

    if (empty($location) || empty($pos_private) || empty($pos_conc)) {
        return new WP_Error('missing_mapping', 'Sling mapping incomplete. Go to Roxy Bookings → Settings and click “Test Connection + Resolve IDs” (or paste numeric IDs).');
    }

    return [
        'location_id' => $location,
        'position_private_show_id' => $pos_private,
        'position_concessionist_id' => $pos_conc,
    ];
}

function roxy_eb_sling_admin_test_and_resolve($settings) {
    if (($settings['sling_mode'] ?? 'disabled') !== 'direct') {
        return new WP_Error('not_direct', 'Set Sling Mode to “Direct API” to test connection.');
    }

    $token = roxy_eb_sling_get_token();
    if (is_wp_error($token)) return $token;

    $base = rtrim($settings['sling_base_url'] ?? 'https://api.getsling.com', '/');
    $baseRoot = preg_replace('#/v1$#', '', $base);
    $healthUrl = $baseRoot . '/v1/health';

    $resp = wp_remote_get($healthUrl, ['timeout' => 15, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($resp)) return new WP_Error('sling_reach', 'Could not reach Sling API (health): ' . $resp->get_error_message());
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('sling_reach', 'Could not reach Sling API (health): HTTP ' . $code . ': ' . substr($body, 0, 300));
    }

    $authCheck = roxy_eb_sling_api_request('GET', '/v1/shifts/current', null);
    if (is_wp_error($authCheck)) return $authCheck;

    return ['ok' => true, 'message' => 'Sling reachable and token valid. Mapping is manual in settings.'];
}

function roxy_eb_sling_match_entity_id($list, $label) {
    $label = trim(strval($label));
    if (!$label) return '';
    $label_l = strtolower($label);
    foreach ($list as $row) {
        if (!is_array($row)) continue;
        $id = $row['id'] ?? ($row['ID'] ?? null);
        if (!$id) continue;
        $name = strtolower(trim(strval($row['name'] ?? $row['title'] ?? '')));
        $external = strtolower(trim(strval($row['external_id'] ?? $row['externalId'] ?? $row['externalID'] ?? '')));
        if ($name === $label_l || $external === $label_l) return strval($id);
    }
    foreach ($list as $row) {
        if (!is_array($row)) continue;
        $id = $row['id'] ?? null;
        if (!$id) continue;
        $name = strtolower(trim(strval($row['name'] ?? $row['title'] ?? '')));
        $external = strtolower(trim(strval($row['external_id'] ?? $row['externalId'] ?? '')));
        if ($name && strpos($name, $label_l) !== false) return strval($id);
        if ($external && strpos($external, $label_l) !== false) return strval($id);
    }
    return '';
}

function roxy_eb_sling_api_create_shift($body, $context = []) {
    $res = roxy_eb_sling_api_request('POST', '/v1/shifts', $body, $context);
    if (is_wp_error($res)) return $res;
    if (is_array($res) && isset($res['id'])) return $res['id'];
    if (is_array($res) && isset($res[0]) && is_array($res[0]) && isset($res[0]['id'])) return $res[0]['id'];
    if (is_scalar($res)) return $res;
    return new WP_Error('bad_response', 'Unexpected create shift response.');
}

function roxy_eb_sling_api_update_shift($shift_id, $body, $context = []) {
    return roxy_eb_sling_api_request('PUT', '/v1/shifts/' . rawurlencode($shift_id), $body, $context);
}

function roxy_eb_sling_api_delete_shift($shift_id, $context = []) {
    return roxy_eb_sling_api_request('DELETE', '/v1/shifts/' . rawurlencode($shift_id), null, $context);
}

function roxy_eb_sling_api_publish_shift($shift_id, $context = []) {
    return roxy_eb_sling_api_request('POST', '/v1/shifts/' . rawurlencode($shift_id) . '/sync', null, $context);
}

function roxy_eb_sling_api_list($type) {
    $type = strtolower($type);
    if (!in_array($type, ['locations','positions'], true)) return new WP_Error('bad_list', 'Invalid list type');
    return roxy_eb_sling_api_request('GET', '/v1/' . $type, null);
}

function roxy_eb_sling_api_request($method, $path, $body = null, $context = []) {
    $settings = roxy_eb_get_settings();
    $base = rtrim($settings['sling_base_url'] ?? 'https://api.getsling.com', '/');
    $url = $base . $path;

    $token = roxy_eb_sling_get_token();
    if (is_wp_error($token)) return $token;

    $args = [
        'method' => $method,
        'timeout' => 20,
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => $token,
        ],
    ];
    if ($body !== null) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($body);
    }

    $resp = wp_remote_request($url, $args);
    if (is_wp_error($resp)) {
        roxy_eb_sling_log_api($context, $path, 0, 'Transport error', $body, $resp->get_error_message());
        roxy_eb_sling_notify_failure($context, 0, $path, $resp->get_error_message());
        return $resp;
    }

    $code = wp_remote_retrieve_response_code($resp);
    $respBody = wp_remote_retrieve_body($resp);

    if ($code === 401 || $code === 403) {
        roxy_eb_sling_notify_auth_failure($code, $path, $respBody);
        roxy_eb_sling_notify_failure($context, $code, $path, $respBody);
        roxy_eb_sling_log_api($context, $path, $code, 'Auth failure', $body, $respBody);
        return new WP_Error('sling_auth', 'Sling authorization failed (HTTP ' . $code . '). Update the Sling token in settings.');
    }

    if ($code < 200 || $code >= 300) {
        roxy_eb_sling_log_api($context, $path, $code, 'HTTP error', $body, $respBody);
        roxy_eb_sling_notify_failure($context, $code, $path, $respBody);
        return new WP_Error('sling_http', 'Sling API HTTP ' . $code . ': ' . substr($respBody, 0, 300));
    }

    $decoded = json_decode($respBody, true);
    $out = is_null($decoded) ? $respBody : $decoded;
    if (is_array($context) && !empty($context['action'])) roxy_eb_sling_log_api($context, $path, $code, 'OK', $body, $out);
    return $out;
}

function roxy_eb_sling_get_token() {
    $settings = roxy_eb_get_settings();
    $mode = $settings['sling_mode'] ?? 'disabled';
    if ($mode !== 'direct') return '';
    $token = roxy_eb_sling_decrypt_secret($settings['sling_auth_token_enc'] ?? '');
    if (empty($token)) return new WP_Error('missing_token', 'Sling token not configured. Paste the Authorization token in settings.');
    return $token;
}

function roxy_eb_sling_login($email, $password) {
    $settings = roxy_eb_get_settings();
    $base = rtrim($settings['sling_base_url'] ?? 'https://api.getsling.com', '/');
    $url = $base . '/account/login';
    $resp = wp_remote_post($url, [
        'timeout' => 20,
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body' => wp_json_encode(['email' => $email, 'password' => $password]),
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $headers = wp_remote_retrieve_headers($resp);
    if ($code < 200 || $code >= 300) {
        $body = wp_remote_retrieve_body($resp);
        return new WP_Error('login_failed', 'Login failed HTTP ' . $code . ': ' . substr($body, 0, 300));
    }
    $auth = '';
    if (is_array($headers)) {
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'authorization') { $auth = is_array($v) ? reset($v) : $v; break; }
        }
    } elseif (is_object($headers) && method_exists($headers, 'getAll')) {
        $all = $headers->getAll();
        foreach ($all as $k => $v) {
            if (strtolower($k) === 'authorization') { $auth = is_array($v) ? reset($v) : $v; break; }
        }
    }
    $auth = trim(strval($auth));
    if (empty($auth)) return new WP_Error('missing_auth_header', 'Login succeeded but Sling did not return an Authorization header.');
    return $auth;
}

function roxy_eb_sling_encrypt_secret($plain) {
    $plain = strval($plain);
    if ($plain === '') return '';
    $key = hash('sha256', (defined('AUTH_SALT') ? AUTH_SALT : 'roxy-eb') . '|' . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : 'roxy-eb'), true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) return '';
    return base64_encode($iv . $cipher);
}

function roxy_eb_sling_decrypt_secret($enc) {
    $enc = strval($enc);
    if ($enc === '') return '';
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $key = hash('sha256', (defined('AUTH_SALT') ? AUTH_SALT : 'roxy-eb') . '|' . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : 'roxy-eb'), true);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

function roxy_eb_sling_notify_failure($context, $http_code, $path, $respBody) {
    $settings = roxy_eb_get_settings();
    $to = sanitize_email($settings['sling_auth_fail_email'] ?? get_option('admin_email'));
    if (!$to) return;
    $action = (string)($context['action'] ?? '');
    $booking_id = intval($context['booking_id'] ?? 0);
    $order_id = intval($context['order_id'] ?? 0);
    if ($action === '') return;

    $key = 'roxy_eb_sling_fail_notice_' . $booking_id . '_' . sanitize_key($action);
    $last = intval(get_option($key, 0));
    $now = time();
    if ($last && ($now - $last) < 6 * HOUR_IN_SECONDS) return;
    update_option($key, $now, false);

    $code = intval($http_code);
    $snippet = is_string($respBody) ? $respBody : wp_json_encode($respBody);
    $snippet = wp_strip_all_tags((string)$snippet);
    if (strlen($snippet) > 800) $snippet = substr($snippet, 0, 800) . '…';

    $subject = 'ROXY: Sling automation FAILED (Booking #' . $booking_id . ') — ' . $action;
    $lines = [
        'Action: ' . $action,
        'Booking ID: ' . $booking_id,
    ];
    if ($order_id) $lines[] = 'Order ID: ' . $order_id;
    $lines[] = 'Endpoint: ' . $path;
    $lines[] = 'HTTP: ' . $code;
    $lines[] = '';
    $lines[] = 'Message:';
    $lines[] = $snippet;
    $lines[] = '';
    $lines[] = 'Next steps:';
    $lines[] = '- Check Roxy Bookings → Sling Logs for details';
    $lines[] = '- Verify token + mapping IDs in Roxy Bookings → Settings';
    $lines[] = '- Use "Retry Sling Sync" on the booking after correcting settings';

    wp_mail($to, $subject, implode("\n", $lines));
}

function roxy_eb_sling_notify_auth_failure($http_code, $path, $body) {
    $settings = roxy_eb_get_settings();
    $to = $settings['sling_auth_fail_email'] ?? ($settings['internal_email'] ?? get_option('admin_email'));
    if (empty($to) || !is_email($to)) $to = get_option('admin_email');

    $token = roxy_eb_sling_decrypt_secret($settings['sling_auth_token_enc'] ?? '');
    $token_hash = substr(hash('sha256', (string)$token), 0, 12);

    $opt_key = 'roxy_eb_sling_auth_fail_notice';
    $state = get_option($opt_key, []);
    if (!is_array($state)) $state = [];

    $last_ts = intval($state['ts'] ?? 0);
    $last_hash = (string)($state['token_hash'] ?? '');
    if ($last_hash === $token_hash && (time() - $last_ts) < 6 * 3600) return;

    $site = home_url();
    $subject = 'Newport Roxy: Sling authorization failed — update token';
    $msg = "Sling API authorization failed (HTTP {$http_code}).\n\n"
         . "Site: {$site}\n"
         . "Endpoint: {$path}\n"
         . "Time: " . date('c') . "\n\n"
         . "Action needed: Update the Sling Authorization token in WP Admin → Roxy Bookings → Settings → Sling Integration.\n\n"
         . "Response (truncated): " . substr((string)$body, 0, 300) . "\n";
    wp_mail($to, $subject, $msg);

    update_option($opt_key, ['ts' => time(), 'token_hash' => $token_hash], false);
}

function roxy_eb_sling_log_api($context, $endpoint, $http_code, $message, $request_body = null, $response_body = null) {
    if (!function_exists('roxy_eb_repo_insert_sling_log')) return;
    $booking_id = 0;
    $action = 'api';
    if (is_array($context)) {
        $booking_id = intval($context['booking_id'] ?? 0);
        $action = sanitize_text_field($context['action'] ?? $action);
    }
    $req = $request_body !== null ? wp_json_encode($request_body) : null;
    $resp = $response_body !== null ? (is_string($response_body) ? $response_body : wp_json_encode($response_body)) : null;
    try {
        roxy_eb_repo_insert_sling_log([
            'booking_id' => $booking_id ?: null,
            'action' => $action ?: 'api',
            'endpoint' => $endpoint,
            'http_code' => $http_code,
            'message' => $message,
            'request_json' => $req,
            'response_body' => $resp,
        ]);
    } catch (Throwable $e) {}
}

function roxy_eb_sling_admin_create_test_shift($settings) {
    if (($settings['sling_mode'] ?? 'disabled') !== 'direct') return new WP_Error('not_direct', 'Set Sling Mode to “Direct API” to create a test shift.');
    $map = roxy_eb_sling_get_mapping();
    if (is_wp_error($map)) return $map;

    $tz = wp_timezone();
    $tomorrow = new DateTimeImmutable('tomorrow 18:00', $tz);
    $end = $tomorrow->modify('+3 hours');

    $body = [
        'summary' => 'Roxy Test - Private Show',
        'location' => ['id' => intval($map['location_id'])],
        'position' => ['id' => intval($map['position_private_show_id'])],
        'dtstart' => $tomorrow->format(DATE_ATOM),
        'dtend' => $end->format(DATE_ATOM),
        'available' => true,
        'availableSlots' => 1,
    ];

    $shift_id = roxy_eb_sling_api_create_shift($body, ['action' => 'create_test_shift']);
    if (is_wp_error($shift_id)) return $shift_id;
    return 'Created shift ID ' . $shift_id . ' (unpublished/planning).';
}
