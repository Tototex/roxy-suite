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

    public static function get(): array {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = array_merge(self::defaults(), $stored);
        $settings['funding_goal_cents'] = max(100, (int) ($settings['funding_goal_cents'] ?? 30000));
        $settings['sponsor_amount_cents'] = max($settings['funding_goal_cents'], (int) ($settings['sponsor_amount_cents'] ?? 30000));
        $settings['sponsor_ticket_qty'] = max(0, (int) ($settings['sponsor_ticket_qty'] ?? 2));
        $settings['min_lead_days'] = max(1, (int) ($settings['min_lead_days'] ?? 30));
        $settings['deadline_days_before_target'] = max(1, (int) ($settings['deadline_days_before_target'] ?? 14));

        return $settings;
    }

    public static function funding_goal_cents(): int {
        return (int) self::get()['funding_goal_cents'];
    }

    public static function sponsor_amount_cents(): int {
        return (int) self::get()['sponsor_amount_cents'];
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

        if ($wrap) {
            echo '<div class="wrap"><h1>Requested Showings Settings</h1>';
        }

        $notice = isset($_GET['roxy_rs_settings_notice']) ? sanitize_key((string) wp_unslash($_GET['roxy_rs_settings_notice'])) : '';
        if ($notice === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>Requested Showings settings saved.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('roxy_rs_save_settings');
        echo '<input type="hidden" name="action" value="roxy_rs_save_settings">';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="roxy-rs-settings-funding-goal">Default funding goal</label></th><td>';
        echo '<input id="roxy-rs-settings-funding-goal" name="funding_goal" type="number" min="1" step="0.01" value="' . esc_attr(CPT::format_currency_input((int) $settings['funding_goal_cents'])) . '" class="regular-text">';
        echo '<p class="description">Default dollar goal used when a new requested showing is created.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="roxy-rs-settings-sponsor-amount">Default sponsor amount</label></th><td>';
        echo '<input id="roxy-rs-settings-sponsor-amount" name="sponsor_amount" type="number" min="1" step="0.01" value="' . esc_attr(CPT::format_currency_input((int) $settings['sponsor_amount_cents'])) . '" class="regular-text">';
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

        $funding_goal = CPT::parse_currency_input($_POST['funding_goal'] ?? '', self::funding_goal_cents());
        $sponsor_amount = CPT::parse_currency_input($_POST['sponsor_amount'] ?? '', self::sponsor_amount_cents());
        $settings = [
            'funding_goal_cents' => max(100, $funding_goal),
            'sponsor_amount_cents' => max(max(100, $funding_goal), $sponsor_amount),
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
