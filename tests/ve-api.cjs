/* window.ClaraVE in the Gutenberg workspace. Run: node tests/ve-api.cjs /path/to/node_modules */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const modules = process.argv[2];
if (!modules) throw new Error('Usage: node tests/ve-api.cjs /path/to/node_modules');
const dependency = createRequire(path.resolve(modules, '../package.json'));
const { JSDOM } = dependency('jsdom');
const React = dependency('react');
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'http://api.test/', runScripts: 'outside-only' });
const w = dom.window;
const json = value => JSON.parse(JSON.stringify(value));

// A tiny block tree with the selectors and actions the API uses.
const blocks = {
  main: { name: 'core/group', attributes: { tagName: 'main' }, parent: '', children: ['hero', 'cta'] },
  hero: { name: 'core/group', attributes: { metadata: { name: 'Hero', patternName: 'theme/hero' } }, parent: 'main', children: ['title', 'buttons'] },
  title: { name: 'core/heading', attributes: { content: 'Hello', level: 1 }, parent: 'hero', children: [] },
  buttons: { name: 'core/buttons', attributes: {}, parent: 'hero', children: ['button'] },
  button: { name: 'core/button', attributes: { text: 'Go', url: '/go' }, parent: 'buttons', children: [] },
  cta: { name: 'core/group', attributes: { metadata: { name: 'CTA' } }, parent: 'main', children: [] },
};
const modes = { title: 'default', button: 'default', hero: 'default', buttons: 'default', cta: 'default', main: 'default' };
const log = [];
let selected = null;
const types = {
  'core/heading': { name: 'core/heading', title: 'Heading', attributes: { content: { role: 'content' }, level: {}, style: {}, className: {}, claraVe: {}, metadata: {} } },
  'core/button': { name: 'core/button', title: 'Button', attributes: { text: { role: 'content' }, url: { role: 'content' }, linkTarget: { role: 'content' }, style: {} } },
  'core/group': { name: 'core/group', title: 'Group', attributes: { style: {} } },
  'core/buttons': { name: 'core/buttons', title: 'Buttons', attributes: {} },
};
const blockEditor = {
  getBlock: id => blocks[id] ? { clientId: id, name: blocks[id].name, attributes: blocks[id].attributes } : null,
  getBlockName: id => blocks[id] && blocks[id].name,
  getBlockAttributes: id => blocks[id] && blocks[id].attributes,
  getBlockEditingMode: id => modes[id] || 'default',
  getBlockRootClientId: id => blocks[id] ? blocks[id].parent : null,
  getBlockParents: id => { const out = []; let p = blocks[id].parent; while (p) { out.unshift(p); p = blocks[p].parent; } return out; },
  getBlockOrder: id => (id ? blocks[id].children : ['main']),
  getBlockIndex: id => blocks[blocks[id].parent].children.indexOf(id),
  getSelectedBlockClientId: () => selected,
  getClientIdsWithDescendants: () => Object.keys(blocks),
  getBlocks: () => [],
  canRemoveBlock: id => id !== 'main',
  canMoveBlock: () => true,
  canInsertBlockType: () => true,
  __experimentalGetAllowedPatterns: () => [
    { name: 'theme/cta', title: 'CTA', source: null, blocks: [{ name: 'core/group', attributes: {}, innerBlocks: [] }] },
    { name: 'theme/header', title: 'Header', source: null, categories: ['header'], blocks: [{ name: 'core/group', attributes: {}, innerBlocks: [] }] },
    { name: 'core/query-grid', title: 'Core', source: 'core', blocks: [] },
  ],
};
const actions = {
  updateBlockAttributes: (id, patch) => { log.push(['update', id, json(patch)]); blocks[id].attributes = { ...blocks[id].attributes, ...patch }; },
  removeBlocks: ids => log.push(['remove', ids]),
  duplicateBlocks: ids => log.push(['duplicate', ids]),
  moveBlocksUp: (ids, root) => log.push(['up', ids, root]),
  moveBlocksDown: (ids, root) => log.push(['down', ids, root]),
  selectBlock: id => { selected = id; },
  insertBlocks: (list, index, root) => log.push(['insert', list.map(b => b.attributes.metadata), index, root]),
};
const editor = { getCurrentPostType: () => 'wp_template', getCurrentPostId: () => 'theme//front-page', getEditedPostAttribute: () => 'Front Page' };
w.wp = {
  element: { ...React, createPortal: () => null },
  i18n: { __: text => text },
  components: {},
  blockEditor: {},
  compose: { createHigherOrderComponent: fn => fn },
  hooks: { addFilter() {}, applyFilters: (name, value) => value },
  plugins: { registerPlugin() {} },
  blocks: { getBlockType: name => types[name], hasBlockSupport: () => false, cloneBlock: (block, attrs) => ({ ...block, attributes: { ...block.attributes, ...(attrs || {}) } }), serialize: () => '<!-- wp:group /-->' },
  data: { select: name => (name === 'core/block-editor' ? blockEditor : editor), dispatch: name => (name === 'core/block-editor' ? actions : {}) },
};
w.claraVeGutenberg = { workspace: true, stylesheet: 'theme', template: 'theme' };
for (const file of ['ve-api.js', 'popup-values.js', 'workspace-model.js', 'workspace.js']) w.eval(fs.readFileSync(path.join(__dirname, '../assets', file), 'utf8'));
const ve = w.ClaraVE;
assert.equal(ve.mode, 'block', 'The workspace registers itself');
let readyMode = null; ve.ready(api => { readyMode = api.mode; });
assert.equal(readyMode, 'block', 'ready() runs at once after registration');

