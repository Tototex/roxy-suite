<?php
namespace RoxyRS;

if (!defined('ABSPATH')) {
    exit;
}

class Settings {
    public const OPTION_KEY = 'roxy_rs_settings';

    public static function init(): void {
        add_action('admin_post_roxy_rs_save_settings', [__CLASS__, 'handle_save']);
    }

    public static function defaults(): array {
        return [
            'funding_goal_cents' => 30000,
            'sponsor_amount_cents' => 30000,
            'sponsor_ticket_qty' => 2,
            'min_lead_days' => 30,
            'deadline_days_before_target' => 14,
        ];
    }

    /** Currency entries may be WP_Error; non-currency settings retain normalized values. */
    public static function get(): array {
        $missing = new \stdClass();
        $stored = get_option(self::OPTION_KEY, $missing);
        $option_invalid = $stored !== $missing && !is_array($stored);
        if (!is_array($stored)) $stored = [];
        $settings = array_merge(self::defaults(), $stored);
        $goal = $option_invalid
            ? new \WP_Error('invalid_currency_settings', 'Saved Requested Showings settings need currency review.')
            : (array_key_exists('funding_goal_cents', $stored)
                ? self::validate_saved_cents($stored['funding_goal_cents'], 'funding goal')
                : self::defaults()['funding_goal_cents']);
        $sponsor = $option_invalid
            ? new \WP_Error('invalid_currency_settings', 'Saved Requested Showings settings need currency review.')
            : (array_key_exists('sponsor_amount_cents', $stored)
                ? self::validate_saved_cents($stored['sponsor_amount_cents'], 'sponsor amount')
                : self::defaults()['sponsor_amount_cents']);
        if (is_wp_error($goal) && !is_wp_error($sponsor)) {
            $sponsor = new \WP_Error('invalid_currency_settings', 'The saved sponsor amount cannot be verified until the funding goal is repaired.');
        } elseif (!is_wp_error($goal) && !is_wp_error($sponsor) && $sponsor < $goal) {
            $sponsor = new \WP_Error('invalid_currency_settings', 'The saved sponsor amount is below the funding goal. Review both currency settings.');
        }
        $settings['funding_goal_cents'] = $goal;
        $settings['sponsor_amount_cents'] = $sponsor;
        $settings['sponsor_ticket_qty'] = max(0, (int) ($settings['sponsor_ticket_qty'] ?? 2));
        $settings['min_lead_days'] = max(1, (int) ($settings['min_lead_days'] ?? 30));
        $settings['deadline_days_before_target'] = max(1, (int) ($settings['deadline_days_before_target'] ?? 14));

        return $settings;
    }

