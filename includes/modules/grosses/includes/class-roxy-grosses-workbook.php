<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Workbook {
  private const SNAPSHOT_OPTION = 'roxy_grosses_workbook_snapshots';

  public static function init(): void {
    add_action('admin_post_roxy_grosses_refresh_workbook', [__CLASS__, 'handle_refresh_workbook']);
    add_action('admin_post_roxy_grosses_download_workbook', [__CLASS__, 'handle_download_workbook']);
    add_action('admin_post_roxy_grosses_send_advertiser_summary', [__CLASS__, 'handle_send_advertiser_summary']);
  }

  public static function handle_refresh_workbook(): void {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to refresh grosses workbooks.');
    }

    check_admin_referer('roxy_grosses_refresh_workbook');
    $year = isset($_POST['year']) ? max(2000, (int) $_POST['year']) : (int) wp_date('Y');

    try {
      $snapshot = self::refresh_snapshot($year, 'manual-refresh');
      self::redirect_with_notice('success', 'Workbook refreshed for ' . $year . ' at ' . ($snapshot['refreshed_at'] ?? '') . '.', $year);
    } catch (\Throwable $e) {
      Store::insert_log('refresh_workbook', 'manual-refresh', null, sprintf('%04d-12-31', $year), false, $e->getMessage());
      Reporter::notify_admin_failure('Grosses workbook refresh failed', sprintf('%04d-12-31', $year), 'manual-refresh', $e->getMessage());
      self::redirect_with_notice('error', $e->getMessage(), $year);
    }
  }

  public static function handle_download_workbook(): void {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to download grosses workbooks.');
    }

    check_admin_referer('roxy_grosses_download_workbook');
    $year = isset($_POST['year']) ? max(2000, (int) $_POST['year']) : (int) wp_date('Y');

    try {
      $path = self::latest_snapshot_path($year);
      if ($path === '') {
        $snapshot = self::refresh_snapshot($year, 'download-bootstrap');
        $path = (string) ($snapshot['path'] ?? '');
      }
      if (!is_readable($path)) {
        throw new \RuntimeException('The generated workbook could not be read.');
      }

      Store::insert_log('download_workbook', 'manual-workbook', null, sprintf('%04d-12-31', $year), true, 'Workbook downloaded for ' . $year . '.', [
        'year' => $year,
        'path' => $path,
      ]);

      nocache_headers();
      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      header('Content-Disposition: attachment; filename="' . basename($path) . '"');
      header('Content-Length: ' . (string) filesize($path));
      readfile($path);
      exit;
    } catch (\Throwable $e) {
      Store::insert_log('download_workbook', 'manual-workbook', null, sprintf('%04d-12-31', $year), false, $e->getMessage());
      Reporter::notify_admin_failure('Grosses workbook generation failed', sprintf('%04d-12-31', $year), 'manual-workbook', $e->getMessage());
      self::redirect_with_notice('error', $e->getMessage());
    }
  }

  public static function handle_send_advertiser_summary(): void {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to email advertiser summaries.');
    }

    check_admin_referer('roxy_grosses_send_advertiser_summary');
    $month_value = isset($_POST['advertiser_month']) ? sanitize_text_field(wp_unslash((string) $_POST['advertiser_month'])) : '';
    if (!preg_match('/^\d{4}-\d{2}$/', $month_value)) {
      self::redirect_with_notice('error', 'Choose a valid advertiser month in YYYY-MM format.');
    }

    $year = (int) substr($month_value, 0, 4);
    $month = (int) substr($month_value, 5, 2);
    $result = self::send_advertiser_summary($year, $month, 'manual-advertiser');
    self::redirect_with_notice($result['success'] ? 'success' : 'error', $result['message']);
  }

  public static function dashboard_summary(int $year): array {
    $weekly_rows = self::weekly_rows_for_year($year);
    $summary = [
      'year' => $year,
      'annual_admissions' => 0,
      'annual_gross' => 0.0,
      'weeks_entered' => 0,
      'average_gross' => 0.0,
    ];

    foreach ($weekly_rows as $row) {
      $summary['annual_admissions'] += (int) ($row['admissions'] ?? 0);
      $summary['annual_gross'] += (float) ($row['gross'] ?? 0);
      if ((string) ($row['film_title'] ?? '') !== '') {
        $summary['weeks_entered']++;
      }
    }

    if ($summary['weeks_entered'] > 0) {
      $summary['average_gross'] = round($summary['annual_gross'] / $summary['weeks_entered'], 2);
    }

    $summary['annual_gross'] = round($summary['annual_gross'], 2);
    return $summary;
  }

  public static function monthly_totals(int $year): array {
    $weekly_rows = self::weekly_rows_for_year($year);
    $months = [];
    for ($month = 1; $month <= 12; $month++) {
      $key = sprintf('%04d-%02d', $year, $month);
      $months[$key] = [
        'month_key' => $key,
        'month_name' => wp_date('F', strtotime($key . '-01')),
        'weeks' => 0,
        'admissions' => 0,
        'gross' => 0.0,
        'average_gross' => 0.0,
        'open_days' => 0,
      ];
    }

    foreach ($weekly_rows as $row) {
      $week_start = (string) ($row['week_of'] ?? '');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start)) {
        continue;
      }

      $month_key = substr($week_start, 0, 7);
      if (!isset($months[$month_key])) {
        continue;
      }

      $months[$month_key]['weeks']++;
      $months[$month_key]['admissions'] += (int) ($row['admissions'] ?? 0);
      $months[$month_key]['gross'] += (float) ($row['gross'] ?? 0);
      $months[$month_key]['open_days'] += (int) ($row['open_days'] ?? 0);
    }

    foreach ($months as &$month_row) {
      $month_row['gross'] = round((float) $month_row['gross'], 2);
      if ((int) $month_row['weeks'] > 0) {
        $month_row['average_gross'] = round((float) $month_row['gross'] / (int) $month_row['weeks'], 2);
      }
    }
    unset($month_row);

    return array_values($months);
  }

  public static function weekly_rows_for_year(int $year): array {
    $history_rows = Store::history_rows_for_year($year);
    $grouped = [];

    foreach ($history_rows as $history_row) {
      $report_date = (string) ($history_row['report_date'] ?? '');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
        continue;
      }

      $week_start = self::week_start_for_date($report_date);
      if ((int) substr($week_start, 0, 4) !== $year) {
        continue;
      }

      $film_title = trim((string) ($history_row['film_title'] ?? ''));
      if ($film_title === '') {
        $film_title = 'Unknown Film';
      }

      $group_key = $week_start . '|' . mb_strtolower($film_title);
      if (!isset($grouped[$group_key])) {
        $grouped[$group_key] = [
          'week_number' => self::week_number_for_start($week_start),
          'week_of' => $week_start,
          'film_title' => $film_title,
          'studio' => self::studio_for_title($film_title),
          'prepared_by' => 'Imported',
          'submitted' => 'Yes',
          'fri_gen' => 0,
          'fri_disc' => 0,
          'fri_group' => 0,
          'sat_gen' => 0,
          'sat_disc' => 0,
          'sat_group' => 0,
          'sun_gen' => 0,
          'sun_disc' => 0,
          'sun_group' => 0,
          'mon_gen' => 0,
          'mon_disc' => 0,
          'mon_group' => 0,
          'tue_gen' => 0,
          'tue_disc' => 0,
          'tue_group' => 0,
          'wed_gen' => 0,
          'wed_disc' => 0,
          'wed_group' => 0,
          'thu_gen' => 0,
          'thu_disc' => 0,
          'thu_group' => 0,
          'total_gen' => 0,
          'total_disc' => 0,
          'total_group' => 0,
          'admissions' => 0,
          'gross' => 0.0,
          'open_days' => 0,
          'notes' => 'Imported from Roxy Grosses',
          '_open_dates' => [],
        ];
      }

      $day_prefix = self::day_prefix_for_date($report_date, $week_start);
      if ($day_prefix === '') {
        continue;
      }

      $general_qty = max(0, (int) ($history_row['general_qty'] ?? 0));
      $discount_qty = max(0, (int) ($history_row['discount_qty'] ?? 0));
      $group_qty = max(0, (int) ($history_row['group_qty'] ?? 0));
      $total_tickets = max(0, (int) ($history_row['total_tickets'] ?? ($general_qty + $discount_qty + $group_qty)));

      $grouped[$group_key][$day_prefix . '_gen'] += $general_qty;
      $grouped[$group_key][$day_prefix . '_disc'] += $discount_qty;
      $grouped[$group_key][$day_prefix . '_group'] += $group_qty;
      $grouped[$group_key]['total_gen'] += $general_qty;
      $grouped[$group_key]['total_disc'] += $discount_qty;
      $grouped[$group_key]['total_group'] += $group_qty;
      $grouped[$group_key]['admissions'] += $total_tickets;
      $grouped[$group_key]['gross'] += (float) ($history_row['gross_total'] ?? 0);
      if ($total_tickets > 0) {
        $grouped[$group_key]['_open_dates'][$report_date] = true;
      }
    }

    foreach ($grouped as &$group) {
      $group['gross'] = round((float) $group['gross'], 2);
      $group['open_days'] = count((array) ($group['_open_dates'] ?? []));
      unset($group['_open_dates']);
    }
    unset($group);

    uasort($grouped, static function (array $left, array $right): int {
      $left_key = ((string) ($left['week_of'] ?? '')) . '|' . ((string) ($left['film_title'] ?? ''));
      $right_key = ((string) ($right['week_of'] ?? '')) . '|' . ((string) ($right['film_title'] ?? ''));
      return strcmp($left_key, $right_key);
    });

    return array_values($grouped);
  }

  public static function send_advertiser_summary(int $year, int $month, string $mode = 'scheduled-advertiser'): array {
    $to = Settings::advertiser_email_list();
    $month_key = sprintf('%04d-%02d', $year, $month);
    $report_date = $month_key . '-01';

    if (!$to) {
      $message = 'No advertiser email addresses are configured.';
      Store::insert_log('send_advertiser_summary', $mode, null, $report_date, false, $message);
      Reporter::notify_admin_failure('Advertiser summary email failed', $report_date, $mode, $message);
      return ['success' => false, 'message' => $message];
    }

    try {
      $snapshot = self::refresh_snapshot($year, $mode);
      $workbook_path = (string) ($snapshot['path'] ?? '');
      $monthly_totals = self::monthly_totals($year);
      $month_total = null;
      foreach ($monthly_totals as $monthly_row) {
        if ((string) ($monthly_row['month_key'] ?? '') === $month_key) {
          $month_total = $monthly_row;
          break;
        }
      }

      if (!$month_total || (int) ($month_total['admissions'] ?? 0) <= 0) {
        throw new \RuntimeException('No advertiser summary data is available for ' . wp_date('F Y', strtotime($month_key . '-01')) . '.');
      }

      $tokens = [
        '{month_name}' => (string) ($month_total['month_name'] ?? wp_date('F', strtotime($month_key . '-01'))),
        '{month}' => $month_key,
        '{year}' => (string) $year,
        '{attendance_total}' => number_format((int) ($month_total['admissions'] ?? 0)),
        '{gross_total}' => number_format((float) ($month_total['gross'] ?? 0), 2),
      ];
      $subject = strtr((string) Settings::get('advertiser_email_subject', ''), $tokens);
      $body = strtr((string) Settings::get('advertiser_email_body', ''), $tokens);

      $sent = wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8'], [$workbook_path]);
      if (!$sent) {
        throw new \RuntimeException('WordPress could not send the advertiser summary email.');
      }

      $message = 'Advertiser summary sent to ' . implode(', ', $to) . ' for ' . $tokens['{month_name}'] . ' ' . $year . '.';
      Store::insert_log('send_advertiser_summary', $mode, null, $report_date, true, $message, [
        'month' => $month_key,
        'attendance_total' => (int) ($month_total['admissions'] ?? 0),
        'gross_total' => (float) ($month_total['gross'] ?? 0),
      ]);

      return ['success' => true, 'message' => $message];
    } catch (\Throwable $e) {
      Store::insert_log('send_advertiser_summary', $mode, null, $report_date, false, $e->getMessage(), [
        'month' => $month_key,
      ]);
      Reporter::notify_admin_failure('Advertiser summary email failed', $report_date, $mode, $e->getMessage());
      return ['success' => false, 'message' => $e->getMessage()];
    }
  }

  public static function build_workbook_file(int $year): string {
    $weekly_rows = self::weekly_rows_for_year($year);
    if (count($weekly_rows) > 54) {
      throw new \RuntimeException('The workbook template only supports 54 weekly rows. Reduce the year scope or expand the template.');
    }

    $template = self::resolve_template_path($year);
    if (!is_readable($template)) {
      throw new \RuntimeException('Workbook template not found: ' . $template);
    }

    if (!class_exists('ZipArchive')) {
      throw new \RuntimeException('ZipArchive is required to build the workbook.');
    }

    $upload_dir = wp_upload_dir();
    $dir = trailingslashit($upload_dir['basedir']) . 'roxy-grosses/workbooks';
    wp_mkdir_p($dir);
    $output_path = trailingslashit($dir) . 'roxy-box-office-' . $year . '.xlsx';
    if (!copy($template, $output_path)) {
      throw new \RuntimeException('The workbook template could not be copied for generation.');
    }

    $zip = new \ZipArchive();
    if ($zip->open($output_path) !== true) {
      throw new \RuntimeException('The generated workbook could not be opened.');
    }

    try {
      $sheet3 = $zip->getFromName('xl/worksheets/sheet3.xml');
      $sheet4 = $zip->getFromName('xl/worksheets/sheet4.xml');
      $workbook = $zip->getFromName('xl/workbook.xml');
      if ($sheet3 === false || $sheet4 === false || $workbook === false) {
        throw new \RuntimeException('The workbook template is missing required worksheets.');
      }

      $zip->addFromString('xl/worksheets/sheet3.xml', self::populate_weekly_log_sheet((string) $sheet3, $weekly_rows));
      $zip->addFromString('xl/worksheets/sheet4.xml', self::populate_setup_sheet((string) $sheet4, $year));
      $zip->addFromString('xl/workbook.xml', self::force_recalculate_workbook((string) $workbook));
    } finally {
      $zip->close();
    }

    return $output_path;
  }

  public static function get_snapshot_status(int $year): array {
    $all = get_option(self::SNAPSHOT_OPTION, []);
    if (!is_array($all)) {
      $all = [];
    }

    $snapshot = $all[(string) $year] ?? [];
    return is_array($snapshot) ? $snapshot : [];
  }

  public static function latest_snapshot_path(int $year): string {
    $snapshot = self::get_snapshot_status($year);
    $path = (string) ($snapshot['path'] ?? '');
    return ($path !== '' && is_readable($path)) ? $path : '';
  }

  public static function refresh_snapshot(int $year, string $mode = 'manual-refresh'): array {
    $path = self::build_workbook_file($year);
    $refreshed_at = wp_date('Y-m-d H:i:s', null, new \DateTimeZone(Settings::get_report_timezone()));
    $snapshot = [
      'year' => $year,
      'path' => $path,
      'refreshed_at' => $refreshed_at,
    ];

    $all = get_option(self::SNAPSHOT_OPTION, []);
    if (!is_array($all)) {
      $all = [];
    }
    $all[(string) $year] = $snapshot;
    update_option(self::SNAPSHOT_OPTION, $all);

    Store::insert_log('refresh_workbook', $mode, null, sprintf('%04d-12-31', $year), true, 'Workbook refreshed for ' . $year . '.', [
      'year' => $year,
      'path' => $path,
      'refreshed_at' => $refreshed_at,
    ]);

    return $snapshot;
  }

  private static function resolve_template_path(int $year): string {
    $template = (string) Settings::get('workbook_template_path', '');
    if ($template === '') {
      throw new \RuntimeException('No workbook template path is configured.');
    }

    return str_replace('{year}', (string) $year, $template);
  }

  private static function populate_weekly_log_sheet(string $xml, array $weekly_rows): string {
    $dom = new \DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    $dom->loadXML($xml);
    $xpath = new \DOMXPath($dom);
    $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    for ($row_number = 7; $row_number <= 60; $row_number++) {
      $data = $weekly_rows[$row_number - 7] ?? null;
      $row = $xpath->query('//m:sheetData/m:row[@r="' . $row_number . '"]')->item(0);
      if (!$row instanceof \DOMElement) {
        continue;
      }

      self::set_cell_inline_string($dom, $xpath, $row, 'C' . $row_number, $data ? (string) ($data['film_title'] ?? '') : '');
      self::set_cell_inline_string($dom, $xpath, $row, 'D' . $row_number, $data ? (string) ($data['studio'] ?? '') : '');
      self::set_cell_inline_string($dom, $xpath, $row, 'E' . $row_number, $data ? (string) ($data['prepared_by'] ?? '') : '');
      self::set_cell_inline_string($dom, $xpath, $row, 'F' . $row_number, $data ? (string) ($data['submitted'] ?? '') : '');

      foreach ([
        'G' => 'fri_gen', 'H' => 'fri_disc', 'I' => 'fri_group',
        'J' => 'sat_gen', 'K' => 'sat_disc', 'L' => 'sat_group',
        'M' => 'sun_gen', 'N' => 'sun_disc', 'O' => 'sun_group',
        'P' => 'mon_gen', 'Q' => 'mon_disc', 'R' => 'mon_group',
        'S' => 'tue_gen', 'T' => 'tue_disc', 'U' => 'tue_group',
        'V' => 'wed_gen', 'W' => 'wed_disc', 'X' => 'wed_group',
        'Y' => 'thu_gen', 'Z' => 'thu_disc', 'AA' => 'thu_group',
      ] as $column => $key) {
        self::set_cell_number($dom, $xpath, $row, $column . $row_number, $data ? (int) ($data[$key] ?? 0) : 0);
      }

      self::set_cell_inline_string($dom, $xpath, $row, 'AH' . $row_number, $data ? (string) ($data['notes'] ?? '') : '');
    }

    return $dom->saveXML();
  }

  private static function populate_setup_sheet(string $xml, int $year): string {
    $dom = new \DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    $dom->loadXML($xml);
    $xpath = new \DOMXPath($dom);
    $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    foreach ([
      'B4' => $year,
      'B10' => (float) Settings::get('general_price', '12'),
      'B11' => (float) Settings::get('discount_price', '8'),
      'B12' => (float) Settings::get('group_price', '5'),
    ] as $cell_ref => $value) {
      $cell = $xpath->query('//m:c[@r="' . $cell_ref . '"]')->item(0);
      if ($cell instanceof \DOMElement) {
        self::replace_numeric_value($dom, $cell, $value);
      }
    }

    return $dom->saveXML();
  }

  private static function force_recalculate_workbook(string $xml): string {
    $dom = new \DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    $dom->loadXML($xml);
    $xpath = new \DOMXPath($dom);
    $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $calc = $xpath->query('//m:calcPr')->item(0);
    if (!$calc instanceof \DOMElement) {
      $workbook = $xpath->query('/m:workbook')->item(0);
      if ($workbook instanceof \DOMElement) {
        $calc = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'calcPr');
        $workbook->appendChild($calc);
      }
    }

    if ($calc instanceof \DOMElement) {
      $calc->setAttribute('calcMode', 'auto');
      $calc->setAttribute('fullCalcOnLoad', '1');
      $calc->setAttribute('forceFullCalc', '1');
    }

    return $dom->saveXML();
  }

  private static function set_cell_inline_string(\DOMDocument $dom, \DOMXPath $xpath, \DOMElement $row, string $cell_ref, string $value): void {
    $cell = self::find_cell($xpath, $row, $cell_ref);
    if (!$cell instanceof \DOMElement) {
      return;
    }

    while ($cell->firstChild) {
      $cell->removeChild($cell->firstChild);
    }

    $cell->setAttribute('t', 'inlineStr');
    $is = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'is');
    $text = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't');
    $text->appendChild($dom->createTextNode($value));
    $is->appendChild($text);
    $cell->appendChild($is);
  }

  private static function set_cell_number(\DOMDocument $dom, \DOMXPath $xpath, \DOMElement $row, string $cell_ref, $value): void {
    $cell = self::find_cell($xpath, $row, $cell_ref);
    if (!$cell instanceof \DOMElement) {
      return;
    }

    self::replace_numeric_value($dom, $cell, $value);
  }

  private static function replace_numeric_value(\DOMDocument $dom, \DOMElement $cell, $value): void {
    while ($cell->firstChild) {
      $cell->removeChild($cell->firstChild);
    }

    if ($cell->hasAttribute('t')) {
      $cell->removeAttribute('t');
    }

    $v = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'v');
    $v->appendChild($dom->createTextNode((string) $value));
    $cell->appendChild($v);
  }

  private static function find_cell(\DOMXPath $xpath, \DOMElement $row, string $cell_ref): ?\DOMElement {
    $cell = $xpath->query('m:c[@r="' . $cell_ref . '"]', $row)->item(0);
    return $cell instanceof \DOMElement ? $cell : null;
  }

  private static function week_start_for_date(string $date): string {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $current = new \DateTimeImmutable($date . ' 00:00:00', $timezone);
    $day = strtolower($current->format('D'));
    $offsets = [
      'fri' => 0,
      'sat' => 1,
      'sun' => 2,
      'mon' => 3,
      'tue' => 4,
      'wed' => 5,
      'thu' => 6,
    ];
    $offset = $offsets[$day] ?? 0;
    return $current->modify('-' . $offset . ' day')->format('Y-m-d');
  }

  private static function day_prefix_for_date(string $date, string $week_start): string {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $current = new \DateTimeImmutable($date . ' 00:00:00', $timezone);
    $start = new \DateTimeImmutable($week_start . ' 00:00:00', $timezone);
    $diff = (int) floor(($current->getTimestamp() - $start->getTimestamp()) / DAY_IN_SECONDS);
    $map = ['fri', 'sat', 'sun', 'mon', 'tue', 'wed', 'thu'];
    return $map[$diff] ?? '';
  }

  private static function week_number_for_start(string $week_start): int {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $start = new \DateTimeImmutable($week_start . ' 00:00:00', $timezone);
    $year = (int) $start->format('Y');
    $first = new \DateTimeImmutable($year . '-01-01 00:00:00', $timezone);
    while ($first->format('D') !== 'Fri') {
      $first = $first->modify('+1 day');
    }

    return (int) floor(($start->getTimestamp() - $first->getTimestamp()) / WEEK_IN_SECONDS) + 1;
  }

  private static function studio_for_title(string $title): string {
    $mappings = Settings::studio_mappings();
    $lookup = mb_strtolower(trim($title));
    if ($lookup === '') {
      return '';
    }

    foreach ($mappings as $mapping) {
      if (($mapping['match'] ?? '') === $lookup) {
        return (string) ($mapping['studio'] ?? '');
      }
    }

    foreach ($mappings as $mapping) {
      if (str_contains($lookup, (string) ($mapping['match'] ?? ''))) {
        return (string) ($mapping['studio'] ?? '');
      }
    }

    return '';
  }

  private static function redirect_with_notice(string $status, string $message, ?int $year = null): void {
    $url = add_query_arg([
      'page' => 'roxy-grosses',
      'tab' => 'workbook',
      'workbook_year' => $year ?: null,
      'roxy_grosses_notice' => $status,
      'message' => $message,
    ], admin_url('admin.php'));

    wp_safe_redirect($url);
    exit;
  }
}
