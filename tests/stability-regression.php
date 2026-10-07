<?php
// Isolated checks. No WordPress load, database, provider calls or email delivery.
namespace RoxyST { class CPT { const POST_TYPE = 'roxy_showing'; } }
namespace {
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
$root = $argv[1] ?? dirname(__DIR__);
function check($condition, $label) { if (!$condition) throw new \RuntimeException($label); echo "PASS: $label\n"; }
function wp_timezone() { return new \DateTimeZone('America/Los_Angeles'); }
function get_posts($args) { return array_keys($GLOBALS['shows']); }
function get_post_meta($id, $key, $single) { return $GLOBALS['shows'][$id]; }
function get_the_title($id) { return 'Test showing'; }
function wp_unslash($value) { return $value; }
function get_site_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_site_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; $GLOBALS['ttls'][$key] = $ttl; }
function delete_site_transient($key) { unset($GLOBALS['transients'][$key]); }
function wp_remote_get($url, $args) { $GLOBALS['calls']++; return strpos($url, '.manifest.json') !== false ? $GLOBALS['manifest_response'] : $GLOBALS['response']; }
function wp_parse_url($url) { return parse_url($url); }
function is_wp_error($value) { return $value === 'error'; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? []; }
function wp_get_scheduled_event($hook) { return $GLOBALS['event'] ?? false; }
function wp_next_scheduled($hook) { return isset($GLOBALS['event']) ? $GLOBALS['event']->timestamp : false; }
function wp_unschedule_event($timestamp, $hook) { if(!empty($GLOBALS['fail_unschedule'])) return false; if(!empty($GLOBALS['noop_unschedule'])) return true; unset($GLOBALS['event']); return true; }
function wp_schedule_single_event($timestamp, $hook) { if(!empty($GLOBALS['fail_schedule'])) return false; if(!empty($GLOBALS['noop_schedule'])) return true; $GLOBALS['event'] = (object) ['timestamp'=>$timestamp,'schedule'=>'']; return true; }
function wp_parse_args($values, $defaults) { return array_merge($defaults, $values); }
function admin_url($path) { return 'https://example.test/' . $path; }

require $root . '/includes/modules/event-booking/includes/availability.php';
require $root . '/includes/modules/inventory/includes/class-roxy-inventory-admin.php';
require $root . '/includes/modules/inventory/includes/class-roxy-inventory-settings.php';
require $root . '/includes/modules/inventory/includes/class-roxy-inventory-scheduler.php';
require $root . '/includes/class-roxy-suite-updater.php';

$GLOBALS['shows'] = [1 => '2026-10-10 19:00:00'];
$blocks = roxy_eb_get_showing_blocks_for_range(new \DateTimeImmutable('2026-10-10 16:00', wp_timezone()), new \DateTimeImmutable('2026-10-10 19:00', wp_timezone()));
check(count($blocks) === 1, 'B1: buffer overlaps even when show starts exactly at range end');
$blocks = roxy_eb_get_showing_blocks_for_range(new \DateTimeImmutable('2026-10-10 21:00', wp_timezone()), new \DateTimeImmutable('2026-10-10 22:00', wp_timezone()));
check(count($blocks) === 0, 'B1: touching reserved end is not an overlap');
$blocks = roxy_eb_get_showing_blocks_for_range(new \DateTimeImmutable('2026-10-10 20:00', wp_timezone()), new \DateTimeImmutable('2026-10-10 22:00', wp_timezone()));
check(count($blocks) === 1, 'B1: show before query start still blocks its trailing buffer');
$GLOBALS['shows'] = [1 => 'bad date'];
check(roxy_eb_get_showing_blocks_for_range(new \DateTimeImmutable('2026-10-10'), new \DateTimeImmutable('2026-10-11')) === [], 'B1: malformed dates safely skipped');

