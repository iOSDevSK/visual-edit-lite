// Form styling CSS must be byte-identical in the editor preview (workspace-model.js)
// and on the public site (Clara_VE_Block_Extras::form_css). Usage: node tests/form-css-congruence.mjs
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const root = new URL('..', import.meta.url).pathname;
const sandbox = { globalThis: {} };
sandbox.window = sandbox.globalThis;
vm.runInNewContext(readFileSync(root + 'assets/workspace-model.js', 'utf8'), sandbox);
const model = sandbox.globalThis.ClaraVEModel || sandbox.window.ClaraVEModel;
assert.ok(model && model.formCss, 'workspace-model.js exposes formCss');

const fixtures = [
  {},
  { label: { color: 'var:preset|color|ink', 'font-size': '12px', 'letter-spacing': '0.2em', 'text-transform': 'uppercase', 'font-weight': '500', 'font-family': 'var:preset|font-family|sans' } },
  { field: { color: '#2b2522', 'background-color': 'transparent', border: 'underline', 'border-width': '2px', 'border-color': '#cfc6bd', 'border-radius': '0' } },
  { field: { border: 'box', 'border-color': 'rgb(1, 2, 3)', 'border-radius': '8px', 'font-family': '"Lora", Georgia, serif' }, focus: { 'border-color': '#8a6f5a' } },
  { field: { border: 'none', 'border-color': '#000', 'border-width': '3px' }, placeholder: { color: '#a89d94' } },
  { field: { 'border-width': '1px' } },
  { button: { color: '#fff', 'background-color': 'var:preset|color|accent', 'border-radius': '0px', 'font-size': 'var:preset|font-size|small', 'letter-spacing': '-1px', 'text-transform': 'none' }, buttonHover: { 'background-color': '#3f3a37', color: 'currentColor' } },
  // Everything below must be refused by both runtimes.
  { label: { color: 'red;}body{display:none', 'font-size': '1px}', 'font-weight': '450', position: 'fixed' }, field: { 'background-color': 'url(x)', border: 'double' }, script: { color: '#fff' } },
  { button: { color: '<style>', 'font-family': 'x;y', 'border-radius': 'calc(1px)' }, focus: 'bad', placeholder: [] },
];

const php = `<?php
define( 'ABSPATH', '/' );
function add_filter() {} function add_action() {} function esc_attr( $v ) { return $v; }
require ${JSON.stringify(root + 'includes/class-block-extras.php')};
$fixtures = json_decode( stream_get_contents( STDIN ), true );
echo json_encode( array_map( static function ( $f ) { return Clara_VE_Block_Extras::form_css( '.cve-r-x', $f ); }, $fixtures ) );`;
const phpOut = JSON.parse(execFileSync('php', ['-r', php.replace(/^<\?php/, '')], { input: JSON.stringify(fixtures) }).toString());

fixtures.forEach((fixture, i) => {
  assert.equal(model.formCss('.cve-r-x', fixture), phpOut[i], `fixture ${i} differs between JS and PHP`);
});
assert.equal(phpOut[0], '', 'no values, no CSS');
assert.match(phpOut[1], /^\.cve-r-x :is\(label, legend\)\{color:var\(--wp--preset--color--ink\) !important;font-family:var\(--wp--preset--font-family--sans\) !important;/);
assert.match(phpOut[2], /border-style:solid !important;border-width:0 0 2px 0 !important;border-color:#cfc6bd !important;/);
assert.match(phpOut[3], /:focus\{border-color:#8a6f5a !important;\}/);
assert.ok(phpOut[4].includes('border-width:0 !important;') && ! phpOut[4].includes('#000'), 'no border drops colour and width');
assert.ok(phpOut[5].includes('{border-width:1px !important;}'), 'a width alone keeps the theme border style');
assert.ok(phpOut[6].includes(':hover{color:currentColor !important;background-color:#3f3a37 !important;}'));
assert.equal(phpOut[7], '', 'injection and unknown targets/properties are refused');
assert.equal(phpOut[8], '', 'malformed values are refused');
phpOut.join('').split('}').filter(Boolean).forEach(rule => {
  assert.match(rule, /^\.cve-r-x [^{}<>;]+\{([a-z-]+:[^{}<>;]+ !important;)+$/, 'every rule is selector{property:value !important;…}');
});
console.log('PASS: form CSS — editor and public site generate identical, sanitised rules');
