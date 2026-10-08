<?php
/** Standalone schedule-child status tests. Run with: php tests/schedule-child-status-regression.php */
namespace {
  define('ABSPATH', __DIR__ . '/');
  define('MINUTE_IN_SECONDS', 60);
  $GLOBALS['schedule_meta'] = [];
  $GLOBALS['schedule_insert_args'] = [];
  $GLOBALS['schedule_fail_at'] = 0;
  $GLOBALS['schedule_fail_false'] = false;
  $GLOBALS['schedule_publish_allowed'] = false;
  $GLOBALS['schedule_deleted'] = [];
  $GLOBALS['schedule_nested_save_calls'] = 0;
  $GLOBALS['schedule_next_id'] = 8000;
  $GLOBALS['schedule_transients'] = [];

  class WP_Error {
    private $code; private $message;
    public function __construct($code, $message = '') { $this->code=$code; $this->message=$message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
  }
  function is_wp_error($value) { return $value instanceof WP_Error; }
  function check_schedule($condition, $message) { if (!$condition) throw new \RuntimeException($message); }
  function schedule_test($name, $callback) { $callback(); echo "ok - $name\n"; }
  function wp_get_object_terms($post_id, $taxonomy, $args = []) { return []; }
  function get_post_thumbnail_id($post_id) { return 0; }
  function get_post_type_object($post_type) { return (object)['cap'=>(object)['publish_posts'=>'publish_roxy_showings']]; }
  function current_user_can($capability, ...$args) {
    if ($capability === 'edit_post') return true;
    return $capability === 'publish_roxy_showings' && $GLOBALS['schedule_publish_allowed'];
  }
  function wp_verify_nonce($nonce, $action) { return $nonce === 'valid'; }
  function sanitize_text_field($value) { return trim((string)$value); }
  function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$value)); }
  function wp_unslash($value) { return $value; }
  function wp_timezone() { return new DateTimeZone('America/Los_Angeles'); }
  function get_current_user_id() { return 42; }
  function set_transient($key, $value, $expiration) { $GLOBALS['schedule_transients'][$key] = $value; return true; }
  function esc_url_raw($value) { return (string)$value; }
  function get_post_meta($post_id, $key, $single = true) { return $GLOBALS['schedule_meta'][$post_id][$key] ?? ''; }
  function update_post_meta($post_id, $key, $value) { $GLOBALS['schedule_meta'][$post_id][$key]=$value; return true; }
  function delete_post_meta($post_id, $key) { unset($GLOBALS['schedule_meta'][$post_id][$key]); return true; }
  function wp_set_object_terms(...$args) { return true; }
  function set_post_thumbnail(...$args) { return true; }
  function wp_delete_post($post_id, $force = false) { $GLOBALS['schedule_deleted'][]=(int)$post_id; unset($GLOBALS['schedule_meta'][$post_id]); return (object)['ID'=>(int)$post_id]; }
  function wp_insert_post($postarr, $wp_error = false) {
    $GLOBALS['schedule_insert_args'][]=$postarr;
    $call=count($GLOBALS['schedule_insert_args']);
    if ($GLOBALS['schedule_fail_at']===$call) return $GLOBALS['schedule_fail_false'] ? 0 : new WP_Error('insert_failed','simulated insert failure');
    $id=++$GLOBALS['schedule_next_id'];
    $GLOBALS['schedule_nested_save_calls']++;
    \RoxyST\CPT::save($id,(object)$postarr); // Mirrors save_post firing synchronously during wp_insert_post.
    return $id;
  }

  $GLOBALS['schedule_cpt_source']=$argv[1] ?? dirname(__DIR__) . '/includes/modules/show-tickets/includes/class-roxy-st-cpt.php';
  require_once $GLOBALS['schedule_cpt_source'];

  function reset_schedule_fixture($status, $can_publish, $fail_at = 0, $return_false = false) {
    $GLOBALS['schedule_meta']=[]; $GLOBALS['schedule_insert_args']=[]; $GLOBALS['schedule_fail_at']=$fail_at;
    $GLOBALS['schedule_fail_false']=$return_false; $GLOBALS['schedule_publish_allowed']=$can_publish;
    $GLOBALS['schedule_deleted']=[]; $GLOBALS['schedule_nested_save_calls']=0; $GLOBALS['schedule_transients']=[]; $_POST=[
      'roxy_showing_nonce'=>'valid', 'roxy_use_schedule_builder'=>'1',
      'roxy_capacity'=>'100', 'roxy_pricing_profile'=>'movie_evening',
      'roxy_schedule_date'=>['2031-06-01','2031-06-02','2031-06-03'],
      'roxy_schedule_time'=>['18:00','18:00','14:00'],
      'roxy_schedule_profile'=>['movie_evening','movie_evening','movie_matinee'],
    ];
    return (object)['ID'=>7001,'post_status'=>$status,'post_title'=>'Test show','post_content'=>'','post_excerpt'=>'','post_author'=>9];
  }

  schedule_test('published source publishes children only with its CPT publish capability and recursion is guarded', function() {
    $source=reset_schedule_fixture('publish',true);
    \RoxyST\CPT::save($source->ID,$source);
    check_schedule(count($GLOBALS['schedule_insert_args'])===2,'two child rows should be inserted');
    check_schedule($GLOBALS['schedule_insert_args'][0]['post_status']==='publish' && $GLOBALS['schedule_insert_args'][1]['post_status']==='publish','authorized publish source should publish children');
    check_schedule($GLOBALS['schedule_nested_save_calls']===2,'insert should invoke child save callback');
    check_schedule(($GLOBALS['schedule_meta'][$source->ID]['_roxy_schedule_generated']??'')==='1','successful batch should be marked generated');
  });

  schedule_test('nonpublic source statuses never create published children', function() {
    foreach (['draft'=>'draft','private'=>'private','pending'=>'pending','future'=>'draft'] as $source_status=>$expected_child_status) {
      $source=reset_schedule_fixture($source_status,true); \RoxyST\CPT::save($source->ID,$source);
      check_schedule(count($GLOBALS['schedule_insert_args'])===2,'expected child rows for '.$source_status);
      foreach ($GLOBALS['schedule_insert_args'] as $args) check_schedule($args['post_status']===$expected_child_status,'child should preserve nonpublic source status');
    }
  });

  schedule_test('published source without publish capability creates drafts', function() {
    $source=reset_schedule_fixture('publish',false); \RoxyST\CPT::save($source->ID,$source);
    foreach ($GLOBALS['schedule_insert_args'] as $args) check_schedule($args['post_status']==='draft','missing CPT publish capability must prevent publishing child');
  });

  schedule_test('shared duplicate-weekend publication decision checks authority and source state', function() {
    $status=new \ReflectionMethod(\RoxyST\CPT::class,'generated_post_status'); $status->setAccessible(true);
    foreach ([['publish',true,'publish'],['publish',false,'draft'],['private',true,'private'],['pending',true,'pending'],['future',true,'draft']] as [$source_status,$allowed,$expected]) {
      $GLOBALS['schedule_publish_allowed']=$allowed;
      check_schedule($status->invoke(null,(object)['post_status'=>$source_status])===$expected,'shared generation status should preserve publication boundary');
    }
    $source=file_get_contents($GLOBALS['schedule_cpt_source']);
    check_schedule(strpos($source,"'post_status' => self::generated_post_status(\$source)")!==false,'weekend duplication must use shared status gate');
  });

  schedule_test('WP_Error or false insert rolls back batch and leaves source unmarked', function() {
    foreach ([false,true] as $return_false) {
      $source=reset_schedule_fixture('publish',true,2,$return_false); \RoxyST\CPT::save($source->ID,$source);
      check_schedule(!isset($GLOBALS['schedule_meta'][$source->ID]['_roxy_schedule_generated']),'failed child insert must not mark schedule generated');
      check_schedule(count($GLOBALS['schedule_deleted'])===1,'earlier child from a failed batch should be removed');
      check_schedule($GLOBALS['schedule_nested_save_calls']===1,'recursion guard should prevent child save from generating descendants');
    }
  });

  schedule_test('invalid single showing date preserves previous date and all module settings', function() {
    $source=reset_schedule_fixture('draft',false);
    unset($_POST['roxy_use_schedule_builder']);
    $_POST['roxy_start']='2026-02-30T18:00';
    $GLOBALS['schedule_meta'][$source->ID]=['_roxy_start'=>'2031-06-01T18:00','_roxy_capacity'=>'60'];
    \RoxyST\CPT::save($source->ID,$source);
    check_schedule($GLOBALS['schedule_meta'][$source->ID]['_roxy_start']==='2031-06-01T18:00','impossible calendar date does not replace last valid showing date');
    check_schedule($GLOBALS['schedule_meta'][$source->ID]['_roxy_capacity']==='60','invalid date rejects unrelated module settings in the same submission');
    check_schedule(isset($GLOBALS['schedule_transients']['roxy_st_invalid_start_42']),'invalid date requests a visible admin notice');
    check_schedule($GLOBALS['schedule_insert_args']===[],'invalid single date creates no scheduled child');
  });

  schedule_test('valid leap-day local start saves in site timezone', function() {
    $source=reset_schedule_fixture('draft',false);
    unset($_POST['roxy_use_schedule_builder']);
    $_POST['roxy_start']='2032-02-29T18:30';
    \RoxyST\CPT::save($source->ID,$source);
    check_schedule(($GLOBALS['schedule_meta'][$source->ID]['_roxy_start']??'')==='2032-02-29T18:30','valid leap-day showing time is retained exactly as local wall time');
  });

  schedule_test('one invalid schedule row rejects the entire schedule batch', function() {
    $source=reset_schedule_fixture('draft',false);
    $_POST['roxy_schedule_date'][1]='2026-02-30';
    $GLOBALS['schedule_meta'][$source->ID]=['_roxy_start'=>'2031-06-01T18:00','_roxy_capacity'=>'60'];
    \RoxyST\CPT::save($source->ID,$source);
    check_schedule($GLOBALS['schedule_insert_args']===[],'invalid row prevents partial schedule creation');
    check_schedule($GLOBALS['schedule_meta'][$source->ID]['_roxy_start']==='2031-06-01T18:00','invalid batch preserves original start date');
    check_schedule($GLOBALS['schedule_meta'][$source->ID]['_roxy_capacity']==='60','invalid batch preserves shared settings');
    check_schedule(!isset($GLOBALS['schedule_meta'][$source->ID]['_roxy_schedule_generated']),'invalid batch remains retryable after correction');
  });

  schedule_test('scheduled ticket price change dates shift seven local days across DST', function() {
    $shift=new \ReflectionMethod(\RoxyST\CPT::class,'shift_local_datetime_days'); $shift->setAccessible(true);
    check_schedule($shift->invoke(null,'2026-03-01T19:30',7)==='2026-03-08T19:30','spring DST transition preserves local price-change time');
    check_schedule($shift->invoke(null,'2026-10-25T19:30',7)==='2026-11-01T19:30','fall DST transition preserves local price-change time');
    check_schedule($shift->invoke(null,'2026-02-30T19:30',7)===null,'invalid saved price-change date refuses to create a shifted value');
  });

  schedule_test('invalid scheduled price-change date preserves prior values and shared settings', function() {
    $source=reset_schedule_fixture('draft',false);
    unset($_POST['roxy_use_schedule_builder']);
    $_POST['roxy_start']='2031-06-01T18:00';
    $_POST['roxy_live_change_at_1']='2026-02-30T19:30';
    $GLOBALS['schedule_meta'][$source->ID]=['_roxy_start'=>'2031-06-01T18:00','_roxy_capacity'=>'60','_roxy_live_change_at_1'=>'2026-02-28T19:30'];
    \RoxyST\CPT::save($source->ID,$source);
    check_schedule($GLOBALS['schedule_meta'][$source->ID]['_roxy_live_change_at_1']==='2026-02-28T19:30','impossible price change date leaves old date intact');
    check_schedule($GLOBALS['schedule_meta'][$source->ID]['_roxy_capacity']==='60','invalid price date rejects unrelated settings in the submission');
  });

  echo "All schedule child status regressions passed.\n";
}
