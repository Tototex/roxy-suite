<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Square {
  private const API_VERSION = '2026-01-22';
  private const IN_STORE_PURCHASE_CATEGORY = 'In Store Purchase';

  public static function fetch_orders_for_date(string $report_date): array {
    [$start_at, $end_at] = self::date_window($report_date, Settings::get_report_timezone());
    return self::search_orders_window($start_at, $end_at, 'closed_at');
  }

  /** Return discovery is separate from sale-day searches, including late updates. */
  public static function fetch_orders_updated_between(string $start_at, string $end_at, ?float $deadline = null, bool $returns_only = false): array {
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
    return self::search_orders_window($start->format('c'), $end->format('c'), 'updated_at', $deadline, $returns_only);
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
      if (!isset($response['orders']) || !is_array($response['orders']) || !array_is_list($response['orders'])) throw new \RuntimeException('Square did not return source orders.');
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
      if (array_key_exists('orders', $data) && (!is_array($data['orders']) || !array_is_list($data['orders']))) throw new \RuntimeException('Square returned an invalid order list. No report was returned.');

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

    foreach ($catalog_object_ids as $catalog_object_id) {
      $cache_key = self::catalog_category_cache_key($catalog_object_id);
      $cached = get_transient($cache_key);
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
      foreach ((array) ($data['objects'] ?? []) as $object) {
        if (is_array($object) && !empty($object['id'])) {
          $all_objects[(string) $object['id']] = $object;
        }
      }
      foreach ((array) ($data['related_objects'] ?? []) as $object) {
        if (is_array($object) && !empty($object['id'])) {
          $all_objects[(string) $object['id']] = $object;
        }
      }

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

        foreach ((array) ($category_data['objects'] ?? []) as $object) {
          if (is_array($object) && !empty($object['id'])) {
            $all_objects[(string) $object['id']] = $object;
          }
        }
      }

      foreach ($batch as $catalog_object_id) {
        $category_name = self::catalog_reporting_category_name($catalog_object_id, $all_objects);
        $cache[$catalog_object_id] = $category_name;
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
    foreach (['orders', 'order_entries', 'objects', 'related_objects', 'locations', 'errors'] as $list) {
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
