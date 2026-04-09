<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Store {
  public const TABLE = 'roxy_grosses_reports';
  public const LOG_TABLE = 'roxy_grosses_logs';
  public const HISTORY_TABLE = 'roxy_grosses_history';
  public const ENTRY_TABLE = 'roxy_grosses_entries';
  public const LIVE_ENTRY_TABLE = 'roxy_grosses_live_entries';
  public const RENTAL_ENTRY_TABLE = 'roxy_grosses_rental_entries';
  public const LEGACY_WEEKLY_TABLE = 'roxy_grosses_legacy_weekly';
  public const IMPORT_BATCH_TABLE = 'roxy_grosses_import_batches';
  public const IMPORT_FILE_TABLE = 'roxy_grosses_import_files';
  public const SCHEMA_OPTION = 'roxy_grosses_schema_version';
  public const HISTORY_BACKFILL_OPTION = 'roxy_grosses_history_backfilled';
  public const ENTRY_MIGRATION_OPTION = 'roxy_grosses_entries_migrated';

  public static function table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::TABLE;
  }

  public static function log_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::LOG_TABLE;
  }

  public static function history_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::HISTORY_TABLE;
  }

  public static function entries_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::ENTRY_TABLE;
  }

  public static function legacy_weekly_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::LEGACY_WEEKLY_TABLE;
  }

  public static function live_entries_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::LIVE_ENTRY_TABLE;
  }

  public static function rental_entries_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::RENTAL_ENTRY_TABLE;
  }

  public static function import_batch_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::IMPORT_BATCH_TABLE;
  }

  public static function import_file_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::IMPORT_FILE_TABLE;
  }

  public static function install_schema(): void {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE " . self::table_name() . " (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      report_end_date DATE NOT NULL,
      lookback_days INT UNSIGNED NOT NULL DEFAULT 0,
      mode VARCHAR(32) NOT NULL DEFAULT 'manual',
      status VARCHAR(32) NOT NULL DEFAULT 'draft',
      summary_gross DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      summary_tickets INT UNSIGNED NOT NULL DEFAULT 0,
      row_count INT UNSIGNED NOT NULL DEFAULT 0,
      emailed_at DATETIME NULL,
      payload_json LONGTEXT NOT NULL,
      PRIMARY KEY (id),
      KEY report_end_date (report_end_date),
      KEY status (status),
      KEY created_at (created_at)
    ) {$charset};");

    dbDelta("CREATE TABLE " . self::log_table_name() . " (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL,
      event_type VARCHAR(32) NOT NULL,
      mode VARCHAR(32) NOT NULL DEFAULT '',
      report_id BIGINT UNSIGNED NULL,
      report_end_date DATE NULL,
      success TINYINT(1) NOT NULL DEFAULT 1,
      message TEXT NULL,
      context_json LONGTEXT NULL,
      PRIMARY KEY (id),
      KEY created_at (created_at),
      KEY event_type (event_type),
      KEY report_id (report_id),
      KEY report_end_date (report_end_date)
    ) {$charset};");

    dbDelta("CREATE TABLE " . self::history_table_name() . " (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      report_date DATE NOT NULL,
      showing_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      show_time VARCHAR(32) NOT NULL DEFAULT '',
      theater_name VARCHAR(190) NOT NULL DEFAULT '',
      film_title VARCHAR(190) NOT NULL DEFAULT '',
      general_qty INT UNSIGNED NOT NULL DEFAULT 0,
      discount_qty INT UNSIGNED NOT NULL DEFAULT 0,
      group_qty INT UNSIGNED NOT NULL DEFAULT 0,
      total_tickets INT UNSIGNED NOT NULL DEFAULT 0,
      gross_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      source_mode VARCHAR(32) NOT NULL DEFAULT '',
      source_report_id BIGINT UNSIGNED NULL,
      PRIMARY KEY (id),
      UNIQUE KEY report_showing (report_date, showing_id),
      KEY report_date (report_date),
      KEY film_title (film_title(100)),
      KEY source_report_id (source_report_id)
    ) {$charset};");

    dbDelta("CREATE TABLE " . self::entries_table_name() . " (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      report_date DATE NOT NULL,
      movie_title VARCHAR(190) NOT NULL DEFAULT '',
      normalized_title VARCHAR(190) NOT NULL DEFAULT '',
      studio VARCHAR(190) NOT NULL DEFAULT '',
      genre VARCHAR(190) NOT NULL DEFAULT '',
      show_time VARCHAR(32) NOT NULL DEFAULT '',
      showing_id BIGINT UNSIGNED NULL,
      theater_name VARCHAR(190) NOT NULL DEFAULT '',
      general_qty INT UNSIGNED NOT NULL DEFAULT 0,
      discount_qty INT UNSIGNED NOT NULL DEFAULT 0,
      group_qty INT UNSIGNED NOT NULL DEFAULT 0,
      subscriber_qty INT UNSIGNED NOT NULL DEFAULT 0,
      live_qty INT UNSIGNED NOT NULL DEFAULT 0,
      other_qty INT UNSIGNED NOT NULL DEFAULT 0,
      total_tickets INT UNSIGNED NOT NULL DEFAULT 0,
      gross_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      concessions_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      source_type VARCHAR(32) NOT NULL DEFAULT '',
      source_ref VARCHAR(64) NOT NULL DEFAULT '',
      source_file VARCHAR(255) NOT NULL DEFAULT '',
      source_batch_id BIGINT UNSIGNED NULL,
      source_report_id BIGINT UNSIGNED NULL,
      notes TEXT NULL,
      is_locked TINYINT(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      UNIQUE KEY day_title_time (report_date, normalized_title(100), show_time),
      KEY report_date (report_date),
      KEY normalized_title (normalized_title(100)),
      KEY studio (studio(100)),
      KEY genre (genre(100)),
      KEY source_batch_id (source_batch_id),
      KEY source_type (source_type)
    ) {$charset};");

    dbDelta("CREATE TABLE " . self::live_entries_table_name() . " (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      report_date DATE NOT NULL,
      show_title VARCHAR(190) NOT NULL DEFAULT '',
      normalized_title VARCHAR(190) NOT NULL DEFAULT '',
      show_time VARCHAR(32) NOT NULL DEFAULT '',
      showing_id BIGINT UNSIGNED NULL,
      theater_name VARCHAR(190) NOT NULL DEFAULT '',
      online_qty INT UNSIGNED NOT NULL DEFAULT 0,
      door_qty INT UNSIGNED NOT NULL DEFAULT 0,
      group_sub_qty INT UNSIGNED NOT NULL DEFAULT 0,
      total_tickets INT UNSIGNED NOT NULL DEFAULT 0,
      gross_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      concessions_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      source_type VARCHAR(32) NOT NULL DEFAULT '',
      source_ref VARCHAR(64) NOT NULL DEFAULT '',
      notes TEXT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY day_title_time (report_date, normalized_title(100), show_time),
      KEY report_date (report_date),
      KEY normalized_title (normalized_title(100)),
      KEY source_type (source_type)
    ) {$charset};");

    dbDelta("CREATE TABLE " . self::rental_entries_table_name() . " (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      report_date DATE NOT NULL,
      rental_title VARCHAR(190) NOT NULL DEFAULT '',
      normalized_title VARCHAR(190) NOT NULL DEFAULT '',
      rental_type VARCHAR(64) NOT NULL DEFAULT '',
      show_time VARCHAR(32) NOT NULL DEFAULT '',
      customer_name VARCHAR(190) NOT NULL DEFAULT '',
      status VARCHAR(32) NOT NULL DEFAULT '',
      invoice_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      concessions_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      source_type VARCHAR(32) NOT NULL DEFAULT '',
      source_ref VARCHAR(64) NOT NULL DEFAULT '',
      notes TEXT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY day_title_time (report_date, normalized_title(100), show_time),
      KEY report_date (report_date),
      KEY normalized_title (normalized_title(100)),
      KEY rental_type (rental_type),
      KEY source_type (source_type)
    ) {$charset};");

    dbDelta("CREATE TABLE " . self::legacy_weekly_table_name() . " (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      week_start_date DATE NOT NULL,
      week_end_date DATE NULL,
      week_label VARCHAR(64) NOT NULL DEFAULT '',
      movie_title VARCHAR(190) NOT NULL DEFAULT '',
      normalized_title VARCHAR(190) NOT NULL DEFAULT '',
      rating VARCHAR(16) NOT NULL DEFAULT '',
      weeks_run VARCHAR(16) NOT NULL DEFAULT '',
      general_qty INT UNSIGNED NOT NULL DEFAULT 0,
      discount_qty INT UNSIGNED NOT NULL DEFAULT 0,
      free_qty INT UNSIGNED NOT NULL DEFAULT 0,
      total_attendance INT UNSIGNED NOT NULL DEFAULT 0,
      gross_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      concessions_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      source_type VARCHAR(32) NOT NULL DEFAULT '',
      source_file VARCHAR(255) NOT NULL DEFAULT '',
      notes TEXT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY week_title (week_start_date, normalized_title(100)),
      KEY week_start_date (week_start_date),
      KEY normalized_title (normalized_title(100)),
      KEY source_type (source_type)
    ) {$charset};");

    dbDelta("CREATE TABLE " . self::import_batch_table_name() . " (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL,
      created_by BIGINT UNSIGNED NULL,
      source_kind VARCHAR(32) NOT NULL DEFAULT '',
      label VARCHAR(190) NOT NULL DEFAULT '',
      file_count INT UNSIGNED NOT NULL DEFAULT 0,
      rows_created INT UNSIGNED NOT NULL DEFAULT 0,
      rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
      rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
      warning_count INT UNSIGNED NOT NULL DEFAULT 0,
      status VARCHAR(32) NOT NULL DEFAULT 'draft',
      summary_json LONGTEXT NULL,
      PRIMARY KEY (id),
      KEY created_at (created_at),
      KEY source_kind (source_kind),
      KEY status (status)
    ) {$charset};");

    dbDelta("CREATE TABLE " . self::import_file_table_name() . " (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      batch_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL,
      original_name VARCHAR(255) NOT NULL DEFAULT '',
      stored_path TEXT NULL,
      parser_type VARCHAR(64) NOT NULL DEFAULT '',
      status VARCHAR(32) NOT NULL DEFAULT 'draft',
      rows_parsed INT UNSIGNED NOT NULL DEFAULT 0,
      rows_imported INT UNSIGNED NOT NULL DEFAULT 0,
      warning_count INT UNSIGNED NOT NULL DEFAULT 0,
      error_message TEXT NULL,
      PRIMARY KEY (id),
      KEY batch_id (batch_id),
      KEY created_at (created_at),
      KEY status (status)
    ) {$charset};");

    update_option(self::SCHEMA_OPTION, ROXY_GROSSES_VER);
  }

  public static function maybe_upgrade_schema(): void {
    if (get_option(self::SCHEMA_OPTION) !== ROXY_GROSSES_VER) {
      self::install_schema();
    }
  }

  public static function maybe_backfill_history(): void {
    if (get_option(self::HISTORY_BACKFILL_OPTION) !== ROXY_GROSSES_VER) {
      self::backfill_history_from_saved_reports();
      update_option(self::HISTORY_BACKFILL_OPTION, ROXY_GROSSES_VER);
    }

    if (get_option(self::ENTRY_MIGRATION_OPTION) !== ROXY_GROSSES_VER) {
      self::migrate_history_to_entries();
      update_option(self::ENTRY_MIGRATION_OPTION, ROXY_GROSSES_VER);
    }
  }

  private static function backfill_history_from_saved_reports(): void {
    global $wpdb;

    $saved_reports = $wpdb->get_results(
      'SELECT id, mode, payload_json FROM ' . self::table_name() . ' ORDER BY id DESC',
      ARRAY_A
    );

    $seen = [];
    foreach ((array) $saved_reports as $saved_report) {
      $payload = json_decode((string) ($saved_report['payload_json'] ?? ''), true);
      $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
      if (!$rows) {
        continue;
      }

      $fresh_rows = [];
      foreach ($rows as $row) {
        $report_date = sanitize_text_field((string) ($row['report_date'] ?? ''));
        $showing_id = max(0, (int) ($row['showing_id'] ?? 0));
        if ($report_date === '') {
          continue;
        }

        $key = $report_date . '|' . $showing_id;
        if (isset($seen[$key])) {
          continue;
        }

        $seen[$key] = true;
        $fresh_rows[] = $row;
      }

      if ($fresh_rows) {
        self::upsert_history_rows($fresh_rows, (string) ($saved_report['mode'] ?? ''), (int) ($saved_report['id'] ?? 0));
      }
    }
  }

  private static function migrate_history_to_entries(): void {
    $rows = self::history_rows_all();
    if (!$rows) {
      return;
    }

    $entries = [];
    foreach ($rows as $row) {
      $entries[] = [
        'report_date' => (string) ($row['report_date'] ?? ''),
        'movie_title' => (string) ($row['film_title'] ?? ''),
        'show_time' => (string) ($row['show_time'] ?? ''),
        'showing_id' => max(0, (int) ($row['showing_id'] ?? 0)),
        'theater_name' => (string) ($row['theater_name'] ?? ''),
        'general_qty' => max(0, (int) ($row['general_qty'] ?? 0)),
        'discount_qty' => max(0, (int) ($row['discount_qty'] ?? 0)),
        'group_qty' => max(0, (int) ($row['group_qty'] ?? 0)),
        'subscriber_qty' => 0,
        'live_qty' => 0,
        'other_qty' => 0,
        'total_tickets' => max(0, (int) ($row['total_tickets'] ?? 0)),
        'gross_total' => round((float) ($row['gross_total'] ?? 0), 2),
        'source_type' => 'saved_report_migration',
        'source_ref' => (string) ($row['source_mode'] ?? ''),
        'source_report_id' => !empty($row['source_report_id']) ? (int) $row['source_report_id'] : null,
        'notes' => 'Migrated from historical grosses rows',
      ];
    }

    self::upsert_entries($entries, 'update');
  }

  public static function create_report(string $report_end_date, int $lookback_days, string $mode, string $status, array $summary, array $rows): int {
    global $wpdb;
    $now = current_time('mysql');
    $ok = $wpdb->insert(self::table_name(), [
      'created_at' => $now,
      'updated_at' => $now,
      'report_end_date' => $report_end_date,
      'lookback_days' => max(0, $lookback_days),
      'mode' => sanitize_text_field($mode),
      'status' => sanitize_text_field($status),
      'summary_gross' => round((float) ($summary['gross_total'] ?? 0), 2),
      'summary_tickets' => max(0, (int) ($summary['total_tickets'] ?? 0)),
      'row_count' => count($rows),
      'emailed_at' => $status === 'emailed' ? $now : null,
      'payload_json' => wp_json_encode(['summary' => $summary, 'rows' => array_values($rows)]),
    ]);
    return $ok ? (int) $wpdb->insert_id : 0;
  }

  public static function mark_emailed(int $report_id): bool {
    global $wpdb;
    $ok = $wpdb->update(self::table_name(), [
      'updated_at' => current_time('mysql'),
      'status' => 'emailed',
      'emailed_at' => current_time('mysql'),
    ], ['id' => $report_id]);
    return $ok !== false;
  }

  public static function get_report(int $report_id): ?array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $report_id), ARRAY_A);
    if (!$row) {
      return null;
    }
    $payload = json_decode((string) $row['payload_json'], true);
    $row['summary'] = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    $row['rows'] = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    return $row;
  }

  public static function list_reports(int $limit = 50): array {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT id, created_at, report_end_date, lookback_days, mode, status, summary_gross, summary_tickets, row_count, emailed_at
       FROM ' . self::table_name() . ' ORDER BY report_end_date DESC, id DESC LIMIT %d',
      max(1, min(200, $limit))
    ), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function insert_log(string $event_type, string $mode, ?int $report_id, ?string $report_end_date, bool $success, string $message, array $context = []): int {
    global $wpdb;
    $ok = $wpdb->insert(self::log_table_name(), [
      'created_at' => current_time('mysql'),
      'event_type' => sanitize_text_field($event_type),
      'mode' => sanitize_text_field($mode),
      'report_id' => $report_id ?: null,
      'report_end_date' => $report_end_date ?: null,
      'success' => $success ? 1 : 0,
      'message' => sanitize_text_field($message),
      'context_json' => wp_json_encode($context),
    ]);
    return $ok ? (int) $wpdb->insert_id : 0;
  }

  public static function list_logs(int $limit = 100): array {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT id, created_at, event_type, mode, report_id, report_end_date, success, message
       FROM ' . self::log_table_name() . ' ORDER BY id DESC LIMIT %d',
      max(1, min(500, $limit))
    ), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function upsert_history_rows(array $rows, string $source_mode = '', ?int $report_id = null): int {
    global $wpdb;
    $count = 0;
    $now = current_time('mysql');

    foreach ($rows as $row) {
      $report_date = sanitize_text_field((string) ($row['report_date'] ?? ''));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
        continue;
      }
      $film_title = sanitize_text_field((string) ($row['film_title'] ?? $row['movie_title'] ?? ''));
      $show_time = sanitize_text_field((string) ($row['show_time'] ?? ''));
      $showing_id = max(0, (int) ($row['showing_id'] ?? 0));
      if ($showing_id <= 0) {
        $showing_id = abs(crc32($report_date . '|' . strtolower($film_title) . '|' . strtolower($show_time)));
      }

      $ok = $wpdb->replace(self::history_table_name(), [
        'created_at' => $now,
        'updated_at' => $now,
        'report_date' => $report_date,
        'showing_id' => $showing_id,
        'show_time' => $show_time,
        'theater_name' => sanitize_text_field((string) ($row['theater_name'] ?? '')),
        'film_title' => $film_title,
        'general_qty' => max(0, (int) ($row['general_qty'] ?? 0)),
        'discount_qty' => max(0, (int) ($row['discount_qty'] ?? 0)),
        'group_qty' => max(0, (int) ($row['group_qty'] ?? 0)),
        'total_tickets' => max(0, (int) ($row['total_tickets'] ?? 0)),
        'gross_total' => round((float) ($row['gross_total'] ?? 0), 2),
        'source_mode' => sanitize_text_field($source_mode),
        'source_report_id' => $report_id ?: null,
      ]);

      if ($ok !== false) {
        $count++;
      }
    }

    return $count;
  }

  public static function history_rows_for_year(int $year): array {
    global $wpdb;
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT report_date, showing_id, show_time, theater_name, film_title, general_qty, discount_qty, group_qty, total_tickets, gross_total
       FROM ' . self::history_table_name() . '
       WHERE report_date BETWEEN %s AND %s
       ORDER BY report_date ASC, show_time ASC, film_title ASC, showing_id ASC',
      $start,
      $end
    ), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function entry_rows_for_year(int $year): array {
    global $wpdb;
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT report_date, showing_id, show_time, theater_name, movie_title, general_qty, discount_qty, group_qty, subscriber_qty, live_qty, other_qty, total_tickets, gross_total, source_type, source_file
       FROM ' . self::entries_table_name() . '
       WHERE report_date BETWEEN %s AND %s
       ORDER BY report_date ASC, show_time ASC, movie_title ASC, showing_id ASC',
      $start,
      $end
    ), ARRAY_A);

    if (!is_array($rows)) {
      return [];
    }

    return array_map(static function (array $row): array {
      $row['film_title'] = (string) ($row['movie_title'] ?? '');
      return $row;
    }, $rows);
  }

  public static function history_rows_all(): array {
    global $wpdb;
    $rows = $wpdb->get_results(
      'SELECT report_date, showing_id, show_time, theater_name, film_title, general_qty, discount_qty, group_qty, total_tickets, gross_total, source_mode, source_report_id
       FROM ' . self::history_table_name() . ' ORDER BY report_date ASC, film_title ASC',
      ARRAY_A
    );
    return is_array($rows) ? $rows : [];
  }

  public static function normalize_title(string $title): string {
    $title = strtolower(trim($title));
    $title = preg_replace('/\s+/', ' ', $title) ?? $title;
    $title = str_replace(['—', '–'], '-', $title);
    return trim($title);
  }

  public static function upsert_entries(array $rows, string $mode = 'update'): array {
    global $wpdb;

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $table = self::entries_table_name();
    $now = current_time('mysql');

    foreach ($rows as $row) {
      $report_date = sanitize_text_field((string) ($row['report_date'] ?? ''));
      $movie_title = sanitize_text_field((string) ($row['movie_title'] ?? $row['film_title'] ?? ''));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date) || $movie_title === '') {
        $skipped++;
        continue;
      }

      $show_time = sanitize_text_field((string) ($row['show_time'] ?? ''));
      $normalized_title = self::normalize_title($movie_title);
      $existing = self::find_existing_entry($report_date, $normalized_title, $show_time);
      $enriched_row = Metadata::enrich_movie_row([
        'report_date' => $report_date,
        'movie_title' => $movie_title,
        'studio' => (string) ($row['studio'] ?? ($existing['studio'] ?? '')),
        'genre' => (string) ($row['genre'] ?? ($existing['genre'] ?? '')),
      ]);

      $payload = [
        'updated_at' => $now,
        'report_date' => $report_date,
        'movie_title' => $movie_title,
        'normalized_title' => $normalized_title,
        'studio' => sanitize_text_field((string) ($enriched_row['studio'] ?? ($existing['studio'] ?? ''))),
        'genre' => sanitize_text_field((string) ($enriched_row['genre'] ?? ($existing['genre'] ?? ''))),
        'show_time' => $show_time,
        'showing_id' => !empty($row['showing_id']) ? max(0, (int) $row['showing_id']) : null,
        'theater_name' => sanitize_text_field((string) ($row['theater_name'] ?? 'Newport Roxy Theater')),
        'general_qty' => max(0, (int) ($row['general_qty'] ?? 0)),
        'discount_qty' => max(0, (int) ($row['discount_qty'] ?? 0)),
        'group_qty' => max(0, (int) ($row['group_qty'] ?? 0)),
        'subscriber_qty' => max(0, (int) ($row['subscriber_qty'] ?? 0)),
        'live_qty' => max(0, (int) ($row['live_qty'] ?? 0)),
        'other_qty' => max(0, (int) ($row['other_qty'] ?? 0)),
        'total_tickets' => max(0, (int) ($row['total_tickets'] ?? 0)),
        'gross_total' => round((float) ($row['gross_total'] ?? 0), 2),
        'concessions_total' => round((float) ($row['concessions_total'] ?? 0), 2),
        'source_type' => sanitize_text_field((string) ($row['source_type'] ?? 'manual_entry')),
        'source_ref' => sanitize_text_field((string) ($row['source_ref'] ?? '')),
        'source_file' => sanitize_text_field((string) ($row['source_file'] ?? '')),
        'source_batch_id' => !empty($row['source_batch_id']) ? (int) $row['source_batch_id'] : null,
        'source_report_id' => !empty($row['source_report_id']) ? (int) $row['source_report_id'] : null,
        'notes' => isset($row['notes']) ? sanitize_text_field((string) $row['notes']) : '',
        'is_locked' => !empty($row['is_locked']) ? 1 : 0,
      ];

      if ($existing) {
        if ($mode === 'skip') {
          $skipped++;
          continue;
        }
        $ok = $wpdb->update($table, $payload, ['id' => (int) $existing['id']]);
        if ($ok !== false) {
          $updated++;
        }
        continue;
      }

      $payload['created_at'] = $now;
      $ok = $wpdb->insert($table, $payload);
      if ($ok !== false) {
        $created++;
      }
    }

    return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
  }

  private static function find_existing_entry(string $report_date, string $normalized_title, string $show_time = ''): ?array {
    global $wpdb;

    if ($show_time !== '') {
      $row = $wpdb->get_row($wpdb->prepare(
        'SELECT id FROM ' . self::entries_table_name() . ' WHERE report_date = %s AND normalized_title = %s AND show_time = %s LIMIT 1',
        $report_date,
        $normalized_title,
        $show_time
      ), ARRAY_A);
      if ($row) {
        return $row;
      }
    }

    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT id FROM ' . self::entries_table_name() . ' WHERE report_date = %s AND normalized_title = %s AND show_time = %s LIMIT 1',
      $report_date,
      $normalized_title,
      ''
    ), ARRAY_A);

    return $row ?: null;
  }

  public static function list_entries(array $filters = [], int $limit = 100, int $offset = 0): array {
    global $wpdb;
    [$where, $params] = self::entry_where_sql($filters);
    $params[] = max(1, min(1000, $limit));
    $params[] = max(0, $offset);
    $sql = 'SELECT * FROM ' . self::entries_table_name() . ' ' . $where . ' ORDER BY report_date DESC, show_time DESC, movie_title ASC LIMIT %d OFFSET %d';
    $rows = $wpdb->get_results(self::prepare_query($sql, $params), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function count_entries(array $filters = []): int {
    global $wpdb;
    [$where, $params] = self::entry_where_sql($filters);
    $sql = 'SELECT COUNT(*) FROM ' . self::entries_table_name() . ' ' . $where;
    return (int) $wpdb->get_var(self::prepare_query($sql, $params));
  }

  public static function delete_entry(int $entry_id): bool {
    global $wpdb;
    if ($entry_id <= 0) {
      return false;
    }

    $deleted = $wpdb->delete(self::entries_table_name(), ['id' => $entry_id], ['%d']);
    return $deleted !== false && $deleted > 0;
  }

  public static function get_entry(int $entry_id): ?array {
    global $wpdb;
    if ($entry_id <= 0) {
      return null;
    }

    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT * FROM ' . self::entries_table_name() . ' WHERE id = %d LIMIT 1',
      $entry_id
    ), ARRAY_A);

    return is_array($row) ? $row : null;
  }

  public static function update_entry(int $entry_id, array $data): bool {
    global $wpdb;
    $existing = self::get_entry($entry_id);
    if (!$existing) {
      return false;
    }

    $movie_title = sanitize_text_field((string) ($data['movie_title'] ?? $existing['movie_title']));
    $enriched_row = Metadata::enrich_movie_row([
      'report_date' => sanitize_text_field((string) ($data['report_date'] ?? $existing['report_date'])),
      'movie_title' => $movie_title,
      'studio' => (string) ($data['studio'] ?? $existing['studio'] ?? ''),
      'genre' => (string) ($data['genre'] ?? $existing['genre'] ?? ''),
    ]);
    $payload = [
      'updated_at' => current_time('mysql'),
      'report_date' => sanitize_text_field((string) ($data['report_date'] ?? $existing['report_date'])),
      'movie_title' => $movie_title,
      'normalized_title' => self::normalize_title($movie_title),
      'studio' => sanitize_text_field((string) ($enriched_row['studio'] ?? '')),
      'genre' => sanitize_text_field((string) ($enriched_row['genre'] ?? '')),
      'show_time' => sanitize_text_field((string) ($data['show_time'] ?? $existing['show_time'])),
      'general_qty' => max(0, (int) ($data['general_qty'] ?? $existing['general_qty'])),
      'discount_qty' => max(0, (int) ($data['discount_qty'] ?? $existing['discount_qty'])),
      'group_qty' => max(0, (int) ($data['group_qty'] ?? $existing['group_qty'])),
      'live_qty' => max(0, (int) ($data['live_qty'] ?? $existing['live_qty'])),
      'total_tickets' => max(0, (int) ($data['total_tickets'] ?? $existing['total_tickets'])),
      'gross_total' => round((float) ($data['gross_total'] ?? $existing['gross_total']), 2),
      'concessions_total' => round((float) ($data['concessions_total'] ?? $existing['concessions_total']), 2),
      'notes' => isset($data['notes']) ? sanitize_text_field((string) $data['notes']) : (string) ($existing['notes'] ?? ''),
    ];

    $updated = $wpdb->update(self::entries_table_name(), $payload, ['id' => $entry_id]);
    return $updated !== false;
  }

  public static function entries_summary(array $filters = []): array {
    global $wpdb;
    [$where, $params] = self::entry_where_sql($filters);
    $sql = 'SELECT COUNT(*) AS row_count,
                   COALESCE(SUM(total_tickets),0) AS admissions,
                   COALESCE(SUM(gross_total),0) AS gross,
                   COALESCE(SUM(concessions_total),0) AS concessions
            FROM ' . self::entries_table_name() . ' ' . $where;
    $row = $wpdb->get_row(self::prepare_query($sql, $params), ARRAY_A);
    $row_count = (int) ($row['row_count'] ?? 0);
    $admissions = (int) ($row['admissions'] ?? 0);
    $gross = round((float) ($row['gross'] ?? 0), 2);
    $concessions = round((float) ($row['concessions'] ?? 0), 2);
    return [
      'row_count' => $row_count,
      'admissions' => $admissions,
      'gross' => $gross,
      'concessions' => $concessions,
      'average_gross' => $row_count > 0 ? round($gross / $row_count, 2) : 0.0,
      'average_concessions' => $row_count > 0 ? round($concessions / $row_count, 2) : 0.0,
      'average_admissions' => $row_count > 0 ? round($admissions / $row_count, 1) : 0.0,
    ];
  }

  private static function entry_where_sql(array $filters): array {
    global $wpdb;

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['search'])) {
      $like = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
      $where[] = '(movie_title LIKE %s OR studio LIKE %s OR genre LIKE %s OR theater_name LIKE %s OR source_file LIKE %s)';
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
    }
    if (!empty($filters['date_from'])) {
      $where[] = 'report_date >= %s';
      $params[] = (string) $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
      $where[] = 'report_date <= %s';
      $params[] = (string) $filters['date_to'];
    }
    if (!empty($filters['year'])) {
      $where[] = 'YEAR(report_date) = %d';
      $params[] = (int) $filters['year'];
    }
    if (!empty($filters['month'])) {
      $where[] = 'DATE_FORMAT(report_date, %s) = %s';
      $params[] = '%Y-%m';
      $params[] = (string) $filters['month'];
    }
    if (!empty($filters['day'])) {
      $where[] = 'report_date = %s';
      $params[] = (string) $filters['day'];
    }
    if (!empty($filters['source_type'])) {
      $where[] = 'source_type = %s';
      $params[] = (string) $filters['source_type'];
    }
    if (!empty($filters['source_batch_id'])) {
      $where[] = 'source_batch_id = %d';
      $params[] = (int) $filters['source_batch_id'];
    }

    return ['WHERE ' . implode(' AND ', $where), $params];
  }

  private static function live_entry_where_sql(array $filters): array {
    global $wpdb;

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['search'])) {
      $like = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
      $where[] = '(show_title LIKE %s OR theater_name LIKE %s)';
      $params[] = $like;
      $params[] = $like;
    }
    if (!empty($filters['date_from'])) {
      $where[] = 'report_date >= %s';
      $params[] = (string) $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
      $where[] = 'report_date <= %s';
      $params[] = (string) $filters['date_to'];
    }
    if (!empty($filters['year'])) {
      $where[] = 'YEAR(report_date) = %d';
      $params[] = (int) $filters['year'];
    }
    if (!empty($filters['month'])) {
      $where[] = 'DATE_FORMAT(report_date, %s) = %s';
      $params[] = '%Y-%m';
      $params[] = (string) $filters['month'];
    }
    if (!empty($filters['day'])) {
      $where[] = 'report_date = %s';
      $params[] = (string) $filters['day'];
    }

    return ['WHERE ' . implode(' AND ', $where), $params];
  }

  public static function overview_metrics(): array {
    $today = wp_date('Y-m-d', null, new \DateTimeZone(Settings::get_report_timezone()));
    $month_key = wp_date('Y-m', null, new \DateTimeZone(Settings::get_report_timezone()));
    $year = (int) substr($today, 0, 4);

    return [
      'month' => self::entries_summary(['month' => $month_key]),
      'year' => self::entries_summary(['year' => $year]),
      'recent_import' => self::latest_import_batch(),
      'entry_count' => self::count_entries(),
    ];
  }

  public static function monthly_trends(int $year): array {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT DATE_FORMAT(report_date, %s) AS month_key, MIN(report_date) AS month_date, COUNT(*) AS row_count,
              COALESCE(SUM(total_tickets),0) AS admissions, COALESCE(SUM(gross_total),0) AS gross
       FROM ' . self::entries_table_name() . '
       WHERE YEAR(report_date) = %d
       GROUP BY DATE_FORMAT(report_date, %s)
       ORDER BY month_key ASC',
      '%Y-%m', $year, '%Y-%m'
    ), ARRAY_A);

    return is_array($rows) ? array_map(static function (array $row): array {
      return [
        'month_key' => (string) ($row['month_key'] ?? ''),
        'month_name' => !empty($row['month_date']) ? wp_date('F', strtotime((string) $row['month_date'])) : '',
        'row_count' => (int) ($row['row_count'] ?? 0),
        'admissions' => (int) ($row['admissions'] ?? 0),
        'gross' => round((float) ($row['gross'] ?? 0), 2),
      ];
    }, $rows) : [];
  }

  public static function top_titles(array $filters = [], int $limit = 10): array {
    global $wpdb;
    [$where, $params] = self::entry_where_sql($filters);
    $params[] = max(1, min(50, $limit));
    $sql = 'SELECT movie_title, COUNT(*) AS row_count, COALESCE(SUM(total_tickets),0) AS admissions, COALESCE(SUM(gross_total),0) AS gross
            FROM ' . self::entries_table_name() . ' ' . $where . '
            GROUP BY normalized_title, movie_title
            ORDER BY gross DESC, admissions DESC
            LIMIT %d';
    $rows = $wpdb->get_results(self::prepare_query($sql, $params), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function top_movie_titles(array $filters = [], int $limit = 5, string $order_by = 'gross'): array {
    global $wpdb;
    [$where, $params] = self::entry_where_sql($filters);
    $params[] = max(1, min(25, $limit));
    $order_sql = $order_by === 'concessions'
      ? 'concessions DESC, gross DESC, admissions DESC'
      : 'gross DESC, concessions DESC, admissions DESC';
    $sql = 'SELECT movie_title,
                   COUNT(*) AS row_count,
                   COALESCE(SUM(total_tickets),0) AS admissions,
                   COALESCE(SUM(gross_total),0) AS gross,
                   COALESCE(SUM(concessions_total),0) AS concessions
            FROM ' . self::entries_table_name() . ' ' . $where . '
            GROUP BY normalized_title, movie_title
            ORDER BY ' . $order_sql . '
            LIMIT %d';
    $rows = $wpdb->get_results(self::prepare_query($sql, $params), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function distinct_years(): array {
    global $wpdb;
    $years = $wpdb->get_col('SELECT DISTINCT YEAR(report_date) AS y FROM ' . self::entries_table_name() . ' ORDER BY y DESC');
    return array_values(array_filter(array_map('intval', (array) $years)));
  }

  public static function list_live_entries(array $filters = [], int $limit = 100, int $offset = 0): array {
    global $wpdb;
    [$where, $params] = self::live_entry_where_sql($filters);
    $params[] = max(1, min(1000, $limit));
    $params[] = max(0, $offset);
    $sql = 'SELECT * FROM ' . self::live_entries_table_name() . ' ' . $where . ' ORDER BY report_date DESC, show_time DESC, show_title ASC LIMIT %d OFFSET %d';
    $rows = $wpdb->get_results(self::prepare_query($sql, $params), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function live_entries_summary(array $filters = []): array {
    global $wpdb;
    [$where, $params] = self::live_entry_where_sql($filters);
    $sql = 'SELECT COUNT(*) AS row_count,
                   COALESCE(SUM(total_tickets),0) AS admissions,
                   COALESCE(SUM(gross_total),0) AS gross,
                   COALESCE(SUM(concessions_total),0) AS concessions
            FROM ' . self::live_entries_table_name() . ' ' . $where;
    $row = $wpdb->get_row(self::prepare_query($sql, $params), ARRAY_A);
    $row_count = (int) ($row['row_count'] ?? 0);
    $admissions = (int) ($row['admissions'] ?? 0);
    $gross = round((float) ($row['gross'] ?? 0), 2);
    $concessions = round((float) ($row['concessions'] ?? 0), 2);
    return [
      'row_count' => $row_count,
      'admissions' => $admissions,
      'gross' => $gross,
      'concessions' => $concessions,
      'average_gross' => $row_count > 0 ? round($gross / $row_count, 2) : 0.0,
      'average_concessions' => $row_count > 0 ? round($concessions / $row_count, 2) : 0.0,
    ];
  }

  public static function distinct_live_years(): array {
    global $wpdb;
    $years = $wpdb->get_col('SELECT DISTINCT YEAR(report_date) AS y FROM ' . self::live_entries_table_name() . ' ORDER BY y DESC');
    return array_values(array_filter(array_map('intval', (array) $years)));
  }

  public static function count_live_entries(array $filters = []): int {
    global $wpdb;
    [$where, $params] = self::live_entry_where_sql($filters);
    $sql = 'SELECT COUNT(*) FROM ' . self::live_entries_table_name() . ' ' . $where;
    return (int) $wpdb->get_var(self::prepare_query($sql, $params));
  }

  public static function top_live_titles(array $filters = [], int $limit = 5, string $order_by = 'gross'): array {
    global $wpdb;
    [$where, $params] = self::live_entry_where_sql($filters);
    $params[] = max(1, min(25, $limit));
    $order_sql = $order_by === 'concessions'
      ? 'concessions DESC, gross DESC, admissions DESC'
      : 'gross DESC, concessions DESC, admissions DESC';
    $sql = 'SELECT show_title,
                   COUNT(*) AS row_count,
                   COALESCE(SUM(total_tickets),0) AS admissions,
                   COALESCE(SUM(gross_total),0) AS gross,
                   COALESCE(SUM(concessions_total),0) AS concessions
            FROM ' . self::live_entries_table_name() . ' ' . $where . '
            GROUP BY normalized_title, show_title
            ORDER BY ' . $order_sql . '
            LIMIT %d';
    $rows = $wpdb->get_results(self::prepare_query($sql, $params), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function get_live_entry(int $entry_id): ?array {
    global $wpdb;
    if ($entry_id <= 0) {
      return null;
    }

    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT * FROM ' . self::live_entries_table_name() . ' WHERE id = %d LIMIT 1',
      $entry_id
    ), ARRAY_A);

    return is_array($row) ? $row : null;
  }

  public static function update_live_entry(int $entry_id, array $data): bool {
    global $wpdb;
    $existing = self::get_live_entry($entry_id);
    if (!$existing) {
      return false;
    }

    $show_title = sanitize_text_field((string) ($data['show_title'] ?? $existing['show_title']));
    $payload = [
      'updated_at' => current_time('mysql'),
      'report_date' => sanitize_text_field((string) ($data['report_date'] ?? $existing['report_date'])),
      'show_title' => $show_title,
      'normalized_title' => self::normalize_title($show_title),
      'show_time' => sanitize_text_field((string) ($data['show_time'] ?? $existing['show_time'])),
      'online_qty' => max(0, (int) ($data['online_qty'] ?? $existing['online_qty'])),
      'door_qty' => max(0, (int) ($data['door_qty'] ?? $existing['door_qty'])),
      'group_sub_qty' => max(0, (int) ($data['group_sub_qty'] ?? $existing['group_sub_qty'])),
      'total_tickets' => max(0, (int) ($data['total_tickets'] ?? $existing['total_tickets'])),
      'gross_total' => round((float) ($data['gross_total'] ?? $existing['gross_total']), 2),
      'concessions_total' => round((float) ($data['concessions_total'] ?? $existing['concessions_total']), 2),
      'notes' => isset($data['notes']) ? sanitize_text_field((string) $data['notes']) : (string) ($existing['notes'] ?? ''),
    ];

    $updated = $wpdb->update(self::live_entries_table_name(), $payload, ['id' => $entry_id]);
    return $updated !== false;
  }

  public static function upsert_live_entries(array $rows, string $mode = 'update'): array {
    global $wpdb;

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $table = self::live_entries_table_name();
    $now = current_time('mysql');

    foreach ($rows as $row) {
      $report_date = sanitize_text_field((string) ($row['report_date'] ?? ''));
      $show_title = sanitize_text_field((string) ($row['show_title'] ?? ''));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date) || $show_title === '') {
        $skipped++;
        continue;
      }

      $show_time = sanitize_text_field((string) ($row['show_time'] ?? ''));
      $normalized_title = self::normalize_title($show_title);
      $existing = self::find_existing_live_entry($report_date, $normalized_title, $show_time);

      $payload = [
        'updated_at' => $now,
        'report_date' => $report_date,
        'show_title' => $show_title,
        'normalized_title' => $normalized_title,
        'show_time' => $show_time,
        'showing_id' => !empty($row['showing_id']) ? (int) $row['showing_id'] : null,
        'theater_name' => sanitize_text_field((string) ($row['theater_name'] ?? '')),
        'online_qty' => max(0, (int) ($row['online_qty'] ?? 0)),
        'door_qty' => max(0, (int) ($row['door_qty'] ?? 0)),
        'group_sub_qty' => max(0, (int) ($row['group_sub_qty'] ?? 0)),
        'total_tickets' => max(0, (int) ($row['total_tickets'] ?? 0)),
        'gross_total' => round((float) ($row['gross_total'] ?? 0), 2),
        'concessions_total' => round((float) ($row['concessions_total'] ?? 0), 2),
        'source_type' => sanitize_text_field((string) ($row['source_type'] ?? '')),
        'source_ref' => sanitize_text_field((string) ($row['source_ref'] ?? '')),
        'notes' => isset($row['notes']) ? sanitize_text_field((string) $row['notes']) : '',
      ];

      if ($existing) {
        if ($mode === 'skip') {
          $skipped++;
          continue;
        }
        $ok = $wpdb->update($table, $payload, ['id' => (int) $existing['id']]);
        if ($ok !== false) {
          $updated++;
        }
        continue;
      }

      $payload['created_at'] = $now;
      $ok = $wpdb->insert($table, $payload);
      if ($ok !== false) {
        $created++;
      }
    }

    return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
  }

  private static function find_existing_live_entry(string $report_date, string $normalized_title, string $show_time): ?array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT id FROM ' . self::live_entries_table_name() . ' WHERE report_date = %s AND normalized_title = %s AND show_time = %s LIMIT 1',
      $report_date,
      $normalized_title,
      $show_time
    ), ARRAY_A);
    return $row ?: null;
  }

  public static function distinct_entry_dates(array $filters = []): array {
    global $wpdb;
    [$where, $params] = self::entry_where_sql($filters);
    $sql = 'SELECT DISTINCT report_date FROM ' . self::entries_table_name() . ' ' . $where . ' ORDER BY report_date ASC';
    $dates = $wpdb->get_col(self::prepare_query($sql, $params));
    return array_values(array_filter(array_map('strval', (array) $dates)));
  }

  public static function distinct_live_entry_dates(array $filters = []): array {
    global $wpdb;
    [$where, $params] = self::live_entry_where_sql($filters);
    $sql = 'SELECT DISTINCT report_date FROM ' . self::live_entries_table_name() . ' ' . $where . ' ORDER BY report_date ASC';
    $dates = $wpdb->get_col(self::prepare_query($sql, $params));
    return array_values(array_filter(array_map('strval', (array) $dates)));
  }

  public static function list_rental_entries(array $filters = [], int $limit = 100, int $offset = 0): array {
    global $wpdb;
    [$where, $params] = self::rental_entry_where_sql($filters);
    $params[] = max(1, min(1000, $limit));
    $params[] = max(0, $offset);
    $sql = 'SELECT * FROM ' . self::rental_entries_table_name() . ' ' . $where . ' ORDER BY report_date DESC, show_time DESC, rental_title ASC LIMIT %d OFFSET %d';
    $rows = $wpdb->get_results(self::prepare_query($sql, $params), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function rental_entries_summary(array $filters = []): array {
    global $wpdb;
    [$where, $params] = self::rental_entry_where_sql($filters);
    $sql = 'SELECT COUNT(*) AS row_count,
                   COALESCE(SUM(invoice_amount),0) AS invoice_total,
                   COALESCE(SUM(concessions_total),0) AS concessions
            FROM ' . self::rental_entries_table_name() . ' ' . $where;
    $row = $wpdb->get_row(self::prepare_query($sql, $params), ARRAY_A);
    $row_count = (int) ($row['row_count'] ?? 0);
    $invoice_total = round((float) ($row['invoice_total'] ?? 0), 2);
    $concessions = round((float) ($row['concessions'] ?? 0), 2);
    return [
      'row_count' => $row_count,
      'invoice_total' => $invoice_total,
      'concessions' => $concessions,
      'average_invoice' => $row_count > 0 ? round($invoice_total / $row_count, 2) : 0.0,
      'average_concessions' => $row_count > 0 ? round($concessions / $row_count, 2) : 0.0,
    ];
  }

  public static function distinct_rental_years(): array {
    global $wpdb;
    $years = $wpdb->get_col('SELECT DISTINCT YEAR(report_date) AS y FROM ' . self::rental_entries_table_name() . ' ORDER BY y DESC');
    return array_values(array_filter(array_map('intval', (array) $years)));
  }

  public static function count_rental_entries(array $filters = []): int {
    global $wpdb;
    [$where, $params] = self::rental_entry_where_sql($filters);
    $sql = 'SELECT COUNT(*) FROM ' . self::rental_entries_table_name() . ' ' . $where;
    return (int) $wpdb->get_var(self::prepare_query($sql, $params));
  }

  public static function get_rental_entry(int $entry_id): ?array {
    global $wpdb;
    if ($entry_id <= 0) {
      return null;
    }

    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT * FROM ' . self::rental_entries_table_name() . ' WHERE id = %d LIMIT 1',
      $entry_id
    ), ARRAY_A);

    return is_array($row) ? $row : null;
  }

  public static function update_rental_entry(int $entry_id, array $data): bool {
    global $wpdb;
    $existing = self::get_rental_entry($entry_id);
    if (!$existing) {
      return false;
    }

    $rental_title = sanitize_text_field((string) ($data['rental_title'] ?? $existing['rental_title']));
    $payload = [
      'updated_at' => current_time('mysql'),
      'report_date' => sanitize_text_field((string) ($data['report_date'] ?? $existing['report_date'])),
      'rental_title' => $rental_title,
      'normalized_title' => self::normalize_title($rental_title),
      'rental_type' => sanitize_text_field((string) ($data['rental_type'] ?? $existing['rental_type'])),
      'show_time' => sanitize_text_field((string) ($data['show_time'] ?? $existing['show_time'])),
      'customer_name' => sanitize_text_field((string) ($data['customer_name'] ?? $existing['customer_name'])),
      'status' => sanitize_text_field((string) ($data['status'] ?? $existing['status'])),
      'invoice_amount' => round((float) ($data['invoice_amount'] ?? $existing['invoice_amount']), 2),
      'concessions_total' => round((float) ($data['concessions_total'] ?? $existing['concessions_total']), 2),
      'notes' => isset($data['notes']) ? sanitize_text_field((string) $data['notes']) : (string) ($existing['notes'] ?? ''),
    ];

    $updated = $wpdb->update(self::rental_entries_table_name(), $payload, ['id' => $entry_id]);
    return $updated !== false;
  }

  public static function upsert_rental_entries(array $rows, string $mode = 'update'): array {
    global $wpdb;

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $table = self::rental_entries_table_name();
    $now = current_time('mysql');

    foreach ($rows as $row) {
      $report_date = sanitize_text_field((string) ($row['report_date'] ?? ''));
      $rental_title = sanitize_text_field((string) ($row['rental_title'] ?? ''));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date) || $rental_title === '') {
        $skipped++;
        continue;
      }

      $show_time = sanitize_text_field((string) ($row['show_time'] ?? ''));
      $normalized_title = self::normalize_title($rental_title);
      $existing = self::find_existing_rental_entry($report_date, $normalized_title, $show_time);

      $payload = [
        'updated_at' => $now,
        'report_date' => $report_date,
        'rental_title' => $rental_title,
        'normalized_title' => $normalized_title,
        'rental_type' => sanitize_text_field((string) ($row['rental_type'] ?? '')),
        'show_time' => $show_time,
        'customer_name' => sanitize_text_field((string) ($row['customer_name'] ?? '')),
        'status' => sanitize_text_field((string) ($row['status'] ?? '')),
        'invoice_amount' => round((float) ($row['invoice_amount'] ?? 0), 2),
        'concessions_total' => round((float) ($row['concessions_total'] ?? 0), 2),
        'source_type' => sanitize_text_field((string) ($row['source_type'] ?? 'invoice_import')),
        'source_ref' => sanitize_text_field((string) ($row['source_ref'] ?? '')),
        'notes' => isset($row['notes']) ? sanitize_text_field((string) $row['notes']) : '',
      ];

      if ($existing) {
        if ($mode === 'skip') {
          $skipped++;
          continue;
        }
        $ok = $wpdb->update($table, $payload, ['id' => (int) $existing['id']]);
        if ($ok !== false) {
          $updated++;
        }
        continue;
      }

      $payload['created_at'] = $now;
      $ok = $wpdb->insert($table, $payload);
      if ($ok !== false) {
        $created++;
      }
    }

    return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
  }

  private static function find_existing_rental_entry(string $report_date, string $normalized_title, string $show_time): ?array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT id FROM ' . self::rental_entries_table_name() . ' WHERE report_date = %s AND normalized_title = %s AND show_time = %s LIMIT 1',
      $report_date,
      $normalized_title,
      $show_time
    ), ARRAY_A);
    return $row ?: null;
  }

  public static function upsert_legacy_weekly(array $rows, string $mode = 'update'): array {
    global $wpdb;

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $table = self::legacy_weekly_table_name();
    $now = current_time('mysql');

    foreach ($rows as $row) {
      $week_start_date = sanitize_text_field((string) ($row['week_start_date'] ?? ''));
      $movie_title = sanitize_text_field((string) ($row['movie_title'] ?? ''));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start_date) || $movie_title === '') {
        $skipped++;
        continue;
      }

      $normalized_title = self::normalize_title($movie_title);
      $existing = self::find_existing_legacy_weekly($week_start_date, $normalized_title);
      $payload = [
        'updated_at' => $now,
        'week_start_date' => $week_start_date,
        'week_end_date' => !empty($row['week_end_date']) ? sanitize_text_field((string) $row['week_end_date']) : null,
        'week_label' => sanitize_text_field((string) ($row['week_label'] ?? '')),
        'movie_title' => $movie_title,
        'normalized_title' => $normalized_title,
        'rating' => sanitize_text_field((string) ($row['rating'] ?? '')),
        'weeks_run' => sanitize_text_field((string) ($row['weeks_run'] ?? '')),
        'general_qty' => max(0, (int) ($row['general_qty'] ?? 0)),
        'discount_qty' => max(0, (int) ($row['discount_qty'] ?? 0)),
        'free_qty' => max(0, (int) ($row['free_qty'] ?? 0)),
        'total_attendance' => max(0, (int) ($row['total_attendance'] ?? 0)),
        'gross_total' => round((float) ($row['gross_total'] ?? 0), 2),
        'concessions_total' => round((float) ($row['concessions_total'] ?? 0), 2),
        'source_type' => sanitize_text_field((string) ($row['source_type'] ?? 'legacy_weekly_import')),
        'source_file' => sanitize_text_field((string) ($row['source_file'] ?? '')),
        'notes' => isset($row['notes']) ? sanitize_text_field((string) $row['notes']) : '',
      ];

      if ($existing) {
        if ($mode === 'skip') {
          $skipped++;
          continue;
        }
        $ok = $wpdb->update($table, $payload, ['id' => (int) $existing['id']]);
        if ($ok !== false) {
          $updated++;
        }
        continue;
      }

      $payload['created_at'] = $now;
      $ok = $wpdb->insert($table, $payload);
      if ($ok !== false) {
        $created++;
      }
    }

    return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
  }

  private static function find_existing_legacy_weekly(string $week_start_date, string $normalized_title): ?array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT id FROM ' . self::legacy_weekly_table_name() . ' WHERE week_start_date = %s AND normalized_title = %s LIMIT 1',
      $week_start_date,
      $normalized_title
    ), ARRAY_A);
    return $row ?: null;
  }

  public static function list_legacy_weekly(array $filters = [], int $limit = 100, int $offset = 0): array {
    global $wpdb;
    [$where, $params] = self::legacy_weekly_where_sql($filters);
    $params[] = max(1, min(1000, $limit));
    $params[] = max(0, $offset);
    $sql = 'SELECT * FROM ' . self::legacy_weekly_table_name() . ' ' . $where . ' ORDER BY week_start_date DESC, movie_title ASC LIMIT %d OFFSET %d';
    $rows = $wpdb->get_results(self::prepare_query($sql, $params), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function legacy_weekly_summary(array $filters = []): array {
    global $wpdb;
    [$where, $params] = self::legacy_weekly_where_sql($filters);
    $sql = 'SELECT COUNT(*) AS row_count,
                   COALESCE(SUM(total_attendance),0) AS attendance,
                   COALESCE(SUM(general_qty + discount_qty),0) AS paid,
                   COALESCE(SUM(free_qty),0) AS free,
                   COALESCE(SUM(gross_total),0) AS gross,
                   COALESCE(SUM(concessions_total),0) AS concessions
            FROM ' . self::legacy_weekly_table_name() . ' ' . $where;
    $row = $wpdb->get_row(self::prepare_query($sql, $params), ARRAY_A);
    $row_count = (int) ($row['row_count'] ?? 0);
    $attendance = (int) ($row['attendance'] ?? 0);
    $paid = (int) ($row['paid'] ?? 0);
    $free = (int) ($row['free'] ?? 0);
    $gross = round((float) ($row['gross'] ?? 0), 2);
    $concessions = round((float) ($row['concessions'] ?? 0), 2);
    return [
      'row_count' => $row_count,
      'attendance' => $attendance,
      'paid' => $paid,
      'free' => $free,
      'gross' => $gross,
      'concessions' => $concessions,
      'average_attendance' => $row_count > 0 ? round($attendance / $row_count, 1) : 0.0,
      'average_gross' => $row_count > 0 ? round($gross / $row_count, 2) : 0.0,
      'average_concessions' => $row_count > 0 ? round($concessions / $row_count, 2) : 0.0,
    ];
  }

  private static function legacy_weekly_where_sql(array $filters): array {
    global $wpdb;

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['search'])) {
      $like = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
      $where[] = '(movie_title LIKE %s OR source_file LIKE %s OR week_label LIKE %s)';
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
    }
    if (!empty($filters['date_from'])) {
      $where[] = 'week_start_date >= %s';
      $params[] = (string) $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
      $where[] = 'week_start_date <= %s';
      $params[] = (string) $filters['date_to'];
    }
    if (!empty($filters['year'])) {
      $where[] = 'YEAR(week_start_date) = %d';
      $params[] = (int) $filters['year'];
    }
    if (!empty($filters['month'])) {
      $where[] = 'DATE_FORMAT(week_start_date, %s) = %s';
      $params[] = '%Y-%m';
      $params[] = (string) $filters['month'];
    }
    if (!empty($filters['day'])) {
      $where[] = 'week_start_date = %s';
      $params[] = (string) $filters['day'];
    }

    return ['WHERE ' . implode(' AND ', $where), $params];
  }

  public static function distinct_legacy_weekly_years(): array {
    global $wpdb;
    $years = $wpdb->get_col('SELECT DISTINCT YEAR(week_start_date) AS y FROM ' . self::legacy_weekly_table_name() . ' ORDER BY y DESC');
    return array_values(array_filter(array_map('intval', (array) $years)));
  }

  public static function count_legacy_weekly(array $filters = []): int {
    global $wpdb;
    [$where, $params] = self::legacy_weekly_where_sql($filters);
    $sql = 'SELECT COUNT(*) FROM ' . self::legacy_weekly_table_name() . ' ' . $where;
    return (int) $wpdb->get_var(self::prepare_query($sql, $params));
  }

  public static function top_legacy_titles(array $filters = [], int $limit = 5, string $order_by = 'gross'): array {
    global $wpdb;
    [$where, $params] = self::legacy_weekly_where_sql($filters);
    $params[] = max(1, min(25, $limit));
    $order_sql = $order_by === 'concessions'
      ? 'concessions DESC, gross DESC, attendance DESC'
      : 'gross DESC, concessions DESC, attendance DESC';
    $sql = 'SELECT movie_title,
                   COUNT(*) AS row_count,
                   COALESCE(SUM(total_attendance),0) AS attendance,
                   COALESCE(SUM(gross_total),0) AS gross,
                   COALESCE(SUM(concessions_total),0) AS concessions
            FROM ' . self::legacy_weekly_table_name() . ' ' . $where . '
            GROUP BY normalized_title, movie_title
            ORDER BY ' . $order_sql . '
            LIMIT %d';
    $rows = $wpdb->get_results(self::prepare_query($sql, $params), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function get_legacy_weekly(int $entry_id): ?array {
    global $wpdb;
    if ($entry_id <= 0) {
      return null;
    }

    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT * FROM ' . self::legacy_weekly_table_name() . ' WHERE id = %d LIMIT 1',
      $entry_id
    ), ARRAY_A);

    return is_array($row) ? $row : null;
  }

  public static function update_legacy_weekly(int $entry_id, array $data): bool {
    global $wpdb;
    $existing = self::get_legacy_weekly($entry_id);
    if (!$existing) {
      return false;
    }

    $movie_title = sanitize_text_field((string) ($data['movie_title'] ?? $existing['movie_title']));
    $payload = [
      'updated_at' => current_time('mysql'),
      'week_start_date' => sanitize_text_field((string) ($data['week_start_date'] ?? $existing['week_start_date'])),
      'week_end_date' => sanitize_text_field((string) ($data['week_end_date'] ?? $existing['week_end_date'])),
      'movie_title' => $movie_title,
      'normalized_title' => self::normalize_title($movie_title),
      'rating' => sanitize_text_field((string) ($data['rating'] ?? $existing['rating'])),
      'weeks_run' => sanitize_text_field((string) ($data['weeks_run'] ?? $existing['weeks_run'])),
      'general_qty' => max(0, (int) ($data['general_qty'] ?? $existing['general_qty'])),
      'discount_qty' => max(0, (int) ($data['discount_qty'] ?? $existing['discount_qty'])),
      'free_qty' => max(0, (int) ($data['free_qty'] ?? $existing['free_qty'])),
      'total_attendance' => max(0, (int) ($data['total_attendance'] ?? $existing['total_attendance'])),
      'gross_total' => round((float) ($data['gross_total'] ?? $existing['gross_total']), 2),
      'concessions_total' => round((float) ($data['concessions_total'] ?? $existing['concessions_total']), 2),
      'notes' => isset($data['notes']) ? sanitize_text_field((string) $data['notes']) : (string) ($existing['notes'] ?? ''),
    ];

    $updated = $wpdb->update(self::legacy_weekly_table_name(), $payload, ['id' => $entry_id]);
    return $updated !== false;
  }

  private static function rental_entry_where_sql(array $filters): array {
    global $wpdb;

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['search'])) {
      $like = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
      $where[] = '(rental_title LIKE %s OR customer_name LIKE %s OR rental_type LIKE %s OR notes LIKE %s)';
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
    }
    if (!empty($filters['date_from'])) {
      $where[] = 'report_date >= %s';
      $params[] = (string) $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
      $where[] = 'report_date <= %s';
      $params[] = (string) $filters['date_to'];
    }
    if (!empty($filters['year'])) {
      $where[] = 'YEAR(report_date) = %d';
      $params[] = (int) $filters['year'];
    }
    if (!empty($filters['month'])) {
      $where[] = 'DATE_FORMAT(report_date, %s) = %s';
      $params[] = '%Y-%m';
      $params[] = (string) $filters['month'];
    }
    if (!empty($filters['day'])) {
      $where[] = 'report_date = %s';
      $params[] = (string) $filters['day'];
    }

    return ['WHERE ' . implode(' AND ', $where), $params];
  }

  public static function distinct_source_types(): array {
    global $wpdb;
    $values = $wpdb->get_col('SELECT DISTINCT source_type FROM ' . self::entries_table_name() . ' WHERE source_type <> "" ORDER BY source_type ASC');
    return array_values(array_filter(array_map('strval', (array) $values)));
  }

  public static function top_rentals(array $filters = [], int $limit = 5, string $order_by = 'concessions'): array {
    global $wpdb;
    [$where, $params] = self::rental_entry_where_sql($filters);
    $params[] = max(1, min(25, $limit));
    $order_sql = $order_by === 'invoice'
      ? 'invoice_total DESC, concessions DESC, row_count DESC'
      : 'concessions DESC, invoice_total DESC, row_count DESC';
    $sql = 'SELECT rental_title,
                   COUNT(*) AS row_count,
                   COALESCE(SUM(invoice_amount),0) AS invoice_total,
                   COALESCE(SUM(concessions_total),0) AS concessions
            FROM ' . self::rental_entries_table_name() . ' ' . $where . '
            GROUP BY normalized_title, rental_title
            ORDER BY ' . $order_sql . '
            LIMIT %d';
    $rows = $wpdb->get_results(self::prepare_query($sql, $params), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function create_import_batch(string $source_kind, string $label): int {
    global $wpdb;
    $wpdb->insert(self::import_batch_table_name(), [
      'created_at' => current_time('mysql'),
      'created_by' => get_current_user_id() ?: null,
      'source_kind' => sanitize_text_field($source_kind),
      'label' => sanitize_text_field($label),
      'status' => 'running',
    ]);
    return (int) $wpdb->insert_id;
  }

  public static function add_import_file(int $batch_id, string $original_name, string $stored_path, string $parser_type, string $status): int {
    global $wpdb;
    $wpdb->insert(self::import_file_table_name(), [
      'batch_id' => $batch_id,
      'created_at' => current_time('mysql'),
      'original_name' => sanitize_file_name($original_name),
      'stored_path' => $stored_path,
      'parser_type' => sanitize_text_field($parser_type),
      'status' => sanitize_text_field($status),
    ]);
    return (int) $wpdb->insert_id;
  }

  public static function update_import_file_path(int $file_id, string $stored_path): void {
    global $wpdb;
    $wpdb->update(self::import_file_table_name(), ['stored_path' => $stored_path], ['id' => $file_id]);
  }

  public static function update_import_file_status(int $file_id, string $status, int $rows_parsed, int $rows_imported, int $warning_count, string $error_message = ''): void {
    global $wpdb;
    $wpdb->update(self::import_file_table_name(), [
      'status' => sanitize_text_field($status),
      'rows_parsed' => max(0, $rows_parsed),
      'rows_imported' => max(0, $rows_imported),
      'warning_count' => max(0, $warning_count),
      'error_message' => $error_message,
    ], ['id' => $file_id]);
  }

  public static function finish_import_batch(int $batch_id, int $file_count, int $rows_created, int $rows_updated, int $rows_skipped, int $warning_count, string $status): void {
    global $wpdb;
    $wpdb->update(self::import_batch_table_name(), [
      'file_count' => max(0, $file_count),
      'rows_created' => max(0, $rows_created),
      'rows_updated' => max(0, $rows_updated),
      'rows_skipped' => max(0, $rows_skipped),
      'warning_count' => max(0, $warning_count),
      'status' => sanitize_text_field($status),
      'summary_json' => wp_json_encode([
        'rows_created' => $rows_created,
        'rows_updated' => $rows_updated,
        'rows_skipped' => $rows_skipped,
        'warning_count' => $warning_count,
      ]),
    ], ['id' => $batch_id]);
  }

  public static function latest_import_batch(): ?array {
    global $wpdb;
    $row = $wpdb->get_row('SELECT * FROM ' . self::import_batch_table_name() . ' ORDER BY id DESC LIMIT 1', ARRAY_A);
    return $row ?: null;
  }

  public static function latest_log(array $event_types = [], ?bool $success = null): ?array {
    global $wpdb;
    $where = ['1=1'];
    $params = [];

    if ($event_types) {
      $placeholders = implode(',', array_fill(0, count($event_types), '%s'));
      $where[] = 'event_type IN (' . $placeholders . ')';
      foreach ($event_types as $event_type) {
        $params[] = (string) $event_type;
      }
    }
    if ($success !== null) {
      $where[] = 'success = %d';
      $params[] = $success ? 1 : 0;
    }

    $sql = 'SELECT * FROM ' . self::log_table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 1';
    $row = $wpdb->get_row(self::prepare_query($sql, $params), ARRAY_A);
    return is_array($row) ? $row : null;
  }

  public static function count_logs_by_type(string $event_type, int $days = 30): int {
    global $wpdb;
    $days = max(1, $days);
    $threshold = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
    return (int) $wpdb->get_var($wpdb->prepare(
      'SELECT COUNT(*) FROM ' . self::log_table_name() . ' WHERE event_type = %s AND created_at >= %s',
      $event_type,
      $threshold
    ));
  }

  public static function backfill_movie_metadata(int $limit = 500, bool $force = false): array {
    global $wpdb;
    $limit = max(1, min(5000, $limit));
    $where = $force ? '1=1' : "(studio = '' OR genre = '' OR studio IS NULL OR genre IS NULL)";
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT id, report_date, movie_title, studio, genre FROM ' . self::entries_table_name() . ' WHERE ' . $where . ' ORDER BY report_date DESC, id DESC LIMIT %d',
      $limit
    ), ARRAY_A);

    $updated = 0;
    $skipped = 0;
    foreach ((array) $rows as $row) {
      $enriched = Metadata::enrich_movie_row($row, $force);
      $studio = sanitize_text_field((string) ($enriched['studio'] ?? ''));
      $genre = sanitize_text_field((string) ($enriched['genre'] ?? ''));
      $current_studio = (string) ($row['studio'] ?? '');
      $current_genre = (string) ($row['genre'] ?? '');
      if ($studio === $current_studio && $genre === $current_genre) {
        $skipped++;
        continue;
      }

      $result = $wpdb->update(
        self::entries_table_name(),
        [
          'updated_at' => current_time('mysql'),
          'studio' => $studio,
          'genre' => $genre,
        ],
        ['id' => (int) $row['id']]
      );
      if ($result !== false) {
        $updated++;
      } else {
        $skipped++;
      }
    }

    return [
      'processed' => count((array) $rows),
      'updated' => $updated,
      'skipped' => $skipped,
    ];
  }

  public static function top_movie_group_average(array $filters, string $group_by, string $metric): ?array {
    global $wpdb;
    if ($group_by === 'genre') {
      return self::top_movie_genre_average($filters, $metric);
    }

    if ($group_by !== 'studio') {
      return null;
    }

    $metric_column = $metric === 'concessions' ? 'concessions_total' : 'gross_total';
    [$where, $params] = self::entry_where_sql($filters);
    $sql = 'SELECT ' . $group_by . ' AS label,
                   COUNT(*) AS row_count,
                   COALESCE(AVG(' . $metric_column . '),0) AS average_value,
                   COALESCE(SUM(' . $metric_column . '),0) AS total_value
            FROM ' . self::entries_table_name() . ' ' . $where . '
              AND ' . $group_by . " <> ''
            GROUP BY " . $group_by . '
            ORDER BY average_value DESC, total_value DESC, row_count DESC
            LIMIT 1';
    $row = $wpdb->get_row(self::prepare_query($sql, $params), ARRAY_A);
    return is_array($row) ? $row : null;
  }

  public static function top_movie_groups(array $filters, string $group_by, int $limit = 5, string $metric = 'gross'): array {
    if ($group_by === 'genre') {
      return self::top_movie_genre_groups($filters, $limit, $metric);
    }

    if ($group_by !== 'studio') {
      return [];
    }

    global $wpdb;
    $limit = max(1, min(20, $limit));
    $metric_column = $metric === 'concessions' ? 'concessions_total' : 'gross_total';
    $secondary_column = $metric === 'concessions' ? 'gross_total' : 'concessions_total';
    [$where, $params] = self::entry_where_sql($filters);
    $sql = 'SELECT studio AS label,
                   COUNT(*) AS row_count,
                   COALESCE(SUM(' . $metric_column . '),0) AS primary_total,
                   COALESCE(SUM(' . $secondary_column . '),0) AS secondary_total
            FROM ' . self::entries_table_name() . ' ' . $where . '
              AND studio <> ""
            GROUP BY studio
            ORDER BY primary_total DESC, secondary_total DESC, row_count DESC
            LIMIT %d';
    $params[] = $limit;
    $rows = $wpdb->get_results(self::prepare_query($sql, $params), ARRAY_A);
    return array_values(array_filter(array_map(static function ($row) {
      return is_array($row) ? $row : null;
    }, (array) $rows)));
  }

  private static function top_movie_genre_average(array $filters, string $metric): ?array {
    $rows = self::list_entries($filters, 5000, 0);
    if (!$rows) {
      return null;
    }

    $metric_key = $metric === 'concessions' ? 'concessions_total' : 'gross_total';
    $genres = [];
    foreach ($rows as $row) {
      $genre_value = trim((string) ($row['genre'] ?? ''));
      if ($genre_value === '') {
        continue;
      }

      $tokens = array_values(array_filter(array_map('trim', explode(',', $genre_value))));
      if (!$tokens) {
        continue;
      }

      $value = (float) ($row[$metric_key] ?? 0);
      foreach ($tokens as $token) {
        if (!isset($genres[$token])) {
          $genres[$token] = [
            'label' => $token,
            'row_count' => 0,
            'average_value' => 0.0,
            'total_value' => 0.0,
          ];
        }
        $genres[$token]['row_count']++;
        $genres[$token]['total_value'] += $value;
      }
    }

    if (!$genres) {
      return null;
    }

    foreach ($genres as &$genre_row) {
      $genre_row['average_value'] = $genre_row['row_count'] > 0
        ? $genre_row['total_value'] / $genre_row['row_count']
        : 0.0;
    }
    unset($genre_row);

    uasort($genres, static function (array $a, array $b): int {
      return [$b['average_value'], $b['total_value'], $b['row_count'], $a['label']]
        <=> [$a['average_value'], $a['total_value'], $a['row_count'], $b['label']];
    });

    $top = reset($genres);
    return is_array($top) ? $top : null;
  }

  private static function top_movie_genre_groups(array $filters, int $limit = 5, string $metric = 'gross'): array {
    $rows = self::list_entries($filters, 5000, 0);
    if (!$rows) {
      return [];
    }

    $primary_key = $metric === 'concessions' ? 'concessions_total' : 'gross_total';
    $secondary_key = $metric === 'concessions' ? 'gross_total' : 'concessions_total';
    $genres = [];
    foreach ($rows as $row) {
      $genre_value = trim((string) ($row['genre'] ?? ''));
      if ($genre_value === '') {
        continue;
      }

      $tokens = array_values(array_filter(array_map('trim', explode(',', $genre_value))));
      if (!$tokens) {
        continue;
      }

      $primary_value = (float) ($row[$primary_key] ?? 0);
      $secondary_value = (float) ($row[$secondary_key] ?? 0);
      foreach ($tokens as $token) {
        if (!isset($genres[$token])) {
          $genres[$token] = [
            'label' => $token,
            'row_count' => 0,
            'primary_total' => 0.0,
            'secondary_total' => 0.0,
          ];
        }
        $genres[$token]['row_count']++;
        $genres[$token]['primary_total'] += $primary_value;
        $genres[$token]['secondary_total'] += $secondary_value;
      }
    }

    uasort($genres, static function (array $a, array $b): int {
      $cmp = $b['primary_total'] <=> $a['primary_total'];
      if ($cmp !== 0) {
        return $cmp;
      }
      $cmp = $b['secondary_total'] <=> $a['secondary_total'];
      if ($cmp !== 0) {
        return $cmp;
      }
      return $b['row_count'] <=> $a['row_count'];
    });

    return array_slice(array_values($genres), 0, max(1, min(20, $limit)));
  }

  public static function dashboard_snapshot(string $scope = 'last12', int $year = 0): array {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $today = new \DateTimeImmutable('now', $timezone);
    $filters = [];
    $label = 'All time';

    if ($scope === 'year' && $year > 0) {
      $filters = ['year' => $year];
      $label = (string) $year;
    } elseif ($scope === 'last12') {
      $filters = [
        'date_from' => $today->modify('first day of -11 months')->format('Y-m-d'),
        'date_to' => $today->format('Y-m-d'),
      ];
      $label = $filters['date_from'] . ' to ' . $filters['date_to'];
    }

    return [
      'movies' => self::entries_summary($filters),
      'live' => self::live_entries_summary($filters),
      'rentals' => self::rental_entries_summary($filters),
      'legacy' => self::legacy_weekly_summary($filters),
      'last_sync' => self::latest_log(['sync_tables', 'scheduled_sync'], true),
      'last_grosses_email' => self::latest_log(['send_report'], true),
      'last_advertiser_email' => self::latest_log(['send_advertiser_summary'], true),
      'recent_anomalies' => self::count_logs_by_type('anomaly', 30),
      'top_movies_by_gross' => self::top_movie_titles($filters, 5, 'gross'),
      'top_movies_by_concessions' => self::top_movie_titles($filters, 5, 'concessions'),
      'top_studios_by_gross' => self::top_movie_groups($filters, 'studio', 5, 'gross'),
      'top_studios_by_concessions' => self::top_movie_groups($filters, 'studio', 5, 'concessions'),
      'top_genres_by_gross' => self::top_movie_groups($filters, 'genre', 5, 'gross'),
      'top_genres_by_concessions' => self::top_movie_groups($filters, 'genre', 5, 'concessions'),
      'top_live_by_gross' => self::top_live_titles($filters, 5, 'gross'),
      'top_rentals_by_concessions' => self::top_rentals($filters, 5, 'concessions'),
      'studio_avg_gross' => self::top_movie_group_average($filters, 'studio', 'gross'),
      'studio_avg_concessions' => self::top_movie_group_average($filters, 'studio', 'concessions'),
      'genre_avg_gross' => self::top_movie_group_average($filters, 'genre', 'gross'),
      'genre_avg_concessions' => self::top_movie_group_average($filters, 'genre', 'concessions'),
      'top_window_label' => $label,
      'scope' => $scope,
      'year' => $year,
    ];
  }

  public static function concessions_by_date(string $date_from, string $date_to): array {
    global $wpdb;
    $totals = [];

    $movie_rows = $wpdb->get_results($wpdb->prepare(
      'SELECT report_date AS date_key, COALESCE(SUM(concessions_total),0) AS total
       FROM ' . self::entries_table_name() . '
       WHERE report_date BETWEEN %s AND %s
       GROUP BY report_date',
      $date_from,
      $date_to
    ), ARRAY_A);
    foreach ((array) $movie_rows as $row) {
      $totals[(string) $row['date_key']]['movies'] = round((float) ($row['total'] ?? 0), 2);
    }

    $live_rows = $wpdb->get_results($wpdb->prepare(
      'SELECT report_date AS date_key, COALESCE(SUM(concessions_total),0) AS total
       FROM ' . self::live_entries_table_name() . '
       WHERE report_date BETWEEN %s AND %s
       GROUP BY report_date',
      $date_from,
      $date_to
    ), ARRAY_A);
    foreach ((array) $live_rows as $row) {
      $totals[(string) $row['date_key']]['live'] = round((float) ($row['total'] ?? 0), 2);
    }

    $rental_rows = $wpdb->get_results($wpdb->prepare(
      'SELECT report_date AS date_key, COALESCE(SUM(concessions_total),0) AS total
       FROM ' . self::rental_entries_table_name() . '
       WHERE report_date BETWEEN %s AND %s
       GROUP BY report_date',
      $date_from,
      $date_to
    ), ARRAY_A);
    foreach ((array) $rental_rows as $row) {
      $totals[(string) $row['date_key']]['rentals'] = round((float) ($row['total'] ?? 0), 2);
    }

    foreach ($totals as $date_key => $row) {
      $movies = (float) ($row['movies'] ?? 0);
      $live = (float) ($row['live'] ?? 0);
      $rentals = (float) ($row['rentals'] ?? 0);
      $totals[$date_key] = [
        'movies' => $movies,
        'live' => $live,
        'rentals' => $rentals,
        'assigned_total' => round($movies + $live + $rentals, 2),
      ];
    }

    return $totals;
  }

  public static function list_import_batches(int $limit = 25): array {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . self::import_batch_table_name() . ' ORDER BY id DESC LIMIT %d',
      max(1, min(200, $limit))
    ), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function list_import_files(int $batch_id): array {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . self::import_file_table_name() . ' WHERE batch_id = %d ORDER BY id ASC',
      $batch_id
    ), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function reset_grosses_data(): void {
    global $wpdb;

    foreach ([
      self::entries_table_name(),
      self::history_table_name(),
      self::table_name(),
      self::import_file_table_name(),
      self::import_batch_table_name(),
    ] as $table_name) {
      $wpdb->query('TRUNCATE TABLE ' . $table_name);
    }

    delete_option('roxy_grosses_workbook_snapshots');
  }

  public static function backup_table_names(): array {
    return [
      self::entries_table_name(),
      self::live_entries_table_name(),
      self::rental_entries_table_name(),
      self::legacy_weekly_table_name(),
      self::log_table_name(),
      self::table_name(),
      self::history_table_name(),
      self::import_batch_table_name(),
      self::import_file_table_name(),
    ];
  }

  public static function unresolved_movie_metadata_count(): int {
    global $wpdb;
    return (int) $wpdb->get_var(
      'SELECT COUNT(*) FROM ' . self::entries_table_name() . " WHERE studio = '' OR studio IS NULL OR genre = '' OR genre IS NULL"
    );
  }

  public static function unresolved_movie_metadata_titles(int $limit = 100): array {
    global $wpdb;
    $limit = max(1, min(500, $limit));
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT
        movie_title,
        COUNT(*) AS row_count,
        MIN(report_date) AS first_date,
        MAX(report_date) AS last_date,
        MAX(CASE WHEN studio = '' OR studio IS NULL THEN 1 ELSE 0 END) AS missing_studio,
        MAX(CASE WHEN genre = '' OR genre IS NULL THEN 1 ELSE 0 END) AS missing_genre
      FROM " . self::entries_table_name() . "
      WHERE studio = '' OR studio IS NULL OR genre = '' OR genre IS NULL
      GROUP BY movie_title
      ORDER BY last_date DESC, movie_title ASC
      LIMIT %d",
      $limit
    ), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  private static function prepare_query(string $sql, array $params): string {
    global $wpdb;
    return $params ? $wpdb->prepare($sql, ...$params) : $sql;
  }
}
