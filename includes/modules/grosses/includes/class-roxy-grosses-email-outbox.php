<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

/**
 * Durable, single-attempt storage for Grosses email sends.
 *
 * A claimed row must be persisted before the caller invokes wp_mail(). A row
 * left in `sending` is intentionally blocking: this class never retries it.
 */
final class EmailOutbox {
  public const TABLE = 'roxy_grosses_email_outbox';
  private const SCHEMA_OPTION = 'roxy_grosses_email_outbox_schema_version';
  private const SCHEMA_VERSION = '1';
  private const MAX_KEY_BYTES = 512;
  private const MAX_JSON_BYTES = 262144;
  private const MAX_ERROR_BYTES = 2000;
  private const MAX_LIST_LIMIT = 100;

  private static function table_name(): string {
    global $wpdb;
    if (!is_object($wpdb) || !isset($wpdb->prefix) || !is_string($wpdb->prefix)
      || !preg_match('/^[A-Za-z0-9_]*$/', $wpdb->prefix)) {
      throw new \RuntimeException('Grosses email outbox database prefix is unavailable or invalid.');
    }
    $table = $wpdb->prefix . self::TABLE;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new \RuntimeException('Grosses email outbox table name is invalid.');
    return '`' . $table . '`';
  }

  private static function storage_error(string $operation): \RuntimeException {
    global $wpdb;
    $detail = is_object($wpdb) && isset($wpdb->last_error) ? trim((string) $wpdb->last_error) : '';
    return new \RuntimeException('Grosses email outbox ' . $operation . ' failed' . ($detail !== '' ? ': ' . $detail : '.') );
  }

  private static function assert_query_ok($result, string $operation): void {
    global $wpdb;
    if ($result === false || (is_object($wpdb) && !empty($wpdb->last_error))) throw self::storage_error($operation);
  }

  private static function key_hash(string $key): string {
    if ($key === '' || strlen($key) > self::MAX_KEY_BYTES || preg_match('//u', $key) !== 1) {
      throw new \InvalidArgumentException('Logical email key must be valid UTF-8 and between 1 and ' . self::MAX_KEY_BYTES . ' bytes.');
    }
    return hash('sha256', $key);
  }

