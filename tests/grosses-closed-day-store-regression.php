<?php
// Isolated fake-DB checks for closed-day snapshot comparison; no production DB or mail.
namespace {
  define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  define('ARRAY_A', 'ARRAY_A');
  function current_time(string $type): string { return '2026-10-07 08:00:00'; }
  function wp_json_encode($value) { return json_encode($value); }

  final class ClosedDayWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $reports = [];
    public array $review_rows = [];
    public array $final_rows = [];
    public int $review_writes = 0;
    public function prepare(string $query, ...$args): string { return json_encode(['sql' => $query, 'args' => $args]); }
    public function esc_like(string $value): string { return $value; }
    private function parts(string $query): array {
      $parts = json_decode($query, true);
      return is_array($parts) && isset($parts['sql']) ? $parts : ['sql' => $query, 'args' => []];
    }
    public function get_var(string $query) {
      $parts = $this->parts($query); $sql = $parts['sql']; $args = $parts['args'];
      if (str_starts_with($sql, 'SHOW TABLES LIKE')) return $this->prefix . 'roxy_grosses_refund_reviews';
      if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'IS_USED_LOCK')) return 1;
      if (str_contains($sql, 'SELECT changes_json FROM')) return $this->review_rows[(int) ($args[0] ?? 0)]['changes_json'] ?? null;
      return null;
    }
    public function get_results(string $query, $output = null): array {
      $parts = $this->parts($query); $sql = $parts['sql']; $args = $parts['args'];
      if (str_contains($sql, 'FROM wp_roxy_grosses_reports')) {
        $date = (string) ($args[0] ?? ''); $cursor = (int) ($args[1] ?? 0);
        $exact = str_contains($sql, 'report_end_date = %s');
        $rows = array_values(array_filter($this->reports, static fn(array $row): bool => $row['status'] === 'emailed' && $row['id'] > $cursor && ($exact ? $row['report_end_date'] === $date : $row['report_end_date'] >= $date)));
        usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return array_slice($rows, 0, 50);
      }
      if (str_contains($sql, 'FROM wp_roxy_grosses_refund_reviews')) {
        preg_match('/IN \(([^)]*)\)/', $sql, $match);
        $ids = array_map('intval', explode(',', $match[1] ?? ''));
        return array_values(array_filter($this->review_rows, static fn(array $row): bool => in_array((int) $row['report_id'], $ids, true)));
      }
      if (str_contains($sql, 'FROM wp_roxy_grosses_entries')) return $this->final_rows;
      return [];
    }
    public function query(string $query) {
      $parts = $this->parts($query); $args = $parts['args'];
      if (str_starts_with($parts['sql'], 'INSERT INTO')) {
        $id = (int) ($args[0] ?? 0);
        $this->review_rows[$id] = ['report_id' => $id, 'changes_json' => (string) ($args[3] ?? '')];
        $this->review_writes++;
        return 1;
      }
      return 1;
    }
  }
  $wpdb = new ClosedDayWpdb();
  $root = $argv[1] ?? dirname(__DIR__);
  require $root . '/includes/modules/grosses/includes/class-roxy-grosses-store.php';
  $checks = 0;
  function closed_day_store_assert(bool $condition, string $message): void {
    global $checks; if (!$condition) throw new RuntimeException($message); $checks++; echo "PASS: $message\n";
  }
  function closed_day_row(string $date, int $qty, float $gross, float $concessions): array {
    return ['report_date'=>$date,'showing_id'=>71,'show_time'=>'20:00','film_title'=>'Late Feature','general_qty'=>$qty,'discount_qty'=>0,'group_qty'=>0,'live_qty'=>0,'total_tickets'=>$qty,'gross_total'=>$gross,'concessions_total'=>$concessions];
  }

  $date = '2026-10-06';
  $wpdb->reports = [
    ['id'=>10,'status'=>'emailed','report_end_date'=>$date,'payload_json'=>json_encode(['rows'=>[closed_day_row($date,1,10.00,2.00)]])],
    ['id'=>11,'status'=>'emailed','report_end_date'=>'2026-10-07','payload_json'=>json_encode(['rows'=>[closed_day_row($date,1,10.00,2.00)]])],
    ['id'=>12,'status'=>'emailed','report_end_date'=>$date,'payload_json'=>json_encode(['rows'=>[]])],
    ['id'=>13,'status'=>'draft','report_end_date'=>$date,'payload_json'=>json_encode(['rows'=>[closed_day_row($date,1,10.00,2.00)]])],
  ];
  $wpdb->final_rows = [array_merge(closed_day_row($date,2,20.00,3.00), ['movie_title'=>'Late Feature'])];
  $final_rows = \RoxyGrosses\Store::closed_day_report_rows($date);
  closed_day_store_assert($final_rows[0]['general_qty'] === 2 && (float) $final_rows[0]['concessions_total'] === 3.0, 'comparison input is read back from final stored quantities and allocations');

  $flagged = \RoxyGrosses\Store::flag_emailed_closed_day_changes($date, $final_rows);
  closed_day_store_assert($flagged === [10,12], 'changed and newly appearing rows in exact-date emailed snapshots are flagged; later and draft reports are excluded');
  $reviews = \RoxyGrosses\Store::refund_reviews([10,12]);
  closed_day_store_assert(($reviews[10][$date]['reason'] ?? '') === 'closed_day_refresh' && $reviews[10][$date]['before'] !== $reviews[10][$date]['after'], 'review evidence records the closed-day reason and before/after totals');
  $writes = $wpdb->review_writes;
  \RoxyGrosses\Store::flag_emailed_closed_day_changes($date, $final_rows);
  closed_day_store_assert($wpdb->review_writes === $writes, 'repeating the same refresh is idempotent and does not rewrite review evidence');
  $unchanged = \RoxyGrosses\Store::flag_emailed_closed_day_changes($date, [closed_day_row($date,1,10.00,2.00)]);
  closed_day_store_assert(!in_array(10, $unchanged, true), 'unchanged emailed totals are not newly flagged');

  echo "$checks closed-day store checks passed; fake DB only.\n";
}
