/* Shared helpers for VE workspace QA steps. */
module.exports = ctx => {
  const { page, frame, wait } = ctx;
  const canvas = () => frame.frameLocator('iframe[name="editor-canvas"]');
  const L = {
    canvas,
    async clickBlock(sel, dx, dy, nth) {
      await frame.evaluate(() => window.dispatchEvent(new CustomEvent('clara-ve-close-popup'))); await wait(200);
      const loc = canvas().locator(sel).nth(nth || 0); await loc.scrollIntoViewIfNeeded().catch(() => {}); await wait(250);
      const b = await loc.boundingBox(); if (!b) throw new Error('no box for ' + sel);
      await page.mouse.click(b.x + (dx == null ? Math.min(30, b.width / 2) : dx), b.y + (dy == null ? Math.min(12, b.height / 2) : dy)); await wait(900); return b;
    },
    selected: () => frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const id = s.getSelectedBlockClientId(); return id ? { id, name: s.getBlockName(id), attrs: s.getBlockAttributes(id) } : null; }),
    dirty: () => frame.evaluate(() => wp.data.select('core').__experimentalGetDirtyEntityRecords().map(r => r.name + ':' + r.key)),
    popup: () => frame.evaluate(() => { const p = document.querySelector('.cve-w-popup'); if (!p) return null; const r = p.getBoundingClientRect(); return { rect: [r.left, r.top, r.width, r.height].map(Math.round), tabs: [...p.querySelectorAll('[role=tab]')].map(t => t.textContent + (t.getAttribute('aria-selected') === 'true' ? '*' : '')), title: (p.querySelector('.cve-w-heading strong') || {}).textContent, crumbs: [...p.querySelectorAll('.cve-w-crumb')].map(c => c.textContent), groups: [...p.querySelectorAll('.cve-w-section-title')].map(t => t.textContent + (t.getAttribute('aria-expanded') === 'true' ? '*' : '')), note: (p.querySelector('.cve-w-mode-note') || {}).textContent || '' }; }),
    async tab(name) { await frame.locator('.cve-w-popup [role=tab]', { hasText: new RegExp('^' + name + '$') }).click(); await wait(500); },
    async openGroup(title) { const t = frame.locator('.cve-w-popup .cve-w-section-title', { hasText: new RegExp('^' + title, 'i') }).first(); if (await t.count() && (await t.getAttribute('aria-expanded')) !== 'true') { await t.click(); await wait(300); } },
    field: label => frame.locator('.cve-w-popup label.cve-w-field', { has: frame.locator('span', { hasText: new RegExp('^' + label + '$') }) }).first(),
    async setSelect(label, value) { const f = L.field(label); await f.locator('select').selectOption(value); await wait(350); },
    async setInput(label, value) { const f = L.field(label); await f.locator('input').fill(value); await wait(350); },
    async setNumber(label, value) { const n = frame.locator('.cve-w-popup .cve-w-number').filter({ has: frame.locator('span', { hasText: new RegExp('^' + label + '$') }) }).first(); await n.locator('input').first().fill(String(value)); await wait(350); },
    async setCustom(label, value) { const f = L.field(label); await f.locator('select').selectOption('__custom').catch(() => {}); await wait(300); const n = f.locator('xpath=following-sibling::*[1]'); const tag = await n.evaluate(e => e.className); if (!/cve-w-(number|field)/.test(tag)) throw new Error('no Custom row after ' + label + ' (' + tag + ')'); await n.locator('input').first().fill(String(value)); await wait(350); },
    btn: text => frame.locator('.cve-w-popup button', { hasText: new RegExp('^' + text + '$') }).first(),
    async apply() { await L.btn('Apply').click(); await wait(400); const badge = frame.locator('.cve-w-badge'); if (await badge.count()) { await badge.click(); await wait(500); } },
    async cancel() { await L.btn('Cancel').click(); await wait(400); },
    async save() {
      await frame.locator('.cve-w-toolbar .cve-w-primary', { hasText: 'Save' }).click(); await wait(1500);
      const confirm = frame.locator('.entities-saved-states__panel button.is-primary, .editor-entities-saved-states__save-button, .entities-saved-states__save-button');
      const hadPanel = await confirm.count(); if (hadPanel) await confirm.first().click();
      await frame.waitForFunction(() => wp.data.select('core').__experimentalGetDirtyEntityRecords().length === 0, null, { timeout: 30000 }); await wait(1200);
      return { panel: !!hadPanel, status: await frame.evaluate(() => document.querySelector('.cve-w-status').textContent) };
    },
    fetchFront: url => frame.evaluate(async u => (await fetch(u + (u.includes('?') ? '&' : '?') + 'nc=' + Date.now(), { credentials: 'omit' })).text(), url),
    canvasStyle: (sel, prop) => canvas().locator(sel).first().evaluate((e, p) => getComputedStyle(e)[p], prop),
    canvasAttr: (sel, attr) => canvas().locator(sel).first().getAttribute(attr),
    undo: () => frame.locator('.cve-w-toolbar button[aria-label="Undo"]').click().then(() => wait(500)),
    redo: () => frame.locator('.cve-w-toolbar button[aria-label="Redo"]').click().then(() => wait(500)),
    more: async name => { await frame.locator('.cve-w-toolbar button', { hasText: '⋯' }).first().click(); await wait(500); await frame.locator('.cve-w-menu.is-right button, .cve-w-menu.is-right a', { hasText: name }).first().click(); await wait(1500); },
    closePanels: () => frame.evaluate(() => { const i = wp.data.dispatch('core/interface'); i.disableComplementaryArea('core'); const e = wp.data.dispatch('core/editor'); e.setIsListViewOpened && e.setIsListViewOpened(false); e.setIsInserterOpened && e.setIsInserterOpened(false); }).then(() => wait(400)),
    closePopup: () => frame.evaluate(() => window.dispatchEvent(new CustomEvent('clara-ve-close-popup'))).then(() => wait(300)),
    serial: () => frame.evaluate(() => wp.blocks.serialize(wp.data.select('core/block-editor').getBlocks())),
  };
  return L;
};
