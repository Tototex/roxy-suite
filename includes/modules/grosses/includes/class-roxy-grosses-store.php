<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Store {
  public const TABLE = 'roxy_grosses_reports';
  public const LOG_TABLE = 'roxy_grosses_logs';
  public const HISTORY_TABLE = 'roxy_grosses_history';
  public const SCHEMA_OPTION = 'roxy_grosses_schema_version';
  public const HISTORY_BACKFILL_OPTION = 'roxy_grosses_history_backfilled';

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

  public static function install_schema(): void {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = self::table_name();
    $log_table = self::log_table_name();
    $history_table = self::history_table_name();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
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
    ) {$charset};";

    $sql_logs = "CREATE TABLE {$log_table} (
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
    ) {$charset};";

    $sql_history = "CREATE TABLE {$history_table} (
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
    ) {$charset};";

    dbDelta($sql);
    dbDelta($sql_logs);
    dbDelta($sql_history);
    update_option(self::SCHEMA_OPTION, ROXY_GROSSES_VER);
  }

  public static function maybe_upgrade_schema(): void {
    if (get_option(self::SCHEMA_OPTION) !== ROXY_GROSSES_VER) {
      self::install_schema();
    }
  }

  public static function maybe_backfill_history(): void {
    if (get_option(self::HISTORY_BACKFILL_OPTION) === ROXY_GROSSES_VER) {
      return;
    }

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

    update_option(self::HISTORY_BACKFILL_OPTION, ROXY_GROSSES_VER);
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
      'payload_json' => wp_json_encode([
        'summary' => $summary,
        'rows' => array_values($rows),
      ]),
    ]);

    return $ok ? (int) $wpdb->insert_id : 0;
  }

  public static function mark_emailed(int $report_id): bool {
    global $wpdb;

    $ok = $wpdb->update(self::table_name(), [
      'updated_at' => current_time('mysql'),
      'status' => 'emailed',
      'emailed_at' => current_time('mysql'),
    ], [
      'id' => $report_id,
    ]);

    return $ok !== false;
  }

  public static function get_report(int $report_id): ?array {
    global $wpdb;

    $row = $wpdb->get_row($wpdb->prepare(
      'SELECT * FROM ' . self::table_name() . ' WHERE id = %d',
      $report_id
    ), ARRAY_A);

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
       FROM ' . self::table_name() . '
       ORDER BY report_end_date DESC, id DESC
       LIMIT %d',
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
       FROM ' . self::log_table_name() . '
       ORDER BY id DESC
       LIMIT %d',
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

      $ok = $wpdb->replace(self::history_table_name(), [
        'created_at' => $now,
        'updated_at' => $now,
        'report_date' => $report_date,
        'showing_id' => max(0, (int) ($row['showing_id'] ?? 0)),
        'show_time' => sanitize_text_field((string) ($row['show_time'] ?? '')),
        'theater_name' => sanitize_text_field((string) ($row['theater_name'] ?? '')),
        'film_title' => sanitize_text_field((string) ($row['film_title'] ?? '')),
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
}
