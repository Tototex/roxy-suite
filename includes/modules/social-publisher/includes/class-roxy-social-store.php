<?php
namespace RoxySocial;

if (!defined('ABSPATH')) exit;

final class Store {
    public const TABLE = 'roxy_social_posts';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table_name() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_key VARCHAR(190) NOT NULL,
            post_key VARCHAR(190) NOT NULL,
            showing_ids TEXT NOT NULL,
            platform VARCHAR(24) NOT NULL DEFAULT 'both',
            scheduled_for DATETIME NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            ai_status VARCHAR(24) NOT NULL DEFAULT 'pending',
            post_text LONGTEXT NOT NULL,
            media_type VARCHAR(24) NOT NULL DEFAULT 'image',
            media_url TEXT NULL,
            trailer_url TEXT NULL,
            hangar_asset_id BIGINT UNSIGNED NULL,
            hangar_filename VARCHAR(255) NULL,
            temporary_attachment_id BIGINT UNSIGNED NULL,
            cleanup_after DATETIME NULL,
            facebook_post_id VARCHAR(190) NULL,
            instagram_media_id VARCHAR(190) NULL,
            instagram_container_id VARCHAR(190) NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY post_key (post_key),
            KEY campaign_key (campaign_key),
            KEY scheduled_for (scheduled_for),
            KEY status (status)
        ) {$charset};");
    }

    public static function update_media(int $id, int $asset_id, string $filename): bool {
        $expected = self::find($id);
        if (!$expected || $expected['status'] !== 'draft') return false;
        return self::save_draft_snapshot($expected, [
            'hangar_asset_id' => $asset_id,
            'hangar_filename' => sanitize_file_name($filename),
            'updated_at' => current_time('mysql'),
        ]);
    }

    public static function cleanup_expired(): int {
        global $wpdb;
        $table = self::table_name();
        $cutoff = current_time('mysql');
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, temporary_attachment_id, cleanup_after FROM ' . $table . ' WHERE cleanup_after IS NOT NULL AND cleanup_after <= %s AND status IN ("posted", "skipped") AND temporary_attachment_id IS NOT NULL ORDER BY cleanup_after ASC, id ASC LIMIT 100', $cutoff), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) return 0;
        $deleted = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $attachment_id = (int) ($row['temporary_attachment_id'] ?? 0);
            if ($id <= 0 || $attachment_id <= 0) continue;
            $claim = self::acquire_publish_lock($id);
            if (!$claim) continue;
            try {
                $fresh = self::find($id);
                if (!$fresh || !in_array((string) ($fresh['status'] ?? ''), ['posted', 'skipped'], true)
                    || (int) ($fresh['temporary_attachment_id'] ?? 0) !== $attachment_id
                    || (string) ($fresh['cleanup_after'] ?? '') !== (string) ($row['cleanup_after'] ?? '')
                    || (string) ($fresh['cleanup_after'] ?? '') === '' || (string) $fresh['cleanup_after'] > $cutoff) continue;

                $wpdb->last_error = '';
                $attachment = get_post($attachment_id);
                if ($wpdb->last_error !== '') continue;
                if (!$attachment) {
                    if (self::owns_publish_lock($claim)) self::save_publish_values($id, ['temporary_attachment_id' => null, 'cleanup_after' => null, 'updated_at' => current_time('mysql')], $claim);
                    continue;
                }
                if (($attachment->post_type ?? '') !== 'attachment'
                    || (string) get_post_meta($attachment_id, '_roxy_social_temporary', true) !== '1'
                    || (int) get_post_meta($attachment_id, '_roxy_hangar_asset_id', true) <= 0) continue;

                $url = wp_get_attachment_url($attachment_id);
                if (!is_string($url) || $url === '' || !self::owns_publish_lock($claim)) continue;
                $wpdb->last_error = '';
                $shared = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $table . ' WHERE id <> %d AND (temporary_attachment_id = %d OR media_url = %s) LIMIT 1', $id, $attachment_id, $url));
                if ($wpdb->last_error !== '' || $shared !== null) continue;

                $posts = $wpdb->posts;
                $wpdb->last_error = '';
                $post_ref = $wpdb->get_var($wpdb->prepare('SELECT ID FROM ' . $posts . ' WHERE ID <> %d AND (post_content LIKE %s OR post_content LIKE %s) LIMIT 1', $attachment_id, '%' . $wpdb->esc_like($url) . '%', '%' . $wpdb->esc_like('wp-image-' . $attachment_id) . '%'));
                if ($wpdb->last_error !== '' || $post_ref !== null) continue;

                $postmeta = $wpdb->postmeta;
                $wpdb->last_error = '';
                $meta_ref = $wpdb->get_var($wpdb->prepare('SELECT meta_id FROM ' . $postmeta . ' WHERE meta_value = %s OR meta_value LIKE %s LIMIT 1', (string) $attachment_id, '%' . $wpdb->esc_like($url) . '%'));
                if ($wpdb->last_error !== '' || $meta_ref !== null || !self::owns_publish_lock($claim)) continue;

                if (!wp_delete_attachment($attachment_id, true)) continue;
                $deleted++;
                if (!self::owns_publish_lock($claim)) continue;
                self::save_publish_values($id, ['temporary_attachment_id' => null, 'cleanup_after' => null, 'updated_at' => current_time('mysql')], $claim);
            } catch (\Throwable $error) {
                // Keep the row pointer for later review/retry after uncertain cleanup.
                error_log('Roxy Social cleanup could not complete for row #' . $id . '. Tracking was retained for review.');
            } finally {
                self::release_publish_lock($claim);
            }
        }
        return $deleted;
    }

    public static function find_by_post_key(string $post_key): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE post_key = %s', $post_key), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function find(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function update_imported_media(int $id, int $attachment_id, string $media_type, ?string $cleanup_after, int $asset_id = 0, string $filename = '', ?array $expected = null): bool {
        if (!$expected || (int) ($expected['id'] ?? 0) !== $id || ($expected['status'] ?? '') !== 'draft') return false;
        $current = self::find($id);
        // Caption generation and media download may finish in either order.
        // Only rebase a completed AI caption, never a manager edit/approval.
        if ($current && ($expected['ai_status'] ?? '') === 'pending' && ($current['ai_status'] ?? '') === 'ready') {
            $matches = true;
            foreach ($expected as $key => $value) {
                if (in_array($key, ['post_text', 'ai_status', 'updated_at'], true)) continue;
                if (!array_key_exists($key, $current) || $current[$key] !== $value) { $matches = false; break; }
            }
            if ($matches) $expected = $current;
        }
        $url = wp_get_attachment_url($attachment_id) ?: '';
        if ($url === '') return false;
        return self::save_draft_snapshot($expected, [
            'media_type' => sanitize_key($media_type),
            'media_url' => esc_url_raw($url),
            'hangar_asset_id' => $asset_id > 0 ? $asset_id : null,
            'hangar_filename' => $filename !== '' ? sanitize_file_name($filename) : null,
            'temporary_attachment_id' => $media_type === 'video' ? $attachment_id : null,
            'cleanup_after' => $media_type === 'video' ? $cleanup_after : null,
            'updated_at' => current_time('mysql'),
        ]);
    }

    public static function clear_media(int $id): ?int {
        $claim = self::acquire_publish_lock($id);
        if (!$claim) return null;
        try { return self::clear_media_owned($id, $claim); }
        finally { self::release_publish_lock($claim); }
    }

    private static function clear_media_owned(int $id, array $claim): ?int {
        global $wpdb;
        $row = self::find($id);
        if (!$row || $row['status'] !== 'draft' || !empty($row['facebook_post_id']) || !empty($row['instagram_media_id']) || !empty($row['instagram_container_id'])) return null;
        $showing_ids = array_filter(array_map('absint', explode(',', (string) $row['showing_ids'])));
        $poster_url = '';
        if ($showing_ids) {
            $poster_id = get_post_thumbnail_id((int) reset($showing_ids));
            if ($poster_id) $poster_url = wp_get_attachment_url($poster_id) ?: '';
        }
        $updated = self::save_publish_values($id, [
            'media_type' => 'image',
            'media_url' => $poster_url,
            'hangar_asset_id' => null,
            'hangar_filename' => null,
            'temporary_attachment_id' => null,
            'cleanup_after' => null,
            'updated_at' => current_time('mysql'),
        ], $claim);
        // Preserve old uploads until reference-aware cleanup can verify ownership.
        return $updated ? 0 : null;
    }

    public static function upsert(array $data): int {
        global $wpdb;
        $now = current_time('mysql');
        $existing = self::find_by_post_key((string) $data['post_key']);
        $values = [
            'campaign_key' => (string) $data['campaign_key'],
            'post_key' => (string) $data['post_key'],
            'showing_ids' => (string) $data['showing_ids'],
            'platform' => 'both',
            'scheduled_for' => (string) $data['scheduled_for'],
            'post_text' => (string) $data['post_text'],
            'media_type' => (string) ($data['media_type'] ?? 'image'),
            'media_url' => (string) ($data['media_url'] ?? ''),
            'trailer_url' => (string) ($data['trailer_url'] ?? ''),
            'updated_at' => $now,
        ];
        if ($existing) {
            if ($existing['status'] !== 'draft' || in_array(($existing['ai_status'] ?? ''), ['ready', 'manual'], true) || !empty($existing['last_error'])) return (int) $existing['id'];
            self::save_draft_snapshot($existing, $values);
            return (int) $existing['id'];
        }
        $values['status'] = 'draft';
        $values['created_at'] = $now;
        $wpdb->insert(self::table_name(), $values);
        return (int) $wpdb->insert_id;
    }

    public static function create_manual(array $data): int {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert(self::table_name(), [
            'campaign_key' => 'manual',
            'post_key' => 'manual-' . wp_generate_uuid4(),
            'showing_ids' => '',
            'platform' => in_array($data['platform'], ['both', 'facebook', 'instagram'], true) ? $data['platform'] : 'both',
            'scheduled_for' => $data['scheduled_for'],
            'status' => 'draft',
            'post_text' => $data['post_text'],
            'media_type' => $data['media_type'],
            'media_url' => $data['media_url'],
            'trailer_url' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function all_recent(): array {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table_name() . ' ORDER BY scheduled_for ASC, id ASC', ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            if ((string) $row['status'] !== 'draft') continue;
            $expected = $row;
            $values = [];
            if (empty($row['hangar_asset_id']) && !empty($row['temporary_attachment_id'])) {
                $asset_id = (int) get_post_meta((int) $row['temporary_attachment_id'], '_roxy_hangar_asset_id', true);
                if ($asset_id > 0) {
                    $row['hangar_asset_id'] = $asset_id;
                    $row['hangar_filename'] = basename((string) get_post_meta((int) $row['temporary_attachment_id'], '_wp_attached_file', true));
                    $values['hangar_asset_id'] = $asset_id;
                    $values['hangar_filename'] = sanitize_file_name($row['hangar_filename']);
                }
            }
            if (empty($row['media_url']) && empty($row['hangar_asset_id'])) {
                $showing_ids = array_filter(array_map('absint', explode(',', (string) $row['showing_ids'])));
                $poster_id = $showing_ids ? get_post_thumbnail_id((int) reset($showing_ids)) : 0;
                $poster_url = $poster_id ? (wp_get_attachment_url($poster_id) ?: '') : '';
                if ($poster_url !== '') {
                    $values['media_type'] = 'image';
                    $values['media_url'] = esc_url_raw($poster_url);
                }
            }
            if ($values) { self::save_draft_snapshot($expected, $values); $row = self::find((int) $expected['id']) ?? $expected; }
        }
        unset($row);
        return $rows;
    }

    public static function campaign_rows(string $campaign_key): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE campaign_key = %s ORDER BY scheduled_for ASC, id ASC', $campaign_key), ARRAY_A) ?: [];
    }

    public static function update_status(int $id, string $status, string $revision = ''): bool {
        $claim = self::acquire_publish_lock($id);
        if (!$claim) return false;
        try {
            $row = self::find($id);
            if (!$row || $revision === '' || !hash_equals(self::draft_revision($row), $revision)) return false;
            $transitions = ['draft' => ['approved', 'skipped'], 'approved' => ['draft', 'skipped'], 'needs_review' => ['approved', 'draft', 'skipped'], 'failed' => ['approved', 'draft', 'skipped']];
            if (!$row || !in_array($status, $transitions[(string) $row['status']] ?? [], true)) return false;
            return self::compare_publish_status($id, (string) $row['status'], $status, $claim);
        } finally { self::release_publish_lock($claim); }
    }

    public static function draft_revision(array $row): string {
        return hash('sha256', wp_json_encode($row));
    }

    private static function save_draft_snapshot(array $expected, array $values, bool $allow_approved = false): bool {
        $id = (int) ($expected['id'] ?? 0);
        $claim = self::acquire_publish_lock($id);
        if (!$claim) return false;
        try {
            $current = self::find($id);
            if (!$current || !in_array((string) $current['status'], $allow_approved ? ['draft', 'needs_review', 'approved', 'failed'] : ['draft', 'needs_review'], true)
                || !hash_equals(self::draft_revision($expected), self::draft_revision($current))
                || !empty($current['facebook_post_id']) || !empty($current['instagram_media_id']) || !empty($current['instagram_container_id'])) return false;
            $values['updated_at'] = current_time('mysql');
            return self::save_publish_values($id, $values, $claim);
        } finally { self::release_publish_lock($claim); }
    }

    public static function approve_snapshot(array $expected): bool {
        if (($expected['status'] ?? '') !== 'draft' || !empty($expected['last_error'])) return false;
        return self::save_draft_snapshot($expected, ['status' => 'approved']);
    }

    public static function review_snapshot(array $expected, string $error): bool {
        if (($expected['status'] ?? '') !== 'draft' || $error === '') return false;
        return self::save_draft_snapshot($expected, ['status' => 'needs_review', 'last_error' => sanitize_textarea_field($error)]);
    }

    public static function save_ai_result(array $expected, string $text, string $error = ''): bool {
        if (($expected['status'] ?? '') !== 'draft' || ($expected['ai_status'] ?? 'pending') !== 'pending') return false;
        if ($error !== '') return self::save_draft_snapshot($expected, ['status' => 'needs_review', 'ai_status' => 'pending', 'last_error' => sanitize_textarea_field($error)]);
        if ($text === '') return false;
        return self::save_draft_snapshot($expected, ['post_text' => sanitize_textarea_field($text), 'ai_status' => 'ready', 'last_error' => null]);
    }

    public static function retry_ai_snapshot(array $expected): bool {
        if (($expected['status'] ?? '') !== 'needs_review' || ($expected['ai_status'] ?? '') !== 'pending') return false;
        return self::save_draft_snapshot($expected, ['status' => 'draft', 'ai_status' => 'pending', 'last_error' => null]);
    }

    public static function replace_generated_caption(array $expected, string $text): bool {
        if (($expected['status'] ?? '') !== 'draft' || !in_array(($expected['ai_status'] ?? ''), ['ready', 'pending'], true) || $text === '') return false;
        return self::save_draft_snapshot($expected, ['post_text' => sanitize_textarea_field($text), 'ai_status' => 'ready', 'last_error' => null]);
    }

    public static function acquire_publish_lock(int $id): ?array {
        global $wpdb;
        if ($id <= 0) return null;
        $name = 'roxy-social-' . substr(hash('sha256', DB_NAME . ':' . self::table_name() . ':' . $id), 0, 48);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') return null;
        $claim = ['name' => $name, 'connection' => (string) $wpdb->get_var('SELECT CONNECTION_ID()')];
        if (!self::owns_publish_lock($claim)) return null;
        return $claim;
    }

    public static function owns_publish_lock(array $claim): bool {
        global $wpdb;
        $connection = (string) $wpdb->get_var('SELECT CONNECTION_ID()');
        return $connection !== '' && $connection === (string) ($claim['connection'] ?? '')
            && (string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', (string) ($claim['name'] ?? ''))) === $connection;
    }

    public static function release_publish_lock(array $claim): void {
        global $wpdb;
        if (self::owns_publish_lock($claim)) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $claim['name']));
    }

    public static function compare_publish_status(int $id, string $expected, string $status, ?array $claim = null): bool {
        global $wpdb;
        if ($claim !== null) {
            return 1 === $wpdb->query($wpdb->prepare('UPDATE ' . self::table_name() . ' SET status = %s, updated_at = %s WHERE id = %d AND status = %s AND IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d', $status, current_time('mysql'), $id, $expected, $claim['name'], $claim['connection']));
        }
        return 1 === $wpdb->update(self::table_name(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id, 'status' => $expected]);
    }

    public static function update_ai_status(int $id, string $status): bool {
        if (!in_array($status, ['pending', 'ready'], true)) return false;
        $expected = self::find($id);
        if (!$expected || $expected['status'] !== 'draft') return false;
        return self::save_draft_snapshot($expected, ['ai_status' => $status]);
    }

    public static function update_publish_result(int $id, string $status, string $error = '', string $facebook_id = '', string $instagram_id = '', ?array $claim = null): bool {
        global $wpdb;
        $values = [
            'status' => sanitize_key($status),
            'last_error' => $error !== '' ? sanitize_textarea_field($error) : null,
            'updated_at' => current_time('mysql'),
        ];
        if ($facebook_id !== '') $values['facebook_post_id'] = sanitize_text_field($facebook_id);
        if ($instagram_id !== '') $values['instagram_media_id'] = sanitize_text_field($instagram_id);
        return self::save_publish_values($id, $values, $claim);
    }

    public static function clear_publish_id(int $id, string $platform, ?array $claim = null): bool {
        global $wpdb;
        $column = $platform === 'facebook' ? 'facebook_post_id' : ($platform === 'instagram' ? 'instagram_media_id' : '');
        if ($column === '') return false;
        return self::save_publish_values($id, [$column => null, 'updated_at' => current_time('mysql')], $claim);
    }

    public static function set_instagram_container_id(int $id, string $container_id, ?array $claim = null): bool {
        global $wpdb;
        return self::save_publish_values($id, ['instagram_container_id' => sanitize_text_field($container_id), 'updated_at' => current_time('mysql')], $claim);
    }

    public static function clear_instagram_container_id(int $id, ?array $claim = null): bool {
        global $wpdb;
        return self::save_publish_values($id, ['instagram_container_id' => null, 'updated_at' => current_time('mysql')], $claim);
    }

    // A zero-row update may mean unchanged values OR a missing row. Verify the
    // durable values before reporting success; this is not a worker claim.
    private static function save_publish_values(int $id, array $values, ?array $claim = null): bool {
        global $wpdb;
        if ($id <= 0) return false;
        if ($claim !== null) {
            if (!self::owns_publish_lock($claim)) return false;
            $sets = [];
            $parameters = [];
            foreach ($values as $column => $value) {
                $sets[] = '`' . $column . '` = ' . ($value === null ? 'NULL' : '%s');
                if ($value !== null) $parameters[] = $value;
            }
            $parameters[] = $id;
            $parameters[] = $claim['name'];
            // SQL itself rejects a reconnected or stale worker, not just PHP.
            $updated = $wpdb->query($wpdb->prepare('UPDATE ' . self::table_name() . ' SET ' . implode(', ', $sets) . ' WHERE id = %d AND IS_USED_LOCK(%s) = CONNECTION_ID()', $parameters));
            if (!self::owns_publish_lock($claim)) return false;
        } else {
            $updated = $wpdb->update(self::table_name(), $values, ['id' => $id]);
        }
        if ($updated === false) return false;
        $saved = self::find($id);
        if (!$saved) return false;
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $saved)) return false;
            if ($value === null ? $saved[$key] !== null : (!is_scalar($saved[$key]) || (string) $saved[$key] !== (string) $value)) return false;
        }
        return true;
    }

    public static function delete_unposted(int $id): ?int {
        global $wpdb;
        $claim = self::acquire_publish_lock($id);
        if (!$claim) return null;
        try {
            $row = self::find($id);
            if (!$row || (string) $row['status'] === 'publishing') return null;
            $deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table_name() . ' WHERE id = %d AND status <> %s AND IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d', $id, 'publishing', $claim['name'], $claim['connection']));
            return $deleted === 1 ? 0 : null;
        } finally { self::release_publish_lock($claim); }
    }

    public static function update_draft(int $id, string $text, string $scheduled_for, ?string $media_url = null, ?string $media_type = null, string $revision = ''): bool {
        $expected = self::find($id);
        if (!$expected || $revision === '' || !hash_equals(self::draft_revision($expected), $revision)) return false;
        $values = [
            'post_text' => sanitize_textarea_field($text),
            'scheduled_for' => sanitize_text_field($scheduled_for),
            'updated_at' => current_time('mysql'),
            'status' => 'draft',
            'ai_status' => 'manual',
            'last_error' => null,
        ];
        if ($media_url !== null) {
            $values['media_url'] = esc_url_raw($media_url);
            $values['media_type'] = in_array($media_type, ['image', 'video'], true) ? $media_type : 'image';
            $values['hangar_asset_id'] = null;
            $values['hangar_filename'] = null;
            $values['temporary_attachment_id'] = null;
            $values['cleanup_after'] = null;
        }
        return self::save_draft_snapshot($expected, $values, true);
    }

    public static function update_text(int $id, string $text): bool {
        $expected = self::find($id);
        if (!$expected || $expected['status'] !== 'draft') return false;
        return self::save_draft_snapshot($expected, ['post_text' => sanitize_textarea_field($text)]);
    }
}