    private static function validate_saved_cents($value, string $label) {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)) {
            return new \WP_Error('invalid_currency_settings', 'The saved ' . $label . ' is malformed. Review currency settings before continuing.');
        }
        $normalized = ltrim((string) $value, '0');
        if ($normalized === '' || strlen($normalized) > 10 || (strlen($normalized) === 10 && strcmp($normalized, '2147483647') > 0)) {
            return new \WP_Error('invalid_currency_settings', 'The saved ' . $label . ' is outside the supported range. Review currency settings before continuing.');
        }
        $cents = (int) $normalized;
        if ($cents < 100) {
            return new \WP_Error('invalid_currency_settings', 'The saved ' . $label . ' is below the $1 minimum. Review currency settings before continuing.');
        }
        return $cents;
    }

    /** @return int|\WP_Error */
    public static function funding_goal_cents() {
        return self::get()['funding_goal_cents'];
    }

    /** @return int|\WP_Error */
    public static function sponsor_amount_cents() {
        return self::get()['sponsor_amount_cents'];
    }

    public static function sponsor_ticket_qty(): int {
        return (int) self::get()['sponsor_ticket_qty'];
    }

    public static function min_lead_days(): int {
        return (int) self::get()['min_lead_days'];
    }

    public static function deadline_days_before_target(): int {
        return (int) self::get()['deadline_days_before_target'];
    }

    public static function render_page(bool $wrap = true): void {
        $settings = self::get();
        $currency_error = is_wp_error($settings['funding_goal_cents']) || is_wp_error($settings['sponsor_amount_cents']);

        if ($wrap) {
            echo '<div class="wrap"><h1>Requested Showings Settings</h1>';
        }

        $notice = isset($_GET['roxy_rs_settings_notice']) ? sanitize_key((string) wp_unslash($_GET['roxy_rs_settings_notice'])) : '';
        if ($notice === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>Requested Showings settings saved.</p></div>';
        } elseif ($notice === 'invalid_currency') {
            echo '<div class="notice notice-error"><p>Settings were not saved. Enter currency amounts using digits and no more than two decimal places; the sponsor amount must be at least the funding goal.</p></div>';
        }
        if ($currency_error) {
            echo '<div class="notice notice-error"><p>Saved currency settings need review. Enter valid funding goal and sponsor amounts below to repair them; no default amount is being substituted.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('roxy_rs_save_settings');
        echo '<input type="hidden" name="action" value="roxy_rs_save_settings">';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="roxy-rs-settings-funding-goal">Default funding goal</label></th><td>';
        $goal_input = is_wp_error($settings['funding_goal_cents']) ? '' : CPT::format_currency_input($settings['funding_goal_cents']);
        echo '<input id="roxy-rs-settings-funding-goal" name="funding_goal" type="number" min="1" step="0.01" value="' . esc_attr($goal_input) . '" class="regular-text">';
        echo '<p class="description">Default dollar goal used when a new requested showing is created.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="roxy-rs-settings-sponsor-amount">Default sponsor amount</label></th><td>';
        $sponsor_input = is_wp_error($settings['sponsor_amount_cents']) ? '' : CPT::format_currency_input($settings['sponsor_amount_cents']);
        echo '<input id="roxy-rs-settings-sponsor-amount" name="sponsor_amount" type="number" min="1" step="0.01" value="' . esc_attr($sponsor_input) . '" class="regular-text">';
        echo '<p class="description">Starting sponsor package amount before paid ticket backing reduces the remaining sponsor commitment.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="roxy-rs-settings-sponsor-tickets">Sponsor included tickets</label></th><td>';
        echo '<input id="roxy-rs-settings-sponsor-tickets" name="sponsor_tickets" type="number" min="0" step="1" value="' . esc_attr((string) $settings['sponsor_ticket_qty']) . '" class="small-text">';
        echo '<p class="description">How many tickets a sponsor receives by default.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="roxy-rs-settings-min-lead">Minimum lead time</label></th><td>';
        echo '<input id="roxy-rs-settings-min-lead" name="min_lead_days" type="number" min="1" step="1" value="' . esc_attr((string) $settings['min_lead_days']) . '" class="small-text"> days';
        echo '<p class="description">How far out the target show date should be from today before a request can be submitted.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="roxy-rs-settings-deadline">Backing deadline lead time</label></th><td>';
        echo '<input id="roxy-rs-settings-deadline" name="deadline_days_before_target" type="number" min="1" step="1" value="' . esc_attr((string) $settings['deadline_days_before_target']) . '" class="small-text"> days';
        echo '<p class="description">How many days before the target showtime backing closes.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button('Save Requested Showings Settings');
        echo '</form>';

        if ($wrap) {
            echo '</div>';
        }
    }

    public static function handle_save(): void {
        if (!roxy_suite_user_can_access_admin()) {
            wp_die('Permission denied.');
        }
        check_admin_referer('roxy_rs_save_settings');

        $current = self::get();
        $goal_default = is_wp_error($current['funding_goal_cents']) ? null : $current['funding_goal_cents'];
        $sponsor_default = is_wp_error($current['sponsor_amount_cents']) ? null : $current['sponsor_amount_cents'];
        $funding_goal = CPT::parse_currency_input(wp_unslash($_POST['funding_goal'] ?? ''), $goal_default);
        $sponsor_amount = CPT::parse_currency_input(wp_unslash($_POST['sponsor_amount'] ?? ''), $sponsor_default);
        if (is_wp_error($funding_goal) || is_wp_error($sponsor_amount)
            || $funding_goal < 100 || $sponsor_amount < 100 || $sponsor_amount < $funding_goal) {
            wp_safe_redirect(add_query_arg([
                'page' => 'roxy-requested-showings',
                'tab' => 'settings',
                'roxy_rs_settings_notice' => 'invalid_currency',
            ], admin_url('admin.php')));
            return;
        }
        $settings = [
            'funding_goal_cents' => $funding_goal,
            'sponsor_amount_cents' => $sponsor_amount,
            'sponsor_ticket_qty' => max(0, (int) wp_unslash($_POST['sponsor_tickets'] ?? self::sponsor_ticket_qty())),
            'min_lead_days' => max(1, (int) wp_unslash($_POST['min_lead_days'] ?? self::min_lead_days())),
            'deadline_days_before_target' => max(1, (int) wp_unslash($_POST['deadline_days_before_target'] ?? self::deadline_days_before_target())),
        ];

        update_option(self::OPTION_KEY, $settings, false);

        wp_safe_redirect(add_query_arg([
            'page' => 'roxy-requested-showings',
            'tab' => 'settings',
            'roxy_rs_settings_notice' => 'saved',
        ], admin_url('admin.php')));
        exit;
    }
}
