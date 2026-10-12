<?php
/**
 * Real WooCommerce/legacy-CPT regression for the Will Call product candidate
 * query. Run only against a disposable WordPress installation via WP-CLI.
 */
if (!defined('WP_CLI') || !WP_CLI || !function_exists('wc_create_order')) {
  throw new RuntimeException('This fixture requires WP-CLI and an active WooCommerce installation.');
}

$root = $args[0] ?? dirname(__DIR__);
$orders = [];
$products = [];
$cache_key = '';
$mail_calls = 0;
$mail_recipients = [];
$mail_recipients_safe = true;
$mail_guard = static function ($pre, $atts) use (&$mail_calls, &$mail_recipients, &$mail_recipients_safe) {
  $mail_calls++;
  $addresses = [];
  foreach (array_merge((array) ($atts['to'] ?? []), (array) ($atts['cc'] ?? []), (array) ($atts['bcc'] ?? [])) as $recipient) {
    if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', (string) $recipient, $matches)) {
      $addresses = array_merge($addresses, $matches[0]);
    }
  }
  if (!$addresses) $mail_recipients_safe = false;
  foreach ($addresses as $address) {
    $mail_recipients[] = strtolower($address);
    if (substr(strtolower($address), -8) !== '.invalid') $mail_recipients_safe = false;
  }
  return true;
};
$check = static function ($condition, string $label): void {
  if (!$condition) throw new RuntimeException($label);
  echo 'PASS: ' . $label . PHP_EOL;
};
$make_product = static function (string $name, float $price) use (&$products): WC_Product_Simple {
  $product = new WC_Product_Simple();
  $product->set_name($name);
  $product->set_status('private');
  $product->set_catalog_visibility('hidden');
  $product->set_regular_price((string) $price);
  $product->set_price((string) $price);
  $product->set_virtual(true);
  $product->save();
  if ($product->get_id() <= 0) throw new RuntimeException('Could not create private fixture product.');
  $products[] = $product->get_id();
  return $product;
};
$make_order = static function (WC_Product $product, string $name, string $email, string $status = 'processing') use (&$orders): WC_Order {
  $order = wc_create_order(['status' => $status, 'customer_note' => 'PRIVATE WILL CALL CANDIDATE FIXTURE - no fulfillment']);
  if (is_wp_error($order) || !($order instanceof WC_Order)) throw new RuntimeException('Could not create private fixture order.');
  $orders[] = $order->get_id();
  $order->set_billing_first_name($name);
  $order->set_billing_last_name('Fixture');
  $order->set_billing_email($email);
  $order->add_product($product, 1);
  $order->calculate_totals(false);
  $order->save();
  return $order;
};

