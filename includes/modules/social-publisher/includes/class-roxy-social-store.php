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
        global $wpdb;
        return false !== $wpdb->update(self::table_name(), [
            'hangar_asset_id' => $asset_id,
            'hangar_filename' => sanitize_file_name($filename),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    public static function cleanup_expired(): int {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT id, temporary_attachment_id FROM ' . self::table_name() . ' WHERE cleanup_after IS NOT NULL AND cleanup_after <= NOW() AND status IN ("posted", "skipped") AND temporary_attachment_id IS NOT NULL', ARRAY_A) ?: [];
        $deleted = 0;
        foreach ($rows as $row) {
            if (wp_delete_attachment((int) $row['temporary_attachment_id'], true)) $deleted++;
            $wpdb->update(self::table_name(), ['temporary_attachment_id' => null, 'cleanup_after' => null, 'updated_at' => current_time('mysql')], ['id' => (int) $row['id']]);
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

    public static function update_imported_media(int $id, int $attachment_id, string $media_type, ?string $cleanup_after, int $asset_id = 0, string $filename = ''): bool {
        global $wpdb;
        $url = wp_get_attachment_url($attachment_id) ?: '';
        return false !== $wpdb->update(self::table_name(), [
            'media_type' => sanitize_key($media_type),
            'media_url' => esc_url_raw($url),
            'hangar_asset_id' => $asset_id > 0 ? $asset_id : null,
            'hangar_filename' => $filename !== '' ? sanitize_file_name($filename) : null,
            'temporary_attachment_id' => $media_type === 'video' ? $attachment_id : null,
            'cleanup_after' => $media_type === 'video' ? $cleanup_after : null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    public static function clear_media(int $id): ?int {
        global $wpdb;
        $row = self::find($id);
        if (!$row) return null;
        $temporary_id = !empty($row['temporary_attachment_id']) ? (int) $row['temporary_attachment_id'] : 0;
        $showing_ids = array_filter(array_map('absint', explode(',', (string) $row['showing_ids'])));
        $poster_url = '';
        if ($showing_ids) {
            $poster_id = get_post_thumbnail_id((int) reset($showing_ids));
            if ($poster_id) $poster_url = wp_get_attachment_url($poster_id) ?: '';
        }
        $updated = $wpdb->update(self::table_name(), [
            'media_type' => 'image',
            'media_url' => $poster_url,
            'hangar_asset_id' => null,
            'hangar_filename' => null,
            'temporary_attachment_id' => null,
            'cleanup_after' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
        return false === $updated ? null : $temporary_id;
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
            if (!in_array($existing['status'], ['draft', 'needs_review'], true)) return (int) $existing['id'];
            $wpdb->update(self::table_name(), $values, ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }
        $values['status'] = 'draft';
        $values['created_at'] = $now;
        $wpdb->insert(self::table_name(), $values);
        return (int) $wpdb->insert_id;
    }

    public static function all_recent(): array {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table_name() . ' ORDER BY scheduled_for ASC, id ASC', ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            if (empty($row['hangar_asset_id']) && !empty($row['temporary_attachment_id'])) {
                $asset_id = (int) get_post_meta((int) $row['temporary_attachment_id'], '_roxy_hangar_asset_id', true);
                if ($asset_id > 0) {
                    $row['hangar_asset_id'] = $asset_id;
                    $row['hangar_filename'] = basename((string) get_post_meta((int) $row['temporary_attachment_id'], '_wp_attached_file', true));
                    $wpdb->update(self::table_name(), ['hangar_asset_id' => $asset_id, 'hangar_filename' => sanitize_file_name($row['hangar_filename']), 'updated_at' => current_time('mysql')], ['id' => (int) $row['id']]);
                }
            }
            if (!empty($row['media_url']) || !empty($row['hangar_asset_id'])) continue;
            $showing_ids = array_filter(array_map('absint', explode(',', (string) $row['showing_ids'])));
            $poster_id = $showing_ids ? get_post_thumbnail_id((int) reset($showing_ids)) : 0;
            $poster_url = $poster_id ? (wp_get_attachment_url($poster_id) ?: '') : '';
            if ($poster_url !== '') {
                $row['media_url'] = $poster_url;
                $wpdb->update(self::table_name(), ['media_type' => 'image', 'media_url' => esc_url_raw($poster_url), 'updated_at' => current_time('mysql')], ['id' => (int) $row['id']]);
            }
        }
        unset($row);
        return $rows;
    }

    public static function update_status(int $id, string $status): bool {
        global $wpdb;
        $allowed = ['draft', 'approved', 'needs_review', 'skipped', 'publishing', 'posted', 'failed'];
        if (!in_array($status, $allowed, true)) return false;
        return false !== $wpdb->update(self::table_name(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id]);
    }

    public static function update_publish_result(int $id, string $status, string $error = '', string $facebook_id = '', string $instagram_id = ''): bool {
        global $wpdb;
        return false !== $wpdb->update(self::table_name(), [
            'status' => sanitize_key($status),
            'last_error' => $error !== '' ? sanitize_textarea_field($error) : null,
            'facebook_post_id' => $facebook_id !== '' ? sanitize_text_field($facebook_id) : null,
            'instagram_media_id' => $instagram_id !== '' ? sanitize_text_field($instagram_id) : null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    public static function update_draft(int $id, string $text, string $scheduled_for): bool {
        global $wpdb;
        return false !== $wpdb->update(self::table_name(), [
            'post_text' => sanitize_textarea_field($text),
            'scheduled_for' => sanitize_text_field($scheduled_for),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }
}
