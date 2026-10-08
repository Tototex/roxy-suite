<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

/** Request-local, read-only refund snapshot. No persistence or mail here. */
final class RefundSnapshot {
  private array $return_orders;
  private array $sources;
  private array $payment_refunds;

  /** PHP 8.0-compatible equivalent of array_is_list(). */
  private static function is_list(array $value): bool {
    $expected = 0;
    foreach ($value as $key => $_) if ($key !== $expected++) return false;
    return true;
  }

  private function __construct(array $return_orders, array $sources, array $payment_refunds = []) {
    $this->return_orders = $return_orders;
    $this->sources = $sources;
    $this->payment_refunds = $payment_refunds;
  }

  /** Create a read-only financial projection from the complete Square payment-refund feed. */
  public static function from_financial_refund_feed(array $refunds): self {
    if (!self::is_list($refunds)) throw new \RuntimeException('Square returned an invalid financial refund feed.');
    $indexed = [];
    foreach ($refunds as $refund) {
      if (!is_array($refund) || !is_string($refund['id'] ?? null) || $refund['id'] === ''
        || strlen($refund['id']) > 255 || isset($indexed[$refund['id']])
        || !is_string($refund['location_id'] ?? null) || $refund['location_id'] === ''
        || !in_array($refund['status'] ?? '', ['PENDING', 'COMPLETED', 'REJECTED', 'FAILED'], true)) {
        throw new \RuntimeException('Square returned a malformed or duplicate financial refund.');
      }
      $indexed[$refund['id']] = $refund;
    }
    return new self([], [], $indexed);
  }

  public static function load(string $earliest_sale_date, ?\DateTimeImmutable $now = null, ?float $deadline = null): self {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $earliest_sale_date, $timezone);
    if (!$start || $start->format('Y-m-d') !== $earliest_sale_date) throw new \RuntimeException('Invalid original sale date for refund reconciliation.');
    $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    if ($start >= $now) return new self([], []);

