<?php
namespace RoxyST;
if (!defined('ABSPATH')) exit;

/** Durable unpaid checkout holds in transactional order metadata; no gateway calls here. */
final class Holds {
  private static array $save_leases=[];
  public static function init(): void {
    add_action('woocommerce_checkout_order_created',[__CLASS__,'claim'],5);
    add_action('woocommerce_store_api_checkout_order_processed',[__CLASS__,'claim_store_api'],5);
    add_action('woocommerce_before_checkout_process',[__CLASS__,'release_changed_cart_hold'],5);
    add_action('woocommerce_cart_item_removed',[__CLASS__,'release_changed_cart_hold'],5);
    add_action('woocommerce_after_cart_item_quantity_update',[__CLASS__,'release_changed_cart_hold'],5);
    add_action('woocommerce_cart_emptied',[__CLASS__,'release_changed_cart_hold'],5);
    add_action('woocommerce_before_pay_action',[__CLASS__,'claim'],5);
    add_action('woocommerce_checkout_order_exception',[__CLASS__,'release'],5);
    add_action('woocommerce_order_status_cancelled',[__CLASS__,'release'],5);
    add_action('woocommerce_order_status_failed',[__CLASS__,'release'],5);
    add_action('woocommerce_before_order_object_save',[__CLASS__,'guard_confirmation'],5);
    add_action('woocommerce_after_order_object_save',[__CLASS__,'release_confirmation'],1);
    register_shutdown_function([__CLASS__,'release_all_confirmations']);
    add_action('woocommerce_admin_order_data_after_order_details',[__CLASS__,'review_notice']);
    add_action('woocommerce_thankyou',[__CLASS__,'hold_notice'],20);
    add_action('woocommerce_view_order',[__CLASS__,'hold_notice'],20);
  }

  public static function claim_store_api($order): void {
    try{self::claim($order);}catch(\Throwable $e){throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('roxy_seat_unavailable','Seats could not be reserved. Refresh your cart before paying.',409);}
  }

  /** Only the server-side session's own unpaid order can be excluded on retry. */
  public static function retry_order_id(): int {
    if(!function_exists('WC') || !WC()->session || !WC()->cart)return 0;
    $id=(int)WC()->session->get('order_awaiting_payment');if($id<=0)return 0;
    $order=wc_get_order($id);
    if(!$order || !in_array($order->get_status(),['pending','failed','checkout-draft'],true) || (int)$order->get_customer_id()!==get_current_user_id() || (int)get_post_meta($id,'_roxy_seat_hold_managed',true)!==1)return 0;
    return $id;
  }

  public static function release_changed_cart_hold(): void {
    $id=self::retry_order_id();if($id<=0)return;
    $order=wc_get_order($id);
    if(!hash_equals((string)$order->get_cart_hash(),(string)WC()->cart->get_cart_hash()))self::release($order);
  }

  /** Raw persisted line identities; never use a request-local sales/entitlement cache. */
  private static function snapshot(int $order_id): array {
    global $wpdb;
    $items=$wpdb->prefix.'woocommerce_order_items';$meta=$wpdb->prefix.'woocommerce_order_itemmeta';
    $rows=$wpdb->get_results($wpdb->prepare("SELECT i.order_item_id,p.meta_value product_id,q.meta_value quantity FROM `$items` i JOIN `$meta` p ON p.order_item_id=i.order_item_id AND p.meta_key='_product_id' JOIN `$meta` q ON q.order_item_id=i.order_item_id AND q.meta_key='_qty' WHERE i.order_id=%d AND i.order_item_type='line_item' ORDER BY i.order_item_id",$order_id),ARRAY_A);
    if($wpdb->last_error || !is_array($rows))throw new \RuntimeException('Order seats could not be read');
    $shows=[];$lines=[];
    foreach($rows as $row) {
      $pid=(int)$row['product_id'];$item=(int)$row['order_item_id'];
      $sid=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM `{$wpdb->postmeta}` WHERE post_id=%d AND meta_key=%s ORDER BY meta_id LIMIT 1",$pid,ROXY_ST_META_SHOWING_ID));
      if($wpdb->last_error)throw new \RuntimeException('Showing identity could not be read');
      if((int)$sid<=0)continue;
      if(isset($lines[$item]) || !is_numeric($row['quantity']) || (float)$row['quantity']<=0 || (float)$row['quantity']!==(float)(int)$row['quantity'])throw new \RuntimeException('Invalid persisted ticket quantities');
      $qty=(int)$row['quantity'];$sid=(int)$sid;
      if($qty>PHP_INT_MAX-($shows[$sid]??0))throw new \RuntimeException('Ticket quantities overflow');
      $shows[$sid]=($shows[$sid]??0)+$qty;$lines[$item]=[$pid,$qty,$sid];
    }
    ksort($shows);return ['shows'=>$shows,'lines'=>$lines];
  }

