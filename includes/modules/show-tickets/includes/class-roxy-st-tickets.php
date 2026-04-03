<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

class Tickets {
  const POST_TYPE = 'roxy_ticket';
  const META_TOKEN = '_roxy_ticket_token';
  const META_STATE = '_roxy_ticket_state';
  const META_ORDER_ID = '_roxy_ticket_order_id';
  const META_SHOWING_ID = '_roxy_ticket_showing_id';
  const META_PRODUCT_ID = '_roxy_ticket_product_id';
  const META_ORDER_ITEM_ID = '_roxy_ticket_order_item_id';
  const META_TICKET_TYPE = '_roxy_ticket_type';
  const META_CHECKED_IN = '_roxy_checked_in';
  const META_CHECKED_IN_AT = '_roxy_checked_in_at';
  const META_CHECKED_IN_BY = '_roxy_checked_in_by';
  const META_QR_URL = '_roxy_ticket_qr_url';

  public static function init(): void {
    add_action('init', [__CLASS__, 'register_post_type']);
    add_action('woocommerce_checkout_order_processed', [__CLASS__, 'on_order_changed'], 30, 1);
    add_action('woocommerce_order_status_changed', [__CLASS__, 'on_order_changed'], 30, 1);
    add_action('woocommerce_refund_created', [__CLASS__, 'on_refund_created'], 30, 2);

    add_action('admin_post_roxy_st_check_in_ticket', [__CLASS__, 'handle_check_in']);
    add_action('admin_post_roxy_st_uncheck_in_ticket', [__CLASS__, 'handle_uncheck_in']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    add_action('wp_ajax_roxy_st_door_validate', [__CLASS__, 'ajax_door_validate']);
    add_action('wp_ajax_roxy_st_door_checkin', [__CLASS__, 'ajax_door_checkin']);
    add_action('wp_ajax_roxy_st_door_stats', [__CLASS__, 'ajax_door_stats']);
    add_action('wp_ajax_roxy_st_qr', [__CLASS__, 'ajax_qr']);
    add_action('wp_ajax_nopriv_roxy_st_qr', [__CLASS__, 'ajax_qr']);

    add_action('woocommerce_thankyou', [__CLASS__, 'render_order_tickets'], 25, 1);
    add_action('woocommerce_view_order', [__CLASS__, 'render_order_tickets'], 25, 1);
    add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'render_order_tickets_from_order'], 25, 1);
    add_action('woocommerce_email_after_order_table', [__CLASS__, 'render_order_tickets_in_email'], 25, 4);
  }

  public static function register_post_type(): void {
    register_post_type(self::POST_TYPE, [
      'label' => 'Roxy Tickets',
      'public' => false,
      'show_ui' => false,
      'exclude_from_search' => true,
      'supports' => ['title'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  public static function enqueue_admin_assets(string $hook): void {
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $tab  = isset($_GET['tab'])  ? sanitize_key(wp_unslash($_GET['tab']))  : '';

    $is_door_mode = ($page === 'roxy-show-tickets' && $tab === 'door-mode');
    $is_checkin   = ($page === 'roxy-show-tickets' && $tab === 'check-in');

    // Pre-load jsQR on both scanner pages so it is ready before any user gesture fires
    if ($is_door_mode || $is_checkin) {
      wp_enqueue_script('jsqr', 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js', [], '1.4.0', true);
    }

    if (!$is_door_mode) {
      return;
    }

    wp_enqueue_style('roxy-st-door-mode', ROXY_ST_URL . 'assets/css/door-mode.css', [], ROXY_ST_VER);
    wp_enqueue_script('roxy-st-door-mode', ROXY_ST_URL . 'assets/js/door-mode.js', ['jsqr'], ROXY_ST_VER, true);
    wp_localize_script('roxy-st-door-mode', 'RoxyDoorMode', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('roxy_st_door_mode'),
      'checkInNonce' => wp_create_nonce('roxy_st_door_checkin'),
      'manualPage' => admin_url('admin.php?page=roxy-show-tickets&tab=check-in'),
      'doorPage'   => admin_url('admin.php?page=roxy-show-tickets&tab=door-mode'),
      'allEventsLabel' => 'All Events',
      'noEventSelectedText' => 'Showing all events. Select a specific event to enable wrong-ticket protection and live attendance.',
      'nfcUnsupportedText' => 'NFC scanning is not supported on this device or browser. Use QR or manual lookup.',
    ]);
  }

  public static function on_order_changed(int $order_id): void {
    self::sync_order_tickets($order_id);
  }

  public static function on_refund_created(int $refund_id, array $args = []): void {
    $order_id = isset($args['order_id']) ? (int) $args['order_id'] : 0;
    if ($order_id <= 0) return;

    if (!empty($args['line_items']) && is_array($args['line_items'])) {
      self::invalidate_refunded_line_items($order_id, $args['line_items']);
    }
    self::sync_order_tickets($order_id);
  }

  public static function sync_order_tickets(int $order_id): void {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $order_status = (string) $order->get_status();
    $state_for_order = self::state_for_order_status($order_status);
    $order_changed = false;
    $used_ticket_ids = [];

    foreach ($order->get_items() as $item_id => $item) {
      $product_id = (int) $item->get_product_id();
      if ($product_id <= 0) continue;

      $showing_id = (int) get_post_meta($product_id, ROXY_ST_META_SHOWING_ID, true);
      if ($showing_id <= 0) continue;

      $ticket_type = (string) get_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, true);
      $qty = max(0, (int) $item->get_quantity());
      $existing = self::normalize_ticket_ids($item->get_meta('_roxy_ticket_ids', true));

      $keep = [];
      for ($i = 0; $i < $qty; $i++) {
        $ticket_id = isset($existing[$i]) ? (int) $existing[$i] : 0;
        if ($ticket_id > 0 && get_post_type($ticket_id) === self::POST_TYPE) {
          self::update_ticket_record($ticket_id, $order, $item, $showing_id, $ticket_type, $state_for_order);
        } else {
          $ticket_id = self::create_ticket_record($order, $item, $showing_id, $ticket_type, $state_for_order, $i + 1);
        }
        if ($ticket_id > 0) {
          $keep[] = $ticket_id;
          $used_ticket_ids[$ticket_id] = $ticket_id;
        }
      }

      foreach (array_slice($existing, $qty) as $extra_id) {
        self::set_ticket_state((int) $extra_id, self::invalid_state_for_order($order_status));
      }

      if ($existing !== $keep) {
        $item->update_meta_data('_roxy_ticket_ids', $keep);
        $order_changed = true;
      }
    }

    if ($order_changed) {
      $order->save();
    }
  }

  private static function create_ticket_record($order, $item, int $showing_id, string $ticket_type, string $state, int $sequence): int {
    $order_id = (int) $order->get_id();
    $product_id = (int) $item->get_product_id();
    $showing_title = get_the_title($showing_id);
    $ticket_label = self::ticket_label($showing_id, $ticket_type);
    $title = trim(sprintf('%s — %s — Order #%d — Ticket %d', $showing_title, $ticket_label, $order_id, $sequence));

    $ticket_id = wp_insert_post([
      'post_type' => self::POST_TYPE,
      'post_status' => 'publish',
      'post_title' => $title,
    ], true);

    if (is_wp_error($ticket_id) || !$ticket_id) {
      return 0;
    }

    update_post_meta($ticket_id, self::META_TOKEN, self::generate_token());
    update_post_meta($ticket_id, self::META_QR_URL, self::qr_image_url((string) get_post_meta($ticket_id, self::META_TOKEN, true)));
    self::update_ticket_record((int) $ticket_id, $order, $item, $showing_id, $ticket_type, $state);
    return (int) $ticket_id;
  }

  private static function update_ticket_record(int $ticket_id, $order, $item, int $showing_id, string $ticket_type, string $state): void {
    $order_id = (int) $order->get_id();
    $product_id = (int) $item->get_product_id();
    $ticket_label = self::ticket_label($showing_id, $ticket_type);
    $token = (string) get_post_meta($ticket_id, self::META_TOKEN, true);
    if ($token === '') {
      $token = self::generate_token();
      update_post_meta($ticket_id, self::META_TOKEN, $token);
    }
    update_post_meta($ticket_id, self::META_QR_URL, self::qr_image_url($token));

    update_post_meta($ticket_id, self::META_ORDER_ID, $order_id);
    update_post_meta($ticket_id, self::META_SHOWING_ID, $showing_id);
    update_post_meta($ticket_id, self::META_PRODUCT_ID, $product_id);
    update_post_meta($ticket_id, self::META_ORDER_ITEM_ID, (int) $item->get_id());
    update_post_meta($ticket_id, self::META_TICKET_TYPE, $ticket_type);
    update_post_meta($ticket_id, '_roxy_ticket_order_number', $order->get_order_number());
    update_post_meta($ticket_id, '_roxy_ticket_ticket_label', $ticket_label);
    update_post_meta($ticket_id, '_roxy_ticket_showing_title', get_the_title($showing_id));
    update_post_meta($ticket_id, '_roxy_ticket_customer_name', trim($order->get_formatted_billing_full_name()));
    update_post_meta($ticket_id, '_roxy_ticket_customer_email', (string) $order->get_billing_email());

    $current_state = (string) get_post_meta($ticket_id, self::META_STATE, true);
    if ($current_state !== 'checked_in') {
      update_post_meta($ticket_id, self::META_STATE, $state);
    }
  }

  private static function invalidate_refunded_line_items(int $order_id, array $line_items): void {
    foreach ($line_items as $item_id => $row) {
      $refund_qty = isset($row['qty']) ? abs((int) $row['qty']) : 0;
      if ($refund_qty <= 0) continue;

      $ticket_ids = self::tickets_for_order_item($order_id, (int) $item_id);
      if (!$ticket_ids) continue;

      $invalidated = 0;
      foreach ($ticket_ids as $ticket_id) {
        $state = (string) get_post_meta($ticket_id, self::META_STATE, true);
        if (in_array($state, ['refunded', 'cancelled'], true)) continue;
        if ((int) get_post_meta($ticket_id, self::META_CHECKED_IN, true) === 1) continue;
        self::set_ticket_state($ticket_id, 'refunded');
        $invalidated++;
        if ($invalidated >= $refund_qty) break;
      }
    }
  }

  private static function tickets_for_order_item(int $order_id, int $item_id): array {
    $q = new \WP_Query([
      'post_type' => self::POST_TYPE,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'meta_query' => [
        ['key' => self::META_ORDER_ID, 'value' => $order_id],
        ['key' => self::META_ORDER_ITEM_ID, 'value' => $item_id],
      ],
      'orderby' => 'ID',
      'order' => 'ASC',
      'no_found_rows' => true,
    ]);
    return array_map('intval', $q->posts ?: []);
  }

  public static function count_checked_in_for_showing(int $showing_id): int {
    $posts = get_posts([
      'post_type' => self::POST_TYPE,
      'post_status' => 'publish',
      'numberposts' => -1,
      'fields' => 'ids',
      'meta_query' => [
        ['key' => self::META_SHOWING_ID, 'value' => $showing_id],
        ['key' => self::META_CHECKED_IN, 'value' => '1'],
      ],
      'no_found_rows' => true,
    ]);
    return count($posts);
  }

  public static function get_door_mode_showings(): array {
    $posts = get_posts([
      'post_type' => CPT::POST_TYPE,
      'post_status' => 'publish',
      'numberposts' => 40,
      'meta_key' => '_roxy_start',
      'orderby' => 'meta_value',
      'order' => 'ASC',
      'no_found_rows' => true,
    ]);

    $out = [];
    foreach ($posts as $post) {
      $showing_id = (int) $post->ID;
      if (get_post_meta($showing_id, '_roxy_pricing_profile', true) === 'free_event') continue;
      $start = (string) get_post_meta($showing_id, '_roxy_start', true);
      $label = get_the_title($showing_id);
      if ($start !== '') {
        $label .= ' — ' . date_i18n('M j, Y g:ia', strtotime($start));
      }
      $out[] = [
        'id' => $showing_id,
        'label' => $label,
      ];
    }
    return $out;
  }

  public static function get_default_door_mode_showing_id(): int {
    $posts = get_posts([
      'post_type' => CPT::POST_TYPE,
      'post_status' => 'publish',
      'numberposts' => 20,
      'meta_key' => '_roxy_start',
      'orderby' => 'meta_value',
      'order' => 'ASC',
      'meta_query' => [[
        'key' => '_roxy_start',
        'value' => '',
        'compare' => '!=',
      ]],
      'no_found_rows' => true,
    ]);

    if (!$posts) {
      return 0;
    }

    $now = current_time('timestamp');
    $best_id = 0;
    $best_distance = null;

    foreach ($posts as $post) {
      $showing_id = (int) $post->ID;
      if (get_post_meta($showing_id, '_roxy_pricing_profile', true) === 'free_event') continue;
      $start_raw = (string) get_post_meta($showing_id, '_roxy_start', true);
      if ($start_raw === '') {
        continue;
      }
      $start_ts = strtotime($start_raw);
      if (!$start_ts) {
        continue;
      }

      $delta = $start_ts - $now;
      if ($delta < -3600 || $delta > 4 * HOUR_IN_SECONDS) {
        continue;
      }

      $distance = abs($delta);
      if ($best_id === 0 || $best_distance === null || $distance < $best_distance) {
        $best_id = $showing_id;
        $best_distance = $distance;
      }
    }

    return $best_id;
  }

  private static function door_stats_payload(int $showing_id): array {
    $capacity = Capacity::capacity_limit_for_showing($showing_id);
    $sold = Sales::sold_qty_for_showing($showing_id);
    $checked_in = self::count_checked_in_for_showing($showing_id);
    $remaining = Capacity::remaining_seats_for_showing($showing_id);

    return [
      'showing_id' => $showing_id,
      'showing_title' => get_the_title($showing_id),
      'capacity' => is_null($capacity) ? null : (int) $capacity,
      'sold' => (int) $sold,
      'checked_in' => (int) $checked_in,
      'remaining' => is_null($remaining) ? null : (int) $remaining,
      'not_checked_in' => max(0, (int) $sold - (int) $checked_in),
    ];
  }

  public static function get_order_ticket_ids(int $order_id): array {
    $q = new \WP_Query([
      'post_type' => self::POST_TYPE,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'meta_key' => self::META_ORDER_ID,
      'meta_value' => $order_id,
      'orderby' => 'ID',
      'order' => 'ASC',
      'no_found_rows' => true,
    ]);
    return array_map('intval', $q->posts ?: []);
  }

  public static function get_checked_in_ticket_ids_for_order_item(int $order_id, int $item_id): array {
    $ticket_ids = self::tickets_for_order_item($order_id, $item_id);
    if (!$ticket_ids) {
      return [];
    }

    $out = [];
    foreach ($ticket_ids as $ticket_id) {
      if ((int) get_post_meta($ticket_id, self::META_CHECKED_IN, true) === 1) {
        $out[] = (int) $ticket_id;
      }
    }
    return $out;
  }

  public static function check_in_ticket(int $ticket_id, int $user_id = 0): bool {
    if ($ticket_id <= 0 || get_post_type($ticket_id) !== self::POST_TYPE) {
      return false;
    }
    if (!self::can_check_in($ticket_id)) {
      return false;
    }

    if ($user_id <= 0) {
      $user_id = get_current_user_id();
    }

    update_post_meta($ticket_id, self::META_CHECKED_IN, '1');
    update_post_meta($ticket_id, self::META_CHECKED_IN_AT, current_time('mysql'));
    update_post_meta($ticket_id, self::META_CHECKED_IN_BY, (int) $user_id);
    update_post_meta($ticket_id, self::META_STATE, 'checked_in');
    return true;
  }

  public static function undo_check_in_ticket(int $ticket_id): bool {
    if ($ticket_id <= 0 || get_post_type($ticket_id) !== self::POST_TYPE) {
      return false;
    }

    delete_post_meta($ticket_id, self::META_CHECKED_IN);
    delete_post_meta($ticket_id, self::META_CHECKED_IN_AT);
    delete_post_meta($ticket_id, self::META_CHECKED_IN_BY);

    $order_id = (int) get_post_meta($ticket_id, self::META_ORDER_ID, true);
    $order = wc_get_order($order_id);
    update_post_meta($ticket_id, self::META_STATE, self::state_for_order_status($order ? (string) $order->get_status() : 'processing'));
    return true;
  }

  public static function set_order_item_check_in_qty(int $order_id, int $item_id, int $target_qty, int $user_id = 0): array {
    $target_qty = max(0, $target_qty);
    $ticket_ids = self::tickets_for_order_item($order_id, $item_id);
    if (!$ticket_ids) {
      return ['changed' => 0, 'checked_in' => 0, 'undone' => 0, 'current' => 0];
    }

    $checked_ids = [];
    $available_ids = [];

    foreach ($ticket_ids as $ticket_id) {
      if ((int) get_post_meta($ticket_id, self::META_CHECKED_IN, true) === 1) {
        $checked_ids[] = (int) $ticket_id;
      } elseif (self::can_check_in((int) $ticket_id)) {
        $available_ids[] = (int) $ticket_id;
      }
    }

    $current_qty = count($checked_ids);
    $changed = 0;
    $checked_count = 0;
    $undone_count = 0;

    if ($current_qty < $target_qty) {
      $needed = min($target_qty - $current_qty, count($available_ids));
      for ($i = 0; $i < $needed; $i++) {
        if (self::check_in_ticket($available_ids[$i], $user_id)) {
          $changed++;
          $checked_count++;
        }
      }
    } elseif ($current_qty > $target_qty) {
      $to_undo = $current_qty - $target_qty;
      $checked_ids = array_reverse($checked_ids);
      for ($i = 0; $i < $to_undo; $i++) {
        if (!isset($checked_ids[$i])) break;
        if (self::undo_check_in_ticket($checked_ids[$i])) {
          $changed++;
          $undone_count++;
        }
      }
    }

    return [
      'changed' => $changed,
      'checked_in' => $checked_count,
      'undone' => $undone_count,
      'current' => max(0, min($target_qty, count($ticket_ids))),
    ];
  }

  public static function render_order_tickets(int $order_id): void {
    if ($order_id <= 0) return;
    $order = wc_get_order($order_id);
    if (!$order) return;
    self::render_order_tickets_html($order);
  }

  public static function render_order_tickets_from_order($order): void {
    if (!$order || !is_a($order, 'WC_Order')) return;
    self::render_order_tickets_html($order);
  }

  public static function render_order_tickets_in_email($order, bool $sent_to_admin = false, bool $plain_text = false, $email = null): void {
    if ($sent_to_admin || $plain_text || !$order || !is_a($order, 'WC_Order')) return;
    self::render_order_tickets_html($order, true);
  }

  private static function render_order_tickets_html($order, bool $is_email = false): void {
    if (!$order) return;
    static $rendered = [];
    $order_id = (int) $order->get_id();
    if (isset($rendered[$order_id])) return;
    $rendered[$order_id] = true;

    $ticket_ids = self::get_order_ticket_ids($order_id);
    if (!$ticket_ids) return;

    echo '<section class="woocommerce-order-details roxy-st-tickets" style="margin-top:24px">';
    echo '<h2 class="woocommerce-order-details__title">Your Tickets</h2>';
    echo '<p style="margin:0 0 14px;opacity:.85">You can access these QR tickets again anytime in <strong>My Account → My Orders → View Order</strong>' . ($is_email ? ', and in this email.' : ', and in your email.') . '</p>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">';
    foreach ($ticket_ids as $ticket_id) {
      $token = (string) get_post_meta($ticket_id, self::META_TOKEN, true);
      $showing_title = (string) get_post_meta($ticket_id, '_roxy_ticket_showing_title', true);
      $ticket_label = (string) get_post_meta($ticket_id, '_roxy_ticket_ticket_label', true);
      $state = (string) get_post_meta($ticket_id, self::META_STATE, true);
      $checked_in = (int) get_post_meta($ticket_id, self::META_CHECKED_IN, true) === 1;
      $qr_url = self::qr_image_url($token);

      echo '<div style="border:1px solid #ddd;border-radius:12px;padding:14px;text-align:center">';
      echo '<div style="font-weight:700;margin-bottom:4px">' . esc_html($showing_title) . '</div>';
      echo '<div style="opacity:.8;margin-bottom:10px">' . esc_html($ticket_label) . '</div>';
      echo '<img src="' . esc_url($qr_url) . '" alt="Ticket QR" style="width:200px;height:200px;max-width:100%;margin:0 auto 10px;display:block" />';
      echo '<div style="font-size:12px;word-break:break-all;opacity:.8;margin-bottom:8px">' . esc_html($token) . '</div>';
      echo '<div style="font-weight:700;color:' . esc_attr(self::state_color($state, $checked_in)) . '">' . esc_html(self::state_label($state, $checked_in)) . '</div>';
      echo '</div>';
    }
    echo '</div></section>';
  }

  public static function render_door_mode_page(bool $wrap = true): void {
    if (!current_user_can('edit_posts')) {
      wp_die('You do not have permission to access this page.');
    }

    $manual_token = isset($_GET['ticket_token']) ? sanitize_text_field(wp_unslash($_GET['ticket_token'])) : '';
    $selected_showing_id = isset($_GET['door_showing_id']) ? max(0, (int) $_GET['door_showing_id']) : self::get_default_door_mode_showing_id();
    $ticket = $manual_token !== '' ? self::get_ticket_by_token($manual_token) : null;
    $door_showings = self::get_door_mode_showings();

    if ($wrap) echo '<div class="wrap roxy-door-wrap">';
    echo '<div class="roxy-door-shell">';
    echo '<div class="roxy-door-header">';
    echo '<div>';
    echo '<h1>Roxy Door Mode</h1>';
    echo '<p>Camera-first scanning for phones and tablets. Scan a ticket, review the result, then tap Admit.</p>';
    echo '</div>';
    echo '<div class="roxy-door-links">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=roxy-show-tickets&tab=check-in')) . '" class="button">Manual Check-In</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=roxy-show-tickets&tab=door-mode')) . '" class="button">Reset</a>';
    echo '</div>';
    echo '</div>';

    echo '<div class="roxy-door-grid">';
    echo '<section class="roxy-door-camera-card">';
    echo '<div class="roxy-door-camera-head">';
    echo '<h2>Scan Ticket</h2>';
    echo '<div class="roxy-door-camera-actions">';
    echo '<button type="button" class="button button-primary" id="roxy-door-start">Start Camera</button>';
    echo '<button type="button" class="button" id="roxy-door-stop" hidden>Stop</button>';
    echo '<button type="button" class="button" id="roxy-door-torch" hidden aria-pressed="false">🔦 Light</button>';
    echo '</div>';
    echo '</div>';
    echo '<div class="roxy-door-video-wrap">';
    echo '<video id="roxy-door-video" playsinline muted></video>';
    echo '<div class="roxy-door-scan-box" aria-hidden="true"></div>';
    echo '<div class="roxy-door-overlay-text" id="roxy-door-overlay-text">Present ticket QR code</div>';
    echo '</div>';
    echo '<div class="roxy-door-camera-note" id="roxy-door-camera-note">Tap Start Camera, allow camera access, and aim at the customer&#8217;s QR ticket.</div>';
    echo '<div class="roxy-door-controls-row">';
    echo '<label class="roxy-door-toggle"><input type="checkbox" id="roxy-door-auto-resume" checked> <span>Auto Admit</span></label>';
    echo '<label class="roxy-door-lock"><span>Event Lock</span><select id="roxy-door-showing-lock">';
    echo '<option value="0"' . selected($selected_showing_id, 0, false) . '>All Events</option>';
    foreach ($door_showings as $door_showing) {
      echo '<option value="' . esc_attr((string) $door_showing['id']) . '"' . selected($selected_showing_id, (int) $door_showing['id'], false) . '>' . esc_html((string) $door_showing['label']) . '</option>';
    }
    echo '</select></label>';
    echo '</div>';
    echo '<div class="roxy-door-attendance-card" id="roxy-door-attendance"><div class="roxy-door-attendance-empty">Select an event lock to show live attendance here.</div></div>';
    echo '</section>';

    echo '<aside class="roxy-door-recent-card">';
    echo '<div class="roxy-door-camera-head">';
    echo '<h2>Recent Scans</h2>';
    echo '<div class="roxy-door-recent-help">Last 8 on this device</div>';
    echo '</div>';
    echo '<div class="roxy-door-recent-list" id="roxy-door-recent-list">';
    echo '<div class="roxy-door-recent-empty">No scans yet. Recent admissions and scan issues will appear here.</div>';
    echo '</div>';
    echo '</aside>';

    echo '<div class="roxy-door-flash" id="roxy-door-flash" aria-hidden="true"></div>';
    echo '<div class="roxy-door-modal" id="roxy-door-modal" aria-hidden="true">';
    echo '<div class="roxy-door-modal-backdrop roxy-door-rescan"></div>';
    echo '<div class="roxy-door-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="roxy-door-modal-title">';
    echo '<div id="roxy-door-result" data-manual-token="' . esc_attr($manual_token) . '">';
    if ($manual_token !== '') {
      if ($ticket instanceof \WP_Post) {
        self::render_door_mode_result_markup(self::door_ticket_payload($ticket));
      } else {
        self::render_door_mode_not_found_markup($manual_token);
      }
    } else {
      echo '<div class="roxy-door-state roxy-door-state-idle">';
      echo '<div class="roxy-door-kicker">Ready</div>';
      echo '<div class="roxy-door-title" id="roxy-door-modal-title">Waiting for next ticket</div>';
      echo '<p>Use the camera for normal flow. Manual lookup and Roxy Check-In stay available for edge cases.</p>';
      echo '</div>';
    }
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
  }

  private static function render_door_mode_result(\WP_Post $ticket): void {
    self::render_door_mode_result_markup(self::door_ticket_payload($ticket));
  }

  private static function render_door_mode_not_found(string $token = ''): void {
    self::render_door_mode_not_found_markup($token);
  }

  private static function door_ticket_payload(\WP_Post $ticket): array {
    $ticket_id = (int) $ticket->ID;
    $token = (string) get_post_meta($ticket_id, self::META_TOKEN, true);
    $showing_title = (string) get_post_meta($ticket_id, '_roxy_ticket_showing_title', true);
    $ticket_label = (string) get_post_meta($ticket_id, '_roxy_ticket_ticket_label', true);
    $customer_name = (string) get_post_meta($ticket_id, '_roxy_ticket_customer_name', true);
    $customer_email = (string) get_post_meta($ticket_id, '_roxy_ticket_customer_email', true);
    $order_number = (string) get_post_meta($ticket_id, '_roxy_ticket_order_number', true);
    $state = (string) get_post_meta($ticket_id, self::META_STATE, true);
    $checked_in = (int) get_post_meta($ticket_id, self::META_CHECKED_IN, true) === 1;
    $checked_in_at = (string) get_post_meta($ticket_id, self::META_CHECKED_IN_AT, true);

    $status = 'valid';
    $headline = 'Valid Ticket';
    $subline = 'Review details, then tap Admit, or enable auto admit for the fastest entry flow.';
    if ($checked_in || $state === 'checked_in') {
      $status = 'used';
      $headline = 'Already Checked In';
      $subline = 'This ticket was already admitted.';
    } elseif (!self::can_check_in($ticket_id)) {
      $status = 'invalid';
      $headline = 'Invalid Ticket';
      $subline = 'This ticket cannot be admitted from Door Mode.';
    }

    return [
      'found' => true,
      'status' => $status,
      'showing_id' => (int) get_post_meta($ticket_id, self::META_SHOWING_ID, true),
      'headline' => $headline,
      'subline' => $subline,
      'ticket_id' => $ticket_id,
      'token' => $token,
      'showing_title' => $showing_title,
      'ticket_label' => $ticket_label,
      'customer_name' => $customer_name ?: 'Unknown',
      'customer_email' => $customer_email,
      'order_number' => $order_number !== '' ? $order_number : (string) get_post_meta($ticket_id, self::META_ORDER_ID, true),
      'checked_in' => $checked_in,
      'checked_in_at' => $checked_in_at,
      'can_check_in' => self::can_check_in($ticket_id),
      'can_undo' => $checked_in,
    ];
  }

  private static function render_door_mode_result_markup(array $payload): void {
    $status = isset($payload['status']) ? (string) $payload['status'] : 'idle';
    echo '<div class="roxy-door-state roxy-door-state-' . esc_attr($status) . '">';
    echo '<div class="roxy-door-kicker">' . esc_html($status === 'valid' ? 'Ready to Admit' : ($status === 'used' ? 'Already Used' : 'Review')) . '</div>';
    echo '<div class="roxy-door-title" id="roxy-door-modal-title">' . esc_html((string) ($payload['headline'] ?? 'Ticket')) . '</div>';
    echo '<p>' . esc_html((string) ($payload['subline'] ?? '')) . '</p>';
    echo '<dl class="roxy-door-details">';
    echo '<div><dt>Guest</dt><dd>' . esc_html((string) ($payload['customer_name'] ?? 'Unknown')) . '</dd></div>';
    echo '<div><dt>Event</dt><dd>' . esc_html((string) ($payload['showing_title'] ?? '')) . '</dd></div>';
    echo '<div><dt>Ticket</dt><dd>' . esc_html((string) ($payload['ticket_label'] ?? '')) . '</dd></div>';
    echo '<div><dt>Order</dt><dd>#' . esc_html((string) ($payload['order_number'] ?? '')) . '</dd></div>';
    echo '</dl>';
    if (!empty($payload['customer_email'])) {
      echo '<div class="roxy-door-meta"><strong>Email:</strong> ' . esc_html((string) $payload['customer_email']) . '</div>';
    }
    if (!empty($payload['checked_in_at'])) {
      echo '<div class="roxy-door-meta"><strong>Checked in:</strong> ' . esc_html(date_i18n('M j, Y g:ia', strtotime((string) $payload['checked_in_at']))) . '</div>';
    }
    if (!empty($payload['token'])) {
      echo '<div class="roxy-door-token">' . esc_html((string) $payload['token']) . '</div>';
    }
    echo '<div class="roxy-door-result-actions">';
    if (!empty($payload['can_check_in'])) {
      echo '<button type="button" class="button button-primary roxy-door-admit" data-ticket-id="' . esc_attr((string) $payload['ticket_id']) . '">Admit / Check In</button>';
    } elseif (!empty($payload['can_undo'])) {
      echo '<button type="button" class="button roxy-door-undo" data-ticket-id="' . esc_attr((string) $payload['ticket_id']) . '" data-undo="1">Undo Check-In</button>';
    }
    echo '<button type="button" class="button roxy-door-rescan">Rescan</button>';
    echo '<a href="' . esc_url(add_query_arg(['page' => 'roxy-show-tickets', 'tab' => 'check-in', 's' => (string) ($payload['token'] ?? '')], admin_url('admin.php'))) . '" class="button">Manual Check-In</a>';
    echo '</div>';
    echo '</div>';
  }

  private static function render_door_mode_not_found_markup(string $token = ''): void {
    echo '<div class="roxy-door-state roxy-door-state-invalid">';
    echo '<div class="roxy-door-kicker">Not Found</div>';
    echo '<div class="roxy-door-title">Invalid Ticket</div>';
    echo '<p>That code was not found. Try again or open Roxy Check-In for manual lookup.</p>';
    if ($token !== '') {
      echo '<div class="roxy-door-token">' . esc_html($token) . '</div>';
    }
    echo '<div class="roxy-door-result-actions">';
    echo '<button type="button" class="button button-primary roxy-door-rescan">Try Again</button>';
    echo '<a href="' . esc_url(add_query_arg(['page' => 'roxy-show-tickets', 'tab' => 'check-in', 's' => $token], admin_url('admin.php'))) . '" class="button">Open in Roxy Check-In</a>';
    echo '</div>';
    echo '</div>';
  }

  public static function render_checkin_page(bool $wrap = true): void {
    if (!current_user_can('edit_posts')) {
      wp_die('You do not have permission to access this page.');
    }

    $token = isset($_GET['ticket_token']) ? sanitize_text_field(wp_unslash($_GET['ticket_token'])) : '';
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $results = [];
    if ($token !== '') {
      $ticket = self::get_ticket_by_token($token);
      if ($ticket) $results[] = $ticket;
    } elseif ($search !== '') {
      $results = self::search_tickets($search);
    }

    if ($wrap) echo '<div class="wrap"><h1>Roxy Check-In</h1>';
    echo '<p>Scan or paste a ticket token, or search by order #, name, or email.</p>';
    echo '<div style="max-width:900px;background:#111;color:#fff;border-radius:14px;padding:16px;margin:16px 0">';
    echo '<form id="roxy-ticket-search-form" method="get" action="" style="display:grid;gap:12px;grid-template-columns:1fr auto;align-items:end">';
    echo '<input type="hidden" name="page" value="roxy-show-tickets">';
    echo '<input type="hidden" name="tab" value="check-in">';
    echo '<label for="roxy-ticket-search" style="font-weight:700">Ticket Search</label>';
    echo '<input id="roxy-ticket-search" type="text" name="s" value="' . esc_attr($search ?: $token) . '" placeholder="Token, order #, name, or email" style="width:100%;max-width:520px;padding:12px;border-radius:10px;border:1px solid #444;background:#222;color:#fff">';
    echo '<button type="submit" class="button button-primary" style="height:46px">Search</button>';
    echo '</form>';
    echo '<div id="roxy-st-scanner-wrap" style="margin-top:14px">';
    echo '<button type="button" id="roxy-st-start-scan" class="button button-primary">Start Camera Scan</button> ';
    echo '<span id="roxy-st-scan-status" style="margin-left:8px;opacity:.85">Manual search always works if camera scan is unavailable.</span>';
    echo '<video id="roxy-st-scan-video" autoplay playsinline muted style="display:none;width:100%;max-width:520px;margin-top:12px;border-radius:12px;background:#000"></video>';
    echo '</div></div>';

    if ($results) {
      echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">';
      foreach ($results as $ticket) {
        self::render_ticket_result_card($ticket);
      }
      echo '</div>';
    } elseif ($token !== '' || $search !== '') {
      echo '<div class="notice notice-warning"><p>No matching tickets found.</p></div>';
    }

    if ($wrap) echo '</div>';
    ?>
<script>
(function(){
  const startBtn = document.getElementById('roxy-st-start-scan');
  const statusEl = document.getElementById('roxy-st-scan-status');
  const video = document.getElementById('roxy-st-scan-video');
  let stream = null, detector = null, jsQRLib = null, timer = null;
  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d', {willReadFrequently: true});

  function stopScan() {
    if (timer) { clearInterval(timer); timer = null; }
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    video.style.display = 'none';
    startBtn.textContent = 'Start Camera Scan';
  }

  function loadJsQR() {
    if (typeof jsQR !== 'undefined') { jsQRLib = jsQR; return Promise.resolve(true); }
    return new Promise((resolve) => {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';
      script.onload = () => { jsQRLib = window.jsQR || null; resolve(!!jsQRLib); };
      script.onerror = () => resolve(false);
      document.head.appendChild(script);
    });
  }

  async function tick() {
    if (!video || video.readyState < 2 || !video.videoWidth) return;
    try {
      let rawValue = null;
      if (detector) {
        const codes = await detector.detect(video);
        if (codes && codes.length) rawValue = codes[0].rawValue || '';
      } else if (jsQRLib) {
        const scale = Math.min(1, 640 / video.videoWidth);
        const sw = Math.round(video.videoWidth * scale);
        const sh = Math.round(video.videoHeight * scale);
        canvas.width = sw; canvas.height = sh;
        ctx.drawImage(video, 0, 0, sw, sh);
        const imageData = ctx.getImageData(0, 0, sw, sh);
        const code = jsQRLib(imageData.data, imageData.width, imageData.height, {inversionAttempts: 'attemptBoth'});
        if (code && code.data) rawValue = code.data;
      }
      if (rawValue && rawValue.trim()) {
        stopScan();
        statusEl.textContent = 'Ticket detected. Loading result…';
        const url = new URL(window.location.href);
        url.searchParams.delete('s');
        url.searchParams.set('ticket_token', rawValue.trim());
        window.location.href = url.toString();
      }
    } catch(e) {}
  }

  startBtn?.addEventListener('click', async function(){
    if (stream) { stopScan(); statusEl.textContent = 'Camera stopped.'; return; }
    if (!('mediaDevices' in navigator) || !navigator.mediaDevices.getUserMedia) {
      statusEl.textContent = 'Camera scanning is not supported on this device. Use manual search.';
      return;
    }
    // Request camera FIRST — any await before getUserMedia silently breaks iOS Safari's user gesture chain
    statusEl.textContent = 'Requesting camera access…';
    try {
      try {
        stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}}, audio: false});
      } catch(e) {
        stream = await navigator.mediaDevices.getUserMedia({video: true, audio: false});
      }
    } catch(e) {
      statusEl.textContent = 'Camera permission denied. Use manual search.';
      return;
    }
    // Attach stream to video IMMEDIATELY — iOS releases orphaned streams within ~2 seconds
    video.srcObject = stream;
    video.muted = true; // Set programmatically — iOS ignores the HTML attribute in some contexts
    // Fire play() IMMEDIATELY — iOS autoplay window closes ~1 second after user gesture
    // Do NOT await anything between getUserMedia and play()
    const playPromise = video.play();
    // Attach rejection handler SYNCHRONOUSLY — before any await — so it fires right away on failure.
    // Do NOT stop the stream on rejection; retry on canplay/loadedmetadata instead.
    // If no frames ever appear the 8 s timeout below is the only kill switch.
    if (playPromise && typeof playPromise.catch === 'function') {
      playPromise.catch(() => {
        const retryPlay = () => video.play().catch(() => {});
        video.addEventListener('canplay',        retryPlay, {once: true});
        video.addEventListener('loadedmetadata', retryPlay, {once: true});
      });
    }
    // Set up QR detector — jsQR is pre-loaded by WordPress so loadJsQR() resolves instantly (no CDN wait)
    if ('BarcodeDetector' in window) {
      detector = new BarcodeDetector({formats: ['qr_code']});
    } else {
      statusEl.textContent = 'Loading QR scanner…';
      const loaded = await loadJsQR();
      if (!loaded) {
        stream.getTracks().forEach(t => t.stop()); stream = null;
        video.srcObject = null;
        statusEl.textContent = 'QR scanning is not available on this browser. Use manual search.';
        return;
      }
    }
    // Wait for first real frame — 'playing' event with videoWidth polling as fallback
    try {
      await new Promise((resolve, reject) => {
        let poll;
        const timeout = setTimeout(() => { clearInterval(poll); reject(new Error('Camera timed out.')); }, 8000);
        const done = () => { clearTimeout(timeout); clearInterval(poll); resolve(); };
        poll = setInterval(() => { if (video.videoWidth > 0 && video.readyState >= 2) done(); }, 200);
        video.addEventListener('playing', done, {once: true});
      });
    } catch(e) {
      stream.getTracks().forEach(t => t.stop()); stream = null;
      statusEl.textContent = e.message || 'Camera failed to start. Try again.';
      return;
    }
    video.style.display = 'block';
    startBtn.textContent = 'Stop Camera Scan';
    statusEl.textContent = 'Point the camera at a ticket QR code.';
    timer = setInterval(tick, 600);
  });
})();
</script>
<?php
  }

  private static function render_ticket_result_card(\WP_Post $ticket): void {
    $ticket_id = (int) $ticket->ID;
    $token = (string) get_post_meta($ticket_id, self::META_TOKEN, true);
    $showing_title = (string) get_post_meta($ticket_id, '_roxy_ticket_showing_title', true);
    $ticket_label = (string) get_post_meta($ticket_id, '_roxy_ticket_ticket_label', true);
    $customer_name = (string) get_post_meta($ticket_id, '_roxy_ticket_customer_name', true);
    $customer_email = (string) get_post_meta($ticket_id, '_roxy_ticket_customer_email', true);
    $order_id = (int) get_post_meta($ticket_id, self::META_ORDER_ID, true);
    $state = (string) get_post_meta($ticket_id, self::META_STATE, true);
    $checked_in = (int) get_post_meta($ticket_id, self::META_CHECKED_IN, true) === 1;
    $checked_in_at = (string) get_post_meta($ticket_id, self::META_CHECKED_IN_AT, true);
    $action = $checked_in ? 'roxy_st_uncheck_in_ticket' : 'roxy_st_check_in_ticket';
    $action_label = $checked_in ? 'Undo Check-In' : 'Check In';
    $current_token = isset($_GET['ticket_token']) && $_GET['ticket_token'] !== '' ? sanitize_text_field(wp_unslash($_GET['ticket_token'])) : '';
    $action_url = self::ticket_action_url($action, $ticket_id, 'roxy-show-tickets', $current_token, 'check-in');

    echo '<div style="border:1px solid #ddd;border-radius:14px;padding:16px;background:#fff">';
    echo '<div style="font-size:22px;font-weight:800;color:' . esc_attr(self::state_color($state, $checked_in)) . ';margin-bottom:8px">' . esc_html(self::state_label($state, $checked_in)) . '</div>';
    echo '<div style="font-weight:700;margin-bottom:4px">' . esc_html($showing_title) . '</div>';
    echo '<div style="opacity:.8;margin-bottom:8px">' . esc_html($ticket_label) . '</div>';
    echo '<div style="font-size:14px;margin-bottom:4px"><strong>Order:</strong> #' . esc_html((string) $order_id) . '</div>';
    echo '<div style="font-size:14px;margin-bottom:4px"><strong>Name:</strong> ' . esc_html($customer_name) . '</div>';
    echo '<div style="font-size:14px;margin-bottom:4px"><strong>Email:</strong> ' . esc_html($customer_email) . '</div>';
    echo '<div style="font-size:12px;word-break:break-all;opacity:.75;margin:8px 0 12px">' . esc_html($token) . '</div>';
    if ($checked_in_at !== '') {
      echo '<div style="font-size:13px;opacity:.75;margin-bottom:12px"><strong>Checked in:</strong> ' . esc_html(date_i18n('M j, Y g:ia', strtotime($checked_in_at))) . '</div>';
    }
    if (self::can_check_in($ticket_id)) {
      echo '<a href="' . esc_url($action_url) . '" class="button button-primary" style="min-width:160px;text-align:center">' . esc_html($action_label) . '</a>';
    } elseif ($checked_in) {
      echo '<a href="' . esc_url($action_url) . '" class="button" style="min-width:160px;text-align:center">' . esc_html($action_label) . '</a>';
    }
    echo '</div>';
  }

  private static function ticket_action_url(string $action, int $ticket_id, string $page, string $token = '', string $tab = ''): string {
    $query = [
      'action'    => $action,
      'ticket_id' => $ticket_id,
      'page'      => $page,
    ];
    if ($tab !== '') {
      $query['tab'] = $tab;
    }
    if (isset($_GET['s']) && $_GET['s'] !== '') {
      $query['s'] = sanitize_text_field(wp_unslash($_GET['s']));
    }
    if ($token !== '') {
      $query['ticket_token'] = $token;
    } elseif (isset($_GET['ticket_token']) && $_GET['ticket_token'] !== '') {
      $query['ticket_token'] = sanitize_text_field(wp_unslash($_GET['ticket_token']));
    }
    return wp_nonce_url(add_query_arg($query, admin_url('admin-post.php')), $action . '_' . $ticket_id);
  }

  public static function handle_check_in(): void {
    self::handle_checkin_action(true);
  }

  public static function handle_uncheck_in(): void {
    self::handle_checkin_action(false);
  }

  private static function handle_checkin_action(bool $check_in): void {
    $ticket_id = isset($_GET['ticket_id']) ? (int) $_GET['ticket_id'] : 0;
    $action = $check_in ? 'roxy_st_check_in_ticket' : 'roxy_st_uncheck_in_ticket';
    if ($ticket_id <= 0 || !current_user_can('edit_posts') || !check_admin_referer($action . '_' . $ticket_id)) {
      wp_die('Invalid request.');
    }

    if ($check_in) {
      self::check_in_ticket($ticket_id, get_current_user_id());
    } else {
      self::undo_check_in_ticket($ticket_id);
    }

    $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'check-in';
    if (!in_array($tab, ['check-in', 'door-mode'], true)) {
      $tab = 'check-in';
    }

    $redirect_args = ['page' => 'roxy-show-tickets', 'tab' => $tab];
    if (isset($_GET['s']) && $_GET['s'] !== '') {
      $redirect_args['s'] = sanitize_text_field(wp_unslash($_GET['s']));
    } elseif (isset($_GET['ticket_token']) && $_GET['ticket_token'] !== '') {
      $redirect_args['ticket_token'] = sanitize_text_field(wp_unslash($_GET['ticket_token']));
    } else {
      $redirect_args['ticket_token'] = (string) get_post_meta($ticket_id, self::META_TOKEN, true);
    }
    $redirect = add_query_arg($redirect_args, admin_url('admin.php'));
    wp_safe_redirect($redirect);
    exit;
  }

  private static function extract_member_subscription_id(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;

    if (preg_match('/^\d+$/', $value)) {
      return absint($value);
    }

    $query = wp_parse_url($value, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
      parse_str($query, $params);
      if (!empty($params['sub'])) {
        return absint($params['sub']);
      }
    }

    if (preg_match('/[?&]sub=(\d+)/i', $value, $m)) {
      return absint($m[1]);
    }
    return 0;
  }

  private static function member_payload_from_value(string $value): ?array {
    $sub_id = self::extract_member_subscription_id($value);
    if ($sub_id <= 0) return null;
    if (!class_exists('\Roxy_Sub_Check') || !method_exists('\Roxy_Sub_Check', 'get_member_payload')) {
      return [
        'credential_type' => 'member',
        'found' => false,
        'status' => 'invalid',
        'headline' => 'Membership Plugin Missing',
        'subline' => 'Roxy Subscription Check is not active.',
        'subscription_id' => $sub_id,
        'token' => $value,
      ];
    }
    $payload = \Roxy_Sub_Check::get_member_payload($sub_id, true);
    $payload['token'] = $value;
    return $payload;
  }

  public static function ajax_door_validate(): void {
    if (!current_user_can('edit_posts')) {
      wp_send_json_error(['message' => 'Permission denied.'], 403);
    }
    check_ajax_referer('roxy_st_door_mode', 'nonce');

    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    $lock_showing_id = isset($_POST['lock_showing_id']) ? max(0, (int) $_POST['lock_showing_id']) : 0;
    if ($token === '') {
      wp_send_json_error(['message' => 'Missing ticket token.'], 400);
    }

    $member_payload = self::member_payload_from_value($token);
    if (is_array($member_payload)) {
      wp_send_json_success($member_payload);
    }

    $ticket = self::get_ticket_by_token($token);
    if (!$ticket) {
      wp_send_json_success(['found' => false, 'status' => 'invalid', 'token' => $token]);
    }

    $payload = self::door_ticket_payload($ticket);
    if ($lock_showing_id > 0 && (int) ($payload['showing_id'] ?? 0) !== $lock_showing_id) {
      $payload['status'] = 'wrong_event';
      $payload['headline'] = 'Wrong Event';
      $payload['subline'] = 'This ticket belongs to a different event than the one locked in Door Mode.';
      $payload['can_check_in'] = false;
      $payload['can_undo'] = false;
      $payload['locked_showing_id'] = $lock_showing_id;
      $payload['locked_showing_title'] = get_the_title($lock_showing_id);
    }

    if ($lock_showing_id > 0) {
      $payload['attendance'] = self::door_stats_payload($lock_showing_id);
    }

    wp_send_json_success($payload);
  }

  public static function ajax_door_checkin(): void {
    if (!current_user_can('edit_posts')) {
      wp_send_json_error(['message' => 'Permission denied.'], 403);
    }
    check_ajax_referer('roxy_st_door_checkin', 'nonce');

    $ticket_id = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
    $undo = !empty($_POST['undo']);
    $lock_showing_id = isset($_POST['lock_showing_id']) ? max(0, (int) $_POST['lock_showing_id']) : 0;
    if ($ticket_id <= 0 || get_post_type($ticket_id) !== self::POST_TYPE) {
      wp_send_json_error(['message' => 'Invalid ticket.'], 400);
    }

    $ticket_showing_id = (int) get_post_meta($ticket_id, self::META_SHOWING_ID, true);
    if (!$undo && $lock_showing_id > 0 && $ticket_showing_id !== $lock_showing_id) {
      wp_send_json_error(['message' => 'That ticket belongs to a different event than Door Mode is locked to.'], 400);
    }

    if ($undo) {
      self::undo_check_in_ticket($ticket_id);
    } else {
      if (!self::check_in_ticket($ticket_id, get_current_user_id())) {
        wp_send_json_error(['message' => 'Ticket is not eligible for check-in.'], 400);
      }
    }

    $ticket = get_post($ticket_id);
    $payload = self::door_ticket_payload($ticket);
    if ($lock_showing_id > 0) {
      $payload['attendance'] = self::door_stats_payload($lock_showing_id);
    }
    wp_send_json_success($payload);
  }

  public static function ajax_door_stats(): void {
    if (!current_user_can('edit_posts')) {
      wp_send_json_error(['message' => 'Permission denied.'], 403);
    }
    check_ajax_referer('roxy_st_door_mode', 'nonce');

    $showing_id = isset($_POST['showing_id']) ? max(0, (int) $_POST['showing_id']) : 0;
    if ($showing_id <= 0 || get_post_type($showing_id) !== CPT::POST_TYPE) {
      wp_send_json_error(['message' => 'Invalid showing.'], 400);
    }

    wp_send_json_success(self::door_stats_payload($showing_id));
  }

  public static function ajax_qr(): void {
    if (!class_exists('\QRCode') || !function_exists('imagepng')) {
      status_header(500);
      wp_die('QR generation is unavailable on this server.');
    }

    $data = isset($_REQUEST['data']) ? wp_unslash((string) $_REQUEST['data']) : '';
    $data = preg_replace('/[\x00-\x1F\x7F]/u', '', $data);
    $data = trim(is_string($data) ? $data : '');
    if ($data === '' || strlen($data) > 1000) {
      status_header(400);
      wp_die('Invalid QR payload.');
    }

    $size = isset($_REQUEST['size']) ? (int) $_REQUEST['size'] : 220;
    $size = max(120, min(512, $size));

    nocache_headers();
    $generator = new \QRCode($data, [
      'w' => $size,
      'h' => $size,
      'p' => 0,
      'wq' => 2,
      'md' => 1,
      'bc' => 'FFFFFF',
      'fc' => '000000',
    ]);
    $generator->output_image();
    exit;
  }

  public static function get_ticket_by_token(string $token): ?\WP_Post {
    $posts = get_posts([
      'post_type' => self::POST_TYPE,
      'post_status' => 'publish',
      'numberposts' => 1,
      'meta_key' => self::META_TOKEN,
      'meta_value' => sanitize_text_field($token),
    ]);
    return !empty($posts[0]) ? $posts[0] : null;
  }

  private static function search_tickets(string $search): array {
    $search = trim($search);
    if ($search === '') return [];

    $results = [];
    $token_match = self::get_ticket_by_token($search);
    if ($token_match) {
      $results[$token_match->ID] = $token_match;
    }

    if (preg_match('/^\d+$/', $search)) {
      $by_order = get_posts([
        'post_type' => self::POST_TYPE,
        'post_status' => 'publish',
        'numberposts' => 20,
        'meta_key' => self::META_ORDER_ID,
        'meta_value' => (int) $search,
      ]);
      foreach ($by_order as $post) {
        $results[$post->ID] = $post;
      }
    }

    foreach (['_roxy_ticket_customer_name', '_roxy_ticket_customer_email'] as $meta_key) {
      $posts = get_posts([
        'post_type' => self::POST_TYPE,
        'post_status' => 'publish',
        'numberposts' => 20,
        'meta_query' => [[
          'key' => $meta_key,
          'value' => $search,
          'compare' => 'LIKE',
        ]],
      ]);
      foreach ($posts as $post) {
        $results[$post->ID] = $post;
      }
    }

    return array_values($results);
  }

  private static function can_check_in(int $ticket_id): bool {
    $state = (string) get_post_meta($ticket_id, self::META_STATE, true);
    $checked_in = (int) get_post_meta($ticket_id, self::META_CHECKED_IN, true) === 1;
    return !$checked_in && $state === 'valid';
  }

  private static function normalize_ticket_ids($raw): array {
    if (is_array($raw)) {
      return array_values(array_filter(array_map('intval', $raw)));
    }
    if (is_string($raw) && $raw !== '') {
      return array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', $raw))));
    }
    return [];
  }

  private static function ticket_label(int $showing_id, string $ticket_type): string {
    switch ($ticket_type) {
      case 'adult': return 'General';
      case 'discount': return 'Discount';
      case 'matinee': return 'Matinee';
      case 'live1': return (string) (get_post_meta($showing_id, '_roxy_live_label_1', true) ?: 'Live 1');
      case 'live2': return (string) (get_post_meta($showing_id, '_roxy_live_label_2', true) ?: 'Live 2');
      case 'subscriber': return 'Subscriber';
    }
    return ucfirst($ticket_type);
  }

  private static function generate_token(): string {
    return wp_generate_password(28, false, false);
  }

  public static function qr_image_url(string $data, int $size = 220): string {
    return add_query_arg([
      'action' => 'roxy_st_qr',
      'data' => $data,
      'size' => max(120, min(512, $size)),
    ], admin_url('admin-ajax.php'));
  }

  private static function state_for_order_status(string $status): string {
    return in_array($status, ['refunded', 'cancelled', 'failed'], true) ? self::invalid_state_for_order($status) : 'valid';
  }

  private static function invalid_state_for_order(string $status): string {
    if ($status === 'refunded') return 'refunded';
    if (in_array($status, ['cancelled', 'failed'], true)) return 'cancelled';
    return 'pending';
  }

  private static function set_ticket_state(int $ticket_id, string $state): void {
    if ($ticket_id <= 0 || get_post_type($ticket_id) !== self::POST_TYPE) return;
    update_post_meta($ticket_id, self::META_STATE, $state);
  }

  private static function state_label(string $state, bool $checked_in): string {
    if ($checked_in || $state === 'checked_in') return 'Checked In';
    if ($state === 'valid') return 'Valid Ticket';
    if ($state === 'refunded') return 'Refunded';
    if ($state === 'cancelled') return 'Cancelled';
    return 'Not Yet Valid';
  }

  private static function state_color(string $state, bool $checked_in): string {
    if ($checked_in || $state === 'checked_in') return '#2e7d32';
    if ($state === 'valid') return '#1565c0';
    if ($state === 'refunded' || $state === 'cancelled') return '#c62828';
    return '#6b7280';
  }
}
