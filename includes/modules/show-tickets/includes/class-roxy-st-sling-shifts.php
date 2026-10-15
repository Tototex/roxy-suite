<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

final class SlingShifts {
  const META_SHIFT_IDS = '_roxy_st_sling_shift_ids';
  const META_SHIFT_EXCLUDED = '_roxy_st_sling_shift_excluded';

  public static function publish_immediately(array $settings): bool {
    return !empty($settings['sling_publish_shifts']);
  }

  public static function init(): void {
    add_action('add_meta_boxes', [__CLASS__, 'metaboxes']);
    add_action('admin_post_roxy_st_create_sling_shifts', [__CLASS__, 'handle_create']);
    add_action('admin_notices', [__CLASS__, 'admin_notice']);
  }

  public static function metaboxes(): void {
    add_meta_box('roxy_st_showing_sling_shifts', 'Sling Shifts', [__CLASS__, 'render_showing_meta_box'], CPT::POST_TYPE, 'normal', 'low');
    add_meta_box('roxy_st_event_sling_shifts', 'Sling Shifts', [__CLASS__, 'render_event_meta_box'], Events::POST_TYPE, 'normal', 'low');
  }

  public static function render_showing_meta_box($post): void {
    $showing_id = (int)$post->ID;
    if ($showing_id <= 0 || !current_user_can('edit_post', $showing_id)) return;
    $title = get_the_title($showing_id);
    $start = (string)get_post_meta($showing_id, '_roxy_start', true);
    $duration = (int)get_post_meta($showing_id, '_roxy_duration_minutes', true);
    $window = self::default_window($start, $duration);
    if (!$window) {
      echo '<p class="description">Save a valid showing date and time before creating shifts.</p>';
      return;
    }
    echo '<p class="description">Creates open Sling shifts. Employee 1 is Private Show; additional employees are Concessionist. Defaults: start 1 hour before showtime; end 30 minutes after runtime. Unknown runtime defaults to 4 hours.</p>';
    self::render_controls([['id'=>$showing_id,'title'=>$title,'start'=>$window['start'],'end'=>$window['end']]], 'showing', $showing_id);
  }

  public static function render_event_meta_box($post): void {
    $event_id = (int)$post->ID;
    if ($event_id <= 0 || !current_user_can('edit_post', $event_id)) return;
    $showings = [];
    foreach ((array)get_post_meta($event_id, Events::META_SHOWINGS, true) as $showing_id) {
      $showing_id = (int)$showing_id;
      if ($showing_id <= 0 || get_post_type($showing_id) !== CPT::POST_TYPE) continue;
      $showings[] = [
        'id'=>$showing_id,
        'title'=>get_the_title($showing_id),
        'start'=>(string)get_post_meta($showing_id, '_roxy_start', true),
        'duration'=>(string)get_post_meta($showing_id, '_roxy_duration_minutes', true),
      ];
    }
    usort($showings, static fn($a,$b)=>strcmp($a['start'],$b['start']));
    $rows = [];
    foreach ($showings as $row) {
      $id = (int)($row['id'] ?? 0);
      if ($id <= 0) continue;
      $window = self::default_window((string)($row['start'] ?? ''), (int)($row['duration'] ?? 0));
      if ($window) $rows[] = ['id'=>$id,'title'=>(string)($row['title'] ?? get_the_title($id)),'start'=>$window['start'],'end'=>$window['end']];
    }
    if (!$rows) {
      echo '<p class="description">Save at least one screening before creating shifts.</p>';
      return;
    }
    echo '<p class="description">Creates open Sling shifts for each selected screening below. Employee 1 is Private Show; additional employees are Concessionist. Defaults: start 1 hour before showtime; end 30 minutes after runtime. Unknown runtime defaults to 4 hours. Adjust any times before creating.</p>';
    self::render_controls($rows, 'event', $event_id);
  }

