<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Reporter {
  public static function init(): void {
    add_action('admin_post_roxy_grosses_send_manual', [__CLASS__, 'handle_manual_send']);
    add_action('admin_post_roxy_grosses_pull_report', [__CLASS__, 'handle_pull_report']);
    add_action('admin_post_roxy_grosses_send_saved_report', [__CLASS__, 'handle_send_saved_report']);
  }

  public static function handle_manual_send(): void {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to send grosses reports.');
    }

    check_admin_referer('roxy_grosses_send_manual');

    $report_date = isset($_POST['report_date']) ? sanitize_text_field(wp_unslash((string) $_POST['report_date'])) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
      self::redirect_with_notice('error', 'Choose a valid report date in YYYY-MM-DD format.');
    }

    $result = self::send_report($report_date, 'manual');
    self::redirect_with_notice($result['success'] ? 'success' : 'error', $result['message']);
  }

  public static function handle_pull_report(): void {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to pull grosses reports.');
    }

    check_admin_referer('roxy_grosses_pull_report');

    $report_date = isset($_POST['report_date']) ? sanitize_text_field(wp_unslash((string) $_POST['report_date'])) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
      self::redirect_with_notice('error', 'Choose a valid report date in YYYY-MM-DD format.', 'reports');
    }

    $result = self::save_report_draft($report_date, 'review');
    $extra = [];
    if (!empty($result['report_id'])) {
      $extra['report_id'] = (int) $result['report_id'];
    }
    self::redirect_with_notice($result['success'] ? 'success' : 'error', $result['message'], 'reports', $extra);
  }

  public static function handle_send_saved_report(): void {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to send grosses reports.');
    }

    check_admin_referer('roxy_grosses_send_saved_report');

    $report_id = isset($_POST['report_id']) ? max(0, (int) $_POST['report_id']) : 0;
    if ($report_id <= 0) {
      self::redirect_with_notice('error', 'Missing saved report ID.', 'reports');
    }

    $result = self::send_saved_report($report_id);
    self::redirect_with_notice($result['success'] ? 'success' : 'error', $result['message'], 'reports', ['report_id' => $report_id]);
  }

  public static function send_report(string $report_date, string $mode = 'scheduled'): array {
    try {
      $reports = self::build_reports($report_date);
      $summary = self::summarize_reports($reports);
      if ((int) ($summary['total_tickets'] ?? 0) <= 0) {
        throw new \RuntimeException('No matching Square ticket sales were found for that report date or its configured lookback window.');
      }

      Store::upsert_history_rows($reports, $mode, null);

      $send = self::send_email($reports, $summary);
      if (!$send['success']) {
        throw new \RuntimeException($send['message']);
      }

      $report_id = Store::create_report($report_date, max(0, (int) Settings::get('lookback_days', '0')), $mode, 'emailed', $summary, $reports);
      if ($report_id > 0) {
        Store::upsert_history_rows($reports, $mode, $report_id);
      }

      $message = $send['message'];
      if ($report_id > 0) {
        $message .= ' Saved as report #' . $report_id . '.';
      }
      Store::insert_log('send_report', $mode, $report_id > 0 ? $report_id : null, $report_date, true, $message, [
        'row_count' => count($reports),
        'gross_total' => (float) ($summary['gross_total'] ?? 0),
      ]);
      Settings::set_status([
        'sent_at' => wp_date('Y-m-d H:i:s', null, new \DateTimeZone(Settings::get_report_timezone())),
        'report_date' => $report_date,
        'mode' => $mode,
        'message' => $message,
        'row_count' => count($reports),
        'gross_total' => (float) ($summary['gross_total'] ?? 0),
      ]);

      return [
        'success' => true,
        'message' => $message,
        'rows' => $reports,
        'summary' => $summary,
        'report_id' => $report_id,
      ];
    } catch (\Throwable $e) {
      Store::insert_log('send_report', $mode, null, $report_date, false, $e->getMessage());
      self::notify_admin_failure('Grosses report failed', $report_date, $mode, $e->getMessage());
      Settings::set_status([
        'sent_at' => wp_date('Y-m-d H:i:s', null, new \DateTimeZone(Settings::get_report_timezone())),
        'report_date' => $report_date,
        'mode' => $mode,
        'message' => $e->getMessage(),
        'row_count' => 0,
        'gross_total' => 0,
      ]);

      return [
        'success' => false,
        'message' => $e->getMessage(),
        'rows' => [],
        'summary' => ['gross_total' => 0, 'ticket_total' => 0],
      ];
    }
  }

  public static function save_report_draft(string $report_date, string $mode = 'review'): array {
    try {
      $reports = self::build_reports($report_date);
      $summary = self::summarize_reports($reports);
      if ((int) ($summary['total_tickets'] ?? 0) <= 0) {
        throw new \RuntimeException('No matching Square ticket sales were found for that report date or its configured lookback window.');
      }

      $report_id = Store::create_report($report_date, max(0, (int) Settings::get('lookback_days', '0')), $mode, 'draft', $summary, $reports);
      if ($report_id <= 0) {
        throw new \RuntimeException('Could not save the draft report.');
      }

      Store::upsert_history_rows($reports, $mode, $report_id);

      Settings::set_status([
        'sent_at' => '',
        'report_date' => $report_date,
        'mode' => $mode,
        'message' => 'Draft report #' . $report_id . ' saved. No email has been sent yet.',
        'row_count' => count($reports),
        'gross_total' => (float) ($summary['gross_total'] ?? 0),
      ]);
      Store::insert_log('save_draft', $mode, $report_id, $report_date, true, 'Draft report #' . $report_id . ' saved. No email has been sent yet.', [
        'row_count' => count($reports),
        'gross_total' => (float) ($summary['gross_total'] ?? 0),
      ]);

      return [
        'success' => true,
        'message' => 'Draft report #' . $report_id . ' saved.',
        'report_id' => $report_id,
        'rows' => $reports,
        'summary' => $summary,
      ];
    } catch (\Throwable $e) {
      Store::insert_log('save_draft', $mode, null, $report_date, false, $e->getMessage());
      self::notify_admin_failure('Grosses data pull failed', $report_date, $mode, $e->getMessage());
      return [
        'success' => false,
        'message' => $e->getMessage(),
      ];
    }
  }

  public static function send_saved_report(int $report_id): array {
    $saved = Store::get_report($report_id);
    if (!$saved) {
      Store::insert_log('send_saved_report', 'saved-report', $report_id, null, false, 'Saved report not found.');
      self::notify_admin_failure('Saved grosses report failed', '', 'saved-report', 'Saved report #' . $report_id . ' was not found.');
      return [
        'success' => false,
        'message' => 'Saved report not found.',
      ];
    }

    $summary = is_array($saved['summary'] ?? null) ? $saved['summary'] : [];
    $rows = is_array($saved['rows'] ?? null) ? $saved['rows'] : [];
    if (!$rows) {
      Store::insert_log('send_saved_report', 'saved-report', $report_id, (string) ($saved['report_end_date'] ?? ''), false, 'Saved report has no rows to email.');
      self::notify_admin_failure('Saved grosses report failed', (string) ($saved['report_end_date'] ?? ''), 'saved-report', 'Saved report #' . $report_id . ' has no rows to email.');
      return [
        'success' => false,
        'message' => 'Saved report has no rows to email.',
      ];
    }

    Store::upsert_history_rows($rows, 'saved-report', $report_id);

    $send = self::send_email($rows, $summary);
    if (!$send['success']) {
      Store::insert_log('send_saved_report', 'saved-report', $report_id, (string) ($saved['report_end_date'] ?? ''), false, $send['message']);
      self::notify_admin_failure('Saved grosses report failed', (string) ($saved['report_end_date'] ?? ''), 'saved-report', $send['message']);
      return $send;
    }

    Store::mark_emailed($report_id);

    $message = 'Saved report #' . $report_id . ' emailed to ' . implode(', ', Settings::email_list()) . '.';
    Store::insert_log('send_saved_report', 'saved-report', $report_id, (string) ($saved['report_end_date'] ?? ''), true, $message, [
      'row_count' => count($rows),
      'gross_total' => (float) ($summary['gross_total'] ?? 0),
    ]);
    Settings::set_status([
      'sent_at' => wp_date('Y-m-d H:i:s', null, new \DateTimeZone(Settings::get_report_timezone())),
      'report_date' => (string) ($saved['report_end_date'] ?? ''),
      'mode' => 'saved-report',
      'message' => $message,
      'row_count' => count($rows),
      'gross_total' => (float) ($summary['gross_total'] ?? 0),
    ]);

    return [
      'success' => true,
      'message' => $message,
    ];
  }

  public static function build_reports(string $report_date): array {
    $reports = [];
    $showings_by_date = self::related_showings_by_date($report_date);

    foreach ($showings_by_date as $date => $showings) {
      foreach (self::build_reports_for_date_showings((string) $date, (array) $showings) as $report) {
        $reports[] = $report;
      }
    }

    usort($reports, static function (array $a, array $b): int {
      $left = ((string) ($a['report_date'] ?? '')) . ' ' . ((string) ($a['show_time'] ?? ''));
      $right = ((string) ($b['report_date'] ?? '')) . ' ' . ((string) ($b['show_time'] ?? ''));
      return strcmp($left, $right);
    });

    return array_values(array_filter($reports, static function (array $report): bool {
      return (int) ($report['total_tickets'] ?? 0) > 0;
    }));
  }

  private static function build_reports_for_date_showings(string $report_date, array $showings): array {
    $orders = Square::fetch_orders_for_date($report_date);
    $prices = self::ticket_prices();
    $reports = [];

    foreach ($showings as $showing) {
      $reports[(int) $showing['id']] = [
        'report_date' => $report_date,
        'theater_name' => (string) Settings::get('theater_name', 'Newport Roxy Theater'),
        'showing_id' => (int) $showing['id'],
        'film_title' => (string) $showing['title'],
        'show_time' => (string) $showing['time_label'],
        'general_qty' => 0,
        'general_gross' => 0.0,
        'discount_qty' => 0,
        'discount_gross' => 0.0,
        'group_qty' => 0,
        'group_gross' => 0.0,
        'total_tickets' => 0,
        'gross_total' => 0.0,
      ];
    }

    foreach ($orders as $order) {
      $order_closed_at = self::order_closed_at($order);
      if (!$order_closed_at) {
        continue;
      }

      foreach ((array) ($order['line_items'] ?? []) as $line_item) {
        $category = self::classify_ticket_variation($line_item);
        if ($category === '') {
          continue;
        }

        $qty = isset($line_item['quantity']) ? (int) round((float) $line_item['quantity']) : 0;
        if ($qty <= 0) {
          continue;
        }

        $showing_id = self::matching_showing_id_for_order_time($order_closed_at, $showings);
        if ($showing_id <= 0 || !isset($reports[$showing_id])) {
          continue;
        }

        $gross = round($qty * (float) ($prices[$category] ?? 0), 2);
        $reports[$showing_id][$category . '_qty'] += $qty;
        $reports[$showing_id][$category . '_gross'] += $gross;
        $reports[$showing_id]['total_tickets'] += $qty;
        $reports[$showing_id]['gross_total'] += $gross;
      }
    }

    foreach ($reports as &$report) {
      $report['general_gross'] = round((float) $report['general_gross'], 2);
      $report['discount_gross'] = round((float) $report['discount_gross'], 2);
      $report['group_gross'] = round((float) $report['group_gross'], 2);
      $report['gross_total'] = round((float) $report['gross_total'], 2);
    }
    unset($report);

    return array_values($reports);
  }

  private static function related_showings_by_date(string $report_date): array {
    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $target = new \DateTimeImmutable($report_date . ' 00:00:00', $timezone);
    $lookback_days = max(0, (int) Settings::get('lookback_days', '2'));
    $target_showings = self::showings_for_date($report_date);
    if (!$target_showings) {
      return [];
    }

    $target_titles = [];
    foreach ($target_showings as $showing) {
      $target_titles[(string) $showing['title']] = true;
    }

    $showings_by_date = [
      $target->format('Y-m-d') => $target_showings,
    ];

    for ($offset = 1; $offset <= $lookback_days; $offset++) {
      $candidate = $target->modify('-' . $offset . ' day');
      $candidate_date = $candidate->format('Y-m-d');
      $candidate_showings = array_values(array_filter(self::showings_for_date($candidate_date), static function (array $showing) use ($target_titles): bool {
        return isset($target_titles[(string) ($showing['title'] ?? '')]);
      }));
      if ($candidate_showings) {
        $showings_by_date[$candidate_date] = $candidate_showings;
      }
    }

    ksort($showings_by_date);
    return $showings_by_date;
  }

  private static function classify_ticket_variation(array $line_item): string {
    $variation = strtolower(trim((string) ($line_item['variation_name'] ?? '')));
    $name = strtolower(trim((string) ($line_item['name'] ?? '')));
    $value = $variation !== '' ? $variation : $name;

    if ($value === 'general' || $value === 'prepaid - general') {
      return 'general';
    }

    if ($value === 'discount' || $value === 'prepaid - discount') {
      return 'discount';
    }

    if ($value === 'group' || $value === 'subscriber') {
      return 'group';
    }

    return '';
  }

  private static function showings_for_date(string $report_date): array {
    if (!post_type_exists('roxy_showing')) {
      return [];
    }

    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    $start = new \DateTimeImmutable($report_date . ' 00:00:00', $timezone);
    $end = $start->modify('+1 day');

    $posts = get_posts([
      'post_type' => 'roxy_showing',
      'post_status' => ['publish', 'private', 'future', 'draft'],
      'posts_per_page' => -1,
      'orderby' => 'meta_value',
      'order' => 'ASC',
      'meta_key' => '_roxy_start',
      'meta_query' => [[
        'key' => '_roxy_start',
        'value' => [$start->format('Y-m-d\TH:i'), $end->format('Y-m-d\TH:i')],
        'compare' => 'BETWEEN',
        'type' => 'CHAR',
      ]],
      'fields' => 'ids',
      'no_found_rows' => true,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
    ]);

    $showings = [];
    foreach ((array) $posts as $post_id) {
      $start_raw = (string) get_post_meta((int) $post_id, '_roxy_start', true);
      if ($start_raw === '') {
        continue;
      }

      try {
        $start_at = new \DateTimeImmutable($start_raw, $timezone);
      } catch (\Throwable $e) {
        continue;
      }

      $showings[] = [
        'id' => (int) $post_id,
        'title' => trim((string) get_the_title((int) $post_id)) ?: 'Unknown Film',
        'start_at' => $start_at,
        'time_label' => $start_at->format('g:i A'),
      ];
    }

    return $showings;
  }

  private static function order_closed_at(array $order): ?\DateTimeImmutable {
    $raw = (string) ($order['closed_at'] ?? $order['updated_at'] ?? '');
    if ($raw === '') {
      return null;
    }

    try {
      return new \DateTimeImmutable($raw);
    } catch (\Throwable $e) {
      return null;
    }
  }

  private static function matching_showing_id_for_order_time(\DateTimeImmutable $order_closed_at, array $showings): int {
    $best_id = 0;
    $best_distance = null;

    foreach ($showings as $showing) {
      if (empty($showing['start_at']) || !($showing['start_at'] instanceof \DateTimeImmutable)) {
        continue;
      }

      $start_at = $showing['start_at'];
      $window_start = $start_at->modify('-90 minutes');
      $window_end = $start_at->modify('+90 minutes');
      $order_local = $order_closed_at->setTimezone($start_at->getTimezone());

      if ($order_local < $window_start || $order_local > $window_end) {
        continue;
      }

      $distance = abs($order_local->getTimestamp() - $start_at->getTimestamp());
      if ($best_distance === null || $distance < $best_distance) {
        $best_distance = $distance;
        $best_id = (int) ($showing['id'] ?? 0);
      }
    }

    return $best_id;
  }

  private static function ticket_prices(): array {
    return [
      'general' => (float) Settings::get('general_price', '12'),
      'discount' => (float) Settings::get('discount_price', '8'),
      'group' => (float) Settings::get('group_price', '5'),
    ];
  }

  private static function summarize_reports(array $reports): array {
    $summary = [
      'report_date' => $reports ? (string) $reports[count($reports) - 1]['report_date'] : '',
      'gross_total' => 0.0,
      'total_tickets' => 0,
    ];

    foreach ($reports as $report) {
      $summary['gross_total'] += (float) ($report['gross_total'] ?? 0);
      $summary['total_tickets'] += (int) ($report['total_tickets'] ?? 0);
    }

    $summary['gross_total'] = round($summary['gross_total'], 2);
    return $summary;
  }

  private static function send_email(array $reports, array $summary): array {
    $attachment = self::write_csv($reports);
    $to = Settings::email_list();
    if (!$to) {
      @unlink($attachment);
      return [
        'success' => false,
        'message' => 'No recipient emails are configured.',
      ];
    }

    $subject = self::expand_tokens((string) Settings::get('email_subject', ''), $summary);
    $body = self::expand_tokens((string) Settings::get('email_body', ''), $summary);
    $body .= "\n\nReport rows\n";
    foreach ($reports as $report) {
      $body .= sprintf(
        "%s %s | %s | General %d | Discount %d | Group %d | Total %d | Gross $%s\n",
        $report['report_date'],
        $report['show_time'],
        $report['film_title'],
        (int) $report['general_qty'],
        (int) $report['discount_qty'],
        (int) $report['group_qty'],
        (int) $report['total_tickets'],
        number_format((float) $report['gross_total'], 2)
      );
    }

    $sent = wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8'], [$attachment]);
    @unlink($attachment);

    if (!$sent) {
      return [
        'success' => false,
        'message' => 'WordPress could not send the grosses email.',
      ];
    }

    return [
      'success' => true,
      'message' => 'Report sent to ' . implode(', ', $to) . '.',
    ];
  }

  private static function expand_tokens(string $template, array $summary): string {
    return strtr($template, [
      '{report_date}' => (string) ($summary['report_date'] ?? ''),
      '{theater_name}' => (string) Settings::get('theater_name', 'Newport Roxy Theater'),
      '{gross_total}' => number_format((float) ($summary['gross_total'] ?? 0), 2),
      '{ticket_total}' => number_format((int) ($summary['total_tickets'] ?? 0)),
    ]);
  }

  private static function write_csv(array $reports): string {
    $upload_dir = wp_upload_dir();
    $dir = trailingslashit($upload_dir['basedir']) . 'roxy-grosses';
    wp_mkdir_p($dir);

    $latest_date = $reports ? (string) $reports[count($reports) - 1]['report_date'] : wp_date('Y-m-d');
    $path = trailingslashit($dir) . 'grosses-' . $latest_date . '.csv';
    $handle = fopen($path, 'w');
    if (!$handle) {
      throw new \RuntimeException('Unable to create the grosses CSV attachment.');
    }

    fputcsv($handle, ['Report Date', 'Show Time', 'Theater', 'Film Title', 'General', 'Discount', 'Group', 'Total Tickets', 'Gross']);
    foreach ($reports as $report) {
      fputcsv($handle, [
        $report['report_date'],
        $report['show_time'],
        $report['theater_name'],
        $report['film_title'],
        $report['general_qty'],
        $report['discount_qty'],
        $report['group_qty'],
        $report['total_tickets'],
        number_format((float) $report['gross_total'], 2, '.', ''),
      ]);
    }

    fclose($handle);
    return $path;
  }

  private static function redirect_with_notice(string $status, string $message, string $tab = 'reports', array $extra = []): void {
    $args = array_merge([
      'page' => 'roxy-grosses',
      'tab' => $tab,
      'roxy_grosses_notice' => $status,
      'message' => $message,
    ], $extra);
    $url = add_query_arg($args, admin_url('admin.php'));

    wp_safe_redirect($url);
    exit;
  }

  public static function notify_admin_failure(string $subject, string $report_date, string $mode, string $message): void {
    $to = sanitize_email((string) Settings::get('admin_email', get_option('admin_email')));
    if ($to === '') {
      return;
    }

    $body = "The Roxy Grosses plugin encountered a failure.\n\n"
      . "Mode: " . $mode . "\n"
      . "Report date: " . ($report_date !== '' ? $report_date : 'n/a') . "\n"
      . "Time: " . wp_date('Y-m-d H:i:s', null, new \DateTimeZone(Settings::get_report_timezone())) . "\n\n"
      . "Error:\n" . $message . "\n";

    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
  }
}
