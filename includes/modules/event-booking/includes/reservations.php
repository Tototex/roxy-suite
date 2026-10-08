<?php
if (!defined('ABSPATH')) exit;

/** One room, one connection-owned lock. No payment/provider work in this transaction. */
final class RoxyEBReservation {
    private string $key;
    private int $owner = 0;
    private array $transactional_tables = [];
    private bool $transaction_active = false;
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
    public function include_transactional_tables(array $tables): void {
        global $wpdb;
        foreach (array_filter($tables, 'is_string') as $table) {
            if ($wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table)) !== 'InnoDB') throw new RuntimeException('Transactional reservation storage is unavailable.');
            $this->transactional_tables[] = $table;
        }
        $this->transactional_tables = array_values(array_unique($this->transactional_tables));
    }
    public function run(callable $callback, array $additional_transactional_tables = []) {
        global $wpdb;
        $this->owner = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
        $started = false;
        try {
            if (!$this->owner || (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $this->key)) !== '1') return new WP_Error('reservation_busy', 'Another reservation change is in progress. Please try again.');
            $this->assert_owner();
            $tables = array_values(array_unique(array_merge(
                [roxy_eb_table_bookings(), roxy_eb_table_blocks()],
                array_filter($additional_transactional_tables, 'is_string')
            )));
            foreach ($tables as $table) {
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
            $this->transactional_tables = $tables;
            $this->transaction_active = true;
            add_filter('query', [$this, 'guard_transaction_query'], PHP_INT_MAX);
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
            if ($this->transaction_active) {
                remove_filter('query', [$this, 'guard_transaction_query'], PHP_INT_MAX);
                $this->transaction_active = false;
                $this->transactional_tables = [];
            }
            if ($this->owner && (int) $wpdb->get_var('SELECT CONNECTION_ID()') === $this->owner) {
                if ($started) $wpdb->query('ROLLBACK');
                if ((int) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $this->key)) === $this->owner) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->key));
            }
        }
    }
    /** Protect WordPress post/meta writes from wpdb reconnect retries escaping this transaction. */
    public function guard_transaction_query(string $sql): string {
        if (!$this->transaction_active || !preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql)) return $sql;
        $pattern = implode('|', array_map(static fn($table) => preg_quote($table, '/'), $this->transactional_tables));
        if (!$pattern || !preg_match('/^\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?(?:' . $pattern . ')`?(?=[\s(])/i', $sql)) return $sql;
        $this->assert_owner();
        $sql = rtrim(trim($sql), ';');
        $predicate = $this->predicate();
        if (preg_match('/^(INSERT\s+INTO\s+`?(?:' . $pattern . ')`?\s*\([^)]*\))\s+VALUES\s*\(([\s\S]*)\)$/i', $sql, $match)) {
            return $match[1] . ' SELECT ' . $match[2] . ' FROM DUAL WHERE ' . $predicate;
        }
        if (preg_match('/^(?:UPDATE|DELETE)\b/i', $sql)) {
            $where = $this->where_offset($sql);
            if ($where !== null) return substr($sql, 0, $where + 5) . ' (' . substr($sql, $where + 5) . ') AND (' . $predicate . ')';
        }
        throw new RuntimeException('Unsupported showing write inside a protected room change. Nothing further was written.');
    }
    private function where_offset(string $sql): ?int {
        $quote = null;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== null) {
                if ($char === '\\') { ++$i; continue; }
                if ($char === $quote) { if ($i + 1 < $length && $sql[$i + 1] === $quote) ++$i; else $quote = null; }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; continue; }
            if (($i === 0 || ctype_space($sql[$i - 1])) && strncasecmp(substr($sql, $i, 5), 'WHERE', 5) === 0 && ($i + 5 === $length || ctype_space($sql[$i + 5]) || $sql[$i + 5] === '(')) return $i;
        }
        return null;
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

function roxy_eb_reservation_run(callable $callback, array $additional_transactional_tables = []) {
    static $active = null;
    if ($active) {
        $active->assert_owner();
        try { $active->include_transactional_tables($additional_transactional_tables); }
        catch (Throwable $error) { return new WP_Error('reservation_storage', $error->getMessage()); }
        return $callback($active);
    }
    $guard = new RoxyEBReservation();
    return $guard->run(static function ($guard) use ($callback, &$active) {
        $active = $guard;
        try { return $callback($guard); }
        finally { $active = null; }
    }, $additional_transactional_tables);
}

/** Serialize a managed showing-time write with event bookings and manual room blocks. */
function roxy_eb_with_showing_time_lock(string $show_start, int $ignore_showing_id, callable $write, bool $uses_terms = false) {
    global $wpdb;
    $start = null;
    foreach (['!Y-m-d\\TH:i', '!Y-m-d\\TH:i:s', '!Y-m-d H:i:s'] as $format) {
        $candidate = DateTimeImmutable::createFromFormat($format, $show_start, wp_timezone());
        $errors = DateTimeImmutable::getLastErrors();
        if ($candidate && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))) {
            $expected = str_replace('!', '', $format);
            if ($candidate->format($expected) === $show_start) { $start = $candidate; break; }
        }
    }
    if (!$start) return new WP_Error('reservation_time', 'Showing date and time are invalid. The showing was not moved or published.');

    $tables = [$wpdb->posts, $wpdb->postmeta];
    if ($uses_terms) $tables = array_merge($tables, [$wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships]);
    return roxy_eb_reservation_run(static function ($guard) use ($start, $ignore_showing_id, $write) {
        $reserved_start = $start->modify('-2 hours');
        $reserved_end = $start->modify('+2 hours');
        if (!roxy_eb_is_slot_available($reserved_start, $reserved_end, 0, true, $ignore_showing_id)) {
            return new WP_Error('reservation_conflict', 'That showing time overlaps another room reservation or showing. The change was not saved.');
        }
        return $write($guard);
    }, $tables);
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
