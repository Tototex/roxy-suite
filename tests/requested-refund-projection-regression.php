<?php
define('ABSPATH', __DIR__);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
$GLOBALS['refund_projection_order'] = null;
$GLOBALS['refund_projection_types'] = [];
function wc_get_order($id) { return $GLOBALS['refund_projection_order']; }
function get_post_meta($id, $key, $single = true): string { return $GLOBALS['refund_projection_types'][$id] ?? ''; }
function check_refund_projection(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
final class RefundProjectionItem {
    public function __construct(private int $id, private string $name, private float $qty, private string $role = '', private float $total = 0.0) {}
    public function get_id(): int { return $this->id; }
    public function get_name(): string { return $this->name; }
    public function get_quantity(): float { return $this->qty; }
    public function get_meta($key, $single = true): string { return $key === '_roxy_rs_refund_role' ? $this->role : ''; }
    public function get_product_id(): int { return $this->id; }
    public function get_total(): float { return $this->total; }
}
class RefundProjectionOrder {
    public function __construct(public int $id, private float $total, private float $refunded, private array $lines, private array $fees, private array $qty_refunds = [], private array $amount_refunds = []) {}
    public function get_id(): int { return $this->id; }
    public function get_meta($key, $single = true): int { return ['_roxy_rs_request_id'=>41,'_roxy_rs_backing_id'=>7][$key] ?? 0; }
    public function get_total(): float { return $this->total; }
    public function get_total_refunded(): float { return $this->refunded; }
    public function get_items($type = 'line_item'): array { return $type === 'fee' ? $this->fees : ($type === 'line_item' ? $this->lines : []); }
    public function get_qty_refunded_for_item($id): float { return $this->qty_refunds[$id] ?? 0.0; }
    public function get_total_refunded_for_item($id): float { return $this->amount_refunds[$id] ?? 0.0; }
}
final class RefundProjectionDatabase {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public function prepare(string $query, ...$args): string { return $query; }
    public function get_results(string $query, $output = null): array { return [$GLOBALS['refund_projection_backing_row']]; }
}
require dirname(__DIR__) . '/includes/modules/requested-showings/includes/repository.php';
require dirname(__DIR__) . '/includes/modules/requested-showings/includes/class-roxy-rs-agreement.php';
$backing = ['id'=>7,'request_id'=>41,'status'=>'charged','woo_order_id'=>88,'general_qty'=>3,'discount_qty'=>2,'support_qty'=>5,'subscriber_qty'=>1,'sponsor_ticket_qty'=>2,'charge_total'=>6000,'sponsor_amount'=>2000];
$quote=\RoxyRS\Agreement::quote(41,'movie_evening',['general'=>1000,'discount'=>500,'matinee'=>0],'USD');
$backing['agreement_json']=\RoxyRS\Agreement::build($quote,$backing);
$paid_general = new RefundProjectionItem(11,'General',3,'paid_general',30.0);
$paid_discount = new RefundProjectionItem(12,'Discount',2,'paid_discount',10.0);
$subscriber = new RefundProjectionItem(13,'Subscriber',1,'subscriber',0.0);
$sponsor_tickets = new RefundProjectionItem(14,'Sponsor tickets',2,'sponsor_ticket',0.0);
$fee = new RefundProjectionItem(15,'Requested showing sponsorship',1,'',20.0);
$GLOBALS['refund_projection_order'] = new RefundProjectionOrder(88,60.0,15.0,[$paid_general,$paid_discount,$subscriber,$sponsor_tickets],[$fee],[11=>1,12=>1],[11=>10.0,12=>5.0]);
$partial = roxy_rs_repo_effective_backing($backing);
check_refund_projection($partial['general_qty']===2 && $partial['discount_qty']===1 && $partial['support_qty']===3,'partial paid-ticket refunds remove exact ticket quantities');
check_refund_projection($partial['charge_total']===4500 && $partial['sponsor_amount']===2000,'retained contribution follows actual Woo refund total while unreimbursed sponsor stays active');
check_refund_projection($partial['subscriber_qty']===1 && $partial['sponsor_ticket_qty']===2,'unrefunded free and subscriber tickets remain counted');
$GLOBALS['refund_projection_order'] = new RefundProjectionOrder(88,60.0,35.0,[$paid_general,$paid_discount,$subscriber,$sponsor_tickets],[$fee],[11=>1,12=>1,14=>1],[11=>10.0,12=>5.0,15=>20.0]);
$full_sponsor = roxy_rs_repo_effective_backing($backing);
check_refund_projection($full_sponsor['charge_total']===2500 && $full_sponsor['sponsor_amount']===0
    && $full_sponsor['general_qty']===2 && $full_sponsor['discount_qty']===1
    && $full_sponsor['support_qty']===3 && $full_sponsor['subscriber_qty']===1
    && $full_sponsor['sponsor_ticket_qty']===1,'itemized sponsorship and ticket refunds remove only their allocated quantities');
$legacy = $backing;
$legacy['sponsor_amount']=0; $legacy['sponsor_ticket_qty']=2; $legacy['charge_total']=4000;
$legacy['agreement_json']=\RoxyRS\Agreement::build($quote,$legacy);
$GLOBALS['refund_projection_types']=[21=>'adult',22=>'discount'];
$legacy_line=new RefundProjectionItem(21,'Legacy general',5,'',30.0);
$legacy_discount=new RefundProjectionItem(22,'Discount',2,'',10.0);
$GLOBALS['refund_projection_order']=new RefundProjectionOrder(88,40.0,10.0,[$legacy_line,$legacy_discount],[],[21=>2],[21=>10.0]);
$legacy_result=roxy_rs_repo_effective_backing($legacy);
check_refund_projection($legacy_result['general_qty']===2 && $legacy_result['sponsor_ticket_qty']===1,'legacy mixed general line allocates the paid portion from its itemized refunded amount');
$GLOBALS['refund_projection_types']=[31=>'discount',32=>'subscriber'];
$legacy_lines=$legacy;
$legacy_lines['subscriber_qty']=1;
$legacy_lines['agreement_json']=\RoxyRS\Agreement::build($quote,$legacy_lines);
$discount_line=new RefundProjectionItem(31,'Legacy discount',2,'',10.0);
$subscriber_line=new RefundProjectionItem(32,'Legacy subscriber',1,'',0.0);
$GLOBALS['refund_projection_order']=new RefundProjectionOrder(88,40.0,5.0,[$legacy_line,$legacy_discount,$discount_line,$subscriber_line],[],[31=>1,32=>1],[31=>5.0]);
$legacy_types=roxy_rs_repo_effective_backing($legacy_lines);
check_refund_projection($legacy_types['discount_qty']===1 && $legacy_types['general_qty']===3 && $legacy_types['subscriber_qty']===0,'legacy product ticket types distinguish refunded discount and subscriber lines');
$no_snapshot=$backing; $no_snapshot['agreement_json']='';
$GLOBALS['refund_projection_order']=new RefundProjectionOrder(88,60.0,0.0,[$paid_general,$paid_discount,$subscriber,$sponsor_tickets],[$fee]);
$legacy_no_refund=roxy_rs_repo_effective_backing($no_snapshot);
check_refund_projection($legacy_no_refund['charge_total']===6000 && $legacy_no_refund['support_qty']===5,'legacy charged backing without a snapshot remains available when there are no refunds');
$GLOBALS['refund_projection_order']=new RefundProjectionOrder(88,60.0,5.0,[$paid_general,$paid_discount,$subscriber,$sponsor_tickets],[$fee]);
$amount_only_partial_failed=false;
try { roxy_rs_repo_effective_backing($backing); } catch (RuntimeException $error) { $amount_only_partial_failed=true; }
check_refund_projection($amount_only_partial_failed,'partial amount-only refund with sponsorship requires manager review instead of guessing which line was refunded');
$no_sponsor = $backing;
$no_sponsor['general_qty']=3; $no_sponsor['discount_qty']=2; $no_sponsor['support_qty']=5;
$no_sponsor['charge_total']=4000; $no_sponsor['sponsor_amount']=0; $no_sponsor['sponsor_ticket_qty']=0;
$no_sponsor['agreement_json']='';
$GLOBALS['refund_projection_order'] = new RefundProjectionOrder(88,40.0,5.0,[$paid_general,$paid_discount,$subscriber],[],[],[]);
$amount_only_non_sponsor_failed=false;
try { roxy_rs_repo_effective_backing($no_sponsor); } catch (RuntimeException $error) { $amount_only_non_sponsor_failed=true; }
check_refund_projection($amount_only_non_sponsor_failed,'partial amount-only ticket refund without sponsorship requires manager review');
$GLOBALS['refund_projection_order'] = new RefundProjectionOrder(88,40.0,40.0,[$paid_general,$paid_discount,$subscriber],[],[],[]);
$amount_only_full_non_sponsor = roxy_rs_repo_effective_backing($no_sponsor);
check_refund_projection($amount_only_full_non_sponsor['charge_total']===0
    && $amount_only_full_non_sponsor['general_qty']===0 && $amount_only_full_non_sponsor['discount_qty']===0
    && $amount_only_full_non_sponsor['support_qty']===0 && $amount_only_full_non_sponsor['subscriber_qty']===1,
    'full amount-only refund clears paid ticket counts for non-sponsorship backing');
$GLOBALS['refund_projection_order']=new RefundProjectionOrder(88,60.0,60.0,[$paid_general,$paid_discount,$subscriber,$sponsor_tickets],[$fee]);
$amount_only_full=roxy_rs_repo_effective_backing($backing);
check_refund_projection($amount_only_full['charge_total']===0 && $amount_only_full['sponsor_amount']===0,'amount-only full Woo refund clears funding and sponsor state');
$converted = $backing + ['approved_showing_id'=>901];
$GLOBALS['refund_projection_order'] = new RefundProjectionOrder(88,60.0,15.0,[$paid_general,$paid_discount,$subscriber,$sponsor_tickets],[$fee],[11=>1,12=>1],[11=>10.0,12=>5.0]);
$refund_review = roxy_rs_repo_converted_refund_review_entry($converted);
check_refund_projection(is_array($refund_review) && $refund_review['verified'] === true
    && $refund_review['order_id'] === 88 && $refund_review['showing_id'] === 901
    && $refund_review['refund_total_cents'] === 1500,
    'verified post-conversion refund is returned for manager review with the exact order/showing and refund amount');
$GLOBALS['refund_projection_backing_row'] = $converted + ['approved_showing_id'=>901];
$GLOBALS['wpdb'] = new RefundProjectionDatabase();
$refund_totals = roxy_rs_repo_backing_totals(41);
check_refund_projection(count($refund_totals['refund_reviews']) === 1
    && $refund_totals['refund_reviews'][0]['order_id'] === 88
    && $refund_totals['charge_total'] === 4500,
    'admin request totals include the post-conversion refund review entry while applying the verified net refund');
$GLOBALS['refund_projection_order'] = new RefundProjectionOrder(88,60.0,5.0,[$paid_general,$paid_discount,$subscriber,$sponsor_tickets],[$fee]);
$amount_only_review = roxy_rs_repo_converted_refund_review_entry($converted);
check_refund_projection(is_array($amount_only_review) && $amount_only_review['verified'] === true
    && $amount_only_review['refund_total_cents'] === 500,
    'ambiguous amount-only refund still appears in the post-conversion manager review queue');
$pre_conversion = $converted; $pre_conversion['approved_showing_id'] = null;
check_refund_projection(roxy_rs_repo_converted_refund_review_entry($pre_conversion) === null,
    'refund review queue excludes a backing not yet moved to an approved event');
$GLOBALS['refund_projection_order'] = new RefundProjectionOrder(88,60.0,0.0,[$paid_general,$paid_discount,$subscriber,$sponsor_tickets],[$fee]);
check_refund_projection(roxy_rs_repo_converted_refund_review_entry($converted) === null,
    'converted backing without refunds does not raise an exceptional-refund review');
$GLOBALS['refund_projection_order'] = new class extends RefundProjectionOrder {
    public function __construct() { parent::__construct(88,60.0,0.0,[],[]); }
    public function get_meta($key, $single = true): int { return 0; }
};
$unverified_review = roxy_rs_repo_converted_refund_review_entry($converted);
check_refund_projection(is_array($unverified_review) && $unverified_review['verified'] === false,
    'unverifiable converted-order identity fails visibly into manager review instead of looking like no refund');
$bad=false;
$GLOBALS['refund_projection_order']=new RefundProjectionOrder(88,60.0,1.0,[$paid_general],[$fee],[11=>0.5],[11=>1.0]);
try { roxy_rs_repo_effective_backing($backing); } catch (RuntimeException $error) { $bad=true; }
check_refund_projection($bad,'fractional refunded ticket quantities fail closed');
$bad=false;
$GLOBALS['refund_projection_order']=new class extends RefundProjectionOrder {
    public function __construct() { parent::__construct(88,60.0,0.0,[],[]); }
    public function get_meta($key, $single = true): int { return 0; }
};
try { roxy_rs_repo_effective_backing($backing); } catch (RuntimeException $error) { $bad=true; }
check_refund_projection($bad,'a Woo order linked to a different backing is rejected');
