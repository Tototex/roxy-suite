<?php
namespace RoxySocial {
    final class Secrets {
        public static function decrypt(string $value): string { return $value; }
    }
}

namespace {
    define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
    function get_option($key, $default = false) { return $GLOBALS['hangar_options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) { $GLOBALS['hangar_options'][$key] = $value; return true; }
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    function wp_remote_post($url, $args) {
        $GLOBALS['hangar_requests'][] = [$url, $args];
        return $GLOBALS['hangar_response'];
    }
    function wp_remote_retrieve_response_code($response): int { return $response['code'] ?? 0; }
    function wp_remote_retrieve_cookies($response): array { return $response['cookies'] ?? []; }
    final class WP_Error {}

    $GLOBALS['hangar_options'] = [
        'roxy_social_hangar_user' => 'fixture-user',
        'roxy_social_hangar_pass' => 'fixture-password',
    ];
    $GLOBALS['hangar_requests'] = [];
    $GLOBALS['hangar_response'] = ['code'=>200, 'cookies'=>['session-cookie']];
    require dirname(__DIR__) . '/includes/modules/social-publisher/includes/class-roxy-social-hangar.php';
    $method = new \ReflectionMethod(\RoxySocial\Hangar::class, 'login_cookies');
    $method->setAccessible(true);
    $checks = 0;
    $check = static function (bool $condition, string $message) use (&$checks): void {
        if (!$condition) throw new \RuntimeException($message);
        $checks++;
    };

    $first = $method->invoke(null);
    $second = $method->invoke(null);
    $check($first === ['session-cookie'] && $second === $first && count($GLOBALS['hangar_requests']) === 1,
        'successful provider session is reused within one PHP request');
    $request = $GLOBALS['hangar_requests'][0][1];
    $check($request['body'] === ['user'=>'fixture-user', 'pass'=>'fixture-password'],
        'provider receives the currently configured credentials');
    $GLOBALS['hangar_options']['roxy_social_hangar_user'] = 'changed-user';
    $check($method->invoke(null) === ['session-cookie'] && count($GLOBALS['hangar_requests']) === 2,
        'credential change gets a distinct request-local session');
    $GLOBALS['hangar_response'] = ['code'=>503, 'cookies'=>[]];
    $GLOBALS['hangar_options']['roxy_social_hangar_pass'] = 'changed-password';
    $failure_one = $method->invoke(null);
    $failure_two = $method->invoke(null);
    $check($failure_one === [] && $failure_two === [] && count($GLOBALS['hangar_requests']) === 3,
        'failed login is reused as a request-local failure without repeated provider calls');
    echo "$checks Hangar request-local authentication checks passed\n";
}
