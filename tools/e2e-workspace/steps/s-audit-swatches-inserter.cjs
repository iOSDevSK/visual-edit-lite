/* Contrast audit for native colour palettes and the block inserter. NEG=1 re-adds the old
   broken rules first, to prove the audit catches them. */
module.exports = async ctx => {
  const { frame, wait } = ctx; const L = require('./lib.cjs')(ctx); const fs = require('fs');
  const lib = fs.readFileSync(__dirname + '/../audit-lib.js', 'utf8'); const out = ctx.out = {};
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  if (process.env.NEG) await frame.evaluate(() => { const s = document.createElement('style'); s.textContent = '.cve-dark-native [role="option"]{color:#f5f5f7!important}html body .cve-dark-native .block-editor-block-types-list__item.block-editor-block-types-list__item .block-editor-block-icon svg path{fill:#1e1e1e!important}'; document.head.appendChild(s); });
  await L.clickBlock('figure.wp-block-image img', 100, 100); await L.tab('Advanced'); await wait(1000);
  await frame.locator('.cve-w-native [role=tab][aria-label*="Style"]').first().click(); await wait(800);
  const toggle = frame.locator('.cve-w-native button[aria-label*="olor picker"]').first(); await toggle.scrollIntoViewIfNeeded(); await toggle.click(); await wait(900);
  await frame.evaluate(lib); out.swatches = (await frame.evaluate(() => window.cveAudit('swatches'))).bad.filter(b => b.tag === 'SWATCH').length;
  await frame.keyboard ? 0 : 0; await ctx.page.keyboard.press('Escape'); await L.closePopup().catch(() => {});
  await frame.evaluate(() => wp.data.dispatch('core/editor').setIsInserterOpened(true)); await wait(2500);
  if (process.env.NEG) await frame.evaluate(() => document.querySelectorAll('.block-editor-block-types-list__item svg path').forEach(p => p.style.setProperty('fill', '#1e1e1e', 'important'))); out.tiles = await frame.locator('.block-editor-block-types-list__item').count(); out.icons = (await frame.evaluate(() => window.cveAudit('inserter'))).bad.filter(b => b.tag === 'BLOCK-ICON').length;
  return out;
};
