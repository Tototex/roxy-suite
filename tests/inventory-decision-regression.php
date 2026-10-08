<?php
// Isolated signed-link and prefetch tests; no real order/email mutation.
namespace RoxyInventory {
    class Store {
        public static $writes = 0;
        public static $status = 'approval_emailed';
        public static function order($id) { return ['id'=>$id,'vendor'=>'Test vendor','status'=>self::$status]; }
        public static function update_order_status($id,$status) { self::$writes++; self::$status=$status; return true; }
        public static function log(...$args) { return true; }
    }
}
namespace {
define('ABSPATH', __DIR__); define('DAY_IN_SECONDS', 86400);
function wp_salt($context) { return 'fixture-secret'; }
function absint($value) { return abs((int)$value); }
function sanitize_key($value) { return (string)$value; }
function sanitize_text_field($value) { return (string)$value; }
function wp_unslash($value) { return $value; }
function roxy_suite_user_can_access_admin() { return $GLOBALS['manager'] ?? false; }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES); }
function admin_url($value) { return 'https://example.test/wp-admin/'.$value; }
function wp_nonce_field(...$args) { return '<input type="hidden" name="_wpnonce" value="fixture">'; }
function wp_die($message,...$args) { throw new RuntimeException($message); }
function check_admin_referer($action) { if (empty($GLOBALS['nonce_valid'])) throw new RuntimeException('POST_NONCE_CHECK'); }
function add_query_arg($args,$url) { return $url.'?'.http_build_query($args); }
function wp_safe_redirect($url) { throw new RuntimeException('REDIRECT:'.$url); }
function check($ok,$label) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
require ($argv[1] ?? dirname(__DIR__)) . '/includes/modules/inventory/includes/class-roxy-inventory-admin.php';
$now=time(); $expires=$now+DAY_IN_SECONDS;
$token=hash_hmac('sha256','12|ordered|'.$expires,'fixture-secret');
check(\RoxyInventory\Admin::valid_decision_token(12,'ordered',$token,$expires,$now), 'fresh scoped expiring decision link accepted');
check(!\RoxyInventory\Admin::valid_decision_token(12,'rejected',$token,$expires,$now), 'decision substitution rejected');
check(!\RoxyInventory\Admin::valid_decision_token(13,'ordered',$token,$expires,$now), 'order substitution rejected');
check(!\RoxyInventory\Admin::valid_decision_token(12,'ordered',$token,$expires+1,$now), 'expiry tampering rejected');
check(!\RoxyInventory\Admin::valid_decision_token(12,'ordered',$token,$expires,$expires), 'expiry boundary rejected');
$_GET=['order_id'=>12,'decision'=>'ordered','token'=>$token,'expires'=>$expires];
$_SERVER['REQUEST_METHOD']='GET';
try { \RoxyInventory\Admin::order_decision(); } catch (RuntimeException $e) { $html=$e->getMessage(); }
check(strpos($html,'Confirm Ordered')!==false && strpos($html,'method="post"')!==false && \RoxyInventory\Store::$writes===0, 'GET prefetch only renders confirmation and never writes');
$_SERVER['REQUEST_METHOD']='POST'; $_POST=$_GET;
try { \RoxyInventory\Admin::order_decision(); } catch (RuntimeException $e) { $message=$e->getMessage(); }
check($message==='POST_NONCE_CHECK' && \RoxyInventory\Store::$writes===0, 'POST reaches nonce verification before any write');
$_SERVER['REQUEST_METHOD']='GET'; $_GET=['order_id'=>12,'decision'=>'ordered','token'=>hash_hmac('sha256','12|ordered','fixture-secret')];
try { \RoxyInventory\Admin::order_decision(); } catch (RuntimeException $e) { $message=$e->getMessage(); }
check(strpos($message,'invalid or expired')!==false && \RoxyInventory\Store::$writes===0, 'legacy indefinite link rejects anonymous access');
$GLOBALS['manager']=true;
try { \RoxyInventory\Admin::order_decision(); } catch (RuntimeException $e) { $message=$e->getMessage(); }
check(strpos($message,'Confirm Ordered')!==false && \RoxyInventory\Store::$writes===0, 'authorized signed-in manager can confirm legacy link without GET mutation');
$_SERVER['REQUEST_METHOD']='POST'; $_POST=['order_id'=>12,'decision'=>'ordered','token'=>$token,'expires'=>$expires];
$GLOBALS['nonce_valid']=true;
try { \RoxyInventory\Admin::order_decision(); } catch (RuntimeException $e) { $message=$e->getMessage(); }
check(\RoxyInventory\Store::$writes===1 && \RoxyInventory\Store::$status==='ordered' && strpos($message,'REDIRECT:')===0 && strpos($message,'tab=history')!==false, 'confirmed POST changes once and redirects to Order History');
try { \RoxyInventory\Admin::order_decision(); } catch (RuntimeException $e) { $message=$e->getMessage(); }
check(\RoxyInventory\Store::$writes===1 && strpos($message,'already been marked')!==false, 'replayed confirmation does not change a closed order');
\RoxyInventory\Store::$status='pending_manager'; $GLOBALS['manager']=false;
try { \RoxyInventory\Admin::order_decision(); } catch (RuntimeException $e) { $message=$e->getMessage(); }
check(\RoxyInventory\Store::$writes===1 && strpos($message,'signed-in manager')!==false,'anonymous signed link cannot resolve uncertain pending submission');
$GLOBALS['manager']=true; $_SERVER['REQUEST_METHOD']='GET'; $_GET=$_POST;
try { \RoxyInventory\Admin::order_decision(); } catch (RuntimeException $e) { $message=$e->getMessage(); }
check(\RoxyInventory\Store::$writes===1 && strpos($message,'Confirm Ordered')!==false,'manager pending placement confirmation GET remains read-only');
$_SERVER['REQUEST_METHOD']='POST';
try { \RoxyInventory\Admin::order_decision(); } catch (RuntimeException $e) { $message=$e->getMessage(); }
check(\RoxyInventory\Store::$writes===2 && \RoxyInventory\Store::$status==='ordered' && strpos($message,'REDIRECT:')===0,'authorized manager explicitly confirms pending order placed via nonce-checked POST');
}
