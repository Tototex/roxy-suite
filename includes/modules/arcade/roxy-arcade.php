<?php
/**
 * Plugin Name: Roxy Arcade
 * Description: Modular arcade with multiple games, per-game + combined leaderboards, guest play, login-required score saving, and monthly prize via WooCommerce Subscriptions.
 * Version: 0.4.4
 * Author: Newport Roxy (AI Team)
 * Update URI: https://github.com/Tototex/roxy-arcade
 */

if (!defined('ABSPATH')) exit;

define('ROXY_ARCADE_VERSION', '0.4.4');

class Roxy_Arcade {
  const DB_VERSION = '1.0';
  const TABLE_KEY  = 'roxy_arcade_scores';

  // Options
  const OPTION_DBV = 'roxy_arcade_db_version';
  const OPTION_REWARDS_ENABLED = 'roxy_arcade_rewards_enabled';
  const OPTION_LAST_AWARDED_MONTH = 'roxy_arcade_last_awarded_month';
  const OPTION_LAST_WINNER_USER_ID = 'roxy_arcade_last_winner_user_id';
  const OPTION_LAST_WINNER_SUB_ID  = 'roxy_arcade_last_winner_sub_id';
  const OPTION_AWARD_TIME = 'roxy_arcade_award_time_local';

  // Prize product SKU
  const SKU_PRIZE = 'SUB002';

  // Monthly award schedule time (site timezone)
  const AWARD_HOUR = 9;
  const AWARD_MIN  = 0;

