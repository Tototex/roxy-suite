<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Square {
  private const API_VERSION = '2026-01-22';

  public static function fetch_orders_for_date(string $report_date): array {
    $settings = Settings::get_all();
    $token = trim((string) ($settings['square_access_token'] ?? ''));

    if ($token === '') {
      throw new \RuntimeException('Add a Square access token before sending grosses reports.');
    }

    [$start_at, $end_at] = self::date_window($report_date, Settings::get_report_timezone());
    $location_ids = Settings::line_list((string) ($settings['square_location_ids'] ?? ''));
    if (!$location_ids) {
      throw new \RuntimeException('Add at least one Square location ID before sending grosses reports.');
    }
    $base_url = ($settings['square_environment'] ?? 'production') === 'sandbox'
      ? 'https://connect.squareupsandbox.com'
      : 'https://connect.squareup.com';

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

      $response = wp_remote_post($base_url . '/v2/orders/search', [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'Square-Version' => self::API_VERSION,
        ],
        'body' => wp_json_encode($body),
        'timeout' => 25,
      ]);

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

      foreach ((array) ($data['orders'] ?? []) as $order) {
        if (is_array($order)) {
          $orders[] = $order;
        }
      }

      $cursor = !empty($data['cursor']) ? (string) $data['cursor'] : null;
    } while ($cursor);

    return $orders;
  }

  private static function date_window(string $report_date, string $timezone): array {
    $tz = new \DateTimeZone($timezone);
    $start = new \DateTimeImmutable($report_date . ' 00:00:00', $tz);
    $end = $start->modify('+1 day');

    return [$start->setTimezone(new \DateTimeZone('UTC'))->format('c'), $end->setTimezone(new \DateTimeZone('UTC'))->format('c')];
  }
}
