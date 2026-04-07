<?php
$path = '/home1/anrvxfmy/public_html/wp-content/plugins/roxy-suite/scripts/legacy-grosses-import-all.csv';
$h = fopen($path, 'rb');
$header = fgetcsv($h);
$header = array_map(static function($value) {
    $value = (string) $value;
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    return trim($value);
}, $header ?: []);
$row = fgetcsv($h);
var_export($header);
echo PHP_EOL;
var_export($row);
echo PHP_EOL;
if (is_array($header) && is_array($row)) {
    var_export(array_combine($header, $row));
    echo PHP_EOL;
}
fclose($h);
?>
