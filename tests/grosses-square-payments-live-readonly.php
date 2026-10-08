<?php
/**
 * Read-only live probe for the candidate Square payments feed.
 *
 * Usage (from the WordPress installation):
 *   wp --skip-plugins --skip-themes eval-file /path/to/grosses-square-payments-live-readonly.php /path/to/candidate/class-roxy-grosses-square.php [YYYY-MM-DD] [/path/to/candidate/class-roxy-grosses-refund-snapshot.php]
 *
 * Optional second argument is one past Pacific calendar date (YYYY-MM-DD);
 * omitted means yesterday. Uses the already-loaded production Settings class.
 * The only permitted network request is HTTPS GET to
 * connect.squareup.com/v2/payments. All wpdb writes and
 * wp_mail are blocked for the duration of the probe. Output contains aggregates
 * only; provider payloads, payment identifiers, amounts and credentials are
 * never printed.
 */
if (!defined('WP_CLI') || !WP_CLI) {
  throw new RuntimeException('This read-only probe must be run through WP-CLI.');
}

$candidate = $args[0] ?? '';
if (!is_string($candidate) || $candidate === '' || !is_file($candidate) || !is_readable($candidate)) {
  throw new RuntimeException('Candidate Square source file is missing or unreadable.');
}
$candidate_events = $args[2] ?? dirname($candidate) . '/class-roxy-grosses-refund-snapshot.php';
if (!is_string($candidate_events) || !is_file($candidate_events) || !is_readable($candidate_events)) {
  throw new RuntimeException('Candidate Square payment-normalization source file is missing or unreadable.');
}
$http_requests = 0;
$http_rejections = 0;
$mail_attempts = 0;
$db_write_attempts = 0;
$allowed_locations = [];
$expected_begin = null;
$expected_end = null;
$http_guard = static function ($pre, $request, $url) use (&$http_requests, &$http_rejections, &$allowed_locations, &$expected_begin, &$expected_end) {
  $parts = is_string($url) ? parse_url($url) : false;
  $method = strtoupper((string) ($request['method'] ?? 'GET'));
  $params = [];
  if (is_array($parts) && isset($parts['query'])) parse_str($parts['query'], $params);
  $expected_keys = ['begin_time', 'end_time', 'sort_order', 'limit', 'location_id'];
  if (array_key_exists('cursor', $params)) $expected_keys[] = 'cursor';
  $valid_query = $expected_begin !== null
    && $expected_end !== null
    && !array_diff(array_keys($params), $expected_keys)
    && ($params['begin_time'] ?? null) === $expected_begin
    && ($params['end_time'] ?? null) === $expected_end
    && ($params['sort_order'] ?? null) === 'ASC'
    && ($params['limit'] ?? null) === '100'
    && in_array($params['location_id'] ?? null, $allowed_locations, true)
    && (!array_key_exists('cursor', $params) || (is_string($params['cursor']) && $params['cursor'] !== ''));
  $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
  $has_bearer = false;
  foreach ($headers as $name => $value) {
    if (strcasecmp((string) $name, 'Authorization') === 0
        && is_string($value)
        && preg_match('/^Bearer\s+\S+$/', $value)) {
      $has_bearer = true;
      break;
    }
  }
  $allowed = is_array($parts)
    && ($parts['scheme'] ?? '') === 'https'
    && ($parts['host'] ?? '') === 'connect.squareup.com'
    && !isset($parts['port'])
    && !isset($parts['user'])
    && !isset($parts['pass'])
    && ($parts['path'] ?? '') === '/v2/payments'
    && $method === 'GET'
    && $valid_query
    && $has_bearer
    && $pre === false
    && empty($request['body'])
    && (int) ($request['redirection'] ?? 0) === 0;
  if (!$allowed) {
    ++$http_rejections;
    return new WP_Error('payments_probe_http_blocked', 'External requests are blocked by the live-readonly payments probe.');
  }
  ++$http_requests;
  return $pre;
};
$mail_guard = static function ($pre) use (&$mail_attempts) {
  ++$mail_attempts;
  return false;
};
$db_guard = static function ($query) use (&$db_write_attempts) {
  $is_read = is_string($query) && preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $query);
  $unsafe_read = !is_string($query)
    || preg_match('/;\s*\S|\b(FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE|INTO\s+(OUTFILE|DUMPFILE))\b/i', $query);
  if (!$is_read || $unsafe_read) {
    ++$db_write_attempts;
    return '';
  }
  return $query;
};

add_filter('pre_http_request', $http_guard, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX, 2);
add_filter('query', $db_guard, PHP_INT_MAX);

