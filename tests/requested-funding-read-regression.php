<?php
// Isolated funding-read fail-closed checks. Run with: php tests/requested-funding-read-regression.php [repo-root]
namespace RoxyRS {
    // Simulate only the already-tested durable insert boundary for acknowledgement cases.
    function roxy_rs_repo_insert_backing(array $data) {
        $GLOBALS['wpdb']->insert_calls++;
        $GLOBALS['funding_fixture']['saved_backing'] = $data;
        if (!empty($GLOBALS['funding_fixture']['fail_after_insert'])) $GLOBALS['wpdb']->query_fails = true;
        return 501;
    }
    final class Settings {
        public static function funding_goal_cents(): int { return 5000; }
        public static function sponsor_amount_cents(): int { return 5000; }
        public static function sponsor_ticket_qty(): int { return 2; }
    }
    final class ConversionClaims {
        public static function lease(string $scope): \RoxyST\Issuance { return new \RoxyST\Issuance(); }
    }
}

namespace RoxyST {
    final class Issuance {
        public function assert_owner(): void {}
        public function release_lease(): void {}
    }
}

namespace {
    final class WP_Error {
        public function __construct(private string $code = '', private string $message = '') {}
        public function get_error_message(): string { return $this->message; }
    }
    set_error_handler(static function(int $severity,string $message,string $file,int $line): void {
        throw new ErrorException($message,0,$severity,$file,$line);
    });
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
    if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
    if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
    if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);

    final class FundingFixtureWpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public $rows = [];
        public bool $query_fails = false;
        public int $insert_calls = 0;
        public int $row_reads = 0;
        public function prepare(string $sql, ...$args): string {
            if (count($args) === 1 && is_array($args[0])) $args = $args[0];
            foreach ($args as $arg) $sql = preg_replace('/%[sd]/', is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'", $sql, 1);
            return $sql;
        }
        public function get_row(string $sql, $format = null) {
            $this->row_reads++;
            if (strpos($sql, 'FROM wp_roxy_requested_showing_backings') === false) throw new RuntimeException('Unexpected fake SQL: ' . $sql);
            if ($this->query_fails) { $this->last_error = 'simulated totals read failure'; return null; }
            return $this->rows[0] ?? null;
        }
        public function insert(string $table, array $data): int { $this->insert_calls++; return 1; }
        public function get_results(string $sql, $format = null) {
            $this->row_reads++;
            if (strpos($sql, 'FROM wp_roxy_requested_showing_backings') === false) throw new RuntimeException('Unexpected backing-list SQL.');
            if ($this->query_fails) { $this->last_error = 'simulated totals read failure'; return null; }
            return $this->rows;
        }
    }
    final class FundingFixtureRedirect extends RuntimeException {}

    $GLOBALS['wpdb'] = new FundingFixtureWpdb();
    $GLOBALS['funding_fixture'] = [
        'meta'=>[41=>['_roxy_rs_status'=>'active','_roxy_rs_funding_goal'=>5000,'_roxy_rs_deadline_at'=>'2000-01-01 00:00:00',
            '_roxy_rs_general_price'=>'12','_roxy_rs_discount_price'=>'8','_roxy_rs_matinee_price'=>'8','_roxy_rs_pricing_profile'=>'movie_evening']],
        'updates'=>[], 'mail'=>0, 'mail_result'=>true, 'posts'=>[], 'posts_error'=>false, 'options'=>[],
    ];
    function roxy_rs_module_fixture_unused(): void {}
    function current_time(string $type, bool $gmt = false): string { return '2026-10-06 12:00:00'; }
    function get_option(string $key, $default = false) { return $GLOBALS['funding_fixture']['options'][$key] ?? $default; }
    function update_option(string $key, $value, bool $autoload = true): bool {
        if (!empty($GLOBALS['funding_fixture']['telemetry_throws'])) throw new RuntimeException('fixture telemetry outage');
        if (!empty($GLOBALS['funding_fixture']['telemetry_false'])) return false;
        $GLOBALS['funding_fixture']['options'][$key] = $value; return true;
    }
    function get_post_meta(int $id, string $key, bool $single = false) { return $GLOBALS['funding_fixture']['meta'][$id][$key] ?? ''; }
    function wp_cache_delete($id, string $group): bool { return true; }
    function update_post_meta(int $id, string $key, $value): bool { $GLOBALS['funding_fixture']['updates'][] = [$id,$key,$value]; $GLOBALS['funding_fixture']['meta'][$id][$key] = $value; return true; }
    function get_posts(array $args = []): array {
        global $wpdb;
        $wpdb->last_error = '';
        if ($GLOBALS['funding_fixture']['posts_error']) { $wpdb->last_error = 'simulated request query failure'; return []; }
        return $GLOBALS['funding_fixture']['posts'];
    }
    function get_post(int $id) { return (object) ['ID'=>$id,'post_type'=>'roxy_req_showing','post_excerpt'=>'','post_author'=>0]; }
    function check_admin_referer(...$args): bool { return true; }
    function is_user_logged_in(): bool { return true; }
    function get_current_user_id(): int { return 7; }
    function add_query_arg($args, string $url): string { $GLOBALS['funding_fixture']['redirect']=$args; return $url . '?' . http_build_query($args); }
    function wp_json_encode($value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
    function get_transient(string $key) { return $GLOBALS['funding_fixture']['cache'][$key] ?? false; }
    function set_transient(string $key, $value, int $ttl): bool { $GLOBALS['funding_fixture']['cache'][$key]=$value; return true; }
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    function sanitize_email(string $value): string { return $value; }
    function wp_safe_redirect(string $url): void { throw new FundingFixtureRedirect($url); }
    function home_url(string $path = '/'): string { return 'https://fixture.invalid' . $path; }
    function get_the_title($id): string { return 'Fixture request'; }
    function admin_url(string $path = ''): string { return 'https://fixture.invalid/' . $path; }
    function wp_mail(...$args): bool { $GLOBALS['funding_fixture']['mail']++; return $GLOBALS['funding_fixture']['mail_result']; }
    function wp_timezone(): DateTimeZone { return new DateTimeZone('UTC'); }
    function current_datetime(): DateTimeImmutable { return new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('UTC')); }
    function has_post_thumbnail(int $id): bool { return false; }
    function get_permalink(int $id): string { return 'https://fixture.invalid/request/' . $id; }
    function esc_url(string $value): string { return $value; }
    function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
    function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
    function wc_price($amount): string { return '$' . number_format((float) $amount, 2); }
    function get_woocommerce_currency(): string { return 'USD'; }
    function wc_tax_enabled(): bool { return false; }
    function wp_strip_all_tags(string $value): string { return strip_tags($value); }
    function wp_kses_post(string $value): string { return $value; }
    function number_format_i18n($value): string { return number_format((int) $value); }
    function wp_unslash($value) { return $value; }

    $root = $argv[1] ?? dirname(__DIR__);
    foreach ([
        $root . '/includes/modules/requested-showings/includes/schema.php',
        $root . '/includes/modules/requested-showings/includes/repository.php',
        $root . '/includes/modules/requested-showings/includes/class-roxy-rs-cpt.php',
        $root . '/includes/modules/requested-showings/includes/class-roxy-rs-agreement.php',
        $root . '/includes/modules/requested-showings/includes/class-roxy-rs-frontend.php',
        $argv[2] ?? $root . '/includes/modules/requested-showings/includes/class-roxy-rs-conversion.php',
    ] as $file) {
        if (!is_file($file)) throw new RuntimeException('Fixture source missing: ' . $file);
        require_once $file;
    }

    $checks = 0;
    $check = static function (bool $ok, string $label) use (&$checks): void {
        if (!$ok) throw new RuntimeException('FAIL: ' . $label);
        $checks++;
        echo "PASS: {$label}\n";
    };
    $totals = static function () { return roxy_rs_repo_backing_totals(41); };
    $wpdb = $GLOBALS['wpdb'];

    $empty = $totals();
    $check($empty === ['support_qty'=>0,'subscriber_qty'=>0,'charge_total'=>0,'sponsor_amount'=>0,'sponsor_ticket_qty'=>0,'has_sponsor'=>false,'refund_reviews'=>[]], 'valid empty backing rows normalize to zero funding with an empty review list');
    $row = ['id'=>1,'request_id'=>41,'status'=>'pending','general_qty'=>'3','discount_qty'=>'0','support_qty'=>'3','subscriber_qty'=>'2','charge_total'=>'3600','sponsor_amount'=>'0','sponsor_ticket_qty'=>'0','woo_order_id'=>null,'agreement_json'=>null,'approved_showing_id'=>null];
    $wpdb->rows = [$row];
    $valid = $totals();
    $check($valid['charge_total'] === 3600 && $valid['support_qty'] === 3 && $valid['subscriber_qty'] === 2, 'valid string-encoded backing rows are summed');
    $wpdb->rows = [['id'=>2,'request_id'=>41,'status'=>'threshold_met','general_qty'=>'0','discount_qty'=>'0','support_qty'=>'0','subscriber_qty'=>'0','charge_total'=>'5000','sponsor_amount'=>'5000','sponsor_ticket_qty'=>'2','woo_order_id'=>null,'agreement_json'=>null,'approved_showing_id'=>null]];
    $check($totals()['has_sponsor'] === true && $totals()['sponsor_amount'] === 5000, 'positive backing sponsorship is detected');

    foreach ([
        null,
        [array_merge($row,['support_qty'=>'0'])],
        [array_merge($row,['general_qty'=>'bad'])],
        [array_merge($row,['general_qty'=>'-1'])],
        [array_merge($row,['general_qty'=>'999999999999999999999999'])],
        [array_merge($row,['charge_total'=>'1.5'])],
        [array_merge($row,['support_qty'=>'2'])],
        [array_merge($row,['sponsor_amount'=>'-1'])],
    ] as $bad_rows) {
        $wpdb->rows = $bad_rows;
        $threw = false;
        try { $totals(); } catch (RuntimeException $error) { $threw = true; }
        $check($threw, 'unavailable, malformed, negative, overflowing, or internally inconsistent backing rows throw');
    }
    $wpdb->rows = [];
    $wpdb->query_fails = true;
    $threw = false;
    try { $totals(); } catch (RuntimeException $error) { $threw = true; }
    $check($threw, 'SQL error throws instead of becoming empty funding');
    $wpdb->query_fails = false;

    $wpdb->query_fails = true;
    $card = \RoxyRS\Frontend::render_request_card(41, true);
    $check(strpos($card, 'Funding temporarily unavailable') !== false && strpos($card, '<form') === false, 'public card reports unknown funding and omits backing form');
    $single = new ReflectionMethod(\RoxyRS\Frontend::class, 'render_single_request');
    if (PHP_VERSION_ID < 80100) $single->setAccessible(true);
    $wpdb->row_reads = 0;
    $single_html = $single->invoke(null, 41);
    $check($wpdb->row_reads === 1 && strpos($single_html, 'Funding temporarily unavailable') !== false, 'single-request rendering performs one checked totals read');
    ob_start();
    \RoxyRS\CPT::render_admin_column('progress', 41);
    $admin_progress = ob_get_clean();
    $check(strpos($admin_progress, 'Funding temporarily unavailable') !== false && strpos($admin_progress, '$0.00') === false, 'admin progress does not display unknown funding as zero');

    $_POST = ['request_id'=>41,'general_qty'=>'1','discount_qty'=>'0','subscriber_qty'=>'0'];
    $GLOBALS['funding_fixture']['meta'][41]['_roxy_rs_deadline_at'] = '';
    $wpdb->insert_calls = 0;
    $wpdb->row_reads = 0;
    $redirected = false;
    try { \RoxyRS\Frontend::handle_commit_backing(); } catch (FundingFixtureRedirect $redirect) { $redirected = true; }
    $check($redirected && $wpdb->insert_calls === 0 && $wpdb->row_reads === 1
        && strpos($GLOBALS['funding_fixture']['redirect']['message'] ?? '', 'Your backing was not saved') !== false,
        'pre-insert totals read failure stops backing submission before insert');
    $_POST = [];

    $GLOBALS['funding_fixture']['updates'] = [];
    $GLOBALS['funding_fixture']['mail'] = 0;
    $threw = false;
    try { \RoxyRS\Conversion::maybe_mark_request_ready(41); } catch (RuntimeException $error) { $threw = true; }
    $check($threw && $GLOBALS['funding_fixture']['updates'] === [] && $GLOBALS['funding_fixture']['mail'] === 0, 'read failure prevents readiness writes and email');

    $GLOBALS['funding_fixture']['posts_error'] = true;
    $threw = false;
    try { \RoxyRS\Conversion::run_daily_review(); } catch (RuntimeException $error) { $threw = true; }
    $check($threw && $GLOBALS['funding_fixture']['updates'] === [] && $GLOBALS['funding_fixture']['mail'] === 0, 'daily review aborts on request-list SQL error');
    $review = $GLOBALS['funding_fixture']['options']['roxy_rs_daily_review_last_result'] ?? [];
    $check(($review['status'] ?? '') === 'failed' && ($review['error'] ?? '') !== '', 'failed daily review records a failure result');
    $GLOBALS['funding_fixture']['posts_error'] = false;
    $GLOBALS['funding_fixture']['posts'] = [(object) ['ID'=>41]];
    $threw = false;
    try { \RoxyRS\Conversion::run_daily_review(); } catch (RuntimeException $error) { $threw = true; }
    $check($threw && ($GLOBALS['funding_fixture']['meta'][41]['_roxy_rs_status'] ?? '') === 'active'
        && $GLOBALS['funding_fixture']['updates'] === [] && $GLOBALS['funding_fixture']['mail'] === 0, 'unknown expired-request funding never marks the request failed');

    $wpdb->query_fails = false;
    $GLOBALS['funding_fixture']['posts'] = [];
    \RoxyRS\Conversion::run_daily_review();
    $check($GLOBALS['funding_fixture']['updates'] === [] && $GLOBALS['funding_fixture']['mail'] === 0, 'valid empty daily review remains a successful no-op');
    $review = $GLOBALS['funding_fixture']['options']['roxy_rs_daily_review_last_result'] ?? [];
    $check(($review['version'] ?? 0) === 1 && ($review['status'] ?? '') === 'completed'
        && ($review['run_id'] ?? '') !== '' && ($review['started_at'] ?? '') !== '' && ($review['completed_at'] ?? '') !== '',
        'successful daily review records its reported completion result');

    $GLOBALS['funding_fixture']['posts'] = [(object) ['ID'=>41]];
    $GLOBALS['funding_fixture']['meta'][41]['_roxy_rs_funding_goal'] = 'malformed';
    $GLOBALS['funding_fixture']['meta'][41]['_roxy_rs_sponsor_amount'] = '5000';
    $GLOBALS['funding_fixture']['updates'] = [];
    $threw = false;
    try { \RoxyRS\Conversion::run_daily_review(); } catch (RuntimeException $error) { $threw = strpos($error->getMessage(), 'currency needs review') !== false; }
    $review = $GLOBALS['funding_fixture']['options']['roxy_rs_daily_review_last_result'] ?? [];
    $check($threw && ($review['status'] ?? '') === 'failed'
        && ($GLOBALS['funding_fixture']['meta'][41]['_roxy_rs_status'] ?? '') === 'active'
        && $GLOBALS['funding_fixture']['updates'] === [],
        'malformed saved currency records a failed review without closing or completing the request');
    $wpdb->query_fails = false;
    $rendered_bad_amount = \RoxyRS\Frontend::render_request_card(41, true);
    $check(strpos($rendered_bad_amount, 'currency review') !== false && strpos($rendered_bad_amount, '<form') === false,
        'public rendering handles malformed saved currency without a fatal or backing form');
    $wpdb->insert_calls = 0;
    $_POST = ['request_id'=>41, 'general_qty'=>'1', 'discount_qty'=>'0', 'subscriber_qty'=>'0'];
    $redirected = false;
    try { \RoxyRS\Frontend::handle_commit_backing(); } catch (FundingFixtureRedirect $redirect) { $redirected = true; }
    $check($redirected && $wpdb->insert_calls === 0
        && strpos($GLOBALS['funding_fixture']['redirect']['message'] ?? '', 'currency values need review') !== false,
        'malformed saved currency blocks backing with a controlled notice before insert');
    $_POST = [];
    $GLOBALS['funding_fixture']['meta'][41]['_roxy_rs_funding_goal'] = 5000;
    $GLOBALS['funding_fixture']['meta'][41]['_roxy_rs_sponsor_amount'] = 5000;
    $GLOBALS['funding_fixture']['posts'] = [];

    foreach (['telemetry_throws', 'telemetry_false'] as $fault) {
        $GLOBALS['funding_fixture'][$fault] = true;
        \RoxyRS\Conversion::run_daily_review();
        $check($GLOBALS['funding_fixture']['updates'] === [], 'logging failure does not interrupt empty review: ' . $fault);
        $GLOBALS['funding_fixture']['posts_error'] = true;
        $message = '';
        try { \RoxyRS\Conversion::run_daily_review(); } catch (RuntimeException $error) { $message = $error->getMessage(); }
        $check(strpos($message, 'could not verify its request list') !== false, 'logging failure preserves original review error: ' . $fault);
        $GLOBALS['funding_fixture']['posts_error'] = false;
        $GLOBALS['funding_fixture'][$fault] = false;
    }

    $_POST = ['request_id'=>41,'general_qty'=>'0','discount_qty'=>'0','subscriber_qty'=>'1'];
    $GLOBALS['funding_fixture']['cache'] = [];
    $GLOBALS['funding_fixture']['fail_after_insert'] = true;
    $GLOBALS['funding_fixture']['meta'][41]['_roxy_rs_deadline_at'] = '';
    $wpdb->insert_calls = 0;
    $redirected = false;
    try { \RoxyRS\Frontend::handle_commit_backing(); } catch (FundingFixtureRedirect $redirect) { $redirected = true; }
    $check($redirected && $wpdb->insert_calls === 1 && ($GLOBALS['funding_fixture']['redirect']['roxy_rs_notice'] ?? '') === 'success'
        && strpos($GLOBALS['funding_fixture']['redirect']['message'] ?? '', 'do not submit it again') !== false,
        'mock-persisted backing remains acknowledged as saved after readiness read failure (insert=' . $wpdb->insert_calls
        . ', notice=' . ($GLOBALS['funding_fixture']['redirect']['roxy_rs_notice'] ?? 'missing')
        . ', message=' . ($GLOBALS['funding_fixture']['redirect']['message'] ?? 'missing') . ')');
    $check(in_array(501, $GLOBALS['funding_fixture']['cache'], true) && ($GLOBALS['funding_fixture']['saved_backing']['subscriber_qty'] ?? 0) === 1,
        'post-insert failure retains replay guard and original backing payload');

    $GLOBALS['funding_fixture']['meta'][41][\RoxyRS\CPT::META_STATUS] = 'active';
    $GLOBALS['funding_fixture']['meta'][41][\RoxyRS\CPT::META_TARGET_NOTIFIED] = '';
    $GLOBALS['funding_fixture']['mail'] = 0;
    $wpdb->query_fails = false;
    $wpdb->last_error = '';
    $GLOBALS['wpdb']->rows = [['id'=>2,'request_id'=>41,'status'=>'pending','general_qty'=>'0','discount_qty'=>'0','support_qty'=>'0','subscriber_qty'=>'0','charge_total'=>'5000','sponsor_amount'=>'5000','sponsor_ticket_qty'=>'2','woo_order_id'=>null,'agreement_json'=>null,'approved_showing_id'=>null]];
    $GLOBALS['funding_fixture']['mail_result'] = false;
    \RoxyRS\Conversion::maybe_mark_request_ready(41);
    $check(($GLOBALS['funding_fixture']['meta'][41][\RoxyRS\CPT::META_STATUS] ?? '') === 'threshold_met'
        && empty($GLOBALS['funding_fixture']['meta'][41][\RoxyRS\CPT::META_TARGET_NOTIFIED])
        && $GLOBALS['funding_fixture']['mail'] === 1,
        'failed funding-goal email does not persist the sent marker');
    \RoxyRS\Conversion::maybe_mark_request_ready(41);
    $check(empty($GLOBALS['funding_fixture']['meta'][41][\RoxyRS\CPT::META_TARGET_NOTIFIED]) && $GLOBALS['funding_fixture']['mail'] === 2,
        'later readiness pass retries an unconfirmed funding-goal email');
    $GLOBALS['funding_fixture']['mail_result'] = true;
    \RoxyRS\Conversion::maybe_mark_request_ready(41);
    $check(!empty($GLOBALS['funding_fixture']['meta'][41][\RoxyRS\CPT::META_TARGET_NOTIFIED]) && $GLOBALS['funding_fixture']['mail'] === 3,
        'successful funding-goal email records its sent marker');
    \RoxyRS\Conversion::maybe_mark_request_ready(41);
    $check($GLOBALS['funding_fixture']['mail'] === 3, 'confirmed funding-goal notification is not sent twice');

    $_POST = [];
    $GLOBALS['funding_fixture']['fail_after_insert'] = false;
    $wpdb->query_fails = false;
    $wpdb->rows = [];
    $GLOBALS['funding_fixture']['posts'] = [(object)['ID'=>41]];
    $GLOBALS['funding_fixture']['meta'][41]['_roxy_rs_deadline_at'] = '2000-01-01 00:00:00';
    \RoxyRS\Conversion::run_daily_review();
    $check(($GLOBALS['funding_fixture']['meta'][41]['_roxy_rs_status'] ?? '') === 'failed', 'verified expired underfunded request retains ordinary closure behavior');

    $review = $GLOBALS['funding_fixture']['options']['roxy_rs_daily_review_last_result'] ?? [];
    $check(($review['status'] ?? '') === 'completed', 'ordinary request transitions still finish with completed telemetry');

    echo "Passed {$checks} requested funding-read regressions.\n";
}
