<?php
// Read-only parity check: run via WP-CLI, or after the isolated fixture bootstrap.
// Arguments: baseline source, candidate source. Neither file is loaded as the live class.
$paths = isset($args) ? $args : array_slice($argv, 1);
foreach (['Baseline', 'Candidate'] as $index => $label) {
    $source = file_get_contents($paths[$index]);
    if ($source === false || !str_contains($source, 'class Members_Dashboard {')) throw new RuntimeException('Invalid dashboard source');
    eval(substr(str_replace('class Members_Dashboard {', 'class Members_Dashboard' . $label . ' {', $source), 5));
}
$old = new ReflectionMethod('RoxySuite\\Members_DashboardBaseline', 'snapshot');
$new = new ReflectionMethod('RoxySuite\\Members_DashboardCandidate', 'snapshot');
$checks = 0;
foreach (['counted', 'all', 'trade'] as $trade) {
    foreach (['', 'active', 'pending-cancel'] as $status) {
        foreach (['', 'has', 'missing'] as $photo) {
            foreach (['', 'NO_MATCH_6b8f9d1c'] as $search) {
                $filters = compact('trade', 'status', 'photo', 'search');
                $_GET = [];
                $before = $old->invoke(null, $filters);
                $count = count($before['rows']);
                $pages = max(1, (int) ceil($count / 50));
                foreach (array_unique([1, 2, $pages, 999999]) as $requested) {
                    $_GET = ['member_page' => $requested];
                    $after = $new->invoke(null, $filters);
                    $page = min($requested, $pages);
                    $expected = $before;
                    $expected['rows'] = array_slice($before['rows'], ($page - 1) * 50, 50);
                    $expected += ['total_rows' => $count, 'page' => $page, 'pages' => $pages];
                    if ($after !== $expected) throw new RuntimeException('Dashboard totals, rows or pagination differ: ' . json_encode($filters));
                    $checks++;
                }
            }
        }
    }
}
echo "PASS: $checks full-result totals/filter/sort/page parity checks; no customer details printed.\n";
