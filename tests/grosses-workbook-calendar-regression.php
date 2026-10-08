<?php
// Standalone fixtures for actual workbook date-to-day-column grouping.
namespace RoxyGrosses {
  final class Settings {
    public static function get_report_timezone(): string { return 'America/Los_Angeles'; }
    public static function studio_mappings(): array { return []; }
  }
  final class Store {
    public static array $rows = [];
    public static function entry_rows_for_year(int $year): array {
      return array_values(array_filter(self::$rows, static function (array $row) use ($year): bool {
        return (int) substr((string) ($row['report_date'] ?? ''), 0, 4) === $year;
      }));
    }
  }
}

namespace {
  define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
  define('DAY_IN_SECONDS', 86400);
  $root = $argv[1] ?? dirname(__DIR__);
  $candidate = $argv[2] ?? $root . '/includes/modules/grosses/includes/class-roxy-grosses-workbook.php';
  require $candidate;

  $checks = 0;
  function workbook_calendar_assert(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "PASS: {$message}\n";
  }
  function workbook_fixture_rows(string $film, array $dates): array {
    $rows = [];
    foreach ($dates as $index => $date) {
      $rows[] = [
        'report_date' => $date,
        'film_title' => $film,
        'show_time' => '19:00',
        'general_qty' => $index + 1,
        'discount_qty' => 0,
        'group_qty' => 0,
        'total_tickets' => $index + 1,
        'gross_total' => (float) ($index + 1),
      ];
    }
    return $rows;
  }
  function workbook_day_row(int $year, string $film, string $week_of): array {
    foreach (\RoxyGrosses\Workbook::weekly_rows_for_year($year) as $row) {
      if ($row['film_title'] === $film && $row['week_of'] === $week_of) return $row;
    }
    throw new RuntimeException("Missing weekly row {$film} {$week_of}");
  }

  \RoxyGrosses\Store::$rows = workbook_fixture_rows('Spring DST', ['2025-03-07', '2025-03-08', '2025-03-09', '2025-03-10', '2025-03-11', '2025-03-12', '2025-03-13']);
  $row = workbook_day_row(2025, 'Spring DST', '2025-03-07');
  workbook_calendar_assert(
    [$row['fri_gen'], $row['sat_gen'], $row['sun_gen'], $row['mon_gen'], $row['tue_gen'], $row['wed_gen'], $row['thu_gen']] === [1, 2, 3, 4, 5, 6, 7]
      && $row['week_of'] === '2025-03-07' && $row['total_gen'] === 28 && $row['gross'] === 28.0 && $row['admissions'] === 28 && $row['open_days'] === 7,
    'full spring-forward week maps all weekdays and preserves week date and totals'
  );

  \RoxyGrosses\Store::$rows = workbook_fixture_rows('Fall DST', ['2025-10-31', '2025-11-01', '2025-11-02', '2025-11-03', '2025-11-04', '2025-11-05', '2025-11-06']);
  $row = workbook_day_row(2025, 'Fall DST', '2025-10-31');
  workbook_calendar_assert(
    [$row['fri_gen'], $row['sat_gen'], $row['sun_gen'], $row['mon_gen'], $row['tue_gen'], $row['wed_gen'], $row['thu_gen']] === [1, 2, 3, 4, 5, 6, 7]
      && $row['week_of'] === '2025-10-31' && $row['total_gen'] === 28 && $row['gross'] === 28.0 && $row['admissions'] === 28 && $row['open_days'] === 7,
    'full fall-back week maps all weekdays and preserves week date and totals'
  );

  \RoxyGrosses\Store::$rows = workbook_fixture_rows('Year start', ['2024-01-01', '2024-01-02']);
  $row = workbook_day_row(2024, 'Year start', '2024-01-01');
  workbook_calendar_assert($row['week_of'] === '2024-01-01' && $row['mon_gen'] === 1 && $row['tue_gen'] === 2 && $row['fri_gen'] === 0, 'midweek Jan 1 clipping preserves week-start policy and actual weekday columns');

  \RoxyGrosses\Store::$rows = array_merge(
    workbook_fixture_rows('First date', ['2024-01-01']),
    workbook_fixture_rows('Last date', ['2024-12-31'])
  );
  $boundary_rows = \RoxyGrosses\Workbook::weekly_rows_for_year(2024);
  $boundary_starts = [];
  foreach ($boundary_rows as $boundary_row) $boundary_starts[$boundary_row['film_title']] = $boundary_row['week_of'];
  workbook_calendar_assert(
    count($boundary_rows) === 2 && $boundary_starts === ['First date' => '2024-01-01', 'Last date' => '2024-12-27']
      && workbook_day_row(2024, 'First date', '2024-01-01')['mon_gen'] === 1
      && workbook_day_row(2024, 'Last date', '2024-12-27')['tue_gen'] === 1,
    'Jan 1 and Dec 31 are each retained exactly once in their correct clipped/Friday-start weeks'
  );

  \RoxyGrosses\Store::$rows = workbook_fixture_rows('Leap day', ['2024-02-29']);
  $row = workbook_day_row(2024, 'Leap day', '2024-02-23');
  workbook_calendar_assert($row['thu_gen'] === 1 && $row['fri_gen'] === 0, 'leap day maps to Thursday in its Friday-start week');

  \RoxyGrosses\Store::$rows = workbook_fixture_rows('Ordinary week', ['2024-05-03', '2024-05-04', '2024-05-05', '2024-05-06', '2024-05-07', '2024-05-08', '2024-05-09']);
  $row = workbook_day_row(2024, 'Ordinary week', '2024-05-03');
  workbook_calendar_assert(
    [$row['fri_gen'], $row['sat_gen'], $row['sun_gen'], $row['mon_gen'], $row['tue_gen'], $row['wed_gen'], $row['thu_gen']] === [1, 2, 3, 4, 5, 6, 7],
    'ordinary full week preserves Friday-through-Thursday template columns'
  );

  \RoxyGrosses\Store::$rows = workbook_fixture_rows('Month crossing', ['2025-01-31', '2025-02-01', '2025-02-02', '2025-02-03', '2025-02-04', '2025-02-05', '2025-02-06']);
  $row = workbook_day_row(2025, 'Month crossing', '2025-01-31');
  $month_totals = \RoxyGrosses\Workbook::monthly_totals(2025);
  $month_values = [];
  foreach ($month_totals as $month) $month_values[$month['month_key']] = $month;
  workbook_calendar_assert(
    $row['week_of'] === '2025-01-31' && $row['fri_gen'] === 1 && $row['thu_gen'] === 7
      && $month_values['2025-01']['weeks'] === 1 && $month_values['2025-02']['weeks'] === 0
      && $month_values['2025-01']['admissions'] === 28,
    'month-crossing Friday-start week remains assigned to its week-start month with totals intact'
  );

  echo "{$checks} workbook calendar checks passed.\n";
}
