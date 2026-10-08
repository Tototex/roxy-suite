<?php
// Isolated regression: failed metadata-candidate reads must not masquerade as
// an empty backfill or proceed to external metadata lookup / row updates.
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

function metadata_backfill_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: {$label}\n";
}

function current_time(string $type): string { return '2026-10-08 12:00:00'; }
function sanitize_text_field(string $value): string { return $value; }
function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void { $GLOBALS['metadata_hooks'][$hook] = [$callback, $accepted_args]; }
function wp_next_scheduled(string $hook, array $args = []) { foreach ($GLOBALS['metadata_scheduled'] as $event) if ($event['hook'] === $hook && $event['args'] === $args) return $event['timestamp']; return false; }
function wp_schedule_single_event(int $timestamp, string $hook, array $args = []) { $GLOBALS['metadata_scheduled'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args]; return true; }
function is_wp_error($value): bool { return false; }

class Metadata {
    public static int $calls = 0;
    public static function enrich_movie_row(array $row, bool $force = false, bool $allow_remote = true): array {
        if ($allow_remote) {
            $metadata = self::metadata_for_movie((string) ($row['movie_title'] ?? ''), (int) substr((string) ($row['report_date'] ?? ''), 0, 4));
            if (empty($row['studio'])) $row['studio'] = $metadata['studio'];
            if (empty($row['genre'])) $row['genre'] = $metadata['genre'];
        }
        return $row;
    }
    public static function metadata_for_movie(string $title, int $year = 0, bool $force = false): array {
        self::$calls++;
        return ['studio' => 'Fixture Studio', 'genre' => 'Fixture Genre'];
    }
}
class_alias(Metadata::class, 'RoxyGrosses\\Metadata');

class MetadataBackfillWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public $result = false;
    public bool $set_error = false;
    public int $writes = 0;
    public string $last_query = '';
    public function prepare(string $query, ...$args): string { return $query; }
    public function get_results(string $query, string $format) {
        if ($this->set_error) $this->last_error = 'fixture read failure';
        return $this->result;
    }
    public function update(string $table, array $data, array $where): int {
        $this->writes++;
        return 1;
    }
    public function get_row(string $query, string $format) { return null; }
    public function insert(string $table, array $data): int { $this->writes++; return 1; }
    public function query(string $query): int { $this->last_query = $query; $this->writes++; return 1; }
}

$GLOBALS['metadata_scheduled'] = [];
$GLOBALS['metadata_hooks'] = [];
$wpdb = new MetadataBackfillWpdb();
require_once dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';
\RoxyGrosses\Store::init_metadata_enrichment();
metadata_backfill_check(isset($GLOBALS['metadata_hooks']['roxy_grosses_enrich_movie_metadata']), 'background enrichment hook is registered');

$wpdb->set_error = true;
try {
    \RoxyGrosses\Store::backfill_movie_metadata();
    throw new RuntimeException('A database read error was incorrectly reported as a successful empty backfill.');
} catch (RuntimeException $error) {
    metadata_backfill_check(str_contains($error->getMessage(), 'could not read candidate rows'), 'database query error fails explicitly');
}
metadata_backfill_check(Metadata::$calls === 0 && $wpdb->writes === 0, 'database query error causes no metadata lookup or row update');

$wpdb->set_error = false;
$wpdb->last_error = '';
$wpdb->result = false;
try {
    \RoxyGrosses\Store::backfill_movie_metadata();
    throw new RuntimeException('A malformed query result was incorrectly reported as a successful empty backfill.');
} catch (RuntimeException $error) {
    metadata_backfill_check(str_contains($error->getMessage(), 'could not read candidate rows'), 'malformed query result fails explicitly');
}
metadata_backfill_check(Metadata::$calls === 0 && $wpdb->writes === 0, 'malformed query result causes no metadata lookup or row update');

$wpdb->result = [];
$result = \RoxyGrosses\Store::backfill_movie_metadata();
metadata_backfill_check($result === ['processed' => 0, 'updated' => 0, 'skipped' => 0], 'verified empty query remains a successful empty backfill');

$wpdb->last_error = '';
$wpdb->result = null;
Metadata::$calls = 0;
$saved = \RoxyGrosses\Store::upsert_entries([[
    'report_date' => '2026-10-08', 'movie_title' => 'Fixture Movie', 'show_time' => '7:00 PM',
    'general_qty' => 10, 'total_tickets' => 10, 'gross_total' => 100.00,
]]);
metadata_backfill_check($saved['created'] === 1 && Metadata::$calls === 0, 'entry upsert does not call external metadata provider inline');
metadata_backfill_check(count($GLOBALS['metadata_scheduled']) === 1 && $GLOBALS['metadata_scheduled'][0]['args'] === ['Fixture Movie', 2026, 0], 'entry upsert queues one title/year enrichment job');

$wpdb->result = [['id' => 42, 'report_date' => '2026-10-08', 'normalized_title' => 'fixture movie', 'studio' => '', 'genre' => '']];
$wpdb->last_error = '';
$job = \RoxyGrosses\Store::run_metadata_enrichment_job('Fixture Movie', 2026, 0);
metadata_backfill_check($job === ['processed' => 1, 'updated' => 1, 'scheduled' => false] && Metadata::$calls === 1, 'background worker enriches candidate rows only when run');
metadata_backfill_check(str_contains($wpdb->last_query, 'is_locked = 0') && str_contains($wpdb->last_query, "studio IS NULL OR studio = ''"), 'background write is conditional on unlocked row and blank metadata');
metadata_backfill_check(!str_contains($wpdb->last_query, 'gross_total') && !str_contains($wpdb->last_query, 'total_tickets'), 'background enrichment cannot change historical financial fields');

$wpdb->result = [];
for ($id = 1; $id <= 100; $id++) $wpdb->result[] = ['id' => $id, 'report_date' => '2026-10-08', 'normalized_title' => 'fixture movie', 'studio' => '', 'genre' => ''];
$job = \RoxyGrosses\Store::run_metadata_enrichment_job('Fixture Movie', 2026, 0);
metadata_backfill_check($job['processed'] === 100 && $job['scheduled'] === true, 'large-title enrichment schedules a bounded continuation');
metadata_backfill_check($GLOBALS['metadata_scheduled'][1]['args'] === ['Fixture Movie', 2026, 100], 'continuation advances beyond the processed row boundary');

$wpdb->set_error = true;
$wpdb->result = [['id' => 201, 'report_date' => '2026-10-08', 'normalized_title' => 'fixture movie', 'studio' => '', 'genre' => '']];
$before_calls = Metadata::$calls;
$before_writes = $wpdb->writes;
try {
    \RoxyGrosses\Store::run_metadata_enrichment_job('Fixture Movie', 2026, 100);
    throw new RuntimeException('A worker candidate read error should not be reported as success.');
} catch (RuntimeException $error) {
    metadata_backfill_check(str_contains($error->getMessage(), 'could not read candidate rows'), 'background worker fails explicitly on a candidate read error');
}
metadata_backfill_check(Metadata::$calls === $before_calls && $wpdb->writes === $before_writes, 'background worker read error causes no provider call or database write');
echo "Grosses metadata-backfill read regression passed.\n";
