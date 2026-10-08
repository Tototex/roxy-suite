<?php
// Focused durable closed-day retry regression; fake cron/mail only.
namespace RoxyGrosses {
  final class Settings {
    public static function get_report_timezone(): string { return 'America/Los_Angeles'; }
    public static function get_all(): array { return ['schedule_enabled'=>'0','advertiser_schedule_enabled'=>'0','report_timezone'=>'America/Los_Angeles']; }
  }
  final class Store {
    public static array $logs = [];
    public static function insert_log(...$args): void { self::$logs[] = $args; }
  }
  final class Reporter {
    public static array $refresh_results = [];
    public static array $refresh_dates = [];
    public static int $send_calls = 0;
    public static function refresh_closed_day(string $date): array {
      self::$refresh_dates[] = $date;
      return array_shift(self::$refresh_results) ?? ['success'=>true,'message'=>'reviewed'];
    }
    public static function send_report(...$args): array { self::$send_calls++; return ['success'=>true]; }
  }
  final class Workbook { public static function send_advertiser_summary(...$args): array { return ['success'=>true]; } }
}

namespace {
  if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  class ClosedDayRetryWpError {}
  final class ClosedDayRetryWpdb {
    public bool $contended = false;
    public bool $lose_owner = false;
    public ?string $owner = null;
    public int $acquisitions = 0;
    public int $ownership_checks = 0;
    public int $releases = 0;
    public function prepare(string $query, ...$args): string { return json_encode(['sql'=>$query,'args'=>$args]); }
    public function get_var(string $query) {
      $parts=json_decode($query,true); $sql=(string)($parts['sql']??$query);
      if (str_contains($sql,'GET_LOCK')) {
        if ($this->contended) return 0;
        $this->owner='fixture-connection'; $this->acquisitions++; return 1;
      }
      if (str_contains($sql,'IS_USED_LOCK')) {
        $this->ownership_checks++;
        if ($this->lose_owner) { $this->owner=null; return 0; }
        return $this->owner==='fixture-connection' ? 1 : 0;
      }
      if (str_contains($sql,'RELEASE_LOCK')) {
        if ($this->owner==='fixture-connection') { $this->owner=null; $this->releases++; return 1; }
        return 0;
      }
      return null;
    }
  }
  function add_action(...$args): void {}
  function wp_installing(): bool { return false; }
  function current_time(string $type, bool $gmt = false): string { return '2038-05-03 09:00:00'; }
  function is_wp_error($value): bool { return $value instanceof ClosedDayRetryWpError; }
  function wp_schedule_single_event(int $timestamp, string $hook, array $args = []) {
    if (($GLOBALS['closed_day_schedule_noop'] ?? false) === true) return true;
    $GLOBALS['closed_day_cron'][] = ['timestamp'=>$timestamp,'hook'=>$hook,'args'=>$args,'schedule'=>false];
    return true;
  }
  function wp_unschedule_event(int $timestamp, string $hook, array $args = []) {
    $GLOBALS['closed_day_cron'] = array_values(array_filter($GLOBALS['closed_day_cron'], static fn(array $event): bool => !($event['timestamp']===$timestamp && $event['hook']===$hook && $event['args']===$args)));
    return true;
  }
  function wp_next_scheduled(string $hook, array $args = []) {
    foreach ($GLOBALS['closed_day_cron'] as $event) if ($event['hook']===$hook && $event['args']===$args) return $event['timestamp'];
    return false;
  }
  function _get_cron_array(): array {
    $cron=[];
    foreach ($GLOBALS['closed_day_cron'] as $event) $cron[$event['timestamp']][$event['hook']][md5(serialize($event['args']))]=['args'=>$event['args'],'schedule'=>$event['schedule']];
    return $cron;
  }
  function get_option(string $key, $default = false) { return $GLOBALS['closed_day_options'][$key] ?? $default; }
  function update_option(string $key, $value, bool $autoload = true): bool { $GLOBALS['closed_day_options'][$key]=$value; return true; }