try {
  // Refuse HPOS: this test specifically exercises Woo's legacy CPT query hook.
  $order_util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
  if (!class_exists($order_util) || !method_exists($order_util, 'custom_orders_table_usage_is_enabled')
    || call_user_func([$order_util, 'custom_orders_table_usage_is_enabled'])) {
    throw new RuntimeException('The private fixture must run with WooCommerce legacy CPT order storage enabled.');
  }

  // Silence Woo email handlers defensively. No payment gateway is configured
  // or invoked; every order is synthetic and remains inside disposable MySQL.
  add_filter('woocommerce_email_enabled_new_order', '__return_false', PHP_INT_MAX);
  add_filter('woocommerce_email_enabled_customer_processing_order', '__return_false', PHP_INT_MAX);
  add_filter('woocommerce_email_enabled_customer_completed_order', '__return_false', PHP_INT_MAX);
  add_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX, 2);

  $simple = $make_product('Private Will Call simple ticket', 10.00);
  $variation_parent = new WC_Product_Variable();
  $variation_parent->set_name('Private Will Call variable ticket');
  $variation_parent->set_status('private');
  $variation_parent->set_catalog_visibility('hidden');
  $variation_parent->save();
  if ($variation_parent->get_id() <= 0) throw new RuntimeException('Could not create private variable fixture product.');
  $products[] = $variation_parent->get_id();

  $attribute = new WC_Product_Attribute();
  $attribute->set_id(0);
  $attribute->set_name('Ticket type');
  $attribute->set_options(['Standard']);
  $attribute->set_visible(true);
  $attribute->set_variation(true);
  $variation_parent->set_attributes([$attribute]);
  $variation_parent->save();
  $variation = new WC_Product_Variation();
  $variation->set_parent_id($variation_parent->get_id());
  $variation->set_attributes(['ticket-type' => 'Standard']);
  $variation->set_regular_price('12.00');
  $variation->set_price('12.00');
  $variation->set_virtual(true);
  $variation->save();
  if ($variation->get_id() <= 0) throw new RuntimeException('Could not create private ticket variation.');
  $products[] = $variation->get_id();
  $unrelated = $make_product('Private Will Call unrelated item', 4.00);

  // More than one 200-order page of real paid CPT orders. Include both direct
  // product matches and a variation match, with unique synthetic customers.
  for ($index = 1; $index <= 201; $index++) {
    $ticket = ($index === 201) ? $variation : $simple;
    $status = ($index === 201) ? 'completed' : 'processing';
    $make_order($ticket, 'Buyer ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'buyer' . $index . '@example.invalid', $status);
  }
  for ($index = 1; $index <= 201; $index++) {
    $make_order($unrelated, 'Unrelated buyer ' . $index, 'unrelated' . $index . '@example.invalid');
  }
  $make_order($simple, 'Cancelled buyer', 'cancelled@example.invalid', 'cancelled');
  $old_order = $make_order($simple, 'Old buyer', 'old@example.invalid');
  $old_order->set_date_created(new WC_DateTime('2020-01-01 12:00:00'));
  $old_order->save();

  require_once $root . '/includes/modules/will-call/roxy-will-call.php';
  $ticket_ids = [$simple->get_id(), $variation->get_id()];
  $labels = [$simple->get_id() => 'General', $variation->get_id() => 'Variation'];
  $cache_key = roxy_will_call_cache_key($ticket_ids, $labels);

  $restricted_query = roxy_will_call_filter_legacy_ticket_order_query(
    ['post__in' => [$unrelated->get_id()]],
    ['roxy_will_call_ticket_product_ids' => $ticket_ids]
  );
  $check(($restricted_query['post__in'] ?? []) === [$unrelated->get_id()], 'candidate filter preserves an existing post__in restriction');
  $wide_query = roxy_will_call_filter_legacy_ticket_order_query(
    ['post__in' => [$unrelated->get_id()]],
    ['roxy_will_call_ticket_product_ids' => range(1, 501)]
  );
  $check($wide_query === ['post__in' => [$unrelated->get_id()]], 'exceptionally wide ticket catalogs fall back without generating oversized SQL');

  // Baseline runs the exact production list builder with only its optimization
  // hook removed.
  remove_filter('woocommerce_order_data_store_cpt_get_orders_query', 'roxy_will_call_filter_legacy_ticket_order_query', PHP_INT_MAX);
  $baseline = roxy_will_call_get_list($ticket_ids, $labels, true);
  add_filter('woocommerce_order_data_store_cpt_get_orders_query', 'roxy_will_call_filter_legacy_ticket_order_query', PHP_INT_MAX, 2);

  $candidate_sql_count = 0;
  $candidate_pages = 0;
  $wpdb_ref = $GLOBALS['wpdb'];
  $candidate_query_observer = static function ($sql) use (&$candidate_sql_count, $wpdb_ref) {
    if (strpos($sql, $wpdb_ref->prefix . 'woocommerce_order_items') !== false
      && strpos($sql, $wpdb_ref->prefix . 'woocommerce_order_itemmeta') !== false
      && stripos($sql, 'EXISTS') !== false) {
      $candidate_sql_count++;
    }
    return $sql;
  };
  $candidate_page_observer = static function ($query, $query_vars) use (&$candidate_pages) {
    if (array_key_exists('roxy_will_call_ticket_product_ids', $query_vars)) $candidate_pages++;
    return $query;
  };
  add_filter('query', $candidate_query_observer, PHP_INT_MAX);
  add_filter('woocommerce_order_data_store_cpt_get_orders_query', $candidate_page_observer, PHP_INT_MAX, 2);

  $optimized = roxy_will_call_get_list($ticket_ids, $labels, true);
  remove_filter('query', $candidate_query_observer, PHP_INT_MAX);
  remove_filter('woocommerce_order_data_store_cpt_get_orders_query', $candidate_page_observer, PHP_INT_MAX);
  $check($optimized === $baseline, 'real Woo narrowed output exactly matches full baseline rows and totals');
  $check($baseline['totals']['order_count'] === 201 && $baseline['totals']['total_qty'] === 201, 'baseline includes matching paid orders but excludes unrelated, cancelled, and >18-month orders');
  $variation_buyer = array_values(array_filter($optimized['rows'], static function ($row) {
    return ($row['email'] ?? '') === 'buyer201@example.invalid';
  }));
  $check(count($variation_buyer) === 1 && ($variation_buyer[0]['ticket_types']['Variation'] ?? 0) === 1, 'actual variation item metadata narrows to its order and preserves the variation ticket label');
  if ($candidate_sql_count !== 2 || $candidate_pages !== 2) {
    $guard_filters = [
      'woocommerce_order_item_get_product_id',
      'woocommerce_order_item_get_variation_id',
      'woocommerce_order_get_items',
      'woocommerce_order_item_class',
      'get_order_item_metadata',
    ];
    $active_guards = [];
    foreach ($guard_filters as $filter_name) {
      if (has_filter($filter_name)) $active_guards[] = $filter_name . '=' . (string) has_filter($filter_name);
    }
    echo 'Candidate query diagnostic: candidate pages=' . $candidate_pages
      . '; bounded EXISTS SQL count=' . $candidate_sql_count
      . '; active guards=' . implode(',', $active_guards) . PHP_EOL;
  }
  $check(count($optimized['rows']) === 201 && $candidate_pages === 2, 'real candidate filtering traverses both pages for 201 matching legacy orders');
  $check($candidate_sql_count === 2, 'bounded canonical item-meta EXISTS predicate is embedded in both paginated order queries');
  $check((float) $optimized['totals']['total_revenue'] === (float) $baseline['totals']['total_revenue'], 'matching variation contributes to unchanged real Woo revenue totals');
  $check(has_filter('pre_wp_mail', $mail_guard) !== false
    && $mail_recipients_safe
    && count($mail_recipients) >= $mail_calls,
    'fixture mail is intercepted before transport and targets only .invalid test addresses');

  // Force one private SQL read failure for a fresh product set. It must throw
  // and must not persist a partial list in the Will Call transient.
  global $wpdb;
  $failure_cache_key = roxy_will_call_cache_key([$unrelated->get_id()], []);
  $break_candidate_read = static function ($sql) use ($wpdb) {
    if (strpos($sql, $wpdb->prefix . 'woocommerce_order_itemmeta') !== false
      && stripos($sql, 'EXISTS') !== false) {
      return str_replace($wpdb->prefix . 'woocommerce_order_itemmeta', $wpdb->prefix . 'missing_private_fixture_table', $sql);
    }
    return $sql;
  };
  add_filter('query', $break_candidate_read, PHP_INT_MAX);
  $read_failed = false;
  try {
    roxy_will_call_get_list([$unrelated->get_id()], [], true);
  } catch (RuntimeException $error) {
    $read_failed = strpos($error->getMessage(), 'complete order list') !== false;
  } finally {
    remove_filter('query', $break_candidate_read, PHP_INT_MAX);
  }
  $check($read_failed && get_transient($failure_cache_key) === false, 'candidate SQL read failure aborts without caching a partial Will Call result');

  echo "Will Call private WooCommerce candidate regression complete.\n";
} finally {
  remove_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX);
  remove_filter('woocommerce_order_data_store_cpt_get_orders_query', 'roxy_will_call_filter_legacy_ticket_order_query', PHP_INT_MAX);
  if (isset($candidate_query_observer)) remove_filter('query', $candidate_query_observer, PHP_INT_MAX);
  if (isset($candidate_page_observer)) remove_filter('woocommerce_order_data_store_cpt_get_orders_query', $candidate_page_observer, PHP_INT_MAX);
  if (isset($break_candidate_read)) remove_filter('query', $break_candidate_read, PHP_INT_MAX);
  if ($cache_key !== '') delete_transient($cache_key);
  if (isset($failure_cache_key)) delete_transient($failure_cache_key);
  foreach (array_reverse($orders) as $order_id) {
    $order = wc_get_order($order_id);
    if ($order) $order->delete(true);
  }
  foreach (array_reverse($products) as $product_id) wp_delete_post((int) $product_id, true);
}
