const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const root = process.argv[2] || require('node:path').resolve(__dirname, '..');
const source = fs.readFileSync(require('node:path').join(root, 'includes/modules/inventory/includes/class-roxy-inventory-admin.php'), 'utf8');
const match = source.match(/if \(!\$open\) echo '<script>(\(function\(\)\{[\s\S]*?\}\)\(\);)<\/script>';/);
assert.ok(match, 'vendor order review recalculation script is present');

function recalculate(input, minimum = 0) {
  const line = { textContent: '' };
  const button = { disabled: false };
  const total = { textContent: '' };
  let onInput;
  const table = {
    addEventListener(event, callback) { assert.equal(event, 'input'); onInput = callback; },
    querySelectorAll(selector) { assert.equal(selector, '.roxy-order-qty'); return [input]; },
  };
  input.closest = () => ({ querySelector: () => line });
  const elements = {
    'roxy-inventory-vendor-review': table,
    'roxy-submit-vendor-order': button,
    'roxy-order-minimum': { dataset: { minimum: String(minimum) } },
    'roxy-order-total': total,
  };
  vm.runInNewContext(match[1], { document: { getElementById: (id) => elements[id] } });
  assert.equal(typeof onInput, 'function', 'quantity input listener is registered');
  onInput();
  return { line, button, total };
}

const free = recalculate({ value: '2', dataset: { cost: '0', costKnown: '1' } });
assert.equal(free.line.textContent, '$0.00', 'documented Free item remains a known zero-cost line after quantity edits');
assert.equal(free.total.textContent, '$0.00', 'Free item does not mark the order estimate incomplete');
assert.equal(free.button.disabled, false, 'zero-minimum order containing a documented Free item remains submittable');

const unknown = recalculate({ value: '2', dataset: { cost: '', costKnown: '0' } });
assert.equal(unknown.line.textContent, 'Unknown', 'unknown cost stays visibly unknown after quantity edits');
assert.equal(unknown.total.textContent, 'Incomplete — $0.00 known', 'unknown cost makes the estimate incomplete');
assert.equal(unknown.button.disabled, true, 'unknown-cost order remains blocked in the UI');

const priced = recalculate({ value: '3', dataset: { cost: '1.5', costKnown: '1' } }, 4.5);
assert.equal(priced.line.textContent, '$4.50', 'known positive cost recalculates the line total');
assert.equal(priced.total.textContent, '$4.50', 'known positive cost recalculates the estimate');
assert.equal(priced.button.disabled, false, 'known order meeting its minimum remains submittable');

console.log('Passed Inventory order-review known/free/unknown cost UI regression checks.');
