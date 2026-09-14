module.exports = async ctx => {
  const { page, shot, wait } = ctx; let frame = ctx.frame; let L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  await L.more('Google Fonts'); await wait(1500);
  await frame.locator('.cve-w-dialog input').first().fill('Lobster'); await wait(1500);
  const row = frame.locator('.cve-w-dialog .cve-w-font-list li, .cve-w-dialog .cve-w-font-list > div').filter({ hasText: /^Lobster\b/ }).first(); out.found = await row.count();
  await row.locator('button').first().click(); await wait(500);
  out.selected = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-dialog .cve-w-chip, .cve-w-dialog .cve-w-font-chip')].map(c => c.textContent));
  await frame.locator('.cve-w-dialog button', { hasText: 'Save fonts' }).click(); await wait(4000);
  out.dialogGone = !(await frame.locator('.cve-w-dialog').count());
  await page.reload(); await wait(5000); frame = await (await page.$('iframe.cve-workspace-frame')).contentFrame(); await frame.waitForFunction(() => document.body.classList.contains('cve-native-collapsed'), null, { timeout: 60000 }); await wait(1500); ctx.frame = frame; L = require('./lib.cjs')(ctx);
  await L.clickBlock('h2', 40, 10); await L.tab('Style'); await L.openGroup('Typography');
  out.fontOptions = await L.field('Font').locator('select option').allTextContents();
  const opt = out.fontOptions.find(o => /Lobster/.test(o));
  if (opt) { await L.field('Font').locator('select').selectOption({ label: opt }); await wait(400); out.canvasFont = await L.canvasStyle('h2', 'fontFamily'); await L.apply(); await L.save(); const html = await L.fetchFront('http://localhost:1111/ve-qa-blocks/'); out.front = { family: /Lobster/.test(html), fontface: /@font-face[^}]*Lobster|fonts\.gstatic|fonts\.googleapis|lobster/i.test(html) }; await L.clickBlock('h2', 40, 10); await L.tab('Style'); await L.openGroup('Typography'); await L.field('Font').locator('select').selectOption(''); await L.apply(); await L.save(); }
  // remove the font again
  await L.more('Google Fonts'); await wait(1500);
  const chip = frame.locator('.cve-w-dialog button', { hasText: /Lobster/ }).first(); if (await chip.count()) { await chip.click(); await wait(300); }
  await frame.locator('.cve-w-dialog button', { hasText: 'Save fonts' }).click(); await wait(4000);
  out.fontsAfter = await frame.evaluate(async () => (await wp.apiFetch({ path: '/wp/v2/font-families?per_page=50' })).map(f => f.font_family_settings && f.font_family_settings.name));
  return out;
};