  private static function render_controls(array $rows, string $scope, int $post_id): void {
    $count_id = 'roxy-sling-employee-count-' . $scope;
    echo '<p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><label for="' . esc_attr($count_id) . '">Number of employees per shift</label><input id="' . esc_attr($count_id) . '" type="number" min="1" max="10" value="2" style="width:75px">';
    echo '<button type="button" class="button button-primary roxy-create-sling-shifts" data-scope="' . esc_attr($scope) . '">Add Sling Shifts</button></p>';
    echo '<table class="widefat striped"><thead><tr><th>Include</th><th>Showing</th><th>Shift starts</th><th>Shift ends</th><th>Existing</th></tr></thead><tbody>';
    foreach ($rows as $i=>$row) {
      $showing_id = (int)$row['id'];
      $excluded = (bool)get_post_meta($showing_id, self::META_SHIFT_EXCLUDED, true);
      $saved = get_post_meta($showing_id, self::META_SHIFT_IDS, true);
      $saved_count = is_array($saved) ? count(array_filter($saved, static fn($item) => is_array($item) && !empty($item['shift_id']))) : 0;
      echo '<tr class="roxy-sling-shift-row"><td><input type="checkbox" class="roxy-sling-showing-selected" value="1"' . ($excluded ? '' : ' checked') . ' aria-label="Include ' . esc_attr($row['title']) . '"><input type="hidden" class="roxy-sling-showing-id" value="' . $showing_id . '"></td><td>' . esc_html($row['title']) . '</td>';
      echo '<td><input type="datetime-local" class="roxy-sling-shift-start" value="' . esc_attr($row['start']) . '"></td><td><input type="datetime-local" class="roxy-sling-shift-end" value="' . esc_attr($row['end']) . '"></td><td>' . ($excluded ? 'Not selected' : '') . ($saved_count ? ($excluded ? '; ' : '') . esc_html($saved_count . ' shift(s) recorded') : ($excluded ? '' : 'None yet')) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<script>(function(){var scope=' . wp_json_encode($scope) . ',postId=' . (int)($scope === 'showing' ? $post_id : 0) . ',eventId=' . (int)($scope === 'event' ? $post_id : 0) . ';document.querySelectorAll(".roxy-create-sling-shifts[data-scope=\""+scope+"\"]").forEach(function(button){button.addEventListener("click",function(){var box=button.closest(".postbox"),count=box&&box.querySelector("#roxy-sling-employee-count-"+scope),rows=box?box.querySelectorAll(".roxy-sling-shift-row"):[];if(!count||!rows.length)return;var n=parseInt(count.value,10);if(!Number.isInteger(n)||n<1||n>10){alert("Choose between 1 and 10 employees.");return;}var form=document.createElement("form");form.method="post";form.action=' . wp_json_encode(admin_url('admin-post.php')) . ';function field(name,value){var input=document.createElement("input");input.type="hidden";input.name=name;input.value=value;form.appendChild(input);}field("action","roxy_st_create_sling_shifts");field("scope",scope);field("post_id",scope==="showing"?postId:eventId);field("employee_count",n);field("_wpnonce",' . wp_json_encode(wp_create_nonce('roxy_st_create_sling_shifts')) . ');var ids=[],starts=[],ends=[],selected=[],selectedCount=0;rows.forEach(function(row){var id=row.querySelector(".roxy-sling-showing-id"),start=row.querySelector(".roxy-sling-shift-start"),end=row.querySelector(".roxy-sling-shift-end"),checkbox=row.querySelector(".roxy-sling-showing-selected");if(id&&start&&end&&checkbox){ids.push(id.value);starts.push(start.value);ends.push(end.value);selected.push(checkbox.checked?"1":"0");if(checkbox.checked)selectedCount++;}});if(!selectedCount){alert("Select at least one showing.");return;}for(var i=0;i<selected.length;i++){if(selected[i]==="1"&&(!starts[i]||!ends[i])){alert("Enter a start and end time for each selected showing.");return;}}ids.forEach(function(v){field("showing_ids[]",v);});starts.forEach(function(v){field("shift_starts[]",v);});ends.forEach(function(v){field("shift_ends[]",v);});selected.forEach(function(v){field("showing_selected[]",v);});if(!window.confirm("Create "+(n*selectedCount)+" open Sling shifts for "+selectedCount+" selected showing(s)?"))return;document.body.appendChild(form);form.submit();});});})();</script>';
  }

  public static function handle_create(): void {
    if (!current_user_can('edit_posts') || !check_admin_referer('roxy_st_create_sling_shifts')) wp_die('You are not allowed to create Sling shifts.');
    $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash((string)$_POST['scope'])) : '';
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    if (!in_array($scope, ['showing','event'], true) || !$post_id || !current_user_can('edit_post', $post_id)) wp_die('Invalid showing or event.');
    $expected_type = $scope === 'showing' ? CPT::POST_TYPE : Events::POST_TYPE;
    if (get_post_type($post_id) !== $expected_type) wp_die('Invalid showing or event.');
    $count = isset($_POST['employee_count']) ? absint($_POST['employee_count']) : 0;
    if ($count < 1 || $count > 10) self::redirect_notice($post_id, 'Choose between 1 and 10 employees.', 'error');
    if (!function_exists('roxy_eb_get_settings') || !function_exists('roxy_eb_sling_get_mapping') || !function_exists('roxy_eb_sling_api_create_shift')) self::redirect_notice($post_id, 'Sling shift creation is unavailable. Check that Roxy Bookings is active.', 'error');
    $settings = roxy_eb_get_settings();
    if (($settings['sling_mode'] ?? 'disabled') !== 'direct') self::redirect_notice($post_id, 'Set Sling Mode to Direct API in Roxy Bookings settings before creating shifts.', 'error');
    $mapping = roxy_eb_sling_get_mapping();
    if (is_wp_error($mapping)) self::redirect_notice($post_id, $mapping->get_error_message(), 'error');

    $ids = isset($_POST['showing_ids']) && is_array($_POST['showing_ids']) ? array_map(static fn($value) => is_scalar($value) ? absint($value) : 0, wp_unslash($_POST['showing_ids'])) : [];
    $starts = isset($_POST['shift_starts']) && is_array($_POST['shift_starts']) ? array_map(static fn($value) => is_scalar($value) ? sanitize_text_field($value) : '', wp_unslash($_POST['shift_starts'])) : [];
    $ends = isset($_POST['shift_ends']) && is_array($_POST['shift_ends']) ? array_map(static fn($value) => is_scalar($value) ? sanitize_text_field($value) : '', wp_unslash($_POST['shift_ends'])) : [];
    $selected = isset($_POST['showing_selected']) && is_array($_POST['showing_selected']) ? array_map(static fn($value) => is_scalar($value) && (string)$value === '1', wp_unslash($_POST['showing_selected'])) : [];
    if (!$ids || count($ids) !== count($starts) || count($ids) !== count($ends) || count($ids) !== count($selected) || count($ids) > 100 || count(array_unique($ids)) !== count($ids)) self::redirect_notice($post_id, 'The submitted showing schedule is incomplete. Refresh and try again.', 'error');
    if (!in_array(true, $selected, true)) self::redirect_notice($post_id, 'Select at least one showing.', 'error');
    if ($scope === 'showing' && (count($ids) !== 1 || $ids[0] !== $post_id)) self::redirect_notice($post_id, 'The selected showing did not match this page.', 'error');
    if ($scope === 'event') {
      $event_showings = array_values(array_unique(array_filter(array_map('intval', get_post_meta($post_id, Events::META_SHOWINGS, true) ?: []))));
      $submitted_showings = $ids;
      sort($event_showings);
      sort($submitted_showings);
      if ($submitted_showings !== $event_showings) self::redirect_notice($post_id, 'The submitted screenings no longer match this event. Refresh and try again.', 'error');
    }

    foreach ($ids as $i=>$showing_id) {
      if (get_post_type($showing_id) !== CPT::POST_TYPE || !current_user_can('edit_post', $showing_id)) self::redirect_notice($post_id, 'A showing could not be verified.', 'error');
      if ($selected[$i]) delete_post_meta($showing_id, self::META_SHIFT_EXCLUDED);
      else update_post_meta($showing_id, self::META_SHIFT_EXCLUDED, 1);
    }

    $created = 0;
    $skipped = 0;
    $errors = [];
    foreach ($ids as $i=>$showing_id) {
      if (!$selected[$i]) continue;
      $start = self::parse_local_datetime($starts[$i] ?? '');
      $end = self::parse_local_datetime($ends[$i] ?? '');
      if (!$start || !$end || $end <= $start) { $errors[] = get_the_title($showing_id) . ': invalid shift times.'; continue; }
      $existing = get_post_meta($showing_id, self::META_SHIFT_IDS, true);
      $existing = is_array($existing) ? $existing : [];
      for ($employee=1; $employee <= $count; $employee++) {
        $key = (string)$employee;
        if (!empty($existing[$key]['shift_id'])) {
          if (self::publish_immediately($settings) && empty($existing[$key]['published'])) {
            $published = roxy_eb_sling_api_publish_shift($existing[$key]['shift_id'], ['action'=>'publish_showing_shift','showing_id'=>$showing_id,'role'=>$existing[$key]['role'] ?? '']);
            if (is_wp_error($published)) { $errors[] = get_the_title($showing_id) . ': shift is recorded but could not be published: ' . $published->get_error_message(); break; }
            $existing[$key]['published'] = true;
            update_post_meta($showing_id, self::META_SHIFT_IDS, $existing);
          } else {
            $skipped++;
          }
          continue;
        }
        $role = $employee === 1 ? 'private_show' : 'concessionist';
        $position_id = $role === 'private_show' ? $mapping['position_private_show_id'] : $mapping['position_concessionist_id'];
        $title = get_the_title($showing_id) . ' - ' . ($role === 'private_show' ? 'Private Show' : 'Concessionist');
        $shift_id = roxy_eb_sling_api_create_shift([
          'summary' => $title,
          'assigneeNotes' => 'Newport Roxy showing: ' . get_the_title($showing_id),
          'location' => ['id'=>(int)$mapping['location_id']],
          'position' => ['id'=>(int)$position_id],
          'dtstart' => $start->format(DATE_ATOM),
          'dtend' => $end->format(DATE_ATOM),
          'available' => true,
          'availableSlots' => 1,
        ], ['action'=>'create_showing_shift','showing_id'=>$showing_id,'employee_number'=>$employee,'role'=>$role]);
        if (is_wp_error($shift_id)) { $errors[] = get_the_title($showing_id) . ' (' . $role . '): ' . $shift_id->get_error_message(); break; }
        $existing[$key] = ['shift_id'=>(string)$shift_id,'role'=>$role,'start'=>$start->format(DATE_ATOM),'end'=>$end->format(DATE_ATOM),'published'=>false];
        update_post_meta($showing_id, self::META_SHIFT_IDS, $existing);
        $created++;
        if (self::publish_immediately($settings)) {
          $published = roxy_eb_sling_api_publish_shift($shift_id, ['action'=>'publish_showing_shift','showing_id'=>$showing_id,'role'=>$role]);
          if (is_wp_error($published)) $errors[] = get_the_title($showing_id) . ': shift created but could not be published: ' . $published->get_error_message();
          else {
            $existing[$key]['published'] = true;
            update_post_meta($showing_id, self::META_SHIFT_IDS, $existing);
          }
        }
      }
    }
    $message = $created . ' shift(s) created';
    if ($skipped) $message .= '; ' . $skipped . ' existing shift(s) skipped';
    if ($errors) $message .= '. Issues: ' . implode(' | ', $errors);
    self::redirect_notice($post_id, $message, $errors ? 'warning' : 'success');
  }

