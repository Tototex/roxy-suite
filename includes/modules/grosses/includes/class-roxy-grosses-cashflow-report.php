<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

/** Read-only, on-demand daily cashflow snapshot. Never writes reports or sends mail. */
final class CashflowReport {
  private const PAGE_SIZE = 100;
  private const MAX_PAGES = 100;

  private static function is_list(array $value): bool {
    $expected = 0;
    foreach ($value as $key => $_) if ($key !== $expected++) return false;
    return true;
  }

  private static function date_window(string $date): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) throw new \RuntimeException('Choose a valid report date.');
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    $errors = \DateTimeImmutable::getLastErrors();
    if (!$start || $start->format('Y-m-d') !== $date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
      throw new \RuntimeException('Choose a valid report date.');
    }
    $end = $start->modify('+1 day');
    return [$start, $end];
  }

  /** Query Woo through its order data store, with a strict page ceiling and no partial results. */
  private static function woo_objects(string $type, string $date_field, int $start, int $end): array {
    $objects = [];
    $last_page = null;
    for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
      $result = wc_get_orders([
        'type' => $type,
        $date_field => $start . '...' . ($end - 1),
        'limit' => self::PAGE_SIZE,
        'page' => $page,
        'paginate' => true,
        'return' => 'objects',
        'orderby' => 'ID',
        'order' => 'ASC',
      ]);
      if (function_exists('is_wp_error') && is_wp_error($result)) throw new \RuntimeException('WooCommerce could not read the selected day.');
      if (!is_object($result) || !isset($result->orders, $result->total_pages)
        || !is_array($result->orders) || !self::is_list($result->orders)
        || !is_numeric($result->total_pages) || (int) $result->total_pages < 0 || (int) $result->total_pages > self::MAX_PAGES) {
        throw new \RuntimeException('WooCommerce returned an incomplete or invalid page of financial records.');
      }
      $total_pages = (int) $result->total_pages;
      if ($total_pages === 0 && $result->orders) throw new \RuntimeException('WooCommerce returned records with an invalid zero-page summary.');
      if ($last_page !== null && $last_page !== $total_pages) throw new \RuntimeException('WooCommerce result changed during pagination; retry the read-only report.');
      $last_page = $total_pages;
      foreach ($result->orders as $order) {
        if (!is_object($order)) throw new \RuntimeException('WooCommerce returned a malformed financial record.');
        $objects[] = $order;
      }
      if ($total_pages > 0 && !$result->orders) throw new \RuntimeException('WooCommerce returned an empty page before the reported end of its financial records.');
      if ($page >= max(1, $total_pages)) return $objects;
      if (count($result->orders) !== self::PAGE_SIZE) throw new \RuntimeException('WooCommerce returned a short page before the end of its financial records.');
    }
    throw new \RuntimeException('WooCommerce daily report exceeded its 10,000-record safety limit. No partial totals were calculated.');
  }

  /**
   * Build provider-based daily totals for a single Pacific/report-timezone day.
   * All collection/refund reads are read-only; errors abort rather than presenting partial totals.
   */
  public static function for_day(string $report_date): array {
    if (!function_exists('wc_get_orders')) throw new \RuntimeException('WooCommerce order APIs are unavailable.');
    [$local_start, $local_end] = self::date_window($report_date);
    $gateways = Settings::line_list((string) Settings::get('cashflow_woo_gateways', ''));
    if (!$gateways) throw new \RuntimeException('Configure the WooCommerce online payment gateway IDs in Grosses settings before calculating a combined cashflow total.');
    $seen_gateways = [];
    foreach ($gateways as $gateway) {
      if (!preg_match('/^[a-z0-9_-]{1,80}$/D', $gateway) || stripos($gateway, 'square') !== false || isset($seen_gateways[$gateway])) {
        throw new \RuntimeException('WooCommerce gateway configuration is invalid or overlaps Square collections.');
      }
      $seen_gateways[$gateway] = true;
    }
    $utc_start = $local_start->setTimezone(new \DateTimeZone('UTC'));
    $utc_end = $local_end->setTimezone(new \DateTimeZone('UTC'));

    $deadline = microtime(true) + 120;
    $square_payment_rows = Square::list_payments_created_between($utc_start->format('Y-m-d\TH:i:s\Z'), $utc_end->format('Y-m-d\TH:i:s\Z'), $deadline);
    if (!is_array($square_payment_rows) || !self::is_list($square_payment_rows)) throw new \RuntimeException('Square returned an incomplete payment list.');
    $square_collections = SquarePaymentEvents::from_payments($square_payment_rows);
    $square_refund_rows = Square::list_payment_refunds_updated_between($utc_start->format('Y-m-d\TH:i:s\Z'), $utc_end->format('Y-m-d\TH:i:s\Z'), $deadline);
    if (!is_array($square_refund_rows) || !self::is_list($square_refund_rows)) throw new \RuntimeException('Square returned an incomplete refund list.');
    $refunds_by_id = [];
    foreach ($square_refund_rows as $refund) {
      if (!is_array($refund) || !is_string($refund['id'] ?? null) || $refund['id'] === '' || isset($refunds_by_id[$refund['id']])) {
        throw new \RuntimeException('Square returned a malformed or duplicate refund identity.');
      }
      $refunds_by_id[$refund['id']] = $refund;
    }

    if (!method_exists(Store::class, 'completed_refund_events_created_between') || !method_exists(Store::class, 'refund_completion_event')) {
      throw new \RuntimeException('Square refund completion-event history is unavailable.');
    }
    $completion_events = Store::completed_refund_events_created_between(
      $utc_start->format('Y-m-d H:i:s'),
      $utc_end->format('Y-m-d H:i:s')
    );
    if (!is_array($completion_events) || !self::is_list($completion_events) || count($completion_events) > 100) {
      throw new \RuntimeException('Square refund completion-event history is incomplete.');
    }
    foreach ($completion_events as $candidate) {
      if (!is_array($candidate) || !is_string($candidate['refund_id'] ?? null) || $candidate['refund_id'] === ''
        || !is_string($candidate['event_id'] ?? null) || $candidate['event_id'] === ''
        || !is_string($candidate['event_created_at'] ?? null)) {
        throw new \RuntimeException('Square refund completion-event identity is malformed.');
      }
      $refund = $refunds_by_id[$candidate['refund_id']] ?? Square::retrieve_payment_refund($candidate['refund_id'], $deadline);
      $updated_at_value = $refund['updated_at'] ?? null;
      if (($refund['status'] ?? '') !== 'COMPLETED' || !is_string($updated_at_value)
        || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $updated_at_value)) {
        throw new \RuntimeException('A dated Square refund event no longer matches a completed refund. Review this cashflow day.');
      }
      try {
        $updated_at = new \DateTimeImmutable($updated_at_value);
        $timestamp_errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp_errors && ($timestamp_errors['warning_count'] || $timestamp_errors['error_count'])) throw new \RuntimeException();
        $refund_updated_at = $updated_at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
      } catch (\Throwable $error) { throw new \RuntimeException('A dated Square refund has an invalid update timestamp.'); }
      $matched = Store::refund_completion_event($refund, $refund_updated_at);
      if (!$matched || ($matched['event_id'] ?? null) !== $candidate['event_id']
        || ($matched['event_created_at'] ?? null) !== $candidate['event_created_at']
        || ($candidate['refund_updated_at'] ?? null) !== $refund_updated_at) {
        throw new \RuntimeException('A dated Square refund completion event does not exactly match the current provider refund. Review this cashflow day.');
      }
      $refunds_by_id[$candidate['refund_id']] = $refund;
    }
    $square_refund_rows = array_values($refunds_by_id);
    $square_snapshot = RefundSnapshot::from_financial_refund_feed($square_refund_rows);
    $square_refunds = $square_snapshot->completed_financial_refunds();

    $start_timestamp = $utc_start->getTimestamp();
    $end_timestamp = $utc_end->getTimestamp();
    $woo_orders = self::woo_objects('shop_order', 'date_paid', $start_timestamp, $end_timestamp);
    $woo_refund_rows = self::woo_objects('shop_order_refund', 'date_created', $start_timestamp, $end_timestamp);
    $woo_collections = WooCollectionEvents::from_orders($woo_orders, $gateways);
    $woo_refunds = WooRefundEvents::from_order_refunds($woo_refund_rows, $gateways);

    $days = CashflowProjection::daily_totals($square_collections, $woo_collections, $square_refunds, $woo_refunds);
    $totals = $days[$report_date] ?? [
      'square_collected_cents' => 0, 'woocommerce_collected_cents' => 0,
      'square_refunded_cents' => 0, 'woocommerce_refunded_cents' => 0,
      'total_collected_cents' => 0, 'total_refunded_cents' => 0, 'net_cents' => 0,
    ];
    return [
      'report_date' => $report_date,
      'timezone' => Settings::get_report_timezone(),
      'totals' => $totals,
      'counts' => [
        'square_collections' => count(array_filter($square_collections, static fn(array $event): bool => ($event['collection_date'] ?? '') === $report_date)),
        'woocommerce_collections' => count(array_filter($woo_collections, static fn(array $event): bool => ($event['collection_date'] ?? '') === $report_date)),
        'square_refunds' => count(array_filter($square_refunds, static fn(array $event): bool => ($event['refund_date'] ?? '') === $report_date)),
        'woocommerce_refunds' => count(array_filter($woo_refunds, static fn(array $event): bool => ($event['refund_date'] ?? '') === $report_date)),
      ],
      'refund_date_bases' => [
        'square' => array_values(array_unique(array_column($square_refunds, 'refund_date_basis'))),
        'woocommerce' => array_values(array_unique(array_column($woo_refunds, 'refund_date_basis'))),
      ],
      'collection_date_bases' => ['square' => 'square_payment_created_at', 'woocommerce' => 'woocommerce_paid_at'],
    ];
  }
}
