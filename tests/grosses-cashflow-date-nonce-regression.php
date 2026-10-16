<?php
declare(strict_types=1);

namespace {
  define('ABSPATH', __DIR__ . '/../');
  $GLOBALS['cashflow_nonce_tokens'] = [];
  $GLOBALS['cashflow_nonce_counter'] = 0;
  $GLOBALS['cashflow_nonce_verified_action'] = null;
  $GLOBALS['cashflow_report_date'] = null;
  function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
  function wp_unslash(string $value): string { return $value; }
  function wp_timezone_string(): string { return 'America/Los_Angeles'; }
  function wp_parse_args($args, $defaults = []): array { return array_merge($defaults, (array) $args); }
  function get_option(string $key, $default = null) {
    if ($key === \RoxyGrosses\Settings::OPTION_KEY) return ['report_timezone' => 'America/Los_Angeles'];
    return $default;
  }
  function admin_url(string $path = ''): string { return '/wp-admin/' . ltrim($path, '/'); }
  function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
  function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
  function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
  function wp_nonce_field(string $action): void {
    $token = 'fixture-nonce-' . ++$GLOBALS['cashflow_nonce_counter'];
    $GLOBALS['cashflow_nonce_tokens'][$token] = $action;
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($token) . '">';
  }
  function wp_verify_nonce(string $nonce, string $action): bool {
    $GLOBALS['cashflow_nonce_verified_action'] = $action;
    return isset($GLOBALS['cashflow_nonce_tokens'][$nonce])
      && hash_equals($GLOBALS['cashflow_nonce_tokens'][$nonce], $action);
  }
  function submit_button(string $text, string $type = 'primary', string $name = 'submit', bool $wrap = true): void {
    echo '<button name="' . esc_attr($name) . '">' . esc_html($text) . '</button>';
  }

  require_once __DIR__ . '/../includes/modules/grosses/includes/class-roxy-grosses-settings.php';

  $render = new \ReflectionMethod(\RoxyGrosses\Settings::class, 'render_cashflow_tab');
  $render->setAccessible(true);
  $_GET = [];
  ob_start();
  $render->invoke(null, '2026-10-16');
  $initial_form = (string) ob_get_clean();
  if (!preg_match('/name="_wpnonce" value="([^"]+)"/', $initial_form, $matches)) {
    throw new RuntimeException('Cashflow form did not render its nonce.');
  }
  $initial_nonce = $matches[1];

  // Simulate selecting a different date after the page generated its nonce.
  $_GET = [
    'cashflow_date' => '2026-10-15',
    'cashflow_load' => '1',
    '_wpnonce' => $initial_nonce,
  ];
  ob_start();
  $render->invoke(null, '2026-10-16');
  $response = (string) ob_get_clean();

  if (str_contains($response, 'The report request expired.')
    || $GLOBALS['cashflow_nonce_verified_action'] !== 'roxy_grosses_cashflow'
    || $GLOBALS['cashflow_report_date'] !== '2026-10-15') {
    throw new RuntimeException('A valid Cashflow form must accept its selected date and reach the read-only report calculation.');
  }

  $GLOBALS['cashflow_report_date'] = null;
  $_GET['_wpnonce'] = 'not-a-valid-cashflow-nonce';
  ob_start();
  $render->invoke(null, '2026-10-16');
  $invalid_response = (string) ob_get_clean();
  if (!str_contains($invalid_response, 'The report request expired.') || $GLOBALS['cashflow_report_date'] !== null) {
    throw new RuntimeException('Cashflow must reject an invalid nonce without starting a provider read.');
  }

  \RoxyGrosses\CashflowReport::$response = [
    'report_date' => '2026-10-15',
    'totals' => ['square_collected_cents'=>1000,'woocommerce_collected_cents'=>0,'square_refunded_cents'=>500,'woocommerce_refunded_cents'=>0,'total_collected_cents'=>1000,'total_refunded_cents'=>500,'net_cents'=>500],
    'counts' => ['square_collections'=>1,'woocommerce_collections'=>0,'square_refunds'=>1,'woocommerce_refunds'=>0],
    'refund_date_basis_counts' => ['square'=>['square_updated_at_proxy'=>1],'woocommerce'=>[]],
    'review_exceptions' => ['square_refunds_using_update_time_proxy'=>[[
      'refund_id'=>'<refund-1>','payment_id'=>'payment-1','order_id'=>'order-1','refund_date'=>'2026-10-15','amount_cents'=>500,
      'review'=>'Proxy date; verify provider record.',
    ]]],
  ];
  $_GET = ['cashflow_date'=>'2026-10-15','cashflow_load'=>'1','_wpnonce'=>$initial_nonce];
  ob_start();
  $render->invoke(null, '2026-10-16');
  $proxy_response = (string) ob_get_clean();
  if (!str_contains($proxy_response, 'Included Square refunds using a proxy date')
    || !str_contains($proxy_response, '&lt;refund-1&gt;')
    || str_contains($proxy_response, '<refund-1>')
    || !str_contains($proxy_response, '$5.00')
    || !str_contains($proxy_response, 'Proxy date; verify provider record.')) {
    throw new RuntimeException('Cashflow must itemize and escape proxy-dated Square refunds without changing included totals.');
  }
  \RoxyGrosses\CashflowReport::$response['review_exceptions']['square_refunds_using_update_time_proxy'] = [];
  ob_start();
  $render->invoke(null, '2026-10-16');
  $empty_proxy_response = (string) ob_get_clean();
  if (!str_contains($empty_proxy_response, 'No Square refunds on this date rely on the update-time proxy.')) {
    throw new RuntimeException('Cashflow must explain when no Square proxy-dated refunds need review.');
  }

  echo "OK: Cashflow accepts a selected date and rejects an invalid nonce before provider reads\n";
}

namespace RoxyGrosses {
  final class CashflowReport {
    public static ?array $response = null;
    public static function for_day(string $date): array {
      $GLOBALS['cashflow_report_date'] = $date;
      if (self::$response !== null) return self::$response;
      throw new \RuntimeException('Expected fixture stop after nonce acceptance.');
    }
  }
}
