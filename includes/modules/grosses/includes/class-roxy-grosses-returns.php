<?php
namespace RoxyGrosses;

/** Pure, fail-closed reconciliation of verified Square return quantities. */
final class Returns {
  public static function reconcile(array $sales, array $return_orders): array {
    $orders = $sales;
    $issues = [];
    $bad_sources = [];
    $sales_by_id = [];

    foreach ($sales as $index => $sale) {
      $id = self::id($sale['id'] ?? null);
      if ($id !== '') $sales_by_id[$id][] = $index;
    }

    $seen_returns = [];
    $refund_totals = [];
    $line_indexes = [];

    foreach ($return_orders as $return_order) {
      $return_order_id = is_array($return_order) ? self::id($return_order['id'] ?? null) : '';
      if ($return_order_id === '') {
        $unidentified_returns = is_array($return_order) && is_array($return_order['returns'] ?? null) ? $return_order['returns'] : [];
        if (!$unidentified_returns) self::issue($issues, $bad_sources, '', '', 'missing_return_order_id');
        foreach ($unidentified_returns as $unidentified_return) {
          $source_id = is_array($unidentified_return) ? self::id($unidentified_return['source_order_id'] ?? null) : '';
          self::issue($issues, $bad_sources, '', $source_id, 'missing_return_order_id');
        }
        continue;
      }

      $returns = $return_order['returns'] ?? [];
      if (!is_array($returns)) {
        self::issue($issues, $bad_sources, $return_order_id, '', 'malformed_returns');
        continue;
      }
      if (!$returns) continue;

      $provider_verified = ($return_order['_refund_verified'] ?? false) === true;
      $completed = strtoupper(trim((string) ($return_order['state'] ?? ''))) === 'COMPLETED';
      foreach ($returns as $return) {
        if (!is_array($return)) {
          self::issue($issues, $bad_sources, $return_order_id, '', 'malformed_return');
          continue;
        }

        $source_order_id = self::id($return['source_order_id'] ?? null);
        if (!$provider_verified || !$completed) {
          self::issue(
            $issues,
            $bad_sources,
            $return_order_id,
            $source_order_id,
            !$provider_verified ? 'refund_unverified' : 'return_not_completed'
          );
          continue;
        }

        $return_items = $return['return_line_items'] ?? null;
        if (!is_array($return_items) || !$return_items) {
          self::issue($issues, $bad_sources, $return_order_id, $source_order_id, $source_order_id === '' ? 'missing_source_order_id' : 'missing_return_line_items');
          continue;
        }
        foreach ($return_items as $return_item) {
          if (!is_array($return_item)) {
            self::issue($issues, $bad_sources, $return_order_id, $source_order_id, 'malformed_return_line_item');
            continue;
          }

          $return_item_uid = self::id($return_item['uid'] ?? null);
          $source_line_uid = self::id($return_item['source_line_item_uid'] ?? null);
          $raw_quantity = $return_item['quantity'] ?? null;
          $item_type = strtoupper(trim((string) ($return_item['item_type'] ?? '')));
          if ($return_item_uid === '') {
            self::issue($issues, $bad_sources, $return_order_id, $source_order_id, 'missing_return_line_item_uid');
            continue;
          }

          $identity = $return_order_id . "\0" . $return_item_uid;
          $fingerprint = serialize([$source_order_id, $source_line_uid, $raw_quantity, $item_type]);
          if (isset($seen_returns[$identity])) {
            if ($seen_returns[$identity]['fingerprint'] !== $fingerprint) {
              self::issue($issues, $bad_sources, $return_order_id, $source_order_id, 'conflicting_duplicate_return_item');
              $prior_source = $seen_returns[$identity]['source_order_id'];
              if ($prior_source !== $source_order_id) {
                self::issue($issues, $bad_sources, $return_order_id, $prior_source, 'conflicting_duplicate_return_item');
              }
            }
            continue;
          }
          $seen_returns[$identity] = ['fingerprint' => $fingerprint, 'source_order_id' => $source_order_id];

          if ($source_order_id === '') {
            self::issue($issues, $bad_sources, $return_order_id, '', 'missing_source_order_id');
            continue;
          }
          if (!isset($sales_by_id[$source_order_id])) {
            self::issue($issues, $bad_sources, $return_order_id, $source_order_id, 'unknown_source_order');
            continue;
          }
          if (count($sales_by_id[$source_order_id]) !== 1) {
            self::issue($issues, $bad_sources, $return_order_id, $source_order_id, 'ambiguous_source_order');
            continue;
          }
          $sale_index = $sales_by_id[$source_order_id][0];

          if ($item_type !== 'ITEM') {
            self::issue($issues, $bad_sources, $return_order_id, $source_order_id, $item_type === 'CUSTOM_AMOUNT' ? 'custom_amount_return' : 'unsupported_return_item_type');
            continue;
          }
          if ($source_line_uid === '') {
            self::issue($issues, $bad_sources, $return_order_id, $source_order_id, 'missing_source_line_item_uid');
            continue;
          }
          $quantity = self::positive_integer($raw_quantity);
          if ($quantity === null) {
            self::issue($issues, $bad_sources, $return_order_id, $source_order_id, 'invalid_return_quantity');
            continue;
          }

          if (!isset($line_indexes[$sale_index])) {
            $line_indexes[$sale_index] = [];
            foreach ((array) ($sales[$sale_index]['line_items'] ?? []) as $line_index => $sale_line) {
              if (!is_array($sale_line)) continue;
              $sale_uid = self::id($sale_line['uid'] ?? null);
              if ($sale_uid !== '') $line_indexes[$sale_index][$sale_uid][] = $line_index;
            }
          }
          $matches = $line_indexes[$sale_index][$source_line_uid] ?? [];
          if (!$matches) {
            self::issue($issues, $bad_sources, $return_order_id, $source_order_id, 'unknown_source_line_item_uid');
            continue;
          }
          if (count($matches) !== 1) {
            self::issue($issues, $bad_sources, $return_order_id, $source_order_id, 'ambiguous_source_line_item_uid');
            continue;
          }

          $line_index = $matches[0];
          $sale_line = $sales[$sale_index]['line_items'][$line_index];
          if (strtoupper(trim((string) ($sale_line['item_type'] ?? ''))) === 'CUSTOM_AMOUNT') {
            self::issue($issues, $bad_sources, $return_order_id, $source_order_id, 'custom_amount_sale_line');
            continue;
          }
          $sold_quantity = self::positive_integer($sale_line['quantity'] ?? null);
          if ($sold_quantity === null) {
            self::issue($issues, $bad_sources, $return_order_id, $source_order_id, 'invalid_source_quantity');
            continue;
          }

          $key = $source_order_id . "\0" . $source_line_uid;
          $prior_total = $refund_totals[$key]['quantity'] ?? 0;
          if ($prior_total > PHP_INT_MAX - $quantity) {
            self::issue($issues, $bad_sources, $return_order_id, $source_order_id, 'return_quantity_overflow');
            continue;
          }
          $refund_totals[$key] = [
            'sale_index' => $sale_index,
            'line_index' => $line_index,
            'quantity' => $prior_total + $quantity,
            'sold_quantity' => $sold_quantity,
            'source_order_id' => $source_order_id,
            'source_line_item_uid' => $source_line_uid,
          ];
        }
      }
    }

    foreach ($refund_totals as $refund) {
      if ($refund['quantity'] > $refund['sold_quantity']) {
        self::issue($issues, $bad_sources, '', $refund['source_order_id'], 'over_refund');
      }
    }

    $adjustments = [];
    foreach ($refund_totals as $refund) {
      $source_order_id = $refund['source_order_id'];
      if (isset($bad_sources[$source_order_id])) continue;

      $sale_index = $refund['sale_index'];
      $line_index = $refund['line_index'];
      $orders[$sale_index]['line_items'][$line_index]['quantity'] = (string) ($refund['sold_quantity'] - $refund['quantity']);
      $adjustment = [
        'source_order_id' => $source_order_id,
        'source_line_item_uid' => $refund['source_line_item_uid'],
        'quantity' => $refund['quantity'],
      ];
      if (isset($sales[$sale_index]['source_date']) && is_string($sales[$sale_index]['source_date'])) {
        $adjustment['source_date'] = $sales[$sale_index]['source_date'];
      }
      $adjustments[] = $adjustment;
    }

    return ['orders' => $orders, 'adjustments' => $adjustments, 'issues' => $issues];
  }

  private static function id($value): string {
    return is_string($value) ? trim($value) : '';
  }

  private static function positive_integer($value): ?int {
    if (!is_string($value) && !is_int($value)) return null;
    $text = (string) $value;
    if (!preg_match('/^\+?([0-9]+)(?:\.([0-9]+))?$/D', $text, $matches)) return null;
    if (isset($matches[2]) && trim($matches[2], '0') !== '') return null;
    $digits = ltrim($matches[1], '0');
    if ($digits === '') return null;
    $maximum = (string) PHP_INT_MAX;
    if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) return null;
    $quantity = (int) $digits;
    return $quantity > 0 ? $quantity : null;
  }

  private static function issue(array &$issues, array &$bad_sources, string $return_order_id, string $source_order_id, string $reason): void {
    $issues[] = [
      'return_order_id' => $return_order_id,
      'source_order_id' => $source_order_id,
      'reason' => $reason,
    ];
    if ($source_order_id !== '') $bad_sources[$source_order_id] = true;
  }
}
