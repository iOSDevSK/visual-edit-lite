/* Converting existing form markup into form blocks. Usage: node tests/form-convert.cjs /path/to/node_modules */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const modules = process.argv[2];
if (!modules) throw new Error('Usage: node tests/form-convert.cjs /path/to/node_modules');
const { JSDOM } = createRequire(path.resolve(modules, '../package.json'))('jsdom');
const dom = new JSDOM('<!doctype html><body></body>', { url: 'http://example.test/enquire/', runScripts: 'outside-only' });
const noop = () => ({});
noop.save = () => ({});
dom.window.wp = {
  blocks: { registerBlockType() {} },
  element: { createElement() {}, Fragment: {}, useEffect() {} },
  blockEditor: { useBlockProps: noop, useInnerBlocksProps: noop, InspectorControls: {} },
  components: {}, i18n: { __: s => s },
};
dom.window.eval(fs.readFileSync(path.join(__dirname, '../assets/form-blocks.js'), 'utf8'));
const convert = dom.window.ClaraVEFormBlocks.fromHtml;
// The converter runs in the page's realm; compare plain data.
const fromHtml = (...args) => JSON.parse(JSON.stringify(convert(...args)));
const create = (name, attributes, innerBlocks = []) => ({ name, attributes, innerBlocks });
const forms = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/theme-forms.json'), 'utf8'));
const names = block => [block.name, ...(block.innerBlocks || []).flatMap(names)];

// Contact: wrapper, redirect, two-column rows, a hint, two lists, a text area, send row.
const contact = fromHtml(forms.contact, create, { formId: 'contact' });
assert.equal(contact.name, 'clara-ve/form');
assert.equal(contact.attributes.wrapperClass, 'ar-form');
assert.equal(contact.attributes.formClass, 'form');
assert.equal(contact.attributes.redirect, '/form-submitted/', 'same-site redirect kept as a path');
assert.match(contact.attributes.message, /^Thank you — your enquiry has been sent/, 'the theme sentence becomes the form message');
assert.deepEqual(contact.innerBlocks.map(b => b.name), ['clara-ve/form-group', 'clara-ve/form-group', 'clara-ve/form-group', 'clara-ve/textarea', 'clara-ve/form-group']);
assert.deepEqual(contact.innerBlocks.slice(0, 3).map(b => b.attributes.groupClass), ['grid-2', 'grid-2', 'grid-2']);
const [name, email] = contact.innerBlocks[0].innerBlocks;
assert.deepEqual([name.name, name.attributes.label, name.attributes.name, name.attributes.inputId, name.attributes.placeholder, name.attributes.required, name.attributes.wrapperClass, name.attributes.inline], ['clara-ve/field', 'Your names', 'names', 'c-name', 'Fern & Callum', true, 'field', false]);
assert.equal(email.attributes.type, 'email');
const date = contact.innerBlocks[1].innerBlocks[0];
assert.deepEqual([date.attributes.label, date.attributes.hint, date.attributes.hintClass, date.attributes.required], ['Wedding date', '(or roughly when)', 'lbl-plain', false]);
const [planning, found] = contact.innerBlocks[2].innerBlocks;
assert.equal(planning.name, 'clara-ve/select');
assert.equal(planning.attributes.options.length, 5);
assert.equal(found.attributes.options.length, 6);
assert.equal(found.attributes.options[0], 'Instagram');
assert.equal(contact.innerBlocks[3].attributes.name, 'message');
const foot = contact.innerBlocks[4];
assert.equal(foot.attributes.groupClass, 'form-foot');
assert.deepEqual(foot.innerBlocks.map(b => b.name), ['clara-ve/submit', 'core/paragraph']);
assert.deepEqual([foot.innerBlocks[0].attributes.text, foot.innerBlocks[0].attributes.buttonClass], ['Send my enquiry', 'btn']);
assert.deepEqual([foot.innerBlocks[1].attributes.content, foot.innerBlocks[1].attributes.className], ['A reply within two days, always from me.', 'note']);

// Signup: label + input + button side by side, no wrappers; the hidden label keeps its class.
const signup = fromHtml(forms.signup, create);
assert.equal(signup.attributes.formClass, 'signup');
assert.equal(signup.attributes.redirect, '');
assert.deepEqual(signup.innerBlocks.map(b => b.name), ['clara-ve/field', 'clara-ve/submit']);
assert.deepEqual([signup.innerBlocks[0].attributes.inline, signup.innerBlocks[0].attributes.wrapperClass, signup.innerBlocks[0].attributes.labelClass, signup.innerBlocks[0].attributes.type], [true, '', 'skip', 'email']);
assert.equal(signup.attributes.message, 'Thank you — your guide is on its way.');

const soon = fromHtml(forms.soon, create);
assert.equal(soon.innerBlocks[1].attributes.text, 'Keep me posted');

const guide = fromHtml(forms.guide, create);
assert.deepEqual(names(guide), ['clara-ve/form', 'clara-ve/field', 'clara-ve/form-group', 'clara-ve/submit']);
assert.equal(guide.innerBlocks[1].attributes.groupClass, 'form-submit-wide');

// Generic markup: a paragraph-wrapped form, a bare submit input, a checkbox, radios kept as HTML, hidden fields dropped.
const generic = fromHtml('<form action="/x"><input type="hidden" name="t" value="1"><p><label for="g">Name</label><br><input id="g" name="g"></p><p><label><input type="checkbox" name="ok" required> I agree</label></p><div><label><input type="radio" name="r"> A</label><label><input type="radio" name="r"> B</label></div><p><input type="submit" value="Go"></p></form>', create);
assert.deepEqual(generic.innerBlocks.map(b => b.name), ['clara-ve/field', 'clara-ve/checkbox', 'core/html', 'clara-ve/form-group']);
assert.deepEqual([generic.innerBlocks[1].attributes.label, generic.innerBlocks[1].attributes.required], ['I agree', true]);
assert.equal(generic.innerBlocks[3].innerBlocks[0].attributes.text, 'Go');
assert.equal(fromHtml('<p>No form here</p>', create), null);
assert.equal(fromHtml('<div data-redirect="https://elsewhere.test/x"><form><button>Go</button></form></div>', create).attributes.redirect, 'https://elsewhere.test/x', 'a foreign redirect stays absolute; the server validates it');
// Two controls with one name lose a value on send: a colliding key is numbered from its stem.
const uniqueName = dom.window.ClaraVEFormBlocks.uniqueName;
assert.equal(uniqueName('email', ['names', 'message']), 'email');
assert.equal(uniqueName('email', ['email']), 'email-2');
assert.equal(uniqueName('email-2', ['email', 'email-2']), 'email-3');
assert.equal(uniqueName('email', ['email', 'email-2', 'email-3']), 'email-4');

console.log('PASS: form conversion — theme shortcode forms and generic markup become form blocks');
