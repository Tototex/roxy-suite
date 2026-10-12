<?php
// Isolated transaction/ownership fixture for concession allocation; no WordPress DB or live reports.
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');
function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}
final class AllocationDatabase {
    public string $prefix = 'fixture_';
    public string $last_error = '';
    public int $connection = 17;
    public ?int $lock_owner = null;
    public array $lock_owners = [];
    public ?string $blocked_lock_name = null;
    public bool $transaction = false;
    public string $engine = 'InnoDB';
    public bool $lock_busy = false;
    public int $value = 0;
    private int $before = 0;
    private ?int $savepoint_before = null;
    private bool $savepoint_active = false;
    private bool $suppress = false;
    public array $queries = [];
    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) $query = preg_replace('/%[sd]/', is_int($arg) ? (string)$arg : "'" . addslashes((string)$arg) . "'", $query, 1);
        return $query;
    }
    public function get_var(string $query) {
        $this->last_error = '';
        if ($query === 'SELECT CONNECTION_ID()') return $this->connection;
        if (str_starts_with($query, 'SELECT GET_LOCK(')) {
            preg_match("/GET_LOCK\\('([^']+)'/", $query, $match);
            $name = (string) ($match[1] ?? '');
            if ($this->lock_busy || $name === '' || $name === $this->blocked_lock_name
                || (isset($this->lock_owners[$name]) && $this->lock_owners[$name] !== $this->connection)) return 0;
            $this->lock_owners[$name] = $this->connection;
            $this->lock_owner = $this->connection;
            return 1;
        }
        if (str_starts_with($query, 'SELECT IS_USED_LOCK(')) {
            if ($this->lock_owner === null) return null;
            preg_match("/IS_USED_LOCK\\('([^']+)'/", $query, $match);
            return $this->lock_owners[$match[1] ?? ''] ?? $this->lock_owner;
        }
        if ($query === 'SELECT 1') return 1;
        if (str_starts_with($query, 'SELECT RELEASE_LOCK(')) {
            preg_match("/RELEASE_LOCK\\('([^']+)'/", $query, $match);
            $name = (string) ($match[1] ?? '');
            if (($this->lock_owners[$name] ?? null) !== $this->connection) return 0;
            unset($this->lock_owners[$name]);
            $this->lock_owner = $this->lock_owners ? $this->connection : null;
            return 1;
        }
        if ($query === 'SELECT @@in_transaction') return $this->transaction ? 1 : 0;
        throw new RuntimeException('Unexpected fixture read: ' . $query);
    }
    public function get_row(string $query, $format = null): array {
        $this->last_error = '';
        if (str_starts_with($query, 'SHOW TABLE STATUS WHERE Name = ')) return ['Engine' => $this->engine];
        if ($query === "SHOW SESSION STATUS LIKE 'In_transaction'") return ['Variable_name' => 'In_transaction', 'Value' => $this->transaction ? '1' : '0'];
        throw new RuntimeException('Unexpected table status query.');
    }
    public function query(string $query) {
        $this->queries[] = $query;
        $this->last_error = '';
        if (str_starts_with($query, 'SAVEPOINT `')) {
            $this->savepoint_active = $this->transaction;
            $this->savepoint_before = $this->value;
            return 0;
        }
        if (str_starts_with($query, 'ROLLBACK TO SAVEPOINT `')) {
            if (!$this->transaction || !$this->savepoint_active) {
                $this->last_error = 'SAVEPOINT does not exist';
                return false;
            }
            $this->value = (int) $this->savepoint_before;
            return 0;
        }
        if (str_starts_with($query, 'RELEASE SAVEPOINT `')) {
            if (!$this->transaction || !$this->savepoint_active) return false;
            $this->savepoint_active = false;
            return 0;
        }
        if ($query === 'START TRANSACTION') {
            if ($this->transaction) return false;
            $this->before = $this->value; $this->transaction = true; return 0;
        }
        if ($query === 'COMMIT') {
            if (!$this->transaction) return false;
            $this->transaction = false; return 0;
        }
        if ($query === 'ROLLBACK') {
            if (!$this->transaction) return false;
            $this->value = $this->before; $this->transaction = false; return 0;
        }
        throw new RuntimeException('Unexpected fixture write: ' . $query);
    }
    public function suppress_errors(bool $suppress = true): bool { $previous = $this->suppress; $this->suppress = $suppress; return $previous; }
}
$GLOBALS['wpdb'] = new AllocationDatabase();
require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';
$store = RoxyGrosses\Store::class;
$db = $GLOBALS['wpdb'];
$run = static fn(callable $operation) => $store::with_concession_allocation_lock('2038-05-01', $operation);

$result = $run(static fn() => $store::with_concession_allocation_transaction(static function () use ($db) { $db->value = 12; return 'committed'; }));
check($result === 'committed' && $db->value === 12 && !$db->transaction && $db->lock_owner === null, 'successful allocation commits and releases its date lock');

$failed = false;
try {
    $run(static fn() => $store::with_concession_allocation_transaction(static function () use ($db) { $db->value = 99; throw new RuntimeException('fixture save failure'); }));
} catch (RuntimeException $error) { $failed = $error->getMessage() === 'fixture save failure'; }
check($failed && $db->value === 12 && !$db->transaction && $db->lock_owner === null, 'later row failure rolls back earlier allocation updates and releases the lock');

