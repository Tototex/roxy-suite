<?php
// Isolated: no WordPress bootstrap, writes, email, or provider calls.
define('ABSPATH', __DIR__ . '/');
function get_option($key, $default = false) { return $GLOBALS['outcome'] ?? $default; }
require $argv[1] ?? dirname(__DIR__) . '/includes/class-roxy-suite-health.php';
$method = new ReflectionMethod(\RoxySuite\Health::class, 'scheduled_run_item');
$now = new DateTimeImmutable('2026-10-07 12:00:00 UTC');
$base = ['version'=>1, 'run_id'=>'fixture-run', 'status'=>'completed',
    'started_at'=>'2026-10-07 10:00:00', 'completed_at'=>'2026-10-07 10:01:00', 'message'=>''];
$checks = 0;
$check = static function ($result, string $expected, string $name, bool $enabled = true) use ($method, $now, &$checks): array {
    $GLOBALS['outcome'] = $result;
    $item = $method->invoke(null, 'Fixture', 'fixture', $enabled, $now);
    if ($item['status'] !== $expected) throw new RuntimeException($name . ': ' . json_encode($item));
    $checks++;
    return $item;
};
$check(null, 'warn', 'missing evidence');
$item = $check($base, 'pass', 'well-formed recent completion');
if (strpos($item['note'], 'not independent verification') === false) throw new RuntimeException('Completion overclaims delivery');
$checks++;
foreach ([['version'=>'1'], ['run_id'=>''], ['run_id'=>[]], ['started_at'=>'2026-02-30 10:00:00'],
    ['completed_at'=>''], ['completed_at'=>'2026-10-07 09:59:00'], ['completed_at'=>'2026-10-08 10:00:00'],
    ['started_at'=>'yesterday'], ['status'=>'unknown'], ['completed_at'=>[]]] as $bad) {
    $check(array_replace($base, $bad), 'warn', 'invalid record ' . json_encode($bad));
}
$check(array_replace($base, ['status'=>'skipped']), 'warn', 'skip does not prove work succeeded');
$check(array_replace($base, ['status'=>'running', 'completed_at'=>'']), 'warn', 'running not complete');
$check(array_replace($base, ['status'=>'failed', 'message'=>'fixture error']), 'fail', 'failure visible');
$old = array_replace($base, ['started_at'=>'2026-10-01 10:00:00', 'completed_at'=>'2026-10-01 10:01:00']);
$check($old, 'warn', 'old completion not green');
$check(array_replace($old, ['status'=>'failed']), 'fail', 'old failure remains visible');
$check(array_replace($old, ['status'=>'running', 'completed_at'=>'']), 'warn', 'interrupted old run');
$check(array_replace($base, ['started_at'=>'2026-10-05 12:00:00', 'completed_at'=>'2026-10-05 12:01:00']), 'pass', '48 hour boundary');
$check(null, 'pass', 'disabled not needed', false);
echo "$checks scheduler outcome checks passed\n";
