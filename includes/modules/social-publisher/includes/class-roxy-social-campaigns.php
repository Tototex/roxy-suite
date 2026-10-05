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
        $source = get_post($post_id);
        if (!$source || $source->post_type !== 'roxy_showing' || $source->post_status !== 'publish') return;
        $title = trim((string) $source->post_title);
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
            $remaining = array_values(array_filter($showings, static function ($showing) use ($scheduled): bool {
                $stamp = self::local_timestamp((string) get_post_meta($showing->ID, '_roxy_start', true));
                return $stamp >= $scheduled->setTime(0, 0)->getTimestamp();
            }));
            $day_times = self::format_showtimes($remaining);
            if ($day_times === '') continue;
            $text = $heading . ":\n\n" . $title . "\n\nShowtimes:\n" . $day_times . "\n\nTickets: " . get_permalink($post_id);
            Store::upsert([
                'campaign_key' => $campaign_key,
                'post_key' => $post_key,
                'showing_ids' => implode(',', wp_list_pluck($remaining, 'ID')),
                'scheduled_for' => $scheduled->format('Y-m-d H:i:s'),
                'post_text' => $text,
                'media_type' => $trailer_post && $trailer_url ? 'video_link' : 'image',
                'media_url' => $media_url,
                'trailer_url' => $trailer_url,
            ]);
        }
        if (Hangar::has_credentials() && !wp_next_scheduled('roxy_social_auto_assign_media', [$campaign_key, $title, $post_id])) wp_schedule_single_event(time() + 5, 'roxy_social_auto_assign_media', [$campaign_key, $title, $post_id]);
        AI::queue_campaign($campaign_key);
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
        $used_by_prior_campaign = [];
        foreach (Store::all_recent() as $existing) {
            $asset_id = (int) ($existing['hangar_asset_id'] ?? 0);
            if ($asset_id <= 0) continue;
            $showing_ids = array_filter(array_map('absint', explode(',', (string) ($existing['showing_ids'] ?? ''))));
            $same_showing = in_array($showing_id, $showing_ids, true);
            if (!$same_showing && $showing_ids) {
                foreach ($showing_ids as $existing_showing_id) {
                    if (strcasecmp(trim((string) get_the_title($existing_showing_id)), trim($title)) === 0) {
                        $same_showing = true;
                        break;
                    }
                }
            }
            if ($same_showing && (string) ($existing['campaign_key'] ?? '') !== $campaign_key) $used_by_prior_campaign[$asset_id] = true;
        }
        $used_in_campaign = [];
        $delay = 10;
        foreach ($drafts as $draft) {
            if (!in_array((string) $draft['status'], ['draft', 'needs_review'], true) || !empty($draft['hangar_asset_id'])) continue;
            $selected = null;
            foreach ($assets as $asset) {
                $asset_id = (int) $asset['asset_id'];
                if (isset($used_in_campaign[$asset_id]) || isset($used_by_prior_campaign[$asset_id])) continue;
                $selected = $asset;
                break;
            }
            // Reuse an older asset only when no unused matching asset remains.
            if (!$selected) {
                foreach ($assets as $asset) {
                    $asset_id = (int) $asset['asset_id'];
                    if (!isset($used_in_campaign[$asset_id])) {
                        $selected = $asset;
                        break;
                    }
                }
            }
            if ($selected) {
                $asset_id = (int) $selected['asset_id'];
                if (wp_schedule_single_event(time() + $delay, 'roxy_social_auto_assign_asset', [$campaign_key, $showing_id, (int) $draft['id'], $asset_id, (string) $selected['filename']])) {
                    $used_in_campaign[$asset_id] = true;
                    $delay += 30;
                }
            }
        }
    }

    public static function auto_assign_asset(string $campaign_key, int $showing_id, int $draft_id, int $asset_id, string $filename): void {
        $draft = Store::find($draft_id);
        if (!$draft || (string) $draft['campaign_key'] !== $campaign_key || (string) $draft['status'] !== 'draft' || ($draft['ai_status'] ?? '') === 'manual' || !empty($draft['last_error']) || !empty($draft['hangar_asset_id'])) return;
        $attachment_id = Hangar::import_social_asset($asset_id, $filename, $showing_id, $draft_id);
        if ($attachment_id) self::maybe_auto_approve($draft_id);
    }

    public static function maybe_auto_approve(int $draft_id): void {
        if (!get_option('roxy_social_auto_approve', false)) return;
        $draft = Store::find($draft_id);
        if (!$draft || (string) $draft['status'] !== 'draft') return;
        if (($draft['ai_status'] ?? '') === 'manual') return;
        if (!empty($draft['facebook_post_id']) || !empty($draft['instagram_media_id']) || !empty($draft['instagram_container_id']) || !empty($draft['last_error'])) return;
        if (empty($draft['media_url']) || empty($draft['hangar_asset_id'])) return;
        if (AI::enabled() && (string) ($draft['ai_status'] ?? 'pending') !== 'ready') return;
        if (!self::verified_caption_schedule($draft)) {
            Store::review_snapshot($draft, 'The caption schedule no longer matches the published showings. Review before approving.');
            return;
        }
        Store::approve_snapshot($draft);
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

    /** Canonical published records, never caption parsing or assumed weekday times. */
    public static function verified_showtimes(array $draft): array {
        $raw = trim((string) ($draft['showing_ids'] ?? ''));
        if ($raw === '' || !preg_match('/^\d+(?:,\d+)*$/', $raw)) throw new \RuntimeException('Missing or invalid showing references.');
        $ids = array_unique(array_map('intval', explode(',', $raw)));
        if (count($ids) > 50) throw new \RuntimeException('Too many showing references.');
        $scheduled = self::local_timestamp((string) ($draft['scheduled_for'] ?? ''));
        if (!$scheduled) throw new \RuntimeException('Invalid Social schedule date.');
        $cutoff = (new \DateTimeImmutable('@' . $scheduled))->setTimezone(wp_timezone())->setTime(0, 0)->getTimestamp();
        $rows = [];
        $title = null;
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'roxy_showing' || $post->post_status !== 'publish') throw new \RuntimeException('A referenced showing is no longer published.');
            $show_title = trim((string) $post->post_title);
            if ($show_title === '' || ($title !== null && strcasecmp($title, $show_title) !== 0)) throw new \RuntimeException('The showing titles do not match.');
            $title = $show_title;
            $stamp = self::local_timestamp((string) get_post_meta($id, '_roxy_start', true));
            if (!$stamp) throw new \RuntimeException('A referenced showing has an invalid date.');
            if ($stamp < $cutoff) continue;
            $rows[] = ['id' => $id, 'timestamp' => $stamp, 'title' => (string) $post->post_title,
                'line' => wp_date('D, M j, Y \\a\\t g:i A', $stamp, wp_timezone())];
        }
        usort($rows, static fn(array $a, array $b): int => ($a['timestamp'] <=> $b['timestamp']) ?: ($a['id'] <=> $b['id']));
        if (!$rows) throw new \RuntimeException('No remaining published showings for this draft.');
        return $rows;
    }

    /** Reject stale/extra/assumed schedule lines without changing an approved caption. */
    public static function verified_caption_schedule(array $draft): bool {
        if (($draft['campaign_key'] ?? '') === 'manual' && empty($draft['showing_ids'])) return true;
        try { $rows = self::verified_showtimes($draft); }
        catch (\RuntimeException $e) { return false; }
        $lines = [];
        foreach (preg_split('/\R/', (string) ($draft['post_text'] ?? '')) as $line) {
            if (!preg_match('/\b\d{1,2}:\d{2}\s*[AP]M\b|\b\d{1,2}\s*[AP]M\b/i', $line)) continue;
            $lines[] = trim((string) preg_replace('/^\s*(?:Tonight|Today)\s*[—-]\s*/u', '', $line));
        }
        if (count($lines) !== count($rows)) return false;
        foreach ($rows as $row) {
            $without_year = wp_date('D, M j \\a\\t g:i A', $row['timestamp'], wp_timezone());
            $match = array_search($row['line'], $lines, true);
            if ($match === false) $match = array_search($without_year, $lines, true);
            if ($match === false) return false;
            unset($lines[$match]);
        }
        return !$lines;
    }

    private static function local_timestamp(string $value): int {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?$/D', $value)) return 0;
        $normalized = str_replace('T', ' ', $value);
        $format = strlen($normalized) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
        $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $normalized, wp_timezone());
        return $parsed && $parsed->format($format) === $normalized ? $parsed->getTimestamp() : 0;
    }
}
