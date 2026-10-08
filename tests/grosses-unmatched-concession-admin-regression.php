<?php
namespace {
  define('ABSPATH', __DIR__ . '/');
  $GLOBALS['hooks'] = [];
  $GLOBALS['can_manage'] = true;
  $GLOBALS['valid_nonce'] = true;
  $GLOBALS['resolved_id'] = 0;
  $GLOBALS['redirect_url'] = '';
  $_POST['item_id'] = '17';
  function add_action($hook, $callback, $priority = 10) { $GLOBALS['hooks'][$hook] = $callback; }
  function current_user_can($capability) { return $GLOBALS['can_manage'] && $capability === 'manage_options'; }
  function wp_die($message) { throw new RuntimeException($message); }
  function absint($value) { return abs((int) $value); }
  function wp_unslash($value) { return $value; }
  function check_admin_referer($action) {
    if (!$GLOBALS['valid_nonce'] || $action !== 'roxy_grosses_resolve_unmatched_concession_17') throw new RuntimeException('invalid nonce');
  }
  function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
  function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
  function wp_safe_redirect($url) { $GLOBALS['redirect_url'] = $url; }
}
namespace RoxyGrosses {
  final class Store {
    public static function resolve_unmatched_concession_line(int $id): bool { $GLOBALS['resolved_id'] = $id; return true; }
  }
}
namespace {
  require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-settings.php';
  \RoxyGrosses\Settings::init();
  if (!isset($GLOBALS['hooks']['admin_post_roxy_grosses_resolve_unmatched_concession'])) throw new RuntimeException('manager action hook was not registered');
  $GLOBALS['can_manage'] = false;
  try { \RoxyGrosses\Settings::handle_resolve_unmatched_concession(); throw new RuntimeException('unauthorized request was accepted'); }
  catch (RuntimeException $error) { if ($error->getMessage() === 'unauthorized request was accepted') throw $error; }
  if ($GLOBALS['resolved_id'] !== 0) throw new RuntimeException('unauthorized request reached storage');
  $GLOBALS['can_manage'] = true;
  $GLOBALS['valid_nonce'] = false;
  try { \RoxyGrosses\Settings::handle_resolve_unmatched_concession(); throw new RuntimeException('invalid nonce was accepted'); }
  catch (RuntimeException $error) { if ($error->getMessage() === 'invalid nonce was accepted') throw $error; }
  if ($GLOBALS['resolved_id'] !== 0) throw new RuntimeException('invalid nonce reached storage');
  $GLOBALS['valid_nonce'] = true;
  register_shutdown_function(static function (): void {
    $expected = 'https://example.test/wp-admin/admin.php?page=roxy-grosses&tab=logs&unmatched_notice=resolved#roxy-unmatched-concessions';
    if ($GLOBALS['resolved_id'] !== 17 || $GLOBALS['redirect_url'] !== $expected) {
      fwrite(STDERR, "FAIL: authorized action did not resolve the item and return to Grosses Logs\n");
      exit(1);
    }
    echo "PASS: admin action is registered, capability/nonce protected, resolves one item and redirects to Logs\n";
  });
  \RoxyGrosses\Settings::handle_resolve_unmatched_concession();
}
