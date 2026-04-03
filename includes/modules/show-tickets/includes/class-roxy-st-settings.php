<?php
namespace RoxyST;

if (!defined('ABSPATH')) exit;

class Settings {
  const OPTION_KEY = 'roxy_st_settings';

  public static function init(): void {
    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);
  }

  public static function defaults(): array {
    return [
      'general_price' => '12',
      'discount_price' => '8',
      'matinee_price' => '8',
      'default_capacity' => '250',
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

  public static function admin_menu(): void {
    add_submenu_page(
      'roxy-suite',
      'Show Tickets',
      'Show Tickets',
      'manage_options',
      'roxy-st-settings',
      [__CLASS__, 'render_page']
    );
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

    return [
      'general_price' => wc_format_decimal($input['general_price'] ?? $defaults['general_price']),
      'discount_price' => wc_format_decimal($input['discount_price'] ?? $defaults['discount_price']),
      'matinee_price' => wc_format_decimal($input['matinee_price'] ?? $defaults['matinee_price']),
      'default_capacity' => (string) max(0, (int) ($input['default_capacity'] ?? $defaults['default_capacity'])),
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

  public static function render_page(): void {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap">';
    echo '<h1>Roxy Showings Settings</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields(self::OPTION_KEY);
    do_settings_sections('roxy-st-settings');
    submit_button();
    echo '</form>';
    echo '</div>';
  }
}
