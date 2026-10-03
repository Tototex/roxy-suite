<?php
// Pure fixtures: no database, email, charges, or published test content.
define('ABSPATH', __DIR__);
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function wp_timezone() { return new DateTimeZone('America/Los_Angeles'); }
function get_post_meta($id, $key, $single) { return $GLOBALS['links'][$id] ?? ''; }
function check($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
require ($argv[1] ?? dirname(__DIR__)) . '/includes/modules/requested-showings/includes/class-roxy-rs-cpt.php';
class FakeResponse {
    private $data;
    public function __construct($data) { $this->data = $data; }
    public function get_data() { return $this->data; }
    public function set_data($data) { $this->data = $data; }
}
$legacy = 'Requested by Test Person (requester@example.test)';
check(\RoxyRS\CPT::public_excerpt($legacy) === '', 'legacy contact removed from conversion excerpt');
check(\RoxyRS\CPT::public_excerpt('An editorial film description.') === 'An editorial film description.', 'editorial description preserved');
check(\RoxyRS\CPT::public_excerpt('Requested by a local club (family matinee)') !== '', 'non-contact editorial copy preserved');
foreach (['roxy_req_showing', 'roxy_showing'] as $type) {
    $post = (object)['ID'=>10, 'post_type'=>$type, 'post_excerpt'=>$legacy];
    $GLOBALS['links'][10] = 20;
    check(\RoxyRS\CPT::filter_public_excerpt($legacy,$post) === '', $type . ' public/feed excerpt protected');
    $response = new FakeResponse(['excerpt'=>['raw'=>$legacy,'rendered'=>'<p>'.$legacy.'</p>'],'title'=>'Preserved']);
    $data = \RoxyRS\CPT::filter_rest_excerpt($response,$post)->get_data();
    check($data['excerpt']['raw'] === '' && $data['excerpt']['rendered'] === '' && $data['title'] === 'Preserved', $type . ' REST raw/rendered privacy');
}
$root = $argv[1] ?? dirname(__DIR__);
$frontend = file_get_contents($root . '/includes/modules/requested-showings/includes/class-roxy-rs-frontend.php');
check(strpos($frontend, "'post_excerpt' => ''") !== false && strpos($frontend, "CPT::META_REQUESTER_EMAIL, \$requester_email") !== false, 'new requests retain private email without public contact excerpt');
require $root . '/includes/modules/requested-showings/includes/class-roxy-rs-frontend.php';
$now = new DateTimeImmutable('2026-10-02 23:00:00', wp_timezone());
check(\RoxyRS\Frontend::backing_window_open('2026-10-03T23:00', $now), 'future backing deadline stays open');
check(!\RoxyRS\Frontend::backing_window_open('2026-10-02T23:00:00', $now), 'deadline boundary closes backing window');
check(!\RoxyRS\Frontend::backing_window_open('2026-10-02T23:00', $now), 'minute-only deadline has zero seconds at boundary');
check(!\RoxyRS\Frontend::backing_window_open('2026-10-01T23:00', $now), 'expired backing deadline closes stale forms');
check(!\RoxyRS\Frontend::backing_window_open('not a date', $now), 'invalid nonempty deadline fails closed');
check(\RoxyRS\Frontend::backing_window_open('', $now), 'explicitly undated requests retain policy');
