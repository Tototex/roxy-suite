<?php
namespace RoxyGrosses;
if (!defined('ABSPATH')) exit;

/** Receives authenticated Square refund.updated events; it does not alter reports or totals. */
final class RefundWebhook {
  private const MAX_BODY_BYTES = 262144;

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'register_route']);
  }

  public static function register_route(): void {
    register_rest_route('roxy/v1', '/square-refund-events', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => [__CLASS__, 'receive'],
    ]);
  }

  public static function expected_signature(string $body, string $signature_key, string $notification_url): string {
    return base64_encode(hash_hmac('sha256', $notification_url . $body, $signature_key, true));
  }

  private static function utc_datetime($value, bool $required = false): ?string {
    if ($value === null || $value === '') {
      if ($required) throw new \UnexpectedValueException('Missing event timestamp.');
      return null;
    }
    if (!is_string($value)) throw new \UnexpectedValueException('Invalid event timestamp.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
      throw new \UnexpectedValueException('Invalid event timestamp.');
    }
    try {
      $date = new \DateTimeImmutable($value);
      $errors = \DateTimeImmutable::getLastErrors();
      if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new \UnexpectedValueException('Invalid event timestamp.');
      return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (\Throwable $error) {
      throw new \UnexpectedValueException('Invalid event timestamp.');
    }
  }

  public static function receive($request) {
    $body = (string) $request->get_body();
    if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) return new \WP_REST_Response(['error' => 'Invalid webhook body.'], 400);

    $signature_key = (string) Settings::get('square_webhook_signature_key', '');
    if ($signature_key === '') return new \WP_REST_Response(['error' => 'Webhook is not configured.'], 503);
    $received_signature = trim((string) $request->get_header('x-square-hmacsha256-signature'));
    $notification_url = rest_url('roxy/v1/square-refund-events');
    $expected_signature = self::expected_signature($body, $signature_key, $notification_url);
    if ($received_signature === '' || !hash_equals($expected_signature, $received_signature)) {
      return new \WP_REST_Response(['error' => 'Invalid webhook signature.'], 403);
    }

    $event = json_decode($body, true);
    if (!is_array($event) || json_last_error() !== JSON_ERROR_NONE) return new \WP_REST_Response(['error' => 'Invalid webhook JSON.'], 400);
    if (($event['type'] ?? '') !== 'refund.updated') return new \WP_REST_Response(['received' => true], 200);

    try {
      $event_id = $event['event_id'] ?? null;
      $refund = $event['data']['object']['refund'] ?? null;
      if (!is_string($event_id) || $event_id === '' || strlen($event_id) > 191 || !is_array($refund)) {
        throw new \UnexpectedValueException('Missing event or refund identity.');
      }
      $refund_id = $refund['id'] ?? null;
      $payment_id = $refund['payment_id'] ?? '';
      $order_id = $refund['order_id'] ?? '';
      $location_id = $refund['location_id'] ?? '';
      $status = $refund['status'] ?? null;
      $money = $refund['amount_money'] ?? null;
      if (!is_string($refund_id) || $refund_id === '' || strlen($refund_id) > 191
        || !is_string($status) || !preg_match('/^[A-Z_]{1,32}$/D', $status)
        || !is_array($money) || !is_int($money['amount'] ?? null) || $money['amount'] < 0
        || ($money['currency'] ?? null) !== 'USD') {
        throw new \UnexpectedValueException('Refund fields are invalid or use an unsupported currency.');
      }
      foreach (['payment_id' => $payment_id, 'order_id' => $order_id, 'location_id' => $location_id] as $field => $value) {
        if (!is_string($value) || strlen($value) > 191) throw new \UnexpectedValueException('Refund reference is invalid.');
      }
      $event_created_at = self::utc_datetime($event['created_at'] ?? null, true);
      $refund_created_at = self::utc_datetime($refund['created_at'] ?? null);
      $refund_updated_at = self::utc_datetime($refund['updated_at'] ?? null);
    } catch (\UnexpectedValueException $error) {
      return new \WP_REST_Response(['error' => 'Invalid refund event.'], 400);
    }

    global $wpdb;
    $table = Store::refund_webhook_table_name();
    $payload_hash = hash('sha256', $body);
    $inserted = $wpdb->insert($table, [
      'event_id' => $event_id,
      'refund_id' => $refund_id,
      'payment_id' => $payment_id,
      'order_id' => $order_id,
      'location_id' => $location_id,
      'status' => $status,
      'amount_cents' => $money['amount'],
      'currency' => 'USD',
      'event_created_at' => $event_created_at,
      'refund_created_at' => $refund_created_at,
      'refund_updated_at' => $refund_updated_at,
      'payload_hash' => $payload_hash,
    ]);
    if ($inserted === 1) return new \WP_REST_Response(['received' => true], 200);

    if ($wpdb->last_error !== '') {
      $existing_hash = $wpdb->get_var($wpdb->prepare("SELECT payload_hash FROM {$table} WHERE event_id = %s", $event_id));
      if ($wpdb->last_error === '' && is_string($existing_hash) && hash_equals($existing_hash, $payload_hash)) {
        return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
      }
      if ($wpdb->last_error === '' && is_string($existing_hash)) {
        error_log('Roxy Grosses ignored a signed Square webhook retry whose event ID had a different payload.');
        return new \WP_REST_Response(['received' => true, 'conflict' => true], 200);
      }
    }
    return new \WP_REST_Response(['error' => 'Could not store webhook event.'], 500);
  }
}
