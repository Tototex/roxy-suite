<?php
// Isolated Store cleanup regression checks. Run with: php tests/social-cleanup-regression.php [repo-root]
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('DB_NAME')) define('DB_NAME', 'social_cleanup_fixture');

final class CleanupWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $usermeta = 'wp_usermeta';
    public string $termmeta = 'wp_termmeta';
    public string $commentmeta = 'wp_commentmeta';
    public string $options = 'wp_options';
    public string $last_error = '';
    public array $rows = [];
    public array $cleanup_rows = [];
    public array $attachments = [];
    public array $meta = [];
    public array $post_content = [];
    public array $postmeta_values = [];
    public array $metadata_values = ['wp_usermeta'=>[], 'wp_termmeta'=>[], 'wp_commentmeta'=>[], 'wp_options'=>[]];
    public bool $initial_query_error = false;
    public bool $reference_query_error = false;
    public string $fail_reference_table = '';
    public bool $lock_busy = false;
    public array $busy_lock_names = [];
    public bool $delete_fails = false;
    public bool $delete_throws = false;
    public bool $post_read_error = false;
    public bool $clear_fails = false;
    public bool $stale_on_reload = false;
    public bool $fail_later_candidate_query = false;
    public array $delete_fails_for_ids = [];
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
        if (strpos($query, 'FROM wp_roxy_social_media_cleanup') !== false) {
            preg_match('/attachment_id > (\d+)/i', $query, $cursor);
            preg_match('/attachment_id <= (\d+)/i', $query, $ceiling);
            preg_match('/cleanup_after <= \'([^\']+)\'/i', $query, $cutoff);
            $after_id = (int) ($cursor[1] ?? 0);
            $upper_id = (int) ($ceiling[1] ?? PHP_INT_MAX);
            $local_cutoff = stripslashes($cutoff[1] ?? '');
            $rows = array_values(array_filter($this->cleanup_rows, static fn(array $row): bool => (int) $row['attachment_id'] > $after_id
                && (int) $row['attachment_id'] <= $upper_id && (string) $row['cleanup_after'] <= $local_cutoff));
            usort($rows, static fn(array $a, array $b): int => (int) $a['attachment_id'] <=> (int) $b['attachment_id']);
            preg_match('/LIMIT\s+(\d+)/i', $query, $limit);
            return array_slice($rows, 0, (int) ($limit[1] ?? 100));
        }
        if ($this->initial_query_error) { $this->last_error = 'fixture query error'; return []; }
        preg_match('/LIMIT\s+(\d+)/i', $query, $limit);
        preg_match('/id > (\d+)/i', $query, $cursor);
        $after_id = (int) ($cursor[1] ?? 0);
        if ($this->fail_later_candidate_query && $after_id > 0) { $this->last_error = 'fixture later-page query error'; return []; }
        preg_match('/id <= (\d+)/i', $query, $ceiling);
        $upper_id = (int) ($ceiling[1] ?? PHP_INT_MAX);
        preg_match('/cleanup_after <= \'([^\']+)\'/i', $query, $cutoff);
        $local_cutoff = stripslashes($cutoff[1] ?? '');
        $rows = [];
        foreach ($this->rows as $row) {
            if ((int) ($row['id'] ?? 0) > $after_id && (int) ($row['id'] ?? 0) <= $upper_id && !empty($row['cleanup_after']) && $row['cleanup_after'] <= $local_cutoff
                && in_array($row['status'], ['posted', 'skipped'], true) && !empty($row['temporary_attachment_id'])) {
                $rows[] = ['id' => $row['id'], 'temporary_attachment_id' => $row['temporary_attachment_id'], 'cleanup_after' => $row['cleanup_after']];
            }
        }
        usort($rows, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        return array_slice($rows, 0, (int) ($limit[1] ?? 100));
    }
    public function get_row(string $query, $format = null) {
        if (strpos($query, 'FROM wp_roxy_social_media_cleanup') !== false) {
            preg_match('/WHERE attachment_id = [\'\"]?(\d+)/', $query, $m);
            $id = (int) ($m[1] ?? 0);
            foreach ($this->cleanup_rows as $row) if ((int) $row['attachment_id'] === $id) return $row;
            return null;
        }
        preg_match('/WHERE id = [\'\"]?(\d+)/', $query, $m);
        $id = (int) ($m[1] ?? 0);
        if ($this->stale_on_reload && isset($this->rows[$id])) $this->rows[$id]['temporary_attachment_id']++;
        return $this->rows[$id] ?? null;
    }
    public function get_var(string $query) {
        $this->queries[] = $query;
        if (strpos($query, 'COALESCE(MAX(attachment_id), 0)') !== false) {
            preg_match('/cleanup_after <= \'([^\']+)\'/i', $query, $cutoff);
            $local_cutoff = stripslashes($cutoff[1] ?? '');
            $ids = array_map(static fn(array $row): int => (int) $row['attachment_id'], array_filter($this->cleanup_rows, static fn(array $row): bool => (string) $row['cleanup_after'] <= $local_cutoff));
            return $ids ? max($ids) : 0;
        }
        if (strpos($query, 'COALESCE(MAX(id), 0)') !== false) {
            preg_match('/cleanup_after <= \'([^\']+)\'/i', $query, $cutoff);
            $local_cutoff = stripslashes($cutoff[1] ?? '');
            $ids = [];
            foreach ($this->rows as $row) if (!empty($row['cleanup_after']) && $row['cleanup_after'] <= $local_cutoff && in_array($row['status'], ['posted', 'skipped'], true) && !empty($row['temporary_attachment_id'])) $ids[] = (int) $row['id'];
            return $ids ? max($ids) : 0;
        }
        if (strpos($query, 'GET_LOCK(') !== false) {
            preg_match("/GET_LOCK\\('([^']+)'/", $query, $lock);
            if ($this->lock_busy || in_array($lock[1] ?? '', $this->busy_lock_names, true)) return 0;
            $this->locked = true; return 1;
        }
        if (strpos($query, 'CONNECTION_ID()') !== false && strpos($query, 'IS_USED_LOCK') === false) return (string) $this->connection;
        if (strpos($query, 'IS_USED_LOCK(') !== false) return $this->locked ? (string) $this->connection : null;
        if (strpos($query, 'RELEASE_LOCK(') !== false) { $this->locked = false; return 1; }
        if ($this->reference_query_error) { $this->last_error = 'fixture reference query error'; return null; }
        if (strpos($query, 'FROM wp_roxy_social_media_cleanup') !== false) return null;
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
            preg_match_all('/post_content LIKE \'(.*?)\'/s', $query, $matches);
            $patterns = array_map(static fn($pattern) => stripslashes($pattern), $matches[1] ?? []);
            foreach ($this->post_content as $id => $content) {
                if ((int) $id === $self) continue;
                if (strpos($content, $image) !== false) return $id;
                foreach ($patterns as $pattern) {
                    $like_regex = '/^' . str_replace('%', '.*', preg_quote($pattern, '/')) . '$/s';
                    if ($pattern !== '' && preg_match($like_regex, (string) $content)) return $id;
                }
            }
            return null;
        }
        foreach (['wp_postmeta'=>$this->postmeta_values] + $this->metadata_values as $table => $values) {
            if (strpos($query, 'FROM ' . $table) === false) continue;
            if ($this->fail_reference_table === $table) { $this->last_error = 'fixture ' . $table . ' read error'; return null; }
            preg_match('/(?:meta_value|option_value) = \'(.*?)\'/s', $query, $id); $attachment = stripslashes($id[1] ?? '');
            preg_match_all('/(?:meta_value|option_value) LIKE \'(.*?)\'/s', $query, $matches);
            $patterns = array_map(static fn($pattern) => stripslashes($pattern), $matches[1] ?? []);
            preg_match('/(?:meta_value|option_value) REGEXP \'(.*?)\'/s', $query, $regex_match);
            $id_regex = stripslashes($regex_match[1] ?? '');
            foreach ($values as $value) {
                if ((string) $value === $attachment) return 1;
                foreach ($patterns as $pattern) {
                    $like_regex = '/^' . str_replace('%', '.*', preg_quote($pattern, '/')) . '$/s';
                    if ($pattern !== '' && preg_match($like_regex, (string) $value)) return 1;
                }
                if ($id_regex !== '' && preg_match('/' . $id_regex . '/', (string) $value)) return 1;
            }
            return null;
        }
        return null;
    }
    public function query(string $query) {
        if (strpos($query, 'DELETE FROM wp_roxy_social_media_cleanup') === 0) {
            preg_match('/WHERE attachment_id = (\d+)/', $query, $id_match);
            preg_match('/AND social_post_id = (\d+)/', $query, $owner_match);
            preg_match('/AND cleanup_after = \'([^\']+)\'/i', $query, $deadline_match);
            $id = (int) ($id_match[1] ?? 0);
            foreach ($this->cleanup_rows as $key => $row) {
                if ((int) $row['attachment_id'] === $id) {
                    if ((int) $row['social_post_id'] !== (int) ($owner_match[1] ?? 0)
                        || (string) $row['cleanup_after'] !== stripslashes($deadline_match[1] ?? '')) continue;
                    unset($this->cleanup_rows[$key]);
                    return 1;
                }
            }
            return 0;
        }
        if ($this->clear_fails) { $this->last_error = 'fixture update failure'; return false; }
        preg_match('/WHERE id = (\d+)/', $query, $idMatch); $id = (int) ($idMatch[1] ?? 0);
        if (!isset($this->rows[$id])) return 0;
        $this->rows[$id]['temporary_attachment_id'] = null;
        $this->rows[$id]['cleanup_after'] = null;
        return 1;
    }
}

