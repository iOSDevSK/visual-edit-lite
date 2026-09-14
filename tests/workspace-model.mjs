import assert from 'node:assert/strict';
import '../assets/workspace-model.js';

const m = globalThis.ClaraVEModel;
const original = { content: 'A <strong>formatted</strong> sentence', style: { typography: { fontWeight: '400' }, spacing: { padding: { top: '12px' } } }, fontSize: 'large', metadata: { bindings: { content: { source: 'test/source' } } } };
const edited = m.put(original, 'style.typography.fontWeight', '700');
assert.equal(original.style.typography.fontWeight, '400', 'editing does not mutate the baseline');
assert.equal(edited.content, original.content, 'styling preserves rich HTML');
assert.deepEqual(edited.metadata, original.metadata, 'styling preserves bindings');

const edits = { 'style.typography.fontWeight': { before: '400', after: '700' } };
const concurrent = m.put(edited, 'style.spacing.padding.top', '24px');
assert.equal(m.revert(concurrent, edits).style.spacing.padding.top, '24px', 'cancel preserves unrelated newer edits');
assert.equal(m.revert(concurrent, edits).style.typography.fontWeight, '400');
const nativeEdit = m.put(concurrent, 'style.typography.fontWeight', '900');
assert.equal(m.revert(nativeEdit, edits).style.typography.fontWeight, '900', 'cancel never overwrites a newer native edit of the same field');

const cleared = m.put({ style: { color: { text: '#fff' } }, textColor: 'brand' }, 'style.color.text', '');
assert.deepEqual(cleared, { textColor: 'brand' }, 'reset prunes empty style objects');
const delta = m.patch({ style: { color: { text: '#fff' } }, content: 'text' }, { content: 'text' });
assert.ok(Object.hasOwn(delta, 'style'));
assert.equal(delta.style, undefined, 'removed attributes are explicitly reset for Gutenberg');
assert.ok(!Object.hasOwn(delta, 'content'));

const duplicate = m.copy({ claraVe: { responsive: { mobile: { 'typography.fontSize': '18px' } } } });
const changedCopy = m.put(duplicate, 'claraVe.responsive.mobile', { 'typography.fontSize': '20px' });
assert.equal(duplicate.claraVe.responsive.mobile['typography.fontSize'], '18px');
assert.equal(changedCopy.claraVe.responsive.mobile['typography.fontSize'], '20px');

const css = m.responsiveCss('.block', { tablet: { 'typography.fontSize': 'var:preset|font-size|large' }, mobile: { display: 'none' } });
assert.ok(css.indexOf('781px') < css.indexOf('600px'), 'mobile overrides tablet in source order');
assert.match(css, /var\(--wp--preset--font-size--large\)/);
assert.match(css, /display:none !important/);
for (const payload of ['</style><script>alert(1)</script>', 'url(https://example.com)', '1px;}body{display:none', 'expression(alert(1))']) {
  assert.equal(m.responsiveCss('.block', { mobile: { 'typography.fontSize': payload } }), '', 'reject CSS injection');
}
assert.equal(m.responsiveCss('.block', { mobile: { unknown: 'red' } }), '');
const fontSettings = { __experimentalFeatures: { color: { custom: false }, typography: { fontFamilies: { theme: [{ slug: 'old' }], custom: [{ slug: 'unsaved-native-font', fontFace: [{ src: 'local.woff2' }] }] } } } };
const refreshed = m.fontSettings(fontSettings, { theme: [{ slug: 'new', fontFamily: '"New", serif' }] });
assert.equal(refreshed.__experimentalFeatures.typography.fontFamilies.theme[0].slug, 'new');
assert.deepEqual(refreshed.__experimentalFeatures.typography.fontFamilies.custom, fontSettings.__experimentalFeatures.typography.fontFamilies.custom, 'Refreshing Google Fonts preserves unsaved font library edits');
assert.equal(refreshed.__experimentalFeatures.color.custom, false);
assert.equal(fontSettings.__experimentalFeatures.typography.fontFamilies.theme[0].slug, 'old', 'The native settings snapshot is never mutated');
const cover = { name: 'core/cover', attributes: { url: { role: 'content' }, id: {}, alt: {}, backgroundType: {}, style: {} } };
assert.equal(m.canEditAttribute(cover, {}, 'contentOnly', 'url'), true);
for (const mode of ['contentOnly', 'disabled', undefined, 'unknown']) {
  assert.equal(m.canEditAttribute(cover, {}, mode, 'style.typography.fontSize'), false, 'Restricted modes cannot write design');
}
assert.equal(m.canEditAttribute(cover, {}, 'disabled', 'url'), false);
for (const name of ['id', 'alt', 'backgroundType']) {
  assert.equal(m.canEditAttribute(cover, {}, 'contentOnly', name), false, 'Unroled fields are not generally editable');
  assert.equal(m.canEditAttribute(cover, {}, 'contentOnly', name, 'media'), true, 'Media transaction keeps Cover coupled fields consistent');
}
assert.equal(m.canEditAttribute(cover, {}, 'contentOnly', 'dimRatio', 'media'), false);
assert.equal(m.canEditAttribute(cover, {}, 'contentOnly', 'claraVe.ornaments', 'media'), false);
assert.equal(m.canEditAttribute(cover, { useFeaturedImage: true }, 'contentOnly', 'id', 'media'), false);
const boundMedia = { metadata: { bindings: { url: { source: 'test/image' } } } };
assert.equal(m.canEditAttribute(cover, boundMedia, 'contentOnly', 'id', 'media'), false);
assert.equal(m.canEditAttribute(cover, boundMedia, 'default', 'url'), false);
const defaultBound = { metadata: { bindings: { __default: { source: 'core/pattern-overrides' } } } };
assert.equal(m.canEditAttribute(cover, defaultBound, 'contentOnly', 'url'), false);
assert.equal(m.canEditAttribute(cover, defaultBound, 'default', 'style.color.text'), true, 'Content binding does not lock unrelated design');
assert.equal(m.canEditAttribute({ name: 'core/paragraph', attributes: { content: { __experimentalRole: 'content' } } }, {}, 'contentOnly', 'content'), true, 'Older WP role declarations work');
assert.equal(m.canEditAttribute({ name: 'core/cover', attributes: { url: {} } }, {}, 'contentOnly', 'url'), false, 'Do not invent content capabilities on an older schema');
console.log('PASS: workspace model — cancel, concurrent edits, presets, duplication, permissions and CSS boundaries');
