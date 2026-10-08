<?php
namespace RoxyRS;

use RoxyST\Issuance;

if (!defined('ABSPATH')) exit;

/** Durable replay receipt and uncertainty gate around backing INSERT acknowledgement. */
final class PledgeAttempts {
    public static function fingerprint(array $row): string {
        unset($row['created_at'], $row['updated_at'], $row['id']);
        ksort($row, SORT_STRING);
        foreach ($row as &$value) {
            if ($value !== null && !is_scalar($value)) throw new \RuntimeException('Invalid pledge fingerprint.');
            if ($value !== null) $value = (string) $value;
        }
        unset($value);
        $json = wp_json_encode($row);
        if (!is_string($json)) throw new \RuntimeException('Pledge fingerprint unavailable.');
        return hash('sha256', $json);
    }

    /** Existing five-minute exact replay policy, now independent of transient-save success. */
    public static function replay(int $request, int $user, string $hash): int {
        return self::run($request, $user, $hash, static function ($writer, $request, $pending, $done): int {
            if (self::read($writer, $request, $pending) !== []) throw new \RuntimeException('A prior pledge save is uncertain. Contact the theater; do not submit it again.');
            $values = self::read($writer, $request, $done);
            if ($values === []) return 0;
            if (count($values) !== 1 || !is_string($values[0])) throw new \RuntimeException('Pledge receipt is ambiguous.');
            $receipt = json_decode($values[0], true);
            if (!is_array($receipt) || ($receipt['version'] ?? null) !== 1
                || !is_int($receipt['id'] ?? null) || $receipt['id'] <= 0
                || !is_int($receipt['at'] ?? null) || $receipt['at'] <= 0 || $receipt['at'] > time()) throw new \RuntimeException('Pledge receipt needs review.');
            return time() - $receipt['at'] < 300 ? $receipt['id'] : 0;
        });
    }

    /** Must commit before INSERT. Any unconfirmed attempt blocks all new pledges for this user/request. */
    public static function begin(int $request, int $user, string $hash): void {
        self::run($request, $user, $hash, static function ($writer, $request, $pending) use ($hash): void {
            if (self::read($writer, $request, $pending) !== []) throw new \RuntimeException('A prior pledge save is uncertain. Contact the theater; do not submit it again.');
            $encoded = wp_json_encode(['version'=>1, 'hash'=>$hash, 'at'=>time()]);
            if (!is_string($encoded)) throw new \RuntimeException('Pledge evidence unavailable.');
            $writer->post_meta($request, $pending, $encoded);
            if (self::read($writer, $request, $pending) !== [$encoded]) throw new \RuntimeException('Pledge evidence readback failed.');
        });
    }

    /** Caller must have read back the actual saved backing and compared its fingerprint. */
    public static function finish(int $request, int $user, string $hash, int $backing): void {
        if ($backing <= 0) throw new \RuntimeException('Saved pledge identity unavailable.');
        self::run($request, $user, $hash, static function ($writer, $request, $pending, $done) use ($hash, $backing): void {
            $values = self::read($writer, $request, $pending);
            $attempt = count($values) === 1 && is_string($values[0]) ? json_decode($values[0], true) : null;
            if (!is_array($attempt) || ($attempt['version'] ?? null) !== 1 || ($attempt['hash'] ?? null) !== $hash) throw new \RuntimeException('Pledge save evidence changed; reconcile it.');
            $encoded = wp_json_encode(['version'=>1, 'id'=>$backing, 'at'=>time()]);
            if (!is_string($encoded)) throw new \RuntimeException('Pledge receipt unavailable.');
            $writer->post_meta($request, $done, $encoded);
            $writer->post_meta($request, $pending, '', true);
            if (self::read($writer, $request, $done) !== [$encoded] || self::read($writer, $request, $pending) !== []) throw new \RuntimeException('Pledge receipt readback failed.');
        });
    }

    private static function run(int $request, int $user, string $hash, callable $callback) {
        if ($request <= 0 || $user <= 0 || !preg_match('/^[a-f0-9]{64}$/D', $hash)
            || !class_exists(Issuance::class)) throw new \RuntimeException('Pledge safety identity unavailable.');
        $pending = '_roxy_rs_pledge_uncertain_' . $user;
        $done = '_roxy_rs_pledge_receipt_' . $user . '_' . $hash;
        return (new Issuance([], 'requested-pledge:' . $request . ':' . $user))->run(
                static function (Issuance $writer) use ($request, $pending, $done, $callback) {
                    global $wpdb;
                    $writer->assert_owner();
                    $wpdb->last_error = '';
                    $type = $wpdb->get_var($wpdb->prepare("SELECT post_type FROM `{$wpdb->posts}` WHERE ID=%d", $request));
                    if ($wpdb->last_error !== '' || $type !== CPT::POST_TYPE) throw new \RuntimeException('Pledge request could not be verified.');
                    return $callback($writer, $request, $pending, $done);
                }
        );
    }

    private static function read(Issuance $writer, int $request, string $key): array {
        global $wpdb;
        $writer->assert_owner();
        $wpdb->last_error = '';
        $values = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM `{$wpdb->postmeta}` WHERE post_id=%d AND meta_key=%s", $request, $key));
        if ($wpdb->last_error !== '' || !is_array($values)) throw new \RuntimeException('Pledge evidence could not be read.');
        $writer->assert_owner();
        return $values;
    }
}
