<?php
declare(strict_types=1);

namespace RoxyGrosses {
  final class CashflowReport {
    public static array $dates = [];
    public static function for_day(string $date): array {
      self::$dates[] = $date;
      return [
        'report_date' => $date,
        'totals' => [
          'square_collected_cents' => 1000, 'square_refunded_cents' => 100,
          'woocommerce_collected_cents' => 2000, 'woocommerce_refunded_cents' => 200,
          'total_collected_cents' => 3000, 'total_refunded_cents' => 300,
          'net_cents' => 2700,
        ],
        'counts' => ['square_collections' => 1, 'square_refunds' => 1, 'woocommerce_collections' => 1, 'woocommerce_refunds' => 1],
        'refund_date_bases' => ['square' => ['square_refund_completed_event'], 'woocommerce' => ['woocommerce_refund_creation_proxy']],
      ];
    }
  }
}

namespace {
  define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  $GLOBALS['nonce_actions'] = [];
  function get_option(string $key, $default = null) { return $key === 'admin_email' ? 'test@example.invalid' : $default; }
  function wp_parse_args(array $args, array $defaults): array { return array_merge($defaults, $args); }
  function wp_timezone_string(): string { return 'America/Los_Angeles'; }
  function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
  function wp_unslash(string $value): string { return $value; }
  function admin_url(string $path = ''): string { return 'https://example.invalid/wp-admin/' . ltrim($path, '/'); }
  function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
  function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
  function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
  function wp_nonce_field(string $action): void {
    $GLOBALS['nonce_actions'][] = $action;
    echo '<input type="hidden" name="_wpnonce" value="test-nonce">';
  }
  function wp_verify_nonce(string $nonce, string $action): bool {
    $GLOBALS['verified_nonce_actions'][] = [$nonce, $action];
    return $nonce === 'test-nonce' && $action === 'roxy_grosses_cashflow';
  }
  function submit_button(string $label, string $type = 'primary', string $name = 'submit', bool $wrap = true): void {
    echo '<button name="' . esc_attr($name) . '">' . esc_html($label) . '</button>';
  }

  require_once __DIR__ . '/../includes/modules/grosses/includes/class-roxy-grosses-settings.php';

  $checks = 0;
  $failures = [];
  $assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    ++$checks;
    if (!$condition) $failures[] = $message;
  };
  $render = new \ReflectionMethod(\RoxyGrosses\Settings::class, 'render_cashflow_tab');
  $render->setAccessible(true);

  $_GET = ['cashflow_date' => '2026-10-03'];
  ob_start();
  $render->invoke(null, '2026-10-08');
  $form = (string) ob_get_clean();
  $assert(in_array('roxy_grosses_cashflow', $GLOBALS['nonce_actions'], true), 'cashflow form nonce is date-independent so changing the date does not stale the nonce');
  $assert(str_contains($form, 'value="2026-10-03"'), 'cashflow form renders the selected report date');

  $_GET = ['cashflow_load' => '1', 'cashflow_date' => '2026-10-03', '_wpnonce' => 'test-nonce'];
  ob_start();
  $render->invoke(null, '2026-10-08');
  $report = (string) ob_get_clean();
  $assert($GLOBALS['verified_nonce_actions'][0] === ['test-nonce', 'roxy_grosses_cashflow'], 'cashflow form verifies the same date-independent nonce action');
  $assert(\RoxyGrosses\CashflowReport::$dates === ['2026-10-03'], 'cashflow form loads the user-selected date');
  $assert(str_contains($report, '2026-10-03') && str_contains($report, '$27.00'), 'selected-date cashflow results render after nonce verification');
  $assert(!str_contains($report, 'request expired'), 'changing report date no longer triggers an expired-request error');

  $_GET = ['cashflow_load' => '1', 'cashflow_date' => '2026-10-04', '_wpnonce' => 'bad-nonce'];
  ob_start();
  $render->invoke(null, '2026-10-08');
  $expired = (string) ob_get_clean();
  $assert(str_contains($expired, 'report request expired') && \RoxyGrosses\CashflowReport::$dates === ['2026-10-03'], 'invalid nonce remains rejected before any provider report query');

  if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    fwrite(STDERR, sprintf("%d checks, %d failures\n", $checks, count($failures)));
    exit(1);
  }
  printf("%d checks passed\n", $checks);
}