    $deadline = $deadline ?? microtime(true) + 120;
    return self::from_return_orders(Square::fetch_orders_updated_between($start->format('c'), $now->format('c'), $deadline, true), $now, $deadline);
  }

  /** Efficient historical discovery through refunds, not every ordinary sale. */
  public static function load_from_refund_feed(string $earliest_date, ?\DateTimeImmutable $now = null, ?float $deadline = null): self {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $earliest_date, $timezone);
    if (!$start || $start->format('Y-m-d') !== $earliest_date) throw new \RuntimeException('Invalid historical refund discovery date.');
    $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    if ($start >= $now) return new self([], []);
    $deadline = $deadline ?? microtime(true) + 120;
    $refunds = Square::list_payment_refunds_updated_between($start->format('c'), $now->format('c'), $deadline);
    $references = []; $cached = [];
    foreach ($refunds as $refund) {
      if (in_array($refund['status'], ['FAILED','REJECTED'], true)) continue;
      $id = $refund['order_id'] ?? null;
      if (!is_string($id) || $id === '') throw new \RuntimeException('Historical refund has no return-order reference. Review it manually.');
      $references[$id] = true;
      if (!is_string($refund['id'] ?? null) || $refund['id'] === '' || isset($cached[$refund['id']])) throw new \RuntimeException('Historical refund feed has an invalid or duplicate payment identity.');
      $cached[$refund['id']] = $refund;
    }
    if (count($references) > 100) throw new \RuntimeException('Historical refund discovery exceeds one batch. Use a smaller window before continuing.');
    $orders = Square::retrieve_orders(array_keys($references), $deadline);
    foreach ($orders as $order) {
      if (($order['state'] ?? '') !== 'COMPLETED' || empty($order['returns'])) throw new \RuntimeException('A payment refund has no completed itemized return order. Review it manually.');
      if (!is_array($order['refunds'] ?? null) || !self::is_list($order['refunds'])) throw new \RuntimeException('Historical return order has an invalid refund-reference list.');
      foreach ($cached as $refund) {
        if ($refund['order_id'] !== $order['id']) continue;
        $matched = false;
        foreach ($order['refunds'] ?? [] as $reference) {
          if (!is_array($reference) || !is_string($reference['tender_id'] ?? null) || !is_string($reference['id'] ?? null)) throw new \RuntimeException('Historical return order has a malformed refund identity.');
          if (($reference['tender_id'] ?? '') . '_' . ($reference['id'] ?? '') === $refund['id']) $matched = true;
        }
        if (!$matched) throw new \RuntimeException('Historical refund identity does not match its return-order evidence.');
      }
    }
    return self::from_return_orders($orders, $now, $deadline, $cached);
  }

  private static function from_return_orders(array $orders, \DateTimeImmutable $now, float $deadline, array $cached_refunds = []): self {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $returns = [];
    $source_ids = [];
    $statuses = [];
    $refund_records = [];
    $refund_owners = [];
    foreach ($orders as $order) {
      if (empty($order['returns'])) continue;
      if (count($returns) >= 100 || microtime(true) >= $deadline) throw new \RuntimeException('Square refund reconciliation exceeded its safety limit.');
      if (!is_array($order['returns']) || !self::is_list($order['returns'])) throw new \RuntimeException('Square returned an invalid return list. No report was calculated.');
      $verified = true;
      $payment_states = [];
      $references = $order['refunds'] ?? [];
      // Exchanges without a completed payment refund need their separate policy.
      if (!is_array($references) || !self::is_list($references) || !$references) $verified = false;
      else foreach ($references as $reference) {
        if (!is_array($reference) || !is_string($reference['tender_id'] ?? null) || !is_string($reference['id'] ?? null) || $reference['tender_id'] === '' || $reference['id'] === '') throw new \RuntimeException('Square refund has no verifiable payment reference.');
        $id = $reference['tender_id'] . '_' . $reference['id'];
        if (isset($refund_owners[$id]) && $refund_owners[$id] !== $order['id']) throw new \RuntimeException('A Square payment refund was repeated across different return orders.');
        $refund_owners[$id] = $order['id'];
        if (!isset($statuses[$id])) {
          $refund = $cached_refunds[$id] ?? Square::retrieve_payment_refund($id, $deadline);
          if (($refund['payment_id'] ?? null) !== $reference['tender_id']) throw new \RuntimeException('Square refund payment reference is missing or does not match its return order.');
          if (isset($refund['order_id']) && $refund['order_id'] !== $order['id']) throw new \RuntimeException('Square refund order reference does not match its return order.');
          if (($refund['id'] ?? null) !== $id) throw new \RuntimeException('Square returned a different payment-refund identity than requested.');
          $refund_records[$id] = $refund;
          $statuses[$id] = $refund['status'];
        }
        if ($statuses[$id] !== 'COMPLETED') $verified = false;
        $payment_states[] = $statuses[$id];
      }
      // Definitively failed/refused refunds never reduce reportable sales.
      if ($payment_states && !array_diff($payment_states, ['FAILED', 'REJECTED'])) continue;
      $order['_refund_verified'] = $verified;
      $order['_refund_pending'] = in_array('PENDING', $payment_states, true);
      foreach ($order['returns'] as $return) {
        if (!is_array($return)) throw new \RuntimeException('Square returned a malformed return.');
        $id = $return['source_order_id'] ?? null;
        if (is_string($id) && $id !== '') $source_ids[$id] = true;
      }
      $returns[] = $order;
    }
    $sources = [];
    foreach (Square::retrieve_orders(array_keys($source_ids), $deadline) as $sale) {
      if (($sale['state'] ?? '') !== 'COMPLETED') throw new \RuntimeException('A returned sale has no confirmed original sale day.');
      $closed = self::sale_timestamp($sale['closed_at'] ?? null);
      if ($closed > $now) throw new \RuntimeException('Square returned a source sale after the refund snapshot cutoff.');
      $date = $closed->setTimezone($timezone)->format('Y-m-d');
      $sale['source_date'] = $date;
      $sources[$sale['id']] = $sale;
    }
    return new self($returns, $sources, $refund_records);
  }

  /** Completed refunds as dated cash-out events; separate from sale-day ticket adjustments. */
  public function completed_financial_refunds(): array {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $events = [];
    foreach ($this->payment_refunds as $refund_id => $refund) {
      if (($refund['status'] ?? '') !== 'COMPLETED') continue;
      if (($refund['id'] ?? null) !== $refund_id
        || !is_string($refund['payment_id'] ?? null) || $refund['payment_id'] === ''
        || !is_string($refund['location_id'] ?? null) || $refund['location_id'] === '') {
        throw new \RuntimeException('A completed Square refund is missing a stable financial identity.');
      }
      $order_id = $refund['order_id'] ?? null;
      if ($order_id !== null && (!is_string($order_id) || $order_id === '')) throw new \RuntimeException('A completed Square refund has an invalid order identity.');
      $money = $refund['amount_money'] ?? null;
      if (!is_array($money) || !is_int($money['amount'] ?? null) || $money['amount'] < 0 || ($money['currency'] ?? null) !== 'USD') {
        throw new \RuntimeException('A completed Square refund has an invalid amount or unsupported currency.');
      }
      $completed_at = $refund['updated_at'] ?? null;
      if (!is_string($completed_at) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $completed_at)) {
        throw new \RuntimeException('A completed Square refund has no valid completion timestamp.');
      }
      try { $timestamp = new \DateTimeImmutable($completed_at); }
      catch (\Throwable $error) { throw new \RuntimeException('A completed Square refund has an invalid completion timestamp.'); }
      $errors = \DateTimeImmutable::getLastErrors();
      if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new \RuntimeException('A completed Square refund has an invalid completion calendar date.');
      $events[] = [
        'refund_id' => $refund_id,
        'payment_id' => $refund['payment_id'],
        'order_id' => $order_id,
        'location_id' => $refund['location_id'],
        'amount_cents' => $money['amount'],
        'currency' => 'USD',
        'refund_updated_at' => $timestamp->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'refund_date' => $timestamp->setTimezone($timezone)->format('Y-m-d'),
      ];
    }
    usort($events, static fn(array $a, array $b): int => [$a['refund_updated_at'], $a['refund_id']] <=> [$b['refund_updated_at'], $b['refund_id']]);
    return $events;
  }

  /** Backward-compatible name for return-linked callers. */
  public function completed_return_financial_refunds(): array {
    return $this->completed_financial_refunds();
  }

  /** Adjust quantities only. Never use these copied orders for cash arithmetic. */
  public function reconcile_sale_day(string $date, array $sales): array {
    $ids = [];
    foreach ($sales as $sale) {
      $source_date = self::sale_timestamp($sale['closed_at'] ?? null)->setTimezone(new \DateTimeZone(Settings::get_report_timezone()))->format('Y-m-d');
      if ($source_date !== $date) throw new \RuntimeException('Square returned a sale outside the original sale-day window.');
      if (is_string($sale['id'] ?? null)) $ids[$sale['id']] = true;
    }
    $relevant = [];
    $pending = [];
    foreach ($this->return_orders as $order) {
      $kept = [];
      foreach ($order['returns'] as $return) {
        $source_id = $return['source_order_id'] ?? '';
        // A missing source cannot be safely attributed to any sale date.
        if ($source_id === '' || isset($ids[$source_id]) || (($this->sources[$source_id]['source_date'] ?? '') === $date)) $kept[] = $return;
      }
      if ($kept) {
        if (!empty($order['_refund_pending'])) { $pending[] = $order['id']; continue; }
        $copy = $order; $copy['returns'] = $kept; $relevant[] = $copy;
      }
    }
    $result = Returns::reconcile($sales, $relevant);
    $result['pending'] = $pending;
    return $result;
  }

  public function original_sale_dates(): array {
    $dates = [];
    foreach ($this->sources as $sale) $dates[$sale['source_date']] = true;
    $dates = array_keys($dates);
    sort($dates);
    return $dates;
  }

  public function source_orders_for_date(string $date): array {
    return array_values(array_filter($this->sources, static fn($sale) => $sale['source_date'] === $date));
  }

  public function pending_source_dates(): array {
    $dates = [];
    foreach ($this->return_orders as $order) {
      if (empty($order['_refund_pending'])) continue;
      foreach ($order['returns'] as $return) {
        $date = $this->sources[$return['source_order_id'] ?? '']['source_date'] ?? null;
        if (!$date) throw new \RuntimeException('A pending refund has no original sale date for retry.');
        $dates[$date] = true;
      }
    }
    $dates = array_keys($dates);
    sort($dates);
    return $dates;
  }

  private static function sale_timestamp($value): \DateTimeImmutable {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value)) throw new \RuntimeException('A returned sale has an invalid original timestamp.');
    try { $timestamp = new \DateTimeImmutable($value); }
    catch (\Throwable $error) { throw new \RuntimeException('A returned sale has an invalid original timestamp.'); }
    $errors = \DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new \RuntimeException('A returned sale has an invalid original calendar date.');
    return $timestamp;
  }
}

