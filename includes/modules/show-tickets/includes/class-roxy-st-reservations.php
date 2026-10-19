<?php
namespace RoxyST;
if (!defined('ABSPATH')) exit;

/** Read-only seat authority, separate from cached financial/reporting projections. */
final class Reservations {
  /** Resolve only ticket-matching order IDs through bounded WooCommerce CRUD reads. */
  private static function hpos_reservation_order_ids(array $candidate_ids, int $exclude_order_id, int $subscriber_user_id, int $now): array {
    global $wpdb;
    if(!function_exists('wc_get_order'))throw new \RuntimeException('WooCommerce order query is unavailable');
    $candidate_ids=array_values(array_unique(array_map('intval',$candidate_ids)));
    if(!$candidate_ids)return [];
    if(min($candidate_ids)<=0)throw new \RuntimeException('Seat order identities could not be verified');
    $eligible=[];
    foreach($candidate_ids as $id) {
      $previous_error=(string)$wpdb->last_error;
      $wpdb->last_error='';
      try {
        // WooCommerce does not document a portable include-by-ID query var.
        // Resolve the bounded candidate IDs individually through CRUD rather
        // than accepting unrelated rows from a broad order query.
        $order=wc_get_order($id);
        $query_error=$wpdb->last_error;
      } catch(\Throwable $error) {
        $wpdb->last_error=$previous_error;
        throw new \RuntimeException('Seat order could not be read',0,$error);
      }
      $wpdb->last_error=$previous_error;
      if($query_error!=='')throw new \RuntimeException('Seat order could not be read');
      // Refund line items share WooCommerce's item tables. They and orphaned
      // item owners were excluded by the legacy order join.
      if(!$order instanceof \WC_Order || $order->get_type()!=='shop_order')continue;
      if((int)$order->get_id()!==$id)throw new \RuntimeException('Seat order identity could not be verified');
      if($id===$exclude_order_id || ($subscriber_user_id>0 && (int)$order->get_customer_id()!==$subscriber_user_id))continue;
      $status=$order->get_status();
      if(in_array($status,['processing','completed','on-hold'],true)) {
        $eligible[]=$id;
        continue;
      }
      if(!in_array($status,['pending','checkout-draft'],true))continue;
      $holds=[];
      foreach($order->get_meta_data() as $meta) {
        $data=is_object($meta) && method_exists($meta,'get_data') ? $meta->get_data() : [];
        if(($data['key']??null)==='_roxy_seat_hold_until')$holds[]=$data['value']??null;
      }
      if(!$holds)continue;
      if(count($holds)!==1 || !(is_int($holds[0]) || (is_string($holds[0]) && preg_match('/^[0-9]{1,19}$/D',$holds[0]))))throw new \RuntimeException('Seat hold expiry is ambiguous or invalid');
      if((int)$holds[0]>$now)$eligible[]=$id;
    }
    return array_values(array_unique($eligible));
  }

