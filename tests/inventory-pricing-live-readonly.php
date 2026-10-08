<?php
// Run with wp eval-file. Reads and renders only: no orders, mail, pulls or saves.
if (!defined('ABSPATH')) exit(1);
global $wpdb;
foreach (['products_table','vendors_table','orders_table'] as $method) {
    $table = \RoxyInventory\Store::$method();
    $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY id", ARRAY_A);
    if ($wpdb->last_error || !is_array($rows)) throw new RuntimeException('Inventory snapshot read failed.');
    echo json_encode(['snapshot'=>$method,'count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows))]) . "\n";
}
$render = static function (string $method, array $arguments = []): string {
    ob_start();
    try { (new ReflectionMethod(\RoxyInventory\Admin::class,$method))->invokeArgs(null,$arguments); return ob_get_contents(); }
    finally { ob_end_clean(); }
};
$dashboard = $render('dashboard');
echo json_encode(['render'=>'dashboard','bytes'=>strlen($dashboard),'incomplete_labels'=>substr_count($dashboard,'Incomplete'),'review_links'=>substr_count($dashboard,'Review Order')]) . "\n";
foreach (\RoxyInventory\Store::vendors() as $vendor) {
    $html = $render('vendor_order',[(string)$vendor['name']]);
    echo json_encode(['render'=>'vendor','name'=>$vendor['name'],'bytes'=>strlen($html),'unknown_labels'=>substr_count($html,'Unknown'),'incomplete_labels'=>substr_count($html,'Incomplete')]) . "\n";
}
$history = $render('history');
echo json_encode(['render'=>'history','bytes'=>strlen($history),'incomplete_labels'=>substr_count($history,'Incomplete')]) . "\n";