/** Read-only normalization for WooCommerce refunds processed through a payment API. */
final class WooRefundEvents {
  private static function is_list(array $value): bool {
    $expected = 0;
    foreach ($value as $key => $_) if ($key !== $expected++) return false;
    return true;
  }

  private static function amount_cents($amount): int {
    if (!is_string($amount) && !is_int($amount)) throw new \RuntimeException('WooCommerce refund amount has an unsupported representation.');
    $value = (string) $amount;
    if (!preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d{1,2})?$/D', $value)) throw new \RuntimeException('WooCommerce refund amount is invalid or has fractional cents.');
    $negative = isset($value[0]) && $value[0] === '-';
    if ($negative) $value = substr($value, 1);
    $parts = explode('.', $value, 2);
    $whole = (int) $parts[0];
    $fraction = isset($parts[1]) ? (int) str_pad($parts[1], 2, '0') : 0;
    if ($whole > intdiv(PHP_INT_MAX - $fraction, 100)) throw new \RuntimeException('WooCommerce refund exceeds the supported amount range.');
    $cents = ($whole * 100) + $fraction;
    return $negative ? -$cents : $cents;
  }

  /** API-confirmed refund events; manual refund records are deliberately excluded. */
  public static function from_order_refunds(array $refunds): array {
    if (!self::is_list($refunds)) throw new \RuntimeException('WooCommerce returned an invalid refund list.');
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $events = [];
    $seen = [];
    foreach ($refunds as $refund) {
      if (!is_object($refund)
        || !method_exists($refund, 'get_id') || !method_exists($refund, 'get_parent_id')
        || !method_exists($refund, 'get_refunded_payment') || !method_exists($refund, 'get_currency')
        || !method_exists($refund, 'get_amount') || !method_exists($refund, 'get_date_created')) {
        throw new \RuntimeException('WooCommerce returned a refund without the required financial fields.');
      }
      $id = $refund->get_id();
      $order_id = $refund->get_parent_id();
      if (!is_int($id) || $id <= 0 || isset($seen[$id]) || !is_int($order_id) || $order_id <= 0) {
        throw new \RuntimeException('WooCommerce returned a duplicate or invalid refund identity.');
      }
      $seen[$id] = true;
      if ($refund->get_refunded_payment() !== true) continue;
      if ($refund->get_currency() !== 'USD') throw new \RuntimeException('WooCommerce refund uses an unsupported currency.');
      $amount_cents = abs(self::amount_cents($refund->get_amount()));
      $created = $refund->get_date_created();
      if (!$created instanceof \DateTimeInterface) throw new \RuntimeException('WooCommerce refund has no valid creation timestamp.');
      $timestamp = \DateTimeImmutable::createFromInterface($created)->setTimezone(new \DateTimeZone('UTC'));
      $events[] = [
        'source' => 'woocommerce',
        'refund_id' => $id,
        'order_id' => $order_id,
        'amount_cents' => $amount_cents,
        'currency' => 'USD',
        'refund_created_at' => $timestamp->format('Y-m-d H:i:s'),
        'refund_date' => $timestamp->setTimezone($timezone)->format('Y-m-d'),
        'payment_api_processed' => true,
      ];
    }
    usort($events, static fn(array $a, array $b): int => [$a['refund_created_at'], $a['refund_id']] <=> [$b['refund_created_at'], $b['refund_id']]);
    return $events;
  }
}

