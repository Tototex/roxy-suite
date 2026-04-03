<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_register_shortcodes() {
    add_shortcode('roxy_booking_calendar', 'roxy_eb_shortcode_calendar');

    add_action('wp_enqueue_scripts', function () {
        if (!is_singular()) return;
        global $post;
        if (!$post || strpos($post->post_content, '[roxy_booking_calendar') === false) return;

        wp_enqueue_style('roxy-eb-fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css', [], ROXY_EB_VERSION);
        wp_enqueue_script('roxy-eb-fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js', [], ROXY_EB_VERSION, true);

        wp_enqueue_style('roxy-eb', ROXY_EB_PLUGIN_URL . 'assets/roxy-eb.css', [], ROXY_EB_VERSION);
        wp_enqueue_script('roxy-eb', ROXY_EB_PLUGIN_URL . 'assets/roxy-eb.js', ['jquery', 'roxy-eb-fullcalendar'], ROXY_EB_VERSION, true);

        $settings = roxy_eb_get_settings();
        wp_localize_script('roxy-eb', 'RoxyEB', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('roxy_eb_nonce'),
            'leadTimeHours' => intval($settings['lead_time_hours']),
            'incrementMinutes' => intval($settings['time_increment_minutes']),
            'openTime' => $settings['open_time'],
            'closeTime' => $settings['close_time'],
            'guestCap' => intval($settings['guest_cap']),
            'pizzaPrice' => intval($settings['pizza_price'] ?? 18),
            'bulkItemPrice' => intval($settings['bulk_item_price'] ?? 3),
            'prices' => [
                'under' => intval($settings['base_price_under']),
                'over' => intval($settings['base_price_over']),
                'extra' => intval($settings['extra_hour_price']),
            ],
            'cancelFreeDays' => intval($settings['cancel_free_days']),
            'timezone' => wp_timezone_string(),
        ]);
    });

    add_action('wp_ajax_roxy_eb_calendar_blocks', 'roxy_eb_ajax_calendar_blocks');
    add_action('wp_ajax_nopriv_roxy_eb_calendar_blocks', 'roxy_eb_ajax_calendar_blocks');
}

