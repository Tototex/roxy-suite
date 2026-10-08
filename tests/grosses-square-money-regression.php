<?php
namespace RoxyGrosses {
  final class Square {
    public static array $orders=[];
    public static function fetch_orders_for_date(string $date):array{return self::$orders;}
    public static function concession_reporting_categories(array $ids):array{return ['fixture'=>'In Store Purchase'];}
    public static function is_in_store_purchase_item(string $id,array $categories):bool{return ($categories[$id]??'')==='In Store Purchase';}
  }
}
namespace {
  define('ABSPATH',__DIR__.'/');
  $root=$argv[1]??dirname(__DIR__);
  require $argv[2]??$root.'/includes/modules/grosses/includes/class-roxy-grosses-reporter.php';
  $methods=[];
  foreach(['square_line_item_concession_cents','square_line_item_total_cents','square_line_item_total','square_in_store_purchase_total_for_date'] as $name){$methods[$name]=new ReflectionMethod(RoxyGrosses\Reporter::class,$name);$methods[$name]->setAccessible(true);}
  $money=static fn(int $amount):array=>['amount'=>$amount,'currency'=>'USD'];
  $count=0;
  $assert=static function($ok,$label)use(&$count){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";$count++;};
  $cases=[
    'exclusive tax'=>[['gross_sales_money'=>$money(1000),'total_tax_money'=>$money(90),'total_money'=>$money(1090)],1000,1090],
    'inclusive US tax'=>[['gross_sales_money'=>$money(909),'total_tax_money'=>$money(91),'total_money'=>$money(1000)],909,1000],
    'discount and tax'=>[['gross_sales_money'=>$money(1000),'total_discount_money'=>$money(200),'total_tax_money'=>$money(72),'total_money'=>$money(872)],800,872],
    'aggregate includes modifiers'=>[['quantity'=>'3','gross_sales_money'=>$money(1800),'total_tax_money'=>$money(162),'total_money'=>$money(1962)],1800,1962],
    'free line'=>[['gross_sales_money'=>$money(1000),'total_discount_money'=>$money(1000),'total_money'=>$money(0),'total_tax_money'=>$money(0)],0,0],
    'allocated service charge'=>[['gross_sales_money'=>$money(1000),'total_discount_money'=>$money(200),'total_service_charge_money'=>$money(100),'total_tax_money'=>$money(81),'total_money'=>$money(981)],900,981],
    'gross-only fallback'=>[['gross_sales_money'=>$money(1000),'total_discount_money'=>$money(200),'total_tax_money'=>$money(72)],800,872],
    'gross-only charge fallback'=>[['gross_sales_money'=>$money(1000),'total_discount_money'=>$money(200),'total_service_charge_money'=>$money(100),'total_tax_money'=>$money(81)],900,981],
    'legacy aggregate not per-unit'=>[['quantity'=>'3','total_base_price_money'=>$money(300)],300,300],
  ];
  $orders=[];$sum=0;
  foreach($cases as $name=>[$line,$net,$total]){
    $assert($methods['square_line_item_concession_cents']->invoke(null,$line)===$net,"$name pre-tax revenue");
    $assert($methods['square_line_item_total_cents']->invoke(null,$line)===$total&&$methods['square_line_item_total']->invoke(null,$line,3)===(float)($total/100),"$name aggregate total not quantity-multiplied");
    $line['catalog_object_id']='fixture';$orders[]=['line_items'=>[$line]];$sum+=$net;
  }
  RoxyGrosses\Square::$orders=$orders;
  $assert($methods['square_in_store_purchase_total_for_date']->invoke(null,'2039-01-02')===(float)($sum/100),'daily reconciliation and allocation share exact same net calculation');
  foreach([
    'missing totals'=>['quantity'=>'1'],
    'legacy tax ambiguity'=>['total_base_price_money'=>$money(1000),'total_tax_money'=>$money(90)],
    'noninteger amount'=>['total_money'=>['amount'=>'1000','currency'=>'USD']],
    'negative sale money'=>['total_money'=>$money(-1)],
    'foreign currency'=>['total_money'=>['amount'=>1000,'currency'=>'EUR']],
    'malformed money'=>['total_money'=>'bad'],
  ] as $name=>$line){$threw=false;try{$methods['square_line_item_concession_cents']->invoke(null,$line);}catch(RuntimeException $e){$threw=true;}$assert($threw,"$name fails closed rather than calculating misleading money");}
  echo "Passed $count Square money checks. Synthetic data; no accounting/mail writes.\n";
}
