<?php
// WP-CLI private fixtures only: evaluate the selected staged/live source, not an old loaded class.
if (!defined('WP_CLI') || !WP_CLI) exit;
function roxy_fixture_issuance(string $root): void {
    if(class_exists('RoxyST\\FixtureIssuance'))return;
    $code=preg_replace('/^<\?php\s*/','',file_get_contents($root.'/includes/modules/show-tickets/includes/class-roxy-st-issuance.php'),1);
    $code=str_replace('final class Issuance {','final class FixtureIssuance {',$code);
    eval($code);
}
function roxy_fixture_tickets(string $root,string $class): void {
    roxy_fixture_issuance($root);
    $code=preg_replace('/^<\?php\s*/','',file_get_contents($root.'/includes/modules/show-tickets/includes/class-roxy-st-tickets.php'),1);
    $code=str_replace('class Tickets {','class '.$class.' {',$code);
    $code=preg_replace('/\bIssuance\b/','FixtureIssuance',$code);
    eval($code);
}