function roxy_eb_shortcode_calendar() {
    ob_start();
    if (!empty($_GET['roxy_eb_submitted']) && $_GET['roxy_eb_submitted'] === 'invoice') {
        echo '<div class="roxy-eb-alert roxy-eb-alert--success"><div><strong>Booking request received.</strong> We will follow up with invoice details.</div></div>';
    }
    ?>
    <div class="roxy-eb-wrap">
        <div class="roxy-eb-header">
            <h3>Check Availability & Book</h3>
            <p>Select a date to see availability and start your booking. Friday/Saturday evenings and Sunday matinees are reserved for regular showings.</p>
        </div>

        <div class="roxy-eb-cta">
            <button type="button" class="roxy-eb-btn roxy-eb-btn--primary" id="roxy-eb-book-now">Book now</button>
        </div>

        <div id="roxy-eb-calendar"></div>

        <div class="roxy-eb-modal" id="roxy-eb-modal" aria-hidden="true">
            <div class="roxy-eb-modal__backdrop" data-roxy-eb-close></div>
            <div class="roxy-eb-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="roxy-eb-modal-title">
                <div class="roxy-eb-modal__top">
                    <h4 id="roxy-eb-modal-title">Book your event</h4>
                    <button type="button" class="roxy-eb-btn roxy-eb-btn--ghost" data-roxy-eb-close aria-label="Close">✕</button>
                </div>

                <div class="roxy-eb-alert roxy-eb-alert--info">
                    <div><strong>Duration:</strong> Guests see a 2-hour booking (plus any added hours). We block 30 minutes before and after for opening/cleanup.</div>
                    <div><strong>Cancellation:</strong> Free cancellation up to 7 days before your event. Within 7 days, contact us to cancel.</div>
                    <div><strong>Less than 48 hours:</strong> Please contact us — depends on staff availability.</div>
                </div>

                <form id="roxy-eb-form">
                    <input type="hidden" name="doors_open_at" id="roxy-eb-doors-open-at" value="" />

                    <div class="roxy-eb-grid">
                        <label>
                            <span>First name *</span>
                            <input type="text" name="first_name" required />
                        </label>
                        <label>
                            <span>Last name *</span>
                            <input type="text" name="last_name" required />
                        </label>
                        <label>
                            <span>Email *</span>
                            <input type="email" name="email" required />
                        </label>
                        <label>
                            <span>Phone *</span>
                            <input type="tel" name="phone" required />
                        </label>

                        <label>
                            <span>Personal or business? *</span>
                            <select name="customer_type" id="roxy-eb-customer-type" required>
                                <option value="personal">Personal</option>
                                <option value="business">Business</option>
                            </select>
                        </label>

                        <label id="roxy-eb-business-name-wrap" style="display:none;">
                            <span>Business name *</span>
                            <input type="text" name="business_name" />
                        </label>

                        <label id="roxy-eb-payment-method-wrap" style="display:none;">
                            <span>Payment method *</span>
                            <select name="payment_method" id="roxy-eb-payment-method">
                                <option value="pay_now">Pay now</option>
                                <option value="invoice">Invoice me</option>
                            </select>
                        </label>

                        <label>
                            <span>Guest count *</span>
                            <input type="number" name="guest_count" min="1" max="250" required />
                            <small class="roxy-eb-help">Pricing: $250 for 25 or less; $300 for 26+.</small>
                        </label>

                        <label>
                            <span>Date *</span>
                            <input type="date" name="event_date" id="roxy-eb-date" required />
                            <small class="roxy-eb-help">Pick a different date here without closing this window.</small>
                        </label>

                        <label>
                            <span>Start time (doors open) *</span>
                            <select name="doors_open_time" id="roxy-eb-doors-open-time" required></select>
                            <small class="roxy-eb-help">Show starts ~30 minutes after doors open.</small>
                        </label>

                        <label>
                            <span>Additional hours (optional)</span>
                            <select name="extra_hours" id="roxy-eb-extra-hours">
                                <option value="0">0 (standard 2 hours)</option>
                                <option value="1">+1 hour</option>
                                <option value="2">+2 hours</option>
                                <option value="3">+3 hours</option>
                                <option value="4">+4 hours</option>
                            </select>
                            <small class="roxy-eb-help">$100 per additional hour.</small>
                        </label>

                        <label>
                            <span>Event format *</span>
                            <select name="event_format" id="roxy-eb-event-format" required>
                                <option value="movie">Movie</option>
                                <option value="live">Live Event</option>
                            </select>
                        </label>

                        <label class="roxy-eb-span-2" id="roxy-eb-movie-title-wrap">
                            <span>Desired movie title *</span>
                            <input type="text" name="movie_title" />
                        </label>

                        <label class="roxy-eb-span-2" id="roxy-eb-live-desc-wrap" style="display:none;">
                            <span>Describe the live event *</span>
                            <textarea name="live_description" rows="3"></textarea>
                        </label>

                        <label>
                            <span>Add pizza? *</span>
                            <select name="pizza_requested" id="roxy-eb-pizza-requested">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </label>

                        <label id="roxy-eb-pizza-quantity-wrap" style="display:none;">
                            <span>Pizza quantity *</span>
                            <input type="number" name="pizza_quantity" min="1" value="1" />
                            <small class="roxy-eb-help">$18 per pizza. Large only. We recommend 1 pizza for every 4 people.</small>
                        </label>

                        <label class="roxy-eb-span-2" id="roxy-eb-pizza-details-wrap" style="display:none;">
                            <span>Pizza order details *</span>
                            <textarea name="pizza_order_details" rows="3" placeholder="Tell us toppings and how many of each. Large pizzas only, 2 toppings or less."></textarea>
                        </label>

                        <label>
                            <span>Bulk concessions? *</span>
                            <select name="bulk_concessions_requested" id="roxy-eb-bulk-concessions-requested">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </label>

                        <label id="roxy-eb-bulk-popcorn-wrap" style="display:none;">
                            <span>Bulk popcorn quantity</span>
                            <input type="number" name="bulk_popcorn_qty" min="0" max="250" value="0" />
                            <small class="roxy-eb-help">$3 per popcorn. Enter 0, or 25 to 250.</small>
                        </label>

                        <label id="roxy-eb-bulk-soda-wrap" style="display:none;">
                            <span>Bulk soda quantity</span>
                            <input type="number" name="bulk_soda_qty" min="0" max="250" value="0" />
                            <small class="roxy-eb-help">$3 per soda. Enter 0, or 25 to 250.</small>
                        </label>

                        <label class="roxy-eb-span-2">
                            <span>Event notes (optional)</span>
                            <textarea name="notes" rows="3" placeholder="Anything we should know? (seating requests, special instructions, etc.)"></textarea>
                            <small class="roxy-eb-help">These notes are shared with our staff and included on your confirmation.</small>
                        </label>

                        <label>
                            <span>Visibility *</span>
                            <select name="visibility" id="roxy-eb-visibility" required>
                                <option value="private">Private (your guests only)</option>
                                <option value="public">Public (contact us for details)</option>
                            </select>
                        </label>
                    </div>

                    <div class="roxy-eb-pricing" id="roxy-eb-pricing"></div>

                    <div class="roxy-eb-actions">
                        <button type="button" class="roxy-eb-btn roxy-eb-btn--ghost" data-roxy-eb-close>Cancel</button>
                        <button type="submit" class="roxy-eb-btn roxy-eb-btn--primary" id="roxy-eb-submit-btn">Continue to checkout</button>
                    </div>

                    <div class="roxy-eb-error" id="roxy-eb-error" style="display:none;"></div>
                </form>

                <div class="roxy-eb-alert roxy-eb-alert--success" id="roxy-eb-success" style="display:none; margin-top:16px;">
                    <div id="roxy-eb-success-message"><strong>Booking request submitted.</strong> Your time has been reserved.</div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function roxy_eb_ajax_calendar_blocks() {
    check_ajax_referer('roxy_eb_nonce', 'nonce');

    $start = sanitize_text_field($_GET['start'] ?? '');
    $end   = sanitize_text_field($_GET['end'] ?? '');

    $tz = wp_timezone();
    try {
        $rangeStart = new DateTimeImmutable($start, $tz);
        $rangeEnd   = new DateTimeImmutable($end, $tz);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Invalid range']);
    }

    $items = roxy_eb_get_calendar_blocks($rangeStart, $rangeEnd);
    wp_send_json_success(['items' => $items]);
}
