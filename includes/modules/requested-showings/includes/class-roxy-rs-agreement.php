<?php
namespace RoxyRS;

/** Versioned, immutable price agreement for one requested-showing backing. */
final class Agreement {
    private const VERSION = 1;
    private const MAX_MONEY = 2147483647;
    private const MAX_QTY = 4294967295;
    // Customer-facing prices are final whole-dollar amounts. WooCommerce may
    // have taxes enabled globally, but Requested Showings absorbs any tax on
    // our side and never adds it to the customer's agreed charge.
    private const TICKET_TAX_POLICY = 'tax_absorbed_by_roxy';

    /** Capture the ticket quote and site tax context without changing the final customer charge. */
    public static function quote(int $request_id, string $profile, array $prices_cents, string $currency, bool $taxes_enabled = false): array {
        if ($request_id <= 0) throw new \InvalidArgumentException('Agreement request identity is invalid.');
        if (!in_array($profile, ['movie_evening', 'movie_matinee'], true)) throw new \InvalidArgumentException('Agreement pricing profile is invalid.');
        if (!preg_match('/^[A-Za-z]{3}$/D', $currency)) throw new \InvalidArgumentException('Agreement currency must be a three-letter code.');
        $allowed = ['general', 'discount', 'matinee'];
        $keys = array_keys($prices_cents);
        sort($keys);
        $expected = $allowed;
        sort($expected);
        if ($keys !== $expected) throw new \InvalidArgumentException('Agreement ticket prices are incomplete or contain unknown prices.');
        $prices = [];
        foreach ($allowed as $key) $prices[$key] = self::integer($prices_cents[$key], self::MAX_MONEY, 'ticket price');

        $quote = [
            'version' => self::VERSION,
            'request_id' => $request_id,
            'currency' => strtoupper($currency),
            'profile' => $profile,
            'unit_prices_cents' => $prices,
            'taxes_enabled' => $taxes_enabled,
            'ticket_tax_policy' => self::TICKET_TAX_POLICY,
        ];
        $quote['quote_hash'] = hash('sha256', self::encode($quote));
        return $quote;
    }

    /** Stable fingerprint for the quote fields, excluding any supplied hash. */
    public static function quote_hash(array $quote): string {
        $core = self::quote_core($quote);
        return hash('sha256', self::encode($core));
    }

