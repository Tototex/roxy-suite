<?php
// Installed WordPress cron API fixture. Only unique inert fixture hooks mutate;
// production callbacks, mail, settings and financial tables are never invoked.
$namespace = 'RoxyGrossesCronFixture40';
$suffix = bin2hex(random_bytes(6));
$hooks = ['roxy_fixture40_report_' . $suffix, 'roxy_fixture40_advertiser_' . $suffix];
$checks = 0;
$check = static function ($condition, $label) use (&$checks) {
  if (!$condition) throw new RuntimeException($label);
  $checks++;
  echo "PASS: $label\n";
};
$source = file_get_contents($args[0]);
$source = str_replace(['namespace RoxyGrosses;', "'roxy_grosses_scheduled_send'", "'roxy_grosses_monthly_advertiser_send'"], ["namespace $namespace;", "'{$hooks[0]}'", "'{$hooks[1]}'"], $source);
eval('namespace ' . $namespace . '; class Settings { public static function get_report_timezone():string{return "America/Los_Angeles";} } class Store { public static $logs=[]; public static function insert_log(...$args):void{self::$logs[]=$args;} }');
eval('?>' . $source);
$scheduler = $namespace . '\\Scheduler';
$store = $namespace . '\\Store';
$settings = ['schedule_enabled'=>'1', 'schedule_time'=>'23:00', 'advertiser_schedule_enabled'=>'1', 'advertiser_schedule_time'=>'09:00', 'report_timezone'=>'America/Los_Angeles'];
$now = new DateTimeImmutable('2037-06-10 18:00:00', new DateTimeZone('America/Los_Angeles'));
$injected = null;
try {
  $check(wp_schedule_event($now->setTime(22, 0)->getTimestamp(), 'daily', $hooks[0]) === true, 'actual WP legacy recurrence seeded only on inert fixture hook');
  $scheduler::ensure_schedule($settings, $now);
  foreach ($hooks as $i => $hook) {
    $event = wp_get_scheduled_event($hook);
    $clock = (new DateTimeImmutable('@' . $event->timestamp))->setTimezone($now->getTimezone())->format('H:i');
    $check($event && $event->schedule === false && $clock === ($i === 0 ? '23:00' : '09:00'), 'actual WP single-event repair preserves configured local clock ' . $i);
  }
  $before = wp_next_scheduled($hooks[0]);
  $scheduler::ensure_schedule($settings, $now->modify('+2 days'));
  $check(wp_next_scheduled($hooks[0]) === $before, 'actual WP matching overdue event is not postponed');
  $settings['schedule_time'] = '21:15';
  $scheduler::ensure_schedule($settings, $now);
  $check((new DateTimeImmutable('@' . wp_next_scheduled($hooks[0])))->setTimezone($now->getTimezone())->format('H:i') === '21:15', 'actual WP settings-time mismatch repaired');
  $injected = static function ($pre, $event) use ($hooks) { return $event->hook === $hooks[0] ? false : $pre; };
  add_filter('pre_schedule_event', $injected, 10, 2);
  $settings['schedule_time'] = '20:00';
  $scheduler::ensure_schedule($settings, $now);
  $last = end($store::$logs);
  $check(!wp_next_scheduled($hooks[0]) && $last[4] === false, 'actual WP registration failure is reported without success or fatal bootstrap');
  remove_filter('pre_schedule_event', $injected, 10);
  $injected = null;
  $scheduler::ensure_schedule($settings, $now);
  $check((bool) wp_next_scheduled($hooks[0]), 'actual WP repair retries successfully after transient registration failure');
  $settings['schedule_enabled'] = $settings['advertiser_schedule_enabled'] = '0';
  $scheduler::ensure_schedule($settings, $now);
  $check(!wp_next_scheduled($hooks[0]) && !wp_next_scheduled($hooks[1]), 'actual WP disable removes only inert fixture events');
  echo "$checks installed WordPress cron checks passed. No production send or settings changes.\n";
} finally {
  if ($injected) remove_filter('pre_schedule_event', $injected, 10);
  foreach ($hooks as $hook) wp_clear_scheduled_hook($hook);
}
