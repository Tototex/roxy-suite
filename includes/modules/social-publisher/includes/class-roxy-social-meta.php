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
        $prepared=[];
        try {
            foreach (['app_secret','access_token','page_access_token'] as $key) {
                $value=(string)wp_unslash($_POST['meta_'.$key]??'');
                if ($value!=='') $prepared[$key]=self::encrypt($value);
            }
        } catch (\Throwable $error) { wp_die('Credential encryption failed. Existing connection settings were not replaced.'); }
        update_option(self::OPTIONS['app_id'], sanitize_text_field((string) ($_POST['meta_app_id'] ?? '')), false);
        update_option(self::OPTIONS['page_id'], sanitize_text_field((string) ($_POST['meta_page_id'] ?? '')), false);
        update_option(self::OPTIONS['page_name'], sanitize_text_field((string) ($_POST['meta_page_name'] ?? '')), false);
        update_option(self::OPTIONS['instagram_user_id'], sanitize_text_field((string) ($_POST['meta_instagram_user_id'] ?? '')), false);
        update_option(self::OPTIONS['instagram_username'], sanitize_text_field((string) ($_POST['meta_instagram_username'] ?? '')), false);
        foreach ($prepared as $key=>$encrypted) update_option(self::OPTIONS[$key],$encrypted,false);
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&tab=meta&saved=1'));
        exit;
    }

    public static function configured(): bool {
        return (string) get_option(self::OPTIONS['app_id'], '') !== ''
            && (string) get_option(self::OPTIONS['page_id'], '') !== ''
            && self::access_token() !== '';
    }

    public static function credentials_unreadable(): bool {
        foreach (['app_secret','access_token','page_access_token'] as $key) {
            $value=(string)get_option(self::OPTIONS[$key],'');
            if ($value!=='' && self::decrypt($value)==='') return true;
        }
        return false;
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
            'scope' => 'pages_show_list,pages_read_engagement,pages_manage_posts,business_management,instagram_basic,instagram_content_publish',
        ], 'https://www.facebook.com/dialog/oauth');
    }

    public static function handle_callback(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        if (!empty($_GET['error'])) { wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&tab=meta&meta_error=cancelled')); exit; }
        $state = sanitize_text_field((string) ($_GET['state'] ?? ''));
        if (!$state || !wp_verify_nonce($state, 'roxy_social_meta_connect')) wp_die('Meta authorization could not be verified.');
        $code = sanitize_text_field((string) ($_GET['code'] ?? ''));
        if ($code === '' || !self::app_secret_saved()) wp_die('Meta authorization is missing required information.');
        $app_secret=self::decrypt((string)get_option(self::OPTIONS['app_secret'],''));
        if ($app_secret==='') wp_die('The saved Meta app secret could not be read. Existing credentials were not changed.');
        $response = wp_remote_post('https://graph.facebook.com/oauth/access_token', [
            'timeout' => 30,
            'body' => [
                'client_id' => self::app_id(),
                'client_secret' => $app_secret,
                'redirect_uri' => self::redirect_url(),
                'code' => $code,
            ],
        ]);
        $data = !is_wp_error($response) ? json_decode((string) wp_remote_retrieve_body($response), true) : null;
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ($token === '') { wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&tab=meta&meta_error=token')); exit; }
        try { $encrypted=self::encrypt($token); } catch (\Throwable $error) { wp_die('Credential encryption failed. Existing Meta token was not replaced.'); }
        update_option(self::OPTIONS['access_token'], $encrypted, false);
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
        try {$selected=self::connected_page($token,self::page_id());}catch(\RuntimeException $error){self::redirect_with_verify_status(in_array($error->getMessage(),['select_page','page_missing'],true)?$error->getMessage():'failed');}
        $instagram = is_array($selected['instagram_business_account'] ?? null) ? $selected['instagram_business_account'] : [];
        if (self::instagram_user_id()!=='' && (string)($instagram['id']??'')!==self::instagram_user_id()) self::redirect_with_verify_status('instagram_mismatch');
        if (empty($selected['access_token'])) self::redirect_with_verify_status('failed');
        $encrypted_page_token='';
        if (!empty($selected['access_token'])) {
            try { $encrypted_page_token=self::encrypt((string)$selected['access_token']); } catch (\Throwable $error) { self::redirect_with_verify_status('failed'); }
        }
        update_option(self::OPTIONS['page_id'], sanitize_text_field((string) ($selected['id'] ?? '')), false);
        update_option(self::OPTIONS['page_name'], sanitize_text_field((string) ($selected['name'] ?? '')), false);
        if ($encrypted_page_token!=='') update_option(self::OPTIONS['page_access_token'], $encrypted_page_token, false);
        update_option(self::OPTIONS['instagram_user_id'], sanitize_text_field((string) ($instagram['id'] ?? '')), false);
        update_option(self::OPTIONS['instagram_username'], sanitize_text_field((string) ($instagram['username'] ?? '')), false);
        self::redirect_with_verify_status(!empty($instagram['id']) ? 'success' : 'no_instagram');
    }

    private static function connected_page(string $token,string $configured_id): array {
        if ($configured_id==='' || !preg_match('/^\d+$/',$configured_id)) throw new \RuntimeException('select_page');
        $cursor='';$seen=[];$started=microtime(true);
        for($page_number=0;$page_number<10;$page_number++) {
            $remaining=30-(microtime(true)-$started);
            if($remaining<=0)throw new \RuntimeException('failed');
            $query=['fields'=>'id,name,access_token,instagram_business_account{id,username}','limit'=>100,'access_token'=>$token];
            if($cursor!=='')$query['after']=$cursor;
            // Reconstruct the trusted endpoint; never follow a provider-supplied URL.
            $response=wp_remote_get(add_query_arg($query,'https://graph.facebook.com/me/accounts'),['timeout'=>min(12,max(1,(int)ceil($remaining))),'redirection'=>0]);
            if(is_wp_error($response))throw new \RuntimeException('failed');
            $status=(int)wp_remote_retrieve_response_code($response);
            $data=json_decode((string)wp_remote_retrieve_body($response),true);
            if($status<200||$status>=300||!is_array($data)||!isset($data['data'])||!is_array($data['data'])||isset($data['error']))throw new \RuntimeException('failed');
            foreach($data['data'] as $candidate)if(is_array($candidate)&&(string)($candidate['id']??'')===$configured_id)return $candidate;
            if(empty($data['paging']['next']))throw new \RuntimeException('page_missing');
            $cursor=(string)($data['paging']['cursors']['after']??'');
            if($cursor===''||strlen($cursor)>4096||isset($seen[$cursor]))throw new \RuntimeException('failed');
            $seen[$cursor]=true;
        }
        throw new \RuntimeException('failed');
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
        return Secrets::encrypt($value);
    }

    private static function decrypt(string $value): string {
        return Secrets::decrypt($value);
    }
}
