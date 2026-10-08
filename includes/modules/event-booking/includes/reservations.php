<?php
if (!defined('ABSPATH')) exit;

/** One room, one connection-owned lock. No payment/provider work in this transaction. */
final class RoxyEBReservation {
    private string $key;
    private int $owner = 0;
    public function __construct() {
        global $wpdb;
        $this->key = 'roxy_room_' . substr(hash('sha256', $wpdb->prefix), 0, 48);
    }
    public function predicate(): string {
        global $wpdb;
        return $wpdb->prepare('(CONNECTION_ID()=%d AND IS_USED_LOCK(%s)=%d)', $this->owner, $this->key, $this->owner);
    }
    public function assert_owner(): void {
        global $wpdb;
        if (!$this->owner || (string) $wpdb->get_var('SELECT IF(' . $this->predicate() . ',1,0)') !== '1') throw new RuntimeException('Reservation ownership lost. Please retry after reviewing the booking.');
    }
    public function run(callable $callback) {
        global $wpdb;
        $this->owner = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
        $started = false;
        try {
            if (!$this->owner || (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $this->key)) !== '1') return new WP_Error('reservation_busy', 'Another reservation change is in progress. Please try again.');
            $this->assert_owner();
            foreach ([roxy_eb_table_bookings(), roxy_eb_table_blocks()] as $table) {
                if ($wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table)) !== 'InnoDB') throw new RuntimeException('Transactional reservation storage is unavailable.');
            }
            if ((string) $wpdb->get_var('SELECT @@SESSION.autocommit') !== '1') throw new RuntimeException('Another database transaction is active.');
            $probe = 'roxy_room_probe_' . bin2hex(random_bytes(8));
            if ($wpdb->query('SAVEPOINT ' . $probe) === false) throw new RuntimeException('Cannot verify transaction ownership.');
            $previous = $wpdb->suppress_errors(true);
            try { $outer = $wpdb->query('RELEASE SAVEPOINT ' . $probe) !== false; }
            finally { $wpdb->suppress_errors($previous); }
            if ($outer) throw new RuntimeException('Another database transaction is active.');
            $this->assert_owner();
            if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('Cannot begin reservation change.');
            $started = true;
            $this->assert_owner();
            $result = $callback($this);
            if (is_wp_error($result)) return $result;
            $this->assert_owner();
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('Cannot commit reservation change.');
            $this->assert_owner();
            $started = false;
            return $result;
        } catch (Throwable $error) {
            return new WP_Error('reservation_storage', $error->getMessage());
        } finally {
            if ($this->owner && (int) $wpdb->get_var('SELECT CONNECTION_ID()') === $this->owner) {
                if ($started) $wpdb->query('ROLLBACK');
                if ((int) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $this->key)) === $this->owner) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->key));
            }
        }
    }
    public function row(string $sql): ?array {
        global $wpdb;
        $this->assert_owner();
        $row = $wpdb->get_row($sql, ARRAY_A);
        if ($wpdb->last_error) throw new RuntimeException('Cannot read reservation storage.');
        $this->assert_owner();
        return $row ?: null;
    }
    private function identifier(string $value): string {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $value)) throw new RuntimeException('Invalid reservation field.');
        return '`' . $value . '`';
    }
    private function value($value): string {
        global $wpdb;
        if ($value === null) return 'NULL';
        if (!is_scalar($value)) throw new RuntimeException('Invalid reservation value.');
        return $wpdb->prepare('%s', (string) $value);
    }
    public function insert(string $table, array $row): int {
        global $wpdb;
        $this->assert_owner();
        $columns = array_map([$this, 'identifier'], array_keys($row));
        $values = array_map([$this, 'value'], array_values($row));
        $result = $wpdb->query('INSERT INTO ' . $this->identifier($table) . ' (' . implode(',', $columns) . ') SELECT ' . implode(',', $values) . ' FROM DUAL WHERE ' . $this->predicate());
        $id = (int) $wpdb->insert_id;
        if ($result !== 1 || $id <= 0) throw new RuntimeException('Reservation insert could not be verified.');
        $this->assert_owner();
        return $id;
    }
    public function update(string $table, int $id, array $data): void {
        global $wpdb;
        if (!$this->row($wpdb->prepare('SELECT * FROM ' . $this->identifier($table) . ' WHERE id=%d FOR UPDATE', $id))) throw new RuntimeException('Reservation no longer exists.');
        $sets = [];
        foreach ($data as $field => $value) $sets[] = $this->identifier($field) . '=' . $this->value($value);
        if ($wpdb->query('UPDATE ' . $this->identifier($table) . ' SET ' . implode(',', $sets) . $wpdb->prepare(' WHERE id=%d AND ', $id) . $this->predicate()) === false) throw new RuntimeException('Reservation update failed.');
        $after = $this->row($wpdb->prepare('SELECT * FROM ' . $this->identifier($table) . ' WHERE id=%d', $id));
        foreach ($data as $field => $value) {
            $actual = $after[$field] ?? null;
            if ($value === null ? $actual !== null : ($actual === null || (is_numeric($value) && is_numeric($actual) ? (float) $value !== (float) $actual : (string) $value !== (string) $actual))) throw new RuntimeException('Reservation update could not be verified.');
        }
    }
    public function delete(string $table, int $id): void {
        global $wpdb;
        $this->assert_owner();
        if ($wpdb->query('DELETE FROM ' . $this->identifier($table) . $wpdb->prepare(' WHERE id=%d AND ', $id) . $this->predicate()) !== 1) throw new RuntimeException('Reservation deletion could not be verified.');
        $this->assert_owner();
    }
}

function roxy_eb_reservation_run(callable $callback) {
    static $active = null;
    if ($active) { $active->assert_owner(); return $callback($active); }
    $guard = new RoxyEBReservation();
    return $guard->run(static function ($guard) use ($callback, &$active) {
        $active = $guard;
        try { return $callback($guard); }
        finally { $active = null; }
    });
}

function roxy_eb_reservation_validate_window(array $row, int $ignore_id = 0) {
    if (!in_array($row['status'] ?? '', ['confirmed', 'pending', 'pending_invoice'], true)) return true;
    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) ($row['reserved_start_at'] ?? ''), wp_timezone());
    $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) ($row['reserved_end_at'] ?? ''), wp_timezone());
    if (!$start || !$end || $end <= $start || $start->format('Y-m-d H:i:s') !== $row['reserved_start_at'] || $end->format('Y-m-d H:i:s') !== $row['reserved_end_at']) return new WP_Error('reservation_time', 'Invalid reservation window.');
    if (!roxy_eb_is_slot_available($start, $end, $ignore_id, true)) return new WP_Error('reservation_conflict', 'That time is no longer available. Please choose another slot.');
    return true;
}

function roxy_eb_reservation_validate_block(array $row) {
    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) ($row['start_at'] ?? ''), wp_timezone());
    $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) ($row['end_at'] ?? ''), wp_timezone());
    if (!$start || !$end || $end <= $start || $start->format('Y-m-d H:i:s') !== $row['start_at'] || $end->format('Y-m-d H:i:s') !== $row['end_at']) return new WP_Error('reservation_time', 'Invalid blocked-event window.');
    $bookings = roxy_eb_repo_list_bookings_in_range($row['start_at'], $row['end_at']);
    if (is_wp_error($bookings)) return $bookings;
    if ($bookings) return new WP_Error('reservation_conflict', 'This block overlaps an existing reservation. Please review that booking first.');
    return true;
}
