<?php
// Direct failure-injection checks for persisted Grosses concession totals.
namespace {
  define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  define('ARRAY_A', 'ARRAY_A');

  final class ConcessionsByDateWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public int $calls = 0;
    public int $fail_at = 0;
    public function prepare(string $query, ...$args): string { return $query; }
    public function get_results(string $query, $output = null) {
      $this->calls++;
      if ($this->calls === $this->fail_at) {
        $this->last_error = 'injected query failure';
        return false;
      }
      return [];
    }
  }

  $wpdb = new ConcessionsByDateWpdb();
  require dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';
  $checks = 0;
  function concessions_by_date_assert(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new \RuntimeException($message);
    $checks++;
    echo "PASS: $message\n";
  }

  foreach ([1 => 'movie', 2 => 'live-show', 3 => 'rental'] as $query_number => $label) {
    $wpdb->calls = 0;
    $wpdb->last_error = '';
    $wpdb->fail_at = $query_number;
    try {
      \RoxyGrosses\Store::concessions_by_date('2026-10-11', '2026-10-11');
      throw new \RuntimeException("$label query failure was accepted as a successful read");
    } catch (\RuntimeException $error) {
      concessions_by_date_assert(str_contains($error->getMessage(), 'Could not read'), "$label concessions read fails closed on database error");
      concessions_by_date_assert($wpdb->calls === $query_number, "$label failure stops before later report-table reads");
    }
  }

  $wpdb->calls = 0;
  $wpdb->last_error = '';
  $wpdb->fail_at = 0;
  concessions_by_date_assert(\RoxyGrosses\Store::concessions_by_date('2026-10-11', '2026-10-11') === [], 'verified empty Movie, Live, and Rental totals remain a valid no-sales result');
  echo "$checks Grosses concessions-by-date Store checks passed; fake DB only.\n";
}
