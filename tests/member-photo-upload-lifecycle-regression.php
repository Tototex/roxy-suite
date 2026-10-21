<?php
// Isolated customer photo upload lifecycle regression; no WordPress or live uploads.
define('ABSPATH', sys_get_temp_dir() . '/roxy-photo-upload-fixture-' . getmypid() . '/');
define('ROXY_PHOTO_FIXTURE_ROOT', ABSPATH);
define('ROXY_PHOTO_UPLOADS', ABSPATH . 'uploads/');
define('MINUTE_IN_SECONDS', 60);
mkdir(ROXY_PHOTO_UPLOADS, 0777, true);
file_put_contents(ROXY_PHOTO_UPLOADS.'.htaccess', "# Existing site rule\n");
foreach (['wp-admin/includes/file.php', 'wp-admin/includes/media.php', 'wp-admin/includes/image.php'] as $include) {
    $path = ABSPATH . $include;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    if (!is_file($path)) file_put_contents($path, "<?php // isolated fixture\n");
}
register_shutdown_function(static function () {
    $root = ROXY_PHOTO_FIXTURE_ROOT;
    foreach (['wp-admin/includes/file.php', 'wp-admin/includes/media.php', 'wp-admin/includes/image.php'] as $include) {
        $path = $root . $include;
        if (is_file($path)) unlink($path);
    }
    foreach (['wp-admin/includes', 'wp-admin'] as $directory) {
        $path = $root . $directory;
        if (is_dir($path)) rmdir($path);
    }
    foreach (glob(ROXY_PHOTO_UPLOADS . '*') ?: [] as $path) if (is_file($path)) unlink($path);
    foreach (glob(ROXY_PHOTO_UPLOADS . '.*') ?: [] as $path) if (is_file($path)) unlink($path);
    if (is_dir(ROXY_PHOTO_UPLOADS)) rmdir(ROXY_PHOTO_UPLOADS);
    if (is_dir(ROXY_PHOTO_FIXTURE_ROOT)) rmdir(ROXY_PHOTO_FIXTURE_ROOT);
});
function check($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
function add_action(...$args) {} function add_filter(...$args) {} function register_activation_hook(...$args) {}
function is_user_logged_in() { return $GLOBALS['member_logged_in']??true; } function get_current_user_id() { return 42; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'fixture'; }
function sanitize_text_field($value) { return (string)$value; } function wp_unslash($value) { return $value; }
function absint($value) { return abs((int)$value); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function remove_filter(...$args) {}
function wp_delete_attachment($id, $force = false) { $GLOBALS['deleted_attachments'][] = [(int)$id, (bool)$force]; return empty($GLOBALS['delete_attachment_fails']); }
function media_handle_upload($field, $post_id) { $id=(int)$GLOBALS['uploaded_attachment_id']; file_put_contents(ROXY_PHOTO_UPLOADS.$id.'.jpg','fixture-photo'); file_put_contents(ROXY_PHOTO_UPLOADS.$id.'-150x150.jpg','fixture-thumbnail'); return $id; }
function get_post_type($id) { return 'attachment'; }
function wp_upload_dir(...$args) { return ['basedir'=>ROXY_PHOTO_UPLOADS]; }
function trailingslashit($path) { return rtrim((string)$path, '/\\') . '/'; }
function wp_normalize_path($path) { return str_replace('\\', '/', (string)$path); }
function get_attached_file($id) { return ROXY_PHOTO_UPLOADS.(int)$id.'.jpg'; }
function wp_get_attachment_metadata($id) { return ['file'=>(int)$id.'.jpg','sizes'=>['thumbnail'=>['file'=>(int)$id.'-150x150.jpg']]]; }
function get_option($key,$default=false) { return $GLOBALS['privacy_options'][$key]??$default; }
function update_option($key,$value,...$args) { $GLOBALS['privacy_options'][$key]=$value; return true; }
function get_post_meta($id,$key,$single=true) { return $GLOBALS['privacy_post_meta'][(int)$id][$key]??''; }
function update_post_meta($id,$key,$value) { $GLOBALS['privacy_post_meta'][(int)$id][$key]=$value; return true; }
function roxy_suite_user_can_access_admin() { return $GLOBALS['member_staff_allowed']??true; }
function wp_check_filetype($path) { return ['type'=>'image/jpeg','ext'=>'jpg']; }
function current_user_can(...$args) { return true; }
function set_transient($key,$value,$expiration) { $GLOBALS['test_transients'][$key]=$value; return true; }
function admin_url($path='') { return 'https://example.test/wp-admin/'.$path; }
function add_query_arg($args,$url) { return $url.'?'.http_build_query($args); }
function home_url($path='') { return 'https://example.test'.$path; }
function esc_attr($value) { return htmlspecialchars((string)$value,ENT_QUOTES); }
function esc_url($value) { return (string)$value; }
function wp_nonce_field(...$args) { echo '<input type="hidden">'; }
function wp_safe_redirect($url) { throw new PhotoUploadRedirect($url); }
function wp_get_referer() { return 'https://example.test/account/subscriptions/'; }
function wc_get_account_endpoint_url($endpoint) { return 'https://example.test/account/' . $endpoint; }
class WP_Error {}
class PhotoUploadRedirect extends RuntimeException { public $url; function __construct($url) { $this->url=$url; parent::__construct('redirect'); } }
class PhotoUploadSubscription {
    public $id=7; public $persisted=[]; public $pending=[]; public $save_mode='ok'; public $readback_mode='normal'; public $fail_restore=false; public $save_count=0;
    function __construct($old=0) { if ($old) $this->persisted[Roxy_Sub_Check::META_PHOTO_ID]=$old; $this->pending=$this->persisted; }
    function get_user_id() { return 42; }
    function get_id() { return $this->id; }
    function get_meta($key, $single=true) {
        if ($this->readback_mode==='mismatch' && ($this->persisted[$key]??0)===$GLOBALS['uploaded_attachment_id']) return 0;
        return $this->persisted[$key]??'';
    }
    function update_meta_data($key,$value) { $this->pending[$key]=$value; }
    function delete_meta_data($key) { unset($this->pending[$key]); }
    function save() {
        $this->save_count++;
        if ($this->save_mode==='throw') throw new RuntimeException('save failed');
        if ($this->fail_restore && $this->save_count>1) return false;
        // Model a CRUD method that may persist before reporting failure.
        $this->persisted=$this->pending;
        if ($this->save_mode==='false') return false;
        return $this->id;
    }
}
class PhotoUploadSubscriptionWithoutCrud {
    private $old_photo;
    function __construct($old_photo) { $this->old_photo=$old_photo; }
    function get_user_id() { return 42; }
    function get_meta($key, $single=true) { return $key===Roxy_Sub_Check::META_PHOTO_ID ? $this->old_photo : ''; }
}
function wcs_get_subscription($id) { return $GLOBALS['photo_subscription']; }
require dirname(__DIR__) . '/includes/modules/sub-check/roxy-sub-check.php';

function run_upload_case(object $sub, int $attachment_id): ?PhotoUploadRedirect {
    $GLOBALS['photo_subscription']=$sub; $GLOBALS['uploaded_attachment_id']=$attachment_id;
    $_POST=['roxy_photo_upload'=>'1','roxy_myaccount_photo_nonce'=>'fixture','roxy_sub_id'=>'7'];
    $_FILES=['roxy_member_photo'=>['name'=>'member.jpg','type'=>'image/jpeg']];
    try { Roxy_Sub_Check::handle_myaccount_photo_upload(); }
    catch (PhotoUploadRedirect $redirect) { return $redirect; }
    return null;
}

$GLOBALS['deleted_attachments']=[];
$GLOBALS['privacy_options']=[]; $GLOBALS['privacy_post_meta']=[];
$success=new PhotoUploadSubscription(111);
$redirect=run_upload_case($success,901);
check($redirect instanceof PhotoUploadRedirect && ($success->persisted[Roxy_Sub_Check::META_PHOTO_ID]??0)===901, 'successful upload saves and redirects without deleting new or prior photo');
check($GLOBALS['deleted_attachments']===[], 'successful upload preserves both prior and new attachments');
$rules=file_get_contents(ROXY_PHOTO_UPLOADS.'.htaccess');
check(($GLOBALS['privacy_post_meta'][901][Roxy_Sub_Check::META_PHOTO_PRIVATE]??'')==='1', 'uploaded member photo is marked private before it is linked to the subscription');
check(is_string($rules) && str_contains($rules, 'RewriteRule ^901\\.jpg$ - [F,L]'), 'uploaded original file is covered by an exact deny rule');
check(is_string($rules) && str_contains($rules, 'RewriteRule ^901-150x150\\.jpg$ - [F,L]'), 'generated thumbnail is covered by an exact deny rule');
check(is_string($rules) && str_contains($rules, "# Existing site rule\n"), 'pre-existing uploads rules are preserved');
check(get_option(Roxy_Sub_Check::OPTION_PRIVATE_PHOTO_PATHS)===['901-150x150.jpg','901.jpg'], 'protected original and thumbnail paths are durably recorded');
$photo_file=(new ReflectionClass(Roxy_Sub_Check::class))->getMethod('authorized_member_photo_file');
if (PHP_VERSION_ID < 80100) $photo_file->setAccessible(true);
check($photo_file->invoke(null,901)===ROXY_PHOTO_UPLOADS.'901.jpg', 'authorized staff can resolve a protected member photo through the private endpoint');
$photo_url=(new ReflectionClass(Roxy_Sub_Check::class))->getMethod('private_member_photo_url');
if (PHP_VERSION_ID < 80100) $photo_url->setAccessible(true);
$staff_url=$photo_url->invoke(null,901);
check(str_contains($staff_url,'admin-post.php') && !str_contains($staff_url,'901.jpg'), 'staff-facing photo URL uses the access-controlled endpoint, not the public upload URL');
$account_sub=new PhotoUploadSubscription(901);
ob_start(); Roxy_Sub_Check::render_myaccount_photo_uploader($account_sub); $account_markup=ob_get_clean();
check(str_contains($account_markup,'visible only to authorized staff') && !str_contains($account_markup,'/uploads/901.jpg'), 'customer account confirms photo status without rendering the private photo');
$GLOBALS['member_logged_in']=false;
check($photo_file->invoke(null,901)==='', 'anonymous visitors cannot resolve private member photo files');
$GLOBALS['member_logged_in']=true; $GLOBALS['member_staff_allowed']=false;
check($photo_file->invoke(null,901)==='', 'logged-in non-staff visitors cannot resolve private member photo files');
$GLOBALS['member_staff_allowed']=true;
$GLOBALS['photo_subscription']=new PhotoUploadSubscription(0);
file_put_contents(ROXY_PHOTO_UPLOADS.'908.jpg','selected-photo');
file_put_contents(ROXY_PHOTO_UPLOADS.'908-150x150.jpg','selected-thumbnail');
$_POST=['roxy_member_photo_nonce'=>'fixture','roxy_member_photo_id'=>'908'];
Roxy_Sub_Check::save_subscription_photo_metabox(7,(object)['post_type'=>'shop_subscription','ID'=>7]);
check(($GLOBALS['photo_subscription']->persisted[Roxy_Sub_Check::META_PHOTO_ID]??0)===908, 'admin photo selection remains attached to the original subscription record');
check(($GLOBALS['privacy_post_meta'][908][Roxy_Sub_Check::META_PHOTO_PRIVATE]??'')==='1'
    && str_contains((string)file_get_contents(ROXY_PHOTO_UPLOADS.'.htaccess'), 'RewriteRule ^908\\.jpg$ - [F,L]'), 'admin-selected photo is protected before its subscription reference is saved');
file_put_contents(ROXY_PHOTO_UPLOADS.'901-300x300.jpg','regenerated-thumbnail');
$new_metadata=['file'=>'901.jpg','sizes'=>['thumbnail'=>['file'=>'901-150x150.jpg'],'medium'=>['file'=>'901-300x300.jpg']]];
Roxy_Sub_Check::protect_member_photo_metadata_updates($new_metadata,901);
check(str_contains((string)file_get_contents(ROXY_PHOTO_UPLOADS.'.htaccess'), 'RewriteRule ^901-300x300\\.jpg$ - [F,L]'), 'future regenerated image sizes are added to the deny rules');
$GLOBALS['wpdb']=new class { public $prefix='fixture_';public $posts='fixture_posts';public $postmeta='fixture_postmeta';public $last_error='';public $last_arg=''; function prepare($sql,...$args) { $this->last_arg=(string)($args[0]??'');return $sql; } function get_col($sql) { return str_contains($sql,'fixture_wc_orders_meta')?[911]:[910]; } function get_var($sql) { return in_array($this->last_arg,['fixture_wc_orders','fixture_wc_orders_meta'],true)?$this->last_arg:''; } function esc_like($value) { return $value; } };
file_put_contents(ROXY_PHOTO_UPLOADS.'910.jpg','legacy-photo');
file_put_contents(ROXY_PHOTO_UPLOADS.'910-150x150.jpg','legacy-thumbnail');
file_put_contents(ROXY_PHOTO_UPLOADS.'911.jpg','hpos-photo');
Roxy_Sub_Check::ensure_existing_member_photos_private();
check(get_option(Roxy_Sub_Check::OPTION_PHOTO_PRIVACY_VERSION)==='1', 'existing subscription photo privacy migration completes only after applying protection');
check(($GLOBALS['privacy_post_meta'][910][Roxy_Sub_Check::META_PHOTO_PRIVATE]??'')==='1'
    && str_contains((string)file_get_contents(ROXY_PHOTO_UPLOADS.'.htaccess'), 'RewriteRule ^910\\.jpg$ - [F,L]'), 'existing attachment is marked private without changing its attachment ID');
check(($GLOBALS['privacy_post_meta'][911][Roxy_Sub_Check::META_PHOTO_PRIVATE]??'')==='1'
    && str_contains((string)file_get_contents(ROXY_PHOTO_UPLOADS.'.htaccess'), 'RewriteRule ^911\\.jpg$ - [F,L]'), 'HPOS-backed subscription photo references are included in the migration');

foreach (['false','throw'] as $mode) {
    $failed=new PhotoUploadSubscription(112); $failed->save_mode=$mode;
    $GLOBALS['deleted_attachments']=[];
    check(run_upload_case($failed,902)===null, $mode.' save failure does not redirect as success');
    check(($failed->persisted[Roxy_Sub_Check::META_PHOTO_ID]??0)===112, $mode.' save failure preserves prior subscription photo reference');
    check($GLOBALS['deleted_attachments']===[[902,true]], $mode.' save failure deletes only this request attachment');
    check(str_contains((string)file_get_contents(ROXY_PHOTO_UPLOADS.'.htaccess'), 'RewriteRule ^902\\.jpg$ - [F,L]'), $mode.' failure leaves the discarded URL blocked');
}

$readback=new PhotoUploadSubscription(113); $readback->readback_mode='mismatch';
$GLOBALS['deleted_attachments']=[];
check(run_upload_case($readback,903)===null, 'failed metadata readback does not redirect as success');
check(($readback->persisted[Roxy_Sub_Check::META_PHOTO_ID]??0)===113, 'failed metadata readback restores the previous photo reference');
check($GLOBALS['deleted_attachments']===[[903,true]], 'failed metadata readback deletes only this request attachment');

$rollback_failed=new PhotoUploadSubscription(115); $rollback_failed->fail_restore=true; $rollback_failed->readback_mode='mismatch';
$GLOBALS['deleted_attachments']=[];
check(run_upload_case($rollback_failed,905)===null, 'unverifiable rollback does not redirect as success');
check($GLOBALS['deleted_attachments']===[], 'unverifiable rollback preserves uploaded attachment to avoid a broken reference');

$delete_failed=new PhotoUploadSubscription(116); $delete_failed->save_mode='false';
$GLOBALS['deleted_attachments']=[]; $GLOBALS['delete_attachment_fails']=true;
check(run_upload_case($delete_failed,906)===null, 'failed attachment cleanup does not redirect as success');
check(($delete_failed->persisted[Roxy_Sub_Check::META_PHOTO_ID]??0)===116, 'failed attachment cleanup leaves old photo reference intact');
check($GLOBALS['deleted_attachments']===[[906,true]], 'failed cleanup targets only the uploaded attachment');
unset($GLOBALS['delete_attachment_fails']);

$missing_crud=new PhotoUploadSubscriptionWithoutCrud(114);
$GLOBALS['deleted_attachments']=[];
check(run_upload_case($missing_crud,904)===null, 'missing subscription CRUD methods do not redirect as success');
check($missing_crud->get_meta(Roxy_Sub_Check::META_PHOTO_ID)===114, 'missing CRUD methods preserve the existing photo reference');
check($GLOBALS['deleted_attachments']===[[904,true]], 'missing CRUD methods delete only this request attachment');

echo "Member photo upload lifecycle regression passed.\n";
