<?php
// Real WordPress and WooCommerce data readers; never prints customer details or changes orders.
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('CLI only');
function engine_read_check($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;}
$orders=wc_get_orders(['limit'=>5,'orderby'=>'date','order'=>'DESC']);
engine_read_check(count($orders)>0,'WooCommerce loads recent orders through its normal data layer');
foreach($orders as $order){engine_read_check($order instanceof WC_Order&&$order->get_id()>0&&is_numeric($order->get_total()),'recent order identity and total load normally');$items=$order->get_items();foreach($items as $item)engine_read_check($item->get_id()>0&&is_numeric($item->get_quantity()),'order line item identity and quantity load normally');}
$products=wc_get_products(['limit'=>5,'status'=>'publish']);engine_read_check(count($products)>0,'published WooCommerce products load normally');
$shows=get_posts(['post_type'=>'roxy_showing','post_status'=>'publish','posts_per_page'=>5]);engine_read_check(count($shows)>0,'published showings load through WordPress');
foreach($shows as $show)engine_read_check(is_string(get_post_meta($show->ID,'_roxy_start',true))&&get_permalink($show->ID)!=='','showing metadata and permalink load normally');
if(function_exists('wcs_get_subscriptions')){$subscriptions=wcs_get_subscriptions(['subscriptions_per_page'=>5,'subscription_status'=>'active']);engine_read_check(count($subscriptions)>0,'active memberships load through Woo Subscriptions');}
echo 'CORE_WORDPRESS_WOO_READ_SMOKE_OK'.PHP_EOL;
