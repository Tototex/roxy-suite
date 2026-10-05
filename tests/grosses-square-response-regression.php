<?php
namespace RoxyGrosses {
  final class Settings {
    public static $locations='fixture-location';
    public static function get_all(){return ['square_location_ids'=>self::$locations,'square_environment'=>'sandbox'];}
    public static function square_access_token(){return 'fixture-only';}
    public static function get_report_timezone(){return 'America/Los_Angeles';}
    public static function line_list($v){return array_filter(explode("\n",$v));}
  }
}
namespace {
  define('ABSPATH',__DIR__);
  final class FixtureNetworkError extends \RuntimeException {public function get_error_message(){return 'fixture network error';}}
  function wp_json_encode($v){return json_encode($v);}
  function is_wp_error($r){return $r instanceof \RuntimeException;}
  function wp_remote_retrieve_response_code($r){return $r['status'];}
  function wp_remote_retrieve_body($r){return $r['body'];}
  function wp_remote_post($url,$args){$GLOBALS['calls'][]=[$url,$args];return array_shift($GLOBALS['responses'])??throw new \LogicException('Unexpected HTTP fixture call');}
  function response($data,$status=200){return ['status'=>$status,'body'=>json_encode($data)];}
  function check($ok,$label){if(!$ok)throw new \RuntimeException($label);echo "PASS: $label\n";}
  function reset_fixture($responses){$GLOBALS['responses']=$responses;$GLOBALS['calls']=[];\RoxyGrosses\Settings::$locations='fixture-location';}
  function must_fail($responses,$label){reset_fixture($responses);try{\RoxyGrosses\Square::fetch_orders_for_date('2026-10-03');throw new \LogicException('Failure expected: '.$label);}catch(\RuntimeException $error){check(true,$label);}}
  require ($argv[1]??dirname(__DIR__)).'/includes/modules/grosses/includes/class-roxy-grosses-square.php';
  foreach([['status'=>200,'body'=>'broken'],response(null),response([]),response('text'),response(['errors'=>[['detail'=>'failure']]]),response(['orders'=>null]),response(['orders'=>'bad']),response(['orders'=>['bad']]),response(['orders'=>[[]]]),response(['orders'=>['not-list'=>['id'=>'a']]]),response(['order_entries'=>[]]),response(['orders'=>[['id'=>'a']]],429),response(['orders'=>[['id'=>'a']]],500),new FixtureNetworkError('network failure')] as $r)must_fail([$r],'malformed/HTTP/error/incomplete order response cannot become a sales report');
  reset_fixture([['status'=>200,'body'=>'{}']]);check(\RoxyGrosses\Square::fetch_orders_for_date('2026-10-03')===[],'valid empty object remains a legitimate zero-order result');
  must_fail([response(['orders'=>(object)[]])],'JSON object cannot masquerade as an empty order list');
  must_fail([response(['errors'=>''])],'malformed error field cannot become silent success');
  reset_fixture([response(['orders'=>[]])]);check(\RoxyGrosses\Square::fetch_orders_for_date('2026-10-03')===[],'valid empty orders list remains legitimate');
  reset_fixture([response(['orders'=>[['id'=>'a']],'cursor'=>'next']),response(['orders'=>[['id'=>'b']]])]);
  check(count(\RoxyGrosses\Square::fetch_orders_for_date('2026-10-03'))===2,'complete cursor retrieval returns all unique orders');
  $first=json_decode($GLOBALS['calls'][0][1]['body'],true);$second=json_decode($GLOBALS['calls'][1][1]['body'],true);
  check($first['query']===$second['query']&&$second['cursor']==='next'&&$first['return_entries']===false,'pagination preserves full-order query and cursor');
  check($GLOBALS['calls'][0][1]['redirection']===0&&$GLOBALS['calls'][0][1]['timeout']<=25,'trusted endpoint disables redirects and bounds timeout');
  must_fail([response(['orders'=>[['id'=>'a']],'cursor'=>'next']),response(['orders'=>[['id'=>'a']]])],'repeated order ID cannot inflate gross');
  must_fail([response(['cursor'=>'same']),response(['cursor'=>'same'])],'repeated cursor cannot hang worker or return partial totals');
  foreach([null,'',[],str_repeat('x',10001)] as $cursor)must_fail([response(['cursor'=>$cursor])],'invalid cursor fails closed');
  $pages=[];for($n=1;$n<=101;$n++)$pages[]=response(['cursor'=>'cursor-'.$n]);
  must_fail($pages,'page limit throws instead of returning incomplete totals');check(count($GLOBALS['calls'])===100,'page cap prevents request 101');
  $window=new \ReflectionMethod(\RoxyGrosses\Square::class,'date_window');$window->setAccessible(true);
  foreach(['2026-02-30','2026-13-01','2026-1-1','2026-10-03junk',''] as $date){try{$window->invoke(null,$date,'America/Los_Angeles');throw new \LogicException('Expected invalid date');}catch(\RuntimeException $e){check(true,'invalid date cannot silently roll into another day');}}
  foreach(['2026-03-08'=>23,'2026-11-01'=>25] as $date=>$hours){[$start,$end]=$window->invoke(null,$date,'America/Los_Angeles');check((strtotime($end)-strtotime($start))/3600===$hours,'site calendar window preserves '.$hours.'-hour DST day');}
  reset_fixture([]);\RoxyGrosses\Settings::$locations=implode("\n",range(1,11));
  try{\RoxyGrosses\Square::fetch_orders_for_date('2026-10-03');throw new \LogicException('Expected location failure');}catch(\RuntimeException $e){check(!$GLOBALS['calls'],'unsupported location count fails before provider call');}
}
