<?php
// WP-CLI read only. Aggregate unit ambiguity without exposing requester data.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
global $wpdb;
$wpdb->last_error = '';
$counts = $wpdb->get_row($wpdb->prepare("SELECT COUNT(DISTINCT p.ID) AS requests,
    COUNT(DISTINCT CASE WHEN pm.meta_value REGEXP '^[0-9]+$' AND CAST(pm.meta_value AS UNSIGNED) BETWEEN 1 AND 999 THEN p.ID END) AS small_amount_requests
    FROM `{$wpdb->posts}` p LEFT JOIN `{$wpdb->postmeta}` pm ON pm.post_id=p.ID AND pm.meta_key IN (%s,%s)
    WHERE p.post_type=%s", \RoxyRS\CPT::META_FUNDING_GOAL, \RoxyRS\CPT::META_SPONSOR_AMOUNT, \RoxyRS\CPT::POST_TYPE), ARRAY_A);
if ($wpdb->last_error !== '' || !is_array($counts)) throw new RuntimeException('Requested monetary baseline unavailable.');
echo json_encode($counts) . "\n";
