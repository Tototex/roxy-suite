<?php
namespace RoxySocial;

if (!defined('ABSPATH')) exit;

final class Campaigns {
    public static function init(): void {
        add_action('save_post_roxy_showing', [__CLASS__, 'showing_saved'], 50, 2);
    }

    public static function showing_saved(int $post_id, $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!$post || $post->post_status !== 'publish' || !current_user_can('edit_post', $post_id)) return;
        self::generate_for_showing($post_id);
    }

    public static function generate_for_showing(int $post_id): void {
        $title = trim((string) get_the_title($post_id));
        $start = (string) get_post_meta($post_id, '_roxy_start', true);
        if ($title === '' || $start === '') return;
        $timestamp = self::local_timestamp($start);
        if (!$timestamp) return;
        $anchor = new \DateTimeImmutable('@' . $timestamp);
        $anchor = $anchor->setTimezone(wp_timezone())->setTime(0, 0);
        $day = (int) $anchor->format('N');
        if ($day < 5 || $day > 7) return;
        $friday = $anchor->modify('-' . ($day - 5) . ' days');
        $today = new \DateTimeImmutable('now', wp_timezone());
        if ($friday < $today->setTime(0, 0)) return;
        $posts = get_posts([
            'post_type' => 'roxy_showing',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'meta_value',
            'meta_key' => '_roxy_start',
            'order' => 'ASC',
            'meta_query' => [[
                'key' => '_roxy_start',
                'value' => [$friday->format('Y-m-d') . 'T00:00', $friday->modify('+2 days')->format('Y-m-d') . 'T23:59'],
                'compare' => 'BETWEEN',
                'type' => 'CHAR',
            ]],
        ]);
        $showings = [];
        foreach ($posts as $showing) {
            if (strcasecmp(trim($showing->post_title), $title) !== 0) continue;
            $showings[] = $showing;
        }
        if (!$showings) return;

        $campaign_key = sanitize_title($title) . '-' . $friday->format('Ymd');
        $media_url = get_the_post_thumbnail_url($post_id, 'large') ?: '';
        $trailer_url = (string) get_post_meta($post_id, '_roxy_trailer_url', true);
        $times = self::format_showtimes($showings);
        $templates = [
            ['mon', -4, 'Coming this weekend', true],
            ['wed', -2, 'This weekend at the Roxy', true],
            ['fri', 0, 'Now playing', false],
            ['sat', 1, 'Now playing', false],
            ['sun', 2, 'Today at the Roxy', false],
        ];
        foreach ($templates as [$key, $offset, $heading, $trailer_post]) {
            $scheduled = $friday->modify($offset . ' days')->setTime(10, 0);
            $post_key = $campaign_key . '-' . $key;
            $text = $heading . ":\n\n" . $title . "\n\nShowtimes:\n" . $times . "\n\nTickets: " . get_permalink($post_id);
            Store::upsert([
                'campaign_key' => $campaign_key,
                'post_key' => $post_key,
                'showing_ids' => implode(',', wp_list_pluck($showings, 'ID')),
                'scheduled_for' => $scheduled->format('Y-m-d H:i:s'),
                'post_text' => $text,
                'media_type' => $trailer_post && $trailer_url ? 'video_link' : 'image',
                'media_url' => $media_url,
                'trailer_url' => $trailer_url,
            ]);
        }
    }

    private static function format_showtimes(array $showings): string {
        $lines = [];
        foreach ($showings as $showing) {
            $start = (string) get_post_meta($showing->ID, '_roxy_start', true);
            $timestamp = self::local_timestamp($start);
            if ($timestamp) $lines[] = wp_date('D, M j \\a\\t g:i A', $timestamp, wp_timezone());
        }
        return implode("\n", $lines);
    }

    private static function local_timestamp(string $value): int {
        $parsed = date_create($value, wp_timezone());
        return $parsed ? $parsed->getTimestamp() : 0;
    }
}
