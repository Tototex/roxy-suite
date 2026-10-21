<?php
// Private actual Woo/MySQL race reproduction; $0-only, no gateway or outbound email.
if (!defined('WP_CLI') || !WP_CLI) exit;
if (!function_exists('proc_open') || !class_exists('RoxyST\\Tickets')) throw new RuntimeException('Private ticket lifecycle dependencies are unavailable');
add_filter('pre_wp_mail', static fn() => true);
foreach (['new_order', 'customer_processing_order', 'customer_completed_order', 'customer_refunded_order'] as $email_id) {
  add_filter('woocommerce_email_enabled_' . $email_id, '__return_false', PHP_INT_MAX);
}
global $wpdb;
$label = 'PRIVATE REFUND ADMISSION RACE ' . bin2hex(random_bytes(6));
$wp_cli = (string) (getenv('ROXY_WP_CLI') ?: '/usr/local/bin/wp');
$showing_id = $product_id = $order_id = 0;
$ticket_ids = [];
$refund = null;
$worker = null;
$worker_pipes = [];
$ready_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $label . '.ready';
$checks = 0;
$check = static function ($ok, string $message) use (&$checks): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $message);
  echo 'PASS: ' . $message . PHP_EOL;
  $checks++;
};
$collect_worker = static function () use (&$worker, &$worker_pipes): array {
  if (!is_resource($worker)) throw new RuntimeException('Race worker was not started');
  $stdout = '';
  $stderr = '';
  $exit = null;
  $deadline = microtime(true) + 15;
  do {
    $stdout .= stream_get_contents($worker_pipes[1]);
    $stderr .= stream_get_contents($worker_pipes[2]);
    $status = proc_get_status($worker);
    if (!$status['running']) { $exit = (int) $status['exitcode']; break; }
    if (microtime(true) >= $deadline) throw new RuntimeException('Refund/admission worker timed out');
    usleep(20000);
  } while (true);
  fclose($worker_pipes[1]);
  fclose($worker_pipes[2]);
  $worker_pipes = [];
  $close_exit = proc_close($worker);
  if (($exit === null || $exit < 0) && $close_exit >= 0) $exit = $close_exit;
  $worker = null;
  if (!preg_match('/REFUND_RACE_ADMISSION=([01])/', $stdout, $match)) throw new RuntimeException('Refund/admission worker result missing: ' . $stderr);
  return ['admitted' => (int) $match[1], 'exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
};
$spawn_worker = static function () use (&$worker, &$worker_pipes, $wp_cli, &$order_id, &$ticket_ids, $label, $ready_path): void {
  if (is_resource($worker)) throw new RuntimeException('Previous race worker is still active');
  @unlink($ready_path);
  $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $worker = proc_open([
    PHP_BINARY, $wp_cli, '--path=' . ABSPATH, 'eval-file', __DIR__ . '/ticket-refund-admission-worker.php',
    (string) $order_id, (string) $ticket_ids[1], $label, $ready_path,
  ], $descriptors, $worker_pipes, ABSPATH);
  if (!is_resource($worker)) throw new RuntimeException('Could not start the independent admission worker');
  fclose($worker_pipes[0]);
  stream_set_blocking($worker_pipes[1], false);
  stream_set_blocking($worker_pipes[2], false);
  $deadline = microtime(true) + 5;
  while (!is_file($ready_path) && microtime(true) < $deadline) usleep(20000);
  if (!is_file($ready_path)) throw new RuntimeException('Admission worker did not reach the lock boundary');
};

try {
  $showing_id = wp_insert_post(['post_type' => 'roxy_showing', 'post_status' => 'publish', 'post_title' => $label], true);
  if (is_wp_error($showing_id) || (int) $showing_id <= 0) throw new RuntimeException('Could not create isolated showing');
  $product = new WC_Product_Simple();
  $product->set_name($label);
  $product->set_status('publish');
  $product->set_catalog_visibility('hidden');
  $product->set_regular_price('0');
  $product_id = (int) $product->save();
  if ($product_id <= 0) throw new RuntimeException('Could not create isolated zero-dollar ticket product');
  update_post_meta($product_id, ROXY_ST_META_SHOWING_ID, $showing_id);
  update_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, 'adult');
  update_post_meta($showing_id, '_roxy_pid_adult', $product_id);
  update_post_meta($showing_id, '_roxy_start', wp_date('Y-m-d\\TH:i', time() + 7200));
  update_post_meta($showing_id, '_roxy_duration_minutes', '60');

  $order = wc_create_order(['status' => 'processing', 'customer_note' => $label]);
  if (is_wp_error($order)) throw new RuntimeException('Could not create isolated zero-dollar order');
  $order_id = (int) $order->get_id();
  $item_id = (int) $order->add_product($product, 2);
  $order->calculate_totals();
  $order->save();
  if ($item_id <= 0 || (float) $order->get_total() !== 0.0 || !\RoxyST\Tickets::sync_order_tickets($order_id)) throw new RuntimeException('Could not prepare isolated ticket order');
  $ticket_ids = \RoxyST\Tickets::get_order_ticket_ids($order_id);
  if (count($ticket_ids) !== 2) throw new RuntimeException('Isolated order did not produce two tickets');

  $refund_args = [
    'order_id' => $order_id, 'amount' => 0, 'reason' => $label,
    'refund_payment' => false, 'restock_items' => false,
    'line_items' => [$item_id => ['qty' => 1, 'refund_total' => 0, 'refund_tax' => []]],
  ];
  $baseline_worker = null;
  $reproduce = static function ($refund_object, array $args) use (&$baseline_worker, $spawn_worker, $collect_worker): void {
    $spawn_worker();
    $baseline_worker = $collect_worker();
  };
  // Reproduce the unsafe interval: without the new pre-save lease, a real staff
  // admission can commit after Woo announces refund creation but before it saves.
  remove_action('woocommerce_create_refund', [\RoxyST\Tickets::class, 'on_refund_creating'], 1);
  add_action('woocommerce_create_refund', $reproduce, 2, 2);
  try { $refund = wc_create_refund($refund_args); }
  finally { remove_action('woocommerce_create_refund', $reproduce, 2); }
  if (is_wp_error($refund)) throw new RuntimeException('Baseline zero-dollar refund failed: ' . $refund->get_error_message());
  $baseline_admitted = ($baseline_worker['exit'] ?? -1) === 0 && ($baseline_worker['admitted'] ?? 0) === 1;
  $baseline_pre_save_rejected = ($baseline_worker['exit'] ?? -1) === 0
    && ($baseline_worker['admitted'] ?? 1) === 0
    && is_array($baseline_worker)
    && preg_match('/"refund_qty":-\d+(?:\.\d+)?/', (string) ($baseline_worker['stdout'] ?? ''))
    && preg_match('/"refund_ids":\[0\]/', (string) ($baseline_worker['stdout'] ?? ''));
  $check($baseline_admitted || $baseline_pre_save_rejected,
    'baseline pre-save check is classified as either admitted or rejected by WooCommerce pre-save refund state; worker=' . wp_json_encode($baseline_worker));
  if (!\RoxyST\Tickets::sync_order_tickets($order_id)) throw new RuntimeException('Could not reconcile baseline refund fixture');
  if ($baseline_admitted) {
    $check((int) get_post_meta($ticket_ids[1], '_roxy_checked_in', true) === 1
      && (int) get_post_meta($ticket_ids[1], '_roxy_ticket_refunded', true) === 1,
      'unlocked baseline admission is retained as refunded, proving the unsafe interleaving');
  } else {
    $check((int) get_post_meta($ticket_ids[1], '_roxy_checked_in', true) !== 1
      && (int) get_post_meta($ticket_ids[1], '_roxy_ticket_refunded', true) === 1,
      'WooCommerce pre-save refund state prevents admission and reconciliation marks the ticket refunded');
  }

  $refund->delete(true);
  $refund = null;
  if (!\RoxyST\Tickets::sync_order_tickets($order_id)) throw new RuntimeException('Could not reconcile deleted baseline refund');
  if ($baseline_admitted && !\RoxyST\Tickets::undo_check_in_ticket($ticket_ids[1], 'ticket')) throw new RuntimeException('Could not reset isolated baseline admission');

  // Repeat with production hooks enabled. The independent worker must wait for
  // refund persistence and ticket reconciliation, then reject the refunded QR.
  add_action('woocommerce_create_refund', [\RoxyST\Tickets::class, 'on_refund_creating'], 1, 2);
  $probe = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
  if (!$probe->ready) throw new RuntimeException('Independent lock probe connection unavailable');
  $lock = 'roxy_ticket_' . substr(hash('sha256', $wpdb->prefix . ':' . $order_id), 0, 48);
  $blocked_probe = false;
  $verify_guarded_lock = static function () use ($spawn_worker, $probe, $lock, &$blocked_probe): void {
    $spawn_worker();
    $blocked_probe = (string) $probe->get_var($probe->prepare('SELECT GET_LOCK(%s,0)', $lock)) === '0';
    if (!$blocked_probe) $probe->get_var($probe->prepare('SELECT RELEASE_LOCK(%s)', $lock));
  };
  add_action('woocommerce_create_refund', $verify_guarded_lock, 2, 2);
  try { $refund = wc_create_refund($refund_args); }
  finally { remove_action('woocommerce_create_refund', $verify_guarded_lock, 2); }
  if (is_wp_error($refund)) throw new RuntimeException('Serialized zero-dollar refund failed: ' . $refund->get_error_message());
  $worker_result = $collect_worker();
  $check($blocked_probe, 'independent MySQL connection cannot enter ticket operation while Woo is saving/reconciling refund');
  $check($worker_result['exit'] === 0 && $worker_result['admitted'] === 0,
    'check-in waits for refund reconciliation and rejects the refunded ticket');
  $check((int) get_post_meta($ticket_ids[1], '_roxy_checked_in', true) !== 1
    && (int) get_post_meta($ticket_ids[1], '_roxy_ticket_refunded', true) === 1,
    'serialized final ticket state is refunded and not admitted');
  $released_probe = (string) $probe->get_var($probe->prepare('SELECT GET_LOCK(%s,0)', $lock)) === '1';
  if ($released_probe) $probe->get_var($probe->prepare('SELECT RELEASE_LOCK(%s)', $lock));
  $check($released_probe, 'refund completion releases the connection-owned order lease');
  $probe->close();
  echo 'TICKET_REFUND_ADMISSION_RACE_OK checks=' . $checks . PHP_EOL;
} finally {
  @unlink($ready_path);
  if (is_resource($worker)) {
    proc_terminate($worker);
    foreach ($worker_pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($worker);
  }
  if ($refund instanceof WC_Order_Refund) $refund->delete(true);
  if ($order_id > 0) {
    \RoxyST\Tickets::sync_order_tickets($order_id);
    foreach (\RoxyST\Tickets::get_order_ticket_ids($order_id) as $ticket_id) wp_delete_post((int) $ticket_id, true);
    $order = wc_get_order($order_id);
    if ($order && (string) $order->get_customer_note() === $label) $order->delete(true);
  }
  if ($product_id > 0) {
    $product = wc_get_product($product_id);
    if ($product && $product->get_name() === $label) $product->delete(true);
  }
  if ($showing_id > 0) {
    $showing = get_post($showing_id);
    if ($showing && $showing->post_title === $label) wp_delete_post($showing_id, true);
  }
}
