/* A theme section shipped as Custom HTML (mu-qa-patterns.php: an FAQ of <details>
   in one Custom HTML block) added with ＋ Section lands as native blocks — a
   Details block per question, every block valid — and one Undo takes the whole
   section back. Saves nothing. POST = any block page. */
module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const out = ctx.out = {};
  await frame.evaluate(() => { try { const p = wp.data.dispatch('core/preferences'); p.set('core/edit-post', 'welcomeGuide', false); p.set('core/edit-site', 'welcomeGuide', false); } catch (e) {} });
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  const count = () => frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const all = s.getClientIdsWithDescendants().map(id => s.getBlock(id)); return { total: all.length, details: all.filter(b => b.name === 'core/details').length, html: all.filter(b => b.name === 'core/html').length, invalid: all.filter(b => b.isValid === false).map(b => b.name), summaries: all.filter(b => b.name === 'core/details').map(b => String(b.attributes.summary)) }; });
  out.before = await count();
  await frame.locator('.cve-w-toolbar button[aria-label="Add a section"]').click(); await wait(2500);
  const dialog = frame.locator('.cve-w-patterns');
  await dialog.locator('input').first().fill('QA FAQ'); await wait(800);
  const item = dialog.locator('.block-editor-block-patterns-list__item').first();
  out.found = await item.count();
  if (!out.found) { await shot('f-section-html-nopattern'); return out; }
  await item.click();
  await frame.waitForFunction(() => { const s = wp.data.select('core/block-editor'); return s.getClientIdsWithDescendants().some(id => s.getBlockName(id) === 'core/details'); }, null, { timeout: 15000 }).catch(() => {});
  await wait(800);
  out.after = await count();
  await shot('f-section-html-' + page.viewportSize().width);
  await frame.locator('.cve-w-toolbar button[aria-label="Undo"]').click(); await wait(900);
  out.afterUndo = await count();
  out.pass = out.after.details === out.before.details + 3 && out.after.html === out.before.html && out.after.invalid.length === 0 && out.afterUndo.total === out.before.total;
  return out;
};