function current_time(string $type): string { return '2026-10-06 12:00:00'; }
function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool { $GLOBALS['cleanup_scheduled'][] = [$timestamp, $hook, $args]; return true; }
function get_post(int $id) { global $wpdb; if($wpdb->post_read_error){$wpdb->last_error='fixture read failed';return null;}return $wpdb->attachments[$id] ?? null; }
function get_post_meta(int $id, string $key, bool $single = false) { global $wpdb; return $wpdb->meta[$id][$key] ?? ''; }
function wp_get_attachment_url(int $id) { return 'https://fixture.invalid/uploads/clip.mp4'; }
function wp_delete_attachment(int $id, bool $force = false) { global $wpdb; if($wpdb->delete_throws)throw new RuntimeException('fixture delete threw');if ($wpdb->delete_fails || in_array($id, $wpdb->delete_fails_for_ids, true)) return false; unset($wpdb->attachments[$id]); return (object) ['ID' => $id]; }

require_once ($argv[1] ?? dirname(__DIR__)) . '/includes/modules/social-publisher/includes/class-roxy-social-store.php';

$checks = 0;
function check_cleanup(bool $ok, string $label): void { global $checks; if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); } $checks++; }
function fixture(array $overrides = []): void {
    global $wpdb;
    $GLOBALS['cleanup_scheduled'] = [];
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
$initial_candidate_query = '';
foreach ($wpdb->queries as $candidate_query) if (strpos($candidate_query, 'SELECT id, temporary_attachment_id, cleanup_after') !== false) $initial_candidate_query = $candidate_query;
check_cleanup(strpos($initial_candidate_query, "'2026-10-06 12:00:00'") !== false && strpos($initial_candidate_query, 'LIMIT 100') !== false, 'candidate query uses local cutoff and bounded batch');

foreach ([
    ['shared social row', static function () { $GLOBALS['wpdb']->rows[2] = ['id'=>2,'temporary_attachment_id'=>42,'media_url'=>'']; }],
    ['shared social media URL', static function () { $GLOBALS['wpdb']->rows[2] = ['id'=>2,'temporary_attachment_id'=>null,'media_url'=>'https://fixture.invalid/uploads/clip.mp4']; }],
    ['shared post content', static function () { $GLOBALS['wpdb']->post_content[5] = 'wp-image-42'; }],
    ['shared post URL without attachment ID', static function () { $GLOBALS['wpdb']->post_content[5] = 'https://fixture.invalid/uploads/clip.mp4'; }],
    ['JSON-escaped post URL without attachment ID', static function () { $GLOBALS['wpdb']->post_content[5] = json_encode(['url'=>'https://fixture.invalid/uploads/clip.mp4']); }],
    ['shared postmeta', static function () { $GLOBALS['wpdb']->postmeta_values[] = '42'; }],
    ['serialized postmeta attachment ID', static function () { $GLOBALS['wpdb']->postmeta_values[] = 'a:1:{s:12:"attachment";i:42;}'; }],
    ['shared postmeta URL without attachment ID', static function () { $GLOBALS['wpdb']->postmeta_values[] = 'https://fixture.invalid/uploads/clip.mp4'; }],
    ['shared option URL without attachment ID', static function () { $GLOBALS['wpdb']->metadata_values['wp_options'][] = 'https://fixture.invalid/uploads/clip.mp4'; }],
    ['JSON-escaped option URL without attachment ID', static function () { $GLOBALS['wpdb']->metadata_values['wp_options'][] = json_encode(['url'=>'https://fixture.invalid/uploads/clip.mp4']); }],
    ['serialized user metadata attachment ID', static function () { $GLOBALS['wpdb']->metadata_values['wp_usermeta'][] = 'a:1:{s:12:"attachment";i:42;}'; }],
    ['JSON term metadata attachment ID', static function () { $GLOBALS['wpdb']->metadata_values['wp_termmeta'][] = '{"attachment":"42"}'; }],
    ['JSON numeric comment metadata attachment ID', static function () { $GLOBALS['wpdb']->metadata_values['wp_commentmeta'][] = '{"attachment":42}'; }],
    ['JSON array option attachment ID', static function () { $GLOBALS['wpdb']->metadata_values['wp_options'][] = '[17,42,99]'; }],
    ['shared comment metadata URL without attachment ID', static function () { $GLOBALS['wpdb']->metadata_values['wp_commentmeta'][] = 'https://fixture.invalid/uploads/clip.mp4'; }],
    ['unowned attachment', static function () { $GLOBALS['wpdb']->meta[42]['_roxy_social_temporary'] = '0'; }],
    ['missing Hangar asset marker', static function () { $GLOBALS['wpdb']->meta[42]['_roxy_hangar_asset_id'] = '0'; }],
    ['wrong attachment type', static function () { $GLOBALS['wpdb']->attachments[42]->post_type = 'post'; }],
    ['reference read failure', static function () { $GLOBALS['wpdb']->reference_query_error = true; }],
    ...array_map(static fn($table) => ['metadata store read failure: ' . $table, static function () use ($table) { $GLOBALS['wpdb']->fail_reference_table = $table; }], ['wp_usermeta', 'wp_termmeta', 'wp_commentmeta', 'wp_options']),
] as [$label, $setup]) {
    fixture(); $setup();
    check_cleanup(\RoxySocial\Store::cleanup_expired() === 0 && isset($wpdb->attachments[42]) && $wpdb->rows[1]['temporary_attachment_id'] === 42, $label . ' fails closed');
}

fixture(); $wpdb->metadata_values['wp_options'][] = '{"count":142}';
check_cleanup(\RoxySocial\Store::cleanup_expired() === 1, 'numeric reference matching respects whole-ID boundaries');
fixture(); $wpdb->metadata_values['wp_options'][] = '{"price":42.50,"label":"asset-42-extra"}';
check_cleanup(\RoxySocial\Store::cleanup_expired() === 1, 'numeric JSON matching ignores decimals and embedded text IDs');

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

fixture();
for ($id = 2; $id <= 101; $id++) {
    $attachment_id = 1000 + $id;
    $wpdb->rows[$id] = ['id'=>$id,'status'=>'posted','temporary_attachment_id'=>$attachment_id,'cleanup_after'=>'2026-10-06 11:00:00','media_url'=>''];
    $wpdb->attachments[$attachment_id] = (object) ['ID'=>$attachment_id,'post_type'=>'attachment'];
    $wpdb->meta[$attachment_id] = ['_roxy_social_temporary'=>'1','_roxy_hangar_asset_id'=>(string) (900 + $id)];
}
$wpdb->busy_lock_names = ['roxy-social-' . substr(hash('sha256', DB_NAME . ':wp_roxy_social_posts:1'), 0, 48)];
$first_busy_cleanup = \RoxySocial\Store::cleanup_expired();
$scheduled_page = $GLOBALS['cleanup_scheduled'][0] ?? null;
check_cleanup($first_busy_cleanup === 99 && isset($scheduled_page[1], $scheduled_page[2])
    && $scheduled_page[1] === 'roxy_social_cleanup_page' && $scheduled_page[2] === [100, 101]
    && isset($wpdb->attachments[42]) && isset($wpdb->attachments[1101]),
    'one bounded page handles busy and available locks, then schedules the remaining fixed snapshot');
check_cleanup(\RoxySocial\Store::cleanup_expired(...$scheduled_page[2]) === 1 && !isset($wpdb->attachments[1101])
    && isset($wpdb->attachments[42]), 'scheduled continuation finishes the remaining row in the fixed snapshot and leaves the earlier busy row for the next full sweep');
$wpdb->busy_lock_names = [];
check_cleanup(\RoxySocial\Store::cleanup_expired() === 1 && !isset($wpdb->attachments[42]), 'next full sweep retries and removes the formerly busy expired-media row');

fixture();
for ($id = 2; $id <= 101; $id++) {
    $attachment_id = 1000 + $id;
    $wpdb->rows[$id] = ['id'=>$id,'status'=>'posted','temporary_attachment_id'=>$attachment_id,'cleanup_after'=>'2026-10-06 11:00:00','media_url'=>''];
    $wpdb->attachments[$attachment_id] = (object) ['ID'=>$attachment_id,'post_type'=>'attachment'];
    $wpdb->meta[$attachment_id] = ['_roxy_social_temporary'=>'1','_roxy_hangar_asset_id'=>(string) (900 + $id)];
}
check_cleanup(\RoxySocial\Store::cleanup_expired() === 100, 'first cleanup page may finish before a later-page storage error');
$scheduled_page = $GLOBALS['cleanup_scheduled'][0] ?? null;
$wpdb->fail_later_candidate_query = true;
check_cleanup(\RoxySocial\Store::cleanup_expired(...$scheduled_page[2]) === 0 && isset($wpdb->attachments[1101]), 'later-page database failure leaves its candidates and references untouched');
$wpdb->fail_later_candidate_query = false;
check_cleanup(\RoxySocial\Store::cleanup_expired(...$scheduled_page[2]) === 1 && !isset($wpdb->attachments[1101]), 'failed continuation can be retried without affecting earlier deletions');

// Detached-media fairness: continuation pages are bounded by the initial
// high-water mark; skipped/failed early rows are retried by the next full sweep.
fixture(['status' => 'failed']);
for ($offset = 0; $offset < 101; $offset++) {
    $attachment_id = 5001 + $offset;
    $social_post_id = $attachment_id === 5001 ? 1 : $attachment_id;
    $wpdb->cleanup_rows[] = ['attachment_id' => $attachment_id, 'social_post_id' => $social_post_id, 'cleanup_after' => '2026-10-06 11:00:00'];
    $wpdb->attachments[$attachment_id] = (object) ['ID' => $attachment_id, 'post_type' => 'attachment'];
    $wpdb->meta[$attachment_id] = ['_roxy_social_temporary' => '1', '_roxy_hangar_asset_id' => (string) (9000 + $offset)];
}
$wpdb->busy_lock_names = ['roxy-social-' . substr(hash('sha256', DB_NAME . ':wp_roxy_social_posts:1'), 0, 48)];
$wpdb->delete_fails_for_ids = [5002];
check_cleanup(\RoxySocial\Store::cleanup_expired() === 98, 'detached first page advances across a busy row and failed deletion');
$detached_page = null;
foreach ($GLOBALS['cleanup_scheduled'] as $scheduled) if (($scheduled[1] ?? '') === 'roxy_social_cleanup_media_page') $detached_page = $scheduled;
check_cleanup(($detached_page[2] ?? null) === [5100, 5101] && count($wpdb->cleanup_rows) === 3
    && isset($wpdb->attachments[5001], $wpdb->attachments[5002]), 'detached continuation records a fixed high-water cursor while preserving skipped rows');
$wpdb->cleanup_rows[] = ['attachment_id' => 9000, 'social_post_id' => 9000, 'cleanup_after' => '2026-10-06 11:00:00'];
$wpdb->attachments[9000] = (object) ['ID' => 9000, 'post_type' => 'attachment'];
$wpdb->meta[9000] = ['_roxy_social_temporary' => '1', '_roxy_hangar_asset_id' => '9900'];
check_cleanup(\RoxySocial\Store::cleanup_detached_media_page(...$detached_page[2]) === 1
    && !isset($wpdb->attachments[5101]) && isset($wpdb->attachments[9000])
    && count($wpdb->cleanup_rows) === 3, 'detached continuation reaches its snapshot end without chasing newer eligible IDs');
$wpdb->busy_lock_names = [];
$wpdb->delete_fails_for_ids = [];
check_cleanup(\RoxySocial\Store::cleanup_expired() === 3 && !isset($wpdb->attachments[5001], $wpdb->attachments[5002], $wpdb->attachments[9000])
    && count($wpdb->cleanup_rows) === 0, 'next full detached sweep retries the formerly busy and failed rows and catches newer entries');

echo "OK: {$checks} social cleanup checks\n";