$db->engine = 'MyISAM'; $called = false;
try { $run(static fn() => $store::with_concession_allocation_transaction(static function () use (&$called) { $called = true; })); }
catch (RuntimeException $error) { $called = !$called && str_contains($error->getMessage(), 'verified InnoDB'); }
check($called && $db->value === 12 && !$db->transaction, 'nontransactional report tables fail before writes');
$db->engine = 'InnoDB';

$db->lock_busy = true; $called = false;
try { $run(static function () use (&$called) { $called = true; }); }
catch (RuntimeException $error) { $called = !$called && str_contains($error->getMessage(), 'already running'); }
check($called && $db->value === 12, 'competing date allocation fails before reads or writes');
$db->lock_busy = false;

$db->transaction = true; $called = false;
try { $run(static fn() => $store::with_concession_allocation_transaction(static function () use (&$called) { $called = true; })); }
catch (RuntimeException $error) { $called = !$called && str_contains($error->getMessage(), 'already active'); }
check($called && $db->transaction && $db->value === 12, 'existing outer transaction is preserved and allocation writes are refused');
$db->transaction = false;

$lost_lock = false;
try {
    $run(static fn() => $store::with_concession_allocation_transaction(static function () use ($db) { $db->value = 55; $db->lock_owner = null; }));
} catch (RuntimeException $error) { $lost_lock = str_contains($error->getMessage(), 'lost its database owner'); }
check($lost_lock && $db->value === 12 && !$db->transaction, 'lost lock during allocation rolls back its transaction');

$connection_lost = false;
try {
    $run(static fn() => $store::with_concession_allocation_transaction(static function () use ($db) { $db->value = 33; $db->connection = 18; }));
} catch (RuntimeException $error) { $connection_lost = str_contains($error->getMessage(), 'lost its database owner'); }
check($connection_lost && $db->connection === 18 && $db->transaction && $db->value === 33, 'reconnected session is never used to roll back another connection transaction');
$db->connection = 17;
$db->lock_owner = null;
$db->transaction = false;
$db->value = 12;
$replacement_db = new AllocationDatabase();
$replacement_detected = false;
try {
    $run(static fn() => $store::with_concession_allocation_transaction(static function () use ($db, $replacement_db) {
        $db->value = 77;
        $GLOBALS['wpdb'] = $replacement_db;
    }));
} catch (RuntimeException $error) { $replacement_detected = str_contains($error->getMessage(), 'lost its database owner'); }
check($replacement_detected && $db->value === 12 && !$db->transaction && $db->lock_owner === null
    && $replacement_db->queries === [], 'replaced global database rolls back and releases through the original owner without using the replacement');
$GLOBALS['wpdb'] = $db;

$allocation_key = static fn(string $date): string => 'roxy_grosses_alloc_' . substr(hash('sha256', $store::entries_table_name() . '|' . $date), 0, 24);
$edit_dates = ['2038-05-03', '2038-05-02', '2038-05-03'];
$edit_result = $store::with_report_edit_allocation_locks($edit_dates, static function () use ($db, $allocation_key): string {
    foreach (['2038-05-02', '2038-05-03'] as $date) {
        $owner = (int) $db->get_var($db->prepare('SELECT IS_USED_LOCK(%s)', $allocation_key($date)));
        if ($owner !== $db->connection) throw new RuntimeException('Both report dates must remain locked during the edit.');
    }
    return 'edited';
});
check($edit_result === 'edited' && $db->lock_owner === null
    && !isset($db->lock_owners[$allocation_key('2038-05-02')])
    && !isset($db->lock_owners[$allocation_key('2038-05-03')]),
    'manual edit owns deduplicated old/new report-date locks together and releases both');

$db->blocked_lock_name = $allocation_key('2038-05-04');
$partial_edit_failed = false;
try { $store::with_report_edit_allocation_locks(['2038-05-04', '2038-05-03'], static fn() => throw new RuntimeException('blocked edit must not run')); }
catch (RuntimeException $error) { $partial_edit_failed = str_contains($error->getMessage(), 'already running'); }
$db->blocked_lock_name = null;
check($partial_edit_failed && $db->lock_owner === null && !isset($db->lock_owners[$allocation_key('2038-05-03')]),
    'failed second-date acquisition releases the earlier lock and never runs the edit');

$invalid_edit_failed = false;
try { $store::with_report_edit_allocation_locks(['2038-02-30'], static fn() => null); }
catch (InvalidArgumentException $error) { $invalid_edit_failed = str_contains($error->getMessage(), 'calendar date'); }
check($invalid_edit_failed && $db->lock_owner === null, 'invalid calendar dates are rejected before a manual-edit lock is taken');

$nested_edit_failed = false;
try { $run(static fn() => $store::with_report_edit_allocation_locks(['2038-05-01'], static fn() => null)); }
catch (RuntimeException $error) { $nested_edit_failed = str_contains($error->getMessage(), 'already active'); }
check($nested_edit_failed && $db->lock_owner === null, 'manual row edits cannot nest inside an active concessions allocation');

echo "OK: Grosses allocation transaction ownership and rollback regression\n";
