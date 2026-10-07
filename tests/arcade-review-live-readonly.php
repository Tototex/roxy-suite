<?php
// wp eval-file only. No gameplay writes, monthly callback, mail or prize creation.
if (!defined('ABSPATH')) exit(1);
global $wpdb;
$rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}roxy_arcade_scores ORDER BY id",ARRAY_A);
if($wpdb->last_error || !is_array($rows)) throw new RuntimeException('Score evidence read failed.');
$keys=['roxy_arcade_rewards_enabled','roxy_arcade_auto_fulfill_rewards','roxy_arcade_last_awarded_month','roxy_arcade_last_winner_user_id','roxy_arcade_last_winner_sub_id','roxy_arcade_last_review_candidate'];
$options=[]; foreach($keys as $key)$options[$key]=get_option($key);
$html=Roxy_Arcade::render_shortcode();
echo json_encode(['score_count'=>count($rows),'score_sha256'=>hash('sha256',wp_json_encode($rows)),'settings_sha256'=>hash('sha256',wp_json_encode($options)),'rewards_enabled'=>(int)$options['roxy_arcade_rewards_enabled'],'auto_fulfill_setting'=>(int)$options['roxy_arcade_auto_fulfill_rewards'],'shortcode_bytes'=>strlen($html),'has_game_canvas'=>strpos($html,'roxyArcadeCanvas')!==false]) . "\n";
