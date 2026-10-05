<?php
// Temporary MU guard, installed only during the authorized engine maintenance window.
if(defined('WP_CLI')&&WP_CLI){
    if(getenv('ROXY_ENGINE_MAINTENANCE_CLI')==='20261005')return;
    fwrite(STDERR,"Roxy engine maintenance: background CLI jobs temporarily paused.\n");
    exit(75);
}
http_response_code(503);
header('Retry-After: 600');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><title>Roxy maintenance</title><h1>Brief scheduled maintenance</h1><p>We are updating the theater website. Please try again shortly.</p>';
exit;
