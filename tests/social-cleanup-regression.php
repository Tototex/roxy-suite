<?php
// Isolated Store cleanup regression checks. Run with: php tests/social-cleanup-regression.php [repo-root]
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('DB_NAME')) define('DB_NAME', 'social_cleanup_fixture');

final class CleanupWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $last_error = '';
    public array $rows = [];
    public array $attachments = [];
    public array $meta = [];
    public array $post_content = [];
    public array $postmeta_values = [];
    public bool $initial_query_error = false;
    public bool $reference_query_error = false;
    public bool $lock_busy = false;
    public bool $delete_fails = false;
    public bool $delete_throws = false;
    public bool $post_read_error = false;
    public bool $clear_fails = false;
    public bool $stale_on_reload = false;
    public array $queries = [];
    private bool $locked = false;
    private int $connection = 81;

    public function prepare(string $query, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $query = preg_replace('/%[sd]/', $replacement, $query, 1);
        }
        return $query;
    }
    public function esc_like(string $value): string { return addcslashes($value, '_%\\'); }
    public function get_results(string $query, $format = null): array {
        $this->queries[] = $query;
        if ($this->initial_query_error) { $this->last_error = 'fixture query error'; return []; }
        preg_match('/LIMIT\s+(\d+)/i', $query, $limit);
        preg_match('/cleanup_after <= \'([^\']+)\'/i', $query, $cutoff);
        $local_cutoff = stripslashes($cutoff[1] ?? '');
        $rows = [];
        foreach ($this->rows as $row) {
            if (!empty($row['cleanup_after']) && $row['cleanup_after'] <= $local_cutoff
                && in_array($row['status'], ['posted', 'skipped'], true) && !empty($row['temporary_attachment_id'])) {
                $rows[] = ['id' => $row['id'], 'temporary_attachment_id' => $row['temporary_attachment_id'], 'cleanup_after' => $row['cleanup_after']];
            }
        }
        return array_slice($rows, 0, (int) ($limit[1] ?? 100));
    }
    public function get_row(string $query, $format = null) {
        preg_match('/WHERE id = [\'\"]?(\d+)/', $query, $m);
        $id = (int) ($m[1] ?? 0);
        if ($this->stale_on_reload && isset($this->rows[$id])) $this->rows[$id]['temporary_attachment_id']++;
        return $this->rows[$id] ?? null;
    }
    public function get_var(string $query) {
        $this->queries[] = $query;
        if (strpos($query, 'GET_LOCK(') !== false) { if ($this->lock_busy) return 0; $this->locked = true; return 1; }
        if (strpos($query, 'CONNECTION_ID()') !== false && strpos($query, 'IS_USED_LOCK') === false) return (string) $this->connection;
        if (strpos($query, 'IS_USED_LOCK(') !== false) return $this->locked ? (string) $this->connection : null;
        if (strpos($query, 'RELEASE_LOCK(') !== false) { $this->locked = false; return 1; }
        if ($this->reference_query_error) { $this->last_error = 'fixture reference query error'; return null; }
        if (strpos($query, 'FROM wp_roxy_social_posts') !== false) {
            preg_match('/id <> (\d+)/', $query, $m); $self = (int) ($m[1] ?? 0);
            preg_match('/temporary_attachment_id = (\d+)/', $query, $a); $attachment = (int) ($a[1] ?? 0);
            preg_match('/media_url = \'(.*?)\'/s', $query, $u); $url = stripslashes($u[1] ?? '');
            foreach ($this->rows as $id => $row) if ((int) $id !== $self && ((int) ($row['temporary_attachment_id'] ?? 0) === $attachment || ($row['media_url'] ?? '') === $url)) return $id;
            return null;
        }
        if (strpos($query, 'FROM wp_posts') !== false) {
            preg_match('/ID <> (\d+)/', $query, $m); $self = (int) ($m[1] ?? 0);
            preg_match('/wp-image-(\d+)/', $query, $i); $image = 'wp-image-' . (int) ($i[1] ?? 0);
            preg_match('/post_content LIKE \'(.*?)\'/s', $query, $u); $urlPattern = str_replace('%', '', stripslashes($u[1] ?? ''));
            foreach ($this->post_content as $id => $content) if ((int) $id !== $self && (strpos($content, $urlPattern) !== false || strpos($content, $image) !== false)) return $id;
            return null;
        }
        if (strpos($query, 'FROM wp_postmeta') !== false) {
            preg_match('/meta_value = \'(.*?)\'/s', $query, $id); $attachment = stripslashes($id[1] ?? '');
            preg_match('/meta_value LIKE \'(.*?)\'/s', $query, $url); $urlPattern = str_replace('%', '', stripslashes($url[1] ?? ''));
            foreach ($this->postmeta_values as $value) if ((string) $value === $attachment || strpos((string) $value, $urlPattern) !== false) return 1;
        }
        return null;
    }
    public function query(string $query) {
        if ($this->clear_fails) { $this->last_error = 'fixture update failure'; return false; }
        preg_match('/WHERE id = (\d+)/', $query, $idMatch); $id = (int) ($idMatch[1] ?? 0);
        if (!isset($this->rows[$id])) return 0;
        $this->rows[$id]['temporary_attachment_id'] = null;
        $this->rows[$id]['cleanup_after'] = null;
        return 1;
    }
}