    /** Build immutable JSON from a quote and normalized repository backing row. */
    public static function build(array $quote, array $backing): string {
        $core = self::quote_core($quote);
        $expected_quote_hash = hash('sha256', self::encode($core));
        if (!isset($quote['quote_hash']) || !is_string($quote['quote_hash'])
            || !hash_equals($expected_quote_hash, $quote['quote_hash'])) {
            throw new \InvalidArgumentException('Agreement quote hash is invalid.');
        }
        if (self::integer($backing['request_id'] ?? null, PHP_INT_MAX, 'request id') !== $core['request_id']) {
            throw new \InvalidArgumentException('Agreement backing belongs to a different request.');
        }

        $quantities = [];
        foreach (['general_qty', 'discount_qty', 'subscriber_qty', 'sponsor_ticket_qty', 'support_qty'] as $key) {
            $quantities[$key] = self::integer($backing[$key] ?? null, self::MAX_QTY, $key);
        }
        if ($quantities['general_qty'] > self::MAX_QTY - $quantities['discount_qty']
            || $quantities['support_qty'] !== $quantities['general_qty'] + $quantities['discount_qty']) {
            throw new \InvalidArgumentException('Agreement ticket quantities are inconsistent.');
        }
        if ($core['profile'] === 'movie_matinee' && $quantities['discount_qty'] !== 0) {
            throw new \InvalidArgumentException('Matinee agreements cannot include discount-profile tickets.');
        }

        $sponsor_amount = self::integer($backing['sponsor_amount'] ?? null, self::MAX_MONEY, 'sponsor amount');
        $tax_cents = array_key_exists('tax_cents', $backing)
            ? self::integer($backing['tax_cents'], self::MAX_MONEY, 'tax amount')
            : 0;
        $sponsor_tax_cents = array_key_exists('sponsor_tax_cents', $backing)
            ? self::integer($backing['sponsor_tax_cents'], self::MAX_MONEY, 'sponsor tax amount')
            : 0;
        if ($tax_cents !== 0 || $sponsor_tax_cents !== 0) {
            throw new \InvalidArgumentException('Customer-facing agreement prices already include absorbed tax; no tax may be added to the charge.');
        }

        $unit = $core['unit_prices_cents'];
        $ticket_total = 0;
        $ticket_total = self::add_product($ticket_total, $quantities['general_qty'], $unit[$core['profile'] === 'movie_matinee' ? 'matinee' : 'general']);
        if ($core['profile'] === 'movie_evening') $ticket_total = self::add_product($ticket_total, $quantities['discount_qty'], $unit['discount']);
        $total = self::add_money($ticket_total, $sponsor_amount);
        $charge_total = self::integer($backing['charge_total'] ?? null, self::MAX_MONEY, 'charge total');
        if ($charge_total !== $total) throw new \InvalidArgumentException('Backing charge total does not match the quoted agreement.');

        $snapshot = $core + [
            'quote_hash' => $expected_quote_hash,
            'quantities' => $quantities,
            'sponsor_amount_cents' => $sponsor_amount,
            'sponsor_tax_cents' => $sponsor_tax_cents,
            'tax_cents' => $tax_cents,
            'total_cents' => $total,
        ];
        $snapshot['hash'] = hash('sha256', self::encode($snapshot));
        return self::encode($snapshot);
    }

