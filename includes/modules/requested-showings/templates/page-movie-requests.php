<?php
if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<main id="primary" class="site-main roxy-rs-standalone-page">
    <div class="roxy-rs-standalone-wrap">
        <header class="roxy-rs-hero">
            <p class="roxy-rs-kicker">Requested Showings</p>
            <h1>Help Us Book The Movie You Want To See</h1>
            <p>Back a movie that is already gathering support, or send us a new idea and we will build the backing page with you. Once a request gets enough support, we can turn it into a real Roxy showing on the big screen.</p>
            <div class="roxy-rs-hero-actions">
                <a class="roxy-rs-button roxy-rs-button-primary" href="#open-requested-showings">Back an open request</a>
                <a class="roxy-rs-button roxy-rs-button-secondary" href="#request-a-film">Request a movie</a>
            </div>
        </header>

        <section class="roxy-rs-list-block" id="open-requested-showings">
            <h2>Open Requested Showings</h2>
            <p>These requests are live right now. Back tickets or sponsor one to help us secure the movie.</p>
            <?php echo do_shortcode('[roxy_requested_showings]'); ?>
        </section>

        <section class="roxy-rs-explainer">
            <h2>How It Works</h2>
            <div class="roxy-rs-info-grid">
                <div class="roxy-rs-info-card">
                    <h3>1. Submit the idea</h3>
                    <p>Tell us the movie you want, when you hope to see it, and why it should work in Newport.</p>
                </div>
                <div class="roxy-rs-info-card">
                    <h3>2. We build the page</h3>
                    <p>We review the request, lock in the backing deadline, and add artwork, trailer, and pricing on our side.</p>
                </div>
                <div class="roxy-rs-info-card">
                    <h3>3. Backers raise the goal</h3>
                    <p>Paid backer tickets count toward the dollar goal. Subscriber reservations help gauge interest but do not count toward funding.</p>
                </div>
                <div class="roxy-rs-info-card">
                    <h3>4. Sponsor if you want it faster</h3>
                    <p>A sponsor can cover the remaining gap and move the request forward immediately.</p>
                </div>
                <div class="roxy-rs-info-card">
                    <h3>5. We confirm and schedule it</h3>
                    <p>Once it is funded, we secure the title, create the real showing, and charge saved payment methods.</p>
                </div>
                <div class="roxy-rs-info-card">
                    <h3>6. If it does not happen, no charge</h3>
                    <p>If the request does not move forward, backers are notified and cards are never charged.</p>
                </div>
            </div>
            <div class="roxy-rs-policy-box">
                <p><strong>Current default goal:</strong> $300 pledged or one sponsor.</p>
                <p><strong>Best chance of approval:</strong> choose a target date at least 30 days out. We usually need about 2 weeks after backing comes in to secure and schedule the film.</p>
                <p><strong>No refunds after scheduling:</strong> once the showing is confirmed and charges go through, tickets are final.</p>
            </div>
        </section>

        <section class="roxy-rs-submit-block" id="request-a-film">
            <?php echo do_shortcode('[roxy_requested_showing_submit]'); ?>
        </section>
    </div>
</main>
<?php
get_footer();
