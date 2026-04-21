<?php
/**
 * Plugin Name: Roxy Subscription Check
 * Description: NFC-friendly membership verification page for WooCommerce Subscriptions. Per-subscription photo, scan log, and customer photo upload.
 * Version: 1.3.8
 * Author: Newport Roxy (AI Team)
 * Update URI: https://github.com/Tototex/roxy-sub-check
 */

if (!defined('ABSPATH')) exit;


class Roxy_Sub_Check {
  const META_PHOTO_ID = '_roxy_member_photo_id';
  const TABLE_LOG     = 'roxy_member_scans';

  public static function init() {
    add_action('init', [__CLASS__, 'rewrite_rule']);
    add_filter('query_vars', [__CLASS__, 'query_vars']);
    add_action('template_redirect', [__CLASS__, 'template_redirect']);

    add_action('add_meta_boxes', [__CLASS__, 'add_subscription_photo_metabox']);
    add_action('save_post', [__CLASS__, 'save_subscription_photo_metabox'], 10, 2);
    add_action('admin_enqueue_scripts', [__CLASS__, 'admin_enqueue_media']);

    if (!defined('ROXY_SUITE_VERSION')) {
      add_action('admin_menu', [__CLASS__, 'admin_menu']);
    }

    add_action('woocommerce_subscription_details_table', [__CLASS__, 'render_myaccount_photo_uploader'], 50);
    add_action('init', [__CLASS__, 'handle_myaccount_photo_upload']);
    add_action('wp_ajax_roxy_sub_check_lookup', [__CLASS__, 'ajax_lookup']);
  }

  public static function activate() {
    self::create_log_table();
  }

  private static function table_name() {
    global $wpdb;
    return $wpdb->prefix . self::TABLE_LOG;
  }

  private static function create_log_table() {
    global $wpdb;

    $table = self::table_name();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      scanned_at DATETIME NOT NULL,
      subscription_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NULL,
      status VARCHAR(50) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 0,
      showing_id BIGINT UNSIGNED NULL,
      source VARCHAR(60) NOT NULL DEFAULT 'nfc_scan',
      quantity INT NOT NULL DEFAULT 1,
      ip VARCHAR(45) NULL,
      user_agent TEXT NULL,
      PRIMARY KEY (id),
      KEY subscription_id (subscription_id),
      KEY scanned_at (scanned_at),
      KEY showing_id (showing_id),
      KEY source (source)
    ) {$charset};";

