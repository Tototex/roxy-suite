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
        // Show Tickets (tabbed: Settings | Door Mode | Check-In | Showings↗)
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

        // Event Booking (tabbed: Bookings | Calendar | Settings | Sling Logs)
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

        // Grosses (uses RoxyGrosses\Settings::render_page() with its own tab UI)
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

    // ── Show Tickets ────────────────────────────────────────────────────────────

    public static function page_show_tickets(): void {
        if (!current_user_can('manage_options')) return;

        $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        $base = admin_url('admin.php?page=roxy-show-tickets');

        $tabs = [
            'settings'  => 'Settings',
            'door-mode' => 'Door Mode',
            'check-in'  => 'Check-In',
        ];

        echo '<div class="wrap">';
        echo '<h1>Show Tickets</h1>';

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $url    = $base . ($slug !== 'settings' ? '&tab=' . $slug : '');
            $active = ($tab === $slug) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($active) . '">' . esc_html($label) . '</a>';
        }
        // Showings is a link to the CPT list, not a tab
        echo '<a href="' . esc_url(admin_url('edit.php?post_type=roxy_showing')) . '" class="nav-tab">Showings ↗</a>';
        echo '</nav>';

        echo '<div class="roxy-suite-tab-content">';
        switch ($tab) {
            case 'door-mode':
                \RoxyST\Tickets::render_door_mode_page(false);
                break;
            case 'check-in':
                \RoxyST\Tickets::render_checkin_page(false);
                break;
            default:
                \RoxyST\Settings::render_page(false);
                break;
        }
        echo '</div>';

        echo '</div>'; // .wrap
    }

    // ── Event Booking ───────────────────────────────────────────────────────────

    public static function page_event_booking(): void {
        if (!current_user_can('manage_options')) return;

        $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'bookings';
        $base = admin_url('admin.php?page=roxy-event-booking');

        $tabs = [
            'bookings'  => 'Bookings',
            'calendar'  => 'Calendar Blocks',
            'settings'  => 'Settings',
            'sling-logs' => 'Sling Logs',
        ];

        echo '<div class="wrap">';
        echo '<h1>Event Booking</h1>';

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $url    = $base . ($slug !== 'bookings' ? '&tab=' . $slug : '');
            $active = ($tab === $slug) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($active) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        // EB render functions wrap themselves in .wrap — we close our outer .wrap
        // before calling them to avoid double-nesting.
        echo '</div>'; // .wrap (header only)

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