(async () => {
  let applied = null; const off = ve.on('apply', detail => { applied = detail; });
  let result = await ve.apply([
    { op: 'set-style', id: 'title', style: { 'typography.fontSize': '48px' } },
    { op: 'set-text', id: 'title', html: 'New <em>title</em>' },
    { op: 'set-responsive', id: 'title', breakpoint: 'mobile', path: 'typography.fontSize', value: '28px' },
    { op: 'set-motion', id: 'title', entrance: 'fade-up' },
    { op: 'set-ornament', id: 'title', pseudo: 'before', props: { content: '“', color: '#fff' } },
    { op: 'set-link', id: 'button', href: 'https://example.com', target: '_blank' },
    { op: 'set-link', id: 'button', href: 'javascript:alert(1)' },
    { op: 'set-attrs', id: 'title', attrs: { metadata: { name: 'x' } } },
    { op: 'set-motion', id: 'title', hover: 'spin' },
    { op: 'set-text', id: 'hero', html: 'x' },
    { op: 'teleport', id: 'title' },
    { op: 'set-style', id: 'missing', style: { 'color.text': 'red' } },
  ]);
  off();
  assert.equal(JSON.stringify(result.applied), JSON.stringify([0, 1, 2, 3, 4, 5]));
  assert.equal(JSON.stringify(result.refused.map(item => item.index)), JSON.stringify([6, 7, 8, 9, 10, 11]));
  assert.match(result.refused[1].reason, /metadata/, 'Metadata and bindings cannot be written through set-attrs');
  assert.equal(applied.applied.length, 6, 'The apply event carries the result');
  const title = blocks.title.attributes;
  assert.equal(title.style.typography.fontSize, '48px');
  assert.equal(title.content, 'New <em>title</em>');
  assert.equal(title.claraVe.responsive.mobile['typography.fontSize'], '28px');
  assert.equal(title.className, 'cve-anim-fade-up');
  assert.equal(title.claraVe.ornaments.before.content, '“');
  assert.equal(blocks.button.attributes.url, 'https://example.com');
  assert.equal(blocks.button.attributes.linkTarget, '_blank');

  // Content-only blocks accept content, never design.
  modes.title = 'contentOnly';
  result = await ve.apply([{ op: 'set-text', id: 'title', html: 'Locked design' }, { op: 'set-style', id: 'title', style: { 'color.text': 'red' } }]);
  assert.equal(JSON.stringify(result.applied), '[0]', 'Content-only still takes text');
  assert.match(result.refused[0].reason, /style/, 'Content-only refuses style');
  modes.title = 'default';

  // Structure and patterns.
  log.length = 0;
  result = await ve.apply([
    { op: 'duplicate', id: 'cta' }, { op: 'move', id: 'cta', direction: 'up' }, { op: 'remove', id: 'cta' }, { op: 'remove', id: 'main' },
    { op: 'insert-pattern', id: 'hero', pattern: 'theme/cta' }, { op: 'insert-pattern', id: 'hero', pattern: 'theme/header' }, { op: 'insert-pattern', id: 'hero', pattern: 'core/query-grid' },
  ]);
  assert.equal(JSON.stringify(result.applied), '[0,1,2,4]');
  assert.equal(JSON.stringify(log.map(entry => entry[0])), JSON.stringify(['duplicate', 'up', 'remove', 'insert']));
  const insert = log.find(entry => entry[0] === 'insert');
  assert.equal(insert[2], 1, 'A pattern goes after the given section');
  assert.equal(insert[3], 'main');
  assert.equal(insert[1][0].patternName, 'theme/cta', 'The inserted section keeps its pattern name');
  assert.match(result.refused.find(item => item.index === 5).reason, /Unknown theme pattern/, 'Header patterns are not offered between sections');

  // Selection and document.
  assert.equal(ve.select('title'), true);
  const selection = ve.getSelection();
  assert.equal(selection.name, 'core/heading');
  assert.equal(selection.sectionName, 'Hero');
  assert.equal(JSON.stringify(selection.parents.map(parent => parent.id)), JSON.stringify(['main', 'hero']));
  assert.equal(ve.select('nope'), false);
  const doc = ve.getDocument();
  assert.equal(doc.type, 'wp_template');
  assert.equal(doc.id, 'theme//front-page');
  await assert.rejects(ve.apply('not an array'), /array/);
  dom.window.close();
  console.log('PASS: ClaraVE API — style, text, responsive, motion, ornaments, links, permissions, structure, patterns, selection and document');
})().catch(error => { console.error(error); process.exitCode = 1; });
