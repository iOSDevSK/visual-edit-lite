module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  await L.clickBlock('h2', 40, 10);
  out.history = await frame.evaluate(async () => { const l = await ClaraVE.history.list(); return { keys: Object.keys(l), entries: l.entries && l.entries.length }; });
  out.api = await frame.evaluate(async () => {
    const r = {}; r.mode = ClaraVE.mode; r.version = ClaraVE.version; const sel = ClaraVE.getSelection(); r.selection = sel && { name: sel.name, title: sel.title, parents: sel.parents.length, section: sel.sectionName, editingMode: sel.editingMode };
    const events = []; ClaraVE.on('select', e => events.push('select')); ClaraVE.on('apply', e => events.push('apply'));
    const res = await ClaraVE.apply([{ op: 'set-style', id: sel.id, style: { 'typography.fontSize': '39px' } }, { op: 'set-attrs', id: sel.id, attrs: { metadata: { name: 'hacked' } } }, { op: 'bogus', id: sel.id }]);
    r.apply = res; r.events = events.slice();
    const doc = ClaraVE.getDocument(); r.doc = { type: doc.type, id: doc.id, mode: doc.mode, title: doc.title, contentLen: (doc.content || '').length };
    const blocks = wp.data.select('core/block-editor').getBlocks(); const p = blocks[0].innerBlocks.find(b => b.name === 'core/paragraph');
    r.selectResult = ClaraVE.select(p.clientId); await new Promise(r => setTimeout(r, 500)); r.selectedNow = ClaraVE.getSelection().name; r.eventsAfterSelect = events.slice();
    r.popupOpen = !!document.querySelector('.cve-w-popup'); ClaraVE.closePopup(); await new Promise(r => setTimeout(r, 300)); r.popupClosed = !document.querySelector('.cve-w-popup'); ClaraVE.openPopup(); await new Promise(r => setTimeout(r, 300)); r.popupReopened = !!document.querySelector('.cve-w-popup');
    wp.hooks.addFilter('clara_ve.popup.groups', 'qa', (groups, c) => groups.concat([{ key: 'qa', tab: 'content', title: 'QA GROUP', render: () => wp.element.createElement('p', { className: 'cve-qa-group' }, 'hello ' + c.block.name) }]));
    wp.hooks.addFilter('clara_ve.popup.footer', 'qa', (items) => items.concat([{ key: 'qa', label: 'QA footer', onClick: () => { window.__qaFooter = true; } }]));
    wp.hooks.addFilter('clara_ve.toolbar.more', 'qa', (items) => items.concat([{ key: 'qa', label: 'QA menu item', onClick: () => { window.__qaClicked = true; } }]));
    return r;
  });
  await L.clickBlock('p', 20, 8); await L.tab('Content');
  out.hooks = await frame.evaluate(() => ({ group: [...document.querySelectorAll('.cve-w-section-title')].map(t => t.textContent).filter(t => /QA GROUP/.test(t)).length, groupBody: !!document.querySelector('.cve-qa-group'), footer: [...document.querySelectorAll('.cve-w-popup button')].some(b => b.textContent.trim() === 'QA footer') }));
  await frame.locator('.cve-w-toolbar button', { hasText: '⋯' }).first().click(); await wait(400); out.moreItem = await frame.locator('.cve-w-menu.is-right button', { hasText: 'QA menu item' }).count(); await frame.locator('.cve-w-menu.is-right button', { hasText: 'QA menu item' }).click().catch(() => {}); await wait(200); out.moreClicked = await frame.evaluate(() => !!window.__qaClicked);
  await L.undo(); out.undoRevertsApply = await L.canvasStyle('h2', 'fontSize');
  return out;
};
