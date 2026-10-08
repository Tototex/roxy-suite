<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Reporter {
  private static array $refund_review_dates = [];
  private const REFUND_SCAN_DATE = 'roxy_grosses_refund_scan_date';
  private const REFUND_PENDING_FROM = 'roxy_grosses_refund_pending_from';
  public static function init(): void {
    add_action('admin_post_roxy_grosses_send_manual', [__CLASS__, 'handle_manual_send']);
    add_action('admin_post_roxy_grosses_pull_database', [__CLASS__, 'handle_pull_database']);
    add_action('admin_post_roxy_grosses_pull_live_database', [__CLASS__, 'handle_pull_live_database']);
    add_action('admin_post_roxy_grosses_pull_report', [__CLASS__, 'handle_pull_report']);
    add_action('admin_post_roxy_grosses_send_saved_report', [__CLASS__, 'handle_send_saved_report']);
    add_action('admin_post_roxy_grosses_export_csv', [__CLASS__, 'handle_export_csv']);
    add_action('admin_post_roxy_grosses_update_row', [__CLASS__, 'handle_update_row']);
    add_action('admin_post_roxy_grosses_run_now', [__CLASS__, 'handle_run_now']);
    add_action('admin_post_roxy_grosses_send_live_email', [__CLASS__, 'handle_send_live_email']);
  }

  public static function handle_pull_database(): void {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('You do not have permission to pull grosses data.');
    }

    check_admin_referer('roxy_grosses_pull_database');

    $report_date = isset($_POST['report_date']) ? sanitize_text_field(wp_unslash((string) $_POST['report_date'])) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
      self::redirect_with_notice('error', 'Choose a valid report date in YYYY-MM-DD format.', 'database');
    }

    $result = self::pull_into_database($report_date, 'manual-pull');
    self::redirect_with_notice($result['success'] ? 'success' : 'error', $result['message'], 'database');
  }

  public static function handle_pull_live_database(): void {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('You do not have permission to pull live grosses data.');
    }

    check_admin_referer('roxy_grosses_pull_live_database');

    $report_date = isset($_POST['report_date']) ? sanitize_text_field(wp_unslash((string) $_POST['report_date'])) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
      self::redirect_with_notice('error', 'Choose a valid report date in YYYY-MM-DD format.', 'live-shows');
    }

    $result = self::pull_live_into_database($report_date, 'manual-live-pull');
    self::redirect_with_notice($result['success'] ? 'success' : 'error', $result['message'], 'live-shows');
  }

  public static function handle_manual_send(): void {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('You do not have permission to send grosses reports.');
    }

    check_admin_referer('roxy_grosses_send_manual');

    $report_date = isset($_POST['report_date']) ? sanitize_text_field(wp_unslash((string) $_POST['report_date'])) : '';
    $return_tab = isset($_POST['return_tab']) ? sanitize_key((string) wp_unslash($_POST['return_tab'])) : 'database';
    if (!in_array($return_tab, ['database', 'settings', 'logs', 'legacy-weekly'], true)) {
      $return_tab = 'database';
    }
    $mode = !empty($_POST['test_send']) ? 'manual-test' : 'manual';
    $send_request_id = isset($_POST['send_request_id']) ? sanitize_text_field(wp_unslash((string) $_POST['send_request_id'])) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
      self::redirect_with_notice('error', 'Choose a valid report date in YYYY-MM-DD format.', $return_tab);
    }

    $result = self::send_report($report_date, $mode, $send_request_id);
    self::redirect_with_notice($result['success'] ? 'success' : 'error', $result['message'], $return_tab);
  }

  public static function handle_send_live_email(): void {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('You do not have permission to send live grosses emails.');
    }

    check_admin_referer('roxy_grosses_send_live_email');

    $entry_id = isset($_POST['live_entry_id']) ? max(0, (int) $_POST['live_entry_id']) : 0;
    $mode = !empty($_POST['test_send']) ? 'manual-live-test' : 'manual-live-email';
    $recipients = $mode === 'manual-live-test' ? self::test_email_list() : Settings::live_email_list();
    $include_concessions = !empty($_POST['include_concessions']);
    $send_request_id = isset($_POST['send_request_id']) ? sanitize_text_field(wp_unslash((string) $_POST['send_request_id'])) : '';

    if ($entry_id <= 0) {
      self::redirect_with_notice('error', 'Choose a live show to email.', 'settings');
    }
    if (!$recipients) {
      self::redirect_with_notice('error', $mode === 'manual-live-test' ? 'No admin alert email is configured for live grosses tests.' : 'No live grosses recipient emails are configured.', 'settings');
    }

    $row = Store::get_live_entry($entry_id);
    if (!$row) {
      self::redirect_with_notice('error', 'Could not find that live show row.', 'settings');
    }

    $result = self::send_live_grosses_email($row, $recipients, $include_concessions, $mode, $send_request_id);
    self::redirect_with_notice($result['success'] ? 'success' : 'error', $result['message'], 'settings');
  }

  public static function handle_pull_report(): void {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('You do not have permission to pull grosses reports.');
    }

    check_admin_referer('roxy_grosses_pull_report');

    $report_date = isset($_POST['report_date']) ? sanitize_text_field(wp_unslash((string) $_POST['report_date'])) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
      self::redirect_with_notice('error', 'Choose a valid report date in YYYY-MM-DD format.', 'database');
    }

    $result = self::save_report_draft($report_date, 'review');
    $extra = [];
    if (!empty($result['report_id'])) {
      $extra['report_id'] = (int) $result['report_id'];
    }
    self::redirect_with_notice($result['success'] ? 'success' : 'error', $result['message'], 'database', $extra);
  }

  public static function handle_send_saved_report(): void {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('You do not have permission to send grosses reports.');
    }

    check_admin_referer('roxy_grosses_send_saved_report');

    $report_id = isset($_POST['report_id']) ? max(0, (int) $_POST['report_id']) : 0;
    if ($report_id <= 0) {
      self::redirect_with_notice('error', 'Missing saved report ID.', 'database');
    }

    $intentional_resend = !empty($_POST['intentional_resend']);
    $send_request_id = isset($_POST['send_request_id']) ? sanitize_text_field(wp_unslash((string) $_POST['send_request_id'])) : '';
    if ($intentional_resend && !self::valid_send_request_id($send_request_id)) {
      wp_die('A deliberate resend needs a fresh request identifier. Reopen the report and try again.');
    }
    $result = self::send_saved_report($report_id, $send_request_id, $intentional_resend);
    self::redirect_with_notice($result['success'] ? 'success' : 'error', $result['message'], 'database', ['report_id' => $report_id]);
  }

  public static function handle_export_csv(): void {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('You do not have permission to export grosses data.');
    }

    check_admin_referer('roxy_grosses_export_csv');

    $dataset = isset($_REQUEST['dataset']) ? sanitize_key((string) wp_unslash($_REQUEST['dataset'])) : 'movies';
    $filters = self::filters_for_dataset($dataset, $_REQUEST);

    switch ($dataset) {
      case 'live':
        $rows = Store::iterate_dataset('live', $filters);
        $header = ['Date', 'Show', 'Show Time', 'Total', 'Presale Tickets', 'Online Ticket', 'Door Ticket', 'Group/Subscriber', 'Gross', 'Concessions'];
        $records = self::map_export_rows(static function (array $row): array {
          return [
            (string) ($row['report_date'] ?? ''),
            (string) ($row['show_title'] ?? ''),
            (string) ($row['show_time'] ?? ''),
            (int) ($row['total_tickets'] ?? 0),
            (int) ($row['presale_qty'] ?? 0),
            (int) ($row['online_qty'] ?? 0),
            (int) ($row['door_qty'] ?? 0),
            (int) ($row['group_sub_qty'] ?? 0),
            '$' . number_format((float) ($row['gross_total'] ?? 0), 2, '.', ''),
            '$' . number_format((float) ($row['concessions_total'] ?? 0), 2, '.', ''),
          ];
        }, $rows);
        break;
      case 'rentals':
        $rows = Store::iterate_dataset('rentals', $filters);
        $header = ['Date', 'Rental', 'Type', 'Customer', 'Status', 'Show Time', 'Invoice', 'Concessions', 'Notes'];
        $records = self::map_export_rows(static function (array $row): array {
          return [
            (string) ($row['report_date'] ?? ''),
            (string) ($row['rental_title'] ?? ''),
            (string) ($row['rental_type'] ?? ''),
            (string) ($row['customer_name'] ?? ''),
            (string) ($row['status'] ?? ''),
            (string) ($row['show_time'] ?? ''),
            '$' . number_format((float) ($row['invoice_amount'] ?? 0), 2, '.', ''),
            '$' . number_format((float) ($row['concessions_total'] ?? 0), 2, '.', ''),
            (string) ($row['notes'] ?? ''),
          ];
        }, $rows);
        break;
      case 'legacy':
        $rows = Store::iterate_dataset('legacy', $filters);
        $header = ['Week Of', 'Week End', 'Movie', 'Rating', 'Weeks', 'General', 'Discount', 'Free', 'Total', 'Ticket Gross', 'Concessions'];
        $records = self::map_export_rows(static function (array $row): array {
          return [
            (string) ($row['week_start_date'] ?? ''),
            (string) ($row['week_end_date'] ?? ''),
            (string) ($row['movie_title'] ?? ''),
            (string) ($row['rating'] ?? ''),
            (string) ($row['weeks_run'] ?? ''),
            (int) ($row['general_qty'] ?? 0),
            (int) ($row['discount_qty'] ?? 0),
            (int) ($row['free_qty'] ?? 0),
            (int) ($row['total_attendance'] ?? 0),
            '$' . number_format((float) ($row['gross_total'] ?? 0), 2, '.', ''),
            '$' . number_format((float) ($row['concessions_total'] ?? 0), 2, '.', ''),
          ];
        }, $rows);
        break;
      default:
        $dataset = 'movies';
        $rows = Store::iterate_dataset('movies', $filters);
        $header = ['Date', 'Movie', 'Studio', 'Genre', 'Show Time', 'Total', 'General', 'Discount', 'Group', 'Free', 'Gross', 'Concessions'];
        $records = self::map_export_rows(static function (array $row): array {
          return [
            (string) ($row['report_date'] ?? ''),
            (string) ($row['movie_title'] ?? ''),
            (string) ($row['studio'] ?? ''),
            (string) ($row['genre'] ?? ''),
            (string) ($row['show_time'] ?? ''),
            (int) ($row['total_tickets'] ?? 0),
            (int) ($row['general_qty'] ?? 0),
            (int) ($row['discount_qty'] ?? 0),
            (int) ($row['group_qty'] ?? 0),
            (int) ($row['live_qty'] ?? 0),
            '$' . number_format((float) ($row['gross_total'] ?? 0), 2, '.', ''),
            '$' . number_format((float) ($row['concessions_total'] ?? 0), 2, '.', ''),
          ];
        }, $rows);
        break;
    }

    // Finish a private, automatically removed spool before sending download headers.
    // A failed later page must not look like a successful truncated CSV download.
    $output = tmpfile();
    if (!$output) {
      wp_die('Could not create CSV export stream.');
    }
    try {
      if (fputcsv($output, $header) === false) {
        throw new \RuntimeException('Could not write CSV export.');
      }
      foreach ($records as $record) {
        if (fputcsv($output, $record) === false) {
          throw new \RuntimeException('Could not write CSV export.');
        }
      }
      if (!rewind($output)) {
        throw new \RuntimeException('Could not read CSV export.');
      }
    } catch (\Throwable $error) {
      fclose($output);
      wp_die('Could not complete CSV export. Please try again.');
      return;
    }
    $filename = 'roxy-grosses-' . $dataset . '-' . wp_date('Y-m-d-His') . '.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    fpassthru($output);
    fclose($output);
    exit;
  }

  private static function map_export_rows(callable $mapper, iterable $rows): \Generator {
    foreach ($rows as $row) {
      yield $mapper($row);
    }
  }

  public static function handle_update_row(): void {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('You do not have permission to edit grosses rows.');
    }

    check_admin_referer('roxy_grosses_update_row');

    $dataset = isset($_POST['dataset']) ? sanitize_key((string) wp_unslash($_POST['dataset'])) : 'movies';
    $entry_id = isset($_POST['entry_id']) ? max(0, (int) $_POST['entry_id']) : 0;
    if ($entry_id <= 0) {
      self::redirect_with_notice('error', 'Missing row ID.', self::tab_for_dataset($dataset));
    }

    $extra = self::redirect_extra_for_dataset($dataset, $_POST);
    $message = 'Row updated.';
    $success = false;

    switch ($dataset) {
      case 'live':
        $success = Store::update_live_entry($entry_id, $_POST, true);
        break;
      case 'rentals':
        $success = Store::update_rental_entry($entry_id, $_POST, true);
        break;
      case 'legacy':
        $success = Store::update_legacy_weekly($entry_id, $_POST, true);
        break;
      default:
        $dataset = 'movies';
        $success = Store::update_entry($entry_id, $_POST, true);
        break;
    }

    if ($success) {
      Store::insert_log('edit_row', 'manual-edit', null, null, true, $message, ['dataset' => $dataset, 'entry_id' => $entry_id]);
      self::redirect_with_notice('success', $message, self::tab_for_dataset($dataset), $extra);
    }

    Store::insert_log('edit_row', 'manual-edit', null, null, false, 'Could not update row.', ['dataset' => $dataset, 'entry_id' => $entry_id]);
    self::redirect_with_notice('error', 'Could not update row.', self::tab_for_dataset($dataset), $extra);
  }

  public static function handle_run_now(): void {
    if (!roxy_suite_user_can_access_admin()) {
      wp_die('You do not have permission to run the grosses automation.');
    }

    check_admin_referer('roxy_grosses_run_now');

    $report_date = isset($_POST['report_date']) ? sanitize_text_field(wp_unslash((string) $_POST['report_date'])) : '';
    if ($report_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
      self::redirect_with_notice('error', 'Choose a valid run date in YYYY-MM-DD format.', 'settings');
    }

    $result = Scheduler::run_now($report_date ?: null);
    self::redirect_with_notice(!empty($result['success']) ? 'success' : 'error', (string) ($result['message'] ?? 'Automation run finished.'), 'settings');
  }

  public static function send_report(string $report_date, string $mode = 'scheduled', string $send_request_id = ''): array {
    try { return Square::with_sale_snapshot(static fn() => Store::with_refund_review_lock(static fn() => self::send_report_locked($report_date, $mode, $send_request_id))); }
    catch (\Throwable $error) { return ['success' => false, 'message' => $error->getMessage()]; }
  }

  private static function send_report_locked(string $report_date, string $mode, string $send_request_id = ''): array {
    try {
      $reports = self::build_reports($report_date);
      if (in_array($mode, ['scheduled', 'scheduled-provisional'], true)) {
        foreach ($reports as $row) {
          if (isset(self::$refund_review_dates[$row['report_date'] ?? ''])) throw new \RuntimeException('A later Square refund changed an already-emailed sale day. Review a fresh draft; it was not automatically resent.');
        }
      }
      $summary = self::summarize_reports($reports);
      if ((int) ($summary['total_tickets'] ?? 0) <= 0 && !self::contains_refund_correction($reports)) {
        throw new \RuntimeException('No matching Square ticket sales were found for that report date or its configured lookback window.');
      }

      Store::upsert_history_rows($reports, $mode, null);
      // Email snapshots are not financial-table refreshes. In particular, do
      // not replace cross-category concession allocations with movie-only ones.

      Store::assert_refund_review_lock();
      $daily_key = 'daily-grosses:' . $report_date;
      if (in_array($mode, ['manual', 'scheduled', 'scheduled-provisional'], true)) {
        try {
          $prior_attempt = EmailOutbox::find($daily_key);
          if ($prior_attempt !== null) {
            $prior_report_id = max(0, (int) ($prior_attempt['source_id'] ?? 0));
            $prior_report = $prior_report_id > 0 ? Store::get_report($prior_report_id) : null;
            if (!$prior_report) throw new \RuntimeException('A prior daily email attempt exists but its saved snapshot is missing. Review the Email Send Guard before proceeding.');
            $same_snapshot = ($prior_report['summary'] ?? null) === $summary && ($prior_report['rows'] ?? null) === $reports;
            if ($same_snapshot) {
              if (($prior_attempt['status'] ?? '') === 'accepted') {
                $marked = Store::mark_emailed($prior_report_id);
                $message = 'This logical report was already accepted by WordPress for sending; no duplicate was sent.';
                if (!$marked) $message .= ' The saved report status could not be reconciled; review report #' . $prior_report_id . ' and the Email Send Guard.';
                Store::insert_log('send_report', $mode, $prior_report_id, $report_date, true, $message, ['duplicate_suppressed' => true]);
                return ['success' => true, 'message' => $message, 'rows' => $reports, 'summary' => $summary, 'report_id' => $prior_report_id, 'duplicate_suppressed' => true];
              }
              throw new \RuntimeException('A prior email attempt is ' . (string) ($prior_attempt['status'] ?? 'unknown') . ' for this unchanged report. It was not resent; verify the mail-provider outcome.');
            }
          }
        } catch (\Throwable $error) {
          Store::insert_log('send_report', $mode, null, $report_date, false, $error->getMessage());
          return ['success' => false, 'message' => $error->getMessage()];
        }
      }
      // Persist the exact snapshot before crossing the mail boundary. If PHP
      // stops after WordPress accepts the message, the manager can still review it.
      $report_id = Store::create_report($report_date, max(0, (int) Settings::get('lookback_days', '0')), $mode, 'draft', $summary, $reports);
      if ($report_id <= 0) throw new \RuntimeException('The report snapshot could not be saved; no email was attempted.');
      $send = self::send_email($reports, $summary, $mode, null, 'daily-grosses', $report_id, $report_date, $send_request_id);
      if (!$send['success']) {
        throw new \RuntimeException($send['message']);
      }
      if (!empty($send['already_accepted'])) {
        $message = $send['message'] . ' The reviewed snapshot remains saved as draft #' . $report_id . '.';
        Store::insert_log('send_report', $mode, $report_id, $report_date, true, $message, ['row_count' => count($reports), 'duplicate_suppressed' => true]);
        Settings::set_status([
          'sent_at' => wp_date('Y-m-d H:i:s', null, new \DateTimeZone(Settings::get_report_timezone())),
          'report_date' => $report_date,
          'mode' => $mode,
          'message' => $message,
          'row_count' => count($reports),
          'gross_total' => (float) ($summary['gross_total'] ?? 0),
        ]);
        return ['success' => true, 'message' => $message, 'rows' => $reports, 'summary' => $summary, 'report_id' => $report_id, 'duplicate_suppressed' => true];
      }

      if (!Store::mark_emailed($report_id)) throw new \RuntimeException('WordPress accepted the email, but could not mark the saved report as emailed. Do not resend; review the email outbox and saved report.');
      Store::upsert_history_rows($reports, $mode, $report_id);

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

  public static function pull_into_database(string $report_date, string $mode = 'manual-pull'): array {
    return Square::with_sale_snapshot(static fn() => self::pull_into_database_snapshot($report_date, $mode));
  }

  private static function pull_into_database_snapshot(string $report_date, string $mode): array {
    try {
      $reports = self::build_reports($report_date);
      $summary = self::summarize_reports($reports);
      if ((int) ($summary['total_tickets'] ?? 0) <= 0 && !self::contains_refund_correction($reports)) {
        throw new \RuntimeException('No matching Square ticket sales were found for that date.');
      }

      $entry_result = Store::upsert_entries(self::entries_from_report_rows($reports, 'square_auto', $mode, null), 'update');
      self::rebalance_concessions_for_report_dates($reports, $report_date);
      Store::upsert_history_rows($reports, $mode, null);
      $message = sprintf(
        'Pulled %d row(s) for %s. %d created, %d updated, %d skipped.',
        count($reports),
        $report_date,
        (int) ($entry_result['created'] ?? 0),
        (int) ($entry_result['updated'] ?? 0),
        (int) ($entry_result['skipped'] ?? 0)
      );

      Store::insert_log('pull_database', $mode, null, $report_date, true, $message, [
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
      ];
    } catch (\Throwable $e) {
      Store::insert_log('pull_database', $mode, null, $report_date, false, $e->getMessage());
      self::notify_admin_failure('Grosses database pull failed', $report_date, $mode, $e->getMessage());
      return [
        'success' => false,
        'message' => $e->getMessage(),
      ];
    }
  }

  public static function pull_live_into_database(string $report_date, string $mode = 'manual-live-pull'): array {
    return Square::with_sale_snapshot(static fn() => self::pull_live_into_database_snapshot($report_date, $mode));
  }

  private static function pull_live_into_database_snapshot(string $report_date, string $mode): array {
    try {
      $rows = self::build_live_reports($report_date);
      if (!$rows) {
        throw new \RuntimeException('No live show rows were found for that date.');
      }

      $result = Store::upsert_live_entries($rows, 'update');
      self::rebalance_concessions_for_date($report_date);
      $gross_total = 0.0;
      foreach ($rows as $row) {
        $gross_total += (float) ($row['gross_total'] ?? 0);
      }

      $message = sprintf(
        'Pulled %d live row(s) for %s. %d created, %d updated, %d skipped.',
        count($rows),
        $report_date,
        (int) ($result['created'] ?? 0),
        (int) ($result['updated'] ?? 0),
        (int) ($result['skipped'] ?? 0)
      );

      Store::insert_log('pull_live_database', $mode, null, $report_date, true, $message, [
        'row_count' => count($rows),
        'gross_total' => round($gross_total, 2),
      ]);
      Settings::set_status([
        'sent_at' => wp_date('Y-m-d H:i:s', null, new \DateTimeZone(Settings::get_report_timezone())),
        'report_date' => $report_date,
        'mode' => $mode,
        'message' => $message,
        'row_count' => count($rows),
        'gross_total' => $gross_total,
      ]);

      return ['success' => true, 'message' => $message, 'rows' => $rows];
    } catch (\Throwable $e) {
      Store::insert_log('pull_live_database', $mode, null, $report_date, false, $e->getMessage());
      self::notify_admin_failure('Live grosses database pull failed', $report_date, $mode, $e->getMessage());
      return ['success' => false, 'message' => $e->getMessage()];
    }
  }

  public static function sync_automatic_tables(string $report_date, string $mode = 'scheduled-sync', ?\DateTimeImmutable $now = null): array {
    return Square::with_sale_snapshot(static fn() => self::sync_automatic_tables_snapshot($report_date, $mode, $now));
  }

  /** Refresh a closed sale day and flag changed emailed snapshots; never sends a report. */
  public static function refresh_closed_day(string $report_date): array {
    return self::sync_automatic_tables($report_date, 'closed-day-refresh');
  }

  private static function sync_automatic_tables_snapshot(string $report_date, string $mode, ?\DateTimeImmutable $now): array {
    global $wpdb;
    $lock = 'roxy_grosses_refund_sync_' . substr(hash('sha256', Store::entries_table_name()), 0, 24);
    $claimed = false;
    try {
      $claimed = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) === 1;
      if (!$claimed) throw new \RuntimeException('A Grosses refund refresh is already running or its lock is unavailable.');
      $timezone = new \DateTimeZone(Settings::get_report_timezone());
      $cutoff = ($now ?? new \DateTimeImmutable('now', $timezone))->setTimezone($timezone);
      $related = self::related_showings_by_date($report_date);
      $dates = array_keys($related);
      sort($dates);
      $earliest = (string) ($dates[0] ?? $report_date);
      $cursor = (string) get_option(self::REFUND_SCAN_DATE, '');
      if ($cursor !== '') {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $cursor, $cutoff->getTimezone());
        if (!$parsed || $parsed->format('Y-m-d') !== $cursor || $parsed > $cutoff) throw new \RuntimeException('The Square refund scan checkpoint is invalid; review it before continuing.');
        $scan_from = $parsed->modify('-1 day')->format('Y-m-d');
      } else $scan_from = $cutoff->modify('-30 days')->format('Y-m-d');
      $earliest = min($earliest, $scan_from);
      $pending_from = (string) get_option(self::REFUND_PENDING_FROM, '');
      if ($pending_from !== '') {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $pending_from, $cutoff->getTimezone());
        if (!$parsed || $parsed->format('Y-m-d') !== $pending_from || $parsed > $cutoff) throw new \RuntimeException('The pending Square refund checkpoint needs review.');
        $earliest = min($earliest, $pending_from);
      }
      $deadline = microtime(true) + 120;
      // Expand to original source days so earlier partial refunds are included.
      for ($attempt = 0; ; $attempt++) {
        $snapshot = RefundSnapshot::load($earliest, $cutoff, $deadline);
        $source_dates = $snapshot->original_sale_dates();
        $source_earliest = $source_dates[0] ?? $earliest;
        if ($source_earliest >= $earliest) break;
        if ($attempt >= 19) throw new \RuntimeException('Square refund source discovery exceeded its safety limit.');
        $earliest = $source_earliest;
      }
      $refund_updated = 0; $refund_protected = 0;
      foreach ($snapshot->original_sale_dates() as $date) {
        if (isset($related[$date])) continue;
        $sources = $snapshot->source_orders_for_date($date);
        $ticket_lines = [];
        foreach ($sources as $sale) foreach (($sale['line_items'] ?? []) as $line) if (self::classify_ticket_variation($line) !== '') $ticket_lines[$sale['id']][$line['uid'] ?? ''] = true;
        if (!$ticket_lines) continue; // Cash/concession refunds have a separate path.
        $reconciled = $snapshot->reconcile_sale_day($date, $sources);
        if ($reconciled['issues']) throw new \RuntimeException('A past original sale day has a Square return requiring manual review. No corrected report was automatically sent.');
        $ticket_returned = false;
        foreach ($reconciled['adjustments'] as $adjustment) if (isset($ticket_lines[$adjustment['source_order_id']][$adjustment['source_line_item_uid']])) $ticket_returned = true;
        if (!$ticket_returned) continue;
        $showings = self::showings_for_date($date);
        if (!$showings) throw new \RuntimeException('A refunded past sale has no original showing to refresh. Review it manually.');
        $corrections = self::build_reports_for_date_showings($date, $showings, $snapshot, true);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock)) !== 1) throw new \RuntimeException('Grosses refund refresh lost its database lock.');
        $result = Store::update_refunded_movie_quantities($corrections);
        $refund_updated += $result['updated'];
        $refund_protected += $result['protected'];
      }
      $movie_rows = self::build_reports($report_date, true, $snapshot);
      $live_rows = self::build_live_reports($report_date, true);

      $movie_result = ['created' => 0, 'updated' => 0, 'skipped' => 0];
      $live_result = ['created' => 0, 'updated' => 0, 'skipped' => 0];

      if ($movie_rows) {
        $movie_result = Store::upsert_entries(self::entries_from_report_rows($movie_rows, 'square_auto', $mode, null), 'update');
      }

      if ($live_rows) {
        $live_result = Store::upsert_live_entries($live_rows, 'update');
      }
      self::rebalance_concessions_for_report_dates($movie_rows, $report_date);
      if ((int) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock)) !== 1) throw new \RuntimeException('Grosses refund refresh lost its database lock.');
      $closed_day_reports_flagged = $mode === 'closed-day-refresh'
        ? Store::with_refund_review_lock(static fn() => Store::flag_emailed_closed_day_changes($report_date, Store::closed_day_report_rows($report_date)))
        : [];
      $scan_date = $cutoff->format('Y-m-d');
      $pending_dates = $snapshot->pending_source_dates();
      $pending_date = (string) ($pending_dates[0] ?? '');
      update_option(self::REFUND_PENDING_FROM, $pending_date, false);
      if ((string) get_option(self::REFUND_PENDING_FROM, '') !== $pending_date) throw new \RuntimeException('Could not record pending Square refunds for retry.');
      update_option(self::REFUND_SCAN_DATE, $scan_date, false);
      if ((string) get_option(self::REFUND_SCAN_DATE, '') !== $scan_date) throw new \RuntimeException('Could not record the Square refund scan checkpoint. Retry the refresh.');

      $movie_paid_rows = 0;
      foreach ($movie_rows as $row) {
        if ((int) ($row['total_tickets'] ?? 0) > 0) {
          $movie_paid_rows++;
        }
      }

      $message = sprintf(
        'Automatic sync updated Movies (%d row(s): %d created, %d updated) and Live Shows (%d row(s): %d created, %d updated) for %s.',
        count($movie_rows),
        (int) ($movie_result['created'] ?? 0),
        (int) ($movie_result['updated'] ?? 0),
        count($live_rows),
        (int) ($live_result['created'] ?? 0),
        (int) ($live_result['updated'] ?? 0),
        $report_date
      );

      Store::insert_log('sync_tables', $mode, null, $report_date, true, $message, [
        'movie_rows' => count($movie_rows),
        'movie_paid_rows' => $movie_paid_rows,
        'movie_created' => (int) ($movie_result['created'] ?? 0),
        'movie_updated' => (int) ($movie_result['updated'] ?? 0),
        'live_rows' => count($live_rows),
        'live_created' => (int) ($live_result['created'] ?? 0),
        'live_updated' => (int) ($live_result['updated'] ?? 0),
        'refund_movie_rows_updated' => $refund_updated,
        'refund_movie_rows_protected' => $refund_protected,
        'closed_day_reports_flagged' => count($closed_day_reports_flagged),
        'pending_refund_source_days' => count($pending_dates),
      ]);
      self::log_sync_anomalies($report_date, $mode, $movie_rows, $live_rows);

      return [
        'success' => true,
        'message' => $message,
        'movie_rows' => count($movie_rows),
        'movie_paid_rows' => $movie_paid_rows,
        'live_rows' => count($live_rows),
        'movie_result' => $movie_result,
        'live_result' => $live_result,
        'refund_movie_rows_updated' => $refund_updated,
        'refund_movie_rows_protected' => $refund_protected,
        'closed_day_reports_flagged' => count($closed_day_reports_flagged),
      ];
    } catch (\Throwable $e) {
      Store::insert_log('sync_tables', $mode, null, $report_date, false, $e->getMessage());
      if ($mode !== 'closed-day-refresh') self::notify_admin_failure('Grosses automatic sync failed', $report_date, $mode, $e->getMessage());
      return [
        'success' => false,
        'message' => $e->getMessage(),
        'movie_rows' => 0,
        'movie_paid_rows' => 0,
        'live_rows' => 0,
        'movie_result' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
        'live_result' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
      ];
    } finally {
      if ($claimed) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
  }

  public static function backfill_free_tickets(array $filters = [], string $mode = 'manual-free-backfill'): array {
    $dates = Store::distinct_entry_dates(array_filter([
      'search' => (string) ($filters['search'] ?? ''),
      'year' => !empty($filters['year']) ? (int) $filters['year'] : null,
      'month' => (string) ($filters['month'] ?? ''),
      'day' => (string) ($filters['day'] ?? ''),
    ]));

    if (!$dates) {
      return [
        'success' => false,
        'message' => 'No database dates matched the current filters.',
      ];
    }

    $date_count = 0;
    $row_count = 0;
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $free_total = 0;

    foreach ($dates as $report_date) {
      try {
        $reports = self::build_free_ticket_backfill_rows_for_date($report_date);
        if (!$reports) {
          $skipped++;
          continue;
        }

        $entry_result = Store::upsert_entries(self::entries_from_report_rows($reports, 'square_auto', $mode, null), 'update');
        self::rebalance_concessions_for_date($report_date);

        $date_count++;
        $row_count += count($reports);
        $created += (int) ($entry_result['created'] ?? 0);
        $updated += (int) ($entry_result['updated'] ?? 0);

        foreach ($reports as $report) {
          $free_total += max(0, (int) ($report['live_qty'] ?? 0));
        }
      } catch (\Throwable $e) {
        $skipped++;
      }
    }

    if ($date_count <= 0) {
      $message = 'No Square movie rows were found for the selected dates.';
      Store::insert_log('backfill_free_tickets', $mode, null, null, false, $message, ['dates' => count($dates)]);
      return ['success' => false, 'message' => $message];
    }

    $message = sprintf(
      'Backfilled free tickets across %d date(s). %d row(s) refreshed, %d created, %d updated, %d skipped, %d free tickets found.',
      $date_count,
      $row_count,
      $created,
      $updated,
      $skipped,
      $free_total
    );
    Store::insert_log('backfill_free_tickets', $mode, null, null, true, $message, [
      'dates' => $date_count,
      'rows' => $row_count,
      'created' => $created,
      'updated' => $updated,
      'skipped' => $skipped,
      'free_total' => $free_total,
    ]);

    return ['success' => true, 'message' => $message];
  }

  public static function backfill_movie_concessions(array $filters = [], string $mode = 'manual-concessions-backfill'): array {
    $dates = Store::distinct_entry_dates(array_filter([
      'search' => (string) ($filters['search'] ?? ''),
      'year' => !empty($filters['year']) ? (int) $filters['year'] : null,
      'month' => (string) ($filters['month'] ?? ''),
      'day' => (string) ($filters['day'] ?? ''),
    ]));

    if (!$dates) {
      return ['success' => false, 'message' => 'No movie database dates matched the current filters.'];
    }

    $date_count = 0;
    $row_count = 0;
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $concessions_total = 0.0;

    foreach ($dates as $report_date) {
      try {
        $reports = self::build_concession_backfill_rows_for_date($report_date);
        if (!$reports) {
          $skipped++;
          continue;
        }

        $entry_result = Store::upsert_entries(self::entries_from_report_rows($reports, 'square_auto', $mode, null), 'update');
        self::rebalance_concessions_for_date($report_date);
        $date_count++;
        $row_count += count($reports);
        $created += (int) ($entry_result['created'] ?? 0);
        $updated += (int) ($entry_result['updated'] ?? 0);
        foreach ($reports as $report) {
          $concessions_total += (float) ($report['concessions_total'] ?? 0);
        }
      } catch (\Throwable $e) {
        $skipped++;
      }
    }

    if ($date_count <= 0) {
      $message = 'No concession rows were updated for the selected movie dates.';
      Store::insert_log('backfill_concessions', $mode, null, null, false, $message, ['dates' => count($dates)]);
      return ['success' => false, 'message' => $message];
    }

    $message = sprintf(
      'Backfilled concessions across %d movie date(s). %d row(s) refreshed, %d created, %d updated, %d skipped, $%s concessions found.',
      $date_count,
      $row_count,
      $created,
      $updated,
      $skipped,
      number_format($concessions_total, 2)
    );
    Store::insert_log('backfill_concessions', $mode, null, null, true, $message, [
      'dates' => $date_count,
      'rows' => $row_count,
      'created' => $created,
      'updated' => $updated,
      'skipped' => $skipped,
      'concessions_total' => round($concessions_total, 2),
    ]);

    return ['success' => true, 'message' => $message];
  }

  public static function backfill_live_concessions(array $filters = [], string $mode = 'manual-live-concessions-backfill'): array {
    $dates = Store::distinct_live_entry_dates(array_filter([
      'search' => (string) ($filters['search'] ?? ''),
      'year' => !empty($filters['year']) ? (int) $filters['year'] : null,
      'month' => (string) ($filters['month'] ?? ''),
      'day' => (string) ($filters['day'] ?? ''),
    ]));

    if (!$dates) {
      return ['success' => false, 'message' => 'No live show dates matched the current filters.'];
    }

    $date_count = 0;
    $row_count = 0;
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $concessions_total = 0.0;

    foreach ($dates as $report_date) {
      try {
        $rows = self::build_live_concession_backfill_rows_for_date($report_date);
        if (!$rows) {
          $skipped++;
          continue;
        }

        $entry_result = Store::upsert_live_entries($rows, 'update');
        self::rebalance_concessions_for_date($report_date);
        $date_count++;
        $row_count += count($rows);
        $created += (int) ($entry_result['created'] ?? 0);
        $updated += (int) ($entry_result['updated'] ?? 0);
        foreach ($rows as $row) {
          $concessions_total += (float) ($row['concessions_total'] ?? 0);
        }
      } catch (\Throwable $e) {
        $skipped++;
      }
    }

    if ($date_count <= 0) {
      $message = 'No concession rows were updated for the selected live show dates.';
      Store::insert_log('backfill_live_concessions', $mode, null, null, false, $message, ['dates' => count($dates)]);
      return ['success' => false, 'message' => $message];
    }

    $message = sprintf(
      'Backfilled concessions across %d live date(s). %d row(s) refreshed, %d created, %d updated, %d skipped, $%s concessions found.',
      $date_count,
      $row_count,
      $created,
      $updated,
      $skipped,
      number_format($concessions_total, 2)
    );
    Store::insert_log('backfill_live_concessions', $mode, null, null, true, $message, [
      'dates' => $date_count,
      'rows' => $row_count,
      'created' => $created,
      'updated' => $updated,
      'skipped' => $skipped,
      'concessions_total' => round($concessions_total, 2),
    ]);

    return ['success' => true, 'message' => $message];
  }

  private static function build_free_ticket_backfill_rows_for_date(string $report_date): array {
    $existing_rows = Store::list_entries(['day' => $report_date], 500, 0);
    if (!$existing_rows) {
      return [];
    }

    $reports = [];
    foreach ($existing_rows as $row) {
      $start_at = self::start_at_for_entry_row($report_date, (string) ($row['show_time'] ?? ''));
      $reports[(int) ($row['id'] ?? 0)] = [
        'entry_id' => (int) ($row['id'] ?? 0),
        'report_date' => $report_date,
        'theater_name' => (string) ($row['theater_name'] ?? Settings::get('theater_name', 'Newport Roxy Theater')),
        'showing_id' => max(0, (int) ($row['showing_id'] ?? 0)),
        'film_title' => (string) ($row['movie_title'] ?? ''),
        'show_time' => (string) ($row['show_time'] ?? ''),
        'general_qty' => max(0, (int) ($row['general_qty'] ?? 0)),
        'general_gross' => 0.0,
        'discount_qty' => max(0, (int) ($row['discount_qty'] ?? 0)),
        'discount_gross' => 0.0,
        'group_qty' => max(0, (int) ($row['group_qty'] ?? 0)),
        'group_gross' => 0.0,
        'live_qty' => 0,
        'live_gross' => 0.0,
        'total_tickets' => max(0, (int) ($row['general_qty'] ?? 0)) + max(0, (int) ($row['discount_qty'] ?? 0)) + max(0, (int) ($row['group_qty'] ?? 0)),
        'gross_total' => round((float) ($row['gross_total'] ?? 0), 2),
        '_start_at' => $start_at,
      ];
    }

    $orders = Square::fetch_orders_for_date($report_date);
    foreach ($orders as $order) {
      $order_closed_at = self::order_closed_at($order);
      if (!$order_closed_at) {
        continue;
      }

      foreach ((array) ($order['line_items'] ?? []) as $line_item) {
        if (self::classify_ticket_variation($line_item) !== 'live') {
          continue;
        }

        $qty = isset($line_item['quantity']) ? (int) round((float) $line_item['quantity']) : 0;
        if ($qty <= 0) {
          continue;
        }

        $candidate_ids = self::matching_entry_ids_for_order_time($order_closed_at, $reports);
        if (!$candidate_ids) {
          continue;
        }

        foreach (self::distribute_quantity_across_rows($qty, $candidate_ids, $reports) as $entry_id => $allocated_qty) {
          if ($allocated_qty <= 0 || !isset($reports[$entry_id])) {
            continue;
          }
          $reports[$entry_id]['live_qty'] += $allocated_qty;
          $reports[$entry_id]['total_tickets'] += $allocated_qty;
        }
      }
    }

    foreach ($reports as &$report) {
      unset($report['_start_at']);
    }
    unset($report);

    return array_values($reports);
  }

  private static function build_concession_backfill_rows_for_date(string $report_date): array {
    $existing_rows = Store::list_entries(['day' => $report_date], 500, 0);
    if (!$existing_rows) {
      return [];
    }

    $reports = [];
    foreach ($existing_rows as $row) {
      $start_at = self::start_at_for_entry_row($report_date, (string) ($row['show_time'] ?? ''));
      $reports[(int) ($row['id'] ?? 0)] = [
        'entry_id' => (int) ($row['id'] ?? 0),
        'report_date' => $report_date,
        'theater_name' => (string) ($row['theater_name'] ?? Settings::get('theater_name', 'Newport Roxy Theater')),
        'showing_id' => max(0, (int) ($row['showing_id'] ?? 0)),
        'film_title' => (string) ($row['movie_title'] ?? ''),
        'show_time' => (string) ($row['show_time'] ?? ''),
        'general_qty' => max(0, (int) ($row['general_qty'] ?? 0)),
        'discount_qty' => max(0, (int) ($row['discount_qty'] ?? 0)),
        'group_qty' => max(0, (int) ($row['group_qty'] ?? 0)),
        'live_qty' => max(0, (int) ($row['live_qty'] ?? 0)),
        'total_tickets' => max(0, (int) ($row['total_tickets'] ?? 0)),
        'gross_total' => round((float) ($row['gross_total'] ?? 0), 2),
        'concessions_total' => 0.0,
        '_start_at' => $start_at,
      ];
    }

    self::apply_concessions_to_reports($reports, $report_date);

    foreach ($reports as &$report) {
      unset($report['_start_at']);
      $report['concessions_total'] = round((float) ($report['concessions_total'] ?? 0), 2);
    }
    unset($report);

    return array_values($reports);
  }

  private static function build_live_concession_backfill_rows_for_date(string $report_date): array {
    $existing_rows = Store::list_live_entries(['day' => $report_date], 500, 0);
    if (!$existing_rows) {
      return [];
    }

    $rows = [];
    foreach ($existing_rows as $row) {
      $start_at = self::start_at_for_entry_row($report_date, (string) ($row['show_time'] ?? ''));
      $rows[(int) ($row['id'] ?? 0)] = [
        'entry_id' => (int) ($row['id'] ?? 0),
        'report_date' => $report_date,
        'show_title' => (string) ($row['show_title'] ?? ''),
        'show_time' => (string) ($row['show_time'] ?? ''),
        'showing_id' => max(0, (int) ($row['showing_id'] ?? 0)),
        'theater_name' => (string) ($row['theater_name'] ?? Settings::get('theater_name', 'Newport Roxy Theater')),
        'presale_qty' => max(0, (int) ($row['presale_qty'] ?? 0)),
        'online_qty' => max(0, (int) ($row['online_qty'] ?? 0)),
        'door_qty' => max(0, (int) ($row['door_qty'] ?? 0)),
        'group_sub_qty' => max(0, (int) ($row['group_sub_qty'] ?? 0)),
        'total_tickets' => max(0, (int) ($row['total_tickets'] ?? 0)),
        'gross_total' => round((float) ($row['gross_total'] ?? 0), 2),
        'concessions_total' => 0.0,
        'source_type' => (string) ($row['source_type'] ?? 'live_combined'),
        'source_ref' => (string) ($row['source_ref'] ?? 'show_tickets+square'),
        'notes' => (string) ($row['notes'] ?? ''),
        '_start_at' => $start_at,
      ];
    }

    self::apply_concessions_to_reports($rows, $report_date);

    foreach ($rows as &$row) {
      unset($row['_start_at']);
      $row['concessions_total'] = round((float) ($row['concessions_total'] ?? 0), 2);
    }
    unset($row);

    return array_values($rows);
  }

  public static function save_report_draft(string $report_date, string $mode = 'review'): array {
    return Square::with_sale_snapshot(static fn() => self::save_report_draft_snapshot($report_date, $mode));
  }

  private static function save_report_draft_snapshot(string $report_date, string $mode): array {
    try {
      $reports = self::build_reports($report_date);
      $summary = self::summarize_reports($reports);
      if ((int) ($summary['total_tickets'] ?? 0) <= 0 && !self::contains_refund_correction($reports)) {
        throw new \RuntimeException('No matching Square ticket sales were found for that report date or its configured lookback window.');
      }

      $report_id = Store::create_report($report_date, max(0, (int) Settings::get('lookback_days', '0')), $mode, 'draft', $summary, $reports);
      if ($report_id <= 0) {
        throw new \RuntimeException('Could not save the draft report.');
      }

      Store::upsert_history_rows($reports, $mode, $report_id);
      // Saving a studio draft must not refresh current financial allocations.

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

  public static function send_saved_report(int $report_id, string $send_request_id = '', bool $intentional_resend = false): array {
    try { return Store::with_refund_review_lock(static fn() => self::send_saved_report_locked($report_id, $send_request_id, $intentional_resend)); }
    catch (\Throwable $error) { return ['success' => false, 'message' => $error->getMessage()]; }
  }

  private static function send_saved_report_locked(int $report_id, string $send_request_id = '', bool $intentional_resend = false): array {
    $saved = Store::get_report($report_id);
    if (!$saved) {
      Store::insert_log('send_saved_report', 'saved-report', $report_id, null, false, 'Saved report not found.');
      self::notify_admin_failure('Saved grosses report failed', '', 'saved-report', 'Saved report #' . $report_id . ' was not found.');
      return [
        'success' => false,
        'message' => 'Saved report not found.',
      ];
    }

    if (!empty($saved['refund_review'])) return ['success' => false, 'message' => 'This emailed snapshot needs refund review. Pull and review a fresh draft instead of resending outdated figures.'];
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

    Store::assert_refund_review_lock();
    if ($intentional_resend && !self::valid_send_request_id($send_request_id)) {
      return ['success' => false, 'message' => 'A deliberate resend needs a fresh request identifier. Reopen the report and try again.'];
    }
    if (!$intentional_resend && ($saved['status'] ?? '') === 'emailed') {
      return ['success' => false, 'message' => 'This report was already emailed. Create and review a fresh draft for a correction, or use the deliberate resend control.'];
    }
    if (!$intentional_resend && !empty($saved['report_end_date'])) {
      try {
        $daily_attempt = EmailOutbox::find('daily-grosses:' . (string) $saved['report_end_date']);
        if ($daily_attempt !== null) {
          return ['success' => false, 'message' => 'A daily email attempt already exists for this report date (' . (string) ($daily_attempt['status'] ?? 'unknown') . '). It was not sent again. Use the deliberate resend control only after reviewing the prior attempt.'];
        }
      } catch (\Throwable $error) {
        return ['success' => false, 'message' => 'The email outbox could not be checked; the report was not sent.'];
      }
    }
    $outbox_key = $intentional_resend ? 'saved-report-resend:' . $report_id . ':' . $send_request_id : 'saved-report:' . $report_id;
    $send = self::send_email($rows, $summary, $intentional_resend ? 'saved-report-resend' : 'saved-report', $outbox_key, 'saved-report', $report_id, (string) ($saved['report_end_date'] ?? ''), $send_request_id);
    if (!$send['success']) {
      Store::insert_log('send_saved_report', 'saved-report', $report_id, (string) ($saved['report_end_date'] ?? ''), false, $send['message']);
      self::notify_admin_failure('Saved grosses report failed', (string) ($saved['report_end_date'] ?? ''), 'saved-report', $send['message']);
      return $send;
    }

    if (!Store::mark_emailed($report_id)) {
      return ['success' => false, 'message' => 'WordPress accepted the saved report email, but its status could not be updated. Do not resend; review the email outbox.'];
    }

    $message = !empty($send['already_accepted'])
      ? 'Saved report #' . $report_id . ' was already accepted by WordPress for sending; it was not sent again.'
      : 'Saved report #' . $report_id . ' emailed to ' . implode(', ', Settings::email_list()) . '.';
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

  public static function build_reports(string $report_date, bool $include_empty = false, ?RefundSnapshot $refund_snapshot = null): array {
    $reports = [];
    $showings_by_date = self::related_showings_by_date($report_date);
    if ($showings_by_date && $refund_snapshot === null) {
      $dates = array_keys($showings_by_date);
      sort($dates);
      $refund_snapshot = RefundSnapshot::load((string) $dates[0]);
    }

    foreach ($showings_by_date as $date => $showings) {
      foreach (self::build_reports_for_date_showings((string) $date, (array) $showings, $refund_snapshot) as $report) {
        $reports[] = $report;
      }
    }

    usort($reports, static function (array $a, array $b): int {
      $left = ((string) ($a['report_date'] ?? '')) . ' ' . ((string) ($a['show_time'] ?? ''));
      $right = ((string) ($b['report_date'] ?? '')) . ' ' . ((string) ($b['show_time'] ?? ''));
      return strcmp($left, $right);
    });

    if ($include_empty) {
      return array_values($reports);
    }

    return array_values(array_filter($reports, static function (array $report): bool {
      return (int) ($report['total_tickets'] ?? 0) > 0 || !empty($report['refund_adjusted']);
    }));
  }

  public static function build_live_reports(string $report_date, bool $include_empty = false): array {
    $showings = self::showings_for_date($report_date, 'live');
    if (!$showings) {
      return [];
    }

    $rows = [];
    foreach ($showings as $showing) {
      $stats = class_exists('\\RoxyST\\Sales') ? \RoxyST\Sales::get_showing_stats((int) $showing['id']) : [];
      $ticket_types = is_array($stats['ticket_types'] ?? null) ? $stats['ticket_types'] : [];
      $presale_qty = max(0, (int) ($stats['presale_qty'] ?? 0));
      $online_qty = max(0, (int) ($stats['day_of_qty'] ?? 0));
      $group_sub_qty = max(0, (int) ($stats['subscriber_qty'] ?? 0));

      if (!array_key_exists('presale_qty', $stats) && !array_key_exists('day_of_qty', $stats)) {
        $online_qty = 0;
        foreach ($ticket_types as $type => $type_row) {
          if ((string) $type === 'subscriber') {
            continue;
          }
          $online_qty += max(0, (int) ($type_row['qty'] ?? 0));
        }
      }

      $rows[(int) $showing['id']] = [
        'report_date' => $report_date,
        'show_title' => (string) $showing['title'],
        'show_time' => (string) $showing['time_label'],
        'showing_id' => (int) $showing['id'],
        'theater_name' => (string) Settings::get('theater_name', 'Newport Roxy Theater'),
        'presale_qty' => $presale_qty,
        'online_qty' => $online_qty,
        'door_qty' => 0,
        'group_sub_qty' => $group_sub_qty,
        'total_tickets' => $presale_qty + $online_qty + $group_sub_qty,
        'gross_total' => round((float) ($stats['gross_revenue'] ?? 0), 2),
        'concessions_total' => 0.0,
        'source_type' => 'live_combined',
        'source_ref' => 'show_tickets+square',
        'notes' => 'Online tickets from Show Tickets sales cache and door tickets from Square.',
        '_start_at' => $showing['start_at'],
      ];
    }

    foreach (Square::fetch_orders_for_date($report_date) as $order) {
      $order_closed_at = self::order_closed_at($order);
      if (!$order_closed_at) {
        continue;
      }

      foreach ((array) ($order['line_items'] ?? []) as $line_item) {
        if (!self::is_live_door_line_item($line_item, $showings)) {
          continue;
        }

        $qty = isset($line_item['quantity']) ? (int) round((float) $line_item['quantity']) : 0;
        if ($qty <= 0) {
          continue;
        }

        $showing_id = self::matching_showing_id_for_order_time($order_closed_at, $showings);
        if ($showing_id <= 0 || !isset($rows[$showing_id])) {
          continue;
        }

        $line_total = self::square_line_item_total($line_item, $qty);
        $rows[$showing_id]['door_qty'] += $qty;
        $rows[$showing_id]['total_tickets'] += $qty;
        $rows[$showing_id]['gross_total'] = round((float) $rows[$showing_id]['gross_total'] + $line_total, 2);
        continue;
      }

    }

    self::apply_concessions_to_reports($rows, $report_date, $showings);

    foreach ($rows as &$row) {
      unset($row['_start_at']);
      $row['concessions_total'] = round((float) ($row['concessions_total'] ?? 0), 2);
    }
    unset($row);

    if ($include_empty) {
      return array_values($rows);
    }

    return array_values(array_filter($rows, static function (array $row): bool {
      return (int) ($row['total_tickets'] ?? 0) > 0 || (float) ($row['gross_total'] ?? 0) > 0;
    }));
  }

  private static function build_reports_for_date_showings(string $report_date, array $showings, ?RefundSnapshot $refund_snapshot = null, bool $require_nominal_baseline = false): array {
    $orders = Square::fetch_orders_for_date($report_date);
    $reconciliation = $refund_snapshot ? $refund_snapshot->reconcile_sale_day($report_date, $orders) : ['orders' => $orders, 'adjustments' => [], 'issues' => []];
    if ($reconciliation['issues']) throw new \RuntimeException('Square returns for this original sale day need manual review (custom amount, unverified refund, or invalid item reference). No report was calculated or sent.');
    $orders = $reconciliation['orders'];
    $returned_lines = [];
    foreach ($reconciliation['adjustments'] as $adjustment) $returned_lines[$adjustment['source_order_id']][$adjustment['source_line_item_uid']] = true;
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
        'live_qty' => 0,
        'live_gross' => 0.0,
        'total_tickets' => 0,
        'gross_total' => 0.0,
        'concessions_total' => 0.0,
        '_start_at' => $showing['start_at'],
      ];
    }

    $showing_prices = [];
    foreach ($orders as $order) {
      $closed = self::order_closed_at($order);
      foreach (($order['line_items'] ?? []) as $line) {
        if (!isset($returned_lines[$order['id'] ?? ''][$line['uid'] ?? '']) || self::classify_ticket_variation($line) === '') continue;
        $candidates = [];
        foreach ($showings as $showing) if ($closed && ($showing['start_at'] ?? null) instanceof \DateTimeImmutable && abs($closed->getTimestamp() - $showing['start_at']->getTimestamp()) <= 90 * 60) $candidates[] = (int) $showing['id'];
        if (count($candidates) !== 1 || !isset($reports[$candidates[0]])) throw new \RuntimeException('A refunded Square ticket cannot be uniquely linked to its original showing. Review it manually; no report was sent.');
        $id = $candidates[0];
        $reports[$id]['refund_adjusted'] = true;
        if (!isset($showing_prices[$id])) {
          $remaining_categories = [];
          foreach ($orders as $remaining_order) {
            $remaining_closed = self::order_closed_at($remaining_order);
            if (!$remaining_closed || self::matching_showing_id_for_order_time($remaining_closed, $showings) !== $id) continue;
            foreach (($remaining_order['line_items'] ?? []) as $remaining_line) {
              $category = self::classify_ticket_variation($remaining_line);
              if ($category !== '' && (float) ($remaining_line['quantity'] ?? 0) > 0) $remaining_categories[$category] = true;
            }
          }
          if ($remaining_categories) {
            $today = wp_date('Y-m-d', null, new \DateTimeZone(Settings::get_report_timezone()));
            $strict = $require_nominal_baseline || $report_date < $today;
            $showing_prices[$id] = Store::nominal_ticket_prices_for_showing($report_date, $id, $prices, $strict, array_keys($remaining_categories));
          } else $showing_prices[$id] = $prices;
        }
      }
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

        $gross = round($qty * (float) (($showing_prices[$showing_id] ?? $prices)[$category] ?? 0), 2);
        $reports[$showing_id][$category . '_qty'] += $qty;
        $reports[$showing_id][$category . '_gross'] += $gross;
        $reports[$showing_id]['total_tickets'] += $qty;
        $reports[$showing_id]['gross_total'] += $gross;
      }
    }

    self::apply_concessions_to_reports($reports, $report_date, $showings);

    foreach ($reports as &$report) {
      $report['general_gross'] = round((float) $report['general_gross'], 2);
      $report['discount_gross'] = round((float) $report['discount_gross'], 2);
      $report['group_gross'] = round((float) $report['group_gross'], 2);
      $report['live_gross'] = round((float) $report['live_gross'], 2);
      $report['gross_total'] = round((float) $report['gross_total'], 2);
      $report['concessions_total'] = round((float) ($report['concessions_total'] ?? 0), 2);
      unset($report['_start_at']);
    }
    unset($report);

    if (self::contains_refund_correction(array_values($reports))) {
      $flagged = Store::flag_emailed_refund_changes($report_date, array_values($reports));
      if ($flagged) self::$refund_review_dates[$report_date] = true;
    }

    return array_values($reports);
  }

  private static function contains_refund_correction(array $reports): bool {
    foreach ($reports as $report) if (!empty($report['refund_adjusted'])) return true;
    return false;
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

    if (in_array($value, ['general', 'prepaid - general', 'adult', 'adults', 'prepaid - adult', 'prepaid - adults'], true)) {
      return 'general';
    }

    if ($value === 'discount' || $value === 'prepaid - discount') {
      return 'discount';
    }

    if ($value === 'group' || $value === 'subscriber') {
      return 'group';
    }

    if (in_array($value, ['free', 'free ticket', 'prepaid - free', 'prepaid - free ticket'], true)) {
      return 'live';
    }

    return '';
  }

  private static function is_movie_showing(int $post_id): bool {
    $profile = (string) get_post_meta($post_id, '_roxy_pricing_profile', true);
    return in_array($profile, ['movie_evening', 'movie_matinee'], true);
  }

  private static function is_live_showing(int $post_id): bool {
    return (string) get_post_meta($post_id, '_roxy_pricing_profile', true) === 'live_event';
  }

  private static function showings_for_date(string $report_date, string $mode = 'movie'): array {
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
      $post_id = (int) $post_id;
      $allowed = $mode === 'live' ? self::is_live_showing($post_id) : self::is_movie_showing($post_id);
      if (!$allowed) {
        continue;
      }

      $start_raw = (string) get_post_meta($post_id, '_roxy_start', true);
      if ($start_raw === '') {
        continue;
      }

      try {
        $start_at = new \DateTimeImmutable($start_raw, $timezone);
      } catch (\Throwable $e) {
        continue;
      }

      $showings[] = [
        'id' => $post_id,
        'title' => trim((string) get_the_title($post_id)) ?: ($mode === 'live' ? 'Unknown Live Show' : 'Unknown Film'),
        'start_at' => $start_at,
        'time_label' => $start_at->format('g:i A'),
        'live_label_1' => (string) get_post_meta($post_id, '_roxy_live_label_1', true),
        'live_label_2' => (string) get_post_meta($post_id, '_roxy_live_label_2', true),
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

  private static function is_live_door_line_item(array $line_item, array $showings): bool {
    $variation = strtolower(trim((string) ($line_item['variation_name'] ?? '')));
    $name = strtolower(trim((string) ($line_item['name'] ?? '')));
    $value = trim($variation !== '' ? $variation : $name);
    if ($value === '') {
      return false;
    }

    if (str_contains($value, 'subscriber') || str_contains($value, 'adult') || str_contains($value, 'discount') || str_contains($value, 'matinee') || str_contains($value, 'prepaid')) {
      return false;
    }

    $labels = [];
    foreach ($showings as $showing) {
      foreach (['live_label_1', 'live_label_2'] as $key) {
        $label = strtolower(trim((string) ($showing[$key] ?? '')));
        if ($label !== '') {
          $labels[$label] = true;
        }
      }
    }

    foreach (array_keys($labels) as $label) {
      if ($label !== '' && ($value === $label || str_contains($value, $label) || str_contains($label, $value))) {
        return true;
      }
    }

    return str_contains($value, 'live') || str_contains($value, 'vip') || str_contains($value, 'general admission');
  }

  private static function square_line_item_total(array $line_item, int $qty): float {
    // Square aggregate money fields already include all units in the line.
    return round(self::square_line_item_total_cents($line_item) / 100, 2);
  }

  private static function square_line_item_total_cents(array $line_item): int {
    $total = self::square_money_cents($line_item, 'total_money');
    if ($total !== null) return $total;
    return self::square_line_item_concession_cents($line_item) + (self::square_money_cents($line_item, 'total_tax_money') ?? 0);
  }

  private static function square_money_cents(array $line_item, string $key): ?int {
    if (!array_key_exists($key, $line_item)) return null;
    $money=$line_item[$key];
    if (!is_array($money) || !isset($money['amount']) || !is_int($money['amount']) || $money['amount'] < 0 || (isset($money['currency']) && $money['currency'] !== 'USD')) {
      throw new \RuntimeException('Square returned invalid or unsupported line-item money. No financial report was calculated.');
    }
    return $money['amount'];
  }

  private static function square_line_item_concession_cents(array $line_item): int {
    // Actual collected line revenue excluding sales tax; allocated charges retained.
    // US gross_sales_money already excludes tax, but has not deducted discounts.
    $total=self::square_money_cents($line_item, 'total_money');
    if ($total !== null) return max(0, $total - (self::square_money_cents($line_item, 'total_tax_money') ?? 0));
    $gross=self::square_money_cents($line_item, 'gross_sales_money');
    if ($gross !== null) return max(0, $gross - (self::square_money_cents($line_item, 'total_discount_money') ?? 0) + (self::square_money_cents($line_item, 'total_service_charge_money') ?? 0));
    // Legacy aggregate fallback is safe only when no inclusive/exclusive tax ambiguity exists.
    $aggregate=self::square_money_cents($line_item, 'total_base_price_money');
    if ($aggregate !== null && (self::square_money_cents($line_item, 'total_tax_money') ?? 0) === 0) {
      return max(0, $aggregate - (self::square_money_cents($line_item, 'total_discount_money') ?? 0) + (self::square_money_cents($line_item, 'total_service_charge_money') ?? 0));
    }
    throw new \RuntimeException('Square line-item totals are missing or ambiguous. No financial report was calculated.');
  }

  private static function is_concession_line_item(array $line_item, array $showings = [], array $category_map = []): bool {
    $item_type = strtoupper(trim((string) ($line_item['item_type'] ?? '')));
    if ($item_type !== 'ITEM') {
      return false;
    }

    if (self::classify_ticket_variation($line_item) !== '') {
      return false;
    }

    if ($showings && self::is_live_door_line_item($line_item, $showings)) {
      return false;
    }

    $name = strtolower(trim((string) ($line_item['name'] ?? '')));
    if ($name === 'tickets') {
      return false;
    }

    $catalog_object_id = trim((string) ($line_item['catalog_object_id'] ?? ''));
    if ($catalog_object_id !== '') {
      return Square::is_in_store_purchase_item($catalog_object_id, $category_map)
        && self::square_line_item_concession_cents($line_item) > 0;
    }

    return false;
  }

  private static function rebalance_concessions_for_report_dates(array $reports, string $requested_date): void {
    $dates = [$requested_date => true];
    foreach ($reports as $report) {
      $date = (string) ($report['report_date'] ?? '');
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $dates[$date] = true;
    }
    $dates = array_keys($dates);
    sort($dates, SORT_STRING);
    foreach ($dates as $date) self::rebalance_concessions_for_date($date);
  }

  private static function rebalance_concessions_for_date(string $report_date): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
      return ['rows' => 0, 'updated' => 0, 'concessions_total' => 0.0];
    }
    return Store::with_concession_allocation_lock($report_date, static fn(): array => self::rebalance_concessions_for_date_locked($report_date));
  }

  private static function rebalance_concessions_for_date_locked(string $report_date): array {
    $reports = [];
    foreach (Store::list_all_entries_for_rebalance('movie', $report_date) as $row) {
      $entry_id = (int) ($row['id'] ?? 0);
      if ($entry_id <= 0) {
        continue;
      }
      $reports[$entry_id] = [
        '_entry_kind' => 'movie',
        '_entry_id' => $entry_id,
        '_start_at' => self::start_at_for_entry_row($report_date, (string) ($row['show_time'] ?? '')),
        'show_time' => (string) ($row['show_time'] ?? ''),
        'general_qty' => max(0, (int) ($row['general_qty'] ?? 0)),
        'discount_qty' => max(0, (int) ($row['discount_qty'] ?? 0)),
        'group_qty' => max(0, (int) ($row['group_qty'] ?? 0)),
        '_is_locked' => !empty($row['is_locked']),
        'concessions_total' => !empty($row['is_locked']) ? round((float) ($row['concessions_total'] ?? 0), 2) : 0.0,
      ];
    }

    foreach (Store::list_all_entries_for_rebalance('live', $report_date) as $row) {
      $entry_id = (int) ($row['id'] ?? 0);
      if ($entry_id <= 0) {
        continue;
      }
      $reports[100000000 + $entry_id] = [
        '_entry_kind' => 'live',
        '_entry_id' => $entry_id,
        '_start_at' => self::start_at_for_entry_row($report_date, (string) ($row['show_time'] ?? '')),
        'show_time' => (string) ($row['show_time'] ?? ''),
        'presale_qty' => max(0, (int) ($row['presale_qty'] ?? 0)),
        'online_qty' => max(0, (int) ($row['online_qty'] ?? 0)),
        'door_qty' => max(0, (int) ($row['door_qty'] ?? 0)),
        'group_sub_qty' => max(0, (int) ($row['group_sub_qty'] ?? 0)),
        '_is_locked' => !empty($row['is_locked']),
        'concessions_total' => !empty($row['is_locked']) ? round((float) ($row['concessions_total'] ?? 0), 2) : 0.0,
      ];
    }

    foreach (Store::list_all_entries_for_rebalance('rental', $report_date) as $row) {
      $entry_id = (int) ($row['id'] ?? 0);
      if ($entry_id <= 0) {
        continue;
      }
      $reports[200000000 + $entry_id] = [
        '_entry_kind' => 'rental',
        '_entry_id' => $entry_id,
        '_start_at' => self::start_at_for_entry_row($report_date, (string) ($row['show_time'] ?? '')),
        'show_time' => (string) ($row['show_time'] ?? ''),
        'concessions_total' => !empty($row['is_locked']) ? round((float) ($row['concessions_total'] ?? 0), 2) : 0.0,
        '_is_locked' => !empty($row['is_locked']),
      ];
    }

    if (!$reports) {
      return ['rows' => 0, 'updated' => 0, 'concessions_total' => 0.0];
    }

    self::apply_concessions_to_reports($reports, $report_date, self::showings_for_date($report_date, 'live'));

    return Store::with_concession_allocation_transaction(static function () use ($reports, $report_date): array {
      $updated = 0;
      $concessions_total = 0.0;
      foreach ($reports as $report) {
        $entry_id = (int) ($report['_entry_id'] ?? 0);
        $kind = (string) ($report['_entry_kind'] ?? '');
        $concessions = round((float) ($report['concessions_total'] ?? 0), 2);
        if ($entry_id <= 0) continue;
        if (!empty($report['_is_locked'])) {
          $concessions_total += $concessions;
          continue;
        }
        $saved = $kind === 'movie'
          ? Store::update_entry($entry_id, ['concessions_total' => $concessions])
          : ($kind === 'live'
            ? Store::update_live_entry($entry_id, ['concessions_total' => $concessions])
            : Store::update_rental_entry($entry_id, ['concessions_total' => $concessions]));
        if (!$saved) throw new \RuntimeException('Could not save the concessions allocation for ' . $report_date . '. The report refresh was not completed.');
        $updated++;
        $concessions_total += $concessions;
      }
      return ['rows' => count($reports), 'updated' => $updated, 'concessions_total' => round($concessions_total, 2)];
    });
  }

  private static function apply_concessions_to_reports(array &$reports, string $report_date, array $showings = []): void {
    if (!$reports) {
      return;
    }

    $orders = Square::fetch_orders_for_date($report_date);
    $catalog_object_ids = [];
    $provisional = [];
    $eligible_cents = 0;
    $locked_cents = 0;
    foreach ($reports as $entry_id => $report) {
      if (!empty($report['_is_locked'])) {
        $fixed = round((float) ($report['concessions_total'] ?? 0) * 100);
        if (!is_finite((float) ($report['concessions_total'] ?? 0)) || $fixed < 0 || $fixed > PHP_INT_MAX - $locked_cents) throw new \RuntimeException('A locked concessions amount is invalid; review this report day manually.');
        $locked_cents += (int) $fixed;
      } else $reports[$entry_id]['concessions_total'] = 0.0;
    }

    foreach ($orders as $order) {
      foreach ((array) ($order['line_items'] ?? []) as $line_item) {
        $catalog_object_id = trim((string) ($line_item['catalog_object_id'] ?? ''));
        if ($catalog_object_id !== '') {
          $catalog_object_ids[] = $catalog_object_id;
        }
      }
    }
    $category_map = Square::concession_reporting_categories($catalog_object_ids);

    foreach ($orders as $order) {
      $order_closed_at = self::order_closed_at($order);
      foreach ((array) ($order['line_items'] ?? []) as $line_item) {
        if (!self::is_concession_line_item($line_item, $showings, $category_map)) {
          continue;
        }

        $line_total_cents = self::square_line_item_concession_cents($line_item);
        if ($line_total_cents <= 0) {
          continue;
        }

        $candidate_ids = $order_closed_at ? self::matching_entry_ids_for_order_time($order_closed_at, $reports) : [];
        if (!$candidate_ids) {
          continue;
        }

        if (!$candidate_ids) continue;
        if ($line_total_cents > PHP_INT_MAX - $eligible_cents) throw new \RuntimeException('Daily concessions exceed the supported allocation range.');
        $eligible_cents += $line_total_cents;
        $candidate_ids = array_values(array_filter($candidate_ids, static fn(int $entry_id): bool => empty($reports[$entry_id]['_is_locked'])));
        if (!$candidate_ids) continue;
        foreach (self::distribute_amount_cents_across_rows($line_total_cents, $candidate_ids, $reports) as $entry_id => $allocated_cents) {
          if ($allocated_cents <= 0 || !isset($reports[$entry_id])) continue;
          if ($allocated_cents > PHP_INT_MAX - ($provisional[$entry_id] ?? 0)) throw new \RuntimeException('A concessions allocation exceeds the supported amount range.');
          $provisional[$entry_id] = ($provisional[$entry_id] ?? 0) + $allocated_cents;
        }
      }
    }

    if ($locked_cents > $eligible_cents) throw new \RuntimeException('Locked concessions exceed the matching Square total; review the protected rows before refreshing.');
    if ($eligible_cents <= 0) return;
    $remaining_cents = $eligible_cents - $locked_cents;
    if ($remaining_cents === 0) return;
    $provisional_total = array_sum($provisional);
    if ($provisional_total !== $eligible_cents || !$provisional) throw new \RuntimeException('Concessions could not be fully matched to editable report rows; review this date manually.');
    $assigned = 0;
    $remainders = [];
    $allocated = [];
    foreach ($provisional as $entry_id => $cents) {
      $exact = ($cents / $provisional_total) * $remaining_cents;
      $whole = (int) floor($exact);
      $allocated[$entry_id] = $whole;
      $assigned += $whole;
      $remainders[$entry_id] = $exact - $whole;
    }
    arsort($remainders, SORT_NUMERIC);
    foreach (array_keys($remainders) as $entry_id) {
      if ($assigned >= $remaining_cents) break;
      $allocated[$entry_id]++;
      $assigned++;
    }
    foreach ($allocated as $entry_id => $cents) {
      $reports[$entry_id]['concessions_total'] = round((float) ($cents / 100), 2);
    }
  }

  private static function matching_entry_ids_for_order_time(\DateTimeImmutable $order_closed_at, array $reports): array {
    $matches = [];
    $best_distance = null;

    foreach ($reports as $entry_id => $report) {
      $start_at = $report['_start_at'] ?? null;
      if (!$start_at instanceof \DateTimeImmutable) {
        continue;
      }

      $window_start = $start_at->modify('-2 hours');
      $window_end = $start_at->modify('+2 hours');
      $order_local = $order_closed_at->setTimezone($start_at->getTimezone());
      if ($order_local < $window_start || $order_local > $window_end) {
        continue;
      }

      $distance = abs($order_local->getTimestamp() - $start_at->getTimestamp());
      if ($best_distance === null || $distance < $best_distance) {
        $best_distance = $distance;
        $matches = [(int) $entry_id];
      } elseif ($distance === $best_distance) {
        $matches[] = (int) $entry_id;
      }
    }

    if ($matches) {
      return array_values(array_unique($matches));
    }

    return [];
  }

  private static function distribute_quantity_across_rows(int $qty, array $candidate_ids, array $reports): array {
    $candidate_ids = array_values(array_filter(array_map('intval', $candidate_ids), static fn (int $id): bool => $id > 0 && isset($reports[$id])));
    if ($qty <= 0 || !$candidate_ids) {
      return [];
    }

    if (count($candidate_ids) === 1) {
      return [$candidate_ids[0] => $qty];
    }

    $weights = [];
    $weight_total = 0;
    foreach ($candidate_ids as $entry_id) {
      $weight = self::row_weight($reports[$entry_id]);
      $weights[$entry_id] = $weight;
      $weight_total += $weight;
    }

    if ($weight_total <= 0) {
      $base = intdiv($qty, count($candidate_ids));
      $remainder = $qty % count($candidate_ids);
      $allocation = [];
      foreach ($candidate_ids as $index => $entry_id) {
        $allocation[$entry_id] = $base + ($index < $remainder ? 1 : 0);
      }
      return $allocation;
    }

    $allocation = [];
    $assigned = 0;
    $fractions = [];
    foreach ($candidate_ids as $entry_id) {
      $exact = ($qty * $weights[$entry_id]) / $weight_total;
      $whole = (int) floor($exact);
      $allocation[$entry_id] = $whole;
      $assigned += $whole;
      $fractions[$entry_id] = $exact - $whole;
    }

    $remainder = $qty - $assigned;
    arsort($fractions);
    foreach (array_keys($fractions) as $entry_id) {
      if ($remainder <= 0) {
        break;
      }
      $allocation[$entry_id]++;
      $remainder--;
    }

    return $allocation;
  }

  private static function distribute_amount_cents_across_rows(int $amount_cents, array $candidate_ids, array $reports): array {
    $candidate_ids = array_values(array_filter(array_map('intval', $candidate_ids), static fn (int $id): bool => $id > 0 && isset($reports[$id])));
    if ($amount_cents <= 0 || !$candidate_ids) {
      return [];
    }

    if (count($candidate_ids) === 1) {
      return [$candidate_ids[0] => $amount_cents];
    }

    $weights = [];
    $weight_total = 0;
    foreach ($candidate_ids as $entry_id) {
      $weight = self::row_weight($reports[$entry_id]);
      $weights[$entry_id] = $weight;
      $weight_total += $weight;
    }

    if ($weight_total <= 0) {
      $base = intdiv($amount_cents, count($candidate_ids));
      $remainder = $amount_cents % count($candidate_ids);
      $allocation = [];
      foreach ($candidate_ids as $index => $entry_id) {
        $allocation[$entry_id] = $base + ($index < $remainder ? 1 : 0);
      }
      return $allocation;
    }

    $allocation = [];
    $assigned = 0;
    $fractions = [];
    foreach ($candidate_ids as $entry_id) {
      $exact = ($amount_cents * $weights[$entry_id]) / $weight_total;
      $whole = (int) floor($exact);
      $allocation[$entry_id] = $whole;
      $assigned += $whole;
      $fractions[$entry_id] = $exact - $whole;
    }

    $remainder = $amount_cents - $assigned;
    arsort($fractions);
    foreach (array_keys($fractions) as $entry_id) {
      if ($remainder <= 0) {
        break;
      }
      $allocation[$entry_id]++;
      $remainder--;
    }

    return $allocation;
  }

  private static function row_weight(array $report): int {
    if (array_key_exists('presale_qty', $report) || array_key_exists('online_qty', $report) || array_key_exists('door_qty', $report) || array_key_exists('group_sub_qty', $report)) {
      return max(0, (int) ($report['presale_qty'] ?? 0))
        + max(0, (int) ($report['online_qty'] ?? 0))
        + max(0, (int) ($report['door_qty'] ?? 0))
        + max(0, (int) ($report['group_sub_qty'] ?? 0));
    }

    return max(0, (int) ($report['general_qty'] ?? 0))
      + max(0, (int) ($report['discount_qty'] ?? 0))
      + max(0, (int) ($report['group_qty'] ?? 0));
  }

  private static function start_at_for_entry_row(string $report_date, string $show_time): ?\DateTimeImmutable {
    $show_time = trim($show_time);
    if ($show_time === '') {
      return null;
    }

    $timezone = new \DateTimeZone(Settings::get_report_timezone());
    foreach (['Y-m-d g:i A', 'Y-m-d g:ia', 'Y-m-d H:i'] as $format) {
      $start_at = \DateTimeImmutable::createFromFormat($format, $report_date . ' ' . $show_time, $timezone);
      if ($start_at instanceof \DateTimeImmutable) {
        return $start_at;
      }
    }

    return null;
  }

  private static function ticket_prices(): array {
    return [
      'general' => (float) Settings::get('general_price', '12'),
      'discount' => (float) Settings::get('discount_price', '8'),
      'group' => (float) Settings::get('group_price', '5'),
      'live' => 0.0,
    ];
  }

  private static function summarize_reports(array $reports): array {
    $summary = [
      'report_date' => $reports ? (string) $reports[count($reports) - 1]['report_date'] : '',
      'gross_total' => 0.0,
      'total_tickets' => 0,
      'paid_tickets' => 0,
    ];

    foreach ($reports as $report) {
      $paid_tickets = max(0, (int) ($report['general_qty'] ?? 0))
        + max(0, (int) ($report['discount_qty'] ?? 0))
        + max(0, (int) ($report['group_qty'] ?? 0));
      $summary['gross_total'] += (float) ($report['gross_total'] ?? 0);
      $summary['total_tickets'] += (int) ($report['total_tickets'] ?? 0);
      $summary['paid_tickets'] += $paid_tickets;
    }

    $summary['gross_total'] = round($summary['gross_total'], 2);
    return $summary;
  }

  private static function entries_from_report_rows(array $rows, string $source_type, string $source_ref, ?int $report_id): array {
    $entries = [];
    foreach ($rows as $row) {
      $entries[] = [
        'report_date' => (string) ($row['report_date'] ?? ''),
        'movie_title' => (string) ($row['film_title'] ?? ''),
        'show_time' => (string) ($row['show_time'] ?? ''),
        'showing_id' => max(0, (int) ($row['showing_id'] ?? 0)),
        'theater_name' => (string) ($row['theater_name'] ?? ''),
        'general_qty' => max(0, (int) ($row['general_qty'] ?? 0)),
        'discount_qty' => max(0, (int) ($row['discount_qty'] ?? 0)),
        'group_qty' => max(0, (int) ($row['group_qty'] ?? 0)),
        'subscriber_qty' => 0,
        'live_qty' => max(0, (int) ($row['live_qty'] ?? 0)),
        'other_qty' => 0,
        'total_tickets' => max(0, (int) ($row['total_tickets'] ?? 0)),
        'gross_total' => round((float) ($row['gross_total'] ?? 0), 2),
        'concessions_total' => round((float) ($row['concessions_total'] ?? 0), 2),
        'source_type' => $source_type,
        'source_ref' => $source_ref,
        'source_report_id' => $report_id,
        'notes' => 'Generated from Square daily grosses flow',
      ];
    }
    return $entries;
  }

  private static function send_email(array $reports, array $summary, string $mode = 'scheduled', ?string $send_key = null, string $kind = 'daily-grosses', int $source_id = 0, ?string $report_date = null, string $send_request_id = ''): array {
    $attachment = self::write_csv($reports);
    try {
      $is_test_send = $mode === 'manual-test';
      $is_provisional = $mode === 'scheduled-provisional';
      $to = $is_test_send ? self::test_email_list() : Settings::email_list();
      if (!$to) {
        return [
          'success' => false,
          'message' => $is_test_send
            ? 'No admin alert email is configured for test sends.'
            : 'No recipient emails are configured.',
        ];
      }

      $subject = self::expand_tokens((string) Settings::get('email_subject', ''), $summary);
      if ($is_provisional) $subject = '[PROVISIONAL — CLOSED-DAY REFRESH PENDING] ' . $subject;
      if ($is_test_send) {
        $subject = '[TEST] ' . $subject;
      }
      $body = self::expand_tokens((string) Settings::get('email_body', ''), $summary);
      if ($is_provisional) $body = "PROVISIONAL GROSSES: this is the initial scheduled snapshot for the reporting day. A date-based after-midnight refresh will check for later Square sales and flag changes for manager review; no corrected report is sent automatically.\n\n" . $body;
      if ($is_test_send) {
        $body = "This is a test grosses email sent only to the configured admin alert address.\n\n" . $body;
      }
      $body .= "\n\nReport rows\n";
        foreach ($reports as $report) {
          $paid_tickets = max(0, (int) ($report['general_qty'] ?? 0))
            + max(0, (int) ($report['discount_qty'] ?? 0))
            + max(0, (int) ($report['group_qty'] ?? 0));
          $body .= sprintf(
            "%s %s | %s | General %d | Discount %d | Group %d | Total %d | Gross $%s\n",
            $report['report_date'],
            $report['show_time'],
            $report['film_title'],
            (int) $report['general_qty'],
            (int) $report['discount_qty'],
            (int) $report['group_qty'],
            $paid_tickets,
            number_format((float) $report['gross_total'], 2)
          );
        }

      $request_id = self::valid_send_request_id($send_request_id) ? $send_request_id : self::new_send_request_id();
      if ($send_key === null) {
        if (in_array($mode, ['manual', 'scheduled', 'scheduled-provisional'], true)
          && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $report_date)) {
          // Manual and scheduled sends for one reporting date share one durable key.
          $send_key = 'daily-grosses:' . $report_date;
        } else {
          // Tests and ad-hoc non-production modes remain independently runnable.
          $send_key = 'daily-grosses:' . $mode . ':' . $request_id;
        }
      }
      try {
        $claim = EmailOutbox::claim($send_key, $kind, $source_id, $report_date, [
          'to' => $to,
          'subject' => $subject,
          'body' => $body,
          'attachment_name' => basename($attachment),
          'attachment_sha256' => is_file($attachment) ? hash_file('sha256', $attachment) : '',
        ], ['mode' => $mode, 'request_id' => $request_id]);
      } catch (\Throwable $error) {
        return ['success' => false, 'message' => 'Email was not attempted because the durable send guard is unavailable: ' . $error->getMessage()];
      }
      if (empty($claim['claimed'])) {
        $state = (string) ($claim['status'] ?? 'unknown');
        return $state === 'accepted'
          ? ['success' => true, 'already_accepted' => true, 'message' => 'This logical report was already accepted by WordPress for sending; no duplicate was sent.']
          : ['success' => false, 'message' => 'A prior send attempt is ' . $state . '. It was not sent again; review the outbox/logs before taking action.'];
      }

      try {
        $sent = wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8'], [$attachment]);
      } catch (\Throwable $error) {
        EmailOutbox::finish((int) $claim['id'], 'uncertain', 'wp_mail threw: ' . substr($error->getMessage(), 0, 1500));
        return ['success' => false, 'message' => 'Mail handling ended unexpectedly. The send is marked uncertain and will not be retried automatically. Review the outbox before acting.'];
      }

      if (!$sent) {
        EmailOutbox::finish((int) $claim['id'], 'uncertain', 'wp_mail returned false; delivery outcome may be ambiguous.');
        return [
          'success' => false,
          'message' => 'WordPress did not confirm the grosses email. The attempt is marked uncertain and will not be retried automatically.',
        ];
      }
      if (!EmailOutbox::finish((int) $claim['id'], 'accepted')) {
        return ['success' => false, 'message' => 'WordPress accepted the email, but the outbox could not record that result. Do not resend; review the outbox before acting.'];
      }

      return [
        'success' => true,
        'message' => 'Report sent to ' . implode(', ', $to) . '.',
      ];
    } finally {
      self::remove_csv_attachment($attachment);
    }
  }

  private static function valid_send_request_id(string $request_id): bool {
    return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $request_id);
  }

  private static function new_send_request_id(): string {
    if (function_exists('wp_generate_uuid4')) return (string) wp_generate_uuid4();
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
  }

  private static function test_email_list(): array {
    $admin_email = Settings::admin_email();
    if ($admin_email === '') {
      return [];
    }

    return [$admin_email];
  }

  private static function send_live_grosses_email(array $row, array $recipients, bool $include_concessions, string $mode = 'manual-live-email', string $send_request_id = ''): array {
    $attachment = self::write_live_csv($row, $include_concessions);
    try {
      $show_title = (string) ($row['show_title'] ?? 'Live Show');
      $report_date = (string) ($row['report_date'] ?? '');
      $show_time = (string) ($row['show_time'] ?? '');
      $ticket_gross = round((float) ($row['gross_total'] ?? 0), 2);
      $concessions = round((float) ($row['concessions_total'] ?? 0), 2);
      $is_test_send = $mode === 'manual-live-test';

      $subject = sprintf('Roxy live grosses for %s on %s', $show_title, $report_date);
      if ($is_test_send) {
        $subject = '[TEST] ' . $subject;
      }
      $body = Settings::get('theater_name', 'Newport Roxy Theater') . "\n";
      if ($is_test_send) {
        $body .= "This is a test live grosses email sent only to the configured admin alert address.\n\n";
      }
      $body .= "Live show grosses\n\n";
      $body .= sprintf("Show: %s\n", $show_title);
      $body .= sprintf("Date: %s\n", $report_date);
      $body .= sprintf("Show time: %s\n\n", $show_time);
      $body .= sprintf("Presale tickets: %s\n", number_format_i18n((int) ($row['presale_qty'] ?? 0)));
      $body .= sprintf("Online tickets: %s\n", number_format_i18n((int) ($row['online_qty'] ?? 0)));
      $body .= sprintf("Door tickets: %s\n", number_format_i18n((int) ($row['door_qty'] ?? 0)));
      $body .= sprintf("Group/subscriber: %s\n", number_format_i18n((int) ($row['group_sub_qty'] ?? 0)));
      $body .= sprintf("Total attendance: %s\n", number_format_i18n((int) ($row['total_tickets'] ?? 0)));
      $body .= sprintf("Ticket gross: $%s\n", number_format($ticket_gross, 2));
      if ($include_concessions) {
        $body .= sprintf("Concessions gross: $%s\n", number_format($concessions, 2));
        $body .= sprintf("Combined gross: $%s\n", number_format($ticket_gross + $concessions, 2));
      }
      $body .= "\nGenerated automatically by the Roxy Grosses plugin.";

      $request_id = self::valid_send_request_id($send_request_id) ? $send_request_id : self::new_send_request_id();
      $entry_id = max(0, (int) ($row['id'] ?? 0));
      $row_fingerprint = hash('sha256', (string) json_encode([$row, $include_concessions, $recipients], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      $send_key = $is_test_send
        ? 'live-grosses-test:' . $entry_id . ':' . $request_id
        : 'live-grosses:' . $entry_id . ':' . $row_fingerprint;
      try {
        $claim = EmailOutbox::claim($send_key, 'live_grosses', $entry_id, $report_date, [
          'to' => $recipients,
          'subject' => $subject,
          'body' => $body,
          'attachment_name' => basename($attachment),
          'attachment_sha256' => is_file($attachment) ? hash_file('sha256', $attachment) : '',
        ], ['mode' => $mode, 'request_id' => $request_id, 'include_concessions' => $include_concessions]);
      } catch (\Throwable $error) {
        return ['success' => false, 'message' => 'Email was not attempted because the durable send guard is unavailable: ' . $error->getMessage()];
      }
      if (empty($claim['claimed'])) {
        $state = (string) ($claim['status'] ?? 'unknown');
        if ($state === 'accepted') {
          $message = 'This live report was already accepted by WordPress for sending; no duplicate was sent.';
          Store::insert_log('send_live_grosses', $mode, null, $report_date, true, $message, ['live_entry_id' => $entry_id, 'duplicate_suppressed' => true]);
          return ['success' => true, 'message' => $message, 'duplicate_suppressed' => true];
        }
        return ['success' => false, 'message' => 'A prior live email attempt is ' . $state . '. It was not sent again; review the outbox/logs before acting.'];
      }
      try {
        $sent = wp_mail($recipients, $subject, $body, ['Content-Type: text/plain; charset=UTF-8'], [$attachment]);
      } catch (\Throwable $error) {
        EmailOutbox::finish((int) $claim['id'], 'uncertain', 'wp_mail threw: ' . substr($error->getMessage(), 0, 1500));
        return ['success' => false, 'message' => 'Mail handling ended unexpectedly. The send is marked uncertain and will not be retried automatically. Review the outbox before acting.'];
      }

      if (!$sent) {
        EmailOutbox::finish((int) $claim['id'], 'uncertain', 'wp_mail returned false; delivery outcome may be ambiguous.');
        Store::insert_log('send_live_grosses', $mode, null, $report_date, false, 'WordPress could not send the live grosses email.', [
          'live_entry_id' => (int) ($row['id'] ?? 0),
          'include_concessions' => $include_concessions,
        ]);
        return [
          'success' => false,
          'message' => 'WordPress could not send the live grosses email.',
        ];
      }
      if (!EmailOutbox::finish((int) $claim['id'], 'accepted')) {
        return ['success' => false, 'message' => 'WordPress accepted the live email, but the outbox could not record that result. Do not resend; review the outbox before acting.'];
      }

      Store::insert_log('send_live_grosses', $mode, null, $report_date, true, 'Live grosses email sent to ' . implode(', ', $recipients) . '.', [
        'live_entry_id' => (int) ($row['id'] ?? 0),
        'include_concessions' => $include_concessions,
      ]);

      return [
        'success' => true,
        'message' => 'Live grosses email sent to ' . implode(', ', $recipients) . '.',
      ];
    } finally {
      self::remove_csv_attachment($attachment);
    }
  }

  private static function expand_tokens(string $template, array $summary): string {
      return strtr($template, [
        '{report_date}' => (string) ($summary['report_date'] ?? ''),
        '{theater_name}' => (string) Settings::get('theater_name', 'Newport Roxy Theater'),
        '{gross_total}' => number_format((float) ($summary['gross_total'] ?? 0), 2),
        '{ticket_total}' => number_format((int) ($summary['paid_tickets'] ?? $summary['total_tickets'] ?? 0)),
      ]);
    }

  private static function write_csv(array $reports): string {
    $latest_date = $reports ? (string) $reports[count($reports) - 1]['report_date'] : wp_date('Y-m-d');
    return self::write_private_csv('grosses-' . $latest_date . '.csv', static function ($handle) use ($reports): void {

      $total_general = 0;
      $total_discount = 0;
      $total_group = 0;
      $total_paid = 0;
      $total_gross = 0.0;

      self::put_attachment_row($handle, ['Report Date', 'Show Time', 'Theater', 'Film Title', 'General', 'Discount', 'Group', 'Total Tickets', 'Gross']);
      foreach ($reports as $report) {
        $general_qty = max(0, (int) ($report['general_qty'] ?? 0));
        $discount_qty = max(0, (int) ($report['discount_qty'] ?? 0));
        $group_qty = max(0, (int) ($report['group_qty'] ?? 0));
        $paid_tickets = $general_qty + $discount_qty + $group_qty;
        $gross_total = round((float) ($report['gross_total'] ?? 0), 2);

        $total_general += $general_qty;
        $total_discount += $discount_qty;
        $total_group += $group_qty;
        $total_paid += $paid_tickets;
        $total_gross += $gross_total;

        self::put_attachment_row($handle, [
          $report['report_date'],
          $report['show_time'],
          $report['theater_name'] ?? (string) Settings::get('theater_name', 'Newport Roxy Theater'),
          $report['film_title'],
          $general_qty,
          $discount_qty,
          $group_qty,
          $paid_tickets,
          '$' . number_format($gross_total, 2, '.', ''),
        ]);
      }

      self::put_attachment_row($handle, [
        'Total',
        '',
        '',
        '',
        $total_general,
        $total_discount,
        $total_group,
        $total_paid,
        '$' . number_format($total_gross, 2, '.', ''),
      ]);

    });
  }

  private static function write_live_csv(array $row, bool $include_concessions): string {
    $report_date = (string) ($row['report_date'] ?? wp_date('Y-m-d'));
    $safe_title = sanitize_title((string) ($row['show_title'] ?? 'live-show'));
    $filename = 'live-grosses-' . $report_date . '-' . ($safe_title !== '' ? $safe_title : 'live-show') . '.csv';
    return self::write_private_csv($filename, static function ($handle) use ($row, $include_concessions, $report_date): void {

      $header = ['Report Date', 'Show Time', 'Show', 'Presale Tickets', 'Online Tickets', 'Door Tickets', 'Group/Subscriber', 'Total Attendance', 'Ticket Gross'];
      $record = [
        $report_date,
        (string) ($row['show_time'] ?? ''),
        (string) ($row['show_title'] ?? ''),
        (int) ($row['presale_qty'] ?? 0),
        (int) ($row['online_qty'] ?? 0),
        (int) ($row['door_qty'] ?? 0),
        (int) ($row['group_sub_qty'] ?? 0),
        (int) ($row['total_tickets'] ?? 0),
        '$' . number_format((float) ($row['gross_total'] ?? 0), 2, '.', ''),
      ];

      if ($include_concessions) {
        $ticket_gross = round((float) ($row['gross_total'] ?? 0), 2);
        $concessions = round((float) ($row['concessions_total'] ?? 0), 2);
        $header[] = 'Concessions Gross';
        $header[] = 'Combined Gross';
        $record[] = '$' . number_format($concessions, 2, '.', '');
        $record[] = '$' . number_format($ticket_gross + $concessions, 2, '.', '');
      }

      self::put_attachment_row($handle, $header);
      self::put_attachment_row($handle, $record);
    });
  }

  /** Only files created by this request may be removed by the attachment cleanup. */
  private static array $csv_attachments = [];

  private static function write_private_csv(string $filename, callable $writer): string {
    $temp = realpath(sys_get_temp_dir());
    if ($temp === false || !is_dir($temp) || !is_writable($temp)) {
      throw new \RuntimeException('Private report temporary storage is unavailable.');
    }
    foreach ([ABSPATH, defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH] as $web_root) {
      $root = realpath($web_root);
      if ($root !== false && ($temp === $root || str_starts_with($temp . DIRECTORY_SEPARATOR, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
        throw new \RuntimeException('Report temporary storage must be outside the website.');
      }
    }
    $dir = $temp . DIRECTORY_SEPARATOR . 'roxy-grosses-' . bin2hex(random_bytes(16));
    if (!mkdir($dir, 0700)) {
      throw new \RuntimeException('Unable to create private report storage.');
    }
    $stem = (string) preg_replace('/\.csv$/i', '', $filename);
    $filename = substr((string) preg_replace('/[^a-zA-Z0-9._-]/', '-', $stem), 0, 176) . '.csv';
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    self::$csv_attachments[$path] = $dir;
    register_shutdown_function(static function () use ($path): void { self::remove_csv_attachment($path); });
    $handle = null;
    try {
      if (!chmod($dir, 0700)) throw new \RuntimeException('Unable to protect report storage.');
      $handle = fopen($path, 'x+b');
      if ($handle === false || !chmod($path, 0600)) throw new \RuntimeException('Unable to create private CSV attachment.');
      $writer($handle);
      if (!fflush($handle)) throw new \RuntimeException('Unable to finish writing CSV attachment.');
      $closed = fclose($handle);
      $handle = null;
      if (!$closed) throw new \RuntimeException('Unable to close CSV attachment.');
      return $path;
    } catch (\Throwable $error) {
      if (is_resource($handle)) fclose($handle);
      self::remove_csv_attachment($path);
      throw $error;
    }
  }

  private static function put_attachment_row($handle, array $row): void {
    if (fputcsv($handle, $row) === false) {
      throw new \RuntimeException('Unable to write CSV attachment.');
    }
  }

  private static function remove_csv_attachment(string $path): void {
    if (!isset(self::$csv_attachments[$path])) return;
    $dir = self::$csv_attachments[$path];
    if (is_file($path) && !@unlink($path)) {
      error_log('Roxy Grosses: private CSV cleanup failed.');
      return; // Keep ownership so shutdown can retry; never delete another run's file.
    }
    if (is_dir($dir) && !@rmdir($dir)) {
      error_log('Roxy Grosses: private CSV directory cleanup failed.');
      return;
    }
    unset(self::$csv_attachments[$path]);
  }

  public static function reconciliation_rows(string $date_from, string $date_to): array {
    return Square::with_sale_snapshot(static fn() => self::reconciliation_rows_snapshot($date_from, $date_to));
  }

  private static function reconciliation_rows_snapshot(string $date_from, string $date_to): array {
    $date_from = sanitize_text_field($date_from);
    $date_to = sanitize_text_field($date_to);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
      return [];
    }

    $start = new \DateTimeImmutable($date_from . ' 00:00:00', new \DateTimeZone(Settings::get_report_timezone()));
    $end = new \DateTimeImmutable($date_to . ' 00:00:00', new \DateTimeZone(Settings::get_report_timezone()));
    if ($end < $start) {
      [$start, $end] = [$end, $start];
    }

    $assigned = Store::concessions_by_date($start->format('Y-m-d'), $end->format('Y-m-d'));
    $rows = [];
    for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
      $date_key = $cursor->format('Y-m-d');
      $square_total = self::square_in_store_purchase_total_for_date($date_key);
      $assigned_row = $assigned[$date_key] ?? ['movies' => 0.0, 'live' => 0.0, 'rentals' => 0.0, 'assigned_total' => 0.0];
      $difference = round($square_total - (float) ($assigned_row['assigned_total'] ?? 0), 2);

      if (abs($square_total) < 0.01 && abs($difference) < 0.01) {
        continue;
      }

      $rows[] = [
        'date' => $date_key,
        'square_total' => round($square_total, 2),
        'movies' => round((float) ($assigned_row['movies'] ?? 0), 2),
        'live' => round((float) ($assigned_row['live'] ?? 0), 2),
        'rentals' => round((float) ($assigned_row['rentals'] ?? 0), 2),
        'assigned_total' => round((float) ($assigned_row['assigned_total'] ?? 0), 2),
        'difference' => $difference,
      ];
    }

    usort($rows, static function (array $left, array $right): int {
      return strcmp((string) $right['date'], (string) $left['date']);
    });

    return $rows;
  }

  private static function redirect_with_notice(string $status, string $message, string $tab = 'database', array $extra = []): void {
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

  private static function filters_for_dataset(string $dataset, array $source): array {
    switch ($dataset) {
      case 'live':
        return array_filter([
          'search' => isset($source['live_search']) ? sanitize_text_field(wp_unslash((string) $source['live_search'])) : '',
          'year' => isset($source['live_year']) ? max(0, (int) $source['live_year']) : 0,
          'month' => isset($source['live_month']) ? sanitize_text_field(wp_unslash((string) $source['live_month'])) : '',
          'day' => isset($source['live_day']) ? sanitize_text_field(wp_unslash((string) $source['live_day'])) : '',
          'date_from' => isset($source['live_from']) ? sanitize_text_field(wp_unslash((string) $source['live_from'])) : '',
          'date_to' => isset($source['live_to']) ? sanitize_text_field(wp_unslash((string) $source['live_to'])) : '',
        ]);
      case 'rentals':
        return array_filter([
          'search' => isset($source['rental_search']) ? sanitize_text_field(wp_unslash((string) $source['rental_search'])) : '',
          'year' => isset($source['rental_year']) ? max(0, (int) $source['rental_year']) : 0,
          'month' => isset($source['rental_month']) ? sanitize_text_field(wp_unslash((string) $source['rental_month'])) : '',
          'day' => isset($source['rental_day']) ? sanitize_text_field(wp_unslash((string) $source['rental_day'])) : '',
          'date_from' => isset($source['rental_from']) ? sanitize_text_field(wp_unslash((string) $source['rental_from'])) : '',
          'date_to' => isset($source['rental_to']) ? sanitize_text_field(wp_unslash((string) $source['rental_to'])) : '',
        ]);
      case 'legacy':
        return array_filter([
          'search' => isset($source['legacy_search']) ? sanitize_text_field(wp_unslash((string) $source['legacy_search'])) : '',
          'year' => isset($source['legacy_year']) ? max(0, (int) $source['legacy_year']) : 0,
          'month' => isset($source['legacy_month']) ? sanitize_text_field(wp_unslash((string) $source['legacy_month'])) : '',
          'day' => isset($source['legacy_day']) ? sanitize_text_field(wp_unslash((string) $source['legacy_day'])) : '',
          'date_from' => isset($source['legacy_from']) ? sanitize_text_field(wp_unslash((string) $source['legacy_from'])) : '',
          'date_to' => isset($source['legacy_to']) ? sanitize_text_field(wp_unslash((string) $source['legacy_to'])) : '',
        ]);
      default:
        return array_filter([
          'search' => isset($source['search']) ? sanitize_text_field(wp_unslash((string) $source['search'])) : '',
          'year' => isset($source['history_year']) ? max(0, (int) $source['history_year']) : 0,
          'month' => isset($source['history_month']) ? sanitize_text_field(wp_unslash((string) $source['history_month'])) : '',
          'day' => isset($source['history_day']) ? sanitize_text_field(wp_unslash((string) $source['history_day'])) : '',
          'date_from' => isset($source['history_from']) ? sanitize_text_field(wp_unslash((string) $source['history_from'])) : '',
          'date_to' => isset($source['history_to']) ? sanitize_text_field(wp_unslash((string) $source['history_to'])) : '',
        ]);
    }
  }

  private static function tab_for_dataset(string $dataset): string {
    return match ($dataset) {
      'live' => 'live-shows',
      'rentals' => 'rentals',
      'legacy' => 'legacy-weekly',
      default => 'database',
    };
  }

  private static function redirect_extra_for_dataset(string $dataset, array $source): array {
    return match ($dataset) {
      'live' => [
        'live_search' => isset($source['live_search']) ? sanitize_text_field(wp_unslash((string) $source['live_search'])) : '',
        'live_year' => isset($source['live_year']) ? max(0, (int) $source['live_year']) : 0,
        'live_month' => isset($source['live_month']) ? sanitize_text_field(wp_unslash((string) $source['live_month'])) : '',
        'live_day' => isset($source['live_day']) ? sanitize_text_field(wp_unslash((string) $source['live_day'])) : '',
        'live_from' => isset($source['live_from']) ? sanitize_text_field(wp_unslash((string) $source['live_from'])) : '',
        'live_to' => isset($source['live_to']) ? sanitize_text_field(wp_unslash((string) $source['live_to'])) : '',
      ],
      'rentals' => [
        'rental_search' => isset($source['rental_search']) ? sanitize_text_field(wp_unslash((string) $source['rental_search'])) : '',
        'rental_year' => isset($source['rental_year']) ? max(0, (int) $source['rental_year']) : 0,
        'rental_month' => isset($source['rental_month']) ? sanitize_text_field(wp_unslash((string) $source['rental_month'])) : '',
        'rental_day' => isset($source['rental_day']) ? sanitize_text_field(wp_unslash((string) $source['rental_day'])) : '',
        'rental_from' => isset($source['rental_from']) ? sanitize_text_field(wp_unslash((string) $source['rental_from'])) : '',
        'rental_to' => isset($source['rental_to']) ? sanitize_text_field(wp_unslash((string) $source['rental_to'])) : '',
      ],
      'legacy' => [
        'legacy_search' => isset($source['legacy_search']) ? sanitize_text_field(wp_unslash((string) $source['legacy_search'])) : '',
        'legacy_year' => isset($source['legacy_year']) ? max(0, (int) $source['legacy_year']) : 0,
        'legacy_month' => isset($source['legacy_month']) ? sanitize_text_field(wp_unslash((string) $source['legacy_month'])) : '',
        'legacy_day' => isset($source['legacy_day']) ? sanitize_text_field(wp_unslash((string) $source['legacy_day'])) : '',
        'legacy_from' => isset($source['legacy_from']) ? sanitize_text_field(wp_unslash((string) $source['legacy_from'])) : '',
        'legacy_to' => isset($source['legacy_to']) ? sanitize_text_field(wp_unslash((string) $source['legacy_to'])) : '',
      ],
      default => [
        'search' => isset($source['search']) ? sanitize_text_field(wp_unslash((string) $source['search'])) : '',
        'history_year' => isset($source['history_year']) ? max(0, (int) $source['history_year']) : 0,
        'history_month' => isset($source['history_month']) ? sanitize_text_field(wp_unslash((string) $source['history_month'])) : '',
        'history_day' => isset($source['history_day']) ? sanitize_text_field(wp_unslash((string) $source['history_day'])) : '',
        'history_from' => isset($source['history_from']) ? sanitize_text_field(wp_unslash((string) $source['history_from'])) : '',
        'history_to' => isset($source['history_to']) ? sanitize_text_field(wp_unslash((string) $source['history_to'])) : '',
      ],
    };
  }

  private static function log_sync_anomalies(string $report_date, string $mode, array $movie_rows, array $live_rows): void {
    $movie_zero_rows = array_values(array_filter($movie_rows, static function (array $row): bool {
      return (int) ($row['total_tickets'] ?? 0) === 0 && abs((float) ($row['concessions_total'] ?? 0)) < 0.01;
    }));
    if ($movie_zero_rows) {
      Store::insert_log('anomaly', $mode, null, $report_date, true, sprintf(
        'Movies sync found %d zero-activity row(s): %s',
        count($movie_zero_rows),
        implode(', ', array_map(static fn(array $row): string => trim(((string) ($row['film_title'] ?? '')) . ' ' . ((string) ($row['show_time'] ?? ''))), $movie_zero_rows))
      ));
    }

    $movie_concession_only_rows = array_values(array_filter($movie_rows, static function (array $row): bool {
      return (int) ($row['total_tickets'] ?? 0) === 0 && (float) ($row['concessions_total'] ?? 0) > 0;
    }));
    if ($movie_concession_only_rows) {
      Store::insert_log('anomaly', $mode, null, $report_date, true, sprintf(
        'Movies sync found %d concession-only row(s): %s',
        count($movie_concession_only_rows),
        implode(', ', array_map(static fn(array $row): string => trim(((string) ($row['film_title'] ?? '')) . ' ' . ((string) ($row['show_time'] ?? ''))), $movie_concession_only_rows))
      ), [
        'anomaly_type' => 'movie_concession_only',
        'rows' => array_map(static fn(array $row): array => [
          'title' => (string) ($row['film_title'] ?? ''),
          'show_time' => (string) ($row['show_time'] ?? ''),
          'concessions_total' => round((float) ($row['concessions_total'] ?? 0), 2),
        ], $movie_concession_only_rows),
      ]);
    }

    $live_zero_rows = array_values(array_filter($live_rows, static function (array $row): bool {
      return (int) ($row['total_tickets'] ?? 0) === 0 && abs((float) ($row['concessions_total'] ?? 0)) < 0.01;
    }));
    if ($live_zero_rows) {
      Store::insert_log('anomaly', $mode, null, $report_date, true, sprintf(
        'Live Shows sync found %d zero-activity row(s): %s',
        count($live_zero_rows),
        implode(', ', array_map(static fn(array $row): string => trim(((string) ($row['show_title'] ?? '')) . ' ' . ((string) ($row['show_time'] ?? ''))), $live_zero_rows))
      ));
    }

    $live_door_only_rows = array_values(array_filter($live_rows, static function (array $row): bool {
      return (int) ($row['door_qty'] ?? 0) > 0 && (int) ($row['presale_qty'] ?? 0) === 0 && (int) ($row['online_qty'] ?? 0) === 0;
    }));
    if ($live_door_only_rows) {
      Store::insert_log('anomaly', $mode, null, $report_date, true, sprintf(
        'Live Shows sync found %d door-only row(s): %s',
        count($live_door_only_rows),
        implode(', ', array_map(static fn(array $row): string => trim(((string) ($row['show_title'] ?? '')) . ' ' . ((string) ($row['show_time'] ?? ''))), $live_door_only_rows))
      ), [
        'anomaly_type' => 'live_door_only',
        'rows' => array_map(static fn(array $row): array => [
          'title' => (string) ($row['show_title'] ?? ''),
          'show_time' => (string) ($row['show_time'] ?? ''),
          'door_qty' => (int) ($row['door_qty'] ?? 0),
        ], $live_door_only_rows),
      ]);
    }

    $live_online_only_rows = array_values(array_filter($live_rows, static function (array $row): bool {
      return ((int) ($row['presale_qty'] ?? 0) + (int) ($row['online_qty'] ?? 0)) > 0 && (int) ($row['door_qty'] ?? 0) === 0 && (float) ($row['concessions_total'] ?? 0) <= 0;
    }));
    if ($live_online_only_rows) {
      Store::insert_log('anomaly', $mode, null, $report_date, true, sprintf(
        'Live Shows sync found %d online-only row(s) with no concessions: %s',
        count($live_online_only_rows),
        implode(', ', array_map(static fn(array $row): string => trim(((string) ($row['show_title'] ?? '')) . ' ' . ((string) ($row['show_time'] ?? ''))), $live_online_only_rows))
      ), [
        'anomaly_type' => 'live_online_only_no_concessions',
      ]);
    }

    $square_total = self::square_in_store_purchase_total_for_date($report_date);
    $assigned_total = 0.0;
    foreach ($movie_rows as $row) {
      $assigned_total += (float) ($row['concessions_total'] ?? 0);
    }
    foreach ($live_rows as $row) {
      $assigned_total += (float) ($row['concessions_total'] ?? 0);
    }
    $difference = round($square_total - $assigned_total, 2);
    if (abs($difference) >= 0.01) {
      Store::insert_log('anomaly', $mode, null, $report_date, true, sprintf(
        'Concessions mismatch on %s. Square shows $%s, assigned Movies + Live Shows total is $%s, difference $%s.',
        $report_date,
        number_format($square_total, 2),
        number_format($assigned_total, 2),
        number_format($difference, 2)
      ), [
        'anomaly_type' => 'concessions_mismatch',
        'square_total' => round($square_total, 2),
        'assigned_total' => round($assigned_total, 2),
        'difference' => $difference,
      ]);
    }

    if ($square_total > 0 && abs($assigned_total) < 0.01) {
      Store::insert_log('anomaly', $mode, null, $report_date, true, sprintf(
        'Square recorded $%s in concessions on %s, but nothing was assigned to Movies or Live Shows.',
        number_format($square_total, 2),
        $report_date
      ), [
        'anomaly_type' => 'unassigned_concessions_day',
        'square_total' => round($square_total, 2),
      ]);
    }
  }

  private static function square_in_store_purchase_total_for_date(string $report_date): float {
    $total_cents = 0;
    $orders = Square::fetch_orders_for_date($report_date);
    $catalog_ids = [];
    foreach ($orders as $order) {
      foreach ((array) ($order['line_items'] ?? []) as $line_item) {
        $catalog_object_id = (string) ($line_item['catalog_object_id'] ?? '');
        if ($catalog_object_id !== '') {
          $catalog_ids[] = $catalog_object_id;
        }
      }
    }
    $category_map = Square::concession_reporting_categories($catalog_ids);

    foreach ($orders as $order) {
      foreach ((array) ($order['line_items'] ?? []) as $line_item) {
        $catalog_object_id = (string) ($line_item['catalog_object_id'] ?? '');
        if ($catalog_object_id === '' || !Square::is_in_store_purchase_item($catalog_object_id, $category_map)) {
          continue;
        }
        $total_cents += self::square_line_item_concession_cents($line_item);
      }
    }

    return round($total_cents / 100, 2);
  }
}
