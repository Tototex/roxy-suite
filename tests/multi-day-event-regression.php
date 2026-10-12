<?php
if (!defined('WP_CLI') || !WP_CLI) exit;
$root = $args[0] ?? dirname(__DIR__);
global $wpdb;
$check = static function ($ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
  echo 'PASS: ' . $message . PHP_EOL;
};
$old_user = get_current_user_id();
$old_post = $_POST;
$created = [];
$event_id = 0;
$conflict_id = 0;
try {
  $admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
  if (!$admins) throw new RuntimeException('An administrator account is required for the event fixture');
  wp_set_current_user((int)$admins[0]);
  $event_id = wp_insert_post(['post_type'=>\RoxyST\Events::POST_TYPE,'post_status'=>'draft','post_title'=>'PRIVATE MULTI EVENT FIXTURE'], true);
  if (is_wp_error($event_id)) throw new RuntimeException('Could not create event fixture');
  $created[] = (int)$event_id;
  $starts=[];
  $today=current_datetime()->setTime(0,0);
  for($offset=10;$offset<=180 && count($starts)<6;$offset++) {
    $date=$today->modify('+' . $offset . ' days');
    $daily=[];
    foreach(['14:30','19:30'] as $time) {
      $start=$date->format('Y-m-d') . 'T' . $time;
      $start_at=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$start,wp_timezone());
      if(function_exists('roxy_eb_is_slot_available') && !$start_at) continue;
      if(function_exists('roxy_eb_is_slot_available') && !roxy_eb_is_slot_available($start_at->modify('-2 hours'),$start_at->modify('+2 hours'),0,true)) continue;
      $daily[]=$start;
    }
    if(count($daily)===2)$starts=array_merge($starts,$daily);
  }
  if(count($starts)!==6)throw new RuntimeException('Could not find three conflict-free fixture dates');
  $titles = ['PRIVATE FILM A','PRIVATE FILM B','PRIVATE FILM C','PRIVATE FILM D','PRIVATE FILM E','PRIVATE FILM F'];
  $_POST = [
    'roxy_st_event_nonce'=>wp_create_nonce('roxy_st_event_save'),
    'roxy_event_format'=>'marathon',
    'roxy_event_double_general'=>'20',
    'roxy_event_double_discount'=>'14',
    'roxy_event_full_pass'=>'45',
    'roxy_event_screening_id'=>array_fill(0,6,0),
    'roxy_event_screening_title'=>$titles,
    'roxy_event_screening_start'=>$starts,
    'roxy_event_screening_profile'=>array_fill(0,6,'movie_evening'),
    'roxy_event_screening_duration'=>array_fill(0,6,'120'),
  ];
  delete_transient('roxy_st_event_invalid_' . get_current_user_id());
  \RoxyST\Events::save((int)$event_id, get_post((int)$event_id));
  $screenings = array_map('intval',(array)get_post_meta((int)$event_id,\RoxyST\Events::META_SHOWINGS,true));
  $check(count($screenings)===6,'draft event creates six linked screening records');
  $check(count(array_unique($screenings))===6,'each scheduled film receives a distinct showing record');
  foreach ($screenings as $id) $created[]=$id;

  $original_general = (string)get_post_meta((int)$event_id, '_roxy_event_double_general', true);
  $invalid_post = $_POST;
  $invalid_post['roxy_event_double_general'] = '99';
  $invalid_post['roxy_event_screening_title'][0] = '';
  $_POST = $invalid_post;
  \RoxyST\Events::save((int)$event_id, get_post((int)$event_id));
  $check((string)get_post_meta((int)$event_id, '_roxy_event_double_general', true) === $original_general,
    'invalid schedule does not save changed event pass prices');
  $check((string)get_post_meta($screenings[0], '_roxy_start', true) === $starts[0]
    && get_the_title($screenings[0]) === $titles[0], 'invalid schedule leaves all screening rows untouched');
  $bad_format_post = $_POST;
  $bad_format_post['roxy_event_format'] = 'unexpected-format';
  $bad_format_post['roxy_event_double_general'] = '99';
  $_POST = $bad_format_post;
  \RoxyST\Events::save((int)$event_id, get_post((int)$event_id));
  $check((string)get_post_meta((int)$event_id, '_roxy_event_double_general', true) === $original_general,
    'invalid event format rejects the entire schedule and price save');
  delete_transient('roxy_st_event_invalid_' . get_current_user_id());

  $first_new_start = '';
  $conflict_start = '';
  for ($offset=300; $offset<=600 && $first_new_start===''; $offset++) {
    $first_date = current_datetime()->modify('+' . $offset . ' days');
    $conflict_date = $first_date->modify('+1 day');
    $candidate_first = $first_date->format('Y-m-d') . 'T14:30';
    $candidate_conflict = $conflict_date->format('Y-m-d') . 'T19:30';
    $first_at = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $candidate_first, wp_timezone());
    $conflict_at = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $candidate_conflict, wp_timezone());
    if (!$first_at || !$conflict_at) continue;
    if (function_exists('roxy_eb_is_slot_available')
      && (!roxy_eb_is_slot_available($first_at->modify('-2 hours'), $first_at->modify('+2 hours'), 0, true, $screenings)
        || !roxy_eb_is_slot_available($conflict_at->modify('-2 hours'), $conflict_at->modify('+2 hours'), 0, true, $screenings))) continue;
    $first_new_start = $candidate_first;
    $conflict_start = $candidate_conflict;
  }
  if ($first_new_start === '') throw new RuntimeException('Could not find conflict-free future slots for atomic-save test.');
  $conflict_id = wp_insert_post(['post_type'=>\RoxyST\CPT::POST_TYPE,'post_status'=>'draft','post_title'=>'PRIVATE EVENT CONFLICT FIXTURE'], true);
  if (is_wp_error($conflict_id) || !$conflict_id) throw new RuntimeException('Could not create room-conflict fixture.');
  $conflict_id = (int)$conflict_id;
  $created[] = $conflict_id;
  update_post_meta($conflict_id, '_roxy_start', $conflict_start);
  update_post_meta($conflict_id, '_roxy_pricing_profile', 'movie_evening');
  $original_screening_rows = [];
  foreach ($screenings as $id) $original_screening_rows[] = [
    'id'=>$id,
    'title'=>get_the_title($id),
    'start'=>(string)get_post_meta($id, '_roxy_start', true),
    'profile'=>(string)get_post_meta($id, '_roxy_pricing_profile', true),
    'duration'=>(string)get_post_meta($id, '_roxy_duration_minutes', true),
  ];
  $conflict_post = [
    'roxy_st_event_nonce'=>wp_create_nonce('roxy_st_event_save'),
    'roxy_event_format'=>'marathon',
    'roxy_event_double_general'=>'99',
    'roxy_event_double_discount'=>'14',
    'roxy_event_full_pass'=>'45',
    'roxy_event_screening_id'=>array_column($original_screening_rows, 'id'),
    'roxy_event_screening_title'=>array_column($original_screening_rows, 'title'),
    'roxy_event_screening_start'=>array_column($original_screening_rows, 'start'),
    'roxy_event_screening_profile'=>array_column($original_screening_rows, 'profile'),
    'roxy_event_screening_duration'=>array_column($original_screening_rows, 'duration'),
  ];
  $conflict_post['roxy_event_screening_title'][0] = 'CHANGED FIRST SCREENING';
  $conflict_post['roxy_event_screening_title'][1] = 'CHANGED CONFLICTING SCREENING';
  $conflict_post['roxy_event_screening_start'][1] = $conflict_start;
  $conflict_post['roxy_event_screening_id'][] = 0;
  $conflict_post['roxy_event_screening_title'][] = 'PRIVATE ROLLBACK SCREENING';
  $conflict_post['roxy_event_screening_start'][] = $first_new_start;
  $conflict_post['roxy_event_screening_profile'][] = 'movie_evening';
  $conflict_post['roxy_event_screening_duration'][] = '120';
  $_POST = $conflict_post;
  \RoxyST\Events::save((int)$event_id, get_post((int)$event_id));
  $check((string)get_post_meta((int)$event_id, '_roxy_event_double_general', true) === $original_general,
    'later room conflict rolls back the pass-price change');
  $all_original = true;
  foreach ($original_screening_rows as $row) {
    if (get_the_title($row['id']) !== $row['title']
      || (string)get_post_meta($row['id'], '_roxy_start', true) !== $row['start']
      || (string)get_post_meta($row['id'], '_roxy_pricing_profile', true) !== $row['profile']
      || (string)get_post_meta($row['id'], '_roxy_duration_minutes', true) !== $row['duration']) $all_original = false;
  }
  $check($all_original, 'later room conflict rolls back all earlier screening edits');
  $check(array_map('intval', (array)get_post_meta((int)$event_id, \RoxyST\Events::META_SHOWINGS, true)) === array_map('intval', $screenings),
    'later room conflict leaves the event linked-screening list unchanged');
  $orphan = (int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title=%s AND post_type=%s LIMIT 1", 'PRIVATE ROLLBACK SCREENING', \RoxyST\CPT::POST_TYPE));
  $check($orphan === 0, 'later room conflict rolls back a screening created earlier in the same save');
  delete_transient('roxy_st_event_invalid_' . get_current_user_id());

  $product_ids_during_publication = [];
  $publication_html = '';
  $publication_probe = static function($screening_id) use (&$product_ids_during_publication, &$publication_html, $event_id): void {
    $product_ids_during_publication[] = (int)get_post_meta((int)$screening_id, '_roxy_pid_adult', true);
    if ($publication_html === '') $publication_html = \RoxyST\Events::render((int)$event_id);
  };
  $check(count(array_filter($screenings, static fn($id) => get_post_status((int)$id) === 'draft')) === 6,
    'all linked screenings are drafts immediately before parent publication');
  $check(count(array_filter($screenings, static fn($id) => (int)get_post_meta((int)$id, '_roxy_pid_adult', true) > 0)) === 0,
    'no adult ticket products exist before parent publication');
  $_POST = $old_post;
  add_action('save_post_' . \RoxyST\CPT::POST_TYPE, $publication_probe, 21, 2);
  wp_update_post(['ID'=>(int)$event_id,'post_status'=>'publish']);
  remove_action('save_post_' . \RoxyST\CPT::POST_TYPE, $publication_probe, 21);
  $check(count($product_ids_during_publication)===6,
    'publication transaction updates all six screening posts (observed ' . count($product_ids_during_publication) . '; parent=' . get_post_status((int)$event_id) . '; notice=' . (string)get_transient('roxy_st_event_invalid_' . get_current_user_id()) . ')');
  $products_created_before_commit = array_values(array_filter($product_ids_during_publication));
  $check(!$products_created_before_commit,
    'ticket products are not synchronized from screening hooks before the publication transaction commits (observed IDs: ' . implode(',', $products_created_before_commit) . ')');
  $sold_out_label = str_contains($publication_html, 'Sold out or sales ended');
  $button_tags = [];
  preg_match_all('/<button\b[^>]*>/i', $publication_html, $button_matches);
  $button_tags = $button_matches[0] ?? [];
  $disabled_button = (bool)array_filter($button_tags, static fn($tag) => preg_match('/\sdisabled(?:\s|=|>)/i', $tag) === 1);
  $check($sold_out_label && $disabled_button,
    'event ticket buttons remain disabled until committed screenings have published ticket products (html=' . strlen($publication_html) . ', sold_out=' . (int)$sold_out_label . ', disabled=' . (int)$disabled_button . ', buttons=' . implode(' | ', $button_tags) . ')');
  $check(count(array_filter($screenings,static fn($id)=>get_post_status($id)==='publish'))===6,'publishing event publishes all six linked showings');
  $check(count(array_filter($screenings,static fn($id)=>(int)get_post_meta($id,'_roxy_capacity',true)===250))===6,'each screening inherits the configured 250-seat capacity');
  $products=[];
  foreach ($screenings as $id) {
    $pid=(int)get_post_meta($id,'_roxy_pid_adult',true);
    if (!$pid || get_post_type($pid)!=='product') throw new RuntimeException('A screening ticket product was not created');
    $products[]=$pid;
    $check(\RoxyST\Eligibility::product_error($pid)===null,'screening ticket product uses normal public ticket eligibility');
  }
  $stable_product_title = get_the_title($products[0]);
  $product_title_during_failed_save = null;
  $product_probe = static function($screening_id, $screening) use ($screenings, $products, &$product_title_during_failed_save): void {
    if ((int)$screening_id === (int)$screenings[0]) $product_title_during_failed_save = get_the_title((int)$products[0]);
  };
  add_action('save_post_' . \RoxyST\CPT::POST_TYPE, $product_probe, 21, 2);
  $published_conflict_post = [
    'roxy_st_event_nonce'=>wp_create_nonce('roxy_st_event_save'),
    'roxy_event_format'=>'marathon',
    'roxy_event_double_general'=>'99',
    'roxy_event_double_discount'=>'14',
    'roxy_event_full_pass'=>'45',
    'roxy_event_screening_id'=>array_column($original_screening_rows, 'id'),
    'roxy_event_screening_title'=>array_column($original_screening_rows, 'title'),
    'roxy_event_screening_start'=>array_column($original_screening_rows, 'start'),
    'roxy_event_screening_profile'=>array_column($original_screening_rows, 'profile'),
    'roxy_event_screening_duration'=>array_column($original_screening_rows, 'duration'),
  ];
  $published_conflict_post['roxy_event_screening_title'][0] = 'PRIVATE FAILED PRODUCT SYNC';
  $published_conflict_post['roxy_event_screening_start'][1] = $conflict_start;
  $_POST = $published_conflict_post;
  \RoxyST\Events::save((int)$event_id, get_post((int)$event_id));
  remove_action('save_post_' . \RoxyST\CPT::POST_TYPE, $product_probe, 21);
  $check($product_title_during_failed_save === $stable_product_title,
    'ticket product synchronization is deferred until the event schedule transaction commits');
  $check(get_the_title($products[0]) === $stable_product_title,
    'failed published schedule edit does not alter a ticket product');
  delete_transient('roxy_st_event_invalid_' . get_current_user_id());
  $committed_title_post = $published_conflict_post;
  $committed_title_post['roxy_event_screening_title'] = array_column($original_screening_rows, 'title');
  $committed_title_post['roxy_event_screening_start'] = array_column($original_screening_rows, 'start');
  $committed_title_post['roxy_event_screening_title'][0] = 'PRIVATE COMMITTED SCREENING TITLE';
  $_POST = $committed_title_post;
  \RoxyST\Events::save((int)$event_id, get_post((int)$event_id));
  $check(str_contains(get_the_title($products[0]), 'PRIVATE COMMITTED SCREENING TITLE'),
    'successful schedule transaction synchronizes the ticket product after commit');
  $committed_title_post['roxy_event_screening_title'][0] = $original_screening_rows[0]['title'];
  $_POST = $committed_title_post;
  \RoxyST\Events::save((int)$event_id, get_post((int)$event_id));
  $check(get_the_title($products[0]) === $stable_product_title,
    'restoring the original screening title synchronizes its ticket product after commit');
  wp_update_post(['ID'=>(int)$event_id,'post_status'=>'draft']);
  $check(count(array_filter($screenings,static fn($id)=>get_post_status($id)==='draft'))===6,
    'drafting the event deactivates all linked screenings');
  wp_update_post(['ID'=>(int)$event_id,'post_status'=>'publish']);
  $check(count(array_filter($screenings,static fn($id)=>get_post_status($id)==='publish'))===6,
    'republishing in the same request republishes all linked screenings');

  $event_html=\RoxyST\Events::render((int)$event_id);
  $check(substr_count($event_html,'<td>PRIVATE FILM ')===6,'event page presents all six films in one schedule');
  $check(strpos($event_html,'All-screenings pass — 6 films')!==false && strpos($event_html,wc_format_localized_price(45.0))!==false,'event page offers the configured $45 all-screenings pass');
  $check(strpos($event_html,'Double feature')!==false && strpos($event_html,wc_format_localized_price(14.0))!==false,'event page offers the configured general and discount double feature');
  $public_events=\RoxyST\Events::eligible_public_events(current_datetime()->getTimestamp(),current_datetime()->modify('+180 days')->getTimestamp());
  $check(count(array_filter($public_events,static fn($post)=>(int)$post->ID===(int)$event_id))===1,'published event is selected as one public listing item');
  $listing=\RoxyST\Frontend::shortcode_showings(['limit'=>500,'days'=>180,'request_showings_url'=>'']);
  $check(substr_count($listing,'PRIVATE MULTI EVENT FIXTURE')===1 && strpos($listing,'PRIVATE FILM A')===false,'regular showings list displays one event card and hides its individual screenings');
  $day_labels=[];
  foreach ($starts as $start) {
    $timestamp=\RoxyST\Eligibility::local_datetime_timestamp($start);
    $day_labels[wp_date('Y-m-d',$timestamp)]=wp_date('D, M j',$timestamp);
  }
  $day_labels=array_values($day_labels);
  $day_labels[count($day_labels)-1].=', '.wp_date('Y',\RoxyST\Eligibility::local_datetime_timestamp($starts[0]));
  $check(strpos($listing,implode(' · ',$day_labels))!==false,'event card lists every distinct screening date');

  $cart_contents=[];
  $price_method=new ReflectionMethod(\RoxyST\Events::class,'allocated_prices');
  $price_method->setAccessible(true);
  $unit_prices=$price_method->invoke(null,45.0,6);
  $check(array_sum($unit_prices)===45.0,'all-screenings package allocation sums exactly to the advertised price');
  $bundle_id=wp_generate_uuid4();$expected_ids=$screenings;
  $signature=wp_hash(wp_json_encode([(int)$event_id,'full',$expected_ids,'adult',$unit_prices,$bundle_id]),'auth');
  $cart_keys=[];
  $double_prices=$price_method->invoke(null,20.0,2);
  $double_bundle=wp_generate_uuid4();$double_ids=array_slice($screenings,0,2);
  $double_signature=wp_hash(wp_json_encode([(int)$event_id,'double',$double_ids,'adult',$double_prices,$double_bundle]),'auth');
  WC()->cart->empty_cart();
  foreach($double_ids as $i=>$sid) {
    $pid=(int)get_post_meta($sid,'_roxy_pid_adult',true);
    $data=['roxy_event_bundle_id'=>$double_bundle,'roxy_event_bundle_label'=>'Double feature','roxy_event_id'=>(int)$event_id,'roxy_event_bundle_kind'=>'double','roxy_event_bundle_showing_ids'=>$double_ids,'roxy_event_ticket_type'=>'adult','roxy_event_bundle_signature'=>$double_signature,'roxy_event_unit_price'=>$double_prices[$i]];
    if(!WC()->cart->add_to_cart($pid,1,0,[],$data))throw new RuntimeException('Double-feature pass could not be added to the WooCommerce cart');
  }
  WC()->cart->calculate_totals();
  wc_clear_notices();
  \RoxyST\Events::validate_cart_bundles();
  $check(round((float)WC()->cart->get_total('edit'),2)===20.0 && !wc_has_notice('An event pass is incomplete or no longer matches the event. Remove all items in that pass and add it again.','error'),'valid $20 double-feature pass accepts integer-allocated line prices');

  WC()->cart->empty_cart();
  foreach ($products as $i=>$pid) {
    $product=wc_get_product($pid);
    $cart_contents['fixture-'.$i]=['product_id'=>$pid,'quantity'=>1,'data'=>clone $product,'roxy_event_bundle_id'=>'fixture-pass','roxy_event_unit_price'=>7.5];
  }
  $cart=new class($cart_contents) { public array $cart_contents; public function __construct(array $items){$this->cart_contents=$items;} public function get_cart(): array { return $this->cart_contents; } };
  \RoxyST\CartPricing::reprice($cart);
  $allocated=0.0;
  foreach ($cart->cart_contents as $item) $allocated+=(float)$item['data']->get_price();
  $check(round($allocated,2)===45.0,'six ordinary ticket lines total exactly $45 without a price-change review');

  WC()->cart->empty_cart();
  foreach($products as $i=>$pid) {
    $data=['roxy_event_bundle_id'=>$bundle_id,'roxy_event_bundle_label'=>'All-screenings pass','roxy_event_id'=>(int)$event_id,'roxy_event_bundle_kind'=>'full','roxy_event_bundle_showing_ids'=>$expected_ids,'roxy_event_ticket_type'=>'adult','roxy_event_bundle_signature'=>$signature,'roxy_event_unit_price'=>$unit_prices[$i]];
    $key=WC()->cart->add_to_cart($pid,1,0,[],$data);
    if(!$key)throw new RuntimeException('Complete event pass could not be added to the WooCommerce cart');
    $cart_keys[]=$key;
  }
  WC()->cart->calculate_totals();
  \RoxyST\Events::validate_cart_bundles();
  $check(round((float)WC()->cart->get_total('edit'),2)===45.0 && !wc_has_notice('An event pass is incomplete or no longer matches the event. Remove all items in that pass and add it again.','error'),'valid $45 pass remains complete and correctly priced in the real WooCommerce cart');
  WC()->cart->remove_cart_item($cart_keys[0]);
  \RoxyST\Events::validate_cart_bundles();
  $check(wc_has_notice('An event pass is incomplete or no longer matches the event. Remove all items in that pass and add it again.','error'),'removing one screening from a multi-show pass blocks checkout');

  WC()->cart->empty_cart();
  wc_clear_notices();
  $add_bundle=static function(string $kind,array $ids,string $type,array $prices,string $label) use($event_id): void {
    $bundle=wp_generate_uuid4();
    $signature=wp_hash(wp_json_encode([(int)$event_id,$kind,$ids,$type,$prices,$bundle]),'auth');
    foreach($ids as $i=>$sid) {
      $line_type=$type;
      $pid=(int)get_post_meta((int)$sid,'_roxy_pid_'.$line_type,true);
      $data=['roxy_event_bundle_id'=>$bundle,'roxy_event_bundle_label'=>$label,'roxy_event_id'=>(int)$event_id,'roxy_event_bundle_kind'=>$kind,'roxy_event_bundle_showing_ids'=>$ids,'roxy_event_ticket_type'=>$type,'roxy_event_bundle_signature'=>$signature];
      if(isset($prices[$i]))$data['roxy_event_unit_price']=$prices[$i];
      if(!WC()->cart->add_to_cart($pid,1,0,[],$data))throw new RuntimeException('A mixed event ticket combination could not be added to the WooCommerce cart');
    }
  };
  $wed_double_ids=array_slice($screenings,2,2);
  $wed_double_prices=$price_method->invoke(null,20.0,2);
  $full_prices=$price_method->invoke(null,45.0,6);
  $add_bundle('double',$wed_double_ids,'adult',$wed_double_prices,'Double feature');
  $add_bundle('full',$screenings,'adult',$full_prices,'All-screenings pass');
  $add_bundle('single',[(int)$screenings[2]],'adult',[],'Individual screening');
  $add_bundle('full',$screenings,'adult',$full_prices,'All-screenings pass');
  WC()->cart->calculate_totals();
  \RoxyST\Events::validate_cart_bundles();
  $check(count(WC()->cart->get_cart())===15 && !wc_has_notice('An event pass is incomplete or no longer matches the event. Remove all items in that pass and add it again.','error'),'overlapping full passes, double features, and an individual ticket coexist as distinct cart lines');
  WC()->cart->empty_cart();
  wc_clear_notices();

  wp_update_post(['ID'=>(int)$event_id,'post_status'=>'draft']);
  if (get_post_status((int)$event_id) !== 'draft') throw new RuntimeException('The event could not be returned to draft for publication-conflict test.');

  $block_publication = static function($check, $postarr) use ($screenings) {
    if ((int)($postarr['ID'] ?? 0) === (int)$screenings[1] && ($postarr['post_status'] ?? '') === 'publish') return false;
    return $check;
  };
  add_filter('pre_wp_update_post', $block_publication, 10, 2);
  wp_update_post(['ID'=>(int)$event_id,'post_status'=>'publish']);
  remove_filter('pre_wp_update_post', $block_publication, 10);
  $check(get_post_status((int)$event_id) === 'draft'
    && count(array_filter($screenings,static fn($id)=>get_post_status($id)==='publish'))===0,
    'failure on a later screening rolls back the earlier publication and returns the event to draft');
  delete_transient('roxy_st_event_invalid_' . get_current_user_id());

  $blocked_start = (string)get_post_meta((int)$screenings[0], '_roxy_start', true);
  wp_update_post(['ID'=>(int)$screenings[0],'post_status'=>'publish']);
  $publish_conflict = wp_insert_post(['post_type'=>\RoxyST\CPT::POST_TYPE,'post_status'=>'draft','post_title'=>'PRIVATE EVENT PUBLISH CONFLICT FIXTURE'], true);
  if (is_wp_error($publish_conflict) || !$publish_conflict) throw new RuntimeException('Could not create publication-conflict fixture.');
  $publish_conflict = (int)$publish_conflict;
  $created[] = $publish_conflict;
  update_post_meta($publish_conflict, '_roxy_start', $blocked_start);
  $block_demotion = static function($check, $postarr) use ($screenings) {
    if ((int)($postarr['ID'] ?? 0) === (int)$screenings[0] && ($postarr['post_status'] ?? '') === 'draft') return false;
    return $check;
  };
  add_filter('pre_wp_update_post', $block_demotion, 10, 2);
  $published_cache = new \ReflectionProperty(\RoxyST\Events::class, 'published_in_request');
  $published_cache->setAccessible(true);
  $published_ids = $published_cache->getValue();
  unset($published_ids[(int)$event_id]);
  $published_cache->setValue(null, $published_ids);
  wp_update_post(['ID'=>(int)$event_id,'post_status'=>'publish']);
  remove_filter('pre_wp_update_post', $block_demotion, 10);
  $check(get_post_status((int)$event_id) === 'draft', 'publication conflict returns the parent event to draft');
  $check(get_post_status((int)$screenings[0]) === 'publish'
    && str_contains((string)get_transient('roxy_st_event_invalid_' . get_current_user_id()), 'could not be fully returned to draft'),
    'failed screening demotion is detected and reported instead of claiming complete cleanup');
  wp_update_post(['ID'=>(int)$screenings[0],'post_status'=>'draft']);
  $check(count(array_filter($screenings,static fn($id)=>get_post_status($id)==='publish'))===0,
    'after the injected cleanup failure, all fixture screenings can be manually returned to draft');
  delete_transient('roxy_st_event_invalid_' . get_current_user_id());

  wp_update_post(['ID'=>(int)$screenings[0],'post_status'=>'publish']);
  $block_delete_cleanup = static function($check, $postarr) use ($screenings) {
    if ((int)($postarr['ID'] ?? 0) === (int)$screenings[0] && ($postarr['post_status'] ?? '') === 'draft') return false;
    return $check;
  };
  add_filter('pre_wp_update_post', $block_delete_cleanup, 10, 2);
  $deleted_event = wp_delete_post((int)$event_id, true);
  remove_filter('pre_wp_update_post', $block_delete_cleanup, 10);
  $check($deleted_event === false && get_post((int)$event_id) !== null
    && get_post_status((int)$screenings[0]) === 'publish'
    && str_contains((string)get_transient('roxy_st_event_invalid_' . get_current_user_id()), 'Event deletion was stopped'),
    'event deletion is blocked and reported if a linked screening cannot be deactivated');
  wp_update_post(['ID'=>(int)$screenings[0],'post_status'=>'draft']);
  delete_transient('roxy_st_event_invalid_' . get_current_user_id());
} finally {
  foreach ($created as $id) {
    if (get_post_type($id)===\RoxyST\CPT::POST_TYPE) {
      foreach (['adult','discount','matinee','subscriber'] as $type) {
        $pid=(int)get_post_meta($id,'_roxy_pid_'.$type,true);
        if ($pid) wp_delete_post($pid,true);
      }
    }
    wp_delete_post((int)$id,true);
  }
  if ($event_id) delete_post_meta((int)$event_id,\RoxyST\Events::META_SHOWINGS);
  $_POST=$old_post;
  wp_set_current_user($old_user);
}
