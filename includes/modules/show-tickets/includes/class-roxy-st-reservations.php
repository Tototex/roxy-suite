<?php
namespace RoxyST;
if (!defined('ABSPATH')) exit;

/** Read-only seat authority, separate from cached financial/reporting projections. */
final class Reservations {
  public static function quantity_for_showing(int $showing_id, int $exclude_order_id = 0, int $subscriber_user_id = 0): int {
    global $wpdb;
    if($showing_id<=0) throw new \RuntimeException('Invalid showing');
    if(class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) throw new \RuntimeException('Seat reservation authority requires the currently supported order storage.');
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
    $value=$wpdb->get_var("SELECT COALESCE(SUM(CAST(q.meta_value AS DECIMAL(20,4))),0) FROM `$items` i JOIN `{$wpdb->posts}` o ON o.ID=i.order_id JOIN `$meta` q ON q.order_item_id=i.order_item_id AND q.meta_key='_qty' WHERE i.order_item_type='line_item' AND o.post_type='shop_order' AND $statuses$exclude AND EXISTS (SELECT 1 FROM `$meta` product WHERE product.order_item_id=i.order_item_id AND product.meta_key='_product_id' AND ($mapping))");
    if($wpdb->last_error || $value===null || !is_numeric($value))throw new \RuntimeException('Seat reservations could not be read');
    if((float)$value>=PHP_INT_MAX)return PHP_INT_MAX;
    return max(0,(int)ceil((float)$value));
  }
}
