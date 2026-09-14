module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {}; const canvas = L.canvas();
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  // Buttons container: Items
  const bb = await L.canvas().locator('.wp-block-buttons').first().boundingBox(); await L.clickBlock('.wp-block-buttons', bb.width - 12, bb.height / 2); out.selButtons = (await L.selected()).name; await shot('qa-f-0');
  if (out.selButtons !== 'core/buttons') { await frame.locator('.cve-w-popup button[title*="parent" i]').first().click(); await wait(500); out.selButtons = (await L.selected()).name; }
  out.tabsButtons = (await L.popup() || {}).tabs;
  await L.tab('Section'); await L.openGroup('Items');
  out.items = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-items li .cve-w-item-name')].map(b => b.textContent));
  await frame.locator('.cve-w-items li').nth(1).locator('button[aria-label^="Move up"]').click(); await wait(400);
  out.canvasOrder = await canvas.locator('.wp-block-button__link').allTextContents();
  await frame.locator('.cve-w-popup button', { hasText: 'Add item' }).click(); await wait(500); out.itemsAfterAdd = await canvas.locator('.wp-block-button__link').count();
  await frame.locator('.cve-w-items li').last().locator('button[aria-label^="Remove"]').click(); await wait(400); out.itemsAfterRemove = await canvas.locator('.wp-block-button__link').count();
  await frame.locator('.cve-w-items li .cve-w-item-name').first().click(); await wait(500); out.itemClickSelects = (await L.selected()).name;
  // Layout on the section group
  await L.clickBlock('h2', 40, 10); await frame.locator('.cve-w-popup button[title*="parent" i]').first().click(); await wait(600); out.selGroup = (await L.selected()).name; await L.tab('Section'); await L.openGroup('Layout');
  out.layoutRows = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-popup .cve-w-body .cve-w-field, .cve-w-popup .cve-w-body .cve-w-number')].filter(e => e.offsetParent).map(e => e.querySelector('span').textContent));
  await L.setSelect('Layout', 'flex').catch(async () => { await L.field('Layout').locator('select').selectOption({ index: 1 }); }); out.layoutAttr = (await L.selected()).attrs.layout;
  await L.undo();
  // Quick actions: duplicate, move, delete on the paragraph
  await L.clickBlock('p', 20, 8); const countBefore = await canvas.locator('p').count();
  await frame.locator('.cve-w-popup .cve-w-actions button', { hasText: 'Duplicate' }).click(); await wait(600); out.afterDuplicate = await canvas.locator('p').count() - countBefore;
  await frame.locator('.cve-w-popup .cve-w-actions button[aria-label^="Move up"], .cve-w-popup .cve-w-actions button[title^="Move up"]').first().click(); await wait(500);
  out.orderAfterMoveUp = await canvas.locator('section.wp-block-group > *').evaluateAll(els => els.slice(0, 3).map(e => e.tagName));
  await frame.locator('.cve-w-popup .cve-w-actions button[aria-label^="Delete"], .cve-w-popup .cve-w-actions button[title^="Delete"]').first().click(); await wait(600); out.afterDelete = await canvas.locator('p').count() - countBefore;
  // Add a section after (pattern browser)
  await L.clickBlock('h2', 40, 10); await frame.locator('.cve-w-popup button[title*="parent" i]').first().click(); await wait(600); await L.tab('Section'); await L.openGroup('Section');
  out.sectionButtons = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-popup .cve-w-body button')].map(b => b.textContent.trim()).filter(Boolean).slice(0, 8));
  const add = frame.locator('.cve-w-popup button', { hasText: 'Add a section after this one' }); if (await add.count()) { await add.click(); await wait(2500); out.patternDialog = await frame.evaluate(() => { const d = document.querySelector('.cve-w-patterns'); return d && { items: d.querySelectorAll('.block-editor-block-patterns-list__item').length, msg: (d.querySelector('p') || {}).textContent }; }); await shot('qa-f-patterns'); const item = frame.locator('.cve-w-patterns .block-editor-block-patterns-list__item').first(); if (await item.count()) { const t = await item.getAttribute('aria-label'); await item.click(); await wait(1500); out.inserted = { pattern: t, selected: (await L.selected()).name, patternName: ((await L.selected()).attrs.metadata || {}).patternName }; } else { await page.keyboard.press('Escape'); } }
  out.dirty = await L.dirty(); await L.undo(); await L.undo();
  await frame.evaluate(() => wp.data.dispatch('core/editor').editPost({ content: undefined })).catch(() => {});
  return out;
};
