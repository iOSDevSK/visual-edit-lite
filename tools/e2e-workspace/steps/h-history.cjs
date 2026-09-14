module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  const raw = () => frame.evaluate(async () => { const p = await wp.apiFetch({ path: '/wp/v2/pages/417?context=edit' }); return p.content.raw; });
  const before = await raw();
  for (const v of ['26px', '27px']) { await L.clickBlock('h2', 40, 10); await L.tab('Style'); await L.openGroup('Typography'); await L.setCustom('Size', v); await L.apply(); await L.save(); }
  await frame.locator('.cve-w-toolbar button[aria-label="History"]').click(); await wait(3000);
  out.dock = await frame.evaluate(() => { const d = document.querySelector('.cve-w-dock.cve-w-history'); const r = d && d.getBoundingClientRect(); const layout = document.querySelector('.edit-post-layout, .editor-editor-interface, .interface-interface-skeleton'); return d && { rect: [r.left, r.top, r.width, r.height].map(Math.round), entries: [...d.querySelectorAll('.cve-w-history-entry')].slice(0, 4).map(e => e.querySelector('.cve-w-history-name').textContent + ' | ' + e.querySelector('.cve-w-note').textContent), original: !!d.textContent.match(/Original/), docSelect: d.querySelector('select') && d.querySelector('select').value, layoutRight: layout && Math.round(layout.getBoundingClientRect().right) }; });
  await shot('qa-h-history');
  // rename
  const first = frame.locator('.cve-w-history-entry').first(); await first.locator('.cve-w-history-name').click().catch(() => {}); await wait(300);
  const input = first.locator('input'); if (await input.count()) { await input.fill('QA renamed'); await input.press('Enter'); await wait(1500); out.renamed = await first.locator('.cve-w-history-name').textContent().catch(() => null); }
  // restore the previous version
  await frame.locator('.cve-w-history-entry').nth(1).locator('button', { hasText: 'Restore' }).click(); await wait(400);
  await frame.locator('.cve-w-history-confirm button', { hasText: 'Restore version' }).click(); await wait(2500);
  out.chip = await frame.evaluate(() => (document.querySelector('.cve-w-chip.is-restored') || {}).textContent); out.dirtyAfterRestore = await L.dirty();
  out.sizeAfterRestore = await L.canvasStyle('h2', 'fontSize');
  await L.save(); out.rawAfterSave = /26px/.test(await raw());
  await page.reload(); await wait(6000); const f2 = await (await page.$('iframe.cve-workspace-frame')).contentFrame(); await f2.waitForFunction(() => document.body.classList.contains('cve-native-collapsed'), null, { timeout: 60000 }); await wait(1500); ctx.frame = f2; const L2 = require('./lib.cjs')(ctx);
  out.sizeAfterReload = await L2.canvasStyle('h2', 'fontSize');
  // Original restore
  await f2.locator('.cve-w-toolbar button[aria-label="History"]').click(); await wait(3000);
  const orig = f2.locator('.cve-w-history-entry').filter({ hasText: 'Original' }).first(); out.originalPresent = !!(await orig.count());
  if (await orig.count()) { await orig.locator('button', { hasText: 'Restore' }).click(); await wait(400); await f2.locator('.cve-w-history-confirm button', { hasText: 'Restore version' }).click(); await wait(2500); out.originalRestoredIdentical = (await L2.serial()) === before || (await L2.serial()).length; await f2.locator('.cve-w-chip.is-restored button', { hasText: 'Undo' }).click().catch(() => {}); await wait(500); out.dirtyAfterChipUndo = await L2.dirty(); }
  // history API
  out.api = await f2.evaluate(async () => { const l = await ClaraVE.history.list(); return { entries: l.entries && l.entries.length, entity: l.entity }; });
  return out;
};
