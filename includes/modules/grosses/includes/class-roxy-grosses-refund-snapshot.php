<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

/** Request-local, read-only refund snapshot. No persistence or mail here. */
final class RefundSnapshot {
  private array $return_orders;
  private array $sources;

  private function __construct(array $return_orders, array $sources) {
    $this->return_orders = $return_orders;
    $this->sources = $sources;
  }

  public static function load(string $earliest_sale_date, ?\DateTimeImmutable $now = null, ?float $deadline = null): self {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $earliest_sale_date, $timezone);
    if (!$start || $start->format('Y-m-d') !== $earliest_sale_date) throw new \RuntimeException('Invalid original sale date for refund reconciliation.');
    $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    if ($start >= $now) return new self([], []);

    $returns = [];
    $source_ids = [];
    $statuses = [];
    $refund_owners = [];
    $deadline = $deadline ?? microtime(true) + 120;
    foreach (Square::fetch_orders_updated_between($start->format('c'), $now->format('c'), $deadline, true) as $order) {
      if (empty($order['returns'])) continue;
      if (count($returns) >= 100 || microtime(true) >= $deadline) throw new \RuntimeException('Square refund reconciliation exceeded its safety limit.');
      if (!is_array($order['returns']) || !array_is_list($order['returns'])) throw new \RuntimeException('Square returned an invalid return list. No report was calculated.');
      $verified = true;
      $payment_states = [];
      $references = $order['refunds'] ?? [];
      // Exchanges without a completed payment refund need their separate policy.
      if (!is_array($references) || !array_is_list($references) || !$references) $verified = false;
      else foreach ($references as $reference) {
        if (!is_array($reference) || !is_string($reference['tender_id'] ?? null) || !is_string($reference['id'] ?? null) || $reference['tender_id'] === '' || $reference['id'] === '') throw new \RuntimeException('Square refund has no verifiable payment reference.');
        $id = $reference['tender_id'] . '_' . $reference['id'];
        if (isset($refund_owners[$id]) && $refund_owners[$id] !== $order['id']) throw new \RuntimeException('A Square payment refund was repeated across different return orders.');
        $refund_owners[$id] = $order['id'];
        if (!isset($statuses[$id])) {
          $refund = Square::retrieve_payment_refund($id, $deadline);
          if (($refund['payment_id'] ?? null) !== $reference['tender_id']) throw new \RuntimeException('Square refund payment reference is missing or does not match its return order.');
          if (isset($refund['order_id']) && $refund['order_id'] !== $order['id']) throw new \RuntimeException('Square refund order reference does not match its return order.');
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
    return new self($returns, $sources);
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
