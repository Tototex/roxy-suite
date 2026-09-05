<?php
namespace RoxySocial;

if (!defined('ABSPATH')) exit;

final class AI {
    public static function init(): void {
        add_action('roxy_social_generate_ai_text', [__CLASS__, 'generate_text'], 10, 2);
        add_action('admin_post_roxy_social_ai_settings', [__CLASS__, 'save_settings']);
        add_action('admin_post_roxy_social_ai_test', [__CLASS__, 'test_connection']);
    }

    public static function enabled(): bool {
        return (bool) get_option('roxy_social_ai_enabled', false) && self::endpoint() !== '' && self::model() !== '';
    }

    public static function endpoint(): string {
        return untrailingslashit(esc_url_raw((string) get_option('roxy_social_ai_endpoint', 'http://127.0.0.1:11434')));
    }

    public static function model(): string {
        return sanitize_text_field((string) get_option('roxy_social_ai_model', 'llama3.2:latest'));
    }

    public static function style_prompt(): string {
        return (string) get_option('roxy_social_ai_style', 'Write in the Newport Roxy Theater voice: warm, local, concise, playful when appropriate, and never misleading. Use short lines, vivid but accurate hooks, and a friendly small-town theater feel.');
    }

    private static function film_context(string $campaign_key): string {
        $slug = sanitize_title((string) preg_replace('/-\d{8}$/', '', $campaign_key));
        if ($slug !== 'forgotten-island') return '';
        return "\n\nVerified film context for Forgotten Island (2026): DreamWorks Animation describes it as an emotional animated adventure/comedy/fantasy about two lifelong best friends who must come together before they drift apart. Use only those verified themes: friendship, adventure, mystery, humor, and the feeling of an unusual island journey. Do not claim a specific plot event, character, cast member, award, review, or fact that is not in this context or the current draft.";
    }

    public static function queue_campaign(string $campaign_key): void {
        if (!self::enabled()) return;
        foreach (Store::campaign_rows($campaign_key) as $index => $draft) {
            if (!in_array((string) $draft['status'], ['draft', 'needs_review'], true)) continue;
            if (!wp_next_scheduled('roxy_social_generate_ai_text', [(int) $draft['id'], $campaign_key])) wp_schedule_single_event(time() + 10 + ($index * 30), 'roxy_social_generate_ai_text', [(int) $draft['id'], $campaign_key]);
        }
    }

    public static function generate_text(int $draft_id, string $campaign_key): void {
        if (!self::enabled()) return;
        $draft = Store::find($draft_id);
        if (!$draft || (string) $draft['campaign_key'] !== $campaign_key || !in_array((string) $draft['status'], ['draft', 'needs_review'], true)) return;
        $scheduled = date_create((string) $draft['scheduled_for'], wp_timezone());
        $day = $scheduled ? wp_date('l', $scheduled->getTimestamp(), wp_timezone()) : 'scheduled day';
        $title = ucwords(str_replace('-', ' ', (string) preg_replace('/-\d{8}$/', '', $campaign_key)));
        $day_guidance = match (strtolower($day)) {
            'monday' => 'Use a coming-this-weekend or keep-it-on-the-radar angle.',
            'wednesday' => 'Use a this-weekend angle with a playful invitation.',
            'friday' => 'Treat this as opening night and make tonight feel like an event.',
            'saturday' => 'Use a Saturday-night-plans angle with a light popcorn joke.',
            'sunday' => 'Treat this as the Sunday matinee or final chance and mention the 2:30 PM show when present.',
            default => 'Choose a natural angle that fits the posting day.',
        };
        $prompt = self::style_prompt() . self::film_context($campaign_key) . "\n\nCreate one social media caption for the Newport Roxy Theater.\nMovie/show title: " . $title . "\nPosting day: " . $day . "\nDay guidance: " . $day_guidance . "\nCurrent draft information:\n" . (string) $draft['post_text'] . "\n\nRequirements:\n- Return only the finished caption, with no explanation, quotation marks, or preamble.\n- Keep it under 900 characters.\n- Preserve the exact title, dates, showtimes, and ticket link from the current draft. Never shorten away dates or times.\n- Write like the sample Roxy posts: a memorable opening hook, short readable lines, a warm local invitation, and a small movie-related joke or observation when it is supported by the verified context.\n- Use the day guidance to make the five posts feel meaningfully different, not like the same caption rearranged.\n- Use one or two tasteful emojis only when they improve the post. Hashtags are optional; use no more than 3 relevant hashtags.\n- Never invent plot events, character names, cast, reviews, awards, runtime, or other film facts. If a detail is not verified, keep the joke general or omit it.\n- Do not use markdown bullets unless they make showtimes easier to scan; blank lines and short lines are encouraged.";
        $response = wp_remote_post(self::endpoint() . '/api/chat', [
            'timeout' => 90,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'model' => self::model(),
                'stream' => false,
                'messages' => [
                    ['role' => 'system', 'content' => 'You write accurate, engaging theater social captions.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'options' => ['temperature' => 0.85],
            ]),
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 300) return;
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $text = trim((string) ($body['message']['content'] ?? ''));
        if ($text !== '') Store::update_text($draft_id, $text);
    }

    public static function connection_status(): string {
        if (self::endpoint() === '') return 'Enter an Ollama endpoint first.';
        $response = wp_remote_get(self::endpoint() . '/api/tags', ['timeout' => 10]);
        if (is_wp_error($response)) return 'Connection failed: ' . $response->get_error_message();
        if (wp_remote_retrieve_response_code($response) >= 300) return 'Connection failed with HTTP ' . wp_remote_retrieve_response_code($response) . '.';
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $models = array_filter(array_map(static function ($model) { return (string) ($model['name'] ?? ''); }, (array) ($body['models'] ?? [])));
        return $models ? 'Connected. Available models: ' . implode(', ', $models) : 'Connected, but no models were reported.';
    }

    public static function save_settings(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        check_admin_referer('roxy_social_ai_settings');
        update_option('roxy_social_ai_enabled', !empty($_POST['ai_enabled']), false);
        update_option('roxy_social_ai_endpoint', untrailingslashit(esc_url_raw((string) ($_POST['ai_endpoint'] ?? ''))), false);
        update_option('roxy_social_ai_model', sanitize_text_field((string) ($_POST['ai_model'] ?? '')), false);
        update_option('roxy_social_ai_style', sanitize_textarea_field((string) ($_POST['ai_style'] ?? '')), false);
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&tab=ai&saved=1'));
        exit;
    }

    public static function test_connection(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        check_admin_referer('roxy_social_ai_test');
        $status = self::connection_status();
        set_transient('roxy_social_ai_test_status', $status, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&tab=ai&tested=1'));
        exit;
    }
}
