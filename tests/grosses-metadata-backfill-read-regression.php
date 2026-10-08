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

class Metadata {
    public static int $calls = 0;
    public static function enrich_movie_row(array $row, bool $force = false): array {
        self::$calls++;
        $row['studio'] = 'Fixture Studio';
        $row['genre'] = 'Fixture Genre';
        return $row;
    }
}
class_alias(Metadata::class, 'RoxyGrosses\\Metadata');

class MetadataBackfillWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public $result = false;
    public bool $set_error = false;
    public int $writes = 0;
    public function prepare(string $query, ...$args): string { return $query; }
    public function get_results(string $query, string $format) {
        if ($this->set_error) $this->last_error = 'fixture read failure';
        return $this->result;
    }
    public function update(string $table, array $data, array $where): int {
        $this->writes++;
        return 1;
    }
}

$wpdb = new MetadataBackfillWpdb();
require_once dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';

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
echo "Grosses metadata-backfill read regression passed.\n";
