<?php
// Isolated requested-showing price agreement tests. Run: php tests/requested-agreement-regression.php [repo-root]

$root = $argv[1] ?? dirname(__DIR__);
require $root . '/includes/modules/requested-showings/includes/class-roxy-rs-agreement.php';

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
};
$throws = static function (callable $callback, string $label, ?string $contains = null) use ($check): void {
    try { $callback(); }
    catch (Throwable $error) {
        $check($contains === null || str_contains($error->getMessage(), $contains), $label . ' (expected error detail)');
        return;
    }
    $check(false, $label . ' (expected rejection)');
};
$prices = ['general' => 1200, 'discount' => 800, 'matinee' => 800];
$backing = [
    'request_id' => 42,
    'general_qty' => '2',
    'discount_qty' => '1',
    'subscriber_qty' => '1',
    'sponsor_ticket_qty' => '2',
    'support_qty' => '3',
    'sponsor_amount' => '500',
    'charge_total' => '3700',
];

$quote = RoxyRS\Agreement::quote(42, 'movie_evening', $prices, 'usd', false);
$check($quote['taxes_enabled'] === false && $quote['ticket_tax_policy'] === 'not_collected', 'tax-disabled agreement records the nontaxable ticket policy');
$check($quote['currency'] === 'USD' && $quote['quote_hash'] === RoxyRS\Agreement::quote_hash($quote), 'currency normalized and canonical quote hash');
$snapshot = RoxyRS\Agreement::build($quote, $backing);
$validated = RoxyRS\Agreement::validate($snapshot, $backing);
$check($validated['total_cents'] === 3700 && $validated['quantities']['subscriber_qty'] === 1, 'mixed paid/free evening backing round trips');
$check($validated['quantities']['sponsor_ticket_qty'] === 2 && $validated['sponsor_amount_cents'] === 500, 'included sponsor tickets stay free while sponsor fee is preserved');

$ticket_only_quote = RoxyRS\Agreement::quote(45, 'movie_evening', $prices, 'USD', true);
$ticket_only = $backing;
$ticket_only['request_id'] = 45;
$ticket_only['sponsor_amount'] = '0';
$ticket_only['charge_total'] = '3200';
$ticket_only_snapshot = RoxyRS\Agreement::build($ticket_only_quote, $ticket_only);
$ticket_only_valid = RoxyRS\Agreement::validate($ticket_only_snapshot, $ticket_only);
$check($ticket_only_valid['taxes_enabled'] === true && $ticket_only_valid['ticket_tax_policy'] === 'not_collected', 'global tax enablement does not change the nontaxable ticket policy');

$matinee_quote = RoxyRS\Agreement::quote(43, 'movie_matinee', $prices, 'USD', false);
$matinee = $backing;
$matinee['request_id'] = 43;
$matinee['discount_qty'] = '0';
$matinee['support_qty'] = '2';
$matinee['charge_total'] = '2100';
$matinee['sponsor_amount'] = '500';
$matinee_snapshot = RoxyRS\Agreement::build($matinee_quote, $matinee);
$check(RoxyRS\Agreement::validate($matinee_snapshot, $matinee)['total_cents'] === 2100, 'matinee price and free subscriber/sponsor quantities');

$taxed_quote = RoxyRS\Agreement::quote(42, 'movie_evening', $prices, 'USD', true);
$taxed = RoxyRS\Agreement::validate(RoxyRS\Agreement::build($taxed_quote, $backing), $backing);
$check($taxed['tax_cents'] === 0 && $taxed['total_cents'] === 3700, 'tax-enabled sponsorship keeps the final customer charge unchanged');
$bad_tax = $backing;
$bad_tax['sponsor_tax_cents'] = '45';
$bad_tax['charge_total'] = '3745';
$throws(static fn() => RoxyRS\Agreement::build($taxed_quote, $bad_tax), 'separate tax charge is rejected when Roxy absorbs tax');

$throws(static fn() => RoxyRS\Agreement::quote(42, 'unknown', $prices, 'USD', false), 'unknown profile rejected');
$throws(static fn() => RoxyRS\Agreement::quote(42, 'movie_evening', $prices, 'US', false), 'non-three-letter currency rejected');
$throws(static fn() => RoxyRS\Agreement::quote(42, 'movie_evening', ['general'=>1,'discount'=>1], 'USD', false), 'incomplete price set rejected');
$throws(static fn() => RoxyRS\Agreement::quote(42, 'movie_evening', ['general'=>1.5,'discount'=>1,'matinee'=>1], 'USD', false), 'fractional unit cents rejected');

$bad = $backing;
$bad['request_id'] = 44;
$throws(static fn() => RoxyRS\Agreement::build($quote, $bad), 'quote cannot be applied to another request');
$bad = $backing;
unset($bad['request_id']);
$throws(static fn() => RoxyRS\Agreement::build($quote, $bad), 'missing request identity cannot build agreement');
$throws(static fn() => RoxyRS\Agreement::validate($snapshot, $bad), 'snapshot cannot supply a missing backing request identity');
$bad = $backing;
$bad['general_qty'] = '02';
$throws(static fn() => RoxyRS\Agreement::build($quote, $bad), 'noncanonical quantity rejected');
$bad = $backing;
$bad['subscriber_qty'] = 4294967296;
$throws(static fn() => RoxyRS\Agreement::build($quote, $bad), 'quantity above storage range rejected');
$bad = $backing;
$bad['charge_total'] = '3699';
$throws(static fn() => RoxyRS\Agreement::build($quote, $bad), 'charge total must exactly match quoted price');
$bad = $backing;
$bad['general_qty'] = '3';
$throws(static fn() => RoxyRS\Agreement::validate($snapshot, $bad), 'snapshot rejects changed backing quantities');

$tampered = json_decode($snapshot, true);
$tampered['total_cents'] = 999;
$throws(static fn() => RoxyRS\Agreement::validate(json_encode($tampered), $backing), 'tampered snapshot hash rejected');
$unsupported = json_decode($snapshot, true);
$unsupported['version'] = 3;
unset($unsupported['hash']);
$unsupported['hash'] = hash('sha256', json_encode($unsupported, JSON_UNESCAPED_SLASHES));
$throws(static fn() => RoxyRS\Agreement::validate(json_encode($unsupported, JSON_UNESCAPED_SLASHES), $backing), 'unsupported snapshot version rejected');
$throws(static fn() => RoxyRS\Agreement::validate('', $backing), 'missing legacy snapshot is not inferred');

echo "OK: $checks requested-showing agreement checks passed\n";
