<?php
// Isolated order/stock/transaction tests; no provider calls or real writes.
define('ABSPATH', __DIR__); define('ARRAY_A','ARRAY_A');
function wp_json_encode($value) { return json_encode($value); }
function wp_salt($context) { return 'test'; }
function current_time($format) { return '2026-10-03 01:00:00'; }
function wp_timezone() { return new DateTimeZone('America/Los_Angeles'); }
function sanitize_text_field($value) { return (string)$value; }
function add_filter(...$args) {}
function remove_filter(...$args) {}
class FakeDatabase {
    public $prefix='test_'; public $products=[]; public $orders=[]; public $insert_id=0;
    public $busy=false; public $fail_write=false; public $cancel_during_reset=false; public $commands=[]; private $saved;
    public function prepare($sql,...$args) { if(count($args)===1 && is_array($args[0]))$args=$args[0];foreach($args as $a)$sql=preg_replace('/%[sdf]/',is_numeric($a)?(string)$a:"'".$a."'",$sql,1);return $sql; }
    public function get_var($sql) { if(preg_match('/SELECT id FROM .*products WHERE id=(\d+)/',$sql,$m))return $this->products[(int)$m[1]]['id']??null;return strpos($sql,'GET_LOCK')!==false && $this->busy ? 0 : 1; }
    public function suppress_errors($value) { return false; }
    public function query($sql) { $this->commands[]=$sql; if(strpos($sql,'RELEASE SAVEPOINT ')===0)return false; if($sql==='START TRANSACTION')$this->saved=[$this->products,$this->orders]; if($sql==='ROLLBACK')[$this->products,$this->orders]=$this->saved; return 0; }
    public function get_results($sql,$format) { return strpos($sql,'products')!==false ? array_values($this->products) : array_values(array_filter($this->orders,fn($o)=>$o['status']==='ordered')); }
    public function get_row($sql,$format) {
        if(preg_match('/submission_key=\x27([^\x27]+)\x27/',$sql,$m))foreach($this->orders as $o)if(($o['submission_key']??'')===$m[1])return $o;
        if(strpos($sql,"status IN")!==false){foreach($this->orders as $o)if(in_array($o['status'],['pending_manager','approval_emailed','ordered']))return $o;return null;}
        if(preg_match('/id=(\d+)/',$sql,$m))return $this->orders[(int)$m[1]]??null;
        return null;
    }
    public function insert($table,$data) { if($this->fail_write)return false; $this->insert_id++;$data['id']=$this->insert_id;$this->orders[$this->insert_id]=$data;return 1; }
    public function update($table,$data,$where) {
        if($this->fail_write)return false;
        $rows=&$this->orders; if(strpos($table,'products')!==false)$rows=&$this->products;
        $id=$where['id']; if($this->cancel_during_reset && isset($data['status']))$rows[$id]['status']='cancelled';
        if(!isset($rows[$id]))return 0;
        foreach($where as $key=>$value)if(($rows[$id][$key]??null)!=$value)return 0;
        $rows[$id]=array_merge($rows[$id],$data);return 1;
    }
}
function check($ok,$label) {if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function rejected(callable $f) {try{$f();return false;}catch(RuntimeException $e){return true;}}
$root=$argv[1]??dirname(__DIR__);
require $root.'/includes/modules/inventory/includes/class-roxy-inventory-store.php';
require $root.'/includes/modules/inventory/includes/class-roxy-inventory-admin.php';
$wpdb=new FakeDatabase();
$vendor=['name'=>'Test vendor','minimum_amount'=>12];
$p=['id'=>1,'square_variation_id'=>'v1','name'=>'Candy','vendor'=>'Test vendor','on_hand'=>20,'pack_size'=>12,'reorder_point'=>20,'target_stock'=>60,'unit_cost'=>1.5,'unit_cost_status'=>'estimate','unit_cost_source'=>'legacy configured cost','unit_cost_checked_at'=>null,'supplier_sku'=>'','override_qty'=>null];
$input=['review_complete'=>'1','review_token'=>\RoxyInventory\Admin::review_token($vendor,[$p]),'order_qty'=>[1=>'12']];
[$lines,$total]=\RoxyInventory\Admin::reviewed_lines($vendor,[$p],$input);
check($total===18.0 && $lines[0]['quantity']===12,'only explicit reviewed quantity submitted');
$unknown_cost=$p; $unknown_cost['unit_cost']=0; $unknown_cost['unit_cost_status']='unknown';
check(rejected(fn()=>\RoxyInventory\Admin::reviewed_lines($vendor,[$unknown_cost],$input)),'unknown zero cost cannot pass order review');
$free_cost=$p; $free_cost['unit_cost']=0; $free_cost['unit_cost_status']='free'; $free_cost['unit_cost_source']='supplier confirmation'; $free_cost['unit_cost_checked_at']='2026-10-08';
check(\RoxyInventory\Admin::cost_summary([['product'=>$free_cost,'qty'=>3]])===['known_total'=>0.0,'incomplete'=>false],'explicitly free items are complete zero-cost lines');
$free_vendor=$vendor; $free_vendor['minimum_amount']=0;
$free_input=$input; $free_input['review_token']=\RoxyInventory\Admin::review_token($free_vendor,[$free_cost]);
[$free_lines,$free_total]=\RoxyInventory\Admin::reviewed_lines($free_vendor,[$free_cost],$free_input);
check($free_total===0.0 && $free_lines[0]['unit_cost_status']==='free','explicit free item can be reviewed without inventing a price');
$changed=$p;$changed['on_hand']=21;
check(rejected(fn()=>\RoxyInventory\Admin::reviewed_lines($vendor,[$changed],$input)),'stock changes require refreshed review');
$missing=$input;unset($missing['order_qty'][1]);
check(rejected(fn()=>\RoxyInventory\Admin::reviewed_lines($vendor,[$p],$missing)),'missing quantity never becomes a suggestion');
$fraction=$input;$fraction['order_qty'][1]='1.5';
check(rejected(fn()=>\RoxyInventory\Admin::reviewed_lines($vendor,[$p],$fraction)),'fractional quantity rejected');
$small=$input;$small['order_qty'][1]='1';
check(rejected(fn()=>\RoxyInventory\Admin::reviewed_lines($vendor,[$p],$small)),'vendor minimum checked on explicit lines');
$wpdb->busy=true;
check(rejected(fn()=>\RoxyInventory\Store::create_order('Test vendor',$lines,$total,12,'pending_manager','key')) && !$wpdb->orders,'concurrent lock failure prevents insertion');
$wpdb->busy=false;
$id=\RoxyInventory\Store::create_order('Test vendor',$lines,$total,12,'pending_manager','key');
check($id===1 && \RoxyInventory\Store::order_for_submission('key')['id']===1,'submission identity persisted');
check(rejected(fn()=>\RoxyInventory\Store::create_order('Test vendor',$lines,$total,12)) && count($wpdb->orders)===1,'second open vendor order rejected inside serialized insertion');
$wpdb->orders[1]['status']='ordered';
$wpdb->orders[1]['payload']=json_encode([['product'=>'Candy','square_variation_id'=>'v1','on_hand'=>40]]);
$wpdb->products[1]=['id'=>1,'square_variation_id'=>'v1','name'=>'Candy','on_hand'=>25];
$wpdb->orders[1]['created_at']='2026-10-02 10:00:00';
check(\RoxyInventory\Store::mark_stock_increases(['v1'=>20])===0 && $wpdb->orders[1]['status']==='ordered','manual or net stock increase alone cannot unlock an open order');
$receipt=['id'=>'receipt-1','quantity'=>5,'from_state'=>'NONE','to_state'=>'IN_STOCK','reason_type'=>'RECEIVED','created_at'=>'2026-10-03T08:00:00Z','occurred_at'=>'2026-10-03T08:00:00Z'];
check(\RoxyInventory\Store::mark_stock_increases(['v1'=>20],['v1'=>array_merge($receipt,['created_at'=>'2026-10-01T08:00:00Z','occurred_at'=>'2026-10-01T08:00:00Z'])])===0 && $wpdb->orders[1]['status']==='ordered','receipt predating vendor order cannot release it');
check(\RoxyInventory\Store::mark_stock_increases(['v1'=>20],['v1'=>$receipt])===1 && $wpdb->orders[1]['status']==='stock_increased','explicit partial Square receipt unlocks an order despite arrival below original snapshot');
$line=json_decode($wpdb->orders[1]['payload'],true)[0];
check($line['stock_increase_from']===20 && $line['stock_increase_to']===25 && (float)$line['square_receipt_quantity']===5.0 && $line['square_receipt_event_id']==='receipt-1' && !empty($line['stock_increase_detected_at']),'triggering item, received quantity, source event and time retained');
$wpdb->orders[1]['status']='ordered';$wpdb->cancel_during_reset=true;
check(\RoxyInventory\Store::mark_stock_increases(['v1'=>20],['v1'=>$receipt])===0 && $wpdb->orders[1]['status']==='cancelled','receipt reset cannot overwrite concurrent cancellation');
$wpdb->cancel_during_reset=false;$before=$wpdb->products[1];
check(rejected(function()use($wpdb){\RoxyInventory\Store::transaction(function()use($wpdb){\RoxyInventory\Store::update_product(1,['on_hand'=>99]);$wpdb->fail_write=true;\RoxyInventory\Store::update_product(1,['on_hand'=>100]);});}) && $wpdb->products[1]===$before,'failed later write rolls back earlier updates');
$wpdb->fail_write=false;
$wpdb->orders[1]['payload']='[]';
check(!\RoxyInventory\Store::update_order_payload(1,[['added_to_cart'=>true]],'stale'),'stale cart progress rejected without overwrite');
check(\RoxyInventory\Store::update_order_payload(1,[],'[]'),'unchanged cart progress is successful without false failure');
