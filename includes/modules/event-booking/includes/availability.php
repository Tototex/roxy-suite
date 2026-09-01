<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_parse_hhmm($hhmm) {
    if (!preg_match('/^(\d{2}):(\d{2})$/', $hhmm, $m)) return null;
    $h = intval($m[1]); $min=intval($m[2]);
    if ($h < 0 || $h > 24) return null;
    if ($min < 0 || $min > 59) return null;
    if ($h === 24 && $min !== 0) return null;
    return [$h,$min];
}

function roxy_eb_dt_from_date_and_time(DateTimeImmutable $date, $hhmm) {
    $p = roxy_eb_parse_hhmm($hhmm);
    if (!$p) return null;
    [$h,$m] = $p;
    // If 24:00, treat as end-of-day (next day 00:00)
    if ($h === 24) {
        return $date->setTime(0,0)->modify('+1 day');
    }
    return $date->setTime($h,$m);
}

function roxy_eb_showtime_block_override(DateTimeImmutable $date, array $block): array {
    $weekday = intval($date->format('w'));
    $ymd = $date->format('Y-m-d');

    // Allow 4 PM private-event starts on normal Friday/Saturday movie days.
    if (($weekday === 5 || $weekday === 6) && !empty($block['label']) && stripos((string) $block['label'], 'Regular Showing') !== false) {
        $block['start'] = '18:30';
        $block['end'] = '22:00';
    }

    // Special October 2026 Friday rentals: daytime blocked, 6 PM opening allowed.
    $octoberFridayOverrides = [
        '2026-10-02',
        '2026-10-09',
        '2026-10-16',
        '2026-10-23',
    ];
    if (in_array($ymd, $octoberFridayOverrides, true) && $weekday === 5 && !empty($block['label']) && stripos((string) $block['label'], 'Regular Showing') !== false) {
        $block['start'] = '13:00';
        $block['end'] = '17:30';
        $block['label'] = 'Reserved Rental Window (Fri Override)';
    }

    return $block;
}

function roxy_eb_get_showtime_blocks_for_range(DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd) {
    $settings = roxy_eb_get_settings();
    $blocks = [];
    $cursor = $rangeStart->setTime(0,0);
    while ($cursor < $rangeEnd) {
        $weekday = intval($cursor->format('w')); // 0..6
        foreach ($settings['showtime_blocks'] as $b) {
            if (intval($b['weekday']) !== $weekday) continue;
            $b = roxy_eb_showtime_block_override($cursor, $b);
            $start = roxy_eb_dt_from_date_and_time($cursor, $b['start']);
            $end   = roxy_eb_dt_from_date_and_time($cursor, $b['end']);
            if (!$start || !$end) continue;
            $blocks[] = [
                'type' => 'showtime',
                'title' => $b['label'] ?? 'Showtime Block',
                'start' => $start,
                'end' => $end,
            ];
        }
        $cursor = $cursor->modify('+1 day');
    }
    return $blocks;
}

function roxy_eb_get_showing_blocks_for_range(DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, bool $public_only = false): array {
    if (!class_exists('\\RoxyST\\CPT')) {
        return [];
    }

    // Existing showings have used more than one datetime string format over time.
    // Load the small showing set and compare parsed dates instead of relying on a
    // lexicographic meta query that can silently miss valid records.
    $posts = get_posts([
        'post_type' => \RoxyST\CPT::POST_TYPE,
        'post_status' => $public_only ? ['publish', 'future'] : ['publish', 'future', 'draft', 'private'],
        'posts_per_page' => -1,
        'fields' => 'ids',
    ]);

    if (empty($posts)) {
        return [];
    }

    $blocks = [];
    foreach ($posts as $post_id) {
        $raw_start = (string) get_post_meta((int) $post_id, '_roxy_start', true);
        if ($raw_start === '') {
            continue;
        }

        try {
            $show_start = new DateTimeImmutable($raw_start, wp_timezone());
        } catch (Exception $e) {
            continue;
        }

        if ($show_start < $rangeStart || $show_start >= $rangeEnd) {
            continue;
        }

        // Public showings reserve the room around the actual showtime.
        $reserved_start = $show_start->modify('-2 hours');
        $reserved_end = $show_start->modify('+2 hours');

        if ($reserved_start >= $rangeEnd || $reserved_end <= $rangeStart) {
            continue;
        }

        $blocks[] = [
            'type' => 'showing',
            'title' => get_the_title((int) $post_id) ?: 'Showing',
            'start' => $reserved_start,
            'end' => $reserved_end,
            'show_start' => $show_start,
            'showing_id' => (int) $post_id,
        ];
    }

    return $blocks;
}

function roxy_eb_calc_times(DateTimeImmutable $doorsOpen, int $extraHours) {
    // Customer-facing duration: 2h + extras
    $guestHours = 2 + max(0, $extraHours);
    $doorsClose = $doorsOpen->modify('+' . $guestHours . ' hours');
    $reservedStart = $doorsOpen->modify('-30 minutes');
    $reservedEnd = $doorsClose->modify('+30 minutes');
    $showStart = $doorsOpen->modify('+30 minutes');
    return [
        'guest_hours' => $guestHours,
        'doors_close' => $doorsClose,
        'reserved_start' => $reservedStart,
        'reserved_end' => $reservedEnd,
        'show_start' => $showStart,
    ];
}

