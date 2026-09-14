/* Contrast audit of every Styles screen, plus Site styles while a template part is open. */
module.exports = async ctx => {
  const { page, frame, wait } = ctx; const fs = require('fs'); const lib = fs.readFileSync(__dirname + '/../audit-lib.js', 'utf8'); const out = ctx.out = { bad: [] };
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  const more = async (f, item) => { await f.locator('.cve-w-toolbar button[aria-label="More"], .cve-w-toolbar button[title="More"]').first().click(); await wait(400); await f.locator('.cve-w-menu [role=menuitem]', { hasText: item }).click(); await wait(2500); };
  const audit = async (f, label) => { await f.evaluate(lib); const r = await f.evaluate(l => window.cveAudit(l), label); out.bad.push(...r.bad.filter(b => b.cls === undefined || !/cve-w-toolbar/.test(b.cls))); };
  const panel = f => f.locator('.interface-interface-skeleton__sidebar');
  await more(frame, 'Site styles'); await audit(frame, 'styles-root');
  out.screens = {};
  for (const screen of ['Typography', 'Colors', 'Background', 'Shadows', 'Layout']) {
    const btn = panel(frame).locator('button, [role=button]', { hasText: new RegExp('^' + screen + '$') }).first();
    if (!(await btn.count())) { out.screens[screen] = 'missing'; continue; }
    await btn.click(); await wait(1200); await audit(frame, 'styles-' + screen);
    out.screens[screen] = await panel(frame).evaluate(el => [...el.querySelectorAll('button, h2, h3, label')].map(b => b.textContent.trim()).filter(Boolean).slice(0, 12));
    if (screen === 'Colors') { const pal = panel(frame).locator('button', { hasText: 'Edit palette' }).first(); if (await pal.count()) { await pal.click(); await wait(1200); await audit(frame, 'styles-palette'); out.palette = await panel(frame).evaluate(el => [...el.querySelectorAll('button[aria-label], .components-truncate, label')].map(b => (b.getAttribute('aria-label') || b.textContent).trim()).filter(Boolean).slice(0, 16)); await page.screenshot({ path: require('path').join(process.env.OUT, 'styles-palette.png') }); await panel(frame).locator('button[aria-label="Back"], button[aria-label="Navigate back"]').first().click().catch(() => {}); await wait(800); } }
    await panel(frame).locator('button[aria-label="Back"], button[aria-label="Navigate back"]').first().click().catch(() => {}); await wait(800);
  }
  await panel(frame).locator('button[aria-label^="Close"]').first().click().catch(() => {}); await wait(1000);
  out.modeAfterClose = await frame.evaluate(() => wp.data.select('core/editor').getRenderingMode());
  // Template part: open Header through Document ▾, then Site styles.
  await frame.locator('.cve-w-doc > button').click(); await wait(400);
  await frame.locator('.cve-w-doc .cve-w-menu select').first().selectOption('wp_template_part'); await wait(2000);
  const header = frame.locator('.cve-w-doc .cve-w-menu .cve-w-site-list button').first(); out.partName = await header.textContent();
  await header.click(); await page.waitForTimeout(9000);
  const f2 = await (await page.$('iframe.cve-workspace-frame')).contentFrame();
  await f2.waitForFunction(() => document.body.classList.contains('cve-native-collapsed'), null, { timeout: 60000 }); await wait(2000);
  out.part = await f2.evaluate(() => { const e = wp.data.select('core/editor'); return { type: e.getCurrentPostType(), mode: e.getRenderingMode() }; });
  const items = await (async () => { await f2.locator('.cve-w-toolbar button[aria-label="More"], .cve-w-toolbar button[title="More"]').first().click(); await wait(400); return f2.locator('.cve-w-menu [role=menuitem]').allTextContents(); })();
  out.partMenu = items;
  if (items.includes('Site styles')) {
    await f2.locator('.cve-w-menu [role=menuitem]', { hasText: 'Site styles' }).click(); await wait(2500);
    out.partOpen = await f2.evaluate(() => { const sb = document.querySelector('.interface-interface-skeleton__sidebar'); const c = document.querySelector('iframe[name="editor-canvas"]'); return { mode: wp.data.select('core/editor').getRenderingMode(), panel: sb && Math.round(sb.getBoundingClientRect().width), canvasBlocks: c && c.contentDocument ? c.contentDocument.querySelectorAll('[data-block]').length : -1 }; });
    await page.screenshot({ path: require('path').join(process.env.OUT, 'styles-part.png') });
    await panel(f2).locator('button[aria-label^="Close"]').first().click().catch(() => {}); await wait(1000);
    out.partClosed = await f2.evaluate(() => ({ mode: wp.data.select('core/editor').getRenderingMode(), dirty: wp.data.select('core').__experimentalGetDirtyEntityRecords().length }));
  }
  return out;
};
