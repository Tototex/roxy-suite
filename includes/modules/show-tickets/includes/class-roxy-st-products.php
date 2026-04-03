<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

class Products {
  public static function init(): void {
    add_action('save_post_' . CPT::POST_TYPE, [__CLASS__, 'on_showing_saved'], 20, 2);
    add_action('pre_get_posts', [__CLASS__, 'hide_ticket_products_from_admin_list']);
    add_action('views_edit-product', [__CLASS__, 'add_ticket_product_views']);
  }

  public static function on_showing_saved(int $post_id, $post): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!class_exists('WooCommerce')) return;

    if (!self::showing_is_ready_for_products($post_id)) {
      return;
    }

    self::ensure_products_for_showing($post_id);
    self::trash_products_for_expired_showing($post_id);
    self::trash_autodraft_ticket_products();
  }

  public static function run_admin_cleanup(): void {
    if (!current_user_can('edit_products')) return;
    if (!class_exists('WooCommerce')) return;

    self::trash_autodraft_ticket_products();
    self::trash_expired_showing_products();
  }

  public static function hide_ticket_products_from_admin_list($query): void {
    if (!is_admin() || !$query->is_main_query()) return;

    $post_type = $query->get('post_type');
    if ($post_type !== 'product') return;

    if ($query->get('post_status') === 'trash') return;

    $meta_query = (array) $query->get('meta_query');

    if (!empty($_GET['roxy_st_show_tickets'])) {
      $meta_query[] = [
        'key' => ROXY_ST_META_SHOWING_ID,
        'compare' => 'EXISTS',
      ];
      $query->set('meta_query', $meta_query);
      return;
    }

    $meta_query[] = [
      'key' => ROXY_ST_META_SHOWING_ID,
      'compare' => 'NOT EXISTS',
    ];
    $query->set('meta_query', $meta_query);
  }


  public static function add_ticket_product_views(array $views): array {
    if (!current_user_can('edit_products')) return $views;

    $base_url = admin_url('edit.php?post_type=product');
    $showing_url = add_query_arg('roxy_st_show_tickets', '1', $base_url);
    $default_url = remove_query_arg('roxy_st_show_tickets', $base_url);

    $is_ticket_view = !empty($_GET['roxy_st_show_tickets']);

    $ticket_count = self::count_ticket_products();
    $label = 'Ticket Products';
    if ($ticket_count > 0) {
      $label .= ' <span class="count">(' . number_format_i18n($ticket_count) . ')</span>';
    }

    $views['roxy_st_default_products'] = sprintf(
      '<a href="%s"%s>Standard Products</a>',
      esc_url($default_url),
      $is_ticket_view ? '' : ' class="current" aria-current="page"'
    );

    $views['roxy_st_ticket_products'] = sprintf(
      '<a href="%s"%s>%s</a>',
      esc_url($showing_url),
      $is_ticket_view ? ' class="current" aria-current="page"' : '',
      $label
    );

    return $views;
  }

  private static function count_ticket_products(): int {
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(DISTINCT p.ID)
       FROM {$wpdb->posts} p
       INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
       WHERE p.post_type = 'product'
         AND p.post_status <> 'trash'
         AND pm.meta_key = %s",
      ROXY_ST_META_SHOWING_ID
    ));
  }

  public static function ensure_products_for_showing(int $showing_id): void {
    if (!self::showing_is_ready_for_products($showing_id)) {
      return;
    }

    if (!self::acquire_sync_lock($showing_id)) {
      return;
    }

    try {
      $profile = get_post_meta($showing_id, '_roxy_pricing_profile', true) ?: 'movie_evening';

    $title = trim((string) get_the_title($showing_id));
    $start = get_post_meta($showing_id, '_roxy_start', true);
    $start_label = $start ? date_i18n('D n/j g:ia', strtotime($start)) : '';

    $thumb_id = get_post_thumbnail_id($showing_id);

    $need = [];

    if ($profile === 'movie_evening') {
      $need['adult'] = ['price' => Settings::get_price('general_price', 12), 'label' => 'General'];
      $need['discount'] = ['price' => Settings::get_price('discount_price', 8), 'label' => 'Discount'];
      $need['subscriber'] = ['price' => 0, 'label' => 'Subscriber'];
    } elseif ($profile === 'movie_matinee') {
      $need['matinee'] = ['price' => Settings::get_price('matinee_price', 8), 'label' => 'Matinee'];
      $need['subscriber'] = ['price' => 0, 'label' => 'Subscriber'];
    } elseif ($profile === 'live_event') {
      $l1 = get_post_meta($showing_id, '_roxy_live_label_1', true) ?: 'General Admission';
      $p1 = self::get_live_tier_active_price($showing_id, 1);
      if (self::live_tier_is_configured($showing_id, 1)) $need['live1'] = ['price' => $p1, 'label' => $l1];

      $l2 = get_post_meta($showing_id, '_roxy_live_label_2', true) ?: 'VIP';
      $p2 = self::get_live_tier_active_price($showing_id, 2);
      if (self::live_tier_is_configured($showing_id, 2)) $need['live2'] = ['price' => $p2, 'label' => $l2];

      $need['subscriber'] = ['price' => 0, 'label' => 'Subscriber'];
    }

    foreach ($need as $type => $cfg) {
      $meta_key = self::type_to_meta_key($type);
      $existing = (int) get_post_meta($showing_id, $meta_key, true);
      $existing = self::canonical_product_id($showing_id, $type, $existing);

      $prod_title = trim($title . ($start_label ? " — {$start_label}" : '') . " ({$cfg['label']})");
      $product_id = self::upsert_product($existing, $prod_title, $cfg['price'], $showing_id, $type, $thumb_id);
      if ($product_id) {
        update_post_meta($showing_id, $meta_key, (int) $product_id);
      }
    }

    $all_types = ['adult','discount','matinee','live1','live2','subscriber'];
    foreach ($all_types as $t) {
      if (!isset($need[$t])) {
        $k = self::type_to_meta_key($t);
        $existing = (int) get_post_meta($showing_id, $k, true);
        if ($existing > 0 && get_post_type($existing) === 'product' && get_post_status($existing) !== 'trash') {
          wp_trash_post($existing);
        }
        foreach (self::find_products_for_showing_type($showing_id, $t) as $extra_id) {
          if ($extra_id > 0 && get_post_status($extra_id) !== 'trash') {
            wp_trash_post($extra_id);
          }
        }
        delete_post_meta($showing_id, $k);
      }
    }
    } finally {
      self::release_sync_lock($showing_id);
    }
  }


  public static function live_tier_is_configured(int $showing_id, int $tier): bool {
    $raw = get_post_meta($showing_id, '_roxy_live_price_' . $tier, true);
    return $raw !== '' && $raw !== null;
  }

  public static function get_live_tier_active_price(int $showing_id, int $tier): float {
    $base_raw = get_post_meta($showing_id, '_roxy_live_price_' . $tier, true);
    $future_raw = get_post_meta($showing_id, '_roxy_live_future_price_' . $tier, true);
    $base = ($base_raw === '' || $base_raw === null) ? null : (float) $base_raw;
    $future = ($future_raw === '' || $future_raw === null) ? null : (float) $future_raw;
    $change_at = (string) get_post_meta($showing_id, '_roxy_live_change_at_' . $tier, true);

    if ($base === null) {
      return 0.0;
    }

    if ($future === null || $change_at === '') {
      return (float) $base;
    }

    $change_ts = strtotime($change_at);
    if (!$change_ts) {
      return (float) $base;
    }

    return current_time('timestamp') >= $change_ts ? (float) $future : (float) $base;
  }

  public static function get_live_tier_display_price(int $showing_id, int $tier): array {
    $base = (float) get_post_meta($showing_id, '_roxy_live_price_' . $tier, true);
    $future = (float) get_post_meta($showing_id, '_roxy_live_future_price_' . $tier, true);
    $change_at = (string) get_post_meta($showing_id, '_roxy_live_change_at_' . $tier, true);
    $active = self::get_live_tier_active_price($showing_id, $tier);

    return [
      'active' => $active,
      'base' => $base,
      'future' => $future,
      'change_at' => $change_at,
      'is_scheduled' => (($future_raw = get_post_meta($showing_id, '_roxy_live_future_price_' . $tier, true)) !== '' && $change_at !== '' && strtotime($change_at)),
      'is_future_active' => (($future_raw = get_post_meta($showing_id, '_roxy_live_future_price_' . $tier, true)) !== '' && (float) $active === (float) $future && $change_at !== '' && current_time('timestamp') >= strtotime($change_at)),
    ];
  }

  private static function showing_is_ready_for_products(int $showing_id): bool {
    if ($showing_id <= 0) return false;
    if (get_post_type($showing_id) !== CPT::POST_TYPE) return false;

    $status = (string) get_post_status($showing_id);
    if (in_array($status, ['auto-draft', 'draft', 'trash'], true)) return false;

    $title = trim((string) get_the_title($showing_id));
    if ($title === '' || stripos($title, 'Auto Draft') === 0) return false;

    $start = (string) get_post_meta($showing_id, '_roxy_start', true);
    if ($start === '' || !strtotime($start)) return false;

    return true;
  }


  private static function acquire_sync_lock(int $showing_id): bool {
    $key = 'roxy_st_sync_' . (int) $showing_id;
    if (get_transient($key)) {
      return false;
    }
    set_transient($key, 1, 30);
    return true;
  }

  private static function release_sync_lock(int $showing_id): void {
    delete_transient('roxy_st_sync_' . (int) $showing_id);
  }

  private static function canonical_product_id(int $showing_id, string $ticket_type, int $preferred_id = 0): int {
    $candidates = self::find_products_for_showing_type($showing_id, $ticket_type);
    if ($preferred_id > 0 && in_array($preferred_id, $candidates, true) && get_post_status($preferred_id) !== 'trash') {
      $canonical = $preferred_id;
    } else {
      $canonical = 0;
      foreach ($candidates as $candidate_id) {
        if (get_post_status($candidate_id) !== 'trash') {
          $canonical = (int) $candidate_id;
          break;
        }
      }
      if ($canonical <= 0 && !empty($candidates)) {
        $canonical = (int) reset($candidates);
        if ($canonical > 0 && get_post_status($canonical) === 'trash') {
          wp_untrash_post($canonical);
        }
      }
    }

    foreach ($candidates as $candidate_id) {
      $candidate_id = (int) $candidate_id;
      if ($candidate_id > 0 && $canonical > 0 && $candidate_id !== $canonical && get_post_status($candidate_id) !== 'trash') {
        wp_trash_post($candidate_id);
      }
    }

    return max(0, $canonical);
  }

  private static function find_products_for_showing_type(int $showing_id, string $ticket_type): array {
    $posts = get_posts([
      'post_type' => 'product',
      'post_status' => ['publish', 'private', 'draft', 'pending', 'future', 'trash'],
      'posts_per_page' => -1,
      'fields' => 'ids',
      'orderby' => 'ID',
      'order' => 'ASC',
      'meta_query' => [
        [
          'key' => ROXY_ST_META_SHOWING_ID,
          'value' => (int) $showing_id,
          'compare' => '=',
        ],
        [
          'key' => ROXY_ST_META_TICKET_TYPE,
          'value' => sanitize_key($ticket_type),
          'compare' => '=',
        ],
      ],
      'no_found_rows' => true,
      'cache_results' => false,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
    ]);

    return array_values(array_unique(array_map('intval', $posts)));
  }

  private static function type_to_meta_key(string $type): string {
    return '_roxy_pid_' . $type;
  }

  private static function upsert_product(int $existing_id, string $title, float $price, int $showing_id, string $ticket_type, int $thumb_id): int {
    $postarr = [
      'post_title'   => $title,
      'post_type'    => 'product',
      'post_status'  => 'publish',
      'post_content' => '',
    ];

    if ($existing_id && get_post_type($existing_id) === 'product') {
      $postarr['ID'] = $existing_id;
      $product_id = wp_update_post($postarr, true);
    } else {
      $product_id = wp_insert_post($postarr, true);
    }

    if (is_wp_error($product_id) || !$product_id) return 0;

    update_post_meta($product_id, '_virtual', 'yes');
    update_post_meta($product_id, '_downloadable', 'no');
    update_post_meta($product_id, '_manage_stock', 'no');
    update_post_meta($product_id, '_stock_status', 'instock');
    update_post_meta($product_id, '_sold_individually', 'no');

    $price_str = wc_format_decimal($price, wc_get_price_decimals());
    update_post_meta($product_id, '_regular_price', $price_str);
    update_post_meta($product_id, '_price', $price_str);
    update_post_meta($product_id, '_tax_status', 'none');
    update_post_meta($product_id, '_tax_class', '');

    wp_set_object_terms($product_id, ['exclude-from-catalog','exclude-from-search'], 'product_visibility', false);

    update_post_meta($product_id, ROXY_ST_META_SHOWING_ID, $showing_id);
    update_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, $ticket_type);

    if ($thumb_id) {
      set_post_thumbnail($product_id, $thumb_id);
    }

    return (int) $product_id;
  }

  private static function trash_expired_showing_products(): void {
    $now = current_time('mysql');
    $showings = get_posts([
      'post_type' => CPT::POST_TYPE,
      'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
      'posts_per_page' => 100,
      'fields' => 'ids',
      'meta_key' => '_roxy_start',
      'meta_value' => $now,
      'meta_compare' => '<',
      'orderby' => 'meta_value',
      'order' => 'ASC',
      'no_found_rows' => true,
    ]);

    foreach ($showings as $showing_id) {
      self::trash_products_for_expired_showing((int) $showing_id);
    }
  }

  private static function trash_products_for_expired_showing(int $showing_id): void {
    $start = (string) get_post_meta($showing_id, '_roxy_start', true);
    if ($start === '' || strtotime($start) === false) return;
    if (strtotime($start) >= current_time('timestamp')) return;

    foreach (self::get_showing_product_ids($showing_id) as $product_id) {
      if ($product_id > 0 && get_post_type($product_id) === 'product' && get_post_status($product_id) !== 'trash') {
        wp_trash_post($product_id);
      }
    }
  }

  private static function get_showing_product_ids(int $showing_id): array {
    $ids = [];
    foreach (['adult','discount','matinee','live1','live2','subscriber'] as $t) {
      $id = (int) get_post_meta($showing_id, self::type_to_meta_key($t), true);
      if ($id > 0) {
        $ids[] = $id;
      }
    }
    return array_values(array_unique($ids));
  }

  private static function trash_autodraft_ticket_products(): void {
    global $wpdb;

    $product_ids = $wpdb->get_col($wpdb->prepare(
      "SELECT p.ID
       FROM {$wpdb->posts} p
       INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
       WHERE p.post_type = 'product'
         AND p.post_status <> 'trash'
         AND p.post_title LIKE %s
         AND pm.meta_key = %s",
      'Auto Draft%',
      ROXY_ST_META_SHOWING_ID
    ));

    foreach ($product_ids as $product_id) {
      wp_trash_post((int) $product_id);
    }
  }
}
