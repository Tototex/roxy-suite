<?php
// WP-CLI only. Disposable table; never saves a score in the live leaderboard.
if(!defined('WP_CLI')||!WP_CLI)exit;
$root=$args[0]??dirname(__DIR__);global $wpdb;
$real=$wpdb;$source=$real->prefix.'roxy_arcade_scores';
$fixture_prefix=$real->prefix.'roxy_score_fixture_'.bin2hex(random_bytes(5)).'_';
$fixture=$fixture_prefix.'roxy_arcade_scores';
if(!preg_match('/^[A-Za-z0-9_]+$/',$fixture)||$fixture===$source)throw new RuntimeException('Unsafe fixture table');
$original=$real->get_results("SELECT * FROM `$source` ORDER BY id",ARRAY_A);
if($real->last_error)throw new RuntimeException('Cannot capture score baseline');
$digest=hash('sha256',wp_json_encode($original));
$code=file_get_contents($root.'/includes/modules/arcade/roxy-arcade.php');
$code=preg_replace('/^<\?php\s*/','',$code,1);
$code=str_replace("define('ROXY_ARCADE_VERSION', '0.4.5');",'', $code);
$code=str_replace('class Roxy_Arcade {','class Roxy_Arcade_Score_Fixture {',$code);
$code=str_replace('Roxy_Arcade::init();','',$code);eval($code);
$save=new ReflectionMethod('Roxy_Arcade_Score_Fixture','upsert_best_score');
function arcade_assert($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$first=null;$second=null;$created=false;
try {
    if($real->query("CREATE TABLE `$fixture` LIKE `$source`")===false)throw new RuntimeException('Fixture creation failed');$created=true;
    $first=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$second=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    $first->prefix=$fixture_prefix;$second->prefix=$fixture_prefix;$wpdb=$first;
    arcade_assert($save->invoke(null,123,'marquee',50),'actual score insert succeeds');
    $first->query("UPDATE `$fixture` SET updated_at='2001-01-01 00:00:00' WHERE user_id=123");
    arcade_assert($save->invoke(null,123,'marquee',30),'lower score is accepted without overwrite');
    arcade_assert($save->invoke(null,123,'marquee',50),'equal score is accepted without overwrite');
    $row=$first->get_row("SELECT * FROM `$fixture` WHERE user_id=123",ARRAY_A);
    arcade_assert((int)$row['best_score']===50&&$row['updated_at']==='2001-01-01 00:00:00','lower/equal score preserves achievement timestamp');
    arcade_assert($save->invoke(null,123,'marquee',90),'improved score persists');
    $row=$first->get_row("SELECT * FROM `$fixture` WHERE user_id=123",ARRAY_A);
    arcade_assert((int)$row['best_score']===90&&$row['updated_at']!=='2001-01-01 00:00:00','improvement advances timestamp');
    arcade_assert($save->invoke(null,456,'marquee',0)&&(int)$first->get_var("SELECT COUNT(*) FROM `$fixture` WHERE user_id=456")===0,'zero creates no actual score row');
    arcade_assert($save->invoke(null,123,'popcorn',7)&&(int)$first->get_var("SELECT best_score FROM `$fixture` WHERE game_key='popcorn'")===7,'game identities remain independent');
    // Build both competing statements from the actual candidate helper.
    $save->invoke(null,321,'projector',100);$high=$first->last_query;
    $save->invoke(null,321,'projector',20);$low=$first->last_query;
    foreach([[$high,$low],[$low,$high]] as $pair){
        $first->query("DELETE FROM `$fixture` WHERE user_id=321");
        if(!$first->dbh->query($pair[0],MYSQLI_ASYNC)||!$second->dbh->query($pair[1],MYSQLI_ASYNC))throw new RuntimeException('Async fixture queries failed');
        $first->dbh->reap_async_query();$second->dbh->reap_async_query();
        arcade_assert((int)$first->get_var("SELECT best_score FROM `$fixture` WHERE user_id=321")===100,'independent concurrent connections retain higher score');
    }
    $first->prefix=$fixture_prefix.'missing_';$first->suppress_errors(true);
    arcade_assert(!$save->invoke(null,123,'marquee',200),'actual missing-table write returns failure');
} finally {
    $wpdb=$real;
    if($first)$first->close();if($second)$second->close();
    if($created&&$real->query("DROP TABLE `$fixture`")===false)throw new RuntimeException('Fixture cleanup failed');
    $after=$real->get_results("SELECT * FROM `$source` ORDER BY id",ARRAY_A);
    if($real->last_error||hash('sha256',wp_json_encode($after))!==$digest)throw new RuntimeException('Production scores changed');
    echo 'UNCHANGED production scores '.count($original)."; disposable table removed.\n";
}
