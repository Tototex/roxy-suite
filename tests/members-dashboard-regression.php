<?php
// Isolated synthetic subscriptions: no live membership or attendance writes.
define('ABSPATH', __DIR__); define('ARRAY_A', 'ARRAY_A');
$GLOBALS['extension'] = false;
function has_filter($hook) { return $GLOBALS['extension']; }
function get_option($key, $default = false) { return ['date_format'=>'Y-m-d','time_format'=>'H:i','roxy_suite_member_visit_cost'=>2,'roxy_suite_member_fixed_cost'=>10][$key] ?? $default; }
function wp_date($format) { return '2026-10-01 00:00:00'; }
function date_i18n($format, $time) { return gmdate($format, $time); }
function absint($value) { return abs((int) $value); }
function update_meta_cache($type, $ids) { if (count($ids)>100 && !$GLOBALS['extension']) throw new RuntimeException('Unbounded candidate metadata batch'); }
function get_post_meta($id, $key, $single) { return str_contains($key, 'trade') ? ($id%10===0) : ($id%2===0 ? $id : 0); }
function wp_get_attachment_image_src($id, $size) { return ['https://example.test/photo/'.$id]; }
function admin_url($path) { return 'https://example.test/wp-admin/'.$path; }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES); }
function esc_url($value) { return htmlspecialchars((string)$value, ENT_QUOTES); }
function add_query_arg($key, $value=null, $url=null) {
    if(is_array($key)) { $url=$value; $values=$key; } else $values=[$key=>$value];
    $parts=parse_url($url);parse_str($parts['query']??'', $query);
    return preg_replace('/\?.*$/','',$url).'?'.http_build_query(array_merge($query,$values));
}
function wcs_get_orders_with_meta_query($query) {
    if ($query['return'] !== 'ids' || $query['type'] !== 'shop_subscription' || $query['status'] !== ['wc-active','wc-pending-cancel']) throw new RuntimeException('Wrong subscription scope');
    return range(257,1);
}
function wcs_get_subscription($id) { return new FixtureSubscription($id); }
function wcs_get_subscriptions($query) {
    $GLOBALS['extension_calls'] = ($GLOBALS['extension_calls'] ?? 0)+1;
    $subs = array_map('wcs_get_subscription', range(257,1));
    if ($GLOBALS['extension']) { $subs = array_slice($subs, 0, 111); foreach($subs as $sub)$sub->custom_total=37; }
    return $subs;
}
class FixtureSubscription {
    public $custom_total = null;
    function __construct(private $id) {}
    function get_id(){return $this->id;}
    function get_user(){return (object)['user_email'=>'fixture'.$this->id.'@example.test','display_name'=>'Fixture'];}
    function get_billing_first_name(){return 'Fixture';} function get_billing_last_name(){return (string)$this->id;}
    function get_status(){return $this->id%3===0 ? 'pending-cancel':'active';}
    function get_date($key){return '';}
    function get_total(){return $this->custom_total ?? (10+$this->id%7);}
    function get_billing_interval(){return 1;} function get_billing_period(){return 'month';}
    function get_items(){return [new class($this->id) {function __construct(private $id){} function get_quantity(){return 1+$this->id%4;}function get_name(){return 'Membership';}}];}
}
class FixtureDatabase {
    public $prefix='fixture_'; public $last_error=''; public $fail=false;
    function prepare($sql, $args) { $args = is_array($args)?$args:[$args]; foreach($args as $arg)$sql=preg_replace('/%[sd]/',is_int($arg)?(string)$arg:"'".$arg."'",$sql,1); return $sql; }
    function get_var($sql){return 'fixture_roxy_member_scans';}
    function get_results($sql,$format){
        if($this->fail){$this->last_error='fixture read failure';return null;}
        preg_match('/IN \(([0-9,]+)\)/',$sql,$match);
        return array_map(static fn($id)=>['subscription_id'=>(int)$id,'visits_month'=>(int)$id%5,'visits_lifetime'=>(int)$id%9,'last_visit'=>'2026-10-01 19:00:00'],explode(',',$match[1]));
    }
}
$GLOBALS['wpdb']=new FixtureDatabase;
// Accept either an explicit baseline PHP path or a committed Git revision so
// this parity test can run without a hand-created fixture file.
$baseline_path = $argv[1] ?? '';
$candidate_path = $argv[2] ?? '';
if ($baseline_path === '--baseline-ref') {
    $revision = $argv[2] ?? '';
    $candidate_path = $argv[3] ?? '';
    if (!preg_match('/^[A-Za-z0-9._\/-]{1,80}$/D', $revision)) throw new RuntimeException('Invalid baseline Git revision.');
    $baseline_source = shell_exec('git show ' . escapeshellarg($revision . ':includes/class-roxy-suite-members-dashboard.php'));
} else {
    $baseline_source = $baseline_path !== '' ? file_get_contents($baseline_path) : false;
}
if (!is_string($baseline_source) || $baseline_source === '' || $candidate_path === '' || !is_file($candidate_path)) {
    throw new RuntimeException('Usage: members-dashboard-regression.php baseline.php candidate.php, or --baseline-ref <revision> candidate.php');
}
// The baseline intentionally loads all metadata; exempt that original path only.
$baseline_source=str_replace('update_meta_cache(', 'fixture_baseline_meta_cache(', $baseline_source);
function fixture_baseline_meta_cache(...$args) {}
// Temporary fixture artifact is outside the repository; deleted immediately after parity.
$baseline=tempnam(sys_get_temp_dir(),'roxy-member-baseline-');
try {
    file_put_contents($baseline,$baseline_source);
    $args=[$baseline,$candidate_path];
    require __DIR__.'/members-dashboard-parity.php';
    $filters=['search'=>'','status'=>'','photo'=>'','trade'=>'all'];
    foreach([0,-9,'bad',[]] as $invalid){$_GET=['member_page'=>$invalid];if($new->invoke(null,$filters)['page']!==1)throw new RuntimeException('Invalid page not normalized');}
    $_GET=['member_page'=>2];$snapshot=$new->invoke(null,$filters);
    $navigation=new ReflectionMethod('RoxySuite\\Members_DashboardCandidate','pagination');
    ob_start();$navigation->invoke(null,$snapshot,['search'=>'Fixture & name','status'=>'active','photo'=>'missing','trade'=>'all']);$html=ob_get_clean();
    foreach(['Previous members','Next members','member_page=1','member_page=3','member_status=active','member_photo=missing','member_trade=all','member_search=Fixture+%26+name','Totals cover all matching memberships'] as $text)if(!str_contains($html,$text))throw new RuntimeException('Pagination lost navigation/filter state');
    echo "PASS: invalid page normalization, navigation and filter preservation.\n";
    $GLOBALS['extension']=true;
    foreach(['counted','all','trade'] as $trade){
        $filters=['search'=>'','status'=>'','photo'=>'','trade'=>$trade];$_GET=[];
        $before=$old->invoke(null,$filters);$after=$new->invoke(null,$filters);
        $expected=$before;$expected['rows']=array_slice($before['rows'],0,50);
        $expected+=['total_rows'=>count($before['rows']),'page'=>1,'pages'=>max(1,(int)ceil(count($before['rows'])/50))];
        if($after!==$expected)throw new RuntimeException('Extension collection/object changes lost');
    }
    echo "PASS: extension-filtered collection and modified subscription objects preserved.\n";
    $GLOBALS['extension']=false;$GLOBALS['wpdb']->fail=true;
    try{$new->invoke(null,['search'=>'','status'=>'','photo'=>'','trade'=>'all']);throw new LogicException('Read failure displayed partial totals');}
    catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
    echo "PASS: attendance read failure prevents partial totals.\n";
} finally { unlink($baseline); }
