<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

class CPT {
  const POST_TYPE = 'roxy_showing';
  private static bool $is_generating_schedule = false;

  public static function init(): void {
    add_action('init', [__CLASS__, 'register']);
    add_action('add_meta_boxes', [__CLASS__, 'metaboxes']);
    add_action('save_post_' . self::POST_TYPE, [__CLASS__, 'save'], 10, 2);
    add_filter('manage_edit-' . self::POST_TYPE . '_columns', [__CLASS__, 'admin_columns']);
    add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [__CLASS__, 'render_admin_column'], 10, 2);
    add_action('pre_get_posts', [__CLASS__, 'filter_admin_list']);
    add_filter('views_edit-' . self::POST_TYPE, [__CLASS__, 'admin_views']);
    add_action('admin_init', [__CLASS__, 'maybe_run_one_time_cleanup']);
    add_filter('post_row_actions', [__CLASS__, 'row_actions'], 10, 2);
    add_action('admin_action_roxy_duplicate_weekend', [__CLASS__, 'handle_duplicate_weekend']);
    add_action('admin_notices', [__CLASS__, 'admin_notices']);
  }

  public static function register(): void {
    $labels = [
      'name' => 'Roxy Showings',
      'singular_name' => 'Roxy Showing',
      'menu_name' => 'Roxy Showings',
      'all_items' => 'Roxy Showings',
      'add_new_item' => 'Add New Showing',
      'edit_item' => 'Edit Showing',
      'new_item' => 'New Showing',
      'view_item' => 'View Showing',
      'search_items' => 'Search Roxy Showings',
    ];

    register_post_type(self::POST_TYPE, [
      'labels' => $labels,
      'public' => true,
      'has_archive' => true,
      'rewrite' => ['slug' => 'showings'],
      'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
      'menu_icon' => 'dashicons-tickets-alt',
      'show_in_rest' => true,
      'show_in_menu' => false,
    ]);

    register_taxonomy('roxy_show_type', self::POST_TYPE, [
      'label' => 'Show Type',
      'public' => true,
      'rewrite' => ['slug' => 'show-type'],
      'show_in_rest' => true,
      'hierarchical' => true,
    ]);
  }

  public static function metaboxes(): void {
    add_meta_box(
      'roxy_showing_details',
      'Showing Details',
      [__CLASS__, 'render_metabox'],
      self::POST_TYPE,
      'normal',
      'high'
    );
  }

  public static function render_metabox($post): void {
    wp_nonce_field('roxy_showing_save', 'roxy_showing_nonce');

    $is_new_showing = self::is_new_showing($post);
    $schedule_rows = $is_new_showing ? self::default_schedule_rows() : [];
    $use_schedule_builder = $is_new_showing;

    $start = get_post_meta($post->ID, '_roxy_start', true);
    $capacity_raw = get_post_meta($post->ID, '_roxy_capacity', true);
    $capacity = ($capacity_raw === '' || $capacity_raw === null) ? Settings::get_default_capacity() : (int) $capacity_raw;
    $profile = get_post_meta($post->ID, '_roxy_pricing_profile', true) ?: 'movie_evening';

    $live_label_1 = get_post_meta($post->ID, '_roxy_live_label_1', true) ?: 'General Admission';
    $live_price_1 = get_post_meta($post->ID, '_roxy_live_price_1', true);
    $live_label_2 = get_post_meta($post->ID, '_roxy_live_label_2', true) ?: 'VIP';
    $live_price_2 = get_post_meta($post->ID, '_roxy_live_price_2', true);
    $live_future_price_1 = get_post_meta($post->ID, '_roxy_live_future_price_1', true);
    $live_change_at_1 = get_post_meta($post->ID, '_roxy_live_change_at_1', true);
    $live_future_price_2 = get_post_meta($post->ID, '_roxy_live_future_price_2', true);
    $live_change_at_2 = get_post_meta($post->ID, '_roxy_live_change_at_2', true);
    $trailer_url = get_post_meta($post->ID, '_roxy_trailer_url', true);

    $general_price = Settings::get_price('general_price', 12);
    $discount_price = Settings::get_price('discount_price', 8);
    $matinee_price = Settings::get_price('matinee_price', 8);

    $p_adult = (int) get_post_meta($post->ID, '_roxy_pid_adult', true);
    $p_discount = (int) get_post_meta($post->ID, '_roxy_pid_discount', true);
    $p_matinee = (int) get_post_meta($post->ID, '_roxy_pid_matinee', true);
    $p_live1 = (int) get_post_meta($post->ID, '_roxy_pid_live1', true);
    $p_live2 = (int) get_post_meta($post->ID, '_roxy_pid_live2', true);
    $p_sub = (int) get_post_meta($post->ID, '_roxy_pid_subscriber', true);

    $stats = Sales::get_showing_stats((int) $post->ID);
    $remaining = Capacity::remaining_seats_for_showing((int) $post->ID);

    echo '<style>.roxy-grid{display:grid;grid-template-columns:180px 1fr;gap:10px;align-items:center;max-width:860px}.roxy-grid input[type=text],.roxy-grid input[type=number],.roxy-grid input[type=url],.roxy-grid input[type=datetime-local],.roxy-grid select{width:100%}.roxy-help{grid-column:1/-1;color:#666;font-size:12px}.roxy-schedule-wrap{grid-column:1/-1;max-width:860px}.roxy-schedule-wrap[hidden]{display:none!important}.roxy-checkbox-row{display:flex;align-items:center;gap:8px}</style>';
    echo '<div class="roxy-grid">';

    echo '<label for="roxy_start"><strong>Start (local time)</strong></label>';
    echo '<input id="roxy_start" name="roxy_start" type="datetime-local" value="' . esc_attr($start) . '"' . ($use_schedule_builder ? ' disabled' : '') . '>';

    if ($is_new_showing) {
      echo '<label for="roxy_use_schedule_builder"><strong>Schedule Builder</strong></label>';
      echo '<div class="roxy-checkbox-row"><label><input id="roxy_use_schedule_builder" name="roxy_use_schedule_builder" type="checkbox" value="1" ' . checked($use_schedule_builder, true, false) . '> Use Schedule Builder</label><span class="description">When enabled, the Start field above is ignored and one showing is created for each schedule row below.</span></div>';
    }

    echo '<label for="roxy_capacity"><strong>Capacity</strong></label>';
    echo '<input id="roxy_capacity" name="roxy_capacity" type="number" min="0" step="1" value="' . esc_attr((string) $capacity) . '">';

    echo '<label for="roxy_pricing_profile"><strong>Pricing Profile</strong></label>';
    echo '<select id="roxy_pricing_profile" name="roxy_pricing_profile">';
    $profiles = [
      'movie_evening' => sprintf('Movie — Evening (General $%s / Discount $%s)', wc_format_localized_price($general_price), wc_format_localized_price($discount_price)),
      'movie_matinee' => sprintf('Movie — Matinee (Everyone $%s)', wc_format_localized_price($matinee_price)),
      'live_event'    => 'Live Event (Tiered)',
      'free_event'    => 'Free Event (No Tickets)',
    ];
    foreach ($profiles as $k => $label) {
      echo '<option value="' . esc_attr($k) . '" ' . selected($profile, $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';

    // Notes — one shown at a time based on profile; initial display set by PHP to avoid flash
    echo '<div class="roxy-help" id="roxy-ticket-products-note" style="display:' . ($profile === 'free_event' ? 'none' : '') . '"><strong>Note:</strong> Ticket products are auto-created/updated when you save this showing. Global defaults can be edited under <a href="' . esc_url(admin_url('admin.php?page=roxy-show-tickets')) . '">Show Tickets → Settings</a>.</div>';
    echo '<div class="roxy-help" id="roxy-free-event-note" style="display:' . ($profile === 'free_event' ? '' : 'none') . '"><strong>Free Event:</strong> No ticket products will be created. This showing appears in the public listing with &ldquo;Free Admission&rdquo; shown instead of a ticket form. Any previously created ticket products for this showing will be trashed on save.</div>';
    echo '<script>document.addEventListener("DOMContentLoaded",function(){var sel=document.getElementById("roxy_pricing_profile");var live=document.getElementById("roxy-live-tier-fields");var tNote=document.getElementById("roxy-ticket-products-note");var fNote=document.getElementById("roxy-free-event-note");if(!sel)return;function upd(){var v=sel.value;if(live)live.style.display=(v==="live_event")?"contents":"none";if(tNote)tNote.style.display=(v==="free_event")?"none":"";if(fNote)fNote.style.display=(v==="free_event")?"":"none";}sel.addEventListener("change",upd);upd();});</script>';

    // Live tier fields — wrapped so JS can show/hide as a group; display:contents keeps them in the parent grid
    echo '<div id="roxy-live-tier-fields" style="display:' . ($profile === 'live_event' ? 'contents' : 'none') . '">';
    echo '<label><strong>Live Tier 1 Label</strong></label>';
    echo '<input name="roxy_live_label_1" type="text" value="' . esc_attr($live_label_1) . '">';

    echo '<label><strong>Live Tier 1 Price</strong></label>';
    echo '<input name="roxy_live_price_1" type="number" min="0" step="0.01" value="' . esc_attr((string) $live_price_1) . '">';

    echo '<label><strong>Live Tier 1 Future Price</strong></label>';
    echo '<input name="roxy_live_future_price_1" type="number" min="0" step="0.01" value="' . esc_attr((string) $live_future_price_1) . '">';

    echo '<label><strong>Live Tier 1 Price Change At</strong></label>';
    echo '<input name="roxy_live_change_at_1" type="datetime-local" value="' . esc_attr((string) $live_change_at_1) . '">';

    echo '<label><strong>Live Tier 2 Label</strong></label>';
    echo '<input name="roxy_live_label_2" type="text" value="' . esc_attr($live_label_2) . '">';

    echo '<label><strong>Live Tier 2 Price</strong></label>';
    echo '<input name="roxy_live_price_2" type="number" min="0" step="0.01" value="' . esc_attr((string) $live_price_2) . '">';

    echo '<label><strong>Live Tier 2 Future Price</strong></label>';
    echo '<input name="roxy_live_future_price_2" type="number" min="0" step="0.01" value="' . esc_attr((string) $live_future_price_2) . '">';

    echo '<label><strong>Live Tier 2 Price Change At</strong></label>';
    echo '<input name="roxy_live_change_at_2" type="datetime-local" value="' . esc_attr((string) $live_change_at_2) . '">';
    echo '</div>'; // #roxy-live-tier-fields

    echo '<label><strong>Trailer / Media URL</strong></label>';
    echo '<input name="roxy_trailer_url" type="url" placeholder="https://www.youtube.com/watch?v=..." value="' . esc_attr((string) $trailer_url) . '">';
    echo '<div class="roxy-help">Optional. Supports YouTube or Vimeo embeds on the public event page.</div>';

    echo '<label><strong>Legacy Product IDs</strong></label>';
    echo '<textarea name="roxy_legacy_product_ids" rows="4" placeholder="One WooCommerce product ID per line" style="width:100%;max-width:480px">' . esc_textarea($legacy_product_ids) . '</textarea>';
    echo '<div class="roxy-help">Optional bridge for already-sold legacy event products. These sales count toward this showing&#8217;s sold, revenue, and remaining seats, but the public event page will still only sell the new Roxy ticket products.</div>';

    if ($is_new_showing) {
      echo '<div id="roxy-schedule-builder-wrap" class="roxy-schedule-wrap"' . ($use_schedule_builder ? '' : ' hidden') . '>';
      echo '<div class="roxy-help" style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e5e5"><strong>Schedule Builder</strong> — Saving this new showing will create one separate showing for each row below. Default rows auto-fill the next Friday, Saturday, and Sunday block, but you can change or remove any row.</div>';
      $default_anchor = self::default_schedule_rows()[0]['date'] ?? '';
      echo '<p style="margin:10px 0"><label for="roxy_schedule_anchor" style="font-weight:600;margin-right:8px">Opening Friday</label><input id="roxy_schedule_anchor" type="date" value="' . esc_attr($default_anchor) . '"> <span class="description">Used by Reset to Default Weekend. Defaults to the next Friday.</span></p>';
      echo '<table class="widefat striped" id="roxy-schedule-builder"><thead><tr><th style="width:34%">Date</th><th style="width:22%">Time</th><th style="width:28%">Pricing Profile</th><th style="width:16%">Action</th></tr></thead><tbody>';
      foreach ($schedule_rows as $index => $row) {
        echo self::render_schedule_row($index, $row['date'], $row['time'], $row['profile']);
      }
      echo '</tbody></table>';
      echo '<p style="margin-top:10px"><button type="button" class="button" id="roxy-add-schedule-row">Add Showing Time</button> <button type="button" class="button" id="roxy-reset-schedule">Reset to Default Weekend</button></p>';
      echo '<p class="description">Tip: Friday and Saturday default to Evening pricing. Sunday defaults to Matinee pricing, but every row can be changed.</p>';
      echo '</div>';
      echo '<script>document.addEventListener("DOMContentLoaded",function(){var table=document.querySelector("#roxy-schedule-builder tbody");var wrap=document.getElementById("roxy-schedule-builder-wrap");var toggle=document.getElementById("roxy_use_schedule_builder");var startField=document.getElementById("roxy_start");var anchorField=document.getElementById("roxy_schedule_anchor");if(!table||!toggle||!startField||!wrap){return;}var addBtn=document.getElementById("roxy-add-schedule-row");var resetBtn=document.getElementById("roxy-reset-schedule");var nextIndex=table.querySelectorAll("tr").length;var defaults=' . wp_json_encode(self::default_schedule_rows()) . ';var template=' . wp_json_encode(self::render_schedule_row('__INDEX__', '', '19:30', 'movie_evening')) . ';function updateMode(){var useBuilder=!!toggle.checked;startField.disabled=useBuilder;wrap.hidden=!useBuilder;}function bindRemove(scope){(scope.matches&&scope.matches(".roxy-remove-schedule-row")?[scope]:scope.querySelectorAll?scope.querySelectorAll(".roxy-remove-schedule-row"):[]).forEach(function(btn){btn.onclick=function(){var rows=table.querySelectorAll("tr");if(rows.length<=1){return;}var row=btn.closest("tr");if(row){row.remove();}};});}function addRow(dateVal,timeVal,profileVal){var html=template.replace(/__INDEX__/g,String(nextIndex++));var holder=document.createElement("tbody");holder.innerHTML=html;var row=holder.querySelector("tr");if(!row){return;}var d=row.querySelector("input[type=date]");if(d&&dateVal){d.value=dateVal;}var t=row.querySelector("input[type=time]");if(t&&timeVal){t.value=timeVal;}var s=row.querySelector("select");if(s&&profileVal){s.value=profileVal;}table.appendChild(row);bindRemove(row);}function addDays(base,days){var d=new Date(base.getTime());d.setDate(d.getDate()+days);return d;}function fmtDate(d){var m=String(d.getMonth()+1).padStart(2,"0");var day=String(d.getDate()).padStart(2,"0");return d.getFullYear()+"-"+m+"-"+day;}function weekendRows(anchor){if(anchor){var parts=anchor.split("-");if(parts.length===3){var base=new Date(parseInt(parts[0],10),parseInt(parts[1],10)-1,parseInt(parts[2],10));if(!isNaN(base.getTime())){return [{date:fmtDate(base),time:"19:30",profile:"movie_evening"},{date:fmtDate(addDays(base,1)),time:"19:30",profile:"movie_evening"},{date:fmtDate(addDays(base,2)),time:"14:30",profile:"movie_matinee"}];}}}return defaults;}function resetDefaults(){table.innerHTML="";weekendRows(anchorField&&anchorField.value?anchorField.value:"").forEach(function(row){addRow(row.date,row.time,row.profile);});}bindRemove(document);toggle.addEventListener("change",updateMode);updateMode();if(addBtn){addBtn.addEventListener("click",function(){addRow(anchorField&&anchorField.value?anchorField.value:"","19:30","movie_evening");});}if(resetBtn){resetBtn.addEventListener("click",function(){resetDefaults();});}if(anchorField){anchorField.addEventListener("change",function(){if(table.querySelectorAll("tr").length===0){resetDefaults();}});}});</script>';
    }

    $products = [
      'General' => $p_adult,
      'Discount' => $p_discount,
      'Matinee' => $p_matinee,
      'Live 1' => $p_live1,
      'Live 2' => $p_live2,
      'Subscriber' => $p_sub,
    ];
    $links = [];
    foreach ($products as $label => $pid) {
      if ($pid > 0 && get_post_type($pid) === 'product') {
        $links[] = esc_html($label) . ' #' . (int) $pid . ' — <a href="' . esc_url(get_edit_post_link($pid, '')) . '">Edit Product</a>';
      } else {
        $links[] = esc_html($label) . ' — not created yet';
      }
    }
    echo '<div class="roxy-help"><strong>Ticket Products</strong>: ' . implode(' | ', $links) . '</div>';
    echo '<div class="roxy-help">Need to browse ticket products in WooCommerce? <a href="' . esc_url(admin_url('edit.php?post_type=product&roxy_st_show_tickets=1')) . '">Show Ticket Products</a></div>';

    echo '<div class="roxy-help" style="margin-top:10px;padding-top:10px;border-top:1px solid #e5e5e5"><strong>Showing Sales Dashboard</strong></div>';
    echo '<div style="grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;max-width:860px">';
    echo self::dashboard_card('Sold', number_format_i18n((int) ($stats['sold_qty'] ?? 0)));
    echo self::dashboard_card('Remaining', is_null($remaining) ? 'Unlimited' : number_format_i18n((int) $remaining));
    $checked_in = Tickets::count_checked_in_for_showing((int) $post->ID);
    $not_checked_in = max(0, (int) ($stats['sold_qty'] ?? 0) - $checked_in);
    echo self::dashboard_card('Orders', number_format_i18n((int) ($stats['order_count'] ?? 0)));
    echo self::dashboard_card('Revenue', wc_price((float) ($stats['gross_revenue'] ?? 0)));
    echo self::dashboard_card('Subscriber Seats', number_format_i18n((int) ($stats['subscriber_qty'] ?? 0)));
    echo self::dashboard_card('Checked In', number_format_i18n((int) $checked_in));
    echo self::dashboard_card('Not Yet Checked In', number_format_i18n((int) $not_checked_in));
    echo '</div>';

    if (!empty($stats['ticket_types']) && is_array($stats['ticket_types'])) {
      echo '<div style="grid-column:1/-1;max-width:860px">';
      echo '<table class="widefat striped" style="max-width:860px"><thead><tr><th>Ticket Type</th><th>Sold</th><th>Revenue</th></tr></thead><tbody>';
      foreach ($stats['ticket_types'] as $row) {
        echo '<tr>';
        echo '<td>' . esc_html((string) ($row['label'] ?? 'Ticket')) . '</td>';
        echo '<td>' . number_format_i18n((int) ($row['qty'] ?? 0)) . '</td>';
        echo '<td>' . wp_kses_post(wc_price((float) ($row['revenue'] ?? 0))) . '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
      echo '</div>';
    }

    echo '</div>';
  }



  public static function filter_admin_list($query): void {
    if (!is_admin() || !$query instanceof \WP_Query || !$query->is_main_query()) {
      return;
    }

    global $pagenow;
    if ($pagenow !== 'edit.php') {
      return;
    }

    $post_type = isset($_GET['post_type']) ? sanitize_key((string) $_GET['post_type']) : '';
    if ($post_type !== self::POST_TYPE) {
      return;
    }

    $filter = self::get_admin_filter();
    if ($filter === 'all') {
      return;
    }

    $cutoff = current_time('Y-m-d') . 'T00:00';

    if ($filter === 'past') {
      $query->set('meta_query', [[
        'key' => '_roxy_start',
        'value' => $cutoff,
        'compare' => '<',
        'type' => 'CHAR',
      ]]);
      $query->set('orderby', 'meta_value');
      $query->set('meta_key', '_roxy_start');
      $query->set('order', 'DESC');
      return;
    }

    $query->set('meta_query', [
      'relation' => 'OR',
      [
        'key' => '_roxy_start',
        'value' => $cutoff,
        'compare' => '>=',
        'type' => 'CHAR',
      ],
      [
        'key' => '_roxy_start',
        'compare' => 'NOT EXISTS',
      ],
      [
        'key' => '_roxy_start',
        'value' => '',
        'compare' => '=',
      ],
    ]);
    $query->set('orderby', 'meta_value');
    $query->set('meta_key', '_roxy_start');
    $query->set('order', 'ASC');
  }

  public static function admin_views(array $views): array {
    $base_url = admin_url('edit.php?post_type=' . self::POST_TYPE);
    $current = self::get_admin_filter();

    $custom = [];
    foreach ([
      'upcoming' => 'Active / Upcoming',
      'past' => 'Past / Archived',
      'all' => 'All',
    ] as $key => $label) {
      $url = $key === 'upcoming' ? $base_url : add_query_arg('roxy_show_filter', $key, $base_url);
      $class = $current === $key ? ' class="current" aria-current="page"' : '';
      $custom[$key] = '<a href="' . esc_url($url) . '"' . $class . '>' . esc_html($label) . '</a>';
    }

    if (isset($views['all'])) {
      unset($views['all']);
    }

    return $custom + $views;
  }

  private static function get_admin_filter(): string {
    $filter = isset($_GET['roxy_show_filter']) ? sanitize_key((string) $_GET['roxy_show_filter']) : 'upcoming';
    if (!in_array($filter, ['upcoming', 'past', 'all'], true)) {
      $filter = 'upcoming';
    }
    return $filter;
  }

  public static function save(int $post_id, $post): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (self::$is_generating_schedule) return;
    if (!isset($_POST['roxy_showing_nonce']) || !wp_verify_nonce($_POST['roxy_showing_nonce'], 'roxy_showing_save')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $capacity = isset($_POST['roxy_capacity']) ? (int) $_POST['roxy_capacity'] : Settings::get_default_capacity();
    $default_profile = isset($_POST['roxy_pricing_profile']) ? sanitize_key($_POST['roxy_pricing_profile']) : 'movie_evening';

    $shared_meta = [
      '_roxy_capacity' => max(0, $capacity),
      '_roxy_live_label_1' => sanitize_text_field($_POST['roxy_live_label_1'] ?? 'General Admission'),
      '_roxy_live_price_1' => sanitize_text_field($_POST['roxy_live_price_1'] ?? ''),
      '_roxy_live_label_2' => sanitize_text_field($_POST['roxy_live_label_2'] ?? 'VIP'),
      '_roxy_live_price_2' => sanitize_text_field($_POST['roxy_live_price_2'] ?? ''),
      '_roxy_live_future_price_1' => sanitize_text_field($_POST['roxy_live_future_price_1'] ?? ''),
      '_roxy_live_change_at_1' => sanitize_text_field($_POST['roxy_live_change_at_1'] ?? ''),
      '_roxy_live_future_price_2' => sanitize_text_field($_POST['roxy_live_future_price_2'] ?? ''),
      '_roxy_live_change_at_2' => sanitize_text_field($_POST['roxy_live_change_at_2'] ?? ''),
      '_roxy_trailer_url' => esc_url_raw($_POST['roxy_trailer_url'] ?? ''),
      '_roxy_legacy_product_ids' => self::sanitize_legacy_product_ids($_POST['roxy_legacy_product_ids'] ?? ''),
    ];

    foreach ($shared_meta as $meta_key => $meta_value) {
      update_post_meta($post_id, $meta_key, $meta_value);
    }

    $use_schedule_builder = !empty($_POST['roxy_use_schedule_builder']);
    if ($use_schedule_builder) {
      $schedule_rows = self::sanitize_schedule_rows($_POST, $default_profile);
      if (!empty($schedule_rows)) {
        $first = array_shift($schedule_rows);
        update_post_meta($post_id, '_roxy_start', $first['start']);
        update_post_meta($post_id, '_roxy_pricing_profile', $first['profile']);

        if ((string) get_post_meta($post_id, '_roxy_schedule_generated', true) !== '1') {
          self::create_additional_showings_from_schedule($post_id, $post, $schedule_rows, $shared_meta);
          update_post_meta($post_id, '_roxy_schedule_generated', '1');
        }
        return;
      }
    }

    delete_post_meta($post_id, '_roxy_schedule_generated');
    $start = isset($_POST['roxy_start']) ? sanitize_text_field($_POST['roxy_start']) : '';
    update_post_meta($post_id, '_roxy_start', $start);
    update_post_meta($post_id, '_roxy_pricing_profile', $default_profile);
  }


  public static function row_actions(array $actions, $post): array {
    if (!($post instanceof \WP_Post) || $post->post_type !== self::POST_TYPE) {
      return $actions;
    }

    $start = (string) get_post_meta((int) $post->ID, '_roxy_start', true);
    if (!$start || !self::weekend_anchor_from_start($start)) {
      return $actions;
    }

    $url = wp_nonce_url(
      admin_url('admin.php?action=roxy_duplicate_weekend&post_id=' . (int) $post->ID),
      'roxy_duplicate_weekend_' . (int) $post->ID
    );

    $actions['roxy_duplicate_weekend'] = '<a href="' . esc_url($url) . '">Duplicate Weekend</a>' ;
    return $actions;
  }

  public static function handle_duplicate_weekend(): void {
    if (!current_user_can('edit_posts')) {
      wp_die('You do not have permission to duplicate weekends.');
    }

    $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
    if ($post_id <= 0) {
      wp_safe_redirect(admin_url('edit.php?post_type=' . self::POST_TYPE . '&roxy_dup_weekend=invalid'));
      exit;
    }

    check_admin_referer('roxy_duplicate_weekend_' . $post_id);

    $post = get_post($post_id);
    if (!$post || $post->post_type !== self::POST_TYPE) {
      wp_safe_redirect(admin_url('edit.php?post_type=' . self::POST_TYPE . '&roxy_dup_weekend=invalid'));
      exit;
    }

    $source_start = (string) get_post_meta($post_id, '_roxy_start', true);
    $anchor = self::weekend_anchor_from_start($source_start);
    if (!$anchor) {
      wp_safe_redirect(admin_url('edit.php?post_type=' . self::POST_TYPE . '&roxy_dup_weekend=unsupported'));
      exit;
    }

    $weekend_posts = self::find_weekend_posts((string) $post->post_title, $anchor);
    if (empty($weekend_posts)) {
      wp_safe_redirect(admin_url('edit.php?post_type=' . self::POST_TYPE . '&roxy_dup_weekend=notfound'));
      exit;
    }

    $next_anchor = $anchor->modify('+7 days');
    $existing_next = self::find_weekend_posts((string) $post->post_title, $next_anchor);
    if (!empty($existing_next)) {
      wp_safe_redirect(admin_url('edit.php?post_type=' . self::POST_TYPE . '&roxy_dup_weekend=exists'));
      exit;
    }

    $created = 0;
    foreach ($weekend_posts as $source) {
      $new_post_id = self::duplicate_showing_to_next_weekend((int) $source->ID);
      if ($new_post_id > 0) {
        $created++;
      }
    }

    $url = add_query_arg([
      'post_type' => self::POST_TYPE,
      'roxy_dup_weekend' => $created > 0 ? 'success' : 'failed',
      'roxy_dup_weekend_created' => $created,
    ], admin_url('edit.php'));
    wp_safe_redirect($url);
    exit;
  }

  public static function admin_notices(): void {
    if (!is_admin()) {
      return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== self::POST_TYPE) {
      return;
    }

    $status = isset($_GET['roxy_dup_weekend']) ? sanitize_key((string) $_GET['roxy_dup_weekend']) : '';
    if ($status === '') {
      return;
    }

    $messages = [
      'success' => ['updated', sprintf('%d showings created for next weekend.', (int) ($_GET['roxy_dup_weekend_created'] ?? 0))],
      'exists' => ['notice notice-warning', 'Next weekend already exists for this title. No duplicate showings were created.'],
      'unsupported' => ['notice notice-warning', 'Duplicate Weekend is only available for Friday, Saturday, or Sunday showings.'],
      'notfound' => ['notice notice-warning', 'Could not locate the source weekend showings for this title.'],
      'invalid' => ['notice notice-error', 'Duplicate Weekend could not be completed.'],
      'failed' => ['notice notice-error', 'No showings were created. Please try again.'],
    ];

    if (!isset($messages[$status])) {
      return;
    }

    [$class, $message] = $messages[$status];
    echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
  }

  public static function admin_columns(array $columns): array {
    $out = [];
    foreach ($columns as $key => $label) {
      $out[$key] = $label;
      if ($key === 'title') {
        $out['roxy_start'] = 'Start';
        $out['roxy_sold'] = 'Sold';
        $out['roxy_remaining'] = 'Remaining';
        $out['roxy_revenue'] = 'Revenue';
      }
    }
    return $out;
  }

  public static function render_admin_column(string $column, int $post_id): void {
    if (!in_array($column, ['roxy_start', 'roxy_sold', 'roxy_remaining', 'roxy_revenue'], true)) {
      return;
    }

    if ($column === 'roxy_start') {
      $start = (string) get_post_meta($post_id, '_roxy_start', true);
      echo $start ? esc_html(date_i18n('M j, Y g:ia', strtotime($start))) : '&mdash;';
      return;
    }

    $stats = Sales::get_showing_stats($post_id);
    if ($column === 'roxy_sold') {
      echo esc_html(number_format_i18n((int) ($stats['sold_qty'] ?? 0)));
      return;
    }

    if ($column === 'roxy_remaining') {
      $remaining = Capacity::remaining_seats_for_showing($post_id);
      echo is_null($remaining) ? 'Unlimited' : esc_html(number_format_i18n((int) $remaining));
      return;
    }

    if ($column === 'roxy_revenue') {
      echo wp_kses_post(wc_price((float) ($stats['gross_revenue'] ?? 0)));
    }
  }


  private static function is_new_showing($post): bool {
    return isset($post->post_status) && $post->post_status === 'auto-draft';
  }

  private static function default_schedule_rows(): array {
    $timezone = wp_timezone();
    $now = new \DateTimeImmutable('now', $timezone);
    $anchor = $now->modify('next friday')->setTime(0, 0);

    return [
      [
        'date' => $anchor->format('Y-m-d'),
        'time' => '19:30',
        'profile' => 'movie_evening',
      ],
      [
        'date' => $anchor->modify('+1 day')->format('Y-m-d'),
        'time' => '19:30',
        'profile' => 'movie_evening',
      ],
      [
        'date' => $anchor->modify('+2 day')->format('Y-m-d'),
        'time' => '14:30',
        'profile' => 'movie_matinee',
      ],
    ];
  }

  private static function render_schedule_row($index, string $date, string $time, string $profile): string {
    $profiles = [
      'movie_evening' => 'Standard',
      'movie_matinee' => 'Matinee',
      'live_event'    => 'Live Event',
      'free_event'    => 'Free Event',
    ];

    $html = '<tr>';
    $html .= '<td><input type="date" name="roxy_schedule_date[]" value="' . esc_attr($date) . '" style="width:100%"></td>';
    $html .= '<td><input type="time" name="roxy_schedule_time[]" value="' . esc_attr($time) . '" style="width:100%"></td>';
    $html .= '<td><select name="roxy_schedule_profile[]" style="width:100%">';
    foreach ($profiles as $key => $label) {
      $html .= '<option value="' . esc_attr($key) . '"' . selected($profile, $key, false) . '>' . esc_html($label) . '</option>';
    }
    $html .= '</select></td>';
    $html .= '<td><button type="button" class="button-link-delete roxy-remove-schedule-row">Remove</button></td>';
    $html .= '</tr>';
    return $html;
  }

  private static function sanitize_schedule_rows(array $source, string $fallback_profile): array {
    $dates = isset($source['roxy_schedule_date']) && is_array($source['roxy_schedule_date']) ? $source['roxy_schedule_date'] : [];
    $times = isset($source['roxy_schedule_time']) && is_array($source['roxy_schedule_time']) ? $source['roxy_schedule_time'] : [];
    $profiles = isset($source['roxy_schedule_profile']) && is_array($source['roxy_schedule_profile']) ? $source['roxy_schedule_profile'] : [];

    $rows = [];
    $count = max(count($dates), count($times), count($profiles));
    for ($i = 0; $i < $count; $i++) {
      $date = sanitize_text_field((string) ($dates[$i] ?? ''));
      $time = sanitize_text_field((string) ($times[$i] ?? ''));
      $profile = sanitize_key((string) ($profiles[$i] ?? $fallback_profile));
      if ($date === '' || $time === '') {
        continue;
      }
      $start = $date . 'T' . $time;
      if (!strtotime($start)) {
        continue;
      }
      if (!in_array($profile, ['movie_evening', 'movie_matinee', 'live_event', 'free_event'], true)) {
        $profile = $fallback_profile;
      }
      $rows[] = [
        'date' => $date,
        'time' => $time,
        'profile' => $profile,
        'start' => $start,
      ];
    }

    return $rows;
  }

  private static function create_additional_showings_from_schedule(int $source_post_id, $post, array $schedule_rows, array $shared_meta): void {
    if (empty($schedule_rows)) {
      return;
    }

    $taxonomy_terms = wp_get_object_terms($source_post_id, 'roxy_show_type', ['fields' => 'ids']);
    $thumbnail_id = get_post_thumbnail_id($source_post_id);
    self::$is_generating_schedule = true;

    foreach ($schedule_rows as $row) {
      $new_post_id = wp_insert_post([
        'post_type' => self::POST_TYPE,
        'post_status' => 'publish',
        'post_title' => (string) $post->post_title,
        'post_content' => (string) $post->post_content,
        'post_excerpt' => (string) $post->post_excerpt,
        'post_author' => (int) $post->post_author,
      ], true);

      if (is_wp_error($new_post_id) || !$new_post_id) {
        continue;
      }

      foreach ($shared_meta as $meta_key => $meta_value) {
        update_post_meta($new_post_id, $meta_key, $meta_value);
      }
      update_post_meta($new_post_id, '_roxy_start', $row['start']);
      update_post_meta($new_post_id, '_roxy_pricing_profile', $row['profile']);
      update_post_meta($new_post_id, '_roxy_schedule_generated', '1');
      update_post_meta($new_post_id, '_roxy_generated_from_builder', '1');

      if (!empty($taxonomy_terms) && !is_wp_error($taxonomy_terms)) {
        wp_set_object_terms($new_post_id, $taxonomy_terms, 'roxy_show_type', false);
      }
      if ($thumbnail_id) {
        set_post_thumbnail($new_post_id, $thumbnail_id);
      }
      if (class_exists(__NAMESPACE__ . '\\Products')) {
        Products::ensure_products_for_showing((int) $new_post_id);
      }
    }

    self::$is_generating_schedule = false;
  }



  private static function weekend_anchor_from_start(string $start): ?\DateTimeImmutable {
    if ($start === '') {
      return null;
    }
    $timestamp = strtotime($start);
    if (!$timestamp) {
      return null;
    }
    $dt = new \DateTimeImmutable('@' . $timestamp);
    $dt = $dt->setTimezone(wp_timezone())->setTime(0, 0);
    $day = (int) $dt->format('N');
    if ($day === 5) {
      return $dt;
    }
    if ($day === 6) {
      return $dt->modify('-1 day');
    }
    if ($day === 7) {
      return $dt->modify('-2 days');
    }
    return null;
  }

  private static function find_weekend_posts(string $title, \DateTimeImmutable $anchor): array {
    $start_window = $anchor->format('Y-m-d\T00:00');
    $end_window = $anchor->modify('+2 days')->format('Y-m-d\T23:59');

    $query = new \WP_Query([
      'post_type' => self::POST_TYPE,
      'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
      'posts_per_page' => -1,
      'orderby' => 'meta_value',
      'order' => 'ASC',
      'meta_key' => '_roxy_start',
      'meta_query' => [[
        'key' => '_roxy_start',
        'value' => [$start_window, $end_window],
        'compare' => 'BETWEEN',
        'type' => 'CHAR',
      ]],
      'title' => $title,
      'suppress_filters' => true,
    ]);

    return is_array($query->posts) ? $query->posts : [];
  }

  private static function duplicate_showing_to_next_weekend(int $source_post_id): int {
    $source = get_post($source_post_id);
    if (!$source || $source->post_type !== self::POST_TYPE) {
      return 0;
    }

    $start = (string) get_post_meta($source_post_id, '_roxy_start', true);
    if ($start === '') {
      return 0;
    }
    try {
      $start_dt = new \DateTimeImmutable($start, wp_timezone());
    } catch (\Exception $e) {
      return 0;
    }
    $new_start = $start_dt->modify('+7 days')->format('Y-m-d\TH:i');

    $new_post_id = wp_insert_post([
      'post_type' => self::POST_TYPE,
      'post_status' => $source->post_status === 'publish' ? 'publish' : 'draft',
      'post_title' => (string) $source->post_title,
      'post_content' => (string) $source->post_content,
      'post_excerpt' => (string) $source->post_excerpt,
      'post_author' => (int) $source->post_author,
    ], true);

    if (is_wp_error($new_post_id) || !$new_post_id) {
      return 0;
    }

    $all_meta = get_post_meta($source_post_id);
    $excluded = [
      '_roxy_pid_adult', '_roxy_pid_discount', '_roxy_pid_matinee', '_roxy_pid_live1', '_roxy_pid_live2', '_roxy_pid_subscriber',
      '_roxy_sales_stats', '_roxy_schedule_generated', '_roxy_generated_from_builder', '_edit_lock', '_edit_last', '_thumbnail_id',
    ];
    foreach ($all_meta as $meta_key => $values) {
      if (in_array($meta_key, $excluded, true)) {
        continue;
      }
      delete_post_meta($new_post_id, $meta_key);
      foreach ((array) $values as $value) {
        add_post_meta($new_post_id, $meta_key, maybe_unserialize($value));
      }
    }

    update_post_meta($new_post_id, '_roxy_start', $new_start);

    $taxonomy_terms = wp_get_object_terms($source_post_id, 'roxy_show_type', ['fields' => 'ids']);
    if (!empty($taxonomy_terms) && !is_wp_error($taxonomy_terms)) {
      wp_set_object_terms($new_post_id, $taxonomy_terms, 'roxy_show_type', false);
    }

    $thumbnail_id = get_post_thumbnail_id($source_post_id);
    if ($thumbnail_id) {
      set_post_thumbnail($new_post_id, $thumbnail_id);
    }

    if (class_exists(__NAMESPACE__ . '\Products')) {
      Products::ensure_products_for_showing((int) $new_post_id);
    }

    return (int) $new_post_id;
  }

  public static function maybe_run_one_time_cleanup(): void {
    if (!current_user_can('manage_options')) {
      return;
    }

    $flag = 'roxy_st_cleanup_test_showings_021019_done';
    if (get_option($flag) === '1') {
      return;
    }

    $ids = get_posts([
      'post_type' => self::POST_TYPE,
      'post_status' => 'any',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'title' => 'Test',
      'suppress_filters' => true,
    ]);

    foreach ($ids as $id) {
      foreach (['_roxy_pid_adult','_roxy_pid_discount','_roxy_pid_matinee','_roxy_pid_live1','_roxy_pid_live2','_roxy_pid_subscriber'] as $product_meta_key) {
        $pid = (int) get_post_meta((int) $id, $product_meta_key, true);
        if ($pid > 0 && get_post_type($pid) === 'product') {
          wp_delete_post($pid, true);
        }
      }
      wp_delete_post((int) $id, true);
    }

    update_option($flag, '1', false);
  }


  private static function sanitize_legacy_product_ids($raw): string {
    $lines = preg_split('/[\r\n,]+/', (string) $raw);
    $ids = [];
    foreach ((array) $lines as $line) {
      $id = (int) trim((string) $line);
      if ($id > 0) {
        $ids[$id] = $id;
      }
    }
    return implode("\n", array_values($ids));
  }

  private static function legacy_product_ids_text(int $post_id): string {
    $raw = get_post_meta($post_id, '_roxy_legacy_product_ids', true);
    if (is_array($raw)) {
      $raw = implode("\n", array_map('intval', $raw));
    }
    return self::sanitize_legacy_product_ids((string) $raw);
  }

  private static function dashboard_card(string $label, string $value): string {
    return '<div style="background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:12px"><div style="font-size:12px;color:#666;margin-bottom:4px">' . esc_html($label) . '</div><div style="font-size:20px;font-weight:700">' . wp_kses_post($value) . '</div></div>';
  }
}
