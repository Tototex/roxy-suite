<?php
/** Standalone regressions for RoxyST\CartPricing. Run with: php tests/ticket-cart-pricing-regression.php */
namespace {
  define('ABSPATH', __DIR__ . '/');
  define('ROXY_ST_META_SHOWING_ID', '_roxy_showing_id');
  define('ROXY_ST_META_TICKET_TYPE', '_roxy_ticket_type');

  $GLOBALS['ticket_test_meta'] = [];
  $GLOBALS['ticket_test_now'] = 0;
  $GLOBALS['ticket_test_options'] = [];
  $GLOBALS['ticket_test_notices'] = [];
  $GLOBALS['ticket_test_hooks'] = [];
  $GLOBALS['ticket_test_post_types'] = [];

  function get_post_meta($post_id, $key, $single = true) { return $GLOBALS['ticket_test_meta'][$post_id][$key] ?? ''; }
  function get_post_type($post_id) { return $GLOBALS['ticket_test_post_types'][$post_id] ?? false; }
  function current_time($type) { return $type === 'timestamp' ? $GLOBALS['ticket_test_now'] : gmdate('Y-m-d H:i:s', $GLOBALS['ticket_test_now']); }
  function get_option($key, $default = false) { return $GLOBALS['ticket_test_options'][$key] ?? $default; }
  function wp_timezone() { return new \DateTimeZone((string) get_option('timezone_string', 'UTC') ?: 'UTC'); }
  function current_datetime() { return (new \DateTimeImmutable('@' . $GLOBALS['ticket_test_now']))->setTimezone(wp_timezone()); }
  function wp_parse_args($args, $defaults = []) { return array_merge($defaults, is_array($args) ? $args : []); }
  function wc_get_price_decimals() { return 2; }
  function wc_price($price) { return '$' . number_format((float)$price, 2, '.', ''); }
  function wc_add_notice($message, $type = 'success') { $GLOBALS['ticket_test_notices'][] = [$message, $type]; }
  function __($message, $domain = null) { return $message; }
  function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
  function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['ticket_test_hooks'][] = [$hook, $callback, $priority, $accepted_args]; }
  function apply_filters($hook, $value, ...$args) { return $value; }
  class TicketFakeCPT { const POST_TYPE = 'roxy_showing'; }
  class_alias('TicketFakeCPT', 'RoxyST\\CPT');

  $root = $argv[1] ?? dirname(__DIR__);
  require_once $root . '/includes/modules/show-tickets/includes/class-roxy-st-settings.php';
  require_once $root . '/includes/modules/show-tickets/includes/class-roxy-st-products.php';
  require_once ($argv[2] ?? $root . '/includes/modules/show-tickets/includes/class-roxy-st-cart-pricing.php');

  class TicketFakeProduct {
    private $id; private $price; private $name; public $setCalls = 0;
    public function __construct($id, $price, $name = 'Ticket') { $this->id=$id; $this->price=(float)$price; $this->name=$name; }
    public function get_id() { return $this->id; }
    public function get_price() { return $this->price; }
    public function set_price($price) { $this->price=$price; $this->setCalls++; }
    public function get_name() { return $this->name; }
  }
  class TicketFakeCart {
    public $cart_contents = [];
    public function __construct(array $items) { $this->cart_contents=$items; }
    public function get_cart() { return $this->cart_contents; }
  }
  function ticket_check($ok, $message) { if (!$ok) throw new \RuntimeException($message); }
  function ticket_test($name, $callback) {
    foreach (['price_changed_in_request'=>false,'review_notice_added'=>false,'changes'=>[]] as $property=>$value) {
      $reflection=new \ReflectionProperty(\RoxyST\CartPricing::class,$property);
      $reflection->setAccessible(true); $reflection->setValue(null,$value);
    }
    clear_notices(); $callback(); echo "ok - $name\n";
  }
  function set_ticket_meta($product_id, $showing_id, $type, $profile) {
    $GLOBALS['ticket_test_meta'][$product_id] = [ROXY_ST_META_SHOWING_ID=>$showing_id, ROXY_ST_META_TICKET_TYPE=>$type];
    if ($profile !== null) $GLOBALS['ticket_test_meta'][$showing_id]['_roxy_pricing_profile']=$profile;
    $GLOBALS['ticket_test_post_types'][$showing_id]='roxy_showing';
  }
  function ticket_line($key, $product) { return [$key=>['product_id'=>$product->get_id(), 'data'=>$product]]; }
  function ticket_cart_line($key, $product) { return new TicketFakeCart(ticket_line($key,$product)); }
  function set_test_time($date) { $GLOBALS['ticket_test_now']=strtotime($date); }
  function notices() { return $GLOBALS['ticket_test_notices']; }
  function clear_notices() { $GLOBALS['ticket_test_notices']=[]; }

  // Settings::get_price reads the same option structure used by the module.
  $GLOBALS['ticket_test_options'][\RoxyST\Settings::OPTION_KEY] = [
    'general_price'=>'12', 'discount_price'=>'8', 'matinee_price'=>'8',
  ];

  ticket_test('registers one shared cart totals hook', function() {
    \RoxyST\CartPricing::init();
    $hooks=array_column($GLOBALS['ticket_test_hooks'],0);
    ticket_check(in_array('woocommerce_before_calculate_totals',$hooks,true) && in_array('woocommerce_check_cart_items',$hooks,true) && in_array('woocommerce_checkout_process',$hooks,true), 'expected shared repricing and classic review hooks');
  });

  ticket_test('uses the actual scheduled live-tier helper before, at, and after its boundary', function() {
    $sid=501; $pid=1501; set_ticket_meta($pid,$sid,'live1','live_event');
    $GLOBALS['ticket_test_meta'][$sid]['_roxy_live_price_1']='20';
    $GLOBALS['ticket_test_meta'][$sid]['_roxy_live_future_price_1']='25';
    $GLOBALS['ticket_test_meta'][$sid]['_roxy_live_change_at_1']='2030-04-05 18:00:00 UTC';
    $product=new TicketFakeProduct($pid,20,'Live General'); $cart=ticket_cart_line('live-line',$product);
    set_test_time('2030-04-05 17:59:59 UTC'); \RoxyST\CartPricing::reprice($cart);
    ticket_check($product->get_price()===20.0 && count(notices())===0,'before boundary should retain base price without notice');
    set_test_time('2030-04-05 18:00:00 UTC'); ticket_check(\RoxyST\Products::get_live_tier_active_price($sid,1)===25.0,'real Products helper should switch at exact boundary');
    \RoxyST\CartPricing::reprice($cart);
    ticket_check($product->get_price()===25.0 && count(notices())===1,'at boundary should reprice and notify');
    ticket_check(strpos(notices()[0][0],'$20.00')!==false && strpos(notices()[0][0],'$25.00')!==false,'notice should identify prior and current prices');
    \RoxyST\CartPricing::reprice($cart); ticket_check(count(notices())===1,'repeat recalculation should not repeat notice');
    $GLOBALS['ticket_test_meta'][$sid]['_roxy_live_future_price_1']='30';
    $GLOBALS['ticket_test_meta'][$sid]['_roxy_live_change_at_1']='2030-04-05 18:05:00 UTC';
    set_test_time('2030-04-05 18:05:00 UTC'); \RoxyST\CartPricing::reprice($cart);
    ticket_check($product->get_price()===30.0 && count(notices())===2,'later distinct transition should notify once');
    ticket_check(strpos(notices()[1][0],'$25.00')!==false && strpos(notices()[1][0],'$30.00')!==false,'later notice should use previous accepted baseline');
    clear_notices();
  });

  ticket_test('scheduled live prices use site-local wall time independent of PHP timezone', function() {
    date_default_timezone_set('UTC');
    $GLOBALS['ticket_test_options']['timezone_string']='America/Los_Angeles';
    $sid=508;
    $GLOBALS['ticket_test_meta'][$sid]['_roxy_live_price_1']='20';
    $GLOBALS['ticket_test_meta'][$sid]['_roxy_live_future_price_1']='25';
    $GLOBALS['ticket_test_meta'][$sid]['_roxy_live_change_at_1']='2030-04-05T18:00';
    set_test_time('2030-04-06 00:59:59 UTC');
    ticket_check(\RoxyST\Products::get_live_tier_active_price($sid,1)===20.0,'UTC process time before the local price boundary keeps the base price');
    set_test_time('2030-04-06 01:00:00 UTC');
    ticket_check(\RoxyST\Products::get_live_tier_active_price($sid,1)===25.0,'site-local price boundary activates at the matching UTC instant');
    $display=\RoxyST\Products::get_live_tier_display_price($sid,1);
    ticket_check(!empty($display['is_scheduled']) && !empty($display['is_future_active']),'display flags use the same validated site-local boundary');
    ticket_check(\RoxyST\Products::parse_live_price_change_at('2026-03-08T02:30')===null,'nonexistent daylight-saving wall time is rejected rather than normalized');
  });

  ticket_test('applies current movie settings prices and subscriber remains free', function() {
    $sid=502; $pid=1502; set_ticket_meta($pid,$sid,'adult','movie_evening');
    set_ticket_meta(1590,$sid,'adult','movie_evening');
    $matching=new TicketFakeProduct(1590,12,'Newly added General');
    \RoxyST\CartPricing::reprice(ticket_cart_line('new-match',$matching));
    ticket_check($matching->get_price()===12.0 && count(notices())===0,'first matching add should not produce a change notice');
    $movie=new TicketFakeProduct($pid,10,'Movie General'); $cart=ticket_cart_line('movie',$movie);
    \RoxyST\CartPricing::reprice($cart);
    ticket_check($movie->get_price()===12.0 && count(notices())===1,'general price should follow current Settings');
    $GLOBALS['ticket_test_options'][\RoxyST\Settings::OPTION_KEY]['general_price']='14';
    \RoxyST\CartPricing::reprice($cart);
    ticket_check($movie->get_price()===14.0 && count(notices())===2,'new Settings price should be a distinct transition');

    $sub_id=1503; set_ticket_meta($sub_id,$sid,'subscriber','movie_evening');
    $subscriber=new TicketFakeProduct($sub_id,9,'Subscriber'); $sub_cart=ticket_cart_line('subscriber',$subscriber);
    \RoxyST\CartPricing::reprice($sub_cart);
    ticket_check($subscriber->get_price()===0.0 && count(notices())===3,'subscriber line must remain free with a clear price-change notice');
    clear_notices();
  });

  ticket_test('matinee uses current Settings price', function() {
    $sid=503; $pid=1504; set_ticket_meta($pid,$sid,'matinee','movie_matinee');
    $product=new TicketFakeProduct($pid,6,'Matinee'); \RoxyST\CartPricing::reprice(ticket_cart_line('matinee',$product));
    ticket_check($product->get_price()===8.0,'matinee should follow Settings'); clear_notices();
  });

  ticket_test('movie fallback requires real showing and checkout review expires with request', function() {
    $GLOBALS['ticket_test_options'][\RoxyST\Settings::OPTION_KEY]['general_price']='12';
    $sid=505; $pid=1507; set_ticket_meta($pid,$sid,'adult',null);
    $product=new TicketFakeProduct($pid,10,'Legacy General'); $cart=ticket_cart_line('legacy',$product);
    \RoxyST\CartPricing::reprice($cart);
    ticket_check($product->get_price()===12.0,'empty profile on a real showing should use movie-evening fallback');
    \RoxyST\CartPricing::require_price_review();
    ticket_check(count(array_filter(notices(),static function($n){return $n[1]==='error';}))===1,'changed price should pause checkout once');
    \RoxyST\CartPricing::require_price_review();
    ticket_check(count(array_filter(notices(),static function($n){return $n[1]==='error';}))===1,'review error should be deduped in the request');

    $changed=new \ReflectionProperty(\RoxyST\CartPricing::class,'price_changed_in_request');
    $changed->setAccessible(true); $changed->setValue(null,false);
    $reviewed=new \ReflectionProperty(\RoxyST\CartPricing::class,'review_notice_added');
    $reviewed->setAccessible(true); $reviewed->setValue(null,false);
    clear_notices(); \RoxyST\CartPricing::reprice($cart); \RoxyST\CartPricing::require_price_review();
    ticket_check(count(notices())===0,'accepted baseline should pass on a later request');

    $GLOBALS['ticket_test_post_types'][$sid]='post';
    $spoof=new TicketFakeProduct($pid,12,'Spoofed metadata');
    \RoxyST\CartPricing::reprice(ticket_cart_line('spoof',$spoof));
    ticket_check($spoof->get_price()===12.0 && count(notices())===0,'metadata on a non-showing post must not affect pricing');
  });

  ticket_test('unrelated products and unknown or removed ticket types are untouched', function() {
    $unrelated=new TicketFakeProduct(1599,17,'Unrelated'); \RoxyST\CartPricing::reprice(ticket_cart_line('other',$unrelated));
    $sid=504; $unknown_id=1505; set_ticket_meta($unknown_id,$sid,'mystery','live_event');
    $unknown=new TicketFakeProduct($unknown_id,19,'Unknown'); \RoxyST\CartPricing::reprice(ticket_cart_line('unknown',$unknown));
    $removed_id=1506; set_ticket_meta($removed_id,$sid,'live2','live_event');
    $GLOBALS['ticket_test_meta'][$sid]['_roxy_live_price_2']='';
    $removed=new TicketFakeProduct($removed_id,21,'Removed tier'); \RoxyST\CartPricing::reprice(ticket_cart_line('removed',$removed));
    ticket_check($unrelated->get_price()===17.0 && $unknown->get_price()===19.0 && $removed->get_price()===21.0,'unrecognized/nonconfigured lines must not be repriced');
    ticket_check(count(notices())===0,'unrecognized lines must not receive price notices');
  });

  ticket_test('legacy subtotal preserves prior quote despite reloaded product price', function() {
    clear_notices();
    $sid=505; $pid=1507; set_ticket_meta($pid,$sid,'adult','movie_evening');
    $GLOBALS['ticket_test_options'][\RoxyST\Settings::OPTION_KEY]['general_price']='14';
    $product=new TicketFakeProduct($pid,14,'Legacy quote');
    $cart=new TicketFakeCart(['legacy'=>['product_id'=>$pid,'data'=>$product,'quantity'=>2,'line_subtotal'=>24,'line_total'=>18]]);
    \RoxyST\CartPricing::reprice($cart);
    ticket_check(count(notices())===1 && strpos(notices()[0][0],'$12.00')!==false && strpos(notices()[0][0],'$14.00')!==false,'legacy notice must use pre-coupon per-item quote');
    \RoxyST\CartPricing::reprice($cart);
    ticket_check(count(notices())===1,'stale line subtotal must not repeat transition after baseline set');
    ticket_check($product->get_price()===14.0 && $cart->cart_contents['legacy']['line_total']===18,'repricing leaves coupon/tax totals to Woo calculation');
  });

  echo "All ticket cart pricing regressions passed.\n";
}
