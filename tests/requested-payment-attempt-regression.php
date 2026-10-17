<?php
/** Static guardrails; end-to-end behavior runs against real Woo CRUD/MySQL in both storage modes. */
$root = $argv[1] ?? dirname(__DIR__);
$source = file_get_contents($root . '/includes/modules/requested-showings/includes/class-roxy-rs-payment-attempts.php');
$schema = file_get_contents($root . '/includes/modules/requested-showings/includes/schema.php');
$workflow = file_get_contents($root . '/.github/workflows/php-compatibility.yml');
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
};
$check(is_string($source) && !str_contains($source, 'RoxyST\\Issuance') && !str_contains($source, 'new Issuance'), 'payment-attempt guard no longer depends on the legacy-only ticket transaction');
$check(str_contains($source, "get_type() !== 'shop_order'") && str_contains($source, 'get_status()') && str_contains($source, 'get_customer_id()')
    && str_contains($source, 'get_total()') && str_contains($source, 'get_currency()'), 'order identity and financial properties are read through WooCommerce CRUD');
$check(str_contains($source, 'assert_no_legacy_markers') && !str_contains($source, 'SELECT post_type FROM') && !str_contains($source, 'SELECT post_status FROM'), 'legacy marker guard remains separate from Woo order properties');
$check(str_contains($source, 'INSERT INTO `$table` (order_id, attempt_json') && str_contains($source, 'result_json IS NULL'), 'unique durable claim and conditional immutable result writes are present');
$check(str_contains($schema, 'roxy_rs_payment_attempts') && str_contains($schema, 'PRIMARY KEY  (order_id)') && str_contains($schema, "!== 'InnoDB'"), 'schema provisions and verifies the InnoDB order-id uniqueness constraint');
$check(str_contains($workflow, 'requested-payment-attempt-mysql.php') && str_contains($workflow, ' legacy') && str_contains($workflow, ' hpos'), 'CI runs real Woo/MySQL fixtures in legacy and authoritative HPOS modes');
echo 'OK: ' . $checks . " payment-attempt architecture guardrails\n";
