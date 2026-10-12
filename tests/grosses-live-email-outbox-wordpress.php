<?php
/**
 * WP-CLI integration fixture for the real live-Grosses email route.
 * Uses one random private outbox table and intercepts mail before transport.
 */
namespace RoxyGrosses {
  final class Settings {
    public static function get($key, $default = '') {
      if ($key === 'theater_name') return 'Roxy Fixture Theater';
      if ($key === 'admin_email') return 'grosses-admin@example.invalid';
      return $default;
    }
    public static function get_report_timezone(): string { return 'UTC'; }
    public static function email_list(): array { return ['grosses-admin@example.invalid']; }
  }

  final class Store {
    public static array $logs = [];
    public static function insert_log(...$args): int { self::$logs[] = $args; return 1; }
  }
}

namespace {
  if (!defined('WP_CLI') || !WP_CLI) exit;

  $root = $args[0] ?? dirname(__DIR__);
  $outbox_path = $root . '/includes/modules/grosses/includes/class-roxy-grosses-email-outbox.php';
  $reporter_path = $root . '/includes/modules/grosses/includes/class-roxy-grosses-reporter.php';
  if (!is_file($outbox_path) || !is_file($reporter_path)) throw new \RuntimeException('Grosses candidate files are missing.');
  require_once $outbox_path;
  require_once $reporter_path;

