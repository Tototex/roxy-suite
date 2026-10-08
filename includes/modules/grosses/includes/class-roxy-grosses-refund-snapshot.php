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
