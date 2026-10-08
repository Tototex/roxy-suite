<?php
// Run via wp eval-file before deployment. Read-only: no admission/order writes.
if (!defined('WP_CLI') || !WP_CLI) exit('WP-CLI only');
$suite_stage = $args[0] ?? dirname(__DIR__);
$source = file_get_contents($suite_stage . '/includes/modules/show-tickets/includes/class-roxy-st-tickets.php');
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$source = str_replace('class Tickets {', 'class StabilityTickets {', $source);
eval($source);
$can = new ReflectionMethod(\RoxyST\StabilityTickets::class, 'can_check_in'); $can->setAccessible(true);
$tickets = get_posts(['post_type'=>\RoxyST\Tickets::POST_TYPE,'post_status'=>'publish','numberposts'=>50,'fields'=>'ids','meta_key'=>\RoxyST\Tickets::META_STATE,'meta_value'=>'valid']);
$counts = ['sample'=>count($tickets),'paid_allowed'=>0,'unpaid_blocked'=>0,'missing_or_refunded_blocked'=>0,'paid_unexpected_block'=>0];
foreach ($tickets as $id) {
    $order = wc_get_order((int) get_post_meta($id,\RoxyST\Tickets::META_ORDER_ID,true));
    $item_id = (int) get_post_meta($id,\RoxyST\Tickets::META_ORDER_ITEM_ID,true);
    $item = $order ? $order->get_item($item_id) : false;
    $allowed = $can->invoke(null,(int)$id);
    $checked = (int) get_post_meta($id,\RoxyST\Tickets::META_CHECKED_IN,true) === 1;
    if (!$order || !$item || abs((float)$order->get_qty_refunded_for_item($item_id)) > 0 || $checked) {
        $counts['missing_or_refunded_blocked'] += !$allowed ? 1 : 0;
    } elseif (!in_array($order->get_status(),['processing','completed'],true)) {
        if ($allowed) throw new RuntimeException('Unpaid sample admitted');
        $counts['unpaid_blocked']++;
    } elseif ($allowed) $counts['paid_allowed']++;
    else $counts['paid_unexpected_block']++;
}
echo wp_json_encode($counts) . "\n";
if ($counts['paid_unexpected_block']) throw new RuntimeException('Review paid tickets lacking issuance identity before deployment');