function roxy_eb_time_within_operating_hours(DateTimeImmutable $doorsOpen, int $guestHours) {
    $settings = roxy_eb_get_settings();
    $date = $doorsOpen->setTime(0,0);
    $open = roxy_eb_dt_from_date_and_time($date, $settings['open_time']);
    $close = roxy_eb_dt_from_date_and_time($date, $settings['close_time']); // may be next day
    if (!$open || !$close) return false;

    // Doors open must be within [open, close)
    if ($doorsOpen < $open) return false;
    if ($doorsOpen >= $close) return false;

    // Doors close must be <= close
    $doorsClose = $doorsOpen->modify('+' . $guestHours . ' hours');
    if ($doorsClose > $close) return false;

    return true;
}

function roxy_eb_check_lead_time(DateTimeImmutable $doorsOpen) {
    $settings = roxy_eb_get_settings();
    $lead = intval($settings['lead_time_hours']);
    $now = new DateTimeImmutable('now', wp_timezone());
    return $doorsOpen >= $now->modify('+' . $lead . ' hours');
}

function roxy_eb_is_slot_available(DateTimeImmutable $reservedStart, DateTimeImmutable $reservedEnd, $ignore_booking_id = 0) {
    $start_mysql = roxy_eb_datetime_to_mysql($reservedStart);
    $end_mysql   = roxy_eb_datetime_to_mysql($reservedEnd);

    $bookings = roxy_eb_repo_list_bookings_in_range($start_mysql, $end_mysql);
    foreach ($bookings as $b) {
        if (intval($ignore_booking_id) > 0 && intval($b['id']) === intval($ignore_booking_id)) continue;
        return false;
    }

    // blocks
    $blocks = roxy_eb_repo_list_blocks_in_range($start_mysql, $end_mysql);
    if (!empty($blocks)) return false;

    // fixed showtime blocks
    $showBlocks = roxy_eb_get_showtime_blocks_for_range($reservedStart, $reservedEnd);
    foreach ($showBlocks as $sb) {
        /** @var DateTimeImmutable $sbStart */
        $sbStart = $sb['start']; $sbEnd = $sb['end'];
        if ($sbStart < $reservedEnd && $sbEnd > $reservedStart) return false;
    }

    $showingBlocks = roxy_eb_get_showing_blocks_for_range($reservedStart, $reservedEnd);
    foreach ($showingBlocks as $sb) {
        /** @var DateTimeImmutable $sbStart */
        $sbStart = $sb['start'];
        $sbEnd = $sb['end'];
        if ($sbStart < $reservedEnd && $sbEnd > $reservedStart) {
            return false;
        }
    }

    return true;
}

/**
 * Returns availability data for frontend calendar between start/end (ISO strings in site tz).
 * Includes background "blocked" events and existing bookings blocks.
 */
function roxy_eb_get_calendar_blocks(DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd) {
    $start_mysql = roxy_eb_datetime_to_mysql($rangeStart);
    $end_mysql = roxy_eb_datetime_to_mysql($rangeEnd);

    $items = [];

    // Existing bookings (reserved windows)
    $bookings = roxy_eb_repo_list_bookings_in_range($start_mysql, $end_mysql);
    foreach ($bookings as $b) {
        $items[] = [
            'kind' => 'booking',
            'title' => 'Reserved',
            'start' => $b['reserved_start_at'],
            'end' => $b['reserved_end_at'],
            'doors_open_at' => $b['doors_open_at'] ?? null,
            'visibility' => $b['visibility'] ?? 'private',
        ];
    }

    // Admin blocks
    $blocks = roxy_eb_repo_list_blocks_in_range($start_mysql, $end_mysql);
    foreach ($blocks as $bl) {
        $items[] = [
            'kind' => 'block',
            'title' => $bl['title'],
            'start' => $bl['start_at'],
            'end' => $bl['end_at'],
            'visibility' => $bl['visibility'] ?? 'private',
        ];
    }

    // Actual published showings replace the generic recurring marker when
    // they occupy the same reserved window, so visitors see the movie title.
    $showingBlocks = roxy_eb_get_showing_blocks_for_range($rangeStart, $rangeEnd, true);

    // Fixed showtime blocks
    $showBlocks = roxy_eb_get_showtime_blocks_for_range($rangeStart, $rangeEnd);
    foreach ($showBlocks as $sb) {
        $overlapsPublishedShowing = false;
        foreach ($showingBlocks as $showing) {
            if ($showing['start'] < $sb['end'] && $showing['end'] > $sb['start']) {
                $overlapsPublishedShowing = true;
                break;
            }
        }
        if ($overlapsPublishedShowing) continue;

        $items[] = [
            'kind' => 'showtime',
            'title' => $sb['title'],
            'start' => roxy_eb_datetime_to_mysql($sb['start']),
            'end' => roxy_eb_datetime_to_mysql($sb['end']),
        ];
    }

    // Only published/upcoming showings are returned to visitors. The validator
    // still considers internal drafts and private entries above.
    foreach ($showingBlocks as $sb) {
        $items[] = [
            'kind' => 'showing',
            'title' => $sb['title'],
            'start' => roxy_eb_datetime_to_mysql($sb['start']),
            'end' => roxy_eb_datetime_to_mysql($sb['end']),
            'doors_open_at' => roxy_eb_datetime_to_mysql($sb['show_start']),
            'visibility' => 'public',
        ];
    }

    return $items;
}
