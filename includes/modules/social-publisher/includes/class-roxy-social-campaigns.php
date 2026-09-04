<?php
namespace RoxySocial;

if (!defined('ABSPATH')) exit;

final class Campaigns {
    public static function init(): void {
        add_action('save_post_roxy_showing', [__CLASS__, 'showing_saved'], 50, 2);
        add_action('roxy_social_auto_assign_media', [__CLASS__, 'auto_assign_media'], 10, 3);
        add_action('roxy_social_auto_assign_asset', [__CLASS__, 'auto_assign_asset'], 10, 5);
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
        if (Hangar::has_credentials() && !wp_next_scheduled('roxy_social_auto_assign_media', [$campaign_key, $title, $post_id])) wp_schedule_single_event(time() + 5, 'roxy_social_auto_assign_media', [$campaign_key, $title, $post_id]);
    }

    public static function auto_assign_media(string $campaign_key, string $title, int $showing_id): void {
        if ($campaign_key === '' || $title === '' || !Hangar::has_credentials()) return;
        $assets = array_values(array_filter(Hangar::search($title), static function (array $asset): bool {
            $filename = strtolower((string) ($asset['filename'] ?? ''));
            $type = strtolower((string) ($asset['asset_category'] ?? '') . ' ' . (string) ($asset['file_type'] ?? ''));
            return strpos($type, 'video') !== false || (bool) preg_match('/\.(mp4|mov|m4v|webm)$/i', $filename);
        }));
        if (!$assets) return;
        usort($assets, static function (array $left, array $right): int {
            $left_vertical = self::looks_vertical($left) ? 0 : 1;
            $right_vertical = self::looks_vertical($right) ? 0 : 1;
            if ($left_vertical !== $right_vertical) return $left_vertical <=> $right_vertical;
            return (strtotime((string) ($left['start_date'] ?? '')) ?: PHP_INT_MAX) <=> (strtotime((string) ($right['start_date'] ?? '')) ?: PHP_INT_MAX);
        });
        $drafts = Store::campaign_rows($campaign_key);
        $used = [];
        $delay = 10;
        foreach ($drafts as $draft) {
            if (!in_array((string) $draft['status'], ['draft', 'needs_review'], true) || !empty($draft['hangar_asset_id'])) continue;
            foreach ($assets as $asset) {
                $asset_id = (int) $asset['asset_id'];
                if (isset($used[$asset_id])) continue;
                if (wp_schedule_single_event(time() + $delay, 'roxy_social_auto_assign_asset', [$campaign_key, $showing_id, (int) $draft['id'], $asset_id, (string) $asset['filename']])) { $used[$asset_id] = true; $delay += 30; break; }
            }
        }
    }

    public static function auto_assign_asset(string $campaign_key, int $showing_id, int $draft_id, int $asset_id, string $filename): void {
        $draft = Store::find($draft_id);
        if (!$draft || (string) $draft['campaign_key'] !== $campaign_key || !in_array((string) $draft['status'], ['draft', 'needs_review'], true) || !empty($draft['hangar_asset_id'])) return;
        Hangar::import_social_asset($asset_id, $filename, $showing_id, $draft_id);
    }

    private static function looks_vertical(array $asset): bool {
        $text = strtolower(implode(' ', [(string) ($asset['filename'] ?? ''), (string) ($asset['asset_name'] ?? ''), (string) ($asset['description'] ?? '')]));
        return (bool) preg_match('/9x16|vertical|portrait|1080x1920|1080x1350|4x5/', $text);
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
