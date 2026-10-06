<?php
// WP-CLI only. Actual candidate code renamed into a fixture namespace; private tables only.
if (!defined('WP_CLI') || !WP_CLI) exit;
$root = $args[0] ?? dirname(__DIR__);
foreach (['reservations.php', 'repository.php', 'availability.php', 'woo.php'] as $file) {
    $source = file_get_contents($root . '/includes/modules/event-booking/includes/' . $file);
    if ($source === false) throw new RuntimeException('Cannot read candidate fixture source');
    $source = str_replace("require_once __DIR__ . '/reservations.php';", '', $source);
    $source = str_replace("require_once __DIR__ . '/refunds.php';", '', $source);
    $source = str_replace(['roxy_eb_', 'RoxyEBReservation'], ['roxy_fixture_eb_', 'RoxyEBFixtureReservation'], $source);
    eval(substr($source, 5));
}
function roxy_fixture_eb_table_bookings() { global $wpdb; return $wpdb->prefix . 'roxy_event_bookings'; }
function roxy_fixture_eb_table_blocks() { global $wpdb; return $wpdb->prefix . 'roxy_event_blocks'; }
function roxy_fixture_eb_get_settings() { return ['showtime_blocks'=>[], 'sling_mode'=>'disabled']; }
function roxy_fixture_eb_clear_pizza_reminders($id) { $GLOBALS['room_fixture_events'][]='reminders'; }
function roxy_fixture_eb_schedule_pizza_reminder($id) { $GLOBALS['room_fixture_events'][]='schedule'; }
function roxy_fixture_eb_email_internal_booking_order_changed($before,$after,$reason) { $GLOBALS['room_fixture_events'][]='internal'; }
function roxy_fixture_eb_email_customer_booking_updated($before,$after) { $GLOBALS['room_fixture_events'][]='customer'; }
$GLOBALS['room_fixture_events']=[];
global $wpdb;
$original_prefix = $wpdb->prefix;
$original_tables = [roxy_eb_table_bookings(), roxy_eb_table_blocks()];
$digest = static function () use ($wpdb, $original_tables) {
    $rows = [];
    foreach ($original_tables as $table) { $rows[] = $wpdb->get_results("SELECT * FROM `$table` ORDER BY id", ARRAY_A); if ($wpdb->last_error) throw new RuntimeException('Baseline read failed'); }
    return hash('sha256', serialize($rows));
};
$before = $digest();
$prefix = $original_prefix . 'room_fixture_' . bin2hex(random_bytes(4)) . '_';
$tables = [$prefix.'roxy_event_bookings', $prefix.'roxy_event_blocks'];
$created = [];
$peer = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$check = static function ($value, $label) { if (!$value) throw new RuntimeException($label); echo 'PASS: '.$label.PHP_EOL; };
try {
    foreach ($tables as $i => $table) {
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table))) throw new RuntimeException('Fixture table exists');
        if ($wpdb->query("CREATE TABLE `$table` LIKE `{$original_tables[$i]}`") === false) throw new RuntimeException('Cannot create private fixture table');
        $created[] = $table;
    }
    $wpdb->prefix = $prefix;
    $row = ['reserved_start_at'=>'2040-01-02 10:00:00','reserved_end_at'=>'2040-01-02 13:00:00','doors_open_at'=>'2040-01-02 10:30:00','doors_close_at'=>'2040-01-02 12:30:00','woo_order_id'=>900000001];
    $id = roxy_fixture_eb_repo_insert_booking($row);
    $check(is_int($id) && $id>0, 'reservation creation commits with final availability check');
    $duplicate = roxy_fixture_eb_repo_insert_booking($row);
    $check(is_wp_error($duplicate) && $duplicate->get_error_code()==='booking_order_exists', 'replayed payment order cannot create duplicate reservation');
    $overlap = $row; $overlap['woo_order_id']=900000002;
    $result = roxy_fixture_eb_repo_insert_booking($overlap);
    $check(is_wp_error($result) && $result->get_error_code()==='reservation_conflict', 'another order cannot reserve overlapping room');
    $check((int)$wpdb->get_var("SELECT COUNT(*) FROM `{$tables[0]}`")===1, 'refused creations leave no partial booking rows');
    $check(roxy_fixture_eb_repo_update_booking($id,['notes_admin'=>'private fixture'])===true, 'metadata update succeeds');
    $check(roxy_fixture_eb_repo_update_booking($id,['notes_admin'=>'private fixture'])===true, 'unchanged update succeeds rather than treating zero affected rows as failure');
    $check(is_wp_error(roxy_fixture_eb_repo_update_booking(99999,['notes_admin'=>'missing'])), 'missing row cannot claim successful update');
    $invalid = $row; $invalid['woo_order_id']=900000003; $invalid['reserved_end_at']='2040-01-02 09:00:00';
    $check(is_wp_error(roxy_fixture_eb_repo_insert_booking($invalid)), 'invalid reversed interval rejected');
    $key = 'roxy_room_'.substr(hash('sha256',$prefix),0,48);
    $check((string)$peer->get_var($peer->prepare('SELECT GET_LOCK(%s,0)',$key))==='1', 'independent fixture connection owns room lock');
    try { $result=roxy_fixture_eb_repo_update_booking($id,['notes_admin'=>'must not save']); $check(is_wp_error($result) && $result->get_error_code()==='reservation_busy','independent writer cannot mutate under contended room lock'); }
    finally { $peer->get_var($peer->prepare('SELECT RELEASE_LOCK(%s)',$key)); }
    $check(roxy_fixture_eb_repo_get_booking($id)['notes_admin']==='private fixture','contended write preserves original value');
    $result=roxy_fixture_eb_reservation_run(static function($guard)use($wpdb,$tables,$id){ $guard->update($tables[0],$id,['notes_admin'=>'rollback']); return new WP_Error('fixture_abort','intentional rollback'); });
    $check(is_wp_error($result) && roxy_fixture_eb_repo_get_booking($id)['notes_admin']==='private fixture','error result rolls back all reservation writes');
    $result=roxy_fixture_eb_repo_insert_block(['start_at'=>$row['reserved_start_at'],'end_at'=>$row['reserved_end_at']]);
    $check(is_wp_error($result),'new manual block cannot silently overlap accepted reservation');
    $block=roxy_fixture_eb_repo_insert_block(['start_at'=>'2040-01-02 14:00:00','end_at'=>'2040-01-02 15:00:00']);
    $check(is_int($block) && $block>0,'manual block accepted outside existing reservation');
    $result=roxy_fixture_eb_repo_update_booking($id,['reserved_start_at'=>'2040-01-02 14:00:00','reserved_end_at'=>'2040-01-02 16:00:00']);
    $check(is_wp_error($result),'reservation edit rechecks manual block inside room lock');
    $check(roxy_fixture_eb_repo_get_booking($id)['reserved_start_at']===$row['reserved_start_at'],'failed conflicting edit preserves original reservation');
    $check(roxy_fixture_eb_repo_delete_block($block)===true,'manual block deletion verified');
    $check(is_wp_error(roxy_fixture_eb_repo_delete_block($block)),'missing block deletion cannot claim success');
    $result=roxy_fixture_eb_reservation_run(static function($guard)use($wpdb,$key,$tables,$id){ $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); $guard->update($tables[0],$id,['notes_admin'=>'lost owner']); return true; });
    $check(is_wp_error($result) && roxy_fixture_eb_repo_get_booking($id)['notes_admin']==='private fixture','lost lock fails before mutation');
    $lost_at_write = false;
    $intercept = static function ($sql) use ($wpdb, $tables, $key, &$lost_at_write) {
        if (!$lost_at_write && strpos($sql, 'UPDATE `'.$tables[0].'`')===0) {
            $lost_at_write = true;
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));
        }
        return $sql;
    };
    add_filter('query',$intercept,999);
    try { $result=roxy_fixture_eb_repo_update_booking($id,['notes_admin'=>'lost immediately before SQL']); }
    finally { remove_filter('query',$intercept,999); }
    $check($lost_at_write && is_wp_error($result) && roxy_fixture_eb_repo_get_booking($id)['notes_admin']==='private fixture','SQL ownership predicate prevents write after lock loss at the actual mutation');
    $quiet = $wpdb->suppress_errors(true);
    try {
        $result=roxy_fixture_eb_reservation_run(static function($guard)use($tables,$id){ $guard->update($tables[0],$id,['notes_admin'=>'must roll back']); $guard->update($tables[0],$id,['invalid_fixture_column'=>'fail']); return true; });
        $check(is_wp_error($result) && roxy_fixture_eb_repo_get_booking($id)['notes_admin']==='private fixture','SQL write failure rolls back earlier change in the same transaction');
        $wpdb->prefix=$prefix.'missing_';
        $check(is_wp_error(roxy_fixture_eb_repo_list_bookings_in_range('2040-01-02 10:00:00','2040-01-02 13:00:00')),'failed availability read is not an empty successful result');
        $start=new DateTimeImmutable('2040-01-02 10:00:00',wp_timezone()); $end=$start->modify('+3 hours');
        $check(roxy_fixture_eb_is_slot_available($start,$end)===false,'public availability fails closed on missing storage');
        $check(is_wp_error(roxy_fixture_eb_get_calendar_blocks($start,$end)),'calendar exposes read failure instead of empty available month');
        $thrown=false; try { roxy_fixture_eb_is_slot_available($start,$end,0,true); } catch(Throwable $error) { $thrown=true; }
        $check($thrown,'payment completion distinguishes read failure from an actual room conflict');
    } finally { $wpdb->prefix=$prefix; $wpdb->suppress_errors($quiet); }
    $wpdb->query('START TRANSACTION');
    try { $check(is_wp_error(roxy_fixture_eb_repo_update_booking($id,['notes_admin'=>'outer transaction'])),'existing external transaction is not implicitly committed'); }
    finally { $wpdb->query('ROLLBACK'); }
    $check(roxy_fixture_eb_repo_update_booking($id,['wp_user_id'=>123,'customer_email'=>'fixture@example.invalid','base_price'=>250,'total_price'=>250])===true,'private paid-adjustment fixture initialized');
    $make_order=static function($oid,$booking_id,$paid=true,$owner=123) {
        return new class($oid,$booking_id,$paid,$owner) {
            private $id; private $booking; private $paid; private $owner;
            public function __construct($id,$booking,$paid,$owner){$this->id=$id;$this->booking=$booking;$this->paid=$paid;$this->owner=$owner;}
            public function get_id(){return $this->id;}
            public function is_paid(){return $this->paid;}
            public function get_user_id(){return $this->owner;}
            public function get_billing_email(){return $this->owner===123?'fixture@example.invalid':'other@example.invalid';}
            public function get_items(){ $item=new WC_Order_Item_Product(); $item->add_meta_data('_roxy_eb_booking_adjustment',wp_json_encode(['booking_id'=>$this->booking])); return [1=>$item]; }
            public function add_order_note($note){$GLOBALS['room_fixture_events'][]='note';}
        };
    };
    $adjustment_for=static function($booking) {
        return ['booking_id'=>(int)$booking['id'],'revision_version'=>1,'booking_revision'=>roxy_fixture_eb_booking_revision($booking),'new_extra_hours'=>1,'new_duration_hours'=>3,'new_show_start_at'=>$booking['show_start_at'],'new_doors_close_at'=>'2040-01-02 13:30:00','new_reserved_start_at'=>$booking['reserved_start_at'],'new_reserved_end_at'=>'2040-01-02 14:00:00','new_notes_admin'=>'paid fixture update','new_special_charge_label'=>'Fixture charge','new_special_charge_total'=>40,'new_extra_price'=>100,'new_total_price'=>390,'delta_total'=>140];
    };
    $snapshot=roxy_fixture_eb_repo_get_booking($id); $adjustment=$adjustment_for($snapshot);
    $check(is_wp_error(roxy_fixture_eb_apply_booking_adjustment_from_order($make_order(900000010,$id,false),$adjustment)), 'unpaid adjustment cannot alter reservation');
    $check(is_wp_error(roxy_fixture_eb_apply_booking_adjustment_from_order($make_order(900000010,$id,true,456),$adjustment)), 'another customer cannot apply paid booking change');
    $legacy=$adjustment; unset($legacy['booking_revision'],$legacy['revision_version']);
    $check(is_wp_error(roxy_fixture_eb_apply_booking_adjustment_from_order($make_order(900000010,$id),$legacy)), 'unversioned legacy adjustment requires manager review');
    roxy_fixture_eb_repo_update_booking($id,['notes_admin'=>'later admin edit']);
    $check(is_wp_error(roxy_fixture_eb_apply_booking_adjustment_from_order($make_order(900000010,$id),$adjustment)), 'late paid snapshot cannot overwrite newer edit');
    $check(roxy_fixture_eb_repo_get_booking($id)['notes_admin']==='later admin edit' && !$GLOBALS['room_fixture_events'],'rejected adjustment preserves row and sends no success side effects');
    $snapshot=roxy_fixture_eb_repo_get_booking($id); $adjustment=$adjustment_for($snapshot);
    $blocking=roxy_fixture_eb_repo_insert_block(['start_at'=>'2040-01-02 13:00:00','end_at'=>'2040-01-02 14:00:00']);
    $check(is_int($blocking),'new private block touches original booking without overlap');
    $check(is_wp_error(roxy_fixture_eb_apply_booking_adjustment_from_order($make_order(900000010,$id),$adjustment)), 'paid extension rechecks newly occupied room before applying');
    $check(!roxy_fixture_eb_booking_adjustment_order_ids(roxy_fixture_eb_repo_get_booking($id)),'failed extension leaves no applied-once marker');
    roxy_fixture_eb_repo_delete_block($blocking);
    $check(roxy_fixture_eb_apply_booking_adjustment_from_order($make_order(900000010,$id),$adjustment)===true,'valid versioned paid adjustment commits');
    $updated=roxy_fixture_eb_repo_get_booking($id);
    $check((int)$updated['total_price']===390 && (int)$updated['special_charge_total']===40 && $updated['special_charge_label']==='Fixture charge','paid snapshot retains special charge and total');
    $check(roxy_fixture_eb_booking_adjustment_order_ids($updated)===[900000010],'change and applied-order identity commit together');
    $events=count($GLOBALS['room_fixture_events']);
    $check(roxy_fixture_eb_apply_booking_adjustment_from_order($make_order(900000010,$id),$adjustment)===true && count($GLOBALS['room_fixture_events'])===$events,'replayed paid callback sends no duplicate success notes or notifications');
    $stale=['expected_revision'=>roxy_fixture_eb_booking_revision($snapshot),'notes_admin'=>'stale form'];
    $check(is_wp_error(roxy_fixture_eb_apply_booking_order_change($id,$stale)),'stale no-charge/invoice edit also rejected under room lock');
    $admin_revision=roxy_fixture_eb_booking_revision($updated);
    roxy_fixture_eb_repo_update_booking($id,['guest_count'=>12]);
    $check(is_wp_error(roxy_fixture_eb_repo_update_booking($id,['_roxy_expected_revision'=>$admin_revision,'guest_count'=>20])) && (int)roxy_fixture_eb_repo_get_booking($id)['guest_count']===12,'old admin form cannot overwrite later booking change');
    roxy_fixture_eb_repo_update_booking($id,['doors_open_at'=>(new DateTimeImmutable('+2 days',wp_timezone()))->format('Y-m-d H:i:s')]);
    $adjustment=$adjustment_for(roxy_fixture_eb_repo_get_booking($id));
    $result=roxy_fixture_eb_apply_booking_adjustment_from_order($make_order(900000011,$id),$adjustment);
    $check(is_wp_error($result) && $result->get_error_code()==='adjustment_cutoff','delayed paid adjustment cannot bypass final edit cutoff');
    roxy_fixture_eb_repo_update_booking($id,['status'=>'cancelled']);
    $adjustment=$adjustment_for(roxy_fixture_eb_repo_get_booking($id));
    $check(is_wp_error(roxy_fixture_eb_apply_booking_adjustment_from_order($make_order(900000011,$id),$adjustment)),'paid adjustment cannot reopen cancelled booking');
} finally {
    $wpdb->prefix=$original_prefix;
    $peer->close();
    foreach (array_reverse($created) as $table) $wpdb->query("DROP TABLE `$table`");
}
$check($before===$digest(),'all original booking and manual block rows unchanged');
echo 'Only private fixture tables removed; no provider/email work.'.PHP_EOL;
