<?php
// Isolated transaction/ownership fixture for concession allocation; no WordPress DB or live reports.
define('ABSPATH', __DIR__);
function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}
final class AllocationDatabase {
    public string $prefix = 'fixture_';
    public string $last_error = '';
    public int $connection = 17;
    public ?int $lock_owner = null;
    public bool $transaction = false;
    public string $engine = 'InnoDB';
    public bool $lock_busy = false;
    public int $value = 0;
    private int $before = 0;
    public array $queries = [];
    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) $query = preg_replace('/%[sd]/', is_int($arg) ? (string)$arg : "'" . addslashes((string)$arg) . "'", $query, 1);
        return $query;
    }
    public function get_var(string $query) {
        $this->last_error = '';
        if ($query === 'SELECT CONNECTION_ID()') return $this->connection;
        if (str_starts_with($query, 'SELECT GET_LOCK(')) {
            if ($this->lock_busy || $this->lock_owner !== null) return 0;
            $this->lock_owner = $this->connection; return 1;
        }
        if (str_starts_with($query, 'SELECT IS_USED_LOCK(')) return $this->lock_owner;
        if (str_starts_with($query, 'SELECT RELEASE_LOCK(')) {
            if ($this->lock_owner !== $this->connection) return 0;
            $this->lock_owner = null; return 1;
        }
        if ($query === 'SELECT @@in_transaction') return $this->transaction ? 1 : 0;
        throw new RuntimeException('Unexpected fixture read: ' . $query);
    }
    public function get_row(string $query, $format = null): array {
        $this->last_error = '';
        if (!str_starts_with($query, 'SHOW TABLE STATUS WHERE Name = ')) throw new RuntimeException('Unexpected table status query.');
        return ['Engine' => $this->engine];
    }
    public function query(string $query) {
        $this->queries[] = $query;
        $this->last_error = '';
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

echo "OK: Grosses allocation transaction ownership and rollback regression\n";