/** Read-only WooCommerce collection events for explicitly approved online gateways. */
final class WooCollectionEvents {
  private static function is_list(array $value): bool {
    $expected = 0;
    foreach ($value as $key => $_) if ($key !== $expected++) return false;
    return true;
  }

  private static function amount_cents($amount): int {
    if (!is_string($amount) && !is_int($amount)) throw new \RuntimeException('WooCommerce order amount has an unsupported representation.');
    $value = (string) $amount;
    if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/D', $value)) throw new \RuntimeException('WooCommerce order amount is invalid or has fractional cents.');
    $parts = explode('.', $value, 2);
    $whole = (int) $parts[0];
    $fraction = isset($parts[1]) ? (int) str_pad($parts[1], 2, '0') : 0;
    if ($whole > intdiv(PHP_INT_MAX - $fraction, 100)) throw new \RuntimeException('WooCommerce order exceeds the supported amount range.');
    return ($whole * 100) + $fraction;
  }

  /** Only paid orders from the caller's allow-list count; manual/offline gateways are excluded. */
  public static function from_orders(array $orders, array $allowed_gateways): array {
    if (!self::is_list($orders) || !self::is_list($allowed_gateways) || !$allowed_gateways) throw new \RuntimeException('WooCommerce collection inputs are invalid.');
    $gateways = [];
    foreach ($allowed_gateways as $gateway) {
      if (!is_string($gateway) || !preg_match('/^[a-z0-9_-]{1,80}$/D', $gateway) || isset($gateways[$gateway])) throw new \RuntimeException('WooCommerce collection gateway allow-list is invalid.');
      $gateways[$gateway] = true;
    }
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $events = [];
    $seen_orders = [];
    $seen_transactions = [];
    foreach ($orders as $order) {
      $methods = ['get_id','get_payment_method','is_paid','get_currency','get_total','get_date_paid','get_transaction_id'];
      if (!is_object($order)) throw new \RuntimeException('WooCommerce returned a malformed collection order.');
      foreach ($methods as $method) if (!method_exists($order, $method)) throw new \RuntimeException('WooCommerce collection order lacks required payment evidence.');
      $id = $order->get_id();
      if (!is_int($id) || $id <= 0 || isset($seen_orders[$id])) throw new \RuntimeException('WooCommerce returned a duplicate or invalid collection order ID.');
      $seen_orders[$id] = true;
      $gateway = $order->get_payment_method();
      if (!is_string($gateway) || $gateway === '') throw new \RuntimeException('WooCommerce collection order has an invalid gateway identity.');
      if (!isset($gateways[$gateway]) || $order->is_paid() !== true) continue;
      if ($order->get_currency() !== 'USD') throw new \RuntimeException('WooCommerce collection order uses an unsupported currency.');
      $amount_cents = self::amount_cents($order->get_total());
      if ($amount_cents === 0) continue;
      $paid_at = $order->get_date_paid();
      if (!$paid_at instanceof \DateTimeInterface) throw new \RuntimeException('Paid WooCommerce order has no paid timestamp.');
      $transaction_id = $order->get_transaction_id();
      if (!is_string($transaction_id) || trim($transaction_id) === '' || strlen($transaction_id) > 255) throw new \RuntimeException('Paid WooCommerce order has no stable transaction identity.');
      $transaction_key = $gateway . ':' . $transaction_id;
      if (isset($seen_transactions[$transaction_key])) throw new \RuntimeException('WooCommerce collection feed repeats a gateway transaction identity.');
      $seen_transactions[$transaction_key] = true;
      $timestamp = \DateTimeImmutable::createFromInterface($paid_at)->setTimezone(new \DateTimeZone('UTC'));
      $events[] = [
        'source' => 'woocommerce',
        'order_id' => $id,
        'gateway' => $gateway,
        'transaction_id' => $transaction_id,
        'amount_cents' => $amount_cents,
        'currency' => 'USD',
        'collected_at' => $timestamp->format('Y-m-d H:i:s'),
        'collection_date' => $timestamp->setTimezone($timezone)->format('Y-m-d'),
      ];
    }
    usort($events, static fn(array $a, array $b): int => [$a['collected_at'], $a['order_id']] <=> [$b['collected_at'], $b['order_id']]);
    return $events;
  }
}

