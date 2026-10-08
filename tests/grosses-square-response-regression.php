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
  function wp_remote_get($url,$args){return wp_remote_post($url,$args);}
  function response($data,$status=200){return ['status'=>$status,'body'=>json_encode($data)];}
  function check($ok,$label){if(!$ok)throw new \RuntimeException($label);echo "PASS: $label\n";}
  function reset_fixture($responses){$GLOBALS['responses']=$responses;$GLOBALS['calls']=[];\RoxyGrosses\Settings::$locations='fixture-location';}
  function must_fail($responses,$label){reset_fixture($responses);try{\RoxyGrosses\Square::fetch_orders_for_date('2026-10-03');throw new \LogicException('Failure expected: '.$label);}catch(\RuntimeException $error){check(true,$label);}}
  require ($argv[2]??(($argv[1]??dirname(__DIR__)).'/includes/modules/grosses/includes/class-roxy-grosses-square.php'));
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
  reset_fixture([response(['orders'=>[['id'=>'range-a']],'cursor'=>'next']),response(['orders'=>[['id'=>'range-b']]])]);
  check(count(\RoxyGrosses\Square::fetch_orders_for_range('2026-03-08','2026-03-09'))===2,'one bounded date-range query fully paginates all orders');
  $range_body=json_decode($GLOBALS['calls'][0][1]['body'],true);
  check($range_body['query']['filter']['date_time_filter']['closed_at']===['start_at'=>'2026-03-08T08:00:00+00:00','end_at'=>'2026-03-10T07:00:00+00:00'],'range request uses local calendar boundaries across DST');
  check(json_decode($GLOBALS['calls'][0][1]['body'],true)['query']===json_decode($GLOBALS['calls'][1][1]['body'],true)['query'],'range pagination preserves the same filter');
  foreach([['2026-02-30','2026-03-01'],['2026-03-09','2026-03-08']] as [$from,$to]){
    reset_fixture([]);try{\RoxyGrosses\Square::fetch_orders_for_range($from,$to);throw new \LogicException('Expected invalid Square date range');}catch(\RuntimeException $e){check(!$GLOBALS['calls'],'invalid or reversed date range fails before provider request');}
  }
  reset_fixture([response(['orders'=>[['id'=>'cached-range']]])]);
  \RoxyGrosses\Square::with_sale_snapshot(static function(){
    \RoxyGrosses\Square::fetch_orders_for_range('2026-03-08','2026-03-09');
    \RoxyGrosses\Square::fetch_orders_for_range('2026-03-08','2026-03-09');
  });
  check(count($GLOBALS['calls'])===1,'same date range is fetched once per managed snapshot');
  reset_fixture([]);\RoxyGrosses\Settings::$locations=implode("\n",range(1,11));
  try{\RoxyGrosses\Square::fetch_orders_for_date('2026-10-03');throw new \LogicException('Expected location failure');}catch(\RuntimeException $e){check(!$GLOBALS['calls'],'unsupported location count fails before provider call');}
  reset_fixture([response(['orders'=>[['id'=>'return-a']],'cursor'=>'n']),response(['orders'=>[['id'=>'return-b']]])]);
  check(count(\RoxyGrosses\Square::fetch_orders_updated_between('2026-10-01T00:00:00Z','2026-10-06T00:00:00Z'))===2,'separate updated-order discovery fully paginates');
  $body=json_decode($GLOBALS['calls'][0][1]['body'],true);
  check(isset($body['query']['filter']['date_time_filter']['updated_at'])&&$body['query']['sort']['sort_field']==='UPDATED_AT','return discovery uses update window, not original close date');
  foreach([['2026-02-30T00:00:00Z','2026-10-06T00:00:00Z'],['2026-10-01','2026-10-06'],['2026-10-06T00:00:00Z','2026-10-01T00:00:00Z']] as [$start,$end]){
    reset_fixture([]);try{\RoxyGrosses\Square::fetch_orders_updated_between($start,$end);throw new \LogicException('Expected invalid timestamp');}catch(\RuntimeException $e){check(!$GLOBALS['calls'],'invalid return window fails before network request');}
  }
  reset_fixture([response(['orders'=>[['id'=>'a'],['id'=>'b']]])]);
  check(count(\RoxyGrosses\Square::retrieve_orders(['a','b','a']))===2,'source batch lookup deduplicates requested identities');
  foreach([response(['orders'=>[['id'=>'a']]]),response(['orders'=>[['id'=>'a'],['id'=>'a']]]),response(['orders'=>[['id'=>'c']]]),response([])] as $r){
    reset_fixture([$r]);try{\RoxyGrosses\Square::retrieve_orders(['a','b']);throw new \LogicException('Expected incomplete source failure');}catch(\RuntimeException $e){check(true,'missing/duplicate/unexpected source identity cannot calculate correction');}
  }
  reset_fixture([response(['refund'=>['id'=>'refund-a','status'=>'COMPLETED']])]);
  check(\RoxyGrosses\Square::retrieve_payment_refund('refund-a')['status']==='COMPLETED','payment-refund status is read from provider');
  foreach([['id'=>'other','status'=>'COMPLETED'],['id'=>'refund-a','status'=>'APPROVED']] as $refund){reset_fixture([response(['refund'=>$refund])]);try{\RoxyGrosses\Square::retrieve_payment_refund('refund-a');throw new \LogicException('Expected invalid status');}catch(\RuntimeException $e){check(true,'wrong identity or legacy order status cannot masquerade as confirmed payment refund');}}
  $refund = ['id'=>'feed-a','location_id'=>'fixture-location','status'=>'COMPLETED','updated_at'=>'2026-10-03T12:00:00Z'];
  reset_fixture([response(['refunds'=>[$refund],'cursor'=>'next']),response(['refunds'=>[array_replace($refund,['id'=>'feed-b'])]])]);
  check(count(\RoxyGrosses\Square::list_payment_refunds_updated_between('2026-10-01T00:00:00Z','2026-10-06T00:00:00Z'))===2,'refund-specific feed retrieves every unique page');
  parse_str(parse_url($GLOBALS['calls'][0][0],PHP_URL_QUERY),$first_query);
  parse_str(parse_url($GLOBALS['calls'][1][0],PHP_URL_QUERY),$next_query);
  check($first_query['sort_field']==='UPDATED_AT' && $first_query['updated_at_begin_time']==='2026-10-01T00:00:00+00:00' && $first_query['begin_time']==='2000-01-01T00:00:00Z','refund feed uses update dates without the implicit one-year creation cutoff');
  check($next_query['cursor']==='next' && array_diff_assoc($first_query,$next_query)===[],'refund pagination retains location and exact original filters');
  $failure_sets = [
    [response(['refunds'=>null])], [response(['refunds'=>(object)[]])], [response(['refunds'=>['wrong-key'=>$refund]])],
    [response(['refunds'=>[array_replace($refund,['location_id'=>'other'])]])],
    [response(['refunds'=>[array_replace($refund,['status'=>'UNKNOWN'])]])],
    [response(['refunds'=>[array_replace($refund,['updated_at'=>'2026-10-07T12:00:00Z'])]])],
    [response(['refunds'=>[array_replace($refund,['updated_at'=>'2026-09-01T12:00:00Z'])]])],
    [response(['refunds'=>[array_replace($refund,['updated_at'=>'2026-02-30T12:00:00Z'])]])],
    [response(['refunds'=>[$refund,$refund]])], [response(['refunds'=>[$refund],'cursor'=>'next']),response(['refunds'=>[$refund]])],
    [response(['cursor'=>'repeat']),response(['cursor'=>'repeat'])], [response(['cursor'=>null])], [response(['cursor'=>''])],
    [response(['refunds'=>[]],503)], new FixtureNetworkError('network failure'),
  ];
  foreach($failure_sets as $responses){if($responses instanceof FixtureNetworkError)$responses=[$responses];reset_fixture($responses);try{\RoxyGrosses\Square::list_payment_refunds_updated_between('2026-10-01T00:00:00Z','2026-10-06T00:00:00Z');throw new \LogicException('Expected invalid feed failure');}catch(\RuntimeException $e){check(true,'malformed/misplaced/duplicate/incomplete refund feed cannot become partial accounting');}}
  reset_fixture([['status'=>200,'body'=>'{}']]);check(\RoxyGrosses\Square::list_payment_refunds_updated_between('2026-10-01T00:00:00Z','2026-10-06T00:00:00Z')===[],'legitimate empty refund feed is preserved');
  reset_fixture([response(['refunds'=>[$refund]]),response(['refunds'=>[array_replace($refund,['id'=>'feed-other','location_id'=>'second-location'])]])]);
  \RoxyGrosses\Settings::$locations="fixture-location\nsecond-location\nfixture-location";
  check(count(\RoxyGrosses\Square::list_payment_refunds_updated_between('2026-10-01T00:00:00Z','2026-10-06T00:00:00Z'))===2 && count($GLOBALS['calls'])===2,'configured locations are deduped and read independently');
  reset_fixture([]);try{\RoxyGrosses\Square::list_payment_refunds_updated_between('2026-10-06T00:00:00Z','2026-10-01T00:00:00Z');throw new \LogicException('Expected invalid window');}catch(\RuntimeException $e){check(!$GLOBALS['calls'],'invalid refund-feed date window fails before network');}
  reset_fixture([]);try{\RoxyGrosses\Square::list_payment_refunds_updated_between('2026-10-01T00:00:00Z','2026-10-06T00:00:00Z',microtime(true)-1);throw new \LogicException('Expected deadline failure');}catch(\RuntimeException $e){check(!$GLOBALS['calls'],'expired shared refund-feed deadline fails before network');}
  $payment=['id'=>'payment-a','location_id'=>'fixture-location','order_id'=>'order-a','status'=>'COMPLETED','created_at'=>'2026-10-03T12:00:00Z','amount_money'=>['amount'=>1234,'currency'=>'USD']];
  reset_fixture([response(['payments'=>[$payment],'cursor'=>'next']),response(['payments'=>[array_replace($payment,['id'=>'payment-b'])]])]);
  check(count(\RoxyGrosses\Square::list_payments_created_between('2026-10-03T00:00:00Z','2026-10-04T00:00:00Z'))===2,'payment feed retrieves all pages for the exact created-time range');
  parse_str(parse_url($GLOBALS['calls'][0][0],PHP_URL_QUERY),$payment_query);
  parse_str(parse_url($GLOBALS['calls'][1][0],PHP_URL_QUERY),$payment_next_query);
  check($payment_query['begin_time']==='2026-10-03T00:00:00+00:00' && $payment_query['end_time']==='2026-10-03T23:59:59.999999+00:00' && $payment_query['location_id']==='fixture-location','payment feed filters by created time and excludes next-day midnight at its inclusive end boundary');
  check($payment_next_query['cursor']==='next' && $payment_next_query['begin_time']===$payment_query['begin_time'] && $payment_next_query['location_id']===$payment_query['location_id'],'payment pagination retains original date/location filters');
  foreach([
    response(['payments'=>null]), response(['payments'=>['wrong-key'=>$payment]]),
    response(['payments'=>[array_replace($payment,['location_id'=>'wrong-location'])]]),
    response(['payments'=>[$payment,$payment]]),
    response(['payments'=>[array_replace($payment,['created_at'=>'2026-10-04T00:00:00Z'])]]),
    response(['payments'=>[array_replace($payment,['created_at'=>'2026-02-30T12:00:00Z'])]]),
    response(['payments'=>[array_replace($payment,['status'=>'UNKNOWN'])]]),
    response(['payments'=>[$payment],'cursor'=>'next']), response(['payments'=>[],'cursor'=>'next']),
    response(['payments'=>[['id'=>'payment-a','location_id'=>'fixture-location','status'=>'COMPLETED']]]),
    response(['payments'=>[$payment]],503), new FixtureNetworkError('network failure'),
  ] as $bad){reset_fixture([$bad]);try{\RoxyGrosses\Square::list_payments_created_between('2026-10-03T00:00:00Z','2026-10-04T00:00:00Z');throw new \LogicException('Expected invalid payment feed failure');}catch(\RuntimeException $e){check(true,'malformed, misplaced, duplicate, incomplete, or failed Square payment feed cannot become partial totals');}}
  reset_fixture([['status'=>200,'body'=>'{}']]);check(\RoxyGrosses\Square::list_payments_created_between('2026-10-03T00:00:00Z','2026-10-04T00:00:00Z')===[],'legitimate empty Square payment feed remains zero collections');
}
