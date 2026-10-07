<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Square {
  private const API_VERSION = '2026-01-22';
  private const IN_STORE_PURCHASE_CATEGORY = 'In Store Purchase';
  private static int $sale_snapshot_depth = 0;
  private static array $sale_snapshot = [];
  private static array $category_snapshot = [];

  /** PHP 8.0-compatible equivalent of array_is_list(). */
  private static function is_list(array $value): bool {
    $expected = 0;
    foreach ($value as $key => $_) if ($key !== $expected++) return false;
    return true;
  }

  /** One immutable sale-day read per managed operation; never a persistent cache. */
  public static function with_sale_snapshot(callable $operation) {
    $outer = self::$sale_snapshot_depth === 0;
    if ($outer) { self::$sale_snapshot = []; self::$category_snapshot = []; }
    ++self::$sale_snapshot_depth;
    try { return $operation(); }
    finally {
      --self::$sale_snapshot_depth;
      if ($outer) { self::$sale_snapshot = []; self::$category_snapshot = []; }
    }
  }

  public static function fetch_orders_for_date(string $report_date): array {
    [$start_at, $end_at] = self::date_window($report_date, Settings::get_report_timezone());
    $settings = Settings::get_all();
    $key = hash('sha256', serialize([$start_at, $end_at, $settings['square_environment'] ?? 'production', $settings['square_location_ids'] ?? '']));
    if (self::$sale_snapshot_depth > 0 && array_key_exists($key, self::$sale_snapshot)) return self::$sale_snapshot[$key];
    $orders = self::search_orders_window($start_at, $end_at, 'closed_at');
    if (self::$sale_snapshot_depth > 0) self::$sale_snapshot[$key] = $orders;
    return $orders;
  }

  /** Return discovery is separate from sale-day searches, including late updates. */
  public static function fetch_orders_updated_between(string $start_at, string $end_at, ?float $deadline = null, bool $returns_only = false): array {
    [$start, $end] = self::ordered_timestamps($start_at, $end_at);
    return self::search_orders_window($start->format('c'), $end->format('c'), 'updated_at', $deadline, $returns_only);
  }

  private static function ordered_timestamps(string $start_at, string $end_at): array {
    $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/';
    if (!preg_match($pattern, $start_at) || !preg_match($pattern, $end_at)) {
      throw new \RuntimeException('Use explicit timezone timestamps for Square return discovery.');
    }
    $start = new \DateTimeImmutable($start_at);
    $errors = \DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new \RuntimeException('Invalid Square return-discovery start timestamp.');
    $end = new \DateTimeImmutable($end_at);
    $errors = \DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new \RuntimeException('Invalid Square return-discovery end timestamp.');
    if ($start >= $end) throw new \RuntimeException('Square return discovery requires an increasing time window.');
    return [$start, $end];
  }

  /** Read-only refund feed; includes pending changes without the one-year default creation cutoff. */
  public static function list_payment_refunds_updated_between(string $start_at, string $end_at, ?float $deadline = null): array {
    [$start, $end] = self::ordered_timestamps($start_at, $end_at);
    $locations = array_values(array_unique(Settings::line_list((string) (Settings::get_all()['square_location_ids'] ?? ''))));
    if (!$locations || count($locations) > 10) throw new \RuntimeException('Configure one to ten Square locations for refund discovery.');
    $refunds = []; $pages = 0; $deadline = $deadline ?? microtime(true) + 120;
    foreach ($locations as $location) {
      $cursor = null; $seen_cursors = [];
      do {
        if (++$pages > 100 || microtime(true) >= $deadline) throw new \RuntimeException('Square refund retrieval exceeded its safety limit. No partial refund list was returned.');
        $query = ['begin_time' => '2000-01-01T00:00:00Z', 'end_time' => $end->format('c'),
          'updated_at_begin_time' => $start->format('c'), 'updated_at_end_time' => $end->format('c'),
          'sort_field' => 'UPDATED_AT', 'sort_order' => 'ASC', 'limit' => 100, 'location_id' => $location];
        if ($cursor !== null) $query['cursor'] = $cursor;
        $response = self::request('GET', '/v2/refunds?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), null, $deadline);
        if (array_key_exists('refunds', $response) && (!is_array($response['refunds']) || !self::is_list($response['refunds']))) throw new \RuntimeException('Square returned an invalid refund list.');
        foreach ($response['refunds'] ?? [] as $refund) {
          $id = is_array($refund) ? ($refund['id'] ?? null) : null;
          if (!is_string($id) || $id === '' || strlen($id) > 255 || isset($refunds[$id]) || ($refund['location_id'] ?? null) !== $location || !in_array($refund['status'] ?? '', ['PENDING','COMPLETED','REJECTED','FAILED'], true)) throw new \RuntimeException('Square returned an invalid, misplaced or duplicate refund.');
          $updated = $refund['updated_at'] ?? null;
          if (!is_string($updated)) throw new \RuntimeException('Square refund has no update timestamp.');
          [$timestamp] = self::ordered_timestamps($updated, $end->modify('+1 second')->format('c'));
          if ($timestamp < $start || $timestamp > $end) throw new \RuntimeException('Square refund falls outside the requested update window.');
          $refunds[$id] = $refund;
        }
        if (array_key_exists('cursor', $response) && (!is_string($response['cursor']) || $response['cursor'] === '' || strlen($response['cursor']) > 10000)) throw new \RuntimeException('Square returned an invalid refund pagination cursor.');
        $cursor = $response['cursor'] ?? null;
        if ($cursor !== null) {
          if (isset($seen_cursors[$cursor])) throw new \RuntimeException('Square repeated a refund pagination cursor.');
          $seen_cursors[$cursor] = true;
        }
      } while ($cursor !== null);
    }
    return array_values($refunds);
  }

  public static function retrieve_orders(array $order_ids, ?float $deadline = null): array {
    $ids = [];
    foreach ($order_ids as $id) {
      if (!is_string($id) || $id === '' || strlen($id) > 192) throw new \RuntimeException('Invalid source order reference in Square returns.');
      $ids[$id] = true;
    }
    if (count($ids) > 1000) throw new \RuntimeException('Square source-order lookup exceeded its safety limit.');
    $orders = [];
    $deadline = $deadline ?? microtime(true) + 120;
    foreach (array_chunk(array_keys($ids), 100) as $batch) {
      if (microtime(true) >= $deadline) throw new \RuntimeException('Square source-order lookup timed out.');
      $response = self::request('POST', '/v2/orders/batch-retrieve', ['order_ids' => $batch], $deadline);
      if (!isset($response['orders']) || !is_array($response['orders']) || !self::is_list($response['orders'])) throw new \RuntimeException('Square did not return source orders.');
      foreach ($response['orders'] as $order) {
        $id = is_array($order) ? ($order['id'] ?? null) : null;
        if (!is_string($id) || !in_array($id, $batch, true) || isset($orders[$id])) throw new \RuntimeException('Square returned unexpected or duplicate source orders.');
        $orders[$id] = $order;
      }
      foreach ($batch as $id) if (!isset($orders[$id])) throw new \RuntimeException('A referenced Square source order is unavailable; no correction was calculated.');
    }
    return array_values($orders);
  }

  public static function retrieve_payment_refund(string $refund_id, ?float $deadline = null): array {
    if ($refund_id === '' || strlen($refund_id) > 255) throw new \RuntimeException('Invalid Square payment-refund reference.');
    $response = self::request('GET', '/v2/refunds/' . rawurlencode($refund_id), null, $deadline);
    $refund = $response['refund'] ?? null;
    if (!is_array($refund) || ($refund['id'] ?? null) !== $refund_id || !in_array($refund['status'] ?? '', ['PENDING', 'COMPLETED', 'REJECTED', 'FAILED'], true)) throw new \RuntimeException('Square returned an invalid payment-refund status.');
    return $refund;
  }

  private static function search_orders_window(string $start_at, string $end_at, string $date_field, ?float $deadline = null, bool $returns_only = false): array {
    $location_ids = Settings::line_list((string) (Settings::get_all()['square_location_ids'] ?? ''));
    if (!$location_ids) {
      throw new \RuntimeException('Add at least one Square location ID before sending grosses reports.');
    }
    if (count($location_ids) > 10) throw new \RuntimeException('Square order search supports at most ten locations per report.');

    $orders = [];
    $cursor = null;
    $seen_cursors = [];
    $seen_orders = [];
    $pages = 0;
    $deadline = $deadline ?? microtime(true) + 120;

    do {
      if (++$pages > 100 || microtime(true) >= $deadline) throw new \RuntimeException('Square order retrieval exceeded its safety limit. No partial report was returned.');
      $body = [
        'return_entries' => false,
        'limit' => 500,
        'query' => [
          'filter' => [
            'date_time_filter' => [
              $date_field => [
                'start_at' => $start_at,
                'end_at' => $end_at,
              ],
            ],
            'state_filter' => [
              'states' => ['COMPLETED'],
            ],
          ],
          'sort' => [
            'sort_field' => strtoupper($date_field),
            'sort_order' => 'ASC',
          ],
        ],
      ];

      if ($location_ids) {
        $body['location_ids'] = $location_ids;
      }

      if ($cursor) {
        $body['cursor'] = $cursor;
      }

      $data = self::request('POST', '/v2/orders/search', $body, $deadline);
      if (isset($data['order_entries'])) throw new \RuntimeException('Square returned order summaries instead of complete orders. No report was returned.');
      if (array_key_exists('orders', $data) && (!is_array($data['orders']) || !self::is_list($data['orders']))) throw new \RuntimeException('Square returned an invalid order list. No report was returned.');

      foreach ((array) ($data['orders'] ?? []) as $order) {
        if (!is_array($order) || !isset($order['id']) || !is_string($order['id']) || $order['id'] === '' || isset($seen_orders[$order['id']])) throw new \RuntimeException('Square returned invalid or repeated orders. No partial report was returned.');
        $seen_orders[$order['id']] = true;
        if (!$returns_only || !empty($order['returns'])) $orders[] = $order;
      }

      if (array_key_exists('cursor', $data) && (!is_string($data['cursor']) || $data['cursor'] === '' || strlen($data['cursor']) > 10000)) throw new \RuntimeException('Square returned an invalid pagination cursor. No partial report was returned.');
      $cursor = $data['cursor'] ?? null;
      if ($cursor !== null) {
        if (isset($seen_cursors[$cursor])) throw new \RuntimeException('Square repeated a pagination cursor. No partial report was returned.');
        $seen_cursors[$cursor] = true;
      }
    } while ($cursor);

    return $orders;
  }

  public static function concession_reporting_categories(array $catalog_object_ids): array {
    $catalog_object_ids = array_values(array_unique(array_filter(array_map('strval', $catalog_object_ids))));
    if (!$catalog_object_ids) {
      return [];
    }

    $cache = [];
    $uncached = [];
    $settings = Settings::get_all();
    $scope = hash('sha256', serialize([$settings['square_environment'] ?? 'production', Settings::square_access_token()]));
    $managed = self::$sale_snapshot_depth > 0;

    foreach ($catalog_object_ids as $catalog_object_id) {
      if ($managed && array_key_exists($catalog_object_id, self::$category_snapshot[$scope] ?? [])) {
        $cache[$catalog_object_id] = self::$category_snapshot[$scope][$catalog_object_id];
        continue;
      }
      $cache_key = self::catalog_category_cache_key($catalog_object_id);
      // Managed reports require fresh metadata once, then share it for this operation.
      $cached = $managed ? false : get_transient($cache_key);
      if (is_string($cached) && $cached !== '') {
        $cache[$catalog_object_id] = $cached;
        continue;
      }
      $uncached[] = $catalog_object_id;
    }

    foreach (array_chunk($uncached, 100) as $batch) {
      $data = self::request('POST', '/v2/catalog/batch-retrieve', [
        'object_ids' => array_values($batch),
        'include_related_objects' => true,
      ]);

      $all_objects = [];
      self::index_catalog_objects($all_objects, $data['objects'] ?? []);
      self::index_catalog_objects($all_objects, $data['related_objects'] ?? []);

      $missing_category_ids = [];
      foreach ($batch as $catalog_object_id) {
        $variation = $all_objects[$catalog_object_id] ?? null;
        if (!is_array($variation) || (($variation['type'] ?? '') !== 'ITEM_VARIATION')) {
          continue;
        }

        $item_id = (string) ($variation['item_variation_data']['item_id'] ?? '');
        $item = $all_objects[$item_id] ?? null;
        if (!is_array($item) || (($item['type'] ?? '') !== 'ITEM')) {
          continue;
        }

        $category_id = (string) ($item['item_data']['reporting_category']['id'] ?? $item['item_data']['category_id'] ?? '');
        if ($category_id !== '' && !isset($all_objects[$category_id])) {
          $missing_category_ids[$category_id] = $category_id;
        }
      }

      foreach (array_chunk(array_values($missing_category_ids), 100) as $category_batch) {
        $category_data = self::request('POST', '/v2/catalog/batch-retrieve', [
          'object_ids' => array_values($category_batch),
        ]);

        self::index_catalog_objects($all_objects, $category_data['objects'] ?? []);
      }

      foreach ($batch as $catalog_object_id) {
        $category_name = self::catalog_reporting_category_name($catalog_object_id, $all_objects);
        $cache[$catalog_object_id] = $category_name;
        if ($managed) self::$category_snapshot[$scope][$catalog_object_id] = $category_name;
        if ($category_name !== '') {
          set_transient(self::catalog_category_cache_key($catalog_object_id), $category_name, DAY_IN_SECONDS * 14);
        } else {
          delete_transient(self::catalog_category_cache_key($catalog_object_id));
        }
      }
    }

    return $cache;
  }

  public static function is_in_store_purchase_item(string $catalog_object_id, array $category_map = []): bool {
    if ($catalog_object_id === '') {
      return false;
    }

    $category_name = (string) ($category_map[$catalog_object_id] ?? '');
    return $category_name !== '' && strcasecmp($category_name, self::IN_STORE_PURCHASE_CATEGORY) === 0;
  }

  private static function index_catalog_objects(array &$index, array $objects): void {
    if (!self::is_list($objects)) throw new \RuntimeException('Square returned an invalid catalog list. No report was calculated.');
    foreach ($objects as $object) {
      if (!is_array($object) || !is_string($object['id'] ?? null) || $object['id'] === '' || !is_string($object['type'] ?? null) || $object['type'] === '') {
        throw new \RuntimeException('Square returned an invalid catalog object. No report was calculated.');
      }
      $id = $object['id'];
      if (isset($index[$id]) && $index[$id] != $object) throw new \RuntimeException('Square returned conflicting catalog metadata. No report was calculated.');
      $index[$id] = $object;
    }
  }

  private static function request(string $method, string $path, array $body = null, ?float $deadline = null): array {
    if ($deadline !== null && microtime(true) >= $deadline) throw new \RuntimeException('Square retrieval timed out. No partial result was returned.');
    $settings = Settings::get_all();
    $token = Settings::square_access_token();

    if ($token === '') {
      throw new \RuntimeException('Add a Square access token before sending grosses reports.');
    }

    $base_url = ($settings['square_environment'] ?? 'production') === 'sandbox'
      ? 'https://connect.squareupsandbox.com'
      : 'https://connect.squareup.com';

    $args = [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Square-Version' => self::API_VERSION,
      ],
      'timeout' => $deadline === null ? 25 : max(1, min(25, (int) ceil($deadline - microtime(true)))),
      'redirection' => 0,
    ];

    if ($body !== null) {
      $args['body'] = wp_json_encode($body);
    }

    $response = strtoupper($method) === 'GET'
      ? wp_remote_get($base_url . $path, $args)
      : wp_remote_post($base_url . $path, $args);

    if (is_wp_error($response)) {
      throw new \RuntimeException($response->get_error_message());
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw = (string) wp_remote_retrieve_body($response);
    $object = json_decode($raw);
    $data = json_decode($raw, true);

    if ($code < 200 || $code >= 300) {
      $message = 'Square request failed.';
      if (!empty($data['errors'][0]['detail'])) {
        $message = (string) $data['errors'][0]['detail'];
      }
      throw new \RuntimeException($message);
    }

    if (json_last_error() !== JSON_ERROR_NONE || !is_object($object) || !is_array($data)) throw new \RuntimeException('Square returned an unreadable response. No report was returned.');
    foreach (['orders', 'order_entries', 'objects', 'related_objects', 'locations', 'refunds', 'errors'] as $list) {
      if (property_exists($object, $list) && !is_array($object->$list)) throw new \RuntimeException('Square returned an invalid response list. No report was returned.');
    }
    if (!empty($data['errors'])) throw new \RuntimeException('Square reported an API error. No report was returned.');
    return $data;
  }

  private static function catalog_reporting_category_name(string $catalog_object_id, array $all_objects): string {
    $variation = $all_objects[$catalog_object_id] ?? null;
    if (!is_array($variation) || (($variation['type'] ?? '') !== 'ITEM_VARIATION')) {
      return '';
    }

    $item_id = (string) ($variation['item_variation_data']['item_id'] ?? '');
    $item = $all_objects[$item_id] ?? null;
    if (!is_array($item) || (($item['type'] ?? '') !== 'ITEM')) {
      return '';
    }

    $category_id = (string) ($item['item_data']['reporting_category']['id'] ?? $item['item_data']['category_id'] ?? '');
    if ($category_id === '') {
      return '';
    }

    $category = $all_objects[$category_id] ?? null;
    if (!is_array($category) || (($category['type'] ?? '') !== 'CATEGORY')) {
      return '';
    }

    return trim((string) ($category['category_data']['name'] ?? ''));
  }

  private static function catalog_category_cache_key(string $catalog_object_id): string {
    return 'roxy_grosses_sq_cat_' . md5($catalog_object_id);
  }

  private static function date_window(string $report_date, string $timezone): array {
    $tz = new \DateTimeZone($timezone);
    $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $report_date, $tz);
    if (!$start || $start->format('Y-m-d') !== $report_date) throw new \RuntimeException('Use a valid calendar date for Square reports.');
    $end = $start->modify('+1 day');

    return [$start->setTimezone(new \DateTimeZone('UTC'))->format('c'), $end->setTimezone(new \DateTimeZone('UTC'))->format('c')];
  }
}
