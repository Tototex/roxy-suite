<?php
// wp eval-file only. Read-only diagnostics; no scheduler/provider invocation.
if (!defined('ABSPATH')) exit(1);
foreach (['functional_requested_showings', 'functional_grosses'] as $name) {
    $method = new ReflectionMethod(\RoxySuite\Health::class, $name);
    $items = $method->invoke(null);
    echo json_encode(['check'=>$name, 'items'=>$items], JSON_UNESCAPED_SLASHES) . "\n";
}