  private static function encode_json(array $value, string $field): string {
    try {
      $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR, 32);
    } catch (\JsonException $error) {
      throw new \InvalidArgumentException('Grosses email outbox ' . $field . ' must be JSON-encodable.', 0, $error);
    }
    if (strlen($json) > self::MAX_JSON_BYTES) {
      throw new \InvalidArgumentException('Grosses email outbox ' . $field . ' exceeds the ' . self::MAX_JSON_BYTES . '-byte limit.');
    }
    return $json;
  }

  private static function valid_date(?string $date): ?string {
    if ($date === null) return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new \InvalidArgumentException('Report date must be null or a valid YYYY-MM-DD date.');
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
    $errors = \DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $parsed->format('Y-m-d') !== $date) {
      throw new \InvalidArgumentException('Report date must be a real calendar date in YYYY-MM-DD format.');
    }
    return $date;
  }

  /** Create and verify the required InnoDB table. Safe to call repeatedly. */
  public static function ensure_schema(bool $force_verify = false): bool {
    global $wpdb;
    try {
      if (!$force_verify && function_exists('get_option')
        && (string) get_option(self::SCHEMA_OPTION, '') === self::SCHEMA_VERSION) return true;
      $table = self::table_name();
      if (!is_object($wpdb) || !method_exists($wpdb, 'get_charset_collate') || !method_exists($wpdb, 'query')) return false;
      $charset = trim((string) $wpdb->get_charset_collate());
      $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        kind VARCHAR(40) NOT NULL,
        source_id BIGINT UNSIGNED NOT NULL,
        report_date DATE NULL,
        status VARCHAR(16) NOT NULL,
        payload_json LONGTEXT NOT NULL,
        context_json LONGTEXT NOT NULL,
        error_text TEXT NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY logical_send (key_hash),
        KEY status_created (status, created_at),
        KEY source_id (source_id)
      ) ENGINE=InnoDB {$charset}";
      self::assert_query_ok($wpdb->query($sql), 'schema creation');

      $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like(trim($table, '`'))), ARRAY_A);
      if ($status === null || $status === false || !empty($wpdb->last_error)) throw self::storage_error('schema verification');
      if (strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0) return false;
      $indexes = $wpdb->get_results('SHOW INDEX FROM ' . $table, ARRAY_A);
      if (!is_array($indexes) || !empty($wpdb->last_error)) throw self::storage_error('index verification');
      $columns = $wpdb->get_results('SHOW COLUMNS FROM ' . $table, ARRAY_A);
      if (!is_array($columns) || !empty($wpdb->last_error)) throw self::storage_error('column verification');
      $unique_key = false;
      foreach ($indexes as $index) {
        if (($index['Key_name'] ?? '') === 'logical_send' && (int) ($index['Non_unique'] ?? 1) === 0
          && ($index['Column_name'] ?? '') === 'key_hash') $unique_key = true;
      }
      $column_names = array_column($columns, 'Field');
      $required_columns = ['id', 'key_hash', 'created_at', 'updated_at', 'kind', 'source_id', 'report_date', 'status', 'payload_json', 'context_json', 'error_text'];
      $valid = $unique_key && count(array_diff($required_columns, $column_names)) === 0;
      if ($valid && !$force_verify && function_exists('update_option')) update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
      return $valid;
    } catch (\Throwable $error) {
      return false;
    }
  }

  /**
   * Atomically reserve one logical send. The caller must invoke wp_mail only
   * after this method returns claimed=true. Duplicate keys never mutate rows.
   */
  public static function claim(string $key, string $kind, int $source_id, ?string $report_date, array $payload, array $context): array {
    global $wpdb;
    $key_hash = self::key_hash($key);
    if (!preg_match('/^[A-Za-z0-9_.:-]{1,40}$/', $kind)) throw new \InvalidArgumentException('Email kind must be 1–40 safe identifier characters.');
    if ($source_id < 0) throw new \InvalidArgumentException('Email source ID must be a non-negative integer.');
    $report_date = self::valid_date($report_date);
    $payload_json = self::encode_json($payload, 'payload');
    $context_json = self::encode_json($context, 'context');
    $table = self::table_name();
    $now = gmdate('Y-m-d H:i:s');
    $date_sql = $report_date === null ? 'NULL' : '%s';
    $query = $wpdb->prepare(
      "INSERT INTO {$table} (key_hash, created_at, updated_at, kind, source_id, report_date, status, payload_json, context_json, error_text)
       VALUES (%s, %s, %s, %s, %d, {$date_sql}, 'sending', %s, %s, '')
       ON DUPLICATE KEY UPDATE id = id",
      ...array_merge([$key_hash, $now, $now, $kind, $source_id], $report_date === null ? [] : [$report_date], [$payload_json, $context_json])
    );
    $result = $wpdb->query($query);
    self::assert_query_ok($result, 'claim');
    if ((int) $wpdb->rows_affected === 1) {
      $id = (int) $wpdb->insert_id;
      if ($id < 1) throw new \RuntimeException('Grosses email outbox claim was written without a usable row ID; the key remains blocked.');
      return ['claimed' => true, 'id' => $id, 'status' => 'sending'];
    }
    if ((int) $wpdb->rows_affected === 0) {
      $existing = self::find_by_hash($key_hash);
      if ($existing === null) throw new \RuntimeException('Grosses email outbox duplicate claim could not be verified; refusing to send.');
      return ['claimed' => false, 'id' => (int) $existing['id'], 'status' => (string) $existing['status']];
    }
    throw new \RuntimeException('Grosses email outbox claim returned an unexpected affected-row count; refusing to send.');
  }

  /**
   * One-way finish. `accepted` means wp_mail accepted the message for handling;
   * it is not proof that the recipient received it. A failed write leaves
   * `sending` blocking and returns false.
   */
  public static function finish(int $id, string $status, string $error = ''): bool {
    global $wpdb;
    if ($id < 1 || !in_array($status, ['accepted', 'uncertain'], true) || strlen($error) > self::MAX_ERROR_BYTES || preg_match('//u', $error) !== 1) return false;
    $table = self::table_name();
    $now = gmdate('Y-m-d H:i:s');
    $query = $wpdb->prepare(
      "UPDATE {$table} SET status = %s, updated_at = %s, error_text = %s WHERE id = %d AND status = 'sending'",
      $status, $now, $error, $id
    );
    $result = $wpdb->query($query);
    if ($result === false || !empty($wpdb->last_error)) return false;
    return (int) $wpdb->rows_affected === 1;
  }

  /** Read a row by the original logical key; returns null only when absent. */
  public static function find(string $key): ?array {
    return self::find_by_hash(self::key_hash($key));
  }

  private static function find_by_hash(string $key_hash): ?array {
    global $wpdb;
    $table = self::table_name();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE key_hash = %s LIMIT 1", $key_hash), ARRAY_A);
    if ($row === null && !empty($wpdb->last_error)) throw self::storage_error('lookup');
    if ($row === false) throw self::storage_error('lookup');
    return is_array($row) ? $row : null;
  }

  /** Read recent rows only; limit is intentionally capped. */
  public static function recent(int $limit = 50): array {
    global $wpdb;
    if ($limit < 1 || $limit > self::MAX_LIST_LIMIT) throw new \InvalidArgumentException('Outbox list limit must be between 1 and ' . self::MAX_LIST_LIMIT . '.');
    $table = self::table_name();
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
    if (!is_array($rows) || !empty($wpdb->last_error)) throw self::storage_error('list');
    return $rows;
  }
}
