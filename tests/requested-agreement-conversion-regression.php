<?php
// Isolated Woo-shaped checks for exact gross agreement application. No WordPress writes or payment calls.

$GLOBALS['requested_agreement_test_tax_enabled'] = true;
$GLOBALS['requested_agreement_test_rates'] = [1 => 0.05, 2 => 0.03];

function wc_tax_enabled(): bool { return (bool) $GLOBALS['requested_agreement_test_tax_enabled']; }
function wc_get_price_decimals(): int { return 2; }
function wc_format_decimal($value, $decimals = 2): string { return number_format((float) $value, $decimals, '.', ''); }
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
if (!defined('ROXY_RS_SCHEMA_READY')) define('ROXY_RS_SCHEMA_READY', false);

class WP_Error {
    public function __construct(private string $code = '', private string $message = '') {}
    public function get_error_code(): string { return $this->code; }
}

class WC_Tax {
    public static function find_rates(array $location): array { return $GLOBALS['requested_agreement_test_rates']; }
    public static function calc_tax($price, array $rates, bool $price_includes_tax): array {
        if (!$price_includes_tax) return [];
        $tax_total = (float) $price - ((float) $price / (1 + array_sum($rates)));
        $total_rate = array_sum($rates);
        $taxes = [];
        foreach ($rates as $id => $rate) $taxes[$id] = $total_rate > 0 ? $tax_total * $rate / $total_rate : 0;
        return $taxes;
    }
}
class WC_Order { public function get_taxable_location(): array { return ['country'=>'US','state'=>'CA','postcode'=>'92663','city'=>'Newport Beach']; } }
class RequestedAgreementProduct {
    public function get_tax_class(): string { return ''; }
    public function is_taxable(): bool { return (bool) ($GLOBALS['requested_agreement_product_taxable'] ?? false); }
}
class WC_Order_Item_Product {
    public $total = 0.0; public $subtotal = 0.0; public $taxes = ['total'=>[], 'subtotal'=>[]];
    public function get_product() { return new RequestedAgreementProduct(); }
    public function set_subtotal($value): void { $this->subtotal = (float) $value; }
    public function set_total($value): void { $this->total = (float) $value; }
    public function set_taxes($taxes): void { $this->taxes = is_array($taxes) ? $taxes : ['total'=>[], 'subtotal'=>[]]; }
    public function get_total(): float { return $this->total; }
    public function get_total_tax(): float { return array_sum($this->taxes['total'] ?? []); }
    public function save(): void {}
}
class WC_Order_Item_Fee {
    public $amount = 0.0; public $total = 0.0; public $taxes = ['total'=>[]];
    public $tax_status = 'taxable';
    public function set_tax_status(string $status): void { $this->tax_status = $status; }
    public function set_amount($value): void { $this->amount = (float) $value; }
    public function set_total($value): void { $this->total = (float) $value; }
    public function set_taxes($taxes): void { $this->taxes = is_array($taxes) ? $taxes : ['total'=>[]]; }
    public function get_total(): float { return $this->total; }
    public function get_total_tax(): float { return array_sum($this->taxes['total'] ?? []); }
}

require dirname(__DIR__) . '/includes/modules/requested-showings/includes/class-roxy-rs-conversion.php';

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
};
$invoke = static function (string $method, array $args) {
    $reflection = new ReflectionMethod(\RoxyRS\Conversion::class, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke(null, ...$args);
};

$GLOBALS['requested_agreement_product_taxable'] = false;
$schema_error = \RoxyRS\Conversion::approve_request(1);
$check($schema_error instanceof WP_Error && $schema_error->get_error_code() === 'requested_showing_schema_unavailable', 'manager conversion fails closed when agreement schema is unavailable');
$item = new WC_Order_Item_Product();
$GLOBALS['requested_agreement_test_rates'] = [1 => 0.0825];
$check($invoke('set_agreed_gross', [new WC_Order(), $item, 1200]) === true, 'Woo line item receives the $12.00 nontaxable amount');
$check($item->total === 12.0 && $item->get_total_tax() === 0.0, 'nontaxable ticket line records no tax');
$GLOBALS['requested_agreement_product_taxable'] = true;
$check($invoke('set_agreed_gross', [new WC_Order(), new WC_Order_Item_Product(), 1200]) === false, 'accidentally taxable ticket products fail closed');
$fee = new WC_Order_Item_Fee();
$check($invoke('set_agreed_fee_gross', [new WC_Order(), $fee, 500]) === true, 'sponsorship fee receives the full agreed amount');
$check($fee->total === 5.0 && $fee->get_total_tax() === 0.0 && $fee->tax_status === 'none', 'sponsorship fee is nontaxable and records no tax');
$check($invoke('money_to_cents', ['12.00']) === 1200 && $invoke('money_to_cents', ['12.001']) === -1, 'order total validation accepts cents and rejects fractional cents');

echo "OK: $checks requested-showing conversion agreement checks passed\n";