function current_time(string $type): string { return '2026-10-06 12:00:00'; }
function get_post(int $id) { global $wpdb; if($wpdb->post_read_error){$wpdb->last_error='fixture read failed';return null;}return $wpdb->attachments[$id] ?? null; }
function get_post_meta(int $id, string $key, bool $single = false) { global $wpdb; return $wpdb->meta[$id][$key] ?? ''; }
function wp_get_attachment_url(int $id) { return "https://fixture.invalid/uploads/{$id}.mp4"; }
function wp_delete_attachment(int $id, bool $force = false) { global $wpdb; if($wpdb->delete_throws)throw new RuntimeException('fixture delete threw');if ($wpdb->delete_fails) return false; unset($wpdb->attachments[$id]); return (object) ['ID' => $id]; }

require_once ($argv[1] ?? dirname(__DIR__)) . '/includes/modules/social-publisher/includes/class-roxy-social-store.php';

$checks = 0;
function check_cleanup(bool $ok, string $label): void { global $checks; if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); } $checks++; }
function fixture(array $overrides = []): void {
    global $wpdb;
    $wpdb = new CleanupWpdb();
    $wpdb->rows[1] = array_merge(['id' => 1, 'status' => 'posted', 'temporary_attachment_id' => 42, 'cleanup_after' => '2026-10-06 11:59:00', 'media_url' => ''], $overrides);
    $wpdb->attachments[42] = (object) ['ID' => 42, 'post_type' => 'attachment'];
    $wpdb->meta[42] = ['_roxy_social_temporary' => '1', '_roxy_hangar_asset_id' => '900'];
}

fixture();
$wpdb->rows[2] = ['id'=>2, 'status'=>'posted', 'temporary_attachment_id'=>43, 'cleanup_after'=>'2026-10-06 12:01:00', 'media_url'=>''];
$wpdb->attachments[43] = (object) ['ID'=>43, 'post_type'=>'attachment'];
$wpdb->meta[43] = ['_roxy_social_temporary'=>'1', '_roxy_hangar_asset_id'=>'901'];
$wpdb->rows[3] = ['id'=>3, 'status'=>'failed', 'temporary_attachment_id'=>44, 'cleanup_after'=>'2026-10-06 11:00:00', 'media_url'=>''];
$wpdb->attachments[44] = (object) ['ID'=>44, 'post_type'=>'attachment'];
$wpdb->meta[44] = ['_roxy_social_temporary'=>'1', '_roxy_hangar_asset_id'=>'902'];
check_cleanup(\RoxySocial\Store::cleanup_expired() === 1, 'owned expired attachment is deleted');
check_cleanup(!isset($wpdb->attachments[42]) && $wpdb->rows[1]['temporary_attachment_id'] === null && isset($wpdb->attachments[43]) && isset($wpdb->attachments[44]), 'successful delete clears pointer and future/failed rows stay');
check_cleanup(strpos($wpdb->queries[0], "'2026-10-06 12:00:00'") !== false && strpos($wpdb->queries[0], 'LIMIT 100') !== false, 'query uses local cutoff and bounded batch');

