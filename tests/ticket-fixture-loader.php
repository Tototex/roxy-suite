<?php
// WP-CLI private fixtures only: evaluate the selected staged/live source, not an old loaded class.
if (!defined('WP_CLI') || !WP_CLI) exit;
function roxy_fixture_reservations(string $root): void {
    if(class_exists('RoxyST\\FixtureReservations'))return;
    $code=preg_replace('/^<\?php\s*/','',file_get_contents($root.'/includes/modules/show-tickets/includes/class-roxy-st-reservations.php'),1);
    $code=str_replace('final class Reservations {','final class FixtureReservations {',$code);
    eval($code);
}
function roxy_fixture_issuance(string $root): void {
    roxy_fixture_reservations($root);
    if(class_exists('RoxyST\\FixtureIssuance'))return;
    $code=preg_replace('/^<\?php\s*/','',file_get_contents($root.'/includes/modules/show-tickets/includes/class-roxy-st-issuance.php'),1);
    $code=str_replace('final class Issuance {','final class FixtureIssuance {',$code);
    $code=preg_replace('/\bReservations\b/','FixtureReservations',$code);
    eval($code);
}
function roxy_fixture_tickets(string $root,string $class): void {
    roxy_fixture_issuance($root);
    $code=preg_replace('/^<\?php\s*/','',file_get_contents($root.'/includes/modules/show-tickets/includes/class-roxy-st-tickets.php'),1);
    $code=str_replace('class Tickets {','class '.$class.' {',$code);
    $code=preg_replace('/\bIssuance\b/','FixtureIssuance',$code);
    if(class_exists('Fixture_Roxy_Sub_Check')) $code=str_replace('Roxy_Sub_Check','Fixture_Roxy_Sub_Check',$code);
    if(class_exists('RoxyST\\FixtureCapacity')) $code=preg_replace('/\bCapacity\b/','FixtureCapacity',$code);
    eval($code);
}
function roxy_fixture_holds(string $root): void {
    roxy_fixture_capacity($root);roxy_fixture_issuance($root);
    $code=preg_replace('/^<\?php\s*/','',file_get_contents($root.'/includes/modules/show-tickets/includes/class-roxy-st-holds.php'),1);
    $code=str_replace('final class Holds {','final class FixtureHolds {',$code);
    foreach(['Issuance','Reservations','Capacity'] as $class)$code=preg_replace('/\b'.$class.'\b/','Fixture'.$class,$code);
    if(class_exists('Fixture_Roxy_Sub_Check'))$code=str_replace('Roxy_Sub_Check','Fixture_Roxy_Sub_Check',$code);
    eval($code);
}
function roxy_fixture_capacity(string $root): void {
    roxy_fixture_reservations($root);
    $code=preg_replace('/^<\?php\s*/','',file_get_contents($root.'/includes/modules/show-tickets/includes/class-roxy-st-capacity.php'),1);
    $code=str_replace('class Capacity {','class FixtureCapacity {',$code);
    $code=preg_replace('/\bReservations\b/','FixtureReservations',$code);
    if(class_exists('Fixture_Roxy_Sub_Check')) $code=str_replace('Roxy_Sub_Check','Fixture_Roxy_Sub_Check',$code);
    $code=preg_replace('/\bHolds\b/','FixtureHolds',$code);
    eval($code);
}
function roxy_fixture_members(string $root): void {
    $code=preg_replace('/^<\?php\s*/','',file_get_contents($root.'/includes/modules/sub-check/roxy-sub-check.php'),1);
    $code=str_replace('Roxy_Sub_Check','Fixture_Roxy_Sub_Check',$code);
    $code=str_replace('Fixture_Roxy_Sub_Check::init();','',$code);
    eval($code);
}
