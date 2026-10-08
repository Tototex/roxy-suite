<?php
// Actual staged Store/Admin renderer. Existing production rows are read only.
global $wpdb;
$root=$args[0]??dirname(__DIR__);
$store=file_get_contents($root.'/includes/modules/social-publisher/includes/class-roxy-social-store.php');
$store=str_replace('final class Store {','final class SnapshotRenderStore {',$store);
eval(substr($store,5));
$admin=file_get_contents($root.'/includes/modules/social-publisher/includes/class-roxy-social-admin.php');
$admin=str_replace(['final class Admin {','Store::','dirname(__DIR__)'],['final class SnapshotRenderAdmin {','SnapshotRenderStore::',var_export($root.'/includes/modules/social-publisher',true)],$admin);
eval(substr($admin,5));
$table=\RoxySocial\Store::table_name();
$before=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A)));
// Do not run the legacy read-maintenance path against live unapproved drafts.
$drafts=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE status='draft'");
if($drafts)throw new RuntimeException('Use temporary draft rows for rendering when production drafts exist');
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
if(!$admins)throw new RuntimeException('An administrator context is required');
wp_set_current_user((int)$admins[0]);$_GET=['updated'=>'0'];
ob_start();\RoxySocial\SnapshotRenderAdmin::render_page();$html=ob_get_clean();
$count=preg_match_all('/name="draft_revision" value="[a-f0-9]{64}"/',$html);
$disabled=preg_match_all('/type="submit" disabled>Save<\/button>/',$html);
if(!$count||$disabled!==$count||!str_contains($html,'Your changes were not saved.'))throw new RuntimeException('Revision/locked action/error notice rendering failed');
$after=hash('sha256',serialize($wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A)));
if(!hash_equals($before,$after))throw new RuntimeException('Rendering changed production post rows');
echo 'SNAPSHOT_RENDER_OK revisions='.$count.' frozen_saves='.$disabled.' production_rows_unchanged=yes'.PHP_EOL;
