<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

/** Multi-day event pages backed by ordinary, independently-scannable showings. */
final class Events {
  const POST_TYPE = 'roxy_st_event';
  const META_SHOWINGS = '_roxy_event_showings';
  private static array $published_in_request = [];

  public static function init(): void {
    add_action('init', [__CLASS__, 'register']);
    add_action('add_meta_boxes', [__CLASS__, 'metabox']);
    add_action('save_post_' . self::POST_TYPE, [__CLASS__, 'save'], 20, 2);
    add_action('transition_post_status', [__CLASS__, 'event_status_changed'], 20, 3);
    add_filter('pre_delete_post', [__CLASS__, 'guard_event_delete'], PHP_INT_MAX, 3);
    add_action('save_post_' . CPT::POST_TYPE, [__CLASS__, 'refresh_event_date'], 30, 2);
    add_action('admin_notices', [__CLASS__, 'admin_notice']);
    add_action('admin_post_roxy_st_event_save_schedule', [__CLASS__, 'handle_admin_save_schedule']);
    add_shortcode('roxy_event', [__CLASS__, 'shortcode']);
    add_action('admin_post_nopriv_roxy_event_add', [__CLASS__, 'handle_add']);
    add_action('admin_post_roxy_event_add', [__CLASS__, 'handle_add']);
    add_filter('woocommerce_get_item_data', [__CLASS__, 'cart_item_data'], 20, 2);
    add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'order_item_meta'], 20, 4);
    add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_cart_bundles'], 9998);
    add_action('woocommerce_checkout_process', [__CLASS__, 'validate_cart_bundles'], 9998);
    add_action('template_redirect', [__CLASS__, 'redirect_screening'], 1);
    add_action('pre_get_posts', [__CLASS__, 'hide_event_screenings_from_archive']);
  }

  public static function register(): void {
    register_post_type(self::POST_TYPE, [
      'labels' => ['name' => 'Multi-day Events', 'singular_name' => 'Multi-day Event', 'add_new_item' => 'Add New Multi-day Event', 'edit_item' => 'Edit Multi-day Event'],
      'public' => true, 'has_archive' => 'roxy-events', 'rewrite' => ['slug' => 'roxy-events'],
      'supports' => ['title', 'editor', 'thumbnail', 'excerpt'], 'show_in_rest' => true,
      'menu_icon' => 'dashicons-calendar-alt', 'show_in_menu' => 'edit.php?post_type=' . CPT::POST_TYPE,
    ]);
  }

  public static function metabox(): void {
    add_meta_box('roxy_st_event_details', 'Event Schedule and Passes', [__CLASS__, 'render_metabox'], self::POST_TYPE, 'normal', 'high');
  }

  public static function render_metabox($post): void {
    wp_nonce_field('roxy_st_event_save', 'roxy_st_event_nonce');
    $showings = self::schedule_rows((int)$post->ID);
    $restore_key = 'roxy_st_event_restore_' . get_current_user_id() . '_' . (int)$post->ID;
    $restore = get_transient($restore_key);
    if (is_array($restore) && isset($restore['titles'], $restore['starts'], $restore['profiles'], $restore['durations'])) {
      $restored_rows = [];
      foreach ($restore['titles'] as $i => $title) $restored_rows[] = ['id'=>(int)($restore['ids'][$i] ?? ($showings[$i]['id'] ?? 0)), 'title'=>$title, 'start'=>$restore['starts'][$i] ?? '', 'profile'=>$restore['profiles'][$i] ?? 'movie_evening', 'duration'=>$restore['durations'][$i] ?? ''];
      if ($restored_rows) $showings = $restored_rows;
      echo '<div class="notice notice-error inline"><p>The last save did not complete. Your entered screening details have been restored below. Review the specific error above before retrying.</p></div>';
      delete_transient($restore_key);
    }
    echo '<p>Each screening is stored as a normal showing so capacity, check-in, and refunds stay tied to that specific performance. The public event page groups them together.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Film / screening name</th><th>Local date and time</th><th>Ticket profile</th><th>Duration (minutes)</th><th>Action</th></tr></thead><tbody id="roxy-event-screenings">';
    $rows = $showings ?: [['title'=>'','start'=>'','profile'=>'movie_evening','duration'=>'']];
    foreach ($rows as $row) {
      $screening_id = (int)($row['id'] ?? 0);
      $row_locked = $screening_id > 0 && self::screening_has_reservations($screening_id);
      echo '<tr class="roxy-event-screening-row"><td><input type="hidden" name="roxy_event_screening_id[]" value="' . $screening_id . '"><input class="widefat" name="roxy_event_screening_title[]" value="' . esc_attr($row['title']) . '" ' . ($row_locked ? 'readonly' : '') . '></td>';
      echo '<td><input type="datetime-local" name="roxy_event_screening_start[]" value="' . esc_attr($row['start']) . '" ' . ($row_locked ? 'readonly' : '') . '></td>';
      echo '<td><select name="roxy_event_screening_profile[]" ' . disabled($row_locked, true, false) . '><option value="movie_evening" ' . selected($row['profile'], 'movie_evening', false) . '>Evening movie</option><option value="movie_matinee" ' . selected($row['profile'], 'movie_matinee', false) . '>Matinee</option></select>' . ($row_locked ? '<input type="hidden" name="roxy_event_screening_profile[]" value="' . esc_attr($row['profile']) . '">' : '') . '</td>';
      echo '<td><input type="number" min="1" max="10080" name="roxy_event_screening_duration[]" value="' . esc_attr($row['duration']) . '" ' . ($row_locked ? 'readonly' : '') . '></td>';
      echo '<td>' . ($row_locked ? '<span class="description">Protected</span>' : '<button type="button" class="button-link-delete roxy-event-remove-screening" onclick="this.closest(&quot;.roxy-event-screening-row&quot;).remove()">Remove</button>') . '</td></tr>';
      if ($row_locked) echo '<tr><td colspan="5"><span class="description">This screening has ticket reservations or recorded sales, so its film, date, time, ticket type, and duration are protected.</span></td></tr>';
    }
    echo '</tbody></table>';
    echo '<p><button type="button" class="button" id="roxy-event-add-screening">Add screening</button> <span class="description">Use Save Schedule &amp; Passes below to save these rows. For a published event, new screenings are published after the room schedule is checked. Event capacity is the existing default seat capacity for each screening.</span></p>';
    echo '<p><label>Nightly double feature price (general) $ <input type="number" min="0" step="0.01" name="roxy_event_double_general" value="' . esc_attr((string)get_post_meta($post->ID, '_roxy_event_double_general', true) ?: '20') . '"></label> &nbsp; <label>Discount $ <input type="number" min="0" step="0.01" name="roxy_event_double_discount" value="' . esc_attr((string)get_post_meta($post->ID, '_roxy_event_double_discount', true) ?: '14') . '"></label></p>';
    echo '<p><label>All-screenings pass $ <input type="number" min="0" step="0.01" name="roxy_event_full_pass" value="' . esc_attr((string)get_post_meta($post->ID, '_roxy_event_full_pass', true) ?: '45') . '"></label></p>';
    echo '<p>On the public event page, visitors can select an individual screening, a same-day double feature when there are exactly two screenings that day, or an all-screenings pass. A logged-in active subscriber can reserve one free admission to each screening as a pass.</p>';
    echo '<p><button type="button" class="button button-primary" id="roxy-event-save-schedule">Save Schedule &amp; Passes</button> <span class="description">This saves the screening rows and ticket prices independently of the event copy. Use the editor’s Update button separately for title and description changes.</span></p>';
    echo '<script>(function(){';
    echo 'var add=document.getElementById("roxy-event-add-screening"),save=document.getElementById("roxy-event-save-schedule"),table=document.getElementById("roxy-event-screenings"),box=document.getElementById("roxy_st_event_details");';
    echo 'if(add&&table)add.addEventListener("click",function(){var row=document.createElement("tr");row.className="roxy-event-screening-row";row.innerHTML="<td><input type=hidden name=roxy_event_screening_id[] value=0><input class=widefat name=roxy_event_screening_title[]></td><td><input type=datetime-local name=roxy_event_screening_start[]></td><td><select name=roxy_event_screening_profile[]><option value=movie_evening>Evening movie</option><option value=movie_matinee>Matinee</option></select></td><td><input type=number min=1 max=10080 name=roxy_event_screening_duration[]></td><td></td>";var remove=document.createElement("button");remove.type="button";remove.className="button-link-delete roxy-event-remove-screening";remove.textContent="Remove";remove.onclick=function(){var item=this.closest(".roxy-event-screening-row");if(item)item.remove();};row.lastElementChild.appendChild(remove);table.appendChild(row);});';
    echo 'if(save)save.addEventListener("click",function(){var form=document.createElement("form"),formId="roxy-event-schedule-submit";form.id=formId;form.method="post";form.action=' . wp_json_encode(admin_url('admin-post.php')) . ';var fields={action:"roxy_st_event_save_schedule",roxy_event_id:' . (int)$post->ID . ',roxy_st_event_nonce:(document.querySelector("#roxy_st_event_details [name=roxy_st_event_nonce]")||{}).value||""};Object.keys(fields).forEach(function(name){var input=document.createElement("input");input.type="hidden";input.name=name;input.value=fields[name];form.appendChild(input);});var titles=box?box.querySelectorAll("[name=\"roxy_event_screening_title[]\"]"):[];if(!titles.length){alert("Add at least one screening before saving.");return;}for(var i=0;i<titles.length;i++){var row=titles[i].closest("tr"),start=row&&row.querySelector("[name=\"roxy_event_screening_start[]\"]");if(!titles[i].value.trim()||!start||!start.value){alert("Every screening needs a film name and date/time. Nothing was saved.");return;}}document.body.appendChild(form);if(box)box.querySelectorAll("input[name],select[name]").forEach(function(source){if(!source.disabled&&source.name.indexOf("roxy_event_")===0)source.setAttribute("form",formId);});form.requestSubmit();});';
    echo '})();</script>';
  }

  public static function handle_admin_save_schedule(): void {
    $event_id = isset($_POST['roxy_event_id']) ? absint($_POST['roxy_event_id']) : 0;
    $nonce = isset($_POST['roxy_st_event_nonce']) ? sanitize_text_field(wp_unslash((string)$_POST['roxy_st_event_nonce'])) : '';
    if (!$event_id || get_post_type($event_id) !== self::POST_TYPE || !wp_verify_nonce($nonce, 'roxy_st_event_save') || !current_user_can('edit_post', $event_id)) {
      wp_die('This event schedule could not be saved. Refresh the page and try again.');
    }

    $user_id = get_current_user_id();
    $restore = [];
    foreach (['ids'=>'roxy_event_screening_id','titles'=>'roxy_event_screening_title','starts'=>'roxy_event_screening_start','profiles'=>'roxy_event_screening_profile','durations'=>'roxy_event_screening_duration'] as $key=>$field) {
      $values = isset($_POST[$field]) && is_array($_POST[$field]) ? $_POST[$field] : [];
      $restore[$key] = array_map(static fn($value) => is_scalar($value) ? sanitize_text_field(wp_unslash((string)$value)) : '', $values);
    }
    delete_transient('roxy_st_event_invalid_' . $user_id);
    delete_transient('roxy_st_event_saved_' . $user_id);
    self::save($event_id, get_post($event_id));
    if (get_transient('roxy_st_event_invalid_' . $user_id)) {
      set_transient('roxy_st_event_restore_' . $user_id . '_' . $event_id, $restore, 30 * MINUTE_IN_SECONDS);
    } else {
      delete_transient('roxy_st_event_restore_' . $user_id . '_' . $event_id);
      set_transient('roxy_st_event_saved_' . $user_id, 1, MINUTE_IN_SECONDS);
    }

    wp_safe_redirect(add_query_arg(['post' => $event_id, 'action' => 'edit'], admin_url('post.php')));
    exit;
  }

  public static function save(int $event_id, $post): void {
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($event_id) || !isset($_POST['roxy_st_event_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_POST['roxy_st_event_nonce'])), 'roxy_st_event_save') || !current_user_can('edit_post', $event_id)) return;
    global $wpdb;
    if (!function_exists('roxy_eb_reservation_run') || !isset($wpdb->posts, $wpdb->postmeta)) {
      set_transient('roxy_st_event_invalid_' . get_current_user_id(), 'The shared room lock is unavailable. No schedule or price changes were saved.', MINUTE_IN_SECONDS);
      return;
    }
    $touched_screenings = isset($_POST['roxy_event_screening_id']) && is_array($_POST['roxy_event_screening_id'])
      ? array_values(array_unique(array_filter(array_map('absint', $_POST['roxy_event_screening_id'])))) : [];
    $deferred_product_hooks = self::defer_product_sync_hooks();
    try {
      $result = roxy_eb_reservation_run(static function () use ($event_id, $post, &$touched_screenings) {
        return self::save_locked($event_id, $post, $touched_screenings);
      }, [$wpdb->posts, $wpdb->postmeta]);
    } finally {
      self::restore_product_sync_hooks($deferred_product_hooks);
    }
    if (is_wp_error($result)) {
      self::clean_event_write_caches($event_id, $touched_screenings);
      set_transient('roxy_st_event_invalid_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS);
      return;
    }
    if ($result !== true) {
      self::clean_event_write_caches($event_id, $touched_screenings);
      set_transient('roxy_st_event_invalid_' . get_current_user_id(), 'The event schedule could not be verified. No changes were saved.', MINUTE_IN_SECONDS);
      return;
    }
    if (class_exists(Products::class) && get_post_status($event_id) !== 'publish') foreach ($touched_screenings as $screening_id) {
      $screening = get_post((int)$screening_id);
      if ($screening) Products::on_showing_saved((int)$screening_id, $screening);
    }
    if (get_post_status($event_id) === 'publish') {
      unset(self::$published_in_request[$event_id]);
      self::publish_screenings($event_id);
    }
  }

  private static function clean_event_write_caches(int $event_id, array $screening_ids): void {
    clean_post_cache($event_id);
    foreach ($screening_ids as $screening_id) clean_post_cache((int)$screening_id);
  }

  private static function defer_product_sync_hooks(): array {
    if (!class_exists(Products::class)) return [];
    $hooks = [
      ['hook'=>'save_post_' . CPT::POST_TYPE, 'callback'=>[Products::class, 'on_showing_saved'], 'args'=>2],
      ['hook'=>'transition_post_status', 'callback'=>[Products::class, 'on_status_changed'], 'args'=>3],
    ];
    $removed = [];
    foreach ($hooks as $entry) {
      $priority = has_action($entry['hook'], $entry['callback']);
      if ($priority === false) continue;
      remove_action($entry['hook'], $entry['callback'], (int)$priority);
      $entry['priority'] = (int)$priority;
      $removed[] = $entry;
    }
    return $removed;
  }

  private static function restore_product_sync_hooks(array $hooks): void {
    foreach ($hooks as $entry) add_action($entry['hook'], $entry['callback'], $entry['priority'], $entry['args']);
  }

  private static function save_locked(int $event_id, $post, array &$touched_screenings) {
    $format = isset($_POST['roxy_event_format']) ? sanitize_key(wp_unslash((string)$_POST['roxy_event_format'])) : (get_post_meta($event_id, '_roxy_event_format', true) ?: 'multi_day');
    if (!in_array($format, ['multi_day','marathon'], true)) return new \WP_Error('event_format_invalid', 'Select a valid event format. Nothing was saved.');
    foreach (['double_general'=>20, 'double_discount'=>14, 'full_pass'=>45] as $key=>$default) {
      $value = isset($_POST['roxy_event_' . $key]) && is_scalar($_POST['roxy_event_' . $key]) ? trim((string)wp_unslash($_POST['roxy_event_' . $key])) : (string)$default;
      if (!is_numeric($value) || (float)$value < 0 || (float)$value > 100000) return new \WP_Error('event_price_invalid', 'Enter valid non-negative event pass prices. Nothing was saved.');
      $prices[$key] = wc_format_decimal($value, 2);
    }
    foreach (['roxy_event_screening_id', 'roxy_event_screening_title', 'roxy_event_screening_start', 'roxy_event_screening_profile', 'roxy_event_screening_duration'] as $field) {
      if (!isset($_POST[$field]) || !is_array($_POST[$field])) return new \WP_Error('event_rows_missing', 'The screening list is incomplete. Nothing was saved.');
    }

    $titles = isset($_POST['roxy_event_screening_title']) && is_array($_POST['roxy_event_screening_title']) ? array_values($_POST['roxy_event_screening_title']) : [];
    $screening_ids = isset($_POST['roxy_event_screening_id']) && is_array($_POST['roxy_event_screening_id']) ? array_values($_POST['roxy_event_screening_id']) : [];
    $starts = isset($_POST['roxy_event_screening_start']) && is_array($_POST['roxy_event_screening_start']) ? array_values($_POST['roxy_event_screening_start']) : [];
    $profiles = isset($_POST['roxy_event_screening_profile']) && is_array($_POST['roxy_event_screening_profile']) ? array_values($_POST['roxy_event_screening_profile']) : [];
    $durations = isset($_POST['roxy_event_screening_duration']) && is_array($_POST['roxy_event_screening_duration']) ? array_values($_POST['roxy_event_screening_duration']) : [];
    if (count($titles) !== count($screening_ids) || count($titles) !== count($starts) || count($titles) !== count($profiles) || count($titles) !== count($durations) || count($titles) > 32) return new \WP_Error('event_rows_invalid', 'The screening list is incomplete or too large. Nothing was saved.');
    $rows = [];
    foreach ($titles as $i=>$raw_title) {
      $title = sanitize_text_field(is_scalar($raw_title) ? wp_unslash((string)$raw_title) : '');
      $start = sanitize_text_field(is_scalar($starts[$i] ?? null) ? wp_unslash((string)$starts[$i]) : '');
      if ($title === '' && $start === '') continue;
      if ($title === '' || Eligibility::local_datetime_timestamp($start) === null) return new \WP_Error('event_screening_invalid', 'Each screening needs a name and valid local date/time. Nothing was saved.');
      $profile = sanitize_key(is_scalar($profiles[$i] ?? null) ? (string)$profiles[$i] : '');
      if (!in_array($profile, ['movie_evening','movie_matinee'], true)) return new \WP_Error('event_profile_invalid', 'Each screening needs a valid ticket profile. Nothing was saved.');
      $duration = trim(is_scalar($durations[$i] ?? null) ? (string)$durations[$i] : '');
      if ($duration !== '' && (!ctype_digit($duration) || (int)$duration < 1 || (int)$duration > 10080)) return new \WP_Error('event_duration_invalid', 'Screening duration must be a whole number from 1 to 10080 minutes. Nothing was saved.');
      $rows[] = ['id'=>absint($screening_ids[$i]),'title'=>$title,'start'=>$start,'profile'=>$profile,'duration'=>$duration];
    }
    if (!$rows) return new \WP_Error('event_rows_empty', 'Add at least one screening. Nothing was saved.');
    $times = array_column($rows, 'start');
    if (count($times) !== count(array_unique($times))) return new \WP_Error('event_times_duplicate', 'Screenings cannot start at the same time. Nothing was saved.');
    $intervals=[];
    foreach($rows as $row) {
      $start=Eligibility::local_datetime_timestamp($row['start']);
      $duration=$row['duration']===''?120:(int)$row['duration'];
      $intervals[]=['start'=>$start,'end'=>$start+$duration*60,'title'=>$row['title']];
    }
    usort($intervals,static fn($a,$b)=>$a['start']<=>$b['start']);
    for($i=1;$i<count($intervals);$i++) if($intervals[$i]['start']<$intervals[$i-1]['end']) return new \WP_Error('event_times_overlap', 'Screenings cannot overlap. Nothing was saved.');

    $existing = array_values(array_unique(array_filter(array_map('intval', (array)get_post_meta($event_id, self::META_SHOWINGS, true)))));
    $existing_set = array_fill_keys($existing, true);
    $submitted_ids = array_values(array_filter(array_map(static fn($row) => (int)$row['id'], $rows)));
    if (count($submitted_ids) !== count(array_unique($submitted_ids)) || array_diff($submitted_ids, $existing) || array_diff($existing, $submitted_ids)) {
      return new \WP_Error('event_screening_list_changed', 'The screening list changed unexpectedly. Refresh and try again; existing screenings were left untouched.');
    }
    foreach ($rows as $row) {
      $screening_id = (int)$row['id'];
      if ($screening_id && self::screening_has_reservations($screening_id) && ($row['title'] !== get_the_title($screening_id) || $row['start'] !== (string)get_post_meta($screening_id, '_roxy_start', true) || $row['profile'] !== ((string)get_post_meta($screening_id, '_roxy_pricing_profile', true) ?: 'movie_evening') || $row['duration'] !== (string)get_post_meta($screening_id, '_roxy_duration_minutes', true))) {
        return new \WP_Error('event_screening_reserved', 'A screening with ticket reservations or recorded sales cannot be changed. No screening changes were saved.');
      }
    }
    if (!update_post_meta($event_id, '_roxy_event_format', $format)) {
      if ((string)get_post_meta($event_id, '_roxy_event_format', true) !== $format) return new \WP_Error('event_format_write', 'Event format could not be saved. No changes were retained.');
    }
    foreach ($prices as $key=>$value) {
      if (!update_post_meta($event_id, '_roxy_event_' . $key, $value) && (string)get_post_meta($event_id, '_roxy_event_' . $key, true) !== (string)$value) {
        return new \WP_Error('event_price_write', 'Event pass prices could not be saved. No changes were retained.');
      }
    }
    $ids = [];
    foreach ($rows as $i=>$row) {
      $screening_id = (int)$row['id'];
      if ($screening_id && (!isset($existing_set[$screening_id]) || get_post_type($screening_id) !== CPT::POST_TYPE)) {
        return new \WP_Error('event_screening_missing', 'A screening could not be matched to this event. Existing screenings were left untouched.');
      }
      $status = $screening_id ? get_post_status($screening_id) : 'draft';
      if ($screening_id) {
        $update = static function () use ($screening_id, $row, $status) {
          $post_result = wp_update_post(['ID'=>$screening_id,'post_title'=>$row['title'],'post_status'=>$status], true);
          if (is_wp_error($post_result) || !$post_result) return is_wp_error($post_result) ? $post_result : new \WP_Error('event_screening_update', 'The screening could not be updated. No changes were retained.');
          if (get_the_title($screening_id) !== $row['title'] || get_post_status($screening_id) !== $status) return new \WP_Error('event_screening_write', 'A screening title or status change could not be verified. No changes were retained.');
          update_post_meta($screening_id, '_roxy_start', $row['start']);
          update_post_meta($screening_id, '_roxy_pricing_profile', $row['profile']);
          if ($row['duration'] !== '') update_post_meta($screening_id, '_roxy_duration_minutes', (string)(int)$row['duration']);
          else delete_post_meta($screening_id, '_roxy_duration_minutes');
          if ((string)get_post_meta($screening_id, '_roxy_start', true) !== $row['start']
            || (string)get_post_meta($screening_id, '_roxy_pricing_profile', true) !== $row['profile']
            || ((string)get_post_meta($screening_id, '_roxy_duration_minutes', true) !== ($row['duration'] === '' ? '' : (string)(int)$row['duration']))) {
            return new \WP_Error('event_screening_write', 'A screening change could not be verified. No changes were retained.');
          }
          return $screening_id;
        };
        $ignored = self::sibling_screening_ids($event_id);
        $updated = function_exists('roxy_eb_with_showing_time_lock') ? roxy_eb_with_showing_time_lock($row['start'], $ignored, $update) : $update();
        if (is_wp_error($updated) || !$updated) return is_wp_error($updated) ? $updated : new \WP_Error('event_screening_update', 'The screening update was rejected by the room schedule. No changes were retained.');
      } else {
        $screening_id = wp_insert_post(['post_type'=>CPT::POST_TYPE,'post_status'=>'draft','post_title'=>$row['title'],'post_content'=>'','post_author'=>get_current_user_id()], true);
        if (is_wp_error($screening_id) || !$screening_id) {
          return is_wp_error($screening_id) ? $screening_id : new \WP_Error('event_screening_create', 'The screening draft could not be created. No changes were retained.');
        }
        $screening_id = (int)$screening_id;
        $touched_screenings[] = $screening_id;
        $capacity = Settings::get_default_capacity();
        update_post_meta($screening_id, '_roxy_event_id', $event_id);
        update_post_meta($screening_id, '_roxy_capacity', $capacity);
        $create = static function () use ($screening_id, $row, $capacity, $event_id) {
          update_post_meta($screening_id, '_roxy_start', $row['start']);
          update_post_meta($screening_id, '_roxy_pricing_profile', $row['profile']);
          if ($row['duration'] !== '') update_post_meta($screening_id, '_roxy_duration_minutes', (string)(int)$row['duration']);
          if ((int)get_post_meta($screening_id, '_roxy_event_id', true) !== $event_id
            || (int)get_post_meta($screening_id, '_roxy_capacity', true) !== (int)$capacity
            || (string)get_post_meta($screening_id, '_roxy_start', true) !== $row['start']
            || (string)get_post_meta($screening_id, '_roxy_pricing_profile', true) !== $row['profile']
            || ((string)get_post_meta($screening_id, '_roxy_duration_minutes', true) !== ($row['duration'] === '' ? '' : (string)(int)$row['duration']))) {
            return new \WP_Error('event_screening_write', 'A screening field could not be verified. No changes were retained.');
          }
          return $screening_id;
        };
        $ignored = self::sibling_screening_ids($event_id);
        $id = function_exists('roxy_eb_with_showing_time_lock') ? roxy_eb_with_showing_time_lock($row['start'], $ignored, $create) : $create();
        if (is_wp_error($id) || !$id) return is_wp_error($id) ? $id : new \WP_Error('event_screening_create', 'The screening could not be created. No changes were retained.');
        $screening_id = (int)$id;
      }
      $ids[] = $screening_id;
    }
    update_post_meta($event_id, self::META_SHOWINGS, $ids);
    if (array_map('intval', (array)get_post_meta($event_id, self::META_SHOWINGS, true)) !== array_map('intval', $ids)) return new \WP_Error('event_screening_list_write', 'The event screening list could not be verified. No changes were retained.');
    if ($ids) {
      $first_start = min(array_column($rows, 'start'));
      update_post_meta($event_id, '_roxy_event_first_start', $first_start);
      if ((string)get_post_meta($event_id, '_roxy_event_first_start', true) !== $first_start) return new \WP_Error('event_first_start_write', 'The event start date could not be verified. No changes were retained.');
    }
    return true;
  }

  private static function screening_has_reservations(int $screening_id): bool {
    try {
      $reserved = class_exists(__NAMESPACE__ . '\\Reservations') ? Reservations::quantity_for_showing($screening_id) : 0;
      $walkups = class_exists('\\Roxy_Sub_Check') ? (int)\Roxy_Sub_Check::walkup_quantity_for_showing($screening_id) : 0;
      return $reserved > 0 || $walkups > 0;
    } catch (\Throwable $error) {
      return true;
    }
  }

  public static function event_status_changed(string $new_status, string $old_status, $post): void {
    if (!$post || $post->post_type !== self::POST_TYPE || $new_status === $old_status) return;
    if ($new_status==='publish') { self::publish_screenings((int)$post->ID); return; }
    unset(self::$published_in_request[(int)$post->ID]);
    if (!self::demote_screenings((array)get_post_meta((int)$post->ID, self::META_SHOWINGS, true))) {
      set_transient('roxy_st_event_invalid_' . get_current_user_id(), 'The event is a draft, but one or more linked screenings could not be deactivated. Immediately verify the public event and ticket products.', MINUTE_IN_SECONDS);
    }
  }

  public static function guard_event_delete($delete, $post, bool $force_delete) {
    if ($delete !== null || !$post || $post->post_type !== self::POST_TYPE) return $delete;
    $event_id=(int)$post->ID;
    $screening_ids=(array)get_post_meta($event_id,self::META_SHOWINGS,true);
    if(self::demote_screenings($screening_ids)) return null;
    set_transient('roxy_st_event_invalid_' . get_current_user_id(), 'Event deletion was stopped because one or more linked screenings could not be deactivated. Immediately review the event and its ticket products, then retry deletion.', MINUTE_IN_SECONDS);
    return false;
  }

  private static function sibling_screening_ids(int $event_id): array {
    $ids=get_posts(['post_type'=>CPT::POST_TYPE,'post_status'=>['publish','future','draft','private'],'posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'_roxy_event_id','meta_value'=>(string)$event_id]);
    return array_values(array_unique(array_map('intval',is_array($ids)?$ids:[])));
  }

  private static function publish_screenings(int $event_id): bool {
    if (isset(self::$published_in_request[$event_id])) return true;
    $ids=array_values(array_unique(array_map('intval',(array)get_post_meta($event_id,self::META_SHOWINGS,true))));
    if (!$ids) return self::publication_failed($event_id, $ids, 'The event has no linked screenings.');
    global $wpdb;
    if (!function_exists('roxy_eb_reservation_run') || !function_exists('roxy_eb_is_slot_available') || !isset($wpdb->posts, $wpdb->postmeta)) {
      return self::publication_failed($event_id, $ids, 'The shared room lock is unavailable.');
    }
    $slots=[];
    foreach($ids as $id) {
      if(get_post_type($id)!==CPT::POST_TYPE) return self::publication_failed($event_id,$ids,'A linked screening could not be found.');
      $start=(string)get_post_meta($id,'_roxy_start',true);
      $timestamp=Eligibility::local_datetime_timestamp($start);
      if($timestamp===null) return self::publication_failed($event_id,$ids,'A linked screening has an invalid local date or time.');
      $duration=(int)get_post_meta($id,'_roxy_duration_minutes',true);
      $slots[]=['id'=>$id,'start'=>$timestamp,'end'=>$timestamp+($duration>0?$duration:120)*60];
    }
    usort($slots,static fn($a,$b)=>$a['start']<=>$b['start']);
    for($i=1;$i<count($slots);$i++) if($slots[$i]['start']<$slots[$i-1]['end']) {
      return self::publication_failed($event_id,$ids,'Linked screenings overlap and cannot all be published.');
    }
    $deferred_product_hooks=self::defer_product_sync_hooks();
    try {
      $result=roxy_eb_reservation_run(static function()use($ids,$slots) {
        foreach($slots as $slot) {
          $start=(new \DateTimeImmutable('@'.$slot['start']))->setTimezone(wp_timezone());
          if(!roxy_eb_is_slot_available($start->modify('-2 hours'),$start->modify('+2 hours'),0,true,$ids)) {
            return new \WP_Error('event_publish_conflict','A linked screening conflicts with another room reservation.');
          }
        }
        foreach($ids as $id) {
          if(get_post_status($id)==='publish') continue;
          $updated=wp_update_post(['ID'=>$id,'post_status'=>'publish'],true);
          if(is_wp_error($updated)||!$updated||get_post_status($id)!=='publish') {
            return is_wp_error($updated)?$updated:new \WP_Error('event_publish_write','A linked screening could not be published.');
          }
        }
        return true;
      },[$wpdb->posts,$wpdb->postmeta]);
    } finally {
      self::restore_product_sync_hooks($deferred_product_hooks);
    }
    if(is_wp_error($result)||$result!==true) {
      foreach($ids as $id) clean_post_cache($id);
      $message=is_wp_error($result)?$result->get_error_message():'The linked screening publication could not be verified.';
      return self::publication_failed($event_id,$ids,$message);
    }
    foreach($ids as $id) Products::ensure_products_for_showing($id);
    self::$published_in_request[$event_id]=true;
    return true;
  }

  private static function publication_failed(int $event_id, array $screening_ids, string $message): bool {
    unset(self::$published_in_request[$event_id]);
    $screenings_demoted=self::demote_screenings($screening_ids);
    $event_demoted=true;
    if(get_post_status($event_id)==='publish') {
      $updated=wp_update_post(['ID'=>$event_id,'post_status'=>'draft'],true);
      $event_demoted=!is_wp_error($updated)&&$updated&&get_post_status($event_id)==='draft';
    }
    $cleanup_message=$screenings_demoted&&$event_demoted
      ? 'The event and its screenings were returned to draft.'
      : 'The event could not be fully returned to draft. Immediately verify its public page and all linked screenings.';
    set_transient('roxy_st_event_invalid_' . get_current_user_id(), trim($message . ' ' . $cleanup_message), MINUTE_IN_SECONDS);
    return false;
  }

  private static function demote_screenings(array $ids): bool {
    $complete=true;
    foreach($ids as $id) {
      $id=(int)$id;
      if(get_post_type($id)!==CPT::POST_TYPE) {$complete=false;continue;}
      if(get_post_status($id)==='publish') {
        $updated=wp_update_post(['ID'=>$id,'post_status'=>'draft'],true);
        if(is_wp_error($updated)||!$updated||get_post_status($id)!=='draft') $complete=false;
      }
    }
    return $complete;
  }

  private static function schedule_rows(int $event_id): array {
    $rows = [];
    foreach ((array)get_post_meta($event_id, self::META_SHOWINGS, true) as $id) {
      $id = (int)$id;
      if (!$id || get_post_type($id) !== CPT::POST_TYPE) continue;
      $rows[] = ['id'=>$id,'title'=>get_the_title($id),'start'=>(string)get_post_meta($id, '_roxy_start', true),'profile'=>(string)get_post_meta($id, '_roxy_pricing_profile', true) ?: 'movie_evening','duration'=>(string)get_post_meta($id, '_roxy_duration_minutes', true)];
    }
    usort($rows, static fn($a,$b)=>strcmp($a['start'],$b['start']));
    return $rows;
  }

  public static function refresh_event_date(int $showing_id, $post): void {
    if (!$post || wp_is_post_revision($showing_id) || $post->post_type !== CPT::POST_TYPE) return;
    $event_id = (int)get_post_meta($showing_id, '_roxy_event_id', true);
    if ($event_id <= 0 || !get_post_meta($event_id, self::META_SHOWINGS, true)) return;
    $starts=[];
    foreach (self::schedule_rows($event_id) as $row) if (get_post_status($row['id']) === 'publish') $starts[]=$row['start'];
    if ($starts) update_post_meta($event_id, '_roxy_event_first_start', min($starts));
    else delete_post_meta($event_id, '_roxy_event_first_start');
  }

  public static function eligible_public_events(int $from, int $through): array {
    $query = new \WP_Query(['post_type'=>self::POST_TYPE,'post_status'=>'publish','posts_per_page'=>-1,'no_found_rows'=>true,'meta_key'=>'_roxy_event_first_start','orderby'=>'meta_value','order'=>'ASC']);
    $events=[];
    foreach ($query->posts as $event) foreach (self::schedule_rows((int)$event->ID) as $row) {
      $start=Eligibility::showing_start_timestamp((int)$row['id']);
      if ($start!==null && $start<=$through && Eligibility::showing_sales_open((int)$row['id'],$from)) { $events[]=$event; break; }
    }
    return $events;
  }

  public static function shortcode($atts = []): string {
    $atts = shortcode_atts(['id'=>get_the_ID()], (array)$atts, 'roxy_event');
    return self::render((int)$atts['id']);
  }

  public static function append_event_content(string $content): string {
    if (!is_singular(self::POST_TYPE) || !in_the_loop() || !is_main_query()) return $content;
    $event_id = get_the_ID();
    if (strpos($content, '[roxy_event') !== false) return do_shortcode($content);
    return $content . self::render((int)$event_id);
  }

  public static function admin_notice(): void {
    $user_id = get_current_user_id();
    $invalid = get_transient('roxy_st_event_invalid_' . $user_id);
    if ($invalid) {
      delete_transient('roxy_st_event_invalid_' . $user_id);
      $message = is_string($invalid) && $invalid !== '1' ? $invalid : 'Check that every screening has a film name, valid local date/time, supported ticket profile, valid duration, and no conflict with another booking.';
      echo '<div class="notice notice-error"><p>The event schedule was not saved. ' . esc_html($message) . '</p></div>';
      return;
    }
    if (get_transient('roxy_st_event_saved_' . $user_id)) {
      delete_transient('roxy_st_event_saved_' . $user_id);
      echo '<div class="notice notice-success is-dismissible"><p>Event schedule and pass prices saved successfully.</p></div>';
    }
  }

  public static function render(int $event_id): string {
    if (get_post_type($event_id) !== self::POST_TYPE || get_post_status($event_id) !== 'publish') return '';
    $rows = self::schedule_rows($event_id);
    if (!$rows) return '<p>Event schedule coming soon.</p>';
    $packages = self::package_definitions($rows);

    ob_start();
    echo '<div class="roxy-event-page"><style>.roxy-event-table{width:100%;border-collapse:collapse;margin:1.2em 0}.roxy-event-table th,.roxy-event-table td{padding:.65em;border-bottom:1px solid #ddd;text-align:left}.roxy-event-package{display:flex;gap:1em;align-items:center;justify-content:space-between;padding:.85em 0;border-bottom:1px solid #ddd;flex-wrap:wrap}.roxy-event-package form{display:flex;align-items:center;gap:.7em;flex-wrap:wrap}.roxy-event-package input[type=number]{width:5em}.roxy-event-package select{min-width:12em}@media(max-width:600px){.roxy-event-table th:nth-child(2),.roxy-event-table td:nth-child(2){display:none}}</style>';
    echo '<h2>Schedule</h2><table class="roxy-event-table"><thead><tr><th>Film</th><th>Date</th><th>Time</th></tr></thead><tbody>';
    foreach ($rows as $row) echo '<tr><td>' . esc_html(get_the_title($row['id'])) . '</td><td>' . esc_html(wp_date('D, M j, Y', Eligibility::showing_start_timestamp($row['id']) ?: time())) . '</td><td>' . esc_html(wp_date('g:i A', Eligibility::showing_start_timestamp($row['id']) ?: time())) . '</td></tr>';
    echo '</tbody></table><h2>Tickets</h2>';
    foreach ($packages as $index=>$package) {
      $kind = $package['kind'];
      $package_open = true;
      $max_quantity = 10;
      foreach ($package['ids'] as $package_showing) {
        if (!Eligibility::showing_sales_open((int)$package_showing)) $package_open = false;
        if (!self::products_ready_for_showing((int)$package_showing)) $package_open = false;
        $remaining = Capacity::remaining_seats_for_showing((int)$package_showing);
        if (is_int($remaining)) { $max_quantity=min($max_quantity,$remaining); if($remaining<=0)$package_open=false; }
      }
      $general = $kind === 'double' ? (float)get_post_meta($event_id,'_roxy_event_double_general',true) : ($kind === 'full' ? (float)get_post_meta($event_id,'_roxy_event_full_pass',true) : null);
      $discount = $kind === 'double' ? (float)get_post_meta($event_id,'_roxy_event_double_discount',true) : null;
      $discount_available = $kind === 'double';
      foreach ($package['ids'] as $package_showing) if ((string)get_post_meta((int)$package_showing, '_roxy_pricing_profile', true) !== 'movie_evening') $discount_available = false;
      echo '<div class="roxy-event-package"><strong>' . esc_html($package['label']) . '</strong><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      echo '<input type="hidden" name="action" value="roxy_event_add"><input type="hidden" name="event_id" value="' . (int)$event_id . '"><input type="hidden" name="package_index" value="' . (int)$index . '"><input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('roxy_event_add_' . $event_id)) . '">';
      echo '<select name="ticket_type" aria-label="Ticket type">';
      if ($kind === 'single') {
        $sid = (int)$package['ids'][0]; $profile = (string)get_post_meta($sid,'_roxy_pricing_profile',true);
        if ($profile === 'movie_matinee') echo '<option value="matinee">Matinee — $' . esc_html(wc_format_localized_price(Settings::get_price('matinee_price',8))) . '</option>';
        else echo '<option value="adult">General — $' . esc_html(wc_format_localized_price(Settings::get_price('general_price',12))) . '</option><option value="discount">Discount — $' . esc_html(wc_format_localized_price(Settings::get_price('discount_price',8))) . '</option>';
      } elseif ($kind === 'double') {
        echo '<option value="adult">General double — $' . esc_html(wc_format_localized_price($general)) . '</option>';
        if ($discount_available) echo '<option value="discount">Discount double — $' . esc_html(wc_format_localized_price($discount)) . '</option>';
      }
      else echo '<option value="adult">All-screenings pass — $' . esc_html(wc_format_localized_price((float)$general)) . '</option>';
      $subscriber_limit=PHP_INT_MAX;
      if($kind==='full' && is_user_logged_in() && Capacity::subscription_entitlement_count(get_current_user_id())>0) foreach($package['ids'] as $package_showing)$subscriber_limit=min($subscriber_limit,Capacity::remaining_subscriber_seats_for_showing((int)$package_showing,get_current_user_id()));
      if ($subscriber_limit>0 && $subscriber_limit<PHP_INT_MAX) echo '<option value="subscriber">Subscriber reservation — Free</option>';
      $button_label=$package_open?'Add to cart':'Sold out or sales ended';
      echo '</select><input type="number" name="quantity" min="1" max="' . (int)max(1,$max_quantity) . '" value="1" aria-label="Quantity"><button type="submit" ' . disabled(!$package_open, true, false) . '>' . esc_html($button_label) . '</button></form></div>';
    }
    echo '</div>';
    return (string)ob_get_clean();
  }

  public static function handle_add(): void {
    $event_id = isset($_POST['event_id']) && is_scalar($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
    if (!$event_id || get_post_type($event_id) !== self::POST_TYPE || get_post_status($event_id) !== 'publish' || !isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_POST['nonce'])), 'roxy_event_add_' . $event_id)) wp_die('This event is unavailable.');
    if (!function_exists('WC')) wp_die('WooCommerce is required.');
    if (!WC()->cart) wc_load_cart();
    WC()->cart->get_cart();
    $rows = self::schedule_rows($event_id);
    $packages = self::package_definitions($rows);
    $index=isset($_POST['package_index']) && is_scalar($_POST['package_index'])?(int)$_POST['package_index']:-1;
    $package=$packages[$index]??null;
    $qty=isset($_POST['quantity']) && is_scalar($_POST['quantity'])?filter_var(wp_unslash($_POST['quantity']),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10]]):false;
    $type=isset($_POST['ticket_type']) && is_scalar($_POST['ticket_type'])?sanitize_key(wp_unslash((string)$_POST['ticket_type'])):'';
    if(!$package || !$qty || !in_array($type,['adult','discount','matinee','subscriber'],true))wp_die('Select a valid event ticket option.');
    foreach($package['ids'] as $sid) if(!self::products_ready_for_showing((int)$sid)) wp_die('Tickets for this event are not ready yet. Refresh the page shortly.');
    $bundle_id=wp_generate_uuid4();$added=[];
    $bundle_label=$package['kind']==='full'?'All-screenings pass':($package['kind']==='double'?'Double feature':'Individual screening');
    if($type==='subscriber')$bundle_label='Subscriber all-screenings reservation';
    $unit_prices=[];
    if($package['kind']==='double')$unit_prices=self::allocated_prices($type==='discount'?(float)get_post_meta($event_id,'_roxy_event_double_discount',true):(float)get_post_meta($event_id,'_roxy_event_double_general',true),count($package['ids']));
    if($package['kind']==='full' && $type!=='subscriber')$unit_prices=self::allocated_prices((float)get_post_meta($event_id,'_roxy_event_full_pass',true),count($package['ids']));
    $expected_ids=array_map('intval',$package['ids']);
    $signature=wp_hash(wp_json_encode([$event_id,$package['kind'],$expected_ids,$type,$unit_prices,$bundle_id]),'auth');
    foreach($package['ids'] as $sid) {
      if(!Eligibility::showing_sales_open((int)$sid)){foreach($added as $key)WC()->cart->remove_cart_item($key);wc_add_notice('One or more screenings in this pass are no longer on sale.','error');wp_safe_redirect(get_permalink($event_id));exit;}
      $profile=(string)get_post_meta($sid,'_roxy_pricing_profile',true);$line_type=$type;
      if($profile==='movie_matinee' && $type==='adult')$line_type='matinee';
      if($type==='subscriber' && $package['kind']!=='full'){wc_add_notice('Subscriber reservations are available for the full event pass.','error');wp_safe_redirect(get_permalink($event_id));exit;}
      if($type==='discount' && ($package['kind']==='full' || $line_type==='matinee' || ($package['kind']==='double' && array_filter($package['ids'],static fn($id)=>(string)get_post_meta((int)$id,'_roxy_pricing_profile',true)!=='movie_evening')))){wc_add_notice('That ticket type is not available for this pass.','error');wp_safe_redirect(get_permalink($event_id));exit;}
      $pid=(int)get_post_meta($sid,'_roxy_pid_'.$line_type,true);
      if(!$pid){wc_add_notice('A ticket option is not configured for one of these screenings. Contact the theater.','error');foreach($added as $key)WC()->cart->remove_cart_item($key);wp_safe_redirect(get_permalink($event_id));exit;}
      $cart_data=['roxy_event_bundle_id'=>$bundle_id,'roxy_event_bundle_label'=>$bundle_label,'roxy_event_id'=>$event_id,'roxy_event_bundle_kind'=>$package['kind'],'roxy_event_bundle_showing_ids'=>$expected_ids,'roxy_event_ticket_type'=>$type,'roxy_event_bundle_signature'=>$signature];
      if(isset($unit_prices[count($added)]))$cart_data['roxy_event_unit_price']=$unit_prices[count($added)];
      $key=WC()->cart->add_to_cart($pid,(int)$qty,0,[],$cart_data);
      if(!$key){foreach($added as $added_key)WC()->cart->remove_cart_item($added_key);wc_add_notice('The complete pass could not be added. No partial pass was kept.','error');wp_safe_redirect(get_permalink($event_id));exit;}
      $stored=WC()->cart->get_cart_item($key);
      $stored_showing=$stored?(int)get_post_meta((int)($stored['product_id']??0),ROXY_ST_META_SHOWING_ID,true):0;
      $stored_ids=array_values(array_map('intval',(array)($stored['roxy_event_bundle_showing_ids']??[])));
      if(!$stored || (string)($stored['roxy_event_bundle_id']??'')!==$bundle_id || (int)($stored['product_id']??0)!==$pid || $stored_showing!==(int)$sid || (string)($stored['roxy_event_ticket_type']??'')!==$type || (int)($stored['roxy_event_id']??0)!==$event_id || (string)($stored['roxy_event_bundle_kind']??'')!==$package['kind'] || $stored_ids!==$expected_ids || !hash_equals($signature,(string)($stored['roxy_event_bundle_signature']??''))) {
        if($stored && (string)($stored['roxy_event_bundle_id']??'')===$bundle_id)WC()->cart->remove_cart_item($key);
        foreach($added as $added_key)WC()->cart->remove_cart_item($added_key);
        wc_add_notice('The complete pass could not be kept separate from existing tickets. No partial pass was kept. Please try again.','error');
        wp_safe_redirect(get_permalink($event_id));exit;
      }
      $added[]=$key;
    }
    if(count($added)!==count($expected_ids) || count(array_unique($added))!==count($expected_ids)) {
      foreach($added as $added_key)WC()->cart->remove_cart_item($added_key);
      wc_add_notice('The complete pass could not be added. No partial pass was kept.','error');
      wp_safe_redirect(get_permalink($event_id));exit;
    }
    wp_safe_redirect(wc_get_cart_url());exit;
  }

  public static function cart_item_data(array $data, array $cart_item): array {
    $label = isset($cart_item['roxy_event_bundle_label']) ? sanitize_text_field((string)$cart_item['roxy_event_bundle_label']) : '';
    if ($label !== '') $data[] = ['key'=>'Event pass','value'=>$label];
    return $data;
  }

  public static function order_item_meta($item, string $cart_item_key, array $values, $order): void {
    $label = isset($values['roxy_event_bundle_label']) ? sanitize_text_field((string)$values['roxy_event_bundle_label']) : '';
    if ($label !== '') $item->add_meta_data('Event pass', $label, true);
  }

  public static function validate_cart_bundles(): void {
    if (!function_exists('WC') || !WC()->cart) return;
    $groups=[];
    foreach (WC()->cart->get_cart() as $item) {
      $bundle=(string)($item['roxy_event_bundle_id']??'');
      if ($bundle==='') continue;
      if (!isset($groups[$bundle]['meta'])) $groups[$bundle]['meta']=$item;
      $pid=(int)($item['product_id']??0);
      $sid=(int)get_post_meta($pid,ROXY_ST_META_SHOWING_ID,true);
      $groups[$bundle]['lines'][]=['pid'=>$pid,'sid'=>$sid,'qty'=>(int)($item['quantity']??0),'price'=>$item['roxy_event_unit_price']??null,'has_price'=>array_key_exists('roxy_event_unit_price',$item),'type'=>(string)get_post_meta($pid,ROXY_ST_META_TICKET_TYPE,true),'data'=>$item];
    }
    foreach ($groups as $bundle=>$group) {
      $meta=$group['meta'];$event_id=(int)($meta['roxy_event_id']??0);$kind=(string)($meta['roxy_event_bundle_kind']??'');$ids=array_values(array_map('intval',(array)($meta['roxy_event_bundle_showing_ids']??[])));$type=(string)($meta['roxy_event_ticket_type']??'');
      $unit_prices=[];
      if($kind==='double')$unit_prices=self::allocated_prices($type==='discount'?(float)get_post_meta($event_id,'_roxy_event_double_discount',true):(float)get_post_meta($event_id,'_roxy_event_double_general',true),count($ids));
      if($kind==='full' && $type!=='subscriber')$unit_prices=self::allocated_prices((float)get_post_meta($event_id,'_roxy_event_full_pass',true),count($ids));
      $signature=wp_hash(wp_json_encode([$event_id,$kind,$ids,$type,$unit_prices,$bundle]),'auth');
      $valid=get_post_type($event_id)===self::POST_TYPE && get_post_status($event_id)==='publish' && $ids && hash_equals($signature,(string)($meta['roxy_event_bundle_signature']??''));
      $valid=$valid && self::package_is_current($event_id,$kind,$ids);
      $line_showings=[];$line_quantities=[];
      foreach($group['lines'] as $index=>$line) {
        $line_showings[]=$line['sid'];$line_quantities[]=$line['qty'];
        $sid=$ids[$index]??0;$line_type=$type;
        if((string)get_post_meta($sid,'_roxy_pricing_profile',true)==='movie_matinee' && $type==='adult')$line_type='matinee';
        $expected_product=(int)get_post_meta($sid,'_roxy_pid_'.$line_type,true);
        $valid=$valid && $line['sid']===$sid && $line['pid']===$expected_product && $line['type']===$line_type && Eligibility::showing_sales_open($sid);
        $line_data=$line['data'];
        $line_ids=array_values(array_map('intval',(array)($line_data['roxy_event_bundle_showing_ids']??[])));
        $valid=$valid && (string)($line_data['roxy_event_bundle_id']??'')===$bundle && (int)($line_data['roxy_event_id']??0)===$event_id && (string)($line_data['roxy_event_bundle_kind']??'')===$kind && $line_ids===$ids && (string)($line_data['roxy_event_ticket_type']??'')===$type && hash_equals($signature,(string)($line_data['roxy_event_bundle_signature']??''));
        if(isset($unit_prices[$index]))$valid=$valid && is_numeric($line['price']) && round((float)$line['price'],2)===round((float)$unit_prices[$index],2);
        elseif($line['has_price'])$valid=false;
      }
      sort($line_showings);$expected_sorted=$ids;sort($expected_sorted);
      $valid=$valid && $line_showings===$expected_sorted && count(array_unique($line_showings))===count($ids) && count(array_unique($line_quantities))===1 && ($line_quantities[0]??0)>0;
      if(!$valid) {
        $message='An event pass is incomplete or no longer matches the event. Remove all items in that pass and add it again.';
        if(!wc_has_notice($message,'error'))wc_add_notice($message,'error');
      }
    }
  }

  private static function package_definitions(array $rows): array {
    $days=[];$packages=[];
    foreach($rows as $row) {
      $timestamp=Eligibility::showing_start_timestamp((int)$row['id'])?:time();
      $packages[]=['label'=>get_the_title($row['id']).' — '.wp_date('D, M j g:i A',$timestamp),'ids'=>[(int)$row['id']],'kind'=>'single'];
      $days[substr($row['start'],0,10)][]=$row;
    }
    foreach($days as $screenings)if(count($screenings)===2)$packages[]=['label'=>'Double feature — '.wp_date('D, M j',Eligibility::showing_start_timestamp((int)$screenings[0]['id'])?:time()),'ids'=>array_map('intval',array_column($screenings,'id')),'kind'=>'double'];
    if(count($rows)>1)$packages[]=['label'=>'All-screenings pass — '.count($rows).' films','ids'=>array_map('intval',array_column($rows,'id')),'kind'=>'full'];
    return $packages;
  }

  private static function products_ready_for_showing(int $showing_id): bool {
    $profile=(string)get_post_meta($showing_id,'_roxy_pricing_profile',true)?:'movie_evening';
    $required=$profile==='movie_evening'?['adult','discount','subscriber']:($profile==='movie_matinee'?['matinee','subscriber']:[]);
    if(!$required) return false;
    foreach($required as $type) {
      $product_id=(int)get_post_meta($showing_id,'_roxy_pid_'.$type,true);
      if($product_id<=0 || get_post_type($product_id)!=='product' || get_post_status($product_id)!=='publish') return false;
    }
    return true;
  }

  private static function package_is_current(int $event_id,string $kind,array $ids): bool {
    foreach(self::package_definitions(self::schedule_rows($event_id)) as $package)if($package['kind']===$kind && $package['ids']===$ids)return true;
    return false;
  }

  private static function allocated_prices(float $total, int $count): array {
    if ($count < 1) return [];
    $cents = (int)round(max(0, $total) * 100);
    $base = intdiv($cents, $count);
    $extra = $cents % $count;
    $prices = [];
    for ($i=0; $i<$count; $i++) $prices[] = ($base + ($i < $extra ? 1 : 0)) / 100;
    return $prices;
  }

  public static function redirect_screening(): void {
    if (!is_singular(CPT::POST_TYPE)) return;
    $event_id=(int)get_post_meta(get_queried_object_id(),'_roxy_event_id',true);
    if($event_id && get_post_status($event_id)==='publish') { wp_safe_redirect(get_permalink($event_id),301); exit; }
  }

  public static function hide_event_screenings_from_archive($query): void {
    if (!($query instanceof \WP_Query) || is_admin() || !$query->is_main_query() || (!$query->is_post_type_archive(CPT::POST_TYPE) && $query->get('post_type')!==CPT::POST_TYPE)) return;
    $meta=(array)$query->get('meta_query');$meta[]=['relation'=>'OR',['key'=>'_roxy_event_id','compare'=>'NOT EXISTS'],['key'=>'_roxy_event_id','value'=>'','compare'=>'=']];$query->set('meta_query',$meta);
  }
}
