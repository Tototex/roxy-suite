<?php
// Unit-test plumbing only. Real transaction/locking assertions live in MySQL/Woo fixtures.
namespace RoxyST {
  class Issuance {
    public function __construct(int|array $ids,string|array $scope='') {}
    public function run(callable $callback) {
      $before=$GLOBALS['meta']??[];
      $baseline=$GLOBALS['baseline']??0;
      try{return $callback($this);}catch(\Throwable $e){$GLOBALS['meta']=$before;$GLOBALS['baseline']=$baseline;throw $e;}
    }
    public function post_meta_value(int $id,string $key){return \get_post_meta($id,$key,true);}
    public function is_ticket(int $id):bool{return \get_post_type($id)==='roxy_ticket';}
    public function post_meta(int $id,string $key,$value,bool $remove=false):void {
      if($remove)\delete_post_meta($id,$key);else \update_post_meta($id,$key,$value);
    }
    public function will_call_quantity(int $id,string $key):int{return (int)($GLOBALS['baseline']??0);}
    public function member_visit(array $row):bool{return true;}
    public function member_walkup_quantity(int $show,int $sub=0):int{return (int)($GLOBALS['walkup']??0);}
    public function reserved_seats(int $show):int{return (int)($GLOBALS['reserved_seats']??0);}
    public function will_call_summary(int $id,string $key,int $qty):void {
      if($GLOBALS['wpdb']->replace()===false)throw new \RuntimeException('Attendance summary write failed');
      $GLOBALS['baseline']=$qty;
    }
  }
  class Log {public static function error(...$args):void{}}
}
namespace {
  if(!function_exists('clean_post_cache')){function clean_post_cache($id){}}
  if(!function_exists('wp_cache_delete')){function wp_cache_delete(...$args){}}
}
