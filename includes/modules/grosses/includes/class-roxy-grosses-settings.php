<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Settings {
  public const OPTION_KEY = 'roxy_grosses_settings';
  public const STATUS_KEY = 'roxy_grosses_last_report';

  public static function init(): void {
    // admin_menu is registered by RoxySuite\Admin — not here
    add_action('admin_init', [__CLASS__, 'register_settings']);
  }

  public static function current_tab(): string {
    $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'reports';
    return in_array($tab, ['reports', 'settings', 'logs'], true) ? $tab : 'reports';
  }

  public static function defaults(): array {
    return [
      'square_environment' => 'production',
      'square_access_token' => '',
      'square_location_ids' => '',
      'report_timezone' => wp_timezone_string() ?: 'America/Los_Angeles',
      'ticket_keywords' => "ticket\nadmission",
      'exclude_keywords' => "popcorn\nsoda\ndrink\ncandy\nmembership",
      'film_mappings' => '',
      'recipient_emails' => 'comscore@example.com,lori@example.com',
      'admin_email' => get_option('admin_email'),
      'email_subject' => 'Roxy grosses for {report_date}',
      'email_body' => "Attached is the grosses report for {report_date}.\n\nGenerated automatically by the Roxy Grosses plugin.",
      'theater_name' => 'Newport Roxy Theater',
      'general_price' => '12',
      'discount_price' => '8',
      'group_price' => '5',
      'lookback_days' => '2',
      'schedule_enabled' => '1',
      'schedule_days' => ['fri', 'sat', 'sun'],
      'schedule_time' => '22:00',
    ];
  }

  public static function ensure_defaults(): void {
    $existing = get_option(self::OPTION_KEY, null);
    if ($existing === null) {
      add_option(self::OPTION_KEY, self::defaults());
    }
  }

  public static function get_all(): array {
    $saved = get_option(self::OPTION_KEY, []);
    if (!is_array($saved)) {
      $saved = [];
    }

    $all = wp_parse_args($saved, self::defaults());
    $all['schedule_days'] = self::sanitize_days($all['schedule_days'] ?? []);
    return $all;
  }

  public static function get(string $key, $default = '') {
    $all = self::get_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
  }

  public static function get_report_timezone(): string {
    $timezone = sanitize_text_field((string) self::get('report_timezone', wp_timezone_string() ?: 'America/Los_Angeles'));

    try {
      new \DateTimeZone($timezone);
      return $timezone;
    } catch (\Exception $e) {
      return wp_timezone_string() ?: 'America/Los_Angeles';
    }
  }

  public static function get_status(): array {
    $status = get_option(self::STATUS_KEY, []);
    return is_array($status) ? $status : [];
  }

  public static function set_status(array $status): void {
    update_option(self::STATUS_KEY, [
      'sent_at' => sanitize_text_field((string) ($status['sent_at'] ?? '')),
      'report_date' => sanitize_text_field((string) ($status['report_date'] ?? '')),
      'mode' => sanitize_text_field((string) ($status['mode'] ?? '')),
      'message' => sanitize_text_field((string) ($status['message'] ?? '')),
      'row_count' => max(0, (int) ($status['row_count'] ?? 0)),
      'gross_total' => round((float) ($status['gross_total'] ?? 0), 2),
    ]);
  }

  public static function register_settings(): void {
    register_setting(self::OPTION_KEY, self::OPTION_KEY, [
      'type' => 'array',
      'sanitize_callback' => [__CLASS__, 'sanitize'],
      'default' => self::defaults(),
    ]);

    add_settings_section(
      'roxy_grosses_square',
      'Square',
      function () {
        echo '<p>Connect to Square and define how ticket line items should be recognized.</p>';
      },
      'roxy-grosses'
    );

    add_settings_section(
      'roxy_grosses_email',
      'Email + Report',
      function () {
        echo '<p>Choose recipients and the contents of the generated grosses report email.</p>';
      },
      'roxy-grosses'
    );

    add_settings_section(
      'roxy_grosses_schedule',
      'Schedule',
      function () {
        echo '<p>Pick the automatic report days and send time. Use the manual form below for odd schedules.</p>';
      },
      'roxy-grosses'
    );

    $fields = [
      'square_environment' => ['label' => 'Square environment', 'section' => 'roxy_grosses_square'],
      'square_access_token' => ['label' => 'Square access token', 'section' => 'roxy_grosses_square'],
      'square_location_ids' => ['label' => 'Square location IDs', 'section' => 'roxy_grosses_square'],
      'report_timezone' => ['label' => 'Report timezone', 'section' => 'roxy_grosses_square'],
      'ticket_keywords' => ['label' => 'Ticket keywords', 'section' => 'roxy_grosses_square'],
      'exclude_keywords' => ['label' => 'Exclude keywords', 'section' => 'roxy_grosses_square'],
      'film_mappings' => ['label' => 'Film mappings', 'section' => 'roxy_grosses_square'],
      'recipient_emails' => ['label' => 'Recipient emails', 'section' => 'roxy_grosses_email'],
      'admin_email' => ['label' => 'Admin alert email', 'section' => 'roxy_grosses_email'],
      'email_subject' => ['label' => 'Email subject', 'section' => 'roxy_grosses_email'],
      'email_body' => ['label' => 'Email body', 'section' => 'roxy_grosses_email'],
      'theater_name' => ['label' => 'Theater name', 'section' => 'roxy_grosses_email'],
      'general_price' => ['label' => 'General ticket price', 'section' => 'roxy_grosses_email'],
      'discount_price' => ['label' => 'Discount ticket price', 'section' => 'roxy_grosses_email'],
      'group_price' => ['label' => 'Group ticket price', 'section' => 'roxy_grosses_email'],
      'lookback_days' => ['label' => 'Previous days to include', 'section' => 'roxy_grosses_email'],
      'schedule_enabled' => ['label' => 'Enable automatic sends', 'section' => 'roxy_grosses_schedule'],
      'schedule_days' => ['label' => 'Automatic days', 'section' => 'roxy_grosses_schedule'],
      'schedule_time' => ['label' => 'Automatic send time', 'section' => 'roxy_grosses_schedule'],
    ];

    foreach ($fields as $key => $field) {
      add_settings_field(
        $key,
        $field['label'],
        [__CLASS__, 'render_field'],
        'roxy-grosses',
        $field['section'],
        ['key' => $key]
      );
    }
  }

  public static function sanitize($input): array {
    $defaults = self::defaults();
    $input = is_array($input) ? $input : [];

    $sanitized = [
      'square_environment' => in_array(($input['square_environment'] ?? ''), ['production', 'sandbox'], true) ? $input['square_environment'] : $defaults['square_environment'],
      'square_access_token' => sanitize_text_field((string) ($input['square_access_token'] ?? $defaults['square_access_token'])),
      'square_location_ids' => self::sanitize_line_list((string) ($input['square_location_ids'] ?? $defaults['square_location_ids'])),
      'report_timezone' => self::sanitize_timezone((string) ($input['report_timezone'] ?? $defaults['report_timezone'])),
      'ticket_keywords' => self::sanitize_line_list((string) ($input['ticket_keywords'] ?? $defaults['ticket_keywords'])),
      'exclude_keywords' => self::sanitize_line_list((string) ($input['exclude_keywords'] ?? $defaults['exclude_keywords'])),
      'film_mappings' => self::sanitize_mappings((string) ($input['film_mappings'] ?? $defaults['film_mappings'])),
      'recipient_emails' => self::sanitize_email_list((string) ($input['recipient_emails'] ?? $defaults['recipient_emails'])),
      'admin_email' => sanitize_email((string) ($input['admin_email'] ?? $defaults['admin_email'])),
      'email_subject' => sanitize_text_field((string) ($input['email_subject'] ?? $defaults['email_subject'])),
      'email_body' => sanitize_textarea_field((string) ($input['email_body'] ?? $defaults['email_body'])),
      'theater_name' => sanitize_text_field((string) ($input['theater_name'] ?? $defaults['theater_name'])),
      'general_price' => wc_format_decimal((string) ($input['general_price'] ?? $defaults['general_price'])),
      'discount_price' => wc_format_decimal((string) ($input['discount_price'] ?? $defaults['discount_price'])),
      'group_price' => wc_format_decimal((string) ($input['group_price'] ?? $defaults['group_price'])),
      'lookback_days' => (string) max(0, (int) ($input['lookback_days'] ?? $defaults['lookback_days'])),
      'schedule_enabled' => !empty($input['schedule_enabled']) ? '1' : '0',
      'schedule_days' => self::sanitize_days($input['schedule_days'] ?? $defaults['schedule_days']),
      'schedule_time' => self::sanitize_time((string) ($input['schedule_time'] ?? $defaults['schedule_time'])),
    ];

    Scheduler::sync_schedule($sanitized);

    return $sanitized;
  }

  public static function render_field(array $args): void {
    $key = (string) ($args['key'] ?? '');
    $value = self::get($key, '');
    $name = self::OPTION_KEY . '[' . $key . ']';

    switch ($key) {
      case 'square_environment':
        echo '<select name="' . esc_attr($name) . '">';
        foreach (['production' => 'Production', 'sandbox' => 'Sandbox'] as $option_value => $label) {
          echo '<option value="' . esc_attr($option_value) . '" ' . selected($value, $option_value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Use sandbox only for testing with a Square sandbox token.</p>';
        return;

      case 'square_access_token':
        echo '<input type="password" class="regular-text code" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" autocomplete="off">';
        echo '<p class="description">Personal access token or OAuth token with Orders read access.</p>';
        return;

      case 'square_location_ids':
      case 'ticket_keywords':
      case 'exclude_keywords':
      case 'film_mappings':
      case 'email_body':
        echo '<textarea class="large-text code" rows="' . esc_attr($key === 'film_mappings' ? '8' : '5') . '" name="' . esc_attr($name) . '">' . esc_textarea((string) $value) . '</textarea>';
        if ($key === 'square_location_ids') {
          echo '<p class="description">One Square location ID per line. Square requires at least one location ID for order searches.</p>';
        } elseif ($key === 'ticket_keywords') {
          echo '<p class="description">One keyword per line. A line item must match at least one keyword to count as a ticket.</p>';
        } elseif ($key === 'exclude_keywords') {
          echo '<p class="description">One keyword per line. Matching line items are always ignored.</p>';
        } elseif ($key === 'film_mappings') {
          echo '<p class="description">One mapping per line in the format: match text|Comscore title|Film code. Match text is compared against the Square line item name.</p>';
        } else {
          echo '<p class="description">You can use {report_date}, {theater_name}, {gross_total}, and {ticket_total}.</p>';
        }
        return;

      case 'recipient_emails':
      case 'admin_email':
      case 'email_subject':
      case 'theater_name':
      case 'report_timezone':
      case 'lookback_days':
        echo '<input type="text" class="regular-text' . ($key === 'recipient_emails' ? ' code' : '') . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        if ($key === 'recipient_emails') {
          echo '<p class="description">Comma-separated email addresses.</p>';
        }
        if ($key === 'admin_email') {
          echo '<p class="description">Used for failure alerts when an automatic run or email send fails.</p>';
        }
        if ($key === 'report_timezone') {
          echo '<p class="description">Timezone used to decide the report day and Square date window, for example America/Los_Angeles.</p>';
        }
        if ($key === 'lookback_days') {
          echo '<p class="description">How many previous days to include if the same film played earlier. Example: 2 includes Friday and Saturday when sending Sunday.</p>';
        }
        return;

      case 'general_price':
      case 'discount_price':
      case 'group_price':
        echo '<input type="number" min="0" step="0.01" class="regular-text" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        return;

      case 'schedule_enabled':
        echo '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($value, '1', false) . '> Send reports automatically</label>';
        return;

      case 'schedule_days':
        $days = is_array($value) ? $value : [];
        $labels = [
          'mon' => 'Mon',
          'tue' => 'Tue',
          'wed' => 'Wed',
          'thu' => 'Thu',
          'fri' => 'Fri',
          'sat' => 'Sat',
          'sun' => 'Sun',
        ];
        foreach ($labels as $day => $label) {
          echo '<label style="display:inline-block; margin-right:12px;">';
          echo '<input type="checkbox" name="' . esc_attr($name) . '[]" value="' . esc_attr($day) . '" ' . checked(in_array($day, $days, true), true, false) . '> ' . esc_html($label);
          echo '</label>';
        }
        return;

      case 'schedule_time':
        echo '<input type="time" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        return;
    }
  }

  public static function render_page(): void {
    if (!current_user_can('manage_options')) {
      return;
    }

    $status = self::get_status();
    $default_date = wp_date('Y-m-d', null, new \DateTimeZone(self::get_report_timezone()));
    $tab = self::current_tab();
    $selected_report_id = isset($_GET['report_id']) ? max(0, (int) $_GET['report_id']) : 0;
    $selected_report = $selected_report_id > 0 ? Store::get_report($selected_report_id) : null;
    $saved_reports = Store::list_reports(50);
    $logs = Store::list_logs(100);

    echo '<div class="wrap">';
    echo '<h1>Roxy Grosses</h1>';
    echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
    echo '<a class="nav-tab ' . ($tab === 'reports' ? 'nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=roxy-grosses&tab=reports')) . '">Reports</a>';
    echo '<a class="nav-tab ' . ($tab === 'settings' ? 'nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=roxy-grosses&tab=settings')) . '">Settings</a>';
    echo '<a class="nav-tab ' . ($tab === 'logs' ? 'nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=roxy-grosses&tab=logs')) . '">Logs</a>';
    echo '</nav>';

    if (!empty($_GET['roxy_grosses_notice'])) {
      $notice = sanitize_text_field(wp_unslash((string) $_GET['roxy_grosses_notice']));
      $class = $notice === 'success' ? 'notice notice-success' : 'notice notice-error';
      $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash((string) $_GET['message'])) : '';
      echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }

    if (!empty($status['sent_at'])) {
      echo '<div class="notice notice-info"><p>';
      echo 'Last report: ' . esc_html($status['report_date'] ?: 'n/a');
      echo ' | Sent at: ' . esc_html($status['sent_at']);
      echo ' | Mode: ' . esc_html($status['mode'] ?: 'n/a');
      echo ' | Rows: ' . esc_html((string) ($status['row_count'] ?? 0));
      echo ' | Gross: $' . esc_html(number_format((float) ($status['gross_total'] ?? 0), 2));
      if (!empty($status['message'])) {
        echo ' | ' . esc_html($status['message']);
      }
      echo '</p></div>';
    }

    if ($tab === 'settings') {
      echo '<form method="post" action="options.php">';
      settings_fields(self::OPTION_KEY);
      do_settings_sections('roxy-grosses');
      submit_button('Save Grosses Settings');
      echo '</form>';
    } elseif ($tab === 'logs') {
      echo '<h2>Run Log</h2>';
      echo '<p>Review automatic runs, draft pulls, saved report emails, and failures.</p>';
      echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Time</th><th>Event</th><th>Mode</th><th>Report ID</th><th>End Date</th><th>Result</th><th>Message</th></tr></thead><tbody>';
      foreach ($logs as $log_row) {
        $report_label = !empty($log_row['report_id']) ? (string) $log_row['report_id'] : '—';
        echo '<tr>';
        echo '<td>' . esc_html((string) $log_row['created_at']) . '</td>';
        echo '<td>' . esc_html((string) $log_row['event_type']) . '</td>';
        echo '<td>' . esc_html((string) $log_row['mode']) . '</td>';
        echo '<td>' . esc_html($report_label) . '</td>';
        echo '<td>' . esc_html((string) ($log_row['report_end_date'] ?: '—')) . '</td>';
        echo '<td>' . (!empty($log_row['success']) ? 'Success' : 'Failed') . '</td>';
        echo '<td>' . esc_html((string) $log_row['message']) . '</td>';
        echo '</tr>';
      }
      if (!$logs) {
        echo '<tr><td colspan="7">No log entries yet.</td></tr>';
      }
      echo '</tbody></table>';
    } else {
      echo '<h2>Pull Report Data</h2>';
      echo '<p>Generate a saved draft report, review it, and email it when it looks right.</p>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('roxy_grosses_pull_report');
      echo '<input type="hidden" name="action" value="roxy_grosses_pull_report">';
      echo '<table class="form-table"><tbody>';
      echo '<tr><th scope="row"><label for="roxy-grosses-report-date">Report end date</label></th><td><input id="roxy-grosses-report-date" type="date" name="report_date" value="' . esc_attr($default_date) . '"></td></tr>';
      echo '</tbody></table>';
      submit_button('Pull And Save Draft', 'primary');
      echo '</form>';

      if ($selected_report) {
        echo '<hr>';
        echo '<h2>Review Saved Report #' . esc_html((string) $selected_report['id']) . '</h2>';
        echo '<p>Created: ' . esc_html((string) $selected_report['created_at']) . ' | Status: ' . esc_html((string) $selected_report['status']) . '</p>';
        echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Report Date</th><th>Show Time</th><th>Theater</th><th>Film Title</th><th>General</th><th>Discount</th><th>Group</th><th>Total Tickets</th><th>Gross</th></tr></thead><tbody>';
        foreach ((array) ($selected_report['rows'] ?? []) as $row) {
          echo '<tr>';
          echo '<td>' . esc_html((string) ($row['report_date'] ?? '')) . '</td>';
          echo '<td>' . esc_html((string) ($row['show_time'] ?? '')) . '</td>';
          echo '<td>' . esc_html((string) ($row['theater_name'] ?? '')) . '</td>';
          echo '<td>' . esc_html((string) ($row['film_title'] ?? '')) . '</td>';
          echo '<td>' . esc_html(number_format_i18n((int) ($row['general_qty'] ?? 0))) . '</td>';
          echo '<td>' . esc_html(number_format_i18n((int) ($row['discount_qty'] ?? 0))) . '</td>';
          echo '<td>' . esc_html(number_format_i18n((int) ($row['group_qty'] ?? 0))) . '</td>';
          echo '<td>' . esc_html(number_format_i18n((int) ($row['total_tickets'] ?? 0))) . '</td>';
          echo '<td>$' . esc_html(number_format((float) ($row['gross_total'] ?? 0), 2)) . '</td>';
          echo '</tr>';
        }
        echo '</tbody></table>';

        $summary = is_array($selected_report['summary'] ?? null) ? $selected_report['summary'] : [];
        echo '<p style="margin-top:12px;"><strong>Total Gross:</strong> $' . esc_html(number_format((float) ($summary['gross_total'] ?? 0), 2)) . ' | <strong>Total Tickets:</strong> ' . esc_html(number_format_i18n((int) ($summary['total_tickets'] ?? 0))) . '</p>';

        if (($selected_report['status'] ?? '') !== 'emailed') {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
          wp_nonce_field('roxy_grosses_send_saved_report');
          echo '<input type="hidden" name="action" value="roxy_grosses_send_saved_report">';
          echo '<input type="hidden" name="report_id" value="' . esc_attr((string) $selected_report['id']) . '">';
          submit_button('Email This Saved Report', 'secondary', 'submit', false);
          echo '</form>';
        }
      }

      echo '<hr>';
      echo '<h2>Saved Reports</h2>';
      echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>ID</th><th>End Date</th><th>Status</th><th>Rows</th><th>Tickets</th><th>Gross</th><th>Created</th><th>Action</th></tr></thead><tbody>';
      foreach ($saved_reports as $report_row) {
        $view_url = add_query_arg([
          'page' => 'roxy-grosses',
          'tab' => 'reports',
          'report_id' => (int) $report_row['id'],
        ], admin_url('admin.php'));
        echo '<tr>';
        echo '<td>' . esc_html((string) $report_row['id']) . '</td>';
        echo '<td>' . esc_html((string) $report_row['report_end_date']) . '</td>';
        echo '<td>' . esc_html((string) $report_row['status']) . '</td>';
        echo '<td>' . esc_html(number_format_i18n((int) $report_row['row_count'])) . '</td>';
        echo '<td>' . esc_html(number_format_i18n((int) $report_row['summary_tickets'])) . '</td>';
        echo '<td>$' . esc_html(number_format((float) $report_row['summary_gross'], 2)) . '</td>';
        echo '<td>' . esc_html((string) $report_row['created_at']) . '</td>';
        echo '<td><a class="button button-small" href="' . esc_url($view_url) . '">Review</a></td>';
        echo '</tr>';
      }
      if (!$saved_reports) {
        echo '<tr><td colspan="8">No saved reports yet.</td></tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';
  }

  public static function sanitize_line_list(string $value): string {
    $lines = preg_split('/[\r\n,]+/', $value);
    $lines = array_filter(array_map(static function ($line): string {
      return sanitize_text_field(trim((string) $line));
    }, (array) $lines));

    return implode("\n", array_values(array_unique($lines)));
  }

  public static function line_list(string $value): array {
    $lines = preg_split('/[\r\n,]+/', $value);
    return array_values(array_filter(array_map(static function ($line): string {
      return trim((string) $line);
    }, (array) $lines)));
  }

  public static function sanitize_email_list(string $value): string {
    $emails = preg_split('/[\r\n,;]+/', $value);
    $emails = array_filter(array_map(static function ($email): string {
      return sanitize_email(trim((string) $email));
    }, (array) $emails));

    return implode(',', array_values(array_unique($emails)));
  }

  public static function email_list(): array {
    $emails = explode(',', (string) self::get('recipient_emails', ''));
    return array_values(array_filter(array_map('sanitize_email', array_map('trim', $emails))));
  }

  public static function sanitize_mappings(string $value): string {
    $lines = preg_split('/\r\n|\r|\n/', $value);
    $clean = [];

    foreach ((array) $lines as $line) {
      $parts = array_map('trim', explode('|', (string) $line));
      if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
        continue;
      }

      $clean[] = sanitize_text_field($parts[0]) . '|' . sanitize_text_field($parts[1]) . '|' . sanitize_text_field($parts[2] ?? '');
    }

    return implode("\n", $clean);
  }

  public static function mappings(): array {
    $lines = preg_split('/\r\n|\r|\n/', (string) self::get('film_mappings', ''));
    $mappings = [];

    foreach ((array) $lines as $line) {
      $parts = array_map('trim', explode('|', (string) $line));
      if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
        continue;
      }

      $mappings[] = [
        'match' => mb_strtolower($parts[0]),
        'title' => $parts[1],
        'code' => $parts[2] ?? '',
      ];
    }

    return $mappings;
  }

  public static function sanitize_days($days): array {
    $allowed = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    $days = is_array($days) ? $days : [];
    $clean = [];

    foreach ($days as $day) {
      $day = strtolower(sanitize_text_field((string) $day));
      if (in_array($day, $allowed, true)) {
        $clean[] = $day;
      }
    }

    return array_values(array_unique($clean));
  }

  public static function sanitize_time(string $time): string {
    return preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '22:00';
  }

  public static function sanitize_timezone(string $timezone): string {
    $timezone = sanitize_text_field($timezone);

    try {
      new \DateTimeZone($timezone);
      return $timezone;
    } catch (\Exception $e) {
      return wp_timezone_string() ?: 'America/Los_Angeles';
    }
  }
}
