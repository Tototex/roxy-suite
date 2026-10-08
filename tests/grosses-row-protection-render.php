<?php
/** WP-CLI: render real candidate edit forms without reading/writing financial rows. */
require_once ABSPATH.'wp-admin/includes/template.php';
$source=str_replace('namespace RoxyGrosses;','namespace RoxyGrossesRenderFixture37;',file_get_contents($args[0]));
eval('?>'.$source);
$checks=0;
foreach(['movie'=>'movies','live'=>'live','rental'=>'rentals','legacy'=>'legacy'] as $name=>$dataset) {
  $method=new ReflectionMethod(RoxyGrossesRenderFixture37\Settings::class,'render_'.$name.'_edit_row');
  $method->setAccessible(true);
  foreach([0,1] as $locked) {
    ob_start(); $method->invoke(null,['id'=>19000001,'is_locked'=>$locked],'<fixture>',2037,'01','02'); $html=ob_get_clean();
    $dom=new DOMDocument(); @$dom->loadHTML($html); $xpath=new DOMXPath($dom);
    foreach([
      'correct dataset'=>$xpath->query('//input[@name="dataset" and @value="'.$dataset.'"]')->length===1,
      'explicit unchecked zero'=>$xpath->query('//input[@name="is_locked" and @type="hidden" and @value="0"]')->length===1,
      'manual protection default'=>$xpath->query('//input[@name="is_locked" and @type="checkbox" and @value="1" and @checked]')->length===1,
      'nonce present'=>$xpath->query('//input[@name="_wpnonce"]')->length===1,
      'current state explained'=>strpos($html,$locked?'currently protected':'not currently protected')!==false,
    ] as $label=>$ok) {
      if(!$ok)throw new RuntimeException("$name/$locked: $label"); $checks++;
    }
  }
}
echo "Passed $checks actual WordPress edit-form rendering checks. No rows changed.\n";
