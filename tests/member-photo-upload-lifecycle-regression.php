<?php
// Isolated customer photo upload lifecycle regression; no WordPress or live uploads.
define('ABSPATH', sys_get_temp_dir() . '/roxy-photo-upload-fixture-' . getmypid() . '/');
define('ROXY_PHOTO_FIXTURE_ROOT', ABSPATH);
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
    foreach (['wp-admin/includes', 'wp-admin', ''] as $directory) {
        $path = $root . $directory;
        if (is_dir($path)) rmdir($path);
    }
});
function check($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
function add_action(...$args) {} function add_filter(...$args) {} function register_activation_hook(...$args) {}
function is_user_logged_in() { return true; } function get_current_user_id() { return 42; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'fixture'; }
function sanitize_text_field($value) { return (string)$value; } function wp_unslash($value) { return $value; }
function absint($value) { return abs((int)$value); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function remove_filter(...$args) {}
function wp_delete_attachment($id, $force = false) { $GLOBALS['deleted_attachments'][] = [(int)$id, (bool)$force]; return empty($GLOBALS['delete_attachment_fails']); }
function media_handle_upload($field, $post_id) { return (int)$GLOBALS['uploaded_attachment_id']; }
function wp_safe_redirect($url) { throw new PhotoUploadRedirect($url); }
function wp_get_referer() { return 'https://example.test/account/subscriptions/'; }
function wc_get_account_endpoint_url($endpoint) { return 'https://example.test/account/' . $endpoint; }
class WP_Error {}
class PhotoUploadRedirect extends RuntimeException { public $url; function __construct($url) { $this->url=$url; parent::__construct('redirect'); } }
class PhotoUploadSubscription {
    public $id=7; public $persisted=[]; public $pending=[]; public $save_mode='ok'; public $readback_mode='normal'; public $fail_restore=false; public $save_count=0;
    function __construct($old=0) { if ($old) $this->persisted[Roxy_Sub_Check::META_PHOTO_ID]=$old; $this->pending=$this->persisted; }
    function get_user_id() { return 42; }
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
$success=new PhotoUploadSubscription(111);
$redirect=run_upload_case($success,901);
check($redirect instanceof PhotoUploadRedirect && ($success->persisted[Roxy_Sub_Check::META_PHOTO_ID]??0)===901, 'successful upload saves and redirects without deleting new or prior photo');
check($GLOBALS['deleted_attachments']===[], 'successful upload preserves both prior and new attachments');

foreach (['false','throw'] as $mode) {
    $failed=new PhotoUploadSubscription(112); $failed->save_mode=$mode;
    $GLOBALS['deleted_attachments']=[];
    check(run_upload_case($failed,902)===null, $mode.' save failure does not redirect as success');
    check(($failed->persisted[Roxy_Sub_Check::META_PHOTO_ID]??0)===112, $mode.' save failure preserves prior subscription photo reference');
    check($GLOBALS['deleted_attachments']===[[902,true]], $mode.' save failure deletes only this request attachment');
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
