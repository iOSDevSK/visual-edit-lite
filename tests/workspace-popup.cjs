/* Run with a node_modules directory containing React 18, react-dom and jsdom. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const modules = process.argv[2];
if (!modules) throw new Error('Usage: node tests/workspace-popup.cjs /path/to/node_modules');
const dependency = createRequire(path.resolve(modules, '../package.json'));
const { JSDOM } = dependency('jsdom');
const dom = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>', { url: 'http://workspace.test/', runScripts: 'outside-only' });
Object.assign(global, { window: dom.window, document: dom.window.document, MutationObserver: dom.window.MutationObserver, IS_REACT_ACT_ENVIRONMENT: true });
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
const React = dependency('react');
const ReactDOM = dependency('react-dom');
const { createRoot } = dependency('react-dom/client');
const { Simulate } = dependency('react-dom/test-utils');
const { act } = React;
const root = createRoot(document.getElementById('root'));
let attributes = { content: 'Keep <strong>this</strong> formatting', style: { typography: { fontWeight: '400' } } };
let wrapped;
let Workspace;
let mode = 'default';
let blockName = 'core/paragraph';
let descriptor = { name: 'core/paragraph', title: 'Paragraph', supports: { typography: { fontSize: true, __experimentalFontFamily: true, __experimentalFontWeight: true }, color: { gradients: true }, spacing: { padding: true, margin: true }, __experimentalBorder: { radius: true } }, attributes: { align: { type: 'string' }, content: { type: 'string', role: 'content' } } };
let richTextProps;
let mediaProps;
let mediaOpens = 0;
let settings = { __experimentalFeatures: {} };
function at(value, target) { return target.split('.').reduce((node, key) => node == null ? undefined : node[key], value); }
const selectors = {
  getBlock: id => { assert.equal(id, 'selected-block'); return { name: blockName, clientId: id, attributes }; },
  getBlockEditingMode: () => mode,
  getSettings: () => settings,
  getBlockRootClientId: () => 'nested-template-part',
  canInsertBlockType: () => true,
  canRemoveBlock: () => true,
  getEditedPostAttribute: () => ({}),
};
let inspectorClosed = false;
const editorSettingCalls = [];
const registry = { select: () => selectors, dispatch: name => name === 'core/interface' ? { disableComplementaryArea: () => { inspectorClosed = true; } } : name === 'core/editor' ? { updateEditorSettings: next => { editorSettingCalls.push(next); settings = { ...settings, ...next }; render(); } } : { __experimentalUpdateSettings: next => { settings = { ...settings, ...next }; render(); } } };
let popupFilter = null;
const fragment = ({ children }) => React.createElement(React.Fragment, null, children);
window.wp = {
  element: { ...React, createPortal: ReactDOM.createPortal },
  i18n: { __: text => text },
  richText: dependency('@wordpress/rich-text'),
  components: { ToolbarGroup: fragment, ToolbarButton: fragment, Modal: fragment, Notice: fragment, Spinner: () => React.createElement('span', null, 'Loading') },
  blockEditor: { RichText: props => { richTextProps = props; return React.createElement('div', { className: props.className }, props.value); }, MediaUploadCheck: fragment, MediaUpload: props => { mediaProps = props; return props.render({ open: () => { mediaOpens++; } }); }, BlockControls: fragment, BlockInspector: () => React.createElement('div', { 'data-native-inspector': true }, 'Plugin controls') },
  data: { useRegistry: () => registry, useSelect: fn => fn(() => selectors), select: name => { if (name === 'core/rich-text') return dependency('@wordpress/data').select(name); throw new Error('Global registry used for nested block'); }, dispatch: () => { throw new Error('Global dispatch used for nested block'); } },
  blocks: { getBlockType: () => descriptor, hasBlockSupport: (_, support, fallback) => at(descriptor.supports, support) ?? fallback },
  compose: { createHigherOrderComponent: fn => fn },
  hooks: { addFilter: (name, _, fn) => { if (name === 'editor.BlockEdit') wrapped = fn; }, applyFilters: (name, value, context) => name === 'clara_ve.popup.groups' && popupFilter ? popupFilter(value, context) : value },
  plugins: { registerPlugin: (_, plugin) => { Workspace = plugin.render; } },
};
window.claraVeGutenberg = { workspace: true, presets: { fontFamily: [{ slug: 'theme-font', name: 'Theme font', value: 'Theme, serif' }] }, googleFontsCss: 'https://fonts.googleapis.com/css2?family=Previous', googleFonts: [{ family: 'Previous' }], canManageFonts: true, googleFontsMax: 1 };
for (const file of ['block-formats.js', 'popup-values.js', 'workspace-model.js', 'workspace.js']) window.eval(fs.readFileSync(path.join(__dirname, '../assets', file), 'utf8'));
const Block = wrapped(() => React.createElement('p', null, 'Native block'));
function render() {
  root.render(React.createElement(Block, { clientId: 'selected-block', name: blockName, attributes, isSelected: true, setAttributes: patch => { attributes = { ...attributes, ...patch }; render(); } }));
}
act(render);
assert.ok(document.querySelector('.cve-w-popup'), 'Selecting a nested block opens the popup');
const tab = label => { const node = [...document.querySelectorAll('.cve-w-popup [role="tab"]')].find(button => button.textContent === label); assert.ok(node, 'Tab ' + label); node.click(); };
assert.equal([...document.querySelectorAll('.cve-w-popup [role="tab"]')].map(node => node.textContent).join('|'), 'Content|Style|Advanced', 'A text block offers Content, Style and Advanced tabs only');
assert.ok(document.querySelector('.cve-w-popup .cve-w-richtext'), 'Content opens first for a text block');
act(() => tab('Style'));
assert.match(document.querySelector('.cve-w-popup').textContent, /Add Google fonts/);
function field(label) { return [...document.querySelectorAll('.cve-w-field')].find(row => row.querySelector('span').textContent === label)?.querySelector('input, select'); }
assert.equal(field('Weight').value, '400');
act(() => Simulate.change(field('Weight'), { target: { value: '700' } }));
assert.equal(attributes.style.typography.fontWeight, '700', 'Popup edits the scoped block');
assert.equal(attributes.content, 'Keep <strong>this</strong> formatting');
attributes = { ...attributes, style: { ...attributes.style, spacing: { padding: { top: '32px' } } } };
act(render);
act(() => [...document.querySelectorAll('.cve-w-popup button')].find(button => button.textContent === 'Cancel').click());
assert.equal(attributes.style.typography.fontWeight, '400', 'Cancel restores own field');
assert.equal(attributes.style.spacing.padding.top, '32px', 'Cancel preserves concurrent native edit');
assert.equal(document.querySelector('.cve-w-popup'), null);
act(() => window.dispatchEvent(new window.CustomEvent('clara-ve-open-popup')));
assert.ok(document.querySelector('.cve-w-popup'), 'Popup can reopen without changing selection');
act(() => Simulate.change(field('Weight'), { target: { value: '700' } }));
act(() => [...document.querySelectorAll('.cve-w-popup button')].find(button => button.textContent === 'Reset styles').click());
assert.equal(attributes.style, undefined);
act(() => [...document.querySelectorAll('.cve-w-popup button')].find(button => button.textContent === 'Cancel').click());
assert.equal(attributes.style.typography.fontWeight, '400', 'Cancel after reset returns the initial value, not the intermediate edit');
act(() => window.dispatchEvent(new window.CustomEvent('clara-ve-open-popup')));
attributes = { ...attributes, style: { spacing: { padding: '2rem' }, border: { radius: '50%' } } };
act(render);
act(() => document.querySelector('[aria-label="Increase Padding top"]').click());
assert.equal(attributes.style.spacing.padding.top, '6rem');
assert.equal(attributes.style.spacing.padding.bottom, '2rem', 'Editing shorthand preserves untouched sides');
act(() => document.querySelector('[aria-label="Increase Radius topLeft"]').click());
assert.equal(attributes.style.border.radius.topLeft, '52%');
assert.equal(attributes.style.border.radius.bottomRight, '50%', 'Other corners keep their unit and value');
act(() => [...document.querySelectorAll('.cve-w-popup button')].find(button => button.textContent === 'Cancel').click());
assert.equal(attributes.style.spacing.padding, '2rem', 'Cancel restores the original shorthand');
assert.equal(attributes.style.border.radius, '50%');
act(() => window.dispatchEvent(new window.CustomEvent('clara-ve-open-popup')));
attributes = { ...attributes, style: { color: { gradient: 'linear-gradient(to right, rgb(1, 2, 3) 0%, #ffffff 100%)' } } };
act(render);
assert.equal(field('Direction').value, 'to right', 'Gradient builder reads the actual existing gradient');
act(() => Simulate.change(field('Direction'), { target: { value: 'to bottom' } }));
assert.equal(attributes.style.color.gradient, 'linear-gradient(to bottom, rgb(1, 2, 3) 0%, #ffffff 100%)', 'Changing direction preserves both original colors');
attributes = { ...attributes, style: { color: { gradient: 'linear-gradient(90deg, red 0%, green 50%, blue 100%)' } } };
act(render);
assert.equal(field('Direction'), undefined, 'Complex gradients are not silently reduced to two stops');
settings = { __experimentalFeatures: { color: { customGradient: false } } };
act(render);
assert.equal(document.querySelector('.cve-w-gradient-stop'), null, 'The builder respects disabled custom gradients');
attributes = { ...attributes, claraVe: { ornaments: { before: { content: '“', color: '#fff', 'font-size': '2em' } } } };
act(render);
act(() => tab('Content'));
act(() => [...document.querySelectorAll('.cve-w-popup button')].find(button => button.textContent === 'Convert to editable text').click());
assert.match(attributes.content, /^<span class="cve-ornament" style="color:#fff;font-size:2em;">“<\/span>Keep <strong>/);
assert.equal(attributes.claraVe.ornaments.before.hidden, true, 'Promotion hides the pseudo-element, preventing double glyphs');
const rich = window.wp.richText.create({ html: attributes.content });
assert.match(window.wp.richText.toHTMLString({ value: rich }), /class="cve-ornament"/, 'The real WordPress RichText parser retains the promoted format');
assert.match(window.wp.richText.toHTMLString({ value: rich }), /font-size:2em/, 'The promoted style survives native RichText serialization');
act(() => [...document.querySelectorAll('.cve-w-popup button')].find(button => button.textContent === 'Cancel').click());
assert.equal(attributes.content, 'Keep <strong>this</strong> formatting', 'Cancel restores both formatted text and ornament');
assert.equal(attributes.claraVe.ornaments.before.hidden, undefined);
act(() => window.dispatchEvent(new window.CustomEvent('clara-ve-open-popup')));
mode = 'contentOnly';
act(render);
assert.equal(document.querySelector('.cve-w-style-controls'), null, 'Content-only mode hides unavailable design controls');
assert.equal(document.querySelector('.cve-w-controls').hasAttribute('disabled'), false, 'The content panel is not blanket-disabled');
assert.match(document.querySelector('.cve-w-mode-note').textContent, /Content-only/);
const previousText = attributes.content;
act(() => richTextProps.onChange('Content-only <strong>edit</strong>'));
assert.equal(attributes.content, 'Content-only <strong>edit</strong>', 'Content-role text remains editable');
act(() => [...document.querySelectorAll('.cve-w-popup button')].find(button => button.textContent === 'Cancel').click());
assert.equal(attributes.content, previousText, 'Content-only Cancel restores the text');
act(() => window.dispatchEvent(new window.CustomEvent('clara-ve-open-popup')));
act(() => tab('Advanced'));
assert.equal(inspectorClosed, true, 'The sidebar releases the native Slot before mounting it in VE');
assert.ok(document.querySelector('.cve-w-popup [data-native-inspector]'), 'Real native inspector is embedded, not copied');
assert.equal(document.querySelector('.cve-w-controls'), null, 'Visual Edit controls step aside for the native inspector');
act(() => tab('Content'));

// Cover mimics the live WP 7.1 schema: only URL has a content role.
const paragraphDescriptor = descriptor;
const paragraphAttributes = attributes;
const clickPopup = label => [...document.querySelectorAll('.cve-w-popup button')].find(button => button.textContent === label)?.click();
act(() => clickPopup('Cancel'));
blockName = 'core/cover';
descriptor = { ...paragraphDescriptor, name: blockName, title: 'Cover', attributes: { url: { role: 'content' }, id: {}, alt: {}, backgroundType: {} } };
attributes = { url: 'old.jpg', id: 10, alt: 'Old boat', backgroundType: 'image', dimRatio: 30, focalPoint: { x: .3, y: .5 }, style: { spacing: { padding: '2rem' } } };
act(render);
act(() => window.dispatchEvent(new window.CustomEvent('clara-ve-open-popup')));
assert.equal(document.querySelector('.cve-w-style-controls'), null);
act(() => clickPopup('Choose or replace media'));
assert.equal(mediaOpens, 1, 'Cover media picker is enabled in content-only mode');
const styleBeforeMedia = attributes.style;
act(() => mediaProps.onSelect({ id: 11, url: 'new.mp4', type: 'video', alt: '' }));
assert.equal(attributes.url, 'new.mp4');
assert.equal(attributes.id, 11);
assert.equal(attributes.backgroundType, 'video', 'Cover media type stays consistent with its new URL');
assert.equal(attributes.dimRatio, 30);
assert.equal(attributes.style, styleBeforeMedia, 'Replacing media never changes design');
act(() => clickPopup('Cancel'));
assert.equal(attributes.url, 'old.jpg');
assert.equal(attributes.id, 10);
assert.equal(attributes.backgroundType, 'image');
act(() => window.dispatchEvent(new window.CustomEvent('clara-ve-open-popup')));
const staleMedia = mediaProps.onSelect;
mode = 'disabled';
// Deliberately do not render first: modal callbacks must re-read permission.
act(() => staleMedia({ id: 12, url: 'forbidden.jpg', type: 'image' }));
assert.equal(attributes.url, 'old.jpg', 'A late picker cannot write after editing is disabled');
act(render);
assert.match(document.querySelector('.cve-w-mode-note').textContent, /disabled by WordPress/);
assert.doesNotMatch(document.querySelector('.cve-w-controls').textContent, /Choose or replace media|TYPOGRAPHY/);
mode = 'contentOnly';
attributes = { ...attributes, metadata: { bindings: { url: { source: 'test/image' } } } };
act(() => staleMedia({ id: 13, url: 'bound-overwrite.jpg', type: 'image' }));
assert.equal(attributes.url, 'old.jpg', 'Late picker cannot overwrite a new binding');
act(render);
assert.doesNotMatch(document.querySelector('.cve-w-controls').textContent, /Choose or replace media/);
attributes = { ...attributes, metadata: undefined, useFeaturedImage: true };
act(render);
assert.doesNotMatch(document.querySelector('.cve-w-controls').textContent, /Choose or replace media/, 'Featured image remains owned by the document');
act(() => clickPopup('Cancel'));
for (const name of ['core/image', 'core/video', 'core/audio']) {
  blockName = name;
  const source = name === 'core/image' ? 'url' : 'src';
  descriptor = { ...paragraphDescriptor, name, title: name, attributes: { [source]: { role: 'content' }, id: { role: 'content' }, alt: { role: 'content' } } };
  attributes = { [source]: 'before-media', id: 20, alt: 'Bound alt', metadata: { bindings: { alt: { source: 'test/alt' } } } };
  act(render);
  act(() => window.dispatchEvent(new window.CustomEvent('clara-ve-open-popup')));
  act(() => mediaProps.onSelect({ id: 21, url: 'after-media', type: name.slice(5), alt: 'Replacement alt' }));
  assert.equal(attributes[source], 'after-media', name + ' content-only media works');
  assert.equal(attributes.id, 21);
  assert.equal(attributes.alt, 'Bound alt', 'Replacing media never overwrites bound alt text');
  act(() => clickPopup('Cancel'));
  assert.equal(attributes[source], 'before-media');
  assert.equal(attributes.id, 20);
}
blockName = 'core/paragraph'; descriptor = paragraphDescriptor; attributes = paragraphAttributes;
mode = 'default';
act(render);
act(() => window.dispatchEvent(new window.CustomEvent('clara-ve-open-popup')));
act(() => tab('Style'));
assert.ok(field('Weight'), 'Full typography returns in normal design mode');
const lateText = richTextProps.onChange;
mode = 'disabled';
act(() => lateText('Should not be written'));
assert.equal(attributes.content, paragraphAttributes.content, 'RichText also rechecks late mode changes');
mode = 'default';

// Unlocking pattern design uses WordPress's own editor setting.
mode = 'contentOnly';
act(render);
const unlock = [...document.querySelectorAll('.cve-w-popup button')].find(button => button.textContent === 'Unlock design');
assert.ok(unlock, 'A content-only block offers to unlock the design');
act(() => unlock.click());
assert.equal(JSON.stringify(editorSettingCalls.pop()), JSON.stringify({ disableContentOnlyForUnsyncedPatterns: true, disableContentOnlyForTemplateParts: true }), 'Unlock is the native "Enable editing all patterns" setting');
act(render);
assert.equal([...document.querySelectorAll('.cve-w-popup button')].some(button => button.textContent === 'Unlock design'), false, 'No second unlock offer once unlocked');
settings = { __experimentalFeatures: {} };
mode = 'default';
act(render);

// Smaller screens write the same fields into claraVe.responsive.
act(() => tab('Style'));
act(() => [...document.querySelectorAll('.cve-w-screen')].find(button => button.textContent === 'Mobile').click());
const mobileSize = [...document.querySelectorAll('.cve-w-number')].find(row => row.querySelector('span').textContent === 'Size')?.querySelector('input');
assert.ok(mobileSize, 'Mobile shows a font size field');
act(() => Simulate.change(mobileSize, { target: { value: '18px' } }));
assert.equal(attributes.claraVe.responsive.mobile['typography.fontSize'], '18px');
assert.equal(attributes.style?.typography?.fontSize, undefined, 'The desktop value is untouched');
assert.ok([...document.querySelectorAll('.cve-w-number span')].some(span => span.textContent === '● Size'), 'An override is marked');
act(() => [...document.querySelectorAll('.cve-w-screen')].find(button => button.textContent === 'Desktop').click());
act(() => clickPopup('Cancel'));
assert.equal(attributes.claraVe?.responsive?.mobile?.['typography.fontSize'], undefined, 'Cancel removes the responsive override');

// Bold applies to the text selected in the canvas, through RichText formats.
const richData = dependency('@wordpress/data');
const richStore = dependency('@wordpress/rich-text');
if (!richData.select('core/rich-text').getFormatType('core/bold')) richStore.registerFormatType('core/bold', { title: 'Bold', tagName: 'strong', className: null, edit: () => null });
attributes = { ...attributes, content: 'Hello world' };
selectors.getSelectionStart = () => ({ clientId: 'selected-block', attributeKey: 'content', offset: 0 });
selectors.getSelectionEnd = () => ({ clientId: 'selected-block', attributeKey: 'content', offset: 5 });
act(() => window.dispatchEvent(new window.CustomEvent('clara-ve-open-popup')));
act(render);
act(() => tab('Content'));
act(() => document.querySelector('.cve-w-format-bold').click());
assert.equal(attributes.content, '<strong>Hello</strong> world', 'Bold wraps only the selected range');
act(() => clickPopup('Cancel'));
assert.equal(attributes.content, 'Hello world');
delete selectors.getSelectionStart; delete selectors.getSelectionEnd;

// Extensions add popup groups through wp.hooks.
popupFilter = (groups, context) => groups.concat([{ key: 'assistant', tab: 'content', title: 'Assistant', render: () => [React.createElement('p', { key: 'a', className: 'assistant-group' }, 'Ask about ' + context.block.name)] }]);
act(() => window.dispatchEvent(new window.CustomEvent('clara-ve-open-popup')));
act(render);
assert.match(document.querySelector('.assistant-group').textContent, /core\/paragraph/, 'A filtered group renders with its context');
popupFilter = null;
act(() => clickPopup('Cancel'));
attributes = paragraphAttributes;

// Placement: beside the block at the click height, never on top of it when there is room.
const place = window.ClaraVEWorkspace.placePopup;
const view = { width: 1440, height: 900, top: 66 };
const size = { width: 340, height: 400 };
assert.equal(JSON.stringify(place({ left: 100, right: 700, top: 300, bottom: 500 }, { x: 400, y: 350 }, size, view)), JSON.stringify({ left: 716, top: 326 }), 'Right of the block, at the click');
assert.equal(JSON.stringify(place({ left: 900, right: 1400, top: 300, bottom: 500 }, { x: 1000, y: 320 }, size, view)), JSON.stringify({ left: 544, top: 296 }), 'Left when the right side is full');
assert.equal(JSON.stringify(place({ left: 0, right: 1440, top: 100, bottom: 300 }, { x: 700, y: 200 }, size, view)), JSON.stringify({ left: 530, top: 316 }), 'Below a full-width block');
assert.equal(JSON.stringify(place({ left: 0, right: 1440, top: 500, bottom: 880 }, { x: 700, y: 600 }, size, view)), JSON.stringify({ left: 530, top: 84 }), 'Above when there is no room below');
const overlap = place({ left: 0, right: 1440, top: 70, bottom: 890 }, { x: 1300, y: 800 }, size, view);
assert.ok(overlap.left + size.width <= 1432 && overlap.top + size.height <= 892, 'The last resort stays fully visible');

async function fontTests() {
  mode = 'default';
  settings = { __experimentalFeatures: { typography: { fontFamilies: { theme: [{ slug: 'previous' }], custom: [{ slug: 'unsaved-library-font' }] } } } };
  const catalog = [{ family: 'New Font', category: 'serif' }, ...Array.from({ length: 61 }, (_, i) => ({ family: `Font ${i}`, category: 'sans-serif' }))];
  let selected = [{ family: 'Previous', category: 'serif' }];
  const toolbarMount = document.createElement('div');
  document.body.appendChild(toolbarMount);
  const toolbar = createRoot(toolbarMount);
  const canvas = document.createElement('iframe');
  canvas.name = 'editor-canvas';
  document.body.appendChild(canvas);
  for (const doc of [document, canvas.contentDocument]) {
    for (const id of ['clara-ve-google-fonts-editor-css', 'cve-workspace-fonts']) {
      const link = doc.createElement('link'); link.id = id; link.rel = 'stylesheet'; link.href = window.claraVeGutenberg.googleFontsCss; doc.head.appendChild(link);
    }
  }
  act(() => toolbar.render(React.createElement(Workspace)));
  const managedLinks = doc => doc.querySelectorAll('#clara-ve-google-fonts-editor-css, #clara-ve-google-fonts-css, #cve-workspace-fonts');
  for (const doc of [document, canvas.contentDocument]) {
    assert.equal(managedLinks(doc).length, 1, 'Do not load the same Google Fonts stylesheet twice');
    assert.doesNotMatch(doc.getElementById('cve-workspace-font-presets').textContent, /theme-font/, 'VE preview must not override the native theme font presets');
  }
  let getFails = true, saveFails = true;
  window.wp.apiFetch = async request => {
    assert.equal(request.path, '/clara-ve/v1/google-fonts');
    if (request.method !== 'POST') {
      if (getFails) { getFails = false; throw new Error('Catalog offline'); }
      return { catalog, selected };
    }
    if (saveFails) { saveFails = false; throw new Error('Save offline'); }
    selected = request.data.families;
    const fonts = selected.map(font => ({ slug: 'new-font', name: font.family, fontFamily: '"New Font", serif', fontFace: [{ src: 'local-font.woff2' }] }));
    return { selected, presets: fonts.map(font => ({ slug: font.slug, name: font.name, value: font.fontFamily })), cssUrl: selected.length ? 'https://fonts.googleapis.com/css2?family=New+Font' : '', fontFamilies: { theme: fonts } };
  };
  function click(text) { const button = [...document.querySelectorAll('button')].find(node => node.textContent === text); assert.ok(button, text); button.click(); }
  act(render);
  act(() => window.dispatchEvent(new window.CustomEvent('clara-ve-open-popup')));
  act(() => tab('Style'));
  const content = attributes.content;
  await act(async () => { click('＋ Add Google fonts'); });
  assert.match(document.body.textContent, /Catalog offline/);
  await act(async () => { click('Retry'); });
  act(() => click('Load more fonts'));
  assert.equal(document.querySelectorAll('.cve-w-font-list > div').length, 62, 'The complete catalog stays browsable');
  act(() => click('Previous ×'));
  act(() => document.querySelector('[aria-label="Add font: New Font"]').click());
  assert.equal(document.querySelector('[aria-label="Add font: Font 0"]').disabled, true, 'Family limit is enforced');
  await act(async () => { click('Save fonts'); });
  assert.match(document.body.textContent, /Save offline/);
  assert.ok(document.querySelector('.cve-w-font-chips'), 'Failed font save keeps the selection and dialog');
  await act(async () => { click('Save fonts'); });
  assert.equal(document.querySelector('.cve-w-font-chips'), null);
  assert.equal(settings.__experimentalFeatures.typography.fontFamilies.theme[0].slug, 'new-font', 'The native picker receives the saved fonts');
  assert.equal(settings.__experimentalFeatures.typography.fontFamilies.theme[0].fontFace[0].src, 'local-font.woff2', 'Native face metadata survives');
  assert.equal(settings.__experimentalFeatures.typography.fontFamilies.custom[0].slug, 'unsaved-library-font', 'Unsaved native font library edits remain');
  assert.equal(attributes.content, content, 'Font library saves do not reset unsaved block content');
  for (const doc of [document, canvas.contentDocument]) {
    assert.equal(managedLinks(doc).length, 1);
    assert.match(managedLinks(doc)[0].href, /New\+Font/, 'The existing native font link refreshes in both documents');
  }
  await act(async () => { click('＋ Add Google fonts'); });
  act(() => click('New Font ×'));
  await act(async () => { click('Save fonts'); });
  assert.equal(settings.__experimentalFeatures.typography.fontFamilies.theme.length, 0, 'Removing the last family refreshes the native picker too');
  for (const doc of [document, canvas.contentDocument]) {
    assert.equal(managedLinks(doc).length, 0, 'Removing the last font also removes the native editor stylesheet');
    assert.equal(doc.getElementById('cve-workspace-font-presets').textContent, '', 'Removed font preview variables do not linger');
  }
  act(() => toolbar.unmount());
  act(() => root.unmount());
  dom.window.close();
  console.log('PASS: popup — scoped registry, content-only text/media, late callbacks, bindings, units, gradients, native inspector, locks and fonts');
}
fontTests().catch(error => { console.error(error); act(() => root.unmount()); dom.window.close(); process.exitCode = 1; });
