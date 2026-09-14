/* Uses actual WordPress core-data/blocks/Undo plus jsdom, without any server. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { createRequire } = require('node:module');
const [modules, wordpress] = process.argv.slice(2);
if (!modules || !wordpress) throw new Error('Usage: node tests/workspace-history.cjs /path/to/node_modules /path/to/wordpress');
const dependency = createRequire(path.resolve(modules, '../package.json'));
const { JSDOM } = dependency('jsdom');
const dom = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>', { url: 'http://history.test/', runScripts: 'outside-only', pretendToBeVisual: true });
const w = dom.window;
w.MessageChannel = class {
  constructor() { this.port1 = {}; this.port2 = { postMessage: () => setTimeout(() => this.port1.onmessage(), 0) }; }
};
w.IS_REACT_ACT_ENVIRONMENT = true;
w.matchMedia = () => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} });
const packages = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require $argv[1]);', path.join(wordpress, 'wp-includes/assets/script-loader-packages.php')], { encoding: 'utf8' }));
const loaded = new Set();
function load(name) {
  if (loaded.has(name)) return;
  loaded.add(name);
  if (name === 'react-jsx-runtime' || name === 'react-dom') load('react');
  const local = name.startsWith('wp-') ? name.slice(3) : name;
  const metadata = packages[local + '.js'];
  if (metadata) metadata.dependencies.forEach(load);
  const filename = path.join(wordpress, 'wp-includes/js/dist', metadata ? '' : 'vendor', (name === 'wp-polyfill' ? name : local) + '.js');
  w.eval(fs.readFileSync(filename, 'utf8'));
}
load('wp-core-data');
const wp = w.wp;
wp.blocks.registerBlockType('core/paragraph', { apiVersion: 3, title: 'Paragraph', category: 'text', attributes: { content: { type: 'string', source: 'html', selector: 'p' } }, save: ({ attributes }) => wp.element.createElement('p', { dangerouslySetInnerHTML: { __html: attributes.content } }) });
wp.blocks.setDefaultBlockName('core/paragraph');
const registry = wp.data;
const core = registry.select('core');
const actions = registry.dispatch('core');
const types = ['page', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block', 'wp_global_styles'];
actions.addEntities(types.map(name => ({ kind: 'postType', name, key: 'id', baseURL: '/test/' + name, rawAttributes: ['content', 'title'], mergedEdits: { meta: true }, transientEdits: { blocks: true, selection: true } })));
function seed(type, id, extra = {}) {
  const target = type === 'wp_global_styles' ? ['root', 'globalStyles', id] : ['postType', type, id];
  actions.receiveEntityRecords(...target.slice(0, 2), { id, content: { raw: '<!-- wp:paragraph --><p>Live</p><!-- /wp:paragraph -->' }, meta: { _clara_ve_responsive: '[]', seo: 'keep' }, status: 'publish', title: { raw: 'My page' }, ...extra });
  actions.finishResolution('getEntityRecord', target);
}
const entity = { type: 'page', id: 1 };
const original = '<!-- wp:paragraph --><p>Original</p><!-- /wp:paragraph -->';
const snapshot = { entity, edits: { content: original, meta: { _clara_ve_responsive: '{"hero":{}}', seo: 'evil' }, status: 'private', title: 'evil' } };
const requests = [];
let respond = async () => { throw new Error('Unexpected REST call'); };
wp.apiFetch.setFetchHandler(args => { requests.push(args); return respond(args); });
w.claraVeGutenberg = { stylesheet: 'sailing', responsiveMeta: '_clara_ve_responsive' };
w.eval(fs.readFileSync(path.join(__dirname, '../assets/workspace-history.js'), 'utf8'));

async function run() {
  seed('page', 1); seed('page', 2);
  actions.editEntityRecord('postType', 'page', 1, { blocks: wp.blocks.parse('<!-- wp:paragraph --><p>Unsaved</p><!-- /wp:paragraph -->'), content: ({ blocks }) => wp.blocks.serialize(blocks), meta: { seo: 'unsaved SEO' } });
  actions.editEntityRecord('postType', 'page', 2, { content: 'Other unsaved work' });
  const before = core.getEditedEntityRecord('postType', 'page', 1);
  const other = core.getEditedEntityRecord('postType', 'page', 2);
  await w.ClaraVEHistory.stage(registry, entity, snapshot, before);
  let record = core.getEditedEntityRecord('postType', 'page', 1);
  assert.equal(record.content, original);
  assert.match(wp.blocks.serialize(record.blocks), /<p>Original<\/p>/, 'Native transient blocks are restored too');
  assert.equal(record.meta.seo, 'unsaved SEO');
  assert.equal(record.status, 'publish');
  assert.equal(record.title, 'My page');
  assert.equal(core.getEditedEntityRecord('postType', 'page', 2), other, 'Unrelated dirty entity untouched');
  assert.equal(requests.length, 0, 'Restore never directly publishes');
  actions.undo();
  record = core.getEditedEntityRecord('postType', 'page', 1);
  assert.equal(record.blocks, before.blocks, 'One actual core-data Undo restores previous blocks');
  assert.equal(record.content, before.content, 'Undo restores native content function');
  assert.equal(record.meta._clara_ve_responsive, '[]');
  assert.equal(core.getEditedEntityRecord('postType', 'page', 2), other);
  actions.redo();
  assert.equal(core.getEditedEntityRecord('postType', 'page', 1).content, original, 'Actual Redo restores the version');
  await assert.rejects(w.ClaraVEHistory.stage(registry, entity, snapshot, before), /changed while/);
  await assert.rejects(w.ClaraVEHistory.stage(registry, entity, { ...snapshot, entity: { type: 'page', id: 2 } }), /different document/);
  for (const type of ['wp_template', 'wp_template_part', 'wp_navigation', 'wp_block']) {
    const id = type.startsWith('wp_template') ? 'sailing//header' : 25;
    seed(type, id);
    await w.ClaraVEHistory.stage(registry, { type, id }, { entity: { type, id }, edits: { content: original } });
    assert.equal(core.getEditedEntityRecord('postType', type, id).content, original);
    actions.undo();
    assert.match(core.getEditedEntityRecord('postType', type, id).content, /Live/);
  }
  seed('wp_global_styles', 50, { styles: { color: { text: '#123' } }, settings: { typography: { customFontSize: true } } });
  const globalEntity = { type: 'wp_global_styles', id: 50 };
  await w.ClaraVEHistory.stage(registry, globalEntity, { entity: globalEntity, edits: { styles: {}, settings: {}, status: 'draft' } });
  assert.equal(Object.keys(core.getEditedEntityRecord('root', 'globalStyles', 50).styles).length, 0);
  actions.undo();
  assert.equal(core.getEditedEntityRecord('root', 'globalStyles', 50).styles.color.text, '#123');
  await assert.rejects(w.ClaraVEHistory.stage(registry, globalEntity, { entity: globalEntity, edits: { styles: [], settings: {} } }), /Invalid styles/);

  // Mount the real panel against the same real core-data registry. Stub only chrome.
  wp.data.registerStore('core/editor', { reducer: (state = {}) => state, selectors: { getCurrentTemplateId: () => 'sailing//header' } });
  wp.blocks.registerBlockType('core/navigation', { apiVersion: 3, title: 'Navigation', category: 'text', attributes: { ref: { type: 'number' } }, save: () => null });
  wp.blocks.registerBlockType('core/template-part', { apiVersion: 3, title: 'Template part', category: 'text', attributes: { slug: { type: 'string' } }, save: () => null });
  wp.data.dispatch('core/block-editor').resetBlocks([wp.blocks.createBlock('core/navigation', { ref: 25 }), wp.blocks.createBlock('core/template-part', { slug: 'header' })]);
  const h = wp.element.createElement;
  wp.components.Modal = ({ children }) => h('section', { role: 'dialog' }, children);
  wp.components.Notice = ({ children }) => h('p', { role: 'status' }, children);
  wp.components.Spinner = () => h('span', null, 'Loading');
  const root = w.ReactDOM.createRoot(w.document.getElementById('root'));
  const act = w.React.act;
  const entries = [{ id: 9, message: 'Before redesign', hash: '1234567', createdAt: '2026-09-13 12:00:00', isHead: true }, { id: 1, message: 'Original', hash: '7654321', createdAt: '2026-09-12 12:00:00', isHead: false }];
  let listFails = true;
  respond = async args => {
    if (args.headers?.['X-HTTP-Method-Override'] === 'PATCH') { entries.find(row => args.path.includes('/' + row.id + '?')).message = args.data.message; return { renamed: true }; }
    if (args.path.includes('/history/1?')) return { ...snapshot, id: 1 };
    if (listFails) { listFails = false; throw new Error('History offline'); }
    return { entity: { ...entity, title: 'My page' }, entries };
  };
  let props = { postType: 'page', postId: 1, title: 'My page', saving: false, onClose() {} };
  const render = () => root.render(h(w.ClaraVEHistory.Panel, props));
  const findButton = text => [...w.document.querySelectorAll('button')].find(button => button.textContent === text);
  const click = async text => { const button = findButton(text); assert.ok(button, 'Button exists: ' + text); await act(async () => button.click()); };
  await act(async () => render());
  assert.match(w.document.body.textContent, /History offline/);
  await click('Retry');
  assert.equal(w.document.querySelectorAll('.cve-w-history-entry').length, 2);
  assert.ok(w.document.querySelector('option[value="wp_template_part:sailing//header"]'));
  assert.ok(w.document.querySelector('option[value="wp_navigation:25"]'));
  const restores = () => [...w.document.querySelectorAll('button')].filter(button => button.textContent === 'Restore');
  await act(async () => restores()[1].click());
  const requestCount = requests.length;
  await click('Cancel');
  assert.equal(requests.length, requestCount, 'Cancel never loads or writes a snapshot');
  await act(async () => restores()[1].click());
  await click('Restore version');
  assert.match(w.document.body.textContent, /Version loaded/);
  assert.equal(requests.filter(args => ['POST', 'PUT', 'PATCH', 'DELETE'].includes(args.method)).length, 0);
  await act(async () => actions.undo());
  assert.doesNotMatch(w.document.body.textContent, /Loaded in editor/, 'Undo clears the loaded badge');
  await click('Before redesign');
  const input = w.document.querySelector('input');
  await act(async () => { Object.getOwnPropertyDescriptor(w.HTMLInputElement.prototype, 'value').set.call(input, 'Before typography'); input.dispatchEvent(new w.Event('input', { bubbles: true })); });
  await click('Save name');
  assert.equal(requests.find(args => args.headers?.['X-HTTP-Method-Override'] === 'PATCH').data.message, 'Before typography');

  // A late request must not overwrite the document after switching selection.
  let release;
  respond = args => args.path.includes('/history/1?') ? new Promise(resolve => { release = resolve; }) : Promise.resolve({ entity: { ...entity, title: 'Page' }, entries });
  await act(async () => restores()[1].click());
  await click('Restore version');
  const atStart = core.getEditedEntityRecord('postType', 'page', 1);
  await act(async () => { props = { ...props, postId: 2 }; render(); });
  await act(async () => release({ ...snapshot, id: 1 }));
  assert.equal(core.getEditedEntityRecord('postType', 'page', 1), atStart, 'Late snapshot after navigation ignored');
  await act(async () => root.unmount());
  dom.window.close();
  console.log('Native history UI and actual WordPress core-data Undo/Redo PASS');
}
run().catch(error => { console.error(error); dom.window.close(); process.exitCode = 1; });