  public static function claim($order): bool {
    if(is_numeric($order))$order=wc_get_order((int)$order);
    if(!$order || !is_a($order,'WC_Order') || $order->get_type()!=='shop_order')return false;
    $id=(int)$order->get_id();if($id<=0)return false;
    $captured=self::snapshot($id);
    if(!$captured['shows']) {
      if((int)get_post_meta($id,'_roxy_seat_hold_managed',true)===1)throw new \RuntimeException('The ticket mapping changed. Contact the theater.');
      return false;
    }
    if(count($captured['shows'])>4)throw new \RuntimeException('Please order tickets for no more than four showings at once.');
    $scopes=array_map(static fn($sid)=>'walkup:'.$sid,array_keys($captured['shows']));
    $operation=new Issuance($id,$scopes);
    $operation->run(static function(Issuance $writer)use($order,$id,$captured):void {
      global $wpdb;
      $fresh=self::snapshot($id);$writer->assert_owner();
      if($fresh!==$captured)throw new \RuntimeException('Your ticket quantities changed. Refresh before paying.');
      foreach($fresh['lines'] as $item_id=>[$pid,$qty,$sid]) {
        $item=$order->get_item($item_id);
        if(!$item || (int)$item->get_product_id()!==$pid || (int)$item->get_quantity()!==$qty)throw new \RuntimeException('Save ticket quantity changes before confirming payment.');
      }
      $status=$wpdb->get_var($wpdb->prepare("SELECT post_status FROM `{$wpdb->posts}` WHERE ID=%d AND post_type='shop_order'",$id));
      if($wpdb->last_error || !in_array($status,['wc-pending','wc-checkout-draft','wc-on-hold','wc-processing','wc-completed','wc-failed','wc-cancelled'],true))throw new \RuntimeException('This order cannot reserve seats.');
      foreach($fresh['shows'] as $sid=>$qty) {
        $subscriber=0;
        foreach($fresh['lines'] as [$pid,$units,$line_show])if($line_show===$sid && $writer->post_meta_value($pid,ROXY_ST_META_TICKET_TYPE)==='subscriber')$subscriber+=$units;
        if($subscriber>0) {
          $user=(int)$writer->post_meta_value($id,'_customer_user');
          if($user<=0 || $user!==(int)$order->get_customer_id())throw new \RuntimeException('Subscriber account could not be verified');
          $entitlement=Capacity::subscription_entitlement_count($user);
          $used=Reservations::quantity_for_showing($sid,$id,$user);
          $walkups=class_exists('\\Roxy_Sub_Check') ? \Roxy_Sub_Check::walkup_quantity_for_showing($sid,$user) : 0;
          if($subscriber>$entitlement-$used-$walkups)throw new \RuntimeException('Not enough subscriber entitlement remains for this showing.');
        }
        $raw=$writer->post_meta_value($sid,'_roxy_capacity');
        if($raw==='' || $raw===null)continue;
        $reserved=Reservations::quantity_for_showing($sid,$id);
        $walkups=$writer->member_walkup_quantity($sid);
        if($qty>max(0,(int)$raw)-$reserved-$walkups)throw new \RuntimeException('Not enough seats remain. Refresh your cart or contact the theater before paying.');
      }
      // A ticket-only override does not change booking/other Woo order settings.
      $settings=get_option('roxy_st_settings',[]);
      $override=is_array($settings)?($settings['ticket_hold_minutes']??''):'';
      $minutes=$override===''?(int)get_option('woocommerce_hold_stock_minutes',60):(int)$override;
      if($minutes<=0)throw new \RuntimeException('Ticket checkout requires a positive WooCommerce hold duration.');
      $now=(int)$wpdb->get_var('SELECT UNIX_TIMESTAMP()');
      if($wpdb->last_error || $now<=0 || $minutes>525600)throw new \RuntimeException('Ticket hold expiry could not be verified.');
      $writer->post_meta($id,'_roxy_seat_hold_managed',1);
      $writer->post_meta($id,'_roxy_seat_hold_until',$now+$minutes*60);
    });
    return true;
  }

