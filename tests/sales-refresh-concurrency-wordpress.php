<?php
/** Disposable two-process WordPress/Woo regression for showing-sales refresh ordering. */
if (!defined('WP_CLI') || !WP_CLI) exit;
if (!function_exists('proc_open')) throw new RuntimeException('Process control is required for the concurrency fixture.');
if (!class_exists('RoxyST\\Sales')) throw new RuntimeException('The installed candidate Sales class is unavailable.');

// Keep even the synthetic processing-order transition entirely mail-free.
foreach (['new_order', 'customer_processing_order', 'customer_completed_order'] as $email_id) {
  add_filter('woocommerce_email_enabled_' . $email_id, '__return_false', PHP_INT_MAX);
}

global $wpdb;
$token = 'PRIVATE-SALES-REFRESH-' . bin2hex(random_bytes(6));
$showing_id = 0;
$product_id = 0;
$order_id = 0;
$peer = null;
$worker = null;
$worker_pipes = [];
$ready_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $token . '.ready';
$hook = null;
$check = static function ($ok, string $label): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $label);
  echo "PASS: {$label}\n";
};

try {
  $product = new WC_Product_Simple();
  $product->set_name($token . ' zero-value ticket product');
  $product->set_status('draft');
  $product->set_catalog_visibility('hidden');
  $product->set_regular_price('0');
  $product_id = (int)$product->save();
  if ($product_id <= 0) throw new RuntimeException('Could not create the private product fixture.');

  $showing_result = wp_insert_post([
    'post_type'=>'roxy_showing', 'post_status'=>'private', 'post_title'=>$token . ' private showing',
  ], true);
  if (is_wp_error($showing_result) || (int)$showing_result <= 0) throw new RuntimeException('Could not create the private showing fixture.');
  $showing_id = (int)$showing_result;
  update_post_meta($showing_id, '_roxy_pid_adult', $product_id);
  update_post_meta($showing_id, '_roxy_legacy_sales_scan_complete', '1');
  update_post_meta($product_id, ROXY_ST_META_SHOWING_ID, (string)$showing_id);
  update_post_meta($product_id, ROXY_ST_META_TICKET_TYPE, 'adult');

  $order = wc_create_order(['status'=>'pending','customer_note'=>$token . ' private sales refresh fixture']);
  if (is_wp_error($order)) throw new RuntimeException('Could not create the zero-value order fixture.');
  $order_id = (int)$order->get_id();
  $item_id = (int)$order->add_product($product, 1);
  if ($item_id <= 0) throw new RuntimeException('Could not add the fixture ticket line.');
  $order->update_meta_data('_roxy_contains_showing_' . $showing_id, '1');
  $order->calculate_totals();
  $order->set_status('processing');
  $order->save();
  // Other active checkout hooks correctly hold synthetic zero-dollar tickets;
  // set only this isolated fixture's persisted status without re-running them.
  $status_updated = $wpdb->update($wpdb->posts, ['post_status'=>'wc-processing'], ['ID'=>$order_id], ['%s'], ['%d']);
  if ($status_updated !== 1) throw new RuntimeException('Could not set the isolated fixture order to processing status.');
  clean_post_cache($order_id);
  $marker = '_roxy_contains_showing_' . $showing_id;
  $saved_order = wc_get_order($order_id);
  if (!$saved_order || $saved_order->get_status() !== 'processing' || (string)$saved_order->get_meta($marker, true) !== '1') {
    throw new RuntimeException('The fixture showing-discovery marker did not persist on its Woo order.');
  }
  $found_order_ids = wc_get_orders([
    'limit'=>-1, 'return'=>'ids', 'status'=>['processing','completed','on-hold'],
    'type'=>'shop_order', 'meta_query'=>[['key'=>$marker,'value'=>'1']],
  ]);
  if (!is_array($found_order_ids) || !in_array($order_id, array_map('intval', $found_order_ids), true)) {
    throw new RuntimeException('Woo order lookup did not find the synthetic ticket order by its persisted showing marker.');
  }
  update_post_meta($showing_id, '_roxy_sales_stats', []);
  wp_cache_delete($showing_id, 'post_meta');

  $lock_name = 'roxy_sales_stats_' . md5((string)get_current_blog_id() . ':' . $showing_id);
  $peer = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
  if (!$peer->ready) throw new RuntimeException('The independent MySQL connection is unavailable.');

  $hook = static function ($query) use (&$hook, &$worker, &$worker_pipes, $peer, $wpdb, $item_id, $showing_id, $lock_name, $ready_path): string {
    if (!preg_match('/^\s*(?:INSERT|UPDATE)\b/i', (string)$query) || strpos((string)$query, '_roxy_sales_stats') === false) return $query;
    remove_filter('query', $hook, PHP_INT_MAX);

    $updated = $peer->update(
      $wpdb->prefix . 'woocommerce_order_itemmeta',
      ['meta_value'=>'3'],
      ['order_item_id'=>$item_id, 'meta_key'=>'_qty'],
      ['%s'], ['%d','%s']
    );
    if ($updated !== 1) throw new RuntimeException('Independent connection could not change the fixture line quantity.');

    $worker_code = 'require ' . var_export(ABSPATH . 'wp-load.php', true) . '; global $wpdb; '
      . '$lock=' . var_export($lock_name, true) . '; '
      . 'if ((string)$wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s,%d)",$lock,0)) !== "0") {fwrite(STDERR,"fixture did not observe the held refresh lock"); exit(41);} '
      . 'file_put_contents(' . var_export($ready_path, true) . ',"blocked"); '
      . '$stats=\\RoxyST\\Sales::refresh_showing_stats(' . (int)$showing_id . '); '
      . 'echo json_encode($stats); if (!empty($stats["read_error"])) exit(42);';
    $descriptors = [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']];
    $worker = proc_open([PHP_BINARY, '-r', $worker_code], $descriptors, $worker_pipes, ABSPATH);
    if (!is_resource($worker)) throw new RuntimeException('Could not start the independent refresh worker.');
    fclose($worker_pipes[0]);
    $deadline = microtime(true) + 5;
    while (!is_file($ready_path) && microtime(true) < $deadline) usleep(20000);
    if (!is_file($ready_path)) throw new RuntimeException('Worker did not confirm contention on the held showing lock.');
    return $query;
  };

  add_filter('query', $hook, PHP_INT_MAX, 1);
  $older_snapshot = \RoxyST\Sales::refresh_showing_stats($showing_id);
  remove_filter('query', $hook, PHP_INT_MAX);
  $hook = null;

  if (!is_resource($worker)) throw new RuntimeException('The refresh write boundary was not reached by the primary worker.');
  $stdout = stream_get_contents($worker_pipes[1]);
  $stderr = stream_get_contents($worker_pipes[2]);
  fclose($worker_pipes[1]); fclose($worker_pipes[2]); $worker_pipes=[];
  $worker_exit = proc_close($worker); $worker=null;
  @unlink($ready_path);
  $newer_snapshot = json_decode(trim((string)$stdout), true);

  $check(empty($older_snapshot['read_error']) && $older_snapshot['sold_qty']===1,
    'primary refresh retains the snapshot it calculated before the concurrent quantity change');
  $check($worker_exit===0 && is_array($newer_snapshot) && empty($newer_snapshot['read_error']) && ($newer_snapshot['sold_qty'] ?? null)===3,
    'second refresh waits for the primary lock then recalculates the newer Woo order quantity' . ($stderr !== '' ? ': ' . trim($stderr) : ''));
  $stored = get_post_meta($showing_id, '_roxy_sales_stats', true);
  $check(is_array($stored) && ($stored['sold_qty'] ?? null)===3,
    'final persistent showing projection is the newer snapshot, not the late stale overwrite');
  $check($peer->get_var($peer->prepare('SELECT IS_USED_LOCK(%s)', $lock_name)) === null,
    'both refresh connections release the real MySQL showing lock after the interleaving');
} finally {
  if ($hook !== null) remove_filter('query', $hook, PHP_INT_MAX);
  @unlink($ready_path);
  if (is_resource($worker)) {
    proc_terminate($worker);
    foreach ($worker_pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($worker);
  }
  if ($peer instanceof wpdb && method_exists($peer, 'close')) $peer->close();
  if ($order_id > 0) {
    $order = wc_get_order($order_id);
    if ($order && strpos((string)$order->get_customer_note(), $token) === false) throw new RuntimeException('Refusing to delete an order not owned by this fixture.');
    if ($order) $order->delete(true);
  }
  if ($showing_id > 0) {
    $showing = get_post($showing_id);
    if ($showing && $showing->post_title !== $token . ' private showing') throw new RuntimeException('Refusing to delete a showing not owned by this fixture.');
    if ($showing) wp_delete_post($showing_id, true);
  }
  if ($product_id > 0) {
    $product = wc_get_product($product_id);
    if ($product && $product->get_name() !== $token . ' zero-value ticket product') throw new RuntimeException('Refusing to delete a product not owned by this fixture.');
    if ($product) $product->delete(true);
  }
}

echo "Passed two-connection sales-refresh concurrency lifecycle. Zero-value private fixtures only.\n";