  public static function quantity_for_showing(int $showing_id, int $exclude_order_id = 0, int $subscriber_user_id = 0): int {
    global $wpdb;
    if($showing_id<=0) throw new \RuntimeException('Invalid showing');
    $utility='\Automattic\WooCommerce\Utilities\OrderUtil';
    if(!class_exists($utility) || !method_exists($utility,'custom_orders_table_usage_is_enabled')) {
      throw new \RuntimeException('WooCommerce order storage could not be verified');
    }
    try {
      $hpos=$utility::custom_orders_table_usage_is_enabled();
    } catch(\Throwable $error) {
      throw new \RuntimeException('WooCommerce order storage could not be verified',0,$error);
    }
    if(!is_bool($hpos)) throw new \RuntimeException('WooCommerce order storage could not be verified');
    $keys=['_roxy_legacy_product_ids'];
    foreach(['adult','discount','matinee','live1','live2','subscriber'] as $type)$keys[]='_roxy_pid_'.$type;
    $placeholders=implode(',',array_fill(0,count($keys),'%s'));
    $rows=$wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM `{$wpdb->postmeta}` WHERE post_id=%d AND meta_key IN ($placeholders)",$showing_id,...$keys),ARRAY_A);
    if($wpdb->last_error || !is_array($rows))throw new \RuntimeException('Showing product mapping could not be read');
    $ids=[];
    foreach($rows as $row) {
      if($row['meta_key']==='_roxy_legacy_product_ids') {
        $legacy=maybe_unserialize($row['meta_value']);
        foreach(is_array($legacy)?$legacy:preg_split('/[\r\n,]+/',(string)$legacy) as $id)if((int)$id>0)$ids[]=(int)$id;
      } elseif((int)$row['meta_value']>0)$ids[]=(int)$row['meta_value'];
    }
    $mapped=$wpdb->get_col($wpdb->prepare("SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key=%s AND meta_value=%s",ROXY_ST_META_SHOWING_ID,(string)$showing_id));
    if($wpdb->last_error || !is_array($mapped))throw new \RuntimeException('Product showing identities could not be read');
    foreach($mapped as $id)if((int)$id>0)$ids[]=(int)$id;
    $ids=array_unique($ids);
    if(!$ids)return 0;
    $mapping='CAST(product.meta_value AS UNSIGNED) IN ('.implode(',',$ids).')';
    if($subscriber_user_id>0)$mapping.=$wpdb->prepare(" AND EXISTS (SELECT 1 FROM `{$wpdb->postmeta}` t WHERE t.post_id=CAST(product.meta_value AS UNSIGNED) AND t.meta_key=%s AND t.meta_value='subscriber')",ROXY_ST_META_TICKET_TYPE);
    $items=$wpdb->prefix.'woocommerce_order_items';$meta=$wpdb->prefix.'woocommerce_order_itemmeta';
    $exclude=$exclude_order_id>0 ? $wpdb->prepare(' AND o.ID<>%d',$exclude_order_id) : '';
    if($subscriber_user_id>0)$exclude.=$wpdb->prepare(" AND EXISTS (SELECT 1 FROM `{$wpdb->postmeta}` u WHERE u.post_id=o.ID AND u.meta_key='_customer_user' AND u.meta_value=%s)",(string)$subscriber_user_id);
    $statuses="(o.post_status IN ('wc-processing','wc-completed','wc-on-hold') OR (o.post_status IN ('wc-pending','wc-checkout-draft') AND EXISTS (SELECT 1 FROM `{$wpdb->postmeta}` h WHERE h.post_id=o.ID AND h.meta_key='_roxy_seat_hold_until' AND CAST(h.meta_value AS UNSIGNED)>UNIX_TIMESTAMP())))";
    // Preserve original-quantity reservation policy; refunds/financials are not rewritten here.
    // Never let MySQL's permissive CAST turn malformed/fractional ticket counts
    // into zero (or partial seats). Validate matched lines in the same query.
    $valid_quantity="TRIM(q.meta_value) REGEXP '^[0-9]{1,19}([.]0+)?$'";
    if($hpos) {
      // Order-item tables are shared with HPOS; first restrict to matching Roxy
      // ticket lines, then page by order ID so neither PHP memory nor the
      // aggregate's IN() list grows with a showing's lifetime sales history.
      $now=$wpdb->get_var('SELECT UNIX_TIMESTAMP()');
      if($wpdb->last_error || !is_numeric($now) || (int)$now<=0)throw new \RuntimeException('Seat hold expiry could not be verified');
      $cursor=0;$reserved=0;
      do {
        $candidate_sql="SELECT DISTINCT i.order_id FROM `$items` i WHERE i.order_item_type='line_item' AND i.order_id>%d";
        if($exclude_order_id>0)$candidate_sql.=$wpdb->prepare(' AND i.order_id<>%d',$exclude_order_id);
        $candidate_sql.=" AND EXISTS (SELECT 1 FROM `$meta` product WHERE product.order_item_id=i.order_item_id AND product.meta_key='_product_id' AND ($mapping)) ORDER BY i.order_id ASC LIMIT 100";
        $candidate_ids=$wpdb->get_col($wpdb->prepare($candidate_sql,$cursor));
        if($wpdb->last_error || !is_array($candidate_ids))throw new \RuntimeException('Seat order candidates could not be read');
        if(!$candidate_ids)break;
        $candidate_ids=array_map('intval',$candidate_ids);
        if(min($candidate_ids)<=$cursor || count($candidate_ids)!==count(array_unique($candidate_ids)))throw new \RuntimeException('Seat order candidate page is invalid');
        $cursor=max($candidate_ids);
        $order_ids=self::hpos_reservation_order_ids($candidate_ids,$exclude_order_id,$subscriber_user_id,(int)$now);
        if(!$order_ids)continue;
        $order_list=implode(',',array_map('intval',$order_ids));
        $batch=$wpdb->get_row("SELECT COALESCE(SUM(CASE WHEN $valid_quantity THEN CAST(TRIM(q.meta_value) AS DECIMAL(65,0)) ELSE 0 END),0) AS reserved_quantity, COALESCE(SUM(CASE WHEN $valid_quantity THEN 0 ELSE 1 END),0) AS invalid_quantity_count FROM `$items` i LEFT JOIN `$meta` q ON q.order_item_id=i.order_item_id AND q.meta_key='_qty' WHERE i.order_item_type='line_item' AND i.order_id IN ($order_list) AND EXISTS (SELECT 1 FROM `$meta` product WHERE product.order_item_id=i.order_item_id AND product.meta_key='_product_id' AND ($mapping))",ARRAY_A);
        if($wpdb->last_error || !is_array($batch) || !is_numeric($batch['reserved_quantity']??null) || !is_numeric($batch['invalid_quantity_count']??null))throw new \RuntimeException('Seat reservations could not be read');
        if((float)$batch['invalid_quantity_count']>0)throw new \RuntimeException('Seat reservations contain an invalid order quantity');
        $batch_quantity=(string)$batch['reserved_quantity'];
        if(!preg_match('/^[0-9]+$/D',$batch_quantity))throw new \RuntimeException('Seat reservations contain an invalid total');
        if($reserved<PHP_INT_MAX) {
          $remaining=(string)(PHP_INT_MAX-$reserved);
          if(strlen($batch_quantity)>strlen($remaining) || (strlen($batch_quantity)===strlen($remaining) && strcmp($batch_quantity,$remaining)>=0))$reserved=PHP_INT_MAX;
          else $reserved=min(PHP_INT_MAX,$reserved+(int)$batch_quantity);
        }
      } while(count($candidate_ids)===100);
      $aggregate=['reserved_quantity'=>$reserved,'invalid_quantity_count'=>0];
    } else {
      $aggregate=$wpdb->get_row("SELECT COALESCE(SUM(CASE WHEN $valid_quantity THEN CAST(TRIM(q.meta_value) AS DECIMAL(65,0)) ELSE 0 END),0) AS reserved_quantity, COALESCE(SUM(CASE WHEN $valid_quantity THEN 0 ELSE 1 END),0) AS invalid_quantity_count FROM `$items` i JOIN `{$wpdb->posts}` o ON o.ID=i.order_id LEFT JOIN `$meta` q ON q.order_item_id=i.order_item_id AND q.meta_key='_qty' WHERE i.order_item_type='line_item' AND o.post_type='shop_order' AND $statuses$exclude AND EXISTS (SELECT 1 FROM `$meta` product WHERE product.order_item_id=i.order_item_id AND product.meta_key='_product_id' AND ($mapping))",ARRAY_A);
    }
    if($wpdb->last_error || !is_array($aggregate) || !array_key_exists('reserved_quantity',$aggregate) || !array_key_exists('invalid_quantity_count',$aggregate) || !is_numeric($aggregate['reserved_quantity']) || !is_numeric($aggregate['invalid_quantity_count']))throw new \RuntimeException('Seat reservations could not be read');
    if((float)$aggregate['invalid_quantity_count']>0)throw new \RuntimeException('Seat reservations contain an invalid order quantity');
    $value=$aggregate['reserved_quantity'];
    if((float)$value>=PHP_INT_MAX)return PHP_INT_MAX;
    return max(0,(int)ceil((float)$value));
  }
}
