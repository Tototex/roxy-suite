<?php
if (!defined('WP_CLI') || !WP_CLI) exit;

$old_user = get_current_user_id();
$old_post = $_POST;
$event_id = 0;
$screening_id = 0;
try {
  wp_set_current_user(1);
  $event_id = wp_insert_post(['post_type'=>\RoxyST\Events::POST_TYPE,'post_status'=>'private','post_title'=>'PRIVATE EVENT LOCK TEST'], true);
  if (is_wp_error($event_id) || !$event_id) throw new RuntimeException('Could not create the draft event fixture.');
  if (get_post_status((int)$event_id) !== 'private') throw new RuntimeException('The test event fixture is not private.');

  $start = current_datetime()->modify('+5 years')->setTime(14, 30)->format('Y-m-d\\TH:i');
  $_POST = [
    'roxy_st_event_nonce'=>wp_create_nonce('roxy_st_event_save'),
    'roxy_event_format'=>'marathon',
    'roxy_event_double_general'=>'20',
    'roxy_event_double_discount'=>'14',
    'roxy_event_full_pass'=>'45',
    'roxy_event_screening_id'=>[0],
    'roxy_event_screening_title'=>['PRIVATE EVENT LOCK TEST FILM'],
    'roxy_event_screening_start'=>[$start],
    'roxy_event_screening_profile'=>['movie_evening'],
    'roxy_event_screening_duration'=>[''],
  ];
  \RoxyST\Events::save((int)$event_id, get_post((int)$event_id));
  $ids = array_map('intval', (array)get_post_meta((int)$event_id, \RoxyST\Events::META_SHOWINGS, true));
  if (count($ids) !== 1) throw new RuntimeException('A single draft screening was not linked to the event.');
  $screening_id = $ids[0];
  if (get_post_status($screening_id) !== 'draft') throw new RuntimeException('The private test screening was unexpectedly published.');
  if ((string)get_post_meta($screening_id, '_roxy_start', true) !== $start) throw new RuntimeException('The draft screening start time did not persist.');
  $save_error = (string) get_transient('roxy_st_event_invalid_' . get_current_user_id());
  if ($save_error !== '') throw new RuntimeException('The room-lock write returned an error: ' . $save_error);
  echo "PASS: One private event screening was saved through the protected room-time lock." . PHP_EOL;
} finally {
  if ($screening_id && get_post_status($screening_id)) wp_delete_post($screening_id, true);
  if ($event_id && get_post_status((int)$event_id)) wp_delete_post((int)$event_id, true);
  delete_transient('roxy_st_event_invalid_' . get_current_user_id());
  delete_transient('roxy_st_event_restore_' . get_current_user_id() . '_' . (int)$event_id);
  $_POST = $old_post;
  wp_set_current_user($old_user);
}