    dbDelta($sql);
  }

  public static function rewrite_rule() {
    add_rewrite_rule('^member-check/?$', 'index.php?roxy_member_check=1', 'top');
  }

  public static function query_vars($vars) {
    $vars[] = 'roxy_member_check';
    return $vars;
  }

  private static function can_access_member_check(): bool {
    return roxy_suite_user_can_access_admin();
  }

  public static function template_redirect() {
    if (get_query_var('roxy_member_check') == 1) {
      if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
      }
      nocache_headers();
      if (!is_user_logged_in()) {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/member-check/';
        $redirect_back = home_url($request_uri);
        wp_safe_redirect(wp_login_url($redirect_back));
        exit;
      }
      if (!self::can_access_member_check()) {
        wp_die('You do not have permission to access Member Check.');
      }
      self::render_page();
      exit;
    }
  }

  public static function member_check_url(int $sub_id): string {
    return home_url('/member-check/?sub=' . absint($sub_id));
  }

  private static function qr_image_url(string $value): string {
    if (class_exists('\RoxyST\Tickets') && method_exists('\RoxyST\Tickets', 'qr_image_url')) {
      return \RoxyST\Tickets::qr_image_url($value, 220);
    }

    return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($value);
  }

  private static function subscription_quantity($sub): int {
    if (!is_object($sub) || !method_exists($sub, 'get_items')) return 0;
    $qty = 0;
    foreach ($sub->get_items() as $item) {
      if (is_object($item) && method_exists($item, 'get_quantity')) {
        $qty += max(0, (int) $item->get_quantity());
      }
    }
    return max(1, $qty);
  }

  public static function get_member_payload(int $sub_id, bool $log_scan = false): array {
    $result = self::check_subscription($sub_id);
    $payload = [
      'credential_type' => 'member',
      'found' => empty($result['error']),
      'status' => !empty($result['active']) ? 'valid' : 'invalid',
      'headline' => !empty($result['active']) ? 'Active Member' : 'Membership Inactive',
      'subline' => !empty($result['active']) ? 'Friends of the Roxy membership verified.' : 'This membership is not currently active.',
      'member_name' => (string) ($result['name'] ?? ''),
      'customer_name' => (string) ($result['name'] ?? ''),
      'customer_email' => (string) ($result['email'] ?? ''),
      'subscription_id' => $sub_id,
      'membership_qty' => (int) ($result['membership_qty'] ?? 0),
      'member_since' => (string) ($result['member_since'] ?? ''),
      'last_visit' => (string) ($result['last_visit'] ?? ''),
      'next_payment' => (string) ($result['next_payment'] ?? ''),
      'photo_url' => (string) ($result['photo_url'] ?? ''),
      'status_label' => (string) ($result['status'] ?? ''),
      'member_check_url' => self::member_check_url($sub_id),
      'token' => self::member_check_url($sub_id),
      'can_check_in' => false,
      'can_undo' => false,
    ];

    if ($sub_id && empty($result['error']) && $log_scan) {
      self::log_scan(
        $sub_id,
        !empty($result['user_id']) ? absint($result['user_id']) : null,
        !empty($result['status']) ? (string)$result['status'] : null,
        !empty($result['active']) ? 1 : 0,
        0,
        'nfc_scan',
        1
      );
      $payload['last_visit'] = self::get_last_visit($sub_id);
    }

    if (!empty($result['error'])) {
      $payload['headline'] = 'Unable to Verify Membership';
      $payload['subline'] = (string) $result['error'];
      $payload['error'] = (string) $result['error'];
    }

    return $payload;
  }

  public static function ajax_lookup() {
    check_ajax_referer('roxy_sub_check_lookup', 'nonce');
    if (!self::can_access_member_check()) {
      wp_send_json_error(['message' => 'Permission denied.'], 403);
    }
    $sub_id = isset($_POST['sub_id']) ? absint($_POST['sub_id']) : 0;
    if ($sub_id <= 0) {
      wp_send_json_error(['message' => 'Missing subscription ID.'], 400);
    }
    wp_send_json_success(self::get_member_payload($sub_id, true));
  }

  private static function render_page() {
    status_header(200);
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Type: text/html; charset=utf-8');

    $sub_id = isset($_GET['sub']) ? absint($_GET['sub']) : 0;
    $result = self::check_subscription($sub_id);

    $is_active = ($result['active'] === true);
    $bg = $is_active ? '#0b5' : '#c22';
    $label = $is_active ? 'ACTIVE' : 'INACTIVE';

    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<title>Roxy Membership Check</title></head>';
    echo '<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:0; padding:16px;">';
    echo '<div style="max-width:720px; margin:0 auto;">';

    echo '<div style="padding:16px; border-radius:14px; background:' . esc_attr($bg) . '; color:#fff;">';
    echo '<div style="font-size:14px; opacity:.9;">Friends of the Roxy</div>';
    echo '<div style="font-size:34px; font-weight:800; letter-spacing:.5px;">' . esc_html($label) . '</div>';
    echo '</div>';

    echo '<div style="margin-top:14px; padding:16px; border:1px solid #ddd; border-radius:14px;">';

    if (!$sub_id) {
      echo '<div style="font-size:18px; font-weight:700;">Missing subscription ID</div>';
      echo '<div style="opacity:.8; margin-top:6px;">Use ?sub=12345</div>';
    } elseif (!empty($result['error'])) {
      echo '<div style="font-size:18px; font-weight:700;">Unable to verify</div>';
      echo '<div style="opacity:.8; margin-top:6px;">' . esc_html($result['error']) . '</div>';
    } else {
      if ($is_active) {
        if (!empty($result['photo_url'])) {
          echo '<div style="text-align:center; margin-bottom:12px;">';
          echo '<img src="' . esc_url($result['photo_url']) . '" alt="Member photo" style="width:110px; height:110px; border-radius:16px; object-fit:cover; border:1px solid #ddd;">';
          echo '<div style="font-size:20px; font-weight:800; margin-top:10px;">' . esc_html($result['name']) . '</div>';
          echo '</div>';
        } else {
          echo '<div style="text-align:center; margin-bottom:12px;">';
          echo '<div style="display:inline-block; width:110px; height:110px; border-radius:16px; border:1px dashed #bbb; line-height:110px; opacity:.7;">No photo</div>';
          echo '<div style="font-size:20px; font-weight:800; margin-top:10px;">' . esc_html($result['name']) . '</div>';
          echo '<div style="opacity:.75; margin-top:6px;">No photo on file</div>';
          echo '</div>';
        }
      } else {
        echo '<div style="font-size:20px; font-weight:900; color:#c22;">Membership not active</div>';
      }

      echo '<div style="opacity:.8; margin-top:6px;">Subscription #' . esc_html($sub_id) . '</div>';
      if (!empty($result['membership_qty'])) {
        echo '<div style="margin-top:6px;">Membership quantity: <b>' . esc_html((string)$result['membership_qty']) . '</b></div>';
      }

      if (!$is_active && !empty($result['status'])) {
        echo '<div style="margin-top:10px;">Status: <b>' . esc_html($result['status']) . '</b></div>';
      }

      if (!empty($result['member_since'])) {
        echo '<div style="margin-top:10px;">Member since: <b>' . esc_html($result['member_since']) . '</b></div>';
      }

      if (!empty($result['last_visit'])) {
        echo '<div style="margin-top:6px;">Last visit: <b>' . esc_html($result['last_visit']) . '</b></div>';
      }

      if (!empty($result['next_payment'])) {
        echo '<div style="margin-top:6px;">Next payment: <b>' . esc_html($result['next_payment']) . '</b></div>';
      }
    }

    echo '</div></div></body></html>';
  }

  public static function check_subscription($sub_id) {
    $out = [
      'active' => false,
      'name' => '',
      'email' => '',
      'user_id' => 0,
      'status' => '',
      'next_payment' => '',
      'member_since' => '',
      'last_visit' => '',
      'photo_url' => '',
      'membership_qty' => 0,
      'error' => ''
    ];

    if (!$sub_id) {
      $out['error'] = 'No subscription id provided.';
      return $out;
    }

    if (!function_exists('wcs_get_subscription')) {
      $out['error'] = 'WooCommerce Subscriptions not available.';
      return $out;
    }

    $sub = wcs_get_subscription($sub_id);
    if (!$sub) {
      $out['error'] = 'Subscription not found.';
      return $out;
    }

    $user = $sub->get_user();
    if (!$user) {
      $out['error'] = 'Subscription has no user.';
      return $out;
    }

    $out['user_id'] = (int)$user->ID;
    $out['email'] = (string)$user->user_email;

    $first = get_user_meta($user->ID, 'first_name', true);
    $last  = get_user_meta($user->ID, 'last_name', true);
    $out['name'] = trim($first . ' ' . $last);
    if (!$out['name']) $out['name'] = $user->display_name;

    $status = $sub->get_status();
    $out['status'] = $status;

    $allowed = ['active', 'pending-cancel'];
    $out['active'] = in_array($status, $allowed, true);

    $out['membership_qty'] = self::subscription_quantity($sub);

    $start = $sub->get_date('start');
    if ($start) {
      $out['member_since'] = date_i18n(get_option('date_format'), strtotime($start));
    }

    $next = $sub->get_date('next_payment');
    if ($next) {
      $out['next_payment'] = date_i18n(get_option('date_format'), strtotime($next));
    }

    $photo_id = absint(get_post_meta($sub_id, self::META_PHOTO_ID, true));
    if ($photo_id) {
      $img = wp_get_attachment_image_src($photo_id, 'medium');
      if (is_array($img) && !empty($img[0])) {
        $out['photo_url'] = $img[0];
      }
    }

    return $out;
  }

  public static function log_member_visit(int $sub_id, int $showing_id = 0, int $quantity = 1, string $source = 'manual_admit'): array {
    $result = self::check_subscription($sub_id);
    if (!empty($result['error'])) {
      return ['ok' => false, 'message' => (string) $result['error'], 'payload' => self::get_member_payload($sub_id, false)];
    }

    if (empty($result['active'])) {
      return ['ok' => false, 'message' => 'Membership is not active.', 'payload' => self::get_member_payload($sub_id, false)];
    }

    $max_qty = max(1, (int) ($result['membership_qty'] ?? 1));
    $quantity = max(1, min($quantity, $max_qty));
    self::log_scan(
      $sub_id,
      !empty($result['user_id']) ? absint($result['user_id']) : null,
      !empty($result['status']) ? (string) $result['status'] : null,
      !empty($result['active']) ? 1 : 0,
      $showing_id,
      $source,
      $quantity
    );

    $payload = self::get_member_payload($sub_id, false);
    $payload['admitted'] = true;
    $payload['admit_quantity'] = $quantity;
    $payload['admit_source'] = $source;
    $payload['admit_showing_id'] = $showing_id;

    return ['ok' => true, 'message' => 'Member admitted.', 'payload' => $payload];
  }

  public static function search_members(string $term, int $limit = 20): array {
    $term = strtolower(trim($term));
    if ($term === '') return [];
    $limit = max(1, min(50, $limit));

    if (!function_exists('wcs_get_subscriptions')) {
      return [];
    }

    $subscriptions = wcs_get_subscriptions([
      'subscription_status' => ['active', 'pending-cancel'],
      'subscriptions_per_page' => -1,
      'orderby' => 'ID',
      'order' => 'DESC',
    ]);

    $matches = [];
    foreach ((array) $subscriptions as $sub_id => $sub) {
      $id = is_object($sub) && method_exists($sub, 'get_id') ? (int) $sub->get_id() : (int) $sub_id;
      if ($id <= 0) continue;

      $payload = self::get_member_payload($id, false);
      $haystack = strtolower(trim(
        (string) ($payload['member_name'] ?? '') . ' ' .
        (string) ($payload['customer_email'] ?? '') . ' ' .
        (string) ($payload['subscription_id'] ?? '')
      ));
      if ($haystack === '' || strpos($haystack, $term) === false) {
        continue;
      }
      $matches[] = $payload;
      if (count($matches) >= $limit) break;
    }

    return $matches;
  }

  public static function showing_admit_rows(int $showing_id): array {
    if ($showing_id <= 0) return [];
    global $wpdb;
    self::create_log_table();
    $table = self::table_name();

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT subscription_id, user_id, MAX(scanned_at) AS scanned_at, SUM(quantity) AS quantity
         FROM {$table}
         WHERE showing_id = %d
           AND is_active = 1
           AND source IN ('manual_admit_walkup', 'nfc_admit_walkup')
         GROUP BY subscription_id, user_id
         ORDER BY scanned_at ASC",
        $showing_id
      ),
      ARRAY_A
    );

    $out = [];
    foreach ((array) $rows as $row) {
      $sub_id = (int) ($row['subscription_id'] ?? 0);
      if ($sub_id <= 0) continue;
      $payload = self::get_member_payload($sub_id, false);
      if (empty($payload['found']) || ($payload['status'] ?? '') !== 'valid') continue;

      $qty = max(1, (int) ($row['quantity'] ?? 1));
      $key = 'subscriber-walkup-' . $sub_id;
      $out[] = [
        'customer_key' => $key,
        'name' => (string) ($payload['member_name'] ?? 'Subscriber'),
        'email' => (string) ($payload['customer_email'] ?? ''),
        'qty' => $qty,
        'ticket_types' => ['Subscriber walk-up' => $qty],
        'orders' => [],
        'latest_order_ts' => strtotime((string) ($row['scanned_at'] ?? '')) ?: 0,
        'source' => 'member_admit',
        'subscription_id' => $sub_id,
      ];
    }
    return $out;
  }

  public static function admitted_quantity_for_showing(int $sub_id, int $showing_id, string $source_like = ''): int {
    if ($sub_id <= 0 || $showing_id <= 0) return 0;
    global $wpdb;
    self::create_log_table();
    $table = self::table_name();
    if ($source_like !== '') {
      return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(quantity), 0) FROM {$table} WHERE subscription_id = %d AND showing_id = %d AND source LIKE %s",
        $sub_id,
        $showing_id,
        $source_like
      ));
    }
    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(SUM(quantity), 0) FROM {$table} WHERE subscription_id = %d AND showing_id = %d",
      $sub_id,
      $showing_id
    ));
  }

  private static function log_scan($sub_id, $user_id, $status, $is_active, int $showing_id = 0, string $source = 'nfc_scan', int $quantity = 1) {
    global $wpdb;
    self::create_log_table();
    $table = self::table_name();

    $ip = '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
      $ip = sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']);
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
      $ip = sanitize_text_field($_SERVER['REMOTE_ADDR']);
    }

    $ua = !empty($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 900) : '';

    $wpdb->insert(
      $table,
      [
        'scanned_at'      => current_time('mysql'),
        'subscription_id' => (int)$sub_id,
        'user_id'         => $user_id ? (int)$user_id : null,
        'status'          => $status ? sanitize_text_field($status) : null,
        'is_active'       => (int)$is_active,
        'showing_id'      => $showing_id > 0 ? (int)$showing_id : null,
        'source'          => sanitize_key($source ?: 'nfc_scan'),
        'quantity'        => max(1, (int)$quantity),
        'ip'              => $ip,
        'user_agent'      => $ua
      ],
      ['%s','%d','%d','%s','%d','%d','%s','%d','%s','%s']
    );
  }

  private static function get_last_visit($sub_id) {
    global $wpdb;
    $table = self::table_name();

    $dt = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT scanned_at 
         FROM {$table} 
         WHERE subscription_id=%d 
         ORDER BY scanned_at DESC 
         LIMIT 1 OFFSET 1",
        (int)$sub_id
      )
    );

    if (!$dt) return '';

    $fmt_date = get_option('date_format');
    $fmt_time = get_option('time_format');
    return date_i18n($fmt_date . ' ' . $fmt_time, strtotime($dt));
  }

  public static function admin_menu() {
    add_submenu_page(
      'roxy-suite',
      'Member Check',
      'Member Check',
      roxy_suite_admin_capability(),
      'roxy-scan-log',
      [__CLASS__, 'render_scan_log_page']
    );
  }

  public static function render_scan_log_page(bool $wrap = true, bool $show_title = true) {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('Insufficient permissions.');
    }

    global $wpdb;
    $table = self::table_name();

    $per_page = 50;
    $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $offset = ($page - 1) * $per_page;

    $filter_sub = isset($_GET['sub']) ? absint($_GET['sub']) : 0;

    if (isset($_GET['roxy_export']) && $_GET['roxy_export'] === 'csv') {
      self::export_scan_log_csv($filter_sub);
      exit;
    }

    $where = '';
    $params = [];
    if ($filter_sub) {
      $where = 'WHERE subscription_id = %d';
      $params[] = $filter_sub;
    }

    $count_sql = "SELECT COUNT(*) FROM {$table} " . $where;
    $total = $params ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params)) : $wpdb->get_var($count_sql);

    $sql = "SELECT * FROM {$table} {$where} ORDER BY scanned_at DESC LIMIT %d OFFSET %d";
    if ($params) {
      $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$per_page, $offset])), ARRAY_A);
    } else {
      $rows = $wpdb->get_results($wpdb->prepare($sql, $per_page, $offset), ARRAY_A);
    }

    $page_slug = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'roxy-scan-log';
    $base_url = admin_url('admin.php?page=' . $page_slug);
    if (!function_exists('WC') && $page_slug === 'roxy-scan-log') {
      $base_url = admin_url('tools.php?page=roxy-scan-log');
    }

    $export_url = add_query_arg(['roxy_export' => 'csv'] + ($filter_sub ? ['sub' => $filter_sub] : []), $base_url);

    if ($wrap) {
      echo '<div class="wrap">';
    }
    if ($show_title) {
      echo '<h1>Roxy Scan Log</h1>';
    }

    echo '<form method="get" style="margin:12px 0;">';
    echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '">';
    echo '<label>Filter by Subscription ID: </label> ';
    echo '<input type="number" name="sub" value="' . esc_attr($filter_sub ?: '') . '" style="width:160px;"> ';
    echo '<button class="button">Filter</button> ';
    echo '<a class="button button-secondary" href="' . esc_url($base_url) . '">Clear</a> ';
    echo '<a class="button button-primary" href="' . esc_url($export_url) . '">Export CSV</a>';
    echo '</form>';

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>Scanned At</th><th>Subscription</th><th>Active?</th><th>Status</th><th>User</th><th>IP</th><th>User Agent</th>';
    echo '</tr></thead><tbody>';

    if (!$rows) {
      echo '<tr><td colspan="7">No scans found.</td></tr>';
    } else {
      foreach ($rows as $r) {
        $active = !empty($r['is_active']) ? 'Yes' : 'No';
        $sub = (int)$r['subscription_id'];
        $user_id = !empty($r['user_id']) ? (int)$r['user_id'] : 0;
        $user_display = $user_id ? esc_html(get_the_author_meta('display_name', $user_id)) . " (#{$user_id})" : '—';

        echo '<tr>';
        echo '<td>' . esc_html($r['scanned_at']) . '</td>';
        echo '<td><a href="' . esc_url(home_url('/member-check/?sub=' . $sub)) . '" target="_blank">#' . esc_html($sub) . '</a></td>';
        echo '<td>' . esc_html($active) . '</td>';
        echo '<td>' . esc_html($r['status'] ?? '') . '</td>';
        echo '<td>' . $user_display . '</td>';
        echo '<td>' . esc_html($r['ip'] ?? '') . '</td>';
        echo '<td style="max-width:420px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' . esc_html($r['user_agent'] ?? '') . '</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';

    $total_pages = (int)ceil($total / $per_page);
    if ($total_pages > 1) {
      echo '<div style="margin-top:12px;">';
      for ($p = 1; $p <= $total_pages; $p++) {
        $url = add_query_arg(['paged' => $p] + ($filter_sub ? ['sub' => $filter_sub] : []), $base_url);
        $style = ($p === $page) ? 'font-weight:700; text-decoration:underline;' : '';
        echo '<a href="' . esc_url($url) . '" style="margin-right:10px; ' . esc_attr($style) . '">' . esc_html($p) . '</a>';
      }
      echo '</div>';
    }

    if ($wrap) {
      echo '</div>';
    }
  }

  private static function export_scan_log_csv($filter_sub) {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('Insufficient permissions.');
    }

    global $wpdb;
    $table = self::table_name();

    $where = '';
    $params = [];
    if ($filter_sub) {
      $where = 'WHERE subscription_id = %d';
      $params[] = $filter_sub;
    }

    $sql = "SELECT scanned_at, subscription_id, is_active, status, user_id, ip, user_agent
            FROM {$table} {$where} ORDER BY scanned_at DESC";
    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=roxy-scan-log.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['scanned_at','subscription_id','is_active','status','user_id','ip','user_agent']);

    foreach ($rows as $r) {
      fputcsv($out, [
        $r['scanned_at'],
        $r['subscription_id'],
        $r['is_active'],
        $r['status'],
        $r['user_id'],
        $r['ip'],
        $r['user_agent']
      ]);
    }

    fclose($out);
  }

  public static function render_myaccount_photo_uploader($subscription) {
    if (!is_user_logged_in()) return;

    $sub_id = is_object($subscription) && method_exists($subscription, 'get_id') ? (int)$subscription->get_id() : 0;
    if (!$sub_id) return;

    $current_user_id = get_current_user_id();
    $owner = $subscription->get_user_id();
    if ((int)$owner !== (int)$current_user_id) return;

    $photo_id = absint(get_post_meta($sub_id, self::META_PHOTO_ID, true));
    $photo_url = '';
    if ($photo_id) {
      $img = wp_get_attachment_image_src($photo_id, 'medium');
      if (is_array($img) && !empty($img[0])) $photo_url = $img[0];
    }

    echo '<section style="margin-top:18px; padding:16px; border:1px solid #e5e5e5; border-radius:12px;">';
    echo '<h3 style="margin-top:0;">Member Photo (for your NFC card)</h3>';
    echo '<p style="opacity:.85;">Upload a clear headshot. This photo will show at the door when your membership is scanned.</p>';

    if ($photo_url) {
      echo '<p><img src="' . esc_url($photo_url) . '" alt="Member photo" style="width:120px; height:120px; border-radius:16px; object-fit:cover; border:1px solid #ddd;"></p>';
    } else {
      echo '<p style="opacity:.75;">No photo on file.</p>';
    }

    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('roxy_myaccount_photo_upload', 'roxy_myaccount_photo_nonce');
    echo '<input type="hidden" name="roxy_sub_id" value="' . esc_attr($sub_id) . '">';
    echo '<input type="file" name="roxy_member_photo" accept="image/*" required> ';
    echo '<button type="submit" name="roxy_photo_upload" value="1" class="button">Upload / Replace Photo</button>';
    echo '</form>';

    $member_url = self::member_check_url($sub_id);
    echo '<hr style="margin:18px 0; border:none; border-top:1px solid #e5e5e5;">';
    echo '<h3 style="margin-top:0;">Membership QR Code</h3>';
    echo '<p style="opacity:.85;">Use this QR code at the door, or save the link to your phone for backup.</p>';
    echo '<p><img src="' . esc_url(self::qr_image_url($member_url)) . '" alt="Membership QR code" style="width:180px; height:180px; border-radius:12px; border:1px solid #ddd;"></p>';
    echo '</section>';
  }

  public static function handle_myaccount_photo_upload() {
    if (!is_user_logged_in()) return;
    if (empty($_POST['roxy_photo_upload'])) return;

    if (
      empty($_POST['roxy_myaccount_photo_nonce']) ||
      !wp_verify_nonce($_POST['roxy_myaccount_photo_nonce'], 'roxy_myaccount_photo_upload')
    ) {
      return;
    }

    $sub_id = isset($_POST['roxy_sub_id']) ? absint($_POST['roxy_sub_id']) : 0;
    if (!$sub_id) return;

    if (!function_exists('wcs_get_subscription')) return;
    $sub = wcs_get_subscription($sub_id);
    if (!$sub) return;

    $owner = (int)$sub->get_user_id();
    if ($owner !== (int)get_current_user_id()) return;

    if (empty($_FILES['roxy_member_photo']) || empty($_FILES['roxy_member_photo']['name'])) return;

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $attachment_id = media_handle_upload('roxy_member_photo', $sub_id);

    if (is_wp_error($attachment_id)) {
      return;
    }

    update_post_meta($sub_id, self::META_PHOTO_ID, (int)$attachment_id);

    wp_safe_redirect(wp_get_referer() ?: wc_get_account_endpoint_url('subscriptions'));
    exit;
  }

  public static function admin_enqueue_media($hook) {
    if (!is_admin()) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) return;
    if ($screen->post_type !== 'shop_subscription') return;

    wp_enqueue_media();

    $js = "
      jQuery(function($){
        function setPreview(url){
          $('#roxy_member_photo_preview').attr('src', url).show();
          $('#roxy_member_photo_remove').show();
        }

        $('#roxy_member_photo_select').on('click', function(e){
          e.preventDefault();
          const frame = wp.media({
            title: 'Select Member Photo',
            button: { text: 'Use this photo' },
            multiple: false
          });

          frame.on('select', function(){
            const attachment = frame.state().get('selection').first().toJSON();
            $('#roxy_member_photo_id').val(attachment.id);
            if (attachment.sizes && attachment.sizes.medium) {
              setPreview(attachment.sizes.medium.url);
            } else {
              setPreview(attachment.url);
            }
          });

          frame.open();
        });

        $('#roxy_member_photo_remove').on('click', function(e){
          e.preventDefault();
          $('#roxy_member_photo_id').val('');
          $('#roxy_member_photo_preview').hide().attr('src','');
          $(this).hide();
        });
      });
    ";
    wp_add_inline_script('jquery', $js);
  }

  public static function add_subscription_photo_metabox() {
    if (!post_type_exists('shop_subscription')) return;

    add_meta_box(
      'roxy_member_photo',
      'Roxy Member Photo (for NFC card)',
      [__CLASS__, 'render_subscription_photo_metabox'],
      'shop_subscription',
      'side',
      'default'
    );
  }

  public static function render_subscription_photo_metabox($post) {
    wp_nonce_field('roxy_member_photo_save', 'roxy_member_photo_nonce');

    $photo_id = absint(get_post_meta($post->ID, self::META_PHOTO_ID, true));
    $photo_url = '';
    if ($photo_id) {
      $img = wp_get_attachment_image_src($photo_id, 'medium');
      if (is_array($img) && !empty($img[0])) $photo_url = $img[0];
    }

    echo '<p style="margin-top:0;">Attach a photo to this specific subscription (best for households with multiple cards).</p>';

    echo '<input type="hidden" id="roxy_member_photo_id" name="roxy_member_photo_id" value="' . esc_attr($photo_id) . '">';

    echo '<div style="margin:8px 0;">';
    echo '<img id="roxy_member_photo_preview" src="' . esc_url($photo_url) . '" style="max-width:100%; height:auto; border-radius:10px; border:1px solid #ddd; ' . ($photo_url ? '' : 'display:none;') . '">';
    echo '</div>';

    echo '<p>';
    echo '<a href="#" class="button button-secondary" id="roxy_member_photo_select">Select/Upload Photo</a> ';
    echo '<a href="#" class="button button-link-delete" id="roxy_member_photo_remove" style="' . ($photo_url ? '' : 'display:none;') . '">Remove</a>';
    echo '</p>';

    echo '<p style="opacity:.75; font-size:12px; margin-bottom:0;">Tip: clear headshot. Photo only appears at the door when membership is ACTIVE.</p>';
  }

  public static function save_subscription_photo_metabox($post_id, $post) {
    if ($post->post_type !== 'shop_subscription') return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (!isset($_POST['roxy_member_photo_nonce']) || !wp_verify_nonce($_POST['roxy_member_photo_nonce'], 'roxy_member_photo_save')) {
      return;
    }

    $photo_id = isset($_POST['roxy_member_photo_id']) ? absint($_POST['roxy_member_photo_id']) : 0;

    if ($photo_id > 0) {
      update_post_meta($post_id, self::META_PHOTO_ID, $photo_id);
    } else {
      delete_post_meta($post_id, self::META_PHOTO_ID);
    }
  }
}

Roxy_Sub_Check::init();