/** Read-only normalization for fully paid Square Orders and tender deduplication references. */
final class SquareCollectionEvents {
  private static function is_list(array $value): bool {
    $expected = 0;
    foreach ($value as $key => $_) if ($key !== $expected++) return false;
    return true;
  }

  private static function timestamp(string $value): \DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
      throw new \RuntimeException('Square order has an invalid close timestamp.');
    }
    try { $timestamp = new \DateTimeImmutable($value); }
    catch (\Throwable $error) { throw new \RuntimeException('Square order has an invalid close timestamp.'); }
    $errors = \DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new \RuntimeException('Square order has an invalid close calendar date.');
    return $timestamp;
  }

  /** Completed orders only; tenders identify Square payments that may also appear in Woo. */
  public static function from_orders(array $orders): array {
    if (!self::is_list($orders)) throw new \RuntimeException('Square returned an invalid collection order list.');
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $events = [];
    $seen = [];
    $seen_payment_ids = [];
    foreach ($orders as $order) {
      if (!is_array($order) || !is_string($order['state'] ?? null)) throw new \RuntimeException('Square returned a malformed collection order.');
      if ($order['state'] !== 'COMPLETED') continue;
      $id = $order['id'] ?? null;
      $location_id = $order['location_id'] ?? null;
      if (!is_string($id) || $id === '' || strlen($id) > 192 || isset($seen[$id])
        || !is_string($location_id) || $location_id === '') {
        throw new \RuntimeException('Square returned a duplicate or invalid collection order identity.');
      }
      $seen[$id] = true;
      $money = $order['total_money'] ?? null;
      if (!is_array($money) || !is_int($money['amount'] ?? null) || $money['amount'] < 0 || ($money['currency'] ?? null) !== 'USD') {
        throw new \RuntimeException('Completed Square order has an invalid amount or unsupported currency.');
      }
      $closed_at = $order['closed_at'] ?? null;
      if (!is_string($closed_at)) throw new \RuntimeException('Completed Square order has no close timestamp.');
      $timestamp = self::timestamp($closed_at);
      $payment_ids = [];
      $tender_ids_complete = array_key_exists('tenders', $order) && is_array($order['tenders']) && self::is_list($order['tenders']);
      if (array_key_exists('tenders', $order) && (!is_array($order['tenders']) || !self::is_list($order['tenders']))) {
        throw new \RuntimeException('Completed Square order has a malformed tender list.');
      }
      foreach ($order['tenders'] ?? [] as $tender) {
        if (!is_array($tender)) throw new \RuntimeException('Completed Square order has a malformed tender.');
        $tender_id = $tender['id'] ?? null;
        $payment_id = $tender['payment_id'] ?? null;
        if ($tender_id !== null && (!is_string($tender_id) || $tender_id === '' || strlen($tender_id) > 192)) {
          throw new \RuntimeException('Completed Square order has an invalid tender identity.');
        }
        if ($payment_id !== null && (!is_string($payment_id) || $payment_id === '' || strlen($payment_id) > 192)) {
          throw new \RuntimeException('Completed Square order has an invalid tender payment identity.');
        }
        if ($tender_id !== null && $payment_id !== null && $tender_id !== $payment_id) {
          throw new \RuntimeException('Completed Square tender and payment identities do not match.');
        }
        $payment_id = $payment_id ?? $tender_id;
        if ($payment_id === null) { $tender_ids_complete = false; continue; }
        if (isset($payment_ids[$payment_id]) || isset($seen_payment_ids[$payment_id])) throw new \RuntimeException('Square collection feed repeats a tender payment identity.');
        $payment_ids[$payment_id] = true;
        $seen_payment_ids[$payment_id] = true;
      }
      $events[] = [
        'source' => 'square',
        'order_id' => $id,
        'location_id' => $location_id,
        'amount_cents' => $money['amount'],
        'currency' => 'USD',
        'collected_at' => $timestamp->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'collection_date' => $timestamp->setTimezone($timezone)->format('Y-m-d'),
        'payment_ids' => array_keys($payment_ids),
        'tender_ids_complete' => $tender_ids_complete,
      ];
    }
    usort($events, static fn(array $a, array $b): int => [$a['collected_at'], $a['order_id']] <=> [$b['collected_at'], $b['order_id']]);
    return $events;
  }
}

