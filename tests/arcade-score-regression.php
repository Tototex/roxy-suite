<?php
// Isolated REST/write contract checks; no live scores or prizes.
define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');
function add_action(...$args){} function sanitize_key($v){return (string)$v;}
function absint($v){return abs((int)$v);}function wp_verify_nonce($n,$a){return $n==='fixture';}
function is_user_logged_in(){return $GLOBALS['logged_in']??true;}function get_current_user_id(){return 123;}
function get_transient($key){return [];}function set_transient(...$args){}function sanitize_text_field($v){return $v;}function wp_unslash($v){return $v;}
class WP_REST_Request {function __construct(private $score=10,private $game='marquee',private $nonce='fixture'){}function get_header($key){return $this->nonce;}function get_param($key){return $key==='game'?$this->game:$this->score;}}
class WP_REST_Response {function __construct(public $data,public $status){}}
class ScoreDatabase {
    public $prefix='fixture_';public $queries=[];public $result=1;public $values=[];
    function prepare($sql,...$args){$this->values=$args;return $sql;}
    function query($sql){$this->queries[]=$sql;return $this->result;}
    function get_results(...$args){return [];}
}
$GLOBALS['wpdb']=new ScoreDatabase;
require ($argv[1]??dirname(__DIR__)).'/includes/modules/arcade/roxy-arcade.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$method=new ReflectionMethod('Roxy_Arcade','upsert_best_score');
check($method->invoke(null,123,'marquee',10),'successful insert reports success');
$sql=end($GLOBALS['wpdb']->queries);
check(str_contains($sql,'GREATEST(best_score, VALUES(best_score))'),'one atomic statement retains maximum score');
check(strpos($sql,'updated_at = IF')<strpos($sql,'best_score = GREATEST'),'timestamp compared before score assignment');
check(str_contains($sql,'VALUES(best_score) > best_score'),'equal and lower attempts retain tie-break timestamp');
check(count($GLOBALS['wpdb']->queries)===1,'save performs no stale pre-read');
$GLOBALS['wpdb']->result=0;check($method->invoke(null,123,'marquee',10),'unchanged score is successful no-op');
$count=count($GLOBALS['wpdb']->queries);check($method->invoke(null,123,'marquee',0)&&count($GLOBALS['wpdb']->queries)===$count,'zero does not create a leaderboard entry');
$GLOBALS['wpdb']->result=false;check(!$method->invoke(null,123,'marquee',10),'database write failure propagated');
$response=Roxy_Arcade::rest_post_score(new WP_REST_Request);
check($response->status===503&&$response->data['ok']===false&&!isset($response->data['leaderboards']),'REST never reports failed persistence as saved');
$GLOBALS['wpdb']->result=1;$response=Roxy_Arcade::rest_post_score(new WP_REST_Request);
check($response->status===200&&$response->data['ok']===true&&isset($response->data['leaderboards']),'successful REST response remains compatible');
$response=Roxy_Arcade::rest_post_score(new WP_REST_Request(10,'marquee','bad'));check($response->status===403,'nonce guard retained');
$response=Roxy_Arcade::rest_post_score(new WP_REST_Request(10,'invalid'));check($response->status===400,'game guard retained');
$GLOBALS['logged_in']=false;$count=count($GLOBALS['wpdb']->queries);$response=Roxy_Arcade::rest_post_score(new WP_REST_Request);
check($response->data['guest']===true&&count($GLOBALS['wpdb']->queries)===$count,'guests cannot write scores');