  $root = $argv[1] ?? dirname(__DIR__);
  require $argv[2] ?? $root . '/includes/modules/grosses/includes/class-roxy-grosses-scheduler.php';
  $GLOBALS['closed_day_cron']=[];
  $GLOBALS['closed_day_options']=[];
  $GLOBALS['wpdb']=new ClosedDayRetryWpdb();
  $checks=0;
  $assert=static function(bool $ok,string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; echo "PASS: $message\n"; };
  $hook=\RoxyGrosses\Scheduler::closed_day_hook();
  $report_date=(new DateTimeImmutable('yesterday',new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
  $GLOBALS['closed_day_options']['roxy_grosses_closed_day_refresh_queue']=[$report_date=>['attempt'=>0,'status'=>'pending','message'=>'','retry_at'=>1]];
  $save_pending=new ReflectionMethod(\RoxyGrosses\Scheduler::class,'save_closed_day_pending');
  $clear_pending=new ReflectionMethod(\RoxyGrosses\Scheduler::class,'clear_pending_closed_day_refresh');
  $queue_key='roxy_grosses_closed_day_refresh_queue';
  $assert($save_pending->invoke(null,'2000-01-01',0,'pending','lock proof',1)
    && $GLOBALS['wpdb']->owner===null && $GLOBALS['wpdb']->acquisitions===1 && $GLOBALS['wpdb']->ownership_checks>=3 && $GLOBALS['wpdb']->releases===1,
    'queue mutation verifies advisory-lock connection ownership and releases the acquired lock');
  $clear_pending->invoke(null,'2000-01-01');
  $queue_before=$GLOBALS['closed_day_options'][$queue_key];
  $GLOBALS['wpdb']->contended=true;
  $assert(!$save_pending->invoke(null,'2000-01-02',0,'pending','blocked',2)
    && $GLOBALS['closed_day_options'][$queue_key]===$queue_before && $GLOBALS['wpdb']->releases===2,
    'queue contention fails closed without overwriting pending dates');
  $GLOBALS['wpdb']->contended=false;
  $GLOBALS['wpdb']->lose_owner=true;
  $assert(!$save_pending->invoke(null,'2000-01-03',0,'pending','lost lock',3)
    && $GLOBALS['closed_day_options'][$queue_key]===$queue_before,
    'lost advisory-lock ownership prevents the queue write');
  $GLOBALS['wpdb']->lose_owner=false;
  $first_retry=(new DateTimeImmutable('now',new DateTimeZone('America/Los_Angeles')))->getTimestamp()+900;
  $GLOBALS['closed_day_cron'][]=['timestamp'=>$first_retry,'hook'=>$hook,'args'=>[$report_date,0],'schedule'=>false];
  \RoxyGrosses\Reporter::$refresh_results=[['success'=>false,'message'=>'temporary Square failure'],['success'=>true,'message'=>'reviewed']];
  $send_count=\RoxyGrosses\Reporter::$send_calls;

  \RoxyGrosses\Scheduler::run_closed_day_refresh($report_date,0);
  $queue=get_option('roxy_grosses_closed_day_refresh_queue',[]);
  $retry=get_option('roxy_grosses_closed_day_refresh_queue',[])[$report_date] ?? [];
  $attempt_one=wp_next_scheduled($hook,[$report_date,1]);
  $assert(($retry['status'] ?? '')==='pending' && ($retry['attempt'] ?? -1)===1 && $attempt_one && $attempt_one >= ($retry['retry_at'] ?? PHP_INT_MAX), 'failed pull persists retry state and registers the next dated event');
  $assert(\RoxyGrosses\Scheduler::closed_day_refresh_health()['status']==='scheduled' && \RoxyGrosses\Reporter::$send_calls===$send_count, 'Health reports scheduled retry and failure does not trigger email');

  foreach ($GLOBALS['closed_day_cron'] as $event) if ($event['hook']===$hook && $event['args']===[$report_date,1]) wp_unschedule_event($event['timestamp'],$hook,$event['args']);
  \RoxyGrosses\Scheduler::run_closed_day_refresh($report_date,1);
  $assert(\RoxyGrosses\Scheduler::closed_day_refresh_health()['status']==='idle' && \RoxyGrosses\Reporter::$refresh_dates===[$report_date,$report_date], 'successful retry clears durable pending state for the original intended day');
  $assert(\RoxyGrosses\Reporter::$send_calls===$send_count, 'successful closed-day catch-up never sends a corrected report');
  echo "$checks closed-day retry checks passed; fake cron and mail only.\n";
}
