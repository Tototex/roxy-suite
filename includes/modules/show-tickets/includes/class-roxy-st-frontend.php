<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

class Frontend {
  public static function init(): void {
    add_shortcode('roxy_showings', [__CLASS__, 'shortcode_showings']);
    add_shortcode('roxy_showings_home', [__CLASS__, 'shortcode_showings_home']);
    add_filter('the_content', [__CLASS__, 'append_single_showing_content']);

    add_action('admin_post_nopriv_roxy_st_add', [__CLASS__, 'handle_add']);
    add_action('admin_post_roxy_st_add', [__CLASS__, 'handle_add']);
    add_action('wp_head', [__CLASS__, 'output_head_meta'], 5);

    add_filter('woocommerce_add_to_cart_validation', function ($passed, $product_id, $quantity, $variation_id = 0, $variations = []) {
      if ($passed) return $passed;
      $showing_id = (int) get_post_meta($product_id, ROXY_ST_META_SHOWING_ID, true);
      $ticket_type = (string) get_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, true);
      if ($showing_id <= 0 || $ticket_type === '') return $passed;

      Log::warn('diag add_to_cart_validation rejected', [
        'product_id' => (int) $product_id,
        'showing_id' => $showing_id,
        'ticket_type' => $ticket_type,
        'qty' => (int) $quantity,
        'notices' => function_exists('wc_get_notices') ? wc_get_notices() : [],
      ]);
      return $passed;
    }, 1000, 5);
  }

  public static function shortcode_showings($atts): string {
    $atts = shortcode_atts([
      'limit' => 20,
      'days' => 60,
      'show_images' => '1',
    ], $atts);

    return self::render_showings_listing([
      'limit' => max(1, (int) $atts['limit']),
      'days' => max(1, (int) $atts['days']),
      'show_images' => $atts['show_images'] === '1',
      'show_all_button' => false,
      'all_button_url' => '',
      'all_button_label' => '',
    ]);
  }

  public static function shortcode_showings_home($atts): string {
    $atts = shortcode_atts([
      'limit' => 6,
      'days' => 14,
      'show_images' => '1',
      'tickets_url' => home_url('/tickets/'),
      'button_label' => 'View All Showings',
    ], $atts);

    return self::render_showings_listing([
      'limit' => max(1, (int) $atts['limit']),
      'days' => max(1, (int) $atts['days']),
      'show_images' => $atts['show_images'] === '1',
      'show_all_button' => true,
      'all_button_url' => (string) $atts['tickets_url'],
      'all_button_label' => (string) $atts['button_label'],
      'pin_next_live' => true,
    ]);
  }

  private static function render_showings_listing(array $args): string {
    $limit = max(1, (int) ($args['limit'] ?? 20));
    $days = max(1, (int) ($args['days'] ?? 60));
    $show_images = !empty($args['show_images']);
    $show_all_button = !empty($args['show_all_button']);
    $all_button_url = (string) ($args['all_button_url'] ?? '');
    $all_button_label = trim((string) ($args['all_button_label'] ?? 'View All Showings'));

    $now = current_time('timestamp');
    $end = $now + ($days * DAY_IN_SECONDS);

    $q = new \WP_Query([
      'post_type' => CPT::POST_TYPE,
      'posts_per_page' => $limit,
      'post_status' => 'publish',
      'meta_key' => '_roxy_start',
      'orderby' => 'meta_value',
      'order' => 'ASC',
      'meta_query' => [
        [
          'key' => '_roxy_start',
          'value' => [date('Y-m-d\TH:i', $now), date('Y-m-d\TH:i', $end)],
          'compare' => 'BETWEEN',
          'type' => 'CHAR',
        ]
      ]
    ]);

    ob_start();

    echo '<style>
      .roxy-st-form{display:grid;gap:8px;}
      .roxy-st-ticket-list{display:grid;gap:8px;}
      .roxy-st-ticket-row{display:grid;grid-template-columns:minmax(220px,340px) 84px;justify-content:end;align-items:start;gap:12px;}
      .roxy-st-ticket-label{min-width:0;}
      .roxy-st-ticket-title{line-height:1.2;}
      .roxy-st-ticket-note{font-size:12px;opacity:.8;margin-top:4px;}
      .roxy-st-ticket-input{width:84px;padding:8px 10px;border:1px solid #ddd;border-radius:10px;}
      .roxy-st-soldout{margin-top:8px;padding:14px 16px;border-radius:10px;background:#1f1f1f !important;color:#ff4d4f !important;font-weight:800;text-align:center;text-transform:uppercase;letter-spacing:.04em;border:1px solid rgba(255,77,79,.35);box-shadow:inset 0 0 0 1px rgba(255,255,255,.03);}
      .roxy-st-soldout, .roxy-st-soldout *{color:#ff4d4f !important;}
      .roxy-st-all-button-wrap{margin-top:18px;text-align:center;}
      .roxy-st-all-button{display:inline-block;padding:12px 18px;border-radius:12px;background:#111;color:#fff !important;text-decoration:none;font-weight:700;box-shadow:0 8px 18px rgba(0,0,0,.15);}
      @media (max-width: 640px){
        .roxy-st-ticket-row{grid-template-columns:minmax(0,1fr) 84px;justify-content:stretch;}
      }
    </style>';
    echo '<div class="roxy-st-grid" style="display:grid;gap:16px">';

    // Collect posts so we can optionally inject a pinned live event
    $posts = $q->posts;

    // Pin the next upcoming live event into the last slot when it isn't already in the set
    if (!empty($args['pin_next_live'])) {
      $has_live = false;
      foreach ($posts as $p) {
        if (get_post_meta($p->ID, '_roxy_pricing_profile', true) === 'live_event') {
          $has_live = true;
          break;
        }
      }
      if (!$has_live) {
        $live_posts = get_posts([
          'post_type'      => CPT::POST_TYPE,
          'posts_per_page' => 1,
          'post_status'    => 'publish',
          'meta_key'       => '_roxy_start',
          'orderby'        => 'meta_value',
          'order'          => 'ASC',
          'no_found_rows'  => true,
          'meta_query'     => [
            'relation' => 'AND',
            ['key' => '_roxy_start',          'value' => date('Y-m-d\TH:i', $now), 'compare' => '>=',  'type' => 'CHAR'],
            ['key' => '_roxy_pricing_profile', 'value' => 'live_event',             'compare' => '='],
          ],
        ]);
        if (!empty($live_posts)) {
          $pinned_id = (int) $live_posts[0]->ID;
          $already_in = false;
          foreach ($posts as $p) { if ((int) $p->ID === $pinned_id) { $already_in = true; break; } }
          if (!$already_in) {
            if (count($posts) >= $limit) { array_pop($posts); } // free up the last slot
            $posts[] = $live_posts[0];
            // Re-sort chronologically — live event naturally ends up last when it's far out
            usort($posts, function($a, $b) {
              return strcmp(
                (string) get_post_meta($a->ID, '_roxy_start', true),
                (string) get_post_meta($b->ID, '_roxy_start', true)
              );
            });
          }
        }
      }
    }

    if (empty($posts)) {
      echo '<p>No upcoming showings found.</p>';
      if ($show_all_button && $all_button_url !== '') {
        echo '<div class="roxy-st-all-button-wrap"><a class="roxy-st-all-button" href="' . esc_url($all_button_url) . '">' . esc_html($all_button_label !== '' ? $all_button_label : 'View All Showings') . '</a></div>';
      }
      echo '</div>';
      return (string) ob_get_clean();
    }

    global $post;
    foreach ($posts as $post) {
      setup_postdata($post);
      $sid = $post->ID;
      $title = get_the_title();
      $start = get_post_meta($sid, '_roxy_start', true);
      $profile = get_post_meta($sid, '_roxy_pricing_profile', true) ?: 'movie_evening';

      $date_label = $start ? date_i18n('l, F j · g:ia', strtotime($start)) : '';

      $img = '';
      $permalink = get_permalink($sid);
      if ($show_images && has_post_thumbnail($sid)) {
        $img = '<a href="' . esc_url($permalink) . '" aria-label="' . esc_attr(sprintf(__('More information about %s', 'roxy-show-tickets'), $title)) . '">' . get_the_post_thumbnail($sid, 'medium', ['style' => 'width:100%;height:auto;border-radius:10px']) . '</a>';
      }

      echo '<div class="roxy-st-card" style="border:1px solid #ddd;border-radius:14px;padding:14px">';
      echo '<div style="display:grid;grid-template-columns:140px 1fr;gap:14px;align-items:start">';
      echo '<div>' . $img . '</div>';
      echo '<div>';
      echo '<div style="font-weight:700;font-size:18px;margin-bottom:4px"><a href="' . esc_url($permalink) . '" style="text-decoration:none;color:inherit">' . esc_html($title) . '</a></div>';
      if ($date_label) echo '<div style="opacity:.8;margin-bottom:8px">' . esc_html($date_label) . '</div>';
      $remaining = ($profile !== 'free_event') ? Capacity::remaining_seats_for_showing($sid) : null;
      if (is_int($remaining) && $remaining > 0 && $remaining <= 50) {
        echo '<div style="opacity:.9;margin-bottom:10px;font-weight:600">Only ' . esc_html($remaining) . ' seats remaining</div>';
      }
      echo '<div style="margin-bottom:10px"><a href="' . esc_url($permalink) . '" style="font-weight:600">More info</a></div>';

      echo self::render_ticket_form($sid, $profile, $remaining);

      echo '</div></div></div>';
    }

    wp_reset_postdata();

    if ($show_all_button && $all_button_url !== '') {
      echo '<div class="roxy-st-all-button-wrap"><a class="roxy-st-all-button" href="' . esc_url($all_button_url) . '">' . esc_html($all_button_label !== '' ? $all_button_label : 'View All Showings') . '</a></div>';
    }

    echo '</div>';

    return (string) ob_get_clean();
  }

  private static function render_ticket_form(int $showing_id, string $profile, ?int $remaining_seats = null): string {
    if ($profile === 'free_event') {
      return '<div class="roxy-st-free-admission" style="display:flex;align-items:center;gap:10px;padding:14px 16px;border-radius:12px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3)">'
        . '<span style="font-size:20px">🎟️</span>'
        . '<span><strong>Free Admission</strong> — No ticket or reservation required.</span>'
        . '</div>';
    }

    $action = esc_url(admin_url('admin-post.php'));
    $nonce = wp_create_nonce('roxy_st_add');

    $p = [
      'adult' => (int) get_post_meta($showing_id, '_roxy_pid_adult', true),
      'discount' => (int) get_post_meta($showing_id, '_roxy_pid_discount', true),
      'matinee' => (int) get_post_meta($showing_id, '_roxy_pid_matinee', true),
      'live1' => (int) get_post_meta($showing_id, '_roxy_pid_live1', true),
      'live2' => (int) get_post_meta($showing_id, '_roxy_pid_live2', true),
      'subscriber' => (int) get_post_meta($showing_id, '_roxy_pid_subscriber', true),
    ];

    $general_price = Settings::get_price('general_price', 12);
    $discount_price = Settings::get_price('discount_price', 8);
    $matinee_price = Settings::get_price('matinee_price', 8);
    $discount_note = (string) Settings::get('discount_note', 'Under 12, over 65, military.');
    $subscriber_url = (string) Settings::get('subscriber_url', '');

    $rows = [];
    if ($profile === 'movie_evening') {
      $rows[] = self::qty_row('General ($' . wc_format_localized_price($general_price) . ')', 'general_qty');
      $rows[] = self::qty_row('Discount ($' . wc_format_localized_price($discount_price) . ')', 'discount_qty', null, 0, $discount_note);
    } elseif ($profile === 'movie_matinee') {
      $rows[] = self::qty_row('Matinee ($' . wc_format_localized_price($matinee_price) . ')', 'matinee_qty');
    } elseif ($profile === 'live_event') {
      $l1 = get_post_meta($showing_id, '_roxy_live_label_1', true) ?: 'General Admission';
      $d1 = Products::get_live_tier_display_price($showing_id, 1);
      if ($p['live1'] && Products::live_tier_is_configured($showing_id, 1)) {
        $rows[] = self::qty_row($l1 . ' ($' . wc_format_localized_price((float) $d1['active']) . ')', 'live1_qty', null, 0, self::live_price_note($d1));
      }

      $l2 = get_post_meta($showing_id, '_roxy_live_label_2', true) ?: 'VIP';
      $d2 = Products::get_live_tier_display_price($showing_id, 2);
      if ($p['live2'] && Products::live_tier_is_configured($showing_id, 2)) {
        $rows[] = self::qty_row($l2 . ' ($' . wc_format_localized_price((float) $d2['active']) . ')', 'live2_qty', null, 0, self::live_price_note($d2));
      }
    }

    $sub_row = '';
    if ($p['subscriber']) {
      $user_id = get_current_user_id();
      $max = 0;
      if ($user_id > 0) {
        $max = Capacity::subscription_entitlement_count((int) $user_id);
      }
      if ($max > 0) {
        $remaining_subscriber = Capacity::remaining_subscriber_seats_for_showing($showing_id, $user_id);
        if ($remaining_subscriber > 0) {
          $sub_row = self::qty_row(
            'Subscriber (free)',
            'subscriber_qty',
            $remaining_subscriber,
            0,
            'Subscriber seats remaining: ' . (int) $remaining_subscriber
          );
        } else {
          $sub_row = self::qty_row(
            'Subscriber (free)',
            'subscriber_qty_disabled',
            0,
            0,
            'Subscriber seats remaining: 0',
            true
          );
        }
      } else {
        $message = $user_id <= 0 ? 'Log in or <a href="' . esc_url($subscriber_url) . '">become a subscriber</a>.' : 'No active subscription found. <a href="' . esc_url($subscriber_url) . '">Become a subscriber</a>.';
        $sub_row = self::qty_row('Subscriber (free)', 'subscriber_qty_disabled', 0, 0, $message, true);
      }
    }

    if (is_int($remaining_seats) && $remaining_seats <= 0) {
      return '<div class="roxy-st-soldout">Sold Out</div>';
    }

    $html = '';
    $html .= '<form method="post" action="' . $action . '" class="roxy-st-form">';
    $html .= '<input type="hidden" name="action" value="roxy_st_add">';
    $html .= '<input type="hidden" name="nonce" value="' . esc_attr($nonce) . '">';
    $html .= '<input type="hidden" name="showing_id" value="' . esc_attr($showing_id) . '">';

    $html .= '<div class="roxy-st-ticket-list">' . implode('', $rows) . '</div>';
    $html .= $sub_row;

    $html .= '<button type="submit" style="margin-top:10px;padding:10px 14px;border-radius:10px;border:0;background:#111;color:#fff;font-weight:700;cursor:pointer">Get tickets</button>';
    $html .= '</form>';

    return $html;
  }

  private static function live_price_note(array $display): string {
    $future = (float) ($display['future'] ?? 0);
    $change_at = (string) ($display['change_at'] ?? '');
    $is_scheduled = !empty($display['is_scheduled']);
    $is_future_active = !empty($display['is_future_active']);

    if (!$is_scheduled || $future <= 0 || $change_at === '' || $is_future_active) {
      return '';
    }

    $change_ts = strtotime($change_at);
    if (!$change_ts) return '';

    $delta = max(0, $change_ts - current_time('timestamp'));
    if ($delta <= 0) return '';

    if ($delta >= DAY_IN_SECONDS) {
      $count = (int) ceil($delta / DAY_IN_SECONDS);
      $unit = $count === 1 ? 'day' : 'days';
    } elseif ($delta >= HOUR_IN_SECONDS) {
      $count = (int) ceil($delta / HOUR_IN_SECONDS);
      $unit = $count === 1 ? 'hour' : 'hours';
    } else {
      $count = max(1, (int) ceil($delta / MINUTE_IN_SECONDS));
      $unit = $count === 1 ? 'minute' : 'minutes';
    }

    return 'Price increases in ' . $count . ' ' . $unit . '.';
  }

  private static function qty_row(string $label, string $name, ?int $max = null, int $value = 0, string $note = '', bool $disabled = false): string {
    $input_style = 'background:' . ($disabled ? '#f2f2f2' : '#fff') . ';';
    $max_attr = is_int($max) && $max > 0 ? ' max="' . (int) $max . '"' : '';
    $disabled_attr = $disabled ? ' disabled aria-disabled="true"' : '';
    $wrapper_opacity = $disabled ? 'opacity:.7;' : '';
    $note_html = $note !== '' ? '<div class="roxy-st-ticket-note">' . wp_kses_post($note) . '</div>' : '';

    return '<div class="roxy-st-ticket-row" style="' . esc_attr($wrapper_opacity) . '">'
      . '<div class="roxy-st-ticket-label">'
      . '<div class="roxy-st-ticket-title">' . esc_html($label) . '</div>'
      . $note_html
      . '</div>'
      . '<input type="number" min="0" step="1"' . $max_attr . $disabled_attr . ' name="' . esc_attr($name) . '" value="' . (int) $value . '" class="roxy-st-ticket-input" style="' . esc_attr($input_style) . '" />'
      . '</div>';
  }





  public static function output_head_meta(): void {
    if (is_admin() || !is_singular(CPT::POST_TYPE)) {
      return;
    }

    $showing_id = get_queried_object_id();
    if ($showing_id <= 0 || get_post_type($showing_id) !== CPT::POST_TYPE) {
      return;
    }

    $title = self::meta_title($showing_id);
    $description = self::meta_description($showing_id);
    $url = get_permalink($showing_id);
    $image = self::meta_image_url($showing_id);

    echo "\n<!-- Roxy Show Tickets social metadata -->\n";
    echo '<meta property="og:type" content="website" />' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($description) . '" />' . "\n";
    echo '<meta property="og:url" content="' . esc_url($url) . '" />' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '" />' . "\n";
    if ($image !== '') {
      echo '<meta property="og:image" content="' . esc_url($image) . '" />' . "\n";
    }

    echo '<meta name="twitter:card" content="' . esc_attr($image !== '' ? 'summary_large_image' : 'summary') . '" />' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr($description) . '" />' . "\n";
    if ($image !== '') {
      echo '<meta name="twitter:image" content="' . esc_url($image) . '" />' . "\n";
    }

    $schema = self::event_schema($showing_id);
    if (!empty($schema)) {
      echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
  }

  private static function meta_title(int $showing_id): string {
    $title = trim((string) get_the_title($showing_id));
    $start = (string) get_post_meta($showing_id, '_roxy_start', true);
    if ($start !== '') {
      $ts = strtotime($start);
      if ($ts) {
        $title .= ' — ' . date_i18n('l, F j \a\t g:ia', $ts);
      }
    }
    return $title;
  }

  private static function meta_description(int $showing_id): string {
    $excerpt = trim((string) get_post_field('post_excerpt', $showing_id));
    $source = $excerpt !== '' ? $excerpt : (string) get_post_field('post_content', $showing_id);
    $text = trim(wp_strip_all_tags(strip_shortcodes($source)));
    if ($text === '') {
      $text = trim((string) get_bloginfo('description'));
    }
    if ($text === '') {
      $text = 'Get tickets now at the Newport Roxy.';
    }
    return wp_html_excerpt($text, 200, '…');
  }

  private static function meta_image_url(int $showing_id): string {
    if (has_post_thumbnail($showing_id)) {
      $img = get_the_post_thumbnail_url($showing_id, 'large');
      if (is_string($img) && $img !== '') {
        return $img;
      }
    }

    $icon_id = (int) get_option('site_icon');
    if ($icon_id > 0) {
      $icon = wp_get_attachment_image_url($icon_id, 'full');
      if (is_string($icon) && $icon !== '') {
        return $icon;
      }
    }

    return '';
  }

  private static function event_schema(int $showing_id): array {
    $title = trim((string) get_the_title($showing_id));
    $url = get_permalink($showing_id);
    $description = self::meta_description($showing_id);
    $image = self::meta_image_url($showing_id);
    $start = (string) get_post_meta($showing_id, '_roxy_start', true);
    $start_iso = self::schema_start_date($start);
    $remaining = Capacity::remaining_seats_for_showing($showing_id);

    if ($title === '' || $url === '' || $start_iso === '') {
      return [];
    }

    $schema = [
      '@context' => 'https://schema.org',
      '@type' => 'Event',
      'name' => $title,
      'startDate' => $start_iso,
      'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
      'eventStatus' => 'https://schema.org/EventScheduled',
      'description' => $description,
      'url' => $url,
      'location' => [
        '@type' => 'Place',
        'name' => 'Newport Roxy Theater',
        'address' => [
          '@type' => 'PostalAddress',
          'addressLocality' => 'Newport',
          'addressRegion' => 'WA',
          'addressCountry' => 'US',
        ],
      ],
      'organizer' => [
        '@type' => 'Organization',
        'name' => get_bloginfo('name'),
        'url' => home_url('/'),
      ],
    ];

    if ($image !== '') {
      $schema['image'] = [$image];
    }

    $offers = self::schema_offers($showing_id, $url, $remaining);
    if (!empty($offers)) {
      $schema['offers'] = $offers;
    }

    return $schema;
  }

  private static function schema_start_date(string $start): string {
    if ($start === '') {
      return '';
    }

    $ts = strtotime($start);
    if (!$ts) {
      return '';
    }

    $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone((string) get_option('timezone_string') ?: 'UTC');
    $dt = new \DateTime('@' . $ts);
    $dt->setTimezone($tz);
    return $dt->format('c');
  }

  private static function schema_offers(int $showing_id, string $url, ?int $remaining): array {
    $prices = [];
    foreach (['adult', 'discount', 'matinee', 'live1', 'live2'] as $type) {
      $pid = (int) get_post_meta($showing_id, '_roxy_pid_' . $type, true);
      if ($pid <= 0) {
        continue;
      }
      $product = wc_get_product($pid);
      if (!$product) {
        continue;
      }
      $price = $product->get_price();
      if ($price === '' || $price === null) {
        continue;
      }
      $prices[] = (float) $price;
    }

    if (empty($prices)) {
      return [];
    }

    sort($prices, SORT_NUMERIC);
    $availability = (is_int($remaining) && $remaining <= 0)
      ? 'https://schema.org/SoldOut'
      : 'https://schema.org/InStock';

    if (count($prices) > 1 && end($prices) > $prices[0]) {
      return [
        '@type' => 'AggregateOffer',
        'url' => $url . '#roxy-showing-tickets',
        'priceCurrency' => get_woocommerce_currency(),
        'lowPrice' => number_format((float) $prices[0], 2, '.', ''),
        'highPrice' => number_format((float) end($prices), 2, '.', ''),
        'offerCount' => count($prices),
        'availability' => $availability,
      ];
    }

    return [
      '@type' => 'Offer',
      'url' => $url . '#roxy-showing-tickets',
      'priceCurrency' => get_woocommerce_currency(),
      'price' => number_format((float) $prices[0], 2, '.', ''),
      'availability' => $availability,
      'validFrom' => current_time('c'),
    ];
  }

  public static function append_single_showing_content($content): string {
    if (is_admin() || !is_singular(CPT::POST_TYPE) || !in_the_loop() || !is_main_query()) {
      return (string) $content;
    }

    $showing_id = get_the_ID();
    if ($showing_id <= 0 || get_post_type($showing_id) !== CPT::POST_TYPE) {
      return (string) $content;
    }

    $title = get_the_title($showing_id);
    $start = (string) get_post_meta($showing_id, '_roxy_start', true);
    $profile = (string) get_post_meta($showing_id, '_roxy_pricing_profile', true);
    $trailer_url = (string) get_post_meta($showing_id, '_roxy_trailer_url', true);
    if ($profile === '') {
      $profile = 'movie_evening';
    }
    $remaining = Capacity::remaining_seats_for_showing($showing_id);
    $permalink = get_permalink($showing_id);

    $hero = '<div class="roxy-st-single" style="max-width:1100px;margin:0 auto 28px">';
    $hero .= '<style>.roxy-st-single-grid{display:grid;grid-template-columns:minmax(220px,320px) 1fr;gap:28px;align-items:start}.roxy-st-single-poster img{width:100%;height:auto;border-radius:16px;display:block;box-shadow:0 10px 26px rgba(0,0,0,.15)}.roxy-st-single-panel{padding:4px 0}.roxy-st-single-kicker{text-transform:uppercase;letter-spacing:.08em;font-size:12px;font-weight:700;opacity:.7;margin:0 0 10px}.roxy-st-single h1{margin:0 0 8px;line-height:1.1}.roxy-st-single-meta{opacity:.84;margin:.35rem 0 1rem;font-weight:600}.roxy-st-single-copy{margin-top:26px}.roxy-st-single-copy h2,.roxy-st-single-copy h3{margin-top:1.25em}.roxy-st-single-media{max-width:1100px;margin:0 auto 26px}.roxy-st-single-media iframe,.roxy-st-single-media video,.roxy-st-single-media embed{width:100%;min-height:420px;border:0;border-radius:16px;display:block}.roxy-st-single-hero-tickets{margin-top:14px;padding:20px;border:1px solid rgba(127,127,127,.2);border-radius:18px;background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.015));box-shadow:0 14px 32px rgba(0,0,0,.18)}.roxy-st-single-hero-tickets h2{margin:0 0 14px;font-size:1.2rem}.roxy-st-single-hero-tickets .roxy-st-form{gap:12px}.roxy-st-single-hero-tickets .roxy-st-ticket-list{gap:12px}.roxy-st-single-hero-tickets .roxy-st-ticket-row{grid-template-columns:minmax(220px,1fr) 92px;justify-content:stretch;gap:14px;margin:0}.roxy-st-single-hero-tickets .roxy-st-ticket-title{font-weight:700}.roxy-st-single-hero-tickets .roxy-st-ticket-note{font-size:13px;opacity:.9}.roxy-st-single-hero-tickets .roxy-st-ticket-input{width:92px;border-radius:12px;border:1px solid rgba(255,255,255,.14);padding:10px 12px}.roxy-st-single-hero-tickets button[type=submit]{width:100%;padding:12px 16px;border-radius:12px;font-size:16px;box-shadow:0 8px 20px rgba(0,0,0,.18)}.roxy-st-single-low{display:inline-flex;align-items:center;gap:8px;margin:0 0 14px;padding:8px 12px;border-radius:999px;background:rgba(255,193,7,.12);border:1px solid rgba(255,193,7,.35);color:#ffd666;font-weight:800;letter-spacing:.01em}.roxy-st-single-low:before{content:"\26A0";line-height:1}.roxy-st-single-divider{max-width:1100px;margin:0 auto 26px;border-top:1px solid rgba(127,127,127,.2)}@media (max-width: 767px){.roxy-st-single-grid{grid-template-columns:1fr}.roxy-st-single-poster{max-width:320px}.roxy-st-single-media iframe,.roxy-st-single-media video,.roxy-st-single-media embed{min-height:240px}.roxy-st-single-hero-tickets .roxy-st-ticket-row{grid-template-columns:minmax(0,1fr) 84px}}</style>';
    $hero .= '<div class="roxy-st-single-grid">';
    $hero .= '<div class="roxy-st-single-poster">' . (has_post_thumbnail($showing_id) ? get_the_post_thumbnail($showing_id, 'large', ['style' => 'width:100%;height:auto;border-radius:16px']) : '') . '</div>';
    $hero .= '<div class="roxy-st-single-panel">';
    $hero .= '<div class="roxy-st-single-kicker">Now at the Newport Roxy</div>';
    $hero .= '<h1>' . esc_html($title) . '</h1>';
    if ($start !== '') {
      $hero .= '<div class="roxy-st-single-meta">' . esc_html(date_i18n('l, F j · g:ia', strtotime($start))) . '</div>';
    }
    if (is_int($remaining) && $remaining > 0 && $remaining <= 50) {
      $hero .= '<div class="roxy-st-single-low">Only ' . esc_html($remaining) . ' seats remaining</div>';
    }
    $hero .= '<div id="roxy-showing-tickets" class="roxy-st-single-hero-tickets">';
    $hero .= '<h2>' . ($profile === 'free_event' ? 'Admission' : 'Tickets') . '</h2>';
    $hero .= self::render_ticket_form($showing_id, $profile, $remaining);
    $hero .= '</div>';
    $hero .= '</div></div>';
    $hero .= '</div>';

    $media = '';
    if ($trailer_url !== '') {
      $embed = wp_oembed_get($trailer_url, ['width' => 1100]);
      if (!$embed) {
        $embed = '<p><a href="' . esc_url($trailer_url) . '" target="_blank" rel="noopener">Watch trailer / media</a></p>';
      }
      $media = '<div id="roxy-showing-trailer" class="roxy-st-single-media"><h2 style="margin:0 0 14px">Trailer & Media</h2>' . $embed . '</div>';
    }

    $divider = '<div class="roxy-st-single-divider"></div>';
    $copy = '<div class="roxy-st-single-copy" style="max-width:1100px;margin:0 auto"><h2 style="margin-top:0">About This Event</h2>' . (string) $content . '</div>';

    return $hero . $divider . $media . $copy;
  }

  public static function handle_add(): void {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'roxy_st_add')) {
      wp_die('Invalid request (nonce).');
    }

    if (!class_exists('WooCommerce')) {
      wp_die('WooCommerce is required.');
    }

    $showing_id = isset($_POST['showing_id']) ? (int) $_POST['showing_id'] : 0;
    if (!$showing_id || get_post_type($showing_id) !== CPT::POST_TYPE) {
      wp_die('Invalid showing.');
    }

    if (!WC()->cart) {
      wc_load_cart();
    }

    Log::info('handle_add start', [
      'showing_id' => $showing_id,
      'user_id' => is_user_logged_in() ? get_current_user_id() : 0,
      'cart_count' => WC()->cart ? WC()->cart->get_cart_contents_count() : 0,
    ]);

    Products::ensure_products_for_showing($showing_id);

    $profile = get_post_meta($showing_id, '_roxy_pricing_profile', true) ?: 'movie_evening';

    $map = [];
    if ($profile === 'movie_evening') {
      $map['general_qty'] = (int) get_post_meta($showing_id, '_roxy_pid_adult', true);
      $map['discount_qty'] = (int) get_post_meta($showing_id, '_roxy_pid_discount', true);
    } elseif ($profile === 'movie_matinee') {
      $map['matinee_qty'] = (int) get_post_meta($showing_id, '_roxy_pid_matinee', true);
    } elseif ($profile === 'live_event') {
      $map['live1_qty'] = (int) get_post_meta($showing_id, '_roxy_pid_live1', true);
      $map['live2_qty'] = (int) get_post_meta($showing_id, '_roxy_pid_live2', true);
    }

    $added_any = false;
    $requested_any = false;

    foreach ($map as $field => $pid) {
      $qty = isset($_POST[$field]) ? max(0, (int) $_POST[$field]) : 0;
      if ($qty <= 0 || !$pid) continue;
      $requested_any = true;

      $p = wc_get_product($pid);
      Log::info('add_to_cart attempt', [
        'showing_id' => $showing_id,
        'field' => $field,
        'pid' => (int) $pid,
        'qty' => (int) $qty,
        'product_type' => $p ? $p->get_type() : '(none)',
        'sold_individually' => $p ? ($p->is_sold_individually() ? 'yes' : 'no') : '(none)',
        'purchasable' => $p ? ($p->is_purchasable() ? 'yes' : 'no') : '(none)',
        'in_stock' => $p ? ($p->is_in_stock() ? 'yes' : 'no') : '(none)',
        'price' => $p ? $p->get_price() : '(none)',
        'meta_showing' => (int) get_post_meta($pid, ROXY_ST_META_SHOWING_ID, true),
        'meta_type' => (string) get_post_meta($pid, ROXY_ST_META_TICKET_TYPE, true),
      ]);

      $pre_passed = apply_filters('woocommerce_add_to_cart_validation', true, $pid, $qty, 0, []);
      Log::info('diag preflight validation', [
        'pid' => (int) $pid,
        'passed' => $pre_passed ? 'yes' : 'no',
        'notices' => function_exists('wc_get_notices') ? wc_get_notices() : [],
      ]);

      if (!$pre_passed) {
        continue;
      }

      $ok = WC()->cart->add_to_cart($pid, $qty);
      if ($ok) {
        $added_any = true;
      } else {
        Log::warn('add_to_cart failed', [
          'pid' => (int) $pid,
          'qty' => (int) $qty,
          'notices' => function_exists('wc_get_notices') ? wc_get_notices() : [],
        ]);
      }
    }

    $sub_qty = isset($_POST['subscriber_qty']) ? max(0, (int) $_POST['subscriber_qty']) : 0;
    if ($sub_qty > 0) {
      $requested_any = true;
      $sub_pid = (int) get_post_meta($showing_id, '_roxy_pid_subscriber', true);
      if ($sub_pid) {
        $pre_passed = apply_filters('woocommerce_add_to_cart_validation', true, $sub_pid, $sub_qty, 0, []);
        if (!$pre_passed) {
          $ok = false;
        } else {
          $ok = WC()->cart->add_to_cart($sub_pid, $sub_qty);
        }
        if ($ok) {
          $added_any = true;
        } else {
          Log::warn('subscriber add_to_cart failed', [
            'showing_id' => $showing_id,
            'pid' => (int) $sub_pid,
            'qty' => (int) $sub_qty,
            'notices' => function_exists('wc_get_notices') ? wc_get_notices() : [],
          ]);
        }
      }
    }

    if (!$added_any && !$requested_any) {
      wc_add_notice('Please choose at least one ticket.', 'error');
    }

    wp_safe_redirect(wc_get_cart_url());
    exit;
  }
}
