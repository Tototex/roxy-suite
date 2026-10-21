<?php
// Focused room-reservation overlap checks for actual showings.
namespace RoxyST {
    final class CPT { public const POST_TYPE = 'roxy_showing'; }
}

namespace {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
    function wp_timezone(): DateTimeZone { return new DateTimeZone('America/Los_Angeles'); }
    function get_posts(array $args): array { return [42]; }
    function get_post_meta(int $post_id, string $key, bool $single = false) {
        return $post_id === 42 && $key === '_roxy_start' ? '2040-05-04 19:00:00' : '';
    }
    function get_the_title(int $post_id): string { return $post_id === 42 ? 'Fixture Show' : ''; }

    require dirname(__DIR__) . '/includes/modules/event-booking/includes/availability.php';

    $show_start = new DateTimeImmutable('2040-05-04 19:00:00', wp_timezone());
    $blocks = roxy_eb_get_showing_blocks_for_range(
        $show_start->modify('-2 hours'),
        $show_start->modify('+2 hours'),
        true
    );
    if (count($blocks) !== 1) throw new RuntimeException('The fixture showing must produce one room reservation.');

    $touching_at_reserved_start = roxy_eb_get_showing_blocks_for_range(
        $show_start->modify('-3 hours'),
        $show_start->modify('-2 hours'),
        true
    );
    if ($touching_at_reserved_start !== []) throw new RuntimeException('A booking ending exactly when the reserved window begins must not conflict.');

    $touching_at_reserved_end = roxy_eb_get_showing_blocks_for_range(
        $show_start->modify('+2 hours'),
        $show_start->modify('+3 hours'),
        true
    );
    if ($touching_at_reserved_end !== []) throw new RuntimeException('A booking beginning exactly when the reserved window ends must not conflict.');

    $pre_show_overlap = roxy_eb_get_showing_blocks_for_range(
        $show_start->modify('-90 minutes'),
        $show_start->modify('-30 minutes'),
        true
    );
    if (count($pre_show_overlap) !== 1) throw new RuntimeException('A rental overlapping the pre-show reservation must conflict even though it ends before showtime.');

    $post_show_overlap = roxy_eb_get_showing_blocks_for_range(
        $show_start->modify('+90 minutes'),
        $show_start->modify('+150 minutes'),
        true
    );
    if (count($post_show_overlap) !== 1) throw new RuntimeException('A rental overlapping the post-show reservation must conflict.');

    echo "PASS: showing room reservation includes the two-hour pre/post buffers with half-open boundaries.\n";
}
