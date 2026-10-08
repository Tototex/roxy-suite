<?php
namespace RoxyRS {
    class TestRedirect extends \RuntimeException {}
}
namespace RoxyST {
    class CPT { public const POST_TYPE = 'roxy_showing'; }
    class Settings {
        public static function get_price($key, $default = 0) { return $default; }
    }
}
namespace {
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['money_meta'] = [];
$GLOBALS['money_writes'] = [];
$GLOBALS['money_options'] = [];
$GLOBALS['money_redirects'] = [];
$GLOBALS['money_insert_calls'] = 0;
function check_money($ok, $label) { if (!$ok) throw new \RuntimeException($label); echo "PASS: $label\n"; }
function get_option($key, $default = false) { return $GLOBALS['money_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['money_options'][$key] = $value; return true; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['money_meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['money_writes'][] = [$id, $key, $value]; $GLOBALS['money_meta'][$id][$key] = $value; return true; }
function set_transient($key, $value, $expiration) { $GLOBALS['money_options'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['money_options'][$key] ?? false; }
function delete_transient($key) { unset($GLOBALS['money_options'][$key]); }
function wp_verify_nonce(...$args) { return true; }
function current_user_can(...$args) { return true; }
function get_current_user_id() { return 9; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return is_scalar($value) ? (string) $value : ''; }
function sanitize_key($value) { return (string) $value; }
function esc_url_raw($value) { return (string) $value; }
function is_wp_error($value) { return $value instanceof \WP_Error; }
class WP_Error {
    private $message;
    function __construct($code, $message) { $this->message = $message; }
    function get_error_message() { return $this->message; }
}
function roxy_suite_user_can_access_admin() { return true; }
function check_admin_referer(...$args) { return true; }
function wp_safe_redirect($url) { $GLOBALS['money_redirects'][] = $url; throw new \RoxyRS\TestRedirect(); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function admin_url($path = '') { return 'https://fixture.invalid/' . $path; }
function wp_nonce_field(...$args) {}
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_url($value) { return (string) $value; }
function submit_button(...$args) {}
function wp_insert_post($args, $error = false) { $GLOBALS['money_insert_calls']++; return 88; }
function is_user_logged_in() { return true; }
function sanitize_email($value) { return (string) $value; }
function sanitize_textarea_field($value) { return (string) $value; }
function wp_mail(...$args) { return true; }
function wp_get_referer() { return 'https://fixture.invalid/edit'; }
function home_url($path = '/') { return 'https://fixture.invalid' . $path; }
function wp_timezone() { return new \DateTimeZone('America/Los_Angeles'); }
function current_datetime() { return new \DateTimeImmutable('2040-01-01 12:00:00', wp_timezone()); }
function roxy_eb_get_settings() { return ['time_increment_minutes'=>15, 'open_time'=>'17:00', 'close_time'=>'19:00']; }
function roxy_eb_parse_hhmm($value) { if (!preg_match('/^(\d{2}):(\d{2})$/', $value, $m)) return null; return [(int)$m[1], (int)$m[2]]; }
function roxy_eb_calc_times($start, $unused) { return ['show_start'=>$start, 'reserved_start'=>$start->modify('-2 hours'), 'reserved_end'=>$start->modify('+2 hours')]; }
function roxy_eb_time_within_operating_hours(...$args) { return true; }
function roxy_eb_is_slot_available(...$args) { return true; }
function get_posts($args) { return []; }
function get_the_title($id) { return 'Fixture'; }
$root = $argv[1] ?? dirname(__DIR__);
require $root . '/includes/modules/requested-showings/includes/class-roxy-rs-settings.php';
require $root . '/includes/modules/requested-showings/includes/class-roxy-rs-cpt.php';
require $root . '/includes/modules/requested-showings/includes/class-roxy-rs-frontend.php';

foreach (['5'=>500, '1.00'=>100, '9.99'=>999, '5.0'=>500, '5.25'=>525] as $input=>$expected) {
    check_money(\RoxyRS\CPT::parse_currency_input($input) === $expected, 'strict decimal parser returns exact cents');
}
foreach (['1.001', '1e2', '-1', '1,000', 'abc', '21474836.48', ['5']] as $invalid) {
    check_money(is_wp_error(\RoxyRS\CPT::parse_currency_input($invalid)), 'invalid or overflowing currency input is rejected');
}
check_money(\RoxyRS\CPT::parse_currency_input('', 725) === 725, 'blank amount uses explicit caller default');

$settings_key = \RoxyRS\Settings::OPTION_KEY;
check_money(\RoxyRS\Settings::funding_goal_cents() === 30000 && \RoxyRS\Settings::sponsor_amount_cents() === 30000,
    'missing Settings option retains the documented defaults');
$GLOBALS['money_options'][$settings_key] = ['sponsor_ticket_qty'=>4];
check_money(\RoxyRS\Settings::funding_goal_cents() === 30000 && \RoxyRS\Settings::sponsor_amount_cents() === 30000,
    'omitted currency keys retain defaults when unrelated Settings fields exist');
$GLOBALS['money_options'][\RoxyRS\Settings::OPTION_KEY] = ['funding_goal_cents'=>30000, 'sponsor_amount_cents'=>30000];
$GLOBALS['money_meta'][10] = [
    \RoxyRS\CPT::META_FUNDING_GOAL => '500',
    \RoxyRS\CPT::META_SPONSOR_AMOUNT => '800',
];
check_money(\RoxyRS\CPT::funding_goal_cents(10) === 500 && \RoxyRS\CPT::sponsor_amount_cents(10) === 800, 'small unmarked values remain cents without magnitude-based conversion');
foreach (['100'=>100, '999'=>999] as $raw=>$expected) {
    $GLOBALS['money_meta'][10][\RoxyRS\CPT::META_FUNDING_GOAL] = $raw;
    check_money(\RoxyRS\CPT::funding_goal_cents(10) === $expected, 'small persisted cents are preserved exactly');
}
$GLOBALS['money_options'][$settings_key] = ['funding_goal_cents'=>'999999garbage'];
check_money(is_wp_error(\RoxyRS\Settings::funding_goal_cents()), 'malformed explicit Settings goal returns a controlled error instead of clamping');
check_money(is_wp_error(\RoxyRS\Settings::sponsor_amount_cents()) && \RoxyRS\Settings::sponsor_ticket_qty() === 2,
    'sponsor default is withheld for corrupt currency while non-currency getters remain safe');
$GLOBALS['money_options'][$settings_key] = ['funding_goal_cents'=>999999];
check_money(is_wp_error(\RoxyRS\Settings::sponsor_amount_cents()), 'missing sponsor amount below a valid high goal is not synthesized upward');
$GLOBALS['money_options'][$settings_key] = ['funding_goal_cents'=>999999, 'sponsor_amount_cents'=>50000];
check_money(is_wp_error(\RoxyRS\Settings::sponsor_amount_cents()), 'explicit sponsor below the saved goal is rejected without clamping');
$GLOBALS['money_options'][$settings_key] = ['funding_goal_cents'=>30000, 'sponsor_amount_cents'=>'1e6'];
check_money(is_wp_error(\RoxyRS\Settings::sponsor_amount_cents()), 'non-decimal persisted sponsor cents are rejected');
$GLOBALS['money_options'][$settings_key] = ['funding_goal_cents'=>'bad', 'sponsor_amount_cents'=>'bad'];
$GLOBALS['money_meta'][10][\RoxyRS\CPT::META_FUNDING_GOAL] = '500';
$GLOBALS['money_meta'][10][\RoxyRS\CPT::META_SPONSOR_AMOUNT] = '800';
check_money(\RoxyRS\CPT::funding_goal_cents(10) === 500 && \RoxyRS\CPT::sponsor_amount_cents(10) === 800,
    'valid explicit per-request amounts do not depend on corrupt global defaults');
$GLOBALS['money_options'][$settings_key] = ['funding_goal_cents'=>30000, 'sponsor_amount_cents'=>30000];
$GLOBALS['money_meta'][10][\RoxyRS\CPT::META_FUNDING_GOAL] = '500';
$GLOBALS['money_meta'][10][\RoxyRS\CPT::META_SPONSOR_AMOUNT] = '800';
$GLOBALS['money_meta'][10][\RoxyRS\CPT::META_GENERAL_PRICE] = '0.10';
$GLOBALS['money_meta'][10][\RoxyRS\CPT::META_DISCOUNT_PRICE] = '8.25';
$GLOBALS['money_meta'][10][\RoxyRS\CPT::META_MATINEE_PRICE] = '6';
check_money(\RoxyRS\Frontend::ticket_prices(10) === ['general'=>10, 'discount'=>825, 'matinee'=>600], 'ticket decimals convert exactly to cents without floats');
$GLOBALS['money_meta'][10][\RoxyRS\CPT::META_GENERAL_PRICE] = 'invalid';
check_money(is_wp_error(\RoxyRS\Frontend::ticket_prices(10)), 'invalid saved ticket price fails closed instead of becoming zero');

$GLOBALS['money_meta'][11] = [\RoxyRS\CPT::META_STATUS=>'pending_review'];
$GLOBALS['money_writes'] = [];
$_POST = [
    'roxy_rs_nonce'=>'fixture', 'roxy_rs_status'=>'pending_review',
    'roxy_rs_funding_goal'=>'5.00', 'roxy_rs_sponsor_amount'=>'10.00',
    'roxy_rs_general_price'=>'12.34', 'roxy_rs_discount_price'=>'bad', 'roxy_rs_matinee_price'=>'8.00',
];
\RoxyRS\CPT::save(11, (object)['post_status'=>'draft']);
check_money($GLOBALS['money_writes'] === [] && get_transient('roxy_rs_invalid_currency_9'), 'invalid CPT currency aborts before every metadata write and sets a controlled notice');

$invalid_cpt_cases = [
    ['0.00', '10.00'],
    ['0.99', '10.00'],
    ['5.00', '0.99'],
    ['5.00', '4.99'],
];
foreach ($invalid_cpt_cases as [$goal, $sponsor]) {
    $GLOBALS['money_writes'] = [];
    $_POST['roxy_rs_general_price'] = '12.00';
    $_POST['roxy_rs_discount_price'] = '8.00';
    $_POST['roxy_rs_matinee_price'] = '6.00';
    $_POST['roxy_rs_funding_goal'] = $goal;
    $_POST['roxy_rs_sponsor_amount'] = $sponsor;
    \RoxyRS\CPT::save(11, (object)['post_status'=>'draft']);
    check_money($GLOBALS['money_writes'] === [], 'zero, sub-dollar goal, or sponsor below goal is rejected before metadata writes');
}

$GLOBALS['money_writes'] = [];
$_POST['roxy_rs_funding_goal'] = '5.00';
$_POST['roxy_rs_sponsor_amount'] = '10.00';
\RoxyRS\CPT::save(11, (object)['post_status'=>'draft']);
check_money(($GLOBALS['money_meta'][11][\RoxyRS\CPT::META_FUNDING_GOAL] ?? null) === 500
    && ($GLOBALS['money_meta'][11][\RoxyRS\CPT::META_SPONSOR_AMOUNT] ?? null) === 1000
    && ($GLOBALS['money_meta'][11][\RoxyRS\CPT::META_FUNDING_UNIT_VERSION] ?? null) === 'cents_v1'
    && ($GLOBALS['money_meta'][11][\RoxyRS\CPT::META_GENERAL_PRICE] ?? null) === '12.00', 'valid CPT save stores funding amounts as marked cents');

$GLOBALS['money_options'][$settings_key] = ['funding_goal_cents'=>'bad', 'sponsor_amount_cents'=>'bad'];
$GLOBALS['money_meta'][14] = [
    \RoxyRS\CPT::META_STATUS=>'pending_review',
    \RoxyRS\CPT::META_FUNDING_GOAL=>'12500',
    \RoxyRS\CPT::META_SPONSOR_AMOUNT=>'18000',
];
$_POST = ['roxy_rs_nonce'=>'fixture', 'roxy_rs_status'=>'pending_review', 'roxy_rs_funding_goal'=>'', 'roxy_rs_sponsor_amount'=>''];
$GLOBALS['money_writes'] = [];
\RoxyRS\CPT::save(14, (object)['post_status'=>'draft']);
check_money((int) ($GLOBALS['money_meta'][14][\RoxyRS\CPT::META_FUNDING_GOAL] ?? 0) === 12500
    && (int) ($GLOBALS['money_meta'][14][\RoxyRS\CPT::META_SPONSOR_AMOUNT] ?? 0) === 18000,
    'CPT admin save preserves valid explicit request amounts despite corrupt global defaults');
$GLOBALS['money_options'][$settings_key] = ['funding_goal_cents'=>30000, 'sponsor_amount_cents'=>30000];

$GLOBALS['money_meta'][12] = [
    \RoxyRS\CPT::META_FUNDING_GOAL=>'garbage',
    \RoxyRS\CPT::META_SPONSOR_AMOUNT=>'10000',
];
check_money(is_wp_error(\RoxyRS\CPT::funding_goal_cents(12)), 'malformed persisted funding blocks use instead of substituting a default');
$GLOBALS['money_meta'][12][\RoxyRS\CPT::META_FUNDING_GOAL] = '10000';
$GLOBALS['money_meta'][12][\RoxyRS\CPT::META_SPONSOR_AMOUNT] = '20000';
$GLOBALS['money_meta'][12][\RoxyRS\CPT::META_FUNDING_UNIT_VERSION] = 'future_unit';
check_money(is_wp_error(\RoxyRS\CPT::sponsor_amount_cents(12)), 'unsupported explicit unit marker blocks funding use');
$GLOBALS['money_meta'][12][\RoxyRS\CPT::META_FUNDING_UNIT_VERSION] = 'cents_v1';
$GLOBALS['money_meta'][12][\RoxyRS\CPT::META_SPONSOR_AMOUNT] = '999';
check_money(is_wp_error(\RoxyRS\CPT::sponsor_amount_cents(12)), 'sponsor below one dollar or below goal is blocked');
$GLOBALS['money_meta'][12][\RoxyRS\CPT::META_SPONSOR_AMOUNT] = '999999999999999999999';
check_money(is_wp_error(\RoxyRS\CPT::sponsor_amount_cents(12)), 'overflow persisted amount is blocked rather than replaced with a default');
$GLOBALS['money_meta'][13] = [];
check_money(\RoxyRS\CPT::funding_goal_cents(13) === 30000, 'missing legacy goal may use the configured default');

$_POST = [
    'roxy_rs_nonce'=>'fixture', 'roxy_rs_status'=>'pending_review',
    'roxy_rs_funding_goal'=>'100.00', 'roxy_rs_sponsor_amount'=>'150.00',
    'roxy_rs_general_price'=>'12.00', 'roxy_rs_discount_price'=>'8.00', 'roxy_rs_matinee_price'=>'6.00',
];
$GLOBALS['money_options'][$settings_key] = ['funding_goal_cents'=>'broken', 'sponsor_amount_cents'=>30000];
$GLOBALS['money_writes'] = [];
\RoxyRS\CPT::save(12, (object)['post_status'=>'draft']);
check_money(($GLOBALS['money_meta'][12][\RoxyRS\CPT::META_FUNDING_GOAL] ?? null) === 10000
    && ($GLOBALS['money_meta'][12][\RoxyRS\CPT::META_FUNDING_UNIT_VERSION] ?? null) === 'cents_v1', 'admin can explicitly repair malformed request units despite corrupt global defaults');
$GLOBALS['money_options'][$settings_key] = ['funding_goal_cents'=>30000, 'sponsor_amount_cents'=>30000];

$old_options = $GLOBALS['money_options'][\RoxyRS\Settings::OPTION_KEY];
foreach ([['12.345','20.00'], ['0','20.00'], ['0.99','20.00'], ['5.00','0.99'], ['12.00','11.99']] as [$goal, $sponsor]) {
    $_POST = ['funding_goal'=>$goal, 'sponsor_amount'=>$sponsor];
    try { \RoxyRS\Settings::handle_save(); } catch (\RoxyRS\TestRedirect $expected) {}
    check_money($GLOBALS['money_options'][\RoxyRS\Settings::OPTION_KEY] === $old_options, 'invalid or inconsistent Settings amounts leave the option unchanged');
}

$GLOBALS['money_options'][$settings_key] = ['funding_goal_cents'=>'broken', 'sponsor_amount_cents'=>30000];
ob_start(); \RoxyRS\Settings::render_page(false); $settings_html = ob_get_clean();
check_money(strpos($settings_html, 'Saved currency settings need review') !== false
    && strpos($settings_html, 'name="funding_goal"') !== false
    && strpos($settings_html, 'value=""') !== false,
    'Settings page renders a controlled repair form instead of casting corrupt values');
$_POST = ['funding_goal'=>'', 'sponsor_amount'=>''];
try { \RoxyRS\Settings::handle_save(); } catch (\RoxyRS\TestRedirect $expected) {}
check_money($GLOBALS['money_options'][$settings_key]['funding_goal_cents'] === 'broken',
    'blank Settings repair does not replace malformed persisted currency with defaults');
$_POST = ['funding_goal'=>'125.00', 'sponsor_amount'=>'180.00'];
try { \RoxyRS\Settings::handle_save(); } catch (\RoxyRS\TestRedirect $expected) {}
check_money(($GLOBALS['money_options'][$settings_key]['funding_goal_cents'] ?? null) === 12500
    && ($GLOBALS['money_options'][$settings_key]['sponsor_amount_cents'] ?? null) === 18000,
    'Settings admin can repair malformed persisted currency with explicit valid values');

$GLOBALS['money_meta'] = [];
$GLOBALS['money_options'][\RoxyRS\Settings::OPTION_KEY] = ['funding_goal_cents'=>12500, 'sponsor_amount_cents'=>18000, 'sponsor_ticket_qty'=>2];
$_POST = ['title'=>'Fixture request', 'requester_name'=>'Tester', 'requester_email'=>'fixture@example.invalid', 'target_at'=>'2040-03-15T17:00', 'notes'=>''];
try { \RoxyRS\Frontend::handle_submit_request(); } catch (\RoxyRS\TestRedirect $expected) {}
check_money(($GLOBALS['money_meta'][88][\RoxyRS\CPT::META_FUNDING_GOAL] ?? null) === 12500
    && ($GLOBALS['money_meta'][88][\RoxyRS\CPT::META_SPONSOR_AMOUNT] ?? null) === 18000
    && ($GLOBALS['money_meta'][88][\RoxyRS\CPT::META_FUNDING_UNIT_VERSION] ?? null) === 'cents_v1', 'new frontend requests write default amounts as marked integer cents');
$GLOBALS['money_meta'] = [];
$GLOBALS['money_writes'] = [];
$GLOBALS['money_options'][$settings_key] = ['funding_goal_cents'=>'broken', 'sponsor_amount_cents'=>30000];
$insert_calls_before = $GLOBALS['money_insert_calls'];
$_POST = ['title'=>'Fixture request', 'requester_name'=>'Tester', 'requester_email'=>'fixture@example.invalid', 'target_at'=>'2040-03-15T17:00', 'notes'=>''];
try { \RoxyRS\Frontend::handle_submit_request(); } catch (\RoxyRS\TestRedirect $expected) {}
check_money($GLOBALS['money_insert_calls'] === $insert_calls_before && $GLOBALS['money_writes'] === [],
    'corrupt global defaults stop new-request creation before any post or metadata writes');
echo "All requested-money regressions passed.\n";
}