    /** Validate exact persisted JSON against the current normalized backing row. */
    public static function validate(string $snapshot_json, array $backing): array {
        try {
            $snapshot = json_decode($snapshot_json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('Agreement snapshot is not valid JSON.');
        }
        if (!is_array($snapshot) || !isset($snapshot['hash']) || !is_string($snapshot['hash'])) {
            throw new \InvalidArgumentException('Agreement snapshot is incomplete.');
        }
        $hash = $snapshot['hash'];
        unset($snapshot['hash']);
        if (!hash_equals(hash('sha256', self::encode($snapshot)), $hash)) {
            throw new \InvalidArgumentException('Agreement snapshot hash does not match.');
        }

        $quote = [
            'version' => $snapshot['version'] ?? null,
            'request_id' => $snapshot['request_id'] ?? null,
            'currency' => $snapshot['currency'] ?? null,
            'profile' => $snapshot['profile'] ?? null,
            'unit_prices_cents' => $snapshot['unit_prices_cents'] ?? null,
            'taxes_enabled' => $snapshot['taxes_enabled'] ?? null,
            'ticket_tax_policy' => $snapshot['ticket_tax_policy'] ?? null,
            'quote_hash' => $snapshot['quote_hash'] ?? null,
        ];
        $core = self::quote_core($quote);
        if (!is_string($quote['quote_hash']) || !hash_equals(hash('sha256', self::encode($core)), $quote['quote_hash'])) {
            throw new \InvalidArgumentException('Agreement quote hash does not match.');
        }

        $quantities = $snapshot['quantities'] ?? null;
        if (!is_array($quantities)) throw new \InvalidArgumentException('Agreement quantities are missing.');
        $candidate = $backing + [
            'request_id' => $snapshot['request_id'] ?? null,
            'general_qty' => $quantities['general_qty'] ?? null,
            'discount_qty' => $quantities['discount_qty'] ?? null,
            'subscriber_qty' => $quantities['subscriber_qty'] ?? null,
            'sponsor_ticket_qty' => $quantities['sponsor_ticket_qty'] ?? null,
            'support_qty' => $quantities['support_qty'] ?? null,
            'sponsor_amount' => $snapshot['sponsor_amount_cents'] ?? null,
            'sponsor_tax_cents' => $snapshot['sponsor_tax_cents'] ?? null,
            'tax_cents' => $snapshot['tax_cents'] ?? null,
            'charge_total' => $snapshot['total_cents'] ?? null,
        ];
        // Prefer explicit backing values; validation must compare them to the snapshot.
        foreach (['request_id','general_qty','discount_qty','subscriber_qty','sponsor_ticket_qty','support_qty','sponsor_amount','charge_total'] as $key) {
            if (!array_key_exists($key, $backing)) throw new \InvalidArgumentException('Backing is missing agreement field: ' . $key);
        }
        $rebuilt = json_decode(self::build($quote, $candidate), true, 32, JSON_THROW_ON_ERROR);
        if ($rebuilt !== array_merge($snapshot, ['hash' => $hash])) {
            throw new \InvalidArgumentException('Backing no longer matches its agreement snapshot.');
        }
        return array_merge($snapshot, ['hash' => $hash]);
    }

    private static function quote_core(array $quote): array {
        if (($quote['version'] ?? null) !== self::VERSION) throw new \InvalidArgumentException('Agreement version is unsupported.');
        $request_id = self::integer($quote['request_id'] ?? null, PHP_INT_MAX, 'request id');
        if ($request_id <= 0) throw new \InvalidArgumentException('Agreement request identity is invalid.');
        $profile = $quote['profile'] ?? null;
        if (!is_string($profile) || !in_array($profile, ['movie_evening', 'movie_matinee'], true)) throw new \InvalidArgumentException('Agreement pricing profile is invalid.');
        $currency = $quote['currency'] ?? null;
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) throw new \InvalidArgumentException('Agreement currency is invalid.');
        if (!is_bool($quote['taxes_enabled'] ?? null)) throw new \InvalidArgumentException('Agreement tax context is invalid.');
        if (($quote['ticket_tax_policy'] ?? null) !== self::TICKET_TAX_POLICY) throw new \InvalidArgumentException('Agreement ticket tax policy is unsupported.');
        $prices = $quote['unit_prices_cents'] ?? null;
        if (!is_array($prices) || array_keys($prices) !== ['general', 'discount', 'matinee']) throw new \InvalidArgumentException('Agreement ticket prices are incomplete.');
        foreach ($prices as $key => $value) $prices[$key] = self::integer($value, self::MAX_MONEY, 'ticket price');
        return [
            'version' => self::VERSION,
            'request_id' => $request_id,
            'currency' => $currency,
            'profile' => $profile,
            'unit_prices_cents' => $prices,
            'taxes_enabled' => $quote['taxes_enabled'],
            'ticket_tax_policy' => self::TICKET_TAX_POLICY,
        ];
    }

    private static function integer($value, int $maximum, string $label): int {
        if (is_int($value)) {
            if ($value >= 0 && $value <= $maximum) return $value;
            throw new \InvalidArgumentException('Agreement ' . $label . ' is outside the supported range.');
        }
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            throw new \InvalidArgumentException('Agreement ' . $label . ' must be a canonical nonnegative integer.');
        }
        $max = (string) $maximum;
        if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
            throw new \InvalidArgumentException('Agreement ' . $label . ' exceeds the supported range.');
        }
        return (int) $value;
    }

    private static function add_product(int $total, int $quantity, int $unit): int {
        if ($unit > 0 && $quantity > intdiv(self::MAX_MONEY - $total, $unit)) throw new \InvalidArgumentException('Agreement total exceeds the supported range.');
        return $total + ($quantity * $unit);
    }

    private static function add_money(int $left, int $right): int {
        if ($right > self::MAX_MONEY - $left) throw new \InvalidArgumentException('Agreement total exceeds the supported range.');
        return $left + $right;
    }

    private static function encode(array $data): string {
        try {
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('Agreement data could not be encoded.');
        }
    }
}
