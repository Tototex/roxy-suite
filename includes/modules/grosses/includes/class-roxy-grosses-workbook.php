<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Workbook {
  private const SNAPSHOT_OPTION = 'roxy_grosses_workbook_snapshots';
  private const TEMPLATE_KEY = 'workbook_template_upload';
  private const PRIVATE_ROOT_DIR = 'roxy-grosses-private';

  public static function init(): void {
    add_action('admin_post_roxy_grosses_upload_template', [__CLASS__, 'handle_upload_template']);
    add_action('admin_post_roxy_grosses_refresh_workbook', [__CLASS__, 'handle_refresh_workbook']);
    add_action('admin_post_roxy_grosses_download_workbook', [__CLASS__, 'handle_download_workbook']);
    add_action('admin_post_roxy_grosses_send_advertiser_summary', [__CLASS__, 'handle_send_advertiser_summary']);
  }

  public static function handle_upload_template(): void {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('You do not have permission to upload workbook templates.');
    }

    check_admin_referer('roxy_grosses_upload_template');

    if (empty($_FILES['workbook_template']['name'])) {
      self::redirect_with_notice('error', 'Choose an .xlsx workbook template to upload.', null, 'workbook');
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    $uploaded = wp_handle_upload($_FILES['workbook_template'], [
      'test_form' => false,
      'mimes' => ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ]);

    if (!empty($uploaded['error'])) {
      self::redirect_with_notice('error', (string) $uploaded['error'], null, 'workbook');
    }

    $original_name = sanitize_file_name((string) ($_FILES['workbook_template']['name'] ?? basename((string) ($uploaded['file'] ?? 'template.xlsx'))));
    $private_path = self::move_file_to_private_storage(
      (string) ($uploaded['file'] ?? ''),
      self::private_templates_dir(),
      $original_name
    );

    self::set_uploaded_template([
      'path' => $private_path,
      'name' => $original_name,
      'uploaded_at' => wp_date('Y-m-d H:i:s', null, new \DateTimeZone(Settings::get_report_timezone())),
    ]);

    Store::insert_log('upload_workbook_template', 'manual-template', null, null, true, 'Workbook template uploaded.', [
      'path' => $private_path,
    ]);
    self::redirect_with_notice('success', 'Workbook template uploaded successfully.', null, 'workbook');
  }

  public static function handle_refresh_workbook(): void {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('You do not have permission to refresh grosses workbooks.');
    }

    check_admin_referer('roxy_grosses_refresh_workbook');
    $year = isset($_POST['year']) ? max(2000, (int) $_POST['year']) : (int) wp_date('Y');

    try {
      $snapshot = self::refresh_snapshot($year, 'manual-refresh');
      self::redirect_with_notice('success', 'Workbook refreshed for ' . $year . ' at ' . ($snapshot['refreshed_at'] ?? '') . '.', $year, 'workbook');
    } catch (\Throwable $e) {
      Store::insert_log('refresh_workbook', 'manual-refresh', null, sprintf('%04d-12-31', $year), false, $e->getMessage());
      Reporter::notify_admin_failure('Grosses workbook refresh failed', sprintf('%04d-12-31', $year), 'manual-refresh', $e->getMessage());
      self::redirect_with_notice('error', $e->getMessage(), $year, 'workbook');
    }
  }

  public static function handle_download_workbook(): void {
    if (!roxy_suite_user_can_access_admin()) {
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
      self::redirect_with_notice('error', $e->getMessage(), null, 'workbook');
    }
  }

  public static function handle_send_advertiser_summary(): void {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('You do not have permission to email advertiser summaries.');
    }

    check_admin_referer('roxy_grosses_send_advertiser_summary');
    $return_tab = isset($_POST['return_tab']) ? sanitize_key((string) wp_unslash($_POST['return_tab'])) : 'workbook';
    if (!in_array($return_tab, ['database', 'settings', 'logs', 'legacy-weekly', 'workbook', 'daily'], true)) {
      $return_tab = 'workbook';
    }
    $start_month_value = isset($_POST['advertiser_start_month']) ? sanitize_text_field(wp_unslash((string) $_POST['advertiser_start_month'])) : '';
    $end_month_value = isset($_POST['advertiser_end_month']) ? sanitize_text_field(wp_unslash((string) $_POST['advertiser_end_month'])) : '';

    if ($start_month_value === '' && $end_month_value === '') {
      $month_value = isset($_POST['advertiser_month']) ? sanitize_text_field(wp_unslash((string) $_POST['advertiser_month'])) : '';
      if ($month_value !== '') {
        $start_month_value = $month_value;
        $end_month_value = $month_value;
      }
    }

    if (!preg_match('/^\d{4}-\d{2}$/', $start_month_value) || !preg_match('/^\d{4}-\d{2}$/', $end_month_value)) {
      self::redirect_with_notice('error', 'Choose valid advertiser start and end months in YYYY-MM format.', null, $return_tab);
    }

    $start_year = (int) substr($start_month_value, 0, 4);
    $start_month = (int) substr($start_month_value, 5, 2);
    $end_year = (int) substr($end_month_value, 0, 4);
    $end_month = (int) substr($end_month_value, 5, 2);
    [$start_year, $start_month, $end_year, $end_month] = self::normalized_period($start_year, $start_month, $end_year, $end_month);

    $mode = !empty($_POST['test_send']) ? 'manual-advertiser-test' : 'manual-advertiser';
    $result = self::send_advertiser_summary($start_year, $start_month, $mode, $end_year, $end_month);
    self::redirect_with_notice($result['success'] ? 'success' : 'error', $result['message'], $start_year, $return_tab);
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
        'month_name' => self::month_name($year, $month),
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

      $month_number = (int) substr($week_start, 5, 2);
      if ($month_number < 1 || $month_number > 12) {
        continue;
      }

      $months[sprintf('%04d-%02d', $year, $month_number)]['weeks']++;
      $months[sprintf('%04d-%02d', $year, $month_number)]['admissions'] += (int) ($row['admissions'] ?? 0);
      $months[sprintf('%04d-%02d', $year, $month_number)]['gross'] += (float) ($row['gross'] ?? 0);
      $months[sprintf('%04d-%02d', $year, $month_number)]['open_days'] += (int) ($row['open_days'] ?? 0);
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
    $history_rows = Store::entry_rows_for_year($year);
    $grouped = [];

    foreach ($history_rows as $history_row) {
      $report_date = (string) ($history_row['report_date'] ?? '');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
        continue;
      }

      $week_start = self::normalized_week_start_for_year($report_date, $year);
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

  public static function send_advertiser_summary(int $year, int $month, string $mode = 'scheduled-advertiser', ?int $end_year = null, ?int $end_month = null): array {
    $is_test_send = $mode === 'manual-advertiser-test';
    $to = $is_test_send ? self::test_email_list() : Settings::advertiser_email_list();
    $end_year = $end_year ?? $year;
    $end_month = $end_month ?? $month;
    [$year, $month, $end_year, $end_month] = self::normalized_period($year, $month, $end_year, $end_month);
    $month_key = sprintf('%04d-%02d', $year, $month);
    $end_month_key = sprintf('%04d-%02d', $end_year, $end_month);
    $report_date = $end_month_key . '-01';
    $period_label = self::period_label($year, $month, $end_year, $end_month);

    if (!$to) {
      $message = $is_test_send ? 'No admin alert email is configured for advertiser tests.' : 'No advertiser email addresses are configured.';
      Store::insert_log('send_advertiser_summary', $mode, null, $report_date, false, $message);
      Reporter::notify_admin_failure('Advertiser summary email failed', $report_date, $mode, $message);
      return ['success' => false, 'message' => $message];
    }

    try {
      $rows = self::advertiser_rows_for_period($year, $month, $end_year, $end_month);
      $period_totals = self::advertiser_totals_for_period($year, $month, $end_year, $end_month);

      if ((int) ($period_totals['attendance_total'] ?? 0) <= 0) {
        throw new \RuntimeException('No advertiser summary data is available for ' . $period_label . '.');
      }

      $workbook_path = self::build_advertiser_workbook_file($year, $month, $end_year, $end_month, $rows);

      $tokens = [
        '{month_name}' => (string) ($period_totals['month_name'] ?? self::month_name($year, $month)),
        '{month}' => $month_key,
        '{year}' => (string) $year,
        '{theater_name}' => (string) Settings::get('theater_name', 'Newport Roxy Theater'),
        '{attendance_total}' => number_format((int) ($period_totals['attendance_total'] ?? 0)),
        '{gross_total}' => number_format((float) ($period_totals['gross_total'] ?? 0), 2),
        '{period_label}' => $period_label,
        '{start_month}' => $month_key,
        '{end_month}' => $end_month_key,
      ];

      $subject_template = (string) Settings::get('advertiser_email_subject', '');
      $body_template = (string) Settings::get('advertiser_email_body', '');
      if ($period_label !== self::month_name($year, $month) . ' ' . $year && trim($subject_template) === 'Roxy advertiser summary for {month_name} {year}') {
        $subject_template = 'Roxy advertiser summary for {period_label}';
      }
      if ($period_label !== self::month_name($year, $month) . ' ' . $year && trim($body_template) === 'Attached is the advertiser summary workbook for {month_name} {year}.') {
        $body_template = 'Attached is the advertiser summary workbook for {period_label}.';
      }

      $subject = strtr($subject_template, $tokens);
      if ($is_test_send) {
        $subject = '[TEST] ' . $subject;
      }
      $body = strtr($body_template, $tokens);
      if ($is_test_send) {
        $body = "This is a test advertiser summary email sent only to the configured admin alert address.\n\n" . $body;
      }
      $body = str_replace('Please use the Advertiser Summary tab.', '', $body);
      $body = trim(preg_replace("/\n{3,}/", "\n\n", $body) ?? $body);
      $body .= "\n\nAdvertiser summary\n";
      foreach ($rows as $index => $row) {
        if ($index === 0) {
          continue;
        }

        $week_of = trim((string) ($row[1] ?? ''));
        $movie = trim((string) ($row[2] ?? ''));
        $attendance = trim((string) ($row[3] ?? ''));
        if ($week_of === '' && $movie === '' && $attendance === '') {
          continue;
        }

        if ($week_of === '' && $movie !== '') {
          $body .= $movie . ': ' . $attendance . "\n";
          continue;
        }

        $body .= sprintf(
          "Week %s | %s | %s | Attendance %s\n",
          (string) ($row[0] ?? ''),
          $week_of,
          $movie,
          $attendance
        );
      }
      if (stripos($body, 'Generated automatically by the Roxy Grosses plugin.') === false) {
        $body .= "\nGenerated automatically by the Roxy Grosses plugin.";
      }

      // Keep advertiser recipients private on production sends; tests go straight to the admin alert address.
      $mail_to = $to[0];
      $headers = ['Content-Type: text/plain; charset=UTF-8'];
      if (!$is_test_send) {
        foreach ($to as $bcc_email) {
          if ($bcc_email && is_email($bcc_email)) {
            $headers[] = 'Bcc: ' . $bcc_email;
          }
        }
      }

      $sent = wp_mail($mail_to, $subject, $body, $headers, [$workbook_path]);
      if (!$sent) {
        throw new \RuntimeException('WordPress could not send the advertiser summary email.');
      }

      $message = 'Advertiser summary sent to ' . implode(', ', $to) . ' for ' . $period_label . '.';
      Store::insert_log('send_advertiser_summary', $mode, null, $report_date, true, $message, [
        'month' => $month_key,
        'end_month' => $end_month_key,
        'period_label' => $period_label,
        'attendance_total' => (int) ($period_totals['attendance_total'] ?? 0),
        'gross_total' => (float) ($period_totals['gross_total'] ?? 0),
      ]);

      return ['success' => true, 'message' => $message];
    } catch (\Throwable $e) {
      Store::insert_log('send_advertiser_summary', $mode, null, $report_date, false, $e->getMessage(), [
        'month' => $month_key,
        'end_month' => $end_month_key,
        'period_label' => $period_label,
      ]);
      Reporter::notify_admin_failure('Advertiser summary email failed', $report_date, $mode, $e->getMessage());
      return ['success' => false, 'message' => $e->getMessage()];
    }
  }

  private static function test_email_list(): array {
    $admin_email = Settings::admin_email();
    if ($admin_email === '') {
      return [];
    }

    return [$admin_email];
  }

  public static function build_workbook_file(int $year): string {
    $weekly_rows = self::weekly_rows_for_year($year);
    if (count($weekly_rows) > 54) {
      throw new \RuntimeException('The workbook template only supports 54 weekly rows. Reduce the year scope or expand the template.');
    }

    $template = self::resolve_template_path($year);
    if (!is_readable($template)) {
      throw new \RuntimeException('Workbook template not found. Upload one on the Workbook tab or save a valid server path in settings.');
    }

    if (!class_exists('ZipArchive')) {
      throw new \RuntimeException('ZipArchive is required to build the workbook.');
    }

    $dir = self::private_workbooks_dir();
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
    return ($path !== '' && is_readable($path) && self::is_private_path($path)) ? $path : '';
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

  public static function template_status(): array {
    try {
      $uploaded = self::ensure_uploaded_template_private();
    } catch (\Throwable $e) {
      $uploaded = self::uploaded_template();
    }
    if (!empty($uploaded['path']) && is_readable((string) $uploaded['path'])) {
      return array_merge($uploaded, ['source' => 'upload', 'readable' => true]);
    }

    $fallback = str_replace('{year}', wp_date('Y'), (string) Settings::get('workbook_template_path', ''));
    return [
      'source' => 'settings-path',
      'path' => $fallback,
      'name' => basename($fallback),
      'uploaded_at' => '',
      'readable' => $fallback !== '' && is_readable($fallback),
    ];
  }

  public static function advertiser_rows_for_month(int $year, int $month): array {
    $month_key = sprintf('%04d-%02d', $year, $month);
    $month_name = self::month_name($year, $month);
    $weekly_rows = array_values(array_filter(self::weekly_rows_for_year($year), static function (array $row) use ($month_key): bool {
      return str_starts_with((string) ($row['week_of'] ?? ''), $month_key);
    }));

    $month_total = 0;
    foreach ($weekly_rows as $row) {
      $month_total += (int) ($row['admissions'] ?? 0);
    }

    $rows = [[
      'Week #',
      'Week Of',
      'Movie',
      'Total Attendance',
    ]];

    foreach ($weekly_rows as $row) {
      $rows[] = [
        self::week_number_for_start((string) ($row['week_of'] ?? '')),
        (string) ($row['week_of'] ?? ''),
        (string) ($row['film_title'] ?? ''),
        (int) ($row['admissions'] ?? 0),
      ];
    }

    if (count($rows) === 1) {
      $rows[] = ['', '', 'No attendance rows found for this month.', 0];
    } else {
      $rows[] = ['', '', '', ''];
      $rows[] = ['', '', $month_name . ' ' . $year . ' Total', $month_total];
    }

    return $rows;
  }

  public static function advertiser_rows_for_period(int $start_year, int $start_month, int $end_year, int $end_month): array {
    [$start_year, $start_month, $end_year, $end_month] = self::normalized_period($start_year, $start_month, $end_year, $end_month);
    if ($start_year === $end_year && $start_month === $end_month) {
      return self::advertiser_rows_for_month($start_year, $start_month);
    }

    $rows = [[
      'Week #',
      'Week Of',
      'Movie',
      'Total Attendance',
    ]];

    $cursor = new \DateTimeImmutable(sprintf('%04d-%02d-01 12:00:00', $start_year, $start_month));
    $end = new \DateTimeImmutable(sprintf('%04d-%02d-01 12:00:00', $end_year, $end_month));
    $overall_total = 0;

    while ($cursor <= $end) {
      $year = (int) $cursor->format('Y');
      $month = (int) $cursor->format('m');
      $month_rows = self::advertiser_rows_for_month($year, $month);

      foreach ($month_rows as $index => $row) {
        if ($index === 0) {
          continue;
        }

        $rows[] = $row;
        if (($row[0] ?? '') !== '' && is_numeric($row[3] ?? null)) {
          $overall_total += (int) ($row[3] ?? 0);
        }
      }

      $cursor = $cursor->modify('first day of next month');
    }

    if (count($rows) === 1) {
      $rows[] = ['', '', 'No attendance rows found for this period.', 0];
    } else {
      $rows[] = ['', '', '', ''];
      $rows[] = ['', '', self::period_label($start_year, $start_month, $end_year, $end_month) . ' Total', $overall_total];
    }

    return $rows;
  }

  private static function uploaded_template(): array {
    $settings = get_option(Settings::OPTION_KEY, []);
    if (!is_array($settings)) {
      $settings = [];
    }
    $template = $settings[self::TEMPLATE_KEY] ?? [];
    return is_array($template) ? $template : [];
  }

  private static function set_uploaded_template(array $template): void {
    $settings = get_option(Settings::OPTION_KEY, []);
    if (!is_array($settings)) {
      $settings = [];
    }
    $settings[self::TEMPLATE_KEY] = [
      'path' => sanitize_text_field((string) ($template['path'] ?? '')),
      'name' => sanitize_file_name((string) ($template['name'] ?? '')),
      'uploaded_at' => sanitize_text_field((string) ($template['uploaded_at'] ?? '')),
    ];
    update_option(Settings::OPTION_KEY, $settings);
  }

  private static function resolve_template_path(int $year): string {
    $uploaded = self::ensure_uploaded_template_private();
    $uploaded_path = (string) ($uploaded['path'] ?? '');
    if ($uploaded_path !== '' && is_readable($uploaded_path)) {
      return $uploaded_path;
    }

    $template = (string) Settings::get('workbook_template_path', '');
    if ($template === '') {
      throw new \RuntimeException('No workbook template is configured.');
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

  private static function month_name(int $year, int $month): string {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    return (new \DateTimeImmutable(sprintf('%04d-%02d-15 12:00:00', $year, $month), $timezone))->format('F');
  }

  private static function normalized_week_start_for_year(string $date, int $year): string {
    $week_start = self::week_start_for_date($date);
    if ((int) substr($week_start, 0, 4) < $year && (int) substr($date, 0, 4) === $year) {
      return sprintf('%04d-01-01', $year);
    }

    return $week_start;
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
    $first = new \DateTimeImmutable(self::week_start_for_date($year . '-01-01') . ' 00:00:00', $timezone);
    if ((int) $first->format('Y') < $year) {
      $first = $first->modify('+7 day');
    }

    $days = (int) $first->diff($start)->days;
    return max(1, (int) floor($days / 7) + 1);
  }

  private static function advertiser_totals_for_period(int $start_year, int $start_month, int $end_year, int $end_month): array {
    [$start_year, $start_month, $end_year, $end_month] = self::normalized_period($start_year, $start_month, $end_year, $end_month);
    $attendance_total = 0;
    $gross_total = 0.0;
    $cursor = new \DateTimeImmutable(sprintf('%04d-%02d-01 12:00:00', $start_year, $start_month));
    $end = new \DateTimeImmutable(sprintf('%04d-%02d-01 12:00:00', $end_year, $end_month));

    while ($cursor <= $end) {
      $month_total = self::monthly_total_for_month((int) $cursor->format('Y'), (int) $cursor->format('m'));
      $attendance_total += (int) ($month_total['admissions'] ?? 0);
      $gross_total += (float) ($month_total['gross'] ?? 0);
      $cursor = $cursor->modify('first day of next month');
    }

    return [
      'attendance_total' => $attendance_total,
      'gross_total' => round($gross_total, 2),
      'month_name' => self::period_label($start_year, $start_month, $end_year, $end_month),
    ];
  }

  private static function monthly_total_for_month(int $year, int $month): array {
    foreach (self::monthly_totals($year) as $monthly_row) {
      if ((string) ($monthly_row['month_key'] ?? '') === sprintf('%04d-%02d', $year, $month)) {
        return $monthly_row;
      }
    }

    return [
      'month_key' => sprintf('%04d-%02d', $year, $month),
      'month_name' => self::month_name($year, $month),
      'admissions' => 0,
      'gross' => 0.0,
    ];
  }

  private static function normalized_period(int $start_year, int $start_month, int $end_year, int $end_month): array {
    $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 12:00:00', $start_year, max(1, min(12, $start_month))));
    $end = new \DateTimeImmutable(sprintf('%04d-%02d-01 12:00:00', $end_year, max(1, min(12, $end_month))));
    if ($start > $end) {
      [$start, $end] = [$end, $start];
    }

    return [
      (int) $start->format('Y'),
      (int) $start->format('m'),
      (int) $end->format('Y'),
      (int) $end->format('m'),
    ];
  }

  private static function period_label(int $start_year, int $start_month, int $end_year, int $end_month): string {
    [$start_year, $start_month, $end_year, $end_month] = self::normalized_period($start_year, $start_month, $end_year, $end_month);
    $start_label = self::month_name($start_year, $start_month) . ' ' . $start_year;
    $end_label = self::month_name($end_year, $end_month) . ' ' . $end_year;

    return $start_label === $end_label ? $start_label : $start_label . ' to ' . $end_label;
  }

  private static function build_advertiser_workbook_file(int $start_year, int $start_month, int $end_year, int $end_month, array $rows): string {
    $dir = self::private_advertiser_dir();
    $range_suffix = sprintf('%04d-%02d', $start_year, $start_month);
    if ($start_year !== $end_year || $start_month !== $end_month) {
      $range_suffix .= '-to-' . sprintf('%04d-%02d', $end_year, $end_month);
    }
    $path = trailingslashit($dir) . 'advertiser-summary-' . $range_suffix . '.xlsx';
    self::write_simple_xlsx($path, 'Advertiser Summary', $rows);
    return $path;
  }

  private static function write_simple_xlsx(string $path, string $sheet_name, array $rows): void {
    if (!class_exists('ZipArchive')) {
      throw new \RuntimeException('ZipArchive is required to build the advertiser workbook.');
    }

    $zip = new \ZipArchive();
    if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
      throw new \RuntimeException('Unable to create the advertiser workbook file.');
    }

    $row_count = max(1, count($rows));
    $col_count = 1;
    foreach ($rows as $row) {
      $col_count = max($col_count, count((array) $row));
    }
    $dimension = self::cell_ref($col_count, $row_count);

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::xml($sheet_name) . '</dc:title><dc:creator>Roxy Grosses</dc:creator><cp:lastModifiedBy>Roxy Grosses</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Roxy Grosses</Application><Sheets>1</Sheets></Properties>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . self::xml($sheet_name) . '" sheetId="1" r:id="rId1"/></sheets><calcPr calcMode="auto" fullCalcOnLoad="1" forceFullCalc="1"/></workbook>');
    $zip->addFromString('xl/worksheets/sheet1.xml', self::simple_sheet_xml($rows, $dimension));
    $zip->close();
  }

  private static function simple_sheet_xml(array $rows, string $dimension): string {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:' . self::xml($dimension) . '"/><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/><sheetData>';
    foreach ($rows as $row_index => $row) {
      $r = $row_index + 1;
      $xml .= '<row r="' . $r . '">';
      foreach ((array) $row as $col_index => $value) {
        $cell_ref = self::cell_ref($col_index + 1, $r);
        if (is_numeric($value) && $value !== '') {
          $xml .= '<c r="' . $cell_ref . '"><v>' . self::xml((string) $value) . '</v></c>';
        } else {
          $xml .= '<c r="' . $cell_ref . '" t="inlineStr"><is><t>' . self::xml((string) $value) . '</t></is></c>';
        }
      }
      $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
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

  private static function redirect_with_notice(string $status, string $message, ?int $year = null, string $tab = 'workbook'): void {
    $url = add_query_arg([
      'page' => 'roxy-grosses',
      'tab' => $tab,
      'workbook_year' => $year ?: null,
      'roxy_grosses_notice' => $status,
      'message' => $message,
    ], admin_url('admin.php'));

    wp_safe_redirect($url);
    exit;
  }

  private static function private_root_dir(): string {
    $dir = trailingslashit(WP_CONTENT_DIR) . self::PRIVATE_ROOT_DIR;
    self::ensure_private_directory($dir);
    return $dir;
  }

  private static function private_templates_dir(): string {
    $dir = trailingslashit(self::private_root_dir()) . 'templates';
    self::ensure_private_directory($dir);
    return $dir;
  }

  private static function private_workbooks_dir(): string {
    $dir = trailingslashit(self::private_root_dir()) . 'workbooks';
    self::ensure_private_directory($dir);
    return $dir;
  }

  private static function private_advertiser_dir(): string {
    $dir = trailingslashit(self::private_root_dir()) . 'advertiser';
    self::ensure_private_directory($dir);
    return $dir;
  }

  private static function ensure_private_directory(string $dir): void {
    if (!is_dir($dir)) {
      wp_mkdir_p($dir);
    }

    if (!is_dir($dir)) {
      throw new \RuntimeException('Could not create the private grosses workbook directory.');
    }

    self::protect_directory($dir);
  }

  private static function protect_directory(string $dir): void {
    $index = trailingslashit($dir) . 'index.html';
    if (!file_exists($index)) {
      file_put_contents($index, '');
    }

    $htaccess = trailingslashit($dir) . '.htaccess';
    if (!file_exists($htaccess)) {
      file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
  }

  private static function move_file_to_private_storage(string $source_path, string $target_dir, string $preferred_name): string {
    $source_path = trim($source_path);
    if ($source_path === '' || !is_readable($source_path)) {
      throw new \RuntimeException('The uploaded workbook template could not be read.');
    }

    self::ensure_private_directory($target_dir);
    $filename = wp_unique_filename($target_dir, $preferred_name !== '' ? $preferred_name : basename($source_path));
    $target_path = trailingslashit($target_dir) . $filename;

    $moved = @rename($source_path, $target_path);
    if (!$moved) {
      $moved = @copy($source_path, $target_path);
      if ($moved) {
        @unlink($source_path);
      }
    }

    if (!$moved || !is_readable($target_path)) {
      throw new \RuntimeException('The workbook file could not be moved into private storage.');
    }

    return $target_path;
  }

  private static function ensure_uploaded_template_private(): array {
    $uploaded = self::uploaded_template();
    $path = trim((string) ($uploaded['path'] ?? ''));
    if ($path === '' || !is_readable($path) || self::is_private_path($path)) {
      return $uploaded;
    }

    $migrated_path = self::move_file_to_private_storage(
      $path,
      self::private_templates_dir(),
      sanitize_file_name((string) ($uploaded['name'] ?? basename($path)))
    );

    $uploaded['path'] = $migrated_path;
    if (empty($uploaded['name'])) {
      $uploaded['name'] = basename($migrated_path);
    }
    self::set_uploaded_template($uploaded);

    return $uploaded;
  }

  private static function is_private_path(string $path): bool {
    $normalized_path = wp_normalize_path($path);
    $private_root = wp_normalize_path(self::private_root_dir());
    return $normalized_path !== '' && str_starts_with($normalized_path, $private_root);
  }

  private static function cell_ref(int $column, int $row): string {
    $letters = '';
    while ($column > 0) {
      $column--;
      $letters = chr(65 + ($column % 26)) . $letters;
      $column = (int) floor($column / 26);
    }

    return $letters . $row;
  }

  private static function xml(string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  }
}
