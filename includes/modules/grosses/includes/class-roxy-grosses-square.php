<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Square {
  private const API_VERSION = '2026-01-22';
  private const IN_STORE_PURCHASE_CATEGORY = 'In Store Purchase';

  public static function fetch_orders_for_date(string $report_date): array {
    [$start_at, $end_at] = self::date_window($report_date, Settings::get_report_timezone());
    $location_ids = Settings::line_list((string) (Settings::get_all()['square_location_ids'] ?? ''));
    if (!$location_ids) {
      throw new \RuntimeException('Add at least one Square location ID before sending grosses reports.');
    }

    $orders = [];
    $cursor = null;

    do {
      $body = [
        'query' => [
          'filter' => [
            'date_time_filter' => [
              'closed_at' => [
                'start_at' => $start_at,
                'end_at' => $end_at,
              ],
            ],
            'state_filter' => [
              'states' => ['COMPLETED'],
            ],
          ],
          'sort' => [
            'sort_field' => 'CLOSED_AT',
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

      $data = self::request('POST', '/v2/orders/search', $body);

      foreach ((array) ($data['orders'] ?? []) as $order) {
        if (is_array($order)) {
          $orders[] = $order;
        }
      }

      $cursor = !empty($data['cursor']) ? (string) $data['cursor'] : null;
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

  private static function request(string $method, string $path, array $body = null): array {
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
      'timeout' => 25,
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
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300) {
      $message = 'Square request failed.';
      if (!empty($data['errors'][0]['detail'])) {
        $message = (string) $data['errors'][0]['detail'];
      }
      throw new \RuntimeException($message);
    }

    return is_array($data) ? $data : [];
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
    $start = new \DateTimeImmutable($report_date . ' 00:00:00', $tz);
    $end = $start->modify('+1 day');

    return [$start->setTimezone(new \DateTimeZone('UTC'))->format('c'), $end->setTimezone(new \DateTimeZone('UTC'))->format('c')];
  }
}
