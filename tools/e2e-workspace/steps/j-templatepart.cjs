module.exports = async ctx => {
  const { page, shot, wait } = ctx; let frame = ctx.frame; let L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  const reframe = async () => { await wait(4000); frame = await (await page.$('iframe.cve-workspace-frame')).contentFrame(); await frame.waitForFunction(() => document.body.classList.contains('cve-native-collapsed'), null, { timeout: 60000 }); await wait(1500); ctx.frame = frame; L = require('./lib.cjs')(ctx); };
  const siteTitle = await frame.evaluate(() => wp.data.select('core').getEntityRecord('root', 'site').title);
  out.siteTitle = siteTitle;
  // Document ▾ → Template parts → Header
  await frame.locator('.cve-w-doc > button').first().click(); await wait(1200);
  await frame.locator('.cve-w-doc .cve-w-menu select').first().selectOption('wp_template_part'); await wait(2500);
  out.parts = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-doc .cve-w-menu .cve-w-site-list button')].map(b => b.textContent.trim()));
  await frame.locator('.cve-w-doc .cve-w-menu .cve-w-site-list button', { hasText: /^header$/i }).first().click(); await reframe();
  out.docTitle = await frame.evaluate(() => document.querySelector('.cve-w-doc-title').textContent);
  out.url = page.url().slice(-80);
  // Site title block
  const st = L.canvas().locator('.wp-block-site-title').first(); out.hasSiteTitle = await st.count();
  if (out.hasSiteTitle) {
    await L.clickBlock('.wp-block-site-title a, .wp-block-site-title', 10, 8); out.sel = (await L.selected()).name; out.popup = await L.popup();
    await L.tab('Content').catch(() => {}); const rt = frame.locator('.cve-w-popup .cve-w-body [contenteditable="true"]').first(); out.rtInPopup = await rt.count();
    await L.canvas().locator('.wp-block-site-title a').first().click(); await page.keyboard.press('End'); await page.keyboard.type(' QA'); await wait(600);
    out.dirty = await L.dirty(); out.saved = await L.save(); out.dirtyAfter = await L.dirty();
    const html = await L.fetchFront('http://localhost:1111/'); out.frontHasTitle = html.includes(siteTitle + ' QA');
    // revert
    await frame.evaluate(t => wp.data.dispatch('core').editEntityRecord('root', 'site', undefined, { title: t }), siteTitle); await wait(500); out.saved2 = await L.save();
    out.frontReverted = (await L.fetchFront('http://localhost:1111/')).includes(siteTitle + ' QA') === false;
  }
  // Navigation link
  const nav = L.canvas().locator('.wp-block-navigation-item__content, .wp-block-navigation-link a').first(); out.hasNavLink = await nav.count();
  if (out.hasNavLink) { await L.clickBlock('.wp-block-navigation-item__content, .wp-block-navigation-link a', 10, 8); out.navSel = (await L.selected()).name; out.navPopup = await L.popup(); await L.tab('Content').catch(() => {}); out.navRows = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-popup .cve-w-body .cve-w-field span')].map(s => s.textContent)); await shot('qa-j-nav'); }
  await shot('qa-j-header');
  return out;
};
