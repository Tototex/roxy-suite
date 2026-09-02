<?php
namespace RoxySocial;

if (!defined('ABSPATH')) exit;

final class Meta {
    private const OPTIONS = [
        'app_id' => 'roxy_social_meta_app_id',
        'app_secret' => 'roxy_social_meta_app_secret',
        'page_id' => 'roxy_social_meta_page_id',
        'page_name' => 'roxy_social_meta_page_name',
        'instagram_user_id' => 'roxy_social_meta_instagram_user_id',
        'instagram_username' => 'roxy_social_meta_instagram_username',
        'access_token' => 'roxy_social_meta_access_token',
        'page_access_token' => 'roxy_social_meta_page_access_token',
    ];

    public static function save_settings(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        check_admin_referer('roxy_social_meta_settings');
        update_option(self::OPTIONS['app_id'], sanitize_text_field((string) ($_POST['meta_app_id'] ?? '')), false);
        update_option(self::OPTIONS['page_id'], sanitize_text_field((string) ($_POST['meta_page_id'] ?? '')), false);
        update_option(self::OPTIONS['page_name'], sanitize_text_field((string) ($_POST['meta_page_name'] ?? '')), false);
        update_option(self::OPTIONS['instagram_user_id'], sanitize_text_field((string) ($_POST['meta_instagram_user_id'] ?? '')), false);
        update_option(self::OPTIONS['instagram_username'], sanitize_text_field((string) ($_POST['meta_instagram_username'] ?? '')), false);
        foreach (['app_secret', 'access_token', 'page_access_token'] as $key) {
            $value = (string) ($_POST['meta_' . $key] ?? '');
            if ($value !== '') update_option(self::OPTIONS[$key], self::encrypt($value), false);
        }
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&tab=meta&saved=1'));
        exit;
    }

    public static function configured(): bool {
        return (string) get_option(self::OPTIONS['app_id'], '') !== ''
            && (string) get_option(self::OPTIONS['page_id'], '') !== ''
            && (string) get_option(self::OPTIONS['access_token'], '') !== '';
    }

    public static function app_secret_saved(): bool {
        return (string) get_option(self::OPTIONS['app_secret'], '') !== '';
    }

    public static function redirect_url(): string {
        return admin_url('admin-post.php?action=roxy_social_meta_callback');
    }

    public static function connect_url(): string {
        return add_query_arg([
            'client_id' => self::app_id(),
            'redirect_uri' => self::redirect_url(),
            'state' => wp_create_nonce('roxy_social_meta_connect'),
            'response_type' => 'code',
            'scope' => 'pages_show_list,pages_read_engagement,business_management,instagram_basic,instagram_content_publish',
        ], 'https://www.facebook.com/dialog/oauth');
    }

    public static function handle_callback(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        if (!empty($_GET['error'])) { wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&tab=meta&meta_error=cancelled')); exit; }
        $state = sanitize_text_field((string) ($_GET['state'] ?? ''));
        if (!$state || !wp_verify_nonce($state, 'roxy_social_meta_connect')) wp_die('Meta authorization could not be verified.');
        $code = sanitize_text_field((string) ($_GET['code'] ?? ''));
        if ($code === '' || !self::app_secret_saved()) wp_die('Meta authorization is missing required information.');
        $response = wp_remote_post('https://graph.facebook.com/oauth/access_token', [
            'timeout' => 30,
            'body' => [
                'client_id' => self::app_id(),
                'client_secret' => self::decrypt((string) get_option(self::OPTIONS['app_secret'], '')),
                'redirect_uri' => self::redirect_url(),
                'code' => $code,
            ],
        ]);
        $data = !is_wp_error($response) ? json_decode((string) wp_remote_retrieve_body($response), true) : null;
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ($token === '') { wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&tab=meta&meta_error=token')); exit; }
        update_option(self::OPTIONS['access_token'], self::encrypt($token), false);
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&tab=meta&meta_connected=1'));
        exit;
    }

    public static function verify_connection(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        check_admin_referer('roxy_social_meta_verify');
        $token = self::access_token();
        if ($token === '') {
            self::redirect_with_verify_status('missing');
        }
        $response = wp_remote_get(add_query_arg([
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'limit' => 100,
            'access_token' => $token,
        ], 'https://graph.facebook.com/me/accounts'), ['timeout' => 30]);
        $data = !is_wp_error($response) ? json_decode((string) wp_remote_retrieve_body($response), true) : null;
        $pages = is_array($data) && !empty($data['data']) && is_array($data['data']) ? $data['data'] : [];
        if (!$pages) self::redirect_with_verify_status('failed');
        $selected = null;
        foreach ($pages as $page) {
            if (!empty($page['instagram_business_account']['id'])) { $selected = $page; break; }
        }
        if (!$selected) $selected = $pages[0];
        update_option(self::OPTIONS['page_id'], sanitize_text_field((string) ($selected['id'] ?? '')), false);
        update_option(self::OPTIONS['page_name'], sanitize_text_field((string) ($selected['name'] ?? '')), false);
        if (!empty($selected['access_token'])) update_option(self::OPTIONS['page_access_token'], self::encrypt((string) $selected['access_token']), false);
        $instagram = is_array($selected['instagram_business_account'] ?? null) ? $selected['instagram_business_account'] : [];
        update_option(self::OPTIONS['instagram_user_id'], sanitize_text_field((string) ($instagram['id'] ?? '')), false);
        update_option(self::OPTIONS['instagram_username'], sanitize_text_field((string) ($instagram['username'] ?? '')), false);
        self::redirect_with_verify_status(!empty($instagram['id']) ? 'success' : 'no_instagram');
    }

    private static function redirect_with_verify_status(string $status): void {
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&tab=meta&meta_verified=' . rawurlencode($status)));
        exit;
    }

    public static function app_id(): string {
        return (string) get_option(self::OPTIONS['app_id'], '');
    }

    public static function page_id(): string {
        return (string) get_option(self::OPTIONS['page_id'], '');
    }

    public static function page_name(): string {
        return (string) get_option(self::OPTIONS['page_name'], '');
    }

    public static function instagram_user_id(): string {
        return (string) get_option(self::OPTIONS['instagram_user_id'], '');
    }

    public static function instagram_username(): string {
        return (string) get_option(self::OPTIONS['instagram_username'], '');
    }

    public static function access_token(): string {
        return self::decrypt((string) get_option(self::OPTIONS['access_token'], ''));
    }

    public static function page_access_token(): string {
        return self::decrypt((string) get_option(self::OPTIONS['page_access_token'], '')) ?: self::access_token();
    }

    private static function encrypt(string $value): string {
        if ($value === '' || !function_exists('openssl_encrypt')) return '';
        $key = hash('sha256', wp_salt('auth'), true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    private static function decrypt(string $value): string {
        if ($value === '' || !function_exists('openssl_decrypt')) return '';
        $raw = base64_decode($value, true);
        if (!is_string($raw) || strlen($raw) <= 16) return '';
        $key = hash('sha256', wp_salt('auth'), true);
        return (string) openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    }
}