$rows = [];
for ($id = 1; $id <= 500; $id++) $rows[$id] = ['vendor'=>'Vistar','pack_size'=>'12','reorder_point'=>'20','target_stock'=>'60','unit_cost'=>'1.53','override_qty'=>''];
$post = ['product_row_count'=>'500','product_rows_complete'=>'1','product_rows_json'=>json_encode($rows)];
check(count(\RoxyInventory\Admin::product_rows_from_submission($post)) === 500, 'I1: 500 rows decoded from one form variable');
unset($post['product_rows_complete']);
check(\RoxyInventory\Admin::product_rows_from_submission($post) === null, 'I1: missing tail marker rejected before writes');
$post['product_rows_complete'] = '1'; $post['product_row_count'] = '501';
check(\RoxyInventory\Admin::product_rows_from_submission($post) === null, 'I1: missing rows rejected');
$post['product_row_count'] = '500'; unset($rows[500]['target_stock']); $post['product_rows_json'] = json_encode($rows);
check(\RoxyInventory\Admin::product_rows_from_submission($post) === null, 'I1: missing last-row field cannot become zero');
$rows[500]['target_stock'] = '2.5'; $post['product_rows_json'] = json_encode($rows);
check(\RoxyInventory\Admin::product_rows_from_submission($post) === null, 'I1: fractional quantity rejected');
$fallback = ['product_row_count'=>'1','product_rows_complete'=>'1','vendor'=>[1=>'Vistar'],'pack_size'=>[1=>'12'],'reorder_point'=>[1=>'20'],'target_stock'=>[1=>'60'],'unit_cost'=>[1=>'1.53'],'override_qty'=>[1=>'']];
check(count(\RoxyInventory\Admin::product_rows_from_submission($fallback)) === 1, 'I1: complete non-JavaScript fallback accepted');
unset($fallback['target_stock']);
check(\RoxyInventory\Admin::product_rows_from_submission($fallback) === null, 'I1: truncated fallback rejected');
foreach(['bad_cost'=>['unit_cost'=>'1.555'],'overflow'=>['target_stock'=>'10000000000']]as$case=>$change){
    $row=['vendor'=>'Vistar','pack_size'=>'12','reorder_point'=>'20','target_stock'=>'60','unit_cost'=>'1.50','override_qty'=>''];
    $p=['product_rows_complete'=>'1','product_row_count'=>'1','product_rows_json'=>json_encode([1=>array_merge($row,$change)])];
    check(\RoxyInventory\Admin::product_rows_from_submission($p)===null,'I9: invalid rule '.$case.' rejected before writes');
}
check(\RoxyInventory\Admin::product_rows_from_submission(['product_rows_complete'=>'1','product_row_count'=>['1']])===null,'I9: array row count rejected safely');
check(\RoxyInventory\Admin::product_rows_from_submission(['product_rows_complete'=>'1','product_row_count'=>'1','product_rows_json'=>['bad']])===null,'I9: non-string JSON submission rejected safely');

