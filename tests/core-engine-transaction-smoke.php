<?php
// Actual four-table transaction with rollback only; no hooks, emails, payments or public posts.
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('CLI only');
global $wpdb;
if($wpdb->prefix!=='NnW_')throw new RuntimeException('Unexpected prefix');
$tables=['NnW_posts','NnW_postmeta','NnW_woocommerce_order_items','NnW_woocommerce_order_itemmeta'];
foreach($tables as $table){$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if($engine!=='InnoDB')throw new RuntimeException('Transactional engine required before inserting probes');}
function engine_smoke_check($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;}
$second=null;$post_id=0;$item_id=0;$marker='ROXY PRIVATE ENGINE ROLLBACK '.wp_generate_uuid4();
if(false===$wpdb->query('START TRANSACTION'))throw new RuntimeException('Cannot start transaction');
try{
    engine_smoke_check(1===$wpdb->insert('NnW_posts',['post_type'=>'roxy_engine_probe','post_status'=>'draft','post_title'=>$marker,'post_content'=>'Private transaction probe; must be rolled back','post_date'=>current_time('mysql'),'post_date_gmt'=>current_time('mysql',true),'post_modified'=>current_time('mysql'),'post_modified_gmt'=>current_time('mysql',true)]),'private post insert participates in transaction');$post_id=(int)$wpdb->insert_id;
    engine_smoke_check(1===$wpdb->insert('NnW_postmeta',['post_id'=>$post_id,'meta_key'=>'_roxy_engine_probe','meta_value'=>$marker]),'post metadata insert participates in transaction');
    engine_smoke_check(1===$wpdb->insert('NnW_woocommerce_order_items',['order_item_name'=>$marker,'order_item_type'=>'roxy_engine_probe','order_id'=>$post_id]),'order item insert participates in transaction');$item_id=(int)$wpdb->insert_id;
    engine_smoke_check(1===$wpdb->insert('NnW_woocommerce_order_itemmeta',['order_item_id'=>$item_id,'meta_key'=>'_roxy_engine_probe','meta_value'=>$marker]),'order item metadata insert participates in transaction');
    engine_smoke_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM NnW_posts WHERE ID=%d',$post_id))===1&&(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM NnW_woocommerce_order_itemmeta WHERE order_item_id=%d',$item_id))===1,'transaction sees its own complete probe');
    $second=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    engine_smoke_check((int)$second->get_var($second->prepare('SELECT COUNT(*) FROM NnW_posts WHERE ID=%d',$post_id))===0&&(int)$second->get_var($second->prepare('SELECT COUNT(*) FROM NnW_woocommerce_order_items WHERE order_item_id=%d',$item_id))===0,'second connection cannot see uncommitted records');
}finally{
    $wpdb->query('ROLLBACK');if($second)$second->close();
}
engine_smoke_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM NnW_posts WHERE ID=%d',$post_id))===0,'rollback removes private post');
engine_smoke_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM NnW_postmeta WHERE post_id=%d',$post_id))===0,'rollback removes post metadata');
engine_smoke_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM NnW_woocommerce_order_items WHERE order_item_id=%d',$item_id))===0,'rollback removes order item');
engine_smoke_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM NnW_woocommerce_order_itemmeta WHERE order_item_id=%d',$item_id))===0,'rollback removes order item metadata');
echo 'FOUR_TABLE_TRANSACTION_ROLLBACK_OK (unused auto-increment gaps are expected)'.PHP_EOL;
