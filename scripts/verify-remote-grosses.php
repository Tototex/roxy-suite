<?php
require_once '/home1/anrvxfmy/public_html/wp-load.php';
global $wpdb;
$table = $wpdb->prefix . 'roxy_grosses_entries';
$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
$min = $wpdb->get_var("SELECT MIN(report_date) FROM {$table}");
$max = $wpdb->get_var("SELECT MAX(report_date) FROM {$table}");
echo "count={$count}\n";
echo "min={$min}\n";
echo "max={$max}\n";
?>
