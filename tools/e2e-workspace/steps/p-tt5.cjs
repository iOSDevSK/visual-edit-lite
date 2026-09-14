module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  out.doc = await frame.evaluate(() => document.querySelector('.cve-w-doc-title').textContent);
  const target = (await L.canvas().locator('h1, h2, p').first().count()) ? 'h1, h2, p' : '.wp-block';
  await L.clickBlock(target, 20, 8); out.sel = (await L.selected()) && (await L.selected()).name; out.popup = await L.popup();
  await frame.locator('.cve-w-toolbar button', { hasText: 'Section' }).first().click(); await wait(3000);
  out.patterns = await frame.evaluate(() => { const d = document.querySelector('.cve-w-patterns'); return d && { items: d.querySelectorAll('.block-editor-block-patterns-list__item').length, labels: [...d.querySelectorAll('.block-editor-block-patterns-list__item')].slice(0, 40).map(i => i.getAttribute('aria-label')) }; });
  await shot('qa-p-tt5-patterns');
  const item = frame.locator('.cve-w-patterns .block-editor-block-patterns-list__item').first(); if (await item.count()) { await item.click(); await wait(2000); out.inserted = { sel: (await L.selected()).name, patternName: ((await L.selected()).attrs.metadata || {}).patternName, tabs: (await L.popup() || {}).tabs }; await L.undo(); }
  out.dirty = await L.dirty(); await shot('qa-p-tt5');
  return out;
};
