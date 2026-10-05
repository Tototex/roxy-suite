<?php
// Unit-test plumbing only. Real transaction/locking assertions live in MySQL/Woo fixtures.
namespace RoxyST {
  class Issuance {
    public function __construct(int $id) {}
    public function run(callable $callback) {
      $before=$GLOBALS['meta']??[];
      try{return $callback($this);}catch(\Throwable $e){$GLOBALS['meta']=$before;throw $e;}
    }
    public function post_meta_value(int $id,string $key){return \get_post_meta($id,$key,true);}
    public function is_ticket(int $id):bool{return \get_post_type($id)==='roxy_ticket';}
    public function post_meta(int $id,string $key,$value,bool $remove=false):void {
      if($remove)\delete_post_meta($id,$key);else \update_post_meta($id,$key,$value);
    }
  }
  class Log {public static function error(...$args):void{}}
}
namespace {
  if(!function_exists('clean_post_cache')){function clean_post_cache($id){}}
  if(!function_exists('wp_cache_delete')){function wp_cache_delete(...$args){}}
}