try {
  // Prefer a minimal WP-CLI bootstrap. Load only the installed Settings class
  // when --skip-plugins is used, so plugin initialization hooks cannot run.
  if (!class_exists('RoxyGrosses\\Settings')) {
    $live_settings = WP_PLUGIN_DIR . '/roxy-suite/includes/modules/grosses/includes/class-roxy-grosses-settings.php';
    if (!is_file($live_settings) || !is_readable($live_settings)) {
      throw new RuntimeException('The live Roxy Grosses Settings source is unavailable.');
    }
    require_once $live_settings;
  }
  $settings = \RoxyGrosses\Settings::get_all();
  if (($settings['square_environment'] ?? '') !== 'production') {
    throw new RuntimeException('The configured Square environment is not production; no request was made.');
  }
  $locations = array_values(array_unique(\RoxyGrosses\Settings::line_list((string) ($settings['square_location_ids'] ?? ''))));
  if (!$locations || count($locations) > 10 || \RoxyGrosses\Settings::square_access_token() === '') {
    throw new RuntimeException('A production Square token and one to ten configured locations are required.');
  }

  $source = file_get_contents($candidate);
  if (!is_string($source) || substr_count($source, 'class Square {') !== 1) {
    throw new RuntimeException('Candidate source did not contain exactly one expected Square class declaration.');
  }
  $probe_class = 'SquarePaymentsReadonlyProbe_' . bin2hex(random_bytes(6));
  $source = str_replace('class Square {', 'class ' . $probe_class . ' {', $source);
  if (strpos($source, 'namespace RoxyGrosses;') === false) {
    throw new RuntimeException('Candidate Square source has an unexpected namespace.');
  }
  eval(substr($source, 5));
  $probe_class = 'RoxyGrosses\\' . $probe_class;

  if (class_exists('RoxyGrosses\\SquarePaymentEvents', false)) {
    throw new RuntimeException('A Square payment-normalization class was already loaded; refusing to test a different source.');
  }
  require_once $candidate_events;
  if (!class_exists('RoxyGrosses\\SquarePaymentEvents', false)) {
    throw new RuntimeException('Candidate Square payment-normalization source did not load.');
  }

  $pacific = new DateTimeZone('America/Los_Angeles');
  $today = new DateTimeImmutable('today', $pacific);
  $probe_date = $args[1] ?? '';
  if ($probe_date === '') {
    $start = $today->modify('-1 day');
  } else {
    if (!is_string($probe_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $probe_date)) {
      throw new RuntimeException('Optional probe date must be one Pacific calendar date in YYYY-MM-DD form.');
    }
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $probe_date, $pacific);
    $date_errors = DateTimeImmutable::getLastErrors();
    if (!$start || ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))
        || $start->format('Y-m-d') !== $probe_date || $start >= $today) {
      throw new RuntimeException('Optional probe date must be a real past Pacific calendar date.');
    }
  }
  $end = $start->modify('+1 day');
  $allowed_locations = $locations;
  $expected_begin = $start->format('c');
  $expected_end = $end->modify('-1 microsecond')->format('Y-m-d\\TH:i:s.uP');
  $payments = $probe_class::list_payments_created_between($start->format('c'), $end->format('c'));
  if (!is_array($payments)) {
    throw new RuntimeException('Candidate returned a non-array result.');
  }
  $collection_events = \RoxyGrosses\SquarePaymentEvents::from_payments($payments);
  if (!is_array($collection_events)) {
    throw new RuntimeException('Candidate payment normalization returned a non-array result.');
  }

  $statuses = [];
  foreach ($payments as $payment) {
    if (!is_array($payment) || !is_string($payment['status'] ?? null)) {
      throw new RuntimeException('Candidate returned a malformed payment result.');
    }
    $status = $payment['status'];
    if (!in_array($status, ['APPROVED', 'PENDING', 'COMPLETED', 'CANCELED', 'FAILED'], true)) {
      throw new RuntimeException('Candidate returned an unrecognized payment status.');
    }
    $statuses[$status] = ($statuses[$status] ?? 0) + 1;
  }
  ksort($statuses);

  if ($http_requests < 1) {
    throw new RuntimeException('No permitted live Square payments request was observed.');
  }
  if ($http_rejections !== 0 || $mail_attempts !== 0 || $db_write_attempts !== 0) {
    throw new RuntimeException('A forbidden network, mail, or database-write attempt was blocked.');
  }

  echo 'SQUARE_PAYMENTS_READONLY_PASS ' . wp_json_encode([
    'pacific_date' => $start->format('Y-m-d'),
    'records' => count($payments),
    'completed_collection_events' => count($collection_events),
    'status_counts' => $statuses,
    'permitted_square_gets' => $http_requests,
    'blocked_external_requests' => $http_rejections,
    'blocked_mail_attempts' => $mail_attempts,
    'blocked_database_write_attempts' => $db_write_attempts,
    'database_write_guard' => 'active; no wpdb writes permitted during probe',
  ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
  // Do not echo provider or WordPress exception text: it may include sensitive data.
  fwrite(STDERR, 'SQUARE_PAYMENTS_READONLY_FAIL exception=' . get_class($error)
    . ' permitted_square_gets=' . $http_requests
    . ' blocked_external_requests=' . $http_rejections
    . ' blocked_mail_attempts=' . $mail_attempts
    . ' blocked_database_write_attempts=' . $db_write_attempts . PHP_EOL);
  exit(1);
} finally {
  remove_filter('pre_http_request', $http_guard, PHP_INT_MAX);
  remove_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX);
  remove_filter('query', $db_guard, PHP_INT_MAX);
}
