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
        return (string) get_option('roxy_social_ai_style', 'Write in the Newport Roxy Theater voice: warm, local, concise, playful when appropriate, and never misleading.');
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
        $prompt = self::style_prompt() . "\n\nCreate one social media caption for the Newport Roxy Theater.\nMovie/show title and campaign context: " . str_replace('-', ' ', $campaign_key) . "\nPosting day: " . $day . "\nCurrent draft information:\n" . (string) $draft['post_text'] . "\n\nRequirements:\n- Return only the finished caption, with no explanation or quotation marks.\n- Keep it under 900 characters.\n- Preserve the factual title, showtimes, ticket link, and any useful details from the current draft.\n- Vary the structure naturally: sometimes use a short hook, sometimes a compact bullet list, and occasionally one or two tasteful emojis.\n- Add a small movie-related joke, observation, or trivia-style line only when it fits; never invent cast, plot, awards, or showtime facts.\n- Do not use hashtags excessively; use 3 to 6 relevant hashtags at most.";
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
