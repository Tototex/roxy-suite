<?php
namespace RoxyRS;

use RoxyST\Issuance;

if (!defined('ABSPATH')) exit;

/** Creation evidence survives a worker crash; leases never enclose provider SQL transactions. */
final class ConversionClaims {
    public static function lease(string $scope): Issuance {
        if (!class_exists(Issuance::class)) throw new \RuntimeException('Conversion safety storage is unavailable.');
        $lease = new Issuance([], 'requested-conversion:' . $scope);
        $lease->acquire_lease();
        return $lease;
    }

    /** Never silently replace a started creation whose saved entity link was lost. */
    public static function begin_creation(int $request_id, string $kind, int $backing_id = 0): void {
        if ($request_id <= 0 || !in_array($kind, ['showing', 'order'], true)
            || ($kind === 'order' && $backing_id <= 0)
            || ($kind === 'showing' && $backing_id !== 0)) throw new \RuntimeException('Invalid conversion identity.');
        $key = '_roxy_rs_creation_' . $kind . ($backing_id ? '_' . $backing_id : '');
        $encoded = wp_json_encode(['version'=>1, 'request_id'=>$request_id, 'backing_id'=>$backing_id,
            'kind'=>$kind, 'started_at'=>current_time('mysql', true)]);
        if (!is_string($encoded)) throw new \RuntimeException('Conversion evidence could not be encoded.');
        (new Issuance([], 'requested-creation:' . $request_id . ':' . $key))->run(
            static function (Issuance $writer) use ($request_id, $key, $encoded): void {
                global $wpdb;
                $writer->assert_owner();
                $wpdb->last_error = '';
                $type = $wpdb->get_var($wpdb->prepare("SELECT post_type FROM `{$wpdb->posts}` WHERE ID=%d", $request_id));
                if ($wpdb->last_error !== '' || $type !== CPT::POST_TYPE) throw new \RuntimeException('Conversion request could not be verified.');
                $read = static function () use ($wpdb, $writer, $request_id, $key): array {
                    $writer->assert_owner();
                    $wpdb->last_error = '';
                    $values = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM `{$wpdb->postmeta}` WHERE post_id=%d AND meta_key=%s", $request_id, $key));
                    if ($wpdb->last_error !== '' || !is_array($values)) throw new \RuntimeException('Conversion evidence could not be read.');
                    $writer->assert_owner();
                    return $values;
                };
                if ($read() !== []) throw new \RuntimeException('Creation already started without a verified saved link; reconcile it before retrying.');
                $writer->post_meta($request_id, $key, $encoded);
                if ($read() !== [$encoded]) throw new \RuntimeException('Conversion evidence could not be verified.');
            }
        );
    }
}