  global $wpdb;
  $original_db = $wpdb;
  $token = 'gxl_' . bin2hex(random_bytes(6));
  $private_prefix = $original_db->prefix . $token . '_';
  if (!preg_match('/^[A-Za-z0-9_]+$/', $private_prefix)) throw new \RuntimeException('Private outbox prefix is invalid.');
  $table = $private_prefix . \RoxyGrosses\EmailOutbox::TABLE;
  if (!preg_match('/^' . preg_quote($original_db->prefix, '/') . preg_quote($token, '/') . '_[A-Za-z0-9_]+$/', $table)) {
    throw new \RuntimeException('Refusing to use an outbox table outside the random private namespace.');
  }
  $fixture_db = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
  $fixture_db->suppress_errors(true);
  $fixture_db->prefix = $private_prefix;
  $created = false;
  $mail_calls = [];
  $checks = 0;
  $check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new \RuntimeException('FAIL: ' . $label);
    $checks++;
    echo "PASS: {$label}\n";
  };
  $mail_interceptor = static function ($pre, $atts) use (&$mail_calls) {
    $mail_calls[] = $atts;
    return true; // WordPress short-circuits before PHPMailer / transport.
  };

  try {
    $existing = $original_db->get_var($original_db->prepare('SHOW TABLES LIKE %s', $original_db->esc_like($table)));
    if ($existing === $table) throw new \RuntimeException('Random private outbox table already exists; refusing to reuse it.');
    $wpdb = $fixture_db;
    if (!\RoxyGrosses\EmailOutbox::ensure_schema(true)) throw new \RuntimeException('Could not create the private outbox schema.');
    $created = true;
    add_filter('pre_wp_mail', $mail_interceptor, PHP_INT_MAX, 2);

    $method = new \ReflectionMethod(\RoxyGrosses\Reporter::class, 'send_live_grosses_email');
    $method->setAccessible(true);
    $recipients = ['live-report@example.invalid'];
    $row = [
      'id' => 7412,
      'report_date' => '2039-07-18',
      'show_time' => '19:30',
      'show_title' => 'Fixture Live Event',
      'presale_qty' => 2,
      'online_qty' => 3,
      'door_qty' => 4,
      'group_sub_qty' => 1,
      'total_tickets' => 10,
      'gross_total' => 125.50,
      'concessions_total' => 17.25,
    ];

    $first = $method->invoke(null, $row, $recipients, true, 'manual-live-email');
    $first_fingerprint = hash('sha256', (string) json_encode([$row, true, $recipients], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $first_key = 'live-grosses:7412:' . $first_fingerprint;
    $first_outbox = \RoxyGrosses\EmailOutbox::find($first_key);
    $check(!empty($first['success']) && count($mail_calls) === 1, 'live report sends once through intercepted WordPress mail');
    $check(($mail_calls[0]['to'] ?? null) === $recipients, 'live report uses only the configured live-report recipient');
    $check(($first_outbox['status'] ?? '') === 'accepted', 'live report records accepted status in the real outbox');

    $duplicate = $method->invoke(null, $row, $recipients, true, 'manual-live-email');
    $check(!empty($duplicate['duplicate_suppressed']) && count($mail_calls) === 1, 'replaying the same live snapshot is suppressed before mail');

    $row['door_qty'] = 5;
    $row['total_tickets'] = 11;
    $changed = $method->invoke(null, $row, $recipients, true, 'manual-live-email');
    $changed_fingerprint = hash('sha256', (string) json_encode([$row, true, $recipients], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $changed_outbox = \RoxyGrosses\EmailOutbox::find('live-grosses:7412:' . $changed_fingerprint);
    $check(!empty($changed['success']) && count($mail_calls) === 2, 'a changed live-row snapshot can be sent as a distinct reviewed version');
    $check(($changed_outbox['status'] ?? '') === 'accepted' && (int) $fixture_db->get_var("SELECT COUNT(*) FROM `{$table}`") === 2, 'distinct live snapshots retain separate immutable outbox rows');

    $mail_count_before_invalid = count($mail_calls);
    $invalid = $method->invoke(null, $row, ["bad\xFF@example.invalid"], true, 'manual-live-email');
    $check(empty($invalid['success']) && count($mail_calls) === $mail_count_before_invalid, 'invalid UTF-8 recipient data is rejected before mail');

    // Reproduce the exact scheduled-provisional failure at the real daily
    // Grosses sender boundary, with WordPress rejecting raw Unicode for the
    // connection charset. The message is intercepted before mail transport.
    $daily_sender = new \ReflectionMethod(\RoxyGrosses\Reporter::class, 'send_email');
    $daily_sender->setAccessible(true);
    $daily_rows = [[
      'report_date' => '2039-07-18', 'show_time' => '19:30', 'film_title' => 'Fixture Feature',
      'general_qty' => 2, 'discount_qty' => 1, 'group_qty' => 0, 'total_tickets' => 3, 'gross_total' => 32.00,
    ]];
    $daily_summary = ['report_date' => '2039-07-18', 'gross_total' => 32.00, 'paid_tickets' => 3, 'total_tickets' => 3];
    $daily_mail_start = count($mail_calls);
    $fixture_charset = $fixture_db->charset;
    $fixture_db->charset = 'ascii';
    try {
      $daily = $daily_sender->invoke(null, $daily_rows, $daily_summary, 'scheduled-provisional', null, 'daily-grosses', 7413, '2039-07-18', 'f4d9f4e1-2b8c-4a95-a66d-c1d9a43024b8');
      $check(!empty($daily['success']) && count($mail_calls) === $daily_mail_start + 1, 'scheduled daily Grosses send survives the reproduced restrictive-charset failure');
      $daily_mail = $mail_calls[$daily_mail_start] ?? [];
      $check(($daily_mail['to'] ?? null) === ['grosses-admin@example.invalid'], 'scheduled daily Grosses email targets only the configured admin fixture, never a vendor');
      $check(str_contains((string) ($daily_mail['subject'] ?? ''), 'PROVISIONAL — CLOSED-DAY REFRESH PENDING'), 'intercepted daily email retains the original Unicode provisional subject');
      $daily_duplicate = $daily_sender->invoke(null, $daily_rows, $daily_summary, 'scheduled-provisional', null, 'daily-grosses', 7413, '2039-07-18', 'f4d9f4e1-2b8c-4a95-a66d-c1d9a43024b8');
      $check(!empty($daily_duplicate['already_accepted']) && count($mail_calls) === $daily_mail_start + 1, 'accepted scheduled daily snapshot is not mailed twice');
    } finally {
      $fixture_db->charset = $fixture_charset;
    }

    $failure_mail_start = count($mail_calls);
    \RoxyGrosses\Reporter::notify_admin_failure('Fixture Grosses failure', '2039-07-18', 'scheduled-provisional', 'Fixture failure only.');
    $failure_mail = $mail_calls[$failure_mail_start] ?? [];
    $failure_recipients = $failure_mail['to'] ?? [];
    $fixture_vendor_addresses = ['inventory-vendor@example.invalid'];
    $check(count($mail_calls) === $failure_mail_start + 1 && $failure_recipients === 'grosses-admin@example.invalid', 'Grosses failure alert targets only the configured admin fixture');
    $check(!array_intersect((array) $failure_recipients, $fixture_vendor_addresses), 'Grosses failure alert never targets the Inventory vendor fixture');

    echo "Passed {$checks} real daily/live Grosses outbox route checks; report and failure-alert mail were intercepted before transport.\n";
  } finally {
    remove_filter('pre_wp_mail', $mail_interceptor, PHP_INT_MAX);
    $wpdb = $original_db;
    if ($created) {
      $drop = $original_db->query("DROP TABLE IF EXISTS `{$table}`");
      if ($drop === false || !empty($original_db->last_error)) throw new \RuntimeException('Could not remove the private live-Grosses outbox table.');
    }
  }
}
