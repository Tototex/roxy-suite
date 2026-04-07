<?php
require_once '/home1/anrvxfmy/public_html/wp-load.php';
require_once '/home1/anrvxfmy/public_html/wp-content/plugins/roxy-suite/roxy-suite.php';
\RoxyGrosses\Store::maybe_upgrade_schema();
global $wpdb;
$table = $wpdb->prefix . 'roxy_grosses_legacy_weekly';
$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
$min = $wpdb->get_var("SELECT MIN(week_start_date) FROM {$table}");
$max = $wpdb->get_var("SELECT MAX(week_start_date) FROM {$table}");
echo "count={$count}\n";
echo "min={$min}\n";
echo "max={$max}\n";
?>