foreach ([
    ['shared social row', static function () { $GLOBALS['wpdb']->rows[2] = ['id'=>2,'temporary_attachment_id'=>42,'media_url'=>'']; }],
    ['shared social media URL', static function () { $GLOBALS['wpdb']->rows[2] = ['id'=>2,'temporary_attachment_id'=>null,'media_url'=>'https://fixture.invalid/uploads/42.mp4']; }],
    ['shared post content', static function () { $GLOBALS['wpdb']->post_content[5] = 'wp-image-42'; }],
    ['shared post URL', static function () { $GLOBALS['wpdb']->post_content[5] = 'https://fixture.invalid/uploads/42.mp4'; }],
    ['shared postmeta', static function () { $GLOBALS['wpdb']->postmeta_values[] = '42'; }],
    ['shared postmeta URL', static function () { $GLOBALS['wpdb']->postmeta_values[] = 'https://fixture.invalid/uploads/42.mp4'; }],
    ['unowned attachment', static function () { $GLOBALS['wpdb']->meta[42]['_roxy_social_temporary'] = '0'; }],
    ['missing Hangar asset marker', static function () { $GLOBALS['wpdb']->meta[42]['_roxy_hangar_asset_id'] = '0'; }],
    ['wrong attachment type', static function () { $GLOBALS['wpdb']->attachments[42]->post_type = 'post'; }],
    ['reference read failure', static function () { $GLOBALS['wpdb']->reference_query_error = true; }],
] as [$label, $setup]) {
    fixture(); $setup();
    check_cleanup(\RoxySocial\Store::cleanup_expired() === 0 && isset($wpdb->attachments[42]) && $wpdb->rows[1]['temporary_attachment_id'] === 42, $label . ' fails closed');
}

fixture(); $wpdb->delete_fails = true;
check_cleanup(\RoxySocial\Store::cleanup_expired() === 0 && $wpdb->rows[1]['temporary_attachment_id'] === 42, 'delete failure preserves retry pointer');
$wpdb->delete_fails = false;
check_cleanup(\RoxySocial\Store::cleanup_expired() === 1 && $wpdb->rows[1]['temporary_attachment_id'] === null, 'retry after delete failure succeeds');

fixture(); $wpdb->stale_on_reload = true;
check_cleanup(\RoxySocial\Store::cleanup_expired() === 0 && isset($wpdb->attachments[42]), 'changed row is not deleted');
fixture(); $wpdb->lock_busy = true;
check_cleanup(\RoxySocial\Store::cleanup_expired() === 0 && isset($wpdb->attachments[42]), 'busy row lock skips cleanup');
fixture(); unset($wpdb->attachments[42]);
check_cleanup(\RoxySocial\Store::cleanup_expired() === 0 && $wpdb->rows[1]['temporary_attachment_id'] === null, 'missing attachment clears stale pointer without delete count');
fixture(); $wpdb->clear_fails = true;
check_cleanup(\RoxySocial\Store::cleanup_expired() === 1 && !isset($wpdb->attachments[42]) && $wpdb->rows[1]['temporary_attachment_id'] === 42, 'pointer write failure preserves reference for absent-attachment retry');
$wpdb->clear_fails = false;
check_cleanup(\RoxySocial\Store::cleanup_expired() === 0 && $wpdb->rows[1]['temporary_attachment_id'] === null, 'retry clears pointer after deletion already completed');
fixture(); $wpdb->initial_query_error = true;
check_cleanup(\RoxySocial\Store::cleanup_expired() === 0 && isset($wpdb->attachments[42]), 'initial query error causes no deletion');
fixture(); $wpdb->post_read_error=true;
check_cleanup(\RoxySocial\Store::cleanup_expired()===0 && $wpdb->rows[1]['temporary_attachment_id']===42,'attachment read error cannot clear retry tracking');
fixture(); $wpdb->delete_throws=true;
check_cleanup(\RoxySocial\Store::cleanup_expired()===0 && $wpdb->rows[1]['temporary_attachment_id']===42,'thrown deletion retains tracking without crashing cron');

echo "OK: {$checks} social cleanup checks\n";