  public static function admin_notice(): void {
    if (!isset($_GET['roxy_sling_notice'])) return;
    $key = sanitize_key(wp_unslash((string)$_GET['roxy_sling_notice']));
    $notice = get_transient('roxy_st_sling_notice_' . get_current_user_id() . '_' . $key);
    if (!is_array($notice)) return;
    delete_transient('roxy_st_sling_notice_' . get_current_user_id() . '_' . $key);
    $class = ($notice['type'] ?? '') === 'success' ? 'notice-success' : ((($notice['type'] ?? '') === 'warning') ? 'notice-warning' : 'notice-error');
    echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html((string)$notice['message']) . '</p></div>';
  }

  private static function default_window(string $local_start, int $duration): ?array {
    $start = self::parse_local_datetime($local_start);
    if (!$start) return null;
    $runtime = $duration > 0 ? $duration : 240;
    return ['start'=>$start->modify('-60 minutes')->format('Y-m-d\TH:i'),'end'=>$start->modify('+' . ($runtime + 30) . ' minutes')->format('Y-m-d\TH:i')];
  }

  private static function parse_local_datetime(string $value): ?\DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) return null;
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, wp_timezone());
    $errors = \DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\TH:i') !== $value) return null;
    return $date;
  }

  private static function redirect_notice(int $post_id, string $message, string $type): void {
    $key = wp_generate_password(12, false, false);
    set_transient('roxy_st_sling_notice_' . get_current_user_id() . '_' . $key, ['message'=>$message,'type'=>$type], 5 * MINUTE_IN_SECONDS);
    wp_safe_redirect(add_query_arg(['post'=>$post_id,'action'=>'edit','roxy_sling_notice'=>$key], admin_url('post.php')));
    exit;
  }
}
