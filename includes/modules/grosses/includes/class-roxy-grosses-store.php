<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Store {
  public const TABLE = 'roxy_grosses_reports';
  public const LOG_TABLE = 'roxy_grosses_logs';
  public const SCHEMA_OPTION = 'roxy_grosses_schema_version';

  public static function table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::TABLE;
  }

  public static function install_schema(): void {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = self::table_name();
    $log_table = self::log_table_name();
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

    dbDelta($sql);
    dbDelta($sql_logs);
    update_option(self::SCHEMA_OPTION, ROXY_GROSSES_VER);
  }

  public static function log_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::LOG_TABLE;
  }

  public static function maybe_upgrade_schema(): void {
    if (get_option(self::SCHEMA_OPTION) !== ROXY_GROSSES_VER) {
      self::install_schema();
    }
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
}
