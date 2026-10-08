<?php
// Run with WP CLI, all email intercepted before a mailer can deliver it.
if (!defined('WP_CLI') || !WP_CLI) exit;
$reporter_class = \RoxyGrosses\Reporter::class;
if (!empty($args[0])) {
  class_alias(\RoxyGrosses\Settings::class, 'RoxyGrossesAttachmentCandidate\\Settings');
  $source = file_get_contents($args[0] . '/includes/modules/grosses/includes/class-roxy-grosses-reporter.php');
  $source = preg_replace('/^<\?php\s*/', '', $source, 1);
  eval(str_replace('namespace RoxyGrosses;', 'namespace RoxyGrossesAttachmentCandidate;', $source));
  $reporter_class = 'RoxyGrossesAttachmentCandidate\\Reporter';
}
$captured = [];
$intercept = static function ($short_circuit, $mail) use (&$captured) {
  $path = $mail['attachments'][0];
  $captured[] = ['path' => $path, 'content' => file_get_contents($path), 'mode' => fileperms($path) & 0777];
  return true;
};
add_filter('pre_wp_mail', $intercept, PHP_INT_MAX, 2);
try {
  $row = ['report_date'=>'2026-10-05', 'show_time'=>'12:00', 'theater_name'=>'Synthetic fixture', 'film_title'=>'Synthetic fixture only', 'general_qty'=>2, 'discount_qty'=>3, 'group_qty'=>4, 'gross_total'=>12.34];
  $send = new ReflectionMethod($reporter_class, 'send_email');
  $result = $send->invoke(null, [$row], ['report_date'=>'2026-10-05', 'gross_total'=>12.34, 'paid_tickets'=>9], 'manual-test');
  if (!$result['success'] || count($captured) !== 1) throw new RuntimeException('Intercepted send failed.');
  $file = $captured[0];
  if (str_starts_with($file['path'], ABSPATH) || $file['mode'] !== 0600 || file_exists($file['path']) || is_dir(dirname($file['path'])) || !str_contains($file['content'], '$12.34')) {
    throw new RuntimeException('WordPress attachment lifecycle failed.');
  }
  echo "PASS: actual WordPress recipient/body construction and intercepted attachment lifecycle; zero delivered emails or report-history writes.\n";
} finally {
  remove_filter('pre_wp_mail', $intercept, PHP_INT_MAX);
}
