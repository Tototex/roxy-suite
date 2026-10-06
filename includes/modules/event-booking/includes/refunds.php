<?php
if (!defined('ABSPATH')) exit;

/** Use actual discounted order-line totals/taxes, never the original booking quote. */
function roxy_eb_booking_refund_plan($order, int $booking_id, int $item_id = 0) {
    if (!$order || !is_a($order, 'WC_Order')) return new WP_Error('invalid_order', 'Invalid WooCommerce order.');
    $items = $order->get_items('line_item');
    $selected = [];
    $legacy = [];
    foreach ($items as $id => $item) {
        $linked = (int) $item->get_meta('_roxy_eb_booking_id', true);
        $adjustment = json_decode((string) $item->get_meta('_roxy_eb_booking_adjustment', true), true);
        $raw = $item->get_meta('_roxy_eb_booking', true) ?: $item->get_meta('Roxy Booking Data', true);
        $payload = json_decode((string) $raw, true);
        if ($item_id > 0) {
            if ((int) $id === $item_id && is_array($payload) && !$adjustment && ($booking_id === 0 || $linked === $booking_id)) $selected[$id] = $item;
            continue;
        }
        if ($booking_id > 0 && ($linked === $booking_id || (is_array($adjustment) && (int) ($adjustment['booking_id'] ?? 0) === $booking_id))) {
            $selected[$id] = $item;
        } elseif ($linked === 0 && !is_array($adjustment) && is_array($payload)) {
            $legacy[$id] = $item;
        }
    }
    if (!$selected && $item_id === 0 && $booking_id > 0 && count($legacy) === 1) {
        $booking = roxy_eb_repo_get_booking($booking_id);
        if ($booking && (int) ($booking['woo_order_id'] ?? 0) === (int) $order->get_id()) $selected = $legacy;
    }
    if (!$selected) return new WP_Error('refund_ownership', 'Cannot safely identify this booking\'s order items. A manager must review the refund.');

    // An amount-only legacy refund cannot be allocated safely to a mixed cart.
    $precision = wc_get_price_decimals();
    $unit = pow(10, -$precision);
    foreach ($order->get_refunds() as $refund) {
        $allocated = 0.0;
        foreach ($refund->get_items(['line_item', 'fee', 'shipping']) as $refunded_item) {
            $allocated += -(float) $refunded_item->get_total() - (float) $refunded_item->get_total_tax();
        }
        if (abs(round($allocated, $precision) - round((float) $refund->get_amount(), $precision)) >= $unit / 2) {
            return new WP_Error('refund_unallocated', 'A previous refund is not allocated to order items. A manager must review the remaining booking balance.');
        }
    }
    $amount = 0.0;
    $lines = [];
    foreach ($selected as $id => $item) {
        $total = round(max(0.0, (float) $item->get_total() - (float) $order->get_total_refunded_for_item((int) $id)), $precision);
        $tax = [];
        foreach (($item->get_taxes()['total'] ?? []) as $rate_id => $original_tax) {
            $tax[$rate_id] = round(max(0.0, (float) $original_tax - (float) $order->get_tax_refunded_for_item((int) $id, (int) $rate_id)), $precision);
        }
        $line_amount = $total + array_sum($tax);
        if ($line_amount <= 0) continue;
        $lines[$id] = ['qty' => max(0, (float) $item->get_quantity() + (float) $order->get_qty_refunded_for_item((int) $id)), 'refund_total' => $total, 'refund_tax' => $tax];
        $amount += $line_amount;
    }
    $amount = round($amount, $precision);
    $remaining = round(max(0.0, (float) $order->get_total() - (float) $order->get_total_refunded()), $precision);
    if ($amount > $remaining + $unit / 2) return new WP_Error('refund_balance', 'Booking item balance exceeds the remaining order balance. A manager must reconcile it.');
    return ['amount' => $amount, 'line_items' => $lines];
}