/** Pure daily aggregation of separately sourced collection and refund projections. */
final class CashflowProjection {
  private static function is_list(array $value): bool {
    $expected = 0;
    foreach ($value as $key => $_) if ($key !== $expected++) return false;
    return true;
  }

  private static function add_event(array &$days, array &$seen, $event, string $source, string $id_field, string $date_field, string $amount_field, string $currency_field, string $total_field): void {
    if (!is_array($event) || ($event['source'] ?? null) !== $source) throw new \RuntimeException('Cashflow event has an invalid source or structure.');
    $id = $event[$id_field] ?? null;
    if ((!is_string($id) && !is_int($id)) || (is_string($id) && ($id === '' || strlen($id) > 255)) || (is_int($id) && $id <= 0)) {
      throw new \RuntimeException('Cashflow event has an invalid identity.');
    }
    $identity = gettype($id) . ':' . (string) $id;
    if (isset($seen[$identity])) throw new \RuntimeException('Cashflow feed repeats an event identity.');
    $seen[$identity] = true;
    $date = $event[$date_field] ?? null;
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) throw new \RuntimeException('Cashflow event has an invalid date.');
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
    $errors = \DateTimeImmutable::getLastErrors();
    if (!$parsed || $parsed->format('Y-m-d') !== $date || ($errors && ($errors['warning_count'] || $errors['error_count']))) throw new \RuntimeException('Cashflow event has an invalid calendar date.');
    if (($event[$currency_field] ?? null) !== 'USD' || !is_int($event[$amount_field] ?? null) || $event[$amount_field] < 0) {
      throw new \RuntimeException('Cashflow event has invalid USD cents.');
    }
    if (!isset($days[$date])) $days[$date] = [
      'square_collected_cents' => 0, 'woocommerce_collected_cents' => 0,
      'square_refunded_cents' => 0, 'woocommerce_refunded_cents' => 0,
      'total_collected_cents' => 0, 'total_refunded_cents' => 0, 'net_cents' => 0,
    ];
    $current = $days[$date][$total_field];
    if ($event[$amount_field] > PHP_INT_MAX - $current) throw new \RuntimeException('Daily cashflow total exceeds the supported amount range.');
    $days[$date][$total_field] += $event[$amount_field];
  }

  /**
   * @return array<string,array<string,int>> One row per Pacific calendar date.
   */
  public static function daily_totals(array $square_collections, array $woo_collections, array $square_refunds, array $woo_refunds): array {
    foreach ([$square_collections, $woo_collections, $square_refunds, $woo_refunds] as $events) {
      if (!self::is_list($events)) throw new \RuntimeException('Cashflow event feeds must be indexed lists.');
    }
    $days = [];
    $feed_specs = [
      [$square_collections, 'square', 'order_id', 'collection_date', 'amount_cents', 'currency', 'square_collected_cents'],
      [$woo_collections, 'woocommerce', 'order_id', 'collection_date', 'amount_cents', 'currency', 'woocommerce_collected_cents'],
      [$square_refunds, 'square', 'refund_id', 'refund_date', 'amount_cents', 'currency', 'square_refunded_cents'],
      [$woo_refunds, 'woocommerce', 'refund_id', 'refund_date', 'amount_cents', 'currency', 'woocommerce_refunded_cents'],
    ];
    foreach ($feed_specs as [$events, $source, $id_field, $date_field, $amount_field, $currency_field, $total_field]) {
      $seen = [];
      foreach ($events as $event) self::add_event($days, $seen, $event, $source, $id_field, $date_field, $amount_field, $currency_field, $total_field);
    }
    ksort($days, SORT_STRING);
    foreach ($days as &$day) {
      if ($day['woocommerce_collected_cents'] > PHP_INT_MAX - $day['square_collected_cents'] || $day['woocommerce_refunded_cents'] > PHP_INT_MAX - $day['square_refunded_cents']) {
        throw new \RuntimeException('Combined daily cashflow total exceeds the supported amount range.');
      }
      $day['total_collected_cents'] = $day['square_collected_cents'] + $day['woocommerce_collected_cents'];
      $day['total_refunded_cents'] = $day['square_refunded_cents'] + $day['woocommerce_refunded_cents'];
      $day['net_cents'] = $day['total_collected_cents'] - $day['total_refunded_cents'];
    }
    unset($day);
    return $days;
  }
}
