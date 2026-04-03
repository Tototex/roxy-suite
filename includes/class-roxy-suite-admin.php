<?php
namespace RoxySuite;

if (!defined('ABSPATH')) exit;

/**
 * Unified admin menu for Roxy Suite.
 * Registers one submenu entry per module; multi-page modules get a tab UI.
 * Submenus are gated on roxy_suite_module_enabled() so disabled modules
 * don't appear in the navigation.
 */
class Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 10);
    }

    public static function admin_menu(): void {
        if (roxy_suite_module_enabled('show_tickets') || roxy_suite_module_enabled('will_call') || roxy_suite_module_enabled('sub_check')) {
            add_submenu_page(
                'roxy-suite',
                'Ticket Ops',
                'Ticket Ops',
                'edit_posts',
                'roxy-ticket-ops',
                [__CLASS__, 'page_ticket_ops']
            );
        }

        if (roxy_suite_module_enabled('show_tickets')) {
            add_submenu_page(
                'roxy-suite',
                'Show Tickets',
                'Show Tickets',
                'manage_options',
                'roxy-show-tickets',
                [__CLASS__, 'page_show_tickets']
            );
        }

        if (roxy_suite_module_enabled('event_booking')) {
            add_submenu_page(
                'roxy-suite',
                'Event Booking',
                'Event Booking',
                'manage_options',
                'roxy-event-booking',
                [__CLASS__, 'page_event_booking']
            );
        }

        if (roxy_suite_module_enabled('grosses')) {
            add_submenu_page(
                'roxy-suite',
                'Grosses',
                'Grosses',
                'manage_options',
                'roxy-grosses',
                ['\\RoxyGrosses\\Settings', 'render_page']
            );
        }
    }

    public static function page_ticket_ops(): void {
        if (!current_user_can('edit_posts')) return;

        $available_tabs = [];

        if (roxy_suite_module_enabled('show_tickets')) {
            $available_tabs['door-mode'] = 'Door Mode';
        }

        if (roxy_suite_module_enabled('will_call') && function_exists('roxy_will_call_admin_page') && current_user_can('manage_woocommerce')) {
            $available_tabs['will-call'] = 'Will Call';
        }

        if (
            roxy_suite_module_enabled('sub_check') &&
            class_exists('\\Roxy_Sub_Check') &&
            method_exists('\\Roxy_Sub_Check', 'render_scan_log_page') &&
            (current_user_can('manage_woocommerce') || current_user_can('manage_options'))
        ) {
            $available_tabs['member-check-log'] = 'Member Check Log';
        }

        if (roxy_suite_module_enabled('show_tickets')) {
            $available_tabs['manual-checkin'] = 'Manual Check-in';
        }

        if (!$available_tabs) {
            wp_die('You do not have permission to access Ticket Ops.');
        }

        $default_tab = array_key_first($available_tabs);
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : $default_tab;
        if (!isset($available_tabs[$tab])) {
            $tab = $default_tab;
        }

        $base = admin_url('admin.php?page=roxy-ticket-ops');

        echo '<div class="wrap">';
        echo '<h1>Ticket Ops</h1>';
        echo '<nav class="nav-tab-wrapper">';
        foreach ($available_tabs as $slug => $label) {
            $url = $base . ($slug !== $default_tab ? '&tab=' . $slug : '');
            $active = ($tab === $slug) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($active) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        echo '</div>';

        switch ($tab) {
            case 'will-call':
                roxy_will_call_admin_page(false, false);
                break;
            case 'member-check-log':
                \Roxy_Sub_Check::render_scan_log_page(false, false);
                break;
            case 'manual-checkin':
                \RoxyST\Tickets::render_checkin_page(false);
                break;
            case 'door-mode':
            default:
                \RoxyST\Tickets::render_door_mode_page(false);
                break;
        }
    }

    public static function page_show_tickets(): void {
        if (!current_user_can('manage_options')) return;

        $base = admin_url('admin.php?page=roxy-show-tickets');

        echo '<div class="wrap">';
        echo '<h1>Show Tickets</h1>';

        echo '<nav class="nav-tab-wrapper">';
        echo '<a href="' . esc_url($base) . '" class="nav-tab nav-tab-active">Settings</a>';
        echo '<a href="' . esc_url(admin_url('edit.php?post_type=roxy_showing')) . '" class="nav-tab">Showings ↗</a>';
        echo '</nav>';

        echo '<div class="roxy-suite-tab-content">';
        \RoxyST\Settings::render_page(false);
        echo '</div>';

        echo '</div>';
    }

    public static function page_event_booking(): void {
        if (!current_user_can('manage_options')) return;

        $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'bookings';
        $base = admin_url('admin.php?page=roxy-event-booking');

        $tabs = [
            'bookings' => 'Bookings',
            'calendar' => 'Calendar Blocks',
            'settings' => 'Settings',
            'sling-logs' => 'Sling Logs',
        ];

        echo '<div class="wrap">';
        echo '<h1>Event Booking</h1>';

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $url = $base . ($slug !== 'bookings' ? '&tab=' . $slug : '');
            $active = ($tab === $slug) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($active) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        echo '</div>';

        switch ($tab) {
            case 'calendar':
                roxy_eb_admin_blocks_page();
                break;
            case 'settings':
                roxy_eb_admin_settings_page();
                break;
            case 'sling-logs':
                roxy_eb_admin_sling_logs_page();
                break;
            default:
                roxy_eb_admin_bookings_page();
                break;
        }
    }
}
