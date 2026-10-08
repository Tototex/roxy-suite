<?php
// Isolated payment/refund/admission checks, with no WP/database/email load.
define('ABSPATH', __DIR__);
$root = $argv[1] ?? dirname(__DIR__);
require __DIR__.'/ticket-atomic-test-double.php';
function check($condition, $label) { if (!$condition) throw new RuntimeException($label); echo "PASS: $label\n"; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key]); return true; }
function get_post_type($id) { return 'roxy_ticket'; }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
function delete_transient($key) {}
class TestItem { public function get_meta($key, $single) { return [101,102,103]; } }
class TestOrder {
    public $status = 'processing'; public $refunded = 0;
    public function get_status() { return $this->status; }
    public function get_qty_refunded_for_item($id) { return -$this->refunded; }
    public function get_item($id) { return $id === 10 ? new TestItem() : false; }
}
require $root . '/includes/modules/show-tickets/includes/class-roxy-st-tickets.php';
$state = new ReflectionMethod(\RoxyST\Tickets::class, 'state_for_order_status'); $state->setAccessible(true);
foreach (['pending','on-hold','checkout-draft','failed','cancelled','refunded','unknown'] as $status) check($state->invoke(null,$status) !== 'valid', 'T2: ' . $status . ' is not admissible');
foreach (['processing','completed'] as $status) check($state->invoke(null,$status) === 'valid', 'T2: confirmed ' . $status . ' including $0 remains admissible');
$order = new TestOrder(); $GLOBALS['orders'][1] = $order;
foreach ([101,102,103] as $id) $GLOBALS['meta'][$id] = ['_roxy_ticket_order_id'=>1,'_roxy_ticket_order_item_id'=>10,'_roxy_ticket_state'=>'valid'];
$can = new ReflectionMethod(\RoxyST\Tickets::class, 'can_check_in'); $can->setAccessible(true);
check($can->invoke(null,101), 'T2: valid paid ticket can be admitted');
$order->status = 'on-hold'; check(!$can->invoke(null,101), 'T2: stale valid ticket cannot admit unpaid order');
$order->status = 'processing'; $GLOBALS['meta'][101]['_roxy_ticket_order_id'] = 999;
check(!$can->invoke(null,101), 'T2: missing order fails closed');
$GLOBALS['meta'][101]['_roxy_ticket_order_id'] = 1;
$sync = new ReflectionMethod(\RoxyST\Tickets::class, 'sync_item_refund_allocation'); $sync->setAccessible(true);
$order->refunded = 1;
check(!$can->invoke(null,103), 'T3: historical refund protected before resync');
$sync->invoke(null,$order,10,[101,102,103],'valid');
check(get_post_meta(103,'_roxy_ticket_state') === 'refunded' && $can->invoke(null,101) && $can->invoke(null,102), 'T3: refund one of three leaves two admissible');
$sync->invoke(null,$order,10,[101,102,103],'valid');
check(get_post_meta(103,'_roxy_ticket_state') === 'refunded', 'T3: repeated paid sync cannot revive refund');
$order->refunded = 2; $sync->invoke(null,$order,10,[101,102,103],'valid');
check(!$can->invoke(null,102) && !$can->invoke(null,103) && $can->invoke(null,101), 'T3: cumulative second refund leaves one admissible');
$GLOBALS['meta'][103]['_roxy_checked_in'] = 1;
\RoxyST\Tickets::undo_check_in_ticket(103);
check(get_post_meta(103,'_roxy_ticket_state') === 'refunded' && !$can->invoke(null,103), 'T3: undo does not revive refunded used ticket');
$order->refunded = 0; $sync->invoke(null,$order,10,[101,102,103],'valid');
check($can->invoke(null,102) && $can->invoke(null,103), 'T3: explicit refund removal reconciles allocation on sync');
$GLOBALS['meta'][101]['_roxy_ticket_order_item_id'] = 999;
check(!$can->invoke(null,101), 'T2: removed order item cannot be admitted');
echo "NOTE: concurrency, will-call bypass and historical reporting are separate open findings.\n";
