<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

class Settings {
  const OPTION_KEY = 'roxy_st_settings';

  public static function init(): void {
    add_action('admin_init', [__CLASS__, 'register_settings']);
  }

  public static function defaults(): array {
    return [
      'general_price' => '12',
      'discount_price' => '8',
      'matinee_price' => '8',
      'default_capacity' => '250',
      'ticket_hold_minutes' => '',
      'discount_note' => 'Under 12, over 65, military.',
      'subscriber_url' => 'https://newportroxy.com/product/friends-of-the-roxy/',
    ];
  }

  public static function get_all(): array {
    $saved = get_option(self::OPTION_KEY, []);
    if (!is_array($saved)) {
      $saved = [];
    }
    return wp_parse_args($saved, self::defaults());
  }

  public static function get(string $key, $default = '') {
    $all = self::get_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
  }

  public static function get_price(string $key, float $fallback): float {
    $raw = self::get($key, (string) $fallback);
    return max(0, (float) $raw);
  }

  public static function get_default_capacity(): int {
    return max(0, (int) self::get('default_capacity', '250'));
  }

  public static function register_settings(): void {
    register_setting(self::OPTION_KEY, self::OPTION_KEY, [
      'type' => 'array',
      'sanitize_callback' => [__CLASS__, 'sanitize'],
      'default' => self::defaults(),
    ]);

    add_settings_section(
      'roxy_st_main',
      'Showings Defaults',
      function () {
        echo '<p>Adjust default pricing and customer-facing text for the Roxy Show Tickets plugin.</p>';
      },
      'roxy-st-settings'
    );

    $fields = [
      'general_price' => 'General ticket price',
      'discount_price' => 'Discount ticket price',
      'matinee_price' => 'Matinee ticket price',
      'default_capacity' => 'Default showing capacity',
      'ticket_hold_minutes' => 'Unpaid ticket seat hold (minutes)',
      'discount_note' => 'Discount helper note',
      'subscriber_url' => 'Subscriber signup URL',
    ];

    foreach ($fields as $key => $label) {
      add_settings_field(
        $key,
        $label,
        [__CLASS__, 'render_field'],
        'roxy-st-settings',
        'roxy_st_main',
        ['key' => $key, 'label' => $label]
      );
    }
  }

  public static function sanitize($input): array {
    $defaults = self::defaults();
    $input = is_array($input) ? $input : [];
    $saved=get_option(self::OPTION_KEY,[]);
    $hold=$input['ticket_hold_minutes']??(is_array($saved)?($saved['ticket_hold_minutes']??''):'');

    return [
      'general_price' => wc_format_decimal($input['general_price'] ?? $defaults['general_price']),
      'discount_price' => wc_format_decimal($input['discount_price'] ?? $defaults['discount_price']),
      'matinee_price' => wc_format_decimal($input['matinee_price'] ?? $defaults['matinee_price']),
      'default_capacity' => (string) max(0, (int) ($input['default_capacity'] ?? $defaults['default_capacity'])),
      'ticket_hold_minutes' => trim((string)$hold)==='' ? '' : (string)max(1,min(525600,(int)$hold)),
      'discount_note' => sanitize_text_field($input['discount_note'] ?? $defaults['discount_note']),
      'subscriber_url' => esc_url_raw($input['subscriber_url'] ?? $defaults['subscriber_url']),
    ];
  }

  public static function render_field(array $args): void {
    $key = $args['key'];
    $value = self::get($key, '');
    $name = self::OPTION_KEY . '[' . $key . ']';

    if (in_array($key, ['general_price', 'discount_price', 'matinee_price'], true)) {
      echo '<input type="number" min="0" step="0.01" class="regular-text" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
      return;
    }

    if ($key === 'ticket_hold_minutes') {
      echo '<input type="number" min="1" max="525600" step="1" name="'.esc_attr($name).'" value="'.esc_attr($value).'">';
      echo '<p class="description">Blank inherits WooCommerce’s unpaid-order hold duration. This override applies only to ticket seats; rentals and other Woo orders keep their existing setting. Expired holds release seats without requiring cron.</p>';
      return;
    }
    if ($key === 'default_capacity') {
      echo '<input type="number" min="0" step="1" class="regular-text" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
      return;
    }

    if ($key === 'discount_note') {
      echo '<input type="text" class="regular-text" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
      return;
    }

    if ($key === 'subscriber_url') {
      echo '<input type="url" class="regular-text code" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
      return;
    }
  }

  public static function render_page(bool $wrap = true): void {
    if (!roxy_suite_user_can_access_admin()) return;
    if ($wrap) echo '<div class="wrap"><h1>Show Tickets — Settings</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields(self::OPTION_KEY);
    do_settings_sections('roxy-st-settings');
    submit_button();
    echo '</form>';
    if ($wrap) echo '</div>';
  }
}