$next = new \ReflectionMethod(\RoxyInventory\Scheduler::class, 'next_time'); $next->setAccessible(true);
foreach (['2026-10-31 23:01'=>'2026-11-01 23:00', '2026-03-07 23:01'=>'2026-03-08 23:00'] as $date => $expected) {
    $actual = (new \DateTimeImmutable('@' . $next->invoke(null, new \DateTimeImmutable($date, wp_timezone()))))->setTimezone(wp_timezone());
    check($actual->format('Y-m-d H:i') === $expected, 'I5: local schedule preserved across DST ' . $date);
}
$GLOBALS['event'] = (object) ['timestamp'=>123,'schedule'=>'daily'];
\RoxyInventory\Scheduler::ensure_schedule();
check($GLOBALS['event']->schedule === '' && $GLOBALS['event']->timestamp > time(), 'I5: legacy recurring schedule migrated to next single event');
$scheduled = $GLOBALS['event']->timestamp;
\RoxyInventory\Scheduler::ensure_schedule();
check($scheduled === $GLOBALS['event']->timestamp, 'I5: existing single event not rescheduled on page loads');
$GLOBALS['options'][\RoxyInventory\Settings::OPTION_KEY] = ['schedule_enabled'=>'0'];
\RoxyInventory\Scheduler::ensure_schedule();
check(!isset($GLOBALS['event']), 'I5: disabled schedule remains disabled');
$GLOBALS['options'][\RoxyInventory\Settings::OPTION_KEY] = ['schedule_enabled'=>'1'];
foreach (['fail_unschedule','noop_unschedule','fail_schedule','noop_schedule'] as $fault) {
    unset($GLOBALS['event']);
    if (strpos($fault,'unschedule')!==false) $GLOBALS['event']=(object)['timestamp'=>123,'schedule'=>'daily'];
    $GLOBALS[$fault]=true; $failed=false;
    try { \RoxyInventory\Scheduler::sync_schedule(); } catch (\RuntimeException $e) { $failed=true; }
    check($failed,'I9: scheduler reports ' . $fault . ' instead of looping or claiming success');
    \RoxyInventory\Scheduler::ensure_schedule();
    check(true,'I9: failed automatic schedule repair does not break page bootstrap');
    $GLOBALS[$fault]=false;
}
unset($GLOBALS['event']);
\RoxyInventory\Scheduler::sync_schedule();
check(isset($GLOBALS['event']) && $GLOBALS['event']->schedule==='','I9: schedule retry succeeds after fault removal');

$config = new \ReflectionProperty(\RoxySuite\Updater::class, 'config'); $config->setAccessible(true); $config->setValue(null, ['github_repo'=>'test/test','slug'=>'test']);
$release = new \ReflectionProperty(\RoxySuite\Updater::class, 'release'); $release->setAccessible(true);
$latest = new \ReflectionMethod(\RoxySuite\Updater::class, 'get_latest_release'); $latest->setAccessible(true);
foreach (['error', ['code'=>503,'body'=>'{}'], ['code'=>200,'body'=>'not json'], ['code'=>200,'body'=>'{"tag_name":"v1","assets":[]}']] as $response) {
    $GLOBALS['transients'] = []; $GLOBALS['calls'] = 0; $GLOBALS['response'] = $response; $release->setValue(null, null);
    check($latest->invoke(null) === null && $latest->invoke(null) === null && $GLOBALS['calls'] === 1, 'C5: failed release check backed off');
}
$GLOBALS['transients'] = []; $GLOBALS['calls'] = 0;
$GLOBALS['response'] = ['code'=>200,'body'=>json_encode(['tag_name'=>'v1.0.17','assets'=>[
    ['name'=>'test-1.0.17.zip','browser_download_url'=>'https://github.com/test/test/releases/download/v1.0.17/test-1.0.17.zip'],
    ['name'=>'test-1.0.17.manifest.json','browser_download_url'=>'https://github.com/test/test/releases/download/v1.0.17/test-1.0.17.manifest.json'],
]])];
$GLOBALS['manifest_response'] = ['code'=>200,'body'=>json_encode([
    'format_version'=>1,'version'=>'1.0.17','source_sha'=>str_repeat('a',40),
    'archive'=>['file'=>'test-1.0.17.zip','sha256'=>str_repeat('b',64)],
    'files'=>[['path'=>'roxy-suite/roxy-suite.php','sha256'=>str_repeat('c',64)]],
])];
check($latest->invoke(null)['version'] === '1.0.17', 'C5: successful release lookup still works');
check($latest->invoke(null)['version'] === '1.0.17' && $GLOBALS['calls'] === 2, 'C5: verified release and manifest lookup remain cached');
check(strpos(file_get_contents($root . '/roxy-suite.php'), 'Requires PHP: 8.0') !== false, 'C3: minimum PHP declared');
}