  public static function release($order): void {
    if(is_numeric($order))$order=wc_get_order((int)$order);
    if(!$order || !is_a($order,'WC_Order') || $order->get_type()!=='shop_order')return;
    $id=(int)$order->get_id();if($id<=0)return;
    try {
      (new Issuance($id))->run(static function(Issuance $writer)use($id):void {
        global $wpdb;
        $status=$wpdb->get_var($wpdb->prepare("SELECT post_status FROM `{$wpdb->posts}` WHERE ID=%d",$id));
        if($wpdb->last_error || !$status)throw new \RuntimeException('Order status could not be read');
        if(in_array($status,['wc-processing','wc-completed','wc-on-hold'],true))return;
        $writer->post_meta($id,'_roxy_seat_hold_until',null,true);
      });
    } catch(\Throwable $e){Log::error('Ticket hold release incomplete; expiry remains the fallback',['order_id'=>$id]);}
  }

  /** Late payment must not silently issue an oversold ticket or discard payment evidence. */
  public static function guard_confirmation($order): void {
    global $wpdb;
    if(!$order || !is_a($order,'WC_Order') || $order->get_type()!=='shop_order' || (int)$order->get_id()<=0)return;
    if(!in_array($order->get_status(),['processing','completed'],true))return;
    $changes=$order->get_changes();if(!isset($changes['status']))return;
    $lease=null;
    try {
      $old=$wpdb->get_var($wpdb->prepare("SELECT post_status FROM `{$wpdb->posts}` WHERE ID=%d",(int)$order->get_id()));
      if($wpdb->last_error || !$old)throw new \RuntimeException('Order status unavailable');
      if(in_array($old,['wc-processing','wc-completed'],true) && (int)get_post_meta($order->get_id(),'_roxy_seat_review',true)!==1)return;
      $captured=self::snapshot((int)$order->get_id());
      if($captured['shows']) {
        $lease=new Issuance((int)$order->get_id(),array_map(static fn($sid)=>'walkup:'.$sid,array_keys($captured['shows'])));
        $lease->acquire_lease();
      }
      if(!self::claim($order))return;
      if($captured!==self::snapshot((int)$order->get_id()))throw new \RuntimeException('Showing mapping changed during confirmation');
      $lease->assert_owner();
      self::$save_leases[spl_object_id($order)][]=$lease;
      $lease=null;
      $order->update_meta_data('_roxy_seat_review',0);
    } catch(\Throwable $e) {
      $order->set_status('on-hold');
      $order->update_meta_data('_roxy_seat_review',1);
      Log::error('Ticket order requires seat review; no automatic refund',['order_id'=>(int)$order->get_id()]);
    } finally{if($lease)$lease->release_lease();}
  }

  public static function release_confirmation($order): void {
    $key=spl_object_id($order);
    if(empty(self::$save_leases[$key]))return;
    $lease=array_pop(self::$save_leases[$key]);$lease->release_lease();
    if(!self::$save_leases[$key])unset(self::$save_leases[$key]);
  }

  public static function release_all_confirmations(): void {
    foreach(self::$save_leases as $leases)foreach(array_reverse($leases) as $lease)$lease->release_lease();
    self::$save_leases=[];
  }

  public static function review_notice($order): void {
    if($order && (int)$order->get_meta('_roxy_seat_review',true)===1)echo '<p class="notice notice-warning">Seat availability requires manager review. Ticket admission is blocked. Payment records are preserved; no automatic refund was made. Resolve availability before confirming this order.</p>';
  }

  public static function hold_notice($order_id): void {
    $order=wc_get_order((int)$order_id);
    if(!$order || !in_array($order->get_status(),['pending','checkout-draft'],true))return;
    $until=(int)get_post_meta((int)$order_id,'_roxy_seat_hold_until',true);if($until<=0)return;
    echo '<p class="woocommerce-info">Your unpaid ticket seat hold expires '.esc_html(wp_date('M j, Y g:i a T',$until)).'. After expiry, seat availability is checked again before confirmation.</p>';
  }
}