/** A connection-owned lock and durable pre-payment marker prohibit blind retries. */
function roxy_eb_refund_booking_payment($order, int $booking_id, int $item_id = 0, string $reason = 'Roxy booking cancelled') {
    global $wpdb;
    if (!$order || !is_a($order, 'WC_Order')) return new WP_Error('invalid_order', 'Invalid WooCommerce order.');
    $order_id = (int) $order->get_id();
    $key = 'roxy-eb-refund-' . substr(hash('sha256', $wpdb->prefix . ':' . $order_id), 0, 40);
    $owner = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
    if (!$owner || (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $key)) !== '1') return new WP_Error('refund_busy', 'Another refund is in progress. Please try again later.');
    $owns = static function () use ($wpdb, $key, $owner): bool {
        return (int) $wpdb->get_var('SELECT CONNECTION_ID()') === $owner && (int) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $key)) === $owner;
    };
    try {
        if (!$owns()) return new WP_Error('refund_lock_lost', 'Refund ownership was lost. A manager must review the order.');
        $order = wc_get_order($order_id);
        if (!$order) return new WP_Error('invalid_order', 'Order is unavailable.');
        $marker_key = '_roxy_eb_refund_claim_' . ($item_id > 0 ? 'item_' . $item_id : 'booking_' . $booking_id);
        $claim = $order->get_meta($marker_key, true);
        if (is_array($claim) && ($claim['state'] ?? '') !== 'complete') return new WP_Error('refund_review', 'A previous refund attempt needs manager review before another attempt.');
        // Unpaid invoices/orders have no captured payment to return.
        if (!$order->is_paid() && !$order->get_date_paid() && !$order->has_status('refunded')) return ['refunded' => false, 'amount' => 0.0, 'refund' => null];
        $plan = roxy_eb_booking_refund_plan($order, $booking_id, $item_id);
        if (is_wp_error($plan)) return $plan;
        if ($plan['amount'] <= 0) return ['refunded' => false, 'amount' => 0.0, 'refund' => null];
        if (is_array($claim) && ($claim['state'] ?? '') === 'complete') return new WP_Error('refund_review', 'A completed refund no longer matches the remaining balance. A manager must reconcile it.');
        $claim = ['state' => 'pending_review', 'amount' => $plan['amount'], 'line_items' => $plan['line_items'], 'started_at' => gmdate('c')];
        $order->update_meta_data($marker_key, $claim);
        $order->save_meta_data();
        $order->read_meta_data(true);
        if ($order->get_meta($marker_key, true) !== $claim || !$owns()) return new WP_Error('refund_claim_failed', 'Cannot safely record refund ownership. No new gateway request was made.');
        try {
            $refund = wc_create_refund(['amount' => $plan['amount'], 'reason' => $reason, 'order_id' => $order_id, 'refund_payment' => true, 'restock_items' => false, 'line_items' => $plan['line_items']]);
            if (is_wp_error($refund)) return $refund;
            if (!$refund || !is_a($refund, 'WC_Order_Refund') || (int) $refund->get_id() <= 0 || !$refund->get_refunded_payment()) return new WP_Error('refund_unknown', 'The gateway refund outcome is uncertain. A manager must reconcile it before retrying.');
            if (!$owns()) return new WP_Error('refund_lock_lost', 'The refund may have completed, but database ownership was lost. A manager must reconcile it.');
            $claim['state'] = 'complete';
            $claim['refund_id'] = (int) $refund->get_id();
            $order->update_meta_data($marker_key, $claim);
            $order->save_meta_data();
            $order->read_meta_data(true);
            if ($order->get_meta($marker_key, true) !== $claim) return new WP_Error('refund_record_failed', 'Refund returned success, but completion could not be recorded. Do not retry before manager review.');
            return ['refunded' => true, 'amount' => $plan['amount'], 'refund' => $refund];
        } catch (Throwable $error) {
            return new WP_Error('refund_exception', 'Refund failed or has an uncertain outcome. A manager must review it before retrying.');
        }
    } catch (Throwable $error) {
        return new WP_Error('refund_storage', 'Refund storage is unavailable. A manager must review the order.');
    } finally {
        if ($owns()) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
    }
}
