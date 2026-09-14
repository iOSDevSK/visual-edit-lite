import assert from 'node:assert/strict';
import '../assets/popup-values.js';
const v = globalThis.ClaraVEValues;
assert.equal(v.step('19.2px', 4), '23.2px');
assert.equal(v.step('1.5rem', 0.1), '1.6rem');
assert.equal(v.step('50%', 2), '52%');
assert.equal(v.step('', 4, { unit: 'px' }), '4px');
assert.equal(v.step('1px', -4, { min: 0 }), '0px');
assert.equal(v.step('0px', -4), '-4px');
assert.equal(v.step('0.9', 0.2, { max: 1 }), '1');
for (const value of ['calc(100% - 20px)', 'var(--wp--preset--spacing--40)', 'auto', '20px 10px']) {
  assert.equal(v.number(value), null);
  assert.equal(v.step(value, 1), null, 'Never flatten a complex CSS value on a nudge');
}
assert.deepEqual(v.box('1rem'), { top: '1rem', right: '1rem', bottom: '1rem', left: '1rem' });
assert.deepEqual(v.box('calc(1rem + 2px) 10% 0'), { top: 'calc(1rem + 2px)', right: '10%', bottom: '0', left: '10%' });
assert.deepEqual(v.box('10px 20px / 30% 40%', true), { topLeft: '10px 30%', topRight: '20px 40%', bottomRight: '10px 30%', bottomLeft: '20px 40%' });
assert.deepEqual(v.box({ topLeft: '4px', bottomRight: '50%' }, true), { topLeft: '4px', bottomRight: '50%' });
const css = v.makeGradient('rgb(20, 30, 40)', 'var(--wp--preset--color--base)', 'to right');
assert.deepEqual(v.parseGradient(css), { direction: 'to right', from: 'rgb(20, 30, 40)', to: 'var(--wp--preset--color--base)' });
for (const gradient of ['linear-gradient(90deg, red 0%, green 50%, blue 100%)', 'radial-gradient(red, blue)', 'url(image.jpg)']) {
  assert.equal(v.parseGradient(gradient), null, 'A two-stop builder must not misrepresent custom gradients');
}
assert.equal(v.paletteGradients([{ name: 'A', value: '#fff' }, { name: 'B', value: '#000' }])[0].value, 'linear-gradient(135deg, #fff 0%, #000 100%)');
console.log('PASS: shared popup values — units, steppers, shorthand, elliptical corners and gradients');