  public static function init() {
    add_action('init', [__CLASS__, 'register_shortcode']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
    add_action('admin_menu', [__CLASS__, 'admin_menu']);

    add_action('roxy_arcade_monthly_award', [__CLASS__, 'award_monthly_winner_snapshot']);
  }

  public static function activate() {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE_KEY;
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE $table (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      game_key VARCHAR(64) NOT NULL,
      best_score BIGINT UNSIGNED NOT NULL DEFAULT 0,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY user_game (user_id, game_key),
      KEY game_score (game_key, best_score),
      KEY user_id (user_id)
    ) $charset;";

    dbDelta($sql);
    update_option(self::OPTION_DBV, self::DB_VERSION);

    if (get_option(self::OPTION_REWARDS_ENABLED, null) === null) {
      update_option(self::OPTION_REWARDS_ENABLED, 0); // OFF by default
    }

    update_option(self::OPTION_AWARD_TIME, sprintf('%02d:%02d (site timezone)', self::AWARD_HOUR, self::AWARD_MIN));
    self::schedule_next_award(true);
  }

  public static function deactivate() {
    $ts = wp_next_scheduled('roxy_arcade_monthly_award');
    while ($ts) {
      wp_unschedule_event($ts, 'roxy_arcade_monthly_award');
      $ts = wp_next_scheduled('roxy_arcade_monthly_award');
    }
  }

  private static function schedule_next_award($force = false) {
    $existing = wp_next_scheduled('roxy_arcade_monthly_award');
    if ($existing && !$force) return;

    if ($existing && $force) {
      wp_unschedule_event($existing, 'roxy_arcade_monthly_award');
    }

    $tz = wp_timezone();
    $run = new DateTime('first day of next month', $tz);
    $run->setTime(self::AWARD_HOUR, self::AWARD_MIN, 0);

    wp_schedule_single_event($run->getTimestamp(), 'roxy_arcade_monthly_award');
  }

  public static function enqueue_assets() {
    if (!is_singular()) return;
    global $post;
    if (!$post || stripos($post->post_content, '[roxy_arcade]') === false) return;

    $ver = ROXY_ARCADE_VERSION;

    wp_enqueue_style('roxy-arcade', plugin_dir_url(__FILE__) . 'assets/roxy-arcade.css', [], $ver);

    // Game files (each registers itself into window.ROXY_ARCADE_GAMES)
    wp_enqueue_script('roxy-arcade-game-marquee',   plugin_dir_url(__FILE__) . 'assets/games/marquee-dash.js', [], $ver, true);
    wp_enqueue_script('roxy-arcade-game-popcorn',   plugin_dir_url(__FILE__) . 'assets/games/popcorn-panic.js', [], $ver, true);
    wp_enqueue_script('roxy-arcade-game-projector', plugin_dir_url(__FILE__) . 'assets/games/projector-defender.js', [], $ver, true);

    // Main loader last
    wp_enqueue_script(
      'roxy-arcade',
      plugin_dir_url(__FILE__) . 'assets/roxy-arcade.js',
      ['roxy-arcade-game-marquee', 'roxy-arcade-game-popcorn', 'roxy-arcade-game-projector'],
      $ver,
      true
    );

    wp_localize_script('roxy-arcade', 'ROXY_ARCADE', [
      'restUrl' => esc_url_raw(rest_url('roxy/v1')),
      'nonce'   => wp_create_nonce('wp_rest'),
      'isLoggedIn' => is_user_logged_in(),
      'loginUrl' => wp_login_url(get_permalink()),
      'awardRuleText' => 'Monthly winner is the #1 combined score on the 1st at 9:00am.',
    ]);
  }

  public static function register_shortcode() {
    add_shortcode('roxy_arcade', [__CLASS__, 'render_shortcode']);
  }

  public static function render_shortcode() {
    ob_start(); ?>
    <div class="roxy-arcade">
      <div class="roxy-arcade__left">
        <div class="roxy-arcade__header">
          <h2 class="roxy-title">Roxy Arcade</h2>
          <div class="roxy-subtitle" id="roxyAwardRule"></div>
        </div>

        <div class="roxy-arcade__tabs" id="roxyArcadeTabs"></div>

        <div class="roxy-arcade__status" id="roxyArcadeStatus"></div>

        <div class="roxy-arcade__canvasWrap">
          <canvas id="roxyArcadeCanvas" width="900" height="520"></canvas>
        </div>

        <div class="roxy-arcade__controls">
          <button id="roxyStartBtn" class="roxy-btn">Start / Submit</button>
          <button id="roxyRestartBtn" class="roxy-btn roxy-btn--ghost">Restart</button>
          <span class="roxy-hint">Desktop: arrows / mouse. Mobile: drag / tap.</span>
        </div>

        <div class="roxy-arcade__guestNote">
          <?php if (!is_user_logged_in()): ?>
            <div class="roxy-alert">
              You’re playing as <strong>Guest</strong>. Scores won’t save unless you
              <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">log in</a>.
            </div>
          <?php else: ?>
            <div class="roxy-alert roxy-alert--ok">
              Logged in. Your best scores will save.
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="roxy-arcade__right">
        <div class="roxy-card">
          <h3>🏆 Combined Top Scores</h3>
          <div id="roxyCombinedBoard" class="roxy-board">Loading…</div>
        </div>

        <div class="roxy-card">
          <h3>🎮 This Game Top Scores</h3>
          <div id="roxyGameBoard" class="roxy-board">Loading…</div>
        </div>

        <div class="roxy-card">
          <h3>👑 Current Leader</h3>
          <div id="roxyChampion" class="roxy-board">Loading…</div>
          <div class="roxy-small">
            Prize: 1 billing period free of <strong>Friends of The Roxy</strong> (SKU: <?php echo esc_html(self::SKU_PRIZE); ?>)
            for the monthly winner.
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  public static function register_routes() {
    register_rest_route('roxy/v1', '/leaderboards', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'rest_get_leaderboards'],
      'permission_callback' => '__return_true',
    ]);

    register_rest_route('roxy/v1', '/score', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'rest_post_score'],
      'permission_callback' => function() { return true; },
    ]);
  }

  private static function table() {
    global $wpdb;
    return $wpdb->prefix . self::TABLE_KEY;
  }

  private static function public_name_from_user($user) {
    if (!$user) return 'Player';

    $first = trim((string) get_user_meta($user->ID, 'first_name', true));
    $last  = trim((string) get_user_meta($user->ID, 'last_name', true));

    if ($first === '') {
      $parts = preg_split('/\s+/', trim((string) $user->display_name));
      $first = $parts[0] ?? 'Player';
      if ($last === '' && count($parts) > 1) $last = $parts[count($parts) - 1];
    }

    $initial = ($last !== '') ? strtoupper(substr($last, 0, 1)) . '.' : '';
    return trim($first . ' ' . $initial);
  }

  public static function rest_get_leaderboards(WP_REST_Request $req) {
    $game = sanitize_key($req->get_param('game'));
    if ($game === '') $game = 'marquee';

    $combined = self::get_combined_top10();
    $champion = self::champion_from_combined($combined);

    return new WP_REST_Response([
      'combined' => $combined,
      'game' => self::get_game_top10($game),
      'champion' => $champion,
      'rewardsEnabled' => (int) get_option(self::OPTION_REWARDS_ENABLED, 0),
      'lastAwardedMonth' => (string) get_option(self::OPTION_LAST_AWARDED_MONTH, ''),
    ], 200);
  }

  public static function rest_post_score(WP_REST_Request $req) {
    $nonce = $req->get_header('X-WP-Nonce');
    if (!wp_verify_nonce($nonce, 'wp_rest')) {
      return new WP_REST_Response(['ok' => false, 'message' => 'Security check failed. Refresh and try again.'], 403);
    }

    $game = sanitize_key($req->get_param('game'));
    $score = absint($req->get_param('score'));

    if ($game === '') return new WP_REST_Response(['ok' => false, 'message' => 'Unknown game.'], 400);

    if (!is_user_logged_in()) {
      $combined = self::get_combined_top10();
      return new WP_REST_Response([
        'ok' => false,
        'guest' => true,
        'message' => 'Nice run! Log in to save your score and compete for the monthly prize.',
        'leaderboards' => [
          'combined' => $combined,
          'game' => self::get_game_top10($game),
          'champion' => self::champion_from_combined($combined),
        ],
      ], 200);
    }

    $user_id = get_current_user_id();

    $rate_key = "roxy_arcade_rate_{$user_id}_{$game}";
    $last = (int) get_transient($rate_key);
    if ($last && (time() - $last) < 10) {
      return new WP_REST_Response(['ok' => false, 'message' => 'Too fast — try again in a moment.'], 429);
    }
    set_transient($rate_key, time(), 30);

    if ($score > 9999999) $score = 9999999;

    self::upsert_best_score($user_id, $game, $score);

    $combined = self::get_combined_top10();
    return new WP_REST_Response([
      'ok' => true,
      'message' => 'Score saved!',
      'leaderboards' => [
        'combined' => $combined,
        'game' => self::get_game_top10($game),
        'champion' => self::champion_from_combined($combined),
      ],
    ], 200);
  }

  private static function upsert_best_score($user_id, $game, $score) {
    global $wpdb;
    $table = self::table();

    $existing = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT best_score FROM $table WHERE user_id = %d AND game_key = %s",
      $user_id, $game
    ));

    if ($score <= $existing) return;

    $wpdb->query($wpdb->prepare(
      "INSERT INTO $table (user_id, game_key, best_score, updated_at)
       VALUES (%d, %s, %d, NOW())
       ON DUPLICATE KEY UPDATE best_score = VALUES(best_score), updated_at = NOW()",
      $user_id, $game, $score
    ));
  }

  private static function get_game_top10($game) {
    global $wpdb;
    $table = self::table();

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT user_id, best_score
       FROM $table
       WHERE game_key = %s
       ORDER BY best_score DESC, updated_at ASC
       LIMIT 10",
      $game
    ), ARRAY_A);

    return array_map(function($r){
      $u = get_user_by('id', (int)$r['user_id']);
      return [
        'name' => self::public_name_from_user($u),
        'score' => (int)$r['best_score'],
      ];
    }, $rows);
  }

  private static function get_combined_top10() {
    global $wpdb;
    $table = self::table();

    $rows = $wpdb->get_results(
      "SELECT user_id, SUM(best_score) AS total
       FROM $table
       GROUP BY user_id
       ORDER BY total DESC
       LIMIT 10",
      ARRAY_A
    );

    return array_map(function($r){
      $u = get_user_by('id', (int)$r['user_id']);
      return [
        'user_id' => (int)$r['user_id'],
        'name' => self::public_name_from_user($u),
        'score' => (int)$r['total'],
      ];
    }, $rows);
  }

  private static function champion_from_combined($combinedTop10) {
    if (empty($combinedTop10)) return ['user_id' => 0, 'name' => '—', 'score' => 0];
    $top = $combinedTop10[0];
    return [
      'user_id' => (int)($top['user_id'] ?? 0),
      'name' => (string)($top['name'] ?? '—'),
      'score' => (int)($top['score'] ?? 0),
    ];
  }

  public static function award_monthly_winner_snapshot() {
    self::schedule_next_award(true);

    $enabled = (int) get_option(self::OPTION_REWARDS_ENABLED, 0);
    if (!$enabled) return;

    $tz = wp_timezone();
    $month = (new DateTime('now', $tz))->format('Y-m');
    $last = (string) get_option(self::OPTION_LAST_AWARDED_MONTH, '');
    if ($last === $month) return;

    $combined = self::get_combined_top10();
    if (empty($combined) || empty($combined[0]['user_id'])) return;

    $winner_id = (int) $combined[0]['user_id'];

    $sub_id = self::award_free_subscription_one_period($winner_id);
    if ($sub_id) {
      update_option(self::OPTION_LAST_AWARDED_MONTH, $month);
      update_option(self::OPTION_LAST_WINNER_USER_ID, $winner_id);
      update_option(self::OPTION_LAST_WINNER_SUB_ID, (int)$sub_id);
    }
  }

  private static function award_free_subscription_one_period($user_id) {
    if (!class_exists('WooCommerce')) return 0;
    if (!function_exists('wcs_create_subscription')) return 0;

    $product_id = wc_get_product_id_by_sku(self::SKU_PRIZE);
    if (!$product_id) return 0;

    $product = wc_get_product($product_id);
    if (!$product) return 0;

    $sub = wcs_create_subscription([
      'customer_id' => $user_id,
      'billing_period' => $product->get_billing_period(),
      'billing_interval' => $product->get_billing_interval(),
      'start_date' => gmdate('Y-m-d H:i:s'),
    ]);

    if (is_wp_error($sub) || !$sub) return 0;

    $sub->add_product($product, 1);

    $sub->set_requires_manual_renewal(true);
    $sub->set_payment_method('');
    $sub->set_total(0);
    $sub->calculate_totals();

    $end_ts = wcs_add_time($product->get_billing_period(), $product->get_billing_interval(), $sub->get_time('start'));
    $sub->update_dates([
      'end' => gmdate('Y-m-d H:i:s', $end_ts),
      'next_payment' => 0,
    ]);

    $sub->update_status('active', 'Roxy Arcade monthly winner prize awarded.');
    $sub->add_order_note('Roxy Arcade: Monthly winner prize — Free 1 billing period (SUB002).');

    return (int) $sub->get_id();
  }

  public static function admin_menu() {
    add_submenu_page('roxy-suite', 'Arcade', 'Arcade', 'manage_options', 'roxy-arcade-settings', [__CLASS__, 'settings_page']);
  }

  public static function settings_page() {
    if (!current_user_can('manage_options')) return;

    $msg = '';
    if (isset($_POST['roxy_arcade_save']) && check_admin_referer('roxy_arcade_save_settings')) {
      $enabled = isset($_POST['rewards_enabled']) ? 1 : 0;
      update_option(self::OPTION_REWARDS_ENABLED, $enabled);

      if (isset($_POST['reset_awarded_month'])) {
        update_option(self::OPTION_LAST_AWARDED_MONTH, '');
        update_option(self::OPTION_LAST_WINNER_USER_ID, 0);
        update_option(self::OPTION_LAST_WINNER_SUB_ID, 0);
      }

      if (isset($_POST['run_award_now'])) {
        self::award_monthly_winner_snapshot();
        $msg = 'Monthly award routine ran (if eligible).';
      } else {
        $msg = 'Settings saved.';
      }
    }

    $enabled = (int) get_option(self::OPTION_REWARDS_ENABLED, 0);
    $last_month = (string) get_option(self::OPTION_LAST_AWARDED_MONTH, '');
    $last_winner_id = (int) get_option(self::OPTION_LAST_WINNER_USER_ID, 0);
    $last_sub_id = (int) get_option(self::OPTION_LAST_WINNER_SUB_ID, 0);

    $next_ts = wp_next_scheduled('roxy_arcade_monthly_award');
    $next_run = $next_ts ? wp_date('Y-m-d H:i:s T', $next_ts, wp_timezone()) : 'Not scheduled';

    $combined = self::get_combined_top10();
    $leader = self::champion_from_combined($combined);

    $sku = self::SKU_PRIZE;
    $product_id = function_exists('wc_get_product_id_by_sku') ? wc_get_product_id_by_sku($sku) : 0;
    ?>
    <div class="wrap">
      <h1>Roxy Arcade Settings</h1>

      <?php if ($msg): ?>
        <div class="updated notice"><p><?php echo esc_html($msg); ?></p></div>
      <?php endif; ?>

      <form method="post">
        <?php wp_nonce_field('roxy_arcade_save_settings'); ?>

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Rewards Enabled</th>
            <td>
              <label>
                <input type="checkbox" name="rewards_enabled" <?php checked($enabled, 1); ?> />
                Enable monthly prize awarding (1st at <?php echo esc_html(get_option(self::OPTION_AWARD_TIME)); ?>)
              </label>
              <p class="description">Keep OFF while seeding scores.</p>
            </td>
          </tr>

          <tr>
            <th scope="row">Prize Product</th>
            <td>
              <div><strong>SKU:</strong> <?php echo esc_html($sku); ?></div>
              <div><strong>Product ID found:</strong> <?php echo esc_html($product_id ? $product_id : 'No (check SKU)'); ?></div>
            </td>
          </tr>

          <tr>
            <th scope="row">Current Leader (Live)</th>
            <td><div><strong><?php echo esc_html($leader['name']); ?></strong> — <?php echo esc_html($leader['score']); ?> pts</div></td>
          </tr>

          <tr>
            <th scope="row">Last Award</th>
            <td>
              <div><strong>Last awarded month:</strong> <?php echo esc_html($last_month ? $last_month : '—'); ?></div>
              <div><strong>Last winner user ID:</strong> <?php echo esc_html($last_winner_id ? $last_winner_id : '—'); ?></div>
              <div><strong>Last winner subscription ID:</strong> <?php echo esc_html($last_sub_id ? $last_sub_id : '—'); ?></div>
              <label style="display:block;margin-top:8px;">
                <input type="checkbox" name="reset_awarded_month" />
                Reset awarded month (allows awarding again this month)
              </label>
            </td>
          </tr>

          <tr>
            <th scope="row">Next Scheduled Run</th>
            <td><div><?php echo esc_html($next_run); ?></div></td>
          </tr>

          <tr>
            <th scope="row">Manual Controls</th>
            <td>
              <label style="display:block;margin-bottom:8px;">
                <input type="checkbox" name="run_award_now" />
                Run monthly award routine now (only awards if eligible + rewards enabled)
              </label>
            </td>
          </tr>
        </table>

        <p class="submit">
          <button type="submit" class="button button-primary" name="roxy_arcade_save" value="1">Save Settings</button>
        </p>
      </form>
    </div>
    <?php
  }
}

Roxy_Arcade::init();
